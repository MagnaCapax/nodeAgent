#!/usr/bin/env php
<?php
// Collect CPU utilization and model information for mcxNodeAgent.
// Samples /proc/stat twice to compute utilization while remaining dependency light.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\currentHostname;
use function McxNodeAgent\writeJson;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logError;
use function McxNodeAgent\profilingDurationMs;
use function McxNodeAgent\metricEnabled;
use function McxNodeAgent\shouldThrottleCollection;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';

$context = buildContext();
$config = $context['config'];
$stateFile = $context['paths']['state'] . '/cpu.json';

$startedAt = microtime(true);

if (!metricEnabled($config, 'cpu')) {
    logInfo($context, 'CPU collector disabled via configuration; skipping');
    exit(0);
}

if (shouldThrottleCollection($context, 'cpu')) {
    exit(0);
}

logInfo($context, 'Collecting CPU metrics');

try {
    $interval = max(1, (int)($config['cpu_sampling_interval'] ?? 1));
    $first = readCpuSnapshot();
    sleep($interval);
    $second = readCpuSnapshot();
    $usage = computeCpuUsage($first, $second);

    $cpuInfo = gatherCpuInfo();
    $payload = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'usage_percent' => $usage,
        'core_count' => $cpuInfo['cores'],
        'architecture' => php_uname('m'),
        'model' => $cpuInfo['model'],
        'hostname' => currentHostname(),
        'profiling' => [
            'duration_ms' => profilingDurationMs($startedAt),
            'sampling_interval_s' => $interval,
        ],
        'raw_counters' => [
            'before' => $first,
            'after' => $second,
        ],
    ];

    writeJson($stateFile, $payload);
    logInfo($context, sprintf('CPU usage %.2f%% recorded for %d core(s) in %.3f ms', $usage, $cpuInfo['cores'], $payload['profiling']['duration_ms']));
} catch (Throwable $throwable) {
    logError($context, 'CPU collection failed: ' . $throwable->getMessage());
    exit(1);
}

/**
 * Read the aggregated CPU counters from /proc/stat.
 */
function readCpuSnapshot(): array
{
    $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to read /proc/stat');
    }
    foreach ($lines as $line) {
        if (strpos($line, 'cpu ') !== 0) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        $values = array_map('intval', array_slice($parts, 1));
        $total = array_sum($values);
        $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
        return ['total' => $total, 'idle' => $idle];
    }
    throw new RuntimeException('No aggregate CPU line found');
}

/**
 * Compute utilization percentage between two snapshots.
 */
function computeCpuUsage(array $first, array $second): float
{
    $deltaTotal = max(0, $second['total'] - $first['total']);
    $deltaIdle = max(0, $second['idle'] - $first['idle']);
    if ($deltaTotal <= 0) {
        return 0.0;
    }
    $active = $deltaTotal - $deltaIdle;
    return round(($active / $deltaTotal) * 100, 2);
}

/**
 * Extract model name and core count for visibility.
 */
function gatherCpuInfo(): array
{
    $lines = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return ['model' => 'Unknown', 'cores' => 1];
    }
    $model = 'Unknown';
    $cores = 0;
    foreach ($lines as $line) {
        if (stripos($line, 'model name') === 0) {
            $parts = explode(':', $line, 2);
            $model = trim($parts[1] ?? 'Unknown');
        }
        if (stripos($line, 'processor') === 0) {
            $cores++;
        }
    }
    $cores = max(1, $cores);
    return ['model' => $model, 'cores' => $cores];
}
