<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Dto;

use Sage\Service\Exception\LLMValidationException;

/**
 * Fully resolved request handed to a provider adapter.
 *
 * Built by LLMService; adapters translate it into their own payload.
 */
final class CompletionRequest {

    public const OUTPUT_MODE_TEXT = 'text';
    public const OUTPUT_MODE_JSON = 'json';

/**
 * Accepted effort levels (mapped per provider; ignored where unsupported).
 *
 * @var list<string>
 */
    public const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public readonly string $system_prompt;
    public readonly string $user_prompt;
    public readonly string $provider;
    public readonly string $model;
    public readonly string $output_mode;
    public readonly ?string $effort;

/**
 * Constructor.
 *
 * @param string $system_prompt System prompt ('' to omit).
 * @param string $user_prompt User prompt.
 * @param string $provider Provider key ('claude', 'openai', 'gemini'...).
 * @param string $model Model identifier.
 * @param float|null $temperature Sampling temperature; null leaves the provider default
 *   (several recent models reject any explicit value).
 * @param int|null $max_tokens Maximum output tokens; null uses the adapter default.
 * @param string $output_mode 'text' or 'json'.
 * @param float $timeout_seconds HTTP timeout, > 0.
 * @param list<\Sage\Service\Dto\Attachment> $attachments Prepared attachments.
 * @param array<string, mixed>|null $json_schema Full JSON Schema for native structured
 *   output (JSON mode only); null falls back to the provider's plain JSON mode.
 * @param string|null $effort Reasoning effort (see EFFORT_LEVELS); null leaves the provider default.
 * @throws \Sage\Service\Exception\LLMValidationException On an empty provider/model, an
 *   unknown output mode or effort, a timeout <= 0 or a non-Attachment attachment.
 */
    public function __construct(
        string $system_prompt,
        string $user_prompt,
        string $provider,
        string $model,
        public readonly ?float $temperature = null,
        public readonly ?int $max_tokens = null,
        string $output_mode = self::OUTPUT_MODE_TEXT,
        public readonly float $timeout_seconds = 30.0,
        public readonly array $attachments = [],
        public readonly ?array $json_schema = null,
        ?string $effort = null
    ) {
        $this->system_prompt = trim($system_prompt);
        $this->user_prompt = trim($user_prompt);
        $this->provider = strtolower(trim($provider));
        $this->model = trim($model);
        $this->output_mode = strtolower(trim($output_mode));
        $this->effort = $effort !== null && $effort !== '' ? strtolower(trim($effort)) : null;

        if ($this->provider === '') {
            throw new LLMValidationException('provider is required.');
        }
        if ($this->model === '') {
            throw new LLMValidationException('model is required.', 0, null, ['provider' => $this->provider]);
        }
        if (!in_array($this->output_mode, [self::OUTPUT_MODE_TEXT, self::OUTPUT_MODE_JSON], true)) {
            throw new LLMValidationException('Invalid output_mode.', 0, null, ['output_mode' => $this->output_mode]);
        }
        if ($this->effort !== null && !in_array($this->effort, self::EFFORT_LEVELS, true)) {
            throw new LLMValidationException('Invalid effort.', 0, null, ['effort' => $this->effort]);
        }
        if ($timeout_seconds <= 0) {
            throw new LLMValidationException('timeout_seconds must be > 0.', 0, null, ['timeout_seconds' => $timeout_seconds]);
        }
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Attachment) {
                throw new LLMValidationException('attachments must be a list of Sage\Service\Dto\Attachment.');
            }
        }
    }

/**
 * Whether the response must be JSON.
 *
 * @return bool
 */
    public function wantsJson(): bool {
        return $this->output_mode === self::OUTPUT_MODE_JSON;
    }

}
