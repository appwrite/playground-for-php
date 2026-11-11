<?php

/**
 * A stand-in for an Appwrite instance, served by PHP's built-in web server.
 *
 * It answers only the routes the playground and the tests call, with the
 * smallest payload each response model accepts, and appends one JSON line per
 * request to the file named by `STUB_LOG`. That is enough to prove the
 * playground talks to the endpoints it means to and that the SDK can hydrate
 * what comes back — without needing a running Appwrite.
 *
 * `PUT /v1/account/verifications/*` are the routes with state behind them.
 * Since appwrite/appwrite#15277 the server counts every attempt against them —
 * API keys included — so the stub keeps a count per user in the file named by
 * `STUB_STATE` and answers `429` once a user has spent its attempts. See
 * `tests/verification.php`.
 */

declare(strict_types=1);

/** Attempts per user per hour on a verification confirmation route. */
const VERIFICATION_LIMIT = 10;

/** The one code the stub accepts; anything else is a wrong guess. */
const VERIFICATION_CODE = '123456';

$method = $_SERVER['REQUEST_METHOD'];
$path = \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$body = \json_decode(\file_get_contents('php://input') ?: '[]', true) ?: [];
$hasKey = ($_SERVER['HTTP_X_APPWRITE_KEY'] ?? '') !== '';

