#!/usr/bin/env php
<?php
// Collect block device throughput metrics using iostat when available.
// Samples over a three-second window and falls back to /proc/diskstats when needed.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\commandAvailable;
use function McxNodeAgent\writeJson;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\logError;
use function McxNodeAgent\profilingDurationMs;
use function McxNodeAgent\ensureCommand;
use function McxNodeAgent\metricEnabled;
use function McxNodeAgent\shouldThrottleCollection;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/tooling.php';

$context = buildContext();
$config = $context['config'];
$stateFile = $context['paths']['state'] . '/storage.json';

$startedAt = microtime(true);

if (!metricEnabled($config, 'storage')) {
    logInfo($context, 'Storage collector disabled via configuration; skipping');
    exit(0);
}

if (shouldThrottleCollection($context, 'storage')) {
    exit(0);
}

logInfo($context, 'Collecting block device metrics');
$haveIostat = ensureCommand($context, 'iostat', 'iostat (sysstat)');

try {
    $iostatRaw = [];
    $iostatDevices = [];
    if ($haveIostat) {
        $iostatResult = collectWithIostat();
        $iostatDevices = $iostatResult['devices'];
        $iostatRaw = $iostatResult['raw'];
    }
    $iostatUsed = $haveIostat && !empty($iostatDevices);
    $devices = $iostatDevices;
    if (!$iostatUsed) {
        logWarn($context, 'iostat unavailable or returned no data; falling back to /proc/diskstats');
        try {
            $diskstats = collectFromDiskstats();
            $devices = $diskstats['devices'];
            $iostatRaw = ['diskstats' => $diskstats['raw']];
        } catch (Throwable $fallbackError) {
            logWarn($context, 'Diskstats fallback failed: ' . $fallbackError->getMessage());
            $devices = [];
        }
    }

    $payload = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'devices' => $devices,
        'profiling' => [
            'duration_ms' => profilingDurationMs($startedAt),
            'iostat_used' => $iostatUsed,
            'sample_window_s' => 3,
        ],
        'raw_source' => $iostatRaw,
    ];

    writeJson($stateFile, $payload);
    logInfo($context, sprintf('Storage metrics captured for %d device(s) in %.3f ms', count($devices), $payload['profiling']['duration_ms']));
} catch (Throwable $throwable) {
    logError($context, 'Storage collection failed: ' . $throwable->getMessage());
    exit(1);
}

/**
 * Attempt to collect metrics via iostat.
 */
function collectWithIostat(): array
{
    if (!commandAvailable('iostat')) {
        return ['devices' => [], 'raw' => []];
    }
    $output = shell_exec('iostat -d -k 3 2 2>/dev/null');
    if (!is_string($output) || trim($output) === '') {
        return ['devices' => [], 'raw' => []];
    }
    $lines = preg_split('/\R/', trim($output));
    $section = 0;
    $devices = [];
    foreach ($lines as $line) {
        if (preg_match('/^Device/', $line)) {
            $section++;
            continue;
        }
        if ($section !== 2 || trim($line) === '') {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 4) {
            continue;
        }
        [$device, $tps, $kbRead, $kbWrite] = array_pad($parts, 4, '0');
        $devices[] = [
            'device' => $device,
            'tps' => (float)$tps,
            'kb_read_s' => (float)$kbRead,
            'kb_wrtn_s' => (float)$kbWrite,
            'raw' => $parts,
        ];
    }
    return ['devices' => $devices, 'raw' => $lines];
}

/**
 * Fallback metrics derived from /proc/diskstats when iostat is not usable.
 */
function collectFromDiskstats(): array
{
    $lines = @file('/proc/diskstats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to read /proc/diskstats');
    }
    $devices = [];
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 14) {
            continue;
        }
        $device = $parts[2];
        if (preg_match('/^(loop|ram)/', $device)) {
            continue;
        }
        $devices[] = [
            'device' => $device,
            'reads_completed' => (int)$parts[3],
            'writes_completed' => (int)$parts[7],
            'raw' => $parts,
        ];
    }
    return ['devices' => $devices, 'raw' => $lines];
}
