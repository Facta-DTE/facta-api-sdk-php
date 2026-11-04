<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\BienTitulo;
use Facta\Api\FactaError;
use Facta\Api\RetentionCode;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

/**
 * Remission note (04) and retention receipt (07): the SDK passes the request through untouched, computes
 * nothing, and surfaces `campo_ignorado` warnings and the `retention_agent_required` refusal.
 */
final class RemissionRetentionTest extends ClientCase
{
    private const DRAFT_ID = '11111111-1111-4111-8111-111111111111';

    /** @return array<string, mixed> */
    private static function remission(): array
    {
        return [
            'tipoDte' => '04',
            'receptor' => [
                'nombre' => 'Comercial Ejemplo, S.A. de C.V.', 'tipoDocumento' => '36', 'numDocumento' => '05110606161016',
                'codActividad' => '46510', 'descActividad' => 'Venta al por mayor', 'bienTitulo' => '04',
            ],
            'items' => [['descripcion' => 'Caja de repuestos', 'cantidad' => 2, 'precioUni' => 25, 'tipoItem' => 1]],
            'descuentoGlobal' => ['modo' => 'porcentaje', 'valor' => 10],
            'documentosRelacionados' => [['codigoGeneracion' => self::CG]],
        ];
    }

    /** @return array<string, mixed> */
    private static function retention(): array
    {
        return [
            'tipoDte' => '07',
            'receptor' => ['nombre' => 'ZD, S.A. de C.V.', 'tipoDocumento' => '36', 'numDocumento' => '05110606161016', 'nrc' => '2513113', 'codActividad' => '46510'],
            'items' => [
                ['tipoDte' => '03', 'numeroDocumento' => '1BC3E8A1-1111-4111-8111-11111111ABCD', 'fechaEmision' => '2026-10-05', 'montoSujetoGrav' => 1000, 'codigoRetencionMH' => '22'],
                ['montoSujetoGrav' => 250.5, 'codigoRetencionMH' => 'C9', 'ivaRetenido' => 2.5, 'descripcion' => 'Compra en papel'],
            ],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function requests(): array
    {
        return ['remission 04' => [self::remission()], 'retention 07' => [self::retention()]];
    }

    /**
     * @dataProvider requests
     * @param array<string, mixed> $request
     */
    public function testIssueSendsTheRequestExactlyAsGiven(array $request): void
    {
        $fake = new FakeTransport([self::ok(['estado' => 'sellado', 'tipoDte' => $request['tipoDte']])]);
        $this->client($fake)->issue($request, ['idempotencyKey' => 'k-1']);

        $this->assertSame('/v1/dte', self::path($fake));
        $this->assertSame(json_encode($request), self::request($fake)['body']);
    }

    /**
     * @dataProvider requests
     * @param array<string, mixed> $request
     */
    public function testPrepareAndDraftsSendTheRequestExactlyAsGiven(array $request): void
    {
        $draft = ['borradorId' => self::DRAFT_ID, 'estado' => 'pendiente', 'version' => 1];
        $fake = new FakeTransport([
            self::ok(['estado' => 'preparado', 'documento' => ['x' => 1], 'prepareToken' => 't']),
            self::ok($draft, 201),
            self::ok($draft),
        ]);
        $client = $this->client($fake);
        $client->prepare($request);
        $client->createDraft($request + ['referencia' => 'r-1'], ['idempotencyKey' => 'r-1']);
        $client->updateDraft(self::DRAFT_ID, 1, $request);

        $this->assertSame(json_encode($request), self::request($fake, 0)['body']);
        $this->assertSame(json_encode($request + ['referencia' => 'r-1']), self::request($fake, 1)['body']);
        $this->assertSame(json_encode($request), self::request($fake, 2)['body']);
    }

    public function testIssueDraftSendsThe07AndTheVersion(): void
    {
        $fake = new FakeTransport([self::ok(['estado' => 'sellado', 'borradorId' => self::DRAFT_ID])]);
        $this->client($fake)->issueDraft(self::DRAFT_ID, 3, self::retention(), ['idempotencyKey' => 'k-2']);
        $this->assertSame(json_encode(self::retention() + ['version' => 3]), self::request($fake)['body']);
    }

    public function testIgnoredFieldWarningsReachIssuePrepareAndDrafts(): void
    {
        $warnings = [
            ['codigo' => 'campo_ignorado', 'campo' => 'pagos', 'mensaje' => 'El 04 no liquida nada.'],
            ['codigo' => 'campo_ignorado', 'campo' => 'formaPago', 'mensaje' => 'El 04 no liquida nada.'],
            ['codigo' => 'campo_desconocido', 'campo' => 'foo', 'mensaje' => 'm'],
        ];
        $draft = ['borradorId' => self::DRAFT_ID, 'estado' => 'pendiente', 'version' => 1, 'advertencias' => $warnings];
        $fake = new FakeTransport([
            self::ok(['estado' => 'sellado', 'advertencias' => $warnings]),
            self::ok(['estado' => 'preparado', 'documento' => ['x' => 1], 'prepareToken' => 't', 'advertencias' => $warnings]),
            self::ok($draft, 201),
            self::ok($draft),
        ]);
        $client = $this->client($fake);
        $request = self::remission() + ['pagos' => [['codigo' => '01', 'montoPago' => 45]], 'formaPago' => '01'];

        $results = [
            $client->issue($request),
            $client->prepare($request),
            $client->createDraft($request),
            $client->updateDraft(self::DRAFT_ID, 1, $request),
        ];
        foreach ($results as $result) {
            $this->assertSame(['pagos', 'formaPago'], array_column($result['ignoredFields'], 'campo'));
            $this->assertSame('El 04 no liquida nada.', $result['ignoredFields'][0]['mensaje']);
            $this->assertCount(1, $result['unknownFields']);
            $this->assertSame('foo', $result['unknownFields'][0]['campo']);
        }
    }

    public function testNoIgnoredFieldsKeyWithoutWarnings(): void
    {
        $fake = new FakeTransport([self::ok(['estado' => 'sellado', 'advertencias' => []])]);
        $this->assertArrayNotHasKey('ignoredFields', $this->client($fake)->issue(self::remission()));
    }

    public function testRetentionAgentRequiredIs403FinalAndNotRetried(): void
    {
        $this->assertSame('retention_agent_required', FactaError::RETENTION_AGENT_REQUIRED);
        $fake = new FakeTransport([self::error(403, 'retention_agent_required', 'No es agente de retención.'), self::ok(['estado' => 'sellado'])]);
        try {
            $this->client($fake)->issue(self::retention(), ['idempotencyKey' => 'k-3']);
            $this->fail('A FactaError was expected.');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::RETENTION_AGENT_REQUIRED, $e->getErrorCode());
            $this->assertSame(403, $e->getCode());
        }
        $this->assertCount(1, $fake->requests);
        $this->assertSame([], $this->sleeps);
    }

    public function testErrorCarriesIgnoredFieldWarnings(): void
    {
        $details = '{"advertencias":[{"codigo":"campo_ignorado","campo":"plazo","mensaje":"m"}]}';
        $fake = new FakeTransport([self::error(422, 'validation_failed', 'm', $details)]);
        try {
            $this->client($fake)->issue(self::remission());
            $this->fail('A FactaError was expected.');
        } catch (FactaError $e) {
            $this->assertSame('plazo', $e->getIgnoredFields()[0]['campo']);
            $this->assertSame([], $e->getUnknownFields());
        }
    }

    public function testCatalogueConstantsMatchTheWireValues(): void
    {
        $this->assertSame(['01', '02', '03', '04', '05'], [BienTitulo::DEPOSITO, BienTitulo::PROPIEDAD, BienTitulo::CONSIGNACION, BienTitulo::TRASLADO, BienTitulo::OTROS]);
        $this->assertSame(['22', 'C4', 'C9'], [RetentionCode::IVA_1_PERCENT, RetentionCode::IVA_13_PERCENT, RetentionCode::OTHER]);
    }
}
