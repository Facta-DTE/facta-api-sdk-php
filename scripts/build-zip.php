<?php

declare(strict_types=1);

/**
 * Builds dist/facta-api-php-vX.Y.Z.zip for installs without Composer.
 *
 *     php scripts/build-zip.php [version]
 *
 * The version defaults to Facta::VERSION. The archive holds one folder,
 * `facta-api-php/`, with src/, autoload.php, README.md, LICENSE and CHANGELOG.md.
 * Entries are sorted, so the archive listing is stable.
 */

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ext-zip is required to build the archive.\n");
    exit(1);
}

$root = dirname(__DIR__);
$version = $argv[1] ?? null;
if ($version === null) {
    $source = (string) file_get_contents($root . '/src/Facta.php');
    if (preg_match("/const VERSION = '([^']+)'/", $source, $m) !== 1) {
        fwrite(STDERR, "Cannot read Facta::VERSION.\n");
        exit(1);
    }
    $version = $m[1];
}
$version = ltrim($version, 'v');
if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version) !== 1) {
    fwrite(STDERR, "Invalid version: {$version}\n");
    exit(1);
}

$files = ['autoload.php', 'README.md', 'LICENSE', 'CHANGELOG.md'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile()) {
        $files[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
sort($files);

if (!is_dir($root . '/dist') && !mkdir($root . '/dist', 0777, true)) {
    fwrite(STDERR, "Cannot create dist/.\n");
    exit(1);
}
$target = $root . '/dist/facta-api-php-v' . $version . '.zip';
if (is_file($target)) {
    unlink($target);
}

$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create {$target}.\n");
    exit(1);
}
foreach ($files as $relative) {
    $name = 'facta-api-php/' . str_replace('\\', '/', $relative);
    $zip->addFile($root . '/' . $relative, $name);
}
$zip->close();

echo $target, "\n";
