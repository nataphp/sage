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
use Sage\Service\Exception\LLMProviderException;
use Sage\Service\Exception\LLMRefusalException;
use Sage\Service\Exception\LLMValidationException;

/**
 * OpenAI adapter (Chat Completions, `POST /chat/completions`).
 *
 * - JSON mode: with a JSON Schema, `response_format: json_schema` (strict);
 *   without, `response_format: json_object`.
 * - Effort goes to `reasoning_effort` (reasoning models only: leave it null
 *   for the others). `xhigh`/`max` are sent as `high`.
 * - The output limit is sent as `max_completion_tokens` (`max_tokens` is
 *   rejected by reasoning models).
 * - A `refusal` message throws LLMRefusalException.
 * - Attachments: images only (JPEG, PNG, GIF, WebP).
 */
final class OpenAIProvider extends AbstractHttpProvider {

    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

/**
 * Image formats Chat Completions accepts.
 *
 * @var list<string>
 */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

/**
 * Provider name used in exception messages.
 *
 * @var string
 */
    protected string $_name = 'OpenAI';

/**
 * Send the request to Chat Completions.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Resolved request.
 * @return \Sage\Service\Dto\CompletionResponse
 * @throws \Sage\Service\Exception\LLMTimeoutException On timeout.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMRefusalException When the model declines.
 * @throws \Sage\Service\Exception\LLMProviderException On other errors or an empty answer.
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported attachment or server tools (Claude only).
 */
    public function complete(CompletionRequest $request): CompletionResponse {
        if ($request->tools !== []) {
            throw new LLMValidationException('Server tools (web_search, web_fetch) are only supported on Claude.', 0, null, ['provider' => 'openai']);
        }
        $messages = [];
        if ($request->system_prompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system_prompt];
        }
        $messages[] = ['role' => 'user', 'content' => $this->_userContent($request->user_prompt, $request->attachments)];

        $payload = ['model' => $request->model, 'messages' => $messages];
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->max_tokens !== null) {
            $payload['max_completion_tokens'] = $request->max_tokens;
        }
        if ($request->effort !== null) {
            $payload['reasoning_effort'] = in_array($request->effort, ['xhigh', 'max'], true) ? 'high' : $request->effort;
        }
        if ($request->wantsJson()) {
            $payload['response_format'] = $request->json_schema !== null
                ? ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => $request->json_schema, 'strict' => true]]
                : ['type' => 'json_object'];
        }

        $data = $this->_postJson($this->baseUrl . '/chat/completions', $payload, ['Authorization' => 'Bearer ' . $this->apiKey], $request->timeout_seconds);

        $choice = $data['choices'][0] ?? [];
        $message = is_array($choice) ? ($choice['message'] ?? []) : [];
        if (!empty($message['refusal'])) {
            throw new LLMRefusalException('OpenAI declined the request.', 0, null, ['explanation' => (string)$message['refusal']]);
        }
        $finishReason = isset($choice['finish_reason']) ? (string)$choice['finish_reason'] : null;
        if ($finishReason === 'content_filter') {
            throw new LLMRefusalException('OpenAI content filter blocked the answer.', 0, null, ['category' => 'content_filter']);
        }

        $text = (string)($message['content'] ?? '');
        if ($text === '' && $finishReason !== 'length') {
            throw new LLMProviderException('OpenAI response missing message content.', 0, null, ['finish_reason' => $finishReason]);
        }

        $usage = $data['usage'] ?? [];
        return new CompletionResponse(
            $text,
            (string)($data['model'] ?? $request->model),
            $this->_usage([
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
            ]),
            $finishReason
        );
    }

/**
 * User message content: a string, or the text followed by image parts.
 *
 * @param string $text Prompt text.
 * @param list<\Sage\Service\Dto\Attachment> $attachments Attachments.
 * @return string|array<int, array<string, mixed>>
 * @throws \Sage\Service\Exception\LLMValidationException On a non-image attachment.
 */
    protected function _userContent(string $text, array $attachments): string|array {
        if ($attachments === []) {
            return $text;
        }

        $parts = [['type' => 'text', 'text' => $text]];
        foreach ($attachments as $attachment) {
            if (!in_array($attachment->mime, self::IMAGE_MIMES, true)) {
                throw new LLMValidationException('OpenAI Chat Completions only accepts image attachments.', 0, null, ['mime' => $attachment->mime, 'name' => $attachment->name]);
            }
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $attachment->dataUri()]];
        }
        return $parts;
    }

}
