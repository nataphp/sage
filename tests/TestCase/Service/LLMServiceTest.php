<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Service;

use Fiber;
use Nata\Core\Configure;
use PHPUnit\Framework\TestCase;
use Sage\Sage;
use Sage\Service\ActionRegistry;
use Sage\Service\Dto\Attachment;
use Sage\Service\Exception\LLMProviderException;
use Sage\Service\Exception\LLMRateLimitedException;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\LLMFactory;
use Sage\Service\LLMService;
use Sage\Service\Provider\ClaudeProvider;
use Sage\Service\Provider\OpenAIProvider;
use Sage\Service\Transport\FakeHttpTransport;
use Sage\Test\TestCase\Fixture\EchoTextAction;
use Sage\Test\TestCase\Fixture\ExtractTotalAction;

/**
 * Option resolution, retries, JSON validation and the configured entry point.
 */
class LLMServiceTest extends TestCase {

/**
 * Fake transport shared by the providers.
 *
 * @var \Sage\Service\Transport\FakeHttpTransport
 */
    protected FakeHttpTransport $_transport;

/**
 * Create the transport.
 *
 * @return void
 */
    protected function setUp(): void {
        parent::setUp();
        $this->_transport = new FakeHttpTransport();
    }

/**
 * Restore the real transport.
 *
 * @return void
 */
    protected function tearDown(): void {
        LLMFactory::setTransport(null);
        parent::tearDown();
    }

/**
 * Service with Claude and OpenAI on the fake transport.
 *
 * @param array<string, mixed> $actions Registered actions.
 * @param string $action Bound action.
 * @param array<string, mixed> $boundOptions Bound options.
 * @return \Sage\Service\LLMService
 */
    protected function _service(array $actions, string $action, array $boundOptions = []): LLMService {
        return new LLMService(
            new ActionRegistry($actions),
            [
                'claude' => new ClaudeProvider($this->_transport, 'key'),
                'openai' => new OpenAIProvider($this->_transport, 'key', OpenAIProvider::DEFAULT_BASE_URL),
            ],
            [
                'provider' => 'claude',
                'models' => ['claude' => 'claude-default', 'openai' => 'gpt-default'],
                'timeout_seconds' => 30,
                'retry_delay_ms' => 0,
            ],
            $action,
            $boundOptions
        );
    }

/**
 * Queue a Claude text answer.
 *
 * @param string $text Text.
 * @param string $stopReason Stop reason.
 * @return void
 */
    protected function _queueClaude(string $text, string $stopReason = 'end_turn'): void {
        $this->_transport->queueJson(200, ['stop_reason' => $stopReason, 'content' => [['type' => 'text', 'text' => $text]]]);
    }

/**
 * Action defaults (including timeout and effort) apply; run options win.
 *
 * @return void
 */
    public function testOptionResolution(): void {
        $service = $this->_service(['echo' => ['class' => EchoTextAction::class, 'defaults' => [
            'model' => 'claude-action', 'timeout_seconds' => 90, 'effort' => 'low', 'max_tokens' => 200,
        ]]], 'echo');

        $this->_queueClaude('ok');
        $result = $service->run(['text' => 'Hi']);
        $this->assertSame('ok', $result->text);
        $this->assertSame('claude', $result->provider);
        $this->assertSame(90.0, $this->_transport->requests[0]['options']['timeout']);
        $payload = $this->_transport->lastPayload();
        $this->assertSame('claude-action', $payload['model']);
        $this->assertSame(200, $payload['max_tokens']);
        $this->assertSame('low', $payload['output_config']['effort']);

        $this->_queueClaude('ok');
        $service->run(['text' => 'Hi'], ['model' => 'claude-run', 'max_tokens' => 50]);
        $this->assertSame('claude-run', $this->_transport->lastPayload()['model']);
        $this->assertSame(50, $this->_transport->lastPayload()['max_tokens']);
    }

/**
 * Switching provider at runtime uses that provider's default model.
 *
 * @return void
 */
    public function testRuntimeProviderUsesItsDefaultModel(): void {
        $service = $this->_service(['echo' => ['class' => EchoTextAction::class, 'defaults' => ['model' => 'claude-action']]], 'echo');
        $this->_transport->queueJson(200, ['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);

        $result = $service->run(['text' => 'Hi'], ['provider' => 'openai']);

        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-default', $this->_transport->lastPayload()['model']);
    }

/**
 * 5xx is retried once; 4xx is not.
 *
 * @return void
 */
    public function testRetry(): void {
        $service = $this->_service(['echo' => EchoTextAction::class], 'echo');

        $this->_transport->queueJson(529, ['error' => ['message' => 'Overloaded']]);
        $this->_queueClaude('second try');
        $this->assertSame('second try', $service->run(['text' => 'Hi'])->text);
        $this->assertCount(2, $this->_transport->requests);

        $this->_transport->queueJson(400, ['error' => ['message' => 'Bad request']]);
        try {
            $service->run(['text' => 'Hi']);
            $this->fail('Expected a provider error.');
        } catch (LLMProviderException $exception) {
            $this->assertSame(400, $exception->context()['status']);
        }
        $this->assertCount(3, $this->_transport->requests);
    }

/**
 * Busy (429, 529): retried up to rate_limit_retries times; a retry-after
 * longer than the longest pause gives up at once; a timeout or another 5xx
 * only once.
 *
 * @return void
 */
    public function testRetryWhenBusy(): void {
        $service = $this->_service(['echo' => EchoTextAction::class], 'echo');

        $this->_transport->queueJson(429, ['error' => ['message' => 'Rate limited']]);
        $this->_transport->queueJson(529, ['error' => ['message' => 'Overloaded']]);
        $this->_transport->queueJson(429, ['error' => ['message' => 'Rate limited']], ['retry-after' => '0']);
        $this->_queueClaude('fourth try');
        $this->assertSame('fourth try', $service->run(['text' => 'Hi'])->text);
        $this->assertCount(4, $this->_transport->requests);

        for ($i = 0; $i < 5; $i++) {
            $this->_transport->queueJson(529, ['error' => ['message' => 'Overloaded']]);
        }
        try {
            $service->run(['text' => 'Hi']);
            $this->fail('Expected a provider error.');
        } catch (LLMProviderException $exception) {
            $this->assertSame(529, $exception->context()['status']);
        }
        $this->assertCount(9, $this->_transport->requests);

        $this->_transport->queueJson(429, ['error' => ['message' => 'Rate limited']], ['retry-after' => '60']);
        try {
            $service->run(['text' => 'Hi']);
            $this->fail('Expected a rate limit.');
        } catch (LLMRateLimitedException $exception) {
            $this->assertSame(60, $exception->context()['retry_after']);
        }
        $this->assertCount(10, $this->_transport->requests);

        $this->_transport->queueJson(500, ['error' => ['message' => 'Internal']]);
        $this->_transport->queueJson(500, ['error' => ['message' => 'Internal']]);
        try {
            $service->run(['text' => 'Hi']);
            $this->fail('Expected a provider error.');
        } catch (LLMProviderException $exception) {
            $this->assertSame(500, $exception->context()['status']);
        }
        $this->assertCount(12, $this->_transport->requests);
    }

/**
 * In a Fiber, the pause before a retry suspends the Fiber instead of
 * blocking the process.
 *
 * @return void
 */
    public function testPauseSuspendsFiber(): void {
        $service = new LLMService(
            new ActionRegistry(['echo' => EchoTextAction::class]),
            ['claude' => new ClaudeProvider($this->_transport, 'key')],
            ['provider' => 'claude', 'models' => ['claude' => 'claude-default'], 'retry_delay_ms' => 50],
            'echo'
        );
        $this->_transport->queueJson(429, ['error' => ['message' => 'Rate limited']]);
        $this->_queueClaude('after the pause');

        $fiber = new Fiber(fn () => $service->run(['text' => 'Hi'])->text);
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $resumes = 0;
        while (!$fiber->isTerminated()) {
            usleep(10000);
            $fiber->resume();
            $resumes++;
        }
        $this->assertGreaterThan(1, $resumes);
        $this->assertSame('after the pause', $fiber->getReturn());
    }

/**
 * JSON is parsed and validated (nullable allowed); the native schema is sent;
 * truncated output gets a clear message.
 *
 * @return void
 */
    public function testJsonAction(): void {
        $service = $this->_service(['total' => ExtractTotalAction::class], 'total');

        $this->_queueClaude('{"total": null, "legible": false}');
        $result = $service->run([]);
        $this->assertSame(['total' => null, 'legible' => false], $result->json);
        $this->assertSame('json_schema', $this->_transport->lastPayload()['output_config']['format']['type']);

        $this->_queueClaude('{"total": 12.5, "legi', 'max_tokens');
        $this->expectException(LLMValidationException::class);
        $this->expectExceptionMessage('truncated');
        $service->run([]);
    }

/**
 * The tools option (action defaults or run options) reaches the request,
 * and the sources and tool errors reach the result.
 *
 * @return void
 */
    public function testServerToolsOption(): void {
        $service = $this->_service(['echo' => ['class' => EchoTextAction::class, 'defaults' => ['tools' => ['web_search']]]], 'echo');
        $this->_transport->queueJson(200, [
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'web_search_tool_result', 'tool_use_id' => 's1', 'content' => [['type' => 'web_search_result', 'url' => 'https://a.test', 'title' => 'A']]],
                ['type' => 'web_fetch_tool_result', 'tool_use_id' => 's2', 'content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible']],
                ['type' => 'text', 'text' => 'ok'],
            ],
        ]);

