<?php
// Tooling helpers: requirement evaluation, package installation, and command guards.

declare(strict_types=1);

namespace McxNodeAgent;

use RuntimeException;

/**
 * Ensure the specified command exists, logging a warning when it does not.
 */
function ensureCommand(array $context, string $command, string $description = ''): bool
{
    if (commandAvailable($command)) {
        return true;
    }

    $label = $description !== '' ? $description : $command;
    logWarn($context, sprintf('%s unavailable on this host', $label));
    return false;
}

/**
 * Attempt to install missing packages based on the distro family. Returns status details.
 */
function installPackages(array $context, array $distribution, array $packages, bool $dryRun = false): array
{
    $packages = array_values(array_unique(array_filter($packages)));
    if (empty($packages)) {
        return ['attempted' => [], 'installed' => [], 'failed' => []];
    }

    $family = $distribution['family'];
    if ($family === 'unknown') {
        logWarn($context, 'Unable to determine package manager for this distribution');
        return ['attempted' => $packages, 'installed' => [], 'failed' => $packages];
    }

    $managerCommand = buildPackageManagerCommand($family, $packages);
    if ($managerCommand === null) {
        logWarn($context, 'No supported package manager found for this distribution');
        return ['attempted' => $packages, 'installed' => [], 'failed' => $packages];
    }

    logInfo($context, sprintf('Preparing to install packages (%s): %s', $family, implode(', ', $packages)));

    if ($dryRun) {
        logInfo($context, 'Dry-run mode; skipping actual installation');
        return ['attempted' => $packages, 'installed' => [], 'failed' => []];
    }

    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        logWarn($context, 'Package installation requires root privileges; rerun as root or with sudo');
        return ['attempted' => $packages, 'installed' => [], 'failed' => $packages];
    }

    $output = [];
    $exitCode = 0;
    exec($managerCommand . ' 2>&1', $output, $exitCode);
    foreach ($output as $line) {
        logInfo($context, '[pkg] ' . $line);
    }

    if ($exitCode !== 0) {
        logWarn($context, sprintf('Package installation command failed with exit code %d', $exitCode));
        return ['attempted' => $packages, 'installed' => [], 'failed' => $packages];
    }

    logInfo($context, 'Package installation completed successfully');
    return ['attempted' => $packages, 'installed' => $packages, 'failed' => []];
}

/**
 * Build distro-specific package manager command.
 */
function buildPackageManagerCommand(string $family, array $packages): ?string
{
    $pkgList = implode(' ', array_map('escapeshellarg', $packages));
    switch ($family) {
        case 'debian':
            $installer = commandAvailable('apt-get') ? 'apt-get' : 'apt';
            return sprintf('%s install -y %s', $installer, $pkgList);
        case 'rhel':
            if (commandAvailable('dnf')) {
                return sprintf('dnf install -y %s', $pkgList);
            }
            if (commandAvailable('yum')) {
                return sprintf('yum install -y %s', $pkgList);
            }
            return null;
        case 'arch':
            return sprintf('pacman -Sy --noconfirm --needed %s', $pkgList);
        case 'suse':
            return sprintf('zypper --non-interactive install --no-recommends %s', $pkgList);
        case 'gentoo':
            return sprintf('emerge --ask=n %s', $pkgList);
        default:
            return null;
    }
}
