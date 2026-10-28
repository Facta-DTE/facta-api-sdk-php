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
}
