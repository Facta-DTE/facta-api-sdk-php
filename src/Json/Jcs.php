<?php

declare(strict_types=1);

namespace Facta\Api\Json;

/**
 * RFC 8785 JSON Canonicalization Scheme in pure PHP (no mbstring, no bcmath).
 *
 * Number spelling follows ECMAScript Number::toString and does not depend on
 * `precision` or `serialize_precision`. Object members are sorted by UTF-16
 * code units.
 */
final class Jcs
{
    /** 2^53: integers beyond it must round to a double exactly as JavaScript does. */
    private const MAX_SAFE = 9007199254740992;

    /**
     * @throws \InvalidArgumentException When the text is not valid JSON, or holds a number a
     *                                   double cannot represent (1e999).
     */
    public static function canonicalize(string $jsonText): string
    {
        // Objects stay stdClass so that `{}` and `[]` remain different. Big integers must NOT
        // become strings (JSON_BIGINT_AS_STRING would): JavaScript turns them into doubles.
        $value = json_decode($jsonText, false, 512);
        if ($value === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }
        return self::encode($value);
    }

    /** @param mixed $value */
    private static function encode($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value)) {
            if ($value > self::MAX_SAFE || $value < -self::MAX_SAFE) {
                return self::number((float) $value);
            }
            return (string) $value;
        }
        if (is_float($value)) {
            return self::number($value);
        }
        if (is_string($value)) {
            return self::quote($value);
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::encode($item);
            }
            return '[' . implode(',', $parts) . ']';
        }
        if (is_object($value)) {
            $members = [];
            foreach (get_object_vars($value) as $key => $item) {
                $members[] = [(string) $key, $item];
            }
            usort($members, static function (array $a, array $b): int {
                return self::compareKeys($a[0], $b[0]);
            });
            $parts = [];
            foreach ($members as $member) {
                $parts[] = self::quote($member[0]) . ':' . self::encode($member[1]);
            }
            return '{' . implode(',', $parts) . '}';
        }
        throw new \InvalidArgumentException('Unsupported JSON value.');
    }

    /** ECMAScript Number::toString for a finite double. */
    public static function number(float $x): string
    {
        if (is_nan($x) || is_infinite($x)) {
            throw new \InvalidArgumentException('JSON numbers must be finite.');
        }
        if ($x == 0.0) {
            return '0'; // also -0
        }
        $sign = $x < 0 ? '-' : '';
        $abs = abs($x);

        list($digits, $exp10) = self::shortestDigits($abs);
        $k = strlen($digits);
        $n = $exp10 + 1; // value = 0.DIGITS x 10^n

        if ($k <= $n && $n <= 21) {
            $body = $digits . str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $body = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $body = '0.' . str_repeat('0', -$n) . $digits;
        } else {
            $e = $n - 1;
            $mantissa = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
            $body = $mantissa . 'e' . ($e < 0 ? '-' : '+') . abs($e);
        }
        return $sign . $body;
    }

    /**
     * The shortest digit string that reads back as exactly `$abs`, and the decimal exponent of its
     * first digit. When several strings of that length round-trip, the closest to the true value
     * wins (the correctly rounded one, unless it falls outside the rounding interval).
     *
     * @return array{0: string, 1: int}
     */
    private static function shortestDigits(float $abs): array
    {
        for ($precision = 0; $precision <= 16; $precision++) {
            $formatted = sprintf('%.' . $precision . 'e', $abs);
            if (preg_match('/^(\d)(?:\.(\d+))?e([+-]?\d+)$/', $formatted, $m) !== 1) {
                throw new \LogicException('Unexpected number format: ' . $formatted);
            }
            $digits = $m[1] . $m[2];
            $exp10 = (int) $m[3];
            $significant = (int) $digits;

            // The correctly rounded candidate first, then its neighbours (they matter only at
            // power-of-two boundaries, where the rounding interval is asymmetric).
            foreach ([0, -1, 1] as $delta) {
                $candidate = $significant + $delta;
                if ($candidate <= 0) {
                    continue;
                }
                $text = (string) $candidate;
                $scale = $exp10 - $precision; // exponent of the last digit
                if ((float) ($text . 'e' . $scale) === $abs) {
                    $length = strlen($text);
                    $exponent = $scale + $length - 1;
                    return [self::trimZeros($text), $exponent];
                }
            }
        }
        throw new \LogicException('A double always round-trips with 17 significant digits.');
    }

    /** Drops trailing zeros of a digit string (they are implied by the exponent). */
    private static function trimZeros(string $digits): string
    {
        $trimmed = rtrim($digits, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function quote(string $s): string
    {
        $out = '"';
        $length = strlen($s);
        for ($i = 0; $i < $length; $i++) {
            $c = $s[$i];
            $o = ord($c);
            if ($c === '"') {
                $out .= '\\"';
            } elseif ($c === '\\') {
                $out .= '\\\\';
            } elseif ($o < 0x20) {
                switch ($o) {
                    case 0x08:
                        $out .= '\\b';
                        break;
                    case 0x09:
                        $out .= '\\t';
                        break;
                    case 0x0a:
                        $out .= '\\n';
                        break;
                    case 0x0c:
                        $out .= '\\f';
                        break;
                    case 0x0d:
                        $out .= '\\r';
                        break;
                    default:
                        $out .= sprintf('\\u%04x', $o);
                }
            } else {
                $out .= $c; // UTF-8 bytes pass through untouched, U+2028/2029 included
            }
        }
        return $out . '"';
    }

    private static function compareKeys(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $ua = self::utf16Units($a);
        $ub = self::utf16Units($b);
        $common = min(count($ua), count($ub));
        for ($i = 0; $i < $common; $i++) {
            if ($ua[$i] !== $ub[$i]) {
                return $ua[$i] < $ub[$i] ? -1 : 1;
            }
        }
        return count($ua) <=> count($ub);
    }

    /**
     * UTF-8 to UTF-16 code units, by hand. The input is valid UTF-8 (json_decode checked it).
     *
     * @return int[]
     */
    private static function utf16Units(string $s): array
    {
        $units = [];
        $length = strlen($s);
        $i = 0;
        while ($i < $length) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                $cp = $b;
                $i += 1;
            } elseif ($b < 0xE0) {
                $cp = (($b & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($b < 0xF0) {
                $cp = (($b & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $cp = (($b & 0x07) << 18) | ((ord($s[$i + 1]) & 0x3F) << 12)
                    | ((ord($s[$i + 2]) & 0x3F) << 6) | (ord($s[$i + 3]) & 0x3F);
                $i += 4;
            }
            if ($cp >= 0x10000) {
                $cp -= 0x10000;
                $units[] = 0xD800 + ($cp >> 10);
                $units[] = 0xDC00 + ($cp & 0x3FF);
            } else {
                $units[] = $cp;
            }
        }
        return $units;
    }
}
