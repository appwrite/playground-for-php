<?php

/**
 * Runs the whole playground against `tests/stub-server.php` and checks that
 * every call succeeded and hit the endpoint it was supposed to hit.
 *
 * Usage: php tests/smoke.php
 */

declare(strict_types=1);

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require __DIR__ . '/stub.php';

/**
 * Every call in the playground's `$methods` list, in order. A call that threw
 * is caught and left out of the printed result, so finding all of these proves
 * none of them failed.
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
    'api.createRecovery',
    'api.createSessionFromToken',
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
    ['POST', '/v1/account/recovery'],
    ['POST', '/v1/users'],
    ['POST', '/v1/users/playground-user/tokens'],
    ['POST', '/v1/account/sessions/token'],
    ['POST', '/v1/functions'],
    ['POST', '/v1/functions/playground-function/deployments'],
    ['POST', '/v1/functions/playground-function/executions'],
    ['POST', '/v1/functions/playground-function/executions'],
    ['GET', '/v1/functions/playground-function/executions/playground-execution'],
    ['GET', '/v1/functions'],
    ['DELETE', '/v1/functions/playground-function'],
];

$failures = [];
$stub = Stub::start();

try {
    echo "Running playground.php against {$stub->endpoint}\n";

    $playgroundOutput = \tmpfile();
    $playground = \proc_open(
        [PHP_BINARY, $root . '/tests/prelude.php'],
        [1 => $playgroundOutput, 2 => $playgroundOutput],
        $playgroundPipes,
        $root,
        ['PLAYGROUND_ENDPOINT' => $stub->endpoint] + \getenv()
    );

    if (!\is_resource($playground)) {
        \fwrite(STDERR, "Could not start playground.php\n");
        exit(1);
    }

    $status = \proc_close($playground);
    \rewind($playgroundOutput);
    $output = (string) \stream_get_contents($playgroundOutput);
    \fclose($playgroundOutput);

    if ($status !== 0) {
        $failures[] = "playground.php exited with status {$status}";
    }

    if (\str_contains($output, 'Stub has no route')) {
        $failures[] = 'The playground called a route the stub does not know';
    }

    $remaining = $expectedCalls;

    foreach (\explode("\n", $output) as $line) {
        if (!\preg_match('/\[call\] => (\S+)/', $line, $matches)) {
            continue;
        }

        $found = \array_search($matches[1], $remaining, true);

        if ($found === false) {
            $failures[] = "Unexpected call in the result: {$matches[1]}";
            continue;
        }

        unset($remaining[$found]);
    }

    foreach ($remaining as $missing) {
        $failures[] = "Missing from the result (the call threw): {$missing}";
    }

    $requests = \array_map(
        static fn (array $request): array => [$request['method'], $request['path']],
        $stub->requests()
    );

    if ($requests !== $expectedRequests) {
        $failures[] = "Requests did not match.\nexpected:\n"
            . \implode("\n", \array_map(static fn (array $r): string => "  {$r[0]} {$r[1]}", $expectedRequests))
            . "\nactual:\n"
            . \implode("\n", \array_map(static fn (array $r): string => "  {$r[0]} {$r[1]}", $requests));
    }

    foreach ($requests as [$method, $path]) {
        if (\str_starts_with($path, '/v1/databases')) {
            $failures[] = "{$method} {$path} uses the Databases API, deprecated since Appwrite 1.8 in favour of TablesDB";
        }
    }

    /**
     * Every route the server keys an abuse limit by project needs the project
     * to reach it. The SDK sends it per route, so a missing one would be a
     * silent cross-project bucket rather than an error.
     */
    foreach ($stub->requests() as $request) {
        if ($request['project'] !== 'playground') {
            $failures[] = "{$request['method']} {$request['path']} did not carry the project";
        }
    }

    /**
     * The playground is a server: it reaches the session exchange with the API
     * key, which is the caller appwrite/appwrite#15470 stopped exempting from
     * that route's limits. `tests/code-guesses.php` covers what the limits then
     * do; this only pins down that the playground is that kind of caller, so a
     * key dropped from the request would not quietly make the test pass.
     */
    foreach ($stub->requests() as $request) {
        if ($request['path'] === '/v1/account/sessions/token' && $request['keyed'] !== true) {
            $failures[] = 'The session exchange did not carry the API key';
        }
    }

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

echo 'OK — ' . \count($expectedCalls) . ' playground calls over '
    . \count($expectedRequests) . " requests\n";
