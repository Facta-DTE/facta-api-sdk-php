<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\Live\Guards;
use Facta\Api\Live\Report;
use Facta\Api\Live\Run;
use Facta\Api\Tests\Support\FakeApi;
use PHPUnit\Framework\TestCase;

/**
 * Runs the whole live script against a stateful imitation of the API, so the script's own logic
 * (its steps, its report, what it prints) is exercised on every pull request without a network.
 */
final class LiveRunTest extends TestCase
{
    /** @var string[] */
    private $out = [];
    /** @var string[] */
    private $err = [];
    /** @var int[] */
    private $sleeps = [];

    /**
     * @param array<string, mixed> $settings
     */
    private function makeRun(FakeApi $api, array $settings = [], ?string $fixtures = null): Run
    {
        $this->out = [];
        $this->err = [];
        $this->sleeps = [];
        $facta = new Facta([
            'apiKey' => 'facta_test_AbC123',
            'signKey' => 'factask_SIGN',
            'baseUrl' => 'https://eobxzotnqzgtpuqvmpkc.supabase.co/functions/v1/api-v1',
            'transport' => $api,
            'sleeper' => static function (int $ms): void {
            },
        ]);
        return new Run(
            $facta,
            $settings + ['runId' => '777', 'mode' => 'emit-test-fe', 'deliveryInbox' => null],
            $fixtures,
            function (string $line): void {
                $this->out[] = $line;
            },
            function (string $line): void {
                $this->err[] = $line;
            },
            function (int $ms): void {
                $this->sleeps[] = $ms;
            }
        );
    }

    public function testAHealthyStagingPassesEveryStepTierOneCanRun(): void
    {
        $api = new FakeApi();
        $run = $this->makeRun($api);

        $this->assertTrue($run->execute(), implode("\n", array_merge($this->out, $this->err)));
        $checks = $run->checks();
        foreach ([
            'status', 'preflight', 'pdf', 'signed-json', 'inline-bytes', 'ticket', 'managed-receipt', 'managed-readback',
            'managed-replay-repair', 'query', 'listing', 'replay', 'download-json', 'download-pdf',
        ] as $id) {
            $this->assertSame('Passed', $checks[$id], $id);
        }
        $this->assertSame('Passed (sealed test FE, $0.01)', $checks['emission']);
        $this->assertSame('Passed (sealed test FE, $0.01)', $checks['dte-01']);
        $this->assertSame('Passed (writes refused for the encrypted CI company)', $checks['catalog-write-gate']);
        $this->assertSame('Not run (no test inbox configured)', $checks['email-delivery']);
        $this->assertSame('Not in this SDK version', $checks['archive']);
        $this->assertNull($run->failureCode());
        $this->assertSame([], $this->err);
    }

    public function testItIssuesOneFeWithAKeyOfItsOwnAndReplaysIt(): void
    {
        $api = new FakeApi();
        $this->makeRun($api, ['runId' => '4242'])->execute();

        $issues = array_values(array_filter($api->log, static function (array $request): bool {
            return $request['method'] === 'POST' && strpos($request['path'], '/v1/dte') === 0 && substr($request['path'], -5) !== '/repair';
        }));
        $this->assertCount(2, $issues, 'The FE and its idempotent replay, nothing else.');
        $this->assertSame('/v1/dte', $issues[0]['path']);
        $this->assertSame($issues[0]['body'], $issues[1]['body'], 'The replay resends the same bytes.');
        $this->assertSame('php-sdk-live-4242-01', $issues[0]['headers']['Idempotency-Key']);
        $this->assertSame($issues[0]['headers']['Idempotency-Key'], $issues[1]['headers']['Idempotency-Key']);
        $this->assertSame(
            '{"tipoDte":"01","items":[{"descripcion":"Facta API SDK integration test","cantidad":1,"precioUni":0.01}]}',
            $issues[0]['body']
        );
        $this->assertArrayNotHasKey('X-Facta-Request-Fingerprint', $issues[0]['headers']);
        $this->assertSame('factask_SIGN', $issues[0]['headers']['X-Facta-Sign-Key']);
    }

    public function testNothingItPrintsOrReportsCarriesDocumentDataOrCredentials(): void
    {
        $api = new FakeApi();
        $run = $this->makeRun($api);
        $run->execute();
        $text = implode("\n", array_merge($this->out, $this->err)) . Report::render($run->checks(), $run->failureCode());

        foreach ([FakeApi::JWS, FakeApi::SELLO, FakeApi::CG, strtolower(FakeApi::CG), 'DTE-01-M001', 'factask_', 'facta_test_', 'fdt_', '06140101001010'] as $secret) {
            $this->assertStringNotContainsString($secret, $text, $secret);
        }
    }

