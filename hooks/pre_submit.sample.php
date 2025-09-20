<?php
// Return true to allow submission, false to skip.
return static function (array $payload, array $context): bool {
    // Example: drop submissions when hostname matches a blocklist.
    if (($payload['hostname'] ?? '') === 'sensitive-host') {
        return false;
    }
    return true;
};
