<?php

declare(strict_types=1);

// Inspect submission helpers when encryption is unavailable.

require_once __DIR__ . '/../mcxNodeAgent/lib/bootstrap.php';
require_once __DIR__ . '/../mcxNodeAgent/lib/logger.php';
require_once __DIR__ . '/../mcxNodeAgent/lib/submission.php';
require_once __DIR__ . '/TestCase.php';

use function McxNodeAgent\prepareSubmissionEnvelope;

final class SubmissionFallbackTest extends TestCase
{
    public function run(): void
    {
        $this->assertPlaintextEnvelopeWhenWorkingDirInvalid();
    }

    private function assertPlaintextEnvelopeWhenWorkingDirInvalid(): void
    {
        // Use a file path for state dir so encryptPayloadWithGpg cannot create temp files.
        $fakeState = tempnam(sys_get_temp_dir(), 'na-state-');
        $context = [
            'paths' => ['state' => $fakeState, 'log_dir' => sys_get_temp_dir()],
            'config' => [],
        ];
        $payload = json_encode(['sample' => 'data']);
        $envelope = prepareSubmissionEnvelope($context, $payload);
        $this->assertSame(false, $envelope['encrypted'], 'Broken working dir should force plaintext submission');
        $this->assertSame('data', $envelope['payload']['sample'] ?? null, 'Payload should survive intact');
        $this->assertSame(0.0, $envelope['meta']['profiling']['encryption_ms'] ?? null, 'Fallback path should report zero encryption time');
        @unlink($fakeState);
    }
}
