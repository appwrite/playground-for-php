<?php

/**
 * Checks the client half of what appwrite/appwrite#15510 (CLOUD-3RCB) leaves
 * behind for callers of the routes that queue work.
 *
 * Queueing an async function run is two server-side steps: the execution row is
 * written, and then the job is published to the message queue the workers read.
 * The upstream fix is entirely inside the server's NATS client — a pooled
 * connection the broker had closed while it sat idle was written into before
 * anything read it, so the first publish after a broker rolling restart threw —
 * and it changes no API the SDK exposes. What reaches a caller is the failure
 * it used to cause, and still can in the narrower window upstream documents:
 * `createExecution(async: true)` answers `500 general_unknown`, which says
 * nothing about whether the execution row was written first.
 *
 * That ambiguity is the whole contract. Retrying blindly queues a second run of
 * the function when the row did get written. `createExecution()` takes no
 * caller-chosen execution ID, so `createAsyncExecution()` in `playground.php`
 * tags the attempt in the execution's path, which the server stores as
 * `requestPath`, and asks `listExecutions()` what was recorded before deciding.
 *
 * These tests run the playground against `tests/stub-server.php` told to fail
 * one async execution, once with the row written before the failure and once
 * without, and check both halves:
 *
 * - nothing was recorded: the attempt is retried, carrying the same tag, and
 *   the run is queued;
 * - something was recorded: the attempt is *not* retried, so the function is
 *   not run twice, and the caller is handed the execution that was left behind.
 *
 * It cannot prove the server no longer writes into a closed connection — that
 * is what the tests in appwrite/appwrite assert. It proves the playground
 * resolves the ambiguity that failure leaves instead of doubling the work.
 *
 * Usage: php tests/queue-restart.php
 */

declare(strict_types=1);

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require __DIR__ . '/stub.php';

$failures = [];

function check(string $what, bool $ok): void
{
    global $failures;

    if ($ok) {
        echo "  ok    {$what}\n";

        return;
    }

    echo "  FAIL  {$what}\n";
    $failures[] = $what;
}

/**
 * Run the whole playground against a stub, and hand back what it printed.
 */
function runPlayground(Stub $stub): string
{
    $root = \dirname(__DIR__);
    $output = \tmpfile();

    if ($output === false) {
        throw new RuntimeException('Could not capture the playground output');
    }

    $playground = \proc_open(
        [PHP_BINARY, $root . '/tests/prelude.php'],
        [1 => $output, 2 => $output],
        $pipes,
        $root,
        ['PLAYGROUND_ENDPOINT' => $stub->endpoint] + \getenv()
    );

    if (!\is_resource($playground)) {
        throw new RuntimeException('Could not start playground.php');
    }

    \proc_close($playground);
    \rewind($output);
    $printed = (string) \stream_get_contents($output);
    \fclose($output);

    return $printed;
}

/**
 * Every request the playground made that was about executing a function, in
 * order. Creating a deployment or listing functions is not one of them.
 *
 * @return list<array{method: string, path: string, executionPath: string|null, async: bool|null}>
 */
function executionRequests(Stub $stub): array
{
    return \array_values(\array_filter(
        $stub->requests(),
        static fn (array $request): bool => \str_contains($request['path'], '/executions')
    ));
}

/**
 * The tag `createAsyncExecution()` put in the path of its attempts. There is
 * one per playground run, which is what makes a retry recognisable as the same
 * piece of work rather than a second one.
 *
 * @param list<array{executionPath: string|null, async: bool|null}> $requests
 * @return list<string>
 */
function attemptTags(array $requests): array
{
    $tags = [];

    foreach ($requests as $request) {
        if (($request['async'] ?? false) !== true) {
            continue;
        }

        if (\preg_match('/attempt=[0-9a-f]{16}/', (string) $request['executionPath'], $matches) === 1) {
            $tags[] = $matches[0];
        }
    }

    return $tags;
}

/**
 * The execution requests named by what they ask for rather than by their path,
 * so a whole run reads as a sequence.
 *
 * @param list<array{method: string, path: string, async: bool|null}> $requests
 * @return list<string>
 */
function executionSequence(array $requests): array
{
    return \array_map(static function (array $request): string {
        if ($request['method'] === 'POST') {
            return ($request['async'] ?? false) === true ? 'create async' : 'create sync';
        }

        return \str_ends_with($request['path'], '/executions') ? 'list' : 'get';
    }, $requests);
}

echo "A failed queue publish that recorded nothing is retried\n";

$stub = Stub::start([
    'STUB_EXECUTIONS_FAIL' => '1',
    'STUB_EXECUTIONS_RECORD_ON_FAILURE' => '0',
]);

try {
    $output = runPlayground($stub);
    $requests = executionRequests($stub);
    $tags = attemptTags($requests);

    $sequence = executionSequence($requests);

    check(
        'the failure was reconciled and only then retried (got ' . \implode(', ', $sequence) . ')',
        $sequence === ['create sync', 'create async', 'list', 'create async', 'get']
    );
    check(
        'both attempts carried the same tag, so they are one piece of work',
        \count($tags) === 2 && $tags[0] === $tags[1]
    );
    check(
        'the playground did not report the run as unqueued',
        !\str_contains($output, '[queued] =>')
    );

    if ($failures !== []) {
        \fwrite(STDERR, "\n--- playground.php output ---\n{$output}\n");
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

echo "A failed queue publish that recorded the run is not retried\n";

$stub = Stub::start([
    'STUB_EXECUTIONS_FAIL' => '1',
    'STUB_EXECUTIONS_RECORD_ON_FAILURE' => '1',
]);

try {
    $output = runPlayground($stub);
    $requests = executionRequests($stub);

    $sequence = executionSequence($requests);

    check(
        'the run was attempted once and not queued again (got ' . \implode(', ', $sequence) . ')',
        $sequence === ['create sync', 'create async', 'list']
    );
    check(
        'the caller was told the run was not queued',
        \str_contains($output, '[queued] => ')
    );
    check(
        'it was handed the execution that was left behind, still waiting',
        \str_contains($output, '[status] => waiting')
    );
    check(
        'the ambiguous answer was reported as the 500 it was',
        \str_contains($output, '[code] => 500')
    );
    check(
        'the playground still finished the rest of its calls',
        \str_contains($output, 'api.deleteFunction')
    );

    if ($failures !== []) {
        \fwrite(STDERR, "\n--- playground.php output ---\n{$output}\n");
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

if ($failures !== []) {
    \fwrite(STDERR, "FAILED\n");

    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo "OK — an ambiguous queue failure is reconciled, not blindly retried\n";
