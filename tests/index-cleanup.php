<?php

/**
 * Checks the client half of appwrite/appwrite#15471: what it takes to clear away
 * an index the index worker could not build, and what an index looks like after
 * one of its columns is dropped.
 *
 * Index work is asynchronous. `createIndex()`, `deleteIndex()` and
 * `deleteColumn()` are all answered as accepted, and a worker does the schema
 * work afterwards, so reading the index back is the only report of how it went.
 * Two of those reports used to be dead ends:
 *
 * - an index that never finished creating has nothing in the schema to drop, and
 *   the worker read that absence as a failed delete. It recorded the index as
 *   `stuck` and left the document behind; every retry took the same path, so the
 *   index could not be removed at all. The worker now treats a missing schema
 *   index as the state the delete asked for and drops the document anyway.
 * - dropping a column an index covered pruned the column out of the index by
 *   value rather than by position, so an index whose lengths repeat lost every
 *   length equal to the removed one — leaving it covering more columns than it
 *   had lengths for, and the table refusing later column changes.
 *
 * What these tests pin down against `tests/stub-server.php`:
 *
 * - an index the worker could not build reads as `failed` with an error, and is
 *   a document in the table's index list rather than nothing;
 * - one `deleteIndex()` is enough to clear it away: the index stops being
 *   readable, which is what a finished delete looks like from here;
 * - dropping an indexed column leaves the index over the columns that remain,
 *   each still carrying its own length and order;
 * - and, against a stub asked to behave the way the worker did before the fix,
 *   that both of those checks notice: the delete leaves a `stuck` index that a
 *   retry cannot shift, and the prune leaves the index without its lengths.
 *
 * It cannot prove the worker does the right thing — that is what the e2e tests
 * in appwrite/appwrite assert. It proves the SDK surfaces these statuses and
 * that a caller driving the cleanup can tell the two outcomes apart.
 *
 * Usage: php tests/index-cleanup.php
 */

declare(strict_types=1);

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\TablesDBIndexType;
use Appwrite\Models\ColumnIndex;
use Appwrite\Services\TablesDB;

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
require __DIR__ . '/stub.php';

/**
 * The database and table the stub answers for.
 */
const DATABASE_ID = 'playground-db';
const TABLE_ID = 'playground-table';

/**
 * Length indexed from each column. Both columns of the index below use it, so
 * nothing tells the first length from the second one by value — which is what
 * pruning by value lost.
 */
const INDEX_LENGTH = 64;

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

function keyedTablesDB(Stub $stub): TablesDB
{
    return new TablesDB(
        (new Client())
            ->setEndpoint($stub->endpoint)
            ->setProject('playground')
            ->setKey('playground-api-key')
    );
}

/**
 * Read an index back, or `null` once it is gone.
 *
 * A delete is answered before the worker has run, so an index that stopped
 * being readable is the only sign the delete went through.
 */
function readIndex(TablesDB $tablesDB, string $key): ?ColumnIndex
{
    try {
        return $tablesDB->getIndex(DATABASE_ID, TABLE_ID, $key);
    } catch (AppwriteException $e) {
        if ($e->getCode() === 404) {
            return null;
        }

        throw $e;
    }
}

/**
 * The columns and the index the checks below start from: an index over two
 * columns, with a length and an order for each.
 */
function createPairIndex(TablesDB $tablesDB): ColumnIndex
{
    foreach (['first', 'second'] as $column) {
        $tablesDB->createStringColumn(DATABASE_ID, TABLE_ID, key: $column, size: 128, required: false);
    }

    return $tablesDB->createIndex(
        DATABASE_ID,
        TABLE_ID,
        key: 'pair',
        type: TablesDBIndexType::KEY(),
        columns: ['first', 'second'],
        orders: ['ASC', 'ASC'],
        lengths: [INDEX_LENGTH, INDEX_LENGTH],
    );
}

/**
 * An index the worker cannot build: unique over a column whose rows repeat a
 * value. The route accepts it, and the failure only shows on the way back.
 */
function createFailingIndex(TablesDB $tablesDB): ColumnIndex
{
    return $tablesDB->createIndex(
        DATABASE_ID,
        TABLE_ID,
        key: 'uniqueFirst',
        type: TablesDBIndexType::UNIQUE(),
        columns: ['first'],
    );
}

