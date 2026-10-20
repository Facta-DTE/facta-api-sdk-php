<?php

declare(strict_types=1);

namespace Facta\Api\Live;

/**
 * Fail-closed checks that run before the client is built and before any request
 * is made. Mirrors the top of `scripts/live-integration.mjs` of the TypeScript SDK.
 */
final class Guards
{
    public const EXPECTED_STAGING_API_BASE_URL = 'https://eobxzotnqzgtpuqvmpkc.supabase.co/functions/v1/api-v1';
    public const MODES = ['emit-test-fe', 'emit-enabled-dte-fixtures'];

    /** A claim older than this may have expired on the server (24 hours), so a rerun could issue twice. */
    private const SAFE_RUN_AGE_SECONDS = 22 * 3600;

    /** Inputs that must all be present; the unlock key is read and validated but unused until tier 2. */
    private const REQUIRED = [
        'STAGING_FACTA_API_KEY',
        'STAGING_FACTA_SIGN_KEY',
        'STAGING_FACTA_UNLOCK_KEY',
        'STAGING_FACTA_API_BASE_URL',
        'GITHUB_RUN_ID',
        'GITHUB_RUN_CREATED_AT',
        'FACTA_LIVE_REPORT_DIR',
    ];

    /**
     * Validates the whole environment and returns the settings for the run.
     *
     * @param array<string, string> $env Name => value (empty or missing means unavailable).
     * @return array{apiKey: string, signKey: string, unlockKey: string, baseUrl: string, runId: string, createdAt: string,
     *               reportDir: string, mode: string, deliveryInbox: string|null}
     * @throws LiveError
     */
    public static function validate(array $env, ?int $now = null): array
    {
        foreach (self::REQUIRED as $name) {
            if (!isset($env[$name]) || $env[$name] === '') {
                throw new LiveError('input_missing', 'Required integration input is unavailable: ' . $name);
            }
        }
        $apiKey = $env['STAGING_FACTA_API_KEY'];
        $signKey = $env['STAGING_FACTA_SIGN_KEY'];
        $unlockKey = $env['STAGING_FACTA_UNLOCK_KEY'];
        $baseUrl = $env['STAGING_FACTA_API_BASE_URL'];

        if (strpos($apiKey, 'facta_test_') !== 0) {
            throw new LiveError('input_invalid', 'Refusing integration run: the API key is not a test key.');
        }
        if (strpos($signKey, 'factask_') !== 0) {
            throw new LiveError('input_invalid', 'Refusing integration run: signing credential has an unexpected type.');
        }
        if (strpos($unlockKey, 'factauk_') !== 0) {
            throw new LiveError('input_invalid', 'Refusing integration run: unlock credential has an unexpected type.');
        }
        if ($baseUrl !== self::EXPECTED_STAGING_API_BASE_URL) {
            throw new LiveError('input_invalid', 'Refusing integration run: API base URL must exactly match the approved staging project.');
        }
        $mode = isset($env['FACTA_LIVE_MODE']) && $env['FACTA_LIVE_MODE'] !== '' ? $env['FACTA_LIVE_MODE'] : 'emit-test-fe';
        if (!in_array($mode, self::MODES, true)) {
            throw new LiveError('input_invalid', 'Refusing integration run: unknown live-test mode.');
        }
        $inbox = isset($env['STAGING_FACTA_DELIVERY_EMAIL']) ? trim($env['STAGING_FACTA_DELIVERY_EMAIL']) : '';
        if ($inbox !== '' && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $inbox) !== 1) {
            throw new LiveError('delivery_inbox_invalid', 'Refusing integration run: the delivery inbox is not an e-mail address.');
        }
        self::assertWithinIdempotencyWindow($env['GITHUB_RUN_CREATED_AT'], $now);

        return [
            'apiKey' => $apiKey,
            'signKey' => $signKey,
            'unlockKey' => $unlockKey,
            'baseUrl' => $baseUrl,
            'runId' => $env['GITHUB_RUN_ID'],
            'createdAt' => $env['GITHUB_RUN_CREATED_AT'],
            'reportDir' => $env['FACTA_LIVE_REPORT_DIR'],
            'mode' => $mode,
            'deliveryInbox' => $inbox === '' ? null : $inbox,
        ];
    }

    /**
     * Blocks reruns before any API access when the server's idempotency claim may have expired.
     *
     * @throws LiveError
     */
    public static function assertWithinIdempotencyWindow(string $createdAt, ?int $now = null): void
    {
        $timestamp = strtotime($createdAt);
        $now = $now ?? time();
        if ($timestamp === false || $now < $timestamp || $now - $timestamp >= self::SAFE_RUN_AGE_SECONDS) {
            throw new LiveError('idempotency_window_expired', 'The original workflow run is too old to retry safely.');
        }
    }

    /**
     * Reads the environment the way the workflow provides it.
     *
     * @return array<string, string>
     */
    public static function fromEnvironment(): array
    {
        $names = array_merge(self::REQUIRED, ['STAGING_FACTA_DTE_FIXTURES_JSON', 'STAGING_FACTA_DELIVERY_EMAIL', 'FACTA_LIVE_MODE']);
        $env = [];
        foreach ($names as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $env[$name] = $value;
            }
        }
        return $env;
    }
}
