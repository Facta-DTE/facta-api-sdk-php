<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

/** The 503 of POST /v1/dte when the document was signed but not transmitted: never a new emission. */
final class SignedNotTransmittedTest extends ClientCase
{
    private const NC = 'DTE-01-M001P001-000000000000001';

    private static function signedNotSent(bool $confirmed = true): \Facta\Api\Http\Response
    {
        $details = (string) json_encode([
            'estado' => 'firmado_sin_transmitir',
            'confirmado' => $confirmed,
            'siguientePaso' => 'POST /v1/dte/{codigoGeneracion}/reintentar',
            'codigoGeneracion' => self::CG,
            'numeroControl' => self::NC,
        ]);
        return FakeTransport::json(503, '{"error":{"code":"service_unavailable","message":"el documento quedó firmado pero no se envió a Hacienda","details":' . $details . '}}');
    }

    public function testTheExceptionNamesTheDocumentAndTheRetryRoute(): void
    {
        $fake = new FakeTransport([self::signedNotSent()]);
        try {
            $this->client($fake, ['signKey' => self::SIGN_KEY])->issue(['tipoDte' => '01', 'items' => []], ['idempotencyKey' => 'order-77']);
            $this->fail('expected FactaError');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::SERVICE_UNAVAILABLE, $e->getErrorCode());
            $this->assertSame(503, $e->getStatus());
            $this->assertTrue($e->isSignedNotTransmitted());
            $this->assertSame([
                'codigoGeneracion' => self::CG,
                'numeroControl' => self::NC,
                'estado' => 'firmado_sin_transmitir',
                'confirmado' => true,
                'siguientePaso' => 'POST /v1/dte/{codigoGeneracion}/reintentar',
            ], $e->signedNotTransmitted());
            $this->assertSame(['codigoGeneracion' => self::CG, 'numeroControl' => self::NC], $e->spent());
        }
    }

    public function testItIsNotRetriedUnderTheSameKeyBecauseTheServerReplaysIt(): void
    {
        $fake = new FakeTransport([self::signedNotSent(false), self::signedNotSent(false)]);
        try {
            $this->client($fake, ['maxRetries' => 3])->issue(['tipoDte' => '01', 'items' => []]);
            $this->fail('expected FactaError');
        } catch (FactaError $e) {
            $this->assertFalse($e->signedNotTransmitted()['confirmado'] ?? true);
        }
        $this->assertCount(1, $fake->requests);
        $this->assertSame([], $this->sleeps);
    }

    public function testTheCallerFinishesItWithTheRetryRouteNotANewEmission(): void
    {
        $sealed = ['estado' => 'sellado', 'via' => 'retransmision', 'codigoGeneracion' => self::CG, 'numeroControl' => self::NC, 'tipoDte' => '01', 'ambiente' => '00'];
        $fake = new FakeTransport([self::signedNotSent(), self::ok($sealed)]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);
        try {
            $client->issue(['tipoDte' => '01', 'items' => []]);
            $this->fail('expected FactaError');
        } catch (FactaError $e) {
            $pending = $e->signedNotTransmitted();
            $this->assertNotNull($pending);
            $result = $client->retryDocument($pending['codigoGeneracion']);
            $this->assertSame('sellado', $result['estado']);
        }
        $this->assertSame('/v1/dte/' . self::CG . '/reintentar', self::path($fake, 1));
    }

    public function testAnOrdinaryServiceUnavailableIsStillRetried(): void
    {
        $fake = new FakeTransport([self::error(503, 'service_unavailable'), self::ok(['estado' => 'sellado', 'codigoGeneracion' => self::CG, 'numeroControl' => self::NC])]);
        $result = $this->client($fake)->issue(['tipoDte' => '01', 'items' => []]);
        $this->assertSame('sellado', $result['estado']);
        $this->assertCount(2, $fake->requests);
        $this->assertSame($fake->requests[0]['headers']['Idempotency-Key'], $fake->requests[1]['headers']['Idempotency-Key']);
    }

    public function testOtherErrorsAreNotSignedNotTransmitted(): void
    {
        $this->assertFalse((new FactaError('service_unavailable', 'm', 503))->isSignedNotTransmitted());
        $this->assertNull((new FactaError('service_unavailable', 'm', 503))->signedNotTransmitted());
        $rejection = new FactaError('mh_rejected', 'm', 422, ['estado' => 'firmado_sin_transmitir', 'codigoGeneracion' => self::CG, 'numeroControl' => self::NC]);
        $this->assertFalse($rejection->isSignedNotTransmitted());
        $noCode = new FactaError('service_unavailable', 'm', 503, (object) ['estado' => 'firmado_sin_transmitir']);
        $this->assertFalse($noCode->isSignedNotTransmitted());
    }
}
