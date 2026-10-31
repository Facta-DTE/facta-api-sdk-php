<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SDK to a snapshot of the server contract (tests/fixtures/openapi.yaml, the staging
 * openapi.yaml): every error code, every route and the retry set. The TypeScript SDK keeps the same
 * checks in its own tests, so the two stay aligned through the contract and not through each other.
 */
final class ContractParityTest extends TestCase
{
    /** Codes the SDK raises itself; the server never sends them. */
    private const LOCAL_ONLY = ['archive_integrity_error', 'network_error', 'operation_outcome_unknown', 'storage_contract_invalid'];

    /** The TypeScript SDK's RETRYABLE set (`src/client.ts`). */
    private const RETRYABLE = [
        'correlative_unavailable', 'data_epoch_stale', 'data_grant_unavailable', 'idempotency_in_flight',
        'mh_unreachable', 'network_error', 'service_unavailable', 'storage_unavailable',
    ];

    private const WRAPPED = [
        'GET /v1/openapi.json' => 'getContract',
        'GET /v1/status' => 'status',
        'GET /v1/storage/status' => 'getStorageStatus',
        'GET /v1/storage/copies' => 'getDocumentCopies',
        'POST /v1/storage/copies/{codigoGeneracion}/repair' => 'retryDocumentStorage',
        'GET /v1/storage/unconfirmed' => 'listUnconfirmedDocuments',
        'POST /v1/dte/{codigoGeneracion}/storage-confirmation' => 'confirmDocumentStorage',
        'POST /v1/dte' => 'issue',
        'GET /v1/dte' => 'listDocuments',
        'POST /v1/dte/prepare' => 'prepare',
        'POST /v1/dte/sign' => 'sign',
        'GET /v1/dte/{codigoGeneracion}' => 'getDocumentStatus',
        'POST /v1/dte/{codigoGeneracion}/invalidate' => 'invalidate',
        'POST /v1/dte/{codigoGeneracion}/return' => 'registerReturn',
        'GET /v1/dte/{codigoGeneracion}/file' => 'downloadDocument',
        'POST /v1/dte/{codigoGeneracion}/entrega/correo' => 'deliverEmail',
        'POST /v1/dte/{codigoGeneracion}/entrega/whatsapp' => 'deliverWhatsApp',
        'GET /v1/dte/{codigoGeneracion}/entrega' => 'getDelivery',
        'GET /v1/dte/holding' => 'listHolding',
        'GET /v1/customers' => 'listCustomers',
        'POST /v1/customers' => 'createCustomer',
        'GET /v1/customers/{id}' => 'getCustomer',
        'PATCH /v1/customers/{id}' => 'updateCustomer',
        'DELETE /v1/customers/{id}' => 'deactivateCustomer',
        'GET /v1/products' => 'listProducts',
        'POST /v1/products' => 'createProduct',
        'GET /v1/products/{id}' => 'getProduct',
        'PATCH /v1/products/{id}' => 'updateProduct',
        'DELETE /v1/products/{id}' => 'deactivateProduct',
        'GET /v1/catalog/changes' => 'listCatalogChanges',
        'POST /v1/webhooks' => 'createWebhook',
        'GET /v1/webhooks' => 'listWebhooks',
        'GET /v1/webhooks/{id}' => 'getWebhook',
        'PATCH /v1/webhooks/{id}' => 'updateWebhook',
        'DELETE /v1/webhooks/{id}' => 'deleteWebhook',
        'POST /v1/webhooks/{id}/rotate-secret' => 'rotateWebhookSecret',
        'POST /v1/webhooks/{id}/test' => 'testWebhook',
        'POST /v1/drafts' => 'createDraft',
        'GET /v1/drafts' => 'listDrafts',
        'GET /v1/drafts/{id}' => 'getDraft',
        'PUT /v1/drafts/{id}' => 'updateDraft',
        'DELETE /v1/drafts/{id}' => 'cancelDraft',
        'GET /v1/drafts/by-key/{key}' => 'getDraftByKey',
        'POST /v1/drafts/{id}/issue' => 'issueDraft',
    ];

