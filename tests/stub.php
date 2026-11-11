<?php

/**
 * Starts `tests/stub-server.php` on PHP's built-in server and tears it down
 * again when the test script ends.
 */

declare(strict_types=1);

/**
 * Ask the kernel for a free port, then hand it to the built-in server.
 */
function freePort(): int
{
    $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($socket === false) {
        throw new RuntimeException("Could not reserve a port: {$error}");
    }

    $name = (string) \stream_socket_get_name($socket, false);
    \fclose($socket);

    return (int) \substr($name, \strrpos($name, ':') + 1);
}

/**
 * @return array{port: int, requestLog: string, serverLog: string}
 */
function startStub(): array
{
    $requestLog = (string) \tempnam(\sys_get_temp_dir(), 'appwrite-requests-');
    $serverLog = (string) \tempnam(\sys_get_temp_dir(), 'appwrite-stub-');
    $port = freePort();

    $server = \proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', __DIR__, __DIR__ . '/stub-server.php'],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $serverLog, 'w'],
            2 => ['file', $serverLog, 'a'],
        ],
        $pipes,
        __DIR__,
        \array_merge(\getenv(), ['STUB_LOG' => $requestLog])
    );

    if (!\is_resource($server)) {
        throw new RuntimeException('Could not start the stub server.');
    }

    \register_shutdown_function(static function () use ($server): void {
        \proc_terminate($server);
        \proc_close($server);
    });

    // Wait for the server to accept connections.
    $deadline = \microtime(true) + 10;
    do {
        $probe = @\fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

        if (\is_resource($probe)) {
            \fclose($probe);

            return ['port' => $port, 'requestLog' => $requestLog, 'serverLog' => $serverLog];
        }

        \usleep(50_000);
    } while (\microtime(true) < $deadline);

    throw new RuntimeException("The stub server never came up on port {$port}.");
}

/**
 * Every request the stub has seen so far, oldest first.
 *
 * @return array<int, array<string, mixed>>
 */
function stubRequests(string $requestLog): array
{
    $lines = \array_filter(\explode("\n", (string) \file_get_contents($requestLog)), '\strlen');

    return \array_map(
        static fn (string $line): array => \json_decode($line, true),
        \array_values($lines)
    );
}
