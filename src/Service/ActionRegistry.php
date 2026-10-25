<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service;

use Nata\Utility\Inflector;
use Sage\Service\Action\ActionInterface;
use Sage\Service\Exception\LLMValidationException;

/**
 * Resolves action keys to their class and per-action defaults.
 *
 * Three registration formats (`Sage.actions`):
 *
 *   'text.summarize' => \App\LLM\Text\Summarize::class,
 *   'email.classify' => ['class' => \App\LLM\Email\Classify::class, 'defaults' => ['provider' => 'claude']],
 *   'invoice.extract' => ['defaults' => ['max_tokens' => 1024]], // class from Sage.actionNamespace
 *
 * With `actionNamespace` = 'App\LLM', 'invoice.extract' → App\LLM\Invoice\Extract
 * and 'email-thread.parse' → App\LLM\EmailThread\Parse.
 */
final class ActionRegistry {

/**
 * Constructor.
 *
 * @param array<string, string|array{class?: string, defaults?: array<string, mixed>}> $actions Registered actions.
 * @param string $actionNamespace Base namespace for class inference ('' disables it).
 */
    public function __construct(
        protected array $actions,
        protected string $actionNamespace = ''
    ) {
        $this->actionNamespace = rtrim($actionNamespace, '\\');
    }

/**
 * Resolve an action key.
 *
 * @param string $action Action key.
 * @return array{class: class-string<\Sage\Service\Action\ActionInterface>, defaults: array<string, mixed>}
 * @throws \Sage\Service\Exception\LLMValidationException When the key is unknown, the entry is
 *   malformed, or the class is missing or does not implement ActionInterface.
 */
    public function get(string $action): array {
        $action = trim($action);
        if ($action === '' || !isset($this->actions[$action])) {
            throw new LLMValidationException('Unknown LLM action.', 0, null, ['action' => $action]);
        }

        $entry = $this->actions[$action];
        $defaults = [];
        if (is_string($entry) && trim($entry) !== '') {
            $class = trim($entry);
        } elseif (is_array($entry)) {
            $class = !empty($entry['class']) && is_string($entry['class']) ? $entry['class'] : $this->_inferClass($action);
            $defaults = is_array($entry['defaults'] ?? null) ? $entry['defaults'] : [];
        } else {
            throw new LLMValidationException('Invalid LLM action registry entry.', 0, null, ['action' => $action]);
        }

        if (!class_exists($class)) {
            throw new LLMValidationException('LLM action class not found.', 0, null, ['action' => $action, 'class' => $class]);
        }
        if (!is_subclass_of($class, ActionInterface::class)) {
            throw new LLMValidationException('LLM action class must implement ActionInterface.', 0, null, ['action' => $action, 'class' => $class]);
        }
        return ['class' => $class, 'defaults' => $defaults];
    }

/**
 * Registered action keys.
 *
 * @return list<string>
 */
    public function keys(): array {
        return array_keys($this->actions);
    }

/**
 * Infer the class from the key and the configured namespace.
 *
 * @param string $action Action key.
 * @return string
 * @throws \Sage\Service\Exception\LLMValidationException When no namespace is configured.
 */
    protected function _inferClass(string $action): string {
        if ($this->actionNamespace === '') {
            throw new LLMValidationException('Cannot infer the action class: set Sage.actionNamespace or give a class.', 0, null, ['action' => $action]);
        }
        $segments = array_map(
            static fn(string $segment) => Inflector::camelize(str_replace('-', '_', $segment)),
            explode('.', $action)
        );
        return $this->actionNamespace . '\\' . implode('\\', $segments);
    }

}
