<?php
require __DIR__ . '/global.inc.php';

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\ExecutionMethod;
use Appwrite\Enums\IdTokenProvider;
use Appwrite\Enums\Runtime;
use Appwrite\ID;
use Appwrite\InputFile;
use Appwrite\Models\Session;
use Appwrite\Permission;
use Appwrite\Query;
use Appwrite\Role;
use Appwrite\Services\Account;
use Appwrite\Services\Functions;
use Appwrite\Services\Storage;
use Appwrite\Services\TablesDB;
use Appwrite\Services\Users;

$client = (new Client())
    ->setEndpoint(ENDPOINT)
    ->setProject(PROJECT_ID)
    // ->setJWT('jwt') // Use this to authenticate with JWT generated from client
    ->setKey(API_KEY);

$databaseId = "";
$tableId = "";
$bucketId = "";
$fileId = "";
$functionId = "";

$tablesDB = new TablesDB($client);
$storage = new Storage($client);
$functions = new Functions($client);
$users = new Users($client);
$account = new Account($client);

/**
 * Covered API methods
 * - createDatabase
 * - deleteDatabase
 * - createTable
 * - deleteTable
 * - listTables
 * - addRow
 * - uploadFile
 * - listFiles
 * - deleteFile
 * - createUser
 * - listUser
 * - listUsers past the counted total
 * - getAccount
 * - createRecovery
 * - createSessionFromToken
 * - createIdTokenSession, and the account it claims
 */

/**
 * Create a new Database.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#create
 * @throws Exception
 */
function createDatabase(): array
{
    global $tablesDB, $databaseId;

    $response = $tablesDB->create(
        databaseId: ID::unique(),
        name: "Test Database"
    );

    $databaseId = $response->id;

    return [
        'call' => 'api.createDatabase',
        'response' => $response,
    ];
}

/**
 * Create a new Table.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#createTable
 * @throws Exception
 */
function createTable(): array
{
    global $tablesDB, $databaseId, $tableId;

    $response = $tablesDB->createTable(
        databaseId: $databaseId,
        tableId: ID::unique(),
        name: 'table',
        permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users()),
        ]
    );

    $tableId = $response->id;

    $response1 = $tablesDB->createStringColumn(
        $databaseId,
        $tableId,
        key: 'name',
        size: 255,
        required: true,
    );
    $response2 = $tablesDB->createIntegerColumn(
        $databaseId,
        $tableId,
        key: 'release_year',
        required: true,
        min: 0,
        max: 9999,
    );

    return [
        'call' => 'api.createTable',
        'response' => $response,
    ];
}

/**
 * Get a list of all the user tables.
 * On admin mode, this endpoint will return a list of all of the project tables.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#listTables
 * @return array
 * @throws Exception
 */
function listTables(): array
{
    global $tablesDB, $databaseId;

    $response = $tablesDB->listTables($databaseId);

    return [
        'call' => 'api.listTables',
        'response' => $response,
    ];
}

/**
 * Create a new Row.
 * Before using this route, you should create a new table resource
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#createRow
 * @return array
 * @throws Exception
 */
function addRow(): array
{
    global $tablesDB, $databaseId, $tableId;

    $response = $tablesDB->createRow(
        $databaseId,
        $tableId,
        rowId: ID::unique(),
        data: [
            'name' => 'Spider Man',
            'release_year' => 1920,
        ],
        permissions: [
            Permission::read(Role::any()),
            Permission::update(Role::users()),
            Permission::delete(Role::users()),
        ]
    );

    return [
        'call' => 'api.addRow',
        'response' => $response,
    ];
}

/**
 * Delete table
 * Delete a table by it's unique id.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#deleteTable
 * @return array
 * @throws Exception
 */
function deleteTable(): array
{
    global $tablesDB, $databaseId, $tableId;

    $response = $tablesDB->deleteTable($databaseId, $tableId);

    return [
        'call' => 'api.deleteTable',
        'response' => $response,
    ];
}

/**
 * Delete Database
 * Delete a database by it's unique id.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/tablesdb#delete
 * @return array
 * @throws Exception
 */
function deleteDatabase(): array
{
    global $tablesDB, $databaseId;

    $response = $tablesDB->delete($databaseId);

    return [
        'call' => 'api.deleteDatabase',
        'response' => $response,
    ];
}

/**
 * Create a bucket
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/storage#createBucket
 * @return array
 * @throws Exception
 */
