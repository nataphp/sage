<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service;

use Nata\Core\Configure;
use Sage\Service\Attachment\ImagePreparer;
use Sage\Service\Exception\LLMValidationException;
use Sage\Service\Provider\ClaudeProvider;
use Sage\Service\Provider\GeminiProvider;
use Sage\Service\Provider\OpenAIProvider;
use Sage\Service\Provider\ProviderInterface;
use Sage\Service\Transport\HttpTransportInterface;
use Sage\Service\Transport\CurlMultiTransport;

/**
 * Builds an LLMService from `Configure::read('Sage')` (see the README for every key).
 *
 * Only providers with an `apiKey` are instantiated. A provider entry with a
 * `className` (implementing ProviderInterface, constructed with the transport,
 * API key and base URL) adds a custom provider.
 */
final class LLMFactory {

/**
 * Transport used by the providers (replaceable in tests).
 *
 * @var \Sage\Service\Transport\HttpTransportInterface|null
 */
    protected static ?HttpTransportInterface $_transport = null;

/**
 * The cURL multi transport, shared by every call of the process (so the
 * calls made in Fibers go out together).
 *
 * @var \Sage\Service\Transport\CurlMultiTransport|null
 */
    protected static ?CurlMultiTransport $_shared = null;

/**
 * Build a service bound to an action.
 *
 * @param string $action Action key.
 * @param array<string, mixed> $options Options applied to every run().
 * @return \Sage\Service\LLMService
 * @throws \Sage\Service\Exception\LLMValidationException When the action is unknown or invalid.
 */
    public static function build(string $action, array $options = []): LLMService {
        $config = (array)Configure::read('Sage');
        $registry = new ActionRegistry(
            is_array($config['actions'] ?? null) ? $config['actions'] : [],
            is_string($config['actionNamespace'] ?? null) ? $config['actionNamespace'] : ''
        );
        // Fail before any provider call.
        $registry->get($action);

        $providersConfig = is_array($config['providers'] ?? null) ? $config['providers'] : [];
        $defaults = [
            'provider' => strtolower(trim((string)($config['defaultProvider'] ?? 'claude'))),
            'models' => array_map(static fn($provider) => (string)($provider['defaultModel'] ?? ''), array_filter($providersConfig, 'is_array')),
            'timeout_seconds' => (float)($config['timeout_seconds'] ?? 30),
            'retry_delay_ms' => (int)($config['retryDelayMs'] ?? 1000),
            'rate_limit_retries' => (int)($config['rateLimitRetries'] ?? 4),
            'retry_max_delay_ms' => (int)($config['retryMaxDelayMs'] ?? 8000),
            'image_max_edge' => (int)($config['images']['maxEdge'] ?? ImagePreparer::DEFAULT_MAX_EDGE),
        ];

        return new LLMService($registry, self::providers($providersConfig), $defaults, $action, $options);
    }

/**
 * Instantiate the configured providers.
 *
 * @param array<string, array<string, mixed>> $providersConfig `Sage.providers`.
 * @return array<string, \Sage\Service\Provider\ProviderInterface>
 * @throws \Sage\Service\Exception\LLMValidationException On an invalid custom provider class.
 */
    public static function providers(array $providersConfig): array {
        $http = self::transport();
        $providers = [];
        foreach ($providersConfig as $key => $providerConfig) {
            if (!is_array($providerConfig) || empty($providerConfig['apiKey'])) {
                continue;
            }
            $apiKey = (string)$providerConfig['apiKey'];
            $baseUrl = isset($providerConfig['baseUrl']) ? (string)$providerConfig['baseUrl'] : null;

            $provider = match ($key) {
                'claude' => new ClaudeProvider($http, $apiKey, $baseUrl ?? ClaudeProvider::DEFAULT_BASE_URL, $providerConfig['fallbacks'] ?? null),
                'openai' => new OpenAIProvider($http, $apiKey, $baseUrl ?? OpenAIProvider::DEFAULT_BASE_URL),
                'gemini' => new GeminiProvider($http, $apiKey, $baseUrl ?? GeminiProvider::DEFAULT_BASE_URL, (bool)($providerConfig['disableThinking'] ?? true)),
                default => self::_customProvider((string)$key, $providerConfig, $http, $apiKey, (string)$baseUrl),
            };
            $providers[strtolower((string)$key)] = $provider;
        }
        return $providers;
    }

/**
 * The transport of the providers: the one set with setTransport(), or the
 * shared cURL multi transport.
 *
 * @return \Sage\Service\Transport\HttpTransportInterface
 */
    public static function transport(): HttpTransportInterface {
        return self::$_transport ?? (self::$_shared ??= new CurlMultiTransport());
    }

/**
 * Replace the transport (tests); null restores the shared cURL multi transport.
 *
 * @param \Sage\Service\Transport\HttpTransportInterface|null $transport Transport.
 * @return void
 */
    public static function setTransport(?HttpTransportInterface $transport): void {
        self::$_transport = $transport;
    }

/**
 * Build a provider from its `className`.
 *
 * @param string $key Provider key.
 * @param array<string, mixed> $providerConfig Provider config.
 * @param \Sage\Service\Transport\HttpTransportInterface $http Transport.
 * @param string $apiKey API key.
 * @param string $baseUrl Base URL.
 * @return \Sage\Service\Provider\ProviderInterface
 * @throws \Sage\Service\Exception\LLMValidationException When the class is missing or invalid.
 */
    protected static function _customProvider(string $key, array $providerConfig, HttpTransportInterface $http, string $apiKey, string $baseUrl): ProviderInterface {
        $class = (string)($providerConfig['className'] ?? '');
        if ($class === '' || !class_exists($class) || !is_subclass_of($class, ProviderInterface::class)) {
            throw new LLMValidationException('Unknown LLM provider: set a className implementing ProviderInterface.', 0, null, ['provider' => $key, 'class' => $class]);
        }
        return new $class($http, $apiKey, $baseUrl);
    }

}
