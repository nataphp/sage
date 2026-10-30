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
 *   $result->sources;       // with server tools: the pages searched and fetched
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
 * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int, web_search_requests?: int, web_fetch_requests?: int} $usage Token usage and server tool calls.
 * @param int $latency_ms Wall-clock time, including a retry.
 * @param string|null $finish_reason Provider's raw stop/finish reason.
 * @param list<array{tool: string, url: string, title?: string, page_age?: string, retrieved_at?: string}> $sources Pages the server tools returned.
 * @param list<array{tool: string, error_code: string}> $tool_errors Server tool calls that failed.
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
        public readonly ?string $finish_reason = null,
        public readonly array $sources = [],
        public readonly array $tool_errors = []
    ) {
    }

}
