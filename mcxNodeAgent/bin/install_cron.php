#!/usr/bin/env php
<?php
// Install or update the cron schedule for mcxNodeAgent.
// Writes an /etc/cron.d style file while offering a dry-run preview for safety.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\logError;
use function McxNodeAgent\detectDistribution;
use function McxNodeAgent\evaluateRequirements;
use function McxNodeAgent\renderRequirementSummary;
use function McxNodeAgent\renderCronTemplate;
use function McxNodeAgent\loadCronTemplate;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/tooling.php';
require_once __DIR__ . '/../lib/cron.php';

$context = buildContext();
$config = $context['config'];

$options = parseArguments($argv);
$cronPath = $options['destination'];
$dryRun = $options['dry_run'];

$phpBinary = $options['php_binary'] ?: PHP_BINARY;
$installRoot = rtrim($options['install_root'] ?: '/opt/mcxNodeAgent', '/');
$collectCronPath = $installRoot . '/cron/collect_metrics.php';
$storageCronPath = $installRoot . '/cron/collect_storage.php';
$submitCronPath = $installRoot . '/cron/send_payload.php';
$templatePath = $installRoot . '/conf/cron.template';

logInfo($context, sprintf('Preparing cron installation at %s', $cronPath));

if (!file_exists($collectCronPath)) {
    logWarn($context, sprintf('Expected collector cron %s missing; verify installation path', $collectCronPath));
}

if (!file_exists($storageCronPath)) {
    logWarn($context, sprintf('Expected storage cron %s missing; verify installation path', $storageCronPath));
}

if (!file_exists($submitCronPath)) {
    logWarn($context, sprintf('Expected submission cron %s missing; verify installation path', $submitCronPath));
}

if (!is_file($templatePath)) {
    logError($context, sprintf('Cron template missing at %s', $templatePath));
    exit(1);
}

$distribution = detectDistribution();
$requirements = evaluateRequirements($distribution);
$summaryLines = renderRequirementSummary($requirements);
$hasMissing = !empty($requirements['missing_descriptors']);
foreach ($summaryLines as $line) {
    if ($hasMissing) {
        logWarn($context, $line);
        continue;
    }
    logInfo($context, $line);
}

try {
    $template = loadCronTemplate($templatePath);
    $cronContent = renderCronTemplate([
        'template' => $template,
        'php' => $phpBinary,
        'commands' => [
            'collect' => $collectCronPath,
            'storage' => $storageCronPath,
            'submit' => $submitCronPath,
        ],
        'intervals' => [
            'core' => $config['cron_core_interval'] ?? 1,
            'storage' => $config['cron_iostat_interval'] ?? 5,
            'submission' => $config['cron_submission_interval'] ?? 5,
        ],
    ]);
} catch (Throwable $throwable) {
    logError($context, 'Unable to build cron content: ' . $throwable->getMessage());
    exit(1);
}

if ($dryRun) {
    logInfo($context, 'Dry-run enabled; printing cron file contents');
    echo $cronContent;
    exit(0);
}

$dir = dirname($cronPath);
if (!is_dir($dir)) {
    logError($context, sprintf('Destination directory %s does not exist', $dir));
    exit(1);
}

if (!is_writable($dir) || (file_exists($cronPath) && !is_writable($cronPath))) {
    logError($context, sprintf('Insufficient permissions to write %s', $cronPath));
    echo $cronContent;
    exit(1);
}

if (file_put_contents($cronPath, $cronContent) === false) {
    logError($context, sprintf('Failed to write cron file %s', $cronPath));
    echo $cronContent;
    exit(1);
}

logInfo($context, sprintf('Cron file installed at %s', $cronPath));

/**
 * Parse CLI arguments for destination, dry-run, and overrides.
 */
function parseArguments(array $argv): array
{
    $destination = getenv('CRON_DEST') ?: '/etc/cron.d/mcx-node-agent';
    $phpBinary = getenv('PHP_BIN') ?: '';
    $installRoot = getenv('INSTALL_ROOT') ?: '';
    $dryRun = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
            continue;
        }
        if (strpos($arg, '--dest=') === 0) {
            $destination = substr($arg, 7);
            continue;
        }
        if (strpos($arg, '--php=') === 0) {
            $phpBinary = substr($arg, 6);
            continue;
        }
        if (strpos($arg, '--root=') === 0) {
            $installRoot = substr($arg, 7);
        }
    }

    return [
        'destination' => $destination,
        'php_binary' => $phpBinary,
        'install_root' => $installRoot,
        'dry_run' => $dryRun,
    ];
}
