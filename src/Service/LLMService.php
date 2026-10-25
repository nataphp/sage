<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service;

use Nata\FilesystemManager\File;
use Nata\Log\Log;
use Sage\Service\Action\ActionInterface;
use Sage\Service\Action\JsonSchemaInterface;
use Sage\Service\Attachment\ImagePreparer;
use Sage\Service\Dto\Attachment;
use Sage\Service\Dto\CompletionRequest;
use Sage\Service\Dto\CompletionResponse;
use Sage\Service\Dto\LLMResult;
use Sage\Service\Exception\LLMException;
use Sage\Service\Exception\LLMProviderException;
use Sage\Service\Exception\LLMTimeoutException;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\Provider\ProviderInterface;
use Fiber;
use Throwable;

/**
 * Runs one bound action: validates the input, resolves provider/model/options,
 * prepares attachments, calls the provider (retries with a backoff on 429 and
 * 529, one retry on timeouts and other 5xx) and validates JSON output.
 *
 * Built by LLMFactory (`Sage::for()`); construct it directly only for custom
 * providers or tests.
 *
 * Option resolution, for every option: `run()` options → `Sage::for()` options
 * → action `defaults` → global defaults. When the provider is chosen at
 * runtime without a model, that provider's default model is used.
 */
final class LLMService {

/**
 * Options accepted by `run()` and `Sage::for()`.
 *
 * @var list<string>
 */
    public const OPTIONS = ['provider', 'model', 'temperature', 'max_tokens', 'timeout_seconds', 'effort', 'attachments', 'image_max_edge'];

/**
 * Constructor.
 *
 * @param \Sage\Service\ActionRegistry $registry Action registry.
 * @param array<string, \Sage\Service\Provider\ProviderInterface> $providers Providers by key.
 * @param array<string, mixed> $defaults Global defaults: provider, models (provider => default model),
 *   timeout_seconds, image_max_edge, retry_delay_ms, and optionally temperature, max_tokens, effort.
 * @param string $boundAction Action key this instance runs.
 * @param array<string, mixed> $boundOptions Options applied to every run().
 */
    public function __construct(
        protected ActionRegistry $registry,
        protected array $providers,
        protected array $defaults = [],
        protected string $boundAction = '',
        protected array $boundOptions = []
    ) {
    }

/**
 * Run the bound action.
 *
 * @param array<string, mixed> $input Input; must contain the action's required keys.
 * @param array<string, mixed> $options Per-call options (see OPTIONS); `attachments` is a list of
 *   `Nata\FilesystemManager\File` or `Sage\Service\Dto\Attachment`.
 * @return \Sage\Service\Dto\LLMResult
 * @throws \Sage\Service\Exception\LLMValidationException On bad input, an unknown provider, an
 *   unsupported attachment or invalid JSON output.
 * @throws \Sage\Service\Exception\LLMRefusalException When the model declines.
 * @throws \Sage\Service\Exception\LLMTimeoutException On timeout after the retry.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMProviderException On other provider errors after the retry.
 */
    public function run(array $input, array $options = []): LLMResult {
        $options = array_merge($this->boundOptions, $options);
        $actionName = trim($this->boundAction);
        $entry = $this->registry->get($actionName);
        $actionDefaults = $entry['defaults'];
        /** @var \Sage\Service\Action\ActionInterface $action */
        $action = new $entry['class']();

        $this->_validateInput($action, $input, $actionName);

        $outputMode = $action->outputMode();
        $outputSchema = $action->outputSchema();
        if ($outputMode === CompletionRequest::OUTPUT_MODE_JSON && $outputSchema === null) {
            throw new LLMValidationException('JSON actions must define outputSchema().', 0, null, ['action' => $actionName]);
        }
        $jsonSchema = $outputMode === CompletionRequest::OUTPUT_MODE_JSON && $action instanceof JsonSchemaInterface ? $action->jsonSchema() : null;

        $providerKey = strtolower(trim((string)$this->_option('provider', $options, $actionDefaults)));
        $provider = $this->providers[$providerKey] ?? null;
        if (!$provider instanceof ProviderInterface) {
            throw new LLMValidationException('Unknown or unconfigured LLM provider.', 0, null, ['action' => $actionName, 'provider' => $providerKey]);
        }

        $temperature = $this->_option('temperature', $options, $actionDefaults);
        $maxTokens = $this->_option('max_tokens', $options, $actionDefaults);
        $request = new CompletionRequest(
            $action->systemPrompt($input),
            $action->userPrompt($input),
            $providerKey,
            $this->_model($providerKey, $options, $actionDefaults),
            $temperature !== null ? (float)$temperature : null,
            $maxTokens !== null ? (int)$maxTokens : null,
            $outputMode,
            (float)($this->_option('timeout_seconds', $options, $actionDefaults) ?? 30),
            $this->_attachments($options['attachments'] ?? [], (int)($this->_option('image_max_edge', $options, $actionDefaults) ?? ImagePreparer::DEFAULT_MAX_EDGE), $actionName),
            $jsonSchema,
            $this->_option('effort', $options, $actionDefaults)
        );

        $logContext = ['scope' => ['sage'], 'action' => $actionName, 'provider' => $providerKey, 'model' => $request->model];
        $started = microtime(true);
        try {
            $response = $this->_complete($provider, $request);
        } catch (LLMException $exception) {
            Log::write('warning', 'Sage action failed: ' . $exception->getMessage(), $logContext + $exception->context());
            throw $exception;
        }
        $latency = (int)round((microtime(true) - $started) * 1000);

        $json = null;
        if ($request->wantsJson()) {
            $context = ['action' => $actionName, 'finish_reason' => $response->finish_reason];
            if ($response->isTruncated() && JsonOutput::decode($response->text) === null) {
                throw new LLMValidationException('Model output was truncated (token limit reached); raise max_tokens.', 0, null, $context);
            }
            $json = JsonOutput::parse($response->text, $outputSchema ?? [], $context);
        }

        Log::write('info', 'Sage action completed', $logContext + [
            'model' => $response->model,
            'latency_ms' => $latency,
            'finish_reason' => $response->finish_reason,
        ] + $response->usage);

        return new LLMResult($actionName, $providerKey, $response->model, $request->output_mode, $response->text, $json, $response->usage, $latency, $response->finish_reason);
    }

/**
 * Call the provider, retrying when it is busy (429, 529) with a backoff,
 * and once on timeouts and other 5xx.
 *
 * @param \Sage\Service\Provider\ProviderInterface $provider Provider.
 * @param \Sage\Service\Dto\CompletionRequest $request Request.
 * @return \Sage\Service\Dto\CompletionResponse
 * @throws \Sage\Service\Exception\LLMException On failure.
 */
    protected function _complete(ProviderInterface $provider, CompletionRequest $request): CompletionResponse {
        $attempt = 0;
        while (true) {
            try {
                return $provider->complete($request);
            } catch (Throwable $exception) {
                $delay = $this->_retryDelay($exception, ++$attempt);
                if ($delay === null) {
                    throw $exception;
                }
                $this->_sleep($delay);
            }
        }
    }

/**
 * How long to wait before trying again, or null to give up. Busy (429 and
 * 529 overloaded): up to `rate_limit_retries` times, doubling from
 * `retry_delay_ms` up to `retry_max_delay_ms`, with jitter, never sooner than
 * the retry-after the API gave (when it asks for longer than the most, give
 * up: the caller tries later). Timeouts and other 5xx: once.
 *
 * @param \Throwable $exception Failure.
 * @param int $attempt Failed attempts so far.
 * @return int|null Milliseconds, or null.
 */
    protected function _retryDelay(Throwable $exception, int $attempt): ?int {
        $base = (int)($this->defaults['retry_delay_ms'] ?? 1000);
        $status = $exception instanceof LLMProviderException ? (int)($exception->context()['status'] ?? 0) : 0;
        if ($status === 429 || $status === 529) {
            if ($attempt > (int)($this->defaults['rate_limit_retries'] ?? 4)) {
                return null;
            }
            $max = (int)($this->defaults['retry_max_delay_ms'] ?? 8000);
            $retryAfter = (int)($exception->context()['retry_after'] ?? 0) * 1000;
            if ($retryAfter > $max) {
                return null;
            }
            $delay = min($max, $base * 2 ** ($attempt - 1));

            return max($retryAfter, $delay - random_int(0, intdiv($delay, 4)));
        }
        if ($attempt > 1 || !$this->_shouldRetry($exception)) {
            return null;
        }

        return $base;
    }

/**
 * Wait. In a Fiber (calls running together, CurlMultiTransport), the
 * Fiber is suspended meanwhile, so the others go on.
 *
 * @param int $ms Milliseconds.
 * @return void
 */
    protected function _sleep(int $ms): void {
        if ($ms <= 0) {
            return;
        }
        if (Fiber::getCurrent() === null) {
            usleep($ms * 1000);

            return;
        }
        $until = microtime(true) + $ms / 1000;
        while (microtime(true) < $until) {
            Fiber::suspend();
        }
    }

/**
 * Whether a failure is transient (timeout or HTTP 5xx, including 529 overloaded).
 *
 * @param \Throwable $exception Failure.
 * @return bool
 */
    protected function _shouldRetry(Throwable $exception): bool {
        if ($exception instanceof LLMTimeoutException) {
            return true;
        }
        if ($exception instanceof LLMProviderException) {
            return (int)($exception->context()['status'] ?? 0) >= 500;
        }
        return false;
    }

/**
 * Check the required input keys are present.
 *
 * @param \Sage\Service\Action\ActionInterface $action Action.
 * @param array<string, mixed> $input Input.
 * @param string $actionName Action key.
 * @return void
 * @throws \Sage\Service\Exception\LLMValidationException When keys are missing.
 */
    protected function _validateInput(ActionInterface $action, array $input, string $actionName): void {
        $missing = array_values(array_filter($action->requiredInputKeys(), static fn($key) => !array_key_exists($key, $input)));
        if ($missing !== []) {
            throw new LLMValidationException('Missing required input keys.', 0, null, ['action' => $actionName, 'missing' => $missing]);
        }
    }

/**
 * Resolve an option: run/bound options → action defaults → global defaults.
 *
 * @param string $key Option key.
 * @param array<string, mixed> $options Run options (bound options merged in).
 * @param array<string, mixed> $actionDefaults Action defaults.
 * @return mixed
 */
    protected function _option(string $key, array $options, array $actionDefaults): mixed {
        return $options[$key] ?? $actionDefaults[$key] ?? $this->defaults[$key] ?? null;
    }

/**
 * Resolve the model for the chosen provider.
 *
 * @param string $providerKey Resolved provider.
 * @param array<string, mixed> $options Run options.
 * @param array<string, mixed> $actionDefaults Action defaults.
 * @return string
 */
    protected function _model(string $providerKey, array $options, array $actionDefaults): string {
        $providerDefault = (string)($this->defaults['models'][$providerKey] ?? '');
        if (!empty($options['model'])) {
            return (string)$options['model'];
        }
        // A provider picked at runtime must not inherit the action's model for another provider.
        if (!empty($options['provider']) && strtolower((string)$options['provider']) !== strtolower((string)($actionDefaults['provider'] ?? ''))) {
            return $providerDefault;
        }
        return (string)($actionDefaults['model'] ?? $providerDefault);
    }

/**
 * Turn the `attachments` option into prepared Attachment instances.
 *
 * @param mixed $attachments List of File or Attachment.
 * @param int $imageMaxEdge Long-edge limit for images (0 keeps them as they are).
 * @param string $actionName Action key.
 * @return list<\Sage\Service\Dto\Attachment>
 * @throws \Sage\Service\Exception\LLMValidationException On anything else in the list.
 */
    protected function _attachments(mixed $attachments, int $imageMaxEdge, string $actionName): array {
        if (!is_array($attachments)) {
            throw new LLMValidationException('attachments must be a list.', 0, null, ['action' => $actionName]);
        }

        $preparer = new ImagePreparer($imageMaxEdge);
        $prepared = [];
        foreach ($attachments as $attachment) {
            if ($attachment instanceof File) {
                $attachment = Attachment::fromFile($attachment);
            }
            if (!$attachment instanceof Attachment) {
                throw new LLMValidationException('attachments must contain Nata\FilesystemManager\File or Sage\Service\Dto\Attachment.', 0, null, ['action' => $actionName]);
            }
            $prepared[] = $preparer->prepare($attachment);
        }
        return $prepared;
    }

}
