<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use PHPUnit\Framework\TestCase;

final class ZipBuildTest extends TestCase
{
    public function testArchiveBuildsAndWorksWithoutComposer(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not available.');
        }
        $root = dirname(__DIR__);
        $php = escapeshellarg(PHP_BINARY);

        $output = [];
        exec($php . ' ' . escapeshellarg($root . '/scripts/build-zip.php') . ' 0.0.0-test 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $archive = $root . '/dist/facta-api-php-v0.0.0-test.zip';
        $this->assertFileExists($archive);

        try {
            $smoke = [];
            exec($php . ' ' . escapeshellarg($root . '/scripts/zip-smoke.php') . ' ' . escapeshellarg($archive) . ' 2>&1', $smoke, $status);
            $this->assertSame(0, $status, implode("\n", $smoke));
            $this->assertStringContainsString('zip smoke ok', implode("\n", $smoke));

            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($archive));
            $this->assertNotFalse($zip->locateName('facta-api-php/src/Facta.php'));
            $this->assertNotFalse($zip->locateName('facta-api-php/autoload.php'));
            $this->assertFalse($zip->locateName('facta-api-php/tests/FactaTest.php'));
            $zip->close();
        } finally {
            @unlink($archive);
        }
    }
}
