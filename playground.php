<?php
require __DIR__ . '/global.inc.php';

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\ExecutionMethod;
use Appwrite\Enums\Runtime;
use Appwrite\ID;
use Appwrite\InputFile;
use Appwrite\Permission;
use Appwrite\Query;
use Appwrite\Role;
use Appwrite\Services\Account;
use Appwrite\Services\Functions;
use Appwrite\Services\Storage;
use Appwrite\Services\TablesDB;
use Appwrite\Services\Users;

$client = (new Client())
    ->setEndpoint(ENDPOINT)
    ->setProject(PROJECT_ID)
    // ->setJWT('jwt') // Use this to authenticate with JWT generated from client
    ->setKey(API_KEY);

$databaseId = "";
$tableId = "";
$bucketId = "";
$fileId = "";
$functionId = "";

$tablesDB = new TablesDB($client);
$storage = new Storage($client);
$functions = new Functions($client);
$users = new Users($client);
$account = new Account($client);

/**
 * Covered API methods
 * - createDatabase
 * - deleteDatabase
 * - createTable
 * - createTable past the row limit
 * - deleteTable
 * - listTables
 * - addRow
 * - uploadFile
 * - listFiles
 * - deleteFile
 * - createUser
 * - listUser
 * - listUsers past the counted total
 * - getAccount
 * - createRecovery
 * - createSessionFromToken
 */

/**
 * Create a new Database.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#create
 * @throws Exception
 */
function createDatabase(): array
{
    global $tablesDB, $databaseId;

    $response = $tablesDB->create(
        databaseId: ID::unique(),
        name: "Test Database"
    );

    $databaseId = $response->id;

    return [
        'call' => 'api.createDatabase',
        'response' => $response,
    ];
}

/**
 * Create a new Table.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#createTable
 * @throws Exception
 */
function createTable(): array
{
    global $tablesDB, $databaseId, $tableId;

    $response = $tablesDB->createTable(
        databaseId: $databaseId,
        tableId: ID::unique(),
        name: 'table',
        permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users()),
        ]
    );

    $tableId = $response->id;

    $response1 = $tablesDB->createStringColumn(
        $databaseId,
        $tableId,
        key: 'name',
        size: 255,
        required: true,
    );
    $response2 = $tablesDB->createIntegerColumn(
        $databaseId,
        $tableId,
        key: 'release_year',
        required: true,
        min: 0,
        max: 9999,
    );

    return [
        'call' => 'api.createTable',
        'response' => $response,
    ];
}

/**
 * Get a list of all the user tables.
 * On admin mode, this endpoint will return a list of all of the project tables.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#listTables
 * @return array
 * @throws Exception
 */
function listTables(): array
{
    global $tablesDB, $databaseId;

    $response = $tablesDB->listTables($databaseId);

    return [
        'call' => 'api.listTables',
        'response' => $response,
    ];
}

/**
 * Create a new Row.
 * Before using this route, you should create a new table resource
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#createRow
 * @return array
 * @throws Exception
 */
function addRow(): array
{
    global $tablesDB, $databaseId, $tableId;

    $response = $tablesDB->createRow(
        $databaseId,
        $tableId,
        rowId: ID::unique(),
        data: [
            'name' => 'Spider Man',
            'release_year' => 1920,
        ],
        permissions: [
            Permission::read(Role::any()),
            Permission::update(Role::users()),
            Permission::delete(Role::users()),
        ]
    );

    return [
        'call' => 'api.addRow',
        'response' => $response,
    ];
}

/**
 * Delete table
 * Delete a table by it's unique id.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#deleteTable
 * @return array
 * @throws Exception
 */
function deleteTable(): array
{
    global $tablesDB, $databaseId, $tableId;

    $response = $tablesDB->deleteTable($databaseId, $tableId);

    return [
        'call' => 'api.deleteTable',
        'response' => $response,
    ];
}