function createBucket(): array
{
    global $storage, $bucketId;

    $response = $storage->createBucket(
        bucketId: ID::unique(),
        name: 'test bucket',
        permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users()),
        ],
        fileSecurity: true,
    );

    $bucketId = $response->id;

    return [
        'call' => 'api.createBucket',
        'response' => $response,
    ];
}

/**
 * Create a new file.
 * The user who creates the file will automatically be assigned to read and write
 * access unless he has passed custom values for read and write arguments.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/storage#createFile
 * @return array
 * @throws Exception
 */
function createFile(): array
{
    global $storage, $bucketId, $fileId;

    $response = $storage->createFile(
        $bucketId,
        fileId: ID::unique(),
        file: InputFile::withPath(__DIR__ . '/test.txt'),
        permissions: [
            Permission::read(Role::any()),
        ]
    );

    $fileId = $response->id;

    return [
        'call' => 'api.createFile',
        'response' => $response,
    ];
}

/**
 * Get a list of all the user files.
 * You can use the query params to filter your results. On admin mode,
 * this endpoint will return a list of all of the project files.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/storage#listFiles
 * @return array
 * @throws Exception
 */
function listFiles(): array
{
    global $storage, $bucketId;

    $response = $storage->listFiles($bucketId);

    return [
        'call' => 'api.listFiles',
        'response' => $response,
    ];
}

/**
 * Delete a file by its unique ID.
 * Only users with write permissions have access to delete this resource.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/storage#deleteFile
 * @return array
 * @throws Exception
 */
function deleteFile(): array
{
    global $storage, $bucketId, $fileId;

    $response = $storage->deleteFile($bucketId, $fileId);

    return [
        'call' => 'api.deleteFile',
        'response' => $response,
    ];
}

/**
 * Delete a bucket by its unique ID.
 * Only users with write permissions have access to delete this resource.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/storage#deleteBucket
 * @return array
 * @throws Exception
 */
function deleteBucket(): array
{
    global $storage, $bucketId;

    $response = $storage->deleteBucket($bucketId);

    return [
        'call' => 'api.deleteBucket',
        'response' => $response,
    ];
}

/**
 * Create a new user.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/users#create
 * @return array
 * @throws Exception
 */
function createUser(): array
{
    global $users;

    $suffix = \time();

    $response = $users->create(
        userId: ID::unique(),
        email: "email{$suffix}@example.com",
        password: 'password',
        name: "Example {$suffix}"
    );

    return [
        'call' => 'api.createUser',
        'response' => $response,
    ];
}

/**
 * How far a list route counts before it gives up and answers with what it
 * reached: the server's `APP_LIMIT_COUNT`.
 */
const APP_LIMIT_COUNT = 5000;

/**
 * Page size for the count below. Counting wants the biggest page it can get —
 * every page is a round trip — unlike a page someone is going to look at.
 */
const COUNT_PAGE_SIZE = 1000;

/**
 * Get a list of all the project users.
 *
 * The `total` that comes back is a count with a budget, not a count: see
 * `countUsersPastTheCountLimit()` below before showing it to anyone or paging
 * with it.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/users#list
 * @throws Exception
 */
function listUsers(): array
{
    global $users;

    $response = $users->list();

    return [
        'call' => 'api.listUsers',
        'response' => $response,
    ];
}

/**
 * Count the project's users, including the ones past the point the server
 * stopped counting.
 *
 * A list `total` is a count with a budget: the server counts at most
 * `APP_LIMIT_COUNT` (5,000) rows and answers with whatever it reached, so a
 * total sitting at that number means "at least 5,000 users", not "5,000 users".
 * A project with 50,000 of them reports 5,000, and the 200 pages of 25 that
 * number divides into end on a page that is not the last one.
 *
 * [appwrite/appwrite#15496](https://github.com/appwrite/appwrite/pull/15496)
 * fixed the console's half of this: a clamped total now reads `5,000+`, and no
 * page count is derived from it. A server reading the same list has the same
 * three options:
 *
 * - report the number as the floor it is (`5,000+`), which costs nothing;
 * - page to the end of the list for the exact number, which the count will not
 *   give. The walk below asks for the next page with `Query::cursorAfter()`
 *   rather than a growing `Query::offset()`, so each page starts where the last
 *   one ended instead of re-scanning every row ahead of it;
 * - ask for no count at all with `total: false`, which skips the count query.
 *   The `0` that answers means "not counted"; reading it as "no users" is the
 *   same mistake in the other direction.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/users#list
 * @throws Exception
 */
