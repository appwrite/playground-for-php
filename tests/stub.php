<?php

/**
 * Starting and stopping `tests/stub-server.php`, shared by the tests.
 */

declare(strict_types=1);

final class Stub
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $errors;

    private function __construct(
        public readonly string $endpoint,
        public readonly string $requestLog,
        public readonly string $stateFile,
        $process,
        $errors
    ) {
        $this->process = $process;
        $this->errors = $errors;
    }

    /**
     * Ask the OS for a port nobody is using, then hand it to the stub server.
     * There is a gap between closing the probe and the server binding, which is
     * why this is a test-only shortcut and not a pattern to copy.
     */
    private static function freePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException("Could not reserve a port: {$error}");
        }

        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, \strrpos($name, ':') + 1);
    }

    private static function tempFile(string $prefix): string
    {
        $file = \tempnam(\sys_get_temp_dir(), $prefix);

        if ($file === false) {
            throw new RuntimeException("Could not create a temporary file for {$prefix}");
        }

        return $file;
    }

    public static function start(): self
    {
        $root = \dirname(__DIR__);
        $port = self::freePort();
        $requestLog = self::tempFile('playground-requests-');
        $stateFile = self::tempFile('playground-stub-state-');
        $errors = \tmpfile();

        if ($errors === false) {
            throw new RuntimeException('Could not create a log for the stub server');
        }

        $process = \proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root, $root . '/tests/stub-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => $errors],
            $pipes,
            $root,
            ['STUB_LOG' => $requestLog, 'STUB_STATE' => $stateFile] + \getenv()
        );

        if (!\is_resource($process)) {
            throw new RuntimeException('Could not start the stub server');
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);

            if ($probe !== false) {
                \fclose($probe);

                return new self(
                    "http://127.0.0.1:{$port}/v1",
                    $requestLog,
                    $stateFile,
                    $process,
                    $errors
                );
            }

            \usleep(50_000);
        }

        \proc_terminate($process);
        \proc_close($process);

        throw new RuntimeException("Stub server never accepted a connection on port {$port}");
    }

    /**
     * The requests the stub has served so far, oldest first.
     *
     * @return list<array{method: string, path: string, project: string}>
     */
    public function requests(): array
    {
        $lines = \file($this->requestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return \array_map(
            static fn (string $line): array => (array) \json_decode($line, true),
            $lines === false ? [] : $lines
        );
    }

    public function errorLog(): string
    {
        \rewind($this->errors);

        return (string) \stream_get_contents($this->errors);
    }

    public function stop(): void
    {
        \proc_terminate($this->process);
        \proc_close($this->process);
        \fclose($this->errors);
        @\unlink($this->requestLog);
        @\unlink($this->stateFile);
    }
}
