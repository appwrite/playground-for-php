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
 * The provider sign-in route keeps the project's accounts, sessions and tokens
 * in the same file and claims an unverified account the way
 * appwrite/appwrite#15282 does: the account is read again under the state lock
 * and the revoke committed with the `emailVerification` write, so a claim that
 * lost the race finds the address already proven and leaves the winner's
 * sessions alone. `STUB_CLAIM_RACE` lets a second claim commit in the instant
 * before this one, `STUB_CLAIM_SESSION_RACE` has the registrant sign in while
 * the claim is in flight, and `STUB_OAUTH_CLAIM_PRE_15282` replays the
 * unlocked claim the fix replaced, under which both races leave the registrant
 * a way in. See `tests/account-claim.php`.
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
 * The account the claim below is about: registered with a password, under an
 * address whoever registered it never proved. Signing in with a provider that
 * does prove the address claims this account for the person holding it, which
 * is the step appwrite/appwrite#15282 made atomic.
 */
const REGISTRANT_ID = 'registrant';
const REGISTRANT_EMAIL = 'claimed@example.com';
const REGISTRANT_PASSWORD = 'registrant-password';

/**
 * The sessions the registrant signed in with before the claim, and the token it
 * had outstanding: the other ways into the account, all of which the claim
 * revokes. The token's secret is the stub's one valid secret, so it exchanges
 * for a session right up until the claim takes it away.
 */
const REGISTRANT_SESSIONS = ['session-registrant-web', 'session-registrant-cli'];
const REGISTRANT_TOKEN = VALID_SECRET;

/**
 * An account under an address that is already proven. A provider proving it
 * again signs in and claims nothing: there is no unverified registrant here,
 * and revoking its password would log out someone who owns the address.
 */
const VERIFIED_ID = 'verified';
const VERIFIED_EMAIL = 'verified@example.com';
const VERIFIED_PASSWORD = 'verified-password';
const VERIFIED_SESSION = 'session-verified-web';

/**
 * The session a competing claim leaves behind under `STUB_CLAIM_RACE`: the one
 * a second revoke would sweep.
 */
const RIVAL_SESSION = 'session-rival';

/**
 * The session the registrant creates for itself mid-claim under
 * `STUB_CLAIM_SESSION_RACE`.
 */
