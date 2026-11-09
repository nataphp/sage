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
 * Normalised HTTP response returned by an HttpTransportInterface.
 */
final class HttpTransportResponse {

/**
 * Constructor.
 *
 * @param int $status_code HTTP status (0 when the request timed out or never completed).
 * @param array<string, string|array> $headers Response headers.
 * @param string $body Raw body.
 * @param string $error Transport error (cURL message...) when `status_code` is 0, or when a
 *   streamed transfer did not end cleanly.
 * @param bool $aborted A callback of a streamed transfer stopped it (StreamingTransportInterface).
 */
    public function __construct(
        public readonly int $status_code,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $error = '',
        public readonly bool $aborted = false
    ) {
    }

/**
 * Decode the body as a JSON object.
 *
 * @return array<string, mixed>|null Null when empty, invalid or not an array.
 */
    public function json(): ?array {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

/**
 * Value of a header, case-insensitively.
 *
 * @param string $name Header name.
 * @return string|null First value, or null when absent.
 */
    public function header(string $name): ?string {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return is_array($value) ? (string)reset($value) : (string)$value;
            }
        }
        return null;
    }

}
