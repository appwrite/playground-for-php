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
 * The table routes keep their tables in the same file and weigh the columns a
 * create asks for the way an SQL adapter does: a count against `COLUMN_LIMIT`
 * and a row width against `ROW_SIZE_LIMIT`. Past either one the create is
 * refused with the limit it actually hit and the library's explanation of it,
 * which is what appwrite/appwrite#15317 fixed; `STUB_COLUMN_LIMIT_PRE_15317`
 * asks for the answer it used to give, a limit on the number of tables in the
 * database. See `tests/column-limit.php`.
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
 * The row a table's columns have to fit into, in bytes: the `documentSize`
 * limit every SQL adapter in utopia-php/database declares.
 */
const ROW_SIZE_LIMIT = 65535;

/**
 * What the internal columns (`_id`, `_uid`, `_tenant`, `_createdAt`,
 * `_updatedAt`, `_permissions`) already spend of that row before the caller's
 * first column.
 */
const ROW_SIZE_INTERNAL = 1067;

/**
 * Longest string a column stores inside the row. Anything longer becomes a
 * `TEXT` kept outside it, so only a 20-byte pointer counts against the row —
 * which is why a wide schema is not the same thing as a long one.
 */
const VARCHAR_LIMIT = 16381;

/**
 * Columns a table can hold, the internal ones included: the adapter's
 * `attributes` limit.
 */
const COLUMN_LIMIT = 1017;

/**
 * Internal columns that count against `COLUMN_LIMIT` alongside the caller's.
 */
const INTERNAL_COLUMNS = 6;

/**
 * The timestamp every document the stub hands back claims to be from.
 */
const STUB_TIMESTAMP = '2026-01-01T00:00:00.000+00:00';

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
 * counters below, and the tables further down.
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
 * The id every table the stub creates comes back with, whatever id the create
 * asked for. Fixed ids are what keep the paths the tests expect determined.
 */
const TABLE_ID = 'playground-table';

/**
 * Does the stub answer a schema that does not fit the way Appwrite did before
 * appwrite/appwrite#15317?
 */
function pre15317(): bool
{
    return \in_array(\getenv('STUB_COLUMN_LIMIT_PRE_15317'), ['1', 'true'], true);
}

/**
 * The stored tables, keyed by table id.
 *
 * @return array<string, array<string, mixed>>
 */
function storedTables(): array
{
    return (array) (state(static fn (array $stored): array => $stored)['tables'] ?? []);
}

/**
 * Run `$change` over the stored tables and write back what it returns.
 *
 * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $change
 */
function changeTables(callable $change): void
{
    state(static function (array $stored) use ($change): array {
        $stored['tables'] = $change((array) ($stored['tables'] ?? []));

        return $stored;
    });
}

/**
 * What one column spends of the row, the way an SQL adapter weighs it.
 *
 * A string is the interesting case: up to `VARCHAR_LIMIT` characters it lives
 * in the row and costs four bytes per character, and past that it becomes a
 * `TEXT` stored outside the row that only costs a pointer. So the columns that
 * fill up a row are the mid-sized ones, not the largest.
 *
 * @param array<string, mixed> $column
 */
function columnWidth(array $column): int
{
    $size = (int) ($column['size'] ?? 0);

    // An array column is JSON kept outside the row; only its pointer counts.
    if (($column['array'] ?? false) === true) {
        return 20;
    }

    return match ((string) ($column['type'] ?? '')) {
        'string' => match (true) {
            $size > VARCHAR_LIMIT => 20,
            $size > 255 => $size * 4 + 2,
            default => $size * 4 + 1,
        },
        'integer' => $size >= 8 ? 8 : 4,
        'double' => 8,
        'boolean' => 1,
        'datetime' => 7,
        default => 20,
    };
}

/**
 * A table as a response model.
 *
 * `bytesUsed` and `bytesMax` are how much of the row the columns spend and how
 * much there is to spend, which is the same arithmetic the refusal below is
 * about — read from a table that exists rather than from an error.
 *
 * @param array<string, mixed> $table
 * @return array<string, mixed>
 */
function tableDocument(array $table): array
{
    return [
        '$id' => TABLE_ID,
        '$createdAt' => STUB_TIMESTAMP,
        '$updatedAt' => STUB_TIMESTAMP,
        '$permissions' => $table['permissions'],
        'databaseId' => $table['databaseId'],
        'name' => $table['name'],
        'description' => '',
        'enabled' => true,
        'rowSecurity' => false,
        'columns' => $table['columns'],
        'indexes' => [],
        'bytesMax' => ROW_SIZE_LIMIT,
        'bytesUsed' => $table['bytesUsed'],
    ];
}

