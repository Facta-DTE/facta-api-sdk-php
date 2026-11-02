<?php

declare(strict_types=1);

namespace Facta\Api\Support;

use Facta\Api\FactaError;
use Facta\Api\NonTaxedLine;

/**
 * Local checks of non-taxed charge/credit lines (`items[].noGravado`, Manual Funcional v2.0 §XII).
 *
 * Nothing is computed here: the server places the line and sums it. What this does is refuse, before a
 * request leaves, the shapes the server would refuse anyway, so a mistyped line never reaches the
 * correlative reservation. Every refusal is a {@see FactaError} with the server's own code, status 0 and
 * `details.local` true. A request without any `noGravado` line is not inspected at all.
 *
 * @internal Called by the issuing methods of {@see \Facta\Api\Facta}.
 */
final class NonTaxedLines
{
    /** Document types whose official schema has `noGravado`. */
    public const DTE_TYPES = ['01', '03', '05', '06', '11'];

    /** Fields a non-taxed line cannot carry: Facta fills them as the Manual says. */
    private const EXCLUSIVE = ['cantidad', 'precioUni', 'montoDescu', 'tipoVenta', 'productId', 'applyIncomeWithholding'];

    /** @param mixed $line */
    public static function isNonTaxed($line): bool
    {
        if ($line instanceof NonTaxedLine) {
            return true;
        }
        // Present is enough, even as null: json_encode() sends the null and the server treats the line as
        // non-taxed (and then refuses the value).
        return is_array($line) && array_key_exists('noGravado', $line);
    }

