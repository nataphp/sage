<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Provider;

use Sage\Service\Dto\Attachment;
use Sage\Service\Dto\CompletionRequest;
use Sage\Service\Dto\CompletionResponse;
use Sage\Service\Exception\LLMRefusalException;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\Transport\HttpTransportInterface;

/**
 * Anthropic Claude adapter (Messages API, `POST /messages`).
 *
 * - JSON mode: with a JSON Schema, native structured output
 *   (`output_config.format`); without, an instruction appended to the prompt.
 * - Effort goes to `output_config.effort`.
 * - The text is every `text` block joined: models that think return
 *   `thinking` blocks first.
 * - `stop_reason: refusal` throws LLMRefusalException.
 * - Attachments: images (JPEG, PNG, GIF, WebP), PDF and plain text documents.
 *
 * Temperature is only sent when set; Claude Opus 4.7+ and the Claude 5 family
 * reject any explicit value, so leave it null for them.
 *
 * Optional `fallbacks` ('default' or a list of `['model' => ...]`): server-side
 * fallback to another model when the requested one declines (beta).
 */
final class ClaudeProvider extends AbstractHttpProvider {

    public const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    public const API_VERSION = '2023-06-01';

/**
 * Output tokens when the request does not say (thinking counts towards it).
 */
    public const DEFAULT_MAX_TOKENS = 16000;

/**
 * Image formats the Messages API accepts.
 *
 * @var list<string>
 */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

/**
 * Provider name used in exception messages.
 *
 * @var string
 */
    protected string $_name = 'Claude';

/**
 * Constructor.
 *
 * @param \Sage\Service\Transport\HttpTransportInterface $http Transport.
 * @param string $apiKey Anthropic API key.
 * @param string $baseUrl API base URL.
 * @param string|array|null $fallbacks Server-side fallbacks: 'default', a list of
 *   `['model' => ...]`, or null for none.
 */
    public function __construct(
        HttpTransportInterface $http,
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        protected string|array|null $fallbacks = null
    ) {
        parent::__construct($http, $apiKey, $baseUrl);
    }

/**
 * Send the request to the Messages API.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Resolved request.
 * @return \Sage\Service\Dto\CompletionResponse
 * @throws \Sage\Service\Exception\LLMTimeoutException On timeout.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMRefusalException When the model declines.
 * @throws \Sage\Service\Exception\LLMProviderException On other errors.
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported attachment.
 */
    public function complete(CompletionRequest $request): CompletionResponse {
        $userText = $request->user_prompt;
        if ($request->wantsJson() && $request->json_schema === null) {
            $userText = rtrim($userText) . "\n\nReturn ONLY valid JSON (no prose, no markdown).";
        }

        $payload = [
            'model' => $request->model,
            'max_tokens' => $request->max_tokens ?? self::DEFAULT_MAX_TOKENS,
            'messages' => [['role' => 'user', 'content' => $this->_userContent($userText, $request->attachments)]],
        ];
        if ($request->system_prompt !== '') {
            $payload['system'] = $request->system_prompt;
        }
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        $outputConfig = [];
        if ($request->effort !== null) {
            $outputConfig['effort'] = $request->effort;
        }
        if ($request->wantsJson() && $request->json_schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $request->json_schema];
        }
        if ($outputConfig !== []) {
            $payload['output_config'] = $outputConfig;
        }

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
        ];
        if ($this->fallbacks !== null && $this->fallbacks !== [] && $this->fallbacks !== '') {
            $payload['fallbacks'] = $this->fallbacks;
            $headers['anthropic-beta'] = is_string($this->fallbacks) ? 'server-side-fallback-2026-07-01' : 'server-side-fallback-2026-06-01';
        }

        $data = $this->_postJson($this->baseUrl . '/messages', $payload, $headers, $request->timeout_seconds);

        $stopReason = isset($data['stop_reason']) ? (string)$data['stop_reason'] : null;
        if ($stopReason === 'refusal') {
            throw new LLMRefusalException('Claude declined the request.', 0, null, [
                'category' => $data['stop_details']['category'] ?? null,
                'explanation' => $data['stop_details']['explanation'] ?? null,
            ]);
        }

        $text = '';
        foreach ((array)($data['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }

        $usage = $data['usage'] ?? [];
        $promptTokens = isset($usage['input_tokens']) ? (int)$usage['input_tokens'] + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0) : null;
        $completionTokens = $usage['output_tokens'] ?? null;

        return new CompletionResponse(
            $text,
            (string)($data['model'] ?? $request->model),
            $this->_usage([
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens !== null && $completionTokens !== null ? $promptTokens + (int)$completionTokens : null,
            ]),
            $stopReason
        );
    }

/**
 * User message content: a string, or attachment blocks followed by the text.
 *
 * @param string $text Prompt text.
 * @param list<\Sage\Service\Dto\Attachment> $attachments Attachments.
 * @return string|array<int, array<string, mixed>>
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported attachment.
 */
    protected function _userContent(string $text, array $attachments): string|array {
        if ($attachments === []) {
            return $text;
        }

        $blocks = array_map(fn(Attachment $attachment) => $this->_attachmentBlock($attachment), $attachments);
        // Attachments before the text, as Anthropic recommends.
        $blocks[] = ['type' => 'text', 'text' => $text];
        return $blocks;
    }

/**
 * Content block of one attachment.
 *
 * @param \Sage\Service\Dto\Attachment $attachment Attachment.
 * @return array<string, mixed>
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported type.
 */
    protected function _attachmentBlock(Attachment $attachment): array {
        if (in_array($attachment->mime, self::IMAGE_MIMES, true)) {
            return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $attachment->mime, 'data' => $attachment->base64()]];
        }
        if ($attachment->mime === 'application/pdf') {
            return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $attachment->base64()]];
        }
        if ($attachment->mime === 'text/plain') {
            return ['type' => 'document', 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => $attachment->data]];
        }
        throw new LLMValidationException('Claude does not accept this attachment type.', 0, null, ['mime' => $attachment->mime, 'name' => $attachment->name]);
    }

}
