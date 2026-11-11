<?php

/**
 * Smoke test for playground.php.
 *
 * Runs the playground against tests/stub-server.php - an Appwrite-shaped stub -
 * and asserts that every covered API method completes. This catches SDK upgrades
 * that rename a service, change a parameter name or reshape a response model,
 * none of which a syntax check would notice.
 */
$root = \dirname(__DIR__);
$host = '127.0.0.1:8099';

$server = \proc_open(
    [PHP_BINARY, '-S', $host, $root . '/tests/stub-server.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);

if (!\is_resource($server)) {
    \fwrite(STDERR, "could not start the stub server\n");
    exit(1);
}

try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @\fsockopen('127.0.0.1', 8099, $errno, $errstr, 0.2);

        if ($probe !== false) {
            \fclose($probe);
            break;
        }

        \usleep(100_000);
    }

    $output = [];
    \exec(
        \escapeshellarg(PHP_BINARY)
            . ' -d ' . \escapeshellarg('auto_prepend_file=' . $root . '/tests/prelude.php')
            . ' ' . \escapeshellarg($root . '/playground.php')
            . ' 2>&1',
        $output
    );
    $output = \implode("\n", $output);
} finally {
    \proc_terminate($server);
    \proc_close($server);
}

// playground.php reports a failed call by printing the exception and its trace,
// so any stack frame in the output means a method did not complete.
$failures = [];

if (\preg_match('/^#\d+ /m', $output) === 1 || \str_contains($output, 'Fatal error')) {
    $failures[] = 'the playground reported an error:' . "\n" . $output;
}

$expected = [
    'createDatabase',
    'createTable',
    'listTables',
    'createRow',
    'listRows',
    'deleteTable',
    'deleteDatabase',
    'createBucket',
    'createFile',
    'listFiles',
    'deleteFile',
    'deleteBucket',
    'createUser',
    'listUsers',
    'createFunction',
    'createDeployment',
    'createExecution',
    'listFunctions',
    'deleteFunction',
];

foreach ($expected as $call) {
    if (!\str_contains($output, '[call] => api.' . $call)) {
        $failures[] = "api.{$call} did not complete";
    }
}

if ($failures !== []) {
    \fwrite(STDERR, "FAIL\n" . \implode("\n", $failures) . "\n");
    exit(1);
}

echo 'OK: ' . \count($expected) . " covered API methods completed against the stub\n";
