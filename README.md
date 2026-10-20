# Facta DTE API client for PHP

PHP client for the [Facta DTE](https://factadte.com) API, El Salvador's electronic
invoicing service. It talks to the API over HTTPS; **it never signs a document and never
computes tax**. Signing, totals, IVA, correlatives and validation happen on the server.

> **Status: tier 1 (0.1.0-dev).** Everything that needs only HTTP is implemented: issuing,
> prepare/sign, queries, downloads, invalidation, returns, delivery, managed storage and the
> catalog (see [Methods](#methods)). What needs the unlock key or local storage is **not**
> there yet: local catalog snapshots, BYOS destinations and the restart-safe archive. Nothing
> is published to Packagist yet.

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

### Methods

| Method | Route | Notes |
| --- | --- | --- |
| `status()` | `GET /v1/status` | Health, environment, limits. |
| `region()` / `servedRegion()` | none | The pinned `x-region`; the region that served the last answer. No discovery call. |
| `diagnose($options)` | `/v1/status`, `/v1/storage/status` | Readiness report. Checks that need local storage say "not available in this SDK version yet". |
| `issue($request, $options)` | `POST /v1/dte` | `idempotencyKey`, `deliver`. Sends `X-Facta-Request-Fingerprint`. |
| `prepare($request)` / `sign($prepared)` | `POST /v1/dte/prepare`, `/sign` | Two-step issuing. `documento` stays the decoded object tree (see below). |
| `getDocumentStatus($code)` | `GET /v1/dte/{code}` | Also answers for rejected documents. |
| `listDocuments($filters)` | `GET /v1/dte` | One page; pass `siguiente` back as `cursor`. |
| `downloadDocument($code, $kind, $options)` | `GET /v1/dte/{code}/file` | `json` (Archivo DTE), `pdf`, `ticket`. Exact bytes, never re-serialized. |
| `invalidate($code, $request)` | `POST .../invalidate` | Sends the sign key. |
| `registerReturn($code, $request)` | `POST .../return` | Sends the sign key. |
| `deliverEmail()` / `deliverWhatsApp()` / `getDelivery()` / `waitForDelivery()` | `.../entrega` | Token from `issue(..., ['deliver' => ...])`. |
| `getStorageStatus()` / `getDocumentCopies()` / `retryDocumentStorage()` | `/v1/storage/...` | Facta Storage coverage and repair. |
| `listCustomers()` ... `searchProducts()` | `/v1/customers`, `/v1/products` | Only when the key's catalog is **readable or plain**. |
| `createCustomer()` ... `deactivateProduct()` | same | Needs `catalog:write` and a plain-text catalog. |
| `listHolding()` / `getContract()` | `/v1/dte/holding`, `/v1/openapi.json` | |

Per-call options accepted by every method: `timeoutMs`, `headers`; the writing ones also
`idempotencyKey`. An unknown option throws `InvalidArgumentException`, so a typo is not silently
ignored.

#### Idempotency and retries

`issue()` mints one `Idempotency-Key` per call (or uses yours) and every retry resends the same
bytes with the same key. It also sends `X-Facta-Request-Fingerprint`, the SHA-256 of the RFC 8785
(JCS) form of your request before any catalog id is resolved, so a retry still matches after a
catalog edit. Only `POST /v1/dte` and `POST /v1/dte/prepare` carry it.

#### prepare / sign

`prepare()` returns the plain members of the answer as an array, but `documento` is the decoded
`\stdClass` tree, on purpose: an empty object `{}` inside it must not turn into `[]` when it goes
back to `sign()`. Pass the array to `sign()` unchanged. If you store it between the two calls, use
`json_encode` and `json_decode($text)` (objects), never `json_decode($text, true)`.

#### Catalog references

`customerId` / `productId` in a request are resolved by the server when the key's catalog is
readable or plain. For an **encrypted** catalog the TypeScript SDK resolves them from a local
snapshot opened with the unlock key; that is not in this version, so `issue()` and the catalog
reads throw `FactaError` `unauthorized` (`details.reason`) before anything is sent. Send the
customer and the items inline instead.

### Options

| Option | Meaning |
| --- | --- |
| `apiKey` | Required. `facta_test_...` or `facta_live_...`. |
| `signKey` | `factask_...`. Sent only to the routes that sign (`POST /v1/dte`, `/v1/dte/sign`, `.../invalidate`, `.../return`). A `factauk_` unlock key is refused here. |
| `unlockKey` | `factauk_...`. Reserved for the next tier (local catalog and destinations); never sent to the server. |
| `baseUrl` | API root (default: the public Facta API). |
| `timeoutMs` | Budget of one attempt, default `60000`. |
| `maxRetries` | Extra attempts for retryable errors, default `3`. |
| `region` | Functions region sent as `x-region`; `false` sends none. Defaults to `FACTA_API_REGION`, then the built-in region. |
| `transport` | A `Facta\Api\Http\Transport`, e.g. `new Psr18Transport($client, $requestFactory, $streamFactory)`. |
| `caBundle` | Path to a CA bundle, for servers with an outdated certificate store. |

### Errors and retries

Every failure is a `Facta\Api\FactaError`. Branch on `getErrorCode()` (the strings are the
same as in the TypeScript SDK: `mh_rejected`, `validation_failed`, `idempotency_key_reuse`,
`network_error`, ...), never on the message. The client retries only
`idempotency_in_flight` and `mh_unreachable`, waiting for `Retry-After` when the server
sends it. A network failure or timeout is reported as `network_error` and is not retried:
the request may have arrived, so repeat it yourself with the same `Idempotency-Key`.

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
  retorno, envío por correo y WhatsApp, almacenamiento de Facta y catálogo. **Aún no** están el
  catálogo local cifrado, los destinos propios (BYOS) ni el archivo local: necesitan la clave de
  desbloqueo y llegan en una versión posterior.
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
