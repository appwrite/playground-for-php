<?php
require __DIR__ . '/global.inc.php';

use Appwrite\Client;
use Appwrite\ID;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\Services\Databases;
use Appwrite\Services\Functions;
use Appwrite\Services\Storage;
use Appwrite\Services\Users;
use Appwrite\Services\Account;
use Appwrite\InputFile;

$client = (new Client())
    ->setEndpoint(ENDPOINT)
    ->setProject(PROJECT_ID)
    // ->setJWT('jwt') // Use this to authenticate with JWT generated from client
    ->setKey(API_KEY);

$collectionId = "";
$databaseId = "";
$bucketId = "";

$databases = new Databases($client);
$storage = new Storage($client);
$functions = new Functions($client);
$users = new Users($client);
$account = new Account($client);

/**
 * Covered API methods
 * - createDatabase
 * - deleteDatabase
 * - createCollection
 * - deleteCollection
 * - listCollection
 * - addDoc
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
 * @see https://appwrite.io/docs/server/databases?sdk=php#databasesCreate
 * @throws Exception
 */
function createDatabase(): array
{
    global $databases, $databaseId;

    $response = $databases->create(
        databaseId: ID::unique(),
        name: "Test Database"
    );

    $databaseId = $response['$id'];

    return [
        'call' => 'api.createDatabase',
        'response' => $response
    ];
}

 /**
 * Create a new Collection.
 *
 * @see https://appwrite.io/docs/server/databases?sdk=php#databasesCreateCollection
 * @throws Exception
 */
function createCollection(): array
{
    global $databases, $databaseId, $collectionId;

    $response = $databases->createCollection(
        databaseId: $databaseId,
        collectionId: ID::unique(),
        name: 'collection',
        permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users()),
        ]
    );

    $collectionId = $response['$id'];

    $response1 = $databases->createStringAttribute(
        $databaseId,
        $collectionId,
        key: 'name',
        size: 255,
        required: true,
    );
    $response2 = $databases->createIntegerAttribute(
        $databaseId,
        $collectionId,
        key: 'release_year',
        required: true,
        min: 0,
        max: 9999,
    );

    return [
        'call' => 'api.createCollection',
        'response' => $response
    ];
}

/**
 * Get a list of all the user collections.
 * On admin mode, this endpoint will return a list of all of the project collections.
 *
 * @see https://appwrite.io/docs/server/databases?sdk=php#databasesListCollections
 * @return array
 * @throws Exception
 */
function listCollections(): array
{
    global $databases, $databaseId;

    $response = $databases->listCollections($databaseId);

    return [
        'call' => 'api.listCollections',
        'response' => $response
    ];
}

/**
 * Create a new Document.
 * Before using this route, you should create a new collection resource
 *
 * @see https://appwrite.io/docs/server/databases?sdk=php#databasesCreateDocument
 * @return array
 * @throws Exception
 */
function addDoc(): array
{
    global $databases, $databaseId, $collectionId;
    $response = $databases->createDocument(
        $databaseId,
        $collectionId,
        documentId: ID::unique(),
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
        'call' => 'api.addDoc',
        'response' => $response
    ];
}

/**
 * Delete collection
 * Delete a collection by it's unique id.
 *
 * @see https://appwrite.io/docs/server/databases?sdk=php#databasesDeleteCollection
 * @return array
 * @throws Exception
 */
function deleteCollection(): array
{
    global $databases, $databaseId, $collectionId;

    $response = $databases->deleteCollection($databaseId, $collectionId);

    return [
        'call' => 'api.deleteCollection',
        'response' => $response
    ];
}

/**
 * Delete Database
 * Delete a database by it's unique id.
 *
 * @see https://appwrite.io/docs/server/databases?sdk=php#databasesDelete
 * @return array
 * @throws Exception
 */
function deleteDatabase(): array
{
    global $databases, $databaseId;

    $response = $databases->delete($databaseId);

    return [
        'call' => 'api.deleteDatabase',
        'response' => $response
    ];
}

/**
 * Create a bucket
 * 
 * @see https://appwrite.io/docs/server/storage?sdk=php#storageCreateBucket
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

    $bucketId = $response['$id'];

    return [
        'call' => 'api.createBucket',
        'response' => $response
    ];
}

/**
 * Create a new file.
 * The user who creates the file will automatically be assigned to read and write
 * access unless he has passed custom values for read and write arguments.
 *
 * @see https://appwrite.io/docs/client/storage?sdk=php#storageCreateFile
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
            Permission::read(Role::any())
        ]
    );

    $fileId = $response['$id'];

    return [
        'call' => 'api.createFile',
        'response' => $response
    ];
}

/**
 * Get a list of all the user files.
 * You can use the query params to filter your results. On admin mode,
 * this endpoint will return a list of all of the project files.
 *
 * @see https://appwrite.io/docs/client/storage?sdk=php#storageListFiles
 * @return array
 * @throws Exception
 */
