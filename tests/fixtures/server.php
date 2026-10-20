<?php

// Router script for `php -S`: a tiny HTTP server for the transport tests.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/slow') {
    sleep(3);
    echo '{"late":true}';
    return;
}
if ($path === '/retry') {
    http_response_code(409);
    header('Retry-After: 7');
    header('Content-Type: application/json');
    echo '{"error":{"code":"idempotency_in_flight","message":"wait"}}';
    return;
}
if ($path === '/empty') {
    http_response_code(204);
    return;
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (strpos($name, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}
header('Content-Type: application/json');
header('X-Custom: one');
http_response_code($path === '/created' ? 201 : 200);
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
], JSON_UNESCAPED_UNICODE);
