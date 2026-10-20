<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\Http\Psr18Transport;
use Facta\Api\Http\TransportException;
use PHPUnit\Framework\TestCase;

final class Psr18TransportTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists('Psr\\Http\\Client\\ClientInterface') || !class_exists('Nyholm\\Psr7\\Factory\\Psr17Factory')) {
            $this->markTestSkipped('psr/http-client and nyholm/psr7 are dev dependencies; not installed here.');
        }
        require_once __DIR__ . '/Support/StubPsr18Client.php';
    }

    public function testStatusThroughAPsr18Client(): void
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $client = new Support\StubPsr18Client(function ($request) use ($factory) {
            return $factory->createResponse(200)->withHeader('Retry-After', '3')->withBody($factory->createStream('{"ok":true}'));
        });
        $facta = new Facta(['apiKey' => 'facta_test_k', 'transport' => new Psr18Transport($client, $factory, $factory)]);

        $this->assertTrue($facta->status()->ok);
        $request = $client->requests[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(Facta::DEFAULT_BASE_URL . '/v1/status', (string) $request->getUri());
        $this->assertSame('facta_test_k', $request->getHeaderLine('X-Facta-Key'));
    }

    public function testPostBodyAndClientFailures(): void
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $client = new Support\StubPsr18Client(function ($request) {
            throw new \RuntimeException('Connection timed out');
        });
        $transport = new Psr18Transport($client, $factory, $factory);
        try {
            $transport->send('POST', 'https://example.test/x', ['Content-Type' => 'application/json'], '{"a":1}', 1000);
            $this->fail('TransportException expected');
        } catch (TransportException $e) {
            $this->assertSame(TransportException::KIND_TIMEOUT, $e->getKind());
        }
        $this->assertSame('{"a":1}', (string) $client->requests[0]->getBody());
    }

    public function testWrongObjectsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Psr18Transport(new \stdClass(), new \stdClass(), new \stdClass());
    }
}
