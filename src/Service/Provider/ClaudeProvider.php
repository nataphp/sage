<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Provider;

use Sage\Service\Dto\Attachment;
use Sage\Service\Dto\CompletionRequest;
use Sage\Service\Dto\CompletionResponse;
use Sage\Service\Exception\LLMProviderException;
use Sage\Service\Exception\LLMRefusalException;
use Sage\Service\Exception\LLMStreamInterruptedException;
use Sage\Service\Exception\LLMTimeoutException;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\Stream\SseParser;
use Sage\Service\Transport\HttpTransportInterface;
use Sage\Service\Transport\HttpTransportResponse;
use Sage\Service\Transport\StreamingTransportInterface;

/**
 * Anthropic Claude adapter (Messages API, `POST /messages`).
 *
 * - JSON mode: with a JSON Schema, native structured output
 *   (`output_config.format`); without, an instruction appended to the prompt.
 * - Effort goes to `output_config.effort`.
 * - The text is every `text` block joined: models that think return
 *   `thinking` blocks first.
 * - `stop_reason: refusal` throws LLMRefusalException.
 * - Attachments: images (JPEG, PNG, GIF, WebP), PDF and plain text documents.
 *
 * Temperature is only sent when set; Claude Opus 4.7+ and the Claude 5 family
 * reject any explicit value, so leave it null for them.
 *
 * Optional `fallbacks` ('default' or a list of `['model' => ...]`): server-side
 * fallback to another model when the requested one declines (beta).
 *
 * Server tools (`tools` option: web_search, web_fetch) run on Anthropic's side
 * within the same call. When the server loop pauses (`stop_reason:
 * pause_turn`) the paused assistant turn is sent back unchanged to resume it,
 * up to MAX_CONTINUATIONS times. The text joins the text blocks of every
 * part; the pages returned go to `sources`, failed tool calls to
 * `tool_errors`, and the number of searches and fetches to `usage`.
 */
final class ClaudeProvider extends AbstractHttpProvider implements StreamingProviderInterface {

