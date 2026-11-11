<?php

/**
 * Drives every playground example against a stub of the Appwrite API and
 * asserts both the requests the SDK sends and the models it hands back.
 *
 * Run with `composer test` or `php tests/smoke.php`.
 */

declare(strict_types=1);

require __DIR__ . '/prelude.php';
require __DIR__ . '/stub.php';

$stub = startStub();
$port = $stub['port'];
$logFile = $stub['requestLog'];

\register_shutdown_function(static function () use ($stub): void {
    if ($GLOBALS['results']['failed'] > 0) {
        echo "\nStub server log:\n" . (string) \file_get_contents($stub['serverLog']);
    }
});

// The playground reads its configuration from the environment.
\putenv("APPWRITE_ENDPOINT=http://127.0.0.1:{$port}/v1");
\putenv('APPWRITE_PROJECT_ID=playground');
\putenv('APPWRITE_API_KEY=a-test-api-key-that-is-long-enough-to-sign-with');
\putenv('no_proxy=127.0.0.1,localhost');

// Load the examples without running the whole sequence.
\define('PLAYGROUND_SUPPRESS_RUN', true);
require \dirname(__DIR__) . '/playground.php';

/**
 * Every request the stub has seen so far, oldest first.
 *
 * @return array<int, array<string, mixed>>
 */
function requests(): array
{
    global $logFile;

    return stubRequests($logFile);
}

/**
 * The request the stub saw most recently.
 *
 * @return array<string, mixed>
 */
function lastRequest(): array
{
    $requests = requests();

    if ($requests === []) {
        throw new RuntimeException('The stub has not seen any request.');
    }

    return \end($requests);
}

/**
 * The method and path of every request the stub has seen since `$offset`.
 *
 * @return array<int, string>
 */
function requestTrail(int $offset = 0): array
{
    return \array_map(
        static fn (array $request): string => "{$request['method']} {$request['path']}",
        \array_slice(requests(), $offset)
    );
}

echo "Playground examples against the Appwrite API stub\n";

test('the endpoint and project come from the environment', function (): void {
    global $port;

    assertSame("http://127.0.0.1:{$port}/v1", ENDPOINT);
    assertSame('playground', PROJECT_ID);
});

test('createDatabase creates a TablesDB database', function (): void {
    global $databaseId;

    $result = createDatabase();

    assertSame('api.createDatabase', $result['call']);
    assertInstanceOf(\Appwrite\Models\Database::class, $result['response']);
    assertSame('Test Database', $result['response']->name);

    $request = lastRequest();
    assertSame('POST', $request['method']);
    assertSame('/v1/tablesdb', $request['path']);
    assertSame('Test Database', $request['json']['name']);
    assertSame($databaseId, $request['json']['databaseId'], 'The playground keeps the created id');
    assertSame('playground', $request['project']);
    assertSame(
        'a-test-api-key-that-is-long-enough-to-sign-with',
        $request['key'],
        'The API key is sent as the project key header'
    );
});

test('createTable creates the table, then its columns, and waits for them', function (): void {
    global $databaseId, $tableId;

    $offset = \count(requests());
    $result = createTable();

    assertInstanceOf(\Appwrite\Models\Table::class, $result['response']);
    assertSame($databaseId, $result['response']->databaseId);

    assertSame([
        "POST /v1/tablesdb/{$databaseId}/tables",
        "POST /v1/tablesdb/{$databaseId}/tables/{$tableId}/columns/string",
        "POST /v1/tablesdb/{$databaseId}/tables/{$tableId}/columns/integer",
        "GET /v1/tablesdb/{$databaseId}/tables/{$tableId}/columns",
    ], requestTrail($offset), 'Columns are created and then awaited');

    $table = requests()[$offset];
    assertSame('table', $table['json']['name']);
    assertSame(
        ['read("any")', 'create("users")', 'update("users")', 'delete("users")'],
        $table['json']['permissions']
    );

    $string = requests()[$offset + 1];
    assertSame('name', $string['json']['key']);
    assertSame(255, $string['json']['size']);
    assertSame(true, $string['json']['required']);

    $integer = requests()[$offset + 2];
    assertSame('release_year', $integer['json']['key']);
    assertSame(0, $integer['json']['min']);
    assertSame(9999, $integer['json']['max']);
});