    public function testTheReportIsWrittenWhereTheWorkflowReadsIt(): void
    {
        $directory = sys_get_temp_dir() . '/facta-live-' . bin2hex(random_bytes(4));
        $run = $this->makeRun(new FakeApi());
        $run->execute();
        $run->saveReport($directory . '/nested', null);
        try {
            $report = (string) file_get_contents($directory . '/nested/report.md');
            $this->assertStringStartsWith('## Staging live validation (PHP SDK)', $report);
            $this->assertStringContainsString('| Invoice generated and sealed | Passed (sealed test FE, $0.01) |', $report);
        } finally {
            @unlink($directory . '/nested/report.md');
            @rmdir($directory . '/nested');
            @rmdir($directory);
        }
    }

    public function testAnApiWithoutArchivoDteOnlyWarns(): void
    {
        $api = new FakeApi();
        $api->servesArchivoDte = false;
        $run = $this->makeRun($api);
        $this->assertTrue($run->execute(), implode("\n", $this->err));
        $this->assertContains('WARN archivoDte not served by this API yet; checked through the download below', $this->out);
    }

    public function testAKeyThatCannotIssueFailsClosedBeforeIssuing(): void
    {
        $api = new FakeApi();
        $api->issueScopes = ['query', 'download'];
        $run = $this->makeRun($api);

        $this->assertFalse($run->execute());
        $this->assertSame('permission_missing', $run->failureCode());
        $this->assertSame('Failed', $run->checks()['preflight']);
        $this->assertSame(['FAIL live integration: preflight (permission_missing)', 'FAIL detail: code: permission_missing'], $this->err);
        foreach ($api->log as $request) {
            $this->assertNotSame('POST', $request['method'], 'Nothing may be issued after a failed readiness check.');
        }
    }

    public function testManagedStorageMustBeReadyBeforeAnythingIsIssued(): void
    {
        $api = new FakeApi();
        $api->managedReady = false;
        $run = $this->makeRun($api);
        $this->assertFalse($run->execute());
        $this->assertSame('assertion_failed', $run->failureCode());
        $this->assertContains('FAIL detail: assertion: Full managed live validation requires ready managed storage.', $this->err);
        foreach ($api->log as $request) {
            $this->assertNotSame('POST', $request['method']);
        }
    }

    public function testACatalogWriteThatIsAcceptedFailsTheRun(): void
    {
        $api = new FakeApi();
        $api->catalogGate = null;
        $run = $this->makeRun($api);
        $this->assertFalse($run->execute());
        $this->assertSame('catalog_write_gate_failed', $run->failureCode());
        $this->assertSame('Failed', $run->checks()['catalog-write-gate']);
    }

    public function testCatalogGateWarningsDoNotFail(): void
    {
        $api = new FakeApi();
        $api->catalogGate = 'forbidden_scope';
        $run = $this->makeRun($api);
        $this->assertTrue($run->execute());
        $this->assertSame('Warning (key lacks catalog:write)', $run->checks()['catalog-write-gate']);

        $api = new FakeApi();
        $api->catalogGate = 'not_found';
        $run = $this->makeRun($api);
        $this->assertTrue($run->execute());
        $this->assertSame('Warning (catalog write routes not deployed on staging yet)', $run->checks()['catalog-write-gate']);
    }

    public function testAnUnexpectedCatalogGateAnswerFails(): void
    {
        $api = new FakeApi();
        $api->catalogGate = 'internal_error';
        $run = $this->makeRun($api);
        $this->assertFalse($run->execute());
        $this->assertSame('catalog_write_gate_failed', $run->failureCode());
    }

    public function testADocumentThatNeverAppearsInTheListingFailsAfterSixReads(): void
    {
        $api = new FakeApi();
        $api->listsDocuments = false;
        $run = $this->makeRun($api);
        $this->assertFalse($run->execute());
        $this->assertSame('Failed', $run->checks()['listing']);
        $this->assertSame([2000, 2000, 2000, 2000, 2000], $this->sleeps);
    }

    public function testEmailDeliveryIsOptionalAndWarnsOnAQuota(): void
    {
        $api = new FakeApi();
        $run = $this->makeRun($api, ['deliveryInbox' => 'qa.inbox@example.com']);
        $this->assertTrue($run->execute(), implode("\n", $this->err));
        $this->assertSame('Passed (e-mail sent)', $run->checks()['email-delivery']);
        $paths = array_column($api->log, 'path');
        $this->assertContains('/v1/dte/' . strtolower(FakeApi::CG) . '/entrega/correo', $paths);

        $api = new FakeApi();
        $api->mailState = 'fallido';
        $api->mailReason = 'quota_exceeded';
        $run = $this->makeRun($api, ['deliveryInbox' => 'qa.inbox@example.com']);
        $this->assertTrue($run->execute(), 'A mail quota only warns.');
        $this->assertSame('Warning (mail quota reached)', $run->checks()['email-delivery']);
        $this->assertStringContainsString('::warning title=E-mail delivery limit::', implode("\n", $this->out));

        $api = new FakeApi();
        $api->mailState = 'fallido';
        $api->mailReason = 'smtp_rejected';
        $run = $this->makeRun($api, ['deliveryInbox' => 'qa.inbox@example.com']);
        $this->assertFalse($run->execute());
        $this->assertSame('email_delivery_failed', $run->failureCode());
        $this->assertSame('Failed', $run->checks()['email-delivery']);
    }

