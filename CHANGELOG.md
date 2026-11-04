# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Additive. Follows the TypeScript SDK (remission note 04 and retention receipt 07).

### Added

- **`tipoDte` `"04"` (Nota de remisión) and `"07"` (Comprobante de retención)** on `issue()`, `prepare()`/`sign()` and the drafts. Requests are arrays sent as given; the SDK signs nothing and computes no amount, tax or `ivaRetenido`. 04: `receptor.bienTitulo` (CAT-025) required, `nrc`/`correo`/`direccion` optional, items like a CCF, optional `documentosRelacionados` (01/03/11) and `descuentoGlobal`. 07: retention agents only, supplier receiver, withholding lines (`montoSujetoGrav`, `codigoRetencionMH` 22/C4/C9, `ivaRetenido` only with C9, an all-or-nothing reference group, up to 500). Documented in the README with Normativa-versus-Facta labels and common mistakes.
- `BienTitulo` and `RetentionCode` constant classes (CAT-025, CAT-006).
- `FactaError::RETENTION_AGENT_REQUIRED` (`retention_agent_required`, HTTP 403): a 07 from a company that is not a retention agent, refused before any number is spent. Final, never retried.
- **`campo_ignorado` warnings.** Payment fields sent on a 04 or 07 (and `descuentoGlobal` on a 07) are ignored by the server; the SDK lists them in `ignoredFields` on `issue()`, `prepare()`, `sign()`, `issueDraft()`, `createDraft()` and `updateDraft()` results, and `FactaError::getIgnoredFields()` reads them from a failure. `createDraft()`/`updateDraft()` results now also carry `unknownFields`/`factaWarning` when there is something to report.
- `tests/fixtures/openapi.yaml`: only the new error code was added (the full current contract also lists routes this branch does not wrap yet).

### Known limit

- A credit or debit note (05/06) related to a sealed 07 is accepted by the API but rejected by Hacienda today; it is documented as not working yet.

## [0.2.0-rc.3] - 2026-11-01

