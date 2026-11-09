<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Transport;

use Sage\Service\Exception\LLMProviderException;

/**
 * Deterministic test double: returns queued responses in order and records
 * every request, so tests can assert on the outgoing payload.
 *
 *   $transport = new FakeHttpTransport();
 *   $transport->queueJson(200, ['content' => [['type' => 'text', 'text' => 'Olá']]]);
 *   $provider = new ClaudeProvider($transport, 'fake-key');
 *   $provider->complete($request);
 *   $payload = $transport->lastPayload();
 */
final class FakeHttpTransport implements HttpTransportInterface, StreamingTransportInterface {

/**
 * Requests made, in call order.
 *
 * @var list<array{method: string, url: string, options: array<string, mixed>}>
 */
    public array $requests = [];

/**
 * Responses still to return (FIFO).
 *
 * @var list<\Sage\Service\Transport\HttpTransportResponse>
 */
    protected array $_queue = [];

/**
 * Streams still to play (FIFO), see queueStream().
 *
 * @var list<array{status: int, steps: list<array{at: float, data?: string}>, headers: array<string, string>, error: string}>
 */
    protected array $_streams = [];

/**
 * Queue a response.
 *
 * @param \Sage\Service\Transport\HttpTransportResponse $response Response to return.
 * @return void
 */
    public function queueResponse(HttpTransportResponse $response): void {
        $this->_queue[] = $response;
    }

/**
 * Queue a JSON response.
 *
 * @param int $status HTTP status.
 * @param array<string, mixed> $body Body, encoded as JSON.
 * @param array<string, string> $headers Response headers.
 * @return void
 */
    public function queueJson(int $status, array $body, array $headers = []): void {
        $this->queueResponse(new HttpTransportResponse($status, $headers, (string)json_encode($body)));
    }

/**
 * Queue a streamed response (for stream()), on a scripted clock: each step
 * is `['at' => seconds, 'data' => bytes]` (a piece of the body arriving at
 * that time) or `['at' => seconds]` (time passing with nothing arriving).
 * Before each step `on_tick` is called at that time. `$error` ends the
 * transfer as cut after the last step (status kept), like a dropped
 * connection.
 *
 * @param int $status HTTP status.
 * @param list<array{at: float, data?: string}> $steps Steps, in time order.
 * @param array<string, string> $headers Response headers.
 * @param string $error Transport error at the end ('' for a clean end).
 * @return void
 */
    public function queueStream(int $status, array $steps, array $headers = [], string $error = ''): void {
        $this->_streams[] = ['status' => $status, 'steps' => $steps, 'headers' => $headers, 'error' => $error];
    }

/**
 * Queue a streamed response of server-sent events, one step per event, the
 * first at `$start` seconds and then every `$interval` seconds.
 *
 * @param list<array<string, mixed>> $events Events (each with its `type`), sent as `event:`/`data:` pairs.
 * @param float $start Time of the first event.
 * @param float $interval Seconds between events.
 * @return void
 */
    public function queueEvents(array $events, float $start = 0.1, float $interval = 0.05): void {
        $steps = [];
        foreach (array_values($events) as $index => $event) {
            $steps[] = [
                'at' => $start + $index * $interval,
                'data' => 'event: ' . $event['type'] . "\n" . 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE) . "\n\n",
            ];
        }
        $this->queueStream(200, $steps);
    }

/**
 * Record the request and play the next queued stream (see queueStream()).
 *
 * @param string $method HTTP method.
 * @param string $url Request URL.
 * @param array<string, mixed> $options Request options (`on_tick` is honoured).
 * @param callable(string, float): bool $onData Receives each piece of a 2xx body.
 * @return \Sage\Service\Transport\HttpTransportResponse
 * @throws \Sage\Service\Exception\LLMProviderException When no stream is queued (test setup error).
 */
    public function stream(string $method, string $url, array $options, callable $onData): HttpTransportResponse {
        $this->requests[] = ['method' => strtoupper(trim($method)), 'url' => $url, 'options' => $options];
        if ($this->_streams === []) {
            throw new LLMProviderException('FakeHttpTransport stream queue is empty.');
        }
        $stream = array_shift($this->_streams);
        $onTick = isset($options['on_tick']) && is_callable($options['on_tick']) ? $options['on_tick'] : null;
        $streamed = $stream['status'] >= 200 && $stream['status'] < 300;
        $body = '';

        foreach ($stream['steps'] as $step) {
            $at = (float)$step['at'];
            if ($onTick !== null && $onTick($at) === false) {
                return new HttpTransportResponse($stream['status'], $stream['headers'], $body, 'Aborted by the caller.', true);
            }
            if (!isset($step['data'])) {
                continue;
            }
            if (!$streamed) {
                $body .= (string)$step['data'];
                continue;
            }
            if ($onData((string)$step['data'], $at) === false) {
                return new HttpTransportResponse($stream['status'], $stream['headers'], $body, 'Aborted by the caller.', true);
            }
        }

        return new HttpTransportResponse($stream['status'], $stream['headers'], $body, (string)$stream['error']);
    }

/**
 * Record the request and return the next queued response.
 *
 * @param string $method HTTP method.
 * @param string $url Request URL.
 * @param array<string, mixed> $options Request options.
 * @return \Sage\Service\Transport\HttpTransportResponse
 * @throws \Sage\Service\Exception\LLMProviderException When the queue is empty (test setup error).
 */
    public function request(string $method, string $url, array $options = []): HttpTransportResponse {
        $this->requests[] = ['method' => strtoupper(trim($method)), 'url' => $url, 'options' => $options];

        if ($this->_queue === []) {
            throw new LLMProviderException('FakeHttpTransport queue is empty.');
        }
        return array_shift($this->_queue);
    }

/**
 * Decoded JSON body of the last request.
 *
 * @return array<string, mixed>
 */
    public function lastPayload(): array {
        $last = end($this->requests);
        if ($last === false) {
            return [];
        }
        return (array)json_decode((string)($last['options']['body'] ?? ''), true);
    }

}