    public const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    public const API_VERSION = '2023-06-01';

/**
 * Output tokens when the request does not say (thinking counts towards it).
 */
    public const DEFAULT_MAX_TOKENS = 16000;

/**
 * Image formats the Messages API accepts.
 *
 * @var list<string>
 */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

/**
 * Type of each server tool (the dynamic-filtering versions; a tool's `type`
 * setting overrides it, e.g. web_search_20250305 for older models).
 *
 * @var array<string, string>
 */
    public const SERVER_TOOL_TYPES = ['web_search' => 'web_search_20260209', 'web_fetch' => 'web_fetch_20260209'];

/**
 * Most times a paused server-tool turn (`pause_turn`) is resumed.
 */
    public const MAX_CONTINUATIONS = 5;

/**
 * Provider name used in exception messages.
 *
 * @var string
 */
    protected string $_name = 'Claude';

/**
 * Constructor.
 *
 * @param \Sage\Service\Transport\HttpTransportInterface $http Transport.
 * @param string $apiKey Anthropic API key.
 * @param string $baseUrl API base URL.
 * @param string|array|null $fallbacks Server-side fallbacks: 'default', a list of
 *   `['model' => ...]`, or null for none.
 */
    public function __construct(
        HttpTransportInterface $http,
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        protected string|array|null $fallbacks = null
    ) {
        parent::__construct($http, $apiKey, $baseUrl);
    }

/**
 * Send the request to the Messages API.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Resolved request.
 * @return \Sage\Service\Dto\CompletionResponse
 * @throws \Sage\Service\Exception\LLMTimeoutException On timeout.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMRefusalException When the model declines.
 * @throws \Sage\Service\Exception\LLMProviderException On other errors.
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported attachment.
 */
    public function complete(CompletionRequest $request): CompletionResponse {
        $payload = $this->_payload($request);
        $headers = $this->_headers($payload);

        // Every part of the turn: one, or more when a server-tool turn pauses.
        $blocks = [];
        $promptTokens = null;
        $completionTokens = null;
        $serverToolUse = [];
        $continuations = 0;
        while (true) {
            $data = $this->_postJson($this->baseUrl . '/messages', $payload, $headers, $request->timeout_seconds);

            $stopReason = isset($data['stop_reason']) ? (string)$data['stop_reason'] : null;
            if ($stopReason === 'refusal') {
                throw new LLMRefusalException('Claude declined the request.', 0, null, [
                    'category' => $data['stop_details']['category'] ?? null,
                    'explanation' => $data['stop_details']['explanation'] ?? null,
                ]);
            }

            $content = array_values(array_filter((array)($data['content'] ?? []), 'is_array'));
            $blocks = array_merge($blocks, $content);

            $usage = $data['usage'] ?? [];
            if (isset($usage['input_tokens'])) {
                $promptTokens = (int)$promptTokens + $this->_inputTokens($usage);
            }
            if (isset($usage['output_tokens'])) {
                $completionTokens = (int)$completionTokens + (int)$usage['output_tokens'];
            }
            foreach ((array)($usage['server_tool_use'] ?? []) as $counter => $count) {
                $serverToolUse[$counter] = ($serverToolUse[$counter] ?? 0) + (int)$count;
            }

            if ($stopReason !== 'pause_turn' || $continuations >= self::MAX_CONTINUATIONS) {
                break;
            }
            // Resume: the paused assistant turn goes back unchanged (with the
            // encrypted search results), never with an extra user message.
            $continuations++;
            $payload['messages'] = [$payload['messages'][0], ['role' => 'assistant', 'content' => $blocks]];
        }

        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
        [$sources, $toolErrors] = $this->_toolResults($blocks);

        return new CompletionResponse(
            $text,
            (string)($data['model'] ?? $request->model),
            $this->_usage([
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens !== null && $completionTokens !== null ? $promptTokens + $completionTokens : null,
                'web_search_requests' => $serverToolUse['web_search_requests'] ?? null,
                'web_fetch_requests' => $serverToolUse['web_fetch_requests'] ?? null,
            ]),
            $stopReason,
            $sources,
            $toolErrors
        );
    }

/**
 * Stream the text of the request (Messages API with `stream: true`).
 *
 * The events: `message_start` (model, input tokens), `content_block_delta`
 * with a `text_delta` (each piece goes to `$onText`), `message_delta` (stop
 * reason, output tokens), `message_stop`, `ping`, and `error` (e.g.
 * overloaded_error, which may arrive after a 200).
 *
 * Time limits, from the start of the request: `$firstTokenTimeout` until the
 * first piece of text, `$request->timeout_seconds` for the whole stream.
 * Before the first piece every failure is an ordinary exception (timeout,
 * rate limit, provider error, refusal); after it, a stream that does not
 * reach `message_stop` throws LLMStreamInterruptedException with the text
 * that arrived. When `$onText` returns false the stream stops and the text so
 * far comes back with `finish_reason` 'stopped'.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Resolved request (text output, no tools).
 * @param callable(string): (bool|null) $onText Receives each piece of text.
 * @param float|null $firstTokenTimeout Seconds allowed until the first piece of text.
 * @return \Sage\Service\Dto\CompletionResponse
 * @throws \Sage\Service\Exception\LLMValidationException For JSON output, server tools or a transport that cannot stream.
 * @throws \Sage\Service\Exception\LLMTimeoutException When nothing arrived in time.
 * @throws \Sage\Service\Exception\LLMStreamInterruptedException When the stream stopped after its first token.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMRefusalException When the model declines.
 * @throws \Sage\Service\Exception\LLMProviderException On other errors.
 */
    public function stream(CompletionRequest $request, callable $onText, ?float $firstTokenTimeout = null): CompletionResponse {
        if ($request->wantsJson() || $request->tools !== []) {
            throw new LLMValidationException('Streaming is for text output, without server tools.', 0, null, ['provider' => 'claude']);
        }
        if (!$this->http instanceof StreamingTransportInterface) {
            throw new LLMValidationException('The HTTP transport cannot stream.', 0, null, ['provider' => 'claude', 'transport' => get_class($this->http)]);
        }

        $payload = $this->_payload($request) + ['stream' => true];
        $headers = $this->_headers($payload);
        $totalTimeout = $request->timeout_seconds;

        $parser = new SseParser();
        $state = [
            'text' => '',
            'model' => $request->model,
            'input_tokens' => null,
            'output_tokens' => null,
            'stop_reason' => null,
            'stopped' => false,
            'complete' => false,
            'error' => null,
            'first_token_at' => null,
            'timed_out' => null,
            'elapsed' => 0.0,
        ];

        // A time limit ran out: remember which, and stop the transfer.
        $checkTime = function (float $elapsed) use (&$state, $firstTokenTimeout, $totalTimeout): bool {
            $state['elapsed'] = $elapsed;
            if ($state['first_token_at'] === null && $firstTokenTimeout !== null && $elapsed >= $firstTokenTimeout) {
                $state['timed_out'] = 'first_token';

                return false;
            }
            if ($elapsed >= $totalTimeout) {
                $state['timed_out'] = 'total';

                return false;
            }

            return true;
        };

        $handleEvent = function (array $event, float $elapsed) use (&$state, $onText): bool {
            $data = json_decode($event['data'], true);
            if (!is_array($data)) {
                return true;
            }
            $type = (string)($data['type'] ?? $event['event']);
            if ($type === 'message_start') {
                $state['model'] = (string)($data['message']['model'] ?? $state['model']);
                $usage = (array)($data['message']['usage'] ?? []);
                if (isset($usage['input_tokens'])) {
                    $state['input_tokens'] = $this->_inputTokens($usage);
                }
                if (isset($usage['output_tokens'])) {
                    $state['output_tokens'] = (int)$usage['output_tokens'];
                }
            } elseif ($type === 'content_block_delta' && ($data['delta']['type'] ?? null) === 'text_delta') {
                $delta = (string)($data['delta']['text'] ?? '');
                if ($delta === '') {
                    return true;
                }
                $state['first_token_at'] ??= $elapsed;
                $state['text'] .= $delta;
                if ($onText($delta) === false) {
                    $state['stopped'] = true;

                    return false;
                }
            } elseif ($type === 'message_delta') {
                if (isset($data['delta']['stop_reason'])) {
                    $state['stop_reason'] = (string)$data['delta']['stop_reason'];
                }
                if (isset($data['usage']['output_tokens'])) {
                    $state['output_tokens'] = (int)$data['usage']['output_tokens'];
                }
            } elseif ($type === 'message_stop') {
                $state['complete'] = true;
            } elseif ($type === 'error') {
                $state['error'] = (array)($data['error'] ?? []);

                return false;
            }

            return true;
        };

        $response = $this->http->stream('POST', $this->baseUrl . '/messages', [
            // A second more than the limit: the limit itself is enforced here, on the ticks.
            'timeout' => $totalTimeout + 1,
            'header' => ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'] + $headers,
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'on_tick' => $checkTime,
        ], function (string $bytes, float $elapsed) use ($parser, $handleEvent, $checkTime): bool {
            foreach ($parser->feed($bytes) as $event) {
                if (!$handleEvent($event, $elapsed)) {
                    return false;
                }
            }

            return $checkTime($elapsed);
        });
        if (!$response->aborted && $response->status_code >= 200 && $response->status_code < 300) {
            foreach ($parser->finish() as $event) {
                if (!$handleEvent($event, $state['elapsed'])) {
                    break;
                }
            }
        }

        return $this->_streamOutcome($request, $response, $state);
    }

/**
 * The response of a stream, or the exception that fits how it ended.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Request.
 * @param \Sage\Service\Transport\HttpTransportResponse $response Transport response.
 * @param array<string, mixed> $state What the stream received (see stream()).
 * @return \Sage\Service\Dto\CompletionResponse
 * @throws \Sage\Service\Exception\LLMException See stream().
 */
    protected function _streamOutcome(CompletionRequest $request, HttpTransportResponse $response, array $state): CompletionResponse {
        $usage = $this->_usage([
            'prompt_tokens' => $state['input_tokens'],
            'completion_tokens' => $state['output_tokens'],
            'total_tokens' => $state['input_tokens'] !== null && $state['output_tokens'] !== null ? $state['input_tokens'] + $state['output_tokens'] : null,
        ]);
        $firstTokenMs = $state['first_token_at'] !== null ? (int)round($state['first_token_at'] * 1000) : null;
        $context = [
            'elapsed_ms' => (int)round($state['elapsed'] * 1000),
            'first_token_ms' => $firstTokenMs,
            'usage' => $usage,
        ];

        if ($state['stopped']) {
            return new CompletionResponse($state['text'], $state['model'], $usage, 'stopped', [], [], $firstTokenMs);
        }
        if ($response->status_code === 0 && $state['timed_out'] === null) {
            throw new LLMTimeoutException('Claude request failed (no response).', 0, null, ['phase' => 'connect', 'error' => $response->error] + $context);
        }
        if ($response->status_code >= 300 || $response->status_code === 429) {
            $this->_throwForStatus($response, $response->json());
        }
        if ($state['stop_reason'] === 'refusal') {
            throw new LLMRefusalException('Claude declined the request.', 0, null, ['partial_text' => $state['text']] + $context);
        }

        if ($state['first_token_at'] === null) {
            if ($state['timed_out'] !== null) {
                throw new LLMTimeoutException('Claude stream: no text in time.', 0, null, ['phase' => $state['timed_out'] === 'first_token' ? 'first_token' : 'total'] + $context);
            }
            if ($state['error'] !== null) {
                $status = ($state['error']['type'] ?? null) === 'overloaded_error' ? 529 : 500;
                throw new LLMProviderException((string)($state['error']['message'] ?? 'Claude stream error.'), 0, null, ['status' => $status, 'type' => $state['error']['type'] ?? null] + $context);
            }
        }

        if (!$state['complete']) {
            $reason = match (true) {
                $state['timed_out'] !== null => 'timeout',
                $state['error'] !== null => 'provider_error',
                $response->error !== '' => 'network',
                default => 'incomplete',
            };
            throw new LLMStreamInterruptedException('Claude stream interrupted (' . $reason . ').', 0, null, [
                'reason' => $reason,
                'partial_text' => $state['text'],
                'error' => $state['error']['message'] ?? $response->error,
            ] + $context);
        }

        return new CompletionResponse($state['text'], $state['model'], $usage, $state['stop_reason'], [], [], $firstTokenMs);
    }

/**
 * Input tokens of a usage block, cached ones included.
 *
 * @param array<string, mixed> $usage Usage block of the API.
 * @return int Tokens.
 */
    protected function _inputTokens(array $usage): int {
        return (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0);
    }

/**
 * Messages API payload of a request.
 *
 * @param \Sage\Service\Dto\CompletionRequest $request Request.
 * @return array<string, mixed> Payload.
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported attachment.
 */
    protected function _payload(CompletionRequest $request): array {
        $userText = $request->user_prompt;
        if ($request->wantsJson() && $request->json_schema === null) {
            $userText = rtrim($userText) . "\n\nReturn ONLY valid JSON (no prose, no markdown).";
        }

        $payload = [
            'model' => $request->model,
            'max_tokens' => $request->max_tokens ?? self::DEFAULT_MAX_TOKENS,
            'messages' => [['role' => 'user', 'content' => $this->_userContent($userText, $request->attachments)]],
        ];
        if ($request->system_prompt !== '') {
            $payload['system'] = $request->system_prompt;
        }
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        $outputConfig = [];
        if ($request->effort !== null) {
            $outputConfig['effort'] = $request->effort;
        }
        if ($request->wantsJson() && $request->json_schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $request->json_schema];
        }
        if ($outputConfig !== []) {
            $payload['output_config'] = $outputConfig;
        }
        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn(array $tool) => $this->_serverTool($tool), $request->tools);
        }

        if ($this->fallbacks !== null && $this->fallbacks !== [] && $this->fallbacks !== '') {
            $payload['fallbacks'] = $this->fallbacks;
        }

        return $payload;
    }

