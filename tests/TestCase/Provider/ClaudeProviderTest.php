<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Provider;

use PHPUnit\Framework\TestCase;
use Sage\Service\Dto\Attachment;
use Sage\Service\Dto\CompletionRequest;
use Sage\Service\Exception\LLMRateLimitedException;
use Sage\Service\Exception\LLMRefusalException;
use Sage\Service\Exception\LLMTimeoutException;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\Provider\ClaudeProvider;
use Sage\Service\Transport\FakeHttpTransport;
use Sage\Service\Transport\HttpTransportResponse;

/**
 * Claude Messages API adapter.
 */
class ClaudeProviderTest extends TestCase {

/**
 * Build a request.
 *
 * @param array<string, mixed> $overrides Constructor arguments by name.
 * @return \Sage\Service\Dto\CompletionRequest
 */
    protected function _request(array $overrides = []): CompletionRequest {
        return new CompletionRequest(...$overrides + [
            'system_prompt' => 'System.',
            'user_prompt' => 'Question?',
            'provider' => 'claude',
            'model' => 'claude-opus-5-5',
        ]);
    }

/**
 * Defaults: no temperature, a generous max_tokens, headers, system prompt;
 * the text joins the text blocks and skips thinking.
 *
 * @return void
 */
    public function testPayloadAndTextBlocks(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, [
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => ''],
                ['type' => 'text', 'text' => 'Olá '],
                ['type' => 'text', 'text' => 'mundo'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4, 'cache_read_input_tokens' => 5],
        ]);

        $response = (new ClaudeProvider($transport, 'key'))->complete($this->_request());

        $this->assertSame('Olá mundo', $response->text);
        $this->assertSame('end_turn', $response->finish_reason);
        $this->assertSame(['prompt_tokens' => 15, 'completion_tokens' => 4, 'total_tokens' => 19], $response->usage);

        $payload = $transport->lastPayload();
        $this->assertSame('System.', $payload['system']);
        $this->assertSame(ClaudeProvider::DEFAULT_MAX_TOKENS, $payload['max_tokens']);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertArrayNotHasKey('output_config', $payload);
        $headers = $transport->requests[0]['options']['header'];
        $this->assertSame('key', $headers['x-api-key']);
        $this->assertSame(ClaudeProvider::API_VERSION, $headers['anthropic-version']);
        $this->assertStringEndsWith('/messages', $transport->requests[0]['url']);
    }

/**
 * A JSON Schema becomes native structured output; effort goes to output_config;
 * fallbacks add the beta header.
 *
 * @return void
 */
    public function testStructuredOutputEffortAndFallbacks(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, ['content' => [['type' => 'text', 'text' => '{}']]]);
        $schema = ['type' => 'object', 'properties' => []];

        (new ClaudeProvider($transport, 'key', ClaudeProvider::DEFAULT_BASE_URL, 'default'))->complete($this->_request([
            'output_mode' => 'json',
            'json_schema' => $schema,
            'effort' => 'low',
        ]));

        $payload = $transport->lastPayload();
        $this->assertSame(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]], $payload['output_config']);
        $this->assertSame('Question?', $payload['messages'][0]['content']);
        $this->assertSame('default', $payload['fallbacks']);
        $this->assertSame('server-side-fallback-2026-07-01', $transport->requests[0]['options']['header']['anthropic-beta']);
    }

/**
 * Without a schema, JSON mode is asked for in the prompt.
 *
 * @return void
 */
    public function testJsonWithoutSchemaUsesInstruction(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, ['content' => [['type' => 'text', 'text' => '{}']]]);

        (new ClaudeProvider($transport, 'key'))->complete($this->_request(['output_mode' => 'json']));

        $this->assertStringContainsString('Return ONLY valid JSON', $transport->lastPayload()['messages'][0]['content']);
    }

/**
 * Images and PDFs go before the text; other types are refused.
 *
 * @return void
 */
    public function testAttachments(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, ['content' => [['type' => 'text', 'text' => 'ok']]]);

        (new ClaudeProvider($transport, 'key'))->complete($this->_request(['attachments' => [
            new Attachment('image/jpeg', 'jpeg-bytes'),
            new Attachment('application/pdf', 'pdf-bytes'),
        ]]));

        $content = $transport->lastPayload()['messages'][0]['content'];
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame(base64_encode('jpeg-bytes'), $content[0]['source']['data']);
        $this->assertSame('document', $content[1]['type']);
        $this->assertSame(['type' => 'text', 'text' => 'Question?'], $content[2]);

        $this->expectException(LLMValidationException::class);
        (new ClaudeProvider($transport, 'key'))->complete($this->_request(['attachments' => [new Attachment('audio/mpeg', 'mp3')]]));
    }

/**
 * A refusal throws with the category.
 *
 * @return void
 */
    public function testRefusal(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, ['stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber'], 'content' => []]);

        try {
            (new ClaudeProvider($transport, 'key'))->complete($this->_request());
            $this->fail('Expected a refusal.');
        } catch (LLMRefusalException $exception) {
            $this->assertSame('cyber', $exception->context()['category']);
        }
    }

