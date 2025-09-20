<?php
// Shared bootstrap helpers for mcxNodeAgent PHP scripts.
// Provides configuration loading, filesystem helpers, and utility routines.

declare(strict_types=1);

namespace McxNodeAgent;

const ROOT_DIR = __DIR__ . '/..';
const DEFAULT_STATE_DIR = '/var/lib/mcxNodeAgent';
const DEFAULT_LOG_DIR = '/var/log/mcxNodeAgent';
const DEFAULT_LOG_FORMAT = 'text';
const DEFAULT_COLLECTOR_PORT = 8080;
const DEFAULT_COLLECTOR_PATH = '/nodeAgentCollector/';
const DEFAULT_METRIC_FLAGS = [
    'cpu' => true,
    'memory' => true,
    'network' => true,
    'storage' => true,
    'storage_latency' => true,
    'storage_health' => true,
    'filesystem' => true,
];
const DEFAULT_BUSY_THRESHOLDS = [
    'load1' => null,
    'ping_ms' => null,
    'skip_probability' => 0.9,
];

/**
 * Load configuration from the default INI file while applying safe defaults.
 * Environment variables prefixed with MCXNA_ can override specific keys.
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
                $config[$key] = (int)$envValue;
            }
            continue;
        }
        if (is_float($value)) {
            if (is_numeric($envValue)) {
                $config[$key] = (float)$envValue;
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
 * Build a context array containing directories, configuration, and logger paths.
 * Ensures state directory exists and log directory is available with fallback.
 */
function buildContext(): array
{
    $config = loadConfig();

    $configuredState = isset($config['state_dir']) ? (string)$config['state_dir'] : DEFAULT_STATE_DIR;
    $stateDir = rtrim(getenv('STATE_DIR') ?: $configuredState, '/');
    if (!is_dir($stateDir) && !@mkdir($stateDir, 0755, true) && !is_dir($stateDir)) {
        $fallbackState = rtrim(sys_get_temp_dir(), '/') . '/mcxNodeAgent-state';
        if (!is_dir($fallbackState) && !@mkdir($fallbackState, 0755, true) && !is_dir($fallbackState)) {
            throw new \RuntimeException('Unable to create state directory: ' . $stateDir);
        }
        $stateDir = $fallbackState;
    }

    $configuredLog = isset($config['log_dir']) ? (string)$config['log_dir'] : DEFAULT_LOG_DIR;
    $logDir = rtrim(getenv('LOG_DIR') ?: $configuredLog, '/');
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        $logDir = $stateDir;
    }

    $context = [
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

    return $context;
}

/**
 * Determine whether a command is available in PATH by invoking `command -v`.
 */
function commandAvailable(string $command): bool
{
    $descriptor = escapeshellarg($command);
    $result = shell_exec('command -v ' . $descriptor . ' 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * Measure elapsed time between start and now in milliseconds.
 */
function profilingDurationMs(float $startedAt): float
{
    return round((microtime(true) - $startedAt) * 1000, 3);
}

/**
 * Safely write an array as JSON to the supplied file path.
 */
function writeJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new \RuntimeException('Failed to encode JSON payload for ' . $path);
    }
    $tmpPath = $path . '.tmp';
    if (file_put_contents($tmpPath, $json) === false) {
        throw new \RuntimeException('Failed to write temporary file: ' . $tmpPath);
    }
    if (!rename($tmpPath, $path)) {
        throw new \RuntimeException('Failed to promote temporary file: ' . $path);
    }
}

/**
 * Read JSON from disk and return an array, returning an empty array when missing.
 */
function readJsonOrEmpty(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return [];
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function metricEnabled(array $config, string $metric): bool
{
    $metrics = $config['metrics'] ?? DEFAULT_METRIC_FLAGS;
    return isset($metrics[$metric]) ? (bool)$metrics[$metric] : false;
}

/**
 * Convert `/proc/net/route` gateway entries to human-readable IPv4 addresses.
 */
function detectDefaultGateway(): ?string
{
    $lines = @file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return null;
    }
    foreach ($lines as $index => $line) {
        if ($index === 0) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 8) {
            continue;
        }
        $destination = $parts[1] ?? '';
        $flags = (int)hexdec($parts[3] ?? '0');
        if ($destination !== '00000000' || ($flags & 0x2) !== 0x2) {
            continue;
        }
        $gatewayHex = $parts[2] ?? '';
        if ($gatewayHex === '') {
            continue;
        }
        $octets = array_reverse(str_split($gatewayHex, 2));
        $converted = array_map(static fn(string $octet): string => (string)hexdec($octet), $octets);
        return implode('.', $converted);
    }

    return null;
}

