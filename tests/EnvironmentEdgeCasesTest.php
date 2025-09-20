<?php

declare(strict_types=1);

// Stress-test environment helpers with oddball configurations.

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

use function McxNodeAgent\resolvePingTargets;
use function McxNodeAgent\determineCollectorEndpoint;
use function McxNodeAgent\derivePassphraseFromIdentity;

final class EnvironmentEdgeCasesTest extends TestCase
{
    public function run(): void
    {
        $this->assertResolveTargetsFiltersNamelessEntries();
        $this->assertCollectorEndpointAlwaysHasTrailingSlash();
        $this->assertPassphraseStableForNullIdentity();
    }

    private function assertResolveTargetsFiltersNamelessEntries(): void
    {
        // Provide malformed targets where the name is missing or blank.
        $config = [
            'ping_targets' => [
                ' :1.1.1.1',
                ['name' => '', 'target' => '5.5.5.5'],
                'valid:8.8.8.8',
            ],
        ];
        $targets = resolvePingTargets($config);

        $names = array_column($targets, 'name');
        $this->assertSame(false, in_array('', $names, true), 'Entries without names should be discarded');
        $this->assertSame(true, in_array('valid', $names, true), 'Valid target should remain after sanitisation');
    }

    private function assertCollectorEndpointAlwaysHasTrailingSlash(): void
    {
        // When caller omits leading or trailing slashes, helper should normalise it.
        $endpoint = determineCollectorEndpoint([
            'collector_port' => 9000,
            'collector_path' => 'api',
        ]);
        $this->assertTrue(str_ends_with($endpoint, '/'), 'Collector endpoint should always end with a slash');
        $this->assertTrue(str_contains($endpoint, ':9000'), 'Custom port should appear in endpoint');
    }

    private function assertPassphraseStableForNullIdentity(): void
    {
        // Null identity should still produce a deterministic fallback hash.
        $withoutIdentity = derivePassphraseFromIdentity(null, null);
        $withIdentity = derivePassphraseFromIdentity('0.0.0.0', '00:00:00:00:00:00');
        $this->assertSame($withoutIdentity, $withIdentity, 'Null identity should map to known default hash');
        $this->assertSame(64, strlen($withoutIdentity), 'Hash length should remain SHA-256');
    }
}
