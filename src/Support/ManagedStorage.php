<?php

declare(strict_types=1);

namespace Facta\Api\Support;

/**
 * Contract checks for the managed-storage answers. A server answer that does
 * not satisfy them is never handed to the caller as if it were good. Mirrors
 * `isManagedStorageReceipt`, `isManagedStorageStatus` and
 * `isManagedDocumentCopies` of the TypeScript SDK.
 *
 * @internal
 */
final class ManagedStorage
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const DIGEST = '/^[0-9a-f]{64}$/i';
    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';

    /** @param mixed $value */
    public static function validBytes($value): bool
    {
        return is_int($value) && $value >= 0;
    }

    /** @param mixed $value */
    public static function validTimestamp($value): bool
    {
        return is_string($value) && preg_match(self::TIMESTAMP, $value) === 1 && strtotime($value) !== false;
    }

    /** @param mixed $value */
    public static function isReceipt($value): bool
    {
        if (
            !is_array($value) || !in_array($value['destination'] ?? null, ['managed', 'none'], true)
            || !in_array($value['environment'] ?? null, ['00', '01'], true)
            || !is_string($value['operationId'] ?? null) || preg_match(self::UUID, $value['operationId']) !== 1
        ) {
            return false;
        }
        $none = $value['destination'] === 'none';
        foreach (['json', 'pdf'] as $kind) {
            if (!self::validReceiptArtifact($value[$kind] ?? null, $none)) {
                return false;
            }
        }
        return true;
    }

    /** @param mixed $artifact */
    private static function validReceiptArtifact($artifact, bool $none): bool
    {
        if (
            !is_array($artifact)
            || !in_array($artifact['state'] ?? null, ['stored', 'pending', 'failed', 'not_configured', 'unsupported'], true)
            || !array_key_exists('sha256', $artifact) || !array_key_exists('bytes', $artifact)
            || !array_key_exists('storedAt', $artifact) || !array_key_exists('errorCode', $artifact)
            || !is_bool($artifact['retryable'] ?? null)
        ) {
            return false;
        }
        $sha = $artifact['sha256'];
        if (!($sha === null || (is_string($sha) && preg_match(self::DIGEST, $sha) === 1))) {
            return false;
        }
        if (!($artifact['bytes'] === null || self::validBytes($artifact['bytes']))) {
            return false;
        }
        if (!($artifact['storedAt'] === null || self::validTimestamp($artifact['storedAt']))) {
            return false;
        }
        $error = $artifact['errorCode'];
        if (!($error === null || (is_string($error) && preg_match('/^[a-z][a-z0-9_]*$/', $error) === 1))) {
            return false;
        }
        if ($none && $artifact['state'] === 'stored') {
            return false;
        }
        return $artifact['state'] !== 'stored'
            || (is_string($sha) && self::validBytes($artifact['bytes']) && self::validTimestamp($artifact['storedAt'])
                && $error === null && $artifact['retryable'] === false);
    }

    /** @param mixed $value */
    public static function isStatus($value): bool
    {
        if (
            !is_array($value) || ($value['capabilityVersion'] ?? null) !== 1
            || !is_array($value['managed'] ?? null) || !is_array($value['byos'] ?? null)
        ) {
            return false;
        }
        $managed = $value['managed'];
        if (!is_bool($managed['configured'] ?? null) || !is_bool($managed['ready'] ?? null) || !is_string($managed['state'] ?? null)) {
            return false;
        }
        if (!in_array($managed['integration'] ?? null, ['ready', 'unavailable'], true)) {
            return false;
        }
        if ($managed['ready'] === true) {
            $quota = $managed['quotaBytes'] ?? null;
            $used = $managed['usedBytesTotal'] ?? null;
            $reserved = $managed['reservedBytesTotal'] ?? null;
            if (
                !$managed['configured'] || $managed['integration'] !== 'ready' || $managed['state'] !== 'ready'
                || ($managed['bucketState'] ?? null) !== 'ready'
                || !self::validBytes($quota) || !self::validBytes($used) || !self::validBytes($reserved)
                || $used + $reserved >= $quota
            ) {
                return false;
            }
            $covered = $managed['coveredUntil'] ?? null;
            if (!($covered === null || (self::validTimestamp($covered) && strtotime((string) $covered) > time()))) {
                return false;
            }
        }
        foreach (['quotaBytes', 'usedBytes', 'reservedBytes', 'usedBytesTotal', 'reservedBytesTotal'] as $name) {
            if (!array_key_exists($name, $managed) || !($managed[$name] === null || self::validBytes($managed[$name]))) {
                return false;
            }
        }
        foreach (['coveredUntil', 'accessUntil'] as $name) {
            if (!array_key_exists($name, $managed) || !($managed[$name] === null || self::validTimestamp($managed[$name]))) {
                return false;
            }
        }
        foreach (['bucketState', 'backupState'] as $name) {
            if (!array_key_exists($name, $managed) || !($managed[$name] === null || is_string($managed[$name]))) {
                return false;
            }
        }
        if (!is_bool($value['byos']['ready'] ?? null)) {
            return false;
        }
        // The kind lists are capabilities, so they grow: since FactaDTE 9d7446e0 the
        // server announces `invalidation` as a supported kind and no longer lists it
        // as unsupported. A status must name `json` and `pdf` as supported, with no
        // duplicates; any further kind is accepted (an exact two-item list made every
        // getStorageStatus() on that server fail with storage_contract_invalid).
        $supported = $value['supportedKinds'] ?? null;
        if (!self::isKindList($supported) || array_diff(['json', 'pdf'], $supported) !== []) {
            return false;
        }
        $unsupported = $value['unsupportedKinds'] ?? null;
        return self::isKindList($unsupported) && array_intersect($supported, $unsupported) === [];
    }

    /** @param mixed $value */
    private static function isKindList($value): bool
    {
        if (!is_array($value) || array_values($value) !== $value) {
            return false;
        }
        foreach ($value as $kind) {
            if (!is_string($kind) || preg_match('/^[a-z][a-z0-9_]*$/', $kind) !== 1) {
                return false;
            }
        }
        return count(array_unique($value)) === count($value);
    }

    /** @param mixed $value */
    public static function isCopies($value): bool
    {
        if (!is_array($value) || ($value['capabilityVersion'] ?? null) !== 1 || !is_array($value['copies'] ?? null)) {
            return false;
        }
        $seen = [];
        foreach ($value['copies'] as $copy) {
            if (!is_array($copy) || !is_string($copy['generationCode'] ?? null) || !is_string($copy['kind'] ?? null)) {
                return false;
            }
            $id = strtoupper($copy['generationCode']) . ':' . $copy['kind'];
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $date = $copy['issuedDate'] ?? null;
            $parsed = is_string($date) ? \DateTime::createFromFormat('!Y-m-d', $date) : false;
            if (
                preg_match(self::UUID, $copy['generationCode']) !== 1 || !in_array($copy['kind'], ['json', 'pdf', 'invalidation'], true)
                || !in_array($copy['environment'] ?? null, ['00', '01'], true)
                || !in_array($copy['state'] ?? null, ['stored', 'pending', 'failed'], true)
                || !self::validBytes($copy['bytes'] ?? null)
                || !is_string($copy['sha256'] ?? null) || preg_match(self::DIGEST, $copy['sha256']) !== 1
                || $parsed === false || $parsed->format('Y-m-d') !== $date
                || !array_key_exists('storedAt', $copy)
                || !($copy['storedAt'] === null || self::validTimestamp($copy['storedAt']))
                || ($copy['state'] === 'stored' && !self::validTimestamp($copy['storedAt']))
            ) {
                return false;
            }
        }
        return true;
    }
}
