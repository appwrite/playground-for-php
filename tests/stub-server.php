<?php
// Minimal Appwrite-shaped stub: answers every route the playground touches with
// a valid resource so the SDK's response models hydrate, letting the playground
// run end to end without a live Appwrite instance.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'DELETE') {
    // Mirror the server's noContent(): 204, no body, no JSON content type.
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// Collapse generated ids out of the path so routes can be matched by shape.
$letters = 'abcdef';
$i = 0;
$segments = [];
foreach (explode('/', trim($path, '/')) as $segment) {
    $segments[] = preg_match('/^[0-9a-f]{20,}$/', $segment) || str_starts_with($segment, 'stub')
        ? '{' . $letters[$i++] . '}'
        : $segment;
}
$shape = '/' . implode('/', $segments);

$base = [
    '$id' => 'stub' . substr(md5($path), 0, 8),
    '$createdAt' => '2026-01-01T00:00:00.000+00:00',
    '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
    'name' => 'stub',
    'description' => '',
    'enabled' => true,
    'type' => 'legacy',
    'permissions' => [],
    '$permissions' => [],
    '$sequence' => '1',
    '$databaseId' => 'stubdb',
    '$tableId' => 'stubtable',
    'rowSecurity' => false,
    'columns' => [],
    'indexes' => [],
    'bytesMax' => 0,
    'bytesUsed' => 0,
    'runtime' => 'php-8.4',
];

$column = $base + ['key' => 'name', 'type' => 'string', 'size' => 255, 'required' => true, 'status' => 'available'];
$user = $base + ['prefs' => []];
$deployment = $base + ['status' => 'ready'];
$execution = $base + ['status' => 'completed', 'trigger' => 'http', 'resourceType' => 'functions'];

// Shape => [list key, single resource]. A GET on a list route returns one item.
$routes = [
    '/v1/tablesdb/{a}/tables' => ['tables', $base],
    '/v1/tablesdb/{a}/tables/{b}/columns' => ['columns', $column],
    '/v1/tablesdb/{a}/tables/{b}/rows' => ['rows', $base],
    '/v1/storage/buckets/{a}/files' => ['files', $base],
    '/v1/users' => ['users', $user],
    '/v1/functions' => ['functions', $base],
    '/v1/functions/{a}/deployments' => ['deployments', $deployment],
    '/v1/functions/{a}/executions' => ['executions', $execution],
];

$resource = match (true) {
    str_contains($shape, '/columns') => $column,
    str_contains($shape, '/users') => $user,
    str_contains($shape, '/deployments') => $deployment,
    str_contains($shape, '/executions') => $execution,
    default => $base,
};

if ($method === 'GET' && isset($routes[$shape])) {
    echo json_encode(['total' => 1, $routes[$shape][0] => [$routes[$shape][1]]]);
    exit;
}

echo json_encode($resource);
