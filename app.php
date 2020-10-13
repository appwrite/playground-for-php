<?php
require_once './vendor/autoload.php';

use Appwrite\Client;
use Appwrite\Services\Database;
use Appwrite\Services\Storage;
use Appwrite\Services\Users;


$ENDPOINT = 'https://localhost/v1';
$PROJECT_ID = '<Your Project ID />';
$API_KEY = '<Your Project Secret Api key/>';

$client = new Client();

$client->setEndpoint($ENDPOINT);
$client->setProject($PROJECT_ID);
$client->setKey($API_KEY);
$collectionID = $userID = 0;
$database = new Database($client);
$storage = new Storage($client);
$users = new Users($client);

# API Calls
#   - api.create_collection
#   - api.list_collection
#   - api.add_doc
#   - api.list_doc
#   - api.upload_file
#   - api.list_files
#   - api.delete_file
#   - api.create_user
#   - api.list_user

# List of API definitions

/**
 * @throws Exception
 */
function create_collection()
{
    # code...to create collection
    global $collectionID, $database;

    $response = $database->createCollection(
        'Movies',
        ['*'],
        ['*'],
        [
            [
                'label' => "Name",
                'key' => "name",
                'type' => "text",
                'default' => "Empty Name",
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

    $collectionID = $response['id'];

    var_dump($response);
}

/**
 * @throws Exception
 */
function list_collection()
{
    global $database;

    echo "Running List Collection API";

    $response = $database->listCollections();

    $collection = $response['$collection'];

    var_dump($collection);
}

/**
 * @throws Exception
 */
function add_doc()
{
    global $collectionId;
    global $database;

    $collectionId = "0";

    echo "Running Add Document API";

    $response = $database->createDocument(
        $collectionId,
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
function upload_files()
{
    global $storage;
    $filename = 'test.txt';

    echo "Running upload file API";

    $response = $storage->createFile(
        curl_file_create($filename),
        [],
        []
    );

    var_dump($response);
}

/**
 * @throws Exception
 */
function list_files()
{
    global $storage;

    echo "Running List Files API";

    $result = $storage->listFiles();

    $file_count = $result['sum'];
    $files = $result['files'];

    var_dump($file_count, $files);
}

/**
 * @throws Exception
 */
function delete_file()
{
    global $storage;


    echo "Running Delete File API";

    $result = $storage->listFiles();
    $first_file_id = 'test.txt';
    //$first_file_id = $result['files'][0]['$id'];
    $response = $storage->deleteFile($first_file_id);

    var_dump($response);
}

/**
 * @param $email
 * @param $password
 * @param $name
 * @throws Exception
 */
function create_user($email, $password, $name)
{
    global $userId, $users;

    echo "Running create user API";

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
function list_user()
{
    global $users;

    echo "Running list user api";

    $response = $users->list();

    var_dump($response);
}

/**
 * @throws Exception
 */
function run_all_tasks()
{

    $name = time();
    create_collection();
    list_collection();
    add_doc();
    upload_files();
    list_files();
    delete_file();
    create_user(
        $name . '@test.com',
        $name . '@123',
        $name
    );
    list_user();
}

try {
    run_all_tasks();
} catch (Exception $e) {
    die($e->getMessage());
}

echo "successfully run playground";