/**
 * Server tools go to `tools` with their type and settings; the pages returned
 * become sources, failed calls tool errors, and the calls are counted.
 *
 * @return void
 */
    public function testServerTools(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, [
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'feriado municipal']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [
                    ['type' => 'web_search_result', 'url' => 'https://cm-vila.pt/feriado', 'title' => 'Feriado municipal', 'encrypted_content' => 'x', 'page_age' => 'June 1, 2026'],
                ]],
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_2', 'name' => 'web_fetch', 'input' => ['url' => 'https://cm-vila.pt/feriado']],
                ['type' => 'web_fetch_tool_result', 'tool_use_id' => 'srvtoolu_2', 'content' => ['type' => 'web_fetch_result', 'url' => 'https://cm-vila.pt/feriado', 'content' => ['type' => 'document'], 'retrieved_at' => '2026-10-30T10:00:00Z']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_3', 'content' => ['type' => 'web_search_tool_result_error', 'error_code' => 'max_uses_exceeded']],
                ['type' => 'text', 'text' => '24 de junho', 'citations' => [['type' => 'web_search_result_location', 'url' => 'https://cm-vila.pt/feriado']]],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'server_tool_use' => ['web_search_requests' => 2, 'web_fetch_requests' => 1]],
        ]);

        $response = (new ClaudeProvider($transport, 'key'))->complete($this->_request(['tools' => ['web_search' => ['name' => 'web_search', 'max_uses' => 3, 'allowed_domains' => ['cm-vila.pt']], 'web_fetch']]));

        $this->assertSame([
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 3, 'allowed_domains' => ['cm-vila.pt']],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch'],
        ], $transport->lastPayload()['tools']);
        $this->assertSame('24 de junho', $response->text);
        $this->assertSame([
            ['tool' => 'web_search', 'url' => 'https://cm-vila.pt/feriado', 'title' => 'Feriado municipal', 'page_age' => 'June 1, 2026'],
            ['tool' => 'web_fetch', 'url' => 'https://cm-vila.pt/feriado', 'retrieved_at' => '2026-10-30T10:00:00Z'],
        ], $response->sources);
        $this->assertSame([['tool' => 'web_search', 'error_code' => 'max_uses_exceeded']], $response->tool_errors);
        $this->assertSame(['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120, 'web_search_requests' => 2, 'web_fetch_requests' => 1], $response->usage);

        // A tool's own type wins (older models take the basic versions).
        $transport->queueJson(200, ['content' => [['type' => 'text', 'text' => 'ok']]]);
        (new ClaudeProvider($transport, 'key'))->complete($this->_request(['tools' => [['name' => 'web_search', 'type' => 'web_search_20250305']]]));
        $this->assertSame([['type' => 'web_search_20250305', 'name' => 'web_search']], $transport->lastPayload()['tools']);
    }

/**
 * A paused server-tool turn is resumed with the paused assistant turn sent
 * back unchanged (no extra user message); text, sources and usage add up.
 *
 * @return void
 */
    public function testPauseTurnIsResumed(): void {
        $paused = [
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'q']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [['type' => 'web_search_result', 'url' => 'https://a.test', 'title' => 'A', 'encrypted_content' => 'enc']]],
            ['type' => 'text', 'text' => 'A procurar… '],
        ];
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, ['stop_reason' => 'pause_turn', 'content' => $paused, 'usage' => ['input_tokens' => 50, 'output_tokens' => 5, 'server_tool_use' => ['web_search_requests' => 1]]]);
        $transport->queueJson(200, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'feito']], 'usage' => ['input_tokens' => 70, 'output_tokens' => 7, 'server_tool_use' => ['web_search_requests' => 1]]]);

        $response = (new ClaudeProvider($transport, 'key'))->complete($this->_request(['tools' => ['web_search']]));

        $this->assertCount(2, $transport->requests);
        $messages = $transport->lastPayload()['messages'];
        $this->assertSame(['user', 'assistant'], array_column($messages, 'role'));
        $this->assertSame($paused, $messages[1]['content']);
        $this->assertSame('A procurar… feito', $response->text);
        $this->assertSame('end_turn', $response->finish_reason);
        $this->assertSame([['tool' => 'web_search', 'url' => 'https://a.test', 'title' => 'A']], $response->sources);
        $this->assertSame(['prompt_tokens' => 120, 'completion_tokens' => 12, 'total_tokens' => 132, 'web_search_requests' => 2], $response->usage);
    }

/**
 * A turn that keeps pausing stops after MAX_CONTINUATIONS resumes, with
 * finish_reason pause_turn.
 *
 * @return void
 */
    public function testPauseTurnGivesUp(): void {
        $transport = new FakeHttpTransport();
        for ($i = 0; $i <= ClaudeProvider::MAX_CONTINUATIONS; $i++) {
            $transport->queueJson(200, ['stop_reason' => 'pause_turn', 'content' => [['type' => 'text', 'text' => '.']]]);
        }

        $response = (new ClaudeProvider($transport, 'key'))->complete($this->_request(['tools' => ['web_search']]));

        $this->assertCount(ClaudeProvider::MAX_CONTINUATIONS + 1, $transport->requests);
        $this->assertSame('pause_turn', $response->finish_reason);
    }

/**
 * Unknown or repeated server tools are refused.
 *
 * @return void
 */
    public function testUnknownServerTool(): void {
        try {
            $this->_request(['tools' => ['web_search', 'web_search']]);
            $this->fail('A repeated tool should be refused.');
        } catch (LLMValidationException $exception) {
            $this->assertSame('web_search', $exception->context()['tool']);
        }

        $this->expectException(LLMValidationException::class);
        $this->_request(['tools' => ['code_execution']]);
    }

/**
 * 429 carries Retry-After; status 0 is a timeout.
 *
 * @return void
 */
    public function testErrors(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(429, ['error' => ['message' => 'slow down']], ['Retry-After' => '7']);
        try {
            (new ClaudeProvider($transport, 'key'))->complete($this->_request());
            $this->fail('Expected a rate limit.');
        } catch (LLMRateLimitedException $exception) {
            $this->assertSame(7, $exception->context()['retry_after']);
        }

        $transport->queueResponse(new HttpTransportResponse(0, [], '', 'Operation timed out'));
        $this->expectException(LLMTimeoutException::class);
        (new ClaudeProvider($transport, 'key'))->complete($this->_request());
    }

}
