<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

final class DraftsTest extends ClientCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const DRAFT = ['borradorId' => self::ID, 'estado' => 'pendiente', 'version' => 2, 'totales' => ['totalPagar' => 11.3]];
    private const REQUEST = ['tipoDte' => '01', 'items' => [['descripcion' => 'Servicio', 'cantidad' => 1, 'precioUni' => 11.3, 'tipoItem' => 2]]];

    public function testCreateDraftPostsTheRequestWithYourIdempotencyKey(): void
    {
        $fake = new FakeTransport([self::ok(self::DRAFT, 201)]);
        $draft = $this->client($fake)->createDraft(self::REQUEST + ['referencia' => 'order-77'], ['idempotencyKey' => 'order-77']);

        $this->assertSame('POST', self::request($fake)['method']);
        $this->assertSame('/v1/drafts', self::path($fake));
        $this->assertSame('order-77', self::bodyOf($fake)['referencia']);
        $this->assertSame('order-77', self::request($fake)['headers']['Idempotency-Key']);
        $this->assertSame(self::ID, $draft['borradorId']);
        $this->assertSame(2, $draft['version']);
    }

    public function testAReferenceTooLongIsRefusedBeforeAnyRequest(): void
    {
        $fake = new FakeTransport();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client($fake)->createDraft(self::REQUEST + ['referencia' => str_repeat('x', 65)]);
        } finally {
            $this->assertSame([], $fake->requests);
        }
    }

    public function testListDraftsMapsTheFiltersAndThePage(): void
    {
        $issued = ['borradorId' => '22222222-2222-4222-8222-222222222222', 'estado' => 'emitido', 'version' => 2, 'codigoGeneracion' => 'AAAA'];
        $fake = new FakeTransport([self::ok(['borradores' => [self::DRAFT, $issued], 'siguiente' => 'c2']), self::ok(['borradores' => [], 'siguiente' => null])]);
        $client = $this->client($fake);

        $page = $client->listDrafts(['state' => 'pendiente', 'reference' => 'order-77', 'limit' => 10, 'cursor' => 'c1', 'include' => 'contenido']);
        $this->assertSame('/v1/drafts?estado=pendiente&referencia=order-77&cursor=c1&include=contenido&limite=10', self::path($fake));
        $this->assertSame('c2', $page['siguiente']);
        $this->assertCount(2, $page['borradores']);
        $this->assertSame('AAAA', $page['borradores'][1]['codigoGeneracion']);

        $empty = $client->listDrafts();
        $this->assertSame('/v1/drafts', self::path($fake, 1));
        $this->assertNull($empty['siguiente']);
    }

    public function testGetByIdByKeyAndCancelUseTheirRoutes(): void
    {
        $fake = new FakeTransport([self::ok(self::DRAFT), self::ok(self::DRAFT), self::ok(self::DRAFT)]);
        $client = $this->client($fake);
        $client->getDraft(self::ID);
        $client->getDraftByKey('order 77/a');
        $client->cancelDraft(self::ID);

        $this->assertSame(['GET', 'GET', 'DELETE'], array_column($fake->requests, 'method'));
        $this->assertSame('/v1/drafts/' . self::ID, self::path($fake, 0));
        $this->assertSame('/v1/drafts/by-key/order%2077%2Fa', self::path($fake, 1));
        $this->assertSame('/v1/drafts/' . self::ID, self::path($fake, 2));
    }

    public function testUpdateDraftSendsIfMatchWithTheIntegerVersion(): void
    {
        $fake = new FakeTransport([self::ok(['version' => 3] + self::DRAFT)]);
        $draft = $this->client($fake)->updateDraft(self::ID, 2, self::REQUEST);

        $this->assertSame('PUT', self::request($fake)['method']);
        $this->assertSame('2', self::request($fake)['headers']['If-Match']);
        $this->assertArrayNotHasKey('Idempotency-Key', self::request($fake)['headers']);
        $this->assertSame(3, $draft['version']);
        $this->expectException(\InvalidArgumentException::class);
        $this->client($fake)->updateDraft(self::ID, 0, self::REQUEST);
    }

    public function testIssueDraftCarriesTheVersionTheSigningKeyAndTheDraftId(): void
    {
        $fake = new FakeTransport([self::ok(['estado' => 'sellado', 'codigoGeneracion' => 'AAAA', 'borradorId' => self::ID])]);
        $result = $this->client($fake, ['signKey' => self::SIGN_KEY])->issueDraft(self::ID, 2, self::REQUEST, ['idempotencyKey' => 'issue-77']);

        $this->assertSame('POST', self::request($fake)['method']);
        $this->assertSame('/v1/drafts/' . self::ID . '/issue', self::path($fake));
        $this->assertSame(2, self::bodyOf($fake)['version']);
        $this->assertSame('01', self::bodyOf($fake)['tipoDte']);
        $this->assertSame(self::SIGN_KEY, self::request($fake)['headers']['X-Facta-Sign-Key']);
        $this->assertSame('issue-77', self::request($fake)['headers']['Idempotency-Key']);
        $this->assertSame(self::ID, $result['borradorId']);
    }

    public function testTheSigningKeyDoesNotRideOnOtherDraftCalls(): void
    {
        $fake = new FakeTransport([self::ok(self::DRAFT, 201)]);
        $this->client($fake, ['signKey' => self::SIGN_KEY])->createDraft(self::REQUEST);
        $this->assertArrayNotHasKey('X-Facta-Sign-Key', self::request($fake)['headers']);
    }

    public function testDraftErrorsKeepTheirCodeAndStatusAndAreNotRetried(): void
    {
        foreach ([
            [409, 'drafts_not_ready'], [409, 'version_mismatch'], [409, 'draft_claimed'], [409, 'draft_issuing'],
            [429, 'inbox_cap_reached'], [403, 'environment_not_allowed'],
        ] as [$status, $code]) {
            $fake = new FakeTransport([self::error($status, $code)]);
            try {
                $this->client($fake, ['maxRetries' => 3])->createDraft(self::REQUEST);
                $this->fail('FactaError expected for ' . $code);
            } catch (FactaError $e) {
                $this->assertSame($code, $e->getErrorCode());
                $this->assertSame($status, $e->getStatus());
                $this->assertCount(1, $fake->requests, $code . ' must not be retried.');
            }
        }
    }

    public function testADraftAnswerWithoutIdAndVersionIsRefused(): void
    {
        $fake = new FakeTransport([self::ok(['estado' => 'pendiente'])]);
        $this->expectException(FactaError::class);
        $this->client($fake)->createDraft(self::REQUEST);
    }

    /** @return array<int, array<string, string>> */
    private static function warnings(): array
    {
        return [
            ['codigo' => 'campo_desconocido', 'mensaje' => 'El campo items[0].descuento no existe', 'campo' => 'items[0].descuento', 'sugerencia' => 'items[0].montoDescu'],
            ['codigo' => 'campo_desconocido', 'mensaje' => 'El campo aplicarRetecionIva no existe', 'campo' => 'aplicarRetecionIva', 'sugerencia' => 'aplicarRetencionIva'],
        ];
    }

    /** @param array<string, mixed> $draft */
    private static function warned(int $status, array $draft): \Facta\Api\Http\Response
    {
        return FakeTransport::json($status, (string) json_encode($draft + ['advertencias' => self::warnings()]), ['Facta-Warning' => 'campo_desconocido; campos=2']);
    }

    public function testCreateReplayAndUpdateSurfaceUnknownFieldsLikeIssue(): void
    {
        $fake = new FakeTransport([self::warned(201, self::DRAFT), self::warned(200, self::DRAFT), self::warned(200, ['version' => 3] + self::DRAFT)]);
        $client = $this->client($fake);
        $typo = self::REQUEST + ['aplicarRetecionIva' => true];

        $created = $client->createDraft($typo, ['idempotencyKey' => 'order-77']);
        $replayed = $client->createDraft($typo, ['idempotencyKey' => 'order-77']);
        $updated = $client->updateDraft(self::ID, 2, $typo);

        foreach ([$created, $replayed, $updated] as $draft) {
            $this->assertSame(['items[0].descuento', 'aplicarRetecionIva'], array_column($draft['unknownFields'], 'campo'));
            $this->assertSame(['items[0].montoDescu', 'aplicarRetencionIva'], array_column($draft['unknownFields'], 'sugerencia'));
            $this->assertSame(['code' => 'campo_desconocido', 'count' => 2], $draft['factaWarning']);
            $this->assertSame('campo_desconocido', $draft['advertencias'][0]['codigo']);
            $this->assertSame(self::ID, $draft['borradorId']);
        }
        $this->assertSame(3, $updated['version']);
    }

    public function testACleanDraftAnswerIsUntouched(): void
    {
        $fake = new FakeTransport([self::ok(self::DRAFT, 201), self::ok(self::DRAFT)]);
        $client = $this->client($fake);
        foreach ([$client->createDraft(self::REQUEST), $client->updateDraft(self::ID, 2, self::REQUEST)] as $draft) {
            $this->assertArrayNotHasKey('unknownFields', $draft);
            $this->assertArrayNotHasKey('factaWarning', $draft);
        }
    }

    public function testAWarningOfAnEarlierCallDoesNotLeakIntoACleanDraft(): void
    {
        $fake = new FakeTransport([self::warned(201, self::DRAFT), self::ok(self::DRAFT, 201)]);
        $client = $this->client($fake);
        $client->createDraft(self::REQUEST);
        $this->assertArrayNotHasKey('factaWarning', $client->createDraft(self::REQUEST));
    }

    public function testARefusedDraftCarriesTheWarningsInItsError(): void
    {
        $details = (string) json_encode(['borradorId' => self::ID, 'advertencias' => self::warnings()]);
        $refusal = FakeTransport::json(422, '{"error":{"code":"idempotency_key_reuse","message":"m","details":' . $details . '}}', ['Facta-Warning' => 'campo_desconocido; campos=2']);
        $fake = new FakeTransport([$refusal]);
        try {
            $this->client($fake)->createDraft(self::REQUEST, ['idempotencyKey' => 'order-77']);
            $this->fail('expected FactaError');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::IDEMPOTENCY_KEY_REUSE, $e->getErrorCode());
            $this->assertCount(2, $e->getUnknownFields());
            $this->assertSame(['code' => 'campo_desconocido', 'count' => 2], $e->getFactaWarning());
        }
    }
}