function countUsersPastTheCountLimit(): array
{
    global $users;

    $page = $users->list(queries: [Query::limit(COUNT_PAGE_SIZE)]);
    $reported = $page->total;
    $requests = 1;

    // Under the budget the count is exact, so it is the answer. At it, paging
    // to the end of the list is the only way to learn the real number: a full
    // page means there is more list past what was counted.
    $exact = $reported;

    if ($reported >= APP_LIMIT_COUNT) {
        $exact = \count($page->users);

        while (\count($page->users) === COUNT_PAGE_SIZE) {
            $rows = $page->users;

            $page = $users->list(queries: [
                Query::limit(COUNT_PAGE_SIZE),
                Query::cursorAfter($rows[\array_key_last($rows)]->id),
            ]);

            $exact += \count($page->users);
            $requests++;
        }
    }

    $uncounted = $users->list(queries: [Query::limit(1)], total: false);

    return [
        'call' => 'api.countUsersPastTheCountLimit',
        'response' => [
            'reportedTotal' => $reported,
            // How the console shows this number since #15496.
            'reportedTotalLabel' => $reported >= APP_LIMIT_COUNT
                ? \number_format($reported) . '+'
                : \number_format($reported),
            'exactTotal' => $exact,
            'requestsToCount' => $requests,
            // `total: false` means no count was taken, not that there is nothing
            // to count: rows still come back.
            'uncountedTotal' => $uncounted->total,
            'uncountedRows' => \count($uncounted->users),
        ],
    ];
}

/**
 * Get an account of authenticated user. Works only with JWT
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/account#get
 * @throws Exception
 */
function getAccount(): array
{
    global $account;

    $response = $account->get();

    return [
        'call' => 'api.getAccount',
        'response' => $response,
    ];
}

/**
 * Email a password recovery link to an address.
 *
 * A guest route: it needs the project, and neither an API key nor a session.
 * The `X-Appwrite-Project` header the SDK sends for it is what scopes its abuse
 * limit — the counter is kept per project and address, so the handful of
 * attempts one project is allowed for an address is its own, and another
 * project asking to recover the same address still gets its full share.
 *
 * Two answers are worth handling rather than letting them abort a flow:
 * `429` once the project has spent its share for the address (retry later), and
 * `404` when no account in the project uses the address.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/account#createRecovery
 * @throws Exception
 */
function createRecovery(): array
{
    global $account;

    try {
        $response = $account->createRecovery(
            email: 'recovery@example.com',
            url: 'https://example.com/recover'
        );
    } catch (AppwriteException $e) {
        if ($e->getCode() !== 429 && $e->getCode() !== 404) {
            throw $e;
        }

        $response = [
            'recoveryRequested' => false,
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
        ];
    }

    return [
        'call' => 'api.createRecovery',
        'response' => $response,
    ];
}

/**
 * Log a user in from a token secret, the way a server relays a login.
 *
 * `users->createToken()` mints a short secret for a user with the API key, and
 * `account->createSession()` exchanges it for a session. The exchange route is
 * where a wrong secret is answered, and since
 * [appwrite/appwrite#15470](https://github.com/appwrite/appwrite/pull/15470) a
 * wrong guess counts against the per-user abuse limit for *every* caller — an
 * API key included. A key is trusted with throughput, not with the inbox or
 * handset the secret was sent to, and a six digit code is only 10^6 guesses, so
 * the limit is the only thing standing between a key and any account.
 *
 * What that means for code like this one: handle `401` (wrong or expired
 * secret) and `429` (the user's share of attempts is spent) instead of retrying
 * the exchange. Retrying a secret the user mistyped now spends the attempts of
 * the user it names, so a retry loop locks that user out of signing in.
 *
 * The same applies to every route that confirms a secret with the caller's
 * copy: `updateRecoveryOTP()`, `updatePhoneSession()`,
 * `updateMagicURLSession()`, `updateMFAChallenge()` and `updateRecovery()`.
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/account#createSession
 * @throws Exception
 */
