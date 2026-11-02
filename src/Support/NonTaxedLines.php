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
        return is_array($line) && array_key_exists('noGravado', $line) && $line['noGravado'] !== null;
    }

    /**
     * Not zero, finite, at most 8 decimals (read on the number's own text: no arithmetic on the amount).
     *
     * @param mixed $value
     */
    public static function validAmount($value): bool
    {
        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value == 0) {
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
                continue; // its constructor already checked the shape
            }
            if (!is_array($item)) {
                continue;
            }
            foreach (self::EXCLUSIVE as $field) {
                if (array_key_exists($field, $item) && $item[$field] !== null) {
                    self::refuse(FactaError::INVALID_REQUEST, $label . '.' . $field . ' does not go on a non-taxed line: send only descripcion, noGravado, tipoItem and codigo.', ['field' => $label . '.' . $field]);
                }
            }
            if (!self::validAmount($item['noGravado'])) {
                self::refuse(FactaError::INVALID_REQUEST, $label . '.noGravado must be a non-zero number with at most 8 decimals.', ['field' => $label . '.noGravado']);
            }
            $type = $item['tipoItem'] ?? null;
            if ($type !== 1 && $type !== 2 && $type !== 3) {
                self::refuse(FactaError::INVALID_REQUEST, $label . '.tipoItem is required on a non-taxed line: 1 (bien), 2 (servicio) or 3 (ambos).', ['field' => $label . '.tipoItem']);
            }
            if (!isset($item['descripcion']) || !is_string($item['descripcion']) || trim($item['descripcion']) === '') {
                self::refuse(FactaError::INVALID_REQUEST, $label . '.descripcion is required on a non-taxed line.', ['field' => $label . '.descripcion']);
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
     * A sale line that may carry an amount: only an explicit zero `cantidad` or `precioUni` is known locally
     * not to. A `productId` line without a price is resolved by the server, which decides.
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
        foreach (['cantidad', 'precioUni'] as $field) {
            if (isset($item[$field]) && (is_int($item[$field]) || is_float($item[$field])) && $item[$field] == 0) {
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
