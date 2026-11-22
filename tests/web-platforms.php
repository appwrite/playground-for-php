<?php

/**
 * Checks the client half of appwrite/appwrite#15509, which stopped seeding a new
 * project with a web platform whose hostname is `*`.
 *
 * A project's web platforms are the origins Appwrite will answer a credentialed
 * browser request from. A wildcard there answers every origin, and a browser
 * attaches the user's session cookie on its own (they are issued
 * `SameSite=None`), so any page a signed-in user visited could call the project
 * as them and read the answer, knowing only the public project ID. New projects
 * now get `localhost` instead, and nothing migrates the projects that already
 * carry `*`.
 *
 * What that leaves for a PHP consumer, pinned down here against
 * `tests/stub-server.php`, which holds requests to the same origin check the
 * server runs and seeds its project the way the console does:
 *
 * - a project seeded since the fix lists one web platform, `localhost`, and no
 *   wildcard;
 * - a project from before it still lists `*` — and the `*.example.com` form the
 *   console's own warning misses — so `wildcardHostnames()` finds both;
 * - registering an origin from PHP sends that one hostname and never a
 *   wildcard, and the project lists it afterwards;
 * - a key without the platform scopes is answered `401`, which the playground
 *   reports rather than treating as "no wildcard registered";
 * - and the tightening does not reach a PHP caller at all: the SDK sends no
 *   `Origin` header, with a key or with a JWT, so every call still goes through
 *   against a project that trusts no browser origin.
 *
 * The last two checks are the browser's half, which no server SDK can make
 * because none of them sends an origin. They go through a raw request so that
 * what the wildcard used to permit is visible: the same credentialed write is
 * refused `403 general_unknown_origin` from an unregistered origin, and
 * accepted from any origin at all by a project still seeded the old way.
 *
 * Usage: php tests/web-platforms.php
 */

declare(strict_types=1);

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Services\Account;
use Appwrite\Services\Project;

$root = \dirname(__DIR__);

if (!\is_file($root . '/vendor/autoload.php')) {
    \fwrite(STDERR, "vendor/autoload.php is missing — run `composer install` first\n");
    exit(1);
}

// Brings the autoloader and `wildcardHostnames()`, the helper the playground
// scans the platform list with.
require $root . '/global.inc.php';
require __DIR__ . '/stub.php';

$failures = [];

function check(string $what, bool $ok): void
{
    global $failures;

    if ($ok) {
        echo "  ok    {$what}\n";

        return;
    }

    echo "  FAIL  {$what}\n";
    $failures[] = $what;
}

/**
 * The project service of a server: a project and an API key with the
 * `platforms.read` and `platforms.write` scopes.
 */
function keyedProject(Stub $stub): Project
{
    return new Project(
        (new Client())
            ->setEndpoint($stub->endpoint)
            ->setProject('playground')
            ->setKey('playground-api-key')
    );
}

/**
 * The hostnames of a project's platforms, wildcards included.
 *
 * @param array<int, array<string, mixed>> $platforms
 * @return list<string>
 */
function hostnames(array $platforms): array
{
    $hostnames = [];

    foreach ($platforms as $platform) {
        $hostname = (string) ($platform['hostname'] ?? '');

        if ($hostname !== '') {
            $hostnames[] = $hostname;
        }
    }

    return $hostnames;
}

/**
 * A request made the way a page in a browser makes one: an origin, and the
 * user's session riding along. No server SDK sends an origin, so this cannot go
 * through the SDK.
 *
 * @return array{0: int, 1: string} [status, error type]
 */
