<?php
/**
 * Sage action stub: copy into the app and adapt.
 *
 * Location: src/LLM/{Segment}/{Name}.php, namespace App\LLM\{Segment}.
 * Register it in the app's Sage config (config/sage.php):
 *
 *   'actions' => [
 *       'my.action' => \App\LLM\My\Action::class,
 *       // or with per-action defaults (any run option):
 *       'my.action' => ['defaults' => ['provider' => 'claude', 'model' => '...', 'max_tokens' => 1024, 'effort' => 'low']],
 *   ],
 *
 * The second form infers the class from the key and Sage.actionNamespace.
 */
namespace App\LLM\My;

use Sage\Service\Action\ActionInterface;
use Sage\Service\Action\JsonSchemaInterface;

/**
 * One LLM task. Drop JsonSchemaInterface (and jsonSchema()) for text actions
 * or when a plain JSON mode is enough.
 */
class Action implements ActionInterface, JsonSchemaInterface {

/**
 * Input keys that must be present (checked before any provider call).
 *
 * @return list<string>
 */
    public function requiredInputKeys(): array {
        return ['text'];
    }

/**
 * Role, constraints and output format. Keep it stable across calls (it is
 * the cacheable part); put the variable content in the user prompt.
 *
 * @param array<string, mixed> $input Validated input.
 * @return string
 */
    public function systemPrompt(array $input): string {
        return 'Classify the text. If unsure, say so in "notes" instead of guessing.';
    }

/**
 * The content for this call. Attachments are passed as run options, not here.
 *
 * @param array<string, mixed> $input Validated input.
 * @return string
 */
    public function userPrompt(array $input): string {
        return (string)$input['text'];
    }

/**
 * 'text' (LLMResult::$text) or 'json' (LLMResult::$json, validated).
 *
 * @return string
 */
    public function outputMode(): string {
        return 'json';
    }

/**
 * Flat validation schema (null for text). Types: string, int, float, bool,
 * array, object, mixed; `?type` accepts null.
 *
 * @return array<string, string>|null
 */
    public function outputSchema(): ?array {
        return ['label' => 'string', 'confidence' => '?float', 'notes' => 'string'];
    }

/**
 * Full JSON Schema for the providers' native structured output. For OpenAI
 * strict mode: `additionalProperties: false` and every property in `required`.
 *
 * @return array<string, mixed>
 */
    public function jsonSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string'],
                'confidence' => ['type' => ['number', 'null']],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['label', 'confidence', 'notes'],
            'additionalProperties' => false,
        ];
    }

}
