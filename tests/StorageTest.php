<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

final class StorageTest extends ClientCase
{
    /** @return array<string, mixed> */
    private static function storageStatus(): array
    {
        return [
            'capabilityVersion' => 1,
            'managed' => [
                'configured' => true, 'ready' => true, 'state' => 'ready', 'integration' => 'ready',
                'quotaBytes' => 1000000, 'usedBytes' => 10, 'reservedBytes' => 0, 'usedBytesTotal' => 10, 'reservedBytesTotal' => 0,
                'coveredUntil' => null, 'accessUntil' => null, 'bucketState' => 'ready', 'backupState' => null,
            ],
            'byos' => ['ready' => false],
            'supportedKinds' => ['json', 'pdf'],
            'unsupportedKinds' => ['ticket', 'invalidation'],
        ];
    }

    /** @return array<string, mixed> */
    private static function receipt(string $environment = '00'): array
    {
        $artifact = ['state' => 'stored', 'sha256' => str_repeat('a', 64), 'bytes' => 12, 'storedAt' => '2026-10-20T10:00:00Z', 'errorCode' => null, 'retryable' => false];
        return ['destination' => 'managed', 'environment' => $environment, 'operationId' => self::CG, 'json' => $artifact, 'pdf' => $artifact];
    }

    /** @return array<string, mixed> */
    private static function copy(string $kind = 'json', string $environment = '00'): array
    {
        return [
            'generationCode' => self::CG, 'kind' => $kind, 'environment' => $environment, 'state' => 'stored', 'bytes' => 12,
            'sha256' => str_repeat('b', 64), 'issuedDate' => '2026-10-20', 'storedAt' => '2026-10-20T10:00:00Z',
        ];
    }

    public function testStorageStatusIsReturnedWhenItMeetsTheContract(): void
    {
        $fake = new FakeTransport([self::ok(self::storageStatus())]);
        $status = $this->client($fake)->getStorageStatus();
        $this->assertTrue($status['managed']['ready']);
        $this->assertSame('/v1/storage/status', self::path($fake));
        $this->assertSame('GET', self::request($fake)['method']);
    }

    public function testStorageStatusAcceptsTheKindListsTheServerSendsToday(): void
    {
        // The exact lists staging and production answer since FactaDTE 9d7446e0, plus an
        // older server's lists and a future kind: all of them meet the contract.
        $shapes = [
            [['json', 'pdf', 'invalidation'], ['ticket']],
            [['json', 'pdf'], ['ticket', 'invalidation']],
            [['pdf', 'json', 'invalidation', 'some_future_kind'], []],
        ];
        $responses = [];
        foreach ($shapes as [$supported, $unsupported]) {
            $status = self::storageStatus();
            $status['supportedKinds'] = $supported;
            $status['unsupportedKinds'] = $unsupported;
            $responses[] = self::ok($status);
        }
        $client = $this->client(new FakeTransport($responses));
        foreach ($shapes as [$supported]) {
            $this->assertSame($supported, $client->getStorageStatus()['supportedKinds']);
        }
    }

    public function testStorageStatusRejectsKindListsThatBreakTheContract(): void
    {
        $broken = [
            [['json'], ['ticket']],                       // pdf missing
            [['json', 'pdf', 'pdf'], ['ticket']],         // duplicate
            [['json', 'pdf', 'ticket'], ['ticket']],      // both supported and unsupported
            [['json', 'pdf', 7], ['ticket']],             // not a string
            [['json', 'pdf'], 'ticket'],                  // not a list
        ];
        $responses = [];
        foreach ($broken as [$supported, $unsupported]) {
            $status = self::storageStatus();
            $status['supportedKinds'] = $supported;
            $status['unsupportedKinds'] = $unsupported;
            $responses[] = self::ok($status);
        }
        $client = $this->client(new FakeTransport($responses));
        foreach ($broken as $_) {
            try {
                $client->getStorageStatus();
                $this->fail('FactaError expected');
            } catch (FactaError $e) {
                $this->assertSame(FactaError::STORAGE_CONTRACT_INVALID, $e->getErrorCode());
            }
        }
    }

