<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Provider;

use Sage\Service\Dto\CompletionRequest;
use Sage\Service\Dto\CompletionResponse;

/**
 * A provider that can stream its text output (`Sage::stream()`).
 */
interface StreamingProviderInterface {

/**
 * Send the request and hand the text to `$onText` as it is generated.
 *
 * `$onText(string $delta): bool|null` receives each piece of text; returning
 * false stops the stream (the response then has `finish_reason` 'stopped').
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Resolved request (text output, no tools);
 *   `timeout_seconds` limits the whole stream.
 * @param callable(string): (bool|null) $onText Receives each piece of text.
 * @param float|null $firstTokenTimeout Seconds allowed until the first piece of text; null: no
 *   limit but the whole one.
 * @return \Sage\Service\Dto\CompletionResponse The whole text, with `first_token_ms`.
 * @throws \Sage\Service\Exception\LLMTimeoutException When nothing arrived in time (context `phase`:
 *   first_token or connect).
 * @throws \Sage\Service\Exception\LLMStreamInterruptedException When the stream stopped after its first token.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMRefusalException When the model declines.
 * @throws \Sage\Service\Exception\LLMProviderException On any other provider error.
 */
    public function stream(CompletionRequest $request, callable $onText, ?float $firstTokenTimeout = null): CompletionResponse;

}
