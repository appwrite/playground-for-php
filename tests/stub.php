<?php

/**
 * Starts and stops `tests/stub-server.php` on PHP's built-in web server.
 */

declare(strict_types=1);

final class Stub
{
    /** @param resource $process */
    private function __construct(
        public readonly string $endpoint,
        private readonly string $log,
        private readonly string $state,
        private $process
    ) {
    }

    /**
     * Boot the stub and wait for it to answer.
     */
    public static function start(): self
    {
        $port = self::freePort();
        $log = (string) \tempnam(\sys_get_temp_dir(), 'stub-log-');
        $state = (string) \tempnam(\sys_get_temp_dir(), 'stub-state-');

        // Truncated rather than deleted: the server appends to the log and
        // reads the state, and a missing file is a 500 from the stub.
        \file_put_contents($log, '');
        \file_put_contents($state, '{}');

        $process = \proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', \dirname(__DIR__), __DIR__ . '/stub-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['STUB_LOG' => $log, 'STUB_STATE' => $state] + \getenv()
        );

        if (!\is_resource($process)) {
            throw new RuntimeException('Could not start the stub server');
        }

        $stub = new self('http://127.0.0.1:' . $port . '/v1', $log, $state, $process);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $health = @\file_get_contents($stub->endpoint . '/health');

            if (\is_string($health) && \str_contains($health, 'pass')) {
                return $stub;
            }

            \usleep(50_000);
        }

        $stub->stop();

        throw new RuntimeException('The stub server did not come up');
    }

    /**
     * Every request the stub has answered so far, in order.
     *
     * @return list<array{method: string, path: string, key: bool}>
     */
    public function requests(): array
    {
        $lines = \file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return \array_values(\array_filter(\array_map(
            static fn (string $line): mixed => \json_decode($line, true),
            $lines
        ), \is_array(...)));
    }

    /**
     * Forget the requests logged so far, so one test does not read another's.
     */
    public function forgetRequests(): void
    {
        \file_put_contents($this->log, '');
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            \proc_terminate($this->process);
            \proc_close($this->process);
        }

        @\unlink($this->log);
        @\unlink($this->state);
    }

    /**
     * A port nothing is listening on, found by letting the OS pick one.
     */
    private static function freePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException('Could not find a free port: ' . $error);
        }

        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, \strrpos($name, ':') + 1);
    }
}
