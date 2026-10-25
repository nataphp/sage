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
 * The provider answered with an error (HTTP 4xx/5xx other than 429).
 *
 * `context()['status']` holds the HTTP status. LLMService retries once on 5xx.
 */
class LLMProviderException extends LLMException {
}
