<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

final class DiagnoseTest extends ClientCase
{
    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function status(array $override = []): array
    {
        $ready = ['status' => 'ready', 'desiredRevision' => 3, 'publishedRevision' => 3];
        return array_replace_recursive([
            'ok' => true,
            'version' => '1',
            'ambiente' => '00',
            'emisor' => ['nit' => '06141234567890', 'nombre' => 'Test SA', 'ambiente' => '00'],
            'llave' => [
                'keyId' => 'k1', 'modo' => 'custodian', 'venceEl' => null,
                'alcances' => ['issue', 'query', 'download'], 'tiposDte' => ['01', '03'],
                'catalogMode' => 'readable',
            ],
            'firma' => ['vaultDeFirma' => true, 'origenDeLaFirma' => 'vault', 'cabecera' => 'X-Facta-Sign-Key'],
            'sincronizacion' => ['sign' => $ready, 'destinations' => $ready, 'catalog' => $ready],
            'limites' => [
                'hora' => ['limit' => 100, 'used' => 1, 'remaining' => 99],
                'dia' => ['limit' => 1000, 'used' => 1, 'remaining' => 999],
                'estado' => null,
            ],
        ], $override);
    }

    /** @return array<string, mixed> */
    private static function managedReady(): array
    {
        return [
            'capabilityVersion' => 1,
            'managed' => [
                'configured' => true, 'ready' => true, 'state' => 'ready', 'integration' => 'ready',
                'quotaBytes' => 1000, 'usedBytes' => 1, 'reservedBytes' => 0, 'usedBytesTotal' => 1, 'reservedBytesTotal' => 0,
                'coveredUntil' => null, 'accessUntil' => null, 'bucketState' => 'ready', 'backupState' => null,
            ],
            'byos' => ['ready' => false],
            'supportedKinds' => ['json', 'pdf'],
            'unsupportedKinds' => ['ticket', 'invalidation'],
        ];
    }

    /**
     * @param array<int, array{id: string, state: string, message: string}> $checks
     * @return array<string, string>
     */
    private static function states(array $checks): array
    {
        $out = [];
        foreach ($checks as $check) {
            $out[$check['id']] = $check['state'];
        }
        return $out;
    }

    public function testAReadyKeyCanIssueButTheLocalArchiveIsReportedAsUnavailable(): void
    {
        $fake = new FakeTransport([self::ok(self::status()), self::ok(self::managedReady())]);
        $client = $this->client($fake);
        $report = $client->diagnose(['expectedEnvironment' => '00', 'requiredScopes' => ['issue', 'query', 'download'], 'dteType' => '01']);

        $this->assertTrue($report['canIssue']);
        $this->assertTrue($report['canQuery']);
        $this->assertTrue($report['canDownload']);
        $this->assertFalse($report['canIssueAndArchive']);
        $this->assertNull($report['pendingArchiveOperations']);
        $this->assertSame('attention', $report['overall']);
        $this->assertTrue($report['storageReady']);
        $this->assertSame('readable', $report['catalogMode']);
        $this->assertSame('us-west-2', $report['region']);

        $states = self::states($report['checks']);
        foreach (['api', 'issuer', 'issuer-environment', 'scope-issue', 'scope-query', 'scope-download', 'issue-quota', 'key-expiry',
                  'dte-type', 'signing', 'sign-sync', 'destinations-sync', 'configured-environment', 'managed-storage',
                  'configured-scope-issue', 'environment'] as $id) {
            $this->assertSame('ok', $states[$id], $id);
        }
        $this->assertSame('unknown', $states['archive']);
        $this->assertSame('unknown', $states['archive-pending']);
        foreach ($report['checks'] as $check) {
            if ($check['id'] === 'archive') {
                $this->assertStringContainsString('not available in this SDK version yet', $check['message']);
            }
        }
        $this->assertSame('/v1/status', self::path($fake, 0));
        $this->assertSame('/v1/storage/status', self::path($fake, 1));
        $this->assertArrayNotHasKey('X-Facta-Sign-Key', self::request($fake)['headers']);
    }

