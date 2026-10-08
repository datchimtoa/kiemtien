<?php
declare(strict_types=1);

// Standalone CLI probe: no bootstrap, database, quotas or wallet operations.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "PHP cURL is required.\n");
    exit(2);
}
$key = getenv('YEUJOB_API_KEY') ?: '';
$destination = getenv('YEUJOB_PROBE_URL') ?: 'https://example.com/yeujob-probe/' . bin2hex(random_bytes(16));
if ($key === '' || preg_match('/[\r\n]/', $key) || !filter_var($destination, FILTER_VALIDATE_URL)
    || parse_url($destination, PHP_URL_SCHEME) !== 'https') {
    fwrite(STDERR, "Set YEUJOB_API_KEY and optionally an HTTPS YEUJOB_PROBE_URL.\n");
    exit(2);
}
$ch = curl_init('https://yeujob.com/st?' . http_build_query(['api' => $key, 'url' => $destination]));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$errno = curl_errno($ch);
unset($ch);
$data = is_string($body) ? json_decode($body, true) : null;
$link = is_array($data) ? ($data['shortenedUrl'] ?? $data['data']['shortenedUrl'] ?? null) : null;
$validLink = is_string($link) && filter_var($link, FILTER_VALIDATE_URL)
    && parse_url($link, PHP_URL_SCHEME) === 'https'
    && in_array(strtolower((string)parse_url($link, PHP_URL_HOST)), ['yeujob.com', 'www.yeujob.com'], true);
$success = is_array($data) && ($data['success'] ?? false) === true;
// Never print the request URL, raw body, cURL error text, key or usable job link.
echo json_encode([
    'http_status' => $status,
    'curl_errno' => $errno,
    'content_type' => preg_replace('/[^a-zA-Z0-9;= ._\/-]/', '', substr($contentType, 0, 100)),
    'body_bytes' => is_string($body) ? strlen($body) : 0,
    'json_object' => is_array($data),
    'success_true' => $success,
    'valid_shortened_url' => (bool)$validLink,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($status >= 200 && $status < 300 && $success && $validLink ? 0 : 1);