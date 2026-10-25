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
 * LLM provider adapter: translates a CompletionRequest into the provider's
 * API, and its response into a CompletionResponse.
 *
 * A custom provider (another API, a local model) implements this and is passed
 * to LLMService, or registered with `Sage.providers.{key}.className`.
 */
interface ProviderInterface {

/**
 * Send the request and return the normalised response.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Resolved request.
 * @return \Sage\Service\Dto\CompletionResponse
 * @throws \Sage\Service\Exception\LLMTimeoutException On timeout or network error.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMRefusalException When the model declines.
 * @throws \Sage\Service\Exception\LLMProviderException On any other provider error.
 */
    public function complete(CompletionRequest $request): CompletionResponse;

}