    public function testAMissingScopeOrTypeBlocksIssuing(): void
    {
        $status = self::status();
        $status['llave']['alcances'] = ['query'];
        $fake = new FakeTransport([self::ok($status), self::ok(self::managedReady())]);
        $report = $this->client($fake)->diagnose(['requiredScopes' => ['issue'], 'dteType' => '11']);

        $this->assertFalse($report['canIssue']);
        $this->assertSame('blocked', $report['overall']);
        $states = self::states($report['checks']);
        $this->assertSame('blocked', $states['scope-issue']);
        $this->assertSame('blocked', $states['configured-scope-issue']);
        $this->assertSame('blocked', $states['dte-type']);
        $this->assertSame('ok', $states['scope-query']);
        $this->assertSame('blocked', $states['scope-download']);
        $this->assertFalse($report['canDownload']);
    }

    public function testTheEnvironmentMismatchAndAnExpiredKeyBlock(): void
    {
        $status = self::status(['llave' => ['venceEl' => '2020-01-01T00:00:00Z']]);
        $fake = new FakeTransport([self::ok($status), self::ok(self::managedReady())]);
        $report = $this->client($fake)->diagnose(['expectedEnvironment' => '01']);
        $states = self::states($report['checks']);
        $this->assertSame('blocked', $states['key-expiry']);
        $this->assertSame('blocked', $states['configured-environment']);
        $this->assertFalse($report['canIssue']);
    }

    public function testQuotaStates(): void
    {
        $low = self::status(['limites' => ['hora' => ['limit' => 100, 'used' => 95, 'remaining' => 5]]]);
        $report = $this->client(new FakeTransport([self::ok($low), self::ok(self::managedReady())]))->diagnose();
        $this->assertSame('warning', self::states($report['checks'])['issue-quota']);
        $this->assertTrue($report['canIssue']);

        $out = self::status(['limites' => ['dia' => ['limit' => 100, 'used' => 100, 'remaining' => 0]]]);
        $report = $this->client(new FakeTransport([self::ok($out), self::ok(self::managedReady())]))->diagnose();
        $this->assertSame('blocked', self::states($report['checks'])['issue-quota']);
        $this->assertFalse($report['canIssue']);

        $unknown = self::status(['limites' => ['hora' => null]]);
        $unknown['limites']['hora'] = null;
        $report = $this->client(new FakeTransport([self::ok($unknown), self::ok(self::managedReady())]))->diagnose();
        $this->assertSame('unknown', self::states($report['checks'])['issue-quota']);
        $this->assertFalse($report['canIssue'], 'Unknown ceilings fail closed, like the TypeScript SDK.');
    }

    public function testNoManagedStorageAndNoByosBlocks(): void
    {
        $storage = self::managedReady();
        $storage['managed'] = ['configured' => false, 'ready' => false, 'state' => 'not_configured', 'integration' => 'unavailable']
            + $storage['managed'];
        $storage['managed']['quotaBytes'] = null;
        $fake = new FakeTransport([self::ok(self::status()), self::ok($storage)]);
        $report = $this->client($fake)->diagnose();
        $this->assertFalse($report['storageReady']);
        $this->assertFalse($report['canIssue']);
        $this->assertSame('blocked', $report['overall']);
        $this->assertSame('blocked', self::states($report['checks'])['managed-storage']);
    }

    public function testAnOldServerWithoutStorageStatusOnlyDowngradesTheReport(): void
    {
        $fake = new FakeTransport([self::ok(self::status()), self::error(404, 'not_found')]);
        $report = $this->client($fake)->diagnose();
        $this->assertNull($report['storageReady']);
        $this->assertTrue($report['canIssue']);
        $this->assertSame('unknown', self::states($report['checks'])['managed-storage']);
    }

