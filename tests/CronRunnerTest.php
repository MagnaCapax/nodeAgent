<?php

declare(strict_types=1);

// Validate cron helper utilities cover filtering and task execution edge cases.

use function McxNodeAgent\parseCronFilters;
use function McxNodeAgent\selectCronScripts;
use function McxNodeAgent\runCronScript;

require_once __DIR__ . '/../mcxNodeAgent/lib/cron.php';
require_once __DIR__ . '/TestCase.php';

final class CronRunnerTest extends TestCase
{
    public function run(): void
    {
        $this->assertParsesFilterFlag();
        $this->assertFiltersScriptMap();
        $this->assertMissingScriptReturnsMinusOne();
        $this->assertSuccessfulScriptRunsCleanly();
    }

    private function assertParsesFilterFlag(): void
    {
        $filters = parseCronFilters(['script.php', '--metrics=cpu, network']);
        $this->assertSame(['cpu', 'network'], $filters ?? [], 'Filter parsing should split and trim values');

        $none = parseCronFilters(['script.php']);
        $this->assertSame(null, $none, 'Missing flag should yield null filters');
    }

    private function assertFiltersScriptMap(): void
    {
        $map = [
            'cpu' => '/tmp/cpu.php',
            'memory' => '/tmp/memory.php',
        ];
        $filtered = selectCronScripts($map, ['memory']);
        $this->assertSame(['memory' => '/tmp/memory.php'], $filtered, 'Filtering should retain keyed scripts only');
    }

    private function assertMissingScriptReturnsMinusOne(): void
    {
        [$context, $logFile] = $this->buildLogContext();
        $exitCode = runCronScript($context, 'Missing tester', '/nonexistent/path.php');
        $this->assertSame(-1, $exitCode, 'Missing scripts should yield -1');
        @unlink($logFile);
    }

    private function assertSuccessfulScriptRunsCleanly(): void
    {
        $scriptPath = sys_get_temp_dir() . '/na-cron-test-' . uniqid() . '.php';
        file_put_contents($scriptPath, "<?php\n// synthetic success script\nexit(0);\n");

        [$context, $logFile] = $this->buildLogContext();
        $exitCode = runCronScript($context, 'Success tester', $scriptPath);
        $this->assertSame(0, $exitCode, 'Runnable scripts should return their process exit code');

        @unlink($scriptPath);
        @unlink($logFile);
    }

    private function buildLogContext(): array
    {
        $logFile = tempnam(sys_get_temp_dir(), 'na-log-');
        $context = [
            'config' => [
                'log_format' => 'text',
                'log_rotate_max_bytes' => 0,
                'log_rotate_keep' => 1,
            ],
            'paths' => [
                'log_file' => $logFile,
                'log_dir' => dirname($logFile),
                'state' => sys_get_temp_dir(),
            ],
        ];

        return [$context, $logFile];
    }
}