    public function testDocumentCopiesIncludeTheInvalidationEventFile(): void
    {
        $fake = new FakeTransport([
            self::ok(['capabilityVersion' => 1, 'copies' => [self::copy('json'), self::copy('pdf'), self::copy('invalidation')]]),
        ]);
        $copies = $this->client($fake)->getDocumentCopies();
        $this->assertSame(['json', 'pdf', 'invalidation'], array_column($copies, 'kind'));
    }

    public function testStorageStatusMapsAnOldServerAndRejectsABrokenContract(): void
    {
        $broken = self::storageStatus();
        $broken['managed']['usedBytesTotal'] = 2000000; // over quota while claiming ready
        $fake = new FakeTransport([self::error(404, 'not_found'), self::error(501, 'internal_error'), self::ok($broken), self::error(403, 'forbidden_scope')]);
        $client = $this->client($fake);

        foreach ([FactaError::STORAGE_UNSUPPORTED, FactaError::STORAGE_UNSUPPORTED, FactaError::STORAGE_CONTRACT_INVALID, FactaError::FORBIDDEN_SCOPE] as $expected) {
            try {
                $client->getStorageStatus();
                $this->fail('FactaError expected');
            } catch (FactaError $e) {
                $this->assertSame($expected, $e->getErrorCode());
            }
        }
    }

    public function testDocumentCopiesWithAndWithoutAFilter(): void
    {
        $fake = new FakeTransport([
            self::ok(['capabilityVersion' => 1, 'copies' => [self::copy('json'), self::copy('pdf')]]),
            self::ok(['capabilityVersion' => 1, 'copies' => [self::copy('json')]]),
        ]);
        $client = $this->client($fake);

        $all = $client->getDocumentCopies();
        $this->assertCount(2, $all);
        $this->assertSame('/v1/storage/copies', self::path($fake, 0));

        $one = $client->getDocumentCopies(['generationCode' => strtolower(self::CG)]);
        $this->assertSame('json', $one[0]['kind']);
        $this->assertSame('/v1/storage/copies?generationCode=' . strtolower(self::CG), self::path($fake, 1));
    }

    public function testDocumentCopiesOfAnotherEnvironmentOrDocumentAreRefused(): void
    {
        $other = self::copy('json');
        $other['generationCode'] = '11111111-2222-4333-8444-555555555555';
        $fake = new FakeTransport([
            self::ok(['capabilityVersion' => 1, 'copies' => [self::copy('json', '01')]]),
            self::ok(['capabilityVersion' => 1, 'copies' => [$other]]),
            self::ok(['capabilityVersion' => 1, 'copies' => [self::copy('json'), self::copy('json')]]),
        ]);
        $client = $this->client($fake);

        foreach ([[], ['generationCode' => self::CG], []] as $options) {
            try {
                $client->getDocumentCopies($options);
                $this->fail('FactaError expected');
            } catch (FactaError $e) {
                $this->assertSame(FactaError::STORAGE_CONTRACT_INVALID, $e->getErrorCode());
            }
        }
    }

    public function testRetryDocumentStoragePostsTheRepairRouteAndChecksTheReceipt(): void
    {
        $fake = new FakeTransport([
            self::ok(['storage' => self::receipt()]),
            self::ok(['storage' => self::receipt('01')]),
            self::error(404, 'not_found'),
        ]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);

        $receipt = $client->retryDocumentStorage(self::CG);
        $this->assertSame('stored', $receipt['json']['state']);
        $sent = self::request($fake);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('/v1/storage/copies/' . self::CG . '/repair', self::path($fake));
        $this->assertNull($sent['body']);
        $this->assertArrayNotHasKey('X-Facta-Sign-Key', $sent['headers']);

        foreach ([FactaError::STORAGE_CONTRACT_INVALID, FactaError::STORAGE_UNSUPPORTED] as $expected) {
            try {
                $client->retryDocumentStorage(self::CG);
                $this->fail('FactaError expected');
            } catch (FactaError $e) {
                $this->assertSame($expected, $e->getErrorCode());
            }
        }
    }

    public function testRetryDocumentStorageRequiresAUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeTransport([]))->retryDocumentStorage('nope');
    }
}
