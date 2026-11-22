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
 *
 * The index routes keep their documents in the same file, and stand in for the
 * index worker: an index is settled the moment it is created, a unique one over
 * the stub's repeating rows settles as `failed`, and deleting a column prunes it
 * out of every index that covered it. `STUB_INDEX_PRE_15471` asks for the worker
 * as it behaved before appwrite/appwrite#15471 — a delete that leaves a failed
 * index behind as `stuck`, and pruning by value rather than by position. See
 * `tests/index-cleanup.php`.
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

/**
 * The timestamp every document the stub hands back claims to be from.
 */
const STUB_TIMESTAMP = '2026-01-01T00:00:00.000+00:00';

/**
 * What the index worker says about a unique index it could not build. The
 * stub's rows repeat a value, so a unique index over them can only fail.
 */
const DUPLICATE_INDEX_ERROR = 'Index creation failed: duplicate values in the indexed columns.';

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
 * Read the stub's state, run `$change` over it and write back what it returns.
 *
 * Everything the stub remembers between requests lives in here: the guess
 * counters below, and the index documents further down.
 *
 * @param callable(array<string, mixed>): array<string, mixed> $change
 * @return array<string, mixed> the state after the change
 */
function state(callable $change): array
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
    $stored = $change(\json_decode(\stream_get_contents($handle) ?: '{}', true) ?: []);
    \ftruncate($handle, 0);
    \rewind($handle);
    \fwrite($handle, (string) \json_encode($stored));
    \fflush($handle);
    \flock($handle, LOCK_UN);
    \fclose($handle);

    return $stored;
}

/**
 * Nothing in the two below looks at whether the caller holds an API key: the
 * limits on the routes that confirm a secret count for every caller.
 */
function spent(string $counter): int
{
    return (int) (state(static fn (array $stored): array => $stored)[$counter] ?? 0);
}

