<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

/**
 * Pins the public surface that 0.1.0 / 0.2.0-rc.1 shipped: the same calls, constants and shapes keep
 * working in later releases. Uses nothing added in rc.2.
 */
final class BackwardCompatTest extends ClientCase
{
    private const SEALED = '{"estado":"sellado","codigoGeneracion":"8F6A3B2C-1D4E-4F50-9A7B-0C1D2E3F4A5B","numeroControl":"N","tipoDte":"01","ambiente":"00"}';

    public function testOldConstructorOptionsAndConstants(): void
    {
        $this->assertSame('https://hcnvknpsbadplnfcflxx.supabase.co/functions/v1/api-v1', Facta::DEFAULT_BASE_URL);
        $facta = new Facta(['apiKey' => self::KEY, 'transport' => new FakeTransport([self::ok(self::SEALED)]), 'signKey' => self::SIGN_KEY, 'maxRetries' => 0, 'timeoutMs' => 5000]);
        $this->assertSame('00', $facta->environment());
    }

    public function testIssueReturnsTheOldShapeWithNoNewMembers(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $request = ['tipoDte' => '01', 'items' => [['descripcion' => 'x', 'cantidad' => 1, 'precioUni' => 1, 'codigo' => null, 'tipoItem' => 2, 'uniMedida' => 59]], 'formaPago' => '01', 'observaciones' => 'o'];
        $result = $this->client($fake)->issue($request, ['idempotencyKey' => 'k', 'timeoutMs' => 1000, 'headers' => ['X-Test' => '1']]);
        $this->assertSame(['estado', 'codigoGeneracion', 'numeroControl', 'tipoDte', 'ambiente'], array_keys($result));
        $this->assertSame(json_encode($request, JSON_UNESCAPED_SLASHES), self::request($fake)['body']);
    }

    public function testPrepareAndSignStillRoundTrip(): void
    {
        $prepared = '{"estado":"preparado","codigoGeneracion":"G","numeroControl":"N","tipoDte":"01","ambiente":"00","totales":{},"documento":{"a":{}},"prepareToken":"t"}';
        $fake = new FakeTransport([self::ok($prepared), self::ok(self::SEALED)]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);
        $p = $client->prepare(['tipoDte' => '01', 'items' => []]);
        $this->assertSame(['estado', 'codigoGeneracion', 'numeroControl', 'tipoDte', 'ambiente', 'totales', 'prepareToken', 'documento'], array_keys($p));
        $this->assertSame('sellado', $client->sign($p)['estado']);
    }

    public function testErrorsKeepTheirOldAccessors(): void
    {
        $fake = new FakeTransport([self::error(422, 'validation_failed', 'm', '{"issues":["x"]}')]);
        try {
            $this->client($fake)->issue(['tipoDte' => '01', 'items' => []]);
            $this->fail('expected');
        } catch (FactaError $e) {
            $this->assertSame([422, 'validation_failed', false, null], [$e->getStatus(), $e->getErrorCode(), $e->isRejection(), $e->getRetryAfterMs()]);
            $this->assertSame(['x'], $e->getDetails()->issues);
        }
        $old = new FactaError('internal_error', 'm', 500, null, 10);
        $this->assertSame(10, $old->getRetryAfterMs());
    }
}
