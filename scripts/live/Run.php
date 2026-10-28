<?php

declare(strict_types=1);

namespace Facta\Api\Live;

use Facta\Api\Facta;
use Facta\Api\FactaError;

/**
 * The live run against the staging API (environment 00), tier 1 of the PHP SDK. It mirrors
 * `scripts/live-integration.mjs` of the TypeScript SDK as far as an SDK without the unlock
 * key, the local archive and BYOS destinations can go, and uses ONLY the SDK's public API.
 *
 * Nothing printed or reported here may carry API messages, document bytes, identifiers or
 * credentials: only fixed strings and error codes.
 */
final class Run
{
    private const PROBE_ID = '00000000-0000-4000-8000-000000000000';
    private const REGION = 'us-west-2';

    /** @var Facta */
    private $facta;
    /** @var array{runId: string, mode: string, deliveryInbox: string|null} */
    private $settings;
    /** @var string|null */
    private $fixturesRaw;
    /** @var callable(string): void */
    private $out;
    /** @var callable(string): void */
    private $err;
    /** @var callable(int): void */
    private $sleep;

    /** @var array<string, string> */
    private $checks;
    /** @var string */
    private $current = 'status';
    /** @var string|null */
    private $failureCode = null;

    /**
     * @param array{runId: string, mode: string, deliveryInbox: string|null} $settings
     * @param callable(string): void $out  Progress lines.
     * @param callable(string): void $err  Failure lines.
     * @param callable(int): void    $sleep Pause in milliseconds (listing is eventually consistent).
     */
    public function __construct(Facta $facta, array $settings, ?string $fixturesRaw, callable $out, callable $err, callable $sleep)
    {
        $this->facta = $facta;
        $this->settings = $settings;
        $this->fixturesRaw = $fixturesRaw;
        $this->out = $out;
        $this->err = $err;
        $this->sleep = $sleep;
        $this->checks = Report::createResults();
    }

    /** @return array<string, string> */
    public function checks(): array
    {
        return $this->checks;
    }

    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    /** Runs every step. Never throws: a failure is recorded and the answer is false. */
    public function execute(): bool
    {
        try {
            $this->run();
            return true;
        } catch (\Throwable $error) {
            $this->checks[$this->current] = 'Failed';
            $this->failureCode = Report::safeFailureCode($error);
            // Never print API messages, stacks, assertion values or document bytes.
            call_user_func($this->err, 'FAIL live integration: ' . $this->current . ' (' . $this->failureCode . ')');
            $detail = self::failureDetail($error);
            call_user_func($this->err, 'FAIL detail: ' . $detail);
            return false;
        }
    }

    private static function failureDetail(\Throwable $error): string
    {
        if ($error instanceof LiveError) {
            // Every LiveError message is a constant written in this directory.
            return $error->getFailureCode() === 'assertion_failed' ? 'assertion: ' . substr($error->getMessage(), 0, 160) : 'code: ' . $error->getFailureCode();
        }
        if ($error instanceof FactaError) {
            return 'code: ' . $error->getErrorCode() . ($error->getStatus() > 0 ? ' (HTTP ' . $error->getStatus() . ')' : '');
        }
        $parts = explode('\\', get_class($error));
        return 'error type: ' . substr((string) end($parts), 0, 40);
    }

    private function log(string $line): void
    {
        call_user_func($this->out, $line);
    }

