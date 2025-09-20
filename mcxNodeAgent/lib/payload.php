<?php
// Payload assembly helpers shared by build/inspect/submit routines.

declare(strict_types=1);

namespace McxNodeAgent;

function assemblePayload(array $context, bool $persistSequence = false): array
{
    $startedAt = microtime(true);
    $config = $context['config'];
    $stateDir = $context['paths']['state'];

    $metricFiles = [
        'cpu' => 'cpu.json',
        'memory' => 'memory.json',
        'network' => 'network.json',
        'storage' => 'storage.json',
        'storage_latency' => 'storage_latency.json',
        'storage_health' => 'storage_health.json',
        'filesystem' => 'filesystem.json',
    ];

    $metrics = [];
    $disabledMetrics = [];
    foreach ($metricFiles as $metric => $file) {
        if (!metricEnabled($config, $metric)) {
            $disabledMetrics[] = $metric;
            continue;
        }
        $metrics[$metric] = readJsonOrEmpty($stateDir . '/' . $file);
    }

    $collectorDurations = [];
    foreach ($metrics as $metric => $data) {
        if (isset($data['profiling']['duration_ms'])) {
            $collectorDurations[$metric] = (float) $data['profiling']['duration_ms'];
        }
    }

    $sequence = nextPayloadSequence($stateDir, $persistSequence);

    return [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'hostname' => currentHostname(),
        'metrics' => $metrics,
        'meta' => [
            'profiling' => [
                'build_duration_ms' => profilingDurationMs($startedAt),
                'collectors' => $collectorDurations,
            ],
            'disabled_metrics' => $disabledMetrics,
            'agent' => [
                'version' => agentVersion(),
                'build_timestamp' => agentBuildTimestamp(),
            ],
            'sequence' => $sequence,
        ],
    ];
}

function nextPayloadSequence(string $stateDir, bool $persist): int
{
    $sequenceFile = rtrim($stateDir, '/') . '/payload.seq';
    $current = 0;
    if (is_file($sequenceFile)) {
        $current = (int) trim((string)file_get_contents($sequenceFile));
    }
    $next = $current + 1;
    if ($persist) {
        @file_put_contents($sequenceFile, (string)$next);
    }
    return $next;
}
