<?php

declare(strict_types=1);

namespace Facta\Api\Http;

use Facta\Api\UnsupportedEnvironmentException;

/** Preferred transport: ext-curl. */
final class CurlTransport implements Transport
{
    /** libcurl error numbers that mean "the TLS handshake or certificate failed". */
    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    private const CURLE_OPERATION_TIMEDOUT = 28;

    /** @var string|null */
    private $caBundle;

    public function __construct(?string $caBundle = null)
    {
        if (!extension_loaded('curl')) {
            throw new UnsupportedEnvironmentException(
                'ext-curl is not loaded. Enable the curl extension, or let the SDK use PHP streams (requires ext-openssl for HTTPS).'
            );
        }
        $this->caBundle = $caBundle;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException(TransportException::KIND_OTHER, 'Could not initialise cURL.');
        }

        $lines = [];
        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }
        // Without this cURL adds "Expect: 100-continue" to large bodies.
        $requestHeaders[] = 'Expect:';

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 30000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$lines): int {
                $lines[] = $line;
                return strlen($line);
            },
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        foreach ($options as $option => $value) {
            if (!curl_setopt($handle, $option, $value)) {
                throw new TransportException(TransportException::KIND_OTHER, 'cURL rejected option ' . $option . '.');
            }
        }

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // No curl_close(): it does nothing since PHP 8.0, is deprecated in 8.5, and before 8.0 the
        // resource is freed as soon as the last reference goes away.
        unset($handle);

        if ($raw === false) {
            if ($errno === self::CURLE_OPERATION_TIMEDOUT) {
                throw new TransportException(TransportException::KIND_TIMEOUT, 'cURL: ' . $error);
            }
            if (in_array($errno, self::TLS_ERRORS, true)) {
                throw new TransportException(TransportException::KIND_TLS, 'cURL: ' . $error);
            }
            throw new TransportException(TransportException::KIND_CONNECT, 'cURL (' . $errno . '): ' . $error);
        }

        list($parsedStatus, $responseHeaders) = HeaderParser::parse($lines);
        return new Response($status > 0 ? $status : $parsedStatus, $responseHeaders, (string) $raw);
    }
}
