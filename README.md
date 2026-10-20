# Facta DTE API client for PHP

PHP client for the [Facta DTE](https://factadte.com) API, El Salvador's electronic
invoicing service. It talks to the API over HTTPS; **it never signs a document and never
computes tax**. Signing, totals, IVA, correlatives and validation happen on the server.

> **Status: skeleton (0.1.0-dev).** Only `status()` is implemented, to prove the transport,
> the retry policy and the JSON canonicalization. Issuing, queries, invalidation, delivery
> and the catalog arrive in the next releases. Nothing is published to Packagist yet.

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
    'apiKey' => getenv('FACTA_API_KEY'),   // facta_test_... (environment 00) or facta_live_... (01)
    // 'signKey'   => getenv('FACTA_SIGN_KEY'),    // factask_..., sent only on routes that sign
    // 'timeoutMs' => 60000,
    // 'maxRetries' => 3,
]);

try {
    $status = $facta->status();           // \stdClass, as decoded from the API
    echo $status->ambiente, "\n";
} catch (FactaError $e) {
    echo $e->getErrorCode(), ' (HTTP ', $e->getStatus(), '): ', $e->getMessage(), "\n";
}
```

Responses are decoded with objects as `\stdClass`, so an empty object `{}` never turns into
an empty array `[]`. To send an empty JSON object in a request, pass `new \stdClass()`.

### Options

| Option | Meaning |
| --- | --- |
| `apiKey` | Required. `facta_test_...` or `facta_live_...`. |
| `signKey` | `factask_...`. Sent only to the routes that sign (`POST /v1/dte`, `/v1/dte/sign`, `.../invalidate`, `.../return`). A `factauk_` unlock key is refused here. |
| `unlockKey` | `factauk_...`. Stored for later releases; never sent to the server. |
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
* **Estado:** esqueleto. Hoy solo existe `status()`; la emisión y el resto de operaciones se
  agregan en las próximas versiones.
* Si el servidor tiene certificados desactualizados, indique la ruta de un paquete de
  certificados en la opción `caBundle`. La verificación TLS nunca se desactiva.

## Licence

MIT. See `LICENSE`.
