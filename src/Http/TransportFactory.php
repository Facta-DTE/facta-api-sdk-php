<?php

declare(strict_types=1);

namespace Facta\Api\Http;

/** Picks the best transport this PHP can run: cURL first, PHP streams second. */
final class TransportFactory
{
    public static function create(?string $caBundle = null): Transport
    {
        if (extension_loaded('curl')) {
            return new CurlTransport($caBundle);
        }
        return new StreamTransport($caBundle);
    }
}
