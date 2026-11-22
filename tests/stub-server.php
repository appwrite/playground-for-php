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
 * The list routes answer a `total` the way the server does: counted only as far
 * as `COUNT_LIMIT`, so a project holding more rows than that is reported as
 * holding exactly that many. That floor is what appwrite/appwrite#15496 taught
 * the console to read as `5,000+`, and what a server reading the same list has
 * to page past to learn the real number. See `tests/list-total.php`.
 *
 * The routes that confirm a secret are the ones with state behind them. They
 * count wrong guesses per project, route and user in the file named by
 * `STUB_STATE`, the way the server keys those abuse limits, and answer `429`
 * once a user's share of attempts is spent. The count is kept for every caller,
 * an API key holder included, which is what appwrite/appwrite#15470 fixed. See
 * `tests/code-guesses.php`.
 */

declare(strict_types=1);

/**
 * Attempts allowed per project, route and user before the limit answers.
 */
const GUESS_LIMIT = 10;

/**
 * The only secret the stub accepts; anything else counts as a wrong guess.
 */
const VALID_SECRET = 'playground-secret';

/**
 * How far a list route counts before it gives up: the server's
 * `APP_LIMIT_COUNT`. A `total` that reaches this is a floor — "at least this
 * many rows" — because the count stopped there, not because the list does.
 */
const COUNT_LIMIT = 5000;

/**
 * Users the stub's project holds. More than `COUNT_LIMIT`, so every count of
 * them comes back clamped and only paging to the end finds the real number.
 */
const USER_POPULATION = 5123;

/**
 * Users a `search` term matches: few enough to be counted in full, so that
 * total is exact and must not be dressed up as a floor.
 */
const SEARCHED_USER_POPULATION = 7;

/**
 * Page size a list uses when the caller sends no `Query::limit`: the server's
 * `APP_LIMIT_LIST_DEFAULT`.
 */
const LIST_LIMIT_DEFAULT = 25;

$method = $_SERVER['REQUEST_METHOD'];
$path = \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$project = $_SERVER['HTTP_X_APPWRITE_PROJECT'] ?? '';

