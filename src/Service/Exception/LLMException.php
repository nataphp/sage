<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for every Sage error.
 *
 * Carries a structured `context()` array (action, provider, HTTP status...)
 * that can be passed straight to a logger. Catch this class to handle any Sage
 * error in one place, or a subclass for finer handling:
 *
 * - LLMValidationException: bad input, unknown action, invalid JSON output (422).
 * - LLMRefusalException: the model or its safety filters declined (422).
 * - LLMRateLimitedException: provider rate limit (429).
 * - LLMTimeoutException: no response after the retry (504).
 * - LLMProviderException: any other provider error after the retry (502).
 */
class LLMException extends RuntimeException {

/**
 * Structured metadata about the failure.
 *
 * @var array<string, mixed>
 */
    protected array $_context;

/**
 * Constructor.
 *
 * @param string $message Human-readable description (safe for logs).
 * @param int $code Exception code (unused internally).
 * @param \Throwable|null $previous Previous exception.
 * @param array<string, mixed> $context Structured metadata.
 */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = []) {
        parent::__construct($message, $code, $previous);
        $this->_context = $context;
    }

/**
 * Structured metadata about the failure, for logging.
 *
 * @return array<string, mixed>
 */
    public function context(): array {
        return $this->_context;
    }

}