/**
 * Expand ping targets defined as name:target pairs, resolving "auto" via default gateway.
 */
function resolvePingTargets(array $config): array
{
    $targets = [];
    $raw = $config['ping_targets'] ?? '';
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return $targets;
        }
        $entries = array_map('trim', explode(',', $raw));
    } elseif (is_array($raw)) {
        $entries = $raw;
    } else {
        return $targets;
    }

    $defaultGateway = null;
    foreach ($entries as $entry) {
        if (is_array($entry)) {
            $name = trim((string)($entry['name'] ?? ''));
            $target = trim((string)($entry['target'] ?? ''));
        } else {
            $entry = trim((string)$entry);
            if ($entry === '') {
                continue;
            }
            [$name, $target] = array_pad(explode(':', $entry, 2), 2, '');
            $name = trim($name);
            $target = trim($target);
        }

        if ($name === '') {
            continue;
        }

        if ($target === 'auto' || $target === '') {
            $defaultGateway ??= detectDefaultGateway();
            if ($defaultGateway === null) {
                continue;
            }
            $target = $defaultGateway;
        }

        $targets[] = ['name' => $name, 'target' => $target];
    }

    return $targets;
}

/**
 * Convenience helper to expose the current hostname in a fail-soft fashion.
 */
function currentHostname(): string
{
    $hostname = gethostname();
    if (is_string($hostname) && $hostname !== '') {
        return $hostname;
    }
    $fallback = trim((string) shell_exec('hostname -f 2>/dev/null'));
    return $fallback !== '' ? $fallback : 'unknown-host';
}

/**
 * Determine the best-effort collector endpoint using the current IPv4 /24.
 */
function determineCollectorEndpoint(array $config): string
{
    $port = (int)($config['collector_port'] ?? DEFAULT_COLLECTOR_PORT);
    $path = (string)($config['collector_path'] ?? DEFAULT_COLLECTOR_PATH);
    if ($path === '') {
        $path = DEFAULT_COLLECTOR_PATH;
    }
    $path = '/' . ltrim($path, '/');
    if (!str_ends_with($path, '/')) {
        $path .= '/';
    }

    $baseIp = detectPrimaryIPv4();
    if ($baseIp === null) {
        return sprintf('http://192.0.2.2:%d%s', $port, $path);
    }
    $segments = explode('.', $baseIp);
    if (count($segments) !== 4) {
        return sprintf('http://192.0.2.2:%d%s', $port, $path);
    }
    $segments[3] = '2';
    $collectorIp = implode('.', $segments);
    return sprintf('http://%s:%d%s', $collectorIp, $port, $path);
}

/**
 * Detect the primary IPv4 address used for outbound connections.
 */
function detectPrimaryIPv4(): ?string
{
    $candidate = shell_exec('ip route get 1 2>/dev/null');
    if (is_string($candidate) && $candidate !== '') {
        if (preg_match('/src\s+(\d+\.\d+\.\d+\.\d+)/', $candidate, $matches)) {
            return $matches[1];
        }
    }
    $candidate = shell_exec('ip route get 8.8.8.8 2>/dev/null');
    if (is_string($candidate) && $candidate !== '') {
        if (preg_match('/src\s+(\d+\.\d+\.\d+\.\d+)/', $candidate, $matches)) {
            return $matches[1];
        }
    }
    $candidate = @file_get_contents('/proc/net/route');
    if (is_string($candidate)) {
        $lines = preg_split('/\R/', trim($candidate));
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) > 7 && $parts[1] === '00000000') {
                $iface = $parts[0];
                $output = shell_exec('ip -4 addr show ' . escapeshellarg($iface) . ' | awk "/inet / {print \$2}" | cut -d/ -f1 2>/dev/null');
                if (is_string($output)) {
                    $ip = trim($output);
                    if ($ip !== '') {
                        return $ip;
                    }
                }
            }
        }
    }
    return null;
}

