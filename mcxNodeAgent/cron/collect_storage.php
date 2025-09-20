#!/usr/bin/env php
<?php
// Cron entrypoint to gather storage metrics (iostat, SMART, NVMe when available).
// Focuses on heavier collectors so they can run on a relaxed cadence.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';

$context = buildContext();

parseCliFilters($argv);

$collectorMap = [
    'iostat' => __DIR__ . '/../bin/collect_iostat.php',
    'ioping' => __DIR__ . '/../bin/collect_ioping.php',
    'storage_health' => __DIR__ . '/../bin/collect_storage_health.php',
];

$collectorScripts = filterCollectors($collectorMap, $GLOBALS['__MCX_COLLECTOR_FILTER__'] ?? null);

logInfo($context, 'Storage collector cron started');

foreach ($collectorScripts as $script) {
    if (!is_file($script) || !is_readable($script)) {
        logWarn($context, sprintf('Storage collector missing: %s', basename($script)));
        continue;
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $buffer = [];
    $exitCode = 0;
    exec($command, $buffer, $exitCode);
    if ($exitCode !== 0) {
        logWarn($context, sprintf('Storage collector %s exited with code %d', basename($script), $exitCode));
    }
}

logInfo($context, 'Storage collector cron complete');

function parseCliFilters(array $argv): void
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--metrics=') === 0) {
            $list = substr($arg, 10);
            $GLOBALS['__MCX_COLLECTOR_FILTER__'] = array_filter(array_map('trim', explode(',', $list)));
        }
    }
}

function filterCollectors(array $map, ?array $filter): array
{
    if (empty($filter)) {
        return array_values($map);
    }
    $selected = [];
    foreach ($filter as $name) {
        if (isset($map[$name])) {
            $selected[] = $map[$name];
        }
    }
    return $selected;
}
