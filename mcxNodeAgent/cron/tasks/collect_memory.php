#!/usr/bin/env php
<?php
// Collect memory and swap statistics from /proc/meminfo.
// Outputs a JSON snapshot used by downstream payload builders.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\writeJson;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logError;
use function McxNodeAgent\profilingDurationMs;
use function McxNodeAgent\shouldRunCollector;

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/logger.php';

$context = buildContext();
$config = $context['config'];
$stateFile = $context['paths']['state'] . '/memory.json';

$startedAt = microtime(true);

if (!shouldRunCollector($context, 'memory', 'Memory')) {
    exit(0);
}

logInfo($context, 'Collecting memory metrics');

try {
    $meminfo = parseMeminfo();
    $memTotal = (int)($meminfo['MemTotal'] ?? 0);
    $memAvailable = (int)($meminfo['MemAvailable'] ?? 0);
    $swapTotal = (int)($meminfo['SwapTotal'] ?? 0);
    $swapFree = (int)($meminfo['SwapFree'] ?? 0);

    $memUsed = max(0, $memTotal - $memAvailable);
    $swapUsed = max(0, $swapTotal - $swapFree);

    $memUsagePercent = calculatePercent($memTotal, $memUsed);
    $swapUsagePercent = calculatePercent($swapTotal, $swapUsed);

    $payload = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'mem_total_kb' => $memTotal,
        'mem_available_kb' => $memAvailable,
        'mem_used_kb' => $memUsed,
        'mem_usage_percent' => $memUsagePercent,
        'swap_total_kb' => $swapTotal,
        'swap_free_kb' => $swapFree,
        'swap_used_kb' => $swapUsed,
        'swap_usage_percent' => $swapUsagePercent,
        'profiling' => [
            'duration_ms' => profilingDurationMs($startedAt),
        ],
        'raw_meminfo' => $meminfo,
    ];

    writeJson($stateFile, $payload);
    logInfo($context, sprintf('Memory usage %.2f%% with swap usage %.2f%% (%.3f ms)', $memUsagePercent, $swapUsagePercent, $payload['profiling']['duration_ms']));
} catch (Throwable $throwable) {
    logError($context, 'Memory collection failed: ' . $throwable->getMessage());
    exit(1);
}

/**
 * Parse /proc/meminfo into an associative array of key/value pairs.
 */
function parseMeminfo(): array
{
    $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to read /proc/meminfo');
    }
    $out = [];
    foreach ($lines as $line) {
        [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
        $out[trim($key)] = (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }
    return $out;
}

/**
 * Compute a percentage with guard rails for zero totals.
 */
function calculatePercent(int $total, int $used): float
{
    if ($total <= 0) {
        return 0.0;
    }
    return round(($used / $total) * 100, 2);
}
