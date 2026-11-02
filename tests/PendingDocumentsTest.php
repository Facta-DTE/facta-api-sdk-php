<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

/** GET /v1/dte/pendientes and POST /v1/dte/{code}/reintentar: finishing a signed document without a new one. */
final class PendingDocumentsTest extends ClientCase
{
    private const ROW = [
        'codigoGeneracion' => self::CG,
        'numeroControl' => 'DTE-01-M001P001-000000000000417',
        'tipoDte' => '01',
        'estado' => 'firmado',
        'reintentable' => true,
        'motivoNoReintentable' => null,
    ];

    public function testListPendingDocumentsMapsTheFiltersAndThePage(): void
    {
        $fake = new FakeTransport([self::ok(['pendientes' => [self::ROW], 'siguiente' => 'c2']), self::ok('{}')]);
        $client = $this->client($fake);

        $page = $client->listPendingDocuments(['estado' => 'firmado', 'tipoDte' => '01', 'limit' => 10, 'cursor' => 'c1']);
        $this->assertSame('GET', self::request($fake)['method']);
        $this->assertSame('/v1/dte/pendientes?estado=firmado&tipoDte=01&limit=10&cursor=c1', self::path($fake));
        $this->assertSame(self::CG, $page['pendientes'][0]['codigoGeneracion']);
        $this->assertTrue($page['pendientes'][0]['reintentable']);
        $this->assertSame('c2', $page['siguiente']);

        $empty = $client->listPendingDocuments();
        $this->assertSame('/v1/dte/pendientes', self::path($fake, 1));
        $this->assertSame([], $empty['pendientes']);
        $this->assertNull($empty['siguiente']);
    }

    public function testAnUnknownFilterIsRefusedBeforeAnyRequest(): void
    {
        $fake = new FakeTransport();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client($fake)->listPendingDocuments(['state' => 'firmado']);
        } finally {
            $this->assertSame([], $fake->requests);
        }
    }

    public function testRetryDocumentPostsToTheRetryRouteWithTheSignKeyAndNoBody(): void
    {
        $sealed = ['estado' => 'sellado', 'via' => 'consulta', 'codigoGeneracion' => self::CG, 'numeroControl' => 'N', 'tipoDte' => '01', 'ambiente' => '00'];
        $fake = new FakeTransport([self::ok($sealed)]);
        $result = $this->client($fake, ['signKey' => self::SIGN_KEY])->retryDocument(self::CG);

        $sent = self::request($fake);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('/v1/dte/' . self::CG . '/reintentar', self::path($fake));
        $this->assertSame(self::SIGN_KEY, $sent['headers']['X-Facta-Sign-Key']);
        $this->assertNull($sent['body']);
        $this->assertSame('sellado', $result['estado']);
        $this->assertSame('consulta', $result['via']);
    }

    public function testAPendingAnswerIsAResultNotAnError(): void
    {
        $pending = ['estado' => 'pendiente', 'via' => 'retransmision', 'codigoGeneracion' => self::CG, 'numeroControl' => 'N', 'mhRespondio' => false];
        $fake = new FakeTransport([self::ok($pending, 202)]);
        $result = $this->client($fake, ['signKey' => self::SIGN_KEY])->retryDocument(self::CG);
        $this->assertSame('pendiente', $result['estado']);
        $this->assertFalse($result['mhRespondio']);
        $this->assertCount(1, $fake->requests);
    }

    public function testRetryInProgressAndNotRetryableAreFinalAndCarryTheirDetails(): void
    {
        $busy = FakeTransport::json(409, '{"error":{"code":"retry_in_progress","message":"m","details":null}}', ['Retry-After' => '3']);
        $fake = new FakeTransport([$busy, self::error(409, 'document_not_retryable', 'm', '{"motivo":"documento_de_la_aplicacion"}')]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);
        try {
            $client->retryDocument(self::CG);
            $this->fail('expected FactaError');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::RETRY_IN_PROGRESS, $e->getErrorCode());
            $this->assertSame(3000, $e->getRetryAfterMs());
        }
        try {
            $client->retryDocument(self::CG);
            $this->fail('expected FactaError');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::DOCUMENT_NOT_RETRYABLE, $e->getErrorCode());
            $this->assertSame('documento_de_la_aplicacion', $e->getDetails()->motivo);
        }
        $this->assertCount(2, $fake->requests);
        $this->assertSame([], $this->sleeps);
    }

    public function testAnEmptyCodeIsRefusedBeforeAnyRequest(): void
    {
        $fake = new FakeTransport();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client($fake)->retryDocument(' ');
        } finally {
            $this->assertSame([], $fake->requests);
        }
    }
}
