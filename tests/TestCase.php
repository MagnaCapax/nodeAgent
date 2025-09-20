<?php

declare(strict_types=1);

abstract class TestCase
{
    private array $errors = [];

    abstract public function run(): void;

    protected function assertTrue(bool $condition, string $message = 'Assertion failed'): void
    {
        if (!$condition) {
            $this->errors[] = $message;
        }
    }

    protected function assertSame($expected, $actual, string $message = 'Values differ'): void
    {
        if ($expected !== $actual) {
            $this->errors[] = $message . sprintf(' (expected %s, got %s)', var_export($expected, true), var_export($actual, true));
        }
    }

    protected function assertNotEmpty($value, string $message = 'Value is empty'): void
    {
        if (empty($value)) {
            $this->errors[] = $message;
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
