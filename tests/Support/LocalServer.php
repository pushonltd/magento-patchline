<?php

declare(strict_types=1);

namespace Patchline\Tests\Support;

use RuntimeException;

/** A `php -S` server on a free local port, standing in for repo.magento.com. */
final class LocalServer
{
    /**
     * @param resource $process
     */
    private function __construct(private $process, private readonly int $port)
    {
    }

    public static function start(string $router): self
    {
        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the local test server.');
        }
        $server = new self($process, $port);
        $server->waitUntilListening();

        return $server;
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if ($socket === false) {
            throw new RuntimeException('Cannot find a free port: ' . $message);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitUntilListening(): void
    {
        // Readiness poll, not a fixed delay: the server needs a moment to bind its port.
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $code, $message, 0.2);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }
        $this->stop();

        throw new RuntimeException('The local test server did not start on port ' . $this->port . '.');
    }
}
