<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use Facta\Api\MemoryReplayStore;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;
use Facta\Api\Webhook;

final class WebhookTest extends ClientCase
{
    private const SECRET = 'whsec_test';
    private const NOW = 1790000000;
    // Computed independently with Node's crypto (the algorithm of the API's reference verifier).
    private const BODY = '{"deliveryId":"d1","event":"catalog.customer.updated","company":"co","recordId":"r1","revision":42,"generation":null,"at":"2026-10-26T12:00:00.000Z"}';
    private const MAC = 'dbf990b5b2cc0a977f4672c09e311b09d315def4b04eea4e1a6b28c62c130fa7';

    private static function header(int $t = self::NOW, string $mac = self::MAC): string
    {
        return 't=' . $t . ',v1=' . $mac;
    }

    public function testSignMatchesAnIndependentHmac(): void
    {
        $this->assertSame(self::header(), Webhook::sign(self::SECRET, self::BODY, self::NOW));
    }

    public function testAValidDeliveryVerifiesAndReturnsThePayload(): void
    {
        $result = Webhook::verify(self::SECRET, self::header(), self::BODY, ['nowSeconds' => self::NOW + 10]);
        $this->assertTrue($result['ok']);
        $this->assertSame('d1', $result['deliveryId']);
        $this->assertSame('catalog.customer.updated', $result['event']);
        $this->assertSame(42, $result['payload']['revision']);
        $this->assertNull($result['payload']['generation']);
    }

    /** @return array<string, array{?string, string, int, string}> */
    public function refusals(): array
    {
        return [
            'no header' => [null, self::BODY, self::NOW, 'malformed_header'],
            'garbage header' => ['nope', self::BODY, self::NOW, 'malformed_header'],
            'no v1' => ['t=' . self::NOW, self::BODY, self::NOW, 'malformed_header'],
            'short mac' => ['t=' . self::NOW . ',v1=abc', self::BODY, self::NOW, 'malformed_header'],
            'stale' => [self::header(), self::BODY, self::NOW + 301, 'stale_timestamp'],
            'future' => [self::header(), self::BODY, self::NOW - 301, 'stale_timestamp'],
            'body changed' => [self::header(), str_replace('42', '43', self::BODY), self::NOW, 'bad_signature'],
            'timestamp changed' => [self::header(self::NOW + 1), self::BODY, self::NOW, 'bad_signature'],
        ];
    }

    /** @dataProvider refusals */
    public function testItRefusesWhatItShould(?string $header, string $body, int $now, string $reason): void
    {
        $this->assertSame(['ok' => false, 'reason' => $reason], Webhook::verify(self::SECRET, $header, $body, ['nowSeconds' => $now]));
    }

    public function testAWrongSecretIsABadSignature(): void
    {
        $this->assertSame('bad_signature', Webhook::verify('whsec_other', self::header(), self::BODY, ['nowSeconds' => self::NOW])['reason']);
    }

    public function testSeveralSignaturesAllowARotationWindow(): void
    {
        $header = 't=' . self::NOW . ',v1=' . str_repeat('0', 64) . ',v1=' . self::MAC;
        $this->assertTrue(Webhook::verify(self::SECRET, $header, self::BODY, ['nowSeconds' => self::NOW])['ok']);
    }

    public function testASignedBodyThatIsNotTheDeliveryObjectIsMalformed(): void
    {
        foreach (['[]', '"x"', 'not json', '{"event":"x"}', '{"deliveryId":"","event":"x"}'] as $body) {
            $result = Webhook::verify(self::SECRET, Webhook::sign(self::SECRET, $body, self::NOW), $body, ['nowSeconds' => self::NOW]);
            $this->assertSame(['ok' => false, 'reason' => 'malformed_body'], $result, $body);
        }
    }

    public function testAReplayedDeliveryIsRefusedAndAForgeryNeverPoisonsTheStore(): void
    {
        $store = new MemoryReplayStore();
        $options = ['nowSeconds' => self::NOW, 'store' => $store];
        Webhook::verify(self::SECRET, self::header(self::NOW, str_repeat('1', 64)), self::BODY, $options);
        $this->assertSame(0, $store->size(), 'A bad signature is not remembered.');
        $this->assertTrue(Webhook::verify(self::SECRET, self::header(), self::BODY, $options)['ok']);
        $this->assertSame('replayed', Webhook::verify(self::SECRET, self::header(), self::BODY, $options)['reason']);
    }

