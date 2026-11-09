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
 * A transport that hands the response body over as it arrives (server-sent
 * events), for `Sage::stream()`.
 *
 * - `$onData(string $bytes, float $elapsed): bool` receives each piece of a
 *   2xx body, with the seconds since the request started; returning false
 *   aborts the transfer. A body of any other status is not streamed: it comes
 *   back whole in the response, as with request().
 * - `$options['on_tick']`, `callable(float $elapsed): bool`, is called at
 *   least every `$options['tick_seconds']` (default 0.05) while the transfer
 *   runs, also when nothing arrives; returning false aborts it. This is how a
 *   caller enforces a time limit to the first token.
 *
 * Never throws on network failures. The response has the status received (0
 * when none), `error` when the transfer did not end cleanly (a cut connection,
 * a timeout) and `aborted` when a callback stopped it.
 */
interface StreamingTransportInterface {

/**
 * Send a request and stream its response body.
 *
 * @param string $method HTTP method.
 * @param string $url Absolute URL.
 * @param array<string, mixed> $options `header`, `body`, `timeout` (seconds, the whole
 *   transfer), `on_tick`, `tick_seconds`.
 * @param callable(string, float): bool $onData Receives each piece of a 2xx body.
 * @return \Sage\Service\Transport\HttpTransportResponse Status and headers; the body only when not streamed.
 */
    public function stream(string $method, string $url, array $options, callable $onData): HttpTransportResponse;

}
