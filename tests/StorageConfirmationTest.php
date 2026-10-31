<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use Facta\Api\Http\Response;
use Facta\Api\Http\Transport;
use Facta\Api\Support\StorageConfirmation;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

/** A scripted API server for the temporary-copy confirmation: routes by path, like the real one. */
final class ConfirmationServer implements Transport
{
    /** @var int|null `storageConfirmation` capability; null = an old server (no field). */
    public $capability = 1;
    /** @var array<string, array<string, mixed>> cursor ('' = first) => body */
    public $pages = [];
    /** @var int */
    public $listStatus = 200;
    /** @var string[] codes whose JSON download is gone (410) */
    public $failDownload = [];
    /** @var string[] codes whose PDF download fails */
    public $failPdf = [];
    /** @var array<string, int> code => HTTP status of the confirmation */
    public $confirmStatus = [];
    /** @var string[] */
    public $already = [];
    /** @var string[] */
    public $calls = [];
    /** @var array<int, array{code: string, body: array<string, string>}> */
    public $confirmations = [];

    public static function jsonOf(string $code): string
    {
        return '{"codigoGeneracion":"' . $code . '","ambiente":"00","jws":"jws-' . $code . '"}';
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $parts = parse_url($url);
        $path = (string) preg_replace('#^.*/api-v1(/v1/)#', '$1', (string) ($parts['path'] ?? ''));
        parse_str((string) ($parts['query'] ?? ''), $query);
        $this->calls[] = $method . ' ' . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if ($path === '/v1/storage/status') {
            return FakeTransport::json(200, (string) json_encode($this->capability === null ? new \stdClass() : ['capabilities' => ['byosCopyReport' => 2, 'storageConfirmation' => $this->capability]]));
        }
        if ($path === '/v1/storage/unconfirmed') {
            if ($this->listStatus !== 200) {
                return self::err($this->listStatus, $this->listStatus === 404 ? 'not_found' : 'forbidden_scope', 'no');
            }
            return FakeTransport::json(200, (string) json_encode($this->pages[(string) ($query['cursor'] ?? '')] ?? ClientCaseHelpers::page([], null, 0)));
        }
        if (preg_match('#^/v1/dte/([^/]+)/file$#', $path, $m) === 1) {
            $code = strtoupper($m[1]);
            $kind = $query['kind'] ?? '';
            if ($kind === 'json' && in_array($code, $this->failDownload, true)) {
                return self::err(410, 'temporary_copy_expired', 'gone');
            }
            if ($kind === 'pdf' && in_array($code, $this->failPdf, true)) {
                return self::err(500, 'internal_error', 'x');
            }
            return new Response(200, ['content-type' => $kind === 'pdf' ? 'application/pdf' : 'application/json'], $kind === 'pdf' ? '%PDF-' . $code : self::jsonOf($code));
        }
        if (preg_match('#^/v1/dte/([^/]+)/storage-confirmation$#', $path, $m) === 1 && $method === 'POST') {
            $code = strtoupper($m[1]);
            $decoded = json_decode((string) $body, true);
            $this->confirmations[] = ['code' => $code, 'body' => is_array($decoded) ? $decoded : []];
            $status = $this->confirmStatus[$code] ?? 200;
            if ($status === 422) {
                return self::err(422, 'storage_hash_mismatch', 'bad hash');
            }
            if ($status === 404) {
                return self::err(404, 'not_found', 'no');
            }
            return FakeTransport::json(200, (string) json_encode([
                'codigoGeneracion' => $code, 'confirmado' => true, 'por' => 'api_report', 'yaConfirmado' => in_array($code, $this->already, true),
                'verificado' => false, 'informado' => true, 'borradoEl' => '2026-11-02T00:00:00.000Z',
            ]));
        }
        throw new \LogicException('unexpected route ' . $method . ' ' . $url);
    }