test('listTables lists the tables of the database', function (): void {
    global $databaseId;

    $result = listTables();

    assertInstanceOf(\Appwrite\Models\TableList::class, $result['response']);
    assertSame(1, $result['response']->total);
    assertInstanceOf(\Appwrite\Models\Table::class, $result['response']->tables[0]);

    $request = lastRequest();
    assertSame('GET', $request['method']);
    assertSame("/v1/tablesdb/{$databaseId}/tables", $request['path']);
});

test('addRow writes a row into the table', function (): void {
    global $databaseId, $tableId;

    $result = addRow();

    assertSame('api.addRow', $result['call']);
    assertInstanceOf(\Appwrite\Models\Row::class, $result['response']);
    assertSame($databaseId, $result['response']->databaseId);
    assertSame($tableId, $result['response']->tableId);
    assertSame('Spider Man', $result['response']->data['name']);
    assertSame(1920, $result['response']->data['release_year']);

    $request = lastRequest();
    assertSame('POST', $request['method']);
    assertSame("/v1/tablesdb/{$databaseId}/tables/{$tableId}/rows", $request['path']);
    assertSame(['name' => 'Spider Man', 'release_year' => 1920], $request['json']['data']);
    assertSame(
        ['read("any")', 'update("users")', 'delete("users")'],
        $request['json']['permissions']
    );
});

test('listRows reads the rows back', function (): void {
    global $databaseId, $tableId;

    $result = listRows();

    assertInstanceOf(\Appwrite\Models\RowList::class, $result['response']);
    assertSame(1, $result['response']->total);
    assertSame('Spider Man', $result['response']->rows[0]->data['name']);

    $request = lastRequest();
    assertSame('GET', $request['method']);
    assertSame("/v1/tablesdb/{$databaseId}/tables/{$tableId}/rows", $request['path']);
});

test('deleteTable and deleteDatabase clean up', function (): void {
    global $databaseId, $tableId;

    $offset = \count(requests());

    assertSame('', deleteTable()['response'], 'A delete answers with no content');
    assertSame('', deleteDatabase()['response']);

    assertSame([
        "DELETE /v1/tablesdb/{$databaseId}/tables/{$tableId}",
        "DELETE /v1/tablesdb/{$databaseId}",
    ], requestTrail($offset));
});

test('createBucket creates a bucket with file security', function (): void {
    global $bucketId;

    $result = createBucket();

    assertInstanceOf(\Appwrite\Models\Bucket::class, $result['response']);
    assertSame(true, $result['response']->fileSecurity);

    $request = lastRequest();
    assertSame('POST', $request['method']);
    assertSame('/v1/storage/buckets', $request['path']);
    assertSame('test bucket', $request['json']['name']);
    assertSame(true, $request['json']['fileSecurity']);
    assertSame($bucketId, $request['json']['bucketId']);
});

test('createFile uploads test.txt as multipart form data', function (): void {
    global $bucketId, $fileId;

    $result = createFile();

    assertInstanceOf(\Appwrite\Models\File::class, $result['response']);
    assertSame('test.txt', $result['response']->name);
    assertSame($bucketId, $result['response']->bucketId);

    $request = lastRequest();
    assertSame('POST', $request['method']);
    assertSame("/v1/storage/buckets/{$bucketId}/files", $request['path']);
    assertTrue(
        \str_starts_with((string) $request['contentType'], 'multipart/form-data'),
        "A file is uploaded as multipart, got {$request['contentType']}"
    );
    assertSame('test.txt', $request['files']['file']['name'], 'The upload carries the file');
    assertSame(\filesize(\dirname(__DIR__) . '/test.txt'), $request['files']['file']['size']);
    assertSame($fileId, $request['form']['fileId'], 'The upload carries the generated file id');
});

test('listFiles lists the bucket files', function (): void {
    global $bucketId;

    $result = listFiles();

    assertInstanceOf(\Appwrite\Models\FileList::class, $result['response']);
    assertSame(1, $result['response']->total);

    assertSame("/v1/storage/buckets/{$bucketId}/files", lastRequest()['path']);
});

