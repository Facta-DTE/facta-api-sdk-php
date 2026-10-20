<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

final class CatalogTest extends ClientCase
{
    private const CUSTOMER = ['id' => 'c1', 'nombre' => 'Ana', 'numDocumento' => '012345678', 'activo' => true];

    public function testAPlainCatalogIsReadThroughTheApiWithBothSpellings(): void
    {
        $fake = new FakeTransport([
            self::statusWithCatalog('readable', true),
            self::ok(['clientes' => [self::CUSTOMER, ['id' => 'c2', 'nombre' => 'Off', 'activo' => false]], 'siguiente' => null]),
        ]);
        $rows = $this->client($fake)->listCustomers();

        $this->assertSame('/v1/status', self::path($fake, 0));
        $this->assertSame('/v1/customers?limite=200', self::path($fake, 1));
        $this->assertCount(1, $rows, 'Deactivated customers are left out by default.');
        $this->assertSame('Ana', $rows[0]['nombre']);
        $this->assertSame('Ana', $rows[0]['name'], 'Every record carries both spellings.');
        $this->assertSame('012345678', $rows[0]['doc_number']);
        $this->assertTrue($rows[0]['active']);
        $this->assertNull($rows[0]['nrc']);
        $this->assertNull($rows[0]['creadoEn']);
    }