    private static function err(int $status, string $code, string $message): Response
    {
        return FakeTransport::json($status, '{"error":{"code":"' . $code . '","message":"' . $message . '","details":null}}');
    }
}

/** Fixtures shared by the server above and the tests. */
final class ClientCaseHelpers
{
    public const A = '7875BC7A-9580-441D-94E4-FA455E9D8BD0';
    public const B = '11111111-2222-4333-8444-555555555555';
    public const C = 'AAAAAAAA-2222-4333-8444-555555555555';

    /** @return array<string, mixed> */
    public static function row(string $code, string $borradoEl = '2026-11-20T00:00:00.000Z'): array
    {
        return [
            'codigoGeneracion' => $code, 'numeroControl' => 'DTE-01-M001P001-000000000000001', 'tipoDte' => '01',
            'fecEmi' => '2026-10-20', 'selladoEn' => '2026-10-21T10:00:00.000Z', 'borradoEl' => $borradoEl,
            'diasRestantes' => 21, 'copias' => ['facta' => 'none', 'byos' => 0],
        ];
    }

    /**
     * @param string[] $codes
     * @return array<string, mixed>
     */
    public static function page(array $codes, ?string $siguiente, ?int $total = null): array
    {
        return [
            'capabilityVersion' => 1,
            'documentos' => array_map([self::class, 'row'], $codes),
            'total' => $total ?? count($codes),
            'siguiente' => $siguiente,
        ];
    }
}

final class StorageConfirmationTest extends ClientCase
{
    private const A = ClientCaseHelpers::A;
    private const B = ClientCaseHelpers::B;
    private const C = ClientCaseHelpers::C;

    private function server(): ConfirmationServer
    {
        $server = new ConfirmationServer();
        $server->pages = ['' => ClientCaseHelpers::page([self::A, self::B], 'c2', 3), 'c2' => ClientCaseHelpers::page([self::C], null, 3)];
        return $server;
    }

    private function facta(ConfirmationServer $server): Facta
    {
        return new Facta(['apiKey' => self::KEY, 'transport' => $server, 'maxRetries' => 0, 'sleeper' => static function (int $ms): void {
        }]);
    }

    private function callCount(ConfirmationServer $server, string $call): int
    {
        return count(array_filter($server->calls, static function (string $c) use ($call): bool {
            return $c === $call;
        }));
    }

