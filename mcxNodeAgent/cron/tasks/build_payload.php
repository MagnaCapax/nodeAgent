#!/usr/bin/env php
<?php
// Aggregate collector JSON fragments into a single payload for submission.
// Ensures missing collectors do not break downstream processing by using defaults.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\writeJson;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logError;
use function McxNodeAgent\assemblePayload;

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/payload.php';

$context = buildContext();
$stateDir = $context['paths']['state'];
$payloadFile = $stateDir . '/payload.json';
logInfo($context, 'Building submission payload');

try {
$payload = assemblePayload($context, true);

    writeJson($payloadFile, $payload);
    logInfo($context, 'Payload assembled successfully');
} catch (Throwable $throwable) {
    logError($context, 'Payload assembly failed: ' . $throwable->getMessage());
    exit(1);
}