if ($path !== '/v1/health') {
    $log = \getenv('STUB_LOG');

    if (\is_string($log) && $log !== '') {
        \file_put_contents(
            $log,
            \json_encode(['method' => $method, 'path' => $path, 'key' => $hasKey]) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

/**
 * Answer a request and stop.
 *
 * @param array<string, mixed>|null $payload
 */
function respond(int $status, ?array $payload = null): never
{
    \http_response_code($status);

    if ($payload !== null) {
        \header('Content-Type: application/json');
        echo \json_encode($payload);
    }

    exit;
}

/**
 * Count one attempt for `$userId` and answer with what is left.
 *
 * The count is kept per user, the way the server keys the limit on these
 * routes, and it advances for every caller — a request carrying an API key
 * included. That is the whole point of the fix this stub mirrors: before it,
 * a key's attempts were peeked at and never counted, so a key could keep
 * guessing a six digit code for free.
 */
function countAttempt(string $userId): int
{
    $state = \getenv('STUB_STATE');

    if (!\is_string($state) || $state === '') {
        return 1;
    }

    $handle = \fopen($state, 'c+');

    if ($handle === false) {
        respond(500, ['message' => 'Stub could not open its state', 'type' => 'general_unknown', 'code' => 500]);
    }

    \flock($handle, LOCK_EX);
    $counts = \json_decode(\stream_get_contents($handle) ?: '{}', true) ?: [];
    $spent = ($counts[$userId] ?? 0) + 1;
    $counts[$userId] = $spent;
    \ftruncate($handle, 0);
    \rewind($handle);
    \fwrite($handle, (string) \json_encode($counts));
    \fflush($handle);
    \flock($handle, LOCK_UN);
    \fclose($handle);

    return $spent;
}

/**
 * A verification confirmation route: `PUT /v1/account/verifications/{email,email/otp,phone}`.
 *
 * @param array<string, mixed> $body
 */
function confirmVerification(array $body): never
{
    $userId = (string) ($body['userId'] ?? '');
    $secret = (string) ($body['secret'] ?? '');

    if ($userId === '' || $secret === '') {
        respond(400, [
            'message' => 'Missing required parameter',
            'type' => 'general_argument_invalid',
            'code' => 400,
        ]);
    }

    if (countAttempt($userId) > VERIFICATION_LIMIT) {
        respond(429, [
            'message' => 'Too many requests',
            'type' => 'general_rate_limit_exceeded',
            'code' => 429,
        ]);
    }

    if ($secret !== VERIFICATION_CODE) {
        respond(401, [
            'message' => 'Invalid token passed in the request',
            'type' => 'user_invalid_token',
            'code' => 401,
        ]);
    }

    respond(200, [
        '$id' => 'playground-token',
        '$createdAt' => '2026-01-01T00:00:00.000+00:00',
        'userId' => $userId,
        'secret' => '',
        'expire' => '2026-01-01T01:00:00.000+00:00',
    ]);
}

$user = [
    '$id' => 'playground-user',
    'name' => 'Example',
    'email' => 'example@example.com',
    'prefs' => [],
];

$execution = [
    '$id' => 'playground-execution',
    'resourceId' => 'playground-function',
    'trigger' => 'http',
    'status' => 'completed',
    'responseStatusCode' => 200,
];

match (true) {
    // Readiness probe for `tests/stub.php`, not an Appwrite route.
    $path === '/v1/health' => respond(200, ['status' => 'pass']),

    $method === 'POST' && $path === '/v1/tablesdb' => respond(201, [
        '$id' => 'playground-db',
        'name' => 'Test Database',
        'type' => 'tablesdb',
    ]),
    $method === 'GET' && \preg_match('#^/v1/tablesdb/[^/]+/tables$#', $path) === 1 => respond(200, [
        'total' => 1,
        'tables' => [['$id' => 'playground-table', 'name' => 'table']],
    ]),
    $method === 'POST' && \preg_match('#^/v1/tablesdb/[^/]+/tables$#', $path) === 1 => respond(201, [
        '$id' => 'playground-table',
        'name' => 'table',
    ]),
    $method === 'POST' && \preg_match('#^/v1/tablesdb/[^/]+/tables/[^/]+/columns/[a-z]+$#', $path) === 1 => respond(202, [
        'key' => 'column',
        'type' => 'string',
        'status' => 'available',
        'required' => true,
    ]),
    $method === 'POST' && \preg_match('#^/v1/tablesdb/[^/]+/tables/[^/]+/rows$#', $path) === 1 => respond(201, [
        '$id' => 'playground-row',
        '$tableId' => 'playground-table',
        '$databaseId' => 'playground-db',
        'name' => 'Spider Man',
        'release_year' => 1920,
    ]),
    $method === 'DELETE' && \preg_match('#^/v1/tablesdb/[^/]+/tables/[^/]+$#', $path) === 1 => respond(204),
    $method === 'DELETE' && \preg_match('#^/v1/tablesdb/[^/]+$#', $path) === 1 => respond(204),

    $method === 'POST' && $path === '/v1/storage/buckets' => respond(201, [
        '$id' => 'playground-bucket',
        'name' => 'test bucket',
    ]),
    $method === 'GET' && \preg_match('#^/v1/storage/buckets/[^/]+/files$#', $path) === 1 => respond(200, [
        'total' => 1,
        'files' => [['$id' => 'playground-file', 'name' => 'test.txt']],
    ]),
    $method === 'POST' && \preg_match('#^/v1/storage/buckets/[^/]+/files$#', $path) === 1 => respond(201, [
        '$id' => 'playground-file',
        'bucketId' => 'playground-bucket',
        'name' => 'test.txt',
    ]),
    $method === 'DELETE' && \preg_match('#^/v1/storage/buckets/[^/]+/files/[^/]+$#', $path) === 1 => respond(204),
    $method === 'DELETE' && \preg_match('#^/v1/storage/buckets/[^/]+$#', $path) === 1 => respond(204),

    $method === 'POST' && $path === '/v1/users' => respond(201, $user),
    $method === 'GET' && $path === '/v1/users' => respond(200, ['total' => 1, 'users' => [$user]]),
    $method === 'PATCH' && \preg_match('#^/v1/users/[^/]+/verification$#', $path) === 1 => respond(
        200,
        $user + ['emailVerification' => true]
    ),

    $method === 'POST' && $path === '/v1/account/verifications/email' => respond(201, [
        '$id' => 'playground-token',
        'userId' => 'playground-user',
        'secret' => '',
    ]),
    $method === 'POST' && $path === '/v1/account/verifications/email/otp' => respond(201, [
        '$id' => 'playground-token',
        'userId' => 'playground-user',
        'secret' => '',
    ]),
    $method === 'PUT' && \in_array($path, [
        '/v1/account/verifications/email',
        '/v1/account/verifications/email/otp',
        '/v1/account/verifications/phone',
    ], true) => confirmVerification($body),

    $method === 'POST' && $path === '/v1/functions' => respond(201, [
        '$id' => 'playground-function',
        'name' => 'Test Function',
        'runtime' => 'php-8.0',
    ]),
    $method === 'GET' && $path === '/v1/functions' => respond(200, [
        'total' => 1,
        'functions' => [['$id' => 'playground-function', 'name' => 'Test Function']],
    ]),
    $method === 'POST' && \preg_match('#^/v1/functions/[^/]+/deployments$#', $path) === 1 => respond(202, [
        '$id' => 'playground-deployment',
        'status' => 'ready',
    ]),
    $method === 'POST' && \preg_match('#^/v1/functions/[^/]+/executions$#', $path) === 1 => respond(201, $execution),
    $method === 'GET' && \preg_match('#^/v1/functions/[^/]+/executions/[^/]+$#', $path) === 1 => respond(200, $execution),
    $method === 'DELETE' && \preg_match('#^/v1/functions/[^/]+$#', $path) === 1 => respond(204),

    default => respond(404, [
        'message' => 'The stub has no route for ' . $method . ' ' . $path,
        'type' => 'general_route_not_found',
        'code' => 404,
    ]),
};
