<?php

/**
 * Checks the client half of the per-project abuse limits that appwrite/appwrite
 * #15280 put on the account- and address-keyed routes.
 *
 * The server keeps those counts per project now, which it can only do with the
 * project the caller names. The SDK names it in the `X-Appwrite-Project` header
 * it sends per route — including on the guest routes that carry no other
 * credential, where it is the only thing separating one project's bucket from
 * another's. These tests pin that down against `tests/stub-server.php`, which
 * counts recovery requests per project and address the way the server does:
 *
 * - the recovery request carries the project;
 * - spending a project's share for an address surfaces as an AppwriteException
 *   with code 429 and type `general_rate_limit_exceeded`;
 * - another project still has its own share for the same address;
 * - changing the case of the address does not hand back a fresh share.
 *
 * It cannot prove the server keys its own counters correctly — that is what the
 * e2e tests in appwrite/appwrite assert. It proves the playground and the SDK
 * hold up their end, and that a 429 reaches callers in a shape they can act on.
 *
 * Usage: php tests/rate-limit.php
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
 * Must match `RECOVERY_LIMIT` in the stub.
 */
const RECOVERY_LIMIT = 10;

const ADDRESS = 'victim@example.com';

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
 * A guest client for a project: no API key, no session, the way a sign-in page
 * reaches the recovery route.
 */
function guestAccount(Stub $stub, string $project): Account
{
    return new Account(
        (new Client())
            ->setEndpoint($stub->endpoint)
            ->setProject($project)
    );
}

/**
 * Request recovery once and report the status the caller sees: 201, or the code
 * of the AppwriteException the SDK raised.
 *
 * @return array{0: int, 1: string} [code, error type]
 */
function requestRecovery(Account $account, string $email): array
{
    try {
        $account->createRecovery(email: $email, url: 'https://example.com/recover');

        return [201, ''];
    } catch (AppwriteException $e) {
        return [$e->getCode(), $e->getType()];
    }
}

$stub = Stub::start();

try {
    $attacker = guestAccount($stub, 'attacker-project');
    $victim = guestAccount($stub, 'victim-project');

    echo "The recovery request carries the project\n";

    [$code] = requestRecovery($attacker, ADDRESS);
    check('a guest with no credential may request recovery', $code === 201);

    $requests = $stub->requests();
    $last = \end($requests);
    check(
        'the request went to POST /v1/account/recovery',
        $last !== false && $last['method'] === 'POST' && $last['path'] === '/v1/account/recovery'
    );
    check(
        "the request named the project (got '" . ($last['project'] ?? '') . "')",
        ($last['project'] ?? '') === 'attacker-project'
    );

    echo "Spending one project's share for an address\n";

    $codes = [];

    for ($attempt = 1; $attempt < RECOVERY_LIMIT; $attempt++) {
        [$codes[]] = requestRecovery($attacker, ADDRESS);
    }

    check(
        'the rest of the share is granted',
        $codes === \array_fill(0, RECOVERY_LIMIT - 1, 201)
    );

    [$code, $type] = requestRecovery($attacker, ADDRESS);
    check("the next request is refused with 429 (got {$code})", $code === 429);
    check("the refusal is typed general_rate_limit_exceeded (got '{$type}')", $type === 'general_rate_limit_exceeded');

    [$code] = requestRecovery($attacker, \strtoupper(ADDRESS));
    check("upper-casing the address does not reset the count (got {$code})", $code === 429);

    echo "Another project keeps its own share for the same address\n";

    [$code, $type] = requestRecovery($victim, ADDRESS);
    check(
        "the victim's project may still recover the address (got {$code} {$type})",
        $code === 201
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

echo "OK — per-project abuse limits behave as appwrite/appwrite#15280 intends\n";
