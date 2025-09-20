#!/usr/bin/env php
<?php
// Cron entrypoint that builds the latest payload and submits it to the collector.
// Aggregation failures halt the cycle; submission failures are logged for retry.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\logError;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';

$context = buildContext();

$steps = [
    'Aggregation' => __DIR__ . '/../bin/build_payload.php',
    'Submission' => __DIR__ . '/../bin/submit_payload.php',
];

logInfo($context, 'Submission cron started');

foreach ($steps as $label => $script) {
    if (!is_file($script) || !is_readable($script)) {
        logError($context, sprintf('%s script missing: %s', $label, basename($script)));
        if ($label === 'Aggregation') {
            exit(1);
        }
        continue;
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $buffer = [];
    $exitCode = 0;
    exec($command, $buffer, $exitCode);

    if ($exitCode !== 0) {
        $message = sprintf('%s step returned exit code %d', $label, $exitCode);
        if ($label === 'Aggregation') {
            logError($context, $message);
            exit(1);
        }
        logWarn($context, $message);
    }
}

logInfo($context, 'Submission cron complete');
