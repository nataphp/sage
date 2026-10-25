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
