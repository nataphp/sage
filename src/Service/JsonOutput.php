<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service;

use Sage\Service\Exception\LLMValidationException;

/**
 * Decodes a JSON object from model output and validates it against a flat schema.
 *
 * Decoding tolerates what models commonly add around JSON: a BOM, markdown
 * fences (even unclosed), leading prose, and raw newlines/tabs inside strings.
 */
final class JsonOutput {

/**
 * Accepted schema types.
 *
 * @var list<string>
 */
    public const TYPES = ['string', 'int', 'float', 'bool', 'array', 'object', 'mixed'];

/**
 * Decode and validate.
 *
 * @param string $text Raw model output.
 * @param array<string, string> $schema `field => type` (see ActionInterface::outputSchema()).
 * @param array<string, mixed> $context Added to exception contexts (action, finish reason...).
 * @return array<string, mixed>
 * @throws \Sage\Service\Exception\LLMValidationException When the output is not a JSON object
 *   or a field is missing or of the wrong type.
 */
    public static function parse(string $text, array $schema, array $context = []): array {
        $decoded = self::decode($text);
        if ($decoded === null) {
            throw new LLMValidationException('Invalid JSON output from model.', 0, null, $context);
        }
        self::validate($decoded, $schema, $context);
        return $decoded;
    }

/**
 * Decode the first JSON object found in the text.
 *
 * @param string $text Raw model output.
 * @return array<string, mixed>|null
 */
    public static function decode(string $text): ?array {
        $trimmed = trim($text);
        if (str_starts_with($trimmed, "\xEF\xBB\xBF")) {
            $trimmed = substr($trimmed, 3);
        }
        // Markdown fence: closed first, then unclosed (truncated output).
        if (preg_match('/```(?:json)?\s*\R?(.*?)\R?```/is', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        } elseif (preg_match('/```(?:json)?\s*\R?(.*)/is', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($decoded)) {
            return $decoded;
        }

        $slice = self::firstObject($trimmed);
        if ($slice === null) {
            return null;
        }
        foreach ([$slice, self::escapeControlCharacters($slice)] as $candidate) {
            $decoded = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

/**
 * Check every schema field is present with the right type.
 *
 * @param array<string, mixed> $decoded Decoded output.
 * @param array<string, string> $schema `field => type`, `?type` or `type|null` for nullable.
 * @param array<string, mixed> $context Added to exception contexts.
 * @return void
 * @throws \Sage\Service\Exception\LLMValidationException On a missing field or a wrong type.
 */
    public static function validate(array $decoded, array $schema, array $context = []): void {
        foreach ($schema as $field => $declared) {
            if (!array_key_exists($field, $decoded)) {
                throw new LLMValidationException('Missing required JSON output field.', 0, null, $context + ['field' => $field]);
            }

            [$type, $nullable] = self::parseType((string)$declared);
            $value = $decoded[$field];
            if ($value === null && $nullable) {
                continue;
            }
            $valid = match ($type) {
                'string' => is_string($value),
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'bool' => is_bool($value),
                // json_decode() in associative mode turns objects into arrays.
                'array', 'object' => is_array($value),
                default => true,
            };
            if (!$valid) {
                throw new LLMValidationException('Invalid JSON output field type.', 0, null, $context + [
                    'field' => $field,
                    'expected' => (string)$declared,
                    'actual' => gettype($value),
                ]);
            }
        }
    }

/**
 * Split a declared type into the base type and whether null is accepted.
 *
 * @param string $declared Declared type ('float', '?float', 'float|null').
 * @return array{0: string, 1: bool}
 */
    public static function parseType(string $declared): array {
        $type = strtolower(trim($declared));
        $nullable = false;
        if (str_starts_with($type, '?')) {
            $nullable = true;
            $type = substr($type, 1);
        }
        if (str_ends_with($type, '|null')) {
            $nullable = true;
            $type = substr($type, 0, -5);
        }
        return [$type, $nullable];
    }

/**
 * First balanced `{...}` in the text (string-aware).
 *
 * @param string $text Text.
 * @return string|null
 */
    public static function firstObject(string $text): ?string {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $character = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

/**
 * Escape raw newlines, carriage returns and tabs inside JSON strings.
 *
 * @param string $text Candidate JSON.
 * @return string
 */
    public static function escapeControlCharacters(string $text): string {
        $result = '';
        $inString = false;
        $escaped = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $character = $text[$i];
            if ($escaped) {
                $result .= $character;
                $escaped = false;
                continue;
            }
            if ($character === '\\') {
                $escaped = true;
            } elseif ($character === '"') {
                $inString = !$inString;
            } elseif ($inString && in_array($character, ["\n", "\r", "\t"], true)) {
                $result .= match ($character) {
                    "\n" => '\n',
                    "\r" => '\r',
                    default => '\t',
                };
                continue;
            }
            $result .= $character;
        }
        return $result;
    }

}
