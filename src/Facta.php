<?php

declare(strict_types=1);

namespace Facta\Api;

use Facta\Api\Http\Response;
use Facta\Api\Http\Transport;
use Facta\Api\Http\TransportException;
use Facta\Api\Http\TransportFactory;
use Facta\Api\Json\Fingerprint;
use Facta\Api\Json\Json;
use Facta\Api\Support\Catalog;
use Facta\Api\Support\Delivery;
use Facta\Api\Support\Diagnostics;
use Facta\Api\Support\ManagedStorage;
use Facta\Api\Support\Redactor;

/**
 * Client for the Facta DTE API.
 *
 * The reference behaviour is the TypeScript SDK (Facta-DTE/facta-api-sdk). This
 * client never signs a document and never computes tax: the server does both.
 *
 * Options (all optional except `apiKey`):
 *  - apiKey     string  `facta_test_...` (environment 00) or `facta_live_...` (01).
 *  - signKey    string  `factask_...`, sent only on the routes that sign.
 *  - unlockKey  string  `factauk_...`; stored for later releases, never sent.
 *  - baseUrl    string  API root; defaults to the public Facta API.
 *  - timeoutMs  int     Budget of ONE attempt. Default 60000.
 *  - maxRetries int     Extra attempts for retryable errors. Default 3.
 *  - region     string|false  Functions region sent as `x-region`; `false` sends none.
 *                       Default: `FACTA_API_REGION`, else the built-in region.
 *  - transport  Transport  Replace the HTTP transport (e.g. a Psr18Transport).
 *  - caBundle   string  Path to a CA bundle, for hosts with an outdated trust store.
 *  - sleeper    callable(int $ms): void  Replaces the pause between retries (tests).
 */
final class Facta
{
    public const VERSION = '0.1.0-dev';

    public const DEFAULT_BASE_URL = 'https://hcnvknpsbadplnfcflxx.supabase.co/functions/v1/api-v1';

    private const DEFAULT_REGION = 'us-west-2';
    private const REGION_PATTERN = '/^[a-z]{2}(?:-[a-z]+)+-\d+$/';
    private const MAX_RETRY_AFTER_MS = 60000;
    private const SERVER_CATALOG_PAGE = 200;
    private const CATALOG_MODE_TTL_SECONDS = 60;

    /** Header carrying fingerprint-v1 (see conformance/fingerprint-v1.md). */
    private const FINGERPRINT_HEADER = 'X-Facta-Request-Fingerprint';
    /** The only routes whose idempotency claim may be hashed by fingerprint. */
    private const FINGERPRINT_PATHS = ['/v1/dte', '/v1/dte/prepare'];

    private const OPTION_KEYS = [
        'apiKey', 'signKey', 'unlockKey', 'baseUrl', 'timeoutMs', 'maxRetries',
        'region', 'transport', 'caBundle', 'sleeper',
    ];

    /** Errors worth repeating. Exactly the TypeScript SDK's set; everything else is final. */
    private const RETRYABLE = [
        FactaError::IDEMPOTENCY_IN_FLIGHT,
        FactaError::MH_UNREACHABLE,
        FactaError::SERVICE_UNAVAILABLE,
        FactaError::CORRELATIVE_UNAVAILABLE,
        FactaError::STORAGE_UNAVAILABLE,
        FactaError::NETWORK_ERROR,
    ];

    /** The routes that open the signing vault and therefore carry the second password. */
    private const SIGNING_PATHS = ['/v1/dte', '/v1/dte/sign'];
    private const SIGNING_EVENT_PATH = '#^/v1/dte/[^/?]+/(?:invalidate|return)$#';

    /** @var string */
    private $apiKey;
    /** @var string|null */
    private $signKey;
    /** @var string|null */
    private $unlockKey;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeoutMs;
    /** @var int */
    private $maxRetries;
    /** @var string|null */
    private $region;
    /** @var Transport */
    private $transport;
    /** @var callable(int): void */
    private $sleeper;
    /** @var string[] */
    private $secrets;
    /** @var string|null */
    private $servedRegion = null;
    /** @var array{mode: string|null, at: int}|null */
    private $catalogModeCache = null;

