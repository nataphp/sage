<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Dto;

/**
 * Final result of `Sage::run()` / `LLMService::run()`.
 *
 *   $result->text;          // raw output (always set)
 *   $result->json['total']; // JSON actions: parsed and validated output
 *   $result->provider; $result->model; $result->latency_ms; $result->usage;
 */
final class LLMResult {

/**
 * Constructor.
 *
 * @param string $action Action key.
 * @param string $provider Provider key used.
 * @param string $model Model reported by the provider.
 * @param string $output_mode 'text' or 'json'.
 * @param string $text Raw text output.
 * @param array<string, mixed>|null $json Parsed output (JSON actions only).
 * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $usage Token usage.
 * @param int $latency_ms Wall-clock time, including a retry.
 * @param string|null $finish_reason Provider's raw stop/finish reason.
 */
    public function __construct(
        public readonly string $action,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $output_mode,
        public readonly string $text,
        public readonly ?array $json,
        public readonly array $usage,
        public readonly int $latency_ms,
        public readonly ?string $finish_reason = null
    ) {
    }

}
