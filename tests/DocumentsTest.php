<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use Facta\Api\Http\Response;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

final class DocumentsTest extends ClientCase
{
    public function testGetDocumentStatusEncodesTheCodeAndSendsNoSecrets(): void
    {
        $fake = new FakeTransport([self::ok('{"estado":"rechazado","codigoGeneracion":"X","observaciones":["a"]}')]);
        $status = $this->client($fake, ['signKey' => self::SIGN_KEY])->getDocumentStatus('A B/C');

        $this->assertSame('rechazado', $status['estado'], 'The estado discriminant passes through untouched.');
        $this->assertSame('GET', self::request($fake)['method']);
        $this->assertSame('/v1/dte/A%20B%2FC', self::path($fake));
        $this->assertArrayNotHasKey('X-Facta-Sign-Key', self::request($fake)['headers']);
        $this->assertArrayNotHasKey('Idempotency-Key', self::request($fake)['headers']);
        $this->assertNull(self::request($fake)['body']);
    }

    public function testGetDocumentStatusMapsNotFound(): void
    {
        $fake = new FakeTransport([self::error(404, 'not_found', 'No such document')]);
        try {
            $this->client($fake)->getDocumentStatus(self::CG);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::NOT_FOUND, $e->getErrorCode());
            $this->assertSame(404, $e->getStatus());
        }
    }

    public function testListDocumentsBuildsTheQueryAndKeepsTheCursor(): void
    {
        $fake = new FakeTransport([
            self::ok('{"documentos":[{"codigoGeneracion":"A","estado":"sellado"}],"siguiente":"cur sor/1"}'),
            self::ok('{"documentos":[],"siguiente":null}'),
        ]);
        $client = $this->client($fake);
        $page = $client->listDocuments(['tipoDte' => '01', 'limit' => 20, 'estado' => 'sellado', 'desde' => '2026-10-01']);

        $this->assertSame('/v1/dte?tipoDte=01&limit=20&estado=sellado&desde=2026-10-01', self::path($fake));
        $this->assertSame('cur sor/1', $page['siguiente']);
        $this->assertSame('A', $page['documentos'][0]['codigoGeneracion']);

        $last = $client->listDocuments(['cursor' => $page['siguiente'], 'include' => ['dte']]);
        $this->assertSame('/v1/dte?cursor=cur%20sor%2F1&include=dte', self::path($fake, 1));
        $this->assertNull($last['siguiente']);
        $this->assertSame([], $last['documentos']);
    }

    public function testListDocumentsWithNoFiltersHasNoQueryAndRefusesUnknownFilters(): void
    {
        $fake = new FakeTransport([self::ok('{"documentos":[],"siguiente":null}')]);
        $client = $this->client($fake);
        $client->listDocuments();
        $this->assertSame('/v1/dte', self::path($fake));
        $this->expectException(\InvalidArgumentException::class);
        $client->listDocuments(['tipoDTE' => '01']);
    }

    public function testDownloadReturnsTheExactBytesAndTheFormatHeaders(): void
    {
        $bytes = "{\n \"a\": 1.0,  \"b\":\"\u{00f1}\"\n}\r\n\x00\xff";
        $fake = new FakeTransport([new Response(200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="DTE-01-X.json"',
            'X-Facta-Json-Format' => 'archivo-dte',
            'X-Facta-Storage-Source' => 'holding',
        ], $bytes)]);
        $file = $this->client($fake)->downloadDocument(self::CG);

        $this->assertSame($bytes, $file['bytes'], 'Bytes are never decoded or re-serialized.');
        $this->assertSame('application/json', $file['contentType']);
        $this->assertSame('DTE-01-X.json', $file['filename']);
        $this->assertSame('archivo-dte', $file['jsonFormat']);
        $this->assertSame('holding', $file['storageSource']);
        $this->assertSame(self::CG, $file['codigoGeneracion']);
        $this->assertSame('json', $file['kind']);
        $this->assertSame('/v1/dte/' . self::CG . '/file?kind=json', self::path($fake));
        $this->assertSame('*/*', self::request($fake)['headers']['Accept']);
    }

    public function testDownloadPdfAndTicketOptions(): void
    {
        $pdf = "%PDF-1.7\n\x00\x01";
        $fake = new FakeTransport([
            new Response(200, ['content-type' => 'application/pdf'], $pdf),
            new Response(200, ['content-type' => 'application/pdf', 'x-facta-storage-source' => 'managed'], $pdf),
            new Response(200, ['content-type' => 'application/pdf'], $pdf),
            new Response(200, ['content-type' => 'application/json', 'x-facta-json-format' => 'raw'], '{}'),
        ]);
        $client = $this->client($fake);

        $first = $client->downloadDocument(self::CG, 'pdf');
        $this->assertSame($pdf, $first['bytes']);
        $this->assertArrayNotHasKey('jsonFormat', $first);
        $this->assertArrayNotHasKey('storageSource', $first);
        $this->assertNull($first['filename']);

        $managed = $client->downloadDocument(self::CG, 'pdf', ['source' => 'managed']);
        $this->assertSame('managed', $managed['storageSource']);
        $this->assertSame('/v1/dte/' . self::CG . '/file?kind=pdf&source=managed', self::path($fake, 1));

        $ticket = $client->downloadDocument(self::CG, 'ticket', ['paperWidthMm' => 58]);
        $this->assertSame(58, $ticket['paperWidthMm']);
        $this->assertSame('/v1/dte/' . self::CG . '/file?kind=ticket&paperWidthMm=58', self::path($fake, 2));

        $raw = $client->downloadDocument(self::CG, 'json', ['raw' => true]);
        $this->assertSame('raw', $raw['jsonFormat']);
        $this->assertSame('/v1/dte/' . self::CG . '/file?kind=json&raw=true', self::path($fake, 3));
    }

    public function testDownloadRefusesCombinationsTheServerWouldRefuse(): void
    {
        $client = $this->client(new FakeTransport([]));
        foreach ([
            ['pdf', ['raw' => true]],
            ['json', ['paperWidthMm' => 58]],
            ['ticket', ['source' => 'managed']],
            ['ticket', ['paperWidthMm' => 39]],
            ['ticket', ['paperWidthMm' => 121]],
            ['json', ['source' => 'other']],
            ['docx', []],
        ] as [$kind, $options]) {
            try {
                $client->downloadDocument(self::CG, $kind, $options);
                $this->fail('InvalidArgumentException expected for ' . $kind . json_encode($options));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testRequiringTheManagedCopyFailsWhenTheServerAnswersFromElsewhere(): void
    {
        $fake = new FakeTransport([
            new Response(200, ['content-type' => 'application/pdf', 'x-facta-storage-source' => 'holding'], 'x'),
            new Response(200, ['content-type' => 'application/pdf'], 'x'),
        ]);
        $client = $this->client($fake);
        try {
            $client->downloadDocument(self::CG, 'pdf', ['source' => 'managed']);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::STORAGE_CONTRACT_INVALID, $e->getErrorCode());
        }
        try {
            $client->downloadDocument(self::CG, 'pdf', ['source' => 'managed']);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::STORAGE_UNSUPPORTED, $e->getErrorCode(), 'A server that predates source reporting.');
        }
    }

    public function testDownloadErrorsAreDecodedFromTheJsonBody(): void
    {
        $fake = new FakeTransport([
            self::error(409, 'not_sealed', 'No seal yet'),
            new Response(502, ['content-type' => 'text/html'], '<html>bad gateway</html>'),
        ]);
        $client = $this->client($fake);
        try {
            $client->downloadDocument(self::CG);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::NOT_SEALED, $e->getErrorCode());
        }
        try {
            $client->downloadDocument(self::CG);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::INTERNAL_ERROR, $e->getErrorCode());
            $this->assertSame(502, $e->getStatus());
        }
    }

    public function testDownloadRetriesAnInFlightAnswerAndKeepsTheBytes(): void
    {
        $fake = new FakeTransport([
            self::error(503, 'mh_unreachable'),
            new Response(200, ['content-type' => 'application/pdf'], "%PDF\x00"),
        ]);
        $file = $this->client($fake)->downloadDocument(self::CG, 'pdf');
        $this->assertSame("%PDF\x00", $file['bytes']);
        $this->assertCount(2, $fake->requests);
    }

    public function testInvalidateSendsTheSigningKeyAndTheCallersKey(): void
    {
        $fake = new FakeTransport([self::ok('{"estado":"invalidado","codigoGeneracion":"' . self::CG . '","evento":{"selloRecibido":"S"}}')]);
        $request = [
            'tipoAnulacion' => 2,
            'motivo' => 'Error',
            'responsable' => ['nombre' => 'Ana', 'tipoDocumento' => '13', 'numDocumento' => '012345678'],
            'solicita' => ['nombre' => 'Luis', 'tipoDocumento' => '13', 'numDocumento' => '087654321'],
        ];
        $result = $this->client($fake, ['signKey' => self::SIGN_KEY])->invalidate(self::CG, $request, ['idempotencyKey' => 'inv-1']);

        $this->assertSame('invalidado', $result['estado']);
        $sent = self::request($fake);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('/v1/dte/' . self::CG . '/invalidate', self::path($fake));
        $this->assertSame(self::SIGN_KEY, $sent['headers']['X-Facta-Sign-Key']);
        $this->assertSame('inv-1', $sent['headers']['Idempotency-Key']);
        $this->assertSame($request, self::bodyOf($fake));
    }

    public function testRegisterReturnValidatesBeforeSending(): void
    {
        $fake = new FakeTransport([self::ok('{"estado":"sellado"}')]);
        $client = $this->client($fake, ['signKey' => self::SIGN_KEY]);

        foreach ([
            [],
            ['items' => []],
            ['items' => [['linea' => 0, 'cantidad' => 1]]],
            ['items' => [['linea' => 1]]],
            ['items' => [['linea' => 1, 'cantidad' => 1, 'noGravado' => 2]]],
            ['items' => [['linea' => 1, 'cantidad' => 0]]],
            ['items' => [['linea' => 1, 'noGravado' => 0]]],
            ['items' => [['linea' => 1, 'cantidad' => 1]], 'fechaEvento' => '20/10/2026'],
        ] as $bad) {
            try {
                $client->registerReturn(self::CG, $bad);
                $this->fail('InvalidArgumentException expected for ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame([], $fake->requests, 'A bad return request fails before any request.');

        $request = ['items' => [['linea' => 1, 'cantidad' => 2], ['linea' => 2, 'noGravado' => -1.5]], 'fechaEvento' => '2026-10-20'];
        $client->registerReturn(self::CG, $request, ['idempotencyKey' => 'ret-1']);
        $this->assertSame('/v1/dte/' . self::CG . '/return', self::path($fake));
        $this->assertSame(self::SIGN_KEY, self::request($fake)['headers']['X-Facta-Sign-Key']);
        $this->assertSame('ret-1', self::request($fake)['headers']['Idempotency-Key']);
        $this->assertSame($request, self::bodyOf($fake));
    }

    public function testRegisterReturnA202AnswerIsNotAnError(): void
    {
        $fake = new FakeTransport([self::ok('{"estado":"firmado","codigoGeneracion":"' . self::CG . '"}', 202)]);
        $result = $this->client($fake)->registerReturn(self::CG, ['items' => [['linea' => 1, 'cantidad' => 1]]]);
        $this->assertSame('firmado', $result['estado']);
    }

    public function testListHoldingAndContract(): void
    {
        $fake = new FakeTransport([self::ok('{"documentos":[]}'), self::ok('{"openapi":"3.0.3","paths":{}}')]);
        $client = $this->client($fake);
        $this->assertSame(['documentos' => []], $client->listHolding(10));
        $this->assertSame('/v1/dte/holding?limit=10', self::path($fake, 0));
        $contract = $client->getContract();
        $this->assertSame('3.0.3', $contract['openapi']);
        $this->assertSame('/v1/openapi.json', self::path($fake, 1));
    }

    public function testTimeoutOptionIsPerCall(): void
    {
        $fake = new FakeTransport([self::ok('{}')]);
        $this->client($fake)->getDocumentStatus(self::CG, ['timeoutMs' => 1500]);
        $this->assertSame(1500, self::request($fake)['timeoutMs']);
        $this->assertSame(Facta::DEFAULT_BASE_URL . '/v1/dte/' . self::CG, self::request($fake)['url']);
    }
}
