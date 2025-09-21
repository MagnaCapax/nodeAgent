#!/usr/bin/env php
<?php
// Cron entrypoint to gather storage metrics (iostat, SMART, NVMe when available).
// Focuses on heavier collectors so they can run on a relaxed cadence.

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

$collectorMap = [
    'iostat' => __DIR__ . '/tasks/collect_iostat.php',
    'ioping' => __DIR__ . '/tasks/collect_ioping.php',
    'storage_health' => __DIR__ . '/tasks/collect_storage_health.php',
];

$scripts = selectCronScripts($collectorMap, $filters);

logInfo($context, 'Storage collector cron started');

foreach ($scripts as $name => $script) {
    runCronScript($context, sprintf('Storage collector %s', $name), $script);
}

logInfo($context, 'Storage collector cron complete');
