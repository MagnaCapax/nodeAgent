<?php

declare(strict_types=1);

use function McxNodeAgent\mapToFamily;
use function McxNodeAgent\derivePassphraseFromIdentity;

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/../mcxNodeAgent/lib/system.php';
require_once __DIR__ . '/TestCase.php';

final class SystemFamilyTest extends TestCase
{
    public function run(): void
    {
        $this->assertSame('debian', mapToFamily('ubuntu', []), 'Ubuntu should map to debian family');
        $this->assertSame('rhel', mapToFamily('rocky', []), 'Rocky should map to rhel family');
        $this->assertSame('unknown', mapToFamily('plan9', []), 'Unknown distro should map to unknown');

        $passphrase = derivePassphraseFromIdentity('192.168.1.10', 'aa:bb:cc:dd:ee:ff');
        $this->assertNotEmpty($passphrase, 'Passphrase should not be empty');
        $this->assertSame(64, strlen($passphrase), 'Passphrase should be SHA-256 hex');
    }
}
