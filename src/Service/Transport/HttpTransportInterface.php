<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Transport;

/**
 * Thin HTTP abstraction used by the provider adapters.
 *
 * NataHttpTransport in production, FakeHttpTransport in tests.
 * Implementations never throw on network failures: they return a response with
 * `status_code` 0 and the error, and the provider turns it into a timeout.
 */
interface HttpTransportInterface {

/**
 * Send an HTTP request.
 *
 * @param string $method HTTP method ('POST', 'GET'...).
 * @param string $url Absolute URL.
 * @param array<string, mixed> $options `header` (array<string, string>), `body` (string), `timeout` (seconds).
 * @return \Sage\Service\Transport\HttpTransportResponse
 */
    public function request(string $method, string $url, array $options = []): HttpTransportResponse;

}
