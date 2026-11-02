<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\NonTaxedLine;
use Facta\Api\Support\NonTaxedLines;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

/** Non-taxed charge/credit lines (`items[].noGravado`, Manual Funcional v2.0 §XII). */
final class NonTaxedLinesTest extends ClientCase
{
    private const SEALED = '{"estado":"sellado","codigoGeneracion":"8F6A3B2C-1D4E-4F50-9A7B-0C1D2E3F4A5B","numeroControl":"DTE-03-M001P001-000000000000001","tipoDte":"03","ambiente":"00","totales":{"totalPagar":51250,"totalNoGravado":50000}}';
    private const DRAFT = ['borradorId' => '11111111-1111-4111-8111-111111111111', 'estado' => 'pendiente', 'version' => 1];
    private const SALE = ['descripcion' => 'Intereses', 'cantidad' => 1, 'precioUni' => 1250, 'tipoVenta' => 'exenta'];
    private const CAPITAL = ['descripcion' => 'Capital', 'noGravado' => 50000, 'tipoItem' => 2, 'codigo' => 'CAP'];

    public function testTheLineTravelsUntouchedOnIssuePrepareAndDraftsForTheFiveTypes(): void
    {
        $prepared = '{"estado":"preparado","codigoGeneracion":"G","numeroControl":"N","tipoDte":"03","ambiente":"00","totales":{},"documento":{},"prepareToken":"t"}';
        $fake = new FakeTransport([
            self::ok(self::SEALED), self::ok(self::SEALED), self::ok(self::SEALED), self::ok(self::SEALED), self::ok(self::SEALED),
            self::ok($prepared), self::ok(self::DRAFT, 201), self::ok(self::DRAFT), self::ok(self::SEALED),
        ]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);
        $related = [['codigoGeneracion' => '8F6A3B2C-1D4E-4F50-9A7B-0C1D2E3F4A5B']];
        $client->issue(['tipoDte' => '01', 'items' => [['descripcion' => 'Cafe', 'cantidad' => 1, 'precioUni' => 2], new NonTaxedLine('Propina', 0.5, NonTaxedLine::SERVICIO)]]);
        $client->issue(['tipoDte' => '03', 'items' => [self::SALE, self::CAPITAL]]);
        $client->issue(['tipoDte' => '05', 'documentosRelacionados' => $related, 'items' => [['descripcion' => 'Devolucion', 'cantidad' => 1, 'precioUni' => 10], ['descripcion' => 'Abono de flete', 'noGravado' => -2.25, 'tipoItem' => 2]]]);
        $client->issue(['tipoDte' => '06', 'documentosRelacionados' => $related, 'items' => [['descripcion' => 'Ajuste', 'cantidad' => 1, 'precioUni' => 10], ['descripcion' => 'Cargo', 'noGravado' => 3, 'tipoItem' => 2]]]);
        $client->issue(['tipoDte' => '11', 'items' => [['descripcion' => 'Software', 'cantidad' => 1, 'precioUni' => 4800, 'tipoItem' => 2], ['descripcion' => 'Reembolso de courier', 'noGravado' => 35.4, 'tipoItem' => 2]]]);
        $client->prepare(['tipoDte' => '03', 'items' => [self::SALE, self::CAPITAL]]);
        $client->createDraft(['tipoDte' => '03', 'items' => [self::SALE, self::CAPITAL]]);
        $client->updateDraft(self::DRAFT['borradorId'], 1, ['tipoDte' => '03', 'items' => [self::SALE, self::CAPITAL]]);
        $client->issueDraft(self::DRAFT['borradorId'], 1, ['tipoDte' => '03', 'items' => [self::SALE, self::CAPITAL]]);

        $this->assertCount(9, $fake->requests);
        $this->assertSame(['descripcion' => 'Propina', 'noGravado' => 0.5, 'tipoItem' => 2], self::bodyOf($fake, 0)['items'][1]);
        $this->assertSame(['descripcion' => 'Abono de flete', 'noGravado' => -2.25, 'tipoItem' => 2], self::bodyOf($fake, 2)['items'][1]);
        $this->assertSame(['descripcion' => 'Reembolso de courier', 'noGravado' => 35.4, 'tipoItem' => 2], self::bodyOf($fake, 4)['items'][1]);
        foreach ([1, 5, 6, 7, 8] as $index) {
            $this->assertSame(self::CAPITAL, self::bodyOf($fake, $index)['items'][1]);
        }
    }

