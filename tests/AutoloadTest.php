<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use PHPUnit\Framework\TestCase;

/** autoload.php must work in a fresh PHP process that has never heard of Composer. */
final class AutoloadTest extends TestCase
{
    private function runPhp(string $code): string
    {
        $root = dirname(__DIR__);
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=1 -r '
            . escapeshellarg('require ' . var_export($root . '/autoload.php', true) . ';' . $code) . ' 2>&1';
        $output = [];
        exec($command, $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        return implode("\n", $output);
    }

    public function testClassesLoadWithoutComposer(): void
    {
        $out = $this->runPhp('
            echo class_exists("Composer\\\\Autoload\\\\ClassLoader", false) ? "composer" : "plain", "|";
            $f = new Facta\\Api\\Facta(["apiKey" => "facta_test_k"]);
            echo get_class($f), "|", $f->environment(), "|";
            echo Facta\\Api\\Json\\Fingerprint::v1("{\"b\":1,\"a\":10.0}"), "|";
            echo get_class(Facta\\Api\\Http\\TransportFactory::create());
        ');
        $this->assertStringStartsWith('plain|Facta\\Api\\Facta|00|' . hash('sha256', '{"a":10,"b":1}') . '|Facta\\Api\\Http\\', $out);
    }

    public function testUnrelatedClassesAreIgnored(): void
    {
        $out = $this->runPhp('echo class_exists("Other\\\\Thing") ? "yes" : "no", "|", class_exists("Facta\\\\Api\\\\Nope") ? "yes" : "no";');
        $this->assertSame('no|no', $out);
    }

    public function testPsr18TransportLoadsWithoutPsrPackagesAndFailsClearlyWhenUsed(): void
    {
        $out = $this->runPhp('
            echo interface_exists("Psr\\\\Http\\\\Client\\\\ClientInterface", false) ? "psr" : "nopsr", "|";
            try { new Facta\\Api\\Http\\Psr18Transport(new stdClass(), new stdClass(), new stdClass()); echo "constructed"; }
            catch (Facta\\Api\\UnsupportedEnvironmentException $e) { echo "unsupported:", $e->getMessage(); }
        ');
        $this->assertStringStartsWith('nopsr|unsupported:The PSR-18 transport needs psr/http-client', $out);
    }
}