    /** Operations deliberately without a method (same list as the TypeScript SDK). */
    private const NOT_WRAPPED = [
        'POST /v1/storage/copies/{codigoGeneracion}/byos',
        'POST /v1/destinations/{destinationId}/drive-token',
        'GET /v1/vault/destinations',
        'GET /v1/representacion/plantillas',
        'DELETE /v1/dte/{codigoGeneracion}/return/{evento}',
        'POST /v1/catalog/changes',
        'GET /v1/catalog/changes/{id}',
        'DELETE /v1/catalog/changes/{id}',
        'GET /v1/catalog/changes/by-key/{key}',
    ];

    private static function contract(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/openapi.yaml');
    }

    /** @return string[] */
    private static function contractCodes(): array
    {
        $yaml = self::contract();
        $start = (int) strpos($yaml, "\n    Error:\n");
        $enum = (int) strpos($yaml, "enum:\n", $start);
        $codes = [];
        foreach (explode("\n", substr($yaml, $enum + 6)) as $line) {
            if (preg_match('/^\s+- ([a-z_]+)\s*$/', $line, $m) !== 1) {
                break;
            }
            $codes[] = $m[1];
        }
        return $codes;
    }

    /** @return string[] */
    private static function sdkCodes(): array
    {
        $codes = [];
        foreach ((new \ReflectionClass(FactaError::class))->getConstants() as $value) {
            if (is_string($value) && preg_match('/^[a-z][a-z_]+$/', $value) === 1) {
                $codes[] = $value;
            }
        }
        return $codes;
    }

    public function testEveryCodeOfTheContractIsAFactaErrorConstant(): void
    {
        $contract = self::contractCodes();
        $this->assertGreaterThan(30, count($contract));
        $this->assertSame([], array_values(array_diff($contract, self::sdkCodes())));
    }

    public function testEveryFactaErrorConstantIsInTheContractOrLocalOnly(): void
    {
        $this->assertSame([], array_values(array_diff(self::sdkCodes(), self::contractCodes(), self::LOCAL_ONLY)));
    }

    public function testTheRetrySetIsTheTypeScriptOne(): void
    {
        $retryable = (new \ReflectionClass(Facta::class))->getReflectionConstant('RETRYABLE');
        $this->assertNotFalse($retryable);
        $set = $retryable->getValue();
        sort($set);
        $this->assertSame(self::RETRYABLE, $set);
    }

    public function testEveryOperationOfTheContractIsWrappedOrDeliberatelyNot(): void
    {
        $operations = [];
        $path = '';
        $yaml = self::contract();
        $paths = substr($yaml, (int) strpos($yaml, "\npaths:"), (int) strpos($yaml, "\ncomponents:") - (int) strpos($yaml, "\npaths:"));
        foreach (explode("\n", $paths) as $line) {
            if (preg_match('#^ {2}(/v1/[^\s:]*):\s*$#', $line, $m) === 1) {
                $path = $m[1];
            }
            if ($path !== '' && preg_match('/^ {4}(get|post|put|patch|delete):\s*$/', $line, $m) === 1) {
                $operations[] = strtoupper($m[1]) . ' ' . $path;
            }
        }
        $this->assertGreaterThan(40, count($operations));
        $known = array_merge(array_keys(self::WRAPPED), self::NOT_WRAPPED);
        $this->assertSame([], array_values(array_diff($operations, $known)), 'Operations of the contract nobody decided on.');
        $this->assertSame([], array_values(array_diff($known, $operations)), 'Table entries for operations the contract no longer has.');
    }

    public function testEveryWrappedOperationIsAPublicMethod(): void
    {
        foreach (self::WRAPPED as $operation => $method) {
            $this->assertTrue(method_exists(Facta::class, $method), $operation . ': Facta::' . $method . '() is missing.');
        }
    }
}
