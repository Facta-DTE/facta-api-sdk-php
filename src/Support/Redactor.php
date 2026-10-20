<?php

declare(strict_types=1);

namespace Facta\Api\Support;

/**
 * Removes secrets from anything that may end up in an exception message or in
 * `details`. Mirrors `redactErrorValue` of the TypeScript SDK: known secret
 * strings are replaced, and object members whose name looks sensitive are
 * dropped.
 *
 * @internal
 */
final class Redactor
{
    private const SENSITIVE_FIELD =
        '/(?:authorization|api[-_]?key|sign[-_]?key|unlock[-_]?key|password|passphrase|secret|token|credential|private[-_]?key|ciphertext|refresh[-_]?token|service[-_]?key)/i';

    private const MAX_DEPTH = 8;

    /**
     * @param mixed    $value
     * @param string[] $secrets
     * @return mixed
     */
    public static function redact($value, array $secrets, int $depth = 0)
    {
        if ($depth > self::MAX_DEPTH) {
            return '[omitted]';
        }
        if (is_string($value)) {
            foreach ($secrets as $secret) {
                if ($secret !== '') {
                    $value = str_replace($secret, '[REDACTED]', $value);
                }
            }
            return $value;
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match(self::SENSITIVE_FIELD, $key) === 1) {
                    continue;
                }
                $safe[$key] = self::redact($item, $secrets, $depth + 1);
            }
            return $safe;
        }
        if (is_object($value)) {
            $safe = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                if (preg_match(self::SENSITIVE_FIELD, (string) $key) === 1) {
                    continue;
                }
                $safe->{(string) $key} = self::redact($item, $secrets, $depth + 1);
            }
            return $safe;
        }
        return $value;
    }
}
