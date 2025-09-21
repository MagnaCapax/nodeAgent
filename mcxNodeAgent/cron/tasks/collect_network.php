#!/usr/bin/env php
<?php
// Collect network throughput metrics and latency probes.
// Reads kernel counters directly to remain distro-neutral and fail-soft on missing tools.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\resolvePingTargets;
use function McxNodeAgent\writeJson;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\logError;
use function McxNodeAgent\profilingDurationMs;
use function McxNodeAgent\shouldRunCollector;
use function McxNodeAgent\ensureCommand;

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/tooling.php';

$context = buildContext();
$config = $context['config'];
$stateFile = $context['paths']['state'] . '/network.json';

$startedAt = microtime(true);

if (!shouldRunCollector($context, 'network', 'Network')) {
    exit(0);
}

logInfo($context, 'Collecting network metrics');

try {
    $interval = max(1, (int)($config['net_sampling_interval'] ?? 1));
    $filter = (string)($config['network_interface_filter'] ?? '');

    $initial = snapshotInterfaces($filter);
    sleep($interval);
    $final = snapshotInterfaces($filter);

    $interfaces = calculateInterfaceRates($initial, $final, $interval);

    $pings = [];
    if (ensureCommand($context, 'ping', 'ping (iputils)')) {
        $pings = performPingProbes($config);
    }

    $payload = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'interfaces' => $interfaces,
        'pings' => $pings,
        'profiling' => [
            'duration_ms' => profilingDurationMs($startedAt),
            'interfaces_observed' => count($interfaces),
            'ping_targets' => count($pings),
        ],
    ];

    writeJson($stateFile, $payload);
    logInfo($context, sprintf('Network metrics captured for %d interface(s) in %.3f ms', count($interfaces), $payload['profiling']['duration_ms']));
} catch (Throwable $throwable) {
    logError($context, 'Network collection failed: ' . $throwable->getMessage());
    exit(1);
}

/**
 * Read byte counters for all eligible interfaces.
 */
function snapshotInterfaces(string $filter): array
{
    $entries = [];
    $interfaces = @scandir('/sys/class/net');
    if (!is_array($interfaces)) {
        throw new RuntimeException('Unable to enumerate network interfaces');
    }
    $pattern = null;
    if ($filter !== '') {
        $candidate = '/' . $filter . '/';
        $pattern = @preg_match($candidate, '') === false ? null : $candidate;
    }
    foreach ($interfaces as $iface) {
        if ($iface === '.' || $iface === '..' || $iface === 'lo') {
            continue;
        }
        if ($pattern !== null && preg_match($pattern, $iface) !== 1) {
            continue;
        }
        $rxPath = sprintf('/sys/class/net/%s/statistics/rx_bytes', $iface);
        $txPath = sprintf('/sys/class/net/%s/statistics/tx_bytes', $iface);
        if (!is_readable($rxPath) || !is_readable($txPath)) {
            continue;
        }
        $rx = (int)file_get_contents($rxPath);
        $tx = (int)file_get_contents($txPath);
        $operstatePath = sprintf('/sys/class/net/%s/operstate', $iface);
        $operstate = is_readable($operstatePath) ? trim((string)file_get_contents($operstatePath)) : 'unknown';
        $entries[$iface] = [
            'rx_bytes' => $rx,
            'tx_bytes' => $tx,
            'operstate' => $operstate,
        ];
    }
    return $entries;
}

/**
 * Convert two snapshots into per-second throughput values.
 */
function calculateInterfaceRates(array $first, array $second, int $interval): array
{
    $interval = max(1, $interval);
    $results = [];
    foreach ($second as $iface => $data) {
        if (!isset($first[$iface])) {
            continue;
        }
        $deltaRx = max(0, $data['rx_bytes'] - $first[$iface]['rx_bytes']);
        $deltaTx = max(0, $data['tx_bytes'] - $first[$iface]['tx_bytes']);
        $rxPerSec = round($deltaRx / $interval, 2);
        $txPerSec = round($deltaTx / $interval, 2);
        $results[] = [
            'interface' => $iface,
            'rx_bytes_per_sec' => $rxPerSec,
            'tx_bytes_per_sec' => $txPerSec,
            'operstate' => $data['operstate'],
            'raw_counters' => [
                'before' => $first[$iface],
                'after' => $data,
            ],
        ];
    }
    return $results;
}

/**
 * Perform latency probes using configured ping targets.
 */
function performPingProbes(array $config): array
{
    $targets = resolvePingTargets($config);
    $count = max(1, (int)($config['ping_count'] ?? 3));
    $results = [];
    foreach ($targets as $target) {
        $command = sprintf('ping -n -q -c %d %s 2>&1', $count, escapeshellarg($target['target']));
        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);
        $output = implode(PHP_EOL, $lines);
        $avgRtt = extractAverageRtt($output);
        $loss = extractPacketLoss($output);
        $results[] = [
            'name' => $target['name'],
            'target' => $target['target'],
            'avg_rtt_ms' => $avgRtt,
            'packet_loss' => $loss,
            'exit_status' => $exitCode,
            'raw_output' => $output,
        ];
    }
    return $results;
}

/**
 * Pull the average RTT from ping output.
 */
function extractAverageRtt(string $output): float
{
    if (preg_match('/= ([0-9.]+)\/[0-9.]+\/[0-9.]+\/[0-9.]+ ms/', $output, $matches)) {
        return (float)$matches[1];
    }
    return -1.0;
}

/**
 * Extract the packet loss percentage reported by ping.
 */
function extractPacketLoss(string $output): string
{
    if (preg_match('/([0-9.]+)% packet loss/', $output, $matches)) {
        return $matches[1] . '%';
    }
    return 'unknown';
}
