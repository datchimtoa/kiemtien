<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;
use App\Settings;

final class Client
{
    public static function call(string $provider, array $spec, array $vars = []): array
    {
        $vars['key'] = Settings::get('provider_' . $provider . '_api_key', '');
        if ($vars['key'] === '' || preg_match('/[\r\n]/', (string)$vars['key'])) {
            throw new RequestException('Chưa cấu hình API key hợp lệ.');
        }
        $replace = static function (string $value) use ($vars): string {
            return preg_replace_callback('/\{([a-z_]+)\}/', static fn($m) => (string)($vars[$m[1]] ?? ''), $value);
        };
        // URL placeholders are path/query components, never arbitrary API hosts.
        $url = preg_replace_callback('/\{([a-z_]+)\}/', static fn($m) => rawurlencode((string)($vars[$m[1]] ?? '')), $spec['url']);
        $query = array_map($replace, $spec['query'] ?? []);
        $opt = ['headers' => array_map($replace, $spec['headers'] ?? [])];
        if (isset($spec['user_agent'])) {
            $opt['user_agent'] = (string)$spec['user_agent'];
        }
        if (isset($spec['json_body']) || isset($spec['body'])) {
            $opt['body'] = array_map($replace, $spec['json_body'] ?? $spec['body']);
            $opt['json'] = isset($spec['json_body']);
        }
        $response = Http::json($spec['method'] ?? 'GET', Http::url($url, $query), $opt);
        $ok = $spec['ok'] ?? null;
        if (is_string($ok)) {
            $ok = ['path' => $ok, 'values' => [true]];
        }
        if (!Http::isOk($response['data'], $response['ok'], [
            'ok_path' => $ok['path'] ?? '', 'ok_values' => $ok['values'] ?? [true],
        ])) {
            // Never expose upstream responses, which may contain credentials/URLs.
            if (!$response['ok']) {
                $status = (int)$response['http'];
                $reason = match (true) {
                    $status === 0 => 'Không kết nối được API nhà cung cấp (mạng, TLS hoặc timeout).',
                    $status === 401 || $status === 403 => "API nhà cung cấp từ chối truy cập (HTTP {$status}); cần kiểm tra API key và quyền truy cập.",
                    $status === 429 => 'API nhà cung cấp đang giới hạn tần suất (HTTP 429).',
                    $status === 503 => 'API nhà cung cấp tạm không phục vụ yêu cầu (HTTP 503). Không tạo được link; chưa có xác nhận nhận job.',
                    $status >= 200 && $status < 300 => "API nhà cung cấp trả HTTP {$status} nhưng phản hồi không phải JSON hợp lệ.",
                    default => "API nhà cung cấp trả lỗi HTTP {$status}.",
                };
                $reason .= ' response_kind=' . $response['response_kind'];
                throw new RequestException($reason);
            }
            throw new RequestException('Nhà cung cấp chưa xác nhận yêu cầu (HTTP ' . (int)$response['http'] . ', success không hợp lệ). Vui lòng kiểm tra cấu hình hoặc liên hệ hỗ trợ.');
        }
        return $response['data'];
    }

    public static function safeLink(string $provider, mixed $value): string
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
            throw new RequestException('API nhà cung cấp không trả link hợp lệ trong trường shortenedUrl / link đã cấu hình.');
        }
        $parts = parse_url($value);
        $host = strtolower($parts['host'] ?? '');
        $expected = strtolower((string)parse_url(Catalog::get($provider)['base'] ?? 'https://yeujob.com', PHP_URL_HOST));
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || ($host !== $expected && $host !== preg_replace('/^www\./', '', $expected))) {
            throw new RequestException('Domain link nhà cung cấp không hợp lệ.');
        }
        return $value;
    }
}