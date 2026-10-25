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
 * The model, or the provider's safety filters, declined to answer.
 *
 * Claude `stop_reason: refusal`, an OpenAI `refusal` message, or a Gemini
 * safety block. Not retried: the same request would be declined again.
 * `context()['category']` holds the provider's reason when it gives one.
 */
class LLMRefusalException extends LLMException {
}
