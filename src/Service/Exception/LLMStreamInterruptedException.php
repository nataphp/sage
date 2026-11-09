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
 * A stream stopped after its first token: the time limit ran out, the
 * connection dropped or the provider sent an error event. The text that
 * arrived (already handed to the callback) is in partialText().
 *
 * `context()`: `reason` (timeout|network|provider_error|incomplete), `partial_text`,
 * `elapsed_ms`, `first_token_ms`, `usage` and the transport or provider error.
 */
class LLMStreamInterruptedException extends LLMProviderException {

/**
 * The text that arrived before the stream stopped.
 *
 * @return string Partial text.
 */
    public function partialText(): string {
        return (string)($this->_context['partial_text'] ?? '');
    }

}
