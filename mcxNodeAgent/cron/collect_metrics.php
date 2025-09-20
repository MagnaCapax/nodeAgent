#!/usr/bin/env php
<?php
// Cron entrypoint to execute all mcxNodeAgent collectors.
// Each collector runs via the PHP CLI to keep responsibilities separated.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';

$context = buildContext();

parseCliFilters($argv);

$collectors = [
    'cpu' => __DIR__ . '/../bin/collect_cpu.php',
    'memory' => __DIR__ . '/../bin/collect_memory.php',
    'network' => __DIR__ . '/../bin/collect_network.php',
    'filesystem' => __DIR__ . '/../bin/collect_filesystems.php',
];

$selected = filterCollectors($collectors, $GLOBALS['__MCX_COLLECTOR_FILTER__'] ?? null);

logInfo($context, 'Collector cron started');

foreach ($selected as $script) {
    if (!is_file($script) || !is_readable($script)) {
        logWarn($context, sprintf('Collector script missing: %s', basename($script)));
        continue;
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $buffer = [];
    $exitCode = 0;
    exec($command, $buffer, $exitCode);
    if ($exitCode !== 0) {
        logWarn($context, sprintf('Collector %s exited with code %d', basename($script), $exitCode));
    }
}

logInfo($context, 'Collector cron complete');

function parseCliFilters(array $argv): void
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--metrics=') === 0) {
            $list = substr($arg, 10);
            $GLOBALS['__MCX_COLLECTOR_FILTER__'] = array_filter(array_map('trim', explode(',', $list)));
        }
    }
}

function filterCollectors(array $collectors, ?array $filter): array
{
    if (empty($filter)) {
        return array_values($collectors);
    }
    $result = [];
    foreach ($filter as $name) {
        if (isset($collectors[$name])) {
            $result[] = $collectors[$name];
        }
    }
    return $result;
}
