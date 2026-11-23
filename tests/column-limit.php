<?php

/**
 * Checks the client half of appwrite/appwrite#15317: what a create whose
 * columns do not fit answers, and what a caller can do with that answer.
 *
 * A table's columns have to fit the row the database gives it — 65,535 bytes on
 * every SQL adapter, of which the internal columns already spend 1,067 — and
 * there can only be so many of them. Appwrite collapsed both of those into
 * `table_limit_exceeded`, "The maximum number of tables for database '...' has
 * been reached": a limit that does not exist, with the id of the refused table
 * dropped into a template documented as a database's, and the library's
 * explanation of the real limit discarded for that description. A user read it
 * the only way it can be read and went looking for a table quota to raise.
 *
 * What a caller should be able to hold up, and what these tests pin down
 * against `tests/stub-server.php`:
 *
 * - a schema too wide for the row is refused as `column_limit_exceeded`, with
 *   the library's own explanation of which limit it hit;
 * - so is a schema with too many columns — one type covers both, because both
 *   are about the columns of the table being created;
 * - the message does not claim a limit on the number of tables, and
 *   `table_limit_exceeded` does not come back at all;
 * - the refusal is about the request, so the same request with one column fewer
 *   goes through, under the id the refused attempt asked for;
 * - a table that fits reports how much of the row is left (`bytesUsed` out of
 *   `bytesMax`), which is the same arithmetic read before being refused rather
 *   than after.
 *
 * The last block runs the same calls against a stub that answers the way
 * Appwrite did before the fix, so the assertions above are shown to be able to
 * fail.
 *
 * It cannot prove the server maps its own `Limit` exceptions this way — that is
 * what the e2e test in appwrite/appwrite asserts. It proves the SDK surfaces
 * the type and the forwarded message, and that a caller can act on them.
 *
 * Usage: php tests/column-limit.php
 */

declare(strict_types=1);

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Services\TablesDB;

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
require __DIR__ . '/stub.php';

/**
 * Must match `ROW_SIZE_LIMIT` in the stub, which mirrors the `documentSize`
 * limit of utopia-php/database's SQL adapters.
 */
const ROW_SIZE_LIMIT = 65535;

/**
 * Must match `ROW_SIZE_INTERNAL` in the stub: what the internal columns spend
 * of the row before the caller's first column.
 */
const ROW_SIZE_INTERNAL = 1067;

/**
 * Must match `COLUMN_LIMIT` in the stub: the adapter's limit on how many
 * columns one table can hold.
 */
const COLUMN_LIMIT = 1017;

/**
 * Must match `TABLE_ID` in the stub: the id every table it creates comes back
 * with, whatever id the create asked for.
 */
const TABLE_ID = 'playground-table';

/**
 * Characters per wide column. 4,000 of them spend 16,002 bytes of the row, so
 * four fit and five do not.
 */
const WIDE_COLUMN_SIZE = 4000;

const DATABASE_ID = 'playground-db';

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
 * `$count` string columns of `$size` characters each.
 *
 * @return list<array<string, mixed>>
 */
function stringColumns(int $count, int $size): array
{
    $columns = [];

    for ($i = 0; $i < $count; $i++) {
        $columns[] = ['key' => "wide{$i}", 'type' => 'string', 'size' => $size, 'required' => false];
    }

    return $columns;
}

/**
 * Try to create a table and hand back the refusal, or `null` if there was none.
 *
 * @param list<array<string, mixed>> $columns
 */
function refusalFor(TablesDB $tablesDB, string $tableId, array $columns): ?AppwriteException
{
    try {
        $tablesDB->createTable(
            databaseId: DATABASE_ID,
            tableId: $tableId,
            name: 'Wide',
            columns: $columns,
        );

        return null;
    } catch (AppwriteException $e) {
        return $e;
    }
}

/**
 * How many string columns of `WIDE_COLUMN_SIZE` fit the row, and one more than
 * that: 16,002 bytes each out of the 64,468 the internal columns leave.
 */
$perColumn = WIDE_COLUMN_SIZE * 4 + 2;
$columnsThatFit = \intdiv(ROW_SIZE_LIMIT - ROW_SIZE_INTERNAL, $perColumn);
$columnsThatDoNot = $columnsThatFit + 1;

$stub = Stub::start();

