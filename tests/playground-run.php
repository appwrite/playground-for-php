<?php

/**
 * Runs `php playground.php` the way a reader of the README would, against a
 * stub of the Appwrite API, and asserts the whole sequence completes without an
 * example swallowing an error.
 *
 * Run with `composer test` or `php tests/playground-run.php`.
 */

declare(strict_types=1);

require __DIR__ . '/prelude.php';
require __DIR__ . '/stub.php';

$stub = startStub();

$process = \proc_open(
    [PHP_BINARY, \dirname(__DIR__) . '/playground.php'],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    \dirname(__DIR__),
    \array_merge(\getenv(), [
        'APPWRITE_ENDPOINT' => "http://127.0.0.1:{$stub['port']}/v1",
        'APPWRITE_PROJECT_ID' => 'playground',
        'APPWRITE_API_KEY' => 'a-test-api-key',
        'no_proxy' => '127.0.0.1,localhost',
    ])
);

if (!\is_resource($process)) {
    \fwrite(STDERR, "Could not run the playground.\n");

    exit(1);
}

$output = (string) \stream_get_contents($pipes[1]);
$errors = (string) \stream_get_contents($pipes[2]);
\fclose($pipes[1]);
\fclose($pipes[2]);
$status = \proc_close($process);

$requests = stubRequests($stub['requestLog']);

echo "The playground script end to end\n";

test('the script exits successfully', function () use ($status, $errors): void {
    assertSame(0, $status, "playground.php exited with {$status}, stderr:\n{$errors}");
    assertSame('', $errors, 'Nothing is written to stderr');
});

test('no example reports an Appwrite error', function () use ($output): void {
    foreach (['Server Error', 'has no route', 'Missing required parameter', 'Fatal error'] as $needle) {
        assertTrue(
            !\str_contains($output, $needle),
            "The playground output mentions \"{$needle}\":\n{$output}"
        );
    }
});

test('every example reaches the API', function () use ($requests): void {
    $trail = \array_map(
        static fn (array $request): string => "{$request['method']} {$request['path']}",
        $requests
    );

    // One request per example, plus the column creations and the two polls the
    // table and the async execution need.
    $expected = [
        'POST /v1/tablesdb',
        'POST /v1/tablesdb/{db}/tables',
        'POST /v1/tablesdb/{db}/tables/{table}/columns/string',
        'POST /v1/tablesdb/{db}/tables/{table}/columns/integer',
        'GET /v1/tablesdb/{db}/tables/{table}/columns',
        'GET /v1/tablesdb/{db}/tables',
        'POST /v1/tablesdb/{db}/tables/{table}/rows',
        'GET /v1/tablesdb/{db}/tables/{table}/rows',
        'DELETE /v1/tablesdb/{db}/tables/{table}',
        'DELETE /v1/tablesdb/{db}',
        'POST /v1/storage/buckets',
        'POST /v1/storage/buckets/{bucket}/files',
        'GET /v1/storage/buckets/{bucket}/files',
        'DELETE /v1/storage/buckets/{bucket}/files/{file}',
        'DELETE /v1/storage/buckets/{bucket}',
        'POST /v1/users',
        'GET /v1/users',
        'POST /v1/functions',
        'POST /v1/functions/{function}/deployments',
        'POST /v1/functions/{function}/executions',
        'POST /v1/functions/{function}/executions',
        'GET /v1/functions/{function}/executions/execution-1',
        'GET /v1/functions',
        'DELETE /v1/functions/{function}',
    ];

    // Ids are generated per run, so compare the shape of each path.
    $actual = \array_map(
        static fn (string $entry): string => \preg_replace(
            [
                '#/tablesdb/[^/{]+#',
                '#/tables/(?!{)[^/]+#',
                '#/buckets/[^/{]+#',
                '#/files/(?!{)[^/]+#',
                '#/functions/(?!{)[^/]+#',
            ],
            [
                '/tablesdb/{db}',
                '/tables/{table}',
                '/buckets/{bucket}',
                '/files/{file}',
                '/functions/{function}',
            ],
            $entry
        ),
        $trail
    );

    assertSame($expected, $actual);
});

test('the output lists the result of every example', function () use ($output): void {
    assertTrue(
        \str_contains($output, 'api.createDatabase'),
        'The collected responses are printed'
    );
    assertTrue(
        \str_contains($output, 'api.deleteFunction'),
        'The sequence runs to the last example'
    );
});

exit(results());