/**
 * Create a table whose columns do not fit a row, and read what comes back.
 *
 * A table's columns have to fit the row its database gives it — 65,535 bytes,
 * of which the internal columns (`_uid`, the timestamps, `_permissions` and
 * friends) already spend 1,067. A string column spends four bytes per
 * character for as long as it stays in the row, so the five 4,000-character
 * ones below ask for 80,010 bytes of the 64,468 that are left.
 *
 * Appwrite used to answer that with `table_limit_exceeded`: "The maximum number
 * of tables for database '...' has been reached" — a limit that does not exist,
 * naming the table it had just refused where it promised a database. The
 * library's explanation, the only part that said what was actually wrong, was
 * dropped for that description.
 * [appwrite/appwrite#15317](https://github.com/appwrite/appwrite/pull/15317)
 * maps the refusal to the limit it really is and forwards the explanation, so
 * the answer now reads:
 *
 *     400 column_limit_exceeded
 *     Document size limit of 65535 exceeded. Cannot create collection.
 *
 * What that means for code like this one: a `400 column_limit_exceeded` is
 * about the schema in the request, not about a quota on the project or the
 * database. Dropping a column or shrinking a string size gets the same request
 * through; there is no larger table allowance to ask for. The same type covers
 * a schema with too many columns ("Attribute limit of 1017 exceeded"), and the
 * deprecated Databases API spells it `attribute_limit_exceeded`.
 *
 * `table_limit_exceeded` and `collection_limit_exceeded` were retired by the
 * same PR — nothing produces them any more, so code branching on either is
 * branching on an answer that cannot arrive.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#createTable
 * @throws Exception
 */
function createTablePastTheRowLimit(): array
{
    global $tablesDB, $databaseId;

    // 4,000 characters of utf8mb4 spend 16,002 bytes of the row each. Five of
    // them do not fit; a string past ~16,000 characters would, because it stops
    // living in the row and starts costing a 20-byte pointer instead.
    $wide = [];

    for ($i = 0; $i < 5; $i++) {
        $wide[] = ['key' => "wide{$i}", 'type' => 'string', 'size' => 4000, 'required' => false];
    }

    $tableId = ID::unique();
    $refused = null;

    try {
        $table = $tablesDB->createTable(
            databaseId: $databaseId,
            tableId: $tableId,
            name: 'Wide',
            columns: $wide,
        );

        // Not every adapter limits the row width, so a create that went through
        // is an answer too — clean it up and let the result say as much.
        $tablesDB->deleteTable($databaseId, $table->id);
    } catch (AppwriteException $e) {
        // `attribute_limit_exceeded` is the same refusal on the Databases API.
        if (
            $e->getCode() !== 400
            || !\in_array($e->getType(), ['column_limit_exceeded', 'attribute_limit_exceeded'], true)
        ) {
            throw $e;
        }

        $refused = [
            'code' => $e->getCode(),
            'type' => $e->getType(),
            // The library's own explanation, forwarded since #15317: it names
            // the limit that was hit, which is the part worth acting on.
            'message' => $e->getMessage(),
        ];
    }

    // A refused create is rolled back whole, so the id the first attempt asked
    // for is still free: one column fewer, same request, and it goes through.
    $fits = $tablesDB->createTable(
        databaseId: $databaseId,
        tableId: $tableId,
        name: 'Wide',
        columns: \array_slice($wide, 0, 4),
    );

    $tablesDB->deleteTable($databaseId, $fits->id);

    return [
        'call' => 'api.createTablePastTheRowLimit',
        'response' => [
            'refused' => $refused,
            'columnsThatFit' => \count($fits->columns),
            // What the table reports about the row it lives in, which is where
            // to look before adding a column rather than after being refused.
            'bytesUsed' => $fits->bytesUsed,
            'bytesMax' => $fits->bytesMax,
            'bytesFree' => $fits->bytesMax - $fits->bytesUsed,
        ],
    ];
}

/**
 * Delete Database
 * Delete a database by it's unique id.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#delete
 * @return array
 * @throws Exception
 */
function deleteDatabase(): array
{
    global $tablesDB, $databaseId;

    $response = $tablesDB->delete($databaseId);

    return [
        'call' => 'api.deleteDatabase',
        'response' => $response,
    ];
}

/**
 * Create a bucket
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/storage#createBucket
 * @return array
 * @throws Exception
 */
