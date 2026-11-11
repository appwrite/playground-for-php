<?php

/**
 * A stand-in for an Appwrite instance, served by PHP's built-in web server.
 *
 * It answers only the routes the tests call, with the smallest payload each
 * response model accepts, and appends one JSON line per request to the file
 * named by `STUB_LOG`. That is enough to prove the playground talks to the
 * endpoints it means to and that the SDK can hydrate what comes back — without
 * needing a running Appwrite.
 *
 * The two email verification confirmations are the exception: they keep state
 * in `STUB_LOG . '.claimed'` so that a token can be claimed exactly once, the
 * way Appwrite claims it under the user row's lock.
 */

declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'];
$path = \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

$log = \getenv('STUB_LOG');

if (!\is_string($log) || $log === '') {
    \http_response_code(500);
    \header('Content-Type: application/json');
    echo \json_encode(['message' => 'STUB_LOG is not set', 'type' => 'general_server_error', 'code' => 500]);

    exit;
}

\file_put_contents(
    $log,
    \json_encode(['method' => $method, 'path' => $path]) . "\n",
    FILE_APPEND | LOCK_EX
);

/**
 * Answer with a JSON body and a status, then stop.
 */
function respond(int $status, ?array $body): never
{
    \http_response_code($status);

    if ($body !== null) {
        \header('Content-Type: application/json');
        echo \json_encode($body);
    }

    exit;
}

/**
 * Claim a verification token, returning false if it was already claimed.
 *
 * `php -S` serves one request at a time and keeps nothing between them, so the
 * claims live in a file next to the request log.
 */
function claim(string $log, string $key): bool
{
    $file = $log . '.claimed';
    $claimed = \is_file($file)
        ? (\file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        : [];

    if (\in_array($key, $claimed, true)) {
        return false;
    }

    \file_put_contents($file, $key . "\n", FILE_APPEND | LOCK_EX);

    return true;
}

// PUT /v1/account/verifications/email and .../otp — single use, like the real
// routes: whoever deletes the token first gets the 200, everyone else a 401.
if ($method === 'PUT' && \in_array($path, ['/v1/account/verifications/email', '/v1/account/verifications/email/otp'], true)) {
    $params = \json_decode(\file_get_contents('php://input') ?: '[]', true) ?: [];
    $userId = (string) ($params['userId'] ?? '');
    $secret = (string) ($params['secret'] ?? '');

    if ($userId === '' || $secret === '') {
        respond(400, [
            'message' => 'Missing required parameter',
            'type' => 'general_argument_invalid',
            'code' => 400,
        ]);
    }

    if (!claim($log, "{$path}|{$userId}|{$secret}")) {
        respond(401, [
            'message' => 'Invalid token passed in the request.',
            'type' => 'user_invalid_token',
            'code' => 401,
        ]);
    }

    respond(200, [
        '$id' => 'playground-token',
        '$createdAt' => '2026-01-01T00:00:00.000+00:00',
        'userId' => $userId,
        'secret' => '',
        'expire' => '2026-01-01T00:15:00.000+00:00',
    ]);
}

/**
 * @var array<int, array{0: string, 1: string, 2: int, 3: array<string, mixed>|null}>
 *      [method, path pattern, status, body] — `*` matches one path segment.
 */
$routes = [
    ['POST', '/v1/tablesdb', 201, ['$id' => 'playground-db', 'name' => 'Test Database']],
    ['DELETE', '/v1/tablesdb/*', 204, null],
    ['GET', '/v1/tablesdb/*/tables', 200, ['total' => 1, 'tables' => [['$id' => 'playground-table', 'name' => 'table']]]],
    ['POST', '/v1/tablesdb/*/tables', 201, ['$id' => 'playground-table', 'name' => 'table']],
    ['DELETE', '/v1/tablesdb/*/tables/*', 204, null],
    ['POST', '/v1/tablesdb/*/tables/*/columns/string', 202, ['key' => 'name', 'type' => 'string', 'size' => 255, 'status' => 'available', 'required' => true]],
    ['POST', '/v1/tablesdb/*/tables/*/columns/integer', 202, ['key' => 'release_year', 'type' => 'integer', 'status' => 'available', 'required' => true]],
    ['POST', '/v1/tablesdb/*/tables/*/rows', 201, ['$id' => 'playground-row', 'name' => 'Spider Man', 'release_year' => 1920]],

    ['POST', '/v1/storage/buckets', 201, ['$id' => 'playground-bucket', 'name' => 'test bucket']],
    ['DELETE', '/v1/storage/buckets/*', 204, null],
    ['GET', '/v1/storage/buckets/*/files', 200, ['total' => 1, 'files' => [['$id' => 'playground-file', 'name' => 'test.txt']]]],
    ['POST', '/v1/storage/buckets/*/files', 201, ['$id' => 'playground-file', 'name' => 'test.txt']],
    ['DELETE', '/v1/storage/buckets/*/files/*', 204, null],

    ['GET', '/v1/users', 200, ['total' => 1, 'users' => [['$id' => 'playground-user', 'prefs' => []]]]],
    ['POST', '/v1/users', 201, ['$id' => 'playground-user', 'email' => 'email@example.com', 'prefs' => []]],

    ['GET', '/v1/functions', 200, ['total' => 1, 'functions' => [['$id' => 'playground-function']]]],
    ['POST', '/v1/functions', 201, ['$id' => 'playground-function', 'name' => 'Test Function']],
    ['DELETE', '/v1/functions/*', 204, null],
    ['POST', '/v1/functions/*/deployments', 202, ['$id' => 'playground-deployment', 'status' => 'ready']],
    ['GET', '/v1/functions/*/executions/*', 200, ['$id' => 'playground-execution', 'trigger' => 'http', 'status' => 'completed']],
    ['POST', '/v1/functions/*/executions', 201, ['$id' => 'playground-execution', 'trigger' => 'http', 'status' => 'completed']],

    // Reached only once the JWT-only call at the end of the playground's
    // `$methods` list is uncommented.
    ['GET', '/v1/account', 200, ['$id' => 'playground-user', 'email' => 'email@example.com', 'prefs' => []]],
];

/**
 * Does a concrete path match a pattern whose `*` segments are wildcards?
 */
function matchesPattern(string $pattern, string $path): bool
{
    $patternSegments = \explode('/', $pattern);
    $pathSegments = \explode('/', $path);

    if (\count($patternSegments) !== \count($pathSegments)) {
        return false;
    }

    foreach ($patternSegments as $i => $segment) {
        if ($segment !== '*' && $segment !== $pathSegments[$i]) {
            return false;
        }
    }

    return true;
}

foreach ($routes as [$routeMethod, $pattern, $status, $body]) {
    if ($routeMethod === $method && matchesPattern($pattern, $path)) {
        respond($status, $body);
    }
}

respond(404, [
    'message' => "Stub has no route for {$method} {$path}",
    'type' => 'general_route_not_found',
    'code' => 404,
]);
