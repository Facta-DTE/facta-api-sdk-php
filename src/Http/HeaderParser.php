<?php

declare(strict_types=1);

namespace Facta\Api\Http;

/**
 * Turns raw header lines into the name => value map {@see Response} expects.
 *
 * @internal
 */
final class HeaderParser
{
    /**
     * Folds the header block of the LAST response in `$lines` (earlier blocks
     * are redirects or `100 Continue`). Repeated names are joined with ", ".
     *
     * @param string[] $lines
     * @return array{0: int, 1: array<string, string>} status and headers
     */
    public static function parse(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'HTTP/') === 0) {
                // A new status line starts a new block: forget the previous one.
                $headers = [];
                $parts = explode(' ', $line, 3);
                $status = isset($parts[1]) ? (int) $parts[1] : 0;
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }
        return [$status, $headers];
    }
}
