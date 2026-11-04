# Facta DTE API client for PHP

PHP client for the [Facta DTE](https://factadte.com) API, El Salvador's electronic
invoicing service. It talks to the API over HTTPS; **it never signs a document and never
computes tax**. Signing, totals, IVA, correlatives and validation happen on the server.

> **Status: tier 1 (0.2.0-rc.3 is a release candidate; 0.1.0 is the stable release).** Everything that needs only
> HTTP is implemented: issuing, prepare/sign, queries, downloads, invalidation, returns,
> delivery, managed storage, the catalog, the catalogue changes feed and webhooks (see
> [Methods](#methods)). The catalogue is always read through the API, also when the company
> keeps it encrypted and shares its data key with your API key. What needs local storage is
> **not** there yet: BYOS destinations and the restart-safe archive.

* Package: `facta-dte/api`, namespace `Facta\Api`, MIT licence.
* **PHP 7.4 or later**, clean on 8.0 to 8.5 (no deprecation notices).
* **No required dependency and no required extension** beyond PHP core
  (`json`, `hash`). `ext-curl` is used when present; otherwise PHP streams
  (`allow_url_fopen`, plus `ext-openssl` for HTTPS). A PSR-18 client can be injected.
* TLS verification is never disabled.

## Install

### With Composer

```bash
composer require facta-dte/api
```

```php
require __DIR__ . '/vendor/autoload.php';
```

### Without Composer (ZIP, FTP, cPanel)

Download `facta-api-php-vX.Y.Z.zip` from the GitHub Release, unzip it and upload the
`facta-api-php/` folder next to your script (or anywhere PHP can read):

```php
require_once __DIR__ . '/facta-api-php/autoload.php';
```

No Composer, no extension to install. If you only have Composer on another machine, run
`composer require facta-dte/api` there and upload the generated `vendor/` folder.

## Use

```php
use Facta\Api\Facta;
use Facta\Api\FactaError;

$facta = new Facta([
    'apiKey'  => getenv('FACTA_API_KEY'),    // facta_test_... (environment 00) or facta_live_... (01)
    'signKey' => getenv('FACTA_SIGN_KEY'),   // factask_..., sent only on routes that sign
    // 'timeoutMs' => 60000,
    // 'maxRetries' => 3,
]);

try {
    $result = $facta->issue(
        ['tipoDte' => '01', 'items' => [['descripcion' => 'Servicio', 'cantidad' => 1, 'precioUni' => 11.30]]],
        ['idempotencyKey' => 'order-1234']   // yours: reuse it to retry safely, even after a crash
    );
    echo $result['estado'], ' ', $result['codigoGeneracion'], "\n";   // sellado ...
} catch (FactaError $e) {
    echo $e->getErrorCode(), ' (HTTP ', $e->getStatus(), '): ', $e->getMessage(), "\n";
}
```

The server computes every amount, signs and transmits; the SDK only sends your request.
Answers come back as plain associative arrays with the API's own field names and `estado`
values. Requests are PHP arrays too: a list becomes a JSON array, an associative array a JSON
object, and `new \stdClass()` is an empty object `{}`.

### Discounts, several payments, VAT class and warnings

```php
use Facta\Api\Facta;
use Facta\Api\Pago;
use Facta\Api\SaleType;

$facta = new Facta([
    'apiKey'  => getenv('FACTA_API_KEY'),            // facta_test_... created in staging.factadte.com
    'baseUrl' => Facta::STAGING_BASE_URL,            // the default stays production
]);
$result = $facta->issue([
    'tipoDte' => '03',
    'receptor' => ['customerId' => $customerId],
    'items' => [
        ['descripcion' => 'Cafe', 'cantidad' => 2, 'precioUni' => 30, 'montoDescu' => 5],   // discount of the line, in money
        ['descripcion' => 'Libro', 'cantidad' => 1, 'precioUni' => 10, 'tipoVenta' => SaleType::EXENTA],
    ],
    'pagos' => [new Pago('01', 25), new Pago('02', 40, 'VOUCHER-0042')],   // must add up to the total, to the cent
    'aplicarRetencionIva' => true,    // 01 and 03 only; never automatic
    // 'aplicarPercepcionIva' => true // 03 only
]);
foreach ($result['unknownFields'] ?? [] as $warning) {
    error_log('Facta ignored ' . $warning['campo'] . ' (did you mean ' . ($warning['sugerencia'] ?? '?') . ')');
}
```

`Facta::PRODUCTION_BASE_URL` names the production endpoint and is the same value as the backward-compatible `Facta::DEFAULT_BASE_URL`. The client uses production by default; set `baseUrl` to `Facta::STAGING_BASE_URL` to opt in to staging, or provide a custom absolute HTTP(S) URL.

* `montoDescu` is the discount of one line in money, in the same VAT basis as `precioUni`.
* `pagos` takes 1 to 10 rows (01, 03, 11, 14), not together with `formaPago`, not on 05/06, not on credit.
  `Pago` is optional: plain arrays work too.
* `tipoVenta` (`gravada` default, `exenta`, `no_sujeta`) is yours to choose and valid in 01, 03, 05, 06.
* **Unknown fields are always a warning, never an error.** A request field Facta DTE does not know is ignored and
  is not part of the document; the answer says so in `advertencias` (`campo_desconocido`, with `campo` and
  `sugerencia`). The SDK also lists them in `unknownFields` and parses the `Facta-Warning` header into
  `factaWarning` (`['code' => ..., 'count' => ...]`), on `issue()`, `prepare()`, `sign()` and `issueDraft()`, only when
  there is something to report. On a failure, `FactaError::getUnknownFields()` and `getFactaWarning()` read them.
  Check the first answer of your integration: an ignored field is a discount or a payment the document does not carry.

### Methods

| Method | Route | Notes |
| --- | --- | --- |
| `status()` | `GET /v1/status` | Health, environment, limits. |
| `region()` / `servedRegion()` | none | The pinned `x-region`; the region that served the last answer. No discovery call. |
| `diagnose($options)` | `/v1/status`, `/v1/storage/status` | Readiness report. Checks that need local storage say "not available in this SDK version yet". |
| `issue($request, $options)` | `POST /v1/dte` | `idempotencyKey`, `deliver`. `customerId` / `productId` are resolved by the server. |
| `prepare($request)` / `sign($prepared)` | `POST /v1/dte/prepare`, `/sign` | Two-step issuing. `documento` stays the decoded object tree (see below). |
| `getDocumentStatus($code)` | `GET /v1/dte/{code}` | Also answers for rejected documents. |
| `listDocuments($filters)` | `GET /v1/dte` | One page; pass `siguiente` back as `cursor`. |
| `downloadDocument($code, $kind, $options)` | `GET /v1/dte/{code}/file` | `json` (Archivo DTE), `pdf`, `ticket`. Exact bytes, never re-serialized. |
| `invalidate($code, $request)` | `POST .../invalidate` | Sends the sign key. |
| `registerReturn($code, $request)` | `POST .../return` | Sends the sign key. |
| `deliverEmail()` / `deliverWhatsApp()` / `getDelivery()` / `waitForDelivery()` | `.../entrega` | Token from `issue(..., ['deliver' => ...])`. |
| `getStorageStatus()` / `getDocumentCopies()` / `retryDocumentStorage()` | `/v1/storage/...` | Facta Storage coverage and repair. The status carries `capabilities.storageConfirmation` and `sinConfirmar`; copies carry `confirmado`. |
| `listUnconfirmedDocuments($options)` | `GET /v1/storage/unconfirmed` | Sealed documents that still depend on Facta's temporary copy, oldest deletion first. `limit`, `cursor`. |
| `confirmDocumentStorage($code, $confirmation)` | `POST /v1/dte/{code}/storage-confirmation` | Tell Facta you stored the JSON: `['where' => ..., 'sha256' => ...]` or `['where' => ..., 'bytes' => $json]`. |
| `storeAndConfirmUnconfirmed($options)` | the three above + `.../file` | Drains the list through your `store` callable and returns a report (never throws for one document). |
| `listCustomers()` ... `searchProducts()` | `/v1/customers`, `/v1/products` | Always through the API, in every catalogue mode. Single records carry `revision` when the company shares its data key. |
| `createCustomer()` ... `deactivateProduct()` | same | Needs `catalog:write` and the company's «administrar desde el API» switch. `updateCustomer` / `updateProduct` accept `ifMatch` (the `revision` you read). |
| `listCatalogChanges($since, $options)` | `GET /v1/catalog/changes?since=` | Ids and revisions of what changed; `catalog_cursor_expired` means read everything again. |
| `createWebhook()` / `listWebhooks()` / `getWebhook()` / `updateWebhook()` / `deleteWebhook()` / `rotateWebhookSecret()` / `testWebhook()` | `/v1/webhooks...` | Scope `catalog:read`. The secret is shown once. |
| `createDraft()` / `listDrafts()` / `getDraft()` / `getDraftByKey()` / `updateDraft()` / `cancelDraft()` / `issueDraft()` | `/v1/drafts...` | Scope `draft` (`issueDraft` also `issue` and the sign key). Leave an invoice for a person of the company to review and issue. See below. |
| `Webhook::verify()` / `Webhook::constructEvent()` | none | Verifies `Facta-Signature` on your receiver (see below). |
| `listHolding()` / `getContract()` | `/v1/dte/holding`, `/v1/openapi.json` | |

Per-call options accepted by every method: `timeoutMs`, `headers`; the writing ones also
`idempotencyKey`. An unknown option throws `InvalidArgumentException`, so a typo is not silently
ignored.

#### The temporary copy and the storage confirmation

Facta keeps an encrypted temporary copy of every sealed document. It is a safety net, not your
archive: it is deleted **2 days after the document is marked as stored**, or **30 days after
sealing** if nobody marks it. The company owner is mailed from day one (production only). Once it
is gone, reads that depended on it answer `temporary_copy_expired` (410), and
`retryDocumentStorage()` answers `nothing_to_repair_from` (409). Look in your own storage.

Store the JSON where you keep your books and say so. The simplest way is to let the SDK drain the
list daily:

```php
$report = $facta->storeAndConfirmUnconfirmed([
    'where' => 'disco-contabilidad',            // used when store() returns nothing
    'store' => function (array $files, array $document): ?string {
        // $files: codigoGeneracion, json (the Archivo DTE, exactly as downloaded), pdf (or null), sha256.
        // Return only when the bytes are durably stored; throw if they are not.
        file_put_contents('/books/' . $files['codigoGeneracion'] . '.json', $files['json']);
        return null;
    },
]);
// $report['stopped'] is 'complete', 'limit', 'too_many_failures', 'fatal' or 'list_failed'.
// $report['remaining'] and $report['firstDeletionAt'] say what is still waiting and until when.
```

Or confirm one document yourself, with the hash of the bytes you stored:

```php
$facta->confirmDocumentStorage($code, ['where' => 'disco-contabilidad', 'bytes' => $json]);
```

A document issued by the API is checked against the file Facta can rebuild (`verificado => true`; a
wrong hash is `storage_hash_mismatch` (422) and nothing is recorded). One issued from the app cannot be
checked and is kept as `informado => true`. Confirming twice is safe (`yaConfirmado => true`). A server that
does not advertise `capabilities.storageConfirmation` makes `confirmDocumentStorage()` throw
`storage_unsupported` without posting anything; such a server deletes no temporary copy.

#### Idempotency and retries

`issue()` mints one `Idempotency-Key` per call (or uses yours) and every retry resends the same
bytes with the same key. Since 0.2.0 the SDK no longer sends `X-Facta-Request-Fingerprint`: it
does not rewrite your request any more, so the server's own idempotency claim hashes the body.

#### prepare / sign

`prepare()` returns the plain members of the answer as an array, but `documento` is the decoded
`\stdClass` tree, on purpose: an empty object `{}` inside it must not turn into `[]` when it goes
back to `sign()`. Pass the array to `sign()` unchanged. If you store it between the two calls, use
`json_encode` and `json_decode($text)` (objects), never `json_decode($text, true)`.

#### Catalog references

`customerId` / `productId` in a request travel untouched and the **server** resolves them, in
every catalogue mode (plain, readable, or encrypted at rest and shared with the key). An id that
does not exist (or is deactivated) is `not_found` with `details.reason` `catalog_unknown_id`.
Reads (`listCustomers()`, `getProduct()`, ...) always call the API; no `unlockKey` is needed.

```php
$facta->issue(['tipoDte' => '01', 'receptor' => ['customerId' => $customerId],
               'items' => [['productId' => $productId, 'cantidad' => 1]]]);
```

#### Editing with a revision

On a company that shares its data key, a record you read carries `revision`. Pass it back as
`ifMatch` to apply the edit only if nobody changed the record meanwhile; otherwise you get
`catalog_revision_conflict` and `details->revisionActual`:

```php
$customer = $facta->getCustomer($id);
try {
    $facta->updateCustomer($id, ['telefono' => '22223333'], ['ifMatch' => $customer['revision']]);
} catch (FactaError $e) {
    if ($e->getErrorCode() === FactaError::CATALOG_REVISION_CONFLICT) { /* read again and re-apply */ }
}
```

Without `ifMatch` the last write wins per field you send.

#### Changes feed and webhooks

```php
$since = 0; $generation = null;
do {
    $page = $facta->listCatalogChanges($since, $generation !== null ? ['generation' => $generation] : []);
    foreach ($page['cambios'] as $change) { /* tipo, registroId, registroRevision, operacion */ }
    $since = $page['siguiente']; $generation = $page['generacion'];
} while ($page['hayMas']);
// catalog_cursor_expired (HTTP 410): read everything again from since = 0
```

A webhook is a hint to read, never the data (ids, a revision and a timestamp). Verify the RAW
body with the secret you got once from `createWebhook()`:

```php
use Facta\Api\MemoryWebhookReplayStore;   // use your own WebhookReplayStore across workers
use Facta\Api\Webhook;

$event = Webhook::constructEvent($secret, $_SERVER['HTTP_FACTA_SIGNATURE'] ?? null,
    file_get_contents('php://input'), ['store' => $store]);   // throws on a bad signature, a stale
// timestamp (> 300 s) or a replayed deliveryId; answer 2xx for a repeated delivery, order by revision
```

The retired sealed inbox (`POST /v1/catalog/changes` and `/v1/catalog/changes/{id}`) answers `410 inbox_retired`.

#### Drafts

A draft is an invoice your program leaves for a person of the company to review and issue in the Facta DTE app. No control number is spent until somebody issues it (or you do, with `issueDraft()`). Bodies and answers use the API's own member names.

```php
$draft = $facta->createDraft(
    ['tipoDte' => '01', 'receptor' => ['customerId' => $customerId], 'items' => [['productId' => $productId, 'cantidad' => 1]], 'referencia' => 'order-77'],
    ['idempotencyKey' => 'order-77']
);                                                        // $draft['borradorId'], ['version'], ['totales']
$page  = $facta->listDrafts(['state' => 'pendiente', 'reference' => 'order-77']);   // ['borradores', 'siguiente']
$same  = $facta->getDraftByKey('order-77');               // draft -> invoice without the generation code
$facta->updateDraft($draft['borradorId'], $draft['version'], $request);               // If-Match: version
$issued = $facta->issueDraft($draft['borradorId'], $draft['version'], $request, ['idempotencyKey' => 'issue-77']);
$facta->cancelDraft($draft['borradorId']);                // only while pending and closed
```

`drafts_not_ready` (409): the owner has not opened Facta with their vault since the company began sharing
its data key; ask them to open it once and repeat. `version_mismatch`, `draft_claimed`, `draft_issuing`,
`draft_issued`, `draft_not_issuable`, `item_final` (409) are about the draft's state and are never retried
(`draft_issuing` / `draft_claimed` spent nothing).

#### Remission note (04) and retention receipt (07)

`tipoDte` also accepts `"04"` and `"07"`, on `issue()`, `prepare()`/`sign()` and the drafts. The SDK keeps its rule: the request is an array in the API's own shape, sent as given. It signs nothing and computes no amount, tax or withholding (it does not derive `ivaRetenido` either). A key minted before these types existed carries only the six older ones and gets `dte_type_not_allowed`: mint another with 04/07 ticked. `status()['capabilities']['remissionRetention']` is `true` on a server that has them.

Each rule below is labelled **Normativa** (Hacienda's Normativa v2.0 or its official schema) or **Facta** (a decision of Facta DTE's API).

**Remission note (04): goods that move without a sale.** It accompanies goods that change place or hands without being sold at that moment (consignment, transfer between the company's own establishments, goods on the way to a customer, deposit). It carries the value of the goods but collects nothing; the sale, when it happens, is a 01/03/11. Any company may issue it if its key carries `"04"`.

| Field | Required | Why |
| --- | --- | --- |
| `receptor.nombre`, `tipoDocumento`, `numDocumento`, `codActividad` (+ `descActividad`) | yes (or `customerId`; what you spell out wins) | The receiver must be identified (**Facta**, from what the 04 schema requires). |
| `receptor.bienTitulo` | **yes, no default** | **Normativa** CAT-025: `01` Depósito, `02` Propiedad, `03` Consignación, `04` Traslado, `05` Otros (`BienTitulo::DEPOSITO`...`OTROS`). It is what the document legally states, so Facta never guesses it and a stored customer does not supply it. Missing: `400 invalid_request`; outside the catalogue: `422 validation_failed`; on another type: `400`. |
| `receptor.nrc`, `correo`, `direccion` | no | The official 04 schema lets them be null; unlike a CCF, a 04 does not demand them (**Facta** decision). |
| `items` | yes | Like a CCF: `precioUni` **excludes** VAT; `tipoVenta`, `montoDescu` and `numeroDocumento` per line are allowed. |
| `documentosRelacionados` | no | Sealed 01, 03 or 11 the goods travel with: `[['codigoGeneracion' => $code]]` (the server completes it from your index) or the full form. |
| `descuentoGlobal` | no | `['modo' => 'monto'|'porcentaje', 'valor' => n]`; accepted on the 04 (**Normativa** lists global discounts per sale class for FE, CCF and NRE; **Facta** applies it to the goods' value). |

```php
$nre = $facta->issue([
    'tipoDte' => '04',
    'receptor' => [
        'nombre' => 'Comercial Ejemplo, S.A. de C.V.', 'tipoDocumento' => '36', 'numDocumento' => '05110606161016',
        'codActividad' => '46510',
        'bienTitulo' => BienTitulo::TRASLADO,                 // required
        // 'nrc' => '2513113', 'correo' => '...', 'direccion' => [...]   // optional on a 04
    ],
    'items' => [['descripcion' => 'Caja de repuestos', 'cantidad' => 2, 'precioUni' => 25, 'tipoItem' => 1]],
    'descuentoGlobal' => ['modo' => 'porcentaje', 'valor' => 10],   // optional
], ['idempotencyKey' => 'transfer-0042']);
```

**Retention receipt (07): a withholding agent's receipt.** A withholding agent designated by Hacienda issues it when it buys from a smaller supplier: it withholds part of the VAT and hands the supplier this receipt. Its lines are not products: each says on how much it withheld and under which code. Only a company marked as a retention agent may issue it; any other gets `403 retention_agent_required` (`FactaError::RETENTION_AGENT_REQUIRED`), refused **before** a number is reserved, so nothing is spent and it is never retried. The owner enables «agente de retención» in Facta's Cuenta → Perfil fiscal, and only if Hacienda designated the company.

* `receptor` is the **supplier** withheld from, a registered taxpayer like a CCF's (`nombre`, NIT in `numDocumento`, `nrc`, `codActividad`, `direccion`, `correo`) or a `customerId`.
* `items`: 1 to **500** withholding lines. There is no `cantidad`, `precioUni` or `productId`.

| Line field | Required | Notes |
| --- | --- | --- |
| `montoSujetoGrav` | yes | Amount withheld on, **without VAT** (**Normativa**, Anexo IV field 103): above zero, at most **two decimals**. |
| `codigoRetencionMH` | yes | **Normativa** CAT-006: `22` 1 % of VAT (art. 162 Código Tributario), `C4` 13 %, `C9` other (`RetentionCode`). Another value: `422`. |
| `ivaRetenido` | only with `C9`, where it is required | With `22` and `C4` the server derives it and sending it is `400` (**Facta**: a client never sends computed money; the Normativa only asks that the result adds up, ±$0.01). A one-cent rounding difference against the supplier's document can only be adjusted through `C9`. The SDK never derives it. |
| `tipoDte`, `numeroDocumento`, `fechaEmision`, `tipoGeneracion` | all-or-nothing group | The document the purchase is documented by: `tipoDte` `"01"` or `"03"`, its `numeroDocumento` (generation code if electronic), `fechaEmision` `YYYY-MM-DD`, `tipoGeneracion` 1 paper / 2 electronic (default 2, only with the document). Send `tipoDte`, `numeroDocumento` and `fechaEmision` **together or not at all**. A paper purchase, or one from an excluded subject, carries none. A partial group is refused. |
| `descripcion` | no | Free text. |

For an **electronic** reference, the supplier's NIT must match the referenced document's issuer: otherwise Hacienda answers `[emisor.nit] DATO NO COINCIDE CON DTE` (**Normativa**, a Hacienda check at transmission). Use a paper purchase (no group, usually `C9` with the amount stated) when there is no electronic document. The document's `resumen.totalIva` is always `0.00` (**Facta**, from Hacienda's test validator, which refuses a VAT total on a 07): the withholding lives in the lines and in the summary's `totalSujetoRetencion` and `totalIvaRetenido`. Do not expect or compute a VAT total on a 07.

```php
$cr = $facta->issue([
    'tipoDte' => '07',
    'receptor' => [
        'nombre' => 'ZD, S.A. de C.V.', 'tipoDocumento' => '36', 'numDocumento' => '05110606161016',
        'nrc' => '2513113', 'codActividad' => '46510',
        'direccion' => ['departamento' => '06', 'municipio' => '20', 'distrito' => '01', 'complemento' => 'Colonia Centro, San Miguel'],
        'correo' => 'proveedor@example.com',
    ],
    'items' => [
        ['tipoDte' => '03', 'numeroDocumento' => '1BC3E8A1-1111-4111-8111-11111111ABCD', 'fechaEmision' => '2026-10-05',
         'montoSujetoGrav' => 1000, 'codigoRetencionMH' => RetentionCode::IVA_1_PERCENT],       // ivaRetenido derived
        ['montoSujetoGrav' => 250.5, 'codigoRetencionMH' => RetentionCode::OTHER, 'ivaRetenido' => 2.5,
         'descripcion' => 'Compra en papel'],                                                    // paper purchase, no reference
    ],
], ['idempotencyKey' => 'retention-0099']);
```

**As a draft** (nothing is signed, no number spent) both types use the same body as `issue()`:

```php
$facta->createDraft(['tipoDte' => '04', /* same body */ 'referencia' => 'transfer-0042'], ['idempotencyKey' => 'transfer-0042']);
$facta->createDraft(['tipoDte' => '07', /* same body */ 'referencia' => 'retention-0099'], ['idempotencyKey' => 'retention-0099']);
```

A 07 draft from a company that is not a retention agent is `403 retention_agent_required` and no draft is stored.

**What is ignored, and why.** Neither document settles anything: their schemas have no `condicionOperacion`, `formaPago`, `pagos`, `plazo` or `periodo`, and the 07 has no `descuentoGlobal` either (the 04 does) nor, on its lines, `cantidad`, `precioUni`, `uniMedida` or `tipoItem`. Sent anyway, they are **ignored, never an error**: the document is issued without them and the answer carries one `advertencias` entry per field, `['codigo' => 'campo_ignorado', 'campo' => ..., 'mensaje' => ...]`. The SDK lists them in `ignoredFields` on `issue()`, `prepare()`, `sign()`, `issueDraft()`, `createDraft()` and `updateDraft()` results (only when there are any), and `FactaError::getIgnoredFields()` reads them from a failure. `apendice` is not accepted (`400`).

```php
foreach ($nre['ignoredFields'] ?? [] as $warning) {
    error_log('Facta ignored ' . $warning['campo'] . ': ' . $warning['mensaje']);
}
```

**Common mistakes (04 and 07)**

| Mistake | What happens | Do this |
| --- | --- | --- |
| 04 without `receptor.bienTitulo` | `400 invalid_request` | Always choose the title (CAT-025). |
| `bienTitulo` outside `01`-`05`, or on another type | `422` / `400` | Only on a 04, only those codes. |
| `pagos`/`formaPago`/`condicionOperacion` on a 04 or 07 | Ignored, `campo_ignorado` | Leave them out. |
| `descuentoGlobal` on a 07 | Ignored, `campo_ignorado` (valid on a 04) | Leave it out. |
| `ivaRetenido` with `22` or `C4` | `400` | Let the server derive it; use `C9` to state it. |
| `C9` without `ivaRetenido` | `400` | State the amount withheld. |
| Only some of `tipoDte`/`numeroDocumento`/`fechaEmision` | Refused | All three, or none. |
| `cantidad`/`precioUni` on a 07 line | Not part of the line | `montoSujetoGrav` only. |
| More than 500 lines; `montoSujetoGrav` with 3 decimals or ≤ 0 | `400`/`422` | Split the document; two decimals. |
| Electronic reference whose issuer is not the supplier | Hacienda: `[emisor.nit] DATO NO COINCIDE CON DTE` | Reference the supplier's own document, or send a paper line. |
| 07 from a company that is not a retention agent | `403 retention_agent_required` | The owner enables «agente de retención», only if Hacienda designated it. |
| Key without `"04"`/`"07"` | `dte_type_not_allowed` | Mint a key with those types. |

**Not working yet.** The API accepts a credit or debit note (05/06) whose `documentosRelacionados` points at a sealed 07, but Hacienda rejects it today (its credit-note-over-retention shape differs). Do not build on it until this note is removed. A 04 may relate a sealed 01, 03 or 11; a 05/06 may relate a sealed 03.

#### Production keys and companies still in test

A production key (`facta_live_`) is refused with `environment_not_allowed` (403) on emission and on the
company-level routes (customers, products, webhooks, the change feed, drafts, destinations, templates)
while the company is still in the test environment; a test key is always allowed. A read-only key hears
`forbidden_scope` before `catalog_encrypted`, and `details.hint` of `catalog_encrypted` says whether the key
lacks access to the shared data or the catalogue is not shared.

### Options

| Option | Meaning |
| --- | --- |
| `apiKey` | Required. `facta_test_...` or `facta_live_...`. |
| `signKey` | `factask_...`. Sent only to the routes that sign (`POST /v1/dte`, `/v1/dte/sign`, `.../invalidate`, `.../return`). A `factauk_` unlock key is refused here. |
| `unlockKey` | `factauk_...`. Reserved for the next tier (BYOS destinations and the archive); never sent to the server and not needed for the catalogue. |
| `baseUrl` | API root (default: the public Facta API). |
| `timeoutMs` | Budget of one attempt, default `60000`. |
| `maxRetries` | Extra attempts for retryable errors, default `3`. |
| `region` | Functions region sent as `x-region`; `false` sends none. Defaults to `FACTA_API_REGION`, then the built-in region. |
| `transport` | A `Facta\Api\Http\Transport`, e.g. `new Psr18Transport($client, $requestFactory, $streamFactory)`. |
| `caBundle` | Path to a CA bundle, for servers with an outdated certificate store. |

### Errors and retries

Every failure is a `Facta\Api\FactaError`. Branch on `getErrorCode()` (the strings are the
same as in the TypeScript SDK: `mh_rejected`, `validation_failed`, `idempotency_key_reuse`,
`network_error`, ...), never on the message. The client retries exactly the
codes the TypeScript SDK retries: `idempotency_in_flight`, `mh_unreachable`, `service_unavailable`,
`correlative_unavailable`, `storage_unavailable`, `data_grant_unavailable`, `data_epoch_stale` and `network_error` (a connection failure or
timeout), up to `maxRetries` extra attempts, waiting for `Retry-After` when the server sends it.
Every attempt resends the same bytes with the same `Idempotency-Key`, so a retry cannot issue twice.
When the attempts run out the last error is thrown; any other code is final (for example `retention_agent_required`, `403`: a 07 from a company that is not a retention agent).

Secrets (API key, sign key, unlock key) are removed from messages and `details`.

### Old servers and TLS

If HTTPS fails because the server's certificate store is outdated, pass the path of a current
CA bundle (for example the one published by curl) in `caBundle`. Verification is never turned off.

### Fingerprint and JSON helpers

```php
use Facta\Api\Json\Fingerprint;

// fingerprint-v1: lowercase hex SHA-256 of the RFC 8785 (JCS) form of the request.
$fingerprint = Fingerprint::v1($jsonRequestText);
```

`Facta\Api\Json\Jcs` is a dependency-free RFC 8785 implementation (numbers follow ECMAScript
`Number::toString`, independent of `serialize_precision`; keys sort by UTF-16 code units).
It is validated against the conformance vectors shared with the TypeScript SDK.

## Live test against staging

`.github/workflows/sdk-live-integration.yml` ("PHP SDK live integration (staging API, test
environment)") runs `php scripts/live-integration.php` against the **staging** API in
environment 00 on every pull request that is ready for review, and by hand
(`workflow_dispatch`: `emit-test-fe`, or `emit-enabled-dte-fixtures`). It issues one FE of
$0.01 (more with the fixture matrix), replays it, queries and lists it, downloads the Archivo DTE,
PDF and ticket, checks the managed copies byte for byte and, if an inbox is configured, the
e-mail delivery. The result is a sticky comment on the pull request ("PHP SDK staging live
test"). Steps that need local storage are listed as "Not in this SDK version".

Configuration, in the repository's `staging-live-test` **environment** (or as organization
secrets with the same names):

| Secret | Meaning |
| --- | --- |
| `STAGING_FACTA_API_KEY` | A `facta_test_...` key of the CI company. |
| `STAGING_FACTA_SIGN_KEY` | Its `factask_...` signing key. |
| `STAGING_FACTA_UNLOCK_KEY` | Its `factauk_...` key. Validated, unused until tier 2. |
| `STAGING_FACTA_DTE_FIXTURES_JSON` | Optional: fixtures for 03, 05, 06, 11, 14 (same format as the TypeScript SDK). |
| `STAGING_FACTA_DELIVERY_EMAIL` | Optional test inbox for the e-mail delivery check. |

The API URL is fixed in the workflow. If a secret is missing the run **fails** with "Required
integration input is unavailable: NAME"; it never passes silently. The guards (test key, exact
staging URL, known mode, run younger than the 22 h idempotency window) and the report renderer
are covered by offline unit tests, and the whole script is exercised against a fake API.

## Development

```bash
composer install
composer test        # PHPUnit; deprecations, notices and warnings fail the run
composer phpstan
composer compat      # PHPCompatibility, PHP 7.4 and later
composer build-zip   # dist/facta-api-php-vX.Y.Z.zip
```

See `CLAUDE.md` for the rules of this repository.

## En español

Cliente PHP para la API de Facta DTE (facturación electrónica de El Salvador). **No firma ni
calcula impuestos**: eso lo hace el servidor.

* **Requisitos:** PHP 7.4 o superior. No necesita extensiones especiales ni dependencias;
  usa `curl` si está disponible y, si no, los flujos nativos de PHP.
* **Con Composer:** `composer require facta-dte/api` y `require 'vendor/autoload.php';`.
* **Sin Composer (hosting compartido, cPanel, FTP):** descargue `facta-api-php-vX.Y.Z.zip` de
  la página de versiones, descomprímalo, suba la carpeta `facta-api-php/` y use
  `require_once 'facta-api-php/autoload.php';`.
* **Estado:** nivel 1. Todo lo que solo necesita HTTP está implementado: emitir (`issue()`,
  `prepare()`/`sign()`), consultar, descargar el Archivo DTE y el PDF, invalidar, eventos de
  retorno, envío por correo y WhatsApp, almacenamiento de Facta y catálogo. **Aún no** están los destinos propios (BYOS) ni el archivo local: necesitan la clave de
  desbloqueo y llegan en una versión posterior. Desde 0.2.0 el catálogo se lee siempre por el API
  (también cifrado y compartido con su llave), `customerId`/`productId` los resuelve el servidor,
  y hay lectura de cambios (`listCatalogChanges`) y webhooks con verificación de firma.
* **Nota de remisión (04) y comprobante de retención (07):** `issue()`, `prepare()`/`sign()` y los
  borradores aceptan `tipoDte` `"04"` y `"07"`; el cuerpo viaja tal como lo arma usted, sin firmar ni
  calcular nada. La 04 exige `receptor.bienTitulo` (CAT-025: 01 Depósito, 02 Propiedad, 03 Consignación,
  04 Traslado, 05 Otros). El 07 solo lo emite un agente de retención (si no, `403 retention_agent_required`,
  sin gastar correlativo ni reintentos); sus líneas llevan `montoSujetoGrav` y `codigoRetencionMH`
  (`22`, `C4`, `C9`), y `ivaRetenido` solo con `C9`. Los campos de pago que no les corresponden se
  ignoran y llegan como `ignoredFields` (`campo_ignorado`). Una nota de crédito o débito sobre un 07
  todavía la rechaza Hacienda: no la use. Detalle completo en la sección en inglés.
* **Copia temporal:** Facta guarda una copia temporal cifrada de cada documento sellado y la borra
  2 días después de que usted lo marque como guardado, o 30 días después de sellarlo si nadie lo
  marca. Guarde el JSON en su almacenamiento y avise con `confirmDocumentStorage()`, o deje que
  `storeAndConfirmUnconfirmed()` lo haga cada día con su función `store`. `listUnconfirmedDocuments()`
  muestra qué falta y cuándo se borra.
* **Prueba en vivo:** el flujo de GitHub Actions `sdk-live-integration.yml` emite una factura de
  prueba de $0.01 en el ambiente 00 del API de staging en cada pull request listo para
  revisión. Usa los secretos `STAGING_FACTA_API_KEY`, `STAGING_FACTA_SIGN_KEY` y
  `STAGING_FACTA_UNLOCK_KEY` (opcionales: `STAGING_FACTA_DTE_FIXTURES_JSON` y
  `STAGING_FACTA_DELIVERY_EMAIL`) del entorno `staging-live-test`; si falta uno, la ejecución
  falla en lugar de pasar en silencio.
* Si el servidor tiene certificados desactualizados, indique la ruta de un paquete de
  certificados en la opción `caBundle`. La verificación TLS nunca se desactiva.

## Licence

MIT. See `LICENSE`.
