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
 * The native sign-in route keeps the project's users and identities in the same
 * file and links an identity the way appwrite/appwrite#15300 does: the row goes
 * in first and the ownership of the provider email is read again afterwards, so
 * two requests linking one address can never both come away with it.
 * `STUB_IDENTITY_RACE` lets a competing linker commit in the instant between
 * those two steps, and `STUB_OAUTH_IDENTITY_PRE_15300` replays the
 * check-then-write order the fix replaced, under which the race leaves one
 * address with two owners. See `tests/identity-email.php`.
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
 * Read the stub's state, run `$change` over it and write back what it returns.
 *
 * Everything the stub remembers between requests lives in here: the guess
 * counters below, and the users and identities further down.
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
 * The timestamp every document the stub hands back claims to be from.
 */
const STUB_TIMESTAMP = '2026-01-01T00:00:00.000+00:00';

/**
 * The first of the two users the stub's project already holds. Its account
 * email is not the address its identity reports: the account was created from
 * one address and later moved to another, which is the state that makes the
 * rule below load-bearing. A second provider account reporting
 * `MOVED_PROVIDER_EMAIL` finds no user holding that address, so the server
 * makes a new user for it — and the only thing standing between the two of them
 * is the rule that a provider email belongs to one user's identities.
 */
const MOVED_USER_ID = 'alice';
const MOVED_USER_EMAIL = 'alice@example.com';
const MOVED_PROVIDER_EMAIL = 'shared@example.com';

/**
 * The second, in the ordinary state: the address its identity reports is the
 * address its account is under. Another provider account reporting it links to
 * the same user, which is why the rule cannot be a unique index.
 */
const LINKED_USER_ID = 'bob';
const LINKED_USER_EMAIL = 'linked@example.com';

/**
 * The user a competing linker signs in as under `STUB_IDENTITY_RACE`.
 */
const RACING_USER_ID = 'racer';

/**
 * Does the stub link an identity the way Appwrite did before
 * appwrite/appwrite#15300 — reading the ownership of the provider email only
 * before the insert, and refusing without cleaning up after itself?
 */
function pre15300(): bool
{
    return \in_array(\getenv('STUB_OAUTH_IDENTITY_PRE_15300'), ['1', 'true'], true);
}

/**
 * Does a competing linker commit its own identity for the same provider email
 * in the instant between this request's insert and the read that follows it?
 */
function racing(): bool
{
    return \in_array(\getenv('STUB_IDENTITY_RACE'), ['1', 'true'], true);
}

/**
 * The stored users and identities, seeded on first use.
 *
 * @return array{users: array<string, array<string, mixed>>, identities: array<string, array<string, mixed>>}
 */
function project(): array
{
    $stored = state(static function (array $stored): array {
        if (!isset($stored['users'])) {
            $stored['users'] = [
                MOVED_USER_ID => ['$id' => MOVED_USER_ID, 'email' => MOVED_USER_EMAIL, 'name' => 'Alice'],
                LINKED_USER_ID => ['$id' => LINKED_USER_ID, 'email' => LINKED_USER_EMAIL, 'name' => 'Bob'],
            ];
            $stored['identities'] = [
                'identity-google-google-alice' => [
                    '$id' => 'identity-google-google-alice',
                    'userId' => MOVED_USER_ID,
                    'provider' => 'google',
                    'providerUid' => 'google-alice',
                    'providerEmail' => MOVED_PROVIDER_EMAIL,
                ],
                'identity-google-google-bob' => [
                    '$id' => 'identity-google-google-bob',
                    'userId' => LINKED_USER_ID,
                    'provider' => 'google',
                    'providerUid' => 'google-bob',
                    'providerEmail' => LINKED_USER_EMAIL,
                ],
            ];
        }

        return $stored;
    });

    return [
        'users' => (array) ($stored['users'] ?? []),
        'identities' => (array) ($stored['identities'] ?? []),
    ];
}

/**
 * Run `$change` over the stored users and identities and write back what it
 * returns. Every write the link below makes goes through here one at a time, so
 * each one is committed — and visible to another request — before the next step
 * reads.
 *
 * @param callable(array<string, array<string, mixed>>, array<string, array<string, mixed>>): array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>} $change
 */
function changeProject(callable $change): void
{
    project();

    state(static function (array $stored) use ($change): array {
        [$stored['users'], $stored['identities']] = $change(
            (array) ($stored['users'] ?? []),
            (array) ($stored['identities'] ?? [])
        );

        return $stored;
    });
}

/**
 * The `equal` queries the SDK sends, as `attribute => values`.
 *
 * @return array<string, array<int, mixed>>
 */