function spend(string $counter): void
{
    state(static function (array $stored) use ($counter): array {
        $stored[$counter] = ((int) ($stored[$counter] ?? 0)) + 1;

        return $stored;
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
 * Does the stub stand in for the index worker as it behaved before
 * appwrite/appwrite#15471?
 */
function pre15471(): bool
{
    return \in_array(\getenv('STUB_INDEX_PRE_15471'), ['1', 'true'], true);
}

/**
 * The stored indexes, keyed by index key.
 *
 * @return array<string, array<string, mixed>>
 */
function storedIndexes(): array
{
    return (array) (state(static fn (array $stored): array => $stored)['indexes'] ?? []);
}

/**
 * Run `$change` over the stored indexes and write back what it returns.
 *
 * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $change
 */
function changeIndexes(callable $change): void
{
    state(static function (array $stored) use ($change): array {
        $stored['indexes'] = $change((array) ($stored['indexes'] ?? []));

        return $stored;
    });
}

/**
 * An index as a response model.
 *
 * @param array<string, mixed> $index
 * @return array<string, mixed>
 */
function indexDocument(string $key, array $index, ?string $status = null): array
{
    return [
        '$id' => $key,
        '$createdAt' => STUB_TIMESTAMP,
        '$updatedAt' => STUB_TIMESTAMP,
        'key' => $key,
        'description' => '',
        'type' => $index['type'],
        'status' => $status ?? $index['status'],
        'error' => $status === null ? $index['error'] : '',
        'columns' => $index['columns'],
        'lengths' => $index['lengths'],
        'orders' => $index['orders'],
    ];
}

function indexNotFound(): never
{
    respond(404, [
        'message' => 'Index with the requested ID could not be found.',
        'type' => 'index_not_found',
        'code' => 404,
    ]);
}

/**
 * `POST .../indexes`: accept an index and settle it the way the worker would.
 *
 * The route answers `202` and a `processing` status, because the worker has not
 * run yet; the status the stub stores is the one the next read will show. A
 * unique index is the one that cannot be built: the stub's rows repeat a value.
 */
function createIndex(): never
{
    $body = body();
    $key = (string) ($body['key'] ?? '');
    $type = (string) ($body['type'] ?? 'key');
    $columns = (array) ($body['columns'] ?? []);

    // A unique index is the one the worker cannot build, so it is also the one
    // whose schema entry never comes to exist — which is what the delete below
    // used to give up on.
    $built = $type !== 'unique';

    $index = [
        'type' => $type,
        'schema' => $built,
        'status' => $built ? 'available' : 'failed',
        'error' => $built ? '' : DUPLICATE_INDEX_ERROR,
        'columns' => \array_values($columns),
        'lengths' => \array_values((array) ($body['lengths'] ?? [])),
        'orders' => \array_values((array) ($body['orders'] ?? [])),
    ];

    changeIndexes(static function (array $indexes) use ($key, $index): array {
        $indexes[$key] = $index;

        return $indexes;
    });

    respond(202, indexDocument($key, $index, status: 'processing'));
}

/**
 * `DELETE .../indexes/{key}`: drop the index document.
 *
 * Since appwrite/appwrite#15471 the worker drops the document even when the
 * schema has no such index to drop — which is the case for an index that never
 * finished creating. Before it, that was recorded as a failed delete and the
 * index was left behind as `stuck`, with every retry taking the same path.
 */
function deleteIndex(string $key): never
{
    $indexes = storedIndexes();

    if (!isset($indexes[$key])) {
        indexNotFound();
    }

    // The worker drops the index from the schema and then the document. Before
    // appwrite/appwrite#15471 an index the schema did not hold stopped it at the
    // first step: it recorded the delete as failed and left the document behind
    // as `stuck`. The schema holds no more of it on the next attempt than it did
    // on this one, so a retry got exactly this far too.
    $stuck = pre15471() && $indexes[$key]['schema'] === false;

    changeIndexes(static function (array $indexes) use ($key, $stuck): array {
        if ($stuck) {
            $indexes[$key]['status'] = 'stuck';
            $indexes[$key]['error'] = 'Index not found';

            return $indexes;
        }

        unset($indexes[$key]);

        return $indexes;
    });

    respond(204);
}

/**
 * `DELETE .../columns/{key}`: prune the column out of every index over it.
 *
 * A length and an order describe the column at their own position, so the
 * removed column takes the ones at its position with it, and an index left with
 * no columns goes altogether. Before appwrite/appwrite#15471 they were pruned by
 * value, which for repeating lengths dropped every length equal to the removed
 * one.
 */
function deleteColumn(string $key): never
{
    $byValue = pre15471();

    changeIndexes(static function (array $indexes) use ($key, $byValue): array {
        foreach ($indexes as $indexKey => $index) {
            $found = \array_search($key, $index['columns'], true);

            if ($found === false) {
                continue;
            }

            if ($byValue) {
                foreach (['columns', 'lengths', 'orders'] as $list) {
                    $index[$list] = \array_values(\array_diff(
                        $index[$list],
                        isset($index[$list][$found]) ? [$index[$list][$found]] : []
                    ));
                }
            } else {
                foreach (['columns', 'lengths', 'orders'] as $list) {
                    \array_splice($index[$list], $found, 1);
                }
            }

            if ($index['columns'] === []) {
                unset($indexes[$indexKey]);
                continue;
            }

            $indexes[$indexKey] = $index;
        }

        return $indexes;
    });

    respond(204);
}

if (\preg_match('#^/v1/tablesdb/[^/]+/tables/[^/]+/indexes(?:/([^/]+))?$#', $path, $matched) === 1) {
    $key = $matched[1] ?? '';

    if ($method === 'POST' && $key === '') {
        createIndex();
    }

    if ($method === 'GET' && $key === '') {
        $indexes = storedIndexes();

        respond(200, [
            'total' => \count($indexes),
            'indexes' => \array_map(
                static fn (string $indexKey): array => indexDocument($indexKey, $indexes[$indexKey]),
                \array_keys($indexes)
            ),
        ]);
    }

    if ($method === 'GET') {
        $indexes = storedIndexes();

        if (!isset($indexes[$key])) {
            indexNotFound();
        }

        respond(200, indexDocument($key, $indexes[$key]));
    }

    if ($method === 'DELETE' && $key !== '') {
        deleteIndex($key);
    }
}

if (
    $method === 'POST'
    && \preg_match('#^/v1/tablesdb/[^/]+/tables/[^/]+/columns/string$#', $path) === 1
) {
    $body = body();

    respond(202, [
        'key' => (string) ($body['key'] ?? ''),
        'type' => 'string',
        'size' => (int) ($body['size'] ?? 0),
        'status' => 'available',
        'required' => (bool) ($body['required'] ?? false),
    ]);
}

if (
    $method === 'DELETE'
    && \preg_match('#^/v1/tablesdb/[^/]+/tables/[^/]+/columns/([^/]+)$#', $path, $matched) === 1
) {
    deleteColumn($matched[1]);
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
