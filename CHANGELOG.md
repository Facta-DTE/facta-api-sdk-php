# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
