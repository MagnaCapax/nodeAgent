<?php
// System utilities: distro detection, requirement evaluation, and package hints.

declare(strict_types=1);

namespace McxNodeAgent;

/**
 * Detect the current distribution using /etc/os-release metadata.
 */
function detectDistribution(): array
{
    $id = 'unknown';
    $version = '';
    $like = [];

    if (is_readable('/etc/os-release')) {
        $content = file_get_contents('/etc/os-release');
        if (is_string($content)) {
            $lines = preg_split('/\R/', $content);
            foreach ($lines as $line) {
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = strtolower(trim($key));
                $value = trim($value, "\"'\n\r ");
                switch ($key) {
                    case 'id':
                        $id = strtolower($value);
                        break;
                    case 'version_id':
                        $version = strtolower($value);
                        break;
                    case 'id_like':
                        $like = array_filter(array_map('strtolower', preg_split('/\s+/', $value)));
                        break;
                }
            }
        }
    }

    $family = mapToFamily($id, $like);

    return [
        'id' => $id,
        'version' => $version,
        'like' => $like,
        'family' => $family,
    ];
}

/**
 * Map distro identifiers to a family for package recommendations.
 */
function mapToFamily(string $id, array $like): string
{
    $id = strtolower($id);
    $candidates = array_merge([$id], $like);

    foreach ($candidates as $candidate) {
        switch ($candidate) {
            case 'debian':
            case 'ubuntu':
            case 'linuxmint':
            case 'pop':
            case 'elementary':
                return 'debian';
            case 'rhel':
            case 'centos':
            case 'rocky':
            case 'alma':
            case 'fedora':
            case 'oracle':
                return 'rhel';
            case 'arch':
            case 'manjaro':
                return 'arch';
            case 'suse':
            case 'opensuse':
            case 'sled':
            case 'sles':
                return 'suse';
            case 'gentoo':
                return 'gentoo';
        }
    }

    return 'unknown';
}

/**
 * Describe command requirements and package hints per family.
 */
function requirementDescriptors(): array
{
    return [
        [
            'command' => 'iostat',
            'description' => 'Block device statistics (sysstat)',
            'packages' => [
                'debian' => ['sysstat'],
                'rhel' => ['sysstat'],
                'arch' => ['sysstat'],
                'suse' => ['sysstat'],
                'gentoo' => ['sysstat'],
            ],
        ],
        [
            'command' => 'smartctl',
            'description' => 'SMART disk health (smartmontools)',
            'packages' => [
                'debian' => ['smartmontools'],
                'rhel' => ['smartmontools'],
                'arch' => ['smartmontools'],
                'suse' => ['smartmontools'],
                'gentoo' => ['smartmontools'],
            ],
        ],
        [
            'command' => 'nvme',
            'description' => 'NVMe device tooling (nvme-cli)',
            'packages' => [
                'debian' => ['nvme-cli'],
                'rhel' => ['nvme-cli'],
                'arch' => ['nvme-cli'],
                'suse' => ['nvme-cli'],
                'gentoo' => ['nvme-cli'],
            ],
        ],
        [
            'command' => 'ioping',
            'description' => 'Storage latency sampling (ioping)',
            'packages' => [
                'debian' => ['ioping'],
                'rhel' => ['ioping'],
                'arch' => ['ioping'],
                'suse' => ['ioping'],
                'gentoo' => ['ioping'],
            ],
        ],
        [
            'command' => 'ping',
            'description' => 'ICMP probing (iputils)',
            'packages' => [
                'debian' => ['iputils-ping'],
                'rhel' => ['iputils'],
                'arch' => ['iputils'],
                'suse' => ['iputils'],
                'gentoo' => ['iputils'],
            ],
        ],
        [
            'command' => 'curl',
            'description' => 'HTTP submission utility',
            'packages' => [
                'debian' => ['curl'],
                'rhel' => ['curl'],
                'arch' => ['curl'],
                'suse' => ['curl'],
                'gentoo' => ['curl'],
            ],
        ],
        [
            'command' => 'gpg',
            'description' => 'Payload encryption (gnupg)',
            'packages' => [
                'debian' => ['gnupg'],
                'rhel' => ['gnupg2'],
                'arch' => ['gnupg'],
                'suse' => ['gpg2'],
                'gentoo' => ['app-crypt/gnupg'],
            ],
        ],
    ];
}

/**
 * Evaluate missing commands and suggested package installs.
 */
function evaluateRequirements(array $distribution): array
{
    $family = $distribution['family'];
    $missing = [];
    $packages = [];

    foreach (requirementDescriptors() as $descriptor) {
        $command = $descriptor['command'];
        if (commandAvailable($command)) {
            continue;
        }
        $missing[] = $descriptor;
        $packages = array_merge($packages, $descriptor['packages'][$family] ?? []);
    }

    return [
        'missing_commands' => array_map(static fn(array $item): string => $item['command'], $missing),
        'missing_descriptors' => $missing,
        'package_recommendations' => array_values(array_unique($packages)),
        'distribution' => $distribution,
    ];
}

/**
 * Format requirement findings into readable lines.
 */
function renderRequirementSummary(array $report): array
{
    $lines = [];
    if (!empty($report['missing_descriptors'])) {
        foreach ($report['missing_descriptors'] as $descriptor) {
            $lines[] = sprintf('Missing command %s (%s)', $descriptor['command'], $descriptor['description']);
        }
    }
    if (!empty($report['package_recommendations'])) {
        $lines[] = sprintf('Consider installing packages: %s', implode(', ', $report['package_recommendations']));
    }
    if (empty($lines)) {
        $lines[] = 'All required tooling detected.';
    }
    return $lines;
}