    public function testType14RefusesItLocallyAndNothingIsSent(): void
    {
        $fake = new FakeTransport([]);
        $client = $this->client($fake);
        $fse = ['tipoDte' => '14', 'items' => [['descripcion' => 'Servicio', 'cantidad' => 1, 'precioUni' => 50], self::CAPITAL]];
        $e = $this->local(function () use ($client, $fse): void {
            $client->issue($fse);
        }, FactaError::NON_TAXED_NOT_SUPPORTED);
        $this->assertSame(['01', '03', '05', '06', '11'], $e->getDetails()['supported']);
        foreach (['07', '15'] as $type) {
            $this->local(function () use ($fse, $type): void {
                NonTaxedLines::check(['tipoDte' => $type] + $fse);
            }, FactaError::NON_TAXED_NOT_SUPPORTED);
        }
        $this->local(function () use ($client, $fse): void {
            $client->createDraft($fse);
        }, FactaError::NON_TAXED_NOT_SUPPORTED);
        $this->assertCount(0, $fake->requests);
    }

    public function testOnlyNonTaxedLinesIsRefusedLocallyWithRequiresSaleLine(): void
    {
        $fake = new FakeTransport([]);
        $client = $this->client($fake);
        $this->local(function () use ($client): void {
            $client->issue(['tipoDte' => '03', 'items' => [self::CAPITAL]]);
        }, FactaError::NON_TAXED_REQUIRES_SALE_LINE);
        $this->local(function (): void {
            NonTaxedLines::check(['tipoDte' => '01', 'items' => [['descripcion' => 'Gratis', 'cantidad' => 1, 'precioUni' => 0], self::CAPITAL]]);
        }, FactaError::NON_TAXED_REQUIRES_SALE_LINE);
        // A productId line has no local price: the server decides.
        NonTaxedLines::check(['tipoDte' => '01', 'items' => [['productId' => 'p-1', 'cantidad' => 1], self::CAPITAL]]);
        $this->assertCount(0, $fake->requests);
    }

    public function testASaleFieldOnANonTaxedLineIsInvalidRequestNamingTheField(): void
    {
        $fields = ['cantidad' => 1, 'precioUni' => 5, 'montoDescu' => 1, 'tipoVenta' => 'exenta', 'productId' => 'p-1', 'productoId' => 'p-1', 'applyIncomeWithholding' => true];
        foreach ($fields as $field => $value) {
            $e = $this->local(function () use ($field, $value): void {
                NonTaxedLines::check(['tipoDte' => '03', 'items' => [self::SALE, self::CAPITAL + [$field => $value]]]);
            }, FactaError::INVALID_REQUEST);
            $this->assertSame('items[1].' . $field, $e->getDetails()['field']);
        }
        $line = self::CAPITAL;
        unset($line['tipoItem']);
        $e = $this->local(function () use ($line): void {
            NonTaxedLines::check(['tipoDte' => '01', 'items' => [self::SALE, $line]]);
        }, FactaError::INVALID_REQUEST);
        $this->assertSame('items[1].tipoItem', $e->getDetails()['field'], 'tipoItem is chosen, never defaulted');
        foreach ([NAN, 1.123456789, '5', 0] as $amount) {
            $e = $this->local(function () use ($amount): void {
                NonTaxedLines::check(['tipoDte' => '01', 'items' => [self::SALE, ['noGravado' => $amount] + self::CAPITAL]]);
            }, FactaError::INVALID_REQUEST);
            $this->assertSame('items[1].noGravado', $e->getDetails()['field']);
        }
        $this->local(function (): void {
            NonTaxedLines::check(['tipoDte' => '01', 'items' => [self::SALE, ['descripcion' => ' '] + self::CAPITAL]]);
        }, FactaError::INVALID_REQUEST);
        NonTaxedLines::check(['tipoDte' => '01', 'items' => [self::SALE, ['noGravado' => -0.12345678] + self::CAPITAL]]);
    }

