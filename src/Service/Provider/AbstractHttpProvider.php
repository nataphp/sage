<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Provider;

use Sage\Service\Exception\LLMProviderException;
use Sage\Service\Exception\LLMRateLimitedException;
use Sage\Service\Exception\LLMTimeoutException;
use Sage\Service\Transport\HttpTransportInterface;
use Sage\Service\Transport\HttpTransportResponse;

/**
 * Shared plumbing of the HTTP providers: POST a JSON payload and map failures
 * to Sage exceptions.
 */
abstract class AbstractHttpProvider implements ProviderInterface {

/**
 * Provider name used in exception messages.
 *
 * @var string
 */
    protected string $_name = 'Provider';

/**
 * Constructor.
 *
 * @param \Sage\Service\Transport\HttpTransportInterface $http Transport.
 * @param string $apiKey API key.
 * @param string $baseUrl API base URL.
 */
    public function __construct(
        protected HttpTransportInterface $http,
        protected string $apiKey,
        protected string $baseUrl
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

/**
 * POST a JSON payload and return the decoded response body.
 *
 * @param string $url Absolute URL.
 * @param array<string, mixed> $payload Request body.
 * @param array<string, string> $headers Extra headers.
 * @param float $timeout Timeout in seconds.
 * @return array<string, mixed>
 * @throws \Sage\Service\Exception\LLMTimeoutException On status 0.
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMProviderException On other errors or a non-JSON body.
 */
    protected function _postJson(string $url, array $payload, array $headers, float $timeout): array {
        $response = $this->http->request('POST', $url, [
            'timeout' => $timeout,
            'header' => ['Content-Type' => 'application/json'] + $headers,
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        if ($response->status_code === 0) {
            throw new LLMTimeoutException($this->_name . ' request failed (no response).', 0, null, ['error' => $response->error]);
        }

        $data = $response->json();
        $this->_throwForStatus($response, $data);
        if ($data === null) {
            throw new LLMProviderException($this->_name . ' returned a non-JSON response.', 0, null, ['status' => $response->status_code]);
        }
        return $data;
    }

/**
 * Throw for an HTTP error status (429, and any status of 400 or more).
 *
 * @param \Sage\Service\Transport\HttpTransportResponse $response Response.
 * @param array<string, mixed>|null $data Decoded body.
 * @return void
 * @throws \Sage\Service\Exception\LLMRateLimitedException On HTTP 429.
 * @throws \Sage\Service\Exception\LLMProviderException On other errors.
 */
    protected function _throwForStatus(HttpTransportResponse $response, ?array $data): void {
        if ($response->status_code === 429) {
            $retryAfter = $response->header('retry-after');
            throw new LLMRateLimitedException($this->_name . ' rate limited.', 0, null, [
                'status' => 429,
                'retry_after' => $retryAfter !== null ? (int)$retryAfter : null,
            ]);
        }
        if ($response->status_code >= 400) {
            $message = $data['error']['message'] ?? ($this->_name . ' request failed.');
            $retryAfter = $response->header('retry-after');
            throw new LLMProviderException((string)$message, 0, null, [
                'status' => $response->status_code,
                'type' => $data['error']['type'] ?? $data['error']['status'] ?? null,
                'retry_after' => $retryAfter !== null ? (int)$retryAfter : null,
            ]);
        }
    }

/**
 * Keep only the non-null integer usage counters.
 *
 * @param array<string, mixed> $usage Usage counters by Sage key.
 * @return array<string, int>
 */
    protected function _usage(array $usage): array {
        $usage = array_filter($usage, static fn($value) => $value !== null);
        return array_map('intval', $usage);
    }

}
