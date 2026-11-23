<?php

/**
 * Checks the client half of appwrite/appwrite#15300: what a provider email is
 * worth as a claim on an account, and what a link that cannot have it answers.
 *
 * A provider email is only ever held by the identities of a single user. One
 * user may hold several identities reporting the same address — the same person
 * signing in with Google and with Apple — so the rule cannot be a unique index,
 * and it was enforced by reading the ownership before writing the identity.
 * That read is a time-of-check race: two sign-ins presenting the same verified
 * address at the same moment both came up clean and both wrote, and the address
 * came out of it with two owners. Two accounts, either of which a later sign-in
 * with that address could land on. The fix writes the row first and reads the
 * ownership again afterwards, so the loser drops what it wrote.
 *
 * What a caller should be able to hold up, and what these tests pin down
 * against `tests/stub-server.php`:
 *
 * - a verified address another account's identity holds is refused, as a
 *   generic `400` that discloses nothing about the account holding it;
 * - the refusal leaves nothing behind: not the identity it had written, and not
 *   the account it had made for the sign-in;
 * - one user may still hold several identities on one address, and an identity
 *   the provider reported no address for is never refused on this ground;
 * - a link that loses the race is refused the same way, so the address comes
 *   out of it with exactly one owner — which is what `users->listIdentities()`
 *   is for.
 *
 * The last block runs the same calls against a stub that links the way Appwrite
 * did before the fix, so the assertions above are shown to be able to fail.
 *
 * It cannot prove the server's own write and read straddle a commit each — that
 * is what the unit tests in appwrite/appwrite assert, with a database that lets
 * another request's write land between them. It proves that an SDK caller is
 * told the link was refused in a shape it can act on, and that reading the
 * identities back shows one owner for the address.
 *
 * Usage: php tests/identity-email.php
 */

declare(strict_types=1);

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\IdTokenProvider;
use Appwrite\Models\Identity;
use Appwrite\Models\Session;
use Appwrite\Query;
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
 * Must match `MOVED_USER_ID` and `MOVED_PROVIDER_EMAIL` in the stub: a user
 * whose identity holds an address its account is no longer under, so a sign-in
 * reporting that address resolves to no account and gets a new one.
 */
const MOVED_USER_ID = 'alice';
const MOVED_PROVIDER_EMAIL = 'shared@example.com';

/**
 * Must match `LINKED_USER_ID` and `LINKED_USER_EMAIL` in the stub: a user whose
 * account is under the address its identity reports.
 */
const LINKED_USER_ID = 'bob';
const LINKED_USER_EMAIL = 'linked@example.com';

/**
 * Must match `RACING_USER_ID` in the stub: the user a competing linker signs in
 * as when the stub is asked to let one commit mid-link.
 */
const RACING_USER_ID = 'racer';

/**
 * An address no identity holds yet, which is the only situation a race can
 * arise in: both racers have to come up clean to both write.
 */
const UNHELD_EMAIL = 'fresh@example.com';

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

function keyedClient(Stub $stub): Client
{
    return (new Client())
        ->setEndpoint($stub->endpoint)
        ->setProject('playground')
        ->setKey('playground-api-key');
}

/**
 * An unsigned stand-in for the id token a provider hands a client: the subject
 * naming the provider account, and the address the provider says it verified.
 * `providerIdToken()` in `playground.php` is the same thing.
 */
function idToken(string $subject, ?string $email): string
{
    $segment = static fn (array $claims): string => \rtrim(
        \strtr(\base64_encode((string) \json_encode($claims)), '+/', '-_'),
        '='
    );

    return $segment(['alg' => 'none', 'typ' => 'JWT'])
        . '.'
        . $segment($email === null
            ? ['sub' => $subject]
            : ['sub' => $subject, 'email' => $email, 'email_verified' => true])
        . '.';
}

/**
 * Sign in with a provider id token, handing back the session or the refusal.
 *
 * @return array{0: ?Session, 1: ?AppwriteException}
 */
function linkIdentity(Account $account, IdTokenProvider $provider, string $subject, ?string $email): array
{
    try {
        return [
            $account->createIdTokenSession(
                provider: $provider,
                idToken: idToken($subject, $email),
            ),
            null,
        ];
    } catch (AppwriteException $e) {
        return [null, $e];
    }
}

/**
 * The identities holding `$providerEmail`, as the API key sees them.
 *
 * @return list<Identity>
 */
function identitiesHolding(Users $users, string $providerEmail): array
{
    return $users->listIdentities(
        queries: [Query::equal('providerEmail', [$providerEmail])]
    )->identities;
}

/**
 * The accounts those identities belong to, deduplicated: one user holding two
 * identities on one address is one owner, not two.
 *
 * @return list<string>
 */
function ownersOf(Users $users, string $providerEmail): array
{
    $owners = \array_unique(\array_map(
        static fn (Identity $identity): string => $identity->userId,
        identitiesHolding($users, $providerEmail)
    ));

    \sort($owners);

    return \array_values($owners);
}

function userExists(Users $users, string $userId): bool
{
    try {
        $users->get($userId);

        return true;
    } catch (AppwriteException) {
        return false;
    }
}

$stub = Stub::start();

