<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Facta;
use Facta\Api\FactaError;
use Facta\Api\Http\TransportException;
use Facta\Api\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class FactaTest extends TestCase
{
    private const KEY = 'facta_test_AbC123';

    /** @var int[] */
    private $sleeps = [];

    /**
     * @param array<string, mixed> $extra
     */
    private function client(FakeTransport $transport, array $extra = []): Facta
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

    public function testStatusDecodesTheAnswerAndSendsTheKey(): void
    {
        $fake = new FakeTransport([FakeTransport::json(200, '{"ok":true,"version":"1","ambiente":"00","emisor":null,"llave":{"alcances":[]}}')]);
        $status = $this->client($fake)->status();

        $this->assertTrue($status->ok);
        $this->assertSame('00', $status->ambiente);
        $this->assertInstanceOf(\stdClass::class, $status->llave);
        $sent = $fake->requests[0];
        $this->assertSame('GET', $sent['method']);
        $this->assertSame(Facta::DEFAULT_BASE_URL . '/v1/status', $sent['url']);
        $this->assertSame(self::KEY, $sent['headers']['X-Facta-Key']);
        $this->assertSame('us-west-2', $sent['headers']['x-region']);
        $this->assertArrayNotHasKey('Idempotency-Key', $sent['headers']);
        $this->assertArrayNotHasKey('Content-Type', $sent['headers']);
        $this->assertNull($sent['body']);
        $this->assertSame(60000, $sent['timeoutMs']);
        $this->assertStringStartsWith('facta-api-sdk-php/', $sent['headers']['User-Agent']);
    }

    public function testDebugMemberIsNeverHandedToTheCaller(): void
    {
        $fake = new FakeTransport([FakeTransport::json(200, '{"ok":true,"debug":{"x":1}}')]);
        $this->assertFalse(property_exists($this->client($fake)->status(), 'debug'));
    }

    public function testBaseUrlOverrideAndRegionOptions(): void
    {
        $fake = new FakeTransport([FakeTransport::json(200, '{}'), FakeTransport::json(200, '{}')]);
        $this->client($fake, ['baseUrl' => 'http://127.0.0.1:9/api/', 'region' => false])->status();
        $this->assertSame('http://127.0.0.1:9/api/v1/status', $fake->requests[0]['url']);
        $this->assertArrayNotHasKey('x-region', $fake->requests[0]['headers']);

        $this->client($fake, ['region' => 'eu-central-1'])->status();
        $this->assertSame('eu-central-1', $fake->requests[1]['headers']['x-region']);
    }

    public function testErrorCarriesCodeStatusAndDetails(): void
    {
        $fake = new FakeTransport([FakeTransport::json(
            422,
            '{"error":{"code":"mh_rejected","message":"Hacienda refused","details":{"codigoGeneracion":"AB","numeroControl":"DTE-01-X","observaciones":["a","b"]}}}'
        )]);
        try {
            $this->client($fake)->status();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::MH_REJECTED, $e->getErrorCode());
            $this->assertSame(422, $e->getStatus());
            $this->assertTrue($e->isRejection());
            $this->assertSame(['codigoGeneracion' => 'AB', 'numeroControl' => 'DTE-01-X'], $e->spent());
            $this->assertSame(['a', 'b'], $e->mhObservations());
            $this->assertCount(1, $fake->requests, 'a rejection is final');
        }
    }

    public function testSecretsAreRedactedFromMessageAndDetails(): void
    {
        $fake = new FakeTransport([FakeTransport::json(
            400,
            '{"error":{"code":"invalid_request","message":"bad key ' . self::KEY . ' and factask_SECRET","details":{"echo":"' . self::KEY . '","apiKey":"x","nested":{"signKey":"y","keep":"ok"}}}}'
        )]);
        $client = $this->client($fake, ['signKey' => 'factask_SECRET', 'unlockKey' => 'factauk_UNLOCK']);
        try {
            $client->status();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString('factask_SECRET', $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
            $details = $e->getDetails();
            $this->assertInstanceOf(\stdClass::class, $details);
            $this->assertSame('[REDACTED]', $details->echo);
            $this->assertFalse(property_exists($details, 'apiKey'));
            $this->assertFalse(property_exists($details->nested, 'signKey'));
            $this->assertSame('ok', $details->nested->keep);
        }
    }

    public function testRetriesInFlightAndHonoursRetryAfter(): void
    {
        $inFlight = '{"error":{"code":"idempotency_in_flight","message":"wait"}}';
        $fake = new FakeTransport([
            FakeTransport::json(409, $inFlight, ['retry-after' => '2']),
            FakeTransport::json(409, $inFlight),
            FakeTransport::json(200, '{"ok":true}'),
        ]);
        $status = $this->client($fake)->status();
        $this->assertTrue($status->ok);
        $this->assertCount(3, $fake->requests);
        $this->assertSame([2000, 500], $this->sleeps, 'Retry-After first, then exponential backoff (250 * 2^(n-1)).');
    }

    public function testRetryAfterIsClampedAndHttpDatesAreUnderstood(): void
    {
        $unreachable = '{"error":{"code":"mh_unreachable","message":"down"}}';
        $fake = new FakeTransport([
            FakeTransport::json(502, $unreachable, ['retry-after' => '99999']),
            FakeTransport::json(502, $unreachable, ['retry-after' => gmdate('D, d M Y H:i:s', time() + 3) . ' GMT']),
            FakeTransport::json(200, '{}'),
        ]);
        $this->client($fake)->status();
        $this->assertSame(60000, $this->sleeps[0]);
        $this->assertGreaterThanOrEqual(1000, $this->sleeps[1]);
        $this->assertLessThanOrEqual(4000, $this->sleeps[1]);
    }

    public function testGivesUpAfterMaxRetriesWithTheLastError(): void
    {
        $unreachable = '{"error":{"code":"mh_unreachable","message":"down"}}';
        $fake = new FakeTransport(array_fill(0, 3, FakeTransport::json(502, $unreachable)));
        try {
            $this->client($fake, ['maxRetries' => 2])->status();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::MH_UNREACHABLE, $e->getErrorCode());
            $this->assertCount(3, $fake->requests);
            $this->assertSame([250, 500], $this->sleeps);
        }
    }

    /**
     * @dataProvider finalErrors
     */
    public function testOtherErrorsAreNeverRetried(int $status, string $code): void
    {
        $fake = new FakeTransport([FakeTransport::json($status, '{"error":{"code":"' . $code . '","message":"m"}}')]);
        try {
            $this->client($fake)->status();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame($code, $e->getErrorCode());
            $this->assertCount(1, $fake->requests);
            $this->assertSame([], $this->sleeps);
        }
    }

    /** @return array<string, array{int, string}> */
    public function finalErrors(): array
    {
        return [
            'rate limited' => [429, 'rate_limited'],
            'unauthorized' => [401, 'invalid_api_key'],
            'service unavailable' => [503, 'service_unavailable'],
            'internal' => [500, 'internal_error'],
            'idempotency reuse' => [422, 'idempotency_key_reuse'],
        ];
    }

    public function testNetworkFailureIsNotRetriedAndIsNamedByKind(): void
    {
        foreach ([
            TransportException::KIND_TIMEOUT => 'did not answer within',
            TransportException::KIND_TLS => 'caBundle',
            TransportException::KIND_CONNECT => 'Could not reach',
        ] as $kind => $fragment) {
            $fake = new FakeTransport([FakeTransport::failure($kind)]);
            try {
                $this->client($fake, ['timeoutMs' => 1234])->status();
                $this->fail('FactaError expected');
            } catch (FactaError $e) {
                $this->assertSame(FactaError::NETWORK_ERROR, $e->getErrorCode());
                $this->assertSame(0, $e->getStatus());
                $this->assertStringContainsString($fragment, $e->getMessage());
                $this->assertCount(1, $fake->requests);
            }
        }
    }

    public function testTimeoutOptionReachesTheTransport(): void
    {
        $fake = new FakeTransport([FakeTransport::json(200, '{}'), FakeTransport::json(200, '{}')]);
        $client = $this->client($fake, ['timeoutMs' => 1500]);
        $client->status();
        $client->status(['timeoutMs' => 99]);
        $this->assertSame(1500, $fake->requests[0]['timeoutMs']);
        $this->assertSame(99, $fake->requests[1]['timeoutMs']);
    }

    public function testInvalidJsonAnswerIsAnInternalError(): void
    {
        $fake = new FakeTransport([FakeTransport::json(200, '<html>oops</html>')]);
        try {
            $this->client($fake)->status();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::INTERNAL_ERROR, $e->getErrorCode());
            $this->assertSame(200, $e->getStatus());
        }
    }

    public function testErrorWithoutBodyFallsBackToTheHttpStatus(): void
    {
        $fake = new FakeTransport([FakeTransport::json(500, '')]);
        try {
            $this->client($fake)->status();
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::INTERNAL_ERROR, $e->getErrorCode());
            $this->assertSame('HTTP 500', $e->getMessage());
        }
    }

    public function testPostMintsOneIdempotencyKeyAndResendsTheSameBytes(): void
    {
        $inFlight = '{"error":{"code":"idempotency_in_flight","message":"wait"}}';
        $fake = new FakeTransport([FakeTransport::json(409, $inFlight), FakeTransport::json(202, '{"estado":"x"}')]);
        $client = $this->client($fake, ['signKey' => 'factask_SECRET']);
        $client->request('POST', '/v1/dte', ['b' => 1.5, 'a' => 'ñ/x', 'e' => new \stdClass(), 'l' => []]);

        $first = $fake->requests[0];
        $second = $fake->requests[1];
        $this->assertSame('{"b":1.5,"a":"ñ/x","e":{},"l":[]}', $first['body']);
        $this->assertSame($first['body'], $second['body']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['headers']['Idempotency-Key']);
        $this->assertSame($first['headers']['Idempotency-Key'], $second['headers']['Idempotency-Key']);
        $this->assertSame('application/json', $first['headers']['Content-Type']);
    }

    public function testCallerSuppliedIdempotencyKeyAndExtraHeadersAreUsed(): void
    {
        $fake = new FakeTransport([FakeTransport::json(200, '{}')]);
        $this->client($fake)->request('POST', '/v1/dte/prepare', ['a' => 1], [
            'idempotencyKey' => 'order-42',
            'headers' => ['X-Facta-Request-Fingerprint' => str_repeat('a', 64)],
        ]);
        $this->assertSame('order-42', $fake->requests[0]['headers']['Idempotency-Key']);
        $this->assertSame(str_repeat('a', 64), $fake->requests[0]['headers']['X-Facta-Request-Fingerprint']);
    }

    public function testSignKeyTravelsOnlyOnTheRoutesThatSign(): void
    {
        $fake = new FakeTransport(array_fill(0, 7, FakeTransport::json(200, '{}')));
        $client = $this->client($fake, ['signKey' => 'factask_SECRET']);
        $client->request('POST', '/v1/dte', ['a' => 1]);
        $client->request('POST', '/v1/dte/sign', ['a' => 1]);
        $client->request('POST', '/v1/dte/ABC-123/invalidate', ['a' => 1]);
        $client->request('POST', '/v1/dte/ABC-123/return', ['a' => 1]);
        $client->request('POST', '/v1/dte/prepare', ['a' => 1]);
        $client->request('GET', '/v1/dte');
        $client->status();

        $has = static function (array $request): bool {
            return isset($request['headers']['X-Facta-Sign-Key']);
        };
        $this->assertSame([true, true, true, true, false, false, false], array_map($has, $fake->requests));
    }

    public function testKeysAreValidatedAtConstruction(): void
    {
        $cases = [
            [['apiKey' => ''], FactaError::UNAUTHORIZED],
            [[], FactaError::UNAUTHORIZED],
            [['apiKey' => 'sk_live_nope'], FactaError::INVALID_API_KEY],
            [['apiKey' => self::KEY, 'signKey' => 'factauk_wrongkey'], FactaError::UNAUTHORIZED],
            [['apiKey' => self::KEY, 'unlockKey' => 'factask_wrongkey'], FactaError::UNAUTHORIZED],
        ];
        foreach ($cases as $case) {
            try {
                new Facta($case[0]);
                $this->fail('FactaError expected');
            } catch (FactaError $e) {
                $this->assertSame($case[1], $e->getErrorCode());
                $this->assertSame(0, $e->getStatus());
                $this->assertStringNotContainsString('factauk_wrongkey', $e->getMessage());
            }
        }
        $this->assertSame('00', (new Facta(['apiKey' => 'facta_test_x', 'transport' => new FakeTransport()]))->environment());
        $this->assertSame('01', (new Facta(['apiKey' => 'facta_live_x', 'transport' => new FakeTransport()]))->environment());
    }

    /**
     * @dataProvider badOptions
     * @param array<string, mixed> $options
     */
    public function testMalformedOptionsAreRejected(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Facta($options + ['apiKey' => self::KEY, 'transport' => new FakeTransport()]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function badOptions(): array
    {
        return [
            'unknown option' => [['nope' => 1]],
            'relative base url' => [['baseUrl' => '/v1']],
            'ftp base url' => [['baseUrl' => 'ftp://example.com']],
            'credentials in base url' => [['baseUrl' => 'https://u:p@example.com']],
            'query in base url' => [['baseUrl' => 'https://example.com/?a=1']],
            'zero timeout' => [['timeoutMs' => 0]],
            'float timeout' => [['timeoutMs' => 1.5]],
            'negative retries' => [['maxRetries' => -1]],
            'bad region' => [['region' => 'mars']],
            'missing ca bundle' => [['caBundle' => '/nonexistent/ca.pem']],
            'bad transport' => [['transport' => new \stdClass()]],
            'bad sleeper' => [['sleeper' => 'no_such_function_xyz']],
        ];
    }

    public function testRegionFromEnvironmentVariable(): void
    {
        putenv('FACTA_API_REGION=eu-west-1');
        try {
            $fake = new FakeTransport([FakeTransport::json(200, '{}')]);
            $this->client($fake)->status();
            $this->assertSame('eu-west-1', $fake->requests[0]['headers']['x-region']);
        } finally {
            putenv('FACTA_API_REGION');
        }
    }
}
