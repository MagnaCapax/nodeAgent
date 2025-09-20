<?php

declare(strict_types=1);

// Validate helper behaviours when presented with unusual data.

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/../mcxNodeAgent/lib/logger.php';
require_once __DIR__ . '/TestCase.php';

use function McxNodeAgent\normalizeMetrics;
use function McxNodeAgent\normalizeBusyThresholds;
use function McxNodeAgent\metricEnabled;
use function McxNodeAgent\readJsonOrEmpty;
use function McxNodeAgent\writeJson;
use function McxNodeAgent\profilingDurationMs;
use function McxNodeAgent\shouldThrottleCollection;

final class HelpersEdgeCasesTest extends TestCase
{
    public function run(): void
    {
        $this->assertNormalizeMetricsHandlesUnexpectedKeys();
        $this->assertBusyThresholdClampWithExtremeValues();
        $this->assertMetricEnabledDefaultsToFalse();
        $this->assertJsonHelpersTolerateCorruption();
        $this->assertProfilingDurationNeverNegative();
        $this->assertThrottleHonoursMaintenanceWindow();
    }

    private function assertNormalizeMetricsHandlesUnexpectedKeys(): void
    {
        // Provide extraneous keys and string values; expect defaults to govern only known metrics.
        $normalized = normalizeMetrics([
            'cpu' => '0',
            'unknown' => true,
            'storage_latency' => '1',
        ]);
        $this->assertSame(false, $normalized['cpu'], 'String zero should disable the metric');
        $this->assertSame(true, $normalized['storage_latency'], 'String one should enable the metric');
        $this->assertSame(false, array_key_exists('unknown', $normalized), 'Unknown metrics should be discarded');
    }

    private function assertBusyThresholdClampWithExtremeValues(): void
    {
        // Feed absurd values to confirm clamping and casting logic stands.
        $thresholds = normalizeBusyThresholds([
            'skip_probability' => -5,
            'load1' => '12.5',
            'ping_ms' => 'not-number',
        ]);
        $this->assertSame(0.0, $thresholds['skip_probability'], 'Skip probability should clamp to zero');
        $this->assertSame(12.5, $thresholds['load1'], 'Load threshold should cast to float');
        $this->assertSame(0.0, $thresholds['ping_ms'], 'Non-numeric ping threshold should coerce to zero');
    }

    private function assertMetricEnabledDefaultsToFalse(): void
    {
        // Without explicit configuration the helper should decline unknown metrics.
        $this->assertSame(false, metricEnabled([], 'nonexistent'), 'Missing metric key should be false');
    }

    private function assertJsonHelpersTolerateCorruption(): void
    {
        // Corrupted JSON should not crash and should return an empty structure.
        $tmp = sys_get_temp_dir() . '/na-helper-' . uniqid();
        file_put_contents($tmp, '{broken json');
        $decoded = readJsonOrEmpty($tmp);
        $this->assertSame([], $decoded, 'Invalid JSON should yield an empty array');

        // Now confirm writeJson produces readable output for downstream consumers.
        $payload = ['one' => 1, 'two' => 2];
        writeJson($tmp, $payload);
        $reloaded = readJsonOrEmpty($tmp);
        $this->assertSame($payload, $reloaded, 'Round-trip JSON should match original payload');
        @unlink($tmp);
    }

    private function assertProfilingDurationNeverNegative(): void
    {
        // Simulate nano-scale elapsed time; the helper should still return a non-negative duration.
        $duration = profilingDurationMs(microtime(true));
        $this->assertTrue($duration >= 0.0, 'Profiling duration must never be negative');
    }

    private function assertThrottleHonoursMaintenanceWindow(): void
    {
        // Maintenance mode should force collectors to bail out immediately.
        $context = [
            'config' => ['maintenance_until' => time() + 60, 'busy_thresholds' => []],
            'paths' => ['log_dir' => sys_get_temp_dir(), 'state' => sys_get_temp_dir()],
        ];
        $shouldSkip = shouldThrottleCollection($context, 'cpu');
        $this->assertSame(true, $shouldSkip, 'Maintenance window should short-circuit collectors');
    }
}
