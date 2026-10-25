<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Fixture;

use Sage\Service\Action\ActionInterface;
use Sage\Service\Action\JsonSchemaInterface;

/**
 * JSON action with a nullable field and a native JSON Schema.
 */
class ExtractTotalAction implements ActionInterface, JsonSchemaInterface {

/**
 * Required input keys.
 *
 * @return list<string>
 */
    public function requiredInputKeys(): array {
        return [];
    }

/**
 * System prompt.
 *
 * @param array<string, mixed> $input Input.
 * @return string
 */
    public function systemPrompt(array $input): string {
        return 'Read the total.';
    }

/**
 * User prompt.
 *
 * @param array<string, mixed> $input Input.
 * @return string
 */
    public function userPrompt(array $input): string {
        return 'What is the total?';
    }

/**
 * Output mode.
 *
 * @return string
 */
    public function outputMode(): string {
        return 'json';
    }

/**
 * Validation schema.
 *
 * @return array<string, string>|null
 */
    public function outputSchema(): ?array {
        return ['total' => '?float', 'legible' => 'bool'];
    }

/**
 * Native JSON Schema.
 *
 * @return array<string, mixed>
 */
    public function jsonSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'total' => ['type' => ['number', 'null']],
                'legible' => ['type' => 'boolean'],
            ],
            'required' => ['total', 'legible'],
            'additionalProperties' => false,
        ];
    }

}
