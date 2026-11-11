<?php

/**
 * Runs the playground against `tests/stub-server.php` and checks that every
 * call succeeded and hit the endpoint it was supposed to hit, then hands over
 * to `tests/verification.php` for the email verification routes.
 *
 * Usage: php tests/smoke.php
 */

declare(strict_types=1);

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

/**
 * Every call in the playground's default `$methods` list, in order. A call that
 * threw is caught and left out of the printed result, so finding all of these
 * proves none of them failed.
 */
$expectedCalls = [
    'api.createDatabase',
    'api.createTable',
    'api.listTables',
    'api.addRow',
    'api.deleteTable',
    'api.deleteDatabase',
    'api.createBucket',
    'api.createFile',
    'api.listFiles',
    'api.deleteFile',
    'api.deleteBucket',
    'api.createUser',
    'api.listUsers',
    'api.createFunction',
    'api.createDeployment',
    'api.createExecution', // sync
    'api.createExecution', // async
    'api.listFunctions',
    'api.deleteFunction',
];

/**
 * The requests those calls are expected to make, in order. The stub hands back
 * fixed IDs, so the paths are fully determined.
 */
$expectedRequests = [
    ['POST', '/v1/tablesdb'],
    ['POST', '/v1/tablesdb/playground-db/tables'],
    ['POST', '/v1/tablesdb/playground-db/tables/playground-table/columns/string'],
    ['POST', '/v1/tablesdb/playground-db/tables/playground-table/columns/integer'],
    ['GET', '/v1/tablesdb/playground-db/tables'],
    ['POST', '/v1/tablesdb/playground-db/tables/playground-table/rows'],
    ['DELETE', '/v1/tablesdb/playground-db/tables/playground-table'],
    ['DELETE', '/v1/tablesdb/playground-db'],
    ['POST', '/v1/storage/buckets'],
    ['POST', '/v1/storage/buckets/playground-bucket/files'],
    ['GET', '/v1/storage/buckets/playground-bucket/files'],
    ['DELETE', '/v1/storage/buckets/playground-bucket/files/playground-file'],
    ['DELETE', '/v1/storage/buckets/playground-bucket'],
    ['POST', '/v1/users'],
    ['GET', '/v1/users'],
    ['POST', '/v1/functions'],
    ['POST', '/v1/functions/playground-function/deployments'],
    ['POST', '/v1/functions/playground-function/executions'],
    ['POST', '/v1/functions/playground-function/executions'],
    ['GET', '/v1/functions/playground-function/executions/playground-execution'],
    ['GET', '/v1/functions'],
    ['DELETE', '/v1/functions/playground-function'],
];

/**
 * The verification calls the playground leaves commented out, named on its
 * command line, and the requests they make.
 */
$verificationCalls = [
    'api.updateEmailVerification',
    'api.updateEmailVerificationOTP',
];

$verificationRequests = [
    ['PUT', '/v1/account/verifications/email'],
    ['PUT', '/v1/account/verifications/email/otp'],
];

$failures = [];

/**
 * Ask the OS for a port nobody is using, then hand it to the stub server. There
 * is a gap between closing the probe and the server binding, which is why this
 * is a test-only shortcut and not a pattern to copy.
 */
function freePort(): int
{
    $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($socket === false) {
        throw new RuntimeException("Could not reserve a port: {$error}");
    }

    $name = \stream_socket_get_name($socket, false);
    \fclose($socket);

    return (int) \substr((string) $name, \strrpos((string) $name, ':') + 1);
}

/**
 * Run a PHP script to completion, capturing its output and the requests it made.
 *
 * The stub logs every request to one file for the whole run, so a phase's
 * requests are the lines it appended.
 *
 * @param list<string> $arguments
 * @param array<string, string> $environment
 * @return array{status: int, output: string, requests: list<array{0: string, 1: string}>}
 */
function run(string $script, array $arguments, array $environment, string $requestLog): array
{
    $root = \dirname(__DIR__);
    $before = \count(readRequests($requestLog));

    $output = \tmpfile();
    $process = \proc_open(
        [PHP_BINARY, $root . '/tests/' . $script, ...$arguments],
        [1 => $output, 2 => $output],
        $pipes,
        $root,
        $environment + \getenv()
    );

    if (!\is_resource($process)) {
        throw new RuntimeException("Could not start {$script}");
    }

    $status = \proc_close($process);
    \rewind($output);
    $contents = (string) \stream_get_contents($output);
    \fclose($output);

    return [
        'status' => $status,
        'output' => $contents,
        'requests' => \array_slice(readRequests($requestLog), $before),
    ];
}

/**
 * Every request the stub has logged so far.
 *
 * @return list<array{0: string, 1: string}>
 */
