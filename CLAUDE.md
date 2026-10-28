# facta-api-sdk-php — context for agents

PHP client for the Facta DTE API (El Salvador electronic invoicing). Package
`facta-dte/api`, namespace `Facta\Api`, MIT. Plan and decisions:
`docs/plan/tareas/php-sdk.md` in the FactaDTE repository.

**The reference behaviour is the TypeScript SDK** (`Facta-DTE/facta-api-sdk`:
`src/client.ts`, `src/errors.ts`). When the two disagree, PHP follows TypeScript
unless the plan says otherwise. Error `code` strings are identical. The HTTP
contract is `openapi.yaml` in FactaDTE. `tests/fixtures/openapi.yaml` is a snapshot of the staging contract (refresh it when the contract
changes; `ContractParityTest` fails on any new code or route). Shared conformance vectors
(`fingerprint-v1`, `envelopeDigest`) come from `facta-api-sdk/conformance/` and
are copied to `tests/fixtures/`; never edit a copy by hand.

## Hard rules

- **Never sign, never compute tax.** JWS signing, totals, IVA, correlatives and
  validation live on the server. Do not add signing primitives or fiscal field
  construction to `src/`. `tests/ArchitectureTest.php` fails on `openssl_sign`,
  `RS512`, `privateKey`, `ventaGravada`, `totalIva` and a `numeroControl` built
  in code.
- **PHP 7.4 minimum, clean on 8.0 to 8.5.** No union types, `match`, named
  arguments, nullsafe `?->`, constructor promotion, enums, `readonly`, `mixed`,
  `static` return type or `never`. Always `?Type $x = null`. No dynamic
  properties. `#[\ReturnTypeWillChange]` on `JsonSerializable` / `Countable` /
  `IteratorAggregate` / `ArrayAccess` methods. Do not call `curl_close()` on
  PHP 8+ (deprecated in 8.5).
- **No required extension beyond PHP core** (`json`, `hash`, `random_bytes`).
  `ext-curl` is preferred, PHP streams are the fallback, `ext-openssl` only for
  later vault decryption. No `mbstring`, `intl`, `bcmath`, `sodium`. No
  required Composer dependency: `require` has only `php`.
- **TLS verification is never disabled.** `caBundle` is the only knob.
- **A retry resends the same bytes.** Encode a body once per call and reuse the
  string; mint the `Idempotency-Key` once, outside the retry loop.
- Retryable errors are exactly the TypeScript `RETRYABLE` set (`client.ts`): `idempotency_in_flight`,
  `mh_unreachable`, `service_unavailable`, `correlative_unavailable`, `storage_unavailable`, `data_grant_unavailable`, `data_epoch_stale` and
  `network_error` (transport failures). Safe because every attempt resends the same bytes with the
  same `Idempotency-Key`. Everything else is final.
- Secrets never reach a message or `details` (`Support\Redactor`).
- Developer-facing text (messages, comments, docs) is English; the README has a
  short Spanish section. Product UI copy is not this repository's business.

## Commands

    composer install
    composer test         # PHPUnit 9.6, deprecations are failures
    composer phpstan
    composer compat       # PHPCompatibility, testVersion 7.4-
    composer build-zip    # dist/facta-api-php-vX.Y.Z.zip
    php scripts/zip-smoke.php dist/facta-api-php-vX.Y.Z.zip

Live integration (staging, environment 00, run by the PR workflow
`sdk-live-integration.yml`): `scripts/live-integration.php` with its helpers in
`scripts/live/` (namespace `Facta\Api\Live`). It uses only the public API, reads the same
`STAGING_FACTA_*` secrets as the TypeScript script and fails closed when one is missing. Its
guards, report and whole flow are unit-tested offline (`tests/Live*Test.php`, `FakeApi`).
Results are plain associative arrays; `prepare()` keeps `documento` as the decoded `\stdClass`.

Releases: tag `vX.Y.Z` (Packagist reads the tag; the GitHub Release attaches the ZIP).
Pushing is done by the maintainers' account; sessions may lack permission.
