<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\DescuentoGlobal;
use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

/** `descuentoGlobal` (01, 03, 11, 14) and the FSE per-line income withholding: plain request fields the server owns. */
final class GlobalDiscountAndFseWithholdingTest extends ClientCase
{
    private const SEALED = '{"estado":"sellado","codigoGeneracion":"8F6A3B2C-1D4E-4F50-9A7B-0C1D2E3F4A5B","numeroControl":"DTE-01-M001P001-000000000000001","tipoDte":"01","ambiente":"00"}';
    private const DRAFT = ['borradorId' => '11111111-1111-4111-8111-111111111111', 'estado' => 'pendiente', 'version' => 1];

    public function testTheGlobalDiscountTravelsUntouchedOnIssuePrepareAndDrafts(): void
    {
        $prepared = '{"estado":"preparado","codigoGeneracion":"G","numeroControl":"N","tipoDte":"03","ambiente":"00","totales":{},"documento":{},"prepareToken":"t"}';
        $fake = new FakeTransport([self::ok(self::SEALED), self::ok($prepared), self::ok(self::DRAFT, 201), self::ok(self::DRAFT), self::ok(self::SEALED)]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);
        $items = [['descripcion' => 'Cafe', 'cantidad' => 10, 'precioUni' => 2]];

        $client->issue(['tipoDte' => '01', 'items' => $items, 'descuentoGlobal' => DescuentoGlobal::porcentaje(10)]);
        $client->prepare(['tipoDte' => '03', 'items' => $items, 'descuentoGlobal' => DescuentoGlobal::monto(12.5)]);
        $client->createDraft(['tipoDte' => '11', 'items' => $items, 'descuentoGlobal' => ['modo' => 'monto', 'valor' => 3]]);
        $client->updateDraft(self::DRAFT['borradorId'], 1, ['tipoDte' => '14', 'items' => $items, 'descuentoGlobal' => DescuentoGlobal::porcentaje(5)]);
        $client->issueDraft(self::DRAFT['borradorId'], 1, ['tipoDte' => '01', 'items' => $items, 'descuentoGlobal' => DescuentoGlobal::porcentaje(10)]);

        $this->assertSame(['modo' => 'porcentaje', 'valor' => 10], self::bodyOf($fake, 0)['descuentoGlobal']);
        $this->assertSame(['modo' => 'monto', 'valor' => 12.5], self::bodyOf($fake, 1)['descuentoGlobal']);
        $this->assertSame(['modo' => 'monto', 'valor' => 3], self::bodyOf($fake, 2)['descuentoGlobal']);
        $this->assertSame(['modo' => 'porcentaje', 'valor' => 5], self::bodyOf($fake, 3)['descuentoGlobal']);
        $this->assertSame(['modo' => 'porcentaje', 'valor' => 10], self::bodyOf($fake, 4)['descuentoGlobal']);
        $this->assertSame(1, self::bodyOf($fake, 4)['version']);
    }

    public function testDescuentoGlobalChecksTheShapeItCanCheck(): void
    {
        $this->assertSame(['modo' => 'monto', 'valor' => 12.5], DescuentoGlobal::monto(12.5)->toArray());
        $this->assertSame('{"modo":"porcentaje","valor":100}', json_encode(DescuentoGlobal::porcentaje(100)));
        $this->assertSame(DescuentoGlobal::PORCENTAJE, (new DescuentoGlobal('porcentaje', 1))->getModo());
        foreach ([['amount', 1], ['monto', 0], ['monto', -1], ['monto', '5'], ['porcentaje', 100.01], ['monto', INF]] as $bad) {
            try {
                new DescuentoGlobal($bad[0], $bad[1]);
                $this->fail('DescuentoGlobal accepted ' . var_export($bad, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('DescuentoGlobal:', $e->getMessage());
            }
        }
    }

    public function testANoteWithAGlobalDiscountIsTheServersRefusal(): void
    {
        $fake = new FakeTransport([self::error(400, 'global_discount_not_supported')]);
        try {
            $this->client($fake)->issue(['tipoDte' => '05', 'items' => [], 'descuentoGlobal' => DescuentoGlobal::monto(1)]);
            $this->fail('expected FactaError');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::GLOBAL_DISCOUNT_NOT_SUPPORTED, $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
        }
        $this->assertCount(1, $fake->requests, 'A 400 is final: never retried.');
    }

    public function testFsePerLineWithholdingTravelsUntouched(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $this->client($fake)->issue([
            'tipoDte' => '14',
            'receptor' => ['nombre' => 'Proveedor'],
            'incomeWithholding' => ['mode' => 'per_line'],
            'items' => [
                ['descripcion' => 'Compra de bienes', 'cantidad' => 1, 'precioUni' => 100, 'applyIncomeWithholding' => false],
                ['descripcion' => 'Servicio', 'cantidad' => 1, 'precioUni' => 50, 'applyIncomeWithholding' => true],
            ],
        ]);
        $sent = self::bodyOf($fake);
        $this->assertSame(['mode' => 'per_line'], $sent['incomeWithholding']);
        $this->assertSame([false, true], array_column($sent['items'], 'applyIncomeWithholding'));
        $this->assertArrayNotHasKey('aplicarReteRenta', $sent);
    }

    public function testEveryFseWithholdingRefusalIsAFinalNamedCode(): void
    {
        $codes = [
            FactaError::FSE_WITHHOLDING_MODE_INVALID,
            FactaError::FSE_WITHHOLDING_LEGACY_CONFLICT,
            FactaError::FSE_WITHHOLDING_NOT_SUPPORTED,
            FactaError::FSE_WITHHOLDING_SELECTION_REQUIRED,
            FactaError::FSE_WITHHOLDING_SELECTION_INVALID,
            FactaError::FSE_WITHHOLDING_MODE_REQUIRED,
        ];
        $fake = new FakeTransport(array_map(static function (string $code) {
            return self::error(400, $code);
        }, $codes));
        $client = $this->client($fake);
        foreach ($codes as $code) {
            try {
                $client->issue(['tipoDte' => '14', 'items' => []]);
                $this->fail('expected ' . $code);
            } catch (FactaError $e) {
                $this->assertSame($code, $e->getErrorCode());
            }
        }
        $this->assertCount(count($codes), $fake->requests);
        $this->assertSame([], $this->sleeps);
    }

    public function testStatusPassesTheCapabilitiesThrough(): void
    {
        $fake = new FakeTransport([self::ok(['ok' => true, 'ambiente' => '00', 'capabilities' => ['fseIncomeWithholdingPerLine' => true, 'descuentoGlobal' => true]])]);
        $status = $this->client($fake)->status();
        $this->assertTrue($status['capabilities']['descuentoGlobal']);
        $this->assertTrue($status['capabilities']['fseIncomeWithholdingPerLine']);
    }
}
