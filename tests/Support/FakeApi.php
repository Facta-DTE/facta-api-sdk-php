<?php

declare(strict_types=1);

namespace Facta\Api\Tests\Support;

use Facta\Api\Http\Response;
use Facta\Api\Http\Transport;

/**
 * A small stateful imitation of the staging API, enough to run the live script offline:
 * idempotent issuing, storage receipts that match the bytes, downloads and delivery.
 * It stands in for the server; it is not a statement of what the server does.
 */
final class FakeApi implements Transport
{
    public const CG = '3C1E7A55-9B0D-4C7E-8F21-6A4D5B3C2E10';
    public const JWS = 'eyJhbGciOiJSUzUxMiJ9.cGF5bG9hZA.c2lnbmF0dXJl';
    public const SELLO = '2026ABCDEF0123456789';

    /** @var array<int, array{method: string, path: string, headers: array<string, string>, body: ?string}> */
    public $log = [];
    /** @var array<string, string> Idempotency-Key => body hash */
    private $keys = [];
    /** @var int */
    private $issued = 0;
    /** @var int */
    private $repairs = 0;

    /** @var string|null Error code answered by the catalog write probe; null = accept the write (wrongly). */
    public $catalogGate = 'catalog_encrypted';
    /** @var string The e-mail channel state the delivery route settles on. */
    public $mailState = 'enviado';
    /** @var string|null */
    public $mailReason = null;
    /** @var bool */
    public $managedReady = true;
    /** @var string[] */
    public $issueScopes = ['issue', 'query', 'download'];
    /** @var bool Whether the sealed answer carries `archivoDte`. */
    public $servesArchivoDte = true;
    /** @var bool Whether `GET /v1/dte` lists the issued documents. */
    public $listsDocuments = true;

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    }

    public static function archivoJson(): string
    {
        return '{"jws":"' . self::JWS . '","identificacion":{"codigoGeneracion":"' . self::CG . '"}}';
    }

    public static function archivoDte(): string
    {
        return '{"identificacion":{"codigoGeneracion":"' . self::CG . '"},"firmaElectronica":"' . self::JWS . '","selloRecibido":"' . self::SELLO . '"}';
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $parts = parse_url($url);
        $path = strtolower(substr((string) $parts['path'], strlen('/functions/v1/api-v1')));
        parse_str($parts['query'] ?? '', $query);
        $this->log[] = ['method' => $method, 'path' => $path . (isset($parts['query']) ? '?' . $parts['query'] : ''), 'headers' => $headers, 'body' => $body];

        if ($method === 'GET' && $path === '/v1/status') {
            return $this->json(200, $this->status(), ['x-sb-edge-region' => 'us-west-2']);
        }
        if ($method === 'GET' && $path === '/v1/storage/status') {
            return $this->json(200, $this->storageStatus());
        }
        if ($method === 'DELETE' && strpos($path, '/v1/customers/') === 0) {
            return $this->catalogGate === null
                ? $this->json(200, ['cliente' => ['id' => 'x', 'activo' => false]])
                : $this->error(409, $this->catalogGate);
        }
        if ($method === 'POST' && $path === '/v1/dte') {
            return $this->issue($headers, (string) $body);
        }
        if ($method === 'GET' && $path === '/v1/dte') {
            return $this->json(200, ['documentos' => $this->listsDocuments && $this->issued > 0 ? [['codigoGeneracion' => strtolower(self::CG), 'estado' => 'sellado']] : [], 'siguiente' => null]);
        }
        if ($method === 'GET' && $path === '/v1/dte/' . strtolower(self::CG)) {
            return $this->json(200, ['estado' => 'sellado', 'codigoGeneracion' => strtolower(self::CG), 'ambiente' => '00', 'tipoDte' => '01']);
        }
        if ($method === 'GET' && $path === '/v1/dte/' . strtolower(self::CG) . '/file') {
            return $this->file($query);
        }
        if ($method === 'GET' && $path === '/v1/storage/copies') {
            return $this->json(200, ['capabilityVersion' => 1, 'copies' => [$this->copy('json', self::archivoJson()), $this->copy('pdf', self::pdf())]]);
        }
        if ($method === 'POST' && $path === '/v1/storage/copies/' . strtolower(self::CG) . '/repair') {
            $this->repairs++;
            return $this->json(200, ['storage' => $this->receipt()]);
        }
        if ($method === 'POST' && $path === '/v1/dte/' . strtolower(self::CG) . '/entrega/correo') {
            return $this->json(200, ['canal' => 'correo', 'estado' => 'en_proceso'], [], 202);
        }
        if ($method === 'GET' && $path === '/v1/dte/' . strtolower(self::CG) . '/entrega') {
            $channel = ['estado' => $this->mailState, 'destino' => 'q•••@example.com'];
            if ($this->mailReason !== null) {
                $channel['motivo'] = $this->mailReason;
            }
            return $this->json(200, ['canales' => ['correo' => $channel]]);
        }
        return $this->error(404, 'not_found');
    }

    /** @return array<string, mixed> */
    private function status(): array
    {
        $ready = ['status' => 'ready', 'desiredRevision' => 1, 'publishedRevision' => 1];
        return [
            'ok' => true, 'version' => '1', 'ambiente' => '00', 'region' => 'us-west-2',
            'emisor' => ['nit' => '06140101001010', 'nombre' => 'Staging SA', 'ambiente' => '00'],
            'llave' => [
                'keyId' => 'k', 'modo' => 'custodian', 'venceEl' => null, 'alcances' => $this->issueScopes,
                'tiposDte' => ['01', '03', '05', '06', '11', '14'], 'catalogMode' => 'encrypted',
            ],
            'firma' => ['vaultDeFirma' => true, 'origenDeLaFirma' => 'vault', 'cabecera' => 'X-Facta-Sign-Key'],
            'sincronizacion' => ['sign' => $ready, 'destinations' => $ready, 'catalog' => $ready],
            'limites' => ['hora' => ['limit' => 100, 'used' => 0, 'remaining' => 100], 'dia' => ['limit' => 1000, 'used' => 0, 'remaining' => 1000], 'estado' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function storageStatus(): array
    {
        return [
            'capabilityVersion' => 1,
            'managed' => [
                'configured' => $this->managedReady, 'ready' => $this->managedReady, 'state' => $this->managedReady ? 'ready' : 'not_configured',
                'integration' => $this->managedReady ? 'ready' : 'unavailable',
                'quotaBytes' => $this->managedReady ? 1000000 : null, 'usedBytes' => 10, 'reservedBytes' => 0,
                'usedBytesTotal' => 10, 'reservedBytesTotal' => 0, 'coveredUntil' => null, 'accessUntil' => null,
                'bucketState' => $this->managedReady ? 'ready' : null, 'backupState' => null,
            ],
            'byos' => ['ready' => false],
            // What staging and production answer since FactaDTE 9d7446e0.
            'supportedKinds' => ['json', 'pdf', 'invalidation'],
            'unsupportedKinds' => ['ticket'],
        ];
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        $artifact = static function (string $bytes): array {
            return ['state' => 'stored', 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'storedAt' => '2026-10-20T10:00:00Z', 'errorCode' => null, 'retryable' => false];
        };
        return [
            'destination' => 'managed', 'environment' => '00', 'operationId' => strtolower(self::CG),
            'json' => $artifact(self::archivoJson()), 'pdf' => $artifact(self::pdf()),
        ];
    }

    /** @return array<string, mixed> */
    private function copy(string $kind, string $bytes): array
    {
        return [
            'generationCode' => strtolower(self::CG), 'kind' => $kind, 'environment' => '00', 'state' => 'stored',
            'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'issuedDate' => '2026-10-20', 'storedAt' => '2026-10-20T10:00:00Z',
        ];
    }

    /** @param array<string, string> $headers */
    private function issue(array $headers, string $body): Response
    {
        $key = $headers['Idempotency-Key'] ?? '';
        if ($key === '') {
            return $this->error(400, 'idempotency_key_required');
        }
        $hash = $headers['X-Facta-Request-Fingerprint'] ?? hash('sha256', $body);
        if (isset($this->keys[$key]) && $this->keys[$key] !== $hash) {
            return $this->error(422, 'idempotency_key_reuse');
        }
        $this->keys[$key] = $hash;
        if (!in_array('issue', $this->issueScopes, true)) {
            return $this->error(403, 'forbidden_scope');
        }
        $this->issued++;
        $sent = json_decode($body, true);
        $answer = [
            'estado' => 'sellado', 'codigoGeneracion' => strtolower(self::CG), 'numeroControl' => 'DTE-01-M001P001-000000000000001',
            'tipoDte' => is_array($sent) && is_string($sent['tipoDte'] ?? null) ? $sent['tipoDte'] : '01', 'ambiente' => '00', 'totales' => ['totalPagar' => 0.01],
            'jws' => self::JWS, 'selloRecibido' => self::SELLO,
            'archivoJson' => self::archivoJson(), 'representacionGrafica' => base64_encode(self::pdf()),
            'storage' => $this->receipt(),
        ];
        if ($this->servesArchivoDte) {
            $answer['archivoDte'] = self::archivoDte();
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['entrega']['correo'])) {
            $answer['entrega'] = ['token' => 'fdt_0123456789abcdef', 'venceEn' => '2026-10-20T10:05:00Z', 'canales' => ['correo' => ['estado' => 'pendiente']]];
        }
        return $this->json(200, $answer);
    }

    /** @param array<string, mixed> $query */
    private function file(array $query): Response
    {
        $kind = $query['kind'] ?? 'json';
        if ($kind === 'pdf' || $kind === 'ticket') {
            return new Response(200, ['content-type' => 'application/pdf'] + (($query['source'] ?? '') === 'managed' ? ['x-facta-storage-source' => 'managed'] : []), self::pdf());
        }
        $raw = ($query['raw'] ?? '') === 'true';
        return new Response(200, [
            'content-type' => 'application/json',
            'x-facta-json-format' => $raw ? 'raw' : 'archivo-dte',
        ] + (($query['source'] ?? '') === 'managed' ? ['x-facta-storage-source' => 'managed'] : []), $raw ? self::archivoJson() : self::archivoDte());
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function json(int $status, array $body, array $headers = [], ?int $override = null): Response
    {
        return new Response($override ?? $status, $headers + ['content-type' => 'application/json'], (string) json_encode($body));
    }

    private function error(int $status, string $code): Response
    {
        return new Response($status, ['content-type' => 'application/json'], '{"error":{"code":"' . $code . '","message":"fake"}}');
    }

    public function repairs(): int
    {
        return $this->repairs;
    }
}
