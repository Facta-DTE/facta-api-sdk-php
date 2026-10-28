<?php

declare(strict_types=1);

/**
 * Small live run of the 0.2 surface against a company that shares its data key (staging, environment 00).
 *
 * The harness (FactaDTE tools/e2e/api-keys-shared.live.mjs) creates a temporary company and the keys, runs this
 * script with them in the environment and deletes everything afterwards. Each check prints one JSON line
 * {"check","ok","detail"}; no key, secret or personal value is ever printed. Exit code 1 when a check fails.
 * The checks are the TypeScript SDK's (scripts/live-shared-catalogue.mjs), one for one.
 *
 *   FACTA_LIVE_BASE_URL         .../functions/v1/api-v1 (must be a staging host)
 *   FACTA_LIVE_KEY              facta_test_ key with catalog:read + catalog:write, granted the catalogue
 *   FACTA_LIVE_DRAFT_KEY        facta_test_ key with catalog:read + draft (not granted)
 *   FACTA_LIVE_PRODUCTION_KEY   facta_live_ key of the same company (which is still in 00)
 *   FACTA_LIVE_DUI              a valid unique 9-digit DUI for this run
 *   FACTA_LIVE_TAG              unique text for names and codes
 */

require_once __DIR__ . '/../autoload.php';

use Facta\Api\Facta;
use Facta\Api\FactaError;

function out(string $check, bool $ok, string $detail): void
{
    echo json_encode(['check' => $check, 'ok' => $ok, 'detail' => $detail]), "\n";
}

$base = (string) getenv('FACTA_LIVE_BASE_URL');
if (preg_match('#^https://[a-z0-9]+\.supabase\.co/functions/v1/api-v1$#', $base) !== 1 || strpos($base, 'hcnvknpsbadplnfcflxx') !== false) {
    out('target', false, 'refusing: not the staging api-v1 URL');
    exit(2);
}
$tag = (string) (getenv('FACTA_LIVE_TAG') ?: 'sdk');
$dui = (string) getenv('FACTA_LIVE_DUI');
$make = static function (string $envName) use ($base): Facta {
    return new Facta(['apiKey' => (string) getenv($envName), 'baseUrl' => $base, 'region' => false, 'maxRetries' => 1]);
};
$facta = $make('FACTA_LIVE_KEY');
$drafts = $make('FACTA_LIVE_DRAFT_KEY');
$production = $make('FACTA_LIVE_PRODUCTION_KEY');

$failed = 0;
function check(string $name, callable $fn): void
{
    global $failed;
    try {
        out($name, true, (string) $fn());
    } catch (\Throwable $e) {
        $failed++;
        $code = $e instanceof FactaError ? $e->getErrorCode() . ' ' . $e->getStatus() . ' ' : '';
        out($name, false, trim($code . substr($e->getMessage(), 0, 200)));
    }
}

/** @return FactaError */
function refusal(callable $call, string $code, int $status): FactaError
{
    try {
        $call();
    } catch (FactaError $e) {
        if ($e->getErrorCode() === $code && $e->getStatus() === $status) {
            return $e;
        }
        throw new \RuntimeException('expected ' . $code . ', got ' . $e->getErrorCode() . ' ' . $e->getStatus());
    }
    throw new \RuntimeException('expected ' . $code . ', the call succeeded');
}

/** @param mixed $details */
function detailOf(FactaError $e, string $name)
{
    $d = $e->getDetails();
    if (is_object($d)) {
        return $d->{$name} ?? null;
    }
    return is_array($d) ? ($d[$name] ?? null) : null;
}

$customer = null;
$product = null;

