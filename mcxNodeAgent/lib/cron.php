<?php
// Cron templating helpers for mcxNodeAgent.

declare(strict_types=1);

namespace McxNodeAgent;

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
