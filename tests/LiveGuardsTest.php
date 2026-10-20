<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Live\Guards;
use Facta\Api\Live\LiveError;
use PHPUnit\Framework\TestCase;

final class LiveGuardsTest extends TestCase
{
    private const NOW = 1792000000; // 2026-10-14

    /** @return array<string, string> */
    private static function env(): array
    {
        return [
            'STAGING_FACTA_API_KEY' => 'facta_test_AbC123',
            'STAGING_FACTA_SIGN_KEY' => 'factask_Sign',
            'STAGING_FACTA_UNLOCK_KEY' => 'factauk_Unlock',
            'STAGING_FACTA_API_BASE_URL' => Guards::EXPECTED_STAGING_API_BASE_URL,
            'GITHUB_RUN_ID' => '12345',
            'GITHUB_RUN_CREATED_AT' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 3600),
            'FACTA_LIVE_REPORT_DIR' => '/tmp/facta-live-report',
        ];
    }

    /** @param array<string, string> $env */
    private function refused(array $env, string $code, string $message): void
    {
        try {
            Guards::validate($env, self::NOW);
            $this->fail('LiveError expected');
        } catch (LiveError $e) {
            $this->assertSame($code, $e->getFailureCode());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testAValidEnvironmentPasses(): void
    {
        $settings = Guards::validate(self::env(), self::NOW);
        $this->assertSame('emit-test-fe', $settings['mode']);
        $this->assertNull($settings['deliveryInbox']);
        $this->assertSame('12345', $settings['runId']);
        $this->assertSame(Guards::EXPECTED_STAGING_API_BASE_URL, $settings['baseUrl']);
    }

    /** @return array<string, array{string}> */
    public function requiredInputs(): array
    {
        $out = [];
        foreach (array_keys(self::env()) as $name) {
            $out[$name] = [$name];
        }
        return $out;
    }

    /** @dataProvider requiredInputs */
    public function testAMissingOrEmptyInputFailsByName(string $name): void
    {
        $env = self::env();
        unset($env[$name]);
        $this->refused($env, 'input_missing', 'Required integration input is unavailable: ' . $name);
        $env[$name] = '';
        $this->refused($env, 'input_missing', 'Required integration input is unavailable: ' . $name);
    }

    public function testTheOptionalFixturesAndInboxDoNotHaveToExist(): void
    {
        $settings = Guards::validate(self::env() + ['STAGING_FACTA_DELIVERY_EMAIL' => ' qa@example.com '], self::NOW);
        $this->assertSame('qa@example.com', $settings['deliveryInbox']);
    }

    public function testOnlyATestKeyIsAccepted(): void
    {
        $this->refused(['STAGING_FACTA_API_KEY' => 'facta_live_AbC123'] + self::env(), 'input_invalid', 'not a test key');
    }

    public function testTheOtherCredentialsMustBeOfTheRightKind(): void
    {
        $this->refused(['STAGING_FACTA_SIGN_KEY' => 'factauk_Unlock'] + self::env(), 'input_invalid', 'signing credential');
        $this->refused(['STAGING_FACTA_UNLOCK_KEY' => 'factask_Sign'] + self::env(), 'input_invalid', 'unlock credential');
    }

    public function testOnlyTheExactStagingUrlIsAccepted(): void
    {
        foreach ([
            'https://hcnvknpsbadplnfcflxx.supabase.co/functions/v1/api-v1',
            Guards::EXPECTED_STAGING_API_BASE_URL . '/',
            'http://eobxzotnqzgtpuqvmpkc.supabase.co/functions/v1/api-v1',
            'https://eobxzotnqzgtpuqvmpkc.supabase.co.evil.example/functions/v1/api-v1',
        ] as $url) {
            $this->refused(['STAGING_FACTA_API_BASE_URL' => $url] + self::env(), 'input_invalid', 'approved staging project');
        }
    }

    public function testModes(): void
    {
        foreach (['emit-test-fe', 'emit-enabled-dte-fixtures'] as $mode) {
            $this->assertSame($mode, Guards::validate(['FACTA_LIVE_MODE' => $mode] + self::env(), self::NOW)['mode']);
        }
        $this->refused(['FACTA_LIVE_MODE' => 'skip'] + self::env(), 'input_invalid', 'unknown live-test mode');
    }

    public function testTheDeliveryInboxMustLookLikeAnAddress(): void
    {
        $this->refused(['STAGING_FACTA_DELIVERY_EMAIL' => 'not-an-address'] + self::env(), 'delivery_inbox_invalid', 'delivery inbox');
    }

    public function testARunOlderThanTheIdempotencyWindowIsRefused(): void
    {
        $old = ['GITHUB_RUN_CREATED_AT' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 22 * 3600)] + self::env();
        $this->refused($old, 'idempotency_window_expired', 'too old');
        $almost = ['GITHUB_RUN_CREATED_AT' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 22 * 3600 + 60)] + self::env();
        $this->assertSame('12345', Guards::validate($almost, self::NOW)['runId']);
        $future = ['GITHUB_RUN_CREATED_AT' => gmdate('Y-m-d\TH:i:s\Z', self::NOW + 600)] + self::env();
        $this->refused($future, 'idempotency_window_expired', 'too old');
        $garbage = ['GITHUB_RUN_CREATED_AT' => 'yesterday-ish'] + self::env();
        $this->refused($garbage, 'idempotency_window_expired', 'too old');
    }

    public function testGuardsRunBeforeAnythingElseSoNoRequestCanFollowAFailure(): void
    {
        // The entry script validates first and exits before building the client.
        $script = (string) file_get_contents(dirname(__DIR__) . '/scripts/live-integration.php');
        $this->assertLessThan(strpos($script, 'new Facta('), strpos($script, 'Guards::validate'));
    }
}
