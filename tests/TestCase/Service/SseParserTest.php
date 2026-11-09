<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Service;

use PHPUnit\Framework\TestCase;
use Sage\Service\Stream\SseParser;

/**
 * Incremental server-sent events parser.
 */
class SseParserTest extends TestCase {

/**
 * Events split at any byte (inside a line, inside a UTF-8 character, between
 * \r and \n) come out whole and in order.
 *
 * @return void
 */
    public function testPiecesOfAnySize(): void {
        $body = "event: a\r\ndata: {\"t\":\"Olá\"}\r\n\r\n: comentário\nevent: b\ndata: um\ndata: dois\n\n";
        foreach ([1, 2, 3, 7, strlen($body)] as $size) {
            $parser = new SseParser();
            $events = [];
            foreach (str_split($body, $size) as $piece) {
                $events = array_merge($events, $parser->feed($piece));
            }
            $events = array_merge($events, $parser->finish());

            $this->assertSame([
                ['event' => 'a', 'data' => '{"t":"Olá"}'],
                ['event' => 'b', 'data' => "um\ndois"],
            ], $events, 'Pieces of ' . $size . ' bytes.');
        }
    }

/**
 * An event without its blank line is completed by finish(); no name is "message".
 *
 * @return void
 */
    public function testFinishCompletesTheLastEvent(): void {
        $parser = new SseParser();
        $this->assertSame([], $parser->feed('data: fim'));
        $this->assertSame([['event' => 'message', 'data' => 'fim']], $parser->finish());
        $this->assertSame([], $parser->finish());
    }

}