test('deleteFile and deleteBucket clean up', function (): void {
    global $bucketId, $fileId;

    $offset = \count(requests());

    assertSame('', deleteFile()['response']);
    assertSame('', deleteBucket()['response']);

    assertSame([
        "DELETE /v1/storage/buckets/{$bucketId}/files/{$fileId}",
        "DELETE /v1/storage/buckets/{$bucketId}",
    ], requestTrail($offset));
});

test('createUser creates a user', function (): void {
    $result = createUser();

    assertInstanceOf(\Appwrite\Models\User::class, $result['response']);

    $request = lastRequest();
    assertSame('POST', $request['method']);
    assertSame('/v1/users', $request['path']);
    assertSame('password', $request['json']['password']);
    assertTrue(
        \str_ends_with((string) $request['json']['email'], '@example.com'),
        'The generated email keeps the example domain'
    );
});

test('listUsers lists the project users', function (): void {
    $result = listUsers();

    assertInstanceOf(\Appwrite\Models\UserList::class, $result['response']);
    assertSame(1, $result['response']->total);

    $request = lastRequest();
    assertSame('GET', $request['method']);
    assertSame('/v1/users', $request['path']);
});

test('createFunction asks for a runtime the SDK still offers', function (): void {
    global $functionId;

    $result = createFunction();

    assertInstanceOf(\Appwrite\Models\FunctionModel::class, $result['response']);
    assertSame('php-8.4', $result['response']->runtime);

    $request = lastRequest();
    assertSame('POST', $request['method']);
    assertSame('/v1/functions', $request['path']);
    assertSame('php-8.4', $request['json']['runtime'], 'The Runtime enum serializes to its API value');
    assertSame(['any'], $request['json']['execute']);
    assertSame($functionId, $request['json']['functionId']);
});

test('createDeployment uploads the code archive', function (): void {
    global $functionId;

    $result = createDeployment();

    assertInstanceOf(\Appwrite\Models\Deployment::class, $result['response']);
    assertSame('src/index.php', $result['response']->entrypoint);
    assertSame(true, $result['response']->activate);

    $request = lastRequest();
    assertSame('POST', $request['method']);
    assertSame("/v1/functions/{$functionId}/deployments", $request['path']);
    assertSame(
        'code.tar.gz',
        $request['files']['code']['name'],
        'The deployment carries the code archive'
    );
    assertSame('src/index.php', $request['form']['entrypoint']);
    assertSame('composer install', $request['form']['commands']);
    assertSame('1', $request['form']['activate'], 'The deployment is activated');
});

test('createSyncExecution executes the function and waits for the response', function (): void {
    global $functionId;

    $result = createSyncExecution();

    assertInstanceOf(\Appwrite\Models\Execution::class, $result['response']);
    assertSame('Hello, World!', $result['response']->responseBody);

    $request = lastRequest();
    assertSame('POST', $request['method']);
    assertSame("/v1/functions/{$functionId}/executions", $request['path']);
    assertSame(false, $request['json']['async']);
    assertSame('GET', $request['json']['method'], 'The ExecutionMethod enum serializes to its API value');
    assertSame('/', $request['json']['path']);
});

test('createAsyncExecution polls the execution it queued', function (): void {
    global $functionId;

    $offset = \count(requests());
    $result = createAsyncExecution();

    assertInstanceOf(\Appwrite\Models\Execution::class, $result['response']);
    assertSame('execution-1', $result['response']->id);

    assertSame([
        "POST /v1/functions/{$functionId}/executions",
        "GET /v1/functions/{$functionId}/executions/execution-1",
    ], requestTrail($offset));

    assertSame(true, requests()[$offset]['json']['async']);
});

test('listFunctions and deleteFunction finish the sequence', function (): void {
    global $functionId;

    $offset = \count(requests());

    $listed = listFunctions();
    assertInstanceOf(\Appwrite\Models\FunctionList::class, $listed['response']);
    assertSame(1, $listed['response']->total);

    assertSame('', deleteFunction()['response']);

    assertSame([
        'GET /v1/functions',
        "DELETE /v1/functions/{$functionId}",
    ], requestTrail($offset));
});

test('every example the playground runs exists', function (): void {
    foreach (playgroundMethods() as $method) {
        assertTrue(\function_exists($method), "playground.php lists an unknown example: {$method}");
    }
});

exit(results());
