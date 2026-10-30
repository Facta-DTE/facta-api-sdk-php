<?php

declare(strict_types=1);

namespace Facta\Api\Support;

/**
 * Pure helpers of the temporary-copy confirmation: the request body and the shape checks of the two
 * answers. The HTTP calls are methods of `Facta` (`listUnconfirmedDocuments`,
 * `confirmDocumentStorage`, `storeAndConfirmUnconfirmed`).
 *
 * Facta keeps an encrypted temporary copy of every sealed document. It is deleted 2 days after the
 * document is marked as stored, or 30 days after sealing if nobody marks it.
 *
 * @internal
 */
final class StorageConfirmation
{
    /** Who can mark a document: `facta_*` were checked by Facta, `*_report` are claims. */
    public const CONFIRMED_BY = ['facta_managed', 'facta_byos', 'sdk_report', 'api_report', 'app_report', 'admin'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** SHA-256 of the bytes as lower-case hex. */
    public static function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /**
     * The validated `{donde, sha256}` body of a confirmation.
     *
     * @param array<string, mixed> $input `where` (short name of the place, 1 to 200 characters, never a
     *                                    secret) and exactly one of `sha256` (64 hex characters) or `bytes`.
     * @return array{donde: string, sha256: string}
     * @throws \InvalidArgumentException
     */
    public static function body(array $input): array
    {
        foreach (array_keys($input) as $name) {
            if (!in_array($name, ['where', 'sha256', 'bytes'], true)) {
                throw new \InvalidArgumentException('Unknown confirmation field: ' . $name . '.');
            }
        }
        $where = isset($input['where']) && is_string($input['where']) ? trim($input['where']) : '';
        if ($where === '' || strlen($where) > 200) {
            throw new \InvalidArgumentException('where is a short name (1 to 200 characters) of the place you stored the document; never a secret.');
        }
        $hasHash = array_key_exists('sha256', $input);
        $hasBytes = array_key_exists('bytes', $input);
        if ($hasHash === $hasBytes) {
            throw new \InvalidArgumentException('Give exactly one of sha256 or bytes.');
        }
        if ($hasBytes) {
            if (!is_string($input['bytes'])) {
                throw new \InvalidArgumentException('bytes must be a string.');
            }
            $sha = self::sha256($input['bytes']);
        } else {
            $sha = is_string($input['sha256']) ? strtolower(trim($input['sha256'])) : '';
        }
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            throw new \InvalidArgumentException('sha256 must be 64 hexadecimal characters.');
        }
        return ['donde' => $where, 'sha256' => $sha];
    }

    /** @param mixed $value */
    public static function isUnconfirmedPage($value): bool
    {
        if (
            !is_array($value) || ($value['capabilityVersion'] ?? null) !== 1 || !is_array($value['documentos'] ?? null)
            || !is_int($value['total'] ?? null) || !array_key_exists('siguiente', $value)
            || !($value['siguiente'] === null || is_string($value['siguiente']))
        ) {
            return false;
        }
        foreach ($value['documentos'] as $doc) {
            if (
                !is_array($doc) || !is_string($doc['codigoGeneracion'] ?? null) || preg_match(self::UUID, $doc['codigoGeneracion']) !== 1
                || !is_string($doc['numeroControl'] ?? null) || !is_string($doc['tipoDte'] ?? null)
                || !array_key_exists('fecEmi', $doc) || !($doc['fecEmi'] === null || is_string($doc['fecEmi']))
                || !is_string($doc['selladoEn'] ?? null) || !is_string($doc['borradoEl'] ?? null)
                || !is_int($doc['diasRestantes'] ?? null)
                || !is_array($doc['copias'] ?? null)
                || !in_array($doc['copias']['facta'] ?? null, ['stored', 'pending', 'none'], true)
                || !is_int($doc['copias']['byos'] ?? null)
            ) {
                return false;
            }
        }
        return true;
    }

    /** @param mixed $value */
    public static function isConfirmation($value): bool
    {
        return is_array($value) && is_string($value['codigoGeneracion'] ?? null) && ($value['confirmado'] ?? null) === true
            && in_array($value['por'] ?? null, self::CONFIRMED_BY, true)
            && is_bool($value['yaConfirmado'] ?? null) && is_bool($value['verificado'] ?? null) && is_bool($value['informado'] ?? null)
            && array_key_exists('borradoEl', $value) && ($value['borradoEl'] === null || is_string($value['borradoEl']));
    }
}
