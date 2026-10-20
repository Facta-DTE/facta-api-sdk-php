# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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
