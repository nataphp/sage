<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Service;

use PHPUnit\Framework\TestCase;
use Sage\Service\ActionRegistry;
use Sage\Service\Exception\LLMTimeoutException;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\LLMService;
use Sage\Service\Provider\ClaudeProvider;
use Sage\Service\Provider\OpenAIProvider;
use Sage\Service\Transport\FakeHttpTransport;
use Sage\Test\TestCase\Fixture\EchoTextAction;
use Sage\Test\TestCase\Fixture\ExtractTotalAction;

/**
 * LLMService::stream(): options, no retries, what cannot stream.
 */
class LLMServiceStreamTest extends TestCase {

/**
 * Fake transport.
 *
 * @var \Sage\Service\Transport\FakeHttpTransport
 */
    protected FakeHttpTransport $_transport;

/**
 * New transport per test.
 *
 * @return void
 */
    protected function setUp(): void {
        parent::setUp();
        $this->_transport = new FakeHttpTransport();
    }

/**
 * Service over the fake transport.
 *
 * @param array<string, mixed> $actions Actions.
 * @param string $action Bound action.
 * @return \Sage\Service\LLMService
 */
    protected function _service(array $actions, string $action): LLMService {
        return new LLMService(
            new ActionRegistry($actions),
            [
                'claude' => new ClaudeProvider($this->_transport, 'key'),
                'openai' => new OpenAIProvider($this->_transport, 'key', OpenAIProvider::DEFAULT_BASE_URL),
            ],
            ['provider' => 'claude', 'models' => ['claude' => 'claude-default', 'openai' => 'gpt-default'], 'timeout_seconds' => 30, 'retry_delay_ms' => 0],
            $action
        );
    }

/**
 * Events of a short answer.
 *
 * @param string $text Text.
 * @return list<array<string, mixed>> Events.
 */
    protected function _answer(string $text): array {
        return [
            ['type' => 'message_start', 'message' => ['model' => 'claude-action', 'usage' => ['input_tokens' => 5]]],
            ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $text]],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]],
            ['type' => 'message_stop'],
        ];
    }

/**
 * The action's defaults (model, timeouts) apply; the result carries the text and first_token_ms.
 *
 * @return void
 */
    public function testStreamWithActionDefaults(): void {
        $this->_transport->queueEvents($this->_answer('Olá'), 0.25);
        $service = $this->_service(['echo' => ['class' => EchoTextAction::class, 'defaults' => [
            'model' => 'claude-action',
            'timeout_seconds' => 8,
            'first_token_timeout_seconds' => 3,
        ]]], 'echo');
        $received = '';

        $result = $service->stream(['text' => 'x'], function (string $delta) use (&$received) {
            $received .= $delta;
        });

        $this->assertSame('Olá', $received);
        $this->assertSame('Olá', $result->text);
        $this->assertSame(300, $result->first_token_ms);
        $this->assertSame('claude-action', $this->_transport->lastPayload()['model']);
        $this->assertSame(9.0, (float)$this->_transport->requests[0]['options']['timeout']);
    }

/**
 * A first-token timeout is not retried (the guest is waiting).
 *
 * @return void
 */
    public function testNoRetry(): void {
        $this->_transport->queueStream(200, [['at' => 3.1]]);
        $this->_transport->queueEvents($this->_answer('tarde'));
        $service = $this->_service(['echo' => EchoTextAction::class], 'echo');

        try {
            $service->stream(['text' => 'x'], fn() => null, ['first_token_timeout_seconds' => 3]);
            $this->fail('No timeout.');
        } catch (LLMTimeoutException) {
            $this->assertCount(1, $this->_transport->requests);
        }
    }

/**
 * JSON actions and providers that cannot stream are refused before any request.
 *
 * @return void
 */
    public function testRefusesWhatCannotStream(): void {
        $json = $this->_service(['total' => ExtractTotalAction::class], 'total');
        try {
            $json->stream(['text' => 'x'], fn() => null);
            $this->fail('JSON accepted.');
        } catch (LLMValidationException) {
            $this->addToAssertionCount(1);
        }

        $openai = $this->_service(['echo' => EchoTextAction::class], 'echo');
        try {
            $openai->stream(['text' => 'x'], fn() => null, ['provider' => 'openai']);
            $this->fail('OpenAI accepted.');
        } catch (LLMValidationException) {
            $this->assertSame([], $this->_transport->requests);
        }
    }

}