/**
 * Determine the primary network interface used for outbound connectivity.
 */
function detectPrimaryInterface(): ?string
{
    $candidate = shell_exec('ip route get 1 2>/dev/null');
    if (is_string($candidate) && preg_match('/dev\s+(\S+)/', $candidate, $matches)) {
        return $matches[1];
    }
    $candidate = shell_exec('ip route show default 2>/dev/null');
    if (is_string($candidate) && preg_match('/dev\s+(\S+)/', $candidate, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Read the MAC address for a given network interface.
 */
function detectMacAddress(?string $interface): ?string
{
    if ($interface === null || $interface === '') {
        return null;
    }
    $path = '/sys/class/net/' . $interface . '/address';
    if (!is_readable($path)) {
        return null;
    }
    $mac = trim((string)file_get_contents($path));
    return $mac !== '' ? strtolower($mac) : null;
}

/**
 * Derive a deterministic passphrase using IP + MAC identity.
 */
function derivePassphraseFromIdentity(?string $ip, ?string $mac): string
{
    $ipPart = $ip ?? '0.0.0.0';
    $macPart = $mac ?? '00:00:00:00:00:00';
    return hash('sha256', strtolower($ipPart . '|' . $macPart));
}

/**
 * Attempt to encrypt payload using GPG symmetric mode with the derived passphrase.
 */
function encryptPayloadWithGpg(string $plaintext, string $passphrase, string $workingDir): ?array
{
    if (!commandAvailable('gpg')) {
        return null;
    }

    $startedAt = microtime(true);

    $tmpPlain = tempnam($workingDir, 'mcx-plain-');
    $tmpCipher = $tmpPlain . '.gpg';
    if ($tmpPlain === false || file_put_contents($tmpPlain, $plaintext) === false) {
        return null;
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $cmd = sprintf(
        'gpg --batch --yes --passphrase-fd 0 --symmetric --cipher-algo AES256 -o %s %s',
        escapeshellarg($tmpCipher),
        escapeshellarg($tmpPlain)
    );

    $process = proc_open($cmd, $descriptorSpec, $pipes, $workingDir);
    if (!is_resource($process)) {
        @unlink($tmpPlain);
        @unlink($tmpCipher);
        return null;
    }

    fwrite($pipes[0], $passphrase . "\n");
    fclose($pipes[0]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || !is_file($tmpCipher)) {
        @unlink($tmpPlain);
        @unlink($tmpCipher);
        return null;
    }

    $ciphertext = file_get_contents($tmpCipher);
    @unlink($tmpPlain);
    @unlink($tmpCipher);

    if ($ciphertext === false) {
        return null;
    }

    return [
        'ciphertext' => base64_encode($ciphertext),
        'method' => 'gpg-symmetric',
        'fingerprint' => substr(hash('sha256', $passphrase), 0, 16),
        'duration_ms' => profilingDurationMs($startedAt),
    ];
}

function normalizeMetrics($metrics): array
{
    if (!is_array($metrics)) {
        return DEFAULT_METRIC_FLAGS;
    }

    $normalized = DEFAULT_METRIC_FLAGS;
    foreach ($normalized as $metric => $default) {
        if (array_key_exists($metric, $metrics)) {
            $normalized[$metric] = (bool)$metrics[$metric];
        }
    }

    return $normalized;
}

function normalizeLogFormat(?string $format): string
{
    $format = strtolower(trim((string)$format));
    return in_array($format, ['text', 'json', 'json_file'], true) ? $format : DEFAULT_LOG_FORMAT;
}

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
        $normalized['skip_probability'] = max(0.0, min(1.0, (float)$normalized['skip_probability']));
    }
    $normalized['load1'] = isset($normalized['load1']) ? (float)$normalized['load1'] : null;
    $normalized['ping_ms'] = isset($normalized['ping_ms']) ? (float)$normalized['ping_ms'] : null;
    return $normalized;
}

function shouldThrottleCollection(array $context, string $collector): bool
{
    if (isMaintenanceActive($context)) {
        if (function_exists(__NAMESPACE__ . '\\logInfo')) {
            logInfo($context, sprintf('Skipping %s collection: maintenance mode active', $collector));
        }
        return true;
    }
    $thresholds = $context['config']['busy_thresholds'] ?? [];
    if ((!$thresholds['load1'] && !$thresholds['ping_ms'])) {
        return false;
    }
    $reason = systemBusyReason($thresholds);
    if ($reason === null) {
        return false;
    }
    $skipProbability = $thresholds['skip_probability'] ?? 0.9;
    if ((mt_rand() / mt_getrandmax()) < $skipProbability) {
        if (function_exists(__NAMESPACE__ . '\\logWarn')) {
            logWarn($context, sprintf('Skipping %s collection: busy system (%s)', $collector, $reason));
        } else {
            error_log(sprintf('Skipping %s collection: busy system (%s)', $collector, $reason));
        }
        return true;
    }
    return false;
}

function systemBusyReason(array $thresholds): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!empty($thresholds['load1'])) {
        $load = sys_getloadavg();
        if (is_array($load) && isset($load[0]) && $load[0] > (float)$thresholds['load1']) {
            return $cached = sprintf('load1 %.2f > %.2f', $load[0], $thresholds['load1']);
        }
    }

    if (!empty($thresholds['ping_ms'])) {
        $latency = measureGatewayLatencyMs();
        if ($latency !== null && $latency > (float)$thresholds['ping_ms']) {
            return $cached = sprintf('ping %.1f ms > %.1f ms', $latency, $thresholds['ping_ms']);
        }
    }

    return $cached = null;
}

