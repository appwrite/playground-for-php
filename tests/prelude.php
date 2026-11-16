<?php

/**
 * Runs `playground.php` against the stub server.
 *
 * `global.inc.php` only defines its constants when they are not already set, so
 * defining them here is all it takes to point the playground somewhere else.
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

require __DIR__ . '/../playground.php';