try {
    $account = new Account(keyedClient($stub));
    $users = new Users(keyedClient($stub));

    echo "An address another account's identity holds is refused, and generically\n";

    [$session, $refusal] = linkIdentity($account, IdTokenProvider::GOOGLE(), 'google-carol', MOVED_PROVIDER_EMAIL);

    check(
        'the link is refused',
        $session === null && $refusal !== null
    );
    check(
        'with a 400, not a 409 that would confirm the address is taken (got '
            . ($refusal === null ? 'nothing' : (string) $refusal->getCode()) . ')',
        $refusal !== null && $refusal->getCode() === 400
    );
    check(
        'as general_bad_request, the answer a malformed request gets (got "'
            . ($refusal === null ? '' : $refusal->getType()) . '")',
        $refusal !== null && $refusal->getType() === 'general_bad_request'
    );
    check(
        'so the message gives away nothing about the account that holds it',
        $refusal !== null
            && !\str_contains($refusal->getMessage(), MOVED_PROVIDER_EMAIL)
            && !\str_contains($refusal->getMessage(), MOVED_USER_ID)
    );
    check(
        'the address still has exactly one owner',
        ownersOf($users, MOVED_PROVIDER_EMAIL) === [MOVED_USER_ID]
    );

    echo "And it leaves neither an identity nor an account behind\n";

    check(
        'the refused link wrote no identity of its own',
        \count(identitiesHolding($users, MOVED_PROVIDER_EMAIL)) === 1
    );
    check(
        'and the account the sign-in would have created is not in the project',
        !userExists($users, 'user-google-carol')
    );
    // Proof that the check above says something: the accounts the project was
    // seeded with do answer.
    check(
        'while the account that holds the address does answer',
        userExists($users, MOVED_USER_ID)
    );

    echo "One account may still hold several identities on one address\n";

    [$second, $refused] = linkIdentity($account, IdTokenProvider::APPLE(), 'apple-bob', LINKED_USER_EMAIL);

    check(
        'a second provider reporting an address its own account is under links',
        $second !== null && $refused === null
    );
    check(
        'to that same account (got "' . ($second === null ? '' : $second->userId) . '")',
        $second !== null && $second->userId === LINKED_USER_ID
    );
    check(
        'which now holds two identities on the one address',
        \count(identitiesHolding($users, LINKED_USER_EMAIL)) === 2
    );
    check(
        'and is still its only owner',
        ownersOf($users, LINKED_USER_EMAIL) === [LINKED_USER_ID]
    );

    echo "An identity the provider reported no address for is never refused\n";

    [$anonymous, $refused] = linkIdentity($account, IdTokenProvider::GOOGLE(), 'google-dave', null);
    [$alsoAnonymous, $alsoRefused] = linkIdentity($account, IdTokenProvider::APPLE(), 'apple-erin', null);

    check(
        'two provider accounts with no verified address both link',
        $anonymous !== null && $refused === null && $alsoAnonymous !== null && $alsoRefused === null
    );
    check(
        'to accounts of their own, because there is no address to share',
        $anonymous !== null && $alsoAnonymous !== null && $anonymous->userId !== $alsoAnonymous->userId
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

/**
 * The same link against a stub that lets a competing linker commit its own
 * identity for the address in the instant between this request's insert and the
 * read that follows it. That read is what the fix added, and it is the only
 * thing that can see a race that has already happened.
 */
$raced = Stub::start(['STUB_IDENTITY_RACE' => '1']);

try {
    echo "A link that loses the race is refused, so the address keeps one owner\n";

    $account = new Account(keyedClient($raced));
    $users = new Users(keyedClient($raced));

    [$session, $refusal] = linkIdentity($account, IdTokenProvider::GOOGLE(), 'google-frank', UNHELD_EMAIL);

    check(
        'an address nobody held is refused once another request has it (got '
            . ($refusal === null ? 'a session' : (string) $refusal->getCode()) . ')',
        $session === null && $refusal !== null && $refusal->getCode() === 400
    );
    check(
        'with the same generic type as the ordinary conflict',
        $refusal !== null && $refusal->getType() === 'general_bad_request'
    );
    check(
        'one of the two racers holds the address, and only one',
        ownersOf($users, UNHELD_EMAIL) === [RACING_USER_ID]
    );
    check(
        'the row this request had already written is gone',
        \count(identitiesHolding($users, UNHELD_EMAIL)) === 1
    );
    check(
        'and so is the account it had created for the sign-in',
        !userExists($users, 'user-google-frank')
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $raced->errorLog() . "\n");
    }
} finally {
    $raced->stop();
}

/**
 * The same two links against a stub that links the way Appwrite did before
 * #15300 — the ownership read before the insert and never again, and a refusal
 * that returns without clearing up — to show the assertions above can fail.
 */
$pre = Stub::start(['STUB_IDENTITY_RACE' => '1', 'STUB_OAUTH_IDENTITY_PRE_15300' => '1']);

try {
    echo "Before the fix, a refused link left the account it had just created\n";

    $account = new Account(keyedClient($pre));
    $users = new Users(keyedClient($pre));

    [, $refusal] = linkIdentity($account, IdTokenProvider::GOOGLE(), 'google-carol', MOVED_PROVIDER_EMAIL);

    check(
        'the conflict it could see was refused then too',
        $refusal !== null && $refusal->getCode() === 400
    );
    check(
        'but the account made for the sign-in stayed in the project',
        userExists($users, 'user-google-carol')
    );

    echo "And a race left one address with two owners\n";

    [$session, $refused] = linkIdentity($account, IdTokenProvider::GOOGLE(), 'google-frank', UNHELD_EMAIL);

    check(
        'the link that lost the race came back with a session',
        $session !== null && $refused === null
    );
    check(
        'and the address ended up held by two accounts (got '
            . \implode(', ', ownersOf($users, UNHELD_EMAIL)) . ')',
        ownersOf($users, UNHELD_EMAIL) === ['racer', 'user-google-frank']
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $pre->errorLog() . "\n");
    }
} finally {
    $pre->stop();
}

if ($failures !== []) {
    \fwrite(STDERR, "FAILED\n");

    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo "OK — a provider email has one owner, as appwrite/appwrite#15300 intends\n";
