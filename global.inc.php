<?php
require_once __DIR__ . '/vendor/autoload.php';

if (!defined('ENDPOINT')) {
    define('ENDPOINT', 'https://localhost/v1');
}

if (!defined('PROJECT_ID')) {
    define('PROJECT_ID', '<Your Project ID />');
}

if (!defined('API_KEY')) {
    define('API_KEY', '<Your Project Secret Api key />');
}

// Helper functions

/**
 * Helper method to output debug data for all passed variables,
 * uses `print_r()` for arrays and objects, `var_dump()` otherwise.
 */
function appwriteDebug()
{
    echo "<pre>";

    $args = func_get_args();
    $length = count($args);

    if ($length === 0) {
        echo "ERROR: No arguments provided.<hr>";
    } else {
        foreach ($args as $i => $iValue) {
            $arg = $iValue;

            echo "<h2>Argument {$i} (" . gettype($arg) . ")</h2>";

            if (is_array($arg) || is_object($arg)) {
                print_r($arg);
            } else {
                var_dump($arg);
            }

            echo "<hr>";
        }
    }

    $backtrace = debug_backtrace();

    // output call location to help finding these debug outputs again
    echo "appwriteDebug() called in {$backtrace[0]['file']} on line {$backtrace[0]['line']}";
    echo "</pre>";

    exit;
}