function listFiles(): array
{
    global $storage, $bucketId;

    $response = $storage->listFiles($bucketId);

    return [
        'call' => 'api.listFiles',
        'response' => $response
    ];
}

/**
 * Delete a file by its unique ID.
 * Only users with write permissions have access to delete this resource.
 *
 * @see https://appwrite.io/docs/client/storage?sdk=php#storageDeleteFile
 * @return array
 * @throws Exception
 */
function deleteFile(): array
{
    global $storage, $bucketId, $fileId;

    $response = $storage->deleteFile($bucketId, $fileId);

    return [
        'call' => 'api.deleteFile',
        'response' => $response
    ];
}

/**
 * Delete a bucket by its unique ID.
 * Only users with write permissions have access to delete this resource.
 *
 * @see https://appwrite.io/docs/server/storage?sdk=php#storageDeleteBucket
 * @return array
 * @throws Exception
 */
function deleteBucket(): array
{
    global $storage, $bucketId;

    $response = $storage->deleteBucket($bucketId);

    return [
        'call' => 'api.deleteBucket',
        'response' => $response
    ];
}

/**
 * Create a new user.
 *
 * @see https://appwrite.io/docs/server/users?sdk=php#usersCreate
 * @return array
 * @throws Exception
 */
function createUser(): array
{
    global $users;

    $suffix = time();

    $response = $users->create(
        userId: ID::unique(),
        email: "email{$suffix}@example.com",
        password: 'password',
        name: "Example {$suffix}"
    );

    return [
        'call' => 'api.createUser',
        'response' => $response
    ];
}

/**
 * Get a list of all the project users.
 *
 * @see https://appwrite.io/docs/server/users?sdk=php#usersList
 * @throws Exception
 */
function listUsers(): array
{
    global $users;

    $response = $users->list();

    return [
        'call' => 'api.listUsers',
        'response' => $response
    ];
}

/**
 * Get an account of authenticated user. Works only with JWT
 *
 * @see https://appwrite.io/docs/server/account?sdk=php#accountGet
 * @throws Exception
 */
function getAccount(): array
{
    global $account;

    $response = $account->get();

    return [
        'call' => 'api.getAccount',
        'response' => $response
    ];
}

/**
 * Create a function
 *
 * @see https://appwrite.io/docs/server/functions?sdk=php#functionsCreate
 * @throws Exception
 */
function createFunction(): array
{
    global $functions, $functionId;

    $response = $functions->create(
        functionId: ID::unique(),
        name: 'Test Function',
        execute: [Role::any()],
        runtime: 'php-8.0',
    );

    $functionId = $response['$id'];

    return [
        'call' => 'api.createFunction',
        'response' => $response
    ];
}

/**
 * List functions
 *
 * @see https://appwrite.io/docs/server/functions?sdk=php#functionsList
 * @throws Exception
 */
function listFunctions(): array
{
    global $functions;

    $response = $functions->list();

    return [
        'call' => 'api.listFunctions',
        'response' => $response
    ];
}

/**
 * Delete a function
 *
 * @see https://appwrite.io/docs/server/functions?sdk=php#functionsDelete
 * @throws Exception
 */
function deleteFunction(): array
{
    global $functions, $functionId;

    $response = $functions->delete($functionId);

    return [
        'call' => 'api.deleteFunction',
        'response' => $response
    ];
}

/**
 * Execute all functions, collect their return values
 * and print everything at the end.
 */
$ret = [];
$methods = [
    'createDatabase',
    'createCollection',
    'listCollections',
    'addDoc',
    'deleteCollection',
    'deleteDatabase',
    'createBucket',
    'createFile',
    'listFiles',
    'deleteFile',
    'deleteBucket',
    'createUser',
    'listUsers',
    'createFunction',
    'listFunctions',
    'deleteFunction',
    // 'getAccount' // works only with JWT
];

foreach ($methods as $method) {
    try {
        if (function_exists($method)) {
            $ret[] = $method();
        }
    } catch (Exception $e) {
        print_r($e->getMessage());
        print_r($e->getTraceAsString());
        print_r("");
    }
}

appwriteDebug($ret);
