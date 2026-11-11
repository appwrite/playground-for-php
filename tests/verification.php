<?php

/**
 * Checks how the playground deals with the verification confirmation routes
 * after appwrite/appwrite#15277.
 *
 * `PUT /v1/account/verifications/email`, `.../email/otp` and `.../phone` take a
 * `userId` and the code mailed or texted to that user. The code is six digits,
 * so the only thing standing between a caller and a guessed code is the abuse
 * limit — and that limit used to be peeked at, never counted, whenever the
 * caller held an API key. Since the fix it counts for every caller: 10 attempts
 * per user per hour, key or no key.
 *
 * What that means for this project is that an API key cannot retry a rejected
 * code, so `confirmEmailVerification()` treats `429` as an answer rather than
 * letting it escape as an exception, and `markUserVerified()` is the call to
 * reach for when the server itself decides a user is verified.
 *
 * Usage: php tests/verification.php
 */

declare(strict_types=1);

if (!\is_file(\dirname(__DIR__) . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require \dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/assertions.php';

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Services\Account;
use Appwrite\Services\Users;

/** Matches `VERIFICATION_LIMIT` in `tests/stub-server.php`. */
const LIMIT = 10;

/** Matches `VERIFICATION_CODE` in `tests/stub-server.php`. */
const CODE = '123456';

/**
 * The status an attempt came back with, or 0 when it was accepted.
 */
function attempt(callable $call): int
{
    try {
        $call();

        return 0;
    } catch (AppwriteException $e) {
        return $e->getCode();
    }
}

$stub = Stub::start();

try {
    $client = (new Client())
        ->setEndpoint($stub->endpoint)
        ->setProject('playground')
        ->setKey('playground-api-key');

    $account = new Account($client);
    $users = new Users($client);

    // A key gets the same budget as anyone else: wrong codes are refused, and
    // they are counted, so the attempts run out.
    $statuses = [];

    for ($i = 0; $i < LIMIT + 1; $i++) {
        $statuses[] = attempt(static fn () => $account->updateEmailVerificationOTP('spender', '000000'));
    }

    checkSame(
        \array_fill(0, LIMIT, 401),
        \array_slice($statuses, 0, LIMIT),
        'Wrong codes should be refused one by one'
    );
    checkSame(429, $statuses[LIMIT], 'An API key should run out of attempts like any other caller');

    // Once the budget is spent it stays spent for the hour — even for the
    // user's own, correct code. Retrying a rejected code is what costs the
    // integration, which is why the playground does not retry.
    checkSame(
        429,
        attempt(static fn () => $account->updateEmailVerificationOTP('spender', CODE)),
        'A spent budget should refuse even the correct code'
    );

    // The limit is keyed per user, so one user burning its attempts leaves
    // every other user untouched. A server doing the one legitimate
    // confirmation per user never notices the limit.
    checkSame(
        0,
        attempt(static fn () => $account->updateEmailVerificationOTP('fresh-user', CODE)),
        'Another user should still be able to confirm its code'
    );

    $token = $account->updateEmailVerificationOTP('another-user', CODE);
    checkSame('another-user', $token->userId, 'A confirmed code should come back as that user\'s token');

    // All three confirmation routes carry the limit, not just the OTP one.
    foreach ([
        'email' => static fn (string $user) => $account->updateEmailVerification($user, '000000'),
        'email/otp' => static fn (string $user) => $account->updateEmailVerificationOTP($user, '000000'),
        'phone' => static fn (string $user) => $account->updatePhoneVerification($user, '000000'),
    ] as $route => $call) {
        $user = 'user-for-' . \str_replace('/', '-', $route);
        $last = 0;

        for ($i = 0; $i < LIMIT + 1; $i++) {
            $last = attempt(static fn () => $call($user));
        }

        checkSame(429, $last, 'Attempts on /account/verifications/' . $route . ' should be counted');
    }

    // Marking a user verified from the server is a different route behind
    // `users.write`, and it is not rate limited per user.
    $verified = 0;

    for ($i = 0; $i < LIMIT + 1; $i++) {
        $verified = attempt(static fn () => $users->updateEmailVerification('spender', true));
    }

    checkSame(0, $verified, 'users->updateEmailVerification() should not share the per-user budget');

    // Finally, the playground itself: a rate limited confirmation is reported,
    // not thrown, so the rest of the run still happens.
    $stub->forgetRequests();
    $output = runPlayground($stub, [
        'PLAYGROUND_VERIFICATION_USER_ID' => 'spender',
        'PLAYGROUND_VERIFICATION_SECRET' => CODE,
    ]);

    check(
        \str_contains($output, 'Rate limited: this user has spent its 10 confirmation attempts'),
        "The playground should report a rate limited confirmation:\n" . $output
    );
    check(
        \in_array('api.deleteFunction', playgroundCalls($output), true),
        "A rate limited confirmation should not stop the run:\n" . $output
    );
} finally {
    $stub->stop();
}

report('verification');
