<?php

/**
 * Runs `playground.php` against the stub server.
 *
 * `global.inc.php` only defines its constants when they are not already set, so
 * defining them here is all it takes to point the playground somewhere else.
 * Any arguments are passed straight through to the playground, which reads them
 * as the list of calls to make.
 */

declare(strict_types=1);

$endpoint = \getenv('PLAYGROUND_ENDPOINT');

if (!\is_string($endpoint) || $endpoint === '') {
    \fwrite(STDERR, "PLAYGROUND_ENDPOINT is not set\n");
    exit(1);
}

\define('ENDPOINT', $endpoint);
\define('PROJECT_ID', 'playground');
\define('API_KEY', 'playground-api-key');
\define('VERIFICATION_USER_ID', 'playground-user');
\define('VERIFICATION_SECRET', \getenv('PLAYGROUND_VERIFICATION_SECRET') ?: 'playground-secret');

require __DIR__ . '/../playground.php';
