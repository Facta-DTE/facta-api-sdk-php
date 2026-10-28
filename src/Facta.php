<?php

declare(strict_types=1);

namespace Facta\Api;

use Facta\Api\Http\Response;
use Facta\Api\Http\Transport;
use Facta\Api\Http\TransportException;
use Facta\Api\Http\TransportFactory;
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
    public const VERSION = '0.2.0';

    public const DEFAULT_BASE_URL = 'https://hcnvknpsbadplnfcflxx.supabase.co/functions/v1/api-v1';

    private const DEFAULT_REGION = 'us-west-2';
    private const REGION_PATTERN = '/^[a-z]{2}(?:-[a-z]+)+-\d+$/';
    private const MAX_RETRY_AFTER_MS = 60000;
    private const SERVER_CATALOG_PAGE = 200;

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
        FactaError::DATA_GRANT_UNAVAILABLE,
        FactaError::DATA_EPOCH_STALE,
        FactaError::NETWORK_ERROR,
    ];

    /** The routes that open the signing vault and therefore carry the second password. */
    private const SIGNING_PATHS = ['/v1/dte', '/v1/dte/sign'];
    private const SIGNING_EVENT_PATH = '#^/v1/dte/[^/?]+/(?:invalidate|return)$#';
    /** Issuing a draft opens the signing vault like POST /v1/dte, so it carries the second password too. */
    private const SIGNING_DRAFT_PATH = '#^/v1/drafts/[^/?]+/issue$#';

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
    /** @var array<string, string>|null Response headers of the last successful call (single-threaded read-back). */
    private $lastHeaders = null;

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
     * Checks that need local storage (the restart-safe archive) are reported as `unknown`
     * with "not available in this SDK version yet"; `canIssueAndArchive` is therefore always false.
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
        $key = isset($status['llave']) && is_array($status['llave']) ? $status['llave'] : [];
        $report['catalogMode'] = isset($key['catalogMode']) && is_string($key['catalogMode']) ? $key['catalogMode'] : null;
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
     * `receptor.customerId` and `items[].productId` travel untouched: the server resolves them from the
     * company catalog in every catalog mode (an unknown id is `not_found`, `details.reason`
     * `catalog_unknown_id`). A retry resends the same bytes and the same `Idempotency-Key`.
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

        return $this->callArray('POST', '/v1/dte', self::withDelivery($request, $deliver), $options);
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
        $raw = $this->execute('POST', '/v1/dte/prepare', $request, $options, false);
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
    // Every read goes straight to the API. The server reads the catalogue in every mode (plain,
    // readable, or encrypted at rest and shared with this key), so no `unlockKey` is needed and the
    // SDK keeps no local copy. `customerId` / `productId` in a document request are resolved by the
    // server the same way.

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
        $rows = $this->listAll('/v1/customers', ['clientes'], $all === true ? ['incluirInactivos' => 'true'] : []);
        return self::onlyActive($rows, $all === true);
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
        $row = $this->getOne('/v1/customers/' . rawurlencode($id), ['cliente']);
        return $row === null || ($row['active'] === false && ($options['includeInactive'] ?? false) !== true) ? null : $row;
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
        $rows = $this->listAll('/v1/products', ['productos'], $all === true ? ['incluirInactivos' => 'true'] : []);
        return self::onlyActive($rows, $all === true);
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
        $row = $this->getOne('/v1/products/' . rawurlencode($id), ['producto']);
        return $row === null || ($row['active'] === false && ($options['includeInactive'] ?? false) !== true) ? null : $row;
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
     * On a company that shares its data key, pass `ifMatch` (the `revision` you read) to apply the
     * edit only when that is still the current revision; otherwise the server answers
     * `catalog_revision_conflict` and `details.revisionActual` carries the current one. Without it
     * the last write wins per field sent.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $options `idempotencyKey`, `ifMatch` (int|string).
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
            $this->withIfMatch($this->options($options, ['idempotencyKey', 'ifMatch'])),
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
     * `ifMatch` works as in {@see Facta::updateCustomer()}.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $options `idempotencyKey`, `ifMatch` (int|string).
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
            $this->withIfMatch($this->options($options, ['idempotencyKey', 'ifMatch'])),
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

    // --- Catalogue changes and webhooks (shared data key) -------------------------------------
    //
    // Ids and revisions only, never the content: read the record with getCustomer() / getProduct().
    // Scope `catalog:read` (or `catalog:write`).

    /**
     * What changed in the company catalogue since a revision (`GET /v1/catalog/changes?since=`):
     * ids, revision, operation and actor, in revision order. Start with `$since = 0`, apply the
     * page, and continue with `siguiente` while `hayMas` is true. Send back the `generacion` you
     * received, so a restore is not mistaken for a long history.
     *
     * A cursor the server can no longer continue from is `FactaError` `catalog_cursor_expired`
     * (HTTP 410, `details.reason`, with the current `cabeza` and `generacion`): read everything
     * again and continue from there.
     *
     * @param array<string, mixed> $options `generation` (UUID from a previous page), `limit` (1-1000, default 200).
     * @return array<string, mixed> `generacion`, `cabeza`, `cambios` (list), `siguiente` (int), `hayMas` (bool).
     * @throws FactaError
     */
    public function listCatalogChanges(int $since = 0, array $options = []): array
    {
        $options = $this->options($options, ['generation', 'limit']);
        if ($since < 0) {
            throw new \InvalidArgumentException('since must be a non-negative integer.');
        }
        $query = ['since' => (string) $since];
        if (isset($options['generation'])) {
            if (!is_string($options['generation']) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $options['generation']) !== 1) {
                throw new \InvalidArgumentException('generation must be the UUID returned as `generacion`.');
            }
            $query['generacion'] = $options['generation'];
        }
        if (isset($options['limit'])) {
            if (!is_int($options['limit']) || $options['limit'] < 1 || $options['limit'] > 1000) {
                throw new \InvalidArgumentException('limit must be an integer from 1 to 1000.');
            }
            $query['limite'] = (string) $options['limit'];
        }
        $page = $this->callArray('GET', '/v1/catalog/changes' . self::queryString($query), null, $this->callOptions($options));
        if (!isset($page['cambios']) || !is_array($page['cambios']) || !isset($page['siguiente']) || !is_int($page['siguiente'])) {
            throw new FactaError(FactaError::INTERNAL_ERROR, 'The catalogue changes answer is malformed.', 502);
        }
        return $page;
    }

    /**
     * Registers a webhook for catalogue changes (at most 5 per key). The answer carries `secreto`
     * ONCE: keep it, and verify every delivery with {@see \Facta\Api\Webhook::verify()}.
     * The URL must be https on port 443 and public.
     *
     * @param array<string, mixed> $input   `url` (required), `eventos` (string[], empty = all), `descripcion`.
     * @param array<string, mixed> $options
     * @return array<string, mixed> `id`, `url`, `eventos`, `activo`, `secreto`, ...
     * @throws FactaError
     */
    public function createWebhook(array $input, array $options = []): array
    {
        $body = self::webhookBody($input, ['url', 'eventos', 'descripcion'], true);
        return $this->callArray('POST', '/v1/webhooks', $body, $this->options($options, ['idempotencyKey']));
    }

    /**
     * The webhooks of this key (never with the secret).
     *
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     * @throws FactaError
     */
    public function listWebhooks(array $options = []): array
    {
        $payload = $this->callArray('GET', '/v1/webhooks', null, $this->options($options, []));
        return isset($payload['webhooks']) && is_array($payload['webhooks']) ? array_values($payload['webhooks']) : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function getWebhook(string $webhookId, array $options = []): array
    {
        return $this->callArray('GET', '/v1/webhooks/' . rawurlencode(Catalog::requireId($webhookId, 'webhook')), null, $this->options($options, []));
    }

    /**
     * Changes the URL, the events, the description, or switches the webhook on and off
     * (`['activo' => true]` also clears the failure count after Facta switched it off).
     *
     * @param array<string, mixed> $changes `url`, `eventos`, `descripcion` (null clears), `activo`.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function updateWebhook(string $webhookId, array $changes, array $options = []): array
    {
        $id = Catalog::requireId($webhookId, 'webhook');
        $body = self::webhookBody($changes, ['url', 'eventos', 'descripcion', 'activo'], false);
        return $this->callArray('PATCH', '/v1/webhooks/' . rawurlencode($id), $body, $this->options($options, []));
    }

    /**
     * Deletes a webhook, its secret and whatever it had queued.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> `id`, `eliminado`.
     * @throws FactaError
     */
    public function deleteWebhook(string $webhookId, array $options = []): array
    {
        return $this->callArray('DELETE', '/v1/webhooks/' . rawurlencode(Catalog::requireId($webhookId, 'webhook')), null, $this->options($options, []));
    }

    /**
     * Replaces the signing secret. The old one stops working at once; the new one (`secreto`) is shown once.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function rotateWebhookSecret(string $webhookId, array $options = []): array
    {
        $path = '/v1/webhooks/' . rawurlencode(Catalog::requireId($webhookId, 'webhook')) . '/rotate-secret';
        return $this->callArray('POST', $path, null, $this->options($options, ['idempotencyKey']));
    }

    /**
     * Queues a `webhook.test` delivery (same body, same signature) so you can check your receiver
     * without touching the catalogue. It leaves within the next minute.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> `entregaId`, `evento`.
     * @throws FactaError
     */
    public function testWebhook(string $webhookId, array $options = []): array
    {
        $path = '/v1/webhooks/' . rawurlencode(Catalog::requireId($webhookId, 'webhook')) . '/test';
        return $this->callArray('POST', $path, null, $this->options($options, ['idempotencyKey']));
    }

    // --- Drafts (scope `draft`; issuing also needs `issue` and the signing key) ----------------------
    //
    // A draft is an invoice your program leaves for a person of the company to review and issue in the
    // Facta DTE app. Facta validates it like an emission up to the point where a control number would be
    // reserved (none is spent), seals it to the company's public draft key and keeps no plaintext. It
    // expires after 30 days; a company holds at most 500. Bodies and answers use the API's own member
    // names (`id`, `referencia`, `borradorId`, `estado`, `version`, ...).

    /**
     * Leaves an invoice as a draft. `$request` is the same array as {@see Facta::issue()} plus an optional
     * `id` (your UUID v4) and `referencia` (up to 64 characters, no personal data). Repeating the call with
     * the same `idempotencyKey` returns the same draft; another body under that key is
     * `idempotency_key_reuse`. `drafts_not_ready` (409) means the owner has not opened Facta with their
     * vault since the company began sharing its data key: ask them to open it once.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $options `idempotencyKey`, `timeoutMs`, `headers`.
     * @return array<string, mixed> `borradorId`, `estado`, `version`, `totales`.
     * @throws FactaError
     */
    public function createDraft(array $request, array $options = []): array
    {
        $options = $this->options($options, ['idempotencyKey']);
        return $this->draftOf($this->callArray('POST', '/v1/drafts', self::draftBody($request), $options));
    }

    /**
     * The drafts this key left. Follow `siguiente` (pass it back as `cursor`) for more.
     *
     * @param array<string, mixed> $options `state` (pendiente, en_revision, emitiendo, emitido, descartado,
     *        cancelado, vencido, aplicado, aplicado_parcial, rechazado), `reference` (your own), `limit`,
     *        `cursor`, `include` ("contenido" adds each draft's resolved request when this key may open the
     *        company data key, max 50 per page; "envelope" the legacy envelope).
     * @return array<string, mixed> `borradores` (list), `siguiente` (string|null).
     * @throws FactaError
     */
    public function listDrafts(array $options = []): array
    {
        $options = $this->options($options, ['state', 'reference', 'limit', 'cursor', 'include']);
        $query = [];
        foreach (['state' => 'estado', 'reference' => 'referencia', 'cursor' => 'cursor', 'include' => 'include'] as $name => $wire) {
            if (isset($options[$name])) {
                if (!is_string($options[$name]) || $options[$name] === '') {
                    throw new \InvalidArgumentException($name . ' must be a non-empty string.');
                }
                $query[$wire] = $options[$name];
            }
        }
        if (isset($options['limit'])) {
            if (!is_int($options['limit']) || $options['limit'] < 1) {
                throw new \InvalidArgumentException('limit must be a positive integer.');
            }
            $query['limite'] = (string) $options['limit'];
        }
        unset($options['state'], $options['reference'], $options['limit'], $options['cursor'], $options['include']);
        $page = $this->callArray('GET', '/v1/drafts' . self::queryString($query), null, $options);
        return [
            'borradores' => isset($page['borradores']) && is_array($page['borradores']) ? array_values($page['borradores']) : [],
            'siguiente' => isset($page['siguiente']) && is_string($page['siguiente']) ? $page['siguiente'] : null,
        ];
    }

    /**
     * One draft and, when it was issued, the document it became (`codigoGeneracion`, `numeroControl`,
     * `estadoDocumento`, `selloRecibido`, `emitidoPor`, `editado`).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function getDraft(string $draftId, array $options = []): array
    {
        $path = '/v1/drafts/' . rawurlencode(Catalog::requireId($draftId, 'draft'));
        return $this->draftOf($this->callArray('GET', $path, null, $this->options($options, [])));
    }

    /**
     * Follows draft to invoice without the generation code: reads a draft by the `idempotencyKey` it
     * was created with.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function getDraftByKey(string $idempotencyKey, array $options = []): array
    {
        if ($idempotencyKey === '' || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException('The draft\'s idempotency key is required (up to 200 characters).');
        }
        $path = '/v1/drafts/by-key/' . rawurlencode($idempotencyKey);
        return $this->draftOf($this->callArray('GET', $path, null, $this->options($options, [])));
    }

    /**
     * Saves a new version of a draft that is still pending. `$version` is the one you read; it travels
     * as `If-Match` (`version_mismatch`, 409, when stale). Refused while a person has the draft open in
     * the app, while it is being issued, or once resolved.
     *
     * @param array<string, mixed> $request Same shape as {@see Facta::createDraft()}.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function updateDraft(string $draftId, int $version, array $request, array $options = []): array
    {
        $id = Catalog::requireId($draftId, 'draft');
        $options = $this->options($options, []);
        $headers = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];
        $headers['If-Match'] = (string) self::draftVersion($version);
        $options['headers'] = $headers;
        return $this->draftOf($this->callArray('PUT', '/v1/drafts/' . rawurlencode($id), self::draftBody($request), $options));
    }

    /**
     * Cancels a draft that is still pending and closed.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function cancelDraft(string $draftId, array $options = []): array
    {
        $path = '/v1/drafts/' . rawurlencode(Catalog::requireId($draftId, 'draft'));
        return $this->draftOf($this->callArray('DELETE', $path, null, $this->options($options, [])));
    }

    /**
     * Issues a draft through the same road as {@see Facta::issue()}: needs the `issue` scope and the
     * signing key, and spends a control number. `$version` is the draft's current version. One door
     * prevents double issuing: `draft_issuing` or `draft_claimed` (409) spent nothing. A Hacienda
     * rejection counts as issued and the draft is not reopened. The answer is the one of `issue` plus
     * `borradorId`.
     *
     * @param array<string, mixed> $request Same array as {@see Facta::issue()}.
     * @param array<string, mixed> $options Same as {@see Facta::issue()}: `idempotencyKey`, `deliver`, `timeoutMs`, `headers`.
     * @return array<string, mixed>
     * @throws FactaError
     */
    public function issueDraft(string $draftId, int $version, array $request, array $options = []): array
    {
        $id = Catalog::requireId($draftId, 'draft');
        $options = $this->options($options, ['idempotencyKey', 'deliver']);
        $deliver = $options['deliver'] ?? null;
        unset($options['deliver']);
        $body = self::withDelivery($request, $deliver);
        $body['version'] = self::draftVersion($version);
        return $this->callArray('POST', '/v1/drafts/' . rawurlencode($id) . '/issue', $body, $options);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function draftBody(array $request): array
    {
        if (isset($request['referencia']) && (!is_string($request['referencia']) || $request['referencia'] === '' || strlen($request['referencia']) > 64)) {
            throw new \InvalidArgumentException('referencia must be a text of 1 to 64 characters.');
        }
        return $request;
    }

    private static function draftVersion(int $version): int
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('version must be the draft\'s version number read from the draft (for example 3).');
        }
        return $version;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function draftOf(array $payload): array
    {
        if (!isset($payload['borradorId']) || !is_string($payload['borradorId']) || !isset($payload['version']) || !is_int($payload['version'])) {
            throw new FactaError(FactaError::INTERNAL_ERROR, 'The draft answer carries no id and version.', 502);
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     * @param string[]             $allowed
     * @return array<string, mixed>
     */
    private static function webhookBody(array $input, array $allowed, bool $create): array
    {
        $body = [];
        foreach ($input as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException('Unknown webhook field: ' . (string) $name . '.');
            }
            $body[$name] = $value;
        }
        if ($create && (!isset($body['url']) || !is_string($body['url']) || trim($body['url']) === '')) {
            throw new \InvalidArgumentException('A webhook needs a url.');
        }
        if (!$create && $body === []) {
            throw new \InvalidArgumentException('Pass at least one field to change.');
        }
        if (isset($body['eventos'])) {
            if (!is_array($body['eventos'])) {
                throw new \InvalidArgumentException('eventos must be a list of event names.');
            }
            $body['eventos'] = array_values($body['eventos']);
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function callOptions(array $options): array
    {
        return array_intersect_key($options, ['timeoutMs' => true, 'headers' => true]);
    }

    /**
     * @param string[]                  $keys
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $options
     * @return array<string, mixed>
     */
    private function catalogWrite(string $method, string $path, array $keys, ?array $body, array $options, ?string $id): array
    {
        $this->lastHeaders = null;
        $payload = $this->callArray($method, $path, $body, $options);
        $record = Catalog::recordOf($payload, $keys);
        if ($record !== null) {
            return $this->withRevision(self::normalizeRecord($path, $record));
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
        $this->lastHeaders = null;
        try {
            $payload = $this->callArray('GET', $path, null, []);
        } catch (FactaError $cause) {
            if ($cause->getStatus() === 404 && $cause->getErrorCode() === FactaError::NOT_FOUND) {
                return null;
            }
            throw $cause;
        }
        $record = Catalog::recordOf($payload, $keys);
        return $record === null ? null : $this->withRevision(self::normalizeRecord($path, $record));
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
     * Turns the `ifMatch` option into the `If-Match` header the shared catalogue reads.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function withIfMatch(array $options): array
    {
        if (!array_key_exists('ifMatch', $options)) {
            return $options;
        }
        $revision = $options['ifMatch'];
        unset($options['ifMatch']);
        if (is_string($revision) && preg_match('/^"?\d{1,12}"?$/', $revision) === 1) {
            $revision = (int) trim($revision, '"');
        }
        if (!is_int($revision) || $revision < 0) {
            throw new \InvalidArgumentException('ifMatch must be the catalogue revision you read (a non-negative integer).');
        }
        $headers = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];
        $headers['If-Match'] = '"' . $revision . '"';
        $options['headers'] = $headers;
        return $options;
    }

    /**
     * Adds `revision` (from `Facta-Catalog-Revision` or `ETag`) to a record read or written on a
     * company that shares its data key; other catalogues have no revision to report.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function withRevision(array $record): array
    {
        $headers = $this->lastHeaders;
        $this->lastHeaders = null;
        if ($headers === null) {
            return $record;
        }
        $raw = $headers['facta-catalog-revision'] ?? ($headers['etag'] ?? null);
        if (is_string($raw) && preg_match('/^(?:W\/)?"?(\d{1,12})"?$/', trim($raw), $m) === 1) {
            $record['revision'] = (int) $m[1];
        }
        return $record;
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
            && (
                in_array($path, self::SIGNING_PATHS, true)
                || preg_match(self::SIGNING_EVENT_PATH, $path) === 1
                || preg_match(self::SIGNING_DRAFT_PATH, $path) === 1
            )
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
            $this->lastHeaders = $response->getHeaders();
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
