<?php

/**
 * Autoloader for installs without Composer (ZIP / FTP upload):
 *
 *     require_once '/path/to/facta/autoload.php';
 *     $facta = new Facta\Api\Facta(['apiKey' => '...']);
 *
 * Composer users do not need this file.
 */

spl_autoload_register(static function ($class) {
    $prefix = 'Facta\\Api\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
