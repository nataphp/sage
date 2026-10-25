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
 * Invalid input, unknown action/provider, or model output that fails the schema.
 *
 * Context keys that may be present: `action`, `missing` (input keys), `field`,
 * `expected`, `actual` (JSON output validation), `finish_reason`.
 */
class LLMValidationException extends LLMException {
}
