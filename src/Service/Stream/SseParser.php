<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Stream;

/**
 * Incremental parser of server-sent events: feed it the body in pieces of
 * any size (a piece may end in the middle of a line, or of a UTF-8
 * character) and it returns the events completed so far.
 *
 * An event ends at a blank line; `event:` sets its name, `data:` lines are
 * joined with "\n", comments (":") and other fields are ignored. Lines end in
 * "\n", "\r\n" or "\r".
 */
final class SseParser {

/**
 * Bytes received but not yet part of a complete line.
 *
 * @var string
 */
    protected string $_buffer = '';

/**
 * Name of the event being read ('' until an `event:` line).
 *
 * @var string
 */
    protected string $_event = '';

/**
 * Data lines of the event being read.
 *
 * @var list<string>
 */
    protected array $_data = [];

/**
 * Feed a piece of the body.
 *
 * @param string $bytes Piece of the body.
 * @return list<array{event: string, data: string}> Events completed by this piece.
 */
    public function feed(string $bytes): array {
        $this->_buffer .= $bytes;
        $events = [];
        while (preg_match('/\r\n|\n|\r(?!$)/', $this->_buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
            $line = substr($this->_buffer, 0, $match[0][1]);
            $this->_buffer = substr($this->_buffer, $match[0][1] + strlen($match[0][0]));
            $event = $this->_line($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

/**
 * The end of the body: an event left without its blank line is completed.
 *
 * @return list<array{event: string, data: string}> The last event, if any.
 */
    public function finish(): array {
        $events = [];
        if ($this->_buffer !== '') {
            $line = rtrim($this->_buffer, "\r");
            $this->_buffer = '';
            $event = $this->_line($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        $last = $this->_line('');
        if ($last !== null) {
            $events[] = $last;
        }

        return $events;
    }

/**
 * Handle one line.
 *
 * @param string $line Line without its end.
 * @return array{event: string, data: string}|null The event a blank line completes.
 */
    protected function _line(string $line): ?array {
        if ($line === '') {
            if ($this->_data === [] && $this->_event === '') {
                return null;
            }
            $event = ['event' => $this->_event !== '' ? $this->_event : 'message', 'data' => implode("\n", $this->_data)];
            $this->_event = '';
            $this->_data = [];

            return $event;
        }
        if ($line[0] === ':') {
            return null;
        }
        $parts = explode(':', $line, 2);
        $field = $parts[0];
        $value = $parts[1] ?? '';
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }
        if ($field === 'event') {
            $this->_event = $value;
        } elseif ($field === 'data') {
            $this->_data[] = $value;
        }

        return null;
    }

}
