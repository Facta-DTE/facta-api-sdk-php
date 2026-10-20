<?php

declare(strict_types=1);

namespace Facta\Api\Json;

/**
 * fingerprint-v1: lower-case hex SHA-256 of the RFC 8785 (JCS) form of the
 * caller's request, taken before any `customerId` / `productId` resolution.
 * Sent as `X-Facta-Request-Fingerprint`, it makes an idempotent retry
 * independent of how a language spells its JSON.
 */
final class Fingerprint
{
    /**
     * @param string $jsonBody The caller's request as JSON text.
     * @throws \InvalidArgumentException When the text is not valid JSON.
     */
    public static function v1(string $jsonBody): string
    {
        return hash('sha256', Jcs::canonicalize($jsonBody));
    }
}
