<?php

/**
 * Checks the client half of appwrite/appwrite#15282: what a provider sign-in
 * does to an account that was registered under the address it proves, and what
 * a caller can rely on once it has.
 *
 * Appwrite lets whoever can prove an address outrank whoever only typed it. An
 * account registered with an email it never verified is *claimed* when a
 * provider vouches for that address: the registrant's password, its sessions
 * and its outstanding tokens are revoked, and the address is recorded as
 * proven. The OAuth2 redirect and the id-token sign-in used to do that from a
 * copy of the account read earlier, calling a revoke that transacted only its
 * own deletes — so nothing held the account still across the step, and a
 * session created meanwhile could outlive the revoke while two claims of one
 * address could each sweep what the other had created. The fix makes the claim
 * one transaction with the account re-read under its row lock.
 *
 * What a caller has to hold up, and what these tests pin down against
 * `tests/stub-server.php`:
 *
 * - the claim takes away every other way into the account — the password stops
 *   signing in, the sessions stop being listed, and a token minted before it
 *   answers `401` on the exchange rather than handing over a session;
 * - `emailVerification` comes back `true` from the claim itself, in the same
 *   commit as the revoke, so it is the signal that the claim has happened;
 * - a claim arriving behind another one revokes nothing: it finds the address
 *   proven on the locked copy and leaves the first claimant's session alone,
 *   rather than sweeping it and writing the registrant's password back;
 * - a session the registrant creates while a claim is in flight does not
 *   survive it;
 * - an account whose address was already proven is not claimed at all.
 *
 * The last three are run twice: once against the stub as the fix leaves it, and
 * once against `STUB_OAUTH_CLAIM_PRE_15282`, which replays the unlocked claim
 * and under which the registrant keeps a way in. That second run is what proves
 * the assertions above are able to fail.
 *
 * It cannot prove the server takes the row lock — that is what the unit tests
 * in appwrite/appwrite assert. It proves the answers a caller sees are the ones
 * the locked claim produces, and that the SDK surfaces them.
 *
 * Usage: php tests/account-claim.php
 */

declare(strict_types=1);

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\IdTokenProvider;
use Appwrite\Models\Session;
use Appwrite\Services\Account;
use Appwrite\Services\Users;

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
require __DIR__ . '/stub.php';

/**
 * Must match `REGISTRANT_ID`, `REGISTRANT_EMAIL` and `REGISTRANT_PASSWORD` in
 * the stub: the account registered under an address nobody proved.
 */
const REGISTRANT_ID = 'registrant';
const REGISTRANT_EMAIL = 'claimed@example.com';
const REGISTRANT_PASSWORD = 'registrant-password';

/**
 * Must match `REGISTRANT_SESSIONS` and `REGISTRANT_TOKEN` in the stub: the ways
 * into that account which the claim revokes.
 */
const REGISTRANT_SESSIONS = ['session-registrant-web', 'session-registrant-cli'];
const REGISTRANT_TOKEN = 'playground-secret';

/**
 * Must match `VERIFIED_*` in the stub: an account under an address that is
 * already proven, so there is nothing for a provider sign-in to claim.
 */
const VERIFIED_ID = 'verified';
const VERIFIED_EMAIL = 'verified@example.com';
const VERIFIED_PASSWORD = 'verified-password';
const VERIFIED_SESSION = 'session-verified-web';

/**
 * Must match `RIVAL_SESSION` in the stub: the session a competing claim leaves
 * behind, and the one a second revoke would sweep.
 */
const RIVAL_SESSION = 'session-rival';

/**
 * Must match `MIDCLAIM_SESSION` in the stub: the session the registrant creates
 * for itself while the claim is in flight.
 */
const MIDCLAIM_SESSION = 'session-registrant-midclaim';

$failures = [];

function check(string $what, bool $ok): void
{
    global $failures;

    if ($ok) {
        echo "  ok    {$what}\n";

        return;
    }

    echo "  FAIL  {$what}\n";
    $failures[] = $what;
}

function keyedUsers(Stub $stub): Users
{
    return new Users(keyedClient($stub));
}

function keyedAccount(Stub $stub): Account
{
    return new Account(keyedClient($stub));
}

function keyedClient(Stub $stub): Client
{
    return (new Client())
        ->setEndpoint($stub->endpoint)
        ->setProject('playground')
        ->setKey('playground-api-key');
}

