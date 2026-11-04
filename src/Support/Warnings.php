<?php

declare(strict_types=1);

namespace Facta\Api\Support;

/**
 * The field warnings of the API. `campo_desconocido`: a request field Facta DTE does not know was ignored
 * and is NOT part of the document (a `descuento` instead of `montoDescu` is a discount the document does
 * not carry). `campo_ignorado`: a field the document type has no place for (settlement fields such as
 * `pagos` or `formaPago` on a remission note 04 or retention receipt 07) was ignored and the document was
 * issued without it. Both are warnings, never errors.
 */
final class Warnings
{
    private function __construct()
    {
    }

    /**
     * @param mixed $source A result array, an error `details` (array or \stdClass), or an `advertencias` list.
     * @return array<int, array{codigo: string, campo: string, mensaje: string, sugerencia?: string}>
     */
    public static function unknownFields($source): array
    {
        if ($source instanceof \stdClass) {
            $source = get_object_vars($source);
        }
        if (!is_array($source)) {
            return [];
        }
        $list = array_key_exists('advertencias', $source) ? $source['advertencias'] : $source;
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if ($item instanceof \stdClass) {
                $item = get_object_vars($item);
            }
            if (!is_array($item) || ($item['codigo'] ?? $item['code'] ?? null) !== 'campo_desconocido' || !is_string($item['campo'] ?? null)) {
                continue;
            }
            $row = [
                'codigo' => 'campo_desconocido',
                'campo' => $item['campo'],
                'mensaje' => is_string($item['mensaje'] ?? null) ? $item['mensaje'] : '',
            ];
            if (is_string($item['sugerencia'] ?? null)) {
                $row['sugerencia'] = $item['sugerencia'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The `campo_ignorado` warnings of a sealed response, a prepare response or a draft: settlement fields
     * (`condicionOperacion`, `formaPago`, `pagos`, `plazo`, `periodo`, and `descuentoGlobal` on a 07) sent on a
     * remission note (04) or a retention receipt (07), which have none, and the goods fields of a 07 line.
     *
     * @param mixed $source A result array, an error `details` (array or \stdClass), or an `advertencias` list.
     * @return array<int, array{codigo: string, campo: string, mensaje: string}>
     */
    public static function ignoredFields($source): array
    {
        $out = [];
        foreach (self::rows($source, 'campo_ignorado') as $item) {
            $out[] = [
                'codigo' => 'campo_ignorado',
                'campo' => $item['campo'],
                'mensaje' => is_string($item['mensaje'] ?? null) ? $item['mensaje'] : '',
            ];
        }
        return $out;
    }

    /**
     * @param mixed $source
     * @return array<int, array<string, mixed>>
     */
    private static function rows($source, string $code): array
    {
        if ($source instanceof \stdClass) {
            $source = get_object_vars($source);
        }
        if (!is_array($source)) {
            return [];
        }
        $list = array_key_exists('advertencias', $source) ? $source['advertencias'] : $source;
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if ($item instanceof \stdClass) {
                $item = get_object_vars($item);
            }
            if (is_array($item) && ($item['codigo'] ?? $item['code'] ?? null) === $code && is_string($item['campo'] ?? null)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Parses a `Facta-Warning` header value such as `campo_desconocido; campos=2`.
     *
     * @return array{code: string, count: int|null}|null Null for an empty or missing value.
     */
    public static function parseHeader(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $parts = array_map('trim', explode(';', $value));
        $head = array_shift($parts);
        if ($head === '') {
            return null;
        }
        $count = null;
        foreach ($parts as $part) {
            if (preg_match('/^campos\s*=\s*(\d+)$/i', $part, $m) === 1) {
                $count = (int) $m[1];
            }
        }
        return ['code' => $head, 'count' => $count];
    }

    /**
     * Adds `unknownFields`, `ignoredFields` and `factaWarning` to a result; returned untouched when there is nothing to report.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function attach(array $result, ?string $header): array
    {
        $unknown = self::unknownFields($result);
        $ignored = self::ignoredFields($result);
        $parsed = self::parseHeader($header);
        if ($unknown !== []) {
            $result['unknownFields'] = $unknown;
        }
        if ($ignored !== []) {
            $result['ignoredFields'] = $ignored;
        }
        if ($parsed !== null) {
            $result['factaWarning'] = $parsed;
        }
        return $result;
    }
}