function browserWrite(Stub $stub, string $origin): array
{
    $response = @\file_get_contents($stub->endpoint . '/account/name', false, \stream_context_create([
        'http' => [
            'method' => 'PATCH',
            'header' => \implode("\r\n", [
                'Content-Type: application/json',
                'X-Appwrite-Project: playground',
                "Origin: {$origin}",
                'Cookie: a_session_playground=a-stolen-ride-along',
            ]),
            'content' => (string) \json_encode(['name' => 'Renamed by the page the user was on']),
            'ignore_errors' => true,
        ],
    ]));

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (\preg_match('#^HTTP/\S+ (\d{3})#', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    $body = \json_decode((string) $response, true);

    return [$status, \is_array($body) ? (string) ($body['type'] ?? '') : ''];
}

$stub = Stub::start();

try {
    $project = keyedProject($stub);

    echo "A project seeded since the fix trusts localhost and nothing else\n";

    $seeded = $project->listPlatforms();

    check("one platform is seeded (got {$seeded->total})", $seeded->total === 1);
    check(
        "it is localhost (got '" . \implode(', ', hostnames($seeded->platforms)) . "')",
        hostnames($seeded->platforms) === ['localhost']
    );
    check(
        'and no wildcard is registered',
        wildcardHostnames($seeded->platforms) === []
    );

    echo "Registering an origin names that one hostname\n";

    $platform = $project->createWebPlatform(
        platformId: 'example-web-app',
        name: 'Example web app',
        hostname: 'app.example.com'
    );

    check("the platform is the hostname that was asked for (got '{$platform->hostname}')", $platform->hostname === 'app.example.com');
    check("it is a web platform (got '{$platform->type}')", (string) $platform->type === 'web');

    $requests = $stub->requests();
    $create = \end($requests);
    check(
        'the create went to POST /v1/project/platforms/web',
        $create !== false && $create['method'] === 'POST' && $create['path'] === '/v1/project/platforms/web'
    );
    check(
        'it sent no wildcard hostname',
        wildcardHostnames([['hostname' => (string) ($create['body']['hostname'] ?? '')]]) === []
    );

    $after = $project->listPlatforms();
    check(
        'the project lists it afterwards',
        \in_array('app.example.com', hostnames($after->platforms), true)
    );
    check('and still no wildcard', wildcardHostnames($after->platforms) === []);

    $project->deletePlatform($platform->id);
    check(
        'deleting it leaves the seeded platform alone',
        hostnames($project->listPlatforms()->platforms) === ['localhost']
    );

    echo "The tightening does not reach a PHP caller\n";

    // A keyed write against a project that trusts no origin but localhost.
    $keyedAccount = new Account(
        (new Client())
            ->setEndpoint($stub->endpoint)
            ->setProject('playground')
            ->setKey('playground-api-key')
    );

    $code = 0;

    try {
        $keyedAccount->updateName(name: 'Renamed by the server');
    } catch (AppwriteException $e) {
        $code = $e->getCode();
    }

    check("a keyed write is still answered (got " . ($code === 0 ? '200' : (string) $code) . ")", $code === 0);

    // And the same write made as the user, the way an SSR backend relays one.
    $jwtAccount = new Account(
        (new Client())
            ->setEndpoint($stub->endpoint)
            ->setProject('playground')
            ->setJWT('playground-jwt')
    );

    $code = 0;

    try {
        $jwtAccount->updateName(name: 'Renamed for the user');
    } catch (AppwriteException $e) {
        $code = $e->getCode();
    }

    check("a JWT write is still answered (got " . ($code === 0 ? '200' : (string) $code) . ")", $code === 0);

    $origins = \array_values(\array_filter(\array_map(
        static fn (array $request): string => (string) ($request['origin'] ?? ''),
        $stub->requests()
    ), static fn (string $origin): bool => $origin !== ''));

    check(
        'because the SDK sent no origin on any request (' . \count($stub->requests()) . ' of them)',
        $origins === []
    );

    echo "A browser, which does send one, is held to the list\n";

    [$code, $type] = browserWrite($stub, 'https://unrelated.example');
    check("an unregistered origin is refused 403 (got {$code})", $code === 403);
    check("the refusal is typed general_unknown_origin (got '{$type}')", $type === 'general_unknown_origin');

    [$code, $type] = browserWrite($stub, 'http://localhost:5173');
    check("the seeded localhost origin is answered (got {$code})", $code === 200);

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $stub->errorLog() . "\n");
    }
} finally {
    $stub->stop();
}

echo "A project created before the fix still carries the wildcard\n";

$legacy = Stub::start(['STUB_LEGACY_WILDCARD' => '1']);

try {
    $platforms = keyedProject($legacy)->listPlatforms()->platforms;

    check(
        "both wildcards are found (got '" . \implode(', ', wildcardHostnames($platforms)) . "')",
        wildcardHostnames($platforms) === ['*', '*.example.com']
    );

    // Which is what there is to find: with `*` registered, the request refused
    // above is answered, from any origin at all.
    [$code] = browserWrite($legacy, 'https://unrelated.example');
    check("and any origin is answered while it is there (got {$code})", $code === 200);

    if ($failures !== []) {
        \fwrite(STDERR, '--- stub server log ---' . "\n" . $legacy->errorLog() . "\n");
    }
} finally {
    $legacy->stop();
}

echo "A key without the platform scopes is told so\n";

$scopeless = Stub::start(['STUB_NO_PLATFORM_SCOPE' => '1']);

try {
    $code = 0;
    $type = '';

    try {
        keyedProject($scopeless)->listPlatforms();
    } catch (AppwriteException $e) {
        $code = $e->getCode();
        $type = $e->getType();
    }

    check("reading the list is refused 401 (got {$code})", $code === 401);
    check("typed general_unauthorized_scope (got '{$type}')", $type === 'general_unauthorized_scope');
} finally {
    $scopeless->stop();
}

if ($failures !== []) {
    \fwrite(STDERR, "FAILED\n");

    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo "OK — the project trusts localhost, not every origin, as appwrite/appwrite#15509 intends\n";
