<?php

declare(strict_types=1);

namespace Facta\Api\Live;

/**
 * The optional matrix of DTE fixtures. Mirrors `scripts/live-dte-fixtures.mjs` of the
 * TypeScript SDK: fixed report-safe states, and no fixture payload ever reaches a log.
 */
final class Fixtures
{
    public const TYPES = ['03', '05', '06', '11', '14'];
    public const SUPPORTED_TYPES = ['01', '03', '05', '06', '11', '14'];
    private const DESCRIPTION = 'Facta API SDK integration test';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Parses the optional Actions secret.
     *
     * @return array<int|string, array<string, mixed>> type => request (numeric types such as "11" become integer keys)
     * @throws LiveError
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $value = json_decode($raw, true);
        if (!is_array($value) || ($value !== [] && array_keys($value) === range(0, count($value) - 1))) {
            throw new LiveError('fixture_invalid', 'Live DTE fixture preflight did not pass.');
        }
        foreach (array_keys($value) as $type) {
            if (!in_array((string) $type, self::TYPES, true)) {
                throw new LiveError('fixture_invalid', 'Live DTE fixture preflight did not pass.');
            }
        }
        $fixtures = [];
        foreach (self::TYPES as $type) {
            if (!array_key_exists($type, $value)) {
                continue;
            }
            self::validate($type, $value[$type]);
            $fixtures[$type] = $value[$type];
        }
        return $fixtures;
    }

    /**
     * Fixed, report-safe states; never fixture payloads or missing scope names.
     *
     * @param array<string, mixed>                $status   The `/v1/status` answer.
     * @param array<int|string, array<string, mixed>> $fixtures
     * @return array<int|string, string> type => state
     */
    public static function assess(array $status, array $fixtures): array
    {
        $key = isset($status['llave']) && is_array($status['llave']) ? $status['llave'] : [];
        $scopes = isset($key['alcances']) && is_array($key['alcances']) ? $key['alcances'] : [];
        $allowed = isset($key['tiposDte']) && is_array($key['tiposDte']) ? $key['tiposDte'] : [];
        $matrix = [];
        foreach (self::SUPPORTED_TYPES as $type) {
            if (!in_array('issue', $scopes, true) || !in_array($type, $allowed, true)) {
                $matrix[$type] = 'Blocked (permission unavailable)';
            } elseif ($type === '01') {
                $matrix[$type] = 'Ready (baseline test only)';
            } else {
                $matrix[$type] = isset($fixtures[$type]) ? 'Ready (fixture configured)' : 'Not run (fixture missing)';
            }
        }
        return $matrix;
    }

    /**
     * Requires complete permission and fixture coverage before any matrix DTE is issued.
     *
     * @param array<int|string, string> $matrix
     * @throws LiveError
     */
    public static function requireComplete(array $matrix): void
    {
        foreach (self::TYPES as $type) {
            if (($matrix[$type] ?? null) !== 'Ready (fixture configured)') {
                $blocked = ($matrix[$type] ?? null) === 'Blocked (permission unavailable)';
                throw new LiveError(
                    $blocked ? 'permission_missing' : 'fixture_required',
                    'Live DTE fixture preflight did not pass.'
                );
            }
        }
    }

    /**
     * Test-environment related document codes, for a read-only status preflight.
     *
     * @param array<int|string, array<string, mixed>> $fixtures
     * @return string[]
     */
    public static function relatedGenerationCodes(array $fixtures): array
    {
        $codes = [];
        foreach (['05', '06'] as $type) {
            $related = $fixtures[$type]['documentosRelacionados'] ?? [];
            if (!is_array($related)) {
                continue;
            }
            foreach ($related as $document) {
                if (is_array($document) && isset($document['codigoGeneracion']) && is_string($document['codigoGeneracion'])) {
                    $codes[$document['codigoGeneracion']] = true;
                }
            }
        }
        return array_map('strval', array_keys($codes));
    }

    /**
     * @param array<int|string, array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>>        $statuses The `getDocumentStatus` answers of {@see Fixtures::relatedGenerationCodes()}.
     * @throws LiveError
     */
    public static function assertRelatedTestDocuments(array $fixtures, array $statuses): void
    {
        $related = self::relatedGenerationCodes($fixtures);
        if (count($related) !== count($statuses)) {
            throw new LiveError('related_document_invalid', 'Live DTE fixture preflight did not pass.');
        }
        $verified = [];
        foreach ($statuses as $status) {
            if (($status['ambiente'] ?? null) !== '00' || ($status['estado'] ?? null) !== 'sellado' || !is_string($status['codigoGeneracion'] ?? null)) {
                throw new LiveError('related_document_invalid', 'Live DTE fixture preflight did not pass.');
            }
            $verified[strtoupper($status['codigoGeneracion'])] = true;
        }
        foreach ($related as $code) {
            if (!isset($verified[strtoupper($code)])) {
                throw new LiveError('related_document_invalid', 'Live DTE fixture preflight did not pass.');
            }
        }
    }

    /**
     * @param mixed $request
     * @throws LiveError
     */
    private static function validate(string $type, $request): void
    {
        $fail = static function (): LiveError {
            return new LiveError('fixture_invalid', 'Live DTE fixture preflight did not pass.');
        };
        if (!is_array($request) || ($request['tipoDte'] ?? null) !== $type || !isset($request['items']) || !is_array($request['items']) || count($request['items']) !== 1) {
            throw $fail();
        }
        $item = reset($request['items']);
        if (
            !is_array($item) || ($item['descripcion'] ?? null) !== self::DESCRIPTION
            || !self::isNumber($item['cantidad'] ?? null, 1) || !self::isNumber($item['precioUni'] ?? null, 0.01)
            || array_key_exists('productId', $item)
        ) {
            throw $fail();
        }
        if ($type === '05' || $type === '06') {
            $related = $request['documentosRelacionados'] ?? null;
            if (
                !is_array($related) || count($related) !== 1 || !is_array(reset($related))
                || !is_string(reset($related)['codigoGeneracion'] ?? null)
                || preg_match(self::UUID, reset($related)['codigoGeneracion']) !== 1
            ) {
                throw $fail();
            }
        }
        $receptor = $request['receptor'] ?? null;
        if ($type === '03' && !self::businessRecipient($receptor)) {
            throw $fail();
        }
        if ($type === '11') {
            $export = $request['exportacion'] ?? null;
            if (
                !is_array($receptor) || !is_array($export)
                || !self::allText($receptor, ['nombre', 'numDocumento', 'codPais', 'nombrePais', 'complemento', 'descActividad', 'correo'])
                || !isset($export['tipoItemExpor'])
            ) {
                throw $fail();
            }
        }
        if ($type === '14') {
            if (
                !is_array($receptor) || !self::allText($receptor, ['nombre', 'numDocumento'])
                || !is_array($receptor['direccion'] ?? null)
                || !self::allText($receptor['direccion'], ['departamento', 'municipio', 'complemento'])
            ) {
                throw $fail();
            }
        }
    }

    /** @param mixed $value */
    private static function isNumber($value, float $expected): bool
    {
        return (is_int($value) || is_float($value)) && (float) $value === $expected;
    }

    /**
     * @param array<string, mixed> $record
     * @param string[]             $keys
     */
    private static function allText(array $record, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!isset($record[$key]) || !is_string($record[$key]) || trim($record[$key]) === '') {
                return false;
            }
        }
        return true;
    }

    /** @param mixed $value */
    private static function businessRecipient($value): bool
    {
        return is_array($value)
            && self::allText($value, ['nombre', 'tipoDocumento', 'numDocumento', 'nrc', 'codActividad', 'descActividad'])
            && isset($value['direccion']) && is_array($value['direccion'])
            && self::allText($value['direccion'], ['departamento', 'municipio', 'complemento']);
    }
}
