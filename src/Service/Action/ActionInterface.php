<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Action;

/**
 * One LLM task: prompts, required input, output mode and (for JSON) the schema.
 *
 * Copy `plugins/Sage/stubs/Action.php` to start a new action. To also get the
 * provider's native structured output, implement JsonSchemaInterface.
 */
interface ActionInterface {

/**
 * Top-level keys that must be present in the input.
 *
 * @return list<string>
 */
    public function requiredInputKeys(): array;

/**
 * System prompt ('' to omit).
 *
 * @param array<string, mixed> $input Validated input.
 * @return string
 */
    public function systemPrompt(array $input): string;

/**
 * User prompt.
 *
 * @param array<string, mixed> $input Validated input.
 * @return string
 */
    public function userPrompt(array $input): string;

/**
 * Output mode: 'text' or 'json'.
 *
 * @return string
 */
    public function outputMode(): string;

/**
 * Flat validation schema for JSON output, null for text actions.
 *
 * `field => type`, type being string, int, float, bool, array, object or mixed.
 * Prefix with `?` (or suffix `|null`) to also accept null: `'total' => '?float'`.
 * Every listed field must be present; extra fields are allowed.
 *
 * @return array<string, string>|null
 */
    public function outputSchema(): ?array;

}
