<?php
require __DIR__ . '/global.inc.php';

use Appwrite\Client;
use Appwrite\Services\Database;
use Appwrite\Services\Storage;
use Appwrite\Services\Users;

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
        'Movies',
        ['*'],
        ['*'],
        [
            [
                'label' => 'Name',
                'key' => 'name',
                'type' => 'text',
                'default' => 'Empty Name',
                'required' => true,
                'array' => false
            ],
            [
                'label' => 'Release Year',
                'key' => 'release_year',
                'type' => 'numeric',
                'default' => 1970,
                'required' => true,
                'array' => false
            ]
        ]
    );

    $collectionId = $response['$id'];

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
        [
            'name' => 'Spider Man',
            'release_year' => 1920,
        ],
        ['*'],
        ['*']
    );

    return [
        'call' => 'api.addDoc',
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
    global $storage;

    $response = $storage->createFile(
        curl_file_create(__DIR__ . '/test.txt'),
        [],
        []
    );

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
    global $storage;

    return [
        'call' => 'api.listFiles',
        'response' => $storage->listFiles()
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
    global $storage;

    return [
        'call' => 'api.deleteFile',
        'response' => $storage->deleteFile('test.txt')
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
        'response' => $users->create("email{$suffix}@example.com", 'password', "Example {$suffix}")
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
try {
    $ret = [];
    $methods = [
        'createCollection',
        'listCollections',
        'addDoc',
        'createFile',
        'listFiles',
        'deleteFile',
        'createUser',
        'listUsers',
        // 'getAccount' // works only with JWT
    ];

    foreach ($methods as $method) {
        if (function_exists($method)) {
            $ret[] = $method();
        }
    }

    appwriteDebug($ret);
} catch (Exception $e) {
    die($e->getMessage());
}