try {
    $tablesDB = keyedTablesDB($stub);

    echo "A schema too wide for the row names the limit it hit\n";

    $refusal = refusalFor($tablesDB, 'wide-table', stringColumns($columnsThatDoNot, WIDE_COLUMN_SIZE));

    check(
        "{$columnsThatDoNot} columns of " . WIDE_COLUMN_SIZE . ' characters are refused',
        $refusal !== null
    );
    check(
        'with a 400, because the schema is the caller\'s to fix (got '
            . ($refusal === null ? 'nothing' : (string) $refusal->getCode()) . ')',
        $refusal !== null && $refusal->getCode() === 400
    );
    check(
        'as column_limit_exceeded (got "' . ($refusal === null ? '' : $refusal->getType()) . '")',
        $refusal !== null && $refusal->getType() === 'column_limit_exceeded'
    );
    check(
        'not as a limit on how many tables the database holds',
        $refusal !== null && $refusal->getType() !== 'table_limit_exceeded'
    );
    check(
        'the message forwards the library\'s explanation, naming the row limit',
        $refusal !== null
            && \str_contains($refusal->getMessage(), 'Document size limit of ' . ROW_SIZE_LIMIT)
    );
    check(
        'and claims no quota on tables the caller could ask to have raised',
        $refusal !== null && !\str_contains($refusal->getMessage(), 'maximum number of tables')
    );

    echo "Too many columns is the same refusal, so one branch handles both\n";

    $tooMany = refusalFor($tablesDB, 'many-columns', stringColumns(COLUMN_LIMIT + 1, 1));

    check(
        'a schema past the column count limit is refused',
        $tooMany !== null
    );
    check(
        'with the same type as the one past the row width (got "'
            . ($tooMany === null ? '' : $tooMany->getType()) . '")',
        $tooMany !== null && $tooMany->getType() === 'column_limit_exceeded'
    );
    check(
        'and a message that names the count rather than the row size',
        $tooMany !== null
            && \str_contains($tooMany->getMessage(), 'Attribute limit of ' . COLUMN_LIMIT)
    );

    echo "The refusal leaves nothing behind, so the retry can reuse the id\n";

    $missing = null;

    try {
        $tablesDB->getTable(DATABASE_ID, TABLE_ID);
    } catch (AppwriteException $e) {
        $missing = $e;
    }

    check(
        'the half-created table was rolled back (got '
            . ($missing === null ? 'a table' : (string) $missing->getCode()) . ')',
        $missing !== null && $missing->getCode() === 404
    );

    $fits = $tablesDB->createTable(
        databaseId: DATABASE_ID,
        tableId: 'wide-table',
        name: 'Wide',
        columns: stringColumns($columnsThatFit, WIDE_COLUMN_SIZE),
    );

    check(
        "the same request with {$columnsThatFit} columns goes through",
        \count($fits->columns) === $columnsThatFit
    );
    check(
        "it reports what it spends of the row ({$fits->bytesUsed} of {$fits->bytesMax})",
        $fits->bytesMax === ROW_SIZE_LIMIT
            && $fits->bytesUsed === ROW_SIZE_INTERNAL + $columnsThatFit * $perColumn
    );
    check(
        'with less left over than one more such column would need',
        $fits->bytesMax - $fits->bytesUsed < $perColumn
    );

    // Proof that the 404 above says something: a table that does exist under
    // that id is refused as a duplicate rather than created again.
    $duplicate = refusalFor($tablesDB, 'wide-table', stringColumns(1, 255));

    check(
        'and an id that is taken answers 409, not a second table',
        $duplicate !== null && $duplicate->getCode() === 409
    );

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

/**
 * The same create against the answer Appwrite gave before #15317, to show the
 * assertions above can fail: a table quota that does not exist, named with the
 * refused table's id, and no word on what did not fit.
 */
$pre = Stub::start(['STUB_COLUMN_LIMIT_PRE_15317' => '1']);

try {
    echo "Before the fix, the same schema was refused as a table quota\n";

    $refusal = refusalFor(keyedTablesDB($pre), 'wide-table', stringColumns($columnsThatDoNot, WIDE_COLUMN_SIZE));

    check(
        'the old answer was table_limit_exceeded (got "'
            . ($refusal === null ? '' : $refusal->getType()) . '")',
        $refusal !== null && $refusal->getType() === 'table_limit_exceeded'
    );
    check(
        'it claimed a maximum number of tables per database',
        $refusal !== null && \str_contains($refusal->getMessage(), 'maximum number of tables')
    );
    check(
        'it named the table it had just refused as that database',
        $refusal !== null && \str_contains($refusal->getMessage(), "'" . TABLE_ID . "'")
    );
    check(
        'and said nothing about the columns that did not fit',
        $refusal !== null && !\str_contains($refusal->getMessage(), 'Document size limit')
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

echo "OK — a schema that does not fit names its own limit, as appwrite/appwrite#15317 intends\n";
