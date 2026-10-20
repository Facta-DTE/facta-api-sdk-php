<?php

declare(strict_types=1);

/**
 * Live integration of the PHP SDK against the STAGING API (environment 00).
 *
 *     php scripts/live-integration.php
 *
 * Run by .github/workflows/sdk-live-integration.yml with the secrets of the
 * `staging-live-test` environment; see the README. It issues real test documents
 * ($0.01 each), so every guard runs before the first request and any missing
 * input fails the run: it never passes silently.
 *
 * Only the SDK's public API is used, loaded through autoload.php like an install
 * without Composer. The helpers live in scripts/live/.
 */

require_once __DIR__ . '/../autoload.php';
foreach (['LiveError', 'Guards', 'Report', 'EmailDelivery', 'Fixtures', 'Run'] as $file) {
    require_once __DIR__ . '/live/' . $file . '.php';
}

use Facta\Api\Facta;
use Facta\Api\Live\Guards;
use Facta\Api\Live\LiveError;
use Facta\Api\Live\Run;

$env = Guards::fromEnvironment();
try {
    // Fail closed before constructing the client or making any API request.
    $settings = Guards::validate($env);
} catch (LiveError $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$facta = new Facta([
    'apiKey' => $settings['apiKey'],
    'signKey' => $settings['signKey'],
    'unlockKey' => $settings['unlockKey'],
    'baseUrl' => $settings['baseUrl'],
    'timeoutMs' => 90000,
    'maxRetries' => 1,
]);

$run = new Run(
    $facta,
    ['runId' => $settings['runId'], 'mode' => $settings['mode'], 'deliveryInbox' => $settings['deliveryInbox']],
    $env['STAGING_FACTA_DTE_FIXTURES_JSON'] ?? null,
    static function (string $line): void {
        fwrite(STDOUT, $line . "\n");
    },
    static function (string $line): void {
        fwrite(STDERR, $line . "\n");
    },
    static function (int $ms): void {
        usleep($ms * 1000);
    }
);

$passed = $run->execute();
$summary = getenv('GITHUB_STEP_SUMMARY');
$run->saveReport($settings['reportDir'], is_string($summary) ? $summary : null);
exit($passed ? 0 : 1);
