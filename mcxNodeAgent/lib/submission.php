<?php

declare(strict_types=1);

namespace McxNodeAgent;

function prepareSubmissionEnvelope(array $context, string $json): array
{
    $stateDir = $context['paths']['state'];
    $ip = detectPrimaryIPv4();
    $iface = detectPrimaryInterface();
    $mac = detectMacAddress($iface);
    $passphrase = derivePassphraseFromIdentity($ip, $mac);

    $encryption = encryptPayloadWithGpg($json, $passphrase, $stateDir);

    if ($encryption === null) {
        logWarn($context, 'GPG encryption unavailable; sending payload in plaintext');
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => base64_encode($json)];
        }
        return [
            'version' => '1.0',
            'encrypted' => false,
            'payload' => $decoded,
            'meta' => [
                'profiling' => [
                    'encryption_ms' => 0.0,
                ],
            ],
        ];
    }

    return [
        'version' => '1.0',
        'encrypted' => true,
        'encryption' => [
            'method' => $encryption['method'],
            'key_hint' => 'sha256(ip+mac)',
            'fingerprint' => $encryption['fingerprint'],
        ],
        'ciphertext' => $encryption['ciphertext'],
        'meta' => [
            'profiling' => [
                'encryption_ms' => $encryption['duration_ms'],
            ],
        ],
    ];
}

function sendPayload(string $endpoint, string $payload, array $context, array $options = []): array
{
    if (!shouldSendPayload($context, $payload)) {
        logWarn($context, 'Submission skipped by pre-submit hook');
        return ['status' => 0, 'body' => 'skipped'];
    }

    $retries = $options['retries'] ?? (int)($context['config']['submission_retries'] ?? 3);
    $backoff = $options['backoff'] ?? (int)($context['config']['submission_backoff_base'] ?? 1);
    $debug = $options['debug'] ?? false;
    $compress = $options['compress'] ?? ($context['config']['submission_compress'] ?? false);
    $headers = $options['headers'] ?? [];

    if ($compress && function_exists('gzencode')) {
        $compressed = gzencode($payload, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to gzip payload');
        }
        $payload = $compressed;
        $headers[] = 'Content-Encoding: gzip';
    } elseif ($compress) {
        logWarn($context, 'gzip compression requested but gzencode() unavailable; sending uncompressed');
    }

    return submitWithRetries($endpoint, $payload, $context, [
        'retries' => $retries,
        'backoff' => $backoff,
        'debug' => $debug,
        'headers' => $headers,
    ]);
}

function shouldSendPayload(array $context, string $payloadJson): bool
{
    $hook = dirname(__DIR__) . '/hooks/pre_submit.php';
    if (is_file($hook)) {
        $decoded = json_decode($payloadJson, true);
        $hookResult = include_once $hook;
        if (is_callable($hookResult)) {
            return (bool)$hookResult($decoded, $context);
        }
        if (function_exists('nodeAgent_pre_submit')) {
            return (bool) nodeAgent_pre_submit($decoded, $context);
        }
    }
    return true;
}

function submitWithRetries(string $endpoint, string $payload, array $context, array $options = []): array
{
    $attempt = 1;
    $maxAttempts = max(1, (int)($options['retries'] ?? 3));
    $delay = max(1, (int)($options['backoff'] ?? 1));
    $debug = (bool)($options['debug'] ?? false);
    $headers = $options['headers'] ?? [];
    do {
        try {
            $result = submitViaCurl($endpoint, $payload, [
                'debug' => $debug,
                'headers' => $headers,
            ]);
        } catch (\Throwable $throwable) {
            if ($attempt >= $maxAttempts) {
                recordSubmissionFailure($context, $throwable->getMessage());
                throw $throwable;
            }
            logWarn($context, sprintf('Submission attempt %d failed: %s', $attempt, $throwable->getMessage()));
            sleep($delay);
            $attempt++;
            $delay *= 2;
            continue;
        }

        if ($result['status'] >= 200 && $result['status'] < 300) {
            recordSubmissionSuccess($context);
            return $result;
        }

        if ($attempt >= $maxAttempts) {
            recordSubmissionFailure($context, 'HTTP status ' . $result['status']);
            logError($context, sprintf('Submission failed with HTTP status %d after %d attempts', $result['status'], $attempt));
            throw new \RuntimeException('Submission failed after retries');
        }

        logWarn($context, sprintf('Submission attempt %d returned HTTP %d; retrying', $attempt, $result['status']));
        sleep($delay);
        $attempt++;
        $delay *= 2;
    } while ($attempt <= $maxAttempts);

    throw new \RuntimeException('Submission retries exhausted');
}

function recordSubmissionSuccess(array $context): void
{
    $stateDir = $context['paths']['state'];
    $failureFile = rtrim($stateDir, '/') . '/submit.failures';
    if (is_file($failureFile)) {
        @unlink($failureFile);
    }
}

function recordSubmissionFailure(array $context, string $message): void
{
    $stateDir = $context['paths']['state'];
    $failureFile = rtrim($stateDir, '/') . '/submit.failures';
    $count = 0;
    if (is_file($failureFile)) {
        $count = (int) trim((string)file_get_contents($failureFile));
    }
    $count++;
    @file_put_contents($failureFile, (string)$count);

    $threshold = (int)($context['config']['failure_alert_threshold'] ?? 0);
    if ($threshold > 0 && $count >= $threshold) {
        triggerFailureHook($context, $message, $count);
        @unlink($failureFile);
    }
}

function triggerFailureHook(array $context, string $message, int $count): void
{
    $hook = dirname(__DIR__) . '/hooks/on_failure.php';
    if (!is_file($hook)) {
        return;
    }
    $payload = [
        'count' => $count,
        'message' => $message,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    $hookResult = include_once $hook;
    if (is_callable($hookResult)) {
        $hookResult($payload, $context);
    } elseif (function_exists('nodeAgent_on_failure')) {
        nodeAgent_on_failure($payload, $context);
    }
}

function submitViaCurl(string $endpoint, string $payload, array $options = []): array
{
    $debug = (bool)($options['debug'] ?? false);
    $headers = $options['headers'] ?? [];
    $headers = array_values(array_unique(array_merge(['Content-Type: application/json'], $headers)));
    if (function_exists('curl_init')) {
        $handle = curl_init($endpoint);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialise cURL handle');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException('cURL submission failed: ' . $error);
        }
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($debug) {
            fwrite(STDOUT, "[submit] status=$status body=$body\n");
        }
        return ['status' => $status, 'body' => $body];
    }

    $httpOptions = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload,
            'timeout' => 15,
        ],
    ];
    $context = stream_context_create($httpOptions);
    $body = @file_get_contents($endpoint, false, $context);
    if ($body === false) {
        $error = error_get_last();
        throw new \RuntimeException('Stream submission failed: ' . ($error['message'] ?? 'unknown error'));
    }
    $status = 0;
    $meta = $http_response_header ?? [];
    foreach ($meta as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }
    if ($debug) {
        fwrite(STDOUT, "[submit] status=$status body=$body\n");
    }
    return ['status' => $status, 'body' => $body];
}