function createSessionFromToken(): array
{
    global $users, $account;

    $suffix = \time();

    $user = $users->create(
        userId: ID::unique(),
        email: "session{$suffix}@example.com",
        password: 'password',
        name: "Session {$suffix}"
    );

    $token = $users->createToken(userId: $user->id);

    try {
        $response = $account->createSession(
            userId: $token->userId,
            secret: $token->secret
        );
    } catch (AppwriteException $e) {
        if ($e->getCode() !== 401 && $e->getCode() !== 429) {
            throw $e;
        }

        // 401: the secret is wrong or has expired — ask the user for a new one.
        // 429: this user's attempts are spent — wait, do not guess again.
        $response = [
            'sessionCreated' => false,
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
        ];
    }

    return [
        'call' => 'api.createSessionFromToken',
        'response' => $response,
    ];
}

/**
 * The address the provider vouches for in the sign-in below.
 *
 * The stub's project holds an account that was registered under this address by
 * somebody who never proved it, so the sign-in claims that account — which is
 * what appwrite/appwrite#15282 is about.
 */
const CLAIMED_EMAIL = 'claimed@example.com';

/**
 * An unsigned stand-in for the id token a provider hands a client.
 *
 * A real caller passes on the token the provider issued and nothing else:
 * Appwrite verifies it against the provider's keys and reads the subject and
 * the verified address out of it, so a token nobody signed is refused with
 * `401`. This exists so the playground has something to send without a Google
 * or Apple client in the loop.
 */
function providerIdToken(string $subject, string $email): string
{
    $segment = static fn (array $claims): string => \rtrim(
        \strtr(\base64_encode((string) \json_encode($claims)), '+/', '-_'),
        '='
    );

    return $segment(['alg' => 'none', 'typ' => 'JWT'])
        . '.'
        . $segment(['sub' => $subject, 'email' => $email, 'email_verified' => true])
        . '.';
}

/**
 * Sign in with a provider id token, and read what that did to the account the
 * session landed on.
 *
 * When the address a provider vouches for belongs to an account that was
 * registered under it but never proved it, signing in *claims* that account:
 * the registrant's password, every session it had and every token outstanding
 * for it are revoked, and the address is recorded as proven. Appwrite has
 * always done that — somebody who can prove an address outranks somebody who
 * only typed it — but
 * [appwrite/appwrite#15282](https://github.com/appwrite/appwrite/pull/15282)
 * made the whole step atomic: the account is re-read under its row lock, the
 * "not proved yet" check is made on *that* copy, and the revoke and the
 * verification write commit together. Before the fix the claim decided from a
 * copy read earlier and revoked in a transaction of its own, so a session the
 * registrant created meanwhile could outlive the revoke, and two claims of one
 * address could each sweep what the other had just created.
 *
 * What that means for code like this one:
 *
 * - every credential the caller held for that account is gone the moment the
 *   claim commits. A token minted before it now answers `401` on the exchange
 *   and has to be minted again, and `users->listSessions()` comes back without
 *   the sessions it used to list. There is nothing to retry — the claim is the
 *   intended outcome, not a failure;
 * - `emailVerification` reading `true` is the signal that the claim has already
 *   happened. Since the flag is persisted inside the claim, a session created
 *   after reading it is not swept by a late second claim, which is the ordering
 *   the fix bought. A request that fails *after* the claim now also leaves the
 *   account proven rather than credential-less and unverified;
 * - the claim deliberately still runs when the account has MFA on. The session
 *   it hands out carries the `oauth2` factor, so skipping it would let the
 *   claimant in while leaving the registrant its password and second factor.
 *
 * Against a real instance the token below is refused with `401
 * user_invalid_token` long before any of this, since no provider signed it.
 *
 * @see https://appwrite.io/docs/references/cloud/client-php/account#createIdTokenSession
 * @throws Exception
 */
