<?php
use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Services\Database;
use Appwrite\Services\Storage;


$ENDPOINT = 'https://localhost/v1';
$PROJECT_ID = '<Project ID>';
$API_KEY = '<Secret API>';

$client = Client();

$client->set_endpoint($ENDPOINT);
$client->set_project($PROJECT_ID);
$client->set_key($API_KEY);

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
    $database = Database($client);
    $response = database.create_collection(
        'Movies',
        ['*'],
        ['*'],
        [
            {'label': "Name", 'key': "name", 'type': "text",
             'default': "Empty Name", 'required': True, 'array': False},
            {'label': 'release_year', 'key': 'release_year', 'type': 'numeric',
             'default': 1970, 'required': True, 'array': False}
            ],
        );
        $collectionID = $response['$id'];
        echo $response;   

}

function list_collection(){
    $database = Database($client);
    echo "Running List Collection API";
    $response = $database->list_collections();
    $collection = $response['$collection'][0];
    echo $collection;
}

function add_doc(){
    $database = Database($client);
    echo "Running Add Document API";

    $response = $database->create_document(
        $collectionId,
        {
            'name': "Spider Man",
            'release_year': 1920,
        },
        ['*'],
        ['*'] 
    );

    print($response);
}


function list_doc(){
    $storage = Storage($client);
    print("Running Upload File API");
    $response = $storage->create_file(
        fopen("test.txt",'rb'),
        [],
        []
    );
}

function upload_files(){
    $storage = Storage($client);
    print("RUnning upload file API");
    $response = $storage->create_file(
        fopen("./test.txt","rb"),
        [],
        []
    );
}

function list_files(){
    $storage = Storage($client);
    print("Running List Files API");
    $result = $storage.list_files();

    $file_count = $result['sum'];
    print($file_count);
    $files = $result['files'];
    print($files);
}

function delete_file(){
    storage = Storage($client);
    print("Running Delete File API");
    $result = $storage.list_files();
    $first_file_id = $result['files'][0]['$id'];
    $response = $storage.delete_file($first_file_id);
    print($response);
}

function create_user($email,$password,$name){
    global $userId;
    print("Running create user API");
    $response = users.create(
        $email,
        $password,
        $name
    );
    $userId = $response['$id'];
    print($response);
}

function list_user(){
    $users = Users($client);
    print("Running list user api");
    $response = $users.list();
    print($response);
}

function run_all_tasks(){
    $name = date();
    ccreate_collection();
    list_collection();
    add_doc();
    list_doc();
    upload_file();
    list_files();
    delete_file();
    create_user(
        name + '@test.com',
        name + '@123',
        name
    );
    list_user();

}

void main(){
    run_all_tasks();
    print("succesfully run playground");
}
?>