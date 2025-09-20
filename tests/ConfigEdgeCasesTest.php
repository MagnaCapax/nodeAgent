<?php

declare(strict_types=1);

// Exercising configuration loader against malformed inputs and overrides.

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

use function McxNodeAgent\loadConfig;

final class ConfigEdgeCasesTest extends TestCase
{
    public function run(): void
    {
        $this->assertInvalidJsonFallsBack();
        $this->assertEnvJsonOverridesMetrics();
        $this->assertNonNumericEnvIgnored();
    }

    private function assertInvalidJsonFallsBack(): void
    {
        // Write intentionally broken JSON to confirm loadConfig keeps defaults.
        $tmp = sys_get_temp_dir() . '/na-config-' . uniqid();
        file_put_contents($tmp, '{ invalid json ');
        $original = getenv('CONFIG_FILE');
        putenv('CONFIG_FILE=' . $tmp);

        $config = loadConfig();
        $this->assertSame(8080, $config['collector_port'] ?? 0, 'Invalid JSON should not alter defaults');

        // Cleanup and restore environment for the remaining assertions.
        if ($original === false) {
            putenv('CONFIG_FILE');
        } else {
            putenv('CONFIG_FILE=' . $original);
        }
        @unlink($tmp);
    }

    private function assertEnvJsonOverridesMetrics(): void
    {
        // Ensure metrics JSON delivered via environment toggles specific collectors.
        $original = getenv('MCXNA_METRICS');
        putenv('MCXNA_METRICS={"network":false,"cpu":true}');
        $config = loadConfig();
        $this->assertSame(false, $config['metrics']['network'] ?? null, 'Env override should disable network metric');
        $this->assertSame(true, $config['metrics']['cpu'] ?? null, 'CPU metric should remain enabled');

        if ($original === false) {
            putenv('MCXNA_METRICS');
        } else {
            putenv('MCXNA_METRICS=' . $original);
        }
    }

    private function assertNonNumericEnvIgnored(): void
    {
        // Non-numeric submission retry counts should be ignored rather than crashing.
        $original = getenv('MCXNA_SUBMISSION_RETRIES');
        putenv('MCXNA_SUBMISSION_RETRIES=not-a-number');
        $config = loadConfig();
        $this->assertSame(3, $config['submission_retries'] ?? 0, 'Invalid retry value should keep default');

        if ($original === false) {
            putenv('MCXNA_SUBMISSION_RETRIES');
        } else {
            putenv('MCXNA_SUBMISSION_RETRIES=' . $original);
        }
    }
}