    /**
     * Not zero, finite, below 1e11 in absolute value, at most 8 decimals (read on the number's own text: no
     * arithmetic on the amount).
     *
     * @param mixed $value
     */
    public static function validAmount($value): bool
    {
        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value == 0) {
            return false;
        }
        if (abs($value) >= 1e11) {
            return false;
        }
        if (is_int($value)) {
            return true;
        }
        $parts = explode('e', strtolower(var_export(abs($value), true)));
        $mantissa = $parts[0];
        $exponent = isset($parts[1]) ? (int) $parts[1] : 0;
        $dot = strpos($mantissa, '.');
        $places = $dot === false ? 0 : strlen(rtrim($mantissa, '0')) - $dot - 1;
        return $places - $exponent <= 8;
    }

    /**
     * @param array<string, mixed> $request A document request or a draft body.
     * @throws FactaError `non_taxed_not_supported`, `invalid_request` (`details.field`) or `non_taxed_requires_sale_line`.
     */
    public static function check(array $request): void
    {
        $items = $request['items'] ?? null;
        if (!is_array($items)) {
            return;
        }
        $hasNonTaxed = false;
        foreach ($items as $item) {
            if (self::isNonTaxed($item)) {
                $hasNonTaxed = true;
                break;
            }
        }
        if (!$hasNonTaxed) {
            return;
        }
        $tipoDte = $request['tipoDte'] ?? null;
        $first = 'items[' . self::firstNonTaxed($items) . '].noGravado';
        if (is_string($tipoDte) && !in_array($tipoDte, self::DTE_TYPES, true)) {
            self::refuse(FactaError::NON_TAXED_NOT_SUPPORTED, 'Type ' . $tipoDte . ' does not accept non-taxed lines (noGravado).', [
                'field' => $first, 'tipoDte' => $tipoDte, 'supported' => self::DTE_TYPES,
            ]);
        }
        $isNote = $tipoDte === '05' || $tipoDte === '06';
        $saleLines = 0;
        foreach (array_values($items) as $index => $item) {
            $label = 'items[' . $index . ']';
            if (!self::isNonTaxed($item)) {
                if (self::mayCarryAmount($item)) {
                    $saleLines++;
                }
                continue;
            }
            if ($item instanceof NonTaxedLine) {
                $item = $item->toArray(); // its constructor checked the shape; numeroDocumento still depends on the type
            }
            if (!is_array($item)) {
                continue;
            }
            // Same checks, same order and same `details.field` as the server's `nonTaxedWireProblem`.
            if (!self::validAmount($item['noGravado'])) {
                self::refuse(FactaError::INVALID_REQUEST, $label . '.noGravado must be a non-zero number below 1e11 in absolute value with at most 8 decimals.', ['field' => $label . '.noGravado']);
            }
            foreach (self::EXCLUSIVE as $field) {
                if (array_key_exists($field, $item)) {
                    self::refuse(FactaError::INVALID_REQUEST, $label . '.' . $field . ' does not go on a non-taxed line: send only descripcion, noGravado, tipoItem and codigo.', ['field' => $label . '.' . $field]);
                }
            }
            if (array_key_exists('uniMedida', $item) && $item['uniMedida'] !== 99) {
                self::refuse(FactaError::INVALID_REQUEST, $label . '.uniMedida of a non-taxed line is always 99 (or leave it out).', ['field' => $label . '.uniMedida']);
            }
            if (!isset($item['descripcion']) || !is_string($item['descripcion']) || trim($item['descripcion']) === '') {
                self::refuse(FactaError::INVALID_REQUEST, $label . '.descripcion is required on a non-taxed line.', ['field' => $label . '.descripcion']);
            }
            $type = $item['tipoItem'] ?? null;
            if ($type !== 1 && $type !== 2 && $type !== 3) {
                self::refuse(FactaError::INVALID_REQUEST, $label . '.tipoItem is required on a non-taxed line: 1 (bien), 2 (servicio) or 3 (ambos).', ['field' => $label . '.tipoItem']);
            }
            if (isset($item['codigo']) && !is_string($item['codigo'])) {
                self::refuse(FactaError::INVALID_REQUEST, $label . '.codigo must be a string.', ['field' => $label . '.codigo']);
            }
            if (array_key_exists('numeroDocumento', $item)) {
                $number = $item['numeroDocumento'];
                if (!$isNote || !is_string($number) || trim($number) === '') {
                    self::refuse(FactaError::INVALID_REQUEST, $label . '.numeroDocumento only goes on a note (05/06), as a string.', ['field' => $label . '.numeroDocumento']);
                }
            }
        }
        if ($saleLines === 0) {
            self::refuse(FactaError::NON_TAXED_REQUIRES_SALE_LINE, 'A non-taxed line needs at least one sale line (gravada, exenta or no sujeta) above zero.', ['field' => $first]);
        }
    }

    /** @param array<mixed> $items */
    private static function firstNonTaxed(array $items): int
    {
        foreach (array_values($items) as $index => $item) {
            if (self::isNonTaxed($item)) {
                return $index;
            }
        }
        return -1;
    }

    /**
     * A sale line that may carry an amount: the server's rule is `cantidad × precioUni − montoDescu > 0`,
     * applied locally only when both numbers are on the line. A `productId` line without a price is resolved
     * by the server, which decides.
     *
     * @param mixed $item
     */
    private static function mayCarryAmount($item): bool
    {
        if ($item instanceof \JsonSerializable) {
            $item = $item->jsonSerialize();
        }
        if (!is_array($item)) {
            return false;
        }
        $cantidad = $item['cantidad'] ?? null;
        $precioUni = $item['precioUni'] ?? null;
        $isNumber = static function ($value): bool {
            return is_int($value) || is_float($value);
        };
        if ($isNumber($cantidad) && $isNumber($precioUni)) {
            $discount = $item['montoDescu'] ?? 0;
            return $cantidad * $precioUni - ($isNumber($discount) ? $discount : 0) > 0;
        }
        foreach ([$cantidad, $precioUni] as $value) {
            if ($isNumber($value) && $value == 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $details
     * @return never
     * @throws FactaError
     */
    private static function refuse(string $code, string $message, array $details): void
    {
        $details['local'] = true;
        throw new FactaError($code, $message, 0, $details);
    }
}
