<?php

declare(strict_types=1);

namespace Facta\Api\Live;

/**
 * The markdown report the pull-request comment shows. Mirrors `scripts/live-report.mjs`
 * of the TypeScript SDK: only allowlisted labels and states are ever published, never
 * invoice data, document identifiers or API messages.
 */
final class Report
{
    public const NOT_IN_VERSION = 'Not in this SDK version';

    /** Check id => label, in the order of the table. The first block is the TypeScript table; the rest is PHP-specific. */
    public const LABELS = [
        'status' => 'Staging API and test environment',
        'preflight' => 'Issuance permissions and readiness',
        'customer-catalog' => 'Authorized customer catalog reads',
        'product-catalog' => 'Authorized product catalog reads',
        'catalog-write-gate' => 'Catalog writes refused while the company catalog is encrypted',
        'destinations' => 'Encrypted destination snapshot opened locally',
        'dte-01' => 'DTE 01 final consumer invoice',
        'dte-03' => 'DTE 03 credit fiscal invoice',
        'dte-05' => 'DTE 05 credit note',
        'dte-06' => 'DTE 06 debit note',
        'dte-11' => 'DTE 11 export invoice',
        'dte-14' => 'DTE 14 excluded-subject invoice',
        'emission' => 'Invoice generated and sealed',
        'archive' => 'Encrypted local archive',
        'ticket' => 'Receipt ticket',
        'signed-json' => 'Signed JSON received and validated',
        'pdf' => 'PDF received and validated',
        'inline-bytes' => 'Exact server bytes preserved',
        'no-downloads' => 'No repeated file downloads',
        'emergency-idle' => 'Emergency safeguard idle on a normal issue',
        'managed-receipt' => 'Durable managed JSON/PDF receipts',
        'managed-readback' => 'Exact managed copy read-back',
        'managed-replay-repair' => 'Idempotent replay and copy repair accounting',
        'query' => 'Invoice consultation',
        'listing' => 'Invoice listing',
        'journal' => 'Completed operation journal',
        'restart' => 'Encrypted journal restart and recovery',
        'email-delivery' => 'E-mail delivery (a mail quota or provider outage only warns)',
        'replay' => 'Idempotent replay returns the same document',
        'download-json' => 'Archivo DTE download and SHA-256',
        'download-pdf' => 'PDF download and SHA-256',
    ];

    /** Checks this SDK version cannot run; the report says so instead of leaving them silent. */
    public const NOT_AVAILABLE = [
        'customer-catalog', 'product-catalog', 'destinations', 'archive',
        'no-downloads', 'emergency-idle', 'journal', 'restart',
    ];

    public const STATES = [
        'Not checked', 'Failed', 'Passed', 'Passed (sealed test FE, $0.01)',
        self::NOT_IN_VERSION,
        'Not applicable (managed only)', 'Not applicable (empty authorized catalog)',
        'Not run (fixture missing)', 'Blocked (permission unavailable)',
        'Ready (baseline test only)', 'Ready (fixture configured)',
        'Passed (test fixture sealed)',
        'Passed (e-mail sent)', 'Warning (mail quota reached)', 'Warning (mail provider unavailable)',
        'Not run (no test inbox configured)',
        'Passed (writes refused for the encrypted CI company)',
        'Warning (key lacks catalog:write)',
        'Warning (catalog write routes not deployed on staging yet)',
    ];

    public const ERROR_CODES = [
        // Stable public API codes from the server error taxonomy.
        'unauthorized', 'invalid_api_key', 'key_revoked', 'key_expired', 'key_inactive',
        'forbidden_scope', 'dte_type_not_allowed', 'ip_not_allowed', 'environment_not_allowed',
        'sign_key_required', 'sign_key_invalid', 'sign_vault_locked', 'sign_vault_missing',
        'invalid_request', 'validation_failed', 'not_found', 'method_not_allowed',
        'idempotency_key_required', 'idempotency_key_reuse', 'idempotency_in_flight',
        'prepare_token_invalid', 'rate_limited', 'amount_limit', 'mh_rejected',
        'mh_unreachable', 'correlative_unavailable', 'no_storage_destination',
        'service_unavailable', 'internal_error',
        // Stable SDK-local codes.
        'archive_integrity_error', 'network_error', 'operation_outcome_unknown',
        // Legacy/local codes kept from the TypeScript script.
        'forbidden', 'rate_limit_exceeded',
        'readiness_blocked', 'catalog_read_failed', 'catalog_write_gate_failed',
        'catalog_write_disabled', 'catalog_encrypted',
        'storage_unsupported', 'storage_unavailable', 'storage_contract_invalid',
        'fixture_required', 'fixture_invalid', 'permission_missing', 'related_document_invalid',
        'idempotency_window_expired', 'assertion_failed',
        'email_delivery_failed', 'delivery_inbox_invalid',
        // Input guards of the PHP script.
        'input_missing', 'input_invalid',
    ];

    /**
     * Every check starts as "Not checked", except the ones this version cannot run.
     *
     * @return array<string, string>
     */
    public static function createResults(): array
    {
        $results = [];
        foreach (array_keys(self::LABELS) as $name) {
            $results[$name] = in_array($name, self::NOT_AVAILABLE, true) ? self::NOT_IN_VERSION : 'Not checked';
        }
        return $results;
    }

    /**
     * Publish only a fixed category: the message of a failure may carry fiscal data.
     */
    public static function safeFailureCode(\Throwable $error): string
    {
        $code = null;
        if ($error instanceof LiveError) {
            $code = $error->getFailureCode();
        } elseif ($error instanceof \Facta\Api\FactaError) {
            $code = $error->getErrorCode();
        }
        return $code !== null && in_array($code, self::ERROR_CODES, true) ? $code : 'validation_failed';
    }

    /**
     * Same as {@see Report::safeFailureCode()} for a code that is already known.
     */
    public static function allowlistedCode(string $code): string
    {
        return in_array($code, self::ERROR_CODES, true) ? $code : 'validation_failed';
    }

    /**
     * Publish only allowlisted validation labels and states; never invoice data.
     *
     * @param array<string, string> $checks
     */
    public static function render(array $checks, ?string $failureCode = null): string
    {
        $lines = [
            '## Staging live validation (PHP SDK)',
            '',
            '| Check | Result |',
            '| --- | --- |',
        ];
        foreach (self::LABELS as $name => $label) {
            $state = $checks[$name] ?? 'Not checked';
            $lines[] = '| ' . $label . ' | ' . (in_array($state, self::STATES, true) ? $state : 'Not checked') . ' |';
        }
        $lines[] = '';
        if ($failureCode !== null && $failureCode !== '') {
            $lines[] = 'Failure category: `' . self::allowlistedCode($failureCode) . '`.';
            $lines[] = '';
        }
        $lines[] = 'Invoice documents and issuer/customer identifiers are not uploaded or published.';
        $lines[] = 'This test uses environment `00`; it does not issue a production invoice.';
        $lines[] = 'Checks marked "' . self::NOT_IN_VERSION . '" need the unlock key, the local archive or BYOS destinations, which arrive in a later version of the PHP SDK.';
        $lines[] = '';
        return implode("\n", $lines);
    }
}
