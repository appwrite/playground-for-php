<?php

/**
 * Runs the whole playground against `tests/stub-server.php` and checks that
 * every call succeeded and hit the endpoint it was supposed to hit.
 *
 * Usage: php tests/smoke.php
 */

declare(strict_types=1);

if (!\is_file(\dirname(__DIR__) . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require __DIR__ . '/assertions.php';

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
    'api.markUserVerified',
    'api.confirmEmailVerification',
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
    ['PATCH', '/v1/users/playground-user/verification'],
    ['PUT', '/v1/account/verifications/email/otp'],
    ['POST', '/v1/functions'],
    ['POST', '/v1/functions/playground-function/deployments'],
    ['POST', '/v1/functions/playground-function/executions'],
    ['POST', '/v1/functions/playground-function/executions'],
    ['GET', '/v1/functions/playground-function/executions/playground-execution'],
    ['GET', '/v1/functions'],
    ['DELETE', '/v1/functions/playground-function'],
];

$stub = Stub::start();

try {
    $output = runPlayground($stub, [
        'PLAYGROUND_VERIFICATION_USER_ID' => 'playground-user',
        'PLAYGROUND_VERIFICATION_SECRET' => '123456',
    ]);

    $requests = \array_map(
        static fn (array $request): array => [$request['method'], $request['path']],
        $stub->requests()
    );
} finally {
    $stub->stop();
}

checkSame($expectedCalls, playgroundCalls($output), 'The playground did not make every call');
checkSame($expectedRequests, $requests, 'The playground did not hit the expected endpoints');
check(!\str_contains($output, 'has no route for'), "The playground called a route the stub does not know:\n" . $output);
check(
    \str_contains($output, '[response] => Array'),
    "The playground printed no hydrated response:\n" . $output
);

report('smoke');
