#!/usr/bin/env php
<?php
// Submit the aggregated payload to the configured collector endpoint.
// Supports hooks, dry-run previews, and configurable retry/backoff.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\logError;
use function McxNodeAgent\prepareSubmissionEnvelope;
use function McxNodeAgent\sendPayload;
use function McxNodeAgent\profilingDurationMs;

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/submission.php';

$options = parseCliOptions($argv);
$context = buildContext();
$config = $context['config'];
$stateDir = $context['paths']['state'];
$payloadFile = $stateDir . '/payload.json';
$responseFile = $stateDir . '/submit.response';
$submissionStartedAt = microtime(true);

if ((int)($config['enable_submission'] ?? 1) !== 1 && !$options['force']) {
    logWarn($context, 'Submission disabled by configuration; exiting');
    exit(0);
}

if (!is_file($payloadFile) || filesize($payloadFile) === 0) {
    logError($context, 'Payload file missing or empty; cannot submit');
    exit(1);
}

$endpoint = (string)($config['collector_endpoint'] ?? '');
if ($endpoint === '') {
    logError($context, 'Collector endpoint is not configured');
    exit(1);
}

$json = file_get_contents($payloadFile);
if ($json === false) {
    logError($context, 'Failed to read payload file');
    exit(1);
}

$payload = json_decode($json, true);
if (!is_array($payload)) {
    logError($context, 'Payload file contains invalid JSON');
    exit(1);
}

if ($options['dry_run']) {
    logInfo($context, '[dry-run] Printing payload (no network call)');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$envelope = prepareSubmissionEnvelope($context, $json);
$body = json_encode($envelope, JSON_UNESCAPED_SLASHES);
if ($body === false) {
    logError($context, 'Unable to encode submission envelope');
    exit(1);
}

if (!empty($options['preview'])) {
    echo "Envelope preview:\n";
    echo json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

try {
    $result = sendPayload($endpoint, $body, $context, [
        'retries' => $options['retries'],
        'backoff' => $options['backoff'],
        'debug' => $options['debug'],
        'compress' => $context['config']['submission_compress'] ?? false,
    ]);
    file_put_contents($responseFile, $result['body']);
    logInfo($context, sprintf('Submission succeeded with HTTP status %d (%.3f ms)', $result['status'], profilingDurationMs($submissionStartedAt)));
} catch (Throwable $throwable) {
    logError($context, 'Submission error: ' . $throwable->getMessage());
    exit(1);
}

function parseCliOptions(array $argv): array
{
    $options = [
        'dry_run' => false,
        'retries' => null,
        'backoff' => null,
        'debug' => false,
        'preview' => false,
        'force' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif (strpos($arg, '--retries=') === 0) {
            $options['retries'] = (int)substr($arg, 10);
        } elseif (strpos($arg, '--backoff=') === 0) {
            $options['backoff'] = (int)substr($arg, 10);
        } elseif ($arg === '--debug') {
            $options['debug'] = true;
        } elseif ($arg === '--preview') {
            $options['preview'] = true;
        } elseif ($arg === '--force') {
            $options['force'] = true;
        }
    }

    return $options;
}
