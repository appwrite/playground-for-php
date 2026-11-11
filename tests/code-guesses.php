<?php

/**
 * Checks the client half of the limits appwrite/appwrite#15470 put on the routes
 * that confirm a short secret.
 *
 * Those routes — the recovery OTP confirmation and the three session exchanges —
 * keep a handful of attempts per user, and that count is the only thing bounding
 * a guess: a six digit code is 10^6 tries and a wrong one costs the caller
 * nothing. The limits used to be waived for a caller holding an API key, so an
 * SSR backend's key could walk the whole space for a chosen user. They now count
 * for every caller.
 *
 * What a client has to hold up: send the project and the key on every attempt so
 * the server can find the right counter, and read the two answers apart —
 * `401` for a wrong secret, `429` once the user's attempts are spent. These
 * tests pin that down against `tests/stub-server.php`, which counts wrong
 * guesses per project, route and user without looking at the key:
 *
 * - the correct secret is still exchanged for a session with a key;
 * - a wrong secret is a typed `401` the SDK raises as an AppwriteException;
 * - the attempts run out for a key holder too, and the answer turns into `429`
 *   typed `general_rate_limit_exceeded`;
 * - the count is per user, so spending one user's attempts leaves another's;
 * - the recovery OTP confirmation behaves the same way, and once its share is
 *   spent it refuses even the correct secret.
 *
 * It cannot prove the server keys its own counters correctly — that is what the
 * e2e tests in appwrite/appwrite assert. It proves the SDK reaches those routes
 * as a key-holding caller and surfaces a spent limit in a shape callers can act
 * on instead of retrying into it.
 *
 * Usage: php tests/code-guesses.php
 */

declare(strict_types=1);

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Services\Account;

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
require __DIR__ . '/stub.php';

/**
 * Must match `GUESS_LIMIT` in the stub.
 */
const GUESS_LIMIT = 10;

/**
 * Must match `VALID_SECRET` in the stub.
 */
const VALID_SECRET = 'playground-secret';

const WRONG_SECRET = '000000';

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

/**
 * The account service of a server: a project and an API key, the way an SSR
 * backend relays a login or a recovery for its users.
 */
function keyedAccount(Stub $stub): Account
{
    return new Account(
        (new Client())
            ->setEndpoint($stub->endpoint)
            ->setProject('playground')
            ->setKey('playground-api-key')
    );
}

/**
 * Exchange a secret for a session and report what the caller sees: 201, or the
 * code and type of the AppwriteException the SDK raised.
 *
 * @return array{0: int, 1: string} [code, error type]
 */
function exchangeToken(Account $account, string $userId, string $secret): array
{
    try {
        $account->createSession(userId: $userId, secret: $secret);

        return [201, ''];
    } catch (AppwriteException $e) {
        return [$e->getCode(), $e->getType()];
    }
}

/**
 * Confirm a recovery OTP, reported the same way.
 *
 * @return array{0: int, 1: string} [code, error type]
 */
function confirmRecoveryOTP(Account $account, string $userId, string $secret): array
{
    try {
        $account->updateRecoveryOTP(userId: $userId, secret: $secret, password: 'new-password');

        return [201, ''];
    } catch (AppwriteException $e) {
        return [$e->getCode(), $e->getType()];
    }
}

$stub = Stub::start();

try {
    $account = keyedAccount($stub);

    echo "A key may still exchange a secret it was given\n";

    [$code, $type] = exchangeToken($account, 'victim', VALID_SECRET);
    check("the correct secret is exchanged for a session (got {$code} {$type})", $code === 201);

    $requests = $stub->requests();
    $last = \end($requests);
    check(
        'the exchange went to POST /v1/account/sessions/token',
        $last !== false && $last['method'] === 'POST' && $last['path'] === '/v1/account/sessions/token'
    );
    check('the exchange carried the API key', ($last['keyed'] ?? false) === true);
    check(
        "the exchange named the project (got '" . ($last['project'] ?? '') . "')",
        ($last['project'] ?? '') === 'playground'
    );

    echo "A wrong secret is refused, and the attempts run out for a key too\n";

    // The exchange above worked, and the limits on this route count failures
    // rather than requests, so all ten of these are still within the share.

    $codes = [];
    $types = [];

    for ($attempt = 1; $attempt <= GUESS_LIMIT; $attempt++) {
        [$codes[], $types[]] = exchangeToken($account, 'victim', WRONG_SECRET);
    }

    check(
        'every attempt within the limit is refused with 401',
        $codes === \array_fill(0, GUESS_LIMIT, 401)
    );
    check(
        'the refusals are typed user_invalid_token',
        $types === \array_fill(0, GUESS_LIMIT, 'user_invalid_token')
    );

    [$code, $type] = exchangeToken($account, 'victim', WRONG_SECRET);
    check("the next guess is throttled with 429 (got {$code})", $code === 429);
    check("the throttle is typed general_rate_limit_exceeded (got '{$type}')", $type === 'general_rate_limit_exceeded');

    echo "Spending one user's attempts leaves another user's\n";

    [$code, $type] = exchangeToken($account, 'bystander', WRONG_SECRET);
    check("another user is still answered 401, not 429 (got {$code} {$type})", $code === 401);

    echo "The recovery OTP confirmation counts the same way\n";

    $codes = [];

    for ($attempt = 1; $attempt <= GUESS_LIMIT; $attempt++) {
        [$codes[]] = confirmRecoveryOTP($account, 'victim', WRONG_SECRET);
    }

    check(
        'every attempt within the limit is refused with 401',
        $codes === \array_fill(0, GUESS_LIMIT, 401)
    );

    [$code, $type] = confirmRecoveryOTP($account, 'victim', WRONG_SECRET);
    check("the next guess is throttled with 429 (got {$code})", $code === 429);
    check("the throttle is typed general_rate_limit_exceeded (got '{$type}')", $type === 'general_rate_limit_exceeded');

    [$code, $type] = confirmRecoveryOTP($account, 'victim', VALID_SECRET);
    check(
        "a spent limit refuses the correct secret too (got {$code} {$type})",
        $code === 429
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

echo "OK — code guesses are counted for an API key, as appwrite/appwrite#15470 intends\n";
