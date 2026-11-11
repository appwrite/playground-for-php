<?php
require __DIR__ . '/global.inc.php';

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

$tableId = "";
$databaseId = "";
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
 * - listRows
 * - uploadFile
 * - listFiles
 * - deleteFile
 * - createUser
 * - listUser
 * - getAccount
 */

/**
 * Create a new Database.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesDB#create
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
* @see https://appwrite.io/docs/references/cloud/server-php/tablesDB#createTable
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

    $tablesDB->createStringColumn(
        $databaseId,
        $tableId,
        key: 'name',
        size: 255,
        required: true,
    );
    $tablesDB->createIntegerColumn(
        $databaseId,
        $tableId,
        key: 'release_year',
        required: true,
        min: 0,
        max: 9999,
    );

    // Columns are created asynchronously, so a row can only be written once the
    // server reports every column as available.
    awaitColumns(['name', 'release_year']);

    return [
        'call' => 'api.createTable',
        'response' => $response,
    ];
}

/**
 * Wait until every given column of the current table is available.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesDB#listColumns
 * @param array<int, string> $keys
 * @throws Exception
 */
function awaitColumns(array $keys, int $timeout = 30): void
{
    global $tablesDB, $databaseId, $tableId;

    $deadline = \time() + $timeout;

    do {
        $statuses = [];

        foreach ($tablesDB->listColumns($databaseId, $tableId)->columns as $column) {
            $statuses[$column['key'] ?? ''] = $column['status'] ?? 'unknown';
        }

        $pending = \array_filter(
            $keys,
            fn (string $key): bool => ($statuses[$key] ?? 'unknown') !== 'available'
        );

        if (\count($pending) === 0) {
            return;
        }

        \sleep(1);
    } while (\time() < $deadline);

    throw new Exception('Columns did not become available in time: ' . \implode(', ', $pending));
}

/**
 * Get a list of all the user tables.
 * On admin mode, this endpoint will return a list of all of the project tables.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesDB#listTables
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
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesDB#createRow
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
 * Get a list of all the rows of a table.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesDB#listRows
 * @return array
 * @throws Exception
 */
function listRows(): array
{
    global $tablesDB, $databaseId, $tableId;

    $response = $tablesDB->listRows($databaseId, $tableId);

    return [
        'call' => 'api.listRows',
        'response' => $response,
    ];
}

/**
 * Delete table
 * Delete a table by it's unique id.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesDB#deleteTable
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
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesDB#delete
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
 * @see https://appwrite.io/docs/references/cloud/server-php/storage#createFile
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
 * @see https://appwrite.io/docs/references/cloud/server-php/storage#listFiles
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
 * @see https://appwrite.io/docs/references/cloud/server-php/storage#deleteFile
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
 * The order the playground runs its examples in. `tests/smoke.php` drives the
 * very same list against a stub of the Appwrite API.
 *
 * @return array<int, string>
 */
function playgroundMethods(): array
{
    return [
        'createDatabase',
        'createTable',
        'listTables',
        'addRow',
        'listRows',
        'deleteTable',
        'deleteDatabase',
        'createBucket',
        'createFile',
        'listFiles',
        'deleteFile',
        'deleteBucket',
        'createUser',
        'listUsers',
        'createFunction',
        'createDeployment',
        'createSyncExecution',
        'createAsyncExecution',
        'listFunctions',
        'deleteFunction',
        // 'getAccount' // works only with JWT
    ];
}

/**
 * Execute all functions, collect their return values
 * and print everything at the end.
 *
 * Defining `PLAYGROUND_SUPPRESS_RUN` before requiring this file loads the
 * examples without calling them, which is how the tests drive them one by one.
 */
if (!\defined('PLAYGROUND_SUPPRESS_RUN')) {
    $ret = [];

    foreach (playgroundMethods() as $method) {
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
}