function equalQueries(): array
{
    $equal = [];

    foreach ((array) ($_GET['queries'] ?? []) as $query) {
        $decoded = \json_decode((string) $query, true);

        if (\is_array($decoded) && ($decoded['method'] ?? '') === 'equal' && isset($decoded['attribute'])) {
            $equal[(string) $decoded['attribute']] = (array) ($decoded['values'] ?? []);
        }
    }

    return $equal;
}

/**
 * An identity as a response model.
 *
 * @param array<string, mixed> $identity
 * @return array<string, mixed>
 */
function identityDocument(array $identity): array
{
    return $identity + [
        '$createdAt' => STUB_TIMESTAMP,
        '$updatedAt' => STUB_TIMESTAMP,
        'providerAccessToken' => '',
        'providerAccessTokenExpiry' => '',
        'providerRefreshToken' => '',
        'providerIdToken' => '',
    ];
}

/**
 * A user as a response model.
 *
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
function userDocument(array $user): array
{
    return $user + [
        '$createdAt' => STUB_TIMESTAMP,
        '$updatedAt' => STUB_TIMESTAMP,
        'registration' => STUB_TIMESTAMP,
        'accessedAt' => STUB_TIMESTAMP,
        'status' => true,
        'labels' => [],
        'prefs' => [],
        'targets' => [],
    ];
}

/**
 * A session as a response model.
 *
 * @return array<string, mixed>
 */
function sessionDocument(string $userId, string $provider, string $providerUid): array
{
    return [
        '$id' => 'session-' . $userId,
        '$createdAt' => STUB_TIMESTAMP,
        '$updatedAt' => STUB_TIMESTAMP,
        'userId' => $userId,
        'expire' => STUB_TIMESTAMP,
        'provider' => $provider,
        'providerUid' => $providerUid,
        'current' => true,
    ];
}

/**
 * Whether an identity of a user other than `$userId` already holds
 * `$providerEmail`.
 *
 * An identity with no provider email is never in anyone's way: the provider did
 * not report an address, so there is no address to own.
 *
 * @param array<string, array<string, mixed>> $identities
 */
function emailTakenByAnother(array $identities, string $providerEmail, string $userId): bool
{
    if ($providerEmail === '') {
        return false;
    }

    foreach ($identities as $identity) {
        if ($identity['providerEmail'] === $providerEmail && $identity['userId'] !== $userId) {
            return true;
        }
    }

    return false;
}

/**
 * The claims of the id token the caller presented.
 *
 * A real server verifies the token against the provider's keys and reads the
 * claims out of it; the stub only decodes the payload, which is one of the
 * several reasons it is a stub. `email` is taken only when the provider says it
 * verified the address — an unverified one cannot be allowed to claim ownership
 * of anything.
 *
 * @return array{sub: string, email: string}
 */
function idTokenClaims(string $idToken): array
{
    $segments = \explode('.', $idToken);
    $payload = \json_decode(
        \base64_decode(\strtr($segments[1] ?? '', '-_', '+/'), false) ?: '[]',
        true
    );

    if (!\is_array($payload) || ($payload['sub'] ?? '') === '') {
        respond(401, [
            'message' => 'Invalid token passed in the request.',
            'type' => 'user_invalid_token',
            'code' => 401,
        ]);
    }

    return [
        'sub' => (string) $payload['sub'],
        'email' => ($payload['email_verified'] ?? false) === true ? (string) ($payload['email'] ?? '') : '',
    ];
}

/**
 * `POST /v1/account/sessions/id-token`: sign in with a provider id token, and
 * link the provider account to a user the first time that account is seen.
 *
 * This is the client-visible half of appwrite/appwrite#15300. A provider email
 * is only ever held by the identities of a single user. One user may hold
 * several identities reporting the same address, so the rule cannot be a unique
 * index, and reading the ownership before the insert is a time-of-check race:
 * two requests linking the same verified address at the same moment both come
 * up clean and both write. Since the fix the row goes in first and the
 * ownership is read again afterwards, each read landing after its own commit,
 * so two racers can never both come away with the address, and the one that
 * sees a foreign identity drops the row it just wrote. Whichever way the link
 * is refused, the user the request made for it goes too.
 *
 * What the caller gets is `400 general_bad_request`: deliberately the answer an
 * ordinary malformed request gets, so that it discloses nothing about the
 * account that does hold the address.
 */
