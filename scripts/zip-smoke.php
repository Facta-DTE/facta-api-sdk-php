<?php

declare(strict_types=1);

/**
 * Unzips a release archive into a temporary folder and runs a smoke script that
 * only does `require 'autoload.php'`. Exit code 0 means the ZIP works without Composer.
 *
 *     php scripts/zip-smoke.php dist/facta-api-php-vX.Y.Z.zip [--keep]
 *
 * `--keep` leaves the extracted folder in place and prints its path (to rerun smoke.php on another PHP).
 */

if (!class_exists('ZipArchive') || !isset($argv[1])) {
    fwrite(STDERR, "usage: php scripts/zip-smoke.php <archive.zip> (needs ext-zip)\n");
    exit(2);
}

$dir = sys_get_temp_dir() . '/facta-zip-smoke-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
$zip = new ZipArchive();
if ($zip->open($argv[1]) !== true) {
    fwrite(STDERR, "Cannot open {$argv[1]}\n");
    exit(1);
}
$zip->extractTo($dir);
$zip->close();

$package = $dir . '/facta-api-php';
foreach (['autoload.php', 'README.md', 'LICENSE', 'CHANGELOG.md', 'src/Facta.php'] as $required) {
    if (!is_file($package . '/' . $required)) {
        fwrite(STDERR, "Missing from the archive: {$required}\n");
        exit(1);
    }
}
foreach (['tests', 'vendor', 'composer.json', 'phpunit.xml.dist', '.github'] as $unwanted) {
    if (file_exists($package . '/' . $unwanted)) {
        fwrite(STDERR, "Does not belong in the archive: {$unwanted}\n");
        exit(1);
    }
}

$smoke = <<<'PHP'
<?php
// The only thing a Composer-less install does:
require __DIR__ . '/facta-api-php/autoload.php';

$transport = new class implements Facta\Api\Http\Transport {
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Facta\Api\Http\Response
    {
        return new Facta\Api\Http\Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"ambiente":"00"}');
    }
};
$facta = new Facta\Api\Facta(['apiKey' => 'facta_test_smoke', 'transport' => $transport]);
$status = $facta->status();
if ($status->ok !== true || $facta->environment() !== '00') {
    fwrite(STDERR, "status() failed\n");
    exit(1);
}
if (Facta\Api\Json\Fingerprint::v1('{"b":1,"a":10.0}') !== hash('sha256', '{"a":10,"b":1}')) {
    fwrite(STDERR, "fingerprint failed\n");
    exit(1);
}
if (!(Facta\Api\Http\TransportFactory::create() instanceof Facta\Api\Http\Transport)) {
    fwrite(STDERR, "transport factory failed\n");
    exit(1);
}
echo "zip smoke ok\n";
PHP;
file_put_contents($dir . '/smoke.php', $smoke);

passthru(escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=1 ' . escapeshellarg($dir . '/smoke.php'), $status);

if (in_array('--keep', $argv, true)) {
    echo "kept: {$dir}\n";
} else {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item instanceof SplFileInfo) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }
    rmdir($dir);
}
exit($status);