$log = \getenv('STUB_LOG');
if (\is_string($log) && $log !== '') {
    \file_put_contents(
        $log,
        \json_encode([
            'method' => $method,
            'path' => $path,
            'project' => $project,
            // Whether the caller held an API key, which used to exempt it from
            // the limits on the routes below.
            'keyed' => ($_SERVER['HTTP_X_APPWRITE_KEY'] ?? '') !== '',
            // How the caller asked for its page, so a test can tell a cursor
            // walk from offset paging.
            'queries' => queries(),
        ]) . "\n",
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
 * The request body as an array, or an empty one when there is no JSON body.
 *
 * @return array<string, mixed>
 */
function body(): array
{
    static $body = null;

    if ($body === null) {
        $decoded = \json_decode(\file_get_contents('php://input') ?: '[]', true);
        $body = \is_array($decoded) ? $decoded : [];
    }

    return $body;
}

/**
 * Read the counters, run `$change` over them and write back what it returns.
 *
 * Nothing here looks at whether the caller holds an API key: the limits on the
 * routes that confirm a secret count for every caller.
 *
 * @param callable(array<string, int>): array<string, int> $change
 * @return array<string, int> the counters after the change
 */
function counters(callable $change): array
{
    $state = \getenv('STUB_STATE');

    if (!\is_string($state) || $state === '') {
        return $change([]);
    }

    $handle = \fopen($state, 'c+');

    if ($handle === false) {
        respond(500, ['message' => 'Stub could not open its state', 'type' => 'general_unknown', 'code' => 500]);
    }

    \flock($handle, LOCK_EX);
    $counts = $change(\json_decode(\stream_get_contents($handle) ?: '{}', true) ?: []);
    \ftruncate($handle, 0);
    \rewind($handle);
    \fwrite($handle, (string) \json_encode($counts));
    \fflush($handle);
    \flock($handle, LOCK_UN);
    \fclose($handle);

    return $counts;
}

function spent(string $counter): int
{
    return counters(static fn (array $counts): array => $counts)[$counter] ?? 0;
}

function spend(string $counter): void
{
    counters(static function (array $counts) use ($counter): array {
        $counts[$counter] = ($counts[$counter] ?? 0) + 1;

        return $counts;
    });
}

/**
 * A route that confirms a secret the caller sends for a user it names.
 *
 * The limit is checked before the secret, the way the server's abuse hook runs
 * ahead of the route: once a user's share is spent the route answers `429` even
 * to the correct secret. Within the share, the correct secret is answered with
 * `$success` and a wrong one with `401`.
 *
 * `$countEveryAttempt` tells the two kinds of limit apart. `Limit::attempts`,
 * which the recovery OTP confirmation carries, counts every request;
 * `Limit::failures`, which the session exchanges carry, counts only the ones
 * that failed, so a login that worked does not eat into the share. Both count
 * for every caller since appwrite/appwrite#15470.
 *
 * @param array<string, mixed> $success
 */
function confirmSecret(string $project, string $path, array $success, bool $countEveryAttempt): never
{
    $body = body();
    $userId = (string) ($body['userId'] ?? '');
    $secret = (string) ($body['secret'] ?? '');
    $counter = $project . "\0" . $path . "\0" . $userId;

    if (spent($counter) >= GUESS_LIMIT) {
        respond(429, [
            'message' => 'Too many requests',
            'type' => 'general_rate_limit_exceeded',
            'code' => 429,
        ]);
    }

    if ($countEveryAttempt) {
        spend($counter);
    }

    if ($secret === VALID_SECRET) {
        respond(201, $success);
    }

    if (!$countEveryAttempt) {
        spend($counter);
    }

    respond(401, [
        'message' => 'Invalid token passed in the request.',
        'type' => 'user_invalid_token',
        'code' => 401,
    ]);
}

if ($method === 'POST' && $path === '/v1/account/sessions/token') {
    confirmSecret($project, $path, [
        '$id' => 'playground-session',
        'userId' => 'playground-user',
        'secret' => 'playground-session-secret',
    ], countEveryAttempt: false);
}

if ($method === 'PUT' && $path === '/v1/account/recovery/otp') {
    confirmSecret($project, $path, [
        '$id' => 'playground-token',
        'userId' => 'playground-user',
    ], countEveryAttempt: true);
}

/**
 * The `queries[]` params the SDK sends, decoded as `method => values`.
 *
 * @return array<string, array<int, mixed>>
 */
function queries(): array
{
    $parsed = [];

    foreach ((array) ($_GET['queries'] ?? []) as $query) {
        $decoded = \json_decode((string) $query, true);

        if (\is_array($decoded) && isset($decoded['method'])) {
            $parsed[(string) $decoded['method']] = (array) ($decoded['values'] ?? []);
        }
    }

    return $parsed;
}

/**
 * The id of the nth user, counting from one. Ids sort the way the rows do, so a
 * cursor is just a position in the list.
 */
function userId(int $position): string
{
    return \sprintf('user-%04d', $position);
}

/**
 * `GET /v1/users`: a page of users, and a `total` counted only as far as
 * `COUNT_LIMIT`.
 *
 * This is the client-visible half of appwrite/appwrite#15496. The count has a
 * budget, so for a project holding more users than the budget the `total` is
 * the budget: the real number is not something the route knows, and only paging
 * to the end of the list finds it. `total=false` asks for no count at all,
 * which answers `0` — "not counted", rather than "no users".
 */
function listUsersPage(): never
{
    $queries = queries();
    $population = ($_GET['search'] ?? '') === '' ? USER_POPULATION : SEARCHED_USER_POPULATION;
    $limit = (int) ($queries['limit'][0] ?? LIST_LIMIT_DEFAULT);
    $offset = (int) ($queries['offset'][0] ?? 0);
    $cursor = $queries['cursorAfter'][0] ?? null;

    // A cursor names the last row the caller saw, so its page starts after that
    // row — plus whatever offset was asked for on top of it.
    if ($cursor !== null) {
        $position = \str_starts_with((string) $cursor, 'user-')
            ? (int) \substr((string) $cursor, \strlen('user-'))
            : 0;

        if ($position < 1 || $position > $population) {
            respond(400, [
                'message' => "User '{$cursor}' for the 'cursor' value not found.",
                'type' => 'general_cursor_not_found',
                'code' => 400,
            ]);
        }

        $offset += $position;
    }

    $rows = [];
    $end = \min($offset + \max($limit, 0), $population);

    for ($position = $offset + 1; $position <= $end; $position++) {
        $rows[] = ['$id' => userId($position), 'prefs' => []];
    }

    respond(200, [
        'total' => \in_array($_GET['total'] ?? '1', ['0', 'false'], true)
            ? 0
            : \min($population, COUNT_LIMIT),
        'users' => $rows,
    ]);
}

if ($method === 'GET' && $path === '/v1/users') {
    listUsersPage();
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

    ['POST', '/v1/users', 201, ['$id' => 'playground-user', 'email' => 'email@example.com', 'prefs' => []]],
    ['POST', '/v1/users/*/tokens', 201, ['$id' => 'playground-token', 'userId' => 'playground-user', 'secret' => VALID_SECRET]],

    ['POST', '/v1/account/recovery', 201, ['$id' => 'playground-token', 'userId' => 'playground-user']],

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
