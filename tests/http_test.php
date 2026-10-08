#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Http.php';

// Real cURL against a loopback-only mock, never a provider or production DB.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false || !function_exists('pcntl_fork')) {
    fwrite(STDERR, "Loopback sockets and pcntl are required.\n");
    exit(2);
}
$address = stream_socket_get_name($server, false);
$pid = pcntl_fork();
if ($pid === -1) {
    exit(2);
}
if ($pid === 0) {
    for ($i = 0; $i < 2; $i++) {
        $connection = stream_socket_accept($server, 10);
        if ($connection === false) {
            exit(1);
        }
        stream_set_timeout($connection, 5);
        $request = fgets($connection);
        $headers = [];
        while (($line = fgets($connection)) !== false && trim($line) !== '') {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        $length = (int)($headers['content-length'] ?? 0);
        $body = '';
        while (strlen($body) < $length) {
            $part = fread($connection, $length - strlen($body));
            if ($part === false || $part === '') {
                break;
            }
            $body .= $part;
        }
        $json = json_encode(['request' => trim((string)$request), 'headers' => $headers, 'body' => $body]);
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n" . $json);
        fclose($connection);
    }
    fclose($server);
    exit(0);
}
fclose($server);
$get = App\Http::json('GET', 'http://' . $address . '/st?api=mock', ['user_agent' => '']);
$post = App\Http::json('POST', 'http://' . $address . '/submit', ['body' => ['action' => 'test']]);
pcntl_waitpid($pid, $status);
$checks = [
    'bodyless GET has no form content type, body or custom agent' => $get['ok']
        && $get['data']['request'] === 'GET /st?api=mock HTTP/1.1'
        && !isset($get['data']['headers']['content-type'])
        && !isset($get['data']['headers']['user-agent']) && $get['data']['body'] === '',
    'POST retains form body and default agent' => $post['ok']
        && $post['data']['request'] === 'POST /submit HTTP/1.1'
        && $post['data']['body'] === 'action=test'
        && $post['data']['headers']['content-type'] === 'application/x-www-form-urlencoded'
        && str_contains($post['data']['headers']['user-agent'], 'HTXG.PRO'),
    'mock server completed' => pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);