check('status', static function () use ($facta) {
    $status = $facta->status();
    if (($status['ambiente'] ?? null) !== '00') {
        throw new \RuntimeException('ambiente ' . json_encode($status['ambiente'] ?? null));
    }
    return 'ambiente 00';
});
check('customers.create+duplicate', static function () use ($facta, $tag, $dui, &$customer) {
    $input = ['nombre' => 'QA SDK PHP ' . $tag, 'tipoDocumento' => '13', 'numDocumento' => $dui, 'correo' => 'sdk-php@qa.factadte.invalid', 'direccion' => ['departamento' => '06', 'municipio' => '23', 'distrito' => '14', 'complemento' => 'Colonia de prueba 1']];
    $customer = $facta->createCustomer($input, ['idempotencyKey' => 'sdk-php-customer-' . $tag]);
    if (!isset($customer['revision']) || !is_int($customer['revision'])) {
        throw new \RuntimeException('created customer has no revision');
    }
    refusal(static function () use ($facta, $input, $tag) {
        $facta->createCustomer($input, ['idempotencyKey' => 'sdk-php-customer-dup-' . $tag]);
    }, 'catalog_duplicate', 409);
    return 'revision ' . $customer['revision'] . '; duplicate refused';
});
check('customers.revision+ifMatch', static function () use ($facta, &$customer) {
    $read = $facta->getCustomer($customer['id']);
    $updated = $facta->updateCustomer($customer['id'], ['telefono' => '22223333'], ['ifMatch' => $read['revision']]);
    if (!($updated['revision'] > $read['revision'])) {
        throw new \RuntimeException('revision ' . $read['revision'] . ' -> ' . $updated['revision']);
    }
    $conflict = refusal(static function () use ($facta, $customer, $read) {
        $facta->updateCustomer($customer['id'], ['telefono' => '22224444'], ['ifMatch' => $read['revision']]);
    }, 'catalog_revision_conflict', 409);
    $actual = detailOf($conflict, 'revisionActual');
    if (!is_int($actual)) {
        throw new \RuntimeException('no revisionActual in details');
    }
    return 'revision ' . $read['revision'] . ' -> ' . $updated['revision'] . '; stale ifMatch refused (revisionActual ' . $actual . ')';
});
check('customers.deactivate+inactive+reactivate', static function () use ($facta, &$customer) {
    $off = $facta->deactivateCustomer($customer['id']);
    if ($off['active'] !== false) {
        throw new \RuntimeException('not deactivated');
    }
    refusal(static function () use ($facta, $customer) {
        $facta->updateCustomer($customer['id'], ['telefono' => '22225555']);
    }, 'catalog_record_inactive', 409);
    $back = $facta->updateCustomer($customer['id'], ['activo' => true, 'telefono' => '22225555']);
    if ($back['active'] !== true) {
        throw new \RuntimeException('not reactivated');
    }
    return 'deactivate; edit of an inactive record refused; activo:true reactivates';
});
check('customers.list+search', static function () use ($facta, $tag, &$customer) {
    $rows = $facta->listCustomers();
    if (!in_array($customer['id'], array_column($rows, 'id'), true)) {
        throw new \RuntimeException('listCustomers misses the created customer');
    }
    $found = $facta->searchCustomers('QA SDK PHP ' . $tag);
    if (!in_array($customer['id'], array_column($found, 'id'), true)) {
        throw new \RuntimeException('searchCustomers misses it');
    }
    return count($rows) . ' customers; search finds it';
});
check('products.create+update+deactivate', static function () use ($facta, $tag, &$product) {
    $product = $facta->createProduct(['descripcion' => 'Producto SDK PHP ' . $tag, 'tipoItem' => 2, 'precioUni' => 2.5, 'ivaIncluido' => false, 'codigo' => 'SDKPHP-' . $tag], ['idempotencyKey' => 'sdk-php-product-' . $tag]);
    $read = $facta->getProduct($product['id']);
    $updated = $facta->updateProduct($product['id'], ['precioUni' => 3], ['ifMatch' => $read['revision']]);
    refusal(static function () use ($facta, $product, $read) {
        $facta->updateProduct($product['id'], ['precioUni' => 4], ['ifMatch' => $read['revision']]);
    }, 'catalog_revision_conflict', 409);
    $off = $facta->deactivateProduct($product['id']);
    if ($off['active'] !== false) {
        throw new \RuntimeException('not deactivated');
    }
    return 'revision ' . $read['revision'] . ' -> ' . $updated['revision'] . '; stale refused; deactivated';
});
check('catalog.changes', static function () use ($facta, $tag, &$customer) {
    $page = $facta->listCatalogChanges(0, ['limit' => 100]);
    $mine = array_filter($page['cambios'], static function ($change) use ($customer) {
        return ($change['registroId'] ?? null) === $customer['id'];
    });
    if ($mine === []) {
        throw new \RuntimeException('the feed does not list the customer');
    }
    if (strpos((string) json_encode($page), 'QA SDK PHP ' . $tag) !== false) {
        throw new \RuntimeException('the feed carries content');
    }
    $expired = refusal(static function () use ($facta, $page) {
        $facta->listCatalogChanges((int) $page['cabeza'] + 1000);
    }, 'catalog_cursor_expired', 410);
    if (!is_int(detailOf($expired, 'cabeza'))) {
        throw new \RuntimeException('no cabeza in details');
    }
    return 'head ' . $page['cabeza'] . '; ' . count($mine) . ' changes of the customer; cursor ahead -> catalog_cursor_expired (reason ' . (string) detailOf($expired, 'reason') . ')';
});
check('webhooks.lifecycle', static function () use ($facta, $tag) {
    $url = 'https://example.com/facta-sdk-qa-' . $tag;
    $created = $facta->createWebhook(['url' => $url, 'eventos' => ['catalog.customer.updated'], 'descripcion' => 'sdk live']);
    try {
        if (!isset($created['secreto']) || strpos($created['secreto'], 'whsec_') !== 0) {
            throw new \RuntimeException('no secret on create');
        }
        refusal(static function () use ($facta, $url) {
            $facta->createWebhook(['url' => $url]);
        }, 'webhook_duplicate', 409);
        $listed = array_values(array_filter($facta->listWebhooks(), static function ($hook) use ($created) {
            return ($hook['id'] ?? null) === $created['id'];
        }));
        if (count($listed) !== 1 || isset($listed[0]['secreto'])) {
            throw new \RuntimeException('not listed (or listed with a secret)');
        }
        $off = $facta->updateWebhook($created['id'], ['activo' => false]);
        if (($off['activo'] ?? null) !== false) {
            throw new \RuntimeException('not switched off');
        }
        $rotated = $facta->rotateWebhookSecret($created['id']);
        if (($rotated['secreto'] ?? null) === $created['secreto']) {
            throw new \RuntimeException('secret did not change');
        }
        return 'create (secret once); duplicate refused; list without secret; switched off; rotated';
    } finally {
        $facta->deleteWebhook($created['id']);
    }
});
check('drafts.scope', static function () use ($facta) {
    refusal(static function () use ($facta) {
        $facta->createDraft(['tipoDte' => '01', 'items' => [['descripcion' => 'x', 'cantidad' => 1, 'precioUni' => 1, 'tipoItem' => 2]]]);
    }, 'forbidden_scope', 403);
    return 'a key without the draft scope hears forbidden_scope';
});
check('drafts.lifecycle', static function () use ($drafts, $tag) {
    $request = ['tipoDte' => '01', 'items' => [['descripcion' => 'Servicio SDK PHP ' . $tag, 'cantidad' => 1, 'precioUni' => 1.13, 'tipoItem' => 2]]];
    $withRef = $request + ['referencia' => 'sdk-php-' . $tag];
    try {
        $draft = $drafts->createDraft($withRef, ['idempotencyKey' => 'sdk-php-draft-' . $tag]);
    } catch (FactaError $e) {
        if ($e->getErrorCode() === 'drafts_not_ready' && $e->getStatus() === 409) {
            return 'drafts_not_ready (409): the owner has not published the draft-box key; typed refusal as documented';
        }
        throw $e;
    }
    try {
        $again = $drafts->createDraft($withRef, ['idempotencyKey' => 'sdk-php-draft-' . $tag]);
        if ($again['borradorId'] !== $draft['borradorId']) {
            throw new \RuntimeException('same idempotency key, another draft');
        }
        $byKey = $drafts->getDraftByKey('sdk-php-draft-' . $tag);
        $listed = $drafts->listDrafts(['reference' => 'sdk-php-' . $tag]);
        if ($byKey['borradorId'] !== $draft['borradorId'] || !in_array($draft['borradorId'], array_column($listed['borradores'], 'borradorId'), true)) {
            throw new \RuntimeException('not found by key / listing');
        }
        $updated = $drafts->updateDraft($draft['borradorId'], $draft['version'], $withRef);
        refusal(static function () use ($drafts, $draft, $request) {
            $drafts->updateDraft($draft['borradorId'], $draft['version'], $request);
        }, 'version_mismatch', 409);
        return 'created v' . $draft['version'] . '; same key -> same draft; by key and listing find it; update v' . $updated['version'] . '; stale version refused';
    } finally {
        try {
            $drafts->cancelDraft($draft['borradorId']);
        } catch (\Throwable $ignored) {
        }
    }
});
check('drafts.issue-scope', static function () use ($drafts) {
    refusal(static function () use ($drafts) {
        $drafts->issueDraft('00000000-0000-4000-8000-000000000000', 1, ['tipoDte' => '01', 'items' => [['descripcion' => 'x', 'cantidad' => 1, 'precioUni' => 1, 'tipoItem' => 2]]]);
    }, 'forbidden_scope', 403);
    return 'issueDraft with a key that lacks the issue scope hears forbidden_scope';
});
check('environment.production-key-on-test-company', static function () use ($production) {
    $error = refusal(static function () use ($production) {
        $production->listCustomers();
    }, 'environment_not_allowed', 403);
    return 'facta_live_ key on a company in 00 -> ' . $error->getErrorCode();
});

exit($failed === 0 ? 0 : 1);
