<?php

/**
 * Router script for PHP's built-in server that impersonates the slice of the
 * Appwrite API the playground touches. It lets `tests/smoke.php` assert that
 * every example still speaks the routes and payloads the SDK expects without
 * needing a running Appwrite instance.
 *
 * Every request is appended as one JSON object per line to the file named by
 * the `STUB_LOG` environment variable, so the test can inspect what was sent.
 */

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = (string) (\parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$rawBody = (string) \file_get_contents('php://input');

$json = null;
if (\str_starts_with($contentType, 'application/json') && $rawBody !== '') {
    $json = \json_decode($rawBody, true);
}

// PHP never exposes a multipart body through `php://input`, it parses it into
// `$_POST` and `$_FILES` instead, which is where the uploads show up.
$form = \str_starts_with($contentType, 'multipart/form-data') ? $_POST : [];
$files = \array_map(
    static fn (array $file): array => [
        'name' => $file['name'],
        'type' => $file['type'],
        'size' => $file['size'],
    ],
    $_FILES
);

$log = \getenv('STUB_LOG');
if (\is_string($log) && $log !== '') {
    \file_put_contents(
        $log,
        \json_encode([
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'contentType' => $contentType,
            'json' => $json,
            'form' => $form,
            'files' => $files,
            'project' => $_SERVER['HTTP_X_APPWRITE_PROJECT'] ?? null,
            'key' => $_SERVER['HTTP_X_APPWRITE_KEY'] ?? null,
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ], JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND
    );
}

const NOW = '2026-11-11T09:00:00.000+00:00';

function respond(array $body, int $status = 200): never
{
    \http_response_code($status);
    \header('content-type: application/json; charset=utf-8');
    echo \json_encode($body);

    exit;
}

/**
 * Appwrite answers a delete with `204 No Content` and no content type, which is
 * what makes the SDK's `string` return type hold.
 */
function respondNoContent(): never
{
    \http_response_code(204);

    exit;
}

function column(string $key, string $type, array $extra = []): array
{
    return [
        'key' => $key,
        'description' => '',
        'type' => $type,
        'status' => 'available',
        'error' => '',
        'required' => true,
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
    ] + $extra;
}

function row(string $databaseId, string $tableId, string $rowId, array $data, array $permissions = []): array
{
    return [
        '$id' => $rowId,
        '$sequence' => '1',
        '$tableId' => $tableId,
        '$databaseId' => $databaseId,
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        '$permissions' => $permissions,
    ] + $data;
}

function execution(string $functionId, string $executionId, array $payload, string $status = 'completed'): array
{
    return [
        '$id' => $executionId,
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        '$permissions' => [],
        'resourceId' => $functionId,
        'resourceType' => 'functions',
        'deploymentId' => 'deployment-1',
        'trigger' => 'http',
        'status' => $status,
        'requestMethod' => $payload['method'] ?? 'GET',
        'requestPath' => $payload['path'] ?? '/',
        'requestHeaders' => [],
        'responseStatusCode' => 200,
        'responseBody' => $status === 'completed' ? 'Hello, World!' : '',
        'responseHeaders' => [],
        'logs' => '',
        'errors' => '',
        'duration' => 0.1,
    ];
}

$routes = [
    // TablesDB
    ['POST', '#^/v1/tablesdb$#', fn (): never => respond([
        '$id' => $json['databaseId'] ?? 'database-1',
        'name' => $json['name'] ?? '',
        'description' => '',
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        'enabled' => true,
        'type' => 'tablesdb',
    ], 201)],
    ['GET', '#^/v1/tablesdb/(?P<databaseId>[^/]+)/tables$#', fn (array $m): never => respond([
        'total' => 1,
        'tables' => [[
            '$id' => 'table-1',
            '$createdAt' => NOW,
            '$updatedAt' => NOW,
            '$permissions' => [],
            'databaseId' => $m['databaseId'],
            'name' => 'table',
            'description' => '',
            'enabled' => true,
            'rowSecurity' => false,
            'columns' => [],
            'indexes' => [],
            'bytesMax' => 0,
            'bytesUsed' => 0,
        ]],
    ])],
    ['POST', '#^/v1/tablesdb/(?P<databaseId>[^/]+)/tables$#', fn (array $m): never => respond([
        '$id' => $json['tableId'] ?? 'table-1',
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        '$permissions' => $json['permissions'] ?? [],
        'databaseId' => $m['databaseId'],
        'name' => $json['name'] ?? '',
        'description' => '',
        'enabled' => true,
        'rowSecurity' => false,
        'columns' => [],
        'indexes' => [],
        'bytesMax' => 65535,
        'bytesUsed' => 0,
    ], 201)],
    ['POST', '#^/v1/tablesdb/[^/]+/tables/[^/]+/columns/string$#', fn (): never => respond(
        column($json['key'] ?? '', 'string', ['size' => $json['size'] ?? 0]),
        202
    )],
    ['POST', '#^/v1/tablesdb/[^/]+/tables/[^/]+/columns/integer$#', fn (): never => respond(
        column($json['key'] ?? '', 'integer', ['min' => $json['min'] ?? null, 'max' => $json['max'] ?? null]),
        202
    )],
    ['GET', '#^/v1/tablesdb/[^/]+/tables/[^/]+/columns$#', fn (): never => respond([
        'total' => 2,
        'columns' => [
            column('name', 'string', ['size' => 255]),
            column('release_year', 'integer', ['min' => 0, 'max' => 9999]),
        ],
    ])],
    ['GET', '#^/v1/tablesdb/(?P<databaseId>[^/]+)/tables/(?P<tableId>[^/]+)/rows$#', fn (array $m): never => respond([
        'total' => 1,
        'rows' => [row($m['databaseId'], $m['tableId'], 'row-1', [
            'name' => 'Spider Man',
            'release_year' => 1920,
        ])],
    ])],
    ['POST', '#^/v1/tablesdb/(?P<databaseId>[^/]+)/tables/(?P<tableId>[^/]+)/rows$#', fn (array $m): never => respond(
        row(
            $m['databaseId'],
            $m['tableId'],
            $json['rowId'] ?? 'row-1',
            $json['data'] ?? [],
            $json['permissions'] ?? []
        ),
        201
    )],
    ['DELETE', '#^/v1/tablesdb/[^/]+/tables/[^/]+$#', fn (): never => respondNoContent()],
    ['DELETE', '#^/v1/tablesdb/[^/]+$#', fn (): never => respondNoContent()],

    // Storage
    ['POST', '#^/v1/storage/buckets$#', fn (): never => respond([
        '$id' => $json['bucketId'] ?? 'bucket-1',
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        '$permissions' => $json['permissions'] ?? [],
        'fileSecurity' => $json['fileSecurity'] ?? false,
        'name' => $json['name'] ?? '',
        'enabled' => true,
        'maximumFileSize' => 30000000,
        'allowedFileExtensions' => [],
        'compression' => 'gzip',
    ], 201)],
    ['GET', '#^/v1/storage/buckets/(?P<bucketId>[^/]+)/files$#', fn (array $m): never => respond([
        'total' => 1,
        'files' => [[
            '$id' => 'file-1',
            'bucketId' => $m['bucketId'],
            '$createdAt' => NOW,
            '$updatedAt' => NOW,
            '$permissions' => [],
            'name' => 'test.txt',
            'signature' => 'd41d8cd98f00b204e9800998ecf8427e',
            'mimeType' => 'text/plain',
            'sizeOriginal' => 19,
            'chunksTotal' => 1,
            'chunksUploaded' => 1,
        ]],
    ])],
    ['POST', '#^/v1/storage/buckets/(?P<bucketId>[^/]+)/files$#', fn (array $m): never => respond([
        '$id' => $form['fileId'] ?? 'file-1',
        'bucketId' => $m['bucketId'],
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        '$permissions' => [],
        'name' => $files['file']['name'] ?? 'file',
        'signature' => 'd41d8cd98f00b204e9800998ecf8427e',
        'mimeType' => $files['file']['type'] ?? 'text/plain',
        'sizeOriginal' => $files['file']['size'] ?? 0,
        'sizeActual' => $files['file']['size'] ?? 0,
        'chunksTotal' => 1,
        'chunksUploaded' => 1,
    ], 201)],
    ['DELETE', '#^/v1/storage/buckets/[^/]+/files/[^/]+$#', fn (): never => respondNoContent()],
    ['DELETE', '#^/v1/storage/buckets/[^/]+$#', fn (): never => respondNoContent()],

    // Users
    ['GET', '#^/v1/users$#', fn (): never => respond([
        'total' => 1,
        'users' => [[
            '$id' => 'user-1',
            '$createdAt' => NOW,
            '$updatedAt' => NOW,
            'name' => 'Example',
            'email' => 'email@example.com',
            'registration' => NOW,
            'hash' => 'argon2',
            'hashOptions' => [],
            'prefs' => [],
            'targets' => [],
            'mfaRecoveryCodes' => [],
        ]],
    ])],
    ['POST', '#^/v1/users$#', fn (): never => respond([
        '$id' => $json['userId'] ?? 'user-1',
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        'name' => $json['name'] ?? '',
        'email' => $json['email'] ?? '',
        'registration' => NOW,
        'hash' => 'argon2',
        'hashOptions' => [],
        'prefs' => [],
        'targets' => [],
        'mfaRecoveryCodes' => [],
    ], 201)],

    // Functions
    ['GET', '#^/v1/functions$#', fn (): never => respond([
        'total' => 1,
        'functions' => [[
            '$id' => 'function-1',
            '$createdAt' => NOW,
            '$updatedAt' => NOW,
            'execute' => ['any'],
            'name' => 'Test Function',
            'runtime' => 'php-8.4',
        ]],
    ])],
    ['POST', '#^/v1/functions$#', fn (): never => respond([
        '$id' => $json['functionId'] ?? 'function-1',
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        'execute' => $json['execute'] ?? [],
        'name' => $json['name'] ?? '',
        'runtime' => $json['runtime'] ?? '',
    ], 201)],
    ['POST', '#^/v1/functions/(?P<functionId>[^/]+)/deployments$#', fn (array $m): never => respond([
        '$id' => 'deployment-1',
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        'type' => 'manual',
        'resourceId' => $m['functionId'],
        'resourceType' => 'functions',
        'entrypoint' => $form['entrypoint'] ?? '',
        'sourceSize' => $files['code']['size'] ?? 0,
        'buildSize' => 0,
        'totalSize' => $files['code']['size'] ?? 0,
        'buildId' => 'build-1',
        // A multipart body carries booleans as strings.
        'activate' => \in_array($form['activate'] ?? '', ['1', 'true'], true),
        'screenshotLight' => '',
        'screenshotDark' => '',
        'status' => 'ready',
        'buildLogs' => '',
        'buildDuration' => 1,
        'providerRepositoryName' => '',
        'providerRepositoryOwner' => '',
        'providerRepositoryUrl' => '',
        'providerCommitHash' => '',
        'providerCommitAuthorUrl' => '',
        'providerCommitAuthor' => '',
        'providerCommitMessage' => '',
        'providerCommitUrl' => '',
        'providerBranch' => '',
        'providerBranchUrl' => '',
    ], 202)],
    ['GET', '#^/v1/functions/(?P<functionId>[^/]+)/executions/(?P<executionId>[^/]+)$#', fn (array $m): never => respond(
        execution($m['functionId'], $m['executionId'], [])
    )],
    // An async execution is only queued, so its result has to be fetched later.
    ['POST', '#^/v1/functions/(?P<functionId>[^/]+)/executions$#', fn (array $m): never => respond(
        execution(
            $m['functionId'],
            'execution-1',
            $json ?? [],
            ($json['async'] ?? false) ? 'processing' : 'completed'
        ),
        ($json['async'] ?? false) ? 202 : 201
    )],
    ['DELETE', '#^/v1/functions/[^/]+$#', fn (): never => respondNoContent()],

    // Account
    ['GET', '#^/v1/account$#', fn (): never => respond([
        '$id' => 'user-1',
        '$createdAt' => NOW,
        '$updatedAt' => NOW,
        'name' => 'Example',
        'email' => 'email@example.com',
        'registration' => NOW,
        'hash' => 'argon2',
        'hashOptions' => [],
        'prefs' => [],
        'targets' => [],
        'mfaRecoveryCodes' => [],
    ])],
];

foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if ($routeMethod === $method && \preg_match($pattern, $path, $matches) === 1) {
        $handler($matches);
    }
}

respond([
    'message' => "Stub has no route for {$method} {$path}",
    'type' => 'general_route_not_found',
], 404);
