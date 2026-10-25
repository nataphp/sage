<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Service;

use PHPUnit\Framework\TestCase;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\JsonOutput;

/**
 * Decoding and validating JSON model output.
 */
class JsonOutputTest extends TestCase {

/**
 * Fences, leading prose, unclosed fences and raw newlines are tolerated.
 *
 * @return void
 */
    public function testDecodeToleratesCommonNoise(): void {
        $this->assertSame(['a' => 1], JsonOutput::decode("```json\n{\"a\": 1}\n```"));
        $this->assertSame(['a' => 1], JsonOutput::decode("Here it is: {\"a\": 1} hope it helps"));
        $this->assertSame(['a' => 1], JsonOutput::decode("```json\n{\"a\": 1}"));
        $this->assertSame(['note' => "line1\nline2"], JsonOutput::decode("{\"note\": \"line1\nline2\"}"));
        $this->assertNull(JsonOutput::decode('no json here'));
    }

/**
 * Nullable types accept null; others reject it; ints count as floats.
 *
 * @return void
 */
    public function testValidateTypes(): void {
        $schema = ['total' => '?float', 'count' => 'int|null', 'name' => 'string'];
        JsonOutput::validate(['total' => null, 'count' => null, 'name' => 'x'], $schema);
        JsonOutput::validate(['total' => 3, 'count' => 2, 'name' => 'x'], $schema);

        $this->expectException(LLMValidationException::class);
        JsonOutput::validate(['total' => 1.5, 'count' => 1, 'name' => null], $schema);
    }

/**
 * A missing field names the field in the context.
 *
 * @return void
 */
    public function testMissingField(): void {
        try {
            JsonOutput::parse('{"a": 1}', ['a' => 'int', 'b' => 'string'], ['action' => 'x']);
            $this->fail('Expected an exception.');
        } catch (LLMValidationException $exception) {
            $this->assertSame('b', $exception->context()['field']);
            $this->assertSame('x', $exception->context()['action']);
        }
    }

}
