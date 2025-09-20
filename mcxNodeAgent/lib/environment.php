<?php
// Environment discovery helpers: networking, host identity, and endpoints.

declare(strict_types=1);

namespace McxNodeAgent;

require_once __DIR__ . '/constants.php';

/**
 * Convert routing entries into the node's default gateway IPv4 address.
 */
function detectDefaultGateway(): ?string
{
    $lines = @file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return null;
    }
    foreach ($lines as $index => $line) {
        if ($index === 0) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 8) {
            continue;
        }
        $destination = $parts[1] ?? '';
        $flags = (int) hexdec($parts[3] ?? '0');
        if ($destination !== '00000000' || ($flags & 0x2) !== 0x2) {
            continue;
        }
        $gatewayHex = $parts[2] ?? '';
        if ($gatewayHex === '') {
            continue;
        }
        $octets = array_reverse(str_split($gatewayHex, 2));
        $converted = array_map(static fn(string $octet): string => (string) hexdec($octet), $octets);
        return implode('.', $converted);
    }

    return null;
}

/**
 * Expand ping target configuration into a uniform array structure.
 */
function resolvePingTargets(array $config): array
{
    $targets = [];
    $raw = $config['ping_targets'] ?? '';
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return $targets;
        }
        $entries = array_map('trim', explode(',', $raw));
    } elseif (is_array($raw)) {
        $entries = $raw;
    } else {
        return $targets;
    }

    $defaultGateway = null;
    foreach ($entries as $entry) {
        if (is_array($entry)) {
            $name = trim((string) ($entry['name'] ?? ''));
            $target = trim((string) ($entry['target'] ?? ''));
        } else {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            [$name, $target] = array_pad(explode(':', $entry, 2), 2, '');
            $name = trim($name);
            $target = trim($target);
        }

        if ($name === '') {
            continue;
        }

        if ($target === 'auto' || $target === '') {
            $defaultGateway ??= detectDefaultGateway();
            if ($defaultGateway === null) {
                continue;
            }
            $target = $defaultGateway;
        }

        $targets[] = ['name' => $name, 'target' => $target];
    }

    return $targets;
}

/**
 * Provide a best-effort hostname while tolerating system quirks.
 */
function currentHostname(): string
{
    $hostname = gethostname();
    if (is_string($hostname) && $hostname !== '') {
        return $hostname;
    }
    $fallback = trim((string) shell_exec('hostname -f 2>/dev/null'));
    return $fallback !== '' ? $fallback : 'unknown-host';
}

/**
 * Derive the collector endpoint by probing the node's primary IPv4 /24.
 */
function determineCollectorEndpoint(array $config): string
{
    $port = (int) ($config['collector_port'] ?? DEFAULT_COLLECTOR_PORT);
    $path = (string) ($config['collector_path'] ?? DEFAULT_COLLECTOR_PATH);
    if ($path === '') {
        $path = DEFAULT_COLLECTOR_PATH;
    }
    $path = '/' . ltrim($path, '/');
    if (!str_ends_with($path, '/')) {
        $path .= '/';
    }

    $baseIp = detectPrimaryIPv4();
    if ($baseIp === null) {
        return sprintf('http://192.0.2.2:%d%s', $port, $path);
    }
    $segments = explode('.', $baseIp);
    if (count($segments) !== 4) {
        return sprintf('http://192.0.2.2:%d%s', $port, $path);
    }
    $segments[3] = '2';
    $collectorIp = implode('.', $segments);
    return sprintf('http://%s:%d%s', $collectorIp, $port, $path);
}

/**
 * Detect the primary IPv4 used for outbound connectivity via routing queries.
 */
function detectPrimaryIPv4(): ?string
{
    $candidate = shell_exec('ip route get 1 2>/dev/null');
    if (is_string($candidate) && $candidate !== '') {
        if (preg_match('/src\s+(\d+\.\d+\.\d+\.\d+)/', $candidate, $matches)) {
            return $matches[1];
        }
    }
    $candidate = shell_exec('ip route get 8.8.8.8 2>/dev/null');
    if (is_string($candidate) && $candidate !== '') {
        if (preg_match('/src\s+(\d+\.\d+\.\d+\.\d+)/', $candidate, $matches)) {
            return $matches[1];
        }
    }
    $candidate = @file_get_contents('/proc/net/route');
    if (is_string($candidate)) {
        $lines = preg_split('/\R/', trim($candidate));
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) > 7 && $parts[1] === '00000000') {
                $iface = $parts[0];
                $output = shell_exec('ip -4 addr show ' . escapeshellarg($iface) . ' | awk "/inet / {print \\$2}" | cut -d/ -f1 2>/dev/null');
                if (is_string($output)) {
                    $ip = trim($output);
                    if ($ip !== '') {
                        return $ip;
                    }
                }
            }
        }
    }
    return null;
}

/**
 * Identify the interface associated with the default outbound route.
 */
function detectPrimaryInterface(): ?string
{
    $candidate = shell_exec('ip route get 1 2>/dev/null');
    if (is_string($candidate) && preg_match('/dev\s+(\S+)/', $candidate, $matches)) {
        return $matches[1];
    }
    $candidate = shell_exec('ip route show default 2>/dev/null');
    if (is_string($candidate) && preg_match('/dev\s+(\S+)/', $candidate, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Read the MAC address for a given network interface when available.
 */
function detectMacAddress(?string $interface): ?string
{
    if ($interface === null || $interface === '') {
        return null;
    }
    $path = '/sys/class/net/' . $interface . '/address';
    if (!is_readable($path)) {
        return null;
    }
    $mac = trim((string) file_get_contents($path));
    return $mac !== '' ? strtolower($mac) : null;
}

/**
 * Produce a deterministic passphrase based on IP and MAC identity.
 */
function derivePassphraseFromIdentity(?string $ip, ?string $mac): string
{
    $ipPart = $ip ?? '0.0.0.0';
    $macPart = $mac ?? '00:00:00:00:00:00';
    return hash('sha256', strtolower($ipPart . '|' . $macPart));
}
