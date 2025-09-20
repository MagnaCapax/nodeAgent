<?php

declare(strict_types=1);

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

use function McxNodeAgent\normalizeBusyThresholds;

final class ConfigTest extends TestCase
{
    public function run(): void
    {
        $thresholds = normalizeBusyThresholds(['load1' => '5', 'skip_probability' => 1.5]);
        $this->assertSame(5.0, $thresholds['load1'], 'load1 should cast to float');
        $this->assertSame(1.0, $thresholds['skip_probability'], 'skip_probability should clamp to 1.0');
    }
}
