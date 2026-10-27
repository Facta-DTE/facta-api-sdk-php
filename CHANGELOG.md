# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - proposed

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
  `data_epoch_stale`, `inbox_retired`, `catalog_snapshot_retired` (constants on `FactaError`).
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
