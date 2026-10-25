<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Provider;

use PHPUnit\Framework\TestCase;
use Sage\Service\Dto\CompletionRequest;
use Sage\Service\Exception\LLMRefusalException;
use Sage\Service\Provider\GeminiProvider;
use Sage\Service\Transport\FakeHttpTransport;

/**
 * Gemini generateContent adapter.
 */
class GeminiProviderTest extends TestCase {

/**
 * The key goes in a header, the system prompt in systemInstruction, the
 * schema in responseJsonSchema; thought parts are left out of the text.
 *
 * @return void
 */
    public function testPayload(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, [
            'modelVersion' => 'gemini-x-001',
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
                ['text' => 'thinking...', 'thought' => true],
                ['text' => '{"a":1}'],
            ]]]],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'totalTokenCount' => 5],
        ]);
        $schema = ['type' => 'object'];

        $response = (new GeminiProvider($transport, 'secret'))->complete(new CompletionRequest(
            'System.', 'Question?', 'gemini', 'gemini-x', output_mode: 'json', json_schema: $schema
        ));

        $this->assertSame('{"a":1}', $response->text);
        $this->assertSame('gemini-x-001', $response->model);
        $request = $transport->requests[0];
        $this->assertStringNotContainsString('secret', $request['url']);
        $this->assertStringEndsWith('/models/gemini-x:generateContent', $request['url']);
        $this->assertSame('secret', $request['options']['header']['x-goog-api-key']);
        $payload = $transport->lastPayload();
        $this->assertSame('System.', $payload['systemInstruction']['parts'][0]['text']);
        $this->assertSame('Question?', $payload['contents'][0]['parts'][0]['text']);
        $this->assertSame($schema, $payload['generationConfig']['responseJsonSchema']);
        $this->assertSame(0, $payload['generationConfig']['thinkingConfig']['thinkingBudget']);
    }

/**
 * Gemini 3 gets the effort as thinkingLevel and never a thinking budget.
 *
 * @return void
 */
    public function testGemini3ThinkingLevel(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'ok']]]]]]);
        $transport->queueJson(200, ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'ok']]]]]]);
        $provider = new GeminiProvider($transport, 'secret');

        $provider->complete(new CompletionRequest('', 'Q', 'gemini', 'gemini-3.8-flash', effort: 'max'));
        $this->assertSame(['thinkingLevel' => 'high'], $transport->lastPayload()['generationConfig']['thinkingConfig']);

        $provider->complete(new CompletionRequest('', 'Q', 'gemini', 'gemini-3.8-flash'));
        $this->assertArrayNotHasKey('generationConfig', $transport->lastPayload());

        $this->assertSame(2, GeminiProvider::generation('gemini-2.5-flash'));
        $this->assertSame(0, GeminiProvider::generation('learnlm-1'));
    }

/**
 * A safety block throws.
 *
 * @return void
 */
    public function testBlocked(): void {
        $transport = new FakeHttpTransport();
        $transport->queueJson(200, ['candidates' => [['finishReason' => 'SAFETY']]]);

        $this->expectException(LLMRefusalException::class);
        (new GeminiProvider($transport, 'secret', GeminiProvider::DEFAULT_BASE_URL, false))->complete(new CompletionRequest('', 'Q', 'gemini', 'gemini-x'));
    }

}