function createBucket(): array
{
    global $storage, $bucketId;

    $response = $storage->createBucket(
        bucketId: ID::unique(),
        name: 'test bucket',
        permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users()),
        ],
        fileSecurity: true,
    );

    $bucketId = $response->id;

    return [
        'call' => 'api.createBucket',
        'response' => $response,
    ];
}

/**
 * Create a new file.
 * The user who creates the file will automatically be assigned to read and write
 * access unless he has passed custom values for read and write arguments.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/storage#createFile
 * @return array
 * @throws Exception
 */
function createFile(): array
{
    global $storage, $bucketId, $fileId;

    $response = $storage->createFile(
        $bucketId,
        fileId: ID::unique(),
        file: InputFile::withPath(__DIR__ . '/test.txt'),
        permissions: [
            Permission::read(Role::any()),
        ]
    );

    $fileId = $response->id;

    return [
        'call' => 'api.createFile',
        'response' => $response,
    ];
}

/**
 * Get a list of all the user files.
 * You can use the query params to filter your results. On admin mode,
 * this endpoint will return a list of all of the project files.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/storage#listFiles
 * @return array
 * @throws Exception
 */
function listFiles(): array
{
    global $storage, $bucketId;

    $response = $storage->listFiles($bucketId);

    return [
        'call' => 'api.listFiles',
        'response' => $response,
    ];
}

/**
 * Delete a file by its unique ID.
 * Only users with write permissions have access to delete this resource.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/storage#deleteFile
 * @return array
 * @throws Exception
 */
function deleteFile(): array
{
    global $storage, $bucketId, $fileId;

    $response = $storage->deleteFile($bucketId, $fileId);

    return [
        'call' => 'api.deleteFile',
        'response' => $response,
    ];
}

/**
 * Delete a bucket by its unique ID.
 * Only users with write permissions have access to delete this resource.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/storage#deleteBucket
 * @return array
 * @throws Exception
 */
function deleteBucket(): array
{
    global $storage, $bucketId;

    $response = $storage->deleteBucket($bucketId);

    return [
        'call' => 'api.deleteBucket',
        'response' => $response,
    ];
}

/**
 * Create a new user.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/users#create
 * @return array
 * @throws Exception
 */
function createUser(): array
{
    global $users;

    $suffix = \time();

    $response = $users->create(
        userId: ID::unique(),
        email: "email{$suffix}@example.com",
        password: 'password',
        name: "Example {$suffix}"
    );

    return [
        'call' => 'api.createUser',
        'response' => $response,
    ];
}

/**
 * How far a list route counts before it gives up and answers with what it
 * reached: the server's `APP_LIMIT_COUNT`.
 */
const APP_LIMIT_COUNT = 5000;

/**
 * Page size for the count below. Counting wants the biggest page it can get —
 * every page is a round trip — unlike a page someone is going to look at.
 */
const COUNT_PAGE_SIZE = 1000;

/**
 * Get a list of all the project users.
 *
 * The `total` that comes back is a count with a budget, not a count: see
 * `countUsersPastTheCountLimit()` below before showing it to anyone or paging
 * with it.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/users#list
 * @throws Exception
 */
function listUsers(): array
{
    global $users;

    $response = $users->list();

    return [
        'call' => 'api.listUsers',
        'response' => $response,
    ];
}

/**
 * Count the project's users, including the ones past the point the server
 * stopped counting.
 *
 * A list `total` is a count with a budget: the server counts at most
 * `APP_LIMIT_COUNT` (5,000) rows and answers with whatever it reached, so a
 * total sitting at that number means "at least 5,000 users", not "5,000 users".
 * A project with 50,000 of them reports 5,000, and the 200 pages of 25 that
 * number divides into end on a page that is not the last one.
 *
 * [appwrite/appwrite#15496](https://github.com/appwrite/appwrite/pull/15496)
 * fixed the console's half of this: a clamped total now reads `5,000+`, and no
 * page count is derived from it. A server reading the same list has the same
 * three options:
 *
 * - report the number as the floor it is (`5,000+`), which costs nothing;
 * - page to the end of the list for the exact number, which the count will not
 *   give. The walk below asks for the next page with `Query::cursorAfter()`
 *   rather than a growing `Query::offset()`, so each page starts where the last
 *   one ended instead of re-scanning every row ahead of it;
 * - ask for no count at all with `total: false`, which skips the count query.
 *   The `0` that answers means "not counted"; reading it as "no users" is the
 *   same mistake in the other direction.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/users#list
 * @throws Exception
 */
