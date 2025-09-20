<?php

declare(strict_types=1);

use function McxNodeAgent\renderCronTemplate;

require_once __DIR__ . '/../mcxNodeAgent/lib/cron.php';
require_once __DIR__ . '/TestCase.php';

final class CronTemplateTest extends TestCase
{
    public function run(): void
    {
        $template = "*/##CRON_CORE_INTERVAL## * * * * root ##CRON_CMD_COLLECT##\n";
        $rendered = renderCronTemplate([
            'template' => $template,
            'php' => '/usr/bin/php',
            'commands' => [
                'collect' => '/opt/mcxNodeAgent/cron/collect_metrics.php',
                'storage' => '/opt/mcxNodeAgent/cron/collect_storage.php',
                'submit' => '/opt/mcxNodeAgent/cron/send_payload.php',
            ],
            'intervals' => [
                'core' => 3,
                'storage' => 5,
                'submission' => 7,
            ],
        ]);

        $this->assertTrue(strpos($rendered, '*/3 * * * * root /usr/bin/php') === 0, 'Cron template should replace interval and command');
    }
}
