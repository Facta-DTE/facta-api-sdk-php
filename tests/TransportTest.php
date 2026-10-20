<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use Facta\Api\Http\CurlTransport;
use Facta\Api\Http\StreamTransport;
use Facta\Api\Http\Transport;
use Facta\Api\Http\TransportException;
use Facta\Api\Http\TransportFactory;
use Facta\Api\Tests\Support\LocalServer;
use PHPUnit\Framework\TestCase;

/** Real HTTP exchanges against `php -S`, once per transport. */
final class TransportTest extends TestCase
{
    /** @var LocalServer|null */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
        }
    }

    private static function url(string $path): string
    {
        return (self::$server !== null ? self::$server->baseUrl : '') . $path;
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
    public function testGetPostHeadersAndBody(string $class): void
    {
        /** @var Transport $transport */
        $transport = new $class();

        $get = $transport->send('GET', self::url('/echo?x=1'), ['X-Facta-Key' => 'facta_test_k', 'Accept' => 'application/json'], null, 5000);
        $this->assertSame(200, $get->getStatus());
        $this->assertSame('one', $get->getHeader('X-Custom'));
        $this->assertSame('one', $get->getHeader('x-custom'));
        $data = json_decode($get->getBody());
        $this->assertSame('GET', $data->method);
        $this->assertSame('facta_test_k', $data->headers->{'x-facta-key'});

        $body = '{"ñ":"x/y","n":1.5}';
        $post = $transport->send('POST', self::url('/created'), ['Content-Type' => 'application/json'], $body, 5000);
        $this->assertSame(201, $post->getStatus());
        $echo = json_decode($post->getBody());
        $this->assertSame('POST', $echo->method);
        $this->assertSame($body, $echo->body, 'the body arrives byte for byte');
    }

    /**
     * @dataProvider transports
     */
    public function testEmptyBodyAndErrorStatusesAreAnswersNotExceptions(string $class): void
    {
        /** @var Transport $transport */
        $transport = new $class();
        $empty = $transport->send('GET', self::url('/empty'), [], null, 5000);
        $this->assertSame(204, $empty->getStatus());
        $this->assertSame('', $empty->getBody());

        $retry = $transport->send('POST', self::url('/retry'), [], '{}', 5000);
        $this->assertSame(409, $retry->getStatus());
        $this->assertSame('7', $retry->getHeader('retry-after'));
    }

    /**
     * @dataProvider transports
     */
    public function testTimeoutAndConnectionRefused(string $class): void
    {
        /** @var Transport $transport */
        $transport = new $class();
        $started = microtime(true);
        try {
            $transport->send('GET', self::url('/slow'), [], null, 700);
            $this->fail('TransportException expected');
        } catch (TransportException $e) {
            $this->assertSame(TransportException::KIND_TIMEOUT, $e->getKind());
            $this->assertLessThan(2.9, microtime(true) - $started);
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe); // the port is now closed
        try {
            $transport->send('GET', 'http://' . $name . '/', [], null, 2000);
            $this->fail('TransportException expected');
        } catch (TransportException $e) {
            $this->assertSame(TransportException::KIND_CONNECT, $e->getKind());
        }
    }

    /**
     * @dataProvider transports
     */
    public function testClientEndToEndWithRetryAfterAgainstARealServer(string $class): void
    {
        $sleeps = [];
        $client = new Facta([
            'apiKey' => 'facta_test_k',
            'baseUrl' => self::url(''),
            'transport' => new $class(),
            'maxRetries' => 1,
            'sleeper' => static function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
        ]);
        try {
            $client->request('POST', '/retry', ['a' => 1]);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::IDEMPOTENCY_IN_FLIGHT, $e->getErrorCode());
            $this->assertSame(7000, $e->getRetryAfterMs());
        }
        $this->assertSame([7000], $sleeps);

        $echo = $client->request('GET', '/echo');
        $this->assertInstanceOf(\stdClass::class, $echo);
        $this->assertSame('/echo', $echo->path);
    }

    public function testStatusAgainstARealServerUsesTheDefaultTransport(): void
    {
        $client = new Facta(['apiKey' => 'facta_test_k', 'baseUrl' => self::url('/api')]);
        $this->assertSame('/api/v1/status', $client->status()['path']);
    }

    public function testFactoryPrefersCurlAndFallsBackToStreams(): void
    {
        $expected = extension_loaded('curl') ? CurlTransport::class : StreamTransport::class;
        $this->assertInstanceOf($expected, TransportFactory::create());
        $this->assertInstanceOf(StreamTransport::class, new StreamTransport());
    }

    public function testTlsErrorsAreReportedAsTlsAndVerificationStaysOn(): void
    {
        // A plain-HTTP server answering a TLS handshake request: the handshake must fail, not be skipped.
        $url = str_replace('http://', 'https://', self::url('/echo'));
        foreach ($this->transports() as $case) {
            /** @var Transport $transport */
            $transport = new $case[0]();
            try {
                $transport->send('GET', $url, [], null, 3000);
                $this->fail('TransportException expected');
            } catch (TransportException $e) {
                $this->assertContains($e->getKind(), [TransportException::KIND_TLS, TransportException::KIND_CONNECT, TransportException::KIND_TIMEOUT]);
            }
        }
    }
}
