<?php

/**
 * The little bit of test plumbing `smoke.php` and `verification.php` share.
 */

declare(strict_types=1);

require __DIR__ . '/stub.php';

/** @var list<string> $failures */
$failures = [];

/**
 * Record a failure unless `$ok` holds.
 */
function check(bool $ok, string $what): void
{
    global $failures;

    if (!$ok) {
        $failures[] = $what;
    }
}

/**
 * Compare two values, reporting what differed.
 */
function checkSame(mixed $expected, mixed $actual, string $what): void
{
    global $failures;

    if ($expected !== $actual) {
        $failures[] = $what . "\n  expected: " . \json_encode($expected) . "\n  actual:   " . \json_encode($actual);
    }
}

/**
 * Print what failed and exit with a status that says so.
 */
function report(string $name): never
{
    global $failures;

    if ($failures === []) {
        echo $name . ": OK\n";
        exit(0);
    }

    \fwrite(STDERR, $name . ": " . \count($failures) . " failure(s)\n");

    foreach ($failures as $failure) {
        \fwrite(STDERR, '  - ' . $failure . "\n");
    }

    exit(1);
}

/**
 * Run the whole playground against `$stub` and hand back what it printed.
 *
 * @param array<string, string> $env extra environment for the run
 */
function runPlayground(Stub $stub, array $env = []): string
{
    $command = \array_map(
        \escapeshellarg(...),
        [PHP_BINARY, __DIR__ . '/prelude.php']
    );

    $environment = ['PLAYGROUND_ENDPOINT' => $stub->endpoint] + $env;

    foreach ($environment as $name => $value) {
        \putenv($name . '=' . $value);
    }

    $output = \shell_exec(\implode(' ', $command) . ' 2>&1');

    foreach (\array_keys($environment) as $name) {
        \putenv((string) $name);
    }

    return \is_string($output) ? $output : '';
}

/**
 * The `call` values the playground printed, in the order it printed them.
 *
 * @return list<string>
 */
function playgroundCalls(string $output): array
{
    \preg_match_all('/\[call\] => (\S+)/', $output, $matches);

    return $matches[1];
}
