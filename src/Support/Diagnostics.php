<?php

declare(strict_types=1);

namespace Facta\Api\Support;

/**
 * Readiness checks over the public `/v1/status` contract. This never opens a
 * vault and never reserves anything. It is the HTTP-only half of `diagnose()`
 * of the TypeScript SDK (`src/diagnostics.ts`); the checks that need local
 * storage (the restart-safe archive) report that they are not
 * available in this SDK version.
 *
 * @internal
 */
final class Diagnostics
{
    private const ISSUE_CHECKS = [
        'api', 'issuer', 'issuer-environment', 'scope-issue', 'issue-quota', 'key-expiry',
        'dte-type', 'signing', 'sign-sync', 'destinations-sync',
    ];

    /**
     * @param array<string, mixed> $status  The decoded `/v1/status` answer.
     * @param array<string, mixed> $options expectedEnvironment, requiredScopes, dteType
     * @return array{overall: string, canIssue: bool, canQuery: bool, canDownload: bool, canIssueAndArchive: bool, pendingArchiveOperations: null, revisions: array<string, mixed>|null, checks: array<int, array{id: string, state: string, message: string}>}
     */
    public static function fromStatus(array $status, array $options, bool $managedStorageReady): array
    {
        $checks = [];
        $add = static function (string $id, string $state, string $message) use (&$checks): void {
            $checks[] = ['id' => $id, 'state' => $state, 'message' => $message];
        };

        $apiOk = ($status['ok'] ?? null) === true;
        $add('api', $apiOk ? 'ok' : 'blocked', $apiOk ? 'API responded.' : 'API reported an unhealthy status.');

        $issuer = isset($status['emisor']) && is_array($status['emisor']) ? $status['emisor'] : null;
        $issuerOk = $issuer !== null;
        $add('issuer', $issuerOk ? 'ok' : 'blocked', $issuerOk ? 'Key has an issuing company.' : 'Key has no issuing company configured.');
        $environment = $status['ambiente'] ?? null;
        $environmentMatches = $issuer === null || ($issuer['ambiente'] ?? null) === $environment;
        $add(
            'issuer-environment',
            $environmentMatches ? 'ok' : 'blocked',
            $environmentMatches ? 'Company and key use the same environment.' : 'Key environment does not match the company environment.'
        );

        $key = isset($status['llave']) && is_array($status['llave']) ? $status['llave'] : [];
        $scopes = isset($key['alcances']) && is_array($key['alcances']) ? $key['alcances'] : [];
        $expectedScopesOk = true;
        $required = isset($options['requiredScopes']) && is_array($options['requiredScopes']) ? $options['requiredScopes'] : [];
        foreach ($required as $scope) {
            $has = in_array($scope, $scopes, true);
            $expectedScopesOk = $expectedScopesOk && $has;
            $add(
                'configured-scope-' . $scope,
                $has ? 'ok' : 'blocked',
                $has ? 'Key has the configured scope ' . $scope . '.' : 'Configured scope is missing: ' . $scope . '.'
            );
        }
        $canQuery = $apiOk && in_array('query', $scopes, true);
        $canDownload = $apiOk && in_array('download', $scopes, true);
        $add('scope-query', in_array('query', $scopes, true) ? 'ok' : 'blocked', in_array('query', $scopes, true) ? 'Key has the query scope.' : 'Query scope is missing.');
        $add('scope-download', in_array('download', $scopes, true) ? 'ok' : 'blocked', in_array('download', $scopes, true) ? 'Key has the download scope.' : 'Download scope is missing.');
        $issueScope = in_array('issue', $scopes, true);
        $add('scope-issue', $issueScope ? 'ok' : 'blocked', $issueScope ? 'Key has the issue scope.' : 'Issue scope is missing.');

        $limits = isset($status['limites']) && is_array($status['limites']) ? $status['limites'] : [];
        $windows = [$limits['hora'] ?? null, $limits['dia'] ?? null];
        $quotaKnown = true;
        $quotaAvailable = true;
        $quotaLow = false;
        foreach ($windows as $window) {
            if (!is_array($window) || !isset($window['remaining']) || !(is_int($window['remaining']) || is_float($window['remaining']))) {
                $quotaKnown = false;
                $quotaAvailable = false;
                continue;
            }
            if ($window['remaining'] <= 0) {
                $quotaAvailable = false;
            }
            if (isset($window['limit']) && $window['limit'] > 0 && $window['remaining'] / $window['limit'] < 0.1) {
                $quotaLow = true;
            }
        }
        if (!$quotaKnown) {
            $add('issue-quota', 'unknown', 'Could not confirm issuance limits.');
        } elseif (!$quotaAvailable) {
            $add('issue-quota', 'blocked', 'Key has reached its hourly or daily limit.');
        } elseif ($quotaLow) {
            $add('issue-quota', 'warning', 'Less than 10% remains in an issuance limit.');
        } else {
            $add('issue-quota', 'ok', 'Key has issuance capacity remaining.');
        }

        $keyNotExpired = true;
        $expiration = $key['venceEl'] ?? null;
        if (is_string($expiration)) {
            $expiresAt = strtotime($expiration);
            $keyNotExpired = $expiresAt !== false && $expiresAt > time();
            if (!$keyNotExpired) {
                $add('key-expiry', 'blocked', 'Key has expired or its expiration date is invalid.');
            } elseif ($expiresAt - time() < 72 * 3600) {
                $add('key-expiry', 'warning', 'Key expires within the next 72 hours.');
            } else {
                $add('key-expiry', 'ok', 'Key is not near expiration.');
            }
        } else {
            $add('key-expiry', 'ok', 'Key has no expiration date.');
        }

        $signing = isset($status['firma']) && is_array($status['firma']) ? $status['firma'] : null;
        $certificateOk = self::certificate($status, $signing, $add);

        $typeOk = true;
        $dteType = $options['dteType'] ?? null;
        if (is_string($dteType)) {
            $types = isset($key['tiposDte']) && is_array($key['tiposDte']) ? $key['tiposDte'] : [];
            $typeOk = in_array($dteType, $types, true);
            $add('dte-type', $typeOk ? 'ok' : 'blocked', $typeOk ? 'Key allows DTE ' . $dteType . '.' : 'Key does not allow DTE ' . $dteType . '.');
        }

        $signingOk = false;
        if ($signing === null) {
            $add('signing', 'unknown', 'API has not published signing-vault status.');
        } elseif (($signing['origenDeLaFirma'] ?? null) === 'vault' && ($signing['vaultDeFirma'] ?? null) === true) {
            $signingOk = true;
            $add('signing', 'ok', 'Signing vault is provisioned.');
        } elseif (($signing['origenDeLaFirma'] ?? null) === 'plataforma') {
            $signingOk = true;
            $add('signing', 'warning', 'Key still uses Facta\'s legacy signing mechanism.');
        } else {
            $add('signing', 'blocked', 'Key has no signing vault provisioned.');
        }

        $sync = array_key_exists('sincronizacion', $status) ? $status['sincronizacion'] : false;
        // Only a wholly absent block identifies the legacy contract; null or incomplete metadata fails closed.
        $legacy = $sync === false;
        $syncMap = is_array($sync) ? $sync : [];
        if ($legacy) {
            $add('sign-sync', 'warning', 'API does not publish synchronization revisions; signing provisioning is checked using the legacy status contract.');
            $add('destinations-sync', 'warning', 'API does not publish destination revisions; legacy status compatibility applies.');
        }
        $signingSyncOk = $legacy || self::revision($add, 'sign-sync', $syncMap['sign'] ?? null, 'Signing vault');
        if ($managedStorageReady) {
            $add('destinations-sync', 'ok', 'Facta-managed storage is ready; BYOS vault synchronization is optional.');
        }
        $destinationsOk = $managedStorageReady || $legacy
            || self::revision($add, 'destinations-sync', $syncMap['destinations'] ?? null, 'Storage destinations');

        $add('archive-pending', 'unknown', 'The local archive is not available in this SDK version yet, so pending operations cannot be inspected.');
        $add('archive', 'unknown', 'The local archive (which needs the unlock key) is not available in this SDK version yet.');

        $add(
            'environment',
            $environment === '00' ? 'ok' : 'warning',
            $environment === '00' ? 'Key targets the test environment.' : 'Key targets the production environment.'
        );
        $expected = $options['expectedEnvironment'] ?? null;
        $configuredEnvironmentOk = $expected === null || $expected === $environment;
        if ($expected !== null) {
            $add(
                'configured-environment',
                $configuredEnvironmentOk ? 'ok' : 'blocked',
                $configuredEnvironmentOk
                    ? 'Key matches the configured environment.'
                    : 'Key does not match the configured environment; review configuration before proceeding.'
            );
        }

        $canIssue = $apiOk && $issuerOk && $environmentMatches && $configuredEnvironmentOk && $expectedScopesOk && $issueScope
            && $keyNotExpired && $quotaAvailable && $certificateOk && $typeOk && $signingOk && $signingSyncOk && $destinationsOk;
        $anyWarning = false;
        foreach ($checks as $check) {
            if (
                $check['state'] === 'warning' || $check['state'] === 'unknown'
                || (in_array($check['id'], self::ISSUE_CHECKS, true) && $check['state'] === 'blocked')
            ) {
                $anyWarning = true;
            }
        }

        return [
            'overall' => !$canIssue ? 'blocked' : ($anyWarning ? 'attention' : 'ready'),
            'canIssue' => $canIssue,
            'canQuery' => $canQuery,
            'canDownload' => $canDownload,
            'canIssueAndArchive' => false,
            'pendingArchiveOperations' => null,
            'revisions' => is_array($sync)
                ? ['sign' => $syncMap['sign'] ?? null, 'destinations' => $syncMap['destinations'] ?? null]
                : null,
            'checks' => $checks,
        ];
    }