/**
 * Refuse a create whose own columns do not fit, and say which limit they hit.
 *
 * Since appwrite/appwrite#15317 the type names the columns — the only thing the
 * `Limit` the library raises here is ever about — and the message is the
 * library's own, which names the limit and what passed it. Before it, both were
 * replaced by a limit on the number of tables a database may hold: a limit that
 * does not exist, described in a way that sent the caller asking for a bigger
 * quota instead of a narrower table. The id it named was the table's, fed into
 * a template documented as a database's.
 */
function columnLimitExceeded(string $cause): never
{
    if (pre15317()) {
        respond(400, [
            'message' => "The maximum number of tables for database '" . TABLE_ID . "' has been reached.",
            'type' => 'table_limit_exceeded',
            'code' => 400,
        ]);
    }

    respond(400, [
        'message' => $cause,
        // `attribute_limit_exceeded` on the deprecated Databases API.
        'type' => 'column_limit_exceeded',
        'code' => 400,
    ]);
}

/**
 * `POST /v1/tablesdb/{databaseId}/tables`: create a table, columns and all.
 *
 * The limits are checked in the order the library checks them, and both are
 * about the columns of the one table being created: how many there are, and how
 * wide the row they add up to is. Neither is a limit on how many tables the
 * database holds — there is no such limit.
 *
 * A create that is refused leaves nothing behind: the table the server had
 * already written is rolled back, so the same id is free for the retry with a
 * schema that fits.
 */
function createTable(string $databaseId): never
{
    $body = body();
    $columns = \array_values((array) ($body['columns'] ?? []));

    if (\count($columns) + INTERNAL_COLUMNS > COLUMN_LIMIT) {
        columnLimitExceeded('Attribute limit of ' . COLUMN_LIMIT . ' exceeded. Cannot create collection.');
    }

    $bytesUsed = \array_sum(\array_map('columnWidth', $columns)) + ROW_SIZE_INTERNAL;

    if ($bytesUsed > ROW_SIZE_LIMIT) {
        columnLimitExceeded('Document size limit of ' . ROW_SIZE_LIMIT . ' exceeded. Cannot create collection.');
    }

    if (isset(storedTables()[TABLE_ID])) {
        respond(409, [
            'message' => "A table with the requested ID '" . TABLE_ID . "' already exists."
                . ' Try again with a different ID or use ID.unique() to generate a unique ID.',
            'type' => 'table_already_exists',
            'code' => 409,
        ]);
    }

    $table = [
        'databaseId' => $databaseId,
        'name' => (string) ($body['name'] ?? ''),
        'permissions' => \array_values((array) ($body['permissions'] ?? [])),
        'columns' => \array_map(
            static fn (array $column): array => $column + ['status' => 'available', 'error' => ''],
            $columns
        ),
        'bytesUsed' => $bytesUsed,
    ];

    changeTables(static function (array $tables) use ($table): array {
        $tables[TABLE_ID] = $table;

        return $tables;
    });

    respond(201, tableDocument($table));
}

function tableNotFound(string $tableId): never
{
    respond(404, [
        'message' => "Table with the requested ID '{$tableId}' could not be found.",
        'type' => 'table_not_found',
        'code' => 404,
    ]);
}

if ($method === 'POST' && matchesPattern('/v1/tablesdb/*/tables', $path)) {
    createTable(\explode('/', $path)[3]);
}

if ($method === 'GET' && matchesPattern('/v1/tablesdb/*/tables', $path)) {
    $tables = storedTables();

    respond(200, [
        'total' => \count($tables),
        'tables' => \array_map('tableDocument', \array_values($tables)),
    ]);
}

if ($method === 'GET' && matchesPattern('/v1/tablesdb/*/tables/*', $path)) {
    $tableId = \basename($path);
    $table = storedTables()[$tableId] ?? null;

    if ($table === null) {
        tableNotFound($tableId);
    }

    respond(200, tableDocument($table));
}

if ($method === 'DELETE' && matchesPattern('/v1/tablesdb/*/tables/*', $path)) {
    $tableId = \basename($path);

    if (!isset(storedTables()[$tableId])) {
        tableNotFound($tableId);
    }

    changeTables(static function (array $tables) use ($tableId): array {
        unset($tables[$tableId]);

        return $tables;
    });

    respond(204);
}

/**
 * @var array<int, array{0: string, 1: string, 2: int, 3: array<string, mixed>|null}>
 *      [method, path pattern, status, body] — `*` matches one path segment.
 */
$routes = [
    ['POST', '/v1/tablesdb', 201, ['$id' => 'playground-db', 'name' => 'Test Database']],
    ['DELETE', '/v1/tablesdb/*', 204, null],
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
