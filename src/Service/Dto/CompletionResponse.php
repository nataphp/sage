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
 * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $usage Token usage (best effort).
 * @param string|null $finish_reason Provider's raw stop/finish reason.
 */
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly array $usage = [],
        public readonly ?string $finish_reason = null
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