    public function testConstructEventReturnsThePayloadOrThrows(): void
    {
        $payload = Webhook::constructEvent(self::SECRET, self::header(), self::BODY, ['nowSeconds' => self::NOW]);
        $this->assertSame('r1', $payload['recordId']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('bad_signature');
        Webhook::constructEvent('whsec_other', self::header(), self::BODY, ['nowSeconds' => self::NOW]);
    }

    public function testCreateWebhookSendsTheDocumentedBodyAndReturnsTheSecretOnce(): void
    {
        $fake = new FakeTransport([self::ok(['id' => 'w1', 'url' => 'https://x.test/h', 'eventos' => [], 'activo' => true, 'secreto' => 'whsec_abc'], 201)]);
        $created = $this->client($fake)->createWebhook(['url' => 'https://x.test/h', 'eventos' => ['catalog.restored'], 'descripcion' => 'erp']);
        $sent = self::request($fake);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('/v1/webhooks', self::path($fake));
        $this->assertSame('{"url":"https://x.test/h","eventos":["catalog.restored"],"descripcion":"erp"}', $sent['body']);
        $this->assertSame('whsec_abc', $created['secreto']);
    }

    public function testWebhookCrudRoutesAndMethods(): void
    {
        $id = '0b6f3b9e-47a4-4f0d-9d0c-6a9a3a8d0a11';
        $fake = new FakeTransport([
            self::ok(['webhooks' => [['id' => $id]]]),
            self::ok(['id' => $id]),
            self::ok(['id' => $id, 'activo' => true]),
            self::ok(['id' => $id, 'secreto' => 'whsec_new']),
            self::ok(['entregaId' => 'e1', 'evento' => 'webhook.test'], 202),
            self::ok(['id' => $id, 'eliminado' => true]),
        ]);
        $client = $this->client($fake);
        $this->assertCount(1, $client->listWebhooks());
        $client->getWebhook($id);
        $client->updateWebhook($id, ['activo' => true, 'descripcion' => null]);
        $this->assertSame('whsec_new', $client->rotateWebhookSecret($id)['secreto']);
        $this->assertSame('webhook.test', $client->testWebhook($id)['evento']);
        $this->assertTrue($client->deleteWebhook($id)['eliminado']);

        $routes = [];
        foreach ($fake->requests as $index => $request) {
            $routes[] = $request['method'] . ' ' . self::path($fake, $index);
        }
        $this->assertSame([
            'GET /v1/webhooks',
            'GET /v1/webhooks/' . $id,
            'PATCH /v1/webhooks/' . $id,
            'POST /v1/webhooks/' . $id . '/rotate-secret',
            'POST /v1/webhooks/' . $id . '/test',
            'DELETE /v1/webhooks/' . $id,
        ], $routes);
        $this->assertSame('{"activo":true,"descripcion":null}', $fake->requests[2]['body']);
    }

    public function testWebhookInputIsCheckedBeforeAnyRequest(): void
    {
        $client = $this->client(new FakeTransport([]));
        foreach ([[[]], [['url' => '']], [['url' => 'https://x.test', 'bogus' => 1]], [['url' => 'https://x.test', 'eventos' => 'all']]] as [$input]) {
            try {
                $client->createWebhook($input);
                $this->fail('InvalidArgumentException expected');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $client->updateWebhook('w', []);
    }

    public function testWebhookErrorsKeepTheirCodes(): void
    {
        $fake = new FakeTransport([self::error(409, 'webhook_limit_reached')]);
        $this->expectException(FactaError::class);
        $this->client($fake)->createWebhook(['url' => 'https://x.test/h']);
    }

    public function testListCatalogChangesBuildsTheQueryAndReturnsThePage(): void
    {
        $page = ['generacion' => 'a0b1c2d3-0000-4000-8000-000000000000', 'cabeza' => 5, 'cambios' => [['revision' => 5, 'tipo' => 'customer']], 'siguiente' => 5, 'hayMas' => false];
        $fake = new FakeTransport([self::ok($page), self::ok($page)]);
        $client = $this->client($fake);
        $this->assertSame($page, $client->listCatalogChanges());
        $client->listCatalogChanges(3, ['generation' => 'a0b1c2d3-0000-4000-8000-000000000000', 'limit' => 50]);
        $this->assertSame('/v1/catalog/changes?since=0', self::path($fake, 0));
        $this->assertSame('/v1/catalog/changes?since=3&generacion=a0b1c2d3-0000-4000-8000-000000000000&limite=50', self::path($fake, 1));
    }

    public function testListCatalogChangesChecksItsArgumentsAndTheAnswer(): void
    {
        $client = $this->client(new FakeTransport([self::ok(['nope' => 1])]));
        foreach ([[-1, []], [0, ['limit' => 0]], [0, ['limit' => 1001]], [0, ['generation' => 'x']]] as [$since, $options]) {
            try {
                $client->listCatalogChanges($since, $options);
                $this->fail('InvalidArgumentException expected');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        try {
            $client->listCatalogChanges();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::INTERNAL_ERROR, $e->getErrorCode());
        }
    }

    public function testAnExpiredCursorAndARetiredInboxKeepTheirCodeAndDetails(): void
    {
        $fake = new FakeTransport([
            self::error(410, 'catalog_cursor_expired', 'm', '{"motivo":"history_pruned","cabeza":9,"generacion":null}'),
        ]);
        try {
            $this->client($fake)->listCatalogChanges(1);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::CATALOG_CURSOR_EXPIRED, $e->getErrorCode());
            $this->assertSame(410, $e->getStatus());
            $details = $e->getDetails();
            $this->assertInstanceOf(\stdClass::class, $details);
            $this->assertSame(9, $details->cabeza);
        }
    }

    public function testTheClientVersionIsTheProposedRelease(): void
    {
        $this->assertSame('0.2.0', Facta::VERSION);
    }
}
