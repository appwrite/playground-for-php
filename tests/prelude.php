<?php

/**
 * A very small test harness, so the playground keeps its single dependency.
 */

declare(strict_types=1);

$GLOBALS['results'] = ['passed' => 0, 'failed' => 0];

function test(string $name, callable $case): void
{
    try {
        $case();
        $GLOBALS['results']['passed']++;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $GLOBALS['results']['failed']++;
        echo "  FAIL {$name}\n       " . \str_replace("\n", "\n       ", $e->getMessage()) . "\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = 'Unexpected value'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(\sprintf(
            "%s\nexpected: %s\nactual:   %s",
            $message,
            \var_export($expected, true),
            \var_export($actual, true)
        ));
    }
}

function assertTrue(bool $condition, string $message = 'Expected condition to hold'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertInstanceOf(string $expected, mixed $actual, string $message = 'Unexpected type'): void
{
    if (!$actual instanceof $expected) {
        throw new RuntimeException(\sprintf(
            '%s: expected %s, got %s',
            $message,
            $expected,
            \get_debug_type($actual)
        ));
    }
}

/**
 * Print the summary and return the exit code the test script should use.
 */
function results(): int
{
    $passed = $GLOBALS['results']['passed'];
    $failed = $GLOBALS['results']['failed'];

    echo "\n{$passed} passed, {$failed} failed\n";

    return $failed === 0 ? 0 : 1;
}
