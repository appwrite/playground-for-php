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
 * `POST /v1/account/recovery` is the one route with state behind it: it keeps a
 * count per project and address in the file named by `STUB_STATE`, the way the
 * server keys that route's abuse limit, and answers `429` once a project has
 * spent its share for an address. See `tests/rate-limit.php`.
 */

declare(strict_types=1);

const RECOVERY_LIMIT = 10;

$method = $_SERVER['REQUEST_METHOD'];
$path = \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$project = $_SERVER['HTTP_X_APPWRITE_PROJECT'] ?? '';

$log = \getenv('STUB_LOG');
if (\is_string($log) && $log !== '') {
    \file_put_contents(
        $log,
        \json_encode(['method' => $method, 'path' => $path, 'project' => $project]) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Answer a request and stop.
 *
 * @param array<string, mixed>|null $body
 */
function respond(int $status, ?array $body = null): never
{
    \http_response_code($status);

    if ($body !== null) {
        \header('Content-Type: application/json');
        echo \json_encode($body);
    }

    exit;
}

/**
 * The abuse-limited recovery route, counted per project and address.
 *
 * The address is lower-cased first, so that changing its case does not hand the
 * caller a fresh count.
 */
function recovery(string $project): never
{
    $state = \getenv('STUB_STATE');
    $email = \strtolower((string) (\json_decode(\file_get_contents('php://input') ?: '[]', true)['email'] ?? ''));

    if (!\is_string($state) || $state === '') {
        respond(201, ['$id' => 'playground-token', 'userId' => 'playground-user']);
    }

    $handle = \fopen($state, 'c+');

    if ($handle === false) {
        respond(500, ['message' => 'Stub could not open its state', 'type' => 'general_unknown', 'code' => 500]);
    }

    \flock($handle, LOCK_EX);
    $counts = \json_decode(\stream_get_contents($handle) ?: '{}', true) ?: [];
    $key = $project . "\0" . $email;
    $spent = ($counts[$key] ?? 0) + 1;
    $counts[$key] = $spent;
    \ftruncate($handle, 0);
    \rewind($handle);
    \fwrite($handle, (string) \json_encode($counts));
    \fflush($handle);
    \flock($handle, LOCK_UN);
    \fclose($handle);

    if ($spent > RECOVERY_LIMIT) {
        respond(429, [
            'message' => 'Too many requests',
            'type' => 'general_rate_limit_exceeded',
            'code' => 429,
        ]);
    }

    respond(201, ['$id' => 'playground-token', 'userId' => 'playground-user']);
}

if ($method === 'POST' && $path === '/v1/account/recovery') {
    recovery($project);
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
