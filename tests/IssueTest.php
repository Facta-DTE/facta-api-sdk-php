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

    private const SEALED = '{"estado":"sellado","codigoGeneracion":"8F6A3B2C-1D4E-4F50-9A7B-0C1D2E3F4A5B","numeroControl":"DTE-01-M001P001-000000000000001","tipoDte":"01","ambiente":"00","archivoDte":"{\"a\":1}"}';

    public function testIssuePostsTheBodyWithKeyAndSignKeyAndNoFingerprint(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $result = $this->client($fake, ['signKey' => self::SIGN_KEY])->issue(self::FE, ['idempotencyKey' => 'order-77']);

        $this->assertSame('sellado', $result['estado']);
        $this->assertSame(self::CG, $result['codigoGeneracion']);
        $sent = self::request($fake);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('/v1/dte', self::path($fake));
        $this->assertSame('order-77', $sent['headers']['Idempotency-Key']);
        $this->assertArrayNotHasKey('X-Facta-Request-Fingerprint', $sent['headers'], 'The SDK no longer rewrites the body, so the server hashes the body itself.');
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

    public function testARetryResendsTheSameBytesAndKey(): void
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
        }
    }

    public function testDeliverIsPartOfTheBody(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $this->client($fake)->issue(self::FE, ['deliver' => ['email' => 'a@b.co']]);

        $this->assertSame(['correo' => 'a@b.co'], self::bodyOf($fake)['entrega']);
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

    public function testNoRouteCarriesTheFingerprintHeader(): void
    {
        $fake = new FakeTransport([self::ok('{"estado":"preparado","documento":{},"prepareToken":"t"}'), self::ok(self::SEALED), self::ok('{}'), self::ok('{}')]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);
        $client->prepare(self::FE);
        $client->sign(['prepareToken' => 't', 'documento' => new \stdClass()]);
        $client->invalidate(self::CG, ['tipoAnulacion' => 2]);
        $client->registerReturn(self::CG, ['items' => [['linea' => 1, 'cantidad' => 1]]]);

        foreach ([0, 1, 2, 3] as $i) {
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

    public function testReferencesToTheCatalogTravelUntouchedAndWithoutAStatusRead(): void
    {
        $request = ['tipoDte' => '01', 'receptor' => ['customerId' => 'c1'], 'items' => [['productId' => 'p1', 'cantidad' => 2]]];
        $fake = new FakeTransport([self::ok(self::SEALED), self::ok(self::SEALED), self::ok('{"estado":"preparado","documento":{},"prepareToken":"t"}')]);
        $client = $this->client($fake);
        $client->issue($request);
        $client->issue($request);
        $client->prepare($request);

        $this->assertCount(3, $fake->requests, 'No /v1/status read: the server resolves the ids in every catalog mode.');
        foreach ([0, 1] as $i) {
            $this->assertSame('/v1/dte', self::path($fake, $i));
            $this->assertSame(
                '{"tipoDte":"01","receptor":{"customerId":"c1"},"items":[{"productId":"p1","cantidad":2}]}',
                self::request($fake, $i)['body']
            );
        }
        $this->assertSame('/v1/dte/prepare', self::path($fake, 2));
    }

    public function testAnUnknownIdIsTheServersNotFoundNotALocalError(): void
    {
        $fake = new FakeTransport([self::error(404, 'not_found', 'm', '{"reason":"catalog_unknown_id"}')]);
        try {
            $this->client($fake)->issue(['tipoDte' => '01', 'receptor' => ['customerId' => 'nope'], 'items' => []]);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::NOT_FOUND, $e->getErrorCode());
            $details = $e->getDetails();
            $this->assertInstanceOf(\stdClass::class, $details);
            $this->assertSame('catalog_unknown_id', $details->reason);
        }
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

    /** @return array<string, array{string, int}> */
    public function retryableFailures(): array
    {
        return [
            'idempotency_in_flight' => ['idempotency_in_flight', 409],
            'mh_unreachable' => ['mh_unreachable', 502],
            'service_unavailable' => ['service_unavailable', 503],
            'correlative_unavailable' => ['correlative_unavailable', 503],
            'storage_unavailable' => ['storage_unavailable', 503],
            'data_grant_unavailable' => ['data_grant_unavailable', 503],
            'data_epoch_stale' => ['data_epoch_stale', 503],
        ];
    }

    /** @dataProvider retryableFailures */
    public function testEachRetryableCodeIsRetriedWithTheSameBytesAndKey(string $code, int $status): void
    {
        $fake = new FakeTransport([self::error($status, $code), self::ok(self::SEALED)]);
        $this->client($fake)->issue(self::FE);
        $this->assertCount(2, $fake->requests);
        $this->assertSame($fake->requests[0]['body'], $fake->requests[1]['body']);
        $this->assertSame($fake->requests[0]['headers']['Idempotency-Key'], $fake->requests[1]['headers']['Idempotency-Key']);
        $this->assertSame([250], $this->sleeps);
    }

    public function testATransportFailureIsRetriedAsNetworkError(): void
    {
        $fake = new FakeTransport([FakeTransport::failure(), self::ok(self::SEALED)]);
        $this->client($fake)->issue(self::FE);
        $this->assertCount(2, $fake->requests);
        $this->assertSame($fake->requests[0]['body'], $fake->requests[1]['body']);
        $this->assertSame($fake->requests[0]['headers']['Idempotency-Key'], $fake->requests[1]['headers']['Idempotency-Key']);
    }

    public function testRetriesStopAtMaxRetriesAndRetryAfterIsHonoured(): void
    {
        $fake = new FakeTransport([
            FakeTransport::json(503, '{"error":{"code":"service_unavailable","message":"m"}}', ['retry-after' => '3']),
            FakeTransport::failure(),
            FakeTransport::failure(),
        ]);
        try {
            $this->client($fake, ['maxRetries' => 2])->issue(self::FE);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::NETWORK_ERROR, $e->getErrorCode());
        }
        $this->assertCount(3, $fake->requests);
        $this->assertSame([3000, 500], $this->sleeps);
    }

    public function testANonRetryableCodeIsFinal(): void
    {
        $fake = new FakeTransport([self::error(422, 'mh_rejected')]);
        try {
            $this->client($fake)->issue(self::FE);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::MH_REJECTED, $e->getErrorCode());
        }
        $this->assertCount(1, $fake->requests);
        $this->assertSame([], $this->sleeps);
    }
}