function readRequests(string $requestLog): array
{
    $requests = [];

    foreach (\file($requestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $request = \json_decode($line, true);
        $requests[] = [$request['method'], $request['path']];
    }

    return $requests;
}

/**
 * The calls the playground printed, in the order it printed them.
 *
 * @return list<string>
 */
function calls(string $output): array
{
    \preg_match_all('/\[call\] => (\S+)/', $output, $matches);

    return $matches[1];
}

/**
 * @param list<array{0: string, 1: string}> $requests
 */
function describeRequests(array $requests): string
{
    return \implode("\n", \array_map(static fn (array $r): string => "  {$r[0]} {$r[1]}", $requests));
}

$port = freePort();
$endpoint = "http://127.0.0.1:{$port}/v1";
$requestLog = \tempnam(\sys_get_temp_dir(), 'playground-requests-');

if ($requestLog === false) {
    \fwrite(STDERR, "Could not create a request log\n");
    exit(1);
}

$serverErrors = \tmpfile();
$server = \proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root, $root . '/tests/stub-server.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => $serverErrors],
    $pipes,
    $root,
    ['STUB_LOG' => $requestLog] + \getenv()
);

if (!\is_resource($server)) {
    \fwrite(STDERR, "Could not start the stub server\n");
    exit(1);
}

$outputs = [];

try {
    $ready = false;

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);

        if ($probe !== false) {
            \fclose($probe);
            $ready = true;
            break;
        }

        \usleep(50_000);
    }

    if (!$ready) {
        \fwrite(STDERR, "Stub server never accepted a connection on port {$port}\n");
        exit(1);
    }

    echo "Running playground.php against {$endpoint}\n";

    $playground = run('prelude.php', [], ['PLAYGROUND_ENDPOINT' => $endpoint], $requestLog);
    $outputs['playground.php'] = $playground['output'];

    if ($playground['status'] !== 0) {
        $failures[] = "playground.php exited with status {$playground['status']}";
    }

    if (\str_contains($playground['output'], 'Stub has no route')) {
        $failures[] = 'The playground called a route the stub does not know';
    }

    $remaining = $expectedCalls;

    foreach (calls($playground['output']) as $call) {
        $found = \array_search($call, $remaining, true);

        if ($found === false) {
            $failures[] = "Unexpected call in the result: {$call}";
            continue;
        }

        unset($remaining[$found]);
    }

    foreach ($remaining as $missing) {
        $failures[] = "Missing from the result (the call threw): {$missing}";
    }

    if ($playground['requests'] !== $expectedRequests) {
        $failures[] = "Requests did not match.\nexpected:\n" . describeRequests($expectedRequests)
            . "\nactual:\n" . describeRequests($playground['requests']);
    }

    foreach ($playground['requests'] as [$method, $path]) {
        if (\str_starts_with($path, '/v1/databases')) {
            $failures[] = "{$method} {$path} uses the Databases API, deprecated since Appwrite 1.8 in favour of TablesDB";
        }
    }

    echo "Running the playground's email verification calls\n";

    $verification = run(
        'prelude.php',
        ['updateEmailVerification', 'updateEmailVerificationOTP'],
        ['PLAYGROUND_ENDPOINT' => $endpoint],
        $requestLog
    );
    $outputs['playground.php (verification calls)'] = $verification['output'];

    if ($verification['status'] !== 0) {
        $failures[] = "The verification calls exited with status {$verification['status']}";
    }

    if (calls($verification['output']) !== $verificationCalls) {
        $failures[] = 'The verification calls did not both succeed, got: '
            . \implode(', ', calls($verification['output']) ?: ['nothing']);
    }

    if ($verification['requests'] !== $verificationRequests) {
        $failures[] = "Verification requests did not match.\nexpected:\n" . describeRequests($verificationRequests)
            . "\nactual:\n" . describeRequests($verification['requests']);
    }

    echo "Checking that a verification token is claimed exactly once\n";

    $singleUse = run('verification.php', [], ['PLAYGROUND_ENDPOINT' => $endpoint], $requestLog);
    $outputs['verification.php'] = $singleUse['output'];

    if ($singleUse['status'] !== 0) {
        $failures[] = 'tests/verification.php failed: ' . \trim($singleUse['output']);
    }

    if ($failures !== []) {
        foreach ($outputs as $name => $output) {
            \fwrite(STDERR, "\n--- {$name} output ---\n{$output}\n");
        }

        \rewind($serverErrors);
        \fwrite(STDERR, "--- stub server log ---\n" . (string) \stream_get_contents($serverErrors) . "\n");
    }
} finally {
    \proc_terminate($server);
    \proc_close($server);
    \fclose($serverErrors);
    @\unlink($requestLog);
    @\unlink($requestLog . '.claimed');
}

if ($failures !== []) {
    \fwrite(STDERR, "FAILED\n");

    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo 'OK — ' . \count($expectedCalls) . ' playground calls over '
    . \count($expectedRequests) . ' requests, plus '
    . \count($verificationCalls) . " email verification calls\n";
