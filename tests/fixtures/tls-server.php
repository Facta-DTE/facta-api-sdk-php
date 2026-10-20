<?php

// Minimal HTTPS server for the TLS tests: php tls-server.php <port> <pem> [connections]
$port = (int) $argv[1];
$pem = $argv[2];
$max = isset($argv[3]) ? (int) $argv[3] : 4;

$context = stream_context_create(['ssl' => ['local_cert' => $pem, 'allow_self_signed' => true, 'verify_peer' => false]]);
$server = stream_socket_server('ssl://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
for ($i = 0; $i < $max; $i++) {
    $client = @stream_socket_accept($server, 5);
    if ($client === false) {
        continue;
    }
    $request = '';
    while (($line = fgets($client)) !== false) {
        $request .= $line;
        if (rtrim($line) === '') {
            break;
        }
    }
    $body = '{"secure":true}';
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($client);
}
