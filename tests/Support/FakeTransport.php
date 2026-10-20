<?php

declare(strict_types=1);

namespace Facta\Api\Tests\Support;

use Facta\Api\Http\Response;
use Facta\Api\Http\Transport;
use Facta\Api\Http\TransportException;

/** Scripted transport: each queued item is a Response (returned) or a Throwable (thrown). */
final class FakeTransport implements Transport
{
    /** @var array<int, Response|\Throwable> */
    private $queue;

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string, timeoutMs: int}> */
    public $requests = [];

    /** @param array<int, Response|\Throwable> $queue */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /** @param array<string, string> $headers */
    public static function json(int $status, string $json, array $headers = []): Response
    {
        return new Response($status, $headers + ['content-type' => 'application/json'], $json);
    }

    public static function failure(string $kind = TransportException::KIND_CONNECT): TransportException
    {
        return new TransportException($kind, 'boom');
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeoutMs' => $timeoutMs];
        if ($this->queue === []) {
            throw new \LogicException('FakeTransport: no scripted answer left.');
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
