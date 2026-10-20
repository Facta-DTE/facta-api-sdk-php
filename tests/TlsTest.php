<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Http\CurlTransport;
use Facta\Api\Http\StreamTransport;
use Facta\Api\Http\Transport;
use Facta\Api\Http\TransportException;
use PHPUnit\Framework\TestCase;

/**
 * Verification is never disabled: a certificate nobody vouches for is refused, and the
 * caBundle option is the only way to trust a private authority.
 */
final class TlsTest extends TestCase
{
    /** @var string */
    private static $dir = '';
    /** @var resource|null */
    private static $process;
    /** @var int */
    private static $port = 0;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('openssl') || trim((string) shell_exec('command -v openssl')) === '') {
            self::markTestSkipped('The openssl extension and CLI are needed to make a test certificate.');
        }
        self::$dir = sys_get_temp_dir() . '/facta-tls-' . bin2hex(random_bytes(4));
        mkdir(self::$dir, 0700, true);
        $key = self::$dir . '/key.pem';
        $cert = self::$dir . '/cert.pem';
        $config = self::$dir . '/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=ext\nprompt=no\n[dn]\nCN=localhost\n[ext]\nsubjectAltName=IP:127.0.0.1,DNS:localhost\n");
        exec(sprintf(
            'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -days 2 -config %s 2>&1',
            escapeshellarg($key),
            escapeshellarg($cert),
            escapeshellarg($config)
        ), $output, $status);
        if ($status !== 0) {
            self::markTestSkipped('Could not create a test certificate: ' . implode(' ', $output));
        }
        file_put_contents(self::$dir . '/server.pem', file_get_contents($cert) . file_get_contents($key));

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/tls-server.php', (string) self::$port, self::$dir . '/server.pem', '6'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::$process = is_resource($process) ? $process : null;
        usleep(400000);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$dir);
    }

    /** @return array<string, array{string}> */
    public function transports(): array
    {
        $cases = ['streams' => [StreamTransport::class]];
        if (extension_loaded('curl')) {
            $cases['curl'] = [CurlTransport::class];
        }
        return $cases;
    }

    /**
     * @dataProvider transports
     */
    public function testUntrustedCertificateIsRefusedAsATlsFailure(string $class): void
    {
        /** @var Transport $transport */
        $transport = new $class();
        try {
            $transport->send('GET', 'https://127.0.0.1:' . self::$port . '/', [], null, 5000);
            $this->fail('A self-signed certificate must not be trusted by default.');
        } catch (TransportException $e) {
            $this->assertSame(TransportException::KIND_TLS, $e->getKind(), $e->getMessage());
        }
    }

    /**
     * @dataProvider transports
     */
    public function testCaBundleMakesThePrivateAuthorityTrusted(string $class): void
    {
        /** @var Transport $transport */
        $transport = new $class(self::$dir . '/cert.pem');
        $response = $transport->send('GET', 'https://127.0.0.1:' . self::$port . '/', [], null, 5000);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('{"secure":true}', $response->getBody());
    }
}