function claimAccountWithProviderEmail(): array
{
    global $account, $users;

    try {
        $session = $account->createIdTokenSession(
            provider: IdTokenProvider::GOOGLE(),
            idToken: providerIdToken('google-playground', CLAIMED_EMAIL),
            name: 'Playground',
        );
    } catch (AppwriteException $e) {
        if (!\in_array($e->getCode(), [400, 401, 409], true)) {
            throw $e;
        }

        // 401: the provider token was not accepted — start the sign-in over.
        // 400: the sign-in did not hold — show "could not sign you in" and stop.
        // 409: that provider account is linked to a different user already.
        return [
            'call' => 'api.claimAccountWithProviderEmail',
            'response' => [
                'signedIn' => false,
                'code' => $e->getCode(),
                'type' => $e->getType(),
                'message' => $e->getMessage(),
            ],
        ];
    }

    // The account the session belongs to, read back with the API key: the one
    // that was registered under the address, now claimed for whoever proved it.
    $claimed = $users->get(userId: $session->userId);
    $sessions = $users->listSessions(userId: $session->userId);
    $sessionIds = \array_map(static fn (Session $s): string => $s->id, $sessions->sessions);

    return [
        'call' => 'api.claimAccountWithProviderEmail',
        'response' => [
            'signedIn' => true,
            'sessionId' => $session->id,
            'userId' => $session->userId,
            'factors' => $session->factors,
            // Written by the claim itself, in the same commit as the revoke.
            'emailVerification' => $claimed->emailVerification,
            // The registrant's password, gone: its sign-in now answers `401`.
            'passwordStillSet' => ($claimed->password ?? '') !== '',
            // And its sessions with it, so the only one left is this sign-in's.
            'sessionsLeft' => $sessionIds,
            'onlyThisSessionLeft' => $sessionIds === [$session->id],
        ],
    ];
}

/**
 * Create a function
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#create
 * @throws Exception
 */
function createFunction(): array
{
    global $functions, $functionId;

    $response = $functions->create(
        functionId: ID::unique(),
        name: 'Test Function',
        runtime: Runtime::PHP84(),
        execute: [Role::any()],
    );

    $functionId = $response->id;

    return [
        'call' => 'api.createFunction',
        'response' => $response,
    ];
}

/**
 * Create a deployment
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#createDeployment
 * @throws Exception
 */
function createDeployment(): array
{
    global $functions, $functionId;

    $response = $functions->createDeployment(
        functionId: $functionId,
        code: InputFile::withPath(__DIR__ . '/resources/code.tar.gz'),
        activate: true,
        entrypoint: "src/index.php",
        commands: "composer install"
    );

    // wait for deployment to be ready
    sleep(5);

    return [
        'call' => 'api.createDeployment',
        'response' => $response,
    ];
}

/**
 * Create sync execution
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#createExecution
 * @throws Exception
 */
function createSyncExecution(): array
{
    global $functions, $functionId;

    $response = $functions->createExecution(
        functionId: $functionId,
        body: "",
        async: false,
        xpath: "/",
        method: ExecutionMethod::GET(),
        headers: [],
    );

    return [
        'call' => 'api.createExecution',
        'response' => $response,
    ];
}

/**
 * Create async execution
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#createExecution
 * @throws Exception
 */
function createAsyncExecution(): array
{
    global $functions, $functionId;

    $response = $functions->createExecution(
        functionId: $functionId,
        body: "",
        async: true,
        xpath: "/",
        method: ExecutionMethod::GET(),
        headers: [],
    );

    // wait for 2 seconds to ensure execution is finished
    sleep(2);

    $asyncResponse = $functions->getExecution($functionId, $response->id);

    return [
        'call' => 'api.createExecution',
        'response' => $asyncResponse,
    ];
}

/**
 * List functions
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#list
 * @throws Exception
 */
function listFunctions(): array
{
    global $functions;

    $response = $functions->list();

    return [
        'call' => 'api.listFunctions',
        'response' => $response,
    ];
}

/**
 * Delete a function
 *
 * @see https://appwrite.io/docs/references/cloud/server-php/functions#delete
 * @throws Exception
 */
function deleteFunction(): array
{
    global $functions, $functionId;

    $response = $functions->delete($functionId);

    return [
        'call' => 'api.deleteFunction',
        'response' => $response,
    ];
}

/**
 * Execute all functions, collect their return values
 * and print everything at the end.
 */
$ret = [];
$methods = [
    'createDatabase',
    'createTable',
    'listTables',
    'addRow',
    'deleteTable',
    'deleteDatabase',
    'createBucket',
    'createFile',
    'listFiles',
    'deleteFile',
    'deleteBucket',
    'createUser',
    'listUsers',
    'countUsersPastTheCountLimit',
    'createRecovery',
    'createSessionFromToken',
    'claimAccountWithProviderEmail',
    'createFunction',
    'createDeployment',
    'createSyncExecution',
    'createAsyncExecution',
    'listFunctions',
    'deleteFunction',
    // 'getAccount' // works only with JWT
];

foreach ($methods as $method) {
    try {
        if (\function_exists($method)) {
            $ret[] = $method();
        }
    } catch (Exception $e) {
        \print_r($e->getMessage());
        \print_r($e->getTraceAsString());
        \print_r("");
    }
}

appwriteDebug($ret);