    /**
     * @param array<string, mixed> $options
     * @throws FactaError                      On a missing or malformed key.
     * @throws \InvalidArgumentException       On an unknown or malformed option.
     * @throws UnsupportedEnvironmentException When this PHP has no way to make HTTP requests.
     */
    public function __construct(array $options)
    {
        foreach (array_keys($options) as $name) {
            if (!in_array($name, self::OPTION_KEYS, true)) {
                throw new \InvalidArgumentException('Unknown Facta option: ' . $name . '.');
            }
        }

        $apiKey = $options['apiKey'] ?? null;
        if (!is_string($apiKey) || $apiKey === '') {
            throw new FactaError(FactaError::UNAUTHORIZED, 'API key is required.', 0);
        }
        if (strpos($apiKey, 'facta_test_') !== 0 && strpos($apiKey, 'facta_live_') !== 0) {
            throw new FactaError(
                FactaError::INVALID_API_KEY,
                'API key must start with facta_test_ (environment 00) or facta_live_ (environment 01).',
                0
            );
        }
        $this->apiKey = $apiKey;

        $signKey = $options['signKey'] ?? null;
        if ($signKey !== null) {
            if (!is_string($signKey)) {
                throw new \InvalidArgumentException('signKey must be a string.');
            }
            if (strpos($signKey, 'factauk_') === 0) {
                // Refused here, before it could travel: the one mistake that cannot be undone later.
                throw new FactaError(
                    FactaError::UNAUTHORIZED,
                    'This is your FACTA_UNLOCK_KEY (factauk_), which opens your local storage and is never sent. '
                    . 'Use FACTA_SIGN_KEY (factask_) for signing.',
                    0
                );
            }
        }
        $this->signKey = $signKey;

        $unlockKey = $options['unlockKey'] ?? null;
        if ($unlockKey !== null && (!is_string($unlockKey) || strpos($unlockKey, 'factauk_') !== 0)) {
            throw new FactaError(FactaError::UNAUTHORIZED, 'FACTA_UNLOCK_KEY must start with factauk_.', 0);
        }
        $this->unlockKey = $unlockKey;

        $this->secrets = array_values(array_filter(
            [$this->apiKey, (string) $this->signKey, (string) $this->unlockKey],
            static function (string $secret): bool {
                return $secret !== '';
            }
        ));

        $this->baseUrl = self::validateBaseUrl($options['baseUrl'] ?? self::DEFAULT_BASE_URL);

        $timeoutMs = $options['timeoutMs'] ?? 60000;
        if (!is_int($timeoutMs) || $timeoutMs < 1) {
            throw new \InvalidArgumentException('timeoutMs must be a positive integer.');
        }
        $this->timeoutMs = $timeoutMs;

        $maxRetries = $options['maxRetries'] ?? 3;
        if (!is_int($maxRetries) || $maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be a non-negative integer.');
        }
        $this->maxRetries = $maxRetries;

        $this->region = $this->resolveRegion(array_key_exists('region', $options) ? $options['region'] : null);

        $caBundle = $options['caBundle'] ?? null;
        if ($caBundle !== null && (!is_string($caBundle) || !is_file($caBundle))) {
            throw new \InvalidArgumentException('caBundle must be the path to an existing CA bundle file.');
        }

        $transport = $options['transport'] ?? null;
        if ($transport !== null && !($transport instanceof Transport)) {
            throw new \InvalidArgumentException('transport must implement ' . Transport::class . '.');
        }
        $this->transport = $transport ?? TransportFactory::create($caBundle);

        $sleeper = $options['sleeper'] ?? null;
        if ($sleeper !== null && !is_callable($sleeper)) {
            throw new \InvalidArgumentException('sleeper must be callable.');
        }
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * The environment the API key selects: "00" (tests) or "01" (production).
     */
    public function environment(): string
    {
        return strpos($this->apiKey, 'facta_test_') === 0 ? '00' : '01';
    }

    /**
     * The functions region sent as `x-region`: the `region` option, else
     * `FACTA_API_REGION`, else the built-in region; `null` when disabled with
     * `region => false`.
     *
     * Unlike the TypeScript SDK this does not ask `/v1/status` for the region:
     * a PHP process lives for one request, so a discovery call per request
     * would cost more than it saves. This never makes a network request.
     */
    public function region(): ?string
    {
        return $this->region;
    }

    /**
     * The region that actually served the latest response (`x-sb-edge-region`),
     * or null before any answer or when the header is absent.
     */
    public function servedRegion(): ?string
    {
        return $this->servedRegion;
    }

    // --- Health and readiness ------------------------------------------------------------------

    /**
     * Health, environment and the ceilings left on this key (`GET /v1/status`).
     *
     * @param array<string, mixed> $options Per-call options: `timeoutMs`, `headers`.
     * @return array<string, mixed> The decoded answer: `ok`, `version`, `ambiente`, `emisor`,
     *                              `llave` (`alcances`, `tiposDte`, `catalogMode`, ...), `firma`,
     *                              `sincronizacion`, `limites`, and `region` when advertised.
     * @throws FactaError
     */
    public function status(array $options = []): array
    {
        $status = $this->callArray('GET', '/v1/status', null, $this->options($options, []));
        return $status;
    }

    /**
     * Actionable readiness checks over `/v1/status` and the storage capability.
     * It reads, and never opens a vault or reserves a correlative.
     *
     * Checks that need local storage (the archive, an encrypted catalog
     * snapshot) are reported as `unknown` with "not available in this SDK
     * version yet"; `canIssueAndArchive` is therefore always false.
     *
     * @param array<string, mixed> $options `expectedEnvironment` ("00"|"01"), `requiredScopes` (string[]),
     *                                      `dteType` (e.g. "01").
     * @return array{overall: string, canIssue: bool, canQuery: bool, canDownload: bool, canIssueAndArchive: bool,
     *               pendingArchiveOperations: null, revisions: array<string, mixed>|null,
     *               checks: array<int, array{id: string, state: string, message: string}>,
     *               storageReady?: bool|null, catalogMode?: string|null, region?: string|null, servedRegion?: string|null}
     */
    public function diagnose(array $options = []): array
    {
        $options = $this->options($options, ['expectedEnvironment', 'requiredScopes', 'dteType']);
        try {
            $status = $this->status();
        } catch (FactaError $cause) {
            return [
                'overall' => 'blocked',
                'canIssue' => false,
                'canQuery' => false,
                'canDownload' => false,
                'canIssueAndArchive' => false,
                'pendingArchiveOperations' => null,
                'revisions' => null,
                'catalogMode' => $this->catalogModeCache !== null ? $this->catalogModeCache['mode'] : null,
                'servedRegion' => $this->servedRegion,
                'checks' => [[
                    'id' => 'api',
                    'state' => 'blocked',
                    'message' => 'Could not read the API status (' . $cause->getErrorCode() . ').',
                ]],
            ];
        }

        $storage = null;
        $storageFailure = null;
        try {
            $storage = $this->getStorageStatus();
        } catch (FactaError $cause) {
            $storageFailure = $cause;
        }
        $managedReady = $storage !== null && $storage['managed']['ready'] === true;
        $report = Diagnostics::fromStatus($status, $options, $managedReady);

        $storageReady = null;
        if ($storage !== null) {
            $storageReady = $managedReady || $storage['byos']['ready'] === true;
            $report['checks'][] = [
                'id' => 'managed-storage',
                'state' => $storageReady ? 'ok' : 'blocked',
                'message' => $storageReady
                    ? ($managedReady ? 'Facta-managed storage is ready.' : 'A verified BYOS destination is ready.')
                    : 'Neither Facta-managed storage nor a verified BYOS destination is ready.',
            ];
            if (!$storageReady) {
                $report['canIssue'] = false;
                $report['overall'] = 'blocked';
            }
        } else {
            $missing = in_array(
                $storageFailure->getErrorCode(),
                [FactaError::STORAGE_UNSUPPORTED, FactaError::NOT_FOUND, FactaError::FORBIDDEN_SCOPE],
                true
            );
            $report['checks'][] = [
                'id' => 'managed-storage',
                'state' => $missing ? 'unknown' : 'blocked',
                'message' => $missing
                    ? 'This API key or server does not expose managed-storage readiness; the server still controls whether issuance can proceed.'
                    : 'Could not confirm managed-storage readiness.',
            ];
            if (!$missing) {
                $report['canIssue'] = false;
                $report['overall'] = 'blocked';
            } elseif ($report['overall'] === 'ready') {
                $report['overall'] = 'attention';
            }
        }
        $report['storageReady'] = $storageReady;
        $report['catalogMode'] = $this->rememberCatalogMode($status);
        $report['region'] = $this->region;
        $report['servedRegion'] = $this->servedRegion;
        return $report;
    }

    // --- Issuing ------------------------------------------------------------------------------

    /**
     * Issues a document in one call: correlative, signature, transmission and index.
     *
     * `$request` is the document request in the API's own shape (`tipoDte`, `receptor`, `items`, ...),
     * as PHP arrays: a list becomes a JSON array and an associative array a JSON object. To send an
     * EMPTY object pass `new \stdClass()`. The server computes every amount; nothing is computed here.
     *
     * Per-call options:
     *  - `idempotencyKey` string  Yours to own. Minted once per call when absent and reused on every retry.
     *    Keep it with the order so a retry after a crash cannot issue twice.
     *  - `deliver` array  `['email' => true|'a@b.c', 'whatsapp' => ['number' => '+503...', 'consent' => true]]`.
     *    Only MARKS the channels; the answer carries `entrega.token` (valid five minutes) for
     *    {@see Facta::deliverEmail()} / {@see Facta::deliverWhatsApp()}.
     *  - `timeoutMs` int, `headers` array.
     *
     * The request travels with `X-Facta-Request-Fingerprint` (fingerprint-v1 of the request as you
     * wrote it, `entrega` included), so a retry survives a catalog edit between attempt and retry.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $options
     * @return array<string, mixed> The sealed (`estado` "sellado") or contingency document:
     *         `codigoGeneracion`, `numeroControl`, `tipoDte`, `ambiente`, `totales`, the signed
     *         document members, `archivoDte` (sealed only), `archivoJson`, `representacionGrafica`
     *         (base64 PDF), `entrega`, `storage`, `advertencias`. Members the server adds later pass through.
     * @throws FactaError
     */
    public function issue(array $request, array $options = []): array
    {
        $options = $this->options($options, ['idempotencyKey', 'deliver', 'headers', 'timeoutMs']);
        $deliver = $options['deliver'] ?? null;
        unset($options['deliver']);

        // Taken before catalog resolution, so a catalog change between an attempt and its retry
        // cannot change what the server's idempotency claim compares.
        $asWritten = self::withDelivery($request, $deliver);
        $options['fingerprint'] = Fingerprint::v1(Json::encode($asWritten));
        $resolved = self::withDelivery($this->resolveCatalogRefs($request), $deliver);
        return $this->callArray('POST', '/v1/dte', $resolved, $options);
    }

    /**
     * Reserves the correlative and returns the canonical document, unsigned.
     * Pass the result to {@see Facta::sign()} UNCHANGED.
     *
     * The returned array holds the plain members of the answer, except `documento`: it stays the
     * decoded `\stdClass` tree on purpose, so an empty object `{}` inside it is not turned into `[]`
     * when it is sent back. Treat it as opaque. If you store the prepared result, keep it with
     * `json_encode()` / `json_decode($text)` (objects, NOT `json_decode($text, true)`).
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $options `idempotencyKey`, `timeoutMs`, `headers`.
     * @return array<string, mixed> `estado` "preparado", `codigoGeneracion`, `numeroControl`, `tipoDte`,
     *         `ambiente`, `totales`, `documento` (\stdClass), `prepareToken`.
     * @throws FactaError
     */
    public function prepare(array $request, array $options = []): array
    {
        $options = $this->options($options, ['idempotencyKey', 'headers', 'timeoutMs']);
        $options['fingerprint'] = Fingerprint::v1(Json::encode($request));
        $resolved = $this->resolveCatalogRefs($request);
        $raw = $this->execute('POST', '/v1/dte/prepare', $resolved, $options, false);
        if (!($raw instanceof \stdClass)) {
            throw new FactaError(FactaError::INTERNAL_ERROR, 'API response was not a JSON object.', 200);
        }
        $document = $raw->documento ?? null;
        unset($raw->documento);
        $prepared = Json::toArray($raw);
        if (!is_array($prepared)) {
            throw new FactaError(FactaError::INTERNAL_ERROR, 'API response was not a JSON object.', 200);
        }
        $prepared['documento'] = $document;
        return $prepared;
    }

    /**
     * Signs and transmits what {@see Facta::prepare()} handed back. Pass the prepared result
     * UNCHANGED: the server checks a MAC over its canonical hash, so one altered cent is refused
     * instead of signed.
     *
     * @param array<string, mixed> $prepared The array returned by prepare().
     * @param array<string, mixed> $options  `idempotencyKey`, `timeoutMs`, `headers`.
     * @return array<string, mixed> Same shape as {@see Facta::issue()}.
     * @throws FactaError
     * @throws \InvalidArgumentException When the prepared result lacks its token or document.
     */
    public function sign(array $prepared, array $options = []): array
    {
        $options = $this->options($options, ['idempotencyKey', 'headers', 'timeoutMs']);
        $token = $prepared['prepareToken'] ?? null;
        $document = $prepared['documento'] ?? null;
        if (!is_string($token) || $token === '' || !($document instanceof \stdClass || is_array($document))) {
            throw new \InvalidArgumentException('sign() needs the result of prepare(), with prepareToken and documento.');
        }
        return $this->callArray('POST', '/v1/dte/sign', ['prepareToken' => $token, 'documento' => $document], $options);
    }

    // --- Delivery -----------------------------------------------------------------------------

    /**
     * Starts the e-mail delivery of a sealed document. `$token` is `entrega.token` of the issue answer,
     * valid five minutes. Answers the final channel state, or `en_proceso` (read it with
     * {@see Facta::getDelivery()} / {@see Facta::waitForDelivery()}). A second call returns the current
     * state without a second message. A channel that cannot be delivered is a STATE (`fallido`, ...),
     * not an error; an expired token is `FactaError` `entrega_vencida`.
     *
     * @param array<string, mixed> $options `timeoutMs`.
     * @return array<string, mixed> `estado`, and `destino` (masked), `motivo`, `actualizado` when present.
     * @throws FactaError
     */
    public function deliverEmail(string $generationCode, string $token, array $options = []): array
    {
        return $this->deliver('correo', $generationCode, $token, $options);
    }

    /**
     * WhatsApp counterpart of {@see Facta::deliverEmail()}; billed to the company's prepaid wallet.
     *
     * @param array<string, mixed> $options `timeoutMs`.
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function deliverWhatsApp(string $generationCode, string $token, array $options = []): array
    {
        return $this->deliver('whatsapp', $generationCode, $token, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function deliver(string $channel, string $generationCode, string $token, array $options): array
    {
        $options = $this->options($options, ['timeoutMs']);
        if (preg_match(Delivery::GENERATION_CODE, $generationCode) !== 1) {
            throw new \InvalidArgumentException('generationCode must be a codigoGeneracion.');
        }
        if ($token === '') {
            throw new \InvalidArgumentException('token is required (entrega.token of the issue answer).');
        }
        try {
            // The route is idempotent per channel on the server, so the default per-call key is enough.
            return $this->callArray('POST', '/v1/dte/' . rawurlencode($generationCode) . '/entrega/' . $channel, ['token' => $token], $options);
        } catch (FactaError $error) {
            // The token is a bearer secret: strip it from anything the server echoed.
            /** @var string $message */
            $message = Redactor::redact($error->getMessage(), [$token]);
            throw new FactaError(
                $error->getErrorCode(),
                $message,
                $error->getStatus(),
                Redactor::redact($error->getDetails(), [$token]),
                $error->getRetryAfterMs()
            );
        }
    }

    /**
     * Reads every channel's delivery state. Works after the token expired.
     *
     * @param array<string, mixed> $options `timeoutMs`.
     * @return array<string, mixed> `canales` => channel => [`estado`, `destino`, `motivo`, `actualizado`], and more.
     * @throws FactaError
     */
    public function getDelivery(string $generationCode, array $options = []): array
    {
        $options = $this->options($options, ['timeoutMs']);
        if (preg_match(Delivery::GENERATION_CODE, $generationCode) !== 1) {
            throw new \InvalidArgumentException('generationCode must be a codigoGeneracion.');
        }
        return $this->callArray('GET', '/v1/dte/' . rawurlencode($generationCode) . '/entrega', null, $options);
    }

    /**
     * Polls {@see Facta::getDelivery()} until every awaited channel is final (anything except
     * `pendiente` / `en_proceso`). On timeout it returns the last status with `settled => false`
     * instead of throwing: delivery never changes the fiscal outcome. The pause between reads uses
     * the injectable `sleeper`.
     *
     * @param array<string, mixed> $options `channels` (string[]; default every channel reported),
     *                                      `timeoutMs` (default 60000), `intervalMs` (default 2000).
     * @return array<string, mixed> The last delivery status plus `settled` (bool).
     * @throws FactaError
     */
    public function waitForDelivery(string $generationCode, array $options = []): array
    {
        $options = $this->options($options, ['channels', 'timeoutMs', 'intervalMs']);
        $timeoutMs = $options['timeoutMs'] ?? 60000;
        $intervalMs = $options['intervalMs'] ?? 2000;
        if (!is_int($timeoutMs) || $timeoutMs < 0) {
            throw new \InvalidArgumentException('timeoutMs must be a non-negative integer.');
        }
        if (!is_int($intervalMs) || $intervalMs <= 0) {
            throw new \InvalidArgumentException('intervalMs must be a positive integer.');
        }
        $channels = $options['channels'] ?? null;
        $started = microtime(true);
        $slept = 0;
        for (;;) {
            $status = $this->getDelivery($generationCode);
            $reported = isset($status['canales']) && is_array($status['canales']) ? $status['canales'] : [];
            $wanted = is_array($channels) ? $channels : array_keys($reported);
            $settled = true;
            foreach ($wanted as $channel) {
                $state = isset($reported[$channel]['estado']) ? $reported[$channel]['estado'] : null;
                if (!is_string($state) || !Delivery::isFinalState($state)) {
                    $settled = false;
                    break;
                }
            }
            // The sleeper may be a test double that does not really wait, so count what it was asked for too.
            $elapsed = max((int) round((microtime(true) - $started) * 1000), $slept);
            if ($settled || $elapsed + $intervalMs > $timeoutMs) {
                $status['settled'] = $settled;
                return $status;
            }
            call_user_func($this->sleeper, $intervalMs);
            $slept += $intervalMs;
        }
    }

    // --- Documents ----------------------------------------------------------------------------

    /**
     * Looks a document up. Answers for rejected ones too, not only sealed.
     *
     * @param array<string, mixed> $options `timeoutMs`.
     * @return array<string, mixed> `estado` ("sellado", "firmado", "rechazado", "contingencia",
     *         "invalidado", "reservado", "liberado", "descartado"), `codigoGeneracion`, `numeroControl`,
     *         `tipoDte`, `ambiente`, `fecEmi`, `selloRecibido`, `totales`, `retornos`, `disponible`...
     * @throws FactaError
     */
    public function getDocumentStatus(string $generationCode, array $options = []): array
    {
        return $this->callArray('GET', '/v1/dte/' . rawurlencode($generationCode), null, $this->options($options, ['timeoutMs']));
    }

    /**
     * Lists sealed and reconcilable documents, one page at a time. Pass the previous page's
     * `siguiente` as `cursor` for the next page; `siguiente` is null on the last one.
     *
     * Filters: `desde`, `hasta` (dates), `estado` ("contingencia"|"firmado"|"invalidado"|"sellado"),
     * `tipoDte`, `limit`, `cursor`, and `include => ['dte']` to add the Archivo DTE to every row
     * (rows the API cannot open itself carry `dteError`; completing them locally needs the unlock key
     * and is not available in this SDK version yet).
     *
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $options `timeoutMs`.
     * @return array{documentos: array<int, array<string, mixed>>, siguiente: string|null}
     * @throws FactaError
     */
    public function listDocuments(array $filters = [], array $options = []): array
    {
        $options = $this->options($options, ['timeoutMs']);
        $query = [];
        foreach ($filters as $name => $value) {
            if (!in_array($name, ['desde', 'hasta', 'estado', 'tipoDte', 'limit', 'cursor', 'include'], true)) {
                throw new \InvalidArgumentException('Unknown listDocuments filter: ' . $name . '.');
            }
            if ($value === null || $name === 'include') {
                continue;
            }
            $query[(string) $name] = (string) $value;
        }
        $include = $filters['include'] ?? null;
        if (is_array($include) && in_array('dte', $include, true)) {
            $query['include'] = 'dte';
        }
        $page = $this->callArray('GET', '/v1/dte' . self::queryString($query), null, $options);
        if (!isset($page['documentos']) || !is_array($page['documentos'])) {
            $page['documentos'] = [];
        }
        $page['siguiente'] = isset($page['siguiente']) && is_string($page['siguiente']) ? $page['siguiente'] : null;
        /** @var array{documentos: array<int, array<string, mixed>>, siguiente: string|null} $page */
        return $page;
    }

    /**
     * Downloads a document file. The bytes are exactly what the server sent, never re-serialized.
     *
     * `kind` is "json" (the Archivo DTE the receiver gets, by default), "pdf", or "ticket" (a roll
     * receipt rendered by the server). Options: `raw => true` (json only: the stored original,
     * needed for a contingency document), `source => 'managed'` (json/pdf only: require the managed
     * copy), `paperWidthMm` (ticket only, 40-120, default 80), `timeoutMs`.
     *
     * @param array<string, mixed> $options
     * @return array{codigoGeneracion: string, kind: string, bytes: string, contentType: string,
     *               filename: string|null, storageSource?: string, jsonFormat?: string, paperWidthMm?: int}
     *         `jsonFormat` ("archivo-dte"|"raw", from `X-Facta-Json-Format`) is present on json downloads
     *         and `storageSource` ("managed"|"holding"|"archive", from `X-Facta-Storage-Source`) when the server says.
     * @throws FactaError
     */
    public function downloadDocument(string $generationCode, string $kind = 'json', array $options = []): array
    {
        $options = $this->options($options, ['source', 'paperWidthMm', 'raw', 'timeoutMs']);
        if (!in_array($kind, ['json', 'pdf', 'ticket'], true)) {
            throw new \InvalidArgumentException('kind must be "json", "pdf" or "ticket".');
        }
        $source = $options['source'] ?? null;
        if ($source !== null && ($source !== 'managed' || $kind === 'ticket')) {
            throw new \InvalidArgumentException('source=managed is only valid for JSON/PDF downloads.');
        }
        $width = $options['paperWidthMm'] ?? null;
        if ($width !== null && $kind !== 'ticket') {
            throw new \InvalidArgumentException('paperWidthMm is only valid when downloading kind=ticket.');
        }
        if ($kind === 'ticket' && $width !== null && (!is_int($width) || $width < 40 || $width > 120)) {
            throw new \InvalidArgumentException('paperWidthMm must be an integer from 40 through 120 millimeters.');
        }
        $raw = $options['raw'] ?? null;
        if ($raw !== null && $kind !== 'json') {
            throw new \InvalidArgumentException('raw is only valid when downloading kind=json.');
        }
        unset($options['source'], $options['paperWidthMm'], $options['raw']);

        $query = ['kind' => $kind];
        if ($kind === 'json' && $raw === true) {
            $query['raw'] = 'true';
        }
        if ($source === 'managed') {
            $query['source'] = 'managed';
        }
        if ($kind === 'ticket' && $width !== null) {
            $query['paperWidthMm'] = (string) $width;
        }
        $response = $this->execute('GET', '/v1/dte/' . rawurlencode($generationCode) . '/file' . self::queryString($query), null, $options, true);
        if (!($response instanceof Response)) {
            throw new FactaError(FactaError::INTERNAL_ERROR, 'The download produced no response.', 0);
        }
        $storageSource = $response->getHeader('x-facta-storage-source');
        $storageSource = in_array($storageSource, ['managed', 'holding', 'archive'], true) ? $storageSource : null;
        $jsonFormat = $response->getHeader('x-facta-json-format');
        $disposition = $response->getHeader('content-disposition');
        $filename = null;
        if ($disposition !== null && preg_match('/filename="?([^";]+)"?/', $disposition, $m) === 1) {
            $filename = $m[1];
        }
        if ($source === 'managed' && $storageSource !== 'managed') {
            throw new FactaError(
                $storageSource === null ? FactaError::STORAGE_UNSUPPORTED : FactaError::STORAGE_CONTRACT_INVALID,
                'API did not return the explicitly requested managed copy.',
                502
            );
        }
        $download = [
            'codigoGeneracion' => $generationCode,
            'kind' => $kind,
            'bytes' => $response->getBody(),
            'contentType' => $response->getHeader('content-type') ?? 'application/octet-stream',
            'filename' => $filename,
        ];
        if ($storageSource !== null) {
            $download['storageSource'] = $storageSource;
        }
        if ($kind === 'json' && ($jsonFormat === 'archivo-dte' || $jsonFormat === 'raw')) {
            $download['jsonFormat'] = $jsonFormat;
        }
        if ($kind === 'ticket') {
            $download['paperWidthMm'] = $width ?? 80;
        }
        return $download;
    }

    /**
     * Invalidates a sealed document. This sends the signing key and is irreversible.
     *
     * @param array<string, mixed> $request `tipoAnulacion` (1|2|3), `motivo`, `codigoGeneracionReemplazo`,
     *                                      `responsable` and `solicita` (each `nombre`, `tipoDocumento`, `numDocumento`).
     * @param array<string, mixed> $options `idempotencyKey`, `timeoutMs`, `headers`.
     * @return array<string, mixed> `estado` "invalidado", `evento`, ... or `yaEstabaInvalidado => true`.
     * @throws FactaError
     */
    public function invalidate(string $generationCode, array $request, array $options = []): array
    {
        return $this->callArray(
            'POST',
            '/v1/dte/' . rawurlencode($generationCode) . '/invalidate',
            $request,
            $this->options($options, ['idempotencyKey', 'headers', 'timeoutMs'])
        );
    }

    /**
     * Registers a return (Evento de Retorno) over a sealed FE, FEX or FSE that the API emitted. Lines
     * are counted from 1. This sends the signing key; it is irreversible once Hacienda seals it.
     * A 202 (`estado` "firmado") means Hacienda did not answer: repeat the call with the SAME
     * `idempotencyKey` and request to resend the same signed event.
     *
     * @param array<string, mixed> $request `items` (each `linea` plus exactly one of `cantidad` or `noGravado`),
     *                                      `fechaEvento` (YYYY-MM-DD, optional).
     * @param array<string, mixed> $options `idempotencyKey`, `timeoutMs`, `headers`.
     * @return array<string, mixed>
     * @throws FactaError
     * @throws \InvalidArgumentException On a request the server would refuse anyway.
     */
    public function registerReturn(string $generationCode, array $request, array $options = []): array
    {
        self::validateReturnRequest($request);
        return $this->callArray(
            'POST',
            '/v1/dte/' . rawurlencode($generationCode) . '/return',
            $request,
            $this->options($options, ['idempotencyKey', 'headers', 'timeoutMs'])
        );
    }

    // --- Managed storage ----------------------------------------------------------------------

    /**
     * Managed-storage coverage, capacity, environment and BYOS readiness.
     *
     * @param array<string, mixed> $options `timeoutMs`.
     * @return array{capabilityVersion: int, managed: array<string, mixed>, byos: array{ready: bool},
     *               supportedKinds: array<int, string>, unsupportedKinds: array<int, string>, capabilities?: array<string, mixed>}
     * @throws FactaError `storage_unsupported` when the server does not expose it; `storage_contract_invalid` on a malformed answer.
     */
    public function getStorageStatus(array $options = []): array
    {
        $options = $this->options($options, ['timeoutMs']);
        try {
            $value = $this->callArray('GET', '/v1/storage/status', null, $options);
        } catch (FactaError $cause) {
            if ($cause->getStatus() === 404 || $cause->getStatus() === 501) {
                throw new FactaError(FactaError::STORAGE_UNSUPPORTED, 'This API server does not expose managed-storage status.', $cause->getStatus());
            }
            throw $cause;
        }
        if (!ManagedStorage::isStatus($value)) {
            throw new FactaError(FactaError::STORAGE_CONTRACT_INVALID, 'API returned an invalid storage capability response.', 502);
        }
        /** @var array{capabilityVersion: int, managed: array<string, mixed>, byos: array{ready: bool}, supportedKinds: array<int, string>, unsupportedKinds: array<int, string>} $value */
        return $value;
    }

    /**
     * Lists stored, pending or failed managed JSON/PDF copies for this key's issuer and environment.
     *
     * @param array<string, mixed> $options `generationCode` (limit to one document), `timeoutMs`.
     * @return array<int, array{generationCode: string, kind: string, environment: string, state: string,
     *                          bytes: int, sha256: string, issuedDate: string, storedAt: string|null}>
     * @throws FactaError
     */
    public function getDocumentCopies(array $options = []): array
    {
        $options = $this->options($options, ['generationCode', 'timeoutMs']);
        $code = $options['generationCode'] ?? null;
        unset($options['generationCode']);
        $query = is_string($code) && $code !== '' ? self::queryString(['generationCode' => $code]) : '';
        try {
            $value = $this->callArray('GET', '/v1/storage/copies' . $query, null, $options);
        } catch (FactaError $cause) {
            if ($cause->getStatus() === 404 || $cause->getStatus() === 501) {
                throw new FactaError(FactaError::STORAGE_UNSUPPORTED, 'This API server does not expose managed document-copy status.', $cause->getStatus());
            }
            throw $cause;
        }
        $valid = ManagedStorage::isCopies($value);
        if ($valid) {
            foreach ($value['copies'] as $copy) {
                if (
                    $copy['environment'] !== $this->environment()
                    || (is_string($code) && $code !== '' && strtoupper($copy['generationCode']) !== strtoupper($code))
                ) {
                    $valid = false;
                    break;
                }
            }
        }
        if (!$valid) {
            throw new FactaError(FactaError::STORAGE_CONTRACT_INVALID, 'API returned an invalid managed document-copy response.', 502);
        }
        /** @var array<int, array{generationCode: string, kind: string, environment: string, state: string, bytes: int, sha256: string, issuedDate: string, storedAt: string|null}> $copies */
        $copies = $value['copies'];
        return $copies;
    }

    /**
     * Repairs only the managed copies of an already sealed document; it never submits a document.
     *
     * @param array<string, mixed> $options `timeoutMs`.
     * @return array<string, mixed> The storage receipt: `destination`, `environment`, `json`, `pdf`, `operationId`.
     * @throws FactaError
     */
    public function retryDocumentStorage(string $generationCode, array $options = []): array
    {
        $options = $this->options($options, ['timeoutMs']);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $generationCode) !== 1) {
            throw new \InvalidArgumentException('generationCode must be a UUID.');
        }
        try {
            $value = $this->callArray('POST', '/v1/storage/copies/' . rawurlencode($generationCode) . '/repair', null, $options);
        } catch (FactaError $cause) {
            if ($cause->getStatus() === 404 || $cause->getStatus() === 501) {
                throw new FactaError(FactaError::STORAGE_UNSUPPORTED, 'This API server does not expose managed document-copy repair.', $cause->getStatus());
            }
            throw $cause;
        }
        $receipt = $value['storage'] ?? null;
        if (
            !ManagedStorage::isReceipt($receipt) || !is_array($receipt)
            || $receipt['environment'] !== $this->environment()
            || strtolower($receipt['operationId']) !== strtolower($generationCode)
        ) {
            throw new FactaError(FactaError::STORAGE_CONTRACT_INVALID, 'API returned an invalid managed-storage repair receipt.', 502);
        }
        return $receipt;
    }

    /**
     * Inspects the holding area (documents retained for a while); this does not download bytes.
     *
     * @return array<string, mixed> `documentos` => rows.
     * @throws FactaError
     */
    public function listHolding(int $limit = 50): array
    {
        return $this->callArray('GET', '/v1/dte/holding' . self::queryString(['limit' => (string) $limit]), null, []);
    }

    /**
     * The server's published OpenAPI contract.
     *
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function getContract(): array
    {
        return $this->callArray('GET', '/v1/openapi.json', null, []);
    }

    // --- Catalog ------------------------------------------------------------------------------
    //
    // Reads follow the catalog mode the key reports in `/v1/status`: "plain" and "readable" are read
    // through the API. An "encrypted" catalog is read from the key's decrypted snapshot, which needs
    // the unlock key and local decryption; local snapshots arrive in a later version of this SDK, so
    // those reads throw FactaError `unauthorized` (details.reason) until then.

    /**
     * Lists customers (deactivated ones only with `includeInactive`).
     *
     * @param array<string, mixed> $options `includeInactive` (bool).
     * @return array<int, array<string, mixed>> Records carrying BOTH spellings (`nombre`/`name`, ...).
     * @throws FactaError
     */
    public function listCustomers(array $options = []): array
    {
        $options = $this->options($options, ['includeInactive']);
        $all = $options['includeInactive'] ?? false;
        return $this->catalogRead(function () use ($all): array {
            $rows = $this->listAll('/v1/customers', ['clientes'], $all === true ? ['incluirInactivos' => 'true'] : []);
            return self::onlyActive($rows, $all === true);
        });
    }

    /**
     * Finds one customer by its stable Facta id, or null.
     *
     * @param array<string, mixed> $options `includeInactive` (bool).
     * @return array<string, mixed>|null
     * @throws FactaError
     */
    public function getCustomer(string $customerId, array $options = []): ?array
    {
        $options = $this->options($options, ['includeInactive']);
        $id = Catalog::requireId($customerId, 'customer');
        return $this->catalogRead(function () use ($id, $options): ?array {
            $row = $this->getOne('/v1/customers/' . rawurlencode($id), ['cliente']);
            return $row === null || ($row['active'] === false && ($options['includeInactive'] ?? false) !== true) ? null : $row;
        });
    }

    /**
     * Searches customer name, document number, NRC and e-mail.
     *
     * @param array<string, mixed> $options `limit` (1-500, default 50), `includeInactive`.
     * @return array<int, array<string, mixed>>
     * @throws FactaError
     */
    public function searchCustomers(string $query, array $options = []): array
    {
        return $this->search('/v1/customers', ['clientes'], $query, $this->options($options, ['limit', 'includeInactive']));
    }

    /**
     * Lists products (deactivated ones only with `includeInactive`).
     *
     * @param array<string, mixed> $options `includeInactive` (bool).
     * @return array<int, array<string, mixed>>
     * @throws FactaError
     */
    public function listProducts(array $options = []): array
    {
        $options = $this->options($options, ['includeInactive']);
        $all = $options['includeInactive'] ?? false;
        return $this->catalogRead(function () use ($all): array {
            $rows = $this->listAll('/v1/products', ['productos'], $all === true ? ['incluirInactivos' => 'true'] : []);
            return self::onlyActive($rows, $all === true);
        });
    }

    /**
     * Finds one product by its stable Facta id. Inactive products are null unless `includeInactive`.
     *
     * @param array<string, mixed> $options `includeInactive` (bool).
     * @return array<string, mixed>|null
     * @throws FactaError
     */
    public function getProduct(string $productId, array $options = []): ?array
    {
        $options = $this->options($options, ['includeInactive']);
        $id = Catalog::requireId($productId, 'product');
        return $this->catalogRead(function () use ($id, $options): ?array {
            $row = $this->getOne('/v1/products/' . rawurlencode($id), ['producto']);
            return $row === null || ($row['active'] === false && ($options['includeInactive'] ?? false) !== true) ? null : $row;
        });
    }

    /**
     * Searches active product descriptions, codes and barcodes.
     *
     * @param array<string, mixed> $options `limit` (1-500, default 50), `includeInactive`.
     * @return array<int, array<string, mixed>>
     * @throws FactaError
     */
    public function searchProducts(string $query, array $options = []): array
    {
        return $this->search('/v1/products', ['productos'], $query, $this->options($options, ['limit', 'includeInactive']));
    }

    /**
     * Creates a customer. The company must keep its catalog in plain text and enable catalog
     * administration, and the key needs `catalog:write`; otherwise the server answers `catalog_encrypted`
     * (409) or `catalog_write_disabled` (403). Pass `idempotencyKey` to make a retried create safe.
     *
     * @param array<string, mixed> $input   `nombre` (required), `tipoDocumento`, `numDocumento`, `nrc`,
     *                                      `codActividad`, `direccion`, `telefono`, `correo`. The stored
     *                                      spellings (`name`, `doc_number`, ...) are accepted too.
     * @param array<string, mixed> $options `idempotencyKey`.
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function createCustomer(array $input, array $options = []): array
    {
        $body = Catalog::prepareCustomer($input, 'create');
        return $this->catalogWrite('POST', '/v1/customers', ['cliente'], $body, $this->options($options, ['idempotencyKey']), null);
    }

    /**
     * Changes fields of a customer; only the fields you pass change.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $options `idempotencyKey`.
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function updateCustomer(string $customerId, array $changes, array $options = []): array
    {
        $id = Catalog::requireId($customerId, 'customer');
        return $this->catalogWrite(
            'PATCH',
            '/v1/customers/' . rawurlencode($id),
            ['cliente'],
            Catalog::prepareCustomer($changes, 'update'),
            $this->options($options, ['idempotencyKey']),
            $id
        );
    }

    /**
     * Deactivates a customer (`DELETE`). Existing documents keep it; it stops being listed. There is no hard delete.
     *
     * @param array<string, mixed> $options `idempotencyKey`.
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function deactivateCustomer(string $customerId, array $options = []): array
    {
        $id = Catalog::requireId($customerId, 'customer');
        return $this->catalogWrite('DELETE', '/v1/customers/' . rawurlencode($id), ['cliente'], null, $this->options($options, ['idempotencyKey']), $id);
    }

    /**
     * Creates a product. `tipoItem` is required and never defaulted.
     *
     * @param array<string, mixed> $input   `descripcion`, `tipoItem` (1|2|3), `precioUni` (required), `uniMedida`,
     *                                      `codigo`, `codigoBarras`, `ivaIncluido`, `tipoVenta`
     *                                      ("gravada"|"exenta"|"no_sujeta").
     * @param array<string, mixed> $options `idempotencyKey`.
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function createProduct(array $input, array $options = []): array
    {
        $body = Catalog::prepareProduct($input, 'create');
        return $this->catalogWrite('POST', '/v1/products', ['producto'], $body, $this->options($options, ['idempotencyKey']), null);
    }

    /**
     * Changes fields of a product; only the fields you pass change.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $options `idempotencyKey`.
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function updateProduct(string $productId, array $changes, array $options = []): array
    {
        $id = Catalog::requireId($productId, 'product');
        return $this->catalogWrite(
            'PATCH',
            '/v1/products/' . rawurlencode($id),
            ['producto'],
            Catalog::prepareProduct($changes, 'update'),
            $this->options($options, ['idempotencyKey']),
            $id
        );
    }

    /**
     * Deactivates a product (`DELETE`). Existing documents keep it; it can no longer be issued.
     *
     * @param array<string, mixed> $options `idempotencyKey`.
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function deactivateProduct(string $productId, array $options = []): array
    {
        $id = Catalog::requireId($productId, 'product');
        return $this->catalogWrite('DELETE', '/v1/products/' . rawurlencode($id), ['producto'], null, $this->options($options, ['idempotencyKey']), $id);
    }

    /**
     * @param string[]                  $keys
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $options
     * @return array<string, mixed>
     */
    private function catalogWrite(string $method, string $path, array $keys, ?array $body, array $options, ?string $id): array
    {
        $payload = $this->callArray($method, $path, $body, $options);
        $record = Catalog::recordOf($payload, $keys);
        if ($record !== null) {
            return self::normalizeRecord($path, $record);
        }
        if ($method === 'DELETE' && $id !== null) {
            return self::normalizeRecord($path, ['id' => $id, 'activo' => false]);
        }
        throw new FactaError(FactaError::INTERNAL_ERROR, 'The catalog answer carries no record.', 502);
    }

    /**
     * @param string[]             $keys
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function search(string $path, array $keys, string $query, array $options): array
    {
        if (trim($query) === '') {
            return [];
        }
        $limit = $options['limit'] ?? 50;
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('catalog search limit must be an integer from 1 to 500.');
        }
        $all = ($options['includeInactive'] ?? false) === true;
        return $this->catalogRead(function () use ($path, $keys, $query, $limit, $all): array {
            $extra = ['buscar' => trim($query)];
            if ($all) {
                $extra['incluirInactivos'] = 'true';
            }
            if ($limit <= self::SERVER_CATALOG_PAGE) {
                $payload = $this->callArray('GET', $path . self::queryString($extra + ['limite' => (string) $limit]), null, []);
                $rows = array_map(static function (array $row) use ($path): array {
                    return self::normalizeRecord($path, $row);
                }, Catalog::rowsOf($payload, $keys));
            } else {
                $rows = $this->listAll($path, $keys, $extra);
            }
            return array_slice(self::onlyActive($rows, $all), 0, $limit);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function onlyActive(array $rows, bool $includeInactive): array
    {
        if ($includeInactive) {
            return $rows;
        }
        return array_values(array_filter($rows, static function (array $row): bool {
            return ($row['active'] ?? true) !== false;
        }));
    }

    /**
     * Follows the cursor through every page.
     *
     * @param string[]              $keys
     * @param array<string, string> $extra
     * @return array<int, array<string, mixed>>
     */
    private function listAll(string $path, array $keys, array $extra = []): array
    {
        $rows = [];
        $cursor = null;
        for ($page = 0; $page < 1000; $page++) {
            $query = ['limite' => (string) self::SERVER_CATALOG_PAGE] + $extra;
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $payload = $this->callArray('GET', $path . self::queryString($query), null, []);
            foreach (Catalog::rowsOf($payload, $keys) as $row) {
                $rows[] = self::normalizeRecord($path, $row);
            }
            $cursor = Catalog::nextCursor($payload);
            if ($cursor === null) {
                return $rows;
            }
        }
        throw new FactaError(FactaError::INTERNAL_ERROR, 'The catalog returned too many pages.', 502);
    }

    /**
     * @param string[] $keys
     * @return array<string, mixed>|null
     */
    private function getOne(string $path, array $keys): ?array
    {
        try {
            $payload = $this->callArray('GET', $path, null, []);
        } catch (FactaError $cause) {
            if ($cause->getStatus() === 404 && $cause->getErrorCode() === FactaError::NOT_FOUND) {
                return null;
            }
            throw $cause;
        }
        $record = Catalog::recordOf($payload, $keys);
        return $record === null ? null : self::normalizeRecord($path, $record);
    }

    /**
     * Both spellings on a record read through the API; the path says what kind it is.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRecord(string $path, array $row): array
    {
        return strpos($path, '/v1/customers') === 0 ? Catalog::normalizeCustomer($row) : Catalog::normalizeProduct($row);
    }

    /**
     * Picks the read path for the key's catalog mode: "plain" reads the API; "readable" tries the API
     * and falls back to the snapshot (not available here); "encrypted" or unknown needs the snapshot.
     *
     * @template T
     * @param callable(): T $viaApi
     * @return T
     */
    private function catalogRead(callable $viaApi)
    {
        $mode = $this->catalogMode();
        if ($mode === 'plain') {
            return $viaApi();
        }
        if ($mode === 'readable') {
            try {
                return $viaApi();
            } catch (FactaError $cause) {
                $routeMissing = $cause->getStatus() === 404 || $cause->getStatus() === 501
                    || $cause->getErrorCode() === FactaError::CATALOG_ENCRYPTED;
                if (!$routeMissing || $this->unlockKey === null) {
                    throw $cause;
                }
                throw $this->localCatalogUnavailable();
            }
        }
        throw $this->localCatalogUnavailable();
    }

    private function localCatalogUnavailable(): FactaError
    {
        $missingKey = $this->unlockKey === null;
        return new FactaError(
            FactaError::UNAUTHORIZED,
            $missingKey
                ? 'This company\'s catalog is encrypted and no unlockKey was configured. Local catalog snapshots '
                    . '(decrypted with the unlock key) arrive in a later version of this SDK; until then only readable '
                    . 'or plain-text catalogs can be used through the API.'
                : 'This company\'s catalog is encrypted. Local catalog snapshots (decrypted with the unlock key) '
                    . 'arrive in a later version of this SDK; until then only readable or plain-text catalogs can be used through the API.',
            0,
            $missingKey
                ? ['reason' => 'unlock_key_missing', 'missing' => 'unlockKey']
                : ['reason' => 'local_snapshot_unsupported']
        );
    }

    /**
     * How the key reaches the catalog, learned from `/v1/status` and trusted for a minute.
     * Null when the server does not say or status failed.
     */
    private function catalogMode(): ?string
    {
        $cached = $this->catalogModeCache;
        if ($cached !== null && time() - $cached['at'] < self::CATALOG_MODE_TTL_SECONDS) {
            return $cached['mode'];
        }
        try {
            return $this->rememberCatalogMode($this->status());
        } catch (FactaError $cause) {
            return null;
        }
    }

    /** @param array<string, mixed> $status */
    private function rememberCatalogMode(array $status): ?string
    {
        $key = isset($status['llave']) && is_array($status['llave']) ? $status['llave'] : [];
        // A company with an unencrypted catalog is advertised as "readable" for older SDKs;
        // `catalogoSinCifrar` is what tells this SDK it is also writable.
        $advertised = ($key['catalogoSinCifrar'] ?? null) === true ? 'plain' : ($key['catalogMode'] ?? null);
        $mode = in_array($advertised, ['plain', 'readable', 'encrypted'], true) ? (string) $advertised : null;
        $this->catalogModeCache = ['mode' => $mode, 'at' => time()];
        return $mode;
    }

    /**
     * True when `/v1/status` advertises a readable or plain catalog, so the server resolves
     * `customerId` / `productId` itself. Any failure answers false.
     */
    private function serverResolvesCatalog(): bool
    {
        $cached = $this->catalogModeCache;
        if ($cached !== null && time() - $cached['at'] < self::CATALOG_MODE_TTL_SECONDS) {
            return $cached['mode'] === 'readable' || $cached['mode'] === 'plain';
        }
        try {
            $mode = $this->rememberCatalogMode($this->status());
        } catch (FactaError $cause) {
            return false;
        }
        return $mode === 'readable' || $mode === 'plain';
    }

    /**
     * References to the catalog (`receptor.customerId`, `items[].productId`) are resolved by the server
     * when the key's catalog is readable or plain. An encrypted catalog is resolved from a local
     * snapshot, which this SDK version cannot build.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function resolveCatalogRefs(array $request): array
    {
        $receptor = $request['receptor'] ?? null;
        $hasCustomer = is_array($receptor) && isset($receptor['customerId']) && is_string($receptor['customerId']);
        $hasProduct = false;
        if (isset($request['items']) && is_array($request['items'])) {
            foreach ($request['items'] as $item) {
                if (is_array($item) && isset($item['productId']) && is_string($item['productId'])) {
                    $hasProduct = true;
                    break;
                }
            }
        }
        if (!$hasCustomer && !$hasProduct) {
            return $request;
        }
        if ($this->serverResolvesCatalog()) {
            return $request;
        }
        throw $this->localCatalogUnavailable();
    }

    /**
     * @param array<string, mixed> $request
     * @param mixed                $deliver
     * @return array<string, mixed>
     */
    private static function withDelivery(array $request, $deliver): array
    {
        return $deliver === null ? $request : array_merge($request, ['entrega' => Delivery::requestFor($deliver)]);
    }

    /**
     * Local checks that save a round trip: the server enforces the same rules.
     *
     * @param array<string, mixed> $request
     */
    private static function validateReturnRequest(array $request): void
    {
        $items = $request['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('registerReturn requires at least one item.');
        }
        foreach (array_values($items) as $index => $item) {
            $label = 'items[' . $index . ']';
            if (!is_array($item) || !isset($item['linea']) || !is_int($item['linea']) || $item['linea'] < 1) {
                throw new \InvalidArgumentException($label . '.linea must be an integer from 1 (the first line of the document is 1).');
            }
            $hasQuantity = isset($item['cantidad']);
            $hasCharge = isset($item['noGravado']);
            if ($hasQuantity === $hasCharge) {
                throw new \InvalidArgumentException($label . ' must carry exactly one of cantidad or noGravado.');
            }
            if ($hasQuantity && !((is_int($item['cantidad']) || is_float($item['cantidad'])) && is_finite((float) $item['cantidad']) && $item['cantidad'] > 0)) {
                throw new \InvalidArgumentException($label . '.cantidad must be a number greater than zero.');
            }
            if ($hasCharge && !((is_int($item['noGravado']) || is_float($item['noGravado'])) && is_finite((float) $item['noGravado']) && $item['noGravado'] != 0)) {
                throw new \InvalidArgumentException($label . '.noGravado must be a non-zero number.');
            }
        }
        if (isset($request['fechaEvento']) && (!is_string($request['fechaEvento']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $request['fechaEvento']) !== 1)) {
            throw new \InvalidArgumentException('fechaEvento must be YYYY-MM-DD.');
        }
    }

    /**
     * Rejects an option name this method does not know, so a typo is not silently ignored.
     *
     * @param array<string, mixed> $options
     * @param string[]             $allowed
     * @return array<string, mixed>
     */
    private function options(array $options, array $allowed): array
    {
        foreach (array_keys($options) as $name) {
            if (!in_array($name, array_merge($allowed, ['timeoutMs', 'headers']), true)) {
                throw new \InvalidArgumentException('Unknown option: ' . $name . '.');
            }
        }
        return $options;
    }

    /**
     * `?a=1&b=2` with RFC 3986 escaping, or an empty string.
     *
     * @param array<string, string> $query
     */
    private static function queryString(array $query): string
    {
        return $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    // --- The request loop ---------------------------------------------------------------------

    /**
     * One call whose answer is a JSON object, as an associative array.
     *
     * @param mixed                $body
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function callArray(string $method, string $path, $body, array $options): array
    {
        $decoded = $this->execute($method, $path, $body, $options, false);
        $array = Json::toArray($decoded);
        if (!is_array($array)) {
            throw new FactaError(FactaError::INTERNAL_ERROR, 'API response was not a JSON object.', 200);
        }
        return $array;
    }

    /**
     * One API call with the SDK's retry policy.
     *
     * Per-call options: `idempotencyKey` (string, POST only, minted once and
     * reused across retries), `headers` (extra request headers) and `timeoutMs`.
     *
     * @internal Building block for the typed methods.
     *
     * @param mixed                $body    Encoded ONCE; every retry resends the same bytes.
     * @param array<string, mixed> $options
     * @return mixed The decoded JSON answer (objects are \stdClass).
     * @throws FactaError
     */
    public function request(string $method, string $path, $body = null, array $options = [])
    {
        return $this->execute($method, $path, $body, $options, false);
    }

    /**
     * @param mixed                $body
     * @param array<string, mixed> $options
     * @return mixed A decoded JSON tree, or the {@see Response} when `$binary`.
     */
    private function execute(string $method, string $path, $body, array $options, bool $binary)
    {
        $headers = [
            'Accept' => $binary ? '*/*' : 'application/json',
            'User-Agent' => 'facta-api-sdk-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
            'X-Facta-Key' => $this->apiKey,
        ];
        // Sent ONLY where it is needed: a password that rides on every request ends up in every proxy log.
        if (
            $this->signKey !== null && $method === 'POST'
            && (in_array($path, self::SIGNING_PATHS, true) || preg_match(self::SIGNING_EVENT_PATH, $path) === 1)
        ) {
            $headers['X-Facta-Sign-Key'] = $this->signKey;
        }
        if ($this->region !== null) {
            $headers['x-region'] = $this->region;
        }
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = Json::encode($body);
        }
        // Minted ONCE, outside the retry loop: a fresh key per attempt is exactly the failure it prevents.
        if ($method === 'POST') {
            $key = $options['idempotencyKey'] ?? null;
            $headers['Idempotency-Key'] = is_string($key) && $key !== '' ? $key : self::uuid4();
            $fingerprint = $options['fingerprint'] ?? null;
            if (is_string($fingerprint) && in_array($path, self::FINGERPRINT_PATHS, true)) {
                $headers[self::FINGERPRINT_HEADER] = $fingerprint;
            }
        }
        if (isset($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $headers[(string) $name] = (string) $value;
            }
        }
        $timeoutMs = isset($options['timeoutMs']) && is_int($options['timeoutMs']) ? $options['timeoutMs'] : $this->timeoutMs;

        $retryAfterMs = null;
        $last = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $wait = $retryAfterMs !== null ? $retryAfterMs : min(250 * (2 ** ($attempt - 1)), 4000);
                call_user_func($this->sleeper, (int) $wait);
            }
            try {
                return $this->attempt($method, $path, $headers, $payload, $timeoutMs, $binary);
            } catch (FactaError $error) {
                if (!in_array($error->getErrorCode(), self::RETRYABLE, true)) {
                    throw $error;
                }
                $last = $error;
                $retryAfterMs = $error->getRetryAfterMs();
            }
        }
        // Unreachable while maxRetries >= 0 (the loop runs at least once and returns or sets $last).
        throw $last ?? new FactaError(FactaError::INTERNAL_ERROR, 'The request loop ended without an answer.', 0);
    }

    /**
     * @param array<string, string> $headers
     * @return mixed
     */
    private function attempt(string $method, string $path, array $headers, ?string $payload, int $timeoutMs, bool $binary)
    {
        try {
            $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $payload, $timeoutMs);
        } catch (TransportException $e) {
            throw new FactaError(FactaError::NETWORK_ERROR, $this->networkMessage($e, $timeoutMs), 0);
        }

        $served = $response->getHeader('x-sb-edge-region');
        if ($served !== null && preg_match(self::REGION_PATTERN, $served) === 1) {
            $this->servedRegion = $served;
        }

        $status = $response->getStatus();
        if ($binary && $status >= 200 && $status < 300) {
            return $response;
        }

        $text = $response->getBody();
        $decoded = null;
        if ($text !== '') {
            try {
                $decoded = Json::decode($text);
            } catch (\InvalidArgumentException $e) {
                if ($binary) {
                    throw $this->errorFrom($response, null);
                }
                throw new FactaError(
                    FactaError::INTERNAL_ERROR,
                    'API response was not valid JSON (HTTP ' . $status . ').',
                    $status
                );
            }
        }

        if ($status >= 200 && $status < 300) {
            if ($decoded instanceof \stdClass) {
                if (isset($decoded->debug)) {
                    unset($decoded->debug); // not asked for: never hand a debug member to the caller
                }
                $this->enforceStorageContract($decoded);
            }
            return $decoded;
        }

        throw $this->errorFrom($response, $decoded);
    }

    /**
     * A storage receipt that does not satisfy the contract (wrong environment, another document, bytes
     * that do not match the ones in the same answer) is dropped and replaced by `storageErrorCode`,
     * so a caller never trusts a receipt for a file it does not describe.
     */
    private function enforceStorageContract(\stdClass $payload): void
    {
        $code = isset($payload->codigoGeneracion) && is_string($payload->codigoGeneracion) ? $payload->codigoGeneracion : null;
        $sealed = isset($payload->estado) && ($payload->estado === 'sellado' || $payload->estado === 'contingencia');
        if ($sealed && property_exists($payload, 'storage')) {
            $receipt = Json::toArray($payload->storage);
            if (
                !ManagedStorage::isReceipt($receipt) || !is_array($receipt)
                || $receipt['environment'] !== $this->environment()
                || $code === null || strtoupper($receipt['operationId']) !== strtoupper($code)
            ) {
                unset($payload->storage);
                $payload->storageErrorCode = FactaError::STORAGE_CONTRACT_INVALID;
                return;
            }
        }
        if (!isset($payload->storage)) {
            return;
        }
        $receipt = Json::toArray($payload->storage);
        if (!ManagedStorage::isReceipt($receipt) || !is_array($receipt)) {
            return;
        }
        foreach (['json', 'pdf'] as $kind) {
            $bytes = null;
            if ($kind === 'json' && isset($payload->archivoJson) && is_string($payload->archivoJson)) {
                $bytes = $payload->archivoJson;
            } elseif ($kind === 'pdf' && isset($payload->representacionGrafica) && is_string($payload->representacionGrafica)) {
                $decodedPdf = base64_decode($payload->representacionGrafica, true);
                $bytes = $decodedPdf === false ? null : $decodedPdf;
            }
            if (
                $bytes !== null && $receipt[$kind]['state'] === 'stored'
                && ($receipt[$kind]['bytes'] !== strlen($bytes)
                    || strtolower((string) $receipt[$kind]['sha256']) !== hash('sha256', $bytes))
            ) {
                unset($payload->storage);
                $payload->storageErrorCode = FactaError::STORAGE_CONTRACT_INVALID;
                return;
            }
        }
    }

    /** @param mixed $payload */
    private function errorFrom(Response $response, $payload): FactaError
    {
        $error = $payload instanceof \stdClass && isset($payload->error) && $payload->error instanceof \stdClass
            ? $payload->error
            : null;
        $code = $error !== null && isset($error->code) && is_string($error->code) ? $error->code : FactaError::INTERNAL_ERROR;
        $guidance = Catalog::writeGuidance($code);
        $message = $guidance !== null
            ? $guidance
            : ($error !== null && isset($error->message) && is_string($error->message)
                ? $error->message
                : 'HTTP ' . $response->getStatus());
        $details = $error !== null && isset($error->details) ? $error->details : null;

        /** @var string $safeMessage */
        $safeMessage = Redactor::redact($message, $this->secrets);
        return new FactaError(
            $code,
            $safeMessage,
            $response->getStatus(),
            Redactor::redact($details, $this->secrets),
            self::retryAfterMs($response->getHeader('retry-after'))
        );
    }

    private function networkMessage(TransportException $e, int $timeoutMs): string
    {
        switch ($e->getKind()) {
            case TransportException::KIND_TIMEOUT:
                return 'The Facta API did not answer within ' . $timeoutMs . ' ms; check connectivity and service status.';
            case TransportException::KIND_TLS:
                return 'The secure connection to the Facta API failed. If this server has an outdated certificate store, '
                    . 'pass the path of a current CA bundle in the caBundle option; verification is never disabled.';
            default:
                return 'Could not reach the Facta API; check connectivity and service status.';
        }
    }

    /** Parses `Retry-After` (seconds or HTTP date) into milliseconds, clamped to a sane range. */
    private static function retryAfterMs(?string $header): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        $header = trim($header);
        if (ctype_digit($header)) {
            $ms = (int) $header * 1000;
        } else {
            $time = strtotime($header);
            if ($time === false) {
                return null;
            }
            $ms = max(0, ($time - time()) * 1000);
        }
        return min($ms, self::MAX_RETRY_AFTER_MS);
    }

    /** @param mixed $value */
    private static function validateBaseUrl($value): string
    {
        $parts = is_string($value) ? parse_url($value) : false;
        if (
            $parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new \InvalidArgumentException('baseUrl must be an absolute HTTP or HTTPS URL.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('baseUrl must use HTTP(S) and contain no credentials, query, or fragment.');
        }
        return rtrim((string) $value, '/');
    }

    /** @param mixed $option */
    private function resolveRegion($option): ?string
    {
        if ($option === false) {
            return null;
        }
        if ($option === null) {
            $env = getenv('FACTA_API_REGION');
            if (is_string($env) && $env !== '') {
                $option = strtolower($env) === 'false' ? false : $env;
                if ($option === false) {
                    return null;
                }
            } else {
                return self::DEFAULT_REGION;
            }
        }
        if (!is_string($option) || preg_match(self::REGION_PATTERN, $option) !== 1) {
            throw new \InvalidArgumentException("region must be a Supabase region such as 'us-west-2', or false.");
        }
        return $option;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
