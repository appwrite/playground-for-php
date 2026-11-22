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
 * The routes that confirm a secret are the ones with state behind them. They
 * count wrong guesses per project, route and user in the file named by
 * `STUB_STATE`, the way the server keys those abuse limits, and answer `429`
 * once a user's share of attempts is spent. The count is kept for every caller,
 * an API key holder included, which is what appwrite/appwrite#15470 fixed. See
 * `tests/code-guesses.php`.
 *
 * The project's web platforms live in the same file, seeded the way the console
 * seeds a new project: one `localhost` platform since appwrite/appwrite#15509,
 * or the wildcard a project created before it still carries when
 * `STUB_LEGACY_WILDCARD=1`. Every request is then held to the check Appwrite's
 * init runs — a state-changing request that carries no API key has to come from
 * an origin one of those platforms names. See `tests/web-platforms.php`.
 *
 * Env vars: `STUB_LOG`, `STUB_STATE`, `STUB_LEGACY_WILDCARD`,
 * `STUB_NO_PLATFORM_SCOPE` (answer the platform routes `401`, the way a key
 * without the `platforms.read` and `platforms.write` scopes is answered).
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
 * The web platform the console seeds a new project with since
 * appwrite/appwrite#15509: the hostname a developer runs their app on while
 * building it, and no other origin.
 */
const SEEDED_PLATFORMS = [
    ['$id' => 'local-development', 'name' => 'Local development', 'type' => 'web', 'hostname' => 'localhost'],
];

/**
 * What a project created before that fix still carries, because nothing
 * migrates it: the wildcard the console used to seed, which trusts every
 * origin, and the prefix form of it that the console's warning does not catch.
 */
const LEGACY_PLATFORMS = [
    ['$id' => 'any-origin', 'name' => 'Web app', 'type' => 'web', 'hostname' => '*'],
    ['$id' => 'any-subdomain', 'name' => 'Web app (subdomains)', 'type' => 'web', 'hostname' => '*.example.com'],
];

/**
 * The methods Appwrite holds to the origin check: the ones that can change
 * something, so that a page in a browser cannot act as the signed-in user.
 */
const GUARDED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

$method = $_SERVER['REQUEST_METHOD'];
$path = \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$project = $_SERVER['HTTP_X_APPWRITE_PROJECT'] ?? '';
$keyed = ($_SERVER['HTTP_X_APPWRITE_KEY'] ?? '') !== '';

/**
 * A browser sends the page's origin on a cross-site request; no server SDK
 * sends one, which is the whole reason the platform list does not constrain
 * them.
 */
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

