#!/usr/bin/env php
<?php
// Collect storage latency metrics using ioping when available.
// Provides min/avg/max latency along with throughput estimates.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\commandAvailable;
use function McxNodeAgent\writeJson;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\profilingDurationMs;
use function McxNodeAgent\ensureCommand;
use function McxNodeAgent\metricEnabled;
use function McxNodeAgent\shouldThrottleCollection;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/tooling.php';

$context = buildContext();
$config = $context['config'];
$stateFile = $context['paths']['state'] . '/storage_latency.json';
$target = (string)($config['ioping_target'] ?? '/');

$startedAt = microtime(true);

if (!metricEnabled($config, 'storage_latency')) {
    logInfo($context, 'Storage latency collector disabled via configuration; skipping');
    exit(0);
}

if (shouldThrottleCollection($context, 'storage_latency')) {
    exit(0);
}

logInfo($context, sprintf('Collecting storage latency metrics (target: %s)', $target));

$payload = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'target' => $target,
    'available' => false,
    'profiling' => [
        'duration_ms' => 0.0,
    ],
];

if (!ensureCommand($context, 'ioping', 'ioping (storage latency)')) {
    $payload['profiling']['duration_ms'] = profilingDurationMs($startedAt);
    writeJson($stateFile, $payload);
    exit(0);
}

try {
    $command = sprintf('ioping -c 5 -q %s 2>&1', escapeshellarg($target));
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        logWarn($context, 'ioping exited with non-zero status; recording metadata only');
    }

    $summary = parseIopingOutput($output);
    $payload = array_merge($payload, $summary);
    $payload['available'] = !empty($summary['latency']);
} catch (Throwable $throwable) {
    logWarn($context, 'ioping collection failed: ' . $throwable->getMessage());
}

$payload['profiling']['duration_ms'] = profilingDurationMs($startedAt);

writeJson($stateFile, $payload);
logInfo($context, sprintf('Storage latency snapshot complete in %.3f ms', $payload['profiling']['duration_ms']));

/**
 * Parse ioping quiet output for latency statistics.
 */
function parseIopingOutput(array $lines): array
{
    $joined = implode("\n", $lines);
    $result = [
        'requests' => [],
        'latency' => [],
        'bandwidth' => null,
        'raw_output' => $lines,
    ];

    if (preg_match('/(\d+) requests completed in ([0-9.]+) (\w+).*?, ([0-9.]+) kB read, ([0-9.]+) ([A-Za-z\/]+),/m', $joined, $matches)) {
        $result['requests'] = [
            'count' => (int)$matches[1],
            'duration_value' => (float)$matches[2],
            'duration_unit' => strtolower($matches[3]),
            'bytes_read_kb' => (float)$matches[4],
            'throughput' => $matches[5] . ' ' . $matches[6],
        ];
        $result['bandwidth'] = $matches[5] . ' ' . $matches[6];
    }

    if (preg_match('/min\/avg\/max\/mdev\s*=\s*([0-9.]+)\s*(\w+)\s*\/\s*([0-9.]+)\s*(\w+)\s*\/\s*([0-9.]+)\s*(\w+)\s*\/\s*([0-9.]+)\s*(\w+)/i', $joined, $matches)) {
        $result['latency'] = [
            'min' => ['value' => (float)$matches[1], 'unit' => strtolower($matches[2])],
            'avg' => ['value' => (float)$matches[3], 'unit' => strtolower($matches[4])],
            'max' => ['value' => (float)$matches[5], 'unit' => strtolower($matches[6])],
            'mdev' => ['value' => (float)$matches[7], 'unit' => strtolower($matches[8])],
        ];
    }

    return $result;
}
