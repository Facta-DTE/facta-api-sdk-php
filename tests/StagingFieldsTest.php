<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use Facta\Api\Pago;
use Facta\Api\SaleType;
use Facta\Api\Support\Warnings;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

/** montoDescu, pagos, tipoVenta, VAT withholding/perception and the campo_desconocido warnings. */
final class StagingFieldsTest extends ClientCase
{
    private const SEALED = '{"estado":"sellado","codigoGeneracion":"8F6A3B2C-1D4E-4F50-9A7B-0C1D2E3F4A5B","numeroControl":"DTE-01-M001P001-000000000000001","tipoDte":"01","ambiente":"00"}';
    private const WARNING = '{"codigo":"campo_desconocido","mensaje":"El campo items[0].descuento no existe","campo":"items[0].descuento","sugerencia":"items[0].montoDescu"}';

    public function testNewRequestFieldsTravelUntouched(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $request = [
            'tipoDte' => '03',
            'receptor' => ['nombre' => 'Cliente'],
            'aplicarRetencionIva' => true,
            'aplicarPercepcionIva' => true,
            'items' => [
                ['descripcion' => 'x', 'cantidad' => 1, 'precioUni' => 10, 'montoDescu' => 2.5, 'tipoVenta' => SaleType::EXENTA],
                ['descripcion' => 'y', 'cantidad' => 1, 'precioUni' => 5, 'tipoVenta' => SaleType::NO_SUJETA],
            ],
            'pagos' => [new Pago('01', 40), new Pago('02', 60.5, 'VOUCHER-0042'), ['codigo' => '05', 'montoPago' => 1]],
        ];
        $this->client($fake)->issue($request);
        $sent = json_decode((string) self::request($fake)['body'], true);
        $this->assertSame(2.5, $sent['items'][0]['montoDescu']);
        $this->assertSame('exenta', $sent['items'][0]['tipoVenta']);
        $this->assertSame('no_sujeta', $sent['items'][1]['tipoVenta']);
        $this->assertTrue($sent['aplicarRetencionIva']);
        $this->assertTrue($sent['aplicarPercepcionIva']);
        $this->assertSame(
            [['codigo' => '01', 'montoPago' => 40], ['codigo' => '02', 'montoPago' => 60.5, 'referencia' => 'VOUCHER-0042'], ['codigo' => '05', 'montoPago' => 1]],
            $sent['pagos']
        );
    }

    public function testPagoChecksTheShapeItCanCheck(): void
    {
        $this->assertSame(['codigo' => '01', 'montoPago' => 5], (new Pago('01', 5))->toArray());
        foreach ([['', 1], ['01', 0], ['01', -1], ['01', '5']] as $bad) {
            try {
                new Pago($bad[0], $bad[1]);
                $this->fail('Pago accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Pago:', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new Pago('01', 1, str_repeat('x', 51));
    }

    public function testIssueSurfacesUnknownFieldsAndTheHeader(): void
    {
        $body = substr(self::SEALED, 0, -1) . ',"advertencias":[' . self::WARNING . ']}';
        $fake = new FakeTransport([FakeTransport::json(200, $body, ['Facta-Warning' => 'campo_desconocido; campos=1'])]);
        $result = $this->client($fake)->issue(['tipoDte' => '01', 'items' => []]);
        $this->assertSame('items[0].montoDescu', $result['unknownFields'][0]['sugerencia']);
        $this->assertSame('items[0].descuento', $result['unknownFields'][0]['campo']);
        $this->assertSame(['code' => 'campo_desconocido', 'count' => 1], $result['factaWarning']);
        $this->assertSame('campo_desconocido', $result['advertencias'][0]['codigo']);
    }

    public function testCleanResultsAreUntouched(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $result = $this->client($fake)->issue(['tipoDte' => '01', 'items' => []]);
        $this->assertArrayNotHasKey('unknownFields', $result);
        $this->assertArrayNotHasKey('factaWarning', $result);
    }

    public function testPrepareSurfacesWarnings(): void
    {
        $body = '{"estado":"preparado","codigoGeneracion":"G","numeroControl":"N","tipoDte":"01","ambiente":"00","totales":{},"documento":{"a":{}},"prepareToken":"t","advertencias":[' . self::WARNING . ']}';
        $fake = new FakeTransport([FakeTransport::json(200, $body, ['facta-warning' => 'campo_desconocido; campos=1'])]);
        $prepared = $this->client($fake)->prepare(['tipoDte' => '01', 'items' => []]);
        $this->assertCount(1, $prepared['unknownFields']);
        $this->assertSame(1, $prepared['factaWarning']['count']);
        $this->assertInstanceOf(\stdClass::class, $prepared['documento']);
    }

    public function testErrorsExposeTheWarnings(): void
    {
        $details = '{"issues":["x"],"advertencias":[' . self::WARNING . ']}';
        $fake = new FakeTransport([FakeTransport::json(422, '{"error":{"code":"validation_failed","message":"m","details":' . $details . '}}', ['Facta-Warning' => 'campo_desconocido; campos=1'])]);
        try {
            $this->client($fake)->issue(['tipoDte' => '01', 'items' => []]);
            $this->fail('expected FactaError');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::VALIDATION_FAILED, $e->getErrorCode());
            $this->assertSame('items[0].descuento', $e->getUnknownFields()[0]['campo']);
            $this->assertSame(['code' => 'campo_desconocido', 'count' => 1], $e->getFactaWarning());
        }
        $plain = new FactaError('not_found', 'm', 404);
        $this->assertSame([], $plain->getUnknownFields());
        $this->assertNull($plain->getFactaWarning());
    }

    public function testStagingBaseUrlIsOptInAndTheDefaultStaysProduction(): void
    {
        $this->assertSame('https://eobxzotnqzgtpuqvmpkc.supabase.co/functions/v1/api-v1', Facta::STAGING_BASE_URL);
        $this->assertSame('https://hcnvknpsbadplnfcflxx.supabase.co/functions/v1/api-v1', Facta::DEFAULT_BASE_URL);
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $this->client($fake, ['baseUrl' => Facta::STAGING_BASE_URL])->issue(['tipoDte' => '01', 'items' => []]);
        $this->assertStringStartsWith(Facta::STAGING_BASE_URL . '/v1/dte', self::request($fake)['url']);
    }

    public function testHeaderParser(): void
    {
        $this->assertNull(Warnings::parseHeader(null));
        $this->assertNull(Warnings::parseHeader(''));
        $this->assertSame(['code' => 'campo_desconocido', 'count' => null], Warnings::parseHeader('campo_desconocido'));
        $this->assertSame(['code' => 'campo_desconocido', 'count' => 12], Warnings::parseHeader('campo_desconocido; campos=12'));
    }
}