/**
 * Headers of a request with its payload (the fallbacks ask for a beta header).
 *
 * @param array<string, mixed> $payload Payload.
 * @return array<string, string> Headers.
 */
    protected function _headers(array $payload): array {
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
        ];
        if (isset($payload['fallbacks'])) {
            $headers['anthropic-beta'] = is_string($payload['fallbacks']) ? 'server-side-fallback-2026-07-01' : 'server-side-fallback-2026-06-01';
        }

        return $headers;
    }

/**
 * Definition of one server tool for the Messages API.
 *
 * @param array<string, mixed> $tool `['name' => ..., ...settings]` (CompletionRequest).
 * @return array<string, mixed>
 */
    protected function _serverTool(array $tool): array {
        $name = (string)$tool['name'];
        unset($tool['name']);

        return ['type' => (string)($tool['type'] ?? self::SERVER_TOOL_TYPES[$name]), 'name' => $name] + $tool;
    }

/**
 * Pages returned by the server tools, and their failed calls.
 *
 * A search result block holds a list of results, or one error object; a
 * fetch result block holds the fetched page, or one error object.
 *
 * @param list<array<string, mixed>> $blocks Content blocks of the turn.
 * @return array{0: list<array<string, string>>, 1: list<array{tool: string, error_code: string}>} Sources and errors.
 */
    protected function _toolResults(array $blocks): array {
        $sources = [];
        $errors = [];
        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;
            if ($type !== 'web_search_tool_result' && $type !== 'web_fetch_tool_result') {
                continue;
            }
            $tool = $type === 'web_search_tool_result' ? 'web_search' : 'web_fetch';
            $content = $block['content'] ?? [];
            if (is_array($content) && isset($content['error_code'])) {
                $errors[] = ['tool' => $tool, 'error_code' => (string)$content['error_code']];
                continue;
            }
            $results = $tool === 'web_search' ? (array)$content : [$content];
            foreach ($results as $result) {
                if (!is_array($result) || !isset($result['url'])) {
                    continue;
                }
                $source = ['tool' => $tool, 'url' => (string)$result['url']];
                foreach (['title', 'page_age', 'retrieved_at'] as $field) {
                    if (isset($result[$field])) {
                        $source[$field] = (string)$result[$field];
                    }
                }
                $sources[] = $source;
            }
        }

        return [$sources, $errors];
    }

