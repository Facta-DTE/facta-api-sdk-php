<?php

declare(strict_types=1);

namespace Facta\Api\Http;

use Facta\Api\UnsupportedEnvironmentException;

/**
 * Fallback transport on PHP streams, for hosts without ext-curl. Needs
 * `allow_url_fopen` and, for HTTPS, ext-openssl. TLS peer verification is
 * always on.
 */
final class StreamTransport implements Transport
{
    /** @var string|null */
    private $caBundle;

    public function __construct(?string $caBundle = null)
    {
        if (!in_array(strtolower((string) ini_get('allow_url_fopen')), ['1', 'on', 'true', 'yes'], true)) {
            throw new UnsupportedEnvironmentException(
                'Neither ext-curl nor allow_url_fopen is available, so the SDK has no way to make HTTP requests. '
                . 'Enable ext-curl, or set allow_url_fopen=On in php.ini.'
            );
        }
        $this->caBundle = $caBundle;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        if (stripos($url, 'https://') === 0 && !extension_loaded('openssl')) {
            throw new TransportException(
                TransportException::KIND_TLS,
                'ext-openssl is required to make HTTPS requests without ext-curl. Enable the openssl extension.'
            );
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // HTTP/1.1 keep-alive would make the read below wait for the timeout.
        $lines[] = 'Connection: close';
        if ($body !== null) {
            $lines[] = 'Content-Length: ' . strlen($body);
        }

        $http = [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
            'timeout' => $timeoutMs / 1000,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($this->caBundle !== null) {
            $ssl['cafile'] = $this->caBundle;
        }
        $context = stream_context_create(['http' => $http, 'ssl' => $ssl]);

        $startedAt = microtime(true);
        $deadline = $startedAt + $timeoutMs / 1000;
        // PHP reports the TLS cause in an earlier warning than the final "failed to open stream".
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $stream = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            $reason = $warnings !== [] ? implode(' | ', $warnings) : 'unknown error';
            // PHP words a read timeout as "HTTP request failed!", so the clock decides.
            $timedOut = stripos($reason, 'timed out') !== false
                || (microtime(true) - $startedAt) >= ($timeoutMs / 1000) * 0.9;
            if (stripos($reason, 'ssl') !== false || stripos($reason, 'certificate') !== false) {
                $kind = TransportException::KIND_TLS;
            } else {
                $kind = $timedOut ? TransportException::KIND_TIMEOUT : TransportException::KIND_CONNECT;
            }
            throw new TransportException($kind, 'Stream: ' . $reason);
        }

        stream_set_timeout($stream, max(1, (int) ceil($timeoutMs / 1000)));
        $meta = stream_get_meta_data($stream);
        $responseLines = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];

        $content = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }
            $content .= $chunk;
            if (microtime(true) > $deadline) {
                fclose($stream);
                throw new TransportException(TransportException::KIND_TIMEOUT, 'Stream: the request timed out.');
            }
        }
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        if (!empty($meta['timed_out'])) {
            throw new TransportException(TransportException::KIND_TIMEOUT, 'Stream: the request timed out.');
        }

        /** @var string[] $responseLines */
        list($status, $responseHeaders) = HeaderParser::parse(array_map('strval', $responseLines));
        if ($status === 0) {
            throw new TransportException(TransportException::KIND_OTHER, 'Stream: the answer had no HTTP status line.');
        }
        return new Response($status, $responseHeaders, $content);
    }
}