    public function testARequestWithoutNonTaxedLinesIsNotInspected(): void
    {
        $fake = new FakeTransport([self::ok(self::SEALED)]);
        $this->client($fake)->issue(['tipoDte' => '14', 'items' => [['descripcion' => 'x', 'cantidad' => 1, 'precioUni' => 0]]]);
        NonTaxedLines::check(['items' => 'not a list']);
        NonTaxedLines::check(['tipoDte' => '14', 'items' => [['descripcion' => 'x', 'noGravado' => null, 'cantidad' => 1]]]);
        $this->assertCount(1, $fake->requests);
    }

    public function testServerSideRefusalsAreNamedCodesAndNeverRetried(): void
    {
        foreach ([FactaError::NON_TAXED_TOTAL_NEGATIVE, FactaError::NON_TAXED_REQUIRES_SALE_LINE, FactaError::NON_TAXED_NOT_SUPPORTED] as $code) {
            $fake = new FakeTransport([self::error(400, $code, 'm', '{"field":"items"}'), self::ok(self::SEALED)]);
            try {
                $this->client($fake, ['maxRetries' => 3])->issue(['tipoDte' => '01', 'items' => [self::SALE, ['noGravado' => -1] + self::CAPITAL]]);
                $this->fail('expected FactaError');
            } catch (FactaError $e) {
                $this->assertSame($code, $e->getErrorCode());
                $this->assertSame(400, $e->getStatus());
                $this->assertCount(1, $fake->requests);
            }
        }
    }

    public function testTotalsAndCapabilityPassThrough(): void
    {
        $fake = new FakeTransport([self::ok(['ok' => true, 'ambiente' => '00', 'capabilities' => ['noGravado' => true]]), self::ok(self::SEALED)]);
        $client = $this->client($fake);
        $this->assertTrue($client->status()['capabilities']['noGravado']);
        $result = $client->issue(['tipoDte' => '03', 'items' => [self::SALE, self::CAPITAL]]);
        $this->assertSame(50000, $result['totales']['totalNoGravado']);
        $this->assertSame(51250, $result['totales']['totalPagar']);
    }

    public function testTheValueObjectChecksTheShapeItCanCheck(): void
    {
        $this->assertSame(self::CAPITAL, (new NonTaxedLine('Capital', 50000, NonTaxedLine::SERVICIO, 'CAP'))->toArray());
        $this->assertSame('{"descripcion":"Abono","noGravado":-1.5,"tipoItem":1,"numeroDocumento":"DTE-03"}', json_encode(new NonTaxedLine('Abono', -1.5, NonTaxedLine::BIEN, null, 'DTE-03')));
        foreach ([['', 1, 2], ['x', 0, 2], ['x', '5', 2], ['x', INF, 2], ['x', 1.000000001, 2], ['x', 1, 4], ['x', 1, 0]] as $bad) {
            try {
                new NonTaxedLine($bad[0], $bad[1], $bad[2]);
                $this->fail('NonTaxedLine accepted ' . var_export($bad, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('NonTaxedLine:', $e->getMessage());
            }
        }
    }

    private function local(callable $call, string $code): FactaError
    {
        try {
            $call();
        } catch (FactaError $e) {
            $this->assertSame($code, $e->getErrorCode());
            $this->assertSame(0, $e->getStatus(), 'a local refusal never reached the server');
            $this->assertTrue($e->getDetails()['local']);
            return $e;
        }
        $this->fail('expected FactaError ' . $code);
    }
}
