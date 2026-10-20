<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

final class IssueTest extends ClientCase
{
    private const FE = ['tipoDte' => '01', 'items' => [['descripcion' => 'Facta API SDK integration test', 'cantidad' => 1, 'precioUni' => 0.01]]];
    private const FE_FINGERPRINT = 'edd763e6eecb9d3ccbbac1e001b0eeef9839e91384d417c5727021193f362fdf';
    private const FE_WITH_MAIL_FINGERPRINT = '9e5301d979f6a84e8b7fcb935c87b25ea5f4f2ae7d3c56192b7e30bb0a03430e';

    private const SEALED = '{"estado":"sellado","codigoGeneracion":"8F6A3B2C-1D4E-4F50-9A7B-0C1D2E3F4A5B","numeroControl":"DTE-01-M001P001-000000000000001","tipoDte":"01","ambiente":"00","archivoDte":"{\"a\":1}"}';

    public function testIssuePostsTheBodyWithKeyFingerprintAndSignKey(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $result = $this->client($fake, ['signKey' => self::SIGN_KEY])->issue(self::FE, ['idempotencyKey' => 'order-77']);

        $this->assertSame('sellado', $result['estado']);
        $this->assertSame(self::CG, $result['codigoGeneracion']);
        $sent = self::request($fake);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('/v1/dte', self::path($fake));
        $this->assertSame('order-77', $sent['headers']['Idempotency-Key']);
        $this->assertSame(self::FE_FINGERPRINT, $sent['headers']['X-Facta-Request-Fingerprint']);
        $this->assertSame(self::SIGN_KEY, $sent['headers']['X-Facta-Sign-Key']);
        $this->assertSame('application/json', $sent['headers']['Content-Type']);
        $this->assertSame(
            '{"tipoDte":"01","items":[{"descripcion":"Facta API SDK integration test","cantidad":1,"precioUni":0.01}]}',
            $sent['body']
        );
        $this->assertCount(1, $fake->requests, 'A request with no catalog references must not read /v1/status.');
    }

