<?php
require __DIR__ . '/global.inc.php';

use Appwrite\Client;
use Appwrite\Services\Database;
use Appwrite\Services\Storage;
use Appwrite\Services\Users;
use Appwrite\Services\Account;

$client = (new Client())
    ->setEndpoint(ENDPOINT)
    ->setProject(PROJECT_ID)
    // ->setJWT('jwt') // Use this to authenticate with JWT generated from client
    ->setKey(API_KEY);

$collectionId = "";

$dataBase = new Database($client);
$storage = new Storage($client);
$users = new Users($client);
$account = new Account($client);

/**
 * Covered API methods
 *
 * - createCollection
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
 * Create a new Collection.
 *
 * @see https://appwrite.io/docs/server/database?sdk=php#databaseCreateCollection
 * @throws Exception
 */
function createCollection()
{
    global $collectionId, $dataBase;

    $response = $dataBase->createCollection(
        'movies',
        'Movies',
        'collection',
        ['role:all'],
        ['role:all']
    );

    $collectionId = $response['$id'];

    $response1 = $dataBase->createStringAttribute(
        $collectionId,
        'name',
        255,
        true,
    );
    $response2 = $dataBase->createIntegerAttribute(
        $collectionId,
        'release_year',
        true,
        0,
        9999,
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
 * @see https://appwrite.io/docs/server/database?sdk=php#databaseListCollections
 * @return array
 * @throws Exception
 */
function listCollections()
{
    global $dataBase;

    return [
        'call' => 'api.listCollections',
        'response' => $dataBase->listCollections()
    ];
}

/**
 * Create a new Document.
 * Before using this route, you should create a new collection resource
 *
 * @see https://appwrite.io/docs/server/database?sdk=php#databaseCreateDocument
 * @return array
 * @throws Exception
 */
function addDoc()
{
    global $collectionId, $dataBase;
    $response = $dataBase->createDocument(
        $collectionId,
        'unique()',
        [
            'name' => 'Spider Man',
            'release_year' => 1920,
        ],
        ['role:all'],
        ['role:all']
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
 * @see https://appwrite.io/docs/server/database?sdk=php#databaseDeleteCollection
 * @return array
 * @throws Exception
 */
function deleteCollection()
{
    global $dataBase, $collectionId;

    return [
        'call' => 'api.deleteCollection',
        'response' => $dataBase->deleteCollection($collectionId)
    ];
}

/**
 * Create a bucket
 * 
 * @see https://appwrite.io/docs/server/storage?sdk=php#storageCreateBucket
 * @return array
 * @throws Exception
 */
function createBucket()
{
    global $storage, $bucketId;
    $response = $storage->createBucket(
        'unique()',
        'test bucket',
        'bucket',
        ['role:all'],
        ['role:all'],
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
function createFile()
{
    global $storage, $bucketId, $fileId;

    $response = $storage->createFile(
        $bucketId,
        'unique()',
        __DIR__ . '/test.txt',
    );

    $fileId = $response['$id'];

    return [
        'call' => 'api.uploadFile',
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
function listFiles()
{
    global $storage, $bucketId;

    return [
        'call' => 'api.listFiles',
        'response' => $storage->listFiles($bucketId)
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
function deleteFile()
{
    global $storage, $bucketId, $fileId;

    return [
        'call' => 'api.deleteFile',
        'response' => $storage->deleteFile($bucketId, $fileId)
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
function deleteBucket()
{
    global $storage, $bucketId;

    return [
        'call' => 'api.deleteBucket',
        'response' => $storage->deleteBucket($bucketId)
    ];
}

/**
 * Create a new user.
 *
 * @see https://appwrite.io/docs/server/users?sdk=php#usersCreate
 * @return array
 * @throws Exception
 */
function createUser()
{
    global $users;

    $suffix = time();

    return [
        'call' => 'api.createUser',
        'response' => $users->create('unique()', "email{$suffix}@example.com", 'password', "Example {$suffix}")
    ];
}

/**
 * Get a list of all the project users.
 *
 * @see https://appwrite.io/docs/server/users?sdk=php#usersList
 * @throws Exception
 */
function listUsers()
{
    global $users;

    return [
        'call' => 'api.listUsers',
        'response' => $users->list()
    ];
}

/**
 * Get an account of authenticated user. Works only with JWT
 *
 * @see https://appwrite.io/docs/server/account?sdk=php#accountGet
 * @throws Exception
 */
function getAccount()
{
    global $account;

    return [
        'call' => 'api.getAccount',
        'response' => $account->get()
    ];
}

/**
 * Execute all functions, collect their return values
 * and print everything at the end.
 */
$ret = [];
$methods = [
    'createCollection',
    'listCollections',
    'addDoc',
    'deleteCollection',
    'createBucket',
    'createFile',
    'listFiles',
    'deleteFile',
    'deleteBucket',
    'createUser',
    'listUsers',
    // 'getAccount' // works only with JWT
];

foreach ($methods as $method) {
    try {
        if (function_exists($method)) {
            $ret[] = $method();
        }
    } catch (Exception $e) {
        print_r($e->getMessage());
    }
}

appwriteDebug($ret);
