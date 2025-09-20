<?php

declare(strict_types=1);

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/../mcxNodeAgent/lib/payload.php';
require_once __DIR__ . '/TestCase.php';

use function McxNodeAgent\buildContext;
use function McxNodeAgent\assemblePayload;

final class PayloadRawTest extends TestCase
{
    public function run(): void
    {
        $tmp = sys_get_temp_dir() . '/na-test-' . uniqid();
        mkdir($tmp, 0777, true);
        file_put_contents($tmp . '/cpu.json', json_encode([
            'usage_percent' => 10,
            'profiling' => ['duration_ms' => 1],
            'raw_counters' => [
                'before' => ['total' => 100, 'idle' => 80],
                'after' => ['total' => 200, 'idle' => 150],
            ],
        ]));

        $context = buildContext();
        $context['paths']['state'] = $tmp;
        $context['config']['state_dir'] = $tmp;

        $payload = assemblePayload($context, false);
        $this->assertSame(100, $payload['metrics']['cpu']['raw_counters']['before']['total'] ?? null, 'Raw counters should be preserved');

        $files = glob($tmp . '/*.json');
        if (is_array($files)) {
            array_map('unlink', $files);
        }
        @rmdir($tmp);
    }
}
