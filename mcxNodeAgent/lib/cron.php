<?php
// Cron templating and orchestration helpers for mcxNodeAgent.
// Provides cron file rendering along with shared runner utilities.

declare(strict_types=1);

namespace McxNodeAgent;

require_once __DIR__ . '/logger.php';

function renderCronTemplate(array $options): string
{
    $template = $options['template'] ?? '';
    if (!is_string($template) || trim($template) === '') {
        throw new \RuntimeException('Cron template is empty');
    }

    $replacements = [
        '##CRON_CORE_INTERVAL##' => (string) max(1, (int)($options['intervals']['core'] ?? 1)),
        '##CRON_IOSTAT_INTERVAL##' => (string) max(1, (int)($options['intervals']['storage'] ?? 5)),
        '##CRON_SUBMIT_INTERVAL##' => (string) max(1, (int)($options['intervals']['submission'] ?? 5)),
        '##CRON_CMD_COLLECT##' => buildCronCommand($options['php'] ?? '/usr/bin/php', $options['commands']['collect'] ?? ''),
        '##CRON_CMD_STORAGE##' => buildCronCommand($options['php'] ?? '/usr/bin/php', $options['commands']['storage'] ?? ''),
        '##CRON_CMD_SUBMIT##' => buildCronCommand($options['php'] ?? '/usr/bin/php', $options['commands']['submit'] ?? ''),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

function buildCronCommand(string $phpBinary, string $script, array $arguments = []): string
{
    $phpBinary = $phpBinary !== '' ? $phpBinary : '/usr/bin/php';
    $parts = [escapeshellcmd($phpBinary)];
    if ($script !== '') {
        $parts[] = escapeshellarg($script);
    }
    foreach ($arguments as $argument) {
        $parts[] = escapeshellarg($argument);
    }
    return implode(' ', $parts);
}

function loadCronTemplate(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new \RuntimeException('Cron template missing at ' . $path);
    }
    return file_get_contents($path) ?: '';
}

/**
 * Parse CLI arguments for --metrics filters, returning null when unused.
 */
function parseCronFilters(array $argv, string $flag = '--metrics='): ?array
{
    foreach ($argv as $argument) {
        if (!str_starts_with($argument, $flag)) {
            continue;
        }
        $raw = substr($argument, strlen($flag));
        $entries = array_filter(array_map('trim', explode(',', $raw)));
        return !empty($entries) ? array_values($entries) : null;
    }

    return null;
}

/**
 * Filter a named script map against the requested metric list.
 */
function selectCronScripts(array $map, ?array $filters): array
{
    if ($filters === null || $filters === []) {
        return $map;
    }

    $selected = [];
    foreach ($filters as $name) {
        if (isset($map[$name])) {
            $selected[$name] = $map[$name];
        }
    }

    return $selected;
}

/**
 * Execute a cron task script and return its exit code (-1 when missing).
 */
function runCronScript(
    array $context,
    string $label,
    string $script,
    array $arguments = [],
    ?string $phpBinary = null,
    bool $treatFailureAsError = false
): int {
    if (!is_file($script) || !is_readable($script)) {
        logWarn($context, sprintf('%s script missing: %s', $label, basename($script)));
        return -1;
    }

    $command = buildCronCommand($phpBinary ?? PHP_BINARY, $script, $arguments);
    $buffer = [];
    $exitCode = 0;
    exec($command, $buffer, $exitCode);

    if ($exitCode !== 0) {
        $logger = $treatFailureAsError ? __NAMESPACE__ . '\\logError' : __NAMESPACE__ . '\\logWarn';
        $logger($context, sprintf('%s returned exit code %d', $label, $exitCode));
    }

    return $exitCode;
}
