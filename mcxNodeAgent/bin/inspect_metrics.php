#!/usr/bin/env php
<?php
// Inspect collected metrics and preview the submission payload in a human-readable format.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\assemblePayload;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logError;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/payload.php';

$options = parseOptions($argv);
$context = buildContext();

try {
    $payload = assemblePayload($context, false);
} catch (Throwable $throwable) {
    logError($context, 'Unable to assemble payload: ' . $throwable->getMessage());
    exit(1);
}

if ($options['raw']) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

if ($options['diff']) {
    showDiff($context, $payload);
    exit(0);
}

displaySummary($payload, $options['metric'], $options['format']);
exit(0);

function parseOptions(array $argv): array
{
    $options = [
        'raw' => false,
        'diff' => false,
        'metric' => null,
        'format' => 'text',
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--raw') {
            $options['raw'] = true;
        } elseif ($arg === '--diff') {
            $options['diff'] = true;
        } elseif (strpos($arg, '--metric=') === 0) {
            $options['metric'] = substr($arg, 9);
        } elseif (strpos($arg, '--format=') === 0) {
            $format = strtolower(substr($arg, 9));
            if (in_array($format, ['text', 'markdown'], true)) {
                $options['format'] = $format;
            }
        }
    }
    return $options;
}

function displaySummary(array $payload, ?string $metricFilter, string $format): void
{
    if ($format === 'markdown') {
        echo "# nodeAgent Metric Snapshot\n\n";
        echo "*Timestamp:* " . ($payload['timestamp'] ?? 'unknown') . "\n";
        echo "*Hostname:* " . ($payload['hostname'] ?? 'unknown') . "\n";
        if (!empty($payload['meta']['disabled_metrics'])) {
            echo "*Disabled:* " . implode(', ', $payload['meta']['disabled_metrics']) . "\n";
        }
        if (!empty($payload['meta']['profiling']['collectors'])) {
            echo "\n## Collector Durations (ms)\n";
            foreach ($payload['meta']['profiling']['collectors'] as $name => $duration) {
                printf("- **%s:** %s\n", $name, $duration);
            }
        }
        echo "\n## Metrics\n";
    } else {
        echo 'nodeAgent Metric Snapshot', PHP_EOL;
        echo 'Timestamp: ', $payload['timestamp'] ?? 'unknown', PHP_EOL;
        echo 'Hostname : ', $payload['hostname'] ?? 'unknown', PHP_EOL;
        if (!empty($payload['meta']['disabled_metrics'])) {
            echo 'Disabled : ', implode(', ', $payload['meta']['disabled_metrics']), PHP_EOL;
        }
        if (!empty($payload['meta']['profiling']['collectors'])) {
            echo 'Collector durations (ms):', PHP_EOL;
            foreach ($payload['meta']['profiling']['collectors'] as $name => $duration) {
                printf("  - %-18s %s\n", $name, $duration);
            }
        }
        echo PHP_EOL . 'Metrics:' . PHP_EOL;
    }

    foreach ($payload['metrics'] as $name => $data) {
        if ($metricFilter !== null && $name !== $metricFilter) {
            continue;
        }
        if ($format === 'markdown') {
            echo "\n### " . $name . "\n";
        } else {
            echo sprintf("[%s]%s", $name, PHP_EOL);
        }
        if (empty($data)) {
            echo ($format === 'markdown' ? '_no data_' : "  (no data available)") . PHP_EOL;
            continue;
        }
        $preview = $data;
        unset($preview['profiling']);
        echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        if ($format !== 'markdown') {
            echo PHP_EOL;
        }
    }
}

function showDiff(array $context, array $current): void
{
    $stateDir = $context['paths']['state'];
    $previousFile = $stateDir . '/payload.json';
    if (!is_file($previousFile)) {
        echo "No previous payload found (expected at $previousFile)." . PHP_EOL;
        echo "Current payload:" . PHP_EOL;
        echo json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        return;
    }

    $previous = json_decode(file_get_contents($previousFile), true) ?: [];
    $changes = [];

    foreach ($current['metrics'] as $metric => $data) {
        $old = $previous['metrics'][$metric] ?? null;
        if ($old !== $data) {
            $changes[$metric] = [
                'before' => $old,
                'after' => $data,
            ];
        }
    }

    if (empty($changes)) {
        echo "No metric changes detected versus last payload." . PHP_EOL;
        return;
    }

    foreach ($changes as $metric => $diff) {
        echo "Metric: $metric" . PHP_EOL;
        printDiff($diff['before'], $diff['after']);
        echo str_repeat('-', 40) . PHP_EOL;
    }
}

function printDiff($before, $after, string $prefix = ''): void
{
    $keys = array_unique(array_merge(array_keys((array)$before), array_keys((array)$after)));
    foreach ($keys as $key) {
        $newPrefix = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        $oldValue = $before[$key] ?? null;
        $newValue = $after[$key] ?? null;
        if (is_array($oldValue) && is_array($newValue)) {
            printDiff($oldValue, $newValue, $newPrefix);
        } elseif ($oldValue !== $newValue) {
            if (is_numeric($oldValue) && is_numeric($newValue)) {
                $delta = $newValue - $oldValue;
                printf("  %s: %s -> %s (Δ %.3f)\n", $newPrefix, $oldValue, $newValue, $delta);
            } else {
                printf("  %s: %s -> %s\n", $newPrefix, json_encode($oldValue, JSON_UNESCAPED_SLASHES), json_encode($newValue, JSON_UNESCAPED_SLASHES));
            }
        }
    }
}
