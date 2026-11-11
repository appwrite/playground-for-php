<?php

/**
 * Checks the two email verification confirmation routes against the stub.
 *
 * A verification token may be claimed once. Appwrite claims it under the user
 * row's lock — so that an email change cannot slip in between the check and the
 * write and leave an unproven address marked verified — and every later or
 * concurrent attempt is turned away with a 401 `user_invalid_token`. These are
 * the two routes `playground.php` calls, so this checks the SDK reports that
 * outcome as an `AppwriteException` the playground can catch.
 *
 * Usage: PLAYGROUND_ENDPOINT=http://127.0.0.1:1234/v1 php tests/verification.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Services\Account;

$endpoint = \getenv('PLAYGROUND_ENDPOINT');

if (!\is_string($endpoint) || $endpoint === '') {
    \fwrite(STDERR, "PLAYGROUND_ENDPOINT is not set\n");
    exit(1);
}

$account = new Account(
    (new Client())
        ->setEndpoint($endpoint)
        ->setProject('playground')
        ->setKey('playground-api-key')
);

/** @var array<string, callable(string, string): \Appwrite\Models\Token> */
$routes = [
    'updateEmailVerification' => static fn (string $userId, string $secret) => $account->updateEmailVerification($userId, $secret),
    'updateEmailVerificationOTP' => static fn (string $userId, string $secret) => $account->updateEmailVerificationOTP($userId, $secret),
];

$failures = [];
$userId = 'playground-user';

foreach ($routes as $name => $confirm) {
    $secret = "secret-for-{$name}";

    try {
        $token = $confirm($userId, $secret);

        if ($token->userId !== $userId) {
            $failures[] = "{$name}: confirmed token is for '{$token->userId}', expected '{$userId}'";
        }
    } catch (AppwriteException $e) {
        $failures[] = "{$name}: the first confirmation was rejected ({$e->getCode()} {$e->getType()}): {$e->getMessage()}";
        continue;
    }

    try {
        $confirm($userId, $secret);
        $failures[] = "{$name}: the token was claimed twice, it must be single use";
    } catch (AppwriteException $e) {
        if ($e->getCode() !== 401) {
            $failures[] = "{$name}: replay answered with {$e->getCode()}, expected 401";
        }

        if ($e->getType() !== 'user_invalid_token') {
            $failures[] = "{$name}: replay answered with type '{$e->getType()}', expected 'user_invalid_token'";
        }
    }
}

if ($failures !== []) {
    \fwrite(STDERR, "FAILED\n");

    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo 'OK — ' . \count($routes) . " verification routes claim their token exactly once\n";