        $result = $service->run(['text' => 'x'], ['tools' => ['web_search', 'web_fetch']]);

        $this->assertSame(['web_search', 'web_fetch'], array_column($this->_transport->lastPayload()['tools'], 'name'));
        $this->assertSame([['tool' => 'web_search', 'url' => 'https://a.test', 'title' => 'A']], $result->sources);
        $this->assertSame([['tool' => 'web_fetch', 'error_code' => 'url_not_accessible']], $result->tool_errors);
    }

/**
 * Missing input and bad attachments fail before any request.
 *
 * @return void
 */
    public function testValidationBeforeRequest(): void {
        $service = $this->_service(['echo' => EchoTextAction::class], 'echo');
        try {
            $service->run([]);
            $this->fail('Expected missing input.');
        } catch (LLMValidationException $exception) {
            $this->assertSame(['text'], $exception->context()['missing']);
        }

        try {
            $service->run(['text' => 'Hi'], ['attachments' => ['not a file']]);
            $this->fail('Expected an invalid attachment.');
        } catch (LLMValidationException $exception) {
            $this->assertStringContainsString('attachments', $exception->getMessage());
        }
        $this->assertSame([], $this->_transport->requests);
    }

/**
 * Sage::run() builds from Configure, skips providers without a key and moves
 * attachments given in the input to the options.
 *
 * @return void
 */
    public function testEntryPointFromConfig(): void {
        $previous = Configure::read('Sage');
        Configure::write('Sage', [
            'defaultProvider' => 'claude',
            'retryDelayMs' => 0,
            'providers' => [
                'claude' => ['apiKey' => 'key', 'defaultModel' => 'claude-config'],
                'openai' => ['apiKey' => ''],
            ],
            'actions' => ['echo' => EchoTextAction::class],
        ]);
        LLMFactory::setTransport($this->_transport);

        try {
            $this->assertSame(['claude'], array_keys(LLMFactory::providers(Configure::read('Sage.providers'))));

            $this->_queueClaude('ok');
            Sage::run('echo', ['text' => 'Hi', 'attachments' => [Attachment::fromBytes('plain text', 'text/plain')]]);
            $payload = $this->_transport->lastPayload();
            $this->assertSame('claude-config', $payload['model']);
            $this->assertSame('document', $payload['messages'][0]['content'][0]['type']);

            $this->expectException(LLMValidationException::class);
            Sage::for('unknown.action');
        } finally {
            Configure::write('Sage', $previous);
        }
    }

}
