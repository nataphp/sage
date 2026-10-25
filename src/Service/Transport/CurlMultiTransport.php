<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Transport;

use CurlHandle;
use CurlMultiHandle;
use Fiber;

/**
 * Production transport on one cURL multi handle, so several calls can be in
 * flight at once (and reuse their connections).
 *
 * Outside a Fiber, request() waits for its own response, like any transport.
 * Inside a Fiber, it adds the request and suspends the Fiber until the
 * response is in: the loop that runs the Fibers calls poll() and resumes them
 * (each checks whether its response arrived, and suspends again if not).
 *
 *   $transport = LLMFactory::transport();
 *   $fibers = [new Fiber(fn () => Sage::run('a', $inputA)), new Fiber(fn () => Sage::run('b', $inputB))];
 *   foreach ($fibers as $fiber) { $fiber->start(); }
 *   while ($fibers !== []) {
 *       $transport->poll(0.05);
 *       foreach ($fibers as $key => $fiber) {
 *           $fiber->isSuspended() && $fiber->resume();
 *           if ($fiber->isTerminated()) { unset($fibers[$key]); }
 *       }
 *   }
 *
 * Network failures (timeout, DNS, TLS) come back as status 0 with the error,
 * never as exceptions, so providers can map them to LLMTimeoutException.
 */
final class CurlMultiTransport implements HttpTransportInterface {

/**
 * Seconds to connect.
 */
    public const CONNECT_TIMEOUT = 15;

/**
 * The multi handle (created on the first request).
 *
 * @var \CurlMultiHandle|null
 */
    protected ?CurlMultiHandle $_multi = null;

/**
 * Requests in flight or not yet collected, by handle id: the handle, the
 * response headers so far and the response once it is in.
 *
 * @var array<int, array{handle: \CurlHandle, headers: array<string, string>, response: \Sage\Service\Transport\HttpTransportResponse|null}>
 */
    protected array $_requests = [];

/**
 * Send a request and wait for its response (suspending the Fiber it runs in,
 * if any).
 *
 * @param string $method HTTP method.
 * @param string $url Absolute URL.
 * @param array<string, mixed> $options `header`, `body`, `timeout` (seconds).
 * @return \Sage\Service\Transport\HttpTransportResponse
 */
    public function request(string $method, string $url, array $options = []): HttpTransportResponse {
        $id = $this->_add($method, $url, $options);
        while ($this->_requests[$id]['response'] === null) {
            if (Fiber::getCurrent() !== null) {
                Fiber::suspend();
            } else {
                $this->poll(1.0);
            }
        }
        $response = $this->_requests[$id]['response'];
        unset($this->_requests[$id]);

        return $response;
    }

/**
 * Move the requests in flight on, waiting up to $timeout seconds for the
 * network (just waits when nothing is in flight), and collect the responses
 * that are in.
 *
 * @param float $timeout Seconds.
 * @return int Requests still in flight.
 */
    public function poll(float $timeout): int {
        if ($this->_multi === null || $this->inFlight() === 0) {
            usleep((int)($timeout * 1000000));

            return 0;
        }
        curl_multi_exec($this->_multi, $running);
        if ($running > 0) {
            // -1: select failed (no descriptor to wait on yet); wait a little.
            if (curl_multi_select($this->_multi, $timeout) === -1) {
                usleep(min(10000, (int)($timeout * 1000000)));
            }
            curl_multi_exec($this->_multi, $running);
        }
        while (($info = curl_multi_info_read($this->_multi)) !== false) {
            if ($info['msg'] === CURLMSG_DONE) {
                $this->_finish($info['handle'], (int)$info['result']);
            }
        }

        return $running;
    }

/**
 * Requests sent whose response is not in yet.
 *
 * @return int Requests.
 */
    public function inFlight(): int {
        return count(array_filter($this->_requests, static fn ($request) => $request['response'] === null));
    }

/**
 * Add a request to the multi handle.
 *
 * @param string $method HTTP method.
 * @param string $url Absolute URL.
 * @param array<string, mixed> $options `header`, `body`, `timeout` (seconds).
 * @return int Handle id.
 */
    protected function _add(string $method, string $url, array $options): int {
        $this->_multi ??= curl_multi_init();
        $handle = curl_init();
        $id = spl_object_id($handle);
        $headers = [];
        foreach ((array)($options['header'] ?? []) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $method = strtoupper(trim($method));
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT_MS => (int)ceil(max(1.0, (float)($options['timeout'] ?? 30)) * 1000),
            CURLOPT_HEADERFUNCTION => function (CurlHandle $handle, string $line) use ($id): int {
                $this->_header($id, $line);

                return strlen($line);
            },
        ]);
        if (isset($options['body']) && $method !== 'GET') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, (string)$options['body']);
        }
        $this->_requests[$id] = ['handle' => $handle, 'headers' => [], 'response' => null];
        curl_multi_add_handle($this->_multi, $handle);
        // Start sending now: the loop may be busy elsewhere before its next poll().
        curl_multi_exec($this->_multi, $running);

        return $id;
    }

/**
 * Keep a response header line (a new status line, after a redirect or a
 * 100 Continue, starts over).
 *
 * @param int $id Handle id.
 * @param string $line Header line.
 * @return void
 */
    protected function _header(int $id, string $line): void {
        if (!isset($this->_requests[$id])) {
            return;
        }
        if (str_starts_with($line, 'HTTP/')) {
            $this->_requests[$id]['headers'] = [];

            return;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $this->_requests[$id]['headers'][strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

/**
 * A request is done: keep its response and free the handle.
 *
 * @param \CurlHandle $handle Handle.
 * @param int $result cURL result code.
 * @return void
 */
    protected function _finish(CurlHandle $handle, int $result): void {
        $id = spl_object_id($handle);
        if (!isset($this->_requests[$id])) {
            return;
        }
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = (string)curl_multi_getcontent($handle);
        curl_multi_remove_handle($this->_multi, $handle);

        $this->_requests[$id]['response'] = $result === CURLE_OK && $status > 0
            ? new HttpTransportResponse($status, $this->_requests[$id]['headers'], $body)
            : new HttpTransportResponse(0, [], '', curl_strerror($result) ?: 'No response.');
    }

}