    public function testIssueMintsOneIdempotencyKeyWhenTheCallerGivesNone(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED), self::ok(self::SEALED)]);
        $client = $this->client($fake);
        $client->issue(self::FE);
        $client->issue(self::FE);
        $first = self::request($fake, 0)['headers']['Idempotency-Key'];
        $second = self::request($fake, 1)['headers']['Idempotency-Key'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
        $this->assertNotSame($first, $second);
    }

    public function testARetryResendsTheSameBytesKeyAndFingerprint(): void
    {
        $fake = new FakeTransport([
            self::error(409, 'idempotency_in_flight'),
            self::error(502, 'mh_unreachable'),
            self::ok(self::SEALED),
        ]);
        $this->client($fake, ['signKey' => self::SIGN_KEY])->issue(self::FE);

        $this->assertCount(3, $fake->requests);
        $this->assertSame([250, 500], $this->sleeps);
        foreach ([1, 2] as $i) {
            $this->assertSame($fake->requests[0]['body'], $fake->requests[$i]['body']);
            $this->assertSame($fake->requests[0]['headers']['Idempotency-Key'], $fake->requests[$i]['headers']['Idempotency-Key']);
            $this->assertSame(self::FE_FINGERPRINT, $fake->requests[$i]['headers']['X-Facta-Request-Fingerprint']);
        }
    }

    public function testDeliverIsPartOfTheBodyAndOfTheFingerprint(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $this->client($fake)->issue(self::FE, ['deliver' => ['email' => 'a@b.co']]);

        $this->assertSame(['correo' => 'a@b.co'], self::bodyOf($fake)['entrega']);
        $this->assertSame(self::FE_WITH_MAIL_FINGERPRINT, self::request($fake)['headers']['X-Facta-Request-Fingerprint']);
        $this->assertStringEndsWith('"entrega":{"correo":"a@b.co"}}', (string) self::request($fake)['body']);
    }

    public function testWhatsAppDeliveryNeedsTheReceiversConsent(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $client = $this->client($fake);
        $client->issue(self::FE, ['deliver' => ['whatsapp' => ['number' => ' +50370001111 ', 'consent' => true]]]);
        $this->assertSame(['whatsapp' => ['numero' => '+50370001111', 'consentimiento' => true]], self::bodyOf($fake)['entrega']);

        foreach ([
            ['whatsapp' => ['number' => '+50370001111']],
            ['whatsapp' => ['number' => 'abc', 'consent' => true]],
            ['email' => 'not-an-address'],
            [],
        ] as $deliver) {
            try {
                $client->issue(self::FE, ['deliver' => $deliver]);
                $this->fail('InvalidArgumentException expected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('deliver', $e->getMessage());
            }
        }
        $this->assertCount(1, $fake->requests, 'A bad deliver option must fail before any request.');
    }

    public function testTheFingerprintOnlyTravelsOnTheTwoIssuingRoutes(): void
    {
        $fake = new FakeTransport([self::ok('{"estado":"preparado","documento":{},"prepareToken":"t"}'), self::ok(self::SEALED), self::ok('{}'), self::ok('{}')]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);
        $client->prepare(self::FE);
        $client->sign(['prepareToken' => 't', 'documento' => new \stdClass()]);
        $client->invalidate(self::CG, ['tipoAnulacion' => 2]);
        $client->registerReturn(self::CG, ['items' => [['linea' => 1, 'cantidad' => 1]]]);

        $this->assertArrayHasKey('X-Facta-Request-Fingerprint', $fake->requests[0]['headers']);
        $this->assertSame('/v1/dte/prepare', self::path($fake, 0));
        foreach ([1, 2, 3] as $i) {
            $this->assertArrayNotHasKey('X-Facta-Request-Fingerprint', $fake->requests[$i]['headers'], self::path($fake, $i));
        }
    }

    public function testTheSignKeyIsSentOnlyWhereTheRouteSigns(): void
    {
        $fake = new FakeTransport([
            self::ok('{"estado":"preparado","documento":{},"prepareToken":"t"}'), // prepare
            self::ok(self::SEALED),   // sign
            self::ok('{}'),           // invalidate
            self::ok('{}'),           // return
            self::ok('{}'),           // status
            self::ok('{}'),           // getDocumentStatus
            self::ok('{"documentos":[],"siguiente":null}'), // list
            self::ok('{}'),           // deliver
        ]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);
        $client->prepare(self::FE);
        $client->sign(['prepareToken' => 't', 'documento' => new \stdClass()]);
        $client->invalidate(self::CG, ['tipoAnulacion' => 2]);
        $client->registerReturn(self::CG, ['items' => [['linea' => 1, 'cantidad' => 1]]]);
        $client->status();
        $client->getDocumentStatus(self::CG);
        $client->listDocuments();
        $client->deliverEmail(self::CG, 'fdt_token');

        $withKey = [];
        foreach ($fake->requests as $i => $request) {
            if (isset($request['headers']['X-Facta-Sign-Key'])) {
                $withKey[] = $request['method'] . ' ' . self::path($fake, $i);
            }
        }
        $this->assertSame([
            'POST /v1/dte/sign',
            'POST /v1/dte/' . self::CG . '/invalidate',
            'POST /v1/dte/' . self::CG . '/return',
        ], $withKey);
    }

    public function testPrepareKeepsTheDocumentExactlyAndSignSendsItBack(): void
    {
        $answer = '{"estado":"preparado","codigoGeneracion":"' . self::CG . '","tipoDte":"01","totales":{"totalPagar":0.01},'
            . '"documento":{"identificacion":{"version":1},"extension":{},"apendice":null,"cuerpoDocumento":[{"numItem":1,"tributos":[]}],"monto":10.0},'
            . '"prepareToken":"tok.en"}';
        $fake = new FakeTransport([self::ok($answer), self::ok(self::SEALED)]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);

        $prepared = $client->prepare(self::FE, ['idempotencyKey' => 'p-1']);
        $this->assertSame('preparado', $prepared['estado']);
        $this->assertSame('tok.en', $prepared['prepareToken']);
        $this->assertSame(['totalPagar' => 0.01], $prepared['totales']);
        $this->assertInstanceOf(\stdClass::class, $prepared['documento'], 'documento stays the decoded tree so {} stays {}.');
        $this->assertInstanceOf(\stdClass::class, $prepared['documento']->extension);
        $this->assertSame('p-1', self::request($fake)['headers']['Idempotency-Key']);

        $client->sign($prepared, ['idempotencyKey' => 's-1']);
        $sent = (string) self::request($fake, 1)['body'];
        $this->assertSame(
            '{"prepareToken":"tok.en","documento":{"identificacion":{"version":1},"extension":{},"apendice":null,'
            . '"cuerpoDocumento":[{"numItem":1,"tributos":[]}],"monto":10}}',
            $sent,
            'An empty object and an empty list must come back as they went out (10.0 is the same number as 10).'
        );
        $this->assertSame('s-1', self::request($fake, 1)['headers']['Idempotency-Key']);
        $this->assertArrayNotHasKey('X-Facta-Request-Fingerprint', self::request($fake, 1)['headers']);
    }

    public function testSignRefusesAnythingThatIsNotAPreparedResult(): void
    {
        $client = $this->client(new FakeTransport([]));
        $this->expectException(\InvalidArgumentException::class);
        $client->sign(['documento' => new \stdClass()]);
    }

    public function testReferencesToTheCatalogAreResolvedByTheServerWhenItIsReadable(): void
    {
        $request = ['tipoDte' => '01', 'receptor' => ['customerId' => 'c1'], 'items' => [['productId' => 'p1', 'cantidad' => 2]]];
        $fake = new FakeTransport([self::statusWithCatalog('readable'), self::ok(self::SEALED), self::ok(self::SEALED)]);
        $client = $this->client($fake);
        $client->issue($request);
        $client->issue($request);

        $this->assertSame('/v1/status', self::path($fake, 0));
        $this->assertSame('/v1/dte', self::path($fake, 1));
        $this->assertSame('/v1/dte', self::path($fake, 2), 'The catalog mode is trusted for a minute: no second status call.');
        $this->assertSame(
            '{"tipoDte":"01","receptor":{"customerId":"c1"},"items":[{"productId":"p1","cantidad":2}]}',
            self::request($fake, 1)['body'],
            'The ids travel unresolved; the server resolves them.'
        );
        $this->assertSame('125f0e35672b58faaef7ccbfbc9d57ee0e1dcba6b3bc2aa9f80100451db8f08e', self::request($fake, 1)['headers']['X-Facta-Request-Fingerprint']);
    }

    /**
     * @dataProvider unresolvableModes
     */
    public function testAnEncryptedCatalogNeedsALocalSnapshotThisVersionCannotBuild(?string $mode): void
    {
        $request = ['tipoDte' => '01', 'items' => [['productId' => 'p1', 'cantidad' => 2]]];
        $fake = new FakeTransport([self::statusWithCatalog($mode)]);
        try {
            $this->client($fake)->issue($request);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::UNAUTHORIZED, $e->getErrorCode());
            $this->assertStringContainsString('later version', $e->getMessage());
            $details = $e->getDetails();
            $this->assertIsArray($details);
            $this->assertSame('unlock_key_missing', $details['reason']);
        }
        $this->assertCount(1, $fake->requests, 'Nothing is issued when the references cannot be resolved.');
    }

    /** @return array<string, array{?string}> */
    public function unresolvableModes(): array
    {
        return ['encrypted' => ['encrypted'], 'unknown' => [null]];
    }

    public function testTheErrorNamesTheMissingSnapshotNotTheMissingKeyWhenAnUnlockKeyIsSet(): void
    {
        $fake = new FakeTransport([self::statusWithCatalog('encrypted')]);
        $client = $this->client($fake, ['unlockKey' => 'factauk_ABCDEF']);
        try {
            $client->issue(['tipoDte' => '01', 'receptor' => ['customerId' => 'c'], 'items' => []]);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $details = $e->getDetails();
            $this->assertIsArray($details);
            $this->assertSame('local_snapshot_unsupported', $details['reason']);
        }
    }

    public function testAFailingStatusAlsoMeansNoServerSideResolution(): void
    {
        $fake = new FakeTransport([self::error(503, 'service_unavailable')]);
        $this->expectException(FactaError::class);
        $this->client($fake)->issue(['tipoDte' => '01', 'receptor' => ['customerId' => 'c'], 'items' => []]);
    }

    public function testIssueErrorsKeepTheirCodeAndNeverLeakTheKeys(): void
    {
        $fake = new FakeTransport([self::error(
            422,
            'mh_rejected',
            'bad ' . self::SIGN_KEY . ' and ' . self::KEY,
            '{"codigoGeneracion":"AB","numeroControl":"DTE-X","observaciones":["[001] x"],"signKey":"' . self::SIGN_KEY . '"}'
        )]);
        try {
            $this->client($fake, ['signKey' => self::SIGN_KEY])->issue(self::FE);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::MH_REJECTED, $e->getErrorCode());
            $this->assertTrue($e->isRejection());
            $this->assertSame(422, $e->getStatus());
            $this->assertSame(['codigoGeneracion' => 'AB', 'numeroControl' => 'DTE-X'], $e->spent());
            $this->assertSame(['[001] x'], $e->mhObservations());
            $this->assertStringNotContainsString(self::SIGN_KEY, $e->getMessage() . json_encode($e->getDetails()));
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testAMalformedStorageReceiptIsReplacedByAnErrorCode(): void
    {
        $sealed = json_decode(self::SEALED, true);
        $sealed['storage'] = ['destination' => 'managed', 'environment' => '00', 'operationId' => self::CG];
        $fake = new FakeTransport([self::ok($sealed)]);
        $result = $this->client($fake)->issue(self::FE);
        $this->assertArrayNotHasKey('storage', $result);
        $this->assertSame('storage_contract_invalid', $result['storageErrorCode']);
    }

    public function testAReceiptThatDoesNotDescribeTheBytesInTheSameAnswerIsDropped(): void
    {
        $json = '{"archivo":"dte"}';
        $good = [
            'destination' => 'managed', 'environment' => '00', 'operationId' => self::CG,
            'json' => ['state' => 'stored', 'sha256' => hash('sha256', $json), 'bytes' => strlen($json), 'storedAt' => '2026-10-20T10:00:00Z', 'errorCode' => null, 'retryable' => false],
            'pdf' => ['state' => 'pending', 'sha256' => null, 'bytes' => null, 'storedAt' => null, 'errorCode' => null, 'retryable' => true],
        ];
        $sealed = json_decode(self::SEALED, true);
        $sealed['archivoJson'] = $json;
        $sealed['storage'] = $good;
        $fake = new FakeTransport([self::ok($sealed)]);
        $this->assertArrayHasKey('storage', $this->client($fake)->issue(self::FE));

        $sealed['archivoJson'] = '{"archivo":"other"}';
        $fake = new FakeTransport([self::ok($sealed)]);
        $result = $this->client($fake)->issue(self::FE);
        $this->assertArrayNotHasKey('storage', $result);
        $this->assertSame('storage_contract_invalid', $result['storageErrorCode']);

        $sealed['archivoJson'] = $json;
        $sealed['storage']['environment'] = '01';
        $result = $this->client(new FakeTransport([self::ok($sealed)]))->issue(self::FE);
        $this->assertArrayNotHasKey('storage', $result, 'A receipt for another environment is never trusted.');
    }

    public function testUnknownOptionsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeTransport([]))->issue(self::FE, ['idempotencyKy' => 'typo']);
    }

    public function testTheRequestIsEncodedWithoutEscapingSlashesOrAccents(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $request = ['tipoDte' => '01', 'items' => [['descripcion' => 'Camión 1/2 pulgada', 'cantidad' => 1, 'precioUni' => 1.10]]];
        $this->client($fake)->issue($request);
        $this->assertSame(
            '{"tipoDte":"01","items":[{"descripcion":"Camión 1/2 pulgada","cantidad":1,"precioUni":1.1}]}',
            self::request($fake)['body']
        );
    }

    public function testEveryCallStillCarriesTheKeyAndTheRegion(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $this->client($fake)->issue(self::FE);
        $this->assertSame(self::KEY, self::request($fake)['headers']['X-Facta-Key']);
        $this->assertSame('us-west-2', self::request($fake)['headers']['x-region']);
        $this->assertSame(Facta::DEFAULT_BASE_URL . '/v1/dte', self::request($fake)['url']);
    }
}
