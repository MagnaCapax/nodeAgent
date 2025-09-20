<?php
// Invoked when submission failures exceed the configured threshold.
return static function (array $payload, array $context): void {
    // Example: write to syslog or send webhook.
    error_log('nodeAgent submission failure x' . $payload['count'] . ': ' . $payload['message']);
};