$log = \getenv('STUB_LOG');
if (\is_string($log) && $log !== '') {
    \file_put_contents(
        $log,
        \json_encode([
            'method' => $method,
            'path' => $path,
            'project' => $project,
            // Whether the caller held an API key, which used to exempt it from
            // the limits on the routes below, and still exempts it from the
            // origin check.
            'keyed' => $keyed,
            'origin' => $origin,
            // The JSON body, or an empty array for a request without one. A
            // multipart upload does not decode, which is all the tests need.
            'body' => body(),
        ], JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
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
 * Read the stub's state, run `$change` over it and write back what it returns,
 * holding the file for the whole of it so concurrent requests cannot interleave.
 *
 * @param callable(array<string, mixed>): array<string, mixed> $change
 * @return array<string, mixed> the state after the change
 */
function state(callable $change): array
{
    $file = \getenv('STUB_STATE');

    if (!\is_string($file) || $file === '') {
        return $change([]);
    }

    $handle = \fopen($file, 'c+');

    if ($handle === false) {
        respond(500, ['message' => 'Stub could not open its state', 'type' => 'general_unknown', 'code' => 500]);
    }

    \flock($handle, LOCK_EX);
    $state = $change(\json_decode(\stream_get_contents($handle) ?: '{}', true) ?: []);
    \ftruncate($handle, 0);
    \rewind($handle);
    \fwrite($handle, (string) \json_encode($state));
    \fflush($handle);
    \flock($handle, LOCK_UN);
    \fclose($handle);

    return $state;
}

/**
 * The wrong-guess counters, keyed by project, route and user.
 *
 * Nothing here looks at whether the caller holds an API key: the limits on the
 * routes that confirm a secret count for every caller.
 *
 * @param callable(array<string, int>): array<string, int> $change
 * @return array<string, int> the counters after the change
 */
function counters(callable $change): array
{
    return state(static function (array $state) use ($change): array {
        $state['counters'] = $change($state['counters'] ?? []);

        return $state;
    })['counters'] ?? [];
}

function spent(string $counter): int
{
    return counters(static fn (array $counts): array => $counts)[$counter] ?? 0;
}

/**
 * The project's platforms, seeded on first read the way the console seeds them.
 *
 * @param null|callable(list<array<string, string>>): list<array<string, string>> $change
 * @return list<array<string, string>> the platforms after the change
 */
function platforms(?callable $change = null): array
{
    return state(static function (array $state) use ($change): array {
        $state['platforms'] ??= \getenv('STUB_LEGACY_WILDCARD') === '1'
            ? LEGACY_PLATFORMS
            : SEEDED_PLATFORMS;

        if ($change !== null) {
            $state['platforms'] = $change($state['platforms']);
        }

        return $state;
    })['platforms'] ?? [];
}

/**
 * Is `$origin` one the project's platforms names?
 *
 * An empty origin is every server SDK, which sends no `Origin` header at all:
 * not a browser, so the platform list has nothing to say about it. A `*`
 * hostname is the wildcard the console used to seed, and matches anything.
 */
function originAllowed(string $origin): bool
{
    if ($origin === '') {
        return true;
    }

    $host = (string) (\parse_url($origin, PHP_URL_HOST) ?: '');

    foreach (platforms() as $platform) {
        if (($platform['type'] ?? '') !== 'web') {
            continue;
        }

        $hostname = (string) ($platform['hostname'] ?? '');

        if ($hostname === '*' || ($hostname !== '' && $hostname === $host)) {
            return true;
        }

        if (\str_starts_with($hostname, '*.') && \str_ends_with($host, \substr($hostname, 1))) {
            return true;
        }
    }

    return false;
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

/**
 * The check Appwrite's init runs before a route sees the request: a
 * state-changing request that carries no API key has to come from an origin one
 * of the project's platforms names, or it is refused `403`.
 *
 * A key holder is exempt. A key is not something a browser attaches to a
 * drive-by request, so the origin tells the server nothing it needs; a session
 * cookie is exactly that, which is why a project trusting `*` let any page a
 * signed-in user visited act as them. appwrite/appwrite#15509 stopped seeding
 * that wildcard.
 */
if (!$keyed && \in_array($method, GUARDED_METHODS, true) && !originAllowed($origin)) {
    respond(403, [
        'message' => "Invalid Origin. Register your new client ({$origin}) as a new Web platform on your project console dashboard",
        'type' => 'general_unknown_origin',
        'code' => 403,
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
 * The platform routes of the project service. They answer `401` when
 * `STUB_NO_PLATFORM_SCOPE=1`, the way a project API key without the
 * `platforms.read` or `platforms.write` scope is answered.
 */
if (\str_starts_with($path, '/v1/project/platforms')) {
    if (\getenv('STUB_NO_PLATFORM_SCOPE') === '1') {
        respond(401, [
            'message' => 'app.' . $project . '@service.local (role: applications) missing scope (platforms.write)',
            'type' => 'general_unauthorized_scope',
            'code' => 401,
        ]);
    }

    if ($method === 'GET' && $path === '/v1/project/platforms') {
        $registered = platforms();

        respond(200, ['total' => \count($registered), 'platforms' => $registered]);
    }

    if ($method === 'POST' && $path === '/v1/project/platforms/web') {
        $body = body();
        $platform = [
            '$id' => (string) ($body['platformId'] ?? 'playground-platform'),
            '$createdAt' => '2026-11-22T00:00:00.000+00:00',
            '$updatedAt' => '2026-11-22T00:00:00.000+00:00',
            'name' => (string) ($body['name'] ?? ''),
            'type' => 'web',
            'hostname' => (string) ($body['hostname'] ?? ''),
        ];

        // The server does not refuse a hostname that is already registered, so
        // neither does this; a caller that does not want duplicates reads the
        // list first, the way the playground does.
        platforms(static function (array $registered) use ($platform): array {
            $registered[] = $platform;

            return $registered;
        });

        respond(201, $platform);
    }

    if ($method === 'DELETE' && \preg_match('#^/v1/project/platforms/([^/]+)$#', $path, $matches) === 1) {
        $found = false;

        platforms(static function (array $registered) use ($matches, &$found): array {
            $kept = \array_values(\array_filter(
                $registered,
                static fn (array $platform): bool => ($platform['$id'] ?? '') !== $matches[1]
            ));
            $found = \count($kept) !== \count($registered);

            return $kept;
        });

        if (!$found) {
            respond(404, [
                'message' => 'Platform with the requested ID could not be found.',
                'type' => 'platform_not_found',
                'code' => 404,
            ]);
        }

        respond(204, null);
    }
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

    // A write a caller makes as the user rather than with a key, which is the
    // kind of request the origin check above is there for.
    ['PATCH', '/v1/account/name', 200, ['$id' => 'playground-user', 'name' => 'Renamed', 'email' => 'email@example.com', 'prefs' => []]],
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
