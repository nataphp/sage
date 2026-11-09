<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Provider;

use PHPUnit\Framework\TestCase;
use Sage\Service\Dto\CompletionRequest;
use Sage\Service\Exception\LLMProviderException;
use Sage\Service\Exception\LLMRateLimitedException;
use Sage\Service\Exception\LLMRefusalException;
use Sage\Service\Exception\LLMStreamInterruptedException;
use Sage\Service\Exception\LLMTimeoutException;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\Provider\ClaudeProvider;
use Sage\Service\Transport\FakeHttpTransport;
use Sage\Service\Transport\HttpTransportInterface;
use Sage\Service\Transport\HttpTransportResponse;

/**
 * Claude streaming (Messages API with stream: true), on the fake transport's scripted clock.
 */
class ClaudeProviderStreamTest extends TestCase {

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
            'model' => 'claude-haiku-5-5',
            'timeout_seconds' => 8.0,
        ]);
    }

/**
 * The events of a whole answer: start, the text deltas, stop.
 *
 * @param list<string> $deltas Pieces of text.
 * @param bool $stop Include message_delta and message_stop.
 * @return list<array<string, mixed>> Events.
 */
    protected function _events(array $deltas, bool $stop = true): array {
        $events = [
            ['type' => 'message_start', 'message' => ['model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 120, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'ping'],
        ];
        foreach ($deltas as $delta) {
            $events[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $delta]];
        }
        if ($stop) {
            $events[] = ['type' => 'content_block_stop', 'index' => 0];
            $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 42]];
            $events[] = ['type' => 'message_stop'];
        }

        return $events;
    }

/**
 * The pieces reach the callback in order; the response has the whole text,
 * the usage and the time to the first token; the payload asks for a stream.
 *
 * @return void
 */
    public function testStreamsTheText(): void {
        $transport = new FakeHttpTransport();
        $transport->queueEvents($this->_events(["OK\n", 'NOIVOS: Vejo', ' luzes acesas.']), 0.4, 0.1);
        $pieces = [];

        $response = (new ClaudeProvider($transport, 'key'))->stream($this->_request(), function (string $delta) use (&$pieces) {
            $pieces[] = $delta;
        }, 3.0);

        $this->assertSame(["OK\n", 'NOIVOS: Vejo', ' luzes acesas.'], $pieces);
        $this->assertSame("OK\nNOIVOS: Vejo luzes acesas.", $response->text);
        $this->assertSame('end_turn', $response->finish_reason);
        $this->assertSame(['prompt_tokens' => 120, 'completion_tokens' => 42, 'total_tokens' => 162], $response->usage);
        $this->assertSame(700, $response->first_token_ms);
        $this->assertTrue($transport->lastPayload()['stream']);
        $this->assertSame('text/event-stream', $transport->requests[0]['options']['header']['Accept']);
    }

/**
 * Nothing in time: a timeout of phase first_token, the callback never called.
 *
 * @return void
 */
    public function testFirstTokenTimeout(): void {
        $transport = new FakeHttpTransport();
        $transport->queueStream(200, [
            ['at' => 0.5, 'data' => "event: ping\ndata: {\"type\":\"ping\"}\n\n"],
            ['at' => 2.9],
            ['at' => 3.05],
            ['at' => 3.5, 'data' => "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"tarde\"}}\n\n"],
        ]);
        $called = false;

        try {
            (new ClaudeProvider($transport, 'key'))->stream($this->_request(), function () use (&$called) {
                $called = true;
            }, 3.0);
            $this->fail('No timeout.');
        } catch (LLMTimeoutException $exception) {
            $this->assertSame('first_token', $exception->context()['phase']);
            $this->assertFalse($called);
        }
    }

/**
 * Cut after the first token (connection dropped): interrupted, with the partial text.
 *
 * @return void
 */
    public function testCutAfterTheFirstToken(): void {
        $transport = new FakeHttpTransport();
        $events = $this->_events(["OK\n", 'NOIVOS: Vejo'], false);
        $steps = [];
        foreach ($events as $index => $event) {
            $steps[] = ['at' => 0.2 + $index * 0.1, 'data' => 'event: ' . $event['type'] . "\ndata: " . json_encode($event) . "\n\n"];
        }
        $transport->queueStream(200, $steps, [], 'Connection reset by peer');

        try {
            (new ClaudeProvider($transport, 'key'))->stream($this->_request(), fn() => null, 3.0);
            $this->fail('Not interrupted.');
        } catch (LLMStreamInterruptedException $exception) {
            $this->assertSame("OK\nNOIVOS: Vejo", $exception->partialText());
            $this->assertSame('network', $exception->context()['reason']);
        }
    }

