<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Exception;

/**
 * The provider rate-limited the request (HTTP 429). Not retried automatically.
 *
 * `context()['retry_after']` holds the provider's `Retry-After` (seconds) when sent.
 */
class LLMRateLimitedException extends LLMProviderException {
}
