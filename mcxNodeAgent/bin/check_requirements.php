#!/usr/bin/env php
<?php
// Inspect the host for mcxNodeAgent prerequisites (distribution + tooling).
// Provides package hints without attempting modification.

declare(strict_types=1);

use function McxNodeAgent\buildContext;
use function McxNodeAgent\detectDistribution;
use function McxNodeAgent\evaluateRequirements;
use function McxNodeAgent\renderRequirementSummary;
use function McxNodeAgent\installPackages;
use function McxNodeAgent\logInfo;
use function McxNodeAgent\logWarn;

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/tooling.php';

$context = buildContext();

$options = parseOptions($argv);

$distribution = detectDistribution();
logInfo($context, sprintf('Detected distribution: %s (family: %s)', $distribution['id'], $distribution['family']));

$report = evaluateRequirements($distribution);
outputSummary($context, $report);

if ($options['list_only']) {
    $packages = $report['package_recommendations'] ?? [];
    if (!empty($packages)) {
        echo implode(PHP_EOL, $packages), PHP_EOL;
        exit(1);
    }
    exit(0);
}

if (!empty($report['missing_descriptors']) && $options['install']) {
    logInfo($context, 'Attempting to install missing requirements');
    installPackages($context, $distribution, $report['package_recommendations'], $options['dry_run']);

    // Re-evaluate after attempting installation.
    $report = evaluateRequirements($distribution);
    outputSummary($context, $report);
}

if (!empty($report['missing_descriptors'])) {
    logWarn($context, 'Some requirements remain missing; manual intervention may be required.');
    exit(1);
}

logInfo($context, 'All required tooling is present.');
exit(0);

function parseOptions(array $argv): array
{
    $options = [
        'dry_run' => false,
        'install' => true,
        'list_only' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--no-install') {
            $options['install'] = false;
        } elseif ($arg === '--list') {
            $options['list_only'] = true;
            $options['install'] = false;
        }
    }

    return $options;
}

function outputSummary(array $context, array $report): void
{
    $summaryLines = renderRequirementSummary($report);
    $hasMissing = !empty($report['missing_descriptors']);
    foreach ($summaryLines as $line) {
        if ($hasMissing) {
            logWarn($context, $line);
        } else {
            logInfo($context, $line);
        }
    }
}
