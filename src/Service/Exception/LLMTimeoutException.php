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
 * The request timed out or never got an HTTP status (network error).
 *
 * `context()['error']` holds the transport error when known. LLMService retries once.
 */
class LLMTimeoutException extends LLMProviderException {
}
