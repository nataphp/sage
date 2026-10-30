# Sage

Multi-provider LLM plugin for NataPHP. An **action** class describes one task (prompts, required input, text or JSON output); Sage runs it against Claude, OpenAI or Gemini through one API.

- Text or validated JSON output, with optional native structured output (JSON Schema).
- Attachments (images, PDF, text, audio/video on Gemini) from any Nata store; images are shrunk and EXIF-rotated before sending.
- Retries with a backoff when the provider is busy (429, 529), one retry on timeouts and other 5xx; typed exceptions for rate limits, refusals and invalid output.
- Several calls at once from PHP Fibers (one cURL multi handle; see "Concurrent calls").
- Server tools on Claude: web search and web fetch run on Anthropic's side within the same call (see "Server tools").
- Logging with token usage; fully testable with a fake HTTP transport.

No client-side tool calling or streaming: one request, one answer (server tools included).

---

## Installation

Require the package (`nataphp/sage`; the VCS repositories go next to `nataphp/core`'s in the app's `composer.json`):

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/nataphp/core"},
    {"type": "vcs", "url": "https://github.com/nataphp/sage"}
],
"require": {
    "nataphp/sage": "^1.0"
}
```

Then load it in `config/plugins.php`:

```php
Plugin::load('Sage', [
    'path' => ROOT . 'vendor' . DS . 'nataphp' . DS . 'sage' . DS,
    'config' => ROOT . 'config' . DS . 'sage.php',
    'bootstrap' => true,
    'routes' => false,
]);
```

The package autoloads `Sage\`. To run its tests from the app, add `"Sage\\Test\\": "vendor/nataphp/sage/tests/"` to the app's `autoload-dev`.

The plugin's bootstrap loads `config` (default `config/sage.php`, a file that **returns** an array) into `Sage.*` and fills the defaults it does not set.

## Configuration

```php
// config/sage.php
return [
    'defaultProvider' => env('LLM_DEFAULT_PROVIDER', 'claude'),
    'timeout_seconds' => 30,          // per request; an action can override it
    'retryDelayMs' => 1000,           // pause before a retry (doubles on each 429/529)
    'rateLimitRetries' => 4,          // retries on 429/529
    'retryMaxDelayMs' => 8000,        // longest pause; a longer retry-after gives up at once
    'actionNamespace' => 'App\\LLM',  // 'invoice.extract' → App\LLM\Invoice\Extract
    'images' => ['maxEdge' => 1568],  // long edge of image attachments; 0 sends them as they are
    'providers' => [
        'claude' => [
            'apiKey' => env('ANTHROPIC_API_KEY', ''),
            'defaultModel' => env('ANTHROPIC_DEFAULT_MODEL', 'claude-opus-5-5'),
            'fallbacks' => 'default', // optional: server-side fallback when the model declines
        ],
        'openai' => [
            'apiKey' => env('OPENAI_API_KEY', ''),
            'defaultModel' => env('OPENAI_DEFAULT_MODEL', 'gpt-4.1-mini'),
        ],
        'gemini' => [
            'apiKey' => env('GEMINI_API_KEY', ''),
            'defaultModel' => env('GEMINI_DEFAULT_MODEL', 'gemini-2.5-flash'),
            'disableThinking' => true, // Gemini 2.x only; false for models that cannot turn thinking off
        ],
        // Custom provider: any class implementing ProviderInterface,
        // constructed with (HttpTransportInterface, apiKey, baseUrl).
        // 'local' => ['apiKey' => 'x', 'baseUrl' => 'http://...', 'defaultModel' => '...', 'className' => \App\LLM\LocalProvider::class],
    ],
    'actions' => [
        'text.summarize' => \App\LLM\Text\Summarize::class,
        'invoice.extract' => ['defaults' => ['provider' => 'claude', 'max_tokens' => 1024, 'effort' => 'low']],
    ],
];
```

Providers without an `apiKey` are not instantiated. Every `baseUrl` is optional.

### Options

Accepted as action `defaults`, by `Sage::for($action, $options)` and by `run($input, $options)`:

| Option | Meaning |
|---|---|
| `provider` | Provider key. Switching it at runtime without a `model` uses that provider's `defaultModel`. |
| `model` | Model identifier. |
| `max_tokens` | Output limit (Claude defaults to 16000; thinking counts towards it). |
| `temperature` | Only sent when set. Claude Opus 4.7+ and Claude 5 models, and OpenAI reasoning models, **reject** it: leave it unset for them. |
| `effort` | `low`, `medium`, `high`, `xhigh`, `max`. Claude: `output_config.effort`. OpenAI: `reasoning_effort` (reasoning models only; `xhigh`/`max` sent as `high`). Gemini 3+: `thinkingLevel` (`xhigh`/`max` sent as `high`); Gemini 2.x: ignored. |
| `timeout_seconds` | HTTP timeout. |
| `attachments` | List of `Nata\FilesystemManager\File` or `Sage\Service\Dto\Attachment`. Run options only. |
| `image_max_edge` | Long-edge limit for image attachments (0 = untouched). |
| `tools` | Server tools, Claude only: `web_search`, `web_fetch` (see "Server tools"). |

Resolution: `run()` options → `Sage::for()` options → action `defaults` → global config.

## Actions

Copy `stubs/Action.php`. An action implements `Sage\Service\Action\ActionInterface`:

```php
class Classify implements ActionInterface {
    public function requiredInputKeys(): array { return ['subject', 'body']; }
    public function systemPrompt(array $input): string { return 'Classify the email...'; }
    public function userPrompt(array $input): string { return "Subject: {$input['subject']}\n\n{$input['body']}"; }
    public function outputMode(): string { return 'json'; }
    public function outputSchema(): ?array { return ['category' => 'string', 'score' => '?float']; }
}
```

`outputSchema()` validates the decoded JSON: `field => type` with `string`, `int`, `float`, `bool`, `array`, `object`, `mixed`; `?type` (or `type|null`) also accepts null. All listed fields are required; extra fields are allowed. Decoding tolerates markdown fences, leading prose and raw newlines in strings.

### Native structured output

Also implement `Sage\Service\Action\JsonSchemaInterface::jsonSchema()` to return a full JSON Schema. Providers then constrain the output natively instead of asking for JSON in the prompt:

| Provider | Without `jsonSchema()` | With `jsonSchema()` |
|---|---|---|
| Claude | instruction appended to the prompt | `output_config.format` (`json_schema`) |
| OpenAI | `response_format: json_object` | `response_format: json_schema`, `strict: true` |
| Gemini | `responseMimeType: application/json` | plus `responseJsonSchema` |

For OpenAI strict mode, every object needs `additionalProperties: false` and all properties in `required` (use `["number", "null"]` for optional values).

## Running

```php
use Sage\Sage;