    public function testBodyHashesBytesAndRejectsBadInput(): void
    {
        $sha = StorageConfirmation::sha256('hello');
        $this->assertSame('2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824', $sha);
        $this->assertSame(['donde' => 'disk', 'sha256' => $sha], StorageConfirmation::body(['where' => ' disk ', 'bytes' => 'hello']));
        $this->assertSame(['donde' => 'disk', 'sha256' => $sha], StorageConfirmation::body(['where' => 'disk', 'sha256' => strtoupper($sha)]));
        $bad = [
            ['where' => '', 'sha256' => $sha],
            ['where' => str_repeat('x', 201), 'sha256' => $sha],
            ['where' => 'disk', 'sha256' => 'abc'],
            ['where' => 'disk'],
            ['where' => 'disk', 'sha256' => $sha, 'bytes' => 'x'],
            ['where' => 'disk', 'sha256' => $sha, 'extra' => 1],
        ];
        foreach ($bad as $input) {
            try {
                StorageConfirmation::body($input);
                $this->fail('Accepted ' . json_encode($input));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testListSendsLimitAndCursorAndReturnsThePage(): void
    {
        $server = $this->server();
        $page = $this->facta($server)->listUnconfirmedDocuments(['limit' => 2, 'cursor' => 'c2']);
        $this->assertSame(['GET /v1/storage/unconfirmed?limit=2&cursor=c2'], $server->calls);
        $this->assertSame([self::C], array_column($page['documentos'], 'codigoGeneracion'));
        $this->assertSame(3, $page['total']);
        $this->assertNull($page['siguiente']);
        foreach ([0, 101, '5'] as $limit) {
            try {
                $this->facta($server)->listUnconfirmedDocuments(['limit' => $limit]);
                $this->fail('limit accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('limit', $e->getMessage());
            }
        }
    }

    public function testListOnAnOldServerIsUnsupportedAndAMalformedPageIsInvalid(): void
    {
        $old = $this->server();
        $old->listStatus = 404;
        try {
            $this->facta($old)->listUnconfirmedDocuments();
            $this->fail('expected storage_unsupported');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::STORAGE_UNSUPPORTED, $e->getErrorCode());
        }
        $bad = $this->server();
        $bad->pages = ['' => ['capabilityVersion' => 1, 'documentos' => [['codigoGeneracion' => 'no']], 'total' => 1, 'siguiente' => null]];
        try {
            $this->facta($bad)->listUnconfirmedDocuments();
            $this->fail('expected storage_contract_invalid');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::STORAGE_CONTRACT_INVALID, $e->getErrorCode());
        }
    }

    public function testConfirmPostsDondeAndTheHashOfTheBytes(): void
    {
        $server = $this->server();
        $facta = $this->facta($server);
        $answer = $facta->confirmDocumentStorage(self::A, ['where' => 'disco-contabilidad', 'bytes' => ConfirmationServer::jsonOf(self::A)]);
        $this->assertSame('api_report', $answer['por']);
        $this->assertTrue($answer['informado']);
        $this->assertSame(
            [['code' => self::A, 'body' => ['donde' => 'disco-contabilidad', 'sha256' => hash('sha256', ConfirmationServer::jsonOf(self::A))]]],
            $server->confirmations
        );
        $facta->confirmDocumentStorage(self::B, ['where' => 'disk', 'sha256' => hash('sha256', 'x')]);
        $this->assertSame(1, $this->callCount($server, 'GET /v1/storage/status'), 'The capability read is cached.');
    }

    public function testConfirmOnAServerWithoutTheCapabilityPostsNothing(): void
    {
        foreach ([null, 0] as $capability) {
            $server = $this->server();
            $server->capability = $capability;
            try {
                $this->facta($server)->confirmDocumentStorage(self::A, ['where' => 'disk', 'bytes' => 'x']);
                $this->fail('expected storage_unsupported');
            } catch (FactaError $e) {
                $this->assertSame(FactaError::STORAGE_UNSUPPORTED, $e->getErrorCode());
            }
            $this->assertSame([], $server->confirmations);
        }
        try {
            $this->facta($this->server())->confirmDocumentStorage('not-a-uuid', ['where' => 'disk', 'bytes' => 'x']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('UUID', $e->getMessage());
        }
    }

    public function testConfirmKeepsServerCodesAndRepeatingIsIdempotent(): void
    {
        $server = $this->server();
        $server->confirmStatus = [self::A => 422, self::B => 404];
        $server->already = [self::C];
        $facta = $this->facta($server);
        foreach ([[self::A, FactaError::STORAGE_HASH_MISMATCH], [self::B, FactaError::NOT_FOUND]] as [$code, $expected]) {
            try {
                $facta->confirmDocumentStorage($code, ['where' => 'disk', 'bytes' => 'x']);
                $this->fail('expected ' . $expected);
            } catch (FactaError $e) {
                $this->assertSame($expected, $e->getErrorCode());
            }
        }
        $this->assertTrue($facta->confirmDocumentStorage(self::C, ['where' => 'disk', 'bytes' => 'x'])['yaConfirmado']);
    }

    public function testStoreAndConfirmListsEveryPageStoresAndConfirmsTheHashOfTheStoredJson(): void
    {
        $server = $this->server();
        $stored = [];
        $report = $this->facta($server)->storeAndConfirmUnconfirmed([
            'where' => 'my-disk',
            'store' => function (array $files) use (&$stored): void {
                $this->assertSame(ConfirmationServer::jsonOf($files['codigoGeneracion']), $files['json']);
                $stored[] = ['code' => $files['codigoGeneracion'], 'sha256' => $files['sha256'], 'pdf' => $files['pdf'] !== null];
            },
        ]);
        $this->assertSame('complete', $report['stopped']);
        $this->assertSame([3, 3, 0], [$report['listed'], $report['confirmed'], $report['failed']]);
        $this->assertSame(0, $report['remaining']);
        $this->assertNull($report['firstDeletionAt']);
        $this->assertSame([self::A, self::B, self::C], array_column($stored, 'code'));
        foreach ($stored as $row) {
            $this->assertTrue($row['pdf']);
        }
        $this->assertSame(
            array_map(static function (array $row): array {
                return ['donde' => 'my-disk', 'sha256' => $row['sha256']];
            }, $stored),
            array_column($server->confirmations, 'body')
        );
        $this->assertSame(
            ['GET /v1/storage/unconfirmed?limit=50', 'GET /v1/storage/unconfirmed?limit=50&cursor=c2'],
            array_values(array_filter($server->calls, static function (string $c): bool {
                return strpos($c, 'GET /v1/storage/unconfirmed') === 0;
            }))
        );
    }

    public function testStoreAndConfirmUsesThePlaceStoreReturnsAndSurvivesAMissingPdf(): void
    {
        $server = $this->server();
        $server->failPdf = [self::A];
        $report = $this->facta($server)->storeAndConfirmUnconfirmed([
            'store' => static function (array $files): string {
                return 's3://books/' . $files['codigoGeneracion'];
            },
        ]);
        $this->assertSame(3, $report['confirmed']);
        $this->assertTrue($report['results'][0]['pdfMissing']);
        $this->assertArrayNotHasKey('pdfMissing', $report['results'][1]);
        $this->assertSame('s3://books/' . self::A, $server->confirmations[0]['body']['donde']);
    }

    public function testStoreAndConfirmReportsAndSkipsADocumentThatFailsAndNeverConfirmsIt(): void
    {
        $server = $this->server();
        $server->failDownload = [self::A];
        $server->confirmStatus = [self::C => 422];
        $report = $this->facta($server)->storeAndConfirmUnconfirmed([
            'store' => static function (array $files): void {
                if ($files['codigoGeneracion'] === self::B) {
                    throw new \RuntimeException('disk full');
                }
            },
        ]);
        $this->assertSame('complete', $report['stopped']);
        $this->assertSame([0, 3], [$report['confirmed'], $report['failed']]);
        $this->assertSame(
            [['download', 'temporary_copy_expired'], ['store', 'store_failed'], ['confirm', 'storage_hash_mismatch']],
            array_map(static function (array $row): array {
                return [$row['step'], $row['code']];
            }, $report['results'])
        );
        $this->assertSame([self::C], array_column($server->confirmations, 'code'), 'Only the stored document got a confirmation attempt.');
        $this->assertSame(3, $report['remaining']);
        $this->assertSame('2026-11-20T00:00:00.000Z', $report['firstDeletionAt']);
    }

    public function testStoreAndConfirmStopsAfterMaxFailuresAndAtTheLimit(): void
    {
        $server = $this->server();
        $server->pages = ['' => ClientCaseHelpers::page([self::A, self::B, self::C], null, 3)];
        $failing = $this->facta($server)->storeAndConfirmUnconfirmed([
            'maxFailures' => 2,
            'store' => static function (array $files): void {
                throw new \RuntimeException('no');
            },
        ]);
        $this->assertSame(['too_many_failures', 2, 2], [$failing['stopped'], $failing['failed'], count($failing['results'])]);

        $limited = $this->facta($this->server())->storeAndConfirmUnconfirmed(['limit' => 2, 'store' => static function (array $files): void {
        }]);
        $this->assertSame(['limit', 2, 1], [$limited['stopped'], $limited['confirmed'], $limited['remaining']]);

        foreach ([['limit' => 1001], ['pageSize' => 0], ['maxFailures' => 0]] as $bad) {
            try {
                $this->facta($server)->storeAndConfirmUnconfirmed($bad + ['store' => static function (array $files): void {
                }]);
                $this->fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        try {
            $this->facta($server)->storeAndConfirmUnconfirmed([]);
            $this->fail('store is required');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('store', $e->getMessage());
        }
    }

    public function testStoreAndConfirmTurnsAnUnsupportedServerAndAListFailureIntoReports(): void
    {
        $noop = static function (array $files): void {
        };
        $unsupported = $this->server();
        $unsupported->capability = 0;
        $report = $this->facta($unsupported)->storeAndConfirmUnconfirmed(['store' => $noop]);
        $this->assertSame(['fatal', 'storage_unsupported', 0], [$report['stopped'], $report['error']['code'], $report['confirmed']]);
        $this->assertCount(1, $report['results'], 'It stops at the first document instead of trying every one.');

        $denied = $this->server();
        $denied->listStatus = 403;
        $report = $this->facta($denied)->storeAndConfirmUnconfirmed(['store' => $noop]);
        $this->assertSame(['list_failed', 'forbidden_scope'], [$report['stopped'], $report['error']['code']]);
    }

    public function testNewErrorCodesAreConstantsAndNotRetried(): void
    {
        $this->assertSame('temporary_copy_expired', FactaError::TEMPORARY_COPY_EXPIRED);
        $this->assertSame('nothing_to_repair_from', FactaError::NOTHING_TO_REPAIR_FROM);
        $this->assertSame('storage_hash_mismatch', FactaError::STORAGE_HASH_MISMATCH);
        $fake = new FakeTransport([self::error(410, 'temporary_copy_expired')]);
        try {
            $this->client($fake)->downloadDocument(self::CG, 'json');
            $this->fail('expected temporary_copy_expired');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::TEMPORARY_COPY_EXPIRED, $e->getErrorCode());
            $this->assertSame(410, $e->getStatus());
        }
        $this->assertCount(1, $fake->requests);
    }

    public function testTheStorageStatusKeepsTheNewFields(): void
    {
        $status = [
            'capabilityVersion' => 1,
            'capabilities' => ['byosCopyReport' => 2, 'invalidationFile' => 1, 'storageConfirmation' => 1],
            'managed' => [
                'configured' => false, 'ready' => false, 'state' => 'not_configured', 'integration' => 'ready',
                'quotaBytes' => null, 'usedBytes' => null, 'reservedBytes' => null, 'usedBytesTotal' => null, 'reservedBytesTotal' => null,
                'coveredUntil' => null, 'accessUntil' => null, 'bucketState' => null, 'backupState' => null,
            ],
            'byos' => ['ready' => false],
            'sinConfirmar' => ['total' => 3, 'primeroSeBorraEl' => '2026-11-20T00:00:00.000Z'],
            'supportedKinds' => ['json', 'pdf'],
            'unsupportedKinds' => ['ticket'],
        ];
        $result = $this->client(new FakeTransport([self::ok($status)]))->getStorageStatus();
        $this->assertSame(1, $result['capabilities']['storageConfirmation']);
        $this->assertSame(3, $result['sinConfirmar']['total']);
    }

    public function testCopiesKeepTheConfirmadoMark(): void
    {
        $copy = [
            'generationCode' => self::CG, 'kind' => 'json', 'environment' => '00', 'state' => 'stored', 'bytes' => 12,
            'sha256' => str_repeat('b', 64), 'issuedDate' => '2026-10-20', 'storedAt' => '2026-10-20T10:00:00Z',
            'confirmado' => ['en' => '2026-10-21T00:00:00Z', 'por' => 'sdk_report'],
        ];
        $copies = $this->client(new FakeTransport([self::ok(['capabilityVersion' => 1, 'copies' => [$copy]])]))->getDocumentCopies();
        $this->assertSame('sdk_report', $copies[0]['confirmado']['por']);
    }
}
