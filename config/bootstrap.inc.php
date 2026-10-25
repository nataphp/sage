<?php
/**
 * Sage plugin (NataPHP).
 *
 * Loads the host application's Sage configuration into the `Sage.*` Configure
 * key, then fills the defaults it does not set.
 *
 * @package Sage
 */

use Nata\Core\Configure;

// The path comes from the `config` option of Plugin::load() ($config is in
// scope: Plugin::bootstrap() includes this file), falling back to the
// conventional location.
$sageAppConfigPath = $config['config'] ?? (ROOT . 'config' . DS . 'sage.php');
if (is_string($sageAppConfigPath) && is_file($sageAppConfigPath)) {
    $sageAppConfig = require $sageAppConfigPath;
    if (is_array($sageAppConfig)) {
        Configure::write('Sage', array_replace_recursive((array)Configure::read('Sage'), $sageAppConfig));
    }
    unset($sageAppConfig);
}
unset($sageAppConfigPath);

$sageDefaults = [
    'defaultProvider' => 'claude',
    'timeout_seconds' => 30,
    'retryDelayMs' => 1000,
    'rateLimitRetries' => 4,
    'retryMaxDelayMs' => 8000,
    'actionNamespace' => 'App\\LLM',
    'images' => ['maxEdge' => 1568],
    'providers' => [],
    'actions' => [],
];
foreach ($sageDefaults as $sageKey => $sageValue) {
    if (Configure::read('Sage.' . $sageKey) === null) {
        Configure::write('Sage.' . $sageKey, $sageValue);
    }
}
unset($sageDefaults, $sageKey, $sageValue);