/**
 * An unsigned stand-in for the id token a provider hands a client, the same one
 * `providerIdToken()` in `playground.php` builds.
 */
function idToken(string $subject, string $email): string
{
    $segment = static fn (array $claims): string => \rtrim(
        \strtr(\base64_encode((string) \json_encode($claims)), '+/', '-_'),
        '='
    );

    return $segment(['alg' => 'none', 'typ' => 'JWT'])
        . '.'
        . $segment(['sub' => $subject, 'email' => $email, 'email_verified' => true])
        . '.';
}

/**
 * Sign in with a provider that vouches for `$email`, claiming whatever
 * unverified account holds it.
 */
function signInWithProvider(Account $account, string $subject, string $email): Session
{
    return $account->createIdTokenSession(
        provider: IdTokenProvider::GOOGLE(),
        idToken: idToken($subject, $email),
        name: 'Claimant',
    );
}

/**
 * The ids of the sessions an account has left.
 *
 * @return list<string>
 */
function sessionIds(Users $users, string $userId): array
{
    return \array_map(
        static fn (Session $session): string => $session->id,
        $users->listSessions(userId: $userId)->sessions
    );
}

/**
 * Whether the account still has a password set. A real server hands an API key
 * holder the hash rather than the password, so what a caller can tell is
 * whether there is one at all — which is exactly what the revoke changes.
 */
function hasPassword(Users $users, string $userId): bool
{
    return ($users->get(userId: $userId)->password ?? '') !== '';
}

/**
 * The code a call was refused with, or 0 when it was not refused.
 */
function refusedWith(callable $call): int
{
    try {
        $call();
    } catch (AppwriteException $e) {
        return $e->getCode();
    }

    return 0;
}

$stub = Stub::start();