$stub = Stub::start();

try {
    $tablesDB = keyedTablesDB($stub);

    echo "An index is accepted before the worker has had a say\n";

    $accepted = createPairIndex($tablesDB);

    check(
        "the create answers with a status still in motion (got {$accepted->status})",
        $accepted->status === 'processing'
    );

    $pair = readIndex($tablesDB, 'pair');

    check(
        'reading it back finds the index the worker settled',
        $pair !== null && $pair->status === 'available'
    );
    check(
        'with a length and an order for each of its columns',
        $pair !== null
            && $pair->columns === ['first', 'second']
            && $pair->lengths === [INDEX_LENGTH, INDEX_LENGTH]
            && $pair->orders === ['ASC', 'ASC']
    );

    echo "An index the worker could not build is a document, not nothing\n";

    createFailingIndex($tablesDB);
    $failed = readIndex($tablesDB, 'uniqueFirst');

    check(
        'it reads as failed rather than disappearing',
        $failed !== null && $failed->status === 'failed'
    );
    check(
        'and says why',
        $failed !== null && $failed->error !== ''
    );
    check(
        'the table lists it alongside the index that worked',
        \array_map(
            static fn (ColumnIndex $index): string => $index->key,
            $tablesDB->listIndexes(DATABASE_ID, TABLE_ID)->indexes
        ) === ['pair', 'uniqueFirst']
    );

    echo "One delete clears it away\n";

    $tablesDB->deleteIndex(DATABASE_ID, TABLE_ID, 'uniqueFirst');
    $afterDelete = readIndex($tablesDB, 'uniqueFirst');

    check(
        'the index stops being readable, which is a delete that finished',
        $afterDelete === null
    );
    check(
        'and the table no longer lists it',
        \array_map(
            static fn (ColumnIndex $index): string => $index->key,
            $tablesDB->listIndexes(DATABASE_ID, TABLE_ID)->indexes
        ) === ['pair']
    );

    echo "Dropping an indexed column takes that column's length and order with it\n";

    $tablesDB->deleteColumn(DATABASE_ID, TABLE_ID, 'first');
    $pruned = readIndex($tablesDB, 'pair');

    check(
        'the index survives over the column that remains',
        $pruned !== null && $pruned->columns === ['second']
    );
    check(
        'carrying one length for that one column',
        $pruned !== null && $pruned->lengths === [INDEX_LENGTH]
    );
    check(
        'and one order',
        $pruned !== null && $pruned->orders === ['ASC']
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

/**
 * The same two reads against a stub standing in for the worker as it behaved
 * before the fix. Without this, a stub that simply never fails would pass the
 * checks above while saying nothing.
 */
$stub = Stub::start(['STUB_INDEX_PRE_15471' => '1']);

try {
    $tablesDB = keyedTablesDB($stub);

    echo "Before the fix, the failed index could not be deleted at all\n";

    createPairIndex($tablesDB);
    createFailingIndex($tablesDB);

    $tablesDB->deleteIndex(DATABASE_ID, TABLE_ID, 'uniqueFirst');
    $afterDelete = readIndex($tablesDB, 'uniqueFirst');

    check(
        'the delete leaves the index behind as stuck',
        $afterDelete !== null && $afterDelete->status === 'stuck'
    );

    $tablesDB->deleteIndex(DATABASE_ID, TABLE_ID, 'uniqueFirst');

    check(
        'and asking again does not shift it',
        readIndex($tablesDB, 'uniqueFirst') !== null
    );

    echo "Before the fix, pruning by value lost the lengths it could not tell apart\n";

    $tablesDB->deleteColumn(DATABASE_ID, TABLE_ID, 'first');
    $pruned = readIndex($tablesDB, 'pair');

    check(
        'the index still covers the remaining column',
        $pruned !== null && $pruned->columns === ['second']
    );
    check(
        'but both equal lengths went with the dropped one',
        $pruned !== null && $pruned->lengths === []
    );
    check(
        'leaving the index describing more columns than it has lengths for',
        $pruned !== null && \count($pruned->lengths) !== \count($pruned->columns)
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

echo "OK — a failed index is cleared away by one delete and an indexed column "
    . "takes its own length with it, as appwrite/appwrite#15471 intends\n";