function countUsersPastTheCountLimit(): array
{
    global $users;

    $page = $users->list(queries: [Query::limit(COUNT_PAGE_SIZE)]);
    $reported = $page->total;
    $requests = 1;

    // Under the budget the count is exact, so it is the answer. At it, paging
    // to the end of the list is the only way to learn the real number: a full
    // page means there is more list past what was counted.
    $exact = $reported;

    if ($reported >= APP_LIMIT_COUNT) {
        $exact = \count($page->users);

        while (\count($page->users) === COUNT_PAGE_SIZE) {
            $rows = $page->users;

            $page = $users->list(queries: [
                Query::limit(COUNT_PAGE_SIZE),
                Query::cursorAfter($rows[\array_key_last($rows)]->id),
            ]);

            $exact += \count($page->users);
            $requests++;
        }
    }

    $uncounted = $users->list(queries: [Query::limit(1)], total: false);

    return [
        'call' => 'api.countUsersPastTheCountLimit',
        'response' => [
            'reportedTotal' => $reported,
            // How the console shows this number since #15496.
            'reportedTotalLabel' => $reported >= APP_LIMIT_COUNT
                ? \number_format($reported) . '+'
                : \number_format($reported),
            'exactTotal' => $exact,
            'requestsToCount' => $requests,
            // `total: false` means no count was taken, not that there is nothing
            // to count: rows still come back.
            'uncountedTotal' => $uncounted->total,
            'uncountedRows' => \count($uncounted->users),
        ],
    ];
}

/**
 * Get an account of authenticated user. Works only with JWT
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/account#get
 * @throws Exception
 */
function getAccount(): array
{
    global $account;

    $response = $account->get();

    return [
        'call' => 'api.getAccount',
        'response' => $response,
    ];
}

/**
 * Email a password recovery link to an address.
 *
 * A guest route: it needs the project, and neither an API key nor a session.
 * The `X-Appwrite-Project` header the SDK sends for it is what scopes its abuse
 * limit — the counter is kept per project and address, so the handful of
 * attempts one project is allowed for an address is its own, and another
 * project asking to recover the same address still gets its full share.
 *
 * Two answers are worth handling rather than letting them abort a flow:
 * `429` once the project has spent its share for the address (retry later), and
 * `404` when no account in the project uses the address.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/account#createRecovery
 * @throws Exception
 */
function createRecovery(): array
{
    global $account;

    try {
        $response = $account->createRecovery(
            email: 'recovery@example.com',
            url: 'https://example.com/recover'
        );
    } catch (AppwriteException $e) {
        if ($e->getCode() !== 429 && $e->getCode() !== 404) {
            throw $e;
        }

        $response = [
            'recoveryRequested' => false,
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
        ];
    }

    return [
        'call' => 'api.createRecovery',
        'response' => $response,
    ];
}

/**
 * Log a user in from a token secret, the way a server relays a login.
 *
 * `users->createToken()` mints a short secret for a user with the API key, and
 * `account->createSession()` exchanges it for a session. The exchange route is
 * where a wrong secret is answered, and since
 * [appwrite/appwrite#15470](https://github.com/appwrite/appwrite/pull/15470) a
 * wrong guess counts against the per-user abuse limit for *every* caller — an
 * API key included. A key is trusted with throughput, not with the inbox or
 * handset the secret was sent to, and a six digit code is only 10^6 guesses, so
 * the limit is the only thing standing between a key and any account.
 *
 * What that means for code like this one: handle `401` (wrong or expired
 * secret) and `429` (the user's share of attempts is spent) instead of retrying
 * the exchange. Retrying a secret the user mistyped now spends the attempts of
 * the user it names, so a retry loop locks that user out of signing in.
 *
 * The same applies to every route that confirms a secret with the caller's
 * copy: `updateRecoveryOTP()`, `updatePhoneSession()`,
 * `updateMagicURLSession()`, `updateMFAChallenge()` and `updateRecovery()`.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/account#createSession
 * @throws Exception
 */
