#!/usr/bin/env php
<?php
// Collect storage health information using smartctl/nvme when available.
// Focuses on high-level health summaries to remain lightweight and privacy friendly.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\writeJson;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\profilingDurationMs;
use function McxNodeAgent\ensureCommand;
use function McxNodeAgent\shouldRunCollector;

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/tooling.php';

$context = buildContext();
$config = $context['config'];
$stateFile = $context['paths']['state'] . '/storage_health.json';

$startedAt = microtime(true);

if (!shouldRunCollector($context, 'storage_health', 'Storage health')) {
    exit(0);
}

logInfo($context, 'Collecting storage health metrics');

$results = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'devices' => [],
    'nvme' => [],
    'profiling' => [
        'duration_ms' => 0.0,
    ],
];

$haveSmart = ensureCommand($context, 'smartctl', 'smartctl (smartmontools)');
$haveNvme = ensureCommand($context, 'nvme', 'nvme (nvme-cli)');

if ($haveSmart) {
    $results['devices'] = gatherSmartctlData();
}

if ($haveNvme) {
    $results['nvme'] = gatherNvmeData();
}

if (!$haveSmart && !$haveNvme) {
    logWarn($context, 'No storage health tooling available; metrics will be empty');
}

$results['profiling']['duration_ms'] = profilingDurationMs($startedAt);

writeJson($stateFile, $results);
logInfo($context, sprintf('Storage health snapshot captured (%d SATA/SAS, %d NVMe) in %.3f ms', count($results['devices']), count($results['nvme']), $results['profiling']['duration_ms']));

function gatherSmartctlData(): array
{
    $devices = [];
    $scanOutput = [];
    exec('smartctl --scan-open 2>/dev/null', $scanOutput);
    foreach ($scanOutput as $line) {
        if (!preg_match('/^(\S+)/', $line, $matches)) {
            continue;
        }
        $device = $matches[1];
        $info = [
            'device' => $device,
            'healthy' => null,
            'detail' => null,
        ];

        $jsonOutput = shell_exec(sprintf('smartctl --health --info --json=o %s 2>/dev/null', escapeshellarg($device)));
        if (is_string($jsonOutput)) {
            $decoded = json_decode($jsonOutput, true);
            if (isset($decoded['smart_status']['passed'])) {
                $info['healthy'] = (bool)$decoded['smart_status']['passed'];
            }
            if (isset($decoded['model_name'])) {
                $info['model'] = $decoded['model_name'];
            }
            if (isset($decoded['serial_number'])) {
                $info['serial'] = $decoded['serial_number'];
            }
            if (isset($decoded['ata_smart_attributes']['table'])) {
                $info['attributes'] = [];
                foreach ($decoded['ata_smart_attributes']['table'] as $attribute) {
                    $name = $attribute['name'] ?? ('attr_' . ($attribute['id'] ?? 'unknown'));
                    $info['attributes'][$name] = [
                        'id' => $attribute['id'] ?? null,
                        'value' => $attribute['value'] ?? null,
                        'worst' => $attribute['worst'] ?? null,
                        'threshold' => $attribute['thresh'] ?? null,
                        'raw' => $attribute['raw']['value'] ?? null,
                    ];
                }
            }
            $info['raw_output'] = $decoded;
        }

        if ($info['healthy'] === null) {
            $textOutput = shell_exec(sprintf('smartctl -H %s 2>/dev/null', escapeshellarg($device)));
            if (is_string($textOutput) && preg_match('/result:\s*(\w+)/i', $textOutput, $healthMatch)) {
                $info['healthy'] = strtoupper($healthMatch[1]) === 'PASSED';
                $info['detail'] = trim($healthMatch[0]);
            }
        }

        $devices[] = $info;
    }

    return $devices;
}

function gatherNvmeData(): array
{
    $devices = [];
    $listOutput = [];
    exec('nvme list 2>/dev/null', $listOutput);
    foreach ($listOutput as $line) {
        if (!preg_match('/^(\/dev\/\S+)/', trim($line), $matches)) {
            continue;
        }
        $device = $matches[1];
        $entry = [
            'device' => $device,
            'critical_warning' => null,
            'temperature_k' => null,
        ];

        $jsonOutput = shell_exec(sprintf('nvme smart-log --json %s 2>/dev/null', escapeshellarg($device)));
        if (is_string($jsonOutput)) {
            $decoded = json_decode($jsonOutput, true);
            if (is_array($decoded)) {
                $entry['critical_warning'] = $decoded['critical_warning'] ?? null;
                $entry['temperature_k'] = $decoded['temperature'] ?? null;
                $entry['nvme_stat'] = [
                    'power_cycles' => $decoded['power_cycles'] ?? null,
                    'power_on_hours' => $decoded['power_on_hours'] ?? null,
                    'unsafe_shutdowns' => $decoded['unsafe_shutdowns'] ?? null,
                    'media_errors' => $decoded['media_errors'] ?? null,
                    'num_err_log_entries' => $decoded['num_err_log_entries'] ?? null,
                ];
                $entry['raw_output'] = $decoded;
            }
        }

        $devices[] = $entry;
    }

    return $devices;
}