$result = Sage::run('email.classify', ['subject' => $subject, 'body' => $body]);
$result->json['category'];

$service = Sage::for('email.classify', ['provider' => 'openai']); // reuse in a loop
$result = $service->run($input, ['max_tokens' => 512]);

$result = Sage::run('invoice.extract', [], ['attachments' => [$file]]);
$result = Sage::run('text.check', [], ['attachments' => [Attachment::fromBytes($csv, 'text/csv')]]);
```

`LLMResult`: `action`, `provider`, `model`, `output_mode`, `text`, `json`, `usage` (`prompt_tokens`, `completion_tokens`, `total_tokens`, best effort; `web_search_requests`, `web_fetch_requests` with server tools), `latency_ms`, `finish_reason`, `sources` and `tool_errors` (server tools).

### Attachments

Backend-injected only: load the file after checking the user may see it; never accept arbitrary client data. An attachment type the provider cannot take throws `LLMValidationException` (it is never silently dropped: the model would answer without it).

| Type | Claude | OpenAI | Gemini |
|---|---|---|---|
| JPEG, PNG, WebP | ✓ | ✓ | ✓ |
| GIF | ✓ | ✓ | ✗ |
| HEIC/HEIF | ✗ | ✗ | ✓ |
| PDF | ✓ | ✗ | ✓ |
| Plain text | ✓ | ✗ | ✓ (also CSV, Markdown, HTML...) |
| Audio, video | ✗ | ✗ | ✓ |

JPEG, PNG and WebP larger than `images.maxEdge` are resampled (same format); JPEGs get their EXIF orientation applied first, since re-encoding drops the tag. Phone photos shrink from several MB to a few hundred KB, which also keeps them under Claude's 5 MB limit. Needs `ext-gd` (and `ext-exif` for the orientation); without them images are sent as they are.

### Server tools (Claude)

`tools` lets a Claude action search and read the web on Anthropic's side, within the same `run()`: `web_search` and `web_fetch` (the `_20260209` versions, with dynamic filtering; Claude Opus 4.6+ and Sonnet 4.6+). Each tool is a name or a name with its settings:

```php
'municipal.find_source' => ['defaults' => [
    'provider' => 'claude',
    'effort' => 'medium',
    'timeout_seconds' => 240,
    'tools' => [
        ['name' => 'web_search', 'max_uses' => 4, 'allowed_domains' => ['example.pt']],
        ['name' => 'web_fetch', 'max_uses' => 3, 'max_content_tokens' => 20000],
    ],
]],
```

Settings go to the API as they are (`max_uses`, `allowed_domains` or `blocked_domains`, `user_location`, `max_content_tokens`, `citations`); `type` overrides the version (e.g. `web_search_20250305` for older models). Web fetch only opens URLs already in the conversation (the prompt or earlier results).

- **Long turns.** When the server loop pauses (`stop_reason: pause_turn`), Sage sends the paused assistant turn back unchanged and resumes, up to `ClaudeProvider::MAX_CONTINUATIONS` (5) times; a turn still paused after that ends with `finish_reason` `pause_turn`.
- **Result.** `text` (and `json`) as usual; `sources` lists the pages returned (`tool`, `url`, and `title`, `page_age`, `retrieved_at` when given); `tool_errors` the calls that failed (`tool`, `error_code`, e.g. `max_uses_exceeded`, `url_not_accessible`): the model saw the error and went on. `usage` adds `web_search_requests` and `web_fetch_requests`.
- **Prompt.** At low effort the model may answer without searching: say in the system prompt to search first and never answer from memory.
- **Cost.** Web search is billed per search (on top of tokens); web fetch only by the tokens it adds. Every search result and fetched page is input to the following steps, so a turn with a few searches easily reads 100k+ input tokens: cap them with `max_uses` and `max_content_tokens`.
- OpenAI and Gemini throw `LLMValidationException` for `tools` (never silently dropped).
## Concurrent calls

The default transport, `CurlMultiTransport`, keeps one cURL multi handle for the process (`LLMFactory::transport()`). A call made outside a Fiber waits for its answer, as usual. A call made inside a Fiber suspends the Fiber until its answer is in, and so do the pauses between retries. The loop that runs the Fibers calls `poll()` and resumes them:

```php
$transport = LLMFactory::transport();
$fibers = [new Fiber(fn () => Sage::run('a', $inputA)), new Fiber(fn () => Sage::run('b', $inputB))];
foreach ($fibers as $fiber) {
    $fiber->start();
}
while ($fibers !== []) {
    $transport->poll(0.05);
    foreach ($fibers as $key => $fiber) {
        if ($fiber->isSuspended()) {
            $fiber->resume();
        }
        if ($fiber->isTerminated()) {
            unset($fibers[$key]);
        }
    }
}
```

## Errors

All extend `Sage\Service\Exception\LLMException`, with a `context()` array for logging.

| Exception | When | Retried | Suggested HTTP |
|---|---|---|---|
| `LLMValidationException` | bad input, unknown action/provider/server tool, unsupported attachment, server tools on OpenAI/Gemini, invalid or truncated JSON | no | 422 |
| `LLMRefusalException` | the model or safety filters declined (`context()['category']`) | no | 422 |
| `LLMRateLimitedException` | HTTP 429 (`context()['retry_after']`) | up to `rateLimitRetries`, backoff | 429 |
| `LLMTimeoutException` | timeout or network error | once | 504 |
| `LLMProviderException` | other provider errors (`context()['status']`) | 529: like 429; other 5xx: once | 502 |

Truncated JSON (the output hit `max_tokens`) throws `LLMValidationException` saying so, with `finish_reason` in the context.

## Testing

`FakeHttpTransport` queues responses and records requests:

```php
$transport = new FakeHttpTransport();
$transport->queueJson(200, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"category":"billing"}']]]);

