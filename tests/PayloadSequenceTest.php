<?php

declare(strict_types=1);

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/../mcxNodeAgent/lib/payload.php';
require_once __DIR__ . '/TestCase.php';

use function McxNodeAgent\buildContext;
use function McxNodeAgent\assemblePayload;

final class PayloadSequenceTest extends TestCase
{
    public function run(): void
    {
        $tmp = sys_get_temp_dir() . '/na-seq-' . uniqid();
        mkdir($tmp, 0777, true);

        $context = buildContext();
        $context['paths']['state'] = $tmp;
        $context['config']['state_dir'] = $tmp;
        $context['config']['metrics'] = ['cpu' => true];

        file_put_contents($tmp . '/cpu.json', json_encode([
            'usage_percent' => 10,
            'profiling' => ['duration_ms' => 1],
        ]));

        $first = assemblePayload($context, true);
        $second = assemblePayload($context, true);
        $this->assertSame(1, $first['meta']['sequence'] ?? 0, 'First sequence should be 1');
        $this->assertSame(2, $second['meta']['sequence'] ?? 0, 'Second sequence should be 2');

        $files = glob($tmp . '/*');
        if (is_array($files)) {
            array_map('unlink', $files);
        }
        @rmdir($tmp);
    }
}
