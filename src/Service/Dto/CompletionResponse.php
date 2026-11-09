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
 * Normalised response returned by a provider adapter.
 */
final class CompletionResponse {

/**
 * Finish reasons (any provider) meaning the output hit the token limit.
 *
 * @var list<string>
 */
    public const TRUNCATED_REASONS = ['max_tokens', 'length', 'MAX_TOKENS'];

/**
 * Constructor.
 *
 * @param string $text Text output (all text parts joined; reasoning excluded).
 * @param string $model Model reported by the provider (or the requested one).
 * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int, web_search_requests?: int, web_fetch_requests?: int} $usage Token usage (best effort) and server tool calls.
 * @param string|null $finish_reason Provider's raw stop/finish reason.
 * @param list<array{tool: string, url: string, title?: string, page_age?: string, retrieved_at?: string}> $sources Pages the server tools returned (search results and fetched pages), in order.
 * @param list<array{tool: string, error_code: string}> $tool_errors Server tool calls that failed (the model saw the error and went on).
 * @param int|null $first_token_ms Streams: milliseconds until the first piece of text.
 */
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly array $usage = [],
        public readonly ?string $finish_reason = null,
        public readonly array $sources = [],
        public readonly array $tool_errors = [],
        public readonly ?int $first_token_ms = null
    ) {
    }

/**
 * Whether the output was cut by the token limit.
 *
 * @return bool
 */
    public function isTruncated(): bool {
        return $this->finish_reason !== null && in_array($this->finish_reason, self::TRUNCATED_REASONS, true);
    }

}