function createIdTokenSession(): never
{
    $body = body();
    $provider = (string) ($body['provider'] ?? '');
    $claims = idTokenClaims((string) ($body['idToken'] ?? ''));
    ['users' => $users, 'identities' => $identities] = project();

    // A provider account the project already holds an identity for is a
    // sign-in, not a link: there is nothing to check and nothing to write.
    foreach ($identities as $identity) {
        if ($identity['provider'] === $provider && $identity['providerUid'] === $claims['sub']) {
            respond(201, sessionDocument((string) $identity['userId'], $provider, $claims['sub']));
        }
    }

    // The user the session will belong to: an account already holding the
    // verified address, or a new one. A brand new user is what a refused link
    // has to clean up after itself.
    $userId = null;

    foreach ($users as $user) {
        if ($claims['email'] !== '' && ($user['email'] ?? '') === $claims['email']) {
            $userId = (string) $user['$id'];
        }
    }

    $createdUser = $userId === null;

    if ($createdUser) {
        $userId = 'user-' . $claims['sub'];

        changeProject(static function (array $users, array $identities) use ($userId, $claims, $body): array {
            $users[$userId] = [
                '$id' => $userId,
                'email' => $claims['email'],
                'name' => (string) ($body['name'] ?? ''),
            ];

            return [$users, $identities];
        });
    }

    $identityId = 'identity-' . $provider . '-' . $claims['sub'];
    $conflict = null;

    if (emailTakenByAnother(project()['identities'], $claims['email'], $userId)) {
        // The ordinary conflict, refused without writing a row at all.
        $conflict = 'general_bad_request';
    } else {
        changeProject(static function (array $users, array $identities) use ($identityId, $userId, $provider, $claims): array {
            $identities[$identityId] = [
                '$id' => $identityId,
                'userId' => $userId,
                'provider' => $provider,
                'providerUid' => $claims['sub'],
                'providerEmail' => $claims['email'],
            ];

            return [$users, $identities];
        });

        // Another request links the same verified address, and its insert
        // commits here: after this request's check came up clean, and after the
        // row above went in.
        if (racing() && $claims['email'] !== '') {
            changeProject(static function (array $users, array $identities) use ($claims): array {
                $users[RACING_USER_ID] ??= ['$id' => RACING_USER_ID, 'email' => '', 'name' => 'Racer'];
                $identities['identity-apple-apple-racer'] ??= [
                    '$id' => 'identity-apple-apple-racer',
                    'userId' => RACING_USER_ID,
                    'provider' => 'apple',
                    'providerUid' => 'apple-racer',
                    'providerEmail' => $claims['email'],
                ];

                return [$users, $identities];
            });
        }

        // Reading the ownership again is what the fix added. Before it, a link
        // that had lost the race was committed and nobody ever looked.
        if (!pre15300() && emailTakenByAnother(project()['identities'], $claims['email'], $userId)) {
            changeProject(static function (array $users, array $identities) use ($identityId): array {
                unset($identities[$identityId]);

                return [$users, $identities];
            });

            $conflict = 'general_bad_request';
        }
    }

    if ($conflict !== null) {
        // A request whose link did not hold must not leave behind the user it
        // just made for it. Before the fix only the duplicate-identity refusal
        // came through here, so an email conflict left that user in the project
        // — signed into by nobody, and holding the address in the users list.
        if ($createdUser && !pre15300()) {
            changeProject(static function (array $users, array $identities) use ($userId): array {
                unset($users[$userId]);

                return [$users, $identities];
            });
        }

        respond(400, [
            // Generic on purpose: naming the conflict would disclose that
            // another account holds the address.
            'message' => 'There was an error processing your request. Please check the inputs and try again.',
            'type' => $conflict,
            'code' => 400,
        ]);
    }

    respond(201, sessionDocument($userId, $provider, $claims['sub']));
}

if ($method === 'POST' && $path === '/v1/account/sessions/id-token') {
    createIdTokenSession();
}

/**
 * `GET /v1/users/identities`: the project's identities, which is how a caller
 * reads who holds a provider email — and, after a refused link, that nothing
 * was left behind.
 */
if ($method === 'GET' && $path === '/v1/users/identities') {
    $equal = equalQueries();
    $matching = \array_values(\array_filter(
        project()['identities'],
        static function (array $identity) use ($equal): bool {
            foreach ($equal as $attribute => $values) {
                if (!\in_array($identity[$attribute] ?? null, $values, true)) {
                    return false;
                }
            }

            return true;
        }
    ));

    respond(200, [
        'total' => \in_array($_GET['total'] ?? '1', ['0', 'false'], true) ? 0 : \count($matching),
        'identities' => \array_map('identityDocument', $matching),
    ]);
}

if ($method === 'GET' && matchesPattern('/v1/users/*', $path)) {
    $user = project()['users'][\basename($path)] ?? null;

    if ($user === null) {
        respond(404, [
            'message' => 'User with the requested ID could not be found.',
            'type' => 'user_not_found',
            'code' => 404,
        ]);
    }

    respond(200, userDocument($user));
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
