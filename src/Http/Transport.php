<?php

declare(strict_types=1);

namespace Facta\Api\Http;

/**
 * One HTTP exchange. Implementations never retry, never follow redirects and
 * never disable TLS verification; the client above decides what to repeat.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers Request headers, name => value.
     * @param int                   $timeoutMs Whole-request budget in milliseconds.
     *
     * @throws TransportException When no HTTP answer was obtained (connection, TLS, timeout).
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response;
}
