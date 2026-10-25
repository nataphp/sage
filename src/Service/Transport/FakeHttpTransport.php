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
final class FakeHttpTransport implements HttpTransportInterface {

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