function createSessionFromToken(): array
{
    global $users, $account;

    $suffix = \time();

    $user = $users->create(
        userId: ID::unique(),
        email: "session{$suffix}@example.com",
        password: 'password',
        name: "Session {$suffix}"
    );

    $token = $users->createToken(userId: $user->id);

    try {
        $response = $account->createSession(
            userId: $token->userId,
            secret: $token->secret
        );
    } catch (AppwriteException $e) {
        if ($e->getCode() !== 401 && $e->getCode() !== 429) {
            throw $e;
        }

        // 401: the secret is wrong or has expired — ask the user for a new one.
        // 429: this user's attempts are spent — wait, do not guess again.
        $response = [
            'sessionCreated' => false,
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
        ];
    }

    return [
        'call' => 'api.createSessionFromToken',
        'response' => $response,
    ];
}

/**
 * Create a function
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#create
 * @throws Exception
 */
function createFunction(): array
{
    global $functions, $functionId;

    $response = $functions->create(
        functionId: ID::unique(),
        name: 'Test Function',
        runtime: Runtime::PHP84(),
        execute: [Role::any()],
    );

    $functionId = $response->id;

    return [
        'call' => 'api.createFunction',
        'response' => $response,
    ];
}

/**
 * Create a deployment
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#createDeployment
 * @throws Exception
 */
function createDeployment(): array
{
    global $functions, $functionId;

    $response = $functions->createDeployment(
        functionId: $functionId,
        code: InputFile::withPath(__DIR__ . '/resources/code.tar.gz'),
        activate: true,
        entrypoint: "src/index.php",
        commands: "composer install"
    );

    // wait for deployment to be ready
    sleep(5);

    return [
        'call' => 'api.createDeployment',
        'response' => $response,
    ];
}

/**
 * Create sync execution
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#createExecution
 * @throws Exception
 */
function createSyncExecution(): array
{
    global $functions, $functionId;

    $response = $functions->createExecution(
        functionId: $functionId,
        body: "",
        async: false,
        xpath: "/",
        method: ExecutionMethod::GET(),
        headers: [],
    );

    return [
        'call' => 'api.createExecution',
        'response' => $response,
    ];
}

/**
 * Create async execution
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#createExecution
 * @throws Exception
 */
function createAsyncExecution(): array
{
    global $functions, $functionId;

    $response = $functions->createExecution(
        functionId: $functionId,
        body: "",
        async: true,
        xpath: "/",
        method: ExecutionMethod::GET(),
        headers: [],
    );

    // wait for 2 seconds to ensure execution is finished
    sleep(2);

    $asyncResponse = $functions->getExecution($functionId, $response->id);

    return [
        'call' => 'api.createExecution',
        'response' => $asyncResponse,
    ];
}

/**
 * List functions
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#list
 * @throws Exception
 */
function listFunctions(): array
{
    global $functions;

    $response = $functions->list();

    return [
        'call' => 'api.listFunctions',
        'response' => $response,
    ];
}

/**
 * Delete a function
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#delete
 * @throws Exception
 */
function deleteFunction(): array
{
    global $functions, $functionId;

    $response = $functions->delete($functionId);

    return [
        'call' => 'api.deleteFunction',
        'response' => $response,
    ];
}

/**
 * Execute all functions, collect their return values
 * and print everything at the end.
 */
$ret = [];
$methods = [
    'createDatabase',
    'createTable',
    'listTables',
    'addRow',
    'deleteTable',
    'createTablePastTheRowLimit',
    'deleteDatabase',
    'createBucket',
    'createFile',
    'listFiles',
    'deleteFile',
    'deleteBucket',
    'createUser',
    'listUsers',
    'countUsersPastTheCountLimit',
    'createRecovery',
    'createSessionFromToken',
    'createFunction',
    'createDeployment',
    'createSyncExecution',
    'createAsyncExecution',
    'listFunctions',
    'deleteFunction',
    // 'getAccount' // works only with JWT
];

foreach ($methods as $method) {
    try {
        if (\function_exists($method)) {
            $ret[] = $method();
        }
    } catch (Exception $e) {
        \print_r($e->getMessage());
        \print_r($e->getTraceAsString());
        \print_r("");
    }
}

appwriteDebug($ret);
