#!/usr/bin/env php
<?php
// Provision mcxNodeAgent into the target directory, ensure requirements, and install cron jobs.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;
use function McxNodeAgent\logError;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';

$options = parseOptions($argv);
$context = buildContext();

$sourceRoot = realpath(__DIR__ . '/..');
if ($sourceRoot === false) {
    logError($context, 'Unable to determine source directory');
    exit(1);
}

$targetRoot = rtrim($options['target'], '/');
$stateDir = $context['config']['state_dir'];
$logDir = $context['config']['log_dir'];


if ($options['mode'] === 'requirements') {
    runRequirements($context, $targetRoot, $options);
    exit(0);
}

if ($options['mode'] === 'cron') {
    runCronSetup($context, $targetRoot, $options);
    exit(0);
}

logInfo($context, sprintf('Preparing to install mcxNodeAgent into %s', $targetRoot));

if ($options['dry_run']) {
    logInfo($context, '[dry-run] No changes will be made');
}

if (!ensureDirectory($options, $targetRoot)) {
    exit(1);
}

if (!copyTree($options, $sourceRoot, $targetRoot)) {
    logError($context, 'Failed to stage mcxNodeAgent files');
    exit(1);
}

if (!ensureDirectory($options, $stateDir)) {
    exit(1);
}
if (!ensureDirectory($options, $logDir)) {
    exit(1);
}

if (!$options['skip_requirements']) {
    runRequirements($context, $targetRoot, $options);
}

if (!$options['skip_cron']) {
    runCronSetup($context, $targetRoot, $options);
}

logInfo($context, 'Installation complete. Review logs above for any warnings.');
exit(0);

function parseOptions(array $argv): array
{
    $options = [
        'target' => '/opt/mcxNodeAgent',
        'dry_run' => false,
        'skip_requirements' => false,
        'skip_cron' => false,
        'mode' => 'full',
        'list_packages' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--skip-requirements') {
            $options['skip_requirements'] = true;
        } elseif ($arg === '--skip-cron') {
            $options['skip_cron'] = true;
        } elseif ($arg === '--only-requirements') {
            $options['mode'] = 'requirements';
        } elseif ($arg === '--only-cron') {
            $options['mode'] = 'cron';
        } elseif ($arg === '--list-packages') {
            $options['mode'] = 'requirements';
            $options['list_packages'] = true;
        } elseif (strpos($arg, '--target=') === 0) {
            $options['target'] = substr($arg, 9);
        }
    }

    return $options;
}

function runRequirements(array $context, string $targetRoot, array $options): void
{
    $args = [];
    if ($options['dry_run']) {
        $args[] = '--dry-run';
    }
    if ($options['list_packages']) {
        $args[] = '--list';
    }
    $cmd = buildCommand($targetRoot . '/bin/check_requirements.php', $args);
    if ($options['dry_run']) {
        logInfo($context, '[dry-run] ' . $cmd);
        return;
    }
    if ($options['list_packages']) {
        runCommand($context, $cmd, 'Listing required packages');
        exit(0);
    }
    runCommand($context, $cmd, 'Checking tool requirements');
}

function runCronSetup(array $context, string $targetRoot, array $options): void
{
    $args = [];
    if ($options['dry_run']) {
        $args[] = '--dry-run';
    }
    $cmd = buildCommand($targetRoot . '/bin/install_cron.php', $args);
    if ($options['dry_run']) {
        logInfo($context, '[dry-run] ' . $cmd);
        exec($cmd, $output, $code);
        foreach ($output as $line) {
            logInfo($context, '[cmd] ' . $line);
        }
        if ($options['mode'] === 'cron') {
            exit($code);
        }
        return;
    }
    runCommand($context, $cmd, 'Installing cron schedule');
}

function ensureDirectory(array $options, string $path): bool
{
    if ($options['dry_run']) {
        printf("[dry-run] would ensure directory %s\n", $path);
        return true;
    }
    if (is_dir($path)) {
        return true;
    }
    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        printf("[error] unable to create directory %s\n", $path);
        return false;
    }
    return true;
}

function copyTree(array $options, string $source, string $destination): bool
{
    if ($options['dry_run']) {
        printf("[dry-run] would copy %s to %s\n", $source, $destination);
        return true;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                printf("[error] unable to create directory %s\n", $targetPath);
                return false;
            }
        } else {
            if (!copy($item->getPathname(), $targetPath)) {
                printf("[error] unable to copy %s\n", $item->getPathname());
                return false;
            }
        }
    }

    return true;
}

function buildCommand(string $script, array $arguments = []): string
{
    $parts = ['php', escapeshellarg($script)];
    foreach ($arguments as $argument) {
        $parts[] = $argument;
    }
    return implode(' ', $parts);
}

function runCommand(array $context, string $command, string $label): void
{
    logInfo($context, sprintf('%s: %s', $label, $command));
    exec($command, $output, $code);
    foreach ($output as $line) {
        logInfo($context, '[cmd] ' . $line);
    }
    if ($code !== 0) {
        logWarn($context, sprintf('%s exited with code %d', $label, $code));
    }
}
