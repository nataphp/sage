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
 * Optional: a JSON action that also gives a full JSON Schema.
 *
 * The providers then constrain the output natively (Claude
 * `output_config.format`, OpenAI `json_schema` strict, Gemini
 * `responseJsonSchema`) instead of only asking for JSON in the prompt.
 * `outputSchema()` still validates the decoded result.
 *
 * For OpenAI strict mode every object needs `additionalProperties: false` and
 * all its properties listed in `required` (use `["number", "null"]` types for
 * optional values).
 */
interface JsonSchemaInterface {

/**
 * JSON Schema of the output (an object schema).
 *
 * @return array<string, mixed>
 */
    public function jsonSchema(): array;

}
