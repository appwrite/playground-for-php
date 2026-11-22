<?php
require_once __DIR__ . '/vendor/autoload.php';

if (!\defined('ENDPOINT')) {
    \define('ENDPOINT', 'https://localhost/v1');
}

if (!\defined('PROJECT_ID')) {
    \define('PROJECT_ID', '<Your Project ID />');
}

if (!\defined('API_KEY')) {
    \define('API_KEY', '<Your Project Secret Api key />');
}

// Helper functions

/**
 * The hostnames in a project's web platform list that trust more than one origin.
 *
 * A project's web platforms are the origins Appwrite answers a credentialed
 * browser request from: a request carrying the signed-in user's session cookie
 * is refused `403` typed `general_unknown_origin` unless its origin is one of
 * them. The console used to seed a new project with the hostname `*`, which
 * matches every origin — so any page a signed-in user visited could call the
 * project's API as them, knowing only the public project ID.
 * [appwrite/appwrite#15509](https://github.com/appwrite/appwrite/pull/15509)
 * seeds `localhost` instead.
 *
 * `*.example.com` is the same idea narrowed to one domain, and still covers
 * every subdomain under it; the console warns about the bare `*` only. Nothing
 * migrates a project created before the fix, so both can still be registered,
 * and a deploy check that reads the list and refuses to ship with a wildcard in
 * it is how that gets noticed. `reviewWebPlatforms()` in `playground.php` shows
 * the call.
 *
 * @param array<int, array<string, mixed>> $platforms the `platforms` of `Project::listPlatforms()`
 * @return list<string>
 */
function wildcardHostnames(array $platforms): array
{
    $wildcards = [];

    foreach ($platforms as $platform) {
        $hostname = (string) ($platform['hostname'] ?? '');

        if ($hostname === '*' || \str_starts_with($hostname, '*.')) {
            $wildcards[] = $hostname;
        }
    }

    return $wildcards;
}

/**
 * Helper method to output debug data for all passed variables,
 * uses `print_r()` for arrays and objects, `var_dump()` otherwise.
 */
function appwriteDebug()
{
    echo "<pre>";

    $args = \func_get_args();
    $length = \count($args);

    if ($length === 0) {
        echo "ERROR: No arguments provided.<hr>";
    } else {
        foreach ($args as $i => $iValue) {
            $arg = $iValue;

            echo "<h2>Argument {$i} (" . \gettype($arg) . ")</h2>";

            if (\is_array($arg) || \is_object($arg)) {
                \print_r($arg);
            } else {
                \var_dump($arg);
            }

            echo "<hr>";
        }
    }

    $backtrace = \debug_backtrace();

    // output call location to help finding these debug outputs again
    echo "appwriteDebug() called in {$backtrace[0]['file']} on line {$backtrace[0]['line']}";
    echo "</pre>";

    exit;
}
