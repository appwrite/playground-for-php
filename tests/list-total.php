<?php

/**
 * Checks the client half of appwrite/appwrite#15496: what a list `total` is
 * worth, and what it takes to learn the number it is hiding.
 *
 * A list route counts its rows with a budget — `APP_LIMIT_COUNT`, 5,000 — so
 * the `total` it answers with is `min(rows, 5000)`. For anything bigger than
 * the budget that number is a floor: a project with 50,000 users reports 5,000,
 * and the 200 pages of 25 it divides into end on a page that is not the last
 * one. The console used to print that floor as the count, which is the bug the
 * upstream PR fixed; a server reading the same list can make the same claim
 * just as easily.
 *
 * What a caller has to hold up, and what these tests pin down against
 * `tests/stub-server.php`:
 *
 * - a total that reaches the budget is a floor, and the rows past it are really
 *   there — the list keeps answering with more of them;
 * - a total under the budget is exact, and dressing it up as a floor would be
 *   wrong in the other direction;
 * - the exact number is reachable by paging to the end of the list with
 *   `Query::cursorAfter()`, which is what `countUsersPastTheCountLimit()` in
 *   `playground.php` does;
 * - `total: false` asks for no count, and the `0` it answers with does not mean
 *   the list is empty — the rows come back as usual.
 *
 * It cannot prove the server clamps its own counts at 5,000 — that is what the
 * e2e tests in appwrite/appwrite assert. It proves the SDK surfaces the clamped
 * total as-is and that a caller can get past it.
 *
 * Usage: php tests/list-total.php
 */

declare(strict_types=1);

use Appwrite\Client;
use Appwrite\Query;
use Appwrite\Services\Users;

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
require __DIR__ . '/stub.php';

/**
 * Must match `COUNT_LIMIT` in the stub, which mirrors the server's
 * `APP_LIMIT_COUNT`.
 */
const COUNT_LIMIT = 5000;

/**
 * Must match `USER_POPULATION` in the stub: more users than the count reaches.
 */
const USER_POPULATION = 5123;

/**
 * Must match `SEARCHED_USER_POPULATION` in the stub: few enough to be counted
 * in full.
 */
const SEARCHED_USER_POPULATION = 7;

/**
 * Must match `LIST_LIMIT_DEFAULT` in the stub, which mirrors the server's
 * `APP_LIMIT_LIST_DEFAULT`.
 */
const LIST_LIMIT_DEFAULT = 25;

const PAGE_SIZE = 1000;

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
    return new Users(
        (new Client())
            ->setEndpoint($stub->endpoint)
            ->setProject('playground')
            ->setKey('playground-api-key')
    );
}

/**
 * Page to the end of the list, counting rows, the way a caller that needs the
 * real number has to. Returns the count and how many requests it cost.
 *
 * @return array{0: int, 1: int} [rows counted, requests spent]
 */
function countByWalking(Users $users, int $pageSize): array
{
    $counted = 0;
    $requests = 0;
    $cursor = null;

    do {
        $queries = [Query::limit($pageSize)];

        if ($cursor !== null) {
            $queries[] = Query::cursorAfter($cursor);
        }

        $page = $users->list(queries: $queries);
        $rows = $page->users;
        $counted += \count($rows);
        $requests++;
        $cursor = $rows === [] ? null : $rows[\array_key_last($rows)]->id;
    } while (\count($rows) === $pageSize);

    return [$counted, $requests];
}

$stub = Stub::start();

try {
    $users = keyedUsers($stub);

    echo "A total that reaches the count limit is a floor\n";

    $page = $users->list(queries: [Query::limit(LIST_LIMIT_DEFAULT)]);

    check(
        "the reported total stops at the count limit (got {$page->total})",
        $page->total === COUNT_LIMIT
    );
    check(
        'the page itself is the size it asked for, not the total',
        \count($page->users) === LIST_LIMIT_DEFAULT
    );

    // The naive reading of that total: 5,000 rows is 200 pages of 25, so page
    // 200 is the last one. The list disagrees — there is a page after it.
    $pastTheCountedEnd = $users->list(queries: [
        Query::limit(LIST_LIMIT_DEFAULT),
        Query::cursorAfter(\sprintf('user-%04d', COUNT_LIMIT)),
    ]);

    check(
        'the list keeps answering with rows past the ones the total accounts for',
        \count($pastTheCountedEnd->users) > 0
    );
    check(
        'and reports the same floor while doing it',
        $pastTheCountedEnd->total === COUNT_LIMIT
    );

    echo "Paging to the end of the list gives the number the total would not\n";

    [$counted, $requests] = countByWalking($users, PAGE_SIZE);

    check(
        "the walk counts every user (got {$counted}, expected " . USER_POPULATION . ')',
        $counted === USER_POPULATION
    );
    check(
        "it costs one request per page (got {$requests})",
        $requests === (int) \ceil(USER_POPULATION / PAGE_SIZE)
    );

    $walk = \array_slice($stub->requests(), -$requests);
    $cursored = \array_filter(
        $walk,
        static fn (array $request): bool => isset($request['queries']['cursorAfter'])
    );

    check(
        'every page after the first asked for a cursor, not an offset',
        \count($cursored) === $requests - 1
    );
    check(
        'no page of the walk used an offset',
        \array_filter($walk, static fn (array $r): bool => isset($r['queries']['offset'])) === []
    );

    echo "A total under the limit is exact, not a floor\n";

    $searched = $users->list(search: 'example');

    check(
        "a narrower list is counted in full (got {$searched->total})",
        $searched->total === SEARCHED_USER_POPULATION
    );
    check(
        'that total is under the count limit, so it needs no "+"',
        $searched->total < COUNT_LIMIT
    );
    check(
        'and it accounts for every row the list returned',
        \count($searched->users) === $searched->total
    );

    echo "Asking for no count answers 0, which is not an empty list\n";

    $uncounted = $users->list(queries: [Query::limit(LIST_LIMIT_DEFAULT)], total: false);

    check(
        "total: false answers a total of 0 (got {$uncounted->total})",
        $uncounted->total === 0
    );
    // The stub answers 0 only when it was asked to skip the count, so the total
    // above is also proof that the SDK sent the param.
    check(
        'the rows still come back',
        \count($uncounted->users) === LIST_LIMIT_DEFAULT
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

echo "OK — a clamped list total is read as a floor, as appwrite/appwrite#15496 intends\n";
