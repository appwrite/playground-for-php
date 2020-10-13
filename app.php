<?php
require_once './vendor/autoload.php';

use Appwrite\Client;
use Appwrite\Services\Database;
use Appwrite\Services\Storage;
use Appwrite\Services\Users;


$endpoint = 'https://localhost/v1';
$projectId = '<Your Project ID />';
$apiKey = '<Your Project Secret Api key/>';

$client = new Client();

$client->setEndpoint($endpoint);
$client->setProject($projectId);
$client->setKey($apiKey);
$collectionId = $userId = 0;
$dataBase = new Database($client);
$storage = new Storage($client);
$users = new Users($client);

# API Calls
#   - api.createCollection
#   - api.listCollection
#   - api.addDoc
#   - api.uploadFile
#   - api.listFiles
#   - api.deleteFile
#   - api.createUser
#   - api.listUser

# List of API definitions

/**
 * @throws Exception
 */
function createCollection()
{
    # code...to create collection
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
                'label' => 'release_year',
                'key' => 'release_year',
                'type' => 'numeric',
                'default' => 1970,
                'required' => true,
                'array' => false
            ]
        ]
    );
    $collectionId = $response['id'];
    var_dump($response);
}

/**
 * @throws Exception
 */
function listCollection()
{
    global $dataBase;

    echo 'Running List Collection API';
    $response = $dataBase->listCollections();
    $collection = $response['$collection'];
    var_dump($collection);
}

/**
 * @throws Exception
 */
function addDoc()
{
    global $collectionId,$dataBase;



    echo 'Running Add Document API';
    $response = $dataBase->createDocument(
        (string)$collectionId,
        [
            'name' => "Spider Man",
            'release_year' => '1920',
        ],
        ['*'],
        ['*']
    );

    var_dump($response);
}

/**
 * @throws Exception
 */
function uploadFiles()
{
    global $storage;

    $fileName = 'test.txt';
    echo 'Running upload file API';
    $response = $storage->createFile(
        curl_file_create($fileName),
        [],
        []
    );

    var_dump($response);
}

/**
 * @throws Exception
 */
function listFiles()
{
    global $storage;

    echo 'Running List Files API';
    $result = $storage->listFiles();
    $fileCount = $result['sum'];
    $files = $result['files'];
    var_dump($fileCount, $files);
}

/**
 * @throws Exception
 */
function deleteFile()
{
    global $storage;

    echo 'Running Delete File API';
    $result = $storage->listFiles();
    $firstFileId = 'test.txt';
    $response = $storage->deleteFile($firstFileId);
    var_dump($response);
}

/**
 * @param $email
 * @param $password
 * @param $name
 * @throws Exception
 */
function createUser($email, $password, $name)
{
    global $userId, $users;

    echo 'Running create user API';
    $response = $users->create(
        $email,
        $password,
        $name
    );
    $userId = $response['$id'];
    var_dump($response);
}

/**
 * @throws Exception
 */
function listUser()
{
    global $users;

    echo 'Running list user api';
    $response = $users->list();
    var_dump($response);
}

/**
 * @throws Exception
 */
function runAllTasks()
{
    $name = time();
    createCollection();
    listCollection();
    addDoc();
    uploadFiles();
    listFiles();
    deleteFile();
    createUser(
        $name . '@test.com',
        $name . '@123',
        $name
    );
    listUser();
}

try {
    runAllTasks();
} catch (Exception $e) {
    die($e->getMessage());
}

echo 'successfully run playground';
