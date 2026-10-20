<?php

declare(strict_types=1);

namespace Facta\Api\Http;

use Facta\Api\UnsupportedEnvironmentException;

/**
 * Adapter for a caller-supplied PSR-18 client. Works only when `psr/http-client`
 * and `psr/http-message` are installed and a PSR-17 request and stream factory
 * are given; nothing here is referenced until this class is constructed, so
 * the SDK loads fine without those packages.
 *
 * The per-request timeout and the CA bundle belong to the injected client: the
 * PSR-18 interface has no way to set them per call.
 */
final class Psr18Transport implements Transport
{
    /** @var object */
    private $client;

    /** @var object */
    private $requestFactory;

    /** @var object */
    private $streamFactory;

    /**
     * @param object $client         A \Psr\Http\Client\ClientInterface
     * @param object $requestFactory A \Psr\Http\Message\RequestFactoryInterface
     * @param object $streamFactory  A \Psr\Http\Message\StreamFactoryInterface
     */
    public function __construct($client, $requestFactory, $streamFactory)
    {
        foreach (
            [
                'Psr\\Http\\Client\\ClientInterface' => $client,
                'Psr\\Http\\Message\\RequestFactoryInterface' => $requestFactory,
                'Psr\\Http\\Message\\StreamFactoryInterface' => $streamFactory,
            ] as $interface => $instance
        ) {
            if (!interface_exists($interface)) {
                throw new UnsupportedEnvironmentException(
                    'The PSR-18 transport needs psr/http-client and psr/http-message (run: composer require psr/http-client psr/http-factory).'
                );
            }
            if (!($instance instanceof $interface)) {
                throw new \InvalidArgumentException('Expected an instance of ' . $interface . '.');
            }
        }
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        /** @var \Psr\Http\Message\RequestFactoryInterface $requests */
        $requests = $this->requestFactory;
        /** @var \Psr\Http\Message\StreamFactoryInterface $streams */
        $streams = $this->streamFactory;
        /** @var \Psr\Http\Client\ClientInterface $client */
        $client = $this->client;

        $request = $requests->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($streams->createStream($body));
        }

        try {
            $response = $client->sendRequest($request);
        } catch (\Throwable $e) {
            $kind = stripos($e->getMessage(), 'timed out') !== false || stripos($e->getMessage(), 'timeout') !== false
                ? TransportException::KIND_TIMEOUT
                : TransportException::KIND_CONNECT;
            throw new TransportException($kind, 'PSR-18 client: ' . $e->getMessage(), $e);
        }

        $out = [];
        foreach ($response->getHeaders() as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', $values);
        }
        return new Response($response->getStatusCode(), $out, (string) $response->getBody());
    }
}
