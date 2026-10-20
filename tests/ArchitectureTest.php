<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The SDK never signs and never computes tax: the server does both. This guard
 * fails when client code grows vocabulary that belongs on the server side
 * (see CLAUDE.md and the plan, section 4).
 */
final class ArchitectureTest extends TestCase
{
    private const FORBIDDEN = [
        // signing primitives
        'openssl_sign', 'openssl_private', 'openssl_pkey', 'openssl_x509', 'RS512', 'RS256', 'privateKey', 'jws',
        'sodium_crypto_sign', 'phpseclib',
        // fiscal vocabulary: building these is the server's job
        'ventaGravada', 'ventaExenta', 'ventaNoSuj', 'totalIva', 'totalGravada', 'montoTotalOperacion', 'ivaPerci',
    ];

    /** @return string[] */
    private static function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    public function testSourceTreeIsNotEmpty(): void
    {
        $this->assertGreaterThan(8, count(self::sourceFiles()));
    }

    public function testClientCodeHasNoSigningOrTaxVocabulary(): void
    {
        foreach (self::sourceFiles() as $file) {
            $code = (string) file_get_contents($file);
            foreach (self::FORBIDDEN as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $code, basename($file) . ' mentions "' . $word . '"');
            }
        }
    }

    public function testNumeroControlIsNeverBuiltHere(): void
    {
        foreach (self::sourceFiles() as $file) {
            $code = (string) file_get_contents($file);
            // Reading the field off an answer is fine (FactaError::spent); assembling one is not.
            $this->assertDoesNotMatchRegularExpression('/numeroControl[^\n]*(?:\.\s*|sprintf|str_pad|implode)/i', preg_replace('/\/\*.*?\*\//s', '', $code) ?? $code, basename($file));
        }
    }

    public function testNoRequiredExtensionBeyondCore(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        $this->assertSame(['php' => '>=7.4'], $composer['require']);
        foreach (self::sourceFiles() as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(mb_[a-z_]+|iconv_[a-z_]+|bc(?:add|sub|mul|div|comp|pow)|grapheme_[a-z_]+|sodium_[a-z_]+)\(/', (string) file_get_contents($file), basename($file));
        }
    }

    public function testNoPhp8OnlySyntaxInSource(): void
    {
        $patterns = [
            '/\bmatch\s*\(/' => 'match expression',
            '/\?->/' => 'nullsafe operator',
            '/\benum\s+\w+/' => 'enum',
            '/\breadonly\b(?!\s*[\'"])/' => 'readonly',
            '/\bnever\b\s*\{/' => 'never type',
            '/function\s+__construct\s*\([^)]*\b(?:public|private|protected)\s+[\\\\\w?]+\s+\$/s' => 'constructor promotion',
            '/(?:function\s+\w+\s*\([^)]*\)|\bfn\s*\([^)]*\))\s*:\s*\??[\\\\\w]+\|/' => 'union return type',
        ];
        foreach (self::sourceFiles() as $file) {
            $code = (string) file_get_contents($file);
            // Strip comments and strings crudely so prose does not trigger the guard.
            $code = preg_replace('#/\*.*?\*/|//[^\n]*|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"#s', '', $code) ?? $code;
            foreach ($patterns as $regex => $what) {
                $this->assertDoesNotMatchRegularExpression($regex, $code, basename($file) . ' uses ' . $what);
            }
        }
    }
}