/**
 * The stream stalls after its first token past the total limit: interrupted (timeout).
 *
 * @return void
 */
    public function testTotalTimeoutAfterTheFirstToken(): void {
        $transport = new FakeHttpTransport();
        $delta = ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => "OK\n"]];
        $transport->queueStream(200, [
            ['at' => 0.8, 'data' => "event: content_block_delta\ndata: " . json_encode($delta) . "\n\n"],
            ['at' => 5.0],
            ['at' => 8.0],
            ['at' => 9.0, 'data' => "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n"],
        ]);

        try {
            (new ClaudeProvider($transport, 'key'))->stream($this->_request(), fn() => null, 3.0);
            $this->fail('Not interrupted.');
        } catch (LLMStreamInterruptedException $exception) {
            $this->assertSame('timeout', $exception->context()['reason']);
            $this->assertSame("OK\n", $exception->partialText());
            $this->assertSame(800, $exception->context()['first_token_ms']);
        }
    }

/**
 * The callback returns false: the stream stops, with the text so far.
 *
 * @return void
 */
    public function testCallerStopsTheStream(): void {
        $transport = new FakeHttpTransport();
        $transport->queueEvents($this->_events(["RECUSA\n", 'nunca chega']));

        $response = (new ClaudeProvider($transport, 'key'))->stream($this->_request(), fn(string $delta) => !str_starts_with($delta, 'RECUSA'), 3.0);

        $this->assertSame('stopped', $response->finish_reason);
        $this->assertSame("RECUSA\n", $response->text);
    }

/**
 * An error event before any text (overloaded): a provider error of status 529.
 *
 * @return void
 */
    public function testErrorEventBeforeText(): void {
        $transport = new FakeHttpTransport();
        $transport->queueEvents([
            ['type' => 'message_start', 'message' => ['model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 10]]],
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
        ]);

        try {
            (new ClaudeProvider($transport, 'key'))->stream($this->_request(), fn() => null, 3.0);
            $this->fail('No error.');
        } catch (LLMStreamInterruptedException) {
            $this->fail('Not interrupted: there was no text yet.');
        } catch (LLMProviderException $exception) {
            $this->assertSame(529, $exception->context()['status']);
            $this->assertSame('Overloaded', $exception->getMessage());
        }
    }

/**
 * HTTP errors come back whole (not streamed): 429 and 400 as with complete().
 *
 * @return void
 */
    public function testHttpErrors(): void {
        $transport = new FakeHttpTransport();
        $transport->queueStream(429, [['at' => 0.1, 'data' => '{"error":{"type":"rate_limit_error","message":"Slow down"}}']], ['retry-after' => '2']);
        $transport->queueStream(400, [['at' => 0.1, 'data' => '{"error":{"type":"invalid_request_error","message":"Bad"}}']]);
        $provider = new ClaudeProvider($transport, 'key');

        try {
            $provider->stream($this->_request(), fn() => null, 3.0);
            $this->fail('No rate limit.');
        } catch (LLMRateLimitedException $exception) {
            $this->assertSame(2, $exception->context()['retry_after']);
        }
        try {
            $provider->stream($this->_request(), fn() => null, 3.0);
            $this->fail('No error.');
        } catch (LLMProviderException $exception) {
            $this->assertSame(400, $exception->context()['status']);
            $this->assertSame('Bad', $exception->getMessage());
        }
    }

/**
 * A refusal (stop_reason refusal) throws, even after some text.
 *
 * @return void
 */
    public function testRefusal(): void {
        $transport = new FakeHttpTransport();
        $events = $this->_events(['Não'], false);
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => 'refusal'], 'usage' => ['output_tokens' => 2]];
        $events[] = ['type' => 'message_stop'];
        $transport->queueEvents($events);

        $this->expectException(LLMRefusalException::class);
        (new ClaudeProvider($transport, 'key'))->stream($this->_request(), fn() => null, 3.0);
    }

/**
 * No response at all: a timeout of phase connect.
 *
 * @return void
 */
    public function testNoResponse(): void {
        $transport = new FakeHttpTransport();
        $transport->queueStream(0, [], [], 'Could not resolve host');

        try {
            (new ClaudeProvider($transport, 'key'))->stream($this->_request(), fn() => null, 3.0);
            $this->fail('No timeout.');
        } catch (LLMTimeoutException $exception) {
            $this->assertSame('connect', $exception->context()['phase']);
        }
    }

/**
 * JSON output, server tools and a transport that cannot stream are refused.
 *
 * @return void
 */
    public function testRefusesWhatCannotStream(): void {
        $provider = new ClaudeProvider(new FakeHttpTransport(), 'key');
        foreach ([['output_mode' => CompletionRequest::OUTPUT_MODE_JSON], ['tools' => ['web_search']]] as $overrides) {
            try {
                $provider->stream($this->_request($overrides), fn() => null);
                $this->fail('Accepted ' . json_encode($overrides));
            } catch (LLMValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $plain = new class implements HttpTransportInterface {
            /**
             * Never called.
             *
             * @param string $method Method.
             * @param string $url URL.
             * @param array<string, mixed> $options Options.
             * @return \Sage\Service\Transport\HttpTransportResponse
             */
            public function request(string $method, string $url, array $options = []): HttpTransportResponse {
                return new HttpTransportResponse(500, [], '');
            }
        };
        $this->expectException(LLMValidationException::class);
        (new ClaudeProvider($plain, 'key'))->stream($this->_request(), fn() => null);
    }

}
