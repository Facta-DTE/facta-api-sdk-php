<?php

declare(strict_types=1);

namespace Facta\Api\Tests\Support;

/** Starts `php -S` on a free local port with tests/fixtures/server.php. */
final class LocalServer
{
    /** @var resource|null */
    private $process;

    /** @var string */
    public $baseUrl = '';

    public static function start(): self
    {
        $server = new self();
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new \RuntimeException('Cannot reserve a port: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/server.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start php -S.');
        }
        $server->process = $process;
        $server->baseUrl = 'http://127.0.0.1:' . $port;

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return $server;
            }
            usleep(50000);
        }
        $server->stop();
        throw new \RuntimeException('php -S did not come up.');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }
}
