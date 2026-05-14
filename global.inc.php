<?php
require_once __DIR__ . '/vendor/autoload.php';

if (!defined('ENDPOINT')) {
    define('ENDPOINT', 'https://demo.appwrite.io/v1');
}

if (!defined('PROJECT_ID')) {
    define('PROJECT_ID', 'playground');
}

if (!defined('API_KEY')) {
    define('API_KEY', 'fff3b3caa94f9b9ab318c1bbf7038752f3a0780ad689b76e94ddb6c6da78ce3a0e25a8b9d31fe54a57a86ce24cd38ad91fbf9ffca70d54f364e65f9e0ae830319a46f01c81bb82371ca3da5409fd8224a61fc76a5ed3b5a476f56c2eaf85f9f2e627a4c1534bcaf5225b702d5150013c795acd888b46edca2878be40f4820cbf');
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
