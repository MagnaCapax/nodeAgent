<?php

declare(strict_types=1);

use function McxNodeAgent\resolvePingTargets;

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

final class ResolvePingTargetsTest extends TestCase
{
    public function run(): void
    {
        $config = ['ping_targets' => 'alpha:1.1.1.1,beta:2.2.2.2'];
        $targets = resolvePingTargets($config);
        $this->assertSame(2, count($targets), 'Should parse two targets');
        $this->assertSame('alpha', $targets[0]['name'] ?? null, 'First target name mismatch');

        $configArray = ['ping_targets' => [
            ['name' => 'gw', 'target' => 'auto'],
            ['name' => 'ext', 'target' => '8.8.8.8'],
        ]];
        $targetsArray = resolvePingTargets($configArray);
        $this->assertSame(2, count($targetsArray), 'Array format should yield two targets');
        $this->assertSame('ext', $targetsArray[1]['name'] ?? null, 'Second target name mismatch');
    }
}