    public function testListFollowsTheCursorAndCanIncludeInactiveRecords(): void
    {
        $fake = new FakeTransport([
            self::statusWithCatalog('plain'),
            self::ok(['productos' => [['id' => 'p1', 'descripcion' => 'A', 'tipoItem' => '1', 'precioUni' => '1.5']], 'siguiente' => 'next page']),
            self::ok(['productos' => [['id' => 'p2', 'descripcion' => 'B', 'activo' => false, 'tipoVenta' => 'exenta']], 'siguiente' => null]),
        ]);
        $rows = $this->client($fake)->listProducts(['includeInactive' => true]);

        $this->assertSame('/v1/products?limite=200&incluirInactivos=true', self::path($fake, 1));
        $this->assertSame('/v1/products?limite=200&incluirInactivos=true&cursor=next%20page', self::path($fake, 2));
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['tipoItem'], 'Numeric strings become numbers.');
        $this->assertSame(1.5, $rows[0]['unit_price']);
        $this->assertSame('gravada', $rows[0]['tipoVenta'], 'A product that never chose one reads as taxed.');
        $this->assertSame('gravada', $rows[0]['sale_class']);
        $this->assertFalse($rows[1]['active']);
        $this->assertSame('exenta', $rows[1]['tipoVenta']);
    }

    public function testGetByIdReturnsNullWhenMissingOrInactive(): void
    {
        $fake = new FakeTransport([
            self::statusWithCatalog('plain'),
            self::ok(['cliente' => self::CUSTOMER]),
            self::error(404, 'not_found'),
            self::ok(['cliente' => ['id' => 'c3', 'nombre' => 'Off', 'activo' => false]]),
            self::ok(['cliente' => ['id' => 'c3', 'nombre' => 'Off', 'activo' => false]]),
        ]);
        $client = $this->client($fake);

        $found = $client->getCustomer('c1');
        $this->assertNotNull($found);
        $this->assertSame('Ana', $found['name']);
        $this->assertSame('/v1/customers/c1', self::path($fake, 1));
        $this->assertNull($client->getCustomer('nope'));
        $this->assertNull($client->getCustomer('c3'));
        $inactive = $client->getCustomer('c3', ['includeInactive' => true]);
        $this->assertNotNull($inactive);
        $this->assertFalse($inactive['activo']);
    }

    public function testSearchUsesTheServerWithTheQueryAndTheLimit(): void
    {
        $fake = new FakeTransport([
            self::statusWithCatalog('plain'),
            self::ok(['clientes' => [self::CUSTOMER]]),
            self::ok(['productos' => [['id' => 'p1', 'descripcion' => 'Café']]]),
        ]);
        $client = $this->client($fake);

        $this->assertCount(1, $client->searchCustomers('  ana ñ ', ['limit' => 5]));
        $this->assertSame('/v1/customers?buscar=ana%20%C3%B1&limite=5', self::path($fake, 1));
        $products = $client->searchProducts('café');
        $this->assertSame('/v1/products?buscar=caf%C3%A9&limite=50', self::path($fake, 2));
        $this->assertSame('Café', $products[0]['description']);
    }

    public function testSearchWithABlankQueryAsksNothing(): void
    {
        $fake = new FakeTransport([]);
        $this->assertSame([], $this->client($fake)->searchCustomers('   '));
        $this->assertSame([], $fake->requests);
    }

    public function testASearchAboveTheServerPageFollowsTheCursor(): void
    {
        $fake = new FakeTransport([
            self::statusWithCatalog('plain'),
            self::ok(['clientes' => [self::CUSTOMER], 'siguiente' => 'k']),
            self::ok(['clientes' => [['id' => 'c9', 'nombre' => 'Ana 2']], 'siguiente' => null]),
        ]);
        $rows = $this->client($fake)->searchCustomers('ana', ['limit' => 300]);
        $this->assertCount(2, $rows);
        $this->assertSame('/v1/customers?limite=200&buscar=ana', self::path($fake, 1));
        $this->assertSame('/v1/customers?limite=200&buscar=ana&cursor=k', self::path($fake, 2));
    }

    public function testSearchLimitIsChecked(): void
    {
        $client = $this->client(new FakeTransport([]));
        foreach ([0, 501, 1.5] as $limit) {
            try {
                $client->searchProducts('x', ['limit' => $limit]);
                $this->fail('InvalidArgumentException expected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('limit', $e->getMessage());
            }
        }
    }

    /** @dataProvider catalogReads */
    public function testAnEncryptedCatalogCannotBeReadYetAndSaysWhy(string $method): void
    {
        $fake = new FakeTransport([self::statusWithCatalog('encrypted')]);
        $client = $this->client($fake);
        try {
            $arguments = in_array($method, ['listCustomers', 'listProducts'], true) ? [] : ['x'];
            $client->{$method}(...$arguments);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::UNAUTHORIZED, $e->getErrorCode());
            $this->assertStringContainsString('later version of this SDK', $e->getMessage());
        }
        $this->assertCount(1, $fake->requests, 'Only the status read; no catalog route is touched.');
    }

    /** @return array<string, array{string}> */
    public function catalogReads(): array
    {
        $out = [];
        foreach (['listCustomers', 'getCustomer', 'searchCustomers', 'listProducts', 'getProduct', 'searchProducts'] as $m) {
            $out[$m] = [$m];
        }
        return $out;
    }

    public function testAReadableCatalogWhoseRoutesAreMissingDoesNotPretendToHaveASnapshot(): void
    {
        $fake = new FakeTransport([self::statusWithCatalog('readable'), self::error(404, 'not_found')]);
        try {
            $this->client($fake)->listCustomers();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::NOT_FOUND, $e->getErrorCode(), 'Without an unlock key the original answer is the honest one.');
        }

        $fake = new FakeTransport([self::statusWithCatalog('readable'), self::error(409, 'catalog_encrypted')]);
        try {
            $this->client($fake, ['unlockKey' => 'factauk_ABC'])->listCustomers();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::UNAUTHORIZED, $e->getErrorCode());
            $this->assertStringContainsString('later version', $e->getMessage());
        }
    }

    public function testCreateCustomerSendsWireNamesDigitsOnlyAndAnIdempotencyKey(): void
    {
        $fake = new FakeTransport([self::ok(['cliente' => ['id' => 'c1', 'nombre' => 'Ana', 'activo' => true]], 201)]);
        $created = $this->client($fake)->createCustomer([
            'name' => 'Ana',
            'tipoDocumento' => '13',
            'numDocumento' => '01234567-8',
            'nrc' => '12-3',
            'direccion' => ['departamento' => '06', 'municipio' => '14', 'distrito' => '01', 'complemento' => 'Calle 1'],
        ], ['idempotencyKey' => 'cust-1']);

        $sent = self::request($fake);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('/v1/customers', self::path($fake));
        $this->assertSame('cust-1', $sent['headers']['Idempotency-Key']);
        $this->assertArrayNotHasKey('X-Facta-Request-Fingerprint', $sent['headers']);
        $body = self::bodyOf($fake);
        $this->assertSame('Ana', $body['nombre']);
        $this->assertArrayNotHasKey('name', $body, 'The stored spelling is translated, not sent.');
        $this->assertSame('012345678', $body['numDocumento']);
        $this->assertSame('123', $body['nrc']);
        $this->assertSame('c1', $created['id']);
        $this->assertSame('Ana', $created['name']);
        $this->assertCount(1, $fake->requests, 'Writes do not need the catalog mode.');
    }

    public function testCustomerValidationCollectsEveryIssueBeforeSending(): void
    {
        $fake = new FakeTransport([]);
        $client = $this->client($fake);
        try {
            $client->createCustomer([
                'tipoDocumento' => '36', 'numDocumento' => '123', 'nrc' => '123456789',
                'direccion' => ['departamento' => '6'], 'correo' => 'nope',
            ]);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::VALIDATION_FAILED, $e->getErrorCode());
            $this->assertSame(422, $e->getStatus());
            $details = $e->getDetails();
            $this->assertIsArray($details);
            $this->assertGreaterThanOrEqual(6, count($details['issues']));
        }
        try {
            $client->createCustomer(['nombre' => 'A', 'name' => 'B']);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertStringContainsString('same field', $e->getMessage());
        }
        try {
            $client->updateCustomer('c1', []);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertStringContainsString('at least one field', $e->getMessage());
        }
        $this->assertSame([], $fake->requests);
    }

    public function testUpdateAndDeactivateUsePatchAndDelete(): void
    {
        $fake = new FakeTransport([
            self::ok(['cliente' => ['id' => 'c1', 'nombre' => 'New', 'activo' => true]]),
            self::ok('{}'),
            self::ok(['producto' => ['id' => 'p1', 'activo' => false]]),
        ]);
        $client = $this->client($fake);

        $updated = $client->updateCustomer('c1', ['telefono' => '2222-3333']);
        $this->assertSame('PATCH', self::request($fake, 0)['method']);
        $this->assertSame('/v1/customers/c1', self::path($fake, 0));
        $this->assertSame(['telefono' => '2222-3333'], self::bodyOf($fake, 0));
        $this->assertSame('New', $updated['nombre']);
        $this->assertArrayNotHasKey('Idempotency-Key', self::request($fake, 0)['headers'], 'Only POST carries a key.');

        $gone = $client->deactivateCustomer('c1');
        $this->assertSame('DELETE', self::request($fake, 1)['method']);
        $this->assertNull(self::request($fake, 1)['body']);
        $this->assertSame(['id' => 'c1', 'activo' => false], array_intersect_key($gone, ['id' => 1, 'activo' => 1]));
        $this->assertFalse($gone['active']);

        $product = $client->deactivateProduct('p1');
        $this->assertSame('/v1/products/p1', self::path($fake, 2));
        $this->assertFalse($product['activo']);
    }

    public function testProductWriteRules(): void
    {
        $fake = new FakeTransport([self::ok(['producto' => ['id' => 'p1', 'descripcion' => 'X', 'tipoItem' => 1, 'precioUni' => 2.5, 'tipoVenta' => 'no_sujeta']], 201)]);
        $client = $this->client($fake);

        $product = $client->createProduct(['description' => 'X', 'item_type' => 1, 'unit_price' => 2.5, 'tipoVenta' => 'no_sujeta']);
        $this->assertEquals(['descripcion' => 'X', 'tipoItem' => 1, 'precioUni' => 2.5, 'tipoVenta' => 'no_sujeta'], self::bodyOf($fake));
        $this->assertSame('/v1/products', self::path($fake));
        $this->assertSame('noSuj', $product['sale_class']);

        foreach ([
            ['descripcion' => 'X', 'precioUni' => 1],
            ['descripcion' => 'X', 'tipoItem' => 4, 'precioUni' => 1],
            ['descripcion' => 'X', 'tipoItem' => 1, 'precioUni' => 0],
            ['descripcion' => 'X', 'tipoItem' => 1, 'precioUni' => 1, 'tipoVenta' => 'otra'],
            ['descripcion' => '', 'tipoItem' => 1, 'precioUni' => 1],
            ['descripcion' => 'X', 'tipoItem' => 1, 'precioUni' => 1, 'ivaIncluido' => 'si'],
        ] as $bad) {
            try {
                $client->createProduct($bad);
                $this->fail('FactaError expected for ' . json_encode($bad));
            } catch (FactaError $e) {
                $this->assertSame(FactaError::VALIDATION_FAILED, $e->getErrorCode());
            }
        }
        $this->assertCount(1, $fake->requests);
    }

    public function testAWriteRefusedByTheCompanyKeepsItsCodeAndGetsGuidance(): void
    {
        $fake = new FakeTransport([self::error(403, 'catalog_write_disabled', 'x'), self::error(409, 'catalog_encrypted', 'x')]);
        $client = $this->client($fake);
        foreach ([FactaError::CATALOG_WRITE_DISABLED, FactaError::CATALOG_ENCRYPTED] as $code) {
            try {
                $client->createProduct(['descripcion' => 'X', 'tipoItem' => 1, 'precioUni' => 1]);
                $this->fail('FactaError expected');
            } catch (FactaError $e) {
                $this->assertSame($code, $e->getErrorCode());
                $this->assertStringContainsString('catalog', $e->getMessage());
            }
        }
    }

    public function testWriteIdsAreRequiredAndEscaped(): void
    {
        $fake = new FakeTransport([self::ok(['cliente' => ['id' => 'a/b', 'activo' => true]])]);
        $client = $this->client($fake);
        $client->updateCustomer('a/b', ['telefono' => '1']);
        $this->assertSame('/v1/customers/a%2Fb', self::path($fake));
        $this->expectException(\InvalidArgumentException::class);
        $client->deactivateProduct('  ');
    }
}
