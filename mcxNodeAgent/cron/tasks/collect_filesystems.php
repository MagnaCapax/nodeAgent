#!/usr/bin/env php
<?php
// Collect filesystem utilisation (blocks and inodes) for mounted filesystems.

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
$stateFile = $context['paths']['state'] . '/filesystem.json';

$startedAt = microtime(true);

if (!shouldRunCollector($context, 'filesystem', 'Filesystem')) {
    exit(0);
}

logInfo($context, 'Collecting filesystem utilisation metrics');
if (!ensureCommand($context, 'df', 'df (filesystem usage)')) {
    $payload = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'filesystems' => [],
        'profiling' => [
            'duration_ms' => profilingDurationMs($startedAt),
        ],
    ];
    writeJson($stateFile, $payload);
    exit(0);
}

$filesystems = gatherFilesystems();

$payload = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'filesystems' => $filesystems,
    'profiling' => [
        'duration_ms' => profilingDurationMs($startedAt),
    ],
];

writeJson($stateFile, $payload);
logInfo($context, sprintf('Filesystem metrics captured for %d mount(s) in %.3f ms', count($filesystems), $payload['profiling']['duration_ms']));

function gatherFilesystems(): array
{
    $output = [];
    exec('df -P -T 2>/dev/null', $output);
    $inodeMap = gatherInodeStats();
    $filesystems = [];
    foreach ($output as $index => $line) {
        if ($index === 0) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 7) {
            continue;
        }
        [$filesystem, $type, $blocks, $used, $available, $usePercent, $mountpoint] = array_pad($parts, 7, null);
        $inodeData = $inodeMap[$mountpoint] ?? null;
        $filesystems[] = [
            'filesystem' => $filesystem,
            'type' => $type,
            'blocks_kb' => (int)$blocks,
            'used_kb' => (int)$used,
            'available_kb' => (int)$available,
            'use_percent' => (float) rtrim($usePercent ?? '0', '%'),
            'mountpoint' => $mountpoint,
            'inode_total' => $inodeData['total'] ?? null,
            'inode_used' => $inodeData['used'] ?? null,
            'inode_available' => $inodeData['available'] ?? null,
            'inode_use_percent' => $inodeData['use_percent'] ?? null,
            'raw_line' => $line,
        ];
    }

    return $filesystems;
}

function gatherInodeStats(): array
{
    $map = [];
    $output = [];
    exec('df -Pi 2>/dev/null', $output);
    foreach ($output as $index => $line) {
        if ($index === 0) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 6) {
            continue;
        }
        [$filesystem, $inodes, $iused, $ifree, $ipercent, $mount] = array_pad($parts, 6, null);
        $map[$mount] = [
            'total' => (int)$inodes,
            'used' => (int)$iused,
            'available' => (int)$ifree,
            'use_percent' => (float) rtrim($ipercent ?? '0', '%'),
        ];
    }
    return $map;
}
