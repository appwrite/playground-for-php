<?php
use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Services\Database;
use Appwrite\Services\Storage;


$ENDPOINT = 'https=>//localhost/v1';
$PROJECT_ID = '<Project ID>';
$API_KEY = '<Secret API>';

$client = new Client();

$client->setEndpoint($ENDPOINT);
$client->setProject($PROJECT_ID);
$client->setKey($API_KEY);

$collectionID = NAN;
$userId = NAN;

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

# List of API definations

function create_collection()
{
    # code...to create collection
    global $collectionID;
    $database = new Database($client);
    $response = $database->createCollection(
        'Movies',
        ['*'],
        ['*'],
        [
            ['label'=> "Name", 'key'=> "name", 'type'=> "text",
             'default'=> "Empty Name", 'required'=> True, 'array'=> False],
            ['label'=> 'release_year', 'key'=> 'release_year', 'type'=> 'numeric',
             'default'=> 1970, 'required'=> True, 'array'=> False]
            ],
        );
        $collectionID = $response['$id'];
        echo $response;   

}

function list_collection(){
    $database = new Database($client);
    echo "Running List Collection API";
    $response = $database->listCollections();
    $collection = $response['$collection'][0];
    echo $collection;
}

function add_doc(){
    $database = new Database($client);
    echo "Running Add Document API";

    $response = $database->createDocument(
        $collectionId,
        [
            'name'=> "Spider Man",
            'release_year'=> 1920,
        ],
        ['*'],
        ['*'] 
    );

    print($response);
}


function list_doc(){
    $storage = new Storage($client);
    print("Running Upload File API");
    $response = $storage->createFile(
        fopen("test.txt",'w+'),
        [],
        []
    );
}

function upload_files(){
    $storage = new Storage($client);
    print("RUnning upload file API");
    $response = $storage->createFile(
        fopen("./test.txt","w"),
        [],
        []
    );
}

function list_files(){
    $storage = new Storage($client);
    print("Running List Files API");
    $result = $storage.list_files();

    $file_count = $result['sum'];
    print($file_count);
    $files = $result['files'];
    print($files);
}

function delete_file(){
    $storage = new Storage($client);
    print("Running Delete File API");
    $result = $storage.list_files();
    $first_file_id = $result['files'][0]['$id'];
    $response = $storage.delete_file($first_file_id);
    print($response);
}

function create_user($email,$password,$name){
    global $userId;
    print("Running create user API");
    $response =users.create(
        $email,
        $password,
        $name
    );
    $userId = $response['$id'];
    print($response);
}

function list_user(){
    $users = new Users($client);
    print("Running list user api");
    $response = $users->list();
    print($response);
}

function run_all_tasks(){
    $name = date();
    create_collection();
    list_collection();
    add_doc();
    list_doc();
    upload_files();
    list_files();
    delete_file();
    create_user(
        $name + '@test.com',
        $name + '@123',
        $name
    );
    list_user();

}

function main(){
    run_all_tasks();
    print("succesfully run playground");
}

main();
?>