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
use Sage\Service\Transport\HttpTransportInterface;

/**
 * Google Gemini adapter (`POST /models/{model}:generateContent`).
 *
 * - The system prompt goes to `systemInstruction`; the API key to the
 *   `x-goog-api-key` header (never in the URL, which ends up in logs).
 * - JSON mode: `responseMimeType: application/json`, plus `responseJsonSchema`
 *   when the action gives a JSON Schema.
 * - Gemini 3+: effort becomes `thinkingLevel` (thinking cannot be turned off;
 *   leave temperature unset, these models no longer take sampling parameters).
 * - Gemini 2.x: thinking is turned off by default (`thinkingBudget: 0`), since
 *   on 2.5 Flash it shares `maxOutputTokens` and truncates JSON. Pass
 *   `disableThinking: false` for models that cannot turn it off (2.5 Pro).
 * - Safety blocks throw LLMRefusalException.
 * - Attachments: images, PDF, text, audio and video inline.
 */
final class GeminiProvider extends AbstractHttpProvider {

    public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

/**
 * MIME types accepted as inline data.
 *
 * @var list<string>
 */
    public const INLINE_MIMES = [
        'image/png', 'image/jpeg', 'image/webp', 'image/heic', 'image/heif',
        'application/pdf', 'text/plain', 'text/html', 'text/css', 'text/javascript', 'text/x-typescript',
        'text/csv', 'text/markdown', 'text/xml', 'text/rtf', 'application/rtf',
        'audio/wav', 'audio/mp3', 'audio/mpeg', 'audio/aiff', 'audio/aac', 'audio/ogg', 'audio/flac',
        'video/mp4', 'video/mpeg', 'video/mov', 'video/quicktime', 'video/avi', 'video/webm', 'video/wmv', 'video/3gpp',
    ];

/**
 * Finish reasons that mean the answer was blocked.
 *
 * @var list<string>
 */
    public const BLOCKED_REASONS = ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'IMAGE_SAFETY'];

/**
 * Provider name used in exception messages.
 *
 * @var string
 */
    protected string $_name = 'Gemini';

/**
 * Constructor.
 *
 * @param \Sage\Service\Transport\HttpTransportInterface $http Transport.
 * @param string $apiKey Gemini API key.
 * @param string $baseUrl API base URL.
 * @param bool $disableThinking Send `thinkingBudget: 0` to Gemini 2.x models.
 */
    public function __construct(
        HttpTransportInterface $http,
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        protected bool $disableThinking = true
    ) {
        parent::__construct($http, $apiKey, $baseUrl);
    }

/**
 * Send the request to generateContent.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Resolved request.
 * @return \Sage\Service\Dto\CompletionResponse
 * @throws \Sage\Service\Exception\LLMTimeoutException On timeout.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMRefusalException When the answer is blocked.
 * @throws \Sage\Service\Exception\LLMProviderException On other errors or an empty answer.
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported attachment.
 */
    public function complete(CompletionRequest $request): CompletionResponse {
        $parts = [['text' => $request->user_prompt]];
        foreach ($request->attachments as $attachment) {
            if (!in_array($attachment->mime, self::INLINE_MIMES, true)) {
                throw new LLMValidationException('Gemini does not accept this attachment type.', 0, null, ['mime' => $attachment->mime, 'name' => $attachment->name]);
            }
            $parts[] = ['inline_data' => ['mime_type' => $attachment->mime, 'data' => $attachment->base64()]];
        }

        $generationConfig = [];
        if ($request->temperature !== null) {
            $generationConfig['temperature'] = $request->temperature;
        }
        if ($request->max_tokens !== null) {
            $generationConfig['maxOutputTokens'] = $request->max_tokens;
        }
        if ($request->wantsJson()) {
            $generationConfig['responseMimeType'] = 'application/json';
            if ($request->json_schema !== null) {
                $generationConfig['responseJsonSchema'] = $request->json_schema;
            }
        }
        $thinkingConfig = $this->_thinkingConfig($request);
        if ($thinkingConfig !== null) {
            $generationConfig['thinkingConfig'] = $thinkingConfig;
        }

        $payload = ['contents' => [['role' => 'user', 'parts' => $parts]]];
        if ($request->system_prompt !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $request->system_prompt]]];
        }
        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        $url = $this->baseUrl . '/models/' . rawurlencode($request->model) . ':generateContent';
        $data = $this->_postJson($url, $payload, ['x-goog-api-key' => $this->apiKey], $request->timeout_seconds);

        if (!empty($data['promptFeedback']['blockReason'])) {
            throw new LLMRefusalException('Gemini blocked the prompt.', 0, null, ['category' => (string)$data['promptFeedback']['blockReason']]);
        }

        $candidate = $data['candidates'][0] ?? [];
        $finishReason = isset($candidate['finishReason']) ? (string)$candidate['finishReason'] : null;
        if ($finishReason !== null && in_array($finishReason, self::BLOCKED_REASONS, true)) {
            throw new LLMRefusalException('Gemini blocked the answer.', 0, null, ['category' => $finishReason]);
        }

        $text = '';
        foreach ((array)($candidate['content']['parts'] ?? []) as $part) {
            // Thought summaries (`thought: true`) are not part of the answer.
            if (is_array($part) && isset($part['text']) && empty($part['thought'])) {
                $text .= (string)$part['text'];
            }
        }
        $text = trim($text);
        if ($text === '' && $finishReason !== 'MAX_TOKENS') {
            throw new LLMProviderException('Gemini response missing candidate text.', 0, null, ['finish_reason' => $finishReason]);
        }

        $usage = $data['usageMetadata'] ?? [];
        return new CompletionResponse(
            $text,
            (string)($data['modelVersion'] ?? $request->model),
            $this->_usage([
                'prompt_tokens' => $usage['promptTokenCount'] ?? null,
                'completion_tokens' => $usage['candidatesTokenCount'] ?? null,
                'total_tokens' => $usage['totalTokenCount'] ?? null,
            ]),
            $finishReason
        );
    }

/**
 * Thinking configuration for the model's generation.
 *
 * Gemini 3+ takes a `thinkingLevel` (low, medium, high) and cannot turn
 * thinking off: the effort is mapped to it (`xhigh`/`max` → high, none →
 * the model default). Gemini 2.x takes a token budget: 0 turns it off when
 * `disableThinking` is set; effort is ignored there.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Request.
 * @return array<string, mixed>|null Config, or null to send none.
 */
    protected function _thinkingConfig(CompletionRequest $request): ?array {
        if (self::generation($request->model) >= 3) {
            if ($request->effort === null) {
                return null;
            }
            return ['thinkingLevel' => in_array($request->effort, ['xhigh', 'max'], true) ? 'high' : $request->effort];
        }

        return $this->disableThinking ? ['thinkingBudget' => 0] : null;
    }

/**
 * Major version of a Gemini model id ('gemini-3.8-flash' → 3).
 *
 * @param string $model Model id.
 * @return int Major version, 0 when unknown.
 */
    public static function generation(string $model): int {
        return preg_match('/^(?:models\/)?gemini-(\d+)/i', $model, $matches) === 1 ? (int)$matches[1] : 0;
    }

}
