<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Fixture;

use Sage\Service\Action\ActionInterface;

/**
 * Text action that sends the input text as the prompt.
 */
class EchoTextAction implements ActionInterface {

/**
 * Required input keys.
 *
 * @return list<string>
 */
    public function requiredInputKeys(): array {
        return ['text'];
    }

/**
 * System prompt.
 *
 * @param array<string, mixed> $input Input.
 * @return string
 */
    public function systemPrompt(array $input): string {
        return 'Reply briefly.';
    }

/**
 * User prompt.
 *
 * @param array<string, mixed> $input Input.
 * @return string
 */
    public function userPrompt(array $input): string {
        return (string)$input['text'];
    }

/**
 * Output mode.
 *
 * @return string
 */
    public function outputMode(): string {
        return 'text';
    }

/**
 * No schema for text output.
 *
 * @return array<string, string>|null
 */
    public function outputSchema(): ?array {
        return null;
    }

}