    public function testTheDeliveryInvoiceUsesItsOwnKey(): void
    {
        $api = new FakeApi();
        $this->makeRun($api, ['runId' => '9', 'deliveryInbox' => 'qa.inbox@example.com'])->execute();
        $keys = [];
        foreach ($api->log as $request) {
            if ($request['method'] === 'POST' && $request['path'] === '/v1/dte') {
                $keys[$request['headers']['Idempotency-Key']] = true;
            }
        }
        $this->assertSame(['php-sdk-live-9-01', 'php-sdk-live-9-mail'], array_keys($keys));
    }

    public function testWithOnlyTheThreeOrganizationSecretsTheRunIsAFullPass(): void
    {
        // The workflow passes the two optional secrets through; when they do not exist they resolve to "".
        $env = [
            'STAGING_FACTA_API_KEY' => 'facta_test_AbC123',
            'STAGING_FACTA_SIGN_KEY' => 'factask_Sign',
            'STAGING_FACTA_UNLOCK_KEY' => 'factauk_Unlock',
            'STAGING_FACTA_API_BASE_URL' => Guards::EXPECTED_STAGING_API_BASE_URL,
            'GITHUB_RUN_ID' => '31',
            'GITHUB_RUN_CREATED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
            'FACTA_LIVE_REPORT_DIR' => '/tmp/unused',
            'FACTA_LIVE_MODE' => 'emit-test-fe',
            'STAGING_FACTA_DTE_FIXTURES_JSON' => '',
            'STAGING_FACTA_DELIVERY_EMAIL' => '',
        ];
        $settings = Guards::validate($env);
        $this->assertNull($settings['deliveryInbox']);

        $api = new FakeApi();
        $run = $this->makeRun(
            $api,
            ['runId' => $settings['runId'], 'mode' => $settings['mode'], 'deliveryInbox' => $settings['deliveryInbox']],
            $env['STAGING_FACTA_DTE_FIXTURES_JSON']
        );
        $this->assertTrue($run->execute(), implode("\n", $this->err));
        $this->assertNull($run->failureCode());
        $this->assertSame('Not run (no test inbox configured)', $run->checks()['email-delivery']);
        foreach (['03', '05', '06', '11', '14'] as $type) {
            $this->assertSame('Not run (fixture missing)', $run->checks()['dte-' . $type]);
        }
        $this->assertNotContains('entrega', array_map(static function (array $r): string {
            return substr($r['path'], -7);
        }, $api->log), 'Nothing is sent when there is no inbox.');
        foreach ($api->log as $request) {
            $this->assertStringNotContainsString('/entrega', $request['path']);
        }
    }

    public function testTheNormalRunLeavesTheOtherTypesAsFixtureNotConfigured(): void
    {
        $run = $this->makeRun(new FakeApi());
        $run->execute();
        foreach (['03', '05', '06', '11', '14'] as $type) {
            $this->assertSame('Not run (fixture missing)', $run->checks()['dte-' . $type]);
        }
    }

    public function testTheFixtureMatrixModeIssuesEveryConfiguredType(): void
    {
        $api = new FakeApi();
        $run = $this->makeRun($api, ['mode' => 'emit-enabled-dte-fixtures', 'runId' => '55'], (string) json_encode(LiveFixturesTest::fixtures(FakeApi::CG)));
        $this->assertTrue($run->execute(), implode("\n", $this->err));
        foreach (['03', '05', '06', '11', '14'] as $type) {
            $this->assertSame('Passed (test fixture sealed)', $run->checks()['dte-' . $type]);
        }
        $keys = [];
        foreach ($api->log as $request) {
            if ($request['method'] === 'POST' && $request['path'] === '/v1/dte') {
                $keys[$request['headers']['Idempotency-Key']] = true;
            }
        }
        $this->assertSame(
            ['php-sdk-live-55-01', 'php-sdk-live-55-03', 'php-sdk-live-55-05', 'php-sdk-live-55-06', 'php-sdk-live-55-11', 'php-sdk-live-55-14'],
            array_keys($keys)
        );
    }

    public function testTheFixtureMatrixModeRefusesAnIncompleteMatrixBeforeIssuing(): void
    {
        $api = new FakeApi();
        $run = $this->makeRun($api, ['mode' => 'emit-enabled-dte-fixtures']);
        $this->assertFalse($run->execute());
        $this->assertSame('fixture_required', $run->failureCode());
        foreach ($api->log as $request) {
            $this->assertNotSame('POST', $request['method']);
        }
    }
}
