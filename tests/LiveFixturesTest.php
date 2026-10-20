<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Live\Fixtures;
use Facta\Api\Live\LiveError;
use PHPUnit\Framework\TestCase;

final class LiveFixturesTest extends TestCase
{
    private const CG = '8f6a3b2c-1d4e-4f50-9a7b-0c1d2e3f4a5b';

    /** @return array<string, mixed> */
    public static function fixtures(string $related = self::CG): array
    {
        $item = [['descripcion' => 'Facta API SDK integration test', 'cantidad' => 1, 'precioUni' => 0.01]];
        $address = ['departamento' => '06', 'municipio' => '14', 'complemento' => 'Calle 1'];
        $related = [['codigoGeneracion' => $related]];
        return [
            '03' => ['tipoDte' => '03', 'items' => $item, 'receptor' => [
                'nombre' => 'ACME', 'tipoDocumento' => '36', 'numDocumento' => '06140101001010', 'nrc' => '123456',
                'codActividad' => '62010', 'descActividad' => 'Software', 'direccion' => $address,
            ]],
            '05' => ['tipoDte' => '05', 'items' => $item, 'documentosRelacionados' => $related],
            '06' => ['tipoDte' => '06', 'items' => $item, 'documentosRelacionados' => $related],
            '11' => ['tipoDte' => '11', 'items' => $item, 'receptor' => [
                'nombre' => 'Foreign', 'numDocumento' => 'X1', 'codPais' => '9300', 'nombrePais' => 'USA', 'complemento' => 'Main St',
                'descActividad' => 'Retail', 'correo' => 'f@example.com',
            ], 'exportacion' => ['tipoItemExpor' => 1]],
            '14' => ['tipoDte' => '14', 'items' => $item, 'receptor' => ['nombre' => 'Persona', 'numDocumento' => '012345678', 'direccion' => $address]],
        ];
    }

    private function assertInvalid(string $raw, string $code = 'fixture_invalid'): void
    {
        try {
            Fixtures::parse($raw);
            $this->fail('LiveError expected');
        } catch (LiveError $e) {
            $this->assertSame($code, $e->getFailureCode());
            $this->assertStringNotContainsString('Facta API', $e->getMessage(), 'No payload in the message.');
        }
    }

    public function testNoSecretMeansNoFixtures(): void
    {
        $this->assertSame([], Fixtures::parse(null));
        $this->assertSame([], Fixtures::parse(''));
        $this->assertSame([], Fixtures::parse('  '));
    }

    public function testAFullMatrixParses(): void
    {
        $parsed = Fixtures::parse((string) json_encode(self::fixtures()));
        $this->assertCount(5, $parsed);
        $this->assertSame([self::CG], Fixtures::relatedGenerationCodes($parsed));
    }

    public function testMalformedFixturesAreRefusedWithoutEchoingThem(): void
    {
        $this->assertInvalid('{not json');
        $this->assertInvalid('[1,2]');
        $this->assertInvalid('{"01":{"tipoDte":"01"}}');
        $f = self::fixtures();
        $f['03']['items'][0]['descripcion'] = 'Something else';
        $this->assertInvalid((string) json_encode($f));
        $f = self::fixtures();
        $f['03']['items'][0]['precioUni'] = 5;
        $this->assertInvalid((string) json_encode($f));
        $f = self::fixtures();
        $f['03']['items'][0]['productId'] = 'p1';
        $this->assertInvalid((string) json_encode($f));
        $f = self::fixtures();
        $f['05']['documentosRelacionados'][0]['codigoGeneracion'] = 'not-a-uuid';
        $this->assertInvalid((string) json_encode($f));
        $f = self::fixtures();
        unset($f['03']['receptor']['nrc']);
        $this->assertInvalid((string) json_encode($f));
        $f = self::fixtures();
        unset($f['11']['exportacion']['tipoItemExpor']);
        $this->assertInvalid((string) json_encode($f));
        $f = self::fixtures();
        $f['14']['receptor']['direccion']['municipio'] = '';
        $this->assertInvalid((string) json_encode($f));
        $f = self::fixtures();
        $f['03']['tipoDte'] = '01';
        $this->assertInvalid((string) json_encode($f));
    }

    public function testTheMatrixStatesFollowThePermissionsAndTheFixtures(): void
    {
        $status = ['llave' => ['alcances' => ['issue'], 'tiposDte' => ['01', '03', '05', '06', '11']]];
        $fixtures = Fixtures::parse((string) json_encode(['03' => self::fixtures()['03'], '05' => self::fixtures()['05']]));
        $matrix = Fixtures::assess($status, $fixtures);
        $this->assertSame('Ready (baseline test only)', $matrix['01']);
        $this->assertSame('Ready (fixture configured)', $matrix['03']);
        $this->assertSame('Ready (fixture configured)', $matrix['05']);
        $this->assertSame('Not run (fixture missing)', $matrix['06']);
        $this->assertSame('Not run (fixture missing)', $matrix['11']);
        $this->assertSame('Blocked (permission unavailable)', $matrix['14']);

        $noIssue = Fixtures::assess(['llave' => ['alcances' => ['query'], 'tiposDte' => ['01']]], []);
        $this->assertSame(['Blocked (permission unavailable)'], array_values(array_unique($noIssue)));
    }

    public function testTheFullMatrixModeNeedsEveryTypeReady(): void
    {
        $ready = array_fill_keys(Fixtures::SUPPORTED_TYPES, 'Ready (fixture configured)');
        Fixtures::requireComplete($ready);
        $this->addToAssertionCount(1);

        $ready['06'] = 'Not run (fixture missing)';
        try {
            Fixtures::requireComplete($ready);
            $this->fail('LiveError expected');
        } catch (LiveError $e) {
            $this->assertSame('fixture_required', $e->getFailureCode());
        }
        $ready['06'] = 'Blocked (permission unavailable)';
        try {
            Fixtures::requireComplete($ready);
            $this->fail('LiveError expected');
        } catch (LiveError $e) {
            $this->assertSame('permission_missing', $e->getFailureCode());
        }
    }

    public function testRelatedDocumentsMustBeSealedTestDocuments(): void
    {
        $fixtures = Fixtures::parse((string) json_encode(self::fixtures()));
        Fixtures::assertRelatedTestDocuments($fixtures, [['codigoGeneracion' => strtoupper(self::CG), 'ambiente' => '00', 'estado' => 'sellado']]);
        $this->addToAssertionCount(1);

        foreach ([
            [],
            [['codigoGeneracion' => self::CG, 'ambiente' => '01', 'estado' => 'sellado']],
            [['codigoGeneracion' => self::CG, 'ambiente' => '00', 'estado' => 'invalidado']],
            [['codigoGeneracion' => '11111111-1111-4111-8111-111111111111', 'ambiente' => '00', 'estado' => 'sellado']],
        ] as $statuses) {
            try {
                Fixtures::assertRelatedTestDocuments($fixtures, $statuses);
                $this->fail('LiveError expected');
            } catch (LiveError $e) {
                $this->assertSame('related_document_invalid', $e->getFailureCode());
            }
        }
    }
}