function measureGatewayLatencyMs(): ?float
{
    $gateway = default_gateway();
    if (!$gateway) {
        return null;
    }
    if (!commandAvailable('ping')) {
        return null;
    }
    $command = sprintf('ping -n -q -c 1 -W 1 %s 2>&1', escapeshellarg($gateway));
    $output = shell_exec($command);
    if (!is_string($output)) {
        return null;
    }
    if (preg_match('/= ([0-9.]+)\/[0-9.]+\/[0-9.]+\/[0-9.]+ ms/', $output, $matches)) {
        return (float)$matches[1];
    }
    return null;
}

function isMaintenanceActive(array $context): bool
{
    $until = $context['config']['maintenance_until'] ?? null;
    if ($until === null || $until === '') {
        return false;
    }
    $timestamp = is_numeric($until) ? (int)$until : strtotime($until);
    if ($timestamp === false) {
        return false;
    }
    return $timestamp > time();
}

function agentVersion(): string
{
    $repoRoot = dirname(ROOT_DIR);
    $versionFile = $repoRoot . '/VERSION';
    if (is_file($versionFile)) {
        $content = trim((string)file_get_contents($versionFile));
        if ($content !== '') {
            return $content;
        }
    }

    $gitHead = $repoRoot . '/.git/HEAD';
    if (is_file($gitHead)) {
        $head = trim((string)file_get_contents($gitHead));
        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            $refPath = $repoRoot . '/.git/' . $ref;
            if (is_file($refPath)) {
                $commit = trim((string)file_get_contents($refPath));
                if ($commit !== '') {
                    return 'git-' . substr($commit, 0, 7);
                }
            }
        } elseif ($head !== '') {
            return 'git-' . substr($head, 0, 7);
        }
    }

    return '0.0.0-dev';
}

function agentBuildTimestamp(): string
{
    $repoRoot = dirname(ROOT_DIR);
    $versionFile = $repoRoot . '/VERSION';
    if (is_file($versionFile)) {
        $mtime = filemtime($versionFile);
        if ($mtime !== false) {
            return gmdate('Y-m-d\TH:i:s\Z', $mtime);
        }
    }
    $gitHead = $repoRoot . '/.git/HEAD';
    if (is_file($gitHead)) {
        $mtime = filemtime($gitHead);
        if ($mtime !== false) {
            return gmdate('Y-m-d\TH:i:s\Z', $mtime);
        }
    }
    return gmdate('Y-m-d\TH:i:s\Z');
}
