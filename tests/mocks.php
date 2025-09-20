<?php

function mock_env(string $type): void
{
    if ($type === 'proc') {
        putenv('MCX_MOCK_BASE=' . __DIR__ . '/fixtures');
    }
}
