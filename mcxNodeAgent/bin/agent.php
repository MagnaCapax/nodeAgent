#!/usr/bin/env php
<?php
// Unified CLI for mcxNodeAgent (health checks, metric previews, submission tests).

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\assemblePayload;
use function McxNodeAgent\prepareSubmissionEnvelope;
use function McxNodeAgent\sendPayload;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\logError;
use function McxNodeAgent\commandAvailable;
use function McxNodeAgent\profilingDurationMs;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/payload.php';
require_once __DIR__ . '/../lib/tooling.php';
require_once __DIR__ . '/../lib/submission.php';

$options = parseOptions($argv);
$context = buildContext();

switch ($options['command']) {
    case 'health':
        runHealth($context);
        break;
    case 'inspect':
        runInspect($context, $options);
        break;
    case 'submit':
        runSubmit($context, $options);
        break;
    default:
        fwrite(STDERR, "Usage: agent.php [health|inspect|submit] [options]\n");
        exit(1);
}

function parseOptions(array $argv): array
{
    $command = $argv[1] ?? 'health';
    $opts = [
        'command' => $command,
        'raw' => in_array('--raw', $argv, true),
        'metric' => null,
        'dry_run' => in_array('--dry-run', $argv, true),
        'preview' => in_array('--preview', $argv, true),
        'debug' => in_array('--debug', $argv, true),
        'retries' => null,
        'backoff' => null,
    ];
    foreach ($argv as $arg) {
        if (strpos($arg, '--metric=') === 0) {
            $opts['metric'] = substr($arg, 9);
        } elseif (strpos($arg, '--retries=') === 0) {
            $opts['retries'] = (int)substr($arg, 10);
        } elseif (strpos($arg, '--backoff=') === 0) {
            $opts['backoff'] = (int)substr($arg, 10);
        }
    }
    return $opts;
}

function runHealth(array $context): void
{
    $expectedCommands = ['iostat', 'ping', 'smartctl', 'nvme', 'ioping'];
    $missing = [];
    foreach ($expectedCommands as $command) {
        if (!commandAvailable($command)) {
            $missing[] = $command;
        }
    }

    if (!empty($missing)) {
        logWarn($context, 'Missing tooling: ' . implode(', ', $missing));
    }

    $stateDir = $context['paths']['state'];
    if (!is_dir($stateDir)) {
        logWarn($context, sprintf('State directory missing: %s', $stateDir));
    }

    logInfo($context, 'Health check completed. Inspect warnings above if present.');
}

function runInspect(array $context, array $options): void
{
    try {
        $payload = assemblePayload($context, false);
    } catch (Throwable $throwable) {
        logError($context, 'Unable to assemble payload: ' . $throwable->getMessage());
        exit(1);
    }

    if ($options['raw']) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        return;
    }

    echo 'nodeAgent payload preview:' . PHP_EOL;
    echo 'Timestamp: ' . ($payload['timestamp'] ?? 'unknown') . PHP_EOL;
    echo 'Hostname : ' . ($payload['hostname'] ?? 'unknown') . PHP_EOL;
    if (!empty($payload['meta']['disabled_metrics'])) {
        echo 'Disabled : ' . implode(', ', $payload['meta']['disabled_metrics']) . PHP_EOL;
    }
    foreach ($payload['metrics'] as $name => $data) {
        if ($options['metric'] !== null && $name !== $options['metric']) {
            continue;
        }
        echo "\n[" . $name . "]" . PHP_EOL;
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
}

function runSubmit(array $context, array $options): void
{
    $startedAt = microtime(true);
    try {
        $payload = assemblePayload($context, false);
    } catch (Throwable $throwable) {
        logError($context, 'Unable to assemble payload: ' . $throwable->getMessage());
        exit(1);
    }

    if ($options['dry_run']) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        return;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        logError($context, 'Unable to encode payload JSON');
        exit(1);
    }

    $envelope = prepareSubmissionEnvelope($context, $json);
    if ($options['preview']) {
        echo json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        return;
    }

    $endpoint = $context['config']['collector_endpoint'] ?? '';
    if ($endpoint === '') {
        logError($context, 'Collector endpoint not configured');
        exit(1);
    }

    $body = json_encode($envelope, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        logError($context, 'Unable to encode submission envelope');
        exit(1);
    }

    try {
        $result = sendPayload($endpoint, $body, $context, [
            'retries' => $options['retries'],
            'backoff' => $options['backoff'],
            'debug' => $options['debug'],
        ]);
        logInfo($context, sprintf('Submission succeeded with HTTP status %d (%.3f ms)', $result['status'], profilingDurationMs($startedAt)));
    } catch (Throwable $throwable) {
        logError($context, 'Submission error: ' . $throwable->getMessage());
        exit(1);
    }
}
