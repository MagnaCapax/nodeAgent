<?php
// Shared helper utilities used across collectors and submission routines.

declare(strict_types=1);

namespace McxNodeAgent;

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/environment.php';

/**
 * Determine whether a command exists within PATH using the shell.
 */
function commandAvailable(string $command): bool
{
    $descriptor = escapeshellarg($command);
    $result = shell_exec('command -v ' . $descriptor . ' 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * Measure elapsed time between the provided start timestamp and now.
 */
function profilingDurationMs(float $startedAt): float
{
    return round((microtime(true) - $startedAt) * 1000, 3);
}

/**
 * Safely write an array to disk as pretty-printed JSON via a temp file.
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
 * Read JSON from disk returning an array, or an empty array when missing/invalid.
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

/**
 * Check whether a metric collector is enabled within the provided config.
 */
function metricEnabled(array $config, string $metric): bool
{
    $metrics = $config['metrics'] ?? DEFAULT_METRIC_FLAGS;
    return isset($metrics[$metric]) ? (bool) $metrics[$metric] : false;
}

/**
 * Decide whether an expensive collector should skip execution due to load.
 */
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

/**
 * Provide a cached explanation when the system is considered busy.
 */
function systemBusyReason(array $thresholds): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!empty($thresholds['load1'])) {
        $load = sys_getloadavg();
        if (is_array($load) && isset($load[0]) && $load[0] > (float) $thresholds['load1']) {
            return $cached = sprintf('load1 %.2f > %.2f', $load[0], $thresholds['load1']);
        }
    }

    if (!empty($thresholds['ping_ms'])) {
        $latency = measureGatewayLatencyMs();
        if ($latency !== null && $latency > (float) $thresholds['ping_ms']) {
            return $cached = sprintf('ping %.1f ms > %.1f ms', $latency, $thresholds['ping_ms']);
        }
    }

    return $cached = null;
}

/**
 * Measure ping latency to the default gateway for busy-threshold checks.
 */
function measureGatewayLatencyMs(): ?float
{
    $gateway = detectDefaultGateway();
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
        return (float) $matches[1];
    }
    return null;
}

/**
 * Determine whether the agent is within a declared maintenance window.
 */
function isMaintenanceActive(array $context): bool
{
    $until = $context['config']['maintenance_until'] ?? null;
    if ($until === null || $until === '') {
        return false;
    }
    $timestamp = is_numeric($until) ? (int) $until : strtotime($until);
    if ($timestamp === false) {
        return false;
    }
    return $timestamp > time();
}

/**
 * Resolve the agent version from VERSION file or Git metadata.
 */
function agentVersion(): string
{
    $repoRoot = dirname(ROOT_DIR);
    $versionFile = $repoRoot . '/VERSION';
    if (is_file($versionFile)) {
        $content = trim((string) file_get_contents($versionFile));
        if ($content !== '') {
            return $content;
        }
    }

    $gitHead = $repoRoot . '/.git/HEAD';
    if (is_file($gitHead)) {
        $head = trim((string) file_get_contents($gitHead));
        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            $refPath = $repoRoot . '/.git/' . $ref;
            if (is_file($refPath)) {
                $commit = trim((string) file_get_contents($refPath));
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

/**
 * Approximate the agent build timestamp using VERSION or Git metadata.
 */
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

/**
 * Attempt to encrypt payloads using GnuPG symmetric mode when available.
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
        if ($stderr !== false && trim($stderr) !== '') {
            error_log('gpg encryption failed: ' . trim($stderr));
        }
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