/**
 * User message content: a string, or attachment blocks followed by the text.
 *
 * @param string $text Prompt text.
 * @param list<\Sage\Service\Dto\Attachment> $attachments Attachments.
 * @return string|array<int, array<string, mixed>>
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported attachment.
 */
    protected function _userContent(string $text, array $attachments): string|array {
        if ($attachments === []) {
            return $text;
        }

        $blocks = array_map(fn(Attachment $attachment) => $this->_attachmentBlock($attachment), $attachments);
        // Attachments before the text, as Anthropic recommends.
        $blocks[] = ['type' => 'text', 'text' => $text];
        return $blocks;
    }

/**
 * Content block of one attachment.
 *
 * @param \Sage\Service\Dto\Attachment $attachment Attachment.
 * @return array<string, mixed>
 * @throws \Sage\Service\Exception\LLMValidationException On an unsupported type.
 */
    protected function _attachmentBlock(Attachment $attachment): array {
        if (in_array($attachment->mime, self::IMAGE_MIMES, true)) {
            return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $attachment->mime, 'data' => $attachment->base64()]];
        }
        if ($attachment->mime === 'application/pdf') {
            return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $attachment->base64()]];
        }
        if ($attachment->mime === 'text/plain') {
            return ['type' => 'document', 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => $attachment->data]];
        }
        throw new LLMValidationException('Claude does not accept this attachment type.', 0, null, ['mime' => $attachment->mime, 'name' => $attachment->name]);
    }

}