LLMFactory::setTransport($transport);  // Sage::run() now uses it
$result = Sage::run('email.classify', $input);
$payload = $transport->lastPayload();  // assert on what was sent
LLMFactory::setTransport(null);
```

Or build `new LLMService(new ActionRegistry([...]), ['claude' => new ClaudeProvider($transport, 'key')], $defaults, 'action.key')`. Set `retry_delay_ms` to 0 in tests.

The plugin's own tests are in `tests/TestCase` (no network, no database).

## Architecture

```
Sage::run / Sage::for
  └ LLMFactory::build        config → ActionRegistry + providers (validates the action)
      └ LLMService::run      input check → options → Attachment + ImagePreparer
          → CompletionRequest → Provider::complete (retries) → JsonOutput::parse → LLMResult
```

| Namespace | Contents |
|---|---|
| `Sage` | `Sage` |
| `Sage\Service` | `LLMService`, `LLMFactory`, `ActionRegistry`, `JsonOutput` |
| `Sage\Service\Action` | `ActionInterface`, `JsonSchemaInterface` |
| `Sage\Service\Attachment` | `ImagePreparer` |
| `Sage\Service\Dto` | `Attachment`, `CompletionRequest`, `CompletionResponse`, `LLMResult` |
| `Sage\Service\Exception` | `LLMException` and subclasses |
| `Sage\Service\Provider` | `ProviderInterface`, `AbstractHttpProvider`, `ClaudeProvider`, `OpenAIProvider`, `GeminiProvider` |
| `Sage\Service\Transport` | `HttpTransportInterface`, `HttpTransportResponse`, `CurlMultiTransport` (default), `NataHttpTransport`, `FakeHttpTransport` |

## Changes from the appontece version

For apps upgrading from the copy in appontece:

- Configuration moves to a file returning an array (`config/sage.php`) loaded by the plugin bootstrap; `Configure::write('Sage', ...)` still works.
- Per-action `timeout_seconds` (and `effort`) in `defaults` are now honoured (they were ignored).
- A provider set in action defaults without a model now uses that provider's `defaultModel` (it used the default provider's model).
- `CompletionRequest::$attachments` holds `Attachment` DTOs; `Sage::run()` still takes Nata `File`s.
- Unsupported attachment types throw instead of being skipped.
- Claude: text joins every text block (thinking models put a `thinking` block first), refusals throw, `max_tokens` defaults to 16000 (was 512), `output_config` for effort and structured output, optional fallbacks.
- OpenAI: `max_completion_tokens` instead of `max_tokens`; refusals throw.
- Gemini: API key in the `x-goog-api-key` header (no longer in the URL), system prompt in `systemInstruction`, thought parts excluded, safety blocks throw.
- `LLMResult` and `CompletionResponse` gain `finish_reason`; `usage` no longer carries Gemini's `finish_reason`.
- The deprecated `AttachmentProcessor` is gone.
