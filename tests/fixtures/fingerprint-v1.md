# fingerprint-v1 and envelopeDigest: conformance specification

Language-independent rules every Facta SDK must reproduce byte for byte. The
shared test vectors are in `fingerprint-v1.vectors.json`, generated from the
TypeScript reference (`src/jcs.ts`) by `scripts/build-conformance-vectors.mjs`.
Never edit that file by hand; a test fails when it drifts from the generator.

## 1. fingerprint-v1

**fingerprint-v1** is the lowercase hexadecimal SHA-256 of the UTF-8 bytes of
the RFC 8785 (JCS) serialization of the caller's request.

"The caller's request" is exactly the JSON body the SDK would send for
`POST /v1/dte` or `POST /v1/dte/prepare` if **no catalog resolution** happened:

- `customerId` stays as `customerId` (it is not replaced by the receptor
  fields) and `productId` stays as `productId` (not replaced by item fields).
- Everything else the caller supplied is included, delivery options too. When
  an SDK adds delivery to the request (`deliver` becoming `entrega`), the
  fingerprint is taken **after** that step, so `entrega` is part of it.
- Nothing is added that the caller did not supply (no generated defaults, no
  idempotency key, no timestamps).

The SDK sends it in the header `X-Facta-Request-Fingerprint`, only on
`POST /v1/dte` and `POST /v1/dte/prepare`. The server stores it as the
idempotency hash of that claim instead of hashing the raw body. Because it does
not depend on the bytes actually sent, a catalog edit between an attempt and
its retry, or a different JSON library, no longer turns a retry into
`422 idempotency_key_reuse`.

The request body itself is unchanged and a retry resends identical bytes.

### Compatibility

A claim recorded by an SDK that did not send the header was hashed by body. A
client that recovers such an operation must **not** send the header (the server
would compare a fingerprint with a body hash). SDKs that keep a journal record
which mode each operation used (`idempotencyHash: "body" | "fingerprint-v1"`;
a record without the field means `"body"`).

## 2. RFC 8785 notes for implementers

The serialization has no whitespace. Objects are written with sorted keys,
arrays keep their order. Details that differ from a default `json_encode` in
many languages:

- **Numbers** use ECMAScript `Number.prototype.toString` formatting of the
  IEEE 754 double: `10.0` and `1E1` become `10`; `1.50` becomes `1.5`; `-0`
  becomes `0`; small and large magnitudes switch to exponent form exactly as
  ECMAScript does (`0.000001` stays, `0.0000001` becomes `1e-7`; below `1e21`
  digits are written in full, from `1e21` on it is `1e+21`). The exponent has
  an explicit sign when positive (`1e+21`, `1.7976931348623157e+308`). The
  formatter must not depend on the language's float-printing precision setting
  (for example PHP `serialize_precision`). `NaN` and infinities are an error.
  Integers beyond 2^53 are parsed as doubles first and so lose precision, the
  same as in JavaScript; callers must not send them.
- **Key order** is by UTF-16 code units, not by UTF-8 bytes or code points.
  `"😀"` (U+1F600, units D83D DE00) sorts **before** `"￿"`, although its
  UTF-8 bytes sort after. Compare the UTF-16 sequences.
- **Strings** are escaped minimally: only `"` (as `\"`), `\` (as `\\`) and
  U+0000 to U+001F. Of those, backspace, form feed, line feed, carriage return
  and tab use the short forms `\b \f \n \r \t`; the others use `\u00xx` with
  **lowercase** hex digits. `/` is not escaped. U+007F, U+2028 and U+2029 are
  written literally. Every other character, including non-ASCII, is written as
  UTF-8.
- **Literals** are `true`, `false`, `null`. A field that is `null` is kept; a
  field that was omitted stays omitted. `{}` and `[]` stay distinct, so decode
  responses and requests in a way that preserves the difference (PHP:
  `json_decode($raw, false)`).
- A key whose value is "undefined" (a language without that concept does not
  have this case) is omitted.

## 3. envelopeDigest

The vault and catalog bundles the API publishes carry an `envelopeDigest`. The
SDK verifies it before opening the data:

```
envelopeDigest = lowercase hex SHA-256 of the UTF-8 bytes of
                 JSON.stringify([envelope])
```

where `envelope` is an object with the keys written **in this fixed order**:
`id, kind, iv, ciphertext, formatVersion, revision`. Values are the strings and
integers received, unchanged. It is plain `JSON.stringify` (no JCS): no
whitespace, and `/` is **not** escaped, which matters because base64 contains
`/`. In PHP, `json_encode([$envelope], JSON_UNESCAPED_SLASHES)` with the same key
order is byte-identical.

## 4. Vectors file

`fingerprint-v1.vectors.json`:

- `fingerprintV1[]`: `{ name, input, jcs, sha256 }`. `input` is raw JSON text,
  kept as text so that `10.0` versus `10`, `{}` versus `[]`, key order and
  escapes survive. An implementation parses `input` with its own JSON parser,
  serializes with its JCS code and must produce `jcs` exactly, and `sha256`
  of those bytes. Includes the RFC 8785 Appendix B number examples.
- `envelopeDigest[]`: `{ name, envelope, serialized, digest }`. Build the
  `serialized` text from `envelope` in the fixed key order and check the digest.
