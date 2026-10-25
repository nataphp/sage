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
use Sage\Service\Exception\LLMRefusalException;
use Sage\Service\Provider\OpenAIProvider;
use Sage\Service\Transport\FakeHttpTransport;

/**
 * OpenAI Chat Completions adapter.
 */
class OpenAIProviderTest extends TestCase {

/**
 * Limits, effort, strict JSON Schema and images.
 *
 * @return void
 */
    public function testPayload(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, [
            'model' => 'gpt-x',
            'choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
        ]);
        $schema = ['type' => 'object'];

        $response = (new OpenAIProvider($transport, 'key', OpenAIProvider::DEFAULT_BASE_URL))->complete(new CompletionRequest(
            'System.', 'Question?', 'openai', 'gpt-x',
            max_tokens: 100,
            output_mode: 'json',
            attachments: [new Attachment('image/png', 'png-bytes')],
            json_schema: $schema,
            effort: 'xhigh'
        ));

        $this->assertSame('{"a":1}', $response->text);
        $this->assertSame('stop', $response->finish_reason);
        $payload = $transport->lastPayload();
        $this->assertSame(100, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
        $this->assertSame('high', $payload['reasoning_effort']);
        $this->assertSame(['name' => 'result', 'schema' => $schema, 'strict' => true], $payload['response_format']['json_schema']);
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertSame('data:image/png;base64,' . base64_encode('png-bytes'), $payload['messages'][1]['content'][1]['image_url']['url']);
        $this->assertSame('Bearer key', $transport->requests[0]['options']['header']['Authorization']);
    }

/**
 * A refusal message throws.
 *
 * @return void
 */
    public function testRefusal(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, ['choices' => [['message' => ['content' => null, 'refusal' => 'No.'], 'finish_reason' => 'stop']]]);

        $this->expectException(LLMRefusalException::class);
        (new OpenAIProvider($transport, 'key', OpenAIProvider::DEFAULT_BASE_URL))->complete(new CompletionRequest('', 'Q', 'openai', 'gpt-x'));
    }

}
