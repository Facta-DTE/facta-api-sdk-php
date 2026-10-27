<?php

declare(strict_types=1);

namespace Facta\Api\Support;

use Facta\Api\FactaError;

/**
 * What the SDK checks before a catalog write is sent, and how it reads what
 * comes back. Mirrors `src/catalog-write.ts` of the TypeScript SDK.
 *
 * The server is the authority: every check here is one the server also makes,
 * so a record refused here is a record the server would refuse. When in doubt a
 * rule is left out, because a false negative would stop a legitimate record
 * over a rule the server does not enforce.
 *
 * The public API speaks the Ministry's field names (`nombre`, `numDocumento`,
 * `precioUni`...). The stored spellings (`name`, `doc_number`, `unit_price`...)
 * are accepted on input and translated; every record the SDK returns carries
 * BOTH spellings.
 *
 * @internal
 */
final class Catalog
{
    /** Where the owner enables catalog administration, for error guidance. */
    private const WHERE = 'Cuenta -> API';

    /** @var array<int, array{string, string}> wire name, stored name */
    private const CUSTOMER_ALIASES = [
        ['nombre', 'name'],
        ['tipoDocumento', 'doc_type'],
        ['numDocumento', 'doc_number'],
        ['nrc', 'nrc'],
        ['codActividad', 'activity_code'],
        ['direccion', 'address'],
        ['telefono', 'phone'],
        ['correo', 'email'],
    ];

    /** @var array<int, array{string, string}> */
    private const PRODUCT_ALIASES = [
        ['codigo', 'code'],
        ['codigoBarras', 'barcode'],
        ['descripcion', 'description'],
        ['tipoItem', 'item_type'],
        ['uniMedida', 'unit_of_measure'],
        ['precioUni', 'unit_price'],
        ['ivaIncluido', 'vat_included'],
    ];

    private const SALE_TYPES = ['gravada', 'exenta', 'no_sujeta'];

    /**
     * The guidance the SDK adds to the two errors a catalog write can hit
     * before anything is stored (English; the codes are the contract).
     */
    public static function writeGuidance(string $code): ?string
    {
        $warning = 'Enabling it stores the company catalog as plain text, readable by Facta DTE.';
        if ($code === FactaError::CATALOG_WRITE_DISABLED) {
            return 'This company does not allow administering customers and products from the API. '
                . 'To enable it, open Facta DTE, go to ' . self::WHERE . ' and tick "Permitir administrar clientes y productos desde el API". '
                . 'The API key also needs the catalog:write scope. ' . $warning;
        }
        if ($code === FactaError::CATALOG_ENCRYPTED) {
            return 'This company\'s catalog is encrypted and the API can only change it in plain text. '
                . 'To change it, open Facta DTE, go to ' . self::WHERE . ', switch the catalog to plain text and tick '
                . '"Permitir administrar clientes y productos desde el API". ' . $warning;
        }
        return null;
    }

    /**
     * @param string[] $issues
     * @throws FactaError
     */
    private static function invalid(array $issues): FactaError
    {
        return new FactaError(
            FactaError::VALIDATION_FAILED,
            'Invalid catalog data: ' . implode(' ', $issues),
            422,
            ['issues' => $issues]
        );
    }