const MIDCLAIM_SESSION = 'session-registrant-midclaim';

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
 * counters below, and the accounts, sessions and tokens further down.
 *
 * The file lock is held across the read and the write, so a `$change` that
 * reads a value and writes it back cannot be interleaved with another request
 * doing the same — which is what makes it a stand-in for a transaction with the
 * row locked for update.
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
 * A secret the project no longer holds is a wrong secret: that is how a token
 * revoked by the claim further down stops working, and it is answered the same
 * `401` an expired or mistyped one gets.
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

    if ($secret === VALID_SECRET && secretStillHeld($userId, $secret)) {
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
 * Does the stub claim an account the way the OAuth flows did before
 * appwrite/appwrite#15282 — deciding on the copy of the account the request had
 * already read, with no lock and no transaction of its own around the revoke?
 */
function pre15282(): bool
{
    return \in_array(\getenv('STUB_OAUTH_CLAIM_PRE_15282'), ['1', 'true'], true);
}

/**
 * Does a second claim of the same address commit in the instant between this
 * request reading the account and claiming it?
 */
function claimRacing(): bool
{
    return \in_array(\getenv('STUB_CLAIM_RACE'), ['1', 'true'], true);
}

/**
 * Does the registrant create a session for itself while the claim is in
 * flight?
 */
function sessionRacing(): bool
{
    return \in_array(\getenv('STUB_CLAIM_SESSION_RACE'), ['1', 'true'], true);
}

/**
 * The stored accounts, sessions and tokens, seeded on first use.
 *
 * @return array{accounts: array<string, array<string, mixed>>, sessions: array<string, array<string, mixed>>, tokens: array<string, array<string, mixed>>}
 */
function project(): array
{
    $stored = state(static function (array $stored): array {
        if (!isset($stored['accounts'])) {
            $stored['accounts'] = [
                REGISTRANT_ID => [
                    '$id' => REGISTRANT_ID,
                    'email' => REGISTRANT_EMAIL,
                    'name' => 'Registrant',
                    'password' => REGISTRANT_PASSWORD,
                    'emailVerification' => false,
                ],
                VERIFIED_ID => [
                    '$id' => VERIFIED_ID,
                    'email' => VERIFIED_EMAIL,
                    'name' => 'Verified',
                    'password' => VERIFIED_PASSWORD,
                    'emailVerification' => true,
                ],
            ];
            $stored['sessions'] = [
                REGISTRANT_SESSIONS[0] => ['$id' => REGISTRANT_SESSIONS[0], 'userId' => REGISTRANT_ID, 'provider' => 'email'],
                REGISTRANT_SESSIONS[1] => ['$id' => REGISTRANT_SESSIONS[1], 'userId' => REGISTRANT_ID, 'provider' => 'token'],
                VERIFIED_SESSION => ['$id' => VERIFIED_SESSION, 'userId' => VERIFIED_ID, 'provider' => 'email'],
            ];
            $stored['tokens'] = [
                REGISTRANT_TOKEN => ['$id' => 'token-registrant', 'userId' => REGISTRANT_ID, 'secret' => REGISTRANT_TOKEN],
            ];
        }

        return $stored;
    });

    return [
        'accounts' => (array) ($stored['accounts'] ?? []),
        'sessions' => (array) ($stored['sessions'] ?? []),
        'tokens' => (array) ($stored['tokens'] ?? []),
    ];
}

/**
 * Run `$change` over the stored accounts, sessions and tokens and write back
 * what it returns, with the state locked for as long as it runs. One call is
 * one transaction: everything it changes is committed together, and nothing
 * another request does can land in the middle of it.
 *
 * @param callable(array<string, array<string, mixed>>, array<string, array<string, mixed>>, array<string, array<string, mixed>>): array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: array<string, array<string, mixed>>} $change
 */
function changeProject(callable $change): void
{
    project();

    state(static function (array $stored) use ($change): array {
        [$stored['accounts'], $stored['sessions'], $stored['tokens']] = $change(
            (array) ($stored['accounts'] ?? []),
            (array) ($stored['sessions'] ?? []),
            (array) ($stored['tokens'] ?? [])
        );

        return $stored;
    });
}

/**
 * Whether a secret the caller sends is still one the project holds for the user
 * it names.
 *
 * Only the accounts in the fixture above have their tokens tracked; any other
 * user has the stub's one valid secret and nothing takes it away. That is what
 * makes a token revoked by the claim answer `401` on the exchange while the
 * token `tests/code-guesses.php` guesses at keeps behaving as it always did.
 */
function secretStillHeld(string $userId, string $secret): bool
{
    $project = project();

    if (!isset($project['accounts'][$userId])) {
        return true;
    }

    foreach ($project['tokens'] as $token) {
        if ($token['userId'] === $userId && $token['secret'] === $secret) {
            return true;
        }
    }

    return false;
}

/**
 * An account as the `User` response model.
 *
 * A real server hands an API key holder the password *hash*; what matters to a
 * caller either way is whether a password is set at all, which is what the
 * claim's revoke takes away.
 *
 * @param array<string, mixed> $account
 * @return array<string, mixed>
 */
function accountDocument(array $account): array
{
    return $account + [
        '$createdAt' => STUB_TIMESTAMP,
        '$updatedAt' => STUB_TIMESTAMP,
        'registration' => STUB_TIMESTAMP,
        'accessedAt' => STUB_TIMESTAMP,
        'passwordUpdate' => STUB_TIMESTAMP,
        'phone' => '',
        'phoneVerification' => false,
        'mfa' => false,
        'status' => true,
        'labels' => [],
        'prefs' => [],
        'targets' => [],
    ];
}

/**
 * A session as the `Session` response model.
 *
 * `factors` carries what the session was proven with. A provider sign-in
 * carries `oauth2`, which is why the claim in the OAuth flows deliberately has
 * no "skip this when the account has MFA on" guard: skipping would let the
 * claimant in on that factor alone while leaving the registrant its password
 * and its second factor.
 *
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function sessionDocument(array $session): array
{
    return $session + [
        '$createdAt' => STUB_TIMESTAMP,
        '$updatedAt' => STUB_TIMESTAMP,
        'expire' => STUB_TIMESTAMP,
        'providerUid' => '',
        'current' => true,
        'factors' => [
            \in_array($session['provider'], ['google', 'apple'], true)
                ? 'oauth2'
                : (string) $session['provider'],
        ],
    ];
}

/**
 * The claims of the id token the caller presented.
 *
 * A real server verifies the token against the provider's keys and reads the
 * claims out of it; the stub only decodes the payload, which is one of the
 * several reasons it is a stub. `email` is taken only when the provider says it
 * verified the address — an unverified one cannot be allowed to claim ownership
 * of anything, least of all somebody else's account.
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
 * Add a session for a user, the way every sign-in route ends.
 *
 * @param array<string, mixed> $session
 */
function addSession(array $session): void
{
    changeProject(static function (array $accounts, array $sessions, array $tokens) use ($session): array {
        $sessions[(string) $session['$id']] = $session;

        return [$accounts, $sessions, $tokens];
    });
}

/**
 * A second claim of the same address, arriving while this one is in flight and
 * getting there first: it revokes the registrant's credentials, proves the
 * address and signs its claimant in. Everything it leaves behind is what a
 * second revoke would destroy.
 */
function competingClaim(string $userId): void
{
    changeProject(static function (array $accounts, array $sessions, array $tokens) use ($userId): array {
        $accounts[$userId] = ['password' => '', 'emailVerification' => true] + $accounts[$userId];

        foreach ($sessions as $id => $session) {
            if ($session['userId'] === $userId) {
                unset($sessions[$id]);
            }
        }

        foreach ($tokens as $secret => $token) {
            if ($token['userId'] === $userId) {
                unset($tokens[$secret]);
            }
        }

        $sessions[RIVAL_SESSION] = ['$id' => RIVAL_SESSION, 'userId' => $userId, 'provider' => 'apple'];

        return [$accounts, $sessions, $tokens];
    });
}

/**
 * A session the registrant signs in and creates for itself with the password it
 * still has, concurrently with the claim.
 */
function addRegistrantSession(string $userId): void
{
    addSession(['$id' => MIDCLAIM_SESSION, 'userId' => $userId, 'provider' => 'email']);
}

/**
 * Claim an account for the owner of an address a provider has just proved: take
 * away every other way in, and record the address as proven.
 *
 * This is the client-visible half of appwrite/appwrite#15282. The account being
 * claimed was registered under the address by somebody who never proved it, so
 * once the real owner turns up with a provider that vouches for the address,
 * the registrant's password, sessions and tokens go. The flows read the account
 * first and decided from that copy, then called a revoke that transacted only
 * its own deletes — so nothing held the account still in between, and two
 * things went wrong:
 *
 * - a token or session the registrant created meanwhile could commit after the
 *   revoke had scanned for what to delete and survive it, leaving the
 *   registrant a way into an account the proven owner had just claimed;
 * - two claims of one address could both decide from a copy saying the address
 *   was unproven, and the one that got there second swept the sessions the
 *   first had already created — then wrote its copy of the account back,
 *   restoring the password the first had revoked.
 *
 * Since the fix the claim is one transaction with the account read again under
 * `forUpdate`, the unproven check made on *that* copy, and the revoke and the
 * `emailVerification` write committed together.
 *
 * Both flows go on to purge the account's cache, revoke its MQTT credentials
 * and fire a realtime `sessions.delete`, gated on the claim having actually
 * revoked something. The stub has none of those to do, so it keeps nothing of
 * that answer — what the tests read is the state the claim left.
 *
 * @param array<string, mixed> $snapshot the account as the request read it
 */
function claimAccount(array $snapshot): void
{
    // Both flows still decide whether to claim at all from the copy they read,
    // which is where this request's own `findOne` left it. What the fix changed
    // is that the claim no longer trusts that copy.
    if ($snapshot['emailVerification'] === true) {
        return;
    }

    if (claimRacing()) {
        competingClaim((string) $snapshot['$id']);
    }

    if (pre15282()) {
        claimOnTheSnapshot($snapshot);

        return;
    }

    claimUnderTheLock($snapshot);
}

/**
 * @param array<string, mixed> $snapshot
 */
function claimUnderTheLock(array $snapshot): void
{
    $userId = (string) $snapshot['$id'];

    // The registrant's concurrent sign-in, committed before the claim takes the
    // lock: the only place it can land now. The claim's scan and its commit are
    // one locked step, so an insert either beats the lock, as this one does, and
    // is swept with the rest — or lands after the claim committed, by which
    // time the password it would have needed is gone.
    if (sessionRacing()) {
        addRegistrantSession($userId);
    }

    changeProject(static function (array $accounts, array $sessions, array $tokens) use ($userId): array {
        $account = $accounts[$userId] ?? null;

        // Re-read under the lock and re-checked here rather than on the
        // caller's copy. A claim that committed since this request read the
        // account has already taken everything away; revoking a second time
        // would only sweep what that claim went on to create.
        if ($account === null || $account['emailVerification'] === true) {
            return [$accounts, $sessions, $tokens];
        }

        $accounts[$userId] = ['password' => '', 'emailVerification' => true] + $account;

        foreach ($sessions as $id => $session) {
            if ($session['userId'] === $userId) {
                unset($sessions[$id]);
            }
        }

        foreach ($tokens as $secret => $token) {
            if ($token['userId'] === $userId) {
                unset($tokens[$secret]);
            }
        }

        return [$accounts, $sessions, $tokens];
    });
}

/**
 * The claim as it was before appwrite/appwrite#15282, replayed so the tests can
 * show what the fix is for.
 *
 * @param array<string, mixed> $snapshot
 */
function claimOnTheSnapshot(array $snapshot): void
{
    $userId = (string) $snapshot['$id'];

    // No check of its own: the caller's copy said the address was unproven, and
    // another claim committing since it was read changes nothing here.
    //
    // The revoke scanned for what to delete in a transaction of its own, so the
    // scan and the delete were a step another writer could get inside.
    $doomed = ['sessions' => [], 'tokens' => []];

    foreach (project()['sessions'] as $id => $session) {
        if ($session['userId'] === $userId) {
            $doomed['sessions'][] = $id;
        }
    }

    foreach (project()['tokens'] as $secret => $token) {
        if ($token['userId'] === $userId) {
            $doomed['tokens'][] = $secret;
        }
    }

    if (sessionRacing()) {
        addRegistrantSession($userId);
    }

    changeProject(static function (array $accounts, array $sessions, array $tokens) use ($doomed): array {
        foreach ($doomed['sessions'] as $id) {
            unset($sessions[$id]);
        }

        foreach ($doomed['tokens'] as $secret) {
            unset($tokens[$secret]);
        }

        return [$accounts, $sessions, $tokens];
    });

    // The proven flag was only set on the request's own copy, which both OAuth
    // flows go on to persist as a whole document — password and all. A claim
    // that lost the race wrote the registrant's credentials back over the
    // winner's revocation.
    changeProject(static function (array $accounts, array $sessions, array $tokens) use ($userId, $snapshot): array {
        $accounts[$userId] = ['emailVerification' => true] + $snapshot;

        return [$accounts, $sessions, $tokens];
    });
}

/**
 * `POST /v1/account/sessions/id-token`: sign in with the id token a provider
 * gave the client.
 *
 * The account the session belongs to is the one holding the verified address
 * the token carries, or a new one when no account holds it. An account that was
 * registered under the address but never proved it is claimed on the way
 * through — see `claimAccount()`.
 *
 * The session is created after the claim committed, which is where the real
 * flows create theirs too: hoisting the insert into the claim's transaction
 * would mean holding the account's lock across the provider's HTTP calls.
 */
function createIdTokenSession(): never
{
    $body = body();
    $provider = (string) ($body['provider'] ?? '');
    $claims = idTokenClaims((string) ($body['idToken'] ?? ''));
    $account = null;

    foreach (project()['accounts'] as $stored) {
        if ($claims['email'] !== '' && $stored['email'] === $claims['email']) {
            $account = $stored;
        }
    }

    if ($account === null) {
        // Nobody holds the address, so the sign-in gets an account of its own:
        // proven by the provider from the start, and with no password, session
        // or token for anyone to lose.
        $account = [
            '$id' => 'user-' . $claims['sub'],
            'email' => $claims['email'],
            'name' => (string) ($body['name'] ?? ''),
            'password' => '',
            'emailVerification' => true,
        ];

        changeProject(static function (array $accounts, array $sessions, array $tokens) use ($account): array {
            $accounts[(string) $account['$id']] = $account;

            return [$accounts, $sessions, $tokens];
        });
    } else {
        claimAccount($account);
    }

    $session = [
        '$id' => 'session-' . $provider . '-' . $claims['sub'],
        'userId' => (string) $account['$id'],
        'userName' => (string) $account['name'],
        'provider' => $provider,
        'providerUid' => $claims['sub'],
    ];

    addSession($session);

    respond(201, sessionDocument($session));
}

if ($method === 'POST' && $path === '/v1/account/sessions/id-token') {
    createIdTokenSession();
}

/**
 * `POST /v1/account/sessions/email`: sign in with an email and a password.
 *
 * This is where a registrant finds out its account was claimed. An account
 * whose password the claim revoked is in the same position as an address nobody
 * registered: there is no credential to match, and the answer says no more than
 * that.
 */
if ($method === 'POST' && $path === '/v1/account/sessions/email') {
    $body = body();
    $email = (string) ($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');

    foreach (project()['accounts'] as $stored) {
        if ($stored['email'] !== $email || $stored['password'] === '' || $stored['password'] !== $password) {
            continue;
        }

        $session = [
            '$id' => 'session-password-' . $stored['$id'],
            'userId' => (string) $stored['$id'],
            'provider' => 'email',
        ];

        addSession($session);
        respond(201, sessionDocument($session));
    }

    respond(401, [
        'message' => 'Invalid credentials. Please check the email and password.',
        'type' => 'user_invalid_credentials',
        'code' => 401,
    ]);
}

/**
 * `GET /v1/users/{userId}/sessions`: the sessions an account has left, which is
 * how a caller sees what the claim revoked — and, after a claim that lost a
 * race, that the winner's session is still there.
 */
if ($method === 'GET' && matchesPattern('/v1/users/*/sessions', $path)) {
    $userId = \explode('/', $path)[3];
    $owned = \array_values(\array_filter(
        project()['sessions'],
        static fn (array $session): bool => $session['userId'] === $userId
    ));

    respond(200, [
        'total' => \in_array($_GET['total'] ?? '1', ['0', 'false'], true) ? 0 : \count($owned),
        'sessions' => \array_map('sessionDocument', $owned),
    ]);
}

/**
 * `GET /v1/users/{userId}`: the account itself, where `emailVerification` tells
 * a caller the address is proven and the claim has already happened.
 */
if ($method === 'GET' && matchesPattern('/v1/users/*', $path)) {
    $account = project()['accounts'][\explode('/', $path)[3]] ?? null;

    if ($account === null) {
        respond(404, [
            'message' => 'User with the requested ID could not be found.',
            'type' => 'user_not_found',
            'code' => 404,
        ]);
    }

    respond(200, accountDocument($account));
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
