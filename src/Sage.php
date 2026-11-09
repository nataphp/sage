<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage;

use Sage\Service\Dto\LLMResult;
use Sage\Service\LLMFactory;
use Sage\Service\LLMService;

/**
 * Entry point: the only class application code needs.
 *
 *   $result = Sage::run('email.classify', ['subject' => $subject, 'body' => $body]);
 *
 *   $service = Sage::for('email.classify', ['provider' => 'claude']);
 *   foreach ($emails as $email) {
 *       $results[] = $service->run($email->toArray());
 *   }
 *
 *   $result = Sage::run('invoice.extract', [], ['attachments' => [$file]]);
 *
 *   $result = Sage::stream('story.tell', $input, function (string $delta) { echo $delta; flush(); },
 *       ['first_token_timeout_seconds' => 3, 'timeout_seconds' => 8]);
 */
final class Sage {

/**
 * Service bound to an action (validated now, before any provider call).
 *
 * @param string $action Action key.
 * @param array<string, mixed> $options Options applied to every run(): provider, model,
 *   temperature, max_tokens, timeout_seconds, effort, attachments, image_max_edge.
 * @return \Sage\Service\LLMService
 * @throws \Sage\Service\Exception\LLMValidationException When the action is unknown or invalid.
 */
    public static function for(string $action, array $options = []): LLMService {
        return LLMFactory::build($action, $options);
    }

/**
 * Build and run in one call.
 *
 * @param string $action Action key.
 * @param array<string, mixed> $input Action input.
 * @param array<string, mixed> $options Per-call options (see `for()`). `attachments` given in
 *   `$input` by mistake are moved to the options.
 * @return \Sage\Service\Dto\LLMResult
 * @throws \Sage\Service\Exception\LLMException On any Sage failure.
 */
    public static function run(string $action, array $input, array $options = []): LLMResult {
        if (!array_key_exists('attachments', $options) && array_key_exists('attachments', $input)) {
            $options['attachments'] = $input['attachments'];
            unset($input['attachments']);
        }
        return static::for($action, $options)->run($input);
    }

/**
 * Build and stream in one call: the text goes to `$onText` as it is
 * generated (see LLMService::stream()).
 *
 * @param string $action Action key (a text action).
 * @param array<string, mixed> $input Action input.
 * @param callable(string): (bool|null) $onText Receives each piece of text; false stops the stream.
 * @param array<string, mixed> $options Per-call options, with `first_token_timeout_seconds`.
 * @return \Sage\Service\Dto\LLMResult
 * @throws \Sage\Service\Exception\LLMException On any Sage failure (LLMStreamInterruptedException
 *   when the stream stopped after its first token).
 */
    public static function stream(string $action, array $input, callable $onText, array $options = []): LLMResult {
        return static::for($action, $options)->stream($input, $onText);
    }

}