    /**
     * @param callable(string, string, string): void $add
     * @param mixed                                  $revision
     */
    private static function revision(callable $add, string $id, $revision, string $label): bool
    {
        if (!is_array($revision)) {
            $add($id, 'unknown', 'Could not confirm synchronization for ' . $label . '.');
            return false;
        }
        $status = $revision['status'] ?? null;
        if (
            $status === 'ready' && isset($revision['desiredRevision']) && is_int($revision['desiredRevision'])
            && ($revision['publishedRevision'] ?? null) === $revision['desiredRevision']
        ) {
            $add($id, 'ok', $label . ' is synchronized.');
            return true;
        }
        if ($status === 'legacy') {
            $add($id, 'warning', $label . ' still uses the legacy synchronization state.');
            return true;
        }
        $add($id, 'blocked', $label . ' has pending or failed synchronization.');
        return false;
    }

    /**
     * @param array<string, mixed>                   $status
     * @param array<string, mixed>|null              $signing
     * @param callable(string, string, string): void $add
     */
    private static function certificate(array $status, ?array $signing, callable $add): bool
    {
        $certificate = $signing !== null && isset($signing['certificado']) && is_array($signing['certificado'])
            ? $signing['certificado']
            : null;
        if ($certificate === null) {
            $add('certificate-metadata', 'unknown', 'API has not published public certificate metadata; the key retains its current behavior.');
            return true;
        }
        if (!isset($certificate['fingerprint']) || !is_string($certificate['fingerprint']) || $certificate['fingerprint'] === '') {
            $add('certificate-metadata', 'unknown', 'Could not confirm the public certificate fingerprint for this key.');
            return true;
        }
        $add('certificate-metadata', 'ok', 'API identified the certificate by its public fingerprint.');

        $identityOk = true;
        $issuer = isset($status['emisor']) && is_array($status['emisor']) ? $status['emisor'] : null;
        if (!empty($certificate['nit']) && is_string($certificate['nit']) && $issuer !== null && is_string($issuer['nit'] ?? null)) {
            $identityOk = preg_replace('/\D/', '', $certificate['nit']) === preg_replace('/\D/', '', $issuer['nit']);
            $add(
                'certificate-identity',
                $identityOk ? 'ok' : 'blocked',
                $identityOk
                    ? 'Certificate NIT matches the issuing company.'
                    : 'Certificate NIT does not match the issuing company; sync the correct certificate from the app.'
            );
        } else {
            $add('certificate-identity', 'unknown', 'There is not enough metadata to compare the certificate NIT.');
        }

        if (($certificate['environment'] ?? null) !== null) {
            $matches = $certificate['environment'] === ($status['ambiente'] ?? null);
            $add(
                'certificate-environment',
                $matches ? 'ok' : 'warning',
                $matches
                    ? 'Certificate is registered for the key environment.'
                    : 'Certificate is registered for a different environment; confirm the selection in the app.'
            );
        } else {
            $add('certificate-environment', 'unknown', 'Could not confirm the environment for which the certificate was registered.');
        }

        $from = isset($certificate['validFrom']) && is_string($certificate['validFrom']) ? strtotime($certificate['validFrom']) : false;
        $to = isset($certificate['validTo']) && is_string($certificate['validTo']) ? strtotime($certificate['validTo']) : false;
        $now = time();
        if ($from === false || $to === false) {
            $add('certificate-validity', 'unknown', 'Could not confirm certificate validity.');
        } elseif ($from > $now) {
            $add('certificate-validity', 'blocked', 'Certificate is not yet valid.');
            return false;
        } elseif ($to <= $now) {
            $add('certificate-validity', 'blocked', 'Certificate has expired; renew it and sync the key from the app.');
            return false;
        } elseif ($to - $now < 30 * 86400) {
            $add('certificate-validity', 'warning', 'Certificate expires within the next 30 days.');
        } else {
            $add('certificate-validity', 'ok', 'Certificate is valid.');
        }
        return $identityOk;
    }
}
