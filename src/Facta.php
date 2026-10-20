<?php

declare(strict_types=1);

namespace Facta\Api;

use Facta\Api\Http\Response;
use Facta\Api\Http\Transport;
use Facta\Api\Http\TransportException;
use Facta\Api\Http\TransportFactory;
use Facta\Api\Json\Json;
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

    private const OPTION_KEYS = [
        'apiKey', 'signKey', 'unlockKey', 'baseUrl', 'timeoutMs', 'maxRetries',
        'region', 'transport', 'caBundle', 'sleeper',
    ];

    /** Errors worth repeating. Exactly the TypeScript SDK's set; everything else is final. */
    private const RETRYABLE = [
        FactaError::IDEMPOTENCY_IN_FLIGHT,
        FactaError::MH_UNREACHABLE,
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
     * Health, environment and the ceilings left on this key (GET /v1/status).
     *
     * @param array<string, mixed> $options Per-call options, see {@see Facta::request()}.
     * @return \stdClass The decoded answer.
     * @throws FactaError
     */
    public function status(array $options = []): \stdClass
    {
        $status = $this->request('GET', '/v1/status', null, $options);
        if (!($status instanceof \stdClass)) {
            throw new FactaError(FactaError::INTERNAL_ERROR, 'API response was not a JSON object.', 200);
        }
        return $status;
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
        $headers = [
            'Accept' => 'application/json',
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
                return $this->attempt($method, $path, $headers, $payload, $timeoutMs);
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
    private function attempt(string $method, string $path, array $headers, ?string $payload, int $timeoutMs)
    {
        try {
            $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $payload, $timeoutMs);
        } catch (TransportException $e) {
            throw new FactaError(FactaError::NETWORK_ERROR, $this->networkMessage($e, $timeoutMs), 0);
        }

        $status = $response->getStatus();
        $text = $response->getBody();
        $decoded = null;
        if ($text !== '') {
            try {
                $decoded = Json::decode($text);
            } catch (\InvalidArgumentException $e) {
                throw new FactaError(
                    FactaError::INTERNAL_ERROR,
                    'API response was not valid JSON (HTTP ' . $status . ').',
                    $status
                );
            }
        }

        if ($status >= 200 && $status < 300) {
            if ($decoded instanceof \stdClass && isset($decoded->debug)) {
                unset($decoded->debug); // not asked for: never hand a debug member to the caller
            }
            return $decoded;
        }

        throw $this->errorFrom($response, $decoded);
    }

    /** @param mixed $payload */
    private function errorFrom(Response $response, $payload): FactaError
    {
        $error = $payload instanceof \stdClass && isset($payload->error) && $payload->error instanceof \stdClass
            ? $payload->error
            : null;
        $code = $error !== null && isset($error->code) && is_string($error->code) ? $error->code : FactaError::INTERNAL_ERROR;
        $message = $error !== null && isset($error->message) && is_string($error->message)
            ? $error->message
            : 'HTTP ' . $response->getStatus();
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
