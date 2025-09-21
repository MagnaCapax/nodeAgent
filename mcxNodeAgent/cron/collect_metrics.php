#!/usr/bin/env php
<?php
// Cron entrypoint to execute all mcxNodeAgent collectors.
// Each collector runs via the PHP CLI to keep responsibilities separated.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\parseCronFilters;
use function McxNodeAgent\selectCronScripts;
use function McxNodeAgent\runCronScript;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cron.php';

$context = buildContext();

$filters = parseCronFilters($argv);

$collectors = [
    'cpu' => __DIR__ . '/tasks/collect_cpu.php',
    'memory' => __DIR__ . '/tasks/collect_memory.php',
    'network' => __DIR__ . '/tasks/collect_network.php',
    'filesystem' => __DIR__ . '/tasks/collect_filesystems.php',
];

$selected = selectCronScripts($collectors, $filters);

logInfo($context, 'Collector cron started');

foreach ($selected as $name => $script) {
    runCronScript($context, sprintf('Collector %s', $name), $script);
}

logInfo($context, 'Collector cron complete');
