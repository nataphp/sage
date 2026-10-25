<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Transport;

use Nata\Http\Client;
use Throwable;

/**
 * Production transport backed by `Nata\Http\Client`.
 *
 * Network failures (timeout, DNS, TLS) come back as status 0 with the error,
 * never as exceptions, so providers can map them to LLMTimeoutException.
 */
final class NataHttpTransport implements HttpTransportInterface {

/**
 * Send the request with the Nata HTTP client.
 *
 * @param string $method HTTP method.
 * @param string $url Absolute URL.
 * @param array<string, mixed> $options `header`, `body`, `timeout` (seconds).
 * @return \Sage\Service\Transport\HttpTransportResponse
 */
    public function request(string $method, string $url, array $options = []): HttpTransportResponse {
        $clientOptions = ['url' => $url] + $options;
        if (isset($clientOptions['timeout'])) {
            $clientOptions['timeout'] = max(1, (int)ceil((float)$clientOptions['timeout']));
        }

        try {
            $request = match (strtoupper(trim($method))) {
                'POST' => Client::post($clientOptions),
                'PUT' => Client::put($clientOptions),
                'DELETE' => Client::delete($clientOptions),
                default => Client::get($clientOptions),
            };
            $response = $request->send();
        } catch (Throwable $exception) {
            return new HttpTransportResponse(0, [], '', $exception->getMessage());
        }

        // No parsed response at all (timeout before any header).
        if ($response === null) {
            return new HttpTransportResponse(0, [], '', 'No response.');
        }

        $status = (int)$response->getStatusCode();
        $headers = $response->getHeader();
        $error = $status === 0 ? (string)$response->getError() : '';

        return new HttpTransportResponse($status, is_array($headers) ? $headers : [], (string)$response->getBody(), $error);
    }

}
