<?php
// Logging utilities for mcxNodeAgent scripts.
// Emits structured lines and mirrors them to stdout for cron visibility.

declare(strict_types=1);

namespace McxNodeAgent;

/**
 * Emit a structured log message.
 */
function logEmit(array $context, string $level, string $message): void
{
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $format = $context['config']['log_format'] ?? 'text';

    rotateLogIfNeeded($context);

    $payload = [
        'timestamp' => $timestamp,
        'level' => strtoupper($level),
        'message' => $message,
    ];

    $fileLine = sprintf('%s [%s] %s', $timestamp, strtoupper($level), $message);
    $consoleLine = $fileLine;

    if ($format === 'json') {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $fileLine = $encoded;
            $consoleLine = $encoded;
        }
    } elseif ($format === 'json_file') {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $fileLine = $encoded;
        }
    }

    $logFile = $context['paths']['log_file'] ?? null;
    if (is_string($logFile) && $logFile !== '') {
        @file_put_contents($logFile, $fileLine . PHP_EOL, FILE_APPEND);
    }

    echo $consoleLine, PHP_EOL;
}

function logInfo(array $context, string $message): void
{
    logEmit($context, 'INFO', $message);
}

function logWarn(array $context, string $message): void
{
    logEmit($context, 'WARN', $message);
}

function logError(array $context, string $message): void
{
    logEmit($context, 'ERROR', $message);
}

function rotateLogIfNeeded(array $context): void
{
    $logFile = $context['paths']['log_file'] ?? null;
    if (!is_string($logFile) || $logFile === '') {
        return;
    }
    $maxBytes = (int)($context['config']['log_rotate_max_bytes'] ?? 0);
    $keep = max(1, (int)($context['config']['log_rotate_keep'] ?? 1));
    if ($maxBytes <= 0 || !is_file($logFile)) {
        return;
    }
    if (filesize($logFile) < $maxBytes) {
        return;
    }
    for ($i = $keep; $i >= 1; $i--) {
        $source = $logFile . '.' . $i;
        $dest = $logFile . '.' . ($i + 1);
        if (is_file($source)) {
            @rename($source, $dest);
        }
    }
    @rename($logFile, $logFile . '.1');
}