try {
    $users = keyedUsers($stub);
    $account = keyedAccount($stub);

    echo "An unverified registrant holds the account before the claim\n";

    $before = $users->get(userId: REGISTRANT_ID);

    check(
        'the address it registered under is not proven',
        $before->emailVerification === false
    );
    check('it has a password', hasPassword($users, REGISTRANT_ID));
    check(
        'and the sessions it signed in with',
        sessionIds($users, REGISTRANT_ID) === REGISTRANT_SESSIONS
    );
    check(
        'its outstanding token exchanges for a session',
        refusedWith(static fn (): Session => $account->createSession(
            userId: REGISTRANT_ID,
            secret: REGISTRANT_TOKEN
        )) === 0
    );

    echo "Proving the address claims the account\n";

    $claim = signInWithProvider($account, 'google-claimant', REGISTRANT_EMAIL);

    check(
        'the session lands on the account that held the address',
        $claim->userId === REGISTRANT_ID
    );

    $claimed = $users->get(userId: REGISTRANT_ID);

    check(
        'the claim recorded the address as proven',
        $claimed->emailVerification === true
    );
    check('the registrant has no password left', !hasPassword($users, REGISTRANT_ID));
    check(
        'so its password no longer signs in',
        refusedWith(static fn (): Session => $account->createEmailPasswordSession(
            email: REGISTRANT_EMAIL,
            password: REGISTRANT_PASSWORD
        )) === 401
    );
    check(
        'its sessions are gone, and the only one left is the claimant\'s',
        sessionIds($users, REGISTRANT_ID) === [$claim->id]
    );
    check(
        'the token it had outstanding no longer exchanges',
        refusedWith(static fn (): Session => $account->createSession(
            userId: REGISTRANT_ID,
            secret: REGISTRANT_TOKEN
        )) === 401
    );

    echo "A claim of an address already proven revokes nothing\n";

    $second = signInWithProvider($account, 'apple-claimant', REGISTRANT_EMAIL);

    check(
        "the first claimant's session survives the second claim",
        \in_array($claim->id, sessionIds($users, REGISTRANT_ID), true)
    );
    check(
        'and the second claimant gets one of its own',
        \in_array($second->id, sessionIds($users, REGISTRANT_ID), true)
    );

    echo "An account under an address that was already proven is not claimed\n";

    signInWithProvider($account, 'google-verified', VERIFIED_EMAIL);

    check(
        'it keeps its password',
        hasPassword($users, VERIFIED_ID)
    );
    check(
        'which still signs in',
        refusedWith(static fn (): Session => $account->createEmailPasswordSession(
            email: VERIFIED_EMAIL,
            password: VERIFIED_PASSWORD
        )) === 0
    );
    check(
        'and it keeps the session it already had',
        \in_array(VERIFIED_SESSION, sessionIds($users, VERIFIED_ID), true)
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

/**
 * Two claims of one address, the second taking the lock after the first has
 * committed. Under the fix it finds the address proven on the locked copy and
 * does nothing; the whole point is what it does *not* do.
 */
$stub = Stub::start(['STUB_CLAIM_RACE' => '1']);

try {
    $users = keyedUsers($stub);
    $account = keyedAccount($stub);

    echo "A claim that lost the race leaves the winner's work alone\n";

    $loser = signInWithProvider($account, 'google-claimant', REGISTRANT_EMAIL);
    $sessions = sessionIds($users, REGISTRANT_ID);

    check(
        "the winning claim's session is still there",
        \in_array(RIVAL_SESSION, $sessions, true)
    );
    check(
        'the registrant has no password back',
        !hasPassword($users, REGISTRANT_ID)
    );
    check(
        'and none of the sessions it signed in with',
        \array_intersect(REGISTRANT_SESSIONS, $sessions) === []
    );
    check(
        'the account stays proven',
        $users->get(userId: REGISTRANT_ID)->emailVerification === true
    );
    check(
        "the loser's own session is there, since the sign-in itself worked",
        \in_array($loser->id, $sessions, true)
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

/**
 * The same race against the claim as it was before appwrite/appwrite#15282,
 * which is what the checks above are worth: deciding from the copy it read
 * before the winner committed, the loser revokes a second time and persists
 * that copy afterwards.
 */
$stub = Stub::start(['STUB_CLAIM_RACE' => '1', 'STUB_OAUTH_CLAIM_PRE_15282' => '1']);

try {
    $users = keyedUsers($stub);
    $account = keyedAccount($stub);

    echo "Without the lock, the loser undoes the winner's claim\n";

    signInWithProvider($account, 'google-claimant', REGISTRANT_EMAIL);

    check(
        "the winning claim's session was swept by the second revoke",
        !\in_array(RIVAL_SESSION, sessionIds($users, REGISTRANT_ID), true)
    );
    check(
        "and the registrant's password was written back over the revocation",
        hasPassword($users, REGISTRANT_ID)
    );
    check(
        'so the account the proven owner claimed can be signed into with it',
        refusedWith(static fn (): Session => $account->createEmailPasswordSession(
            email: REGISTRANT_EMAIL,
            password: REGISTRANT_PASSWORD
        )) === 0
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

/**
 * The registrant signing in for itself while the claim is in flight. Under the
 * fix the claim's scan and its commit are one locked step, so that session
 * cannot land between them: it commits before the lock is taken and is swept
 * with the rest.
 */
$stub = Stub::start(['STUB_CLAIM_SESSION_RACE' => '1']);

try {
    $users = keyedUsers($stub);
    $account = keyedAccount($stub);

    echo "A session the registrant creates mid-claim does not survive it\n";

    $claim = signInWithProvider($account, 'google-claimant', REGISTRANT_EMAIL);

    check(
        'the session created while the claim ran is gone with the others',
        !\in_array(MIDCLAIM_SESSION, sessionIds($users, REGISTRANT_ID), true)
    );
    check(
        "so the claimant's is the only way in",
        sessionIds($users, REGISTRANT_ID) === [$claim->id]
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

/**
 * And the same thing before the fix: the revoke scanned for what to delete in a
 * transaction of its own, so a session committing inside that step was never on
 * the list.
 */
$stub = Stub::start(['STUB_CLAIM_SESSION_RACE' => '1', 'STUB_OAUTH_CLAIM_PRE_15282' => '1']);

try {
    $users = keyedUsers($stub);
    $account = keyedAccount($stub);

    echo "Without the lock, that session outlives the revoke\n";

    signInWithProvider($account, 'google-claimant', REGISTRANT_EMAIL);

    check(
        'the registrant keeps a session into the account that was claimed',
        \in_array(MIDCLAIM_SESSION, sessionIds($users, REGISTRANT_ID), true)
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

if ($failures !== []) {
    \fwrite(STDERR, "FAILED\n");

    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo "OK — a proven address claims an account in one locked step, as appwrite/appwrite#15282 intends\n";