    /**
     * An associative array (a JSON object), or null when the value is not one.
     *
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function record($value): ?array
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value) || ($value !== [] && array_keys($value) === range(0, count($value) - 1))) {
            return null;
        }
        return $value;
    }

    /** Length in characters (code points), the way the limits are written. */
    private static function length(string $text): int
    {
        $count = preg_match_all('/./su', $text);
        return is_int($count) ? $count : strlen($text);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, string>  $issues
     */
    private static function nullableText(array $input, string $key, array &$issues, int $max = 250): void
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return;
        }
        if (!is_string($value) || self::length($value) > $max) {
            $issues[] = $key . ' must be text of up to ' . $max . ' characters.';
        }
    }

    /**
     * Translates stored names to wire names. Both spellings given for the same
     * field must agree, or the caller is told.
     *
     * @param array<string, mixed>                $input
     * @param array<int, array{string, string}>   $aliases
     * @param array<int, string>                  $issues
     * @return array<string, mixed>
     */
    private static function toWire(array $input, array $aliases, array &$issues): array
    {
        $out = $input;
        foreach ($aliases as [$wire, $stored]) {
            if ($wire === $stored || !array_key_exists($stored, $input)) {
                continue;
            }
            unset($out[$stored]);
            if (array_key_exists($wire, $input)) {
                if (json_encode($input[$wire]) !== json_encode($input[$stored])) {
                    $issues[] = $wire . ' and ' . $stored . ' are the same field with different values; use only ' . $wire . '.';
                }
            } else {
                $out[$wire] = $input[$stored];
            }
        }
        return $out;
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    /**
     * The customer fields the server expects: wire names, document and NRC as digits only.
     *
     * @param mixed $input
     * @return array<string, mixed>
     * @throws FactaError
     */
    public static function prepareCustomer($input, string $mode): array
    {
        $record = self::record($input);
        if ($record === null) {
            throw self::invalid(['an object with the customer data was expected.']);
        }
        $issues = [];
        $out = self::toWire($record, self::CUSTOMER_ALIASES, $issues);

        if (!array_key_exists('nombre', $out)) {
            if ($mode === 'create') {
                $issues[] = 'nombre is required.';
            }
        } elseif (!is_string($out['nombre']) || trim($out['nombre']) === '' || self::length($out['nombre']) > 250) {
            $issues[] = 'nombre must be non-empty text of up to 250 characters.';
        }

        $docType = $out['tipoDocumento'] ?? null;
        $docNumber = $out['numDocumento'] ?? null;
        if ($docType !== null && !is_string($docType)) {
            $issues[] = 'tipoDocumento must be text (for example "13" or "36").';
        }
        if ($docNumber !== null) {
            if (!is_string($docNumber)) {
                $issues[] = 'numDocumento must be text.';
            } elseif ($docType === '13' || $docType === '36') {
                $clean = self::digits($docNumber);
                $want = $docType === '13' ? 9 : 14;
                if (strlen($clean) !== $want) {
                    $issues[] = 'numDocumento of a ' . ($docType === '13' ? 'DUI' : 'NIT') . ' has ' . $want . ' digits.';
                } else {
                    $out['numDocumento'] = $clean;
                }
            }
        }

        $nrc = $out['nrc'] ?? null;
        if ($nrc !== null) {
            if (!is_string($nrc)) {
                $issues[] = 'nrc must be text.';
            } else {
                $clean = self::digits($nrc);
                if (strlen($clean) < 1 || strlen($clean) > 8) {
                    $issues[] = 'nrc has 1 to 8 digits.';
                } else {
                    $out['nrc'] = $clean;
                }
            }
        }

        if (array_key_exists('direccion', $out) && $out['direccion'] !== null) {
            $address = self::record($out['direccion']);
            if ($address === null) {
                $issues[] = 'direccion must be an object {departamento, municipio, distrito, complemento}.';
            } else {
                foreach (['departamento', 'municipio'] as $part) {
                    if (!is_string($address[$part] ?? null) || preg_match('/^\d{2}$/', $address[$part]) !== 1) {
                        $issues[] = 'direccion.' . $part . ' is a two-digit code.';
                    }
                }
                foreach (['distrito', 'complemento'] as $part) {
                    if (!is_string($address[$part] ?? null) || trim($address[$part]) === '') {
                        $issues[] = 'direccion.' . $part . ' is required.';
                    }
                }
            }
        }

        $correo = $out['correo'] ?? null;
        if ($correo !== null && (!is_string($correo) || strpos($correo, '@') === false || self::length($correo) > 250)) {
            $issues[] = 'correo does not look like an e-mail address.';
        }
        self::nullableText($out, 'codActividad', $issues, 10);
        self::nullableText($out, 'telefono', $issues, 30);
        if ($mode === 'update' && $out === []) {
            $issues[] = 'pass at least one field to change.';
        }
        if ($issues !== []) {
            throw self::invalid($issues);
        }
        return $out;
    }

    /**
     * The product fields to send. `tipoItem` is never defaulted: it decides the tax treatment.
     *
     * @param mixed $input
     * @return array<string, mixed>
     * @throws FactaError
     */
    public static function prepareProduct($input, string $mode): array
    {
        $record = self::record($input);
        if ($record === null) {
            throw self::invalid(['an object with the product data was expected.']);
        }
        $issues = [];
        $out = self::toWire($record, self::PRODUCT_ALIASES, $issues);

        if (!array_key_exists('descripcion', $out)) {
            if ($mode === 'create') {
                $issues[] = 'descripcion is required.';
            }
        } elseif (!is_string($out['descripcion']) || trim($out['descripcion']) === '' || self::length($out['descripcion']) > 1500) {
            $issues[] = 'descripcion must be non-empty text of up to 1500 characters.';
        }

        if (!array_key_exists('tipoItem', $out)) {
            if ($mode === 'create') {
                $issues[] = 'tipoItem is required: 1 good, 2 service or 3 both. None is assumed.';
            }
        } elseif (!in_array($out['tipoItem'], [1, 2, 3], true)) {
            $issues[] = 'tipoItem must be 1 (good), 2 (service) or 3 (both).';
        }

        if (!array_key_exists('precioUni', $out)) {
            if ($mode === 'create') {
                $issues[] = 'precioUni is required.';
            }
        } else {
            $price = $out['precioUni'];
            if (!(is_int($price) || is_float($price)) || !is_finite((float) $price) || $price <= 0) {
                $issues[] = 'precioUni must be a number greater than zero.';
            }
        }

        if (array_key_exists('uniMedida', $out) && (!is_int($out['uniMedida']) || $out['uniMedida'] < 1)) {
            $issues[] = 'uniMedida must be a positive integer.';
        }
        if (array_key_exists('ivaIncluido', $out) && !is_bool($out['ivaIncluido'])) {
            $issues[] = 'ivaIncluido must be true or false.';
        }
        if (array_key_exists('tipoVenta', $out) && !in_array($out['tipoVenta'], self::SALE_TYPES, true)) {
            $issues[] = 'tipoVenta must be "gravada", "exenta" or "no_sujeta".';
        }
        self::nullableText($out, 'codigo', $issues, 25);
        self::nullableText($out, 'codigoBarras', $issues, 100);
        if ($mode === 'update' && $out === []) {
            $issues[] = 'pass at least one field to change.';
        }
        if ($issues !== []) {
            throw self::invalid($issues);
        }
        return $out;
    }

    /**
     * @param array<string, mixed>              $row
     * @param array<int, array{string, string}>  $aliases
     * @return array<string, mixed>
     */
    private static function withAliases(array $row, array $aliases): array
    {
        $out = $row;
        foreach ($aliases as [$wire, $stored]) {
            if (array_key_exists($wire, $out) && !array_key_exists($stored, $out)) {
                $out[$stored] = $out[$wire];
            } elseif (array_key_exists($stored, $out) && !array_key_exists($wire, $out)) {
                $out[$wire] = $out[$stored];
            }
            // The same fields exist in every mode: a field the source did not carry is null, never absent.
            if (!array_key_exists($wire, $out)) {
                $out[$wire] = null;
                $out[$stored] = null;
            }
        }
        // `activo` (API) and `active` (stored) are the same flag; a record that does not say is active.
        $active = array_key_exists('activo', $out) ? $out['activo'] : (array_key_exists('active', $out) ? $out['active'] : true);
        $out['activo'] = $active !== false;
        $out['active'] = $active !== false;
        return $out;
    }

    /**
     * A customer with both spellings and the same fields in every catalog mode.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeCustomer(array $row): array
    {
        $out = self::withAliases($row, self::CUSTOMER_ALIASES);
        foreach (['creadoEn', 'actualizadoEn'] as $key) {
            if (!array_key_exists($key, $out)) {
                $out[$key] = null;
            }
        }
        return $out;
    }

    /**
     * A product with both spellings and the same fields in every catalog mode.
     * `tipoVenta` (API spelling) and `sale_class` (stored spelling) are the VAT
     * treatment; a product that never chose one reads as taxed.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeProduct(array $row): array
    {
        $out = self::withAliases($row, self::PRODUCT_ALIASES);
        foreach (['tipoItem', 'uniMedida', 'precioUni'] as $key) {
            $value = $out[$key] ?? null;
            if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
                $out[$key] = $value + 0;
            }
        }
        $out['item_type'] = $out['tipoItem'];
        $out['unit_of_measure'] = $out['uniMedida'];
        $out['unit_price'] = $out['precioUni'];
        $stored = $out['sale_class'] ?? null;
        $wire = $out['tipoVenta'] ?? null;
        if (in_array($wire, self::SALE_TYPES, true)) {
            $type = $wire;
        } elseif ($stored === 'exenta') {
            $type = 'exenta';
        } elseif ($stored === 'noSuj') {
            $type = 'no_sujeta';
        } else {
            $type = 'gravada';
        }
        $out['tipoVenta'] = $type;
        $out['sale_class'] = $type === 'no_sujeta' ? 'noSuj' : $type;
        if (!array_key_exists('actualizadoEn', $out)) {
            $out['actualizadoEn'] = null;
        }
        return $out;
    }

    /**
     * @param mixed $id
     * @throws \InvalidArgumentException
     */
    public static function requireId($id, string $label): string
    {
        if (!is_string($id) || trim($id) === '' || strlen($id) > 100) {
            throw new \InvalidArgumentException($label . ' id is required.');
        }
        return $id;
    }

    /**
     * Rows of a list answer: a bare list, or an object holding the list under a known key.
     *
     * @param mixed    $payload
     * @param string[] $keys
     * @return array<int, array<string, mixed>>
     * @throws FactaError
     */
    public static function rowsOf($payload, array $keys): array
    {
        $list = null;
        if (is_array($payload)) {
            if ($payload === [] || array_keys($payload) === range(0, count($payload) - 1)) {
                $list = $payload;
            } else {
                foreach (array_merge($keys, ['data', 'items']) as $key) {
                    if (isset($payload[$key]) && is_array($payload[$key])) {
                        $list = $payload[$key];
                        break;
                    }
                }
            }
        }
        if ($list === null) {
            throw new FactaError(FactaError::INTERNAL_ERROR, 'The catalog answer carries no list of records.', 502);
        }
        $rows = [];
        foreach ($list as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * The cursor of the next page, if any.
     *
     * @param mixed $payload
     */
    public static function nextCursor($payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }
        foreach (['siguiente', 'nextCursor', 'next_cursor', 'next'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }
        return null;
    }

    /**
     * One record of a get/create/update answer, or null when the answer carries none.
     *
     * @param mixed    $payload
     * @param string[] $keys
     * @return array<string, mixed>|null
     */
    public static function recordOf($payload, array $keys): ?array
    {
        if (!is_array($payload)) {
            return null;
        }
        foreach (array_merge($keys, ['data', 'item']) as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value) && isset($value['id']) && is_string($value['id'])) {
                return $value;
            }
        }
        return isset($payload['id']) && is_string($payload['id']) ? $payload : null;
    }
}
