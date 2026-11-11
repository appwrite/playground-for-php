<?php
require __DIR__ . '/global.inc.php';

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\ExecutionMethod;
use Appwrite\Enums\Runtime;
use Appwrite\ID;
use Appwrite\InputFile;
use Appwrite\Permission;
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
 * - deleteTable
 * - listTables
 * - addRow
 * - uploadFile
 * - listFiles
 * - deleteFile
 * - createUser
 * - listUser
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
 * Get a list of all the project users.
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
    'deleteDatabase',
    'createBucket',
    'createFile',
    'listFiles',
    'deleteFile',
    'deleteBucket',
    'createUser',
    'listUsers',
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
