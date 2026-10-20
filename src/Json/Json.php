<?php

declare(strict_types=1);

namespace Facta\Api\Json;

/**
 * Request-body encoding and response decoding.
 *
 * The server hashes the RAW body bytes for idempotency, so a request is encoded
 * ONCE per call and the same string is resent on every retry.
 */
final class Json
{
    /**
     * Encodes a request body. An empty PHP array is `[]`; pass `new \stdClass()`
     * where the API wants an empty object `{}`.
     *
     * @param mixed $value
     * @throws \InvalidArgumentException When the value cannot be encoded as JSON.
     */
    public static function encode($value): string
    {
        // A php.ini with serialize_precision=17 would write 0.1 as 0.10000000000000001.
        $previous = @ini_set('serialize_precision', '-1');
        try {
            $text = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } finally {
            if ($previous !== false) {
                @ini_set('serialize_precision', (string) $previous);
            }
        }
        if ($text === false) {
            throw new \InvalidArgumentException('The request body cannot be encoded as JSON: ' . json_last_error_msg());
        }
        return $text;
    }

    /**
     * Decodes a response. Objects come back as `\stdClass` so `{}` stays
     * distinguishable from `[]`.
     *
     * @return mixed
     * @throws \InvalidArgumentException On malformed JSON.
     */
    public static function decode(string $text)
    {
        $value = json_decode($text, false, 512);
        if ($value === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }
        return $value;
    }
}