Additive only: every existing call, type and default keeps working. Follows the TypeScript SDK (Facta-DTE/facta-api-sdk#73).

### Added

- **The temporary copy and the storage confirmation.** Facta keeps an encrypted temporary copy of every sealed document and deletes it **2 days after the document is marked as stored**, or **30 days after sealing** if nobody marks it. New: `listUnconfirmedDocuments()` (`GET /v1/storage/unconfirmed`), `confirmDocumentStorage($code, ['where' => ..., 'sha256' => ...|'bytes' => ...])` (`POST /v1/dte/{code}/storage-confirmation`; throws `storage_unsupported` without posting on a server that does not advertise `capabilities.storageConfirmation`) and `storeAndConfirmUnconfirmed(['store' => callable, ...])`, which downloads each document, hands it to your callable and confirms with the SHA-256 of the stored bytes; it returns a report and never throws for one document.
- `FactaError::TEMPORARY_COPY_EXPIRED` (410), `NOTHING_TO_REPAIR_FROM` (409) and `STORAGE_HASH_MISMATCH` (422). None is retried.
- `getStorageStatus()` passes through `capabilities.storageConfirmation` and `sinConfirmar`; `getDocumentCopies()` passes through `confirmado`.
- `tests/fixtures/openapi.yaml` refreshed with the two routes and the three codes (`ContractParityTest`).

No `/byos` report gate exists in this SDK (BYOS destinations are not implemented yet), so the `=== 1` capability bug fixed in the TypeScript SDK has no counterpart here.

- `Facta::PRODUCTION_BASE_URL` names the existing production endpoint and aliases `Facta::DEFAULT_BASE_URL`; the production default and opt-in `Facta::STAGING_BASE_URL` remain unchanged.

## [0.2.0-rc.2] - 2026-10-29

**Release candidate** (pre-release tag; the stable release stays at 0.1.0). Additive only: every call, constant, option and return shape of 0.1.0 and 0.2.0-rc.1 keeps working (`tests/BackwardCompatTest.php`). Matches the staging API of 29-Oct-2026.

### Added

- `items[].montoDescu` (discount of a line in money), `pagos` with the new `Pago` value object (plain arrays still work), `items[].tipoVenta` with the `SaleType` constants, and `aplicarRetencionIva` (01, 03) / `aplicarPercepcionIva` (03). The request is still a plain array that travels untouched: the server owns every rule.
- `campo_desconocido` warnings: `issue()`, `prepare()`, `sign()` and `issueDraft()` results gain `unknownFields` (`campo`, `mensaje`, `sugerencia`) and `factaWarning` (the parsed `Facta-Warning` header) only when there is something to report. `FactaError::getUnknownFields()` reads `details.advertencias` and `getFactaWarning()` the header. Unknown fields stay warnings: they are never rejected.
- `Facta::STAGING_BASE_URL`. The default base URL is still production.
- `FactaError` takes an optional sixth constructor argument (the header); existing calls are unchanged.

## [0.2.0-rc.1] - 2026-10-28

**Release candidate.** Tagged as a pre-release: Composer's default `stable` stability and constraints such as `^0.1` do not select it. Install it explicitly with `composer require facta-dte/api:0.2.0-rc.1`. The stable release stays at 0.1.0 until the shared data key is in production. The notes below are the planned 0.2.0 notes.

The shared data key: the server resolves catalogue ids and reads the catalogue in every mode, so the
SDK carries no copy of it. Follows the TypeScript SDK 0.6.0.

### Changed

- **Catalogue reads always call the API** (`listCustomers()` ... `searchProducts()`). They no longer
  read `/v1/status` first, no longer need `unlockKey`, and no longer throw `unauthorized`
  (`local_snapshot_unsupported` / `unlock_key_missing`) for a company whose catalogue is encrypted at rest.
- **`customerId` / `productId` in `issue()` and `prepare()` travel untouched.** The same gate no
  longer refuses them. An unknown id is the server's `not_found` (`details.reason` `catalog_unknown_id`).
- **No `X-Facta-Request-Fingerprint` header** on `issue()` / `prepare()`: the SDK does not rewrite the
  body, so the server's idempotency claim hashes it. `Json\Fingerprint` stays for other uses.
- `diagnose()` no longer reports a `catalog-sync` check. The guidance of `catalog_encrypted` no longer
  says the catalogue can be read with the unlock key.

### Added

- Error codes the server now returns: `catalog_revision_conflict`, `catalog_record_inactive`,
  `catalog_search_too_broad`, `catalog_cursor_expired`, `data_grant_unavailable`, `data_grant_revoked`,
  `data_epoch_stale`, `inbox_retired` (constants on `FactaError`).
- **Drafts** (scope `draft`): `createDraft()`, `listDrafts()`, `getDraft()`, `getDraftByKey()`, `updateDraft()` (`version` sent as `If-Match`), `cancelDraft()` and `issueDraft()` (carries the sign key, answers like `issue()` plus `borradorId`). Constants for the draft codes (`DRAFTS_NOT_READY`, `VERSION_MISMATCH`, `DRAFT_CLAIMED`, `DRAFT_ISSUING`, `DRAFT_ISSUED`, `DRAFT_NOT_ISSUABLE`, `ITEM_FINAL`, `ID_CONFLICT`, `INBOX_CAP_REACHED`, `CONTENT_HASH_MISMATCH`, `CATALOG_WRITE_TEST_KEY`).
- Every code the server can answer is a `FactaError` constant, including `MH_AUTH_FAILED` (424), `PAID_PLAN_REQUIRED` (402) and the Drive broker codes. `CATALOG_SNAPSHOT_RETIRED` is removed: the server never sent it.
- `tests/ContractParityTest.php` pins the SDK to a snapshot of the staging contract (`tests/fixtures/openapi.yaml`): error codes both ways, the retry set (the TypeScript one) and every route wrapped or deliberately not wrapped.
- `ifMatch` option on `updateCustomer()` / `updateProduct()`, and `revision` on single catalogue records
  read or written on a company that shares its data key (from `Facta-Catalog-Revision` / `ETag`).
- `listCatalogChanges()` for `GET /v1/catalog/changes?since=`.
- `FactaError::WEBHOOK_LIMIT_REACHED`, `WEBHOOK_DUPLICATE`; `Webhook::EVENTS`; `WebhookVerificationError` (with `getReason()`) thrown by `Webhook::constructEvent()`.
- `data_grant_unavailable` and `data_epoch_stale` (503) are retried like the other transient codes; `data_grant_revoked` (403) is final.
- Webhooks: `createWebhook()`, `listWebhooks()`, `getWebhook()`, `updateWebhook()`, `deleteWebhook()`,
  `rotateWebhookSecret()`, `testWebhook()`, and the receiver-side `Webhook::verify()` /
  `Webhook::constructEvent()` / `Webhook::sign()` with `WebhookReplayStore` / `MemoryWebhookReplayStore`.

## [0.1.0] - 2026-10-20

### Changed

- `status()` returns an associative array, like every other method (it returned a `\stdClass`).

### Not yet

- Local catalog snapshots, BYOS destinations and the restart-safe archive: they need the unlock
  key. Until then, reading an encrypted catalog or issuing with `customerId` / `productId`
  against one throws `unauthorized` (`details.reason`).

### Added

- Tier 1, everything that needs only HTTP: `region()`, `servedRegion()`, `diagnose()`,
  `issue()` (caller-owned `idempotencyKey`, `deliver`), `prepare()` / `sign()`,
  `getDocumentStatus()`, `listDocuments()`, byte-exact `downloadDocument()` (`json`, `pdf`,
  `ticket`), `invalidate()`, `registerReturn()`, `deliverEmail()`, `deliverWhatsApp()`,
  `getDelivery()`, `waitForDelivery()`, `getStorageStatus()`, `getDocumentCopies()`,
  `retryDocumentStorage()`, `listHolding()`, `getContract()` and the catalog reads and
  writes (`listCustomers()` ... `deactivateProduct()`).
- `X-Facta-Request-Fingerprint` (fingerprint-v1 of the unresolved request, `entrega` included) on
  `POST /v1/dte` and `POST /v1/dte/prepare` only.
- Storage receipts are checked against the contract and against the bytes in the same answer; a
  receipt that does not match is replaced by `storageErrorCode`.
- Live integration against the staging API: `scripts/live-integration.php`, its guards and report
  (`scripts/live/`), and the workflow `sdk-live-integration.yml` with a sticky pull-request comment.
- Package skeleton: `facta-dte/api`, namespace `Facta\Api`, PHP 7.4 and later,
  no required Composer dependency.
- `Facta` client with `status()` (`GET /v1/status`) and the internal request loop:
  retries of `idempotency_in_flight` and `mh_unreachable` honouring `Retry-After`,
  per-attempt timeouts, `x-region`, `X-Facta-Key` and `X-Facta-Sign-Key` (only on
  the routes that sign), one `Idempotency-Key` per call reused across retries.
- `FactaError` with the error codes of the TypeScript SDK and secret redaction.
- HTTP transports: cURL, PHP streams (fallback without `ext-curl`) and PSR-18.
- `Json\Jcs` (RFC 8785) and `Json\Fingerprint::v1()`, validated against the shared
  conformance vectors of `facta-api-sdk`.
- `autoload.php` and `scripts/build-zip.php` for installs without Composer.