    /** @throws LiveError */
    private static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new LiveError('assertion_failed', $message);
        }
    }

    private function run(): void
    {
        $facta = $this->facta;
        $runId = $this->settings['runId'];

        $this->current = 'status';
        $health = $facta->status();
        self::require(($health['ok'] ?? null) === true, 'API status must report healthy');
        self::require(($health['ambiente'] ?? null) === '00', 'API status must confirm the test environment');
        self::require(($health['emisor']['ambiente'] ?? null) === '00', 'issuer must also be in the test environment');
        $this->log('INFO region: pinned=' . ($facta->region() ?? 'none') . ' served=' . ($facta->servedRegion() ?? 'unknown'));
        if (isset($health['region']) && is_string($health['region'])) {
            self::require($health['region'] === self::REGION, 'API status must advertise the database region');
            if ($facta->servedRegion() !== null) {
                self::require($facta->servedRegion() === self::REGION, 'requests must be served from the database region');
            } else {
                $this->log('WARN region: the served region header was not present');
            }
        } else {
            $this->log('WARN region: the API does not advertise a region yet; the built-in default was used');
        }
        $this->checks['status'] = 'Passed';
        $this->log('PASS status: healthy test environment confirmed');

        $this->current = 'preflight';
        $fixtures = Fixtures::parse($this->fixturesRaw);
        $matrix = Fixtures::assess($health, $fixtures);
        foreach ($matrix as $type => $state) {
            $this->checks['dte-' . $type] = $state;
        }
        if ($this->settings['mode'] === 'emit-enabled-dte-fixtures') {
            $this->current = 'fixture-matrix';
            Fixtures::requireComplete($matrix);
            $statuses = [];
            foreach (Fixtures::relatedGenerationCodes($fixtures) as $code) {
                $statuses[] = $facta->getDocumentStatus($code);
            }
            Fixtures::assertRelatedTestDocuments($fixtures, $statuses);
        }

        $this->current = 'preflight';
        $diagnostics = $facta->diagnose([
            'dteType' => '01',
            'expectedEnvironment' => '00',
            'requiredScopes' => ['issue', 'query', 'download'],
        ]);
        // Read-only capability checks must pass before this run issues anything.
        $storage = $facta->getStorageStatus();
        self::require($storage['managed']['ready'] === true, 'Full managed live validation requires ready managed storage.');
        $this->validateCatalogWriteGate();
        $this->log('SKIP local storage: the unlock key, the local archive and BYOS destinations are not in this SDK version');
        $this->current = 'preflight';
        if (!$diagnostics['canIssue'] || !$diagnostics['canQuery'] || !$diagnostics['canDownload']) {
            throw new LiveError('permission_missing', 'Readiness checks blocked live issuance.');
        }
        $this->checks['preflight'] = 'Passed';
        $this->log('PASS diagnose: issue, query and download are ready');

        $idempotencyKey = 'php-sdk-live-' . $runId . '-01';
        $this->current = 'emission';
        $request = ['tipoDte' => '01', 'items' => [['descripcion' => 'Facta API SDK integration test', 'cantidad' => 1, 'precioUni' => 0.01]]];
        $emission = $facta->issue($request, ['idempotencyKey' => $idempotencyKey]);
        self::require(($emission['estado'] ?? null) === 'sellado', 'test invoice must be sealed by Hacienda');
        self::require(isset($emission['totales']['totalPagar']) && (float) $emission['totales']['totalPagar'] === 0.01, 'test invoice must total exactly $0.01');
        self::require(($emission['ambiente'] ?? null) === '00', 'issued document must be in test environment');
        self::require(($emission['tipoDte'] ?? null) === '01', 'issued document must be FE');
        self::require(isset($emission['codigoGeneracion']) && is_string($emission['codigoGeneracion']), 'the answer must carry the generation code');
        $code = $emission['codigoGeneracion'];
        $this->checks['emission'] = 'Passed (sealed test FE, $0.01)';
        $this->checks['dte-01'] = 'Passed (sealed test FE, $0.01)';
        $this->log('PASS issue: test FE sealed');

        $this->current = 'signed-json';
        $archivoJson = $emission['archivoJson'] ?? null;
        self::require(is_string($archivoJson) && $archivoJson !== '', 'API must return inline signed JSON');
        $stored = json_decode((string) $archivoJson, true);
        self::require(is_array($stored) && isset($emission['jws']) && ($stored['jws'] ?? null) === $emission['jws'], 'the stored JSON must preserve the server-signed JWS');
        $archivoDte = $emission['archivoDte'] ?? null;
        if (is_string($archivoDte)) {
            $parsed = json_decode($archivoDte, true);
            self::require(is_array($parsed) && ($parsed['firmaElectronica'] ?? null) === $emission['jws'], 'Archivo DTE must carry the exact JWS');
            self::require(($parsed['selloRecibido'] ?? null) === ($emission['selloRecibido'] ?? false), 'Archivo DTE must carry Hacienda\'s seal');
            $this->log('INFO archivoDte served by the API');
        } else {
            $this->log('WARN archivoDte not served by this API yet; checked through the download below');
        }
        $this->checks['signed-json'] = 'Passed';

        $this->current = 'pdf';
        $inlinePdf = isset($emission['representacionGrafica']) && is_string($emission['representacionGrafica'])
            ? base64_decode($emission['representacionGrafica'], true)
            : false;
        self::require(is_string($inlinePdf) && strncmp($inlinePdf, '%PDF-', 5) === 0, 'inline PDF must be a PDF');
        $this->checks['pdf'] = 'Passed';

        $this->current = 'download-json';
        $json = $facta->downloadDocument($code, 'json');
        self::require($json['bytes'] !== '' && stripos($json['contentType'], 'json') !== false, 'the Archivo DTE download must be JSON');
        $file = json_decode($json['bytes'], true);
        self::require(is_array($file) && ($file['firmaElectronica'] ?? null) === $emission['jws'], 'downloaded Archivo DTE must carry the exact JWS');
        self::require(($file['selloRecibido'] ?? null) === ($emission['selloRecibido'] ?? false), 'downloaded Archivo DTE must carry Hacienda\'s seal');
        self::require(!isset($json['jsonFormat']) || $json['jsonFormat'] === 'archivo-dte', 'the default JSON download must be the Archivo DTE');
        $this->log('INFO download json sha256=' . substr(hash('sha256', $json['bytes']), 0, 16) . ' bytes=' . strlen($json['bytes'])
            . (is_string($archivoDte) ? ($archivoDte === $json['bytes'] ? ' identical to the answer' : ' differs from the answer') : ''));
        $this->checks['download-json'] = 'Passed';

        $this->current = 'download-pdf';
        $pdf = $facta->downloadDocument($code, 'pdf');
        self::require(strncmp($pdf['bytes'], '%PDF-', 5) === 0 && stripos($pdf['contentType'], 'pdf') !== false, 'the PDF download must be a PDF');
        $this->log('INFO download pdf sha256=' . substr(hash('sha256', $pdf['bytes']), 0, 16) . ' bytes=' . strlen($pdf['bytes'])
            . ($pdf['bytes'] === $inlinePdf ? ' identical to the answer' : ' differs from the answer'));
        $this->checks['download-pdf'] = 'Passed';

        $this->current = 'ticket';
        $ticket = $facta->downloadDocument($code, 'ticket', ['paperWidthMm' => 58]);
        self::require(strncmp($ticket['bytes'], '%PDF-', 5) === 0 && ($ticket['paperWidthMm'] ?? null) === 58, 'the ticket must be a PDF of the requested width');
        $this->checks['ticket'] = 'Passed';
        $this->log('PASS documents: Archivo DTE, PDF and ticket downloaded');

        $this->validateManagedStorage($emission, $request, $idempotencyKey, (string) $archivoJson, $inlinePdf);

        if ($this->settings['mode'] === 'emit-enabled-dte-fixtures') {
            foreach (Fixtures::TYPES as $type) {
                $this->current = 'dte-' . $type;
                self::require(isset($fixtures[$type]), 'every fixture must be configured');
                $fixtureResult = $facta->issue($fixtures[$type], ['idempotencyKey' => 'php-sdk-live-' . $runId . '-' . $type]);
                self::require(($fixtureResult['estado'] ?? null) === 'sellado', 'test DTE fixture must be sealed');
                self::require(($fixtureResult['tipoDte'] ?? null) === $type, 'fixture type must match');
                self::require(($fixtureResult['ambiente'] ?? null) === '00', 'fixture must be in the test environment');
                self::require(isset($fixtureResult['totales']['totalPagar']) && (float) $fixtureResult['totales']['totalPagar'] === 0.01, 'fixture must total $0.01');
                $fixtureStatus = $facta->getDocumentStatus((string) $fixtureResult['codigoGeneracion']);
                self::require(($fixtureStatus['estado'] ?? null) === 'sellado' && ($fixtureStatus['ambiente'] ?? null) === '00', 'fixture must stay sealed');
                $this->checks['dte-' . $type] = 'Passed (test fixture sealed)';
            }
        }

        $this->current = 'query';
        $status = $facta->getDocumentStatus($code);
        self::require(strtoupper((string) ($status['codigoGeneracion'] ?? '')) === strtoupper($code), 'queried UUID must identify the issued invoice regardless of database casing');
        self::require(($status['ambiente'] ?? null) === '00', 'queried invoice must be in the test environment');
        self::require(($status['estado'] ?? null) === 'sellado', 'queried invoice must remain sealed');
        $this->checks['query'] = 'Passed';

        $this->current = 'listing';
        $listed = false;
        for ($attempt = 0; $attempt < 6 && !$listed; $attempt++) {
            $page = $facta->listDocuments(['tipoDte' => '01', 'limit' => 20]);
            foreach ($page['documentos'] as $row) {
                if (isset($row['codigoGeneracion']) && is_string($row['codigoGeneracion']) && strtoupper($row['codigoGeneracion']) === strtoupper($code)) {
                    $listed = true;
                    break;
                }
            }
            if (!$listed && $attempt < 5) {
                call_user_func($this->sleep, 2000);
            }
        }
        self::require($listed, 'the new FE must appear in the document list');
        $this->checks['listing'] = 'Passed';
        $this->log('PASS query and listing: test FE found and sealed');

        $this->current = 'email-delivery';
        if ($this->settings['deliveryInbox'] === null) {
            $this->checks['email-delivery'] = 'Not run (no test inbox configured)';
            $this->log('SKIP e-mail delivery: STAGING_FACTA_DELIVERY_EMAIL is not configured');
        } else {
            $this->validateEmailDelivery($request, $this->settings['deliveryInbox']);
        }
    }

    /**
     * The CI key's company keeps its catalog encrypted, so the catalog write routes must refuse. The
     * probe deactivates an id that does not exist: even if a server wrongly allowed the write, nothing
     * real is touched. Until the routes are deployed or while the key lacks the scope, this only warns;
     * any other outcome, including a success, fails the run.
     */
    private function validateCatalogWriteGate(): void
    {
        $this->current = 'catalog-write-gate';
        try {
            $this->facta->deactivateCustomer(self::PROBE_ID);
        } catch (FactaError $cause) {
            $code = $cause->getErrorCode();
            if ($code === FactaError::CATALOG_WRITE_DISABLED || $code === FactaError::CATALOG_ENCRYPTED) {
                $this->checks['catalog-write-gate'] = 'Passed (writes refused for the encrypted CI company)';
            } elseif ($code === FactaError::FORBIDDEN_SCOPE) {
                $this->checks['catalog-write-gate'] = 'Warning (key lacks catalog:write)';
            } elseif (in_array($cause->getStatus(), [404, 405, 501], true) || $code === FactaError::NOT_FOUND || $code === FactaError::METHOD_NOT_ALLOWED) {
                $this->checks['catalog-write-gate'] = 'Warning (catalog write routes not deployed on staging yet)';
            } else {
                throw new LiveError('catalog_write_gate_failed', 'Catalog write gate answered unexpectedly.');
            }
            $this->log(($this->checks['catalog-write-gate'] === 'Passed (writes refused for the encrypted CI company)' ? 'PASS' : 'WARN')
                . ' catalog write gate: ' . $this->checks['catalog-write-gate']);
            return;
        }
        throw new LiveError('catalog_write_gate_failed', 'Catalog write was accepted for the encrypted CI company.');
    }

    /**
     * Verifies only the explicitly issued test document; never emits a second request identity.
     *
     * @param array<string, mixed> $emission
     * @param array<string, mixed> $request
     * @param string|false         $inlinePdf
     */
    private function validateManagedStorage(array $emission, array $request, string $idempotencyKey, string $inlineJson, $inlinePdf): void
    {
        $facta = $this->facta;
        $code = (string) $emission['codigoGeneracion'];
        $this->current = 'managed-receipt';
        $receipt = $emission['storage'] ?? null;
        self::require(!isset($emission['storageErrorCode']) && is_array($receipt) && ($receipt['destination'] ?? null) === 'managed', 'Managed storage receipt is required.');
        if (($receipt['json']['state'] ?? null) !== 'stored' || ($receipt['pdf']['state'] ?? null) !== 'stored') {
            $receipt = $facta->retryDocumentStorage($code);
        }
        self::require(($receipt['json']['state'] ?? null) === 'stored' && ($receipt['pdf']['state'] ?? null) === 'stored', 'Managed copies must be durable before validation completes.');
        $this->checks['managed-receipt'] = 'Passed';

        $this->current = 'managed-readback';
        $copies = $facta->getDocumentCopies(['generationCode' => $code]);
        foreach (['json' => $inlineJson, 'pdf' => (string) $inlinePdf] as $kind => $local) {
            $digest = hash('sha256', $local);
            $copy = null;
            foreach ($copies as $candidate) {
                if ($candidate['kind'] === $kind) {
                    $copy = $candidate;
                    break;
                }
            }
            self::require($copy !== null && $copy['state'] === 'stored' && strtolower($copy['sha256']) === $digest && $copy['bytes'] === strlen($local), 'Managed copy metadata must match exact local bytes.');
            self::require(strtolower((string) $receipt[$kind]['sha256']) === $digest && $receipt[$kind]['bytes'] === strlen($local), 'Receipt must match exact local bytes.');
            $options = ['source' => 'managed'] + ($kind === 'json' ? ['raw' => true] : []);
            $downloaded = $facta->downloadDocument($code, $kind, $options);
            self::require(($downloaded['storageSource'] ?? null) === 'managed' && hash('sha256', $downloaded['bytes']) === $digest, 'Managed read-back must preserve exact bytes.');
        }
        $this->checks['managed-readback'] = 'Passed';
        $this->checks['inline-bytes'] = 'Passed';

        $this->current = 'managed-replay-repair';
        $before = $facta->getStorageStatus();
        $this->current = 'replay';
        $replay = $facta->issue($request, ['idempotencyKey' => $idempotencyKey]);
        self::require(
            isset($replay['codigoGeneracion']) && is_string($replay['codigoGeneracion']) && strtoupper($replay['codigoGeneracion']) === strtoupper($code),
            'Idempotent replay must identify the original invoice.'
        );
        self::require(!isset($replay['storageErrorCode']), 'Idempotent replay must keep the managed receipt valid.');
        $this->checks['replay'] = 'Passed';
        $this->current = 'managed-replay-repair';
        $repaired = $facta->retryDocumentStorage($code);
        self::require($repaired['json']['state'] === 'stored' && $repaired['pdf']['state'] === 'stored', 'Copy repair must retain durable copies.');
        $after = $facta->getStorageStatus();
        self::require(
            $before['managed']['usedBytesTotal'] === $after['managed']['usedBytesTotal']
            && $before['managed']['reservedBytesTotal'] === $after['managed']['reservedBytesTotal'],
            'Replay and repair must not increase managed accounting.'
        );
        $this->checks['managed-replay-repair'] = 'Passed';
        $this->log('PASS managed storage: receipts, exact read-back, replay and repair accounting');
    }

    /**
     * Issues one more $0.01 test FE marked for e-mail delivery, starts it, and waits for its final
     * state. A reached mail quota or a provider outage only warns; any other outcome that is not
     * "enviado" to the right inbox fails the run.
     *
     * @param array<string, mixed> $request
     */
    private function validateEmailDelivery(array $request, string $inbox): void
    {
        $facta = $this->facta;
        $emission = $facta->issue($request, [
            'idempotencyKey' => 'php-sdk-live-' . $this->settings['runId'] . '-mail',
            'deliver' => ['email' => $inbox],
        ]);
        self::require(($emission['estado'] ?? null) === 'sellado', 'the delivery test invoice must be sealed');
        self::require(($emission['ambiente'] ?? null) === '00', 'the delivery test invoice must be in the test environment');
        $token = $emission['entrega']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            // No token: the API declined the channel up front; its state says why.
            $observed = ['channel' => $emission['entrega']['canales']['correo'] ?? null, 'settled' => true, 'expectedRecipient' => $inbox];
        } else {
            try {
                $facta->deliverEmail((string) $emission['codigoGeneracion'], $token);
                $waited = $facta->waitForDelivery((string) $emission['codigoGeneracion'], ['channels' => ['correo'], 'timeoutMs' => 90000, 'intervalMs' => 3000]);
                $observed = ['channel' => $waited['canales']['correo'] ?? null, 'settled' => (bool) $waited['settled'], 'expectedRecipient' => $inbox];
            } catch (FactaError $error) {
                $observed = ['error' => $error];
            }
        }
        $verdict = EmailDelivery::verdict($observed);
        $this->checks['email-delivery'] = EmailDelivery::reportState($verdict);
        if ($verdict['outcome'] === 'warn') {
            foreach (EmailDelivery::warningLines($verdict) as $line) {
                $this->log($line);
            }
            return;
        }
        if ($verdict['outcome'] === 'fail') {
            call_user_func($this->err, 'FAIL e-mail delivery: ' . $verdict['code'] . (isset($verdict['status']) ? ' (HTTP ' . $verdict['status'] . ')' : ''));
            throw new LiveError('email_delivery_failed', 'E-mail delivery failed.');
        }
        $this->log('PASS e-mail delivery: sent to the configured test inbox');
    }

    /**
     * Writes the report where the workflow comment step reads it.
     */
    public function saveReport(string $directory, ?string $stepSummary): void
    {
        $report = Report::render($this->checks, $this->failureCode);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory . '/report.md', $report);
        chmod($directory . '/report.md', 0600);
        if ($stepSummary !== null && $stepSummary !== '') {
            file_put_contents($stepSummary, $report, FILE_APPEND);
        }
    }
}
