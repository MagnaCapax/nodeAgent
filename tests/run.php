#!/usr/bin/env php
<?php

declare(strict_types=1);

$testFiles = glob(__DIR__ . '/*Test.php');
foreach ($testFiles as $file) {
    require_once $file;
}

$testClasses = array_filter(
    get_declared_classes(),
    static fn(string $class): bool => is_subclass_of($class, TestCase::class)
);

$total = 0;
$failures = 0;

foreach ($testClasses as $class) {
    /** @var TestCase $test */
    $test = new $class();
    $test->run();
    $errors = $test->getErrors();
    $total++;
    if (!empty($errors)) {
        $failures++;
        fwrite(STDERR, sprintf("[FAIL] %s\n", $class));
        foreach ($errors as $error) {
            fwrite(STDERR, "  - $error\n");
        }
    } else {
        fwrite(STDOUT, sprintf("[PASS] %s\n", $class));
    }
}

fwrite(STDOUT, sprintf("Executed %d test case(s); %d failure(s).\n", $total, $failures));
exit($failures === 0 ? 0 : 1);
