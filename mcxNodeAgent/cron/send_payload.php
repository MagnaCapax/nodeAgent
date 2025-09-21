#!/usr/bin/env php
<?php
// Cron entrypoint that builds the latest payload and submits it to the collector.
// Aggregation failures halt the cycle; submission failures are logged for retry.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\runCronScript;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cron.php';

$context = buildContext();

$steps = [
    'Aggregation' => __DIR__ . '/tasks/build_payload.php',
    'Submission' => __DIR__ . '/tasks/submit_payload.php',
];

logInfo($context, 'Submission cron started');

foreach ($steps as $label => $script) {
    $treatFailureAsError = $label === 'Aggregation';
    $exitCode = runCronScript($context, $label, $script, [], null, $treatFailureAsError);
    if ($exitCode !== 0 && $treatFailureAsError) {
        exit(1);
    }
}

logInfo($context, 'Submission cron complete');
