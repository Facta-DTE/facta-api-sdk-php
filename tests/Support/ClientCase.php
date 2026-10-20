<?php

declare(strict_types=1);

namespace Facta\Api\Tests\Support;

use Facta\Api\Facta;
use PHPUnit\Framework\TestCase;

/** Shared helpers for the tests that script the API with a FakeTransport. */
abstract class ClientCase extends TestCase
{
    protected const KEY = 'facta_test_AbC123';
    protected const SIGN_KEY = 'factask_SECRETSIGN99';
    protected const CG = '8F6A3B2C-1D4E-4F50-9A7B-0C1D2E3F4A5B';
    protected const BASE = Facta::DEFAULT_BASE_URL;

    /** @var int[] */
    protected $sleeps = [];

    /**
     * @param array<string, mixed> $extra
     */
    protected function client(FakeTransport $transport, array $extra = []): Facta
    {
        $this->sleeps = [];
        return new Facta($extra + [
            'apiKey' => self::KEY,
            'transport' => $transport,
            'sleeper' => function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        ]);
    }

    /** @param array<string, mixed>|string $body */
    protected static function ok($body, int $status = 200): \Facta\Api\Http\Response
    {
        return FakeTransport::json($status, is_string($body) ? $body : (string) json_encode($body));
    }

    protected static function error(int $status, string $code, string $message = 'm', string $details = 'null'): \Facta\Api\Http\Response
    {
        return FakeTransport::json($status, '{"error":{"code":"' . $code . '","message":"' . $message . '","details":' . $details . '}}');
    }

    /** A `/v1/status` answer for a key whose catalog is in the given mode. */
    protected static function statusWithCatalog(?string $mode, bool $plain = false): \Facta\Api\Http\Response
    {
        $llave = ['alcances' => ['issue', 'query', 'download']];
        if ($mode !== null) {
            $llave['catalogMode'] = $mode;
        }
        if ($plain) {
            $llave['catalogoSinCifrar'] = true;
        }
        return self::ok(['ok' => true, 'ambiente' => '00', 'llave' => $llave]);
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: ?string, timeoutMs: int}
     */
    protected static function request(FakeTransport $fake, int $index = 0): array
    {
        return $fake->requests[$index];
    }

    protected static function path(FakeTransport $fake, int $index = 0): string
    {
        return substr($fake->requests[$index]['url'], strlen(self::BASE));
    }

    /** @return array<string, mixed> */
    protected static function bodyOf(FakeTransport $fake, int $index = 0): array
    {
        $decoded = json_decode((string) $fake->requests[$index]['body'], true);
        return is_array($decoded) ? $decoded : [];
    }
}