    public function testAStorageFailureBlocks(): void
    {
        $fake = new FakeTransport([self::ok(self::status()), self::error(500, 'internal_error')]);
        $report = $this->client($fake)->diagnose();
        $this->assertFalse($report['canIssue']);
        $this->assertSame('blocked', $report['overall']);
    }

    public function testAnUnreachableStatusIsABlockedReportNotAnException(): void
    {
        $fake = new FakeTransport([FakeTransport::failure()]);
        $report = $this->client($fake, ['maxRetries' => 0])->diagnose();
        $this->assertSame('blocked', $report['overall']);
        $this->assertFalse($report['canIssue']);
        $this->assertSame('api', $report['checks'][0]['id']);
        $this->assertStringContainsString('network_error', $report['checks'][0]['message']);
        $this->assertCount(1, $fake->requests);
    }

    public function testTheLegacyStatusContractWarnsInsteadOfBlocking(): void
    {
        $status = self::status();
        unset($status['sincronizacion']);
        $fake = new FakeTransport([self::ok($status), self::error(404, 'not_found')]);
        $report = $this->client($fake)->diagnose();
        $states = self::states($report['checks']);
        $this->assertSame('warning', $states['sign-sync']);
        $this->assertSame('warning', $states['destinations-sync']);
        $this->assertTrue($report['canIssue']);
        $this->assertNull($report['revisions']);
    }

    public function testUnsyncedSigningVaultBlocksAndCertificateChecksRun(): void
    {
        $status = self::status();
        $status['sincronizacion']['sign'] = ['status' => 'pending', 'desiredRevision' => 4, 'publishedRevision' => 3];
        $status['firma']['certificado'] = [
            'fingerprint' => 'ab', 'nit' => '0614-123456-789-0', 'environment' => '00',
            'validFrom' => '2020-01-01T00:00:00Z', 'validTo' => '2099-01-01T00:00:00Z',
        ];
        $fake = new FakeTransport([self::ok($status), self::ok(self::managedReady())]);
        $report = $this->client($fake)->diagnose();
        $states = self::states($report['checks']);
        $this->assertSame('blocked', $states['sign-sync']);
        $this->assertSame('ok', $states['certificate-identity']);
        $this->assertSame('ok', $states['certificate-validity']);
        $this->assertFalse($report['canIssue']);

        $status['sincronizacion']['sign'] = ['status' => 'ready', 'desiredRevision' => 4, 'publishedRevision' => 4];
        $status['firma']['certificado']['validTo'] = '2020-06-01T00:00:00Z';
        $report = $this->client(new FakeTransport([self::ok($status), self::ok(self::managedReady())]))->diagnose();
        $this->assertSame('blocked', self::states($report['checks'])['certificate-validity']);
        $this->assertFalse($report['canIssue']);
    }

    public function testDiagnoseRefusesUnknownOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeTransport([]))->diagnose(['archive' => new \stdClass()]);
    }

    public function testRegionIsTheConfiguredOneAndNeverAsksTheNetwork(): void
    {
        $fake = new FakeTransport([]);
        $this->assertSame('us-west-2', $this->client($fake)->region());
        $this->assertSame('eu-central-1', $this->client($fake, ['region' => 'eu-central-1'])->region());
        $this->assertNull($this->client($fake, ['region' => false])->region());
        $this->assertSame([], $fake->requests);
    }

    public function testServedRegionComesFromTheLatestAnswer(): void
    {
        $fake = new FakeTransport([
            FakeTransport::json(200, '{}', ['x-sb-edge-region' => 'us-west-2']),
            FakeTransport::json(200, '{}', ['x-sb-edge-region' => '<script>']),
            FakeTransport::json(200, '{}'),
        ]);
        $client = $this->client($fake);
        $this->assertNull($client->servedRegion());
        $client->status();
        $this->assertSame('us-west-2', $client->servedRegion());
        $client->status();
        $this->assertSame('us-west-2', $client->servedRegion(), 'A malformed header never replaces a good value.');
        $client->status();
        $this->assertSame('us-west-2', $client->servedRegion());
        $this->assertSame('00', $client->environment());
    }
}
