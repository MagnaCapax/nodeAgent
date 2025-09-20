<?php
// Configuration loading and normalisation helpers for mcxNodeAgent.

declare(strict_types=1);

namespace McxNodeAgent;

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/environment.php';

/**
 * Load agent configuration from disk, environment variables, and defaults.
 */
function loadConfig(): array
{
    $defaults = [
        'collector_endpoint' => 'auto',
        'collector_port' => DEFAULT_COLLECTOR_PORT,
        'collector_path' => DEFAULT_COLLECTOR_PATH,
        'ping_targets' => 'gateway:auto,external:185.148.0.2',
        'ping_count' => 3,
        'cpu_sampling_interval' => 1,
        'net_sampling_interval' => 1,
        'network_interface_filter' => '',
        'enable_submission' => 1,
        'submission_retries' => 3,
        'submission_backoff_base' => 1,
        'submission_compress' => false,
        'failure_alert_threshold' => 5,
        'state_dir' => DEFAULT_STATE_DIR,
        'log_dir' => DEFAULT_LOG_DIR,
        'log_format' => DEFAULT_LOG_FORMAT,
        'ioping_target' => '/',
        'cron_core_interval' => 1,
        'cron_iostat_interval' => 5,
        'cron_submission_interval' => 5,
        'metrics' => DEFAULT_METRIC_FLAGS,
        'busy_thresholds' => DEFAULT_BUSY_THRESHOLDS,
        'maintenance_until' => null,
        'log_rotate_max_bytes' => 5242880,
        'log_rotate_keep' => 3,
    ];

    $configPath = getenv('CONFIG_FILE') ?: ROOT_DIR . '/conf/agent.json';
    $fileConfig = [];
    if (is_readable($configPath)) {
        $raw = file_get_contents($configPath);
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $fileConfig = array_change_key_case($decoded, CASE_LOWER);
                }
            } catch (\JsonException $exception) {
                fwrite(STDERR, 'mcxNodeAgent: invalid configuration JSON: ' . $exception->getMessage() . PHP_EOL);
            }
        }
    }

    $config = array_merge($defaults, $fileConfig);

    foreach ($config as $key => $value) {
        $envKey = 'MCXNA_' . strtoupper($key);
        $envValue = getenv($envKey);
        if ($envValue === false) {
            continue;
        }
        if (is_bool($value)) {
            $config[$key] = in_array(strtolower($envValue), ['1', 'true', 'yes'], true);
            continue;
        }
        if (is_int($value)) {
            if (is_numeric($envValue)) {
                $config[$key] = (int) $envValue;
            }
            continue;
        }
        if (is_float($value)) {
            if (is_numeric($envValue)) {
                $config[$key] = (float) $envValue;
            }
            continue;
        }
        if (is_array($value)) {
            $decoded = json_decode($envValue, true);
            if (is_array($decoded)) {
                $config[$key] = $decoded;
            }
            continue;
        }

        $config[$key] = $envValue;
    }

    if (!isset($config['collector_endpoint']) || $config['collector_endpoint'] === 'auto' || $config['collector_endpoint'] === '') {
        $config['collector_endpoint'] = determineCollectorEndpoint($config);
    }

    $config['metrics'] = normalizeMetrics($config['metrics'] ?? DEFAULT_METRIC_FLAGS);
    $config['log_format'] = normalizeLogFormat($config['log_format'] ?? DEFAULT_LOG_FORMAT);
    $config['busy_thresholds'] = normalizeBusyThresholds($config['busy_thresholds'] ?? []);

    return $config;
}

/**
 * Build execution context including config and resolved filesystem paths.
 */
function buildContext(): array
{
    $config = loadConfig();

    $configuredState = isset($config['state_dir']) ? (string) $config['state_dir'] : DEFAULT_STATE_DIR;
    $stateDir = rtrim(getenv('STATE_DIR') ?: $configuredState, '/');
    if (!is_dir($stateDir) && !@mkdir($stateDir, 0755, true) && !is_dir($stateDir)) {
        $fallbackState = rtrim(sys_get_temp_dir(), '/') . '/mcxNodeAgent-state';
        if (!is_dir($fallbackState) && !@mkdir($fallbackState, 0755, true) && !is_dir($fallbackState)) {
            throw new \RuntimeException('Unable to create state directory: ' . $stateDir);
        }
        $stateDir = $fallbackState;
    }

    $configuredLog = isset($config['log_dir']) ? (string) $config['log_dir'] : DEFAULT_LOG_DIR;
    $logDir = rtrim(getenv('LOG_DIR') ?: $configuredLog, '/');
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        $logDir = $stateDir;
    }

    return [
        'config' => array_merge($config, [
            'state_dir' => $stateDir,
            'log_dir' => $logDir,
        ]),
        'paths' => [
            'root' => ROOT_DIR,
            'state' => $stateDir,
            'log_dir' => $logDir,
            'log_file' => $logDir . '/agent.log',
        ],
    ];
}

/**
 * Normalise metric toggle values, falling back to defaults when absent.
 */
function normalizeMetrics($metrics): array
{
    if (!is_array($metrics)) {
        return DEFAULT_METRIC_FLAGS;
    }

    $normalized = DEFAULT_METRIC_FLAGS;
    foreach ($normalized as $metric => $default) {
        if (array_key_exists($metric, $metrics)) {
            $normalized[$metric] = (bool) $metrics[$metric];
        }
    }

    return $normalized;
}

/**
 * Ensure log format value is one of the supported strings.
 */
function normalizeLogFormat(?string $format): string
{
    $format = strtolower(trim((string) $format));
    return in_array($format, ['text', 'json', 'json_file'], true) ? $format : DEFAULT_LOG_FORMAT;
}

/**
 * Clamp busy-threshold configuration values into safe ranges.
 */
function normalizeBusyThresholds($thresholds): array
{
    if (!is_array($thresholds)) {
        return DEFAULT_BUSY_THRESHOLDS;
    }
    $normalized = DEFAULT_BUSY_THRESHOLDS;
    foreach ($normalized as $key => $default) {
        if (array_key_exists($key, $thresholds)) {
            $normalized[$key] = $thresholds[$key];
        }
    }
    if ($normalized['skip_probability'] !== null) {
        $normalized['skip_probability'] = max(0.0, min(1.0, (float) $normalized['skip_probability']));
    }
    $normalized['load1'] = isset($normalized['load1']) ? (float) $normalized['load1'] : null;
    $normalized['ping_ms'] = isset($normalized['ping_ms']) ? (float) $normalized['ping_ms'] : null;
    return $normalized;
}
