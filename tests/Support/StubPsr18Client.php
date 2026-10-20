<?php

declare(strict_types=1);

namespace Facta\Api\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Loaded only when psr/http-client is installed (see Psr18TransportTest). */
final class StubPsr18Client implements ClientInterface
{
    /** @var callable */
    private $handler;

    /** @var RequestInterface[] */
    public $requests = [];

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        return call_user_func($this->handler, $request);
    }
}
