<?php
declare(strict_types=1);

namespace App;

/**
 * HTTP client tối giản (curl) cho các provider API bên ngoài.
 * Http::fake() cho phép test offline (không gọi mạng thật).
 */
final class Http
{
    /** @var null|callable(string,string,array<string,mixed>):array<string,mixed> */
    private static $fake = null;

    /** Bật chế độ giả lập (test). Truyền null để tắt. */
    public static function fake(?callable $fn): void
    {
        self::$fake = $fn;
    }

    /**
     * @param array{headers?:array<int,string>,body?:mixed,json?:bool,timeout?:int,user_agent?:string} $opt
     * @return array{ok:bool,http:int,body:string,error:string}
     */
    public static function request(string $method, string $url, array $opt = []): array
    {
        if (self::$fake !== null) {
            $res = (self::$fake)($method, $url, $opt);
            return [
                'ok'    => (bool)($res['ok'] ?? true),
                'http'  => (int)($res['http'] ?? 200),
                'body'  => (string)($res['body'] ?? ''),
                'error' => (string)($res['error'] ?? ''),
            ];
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Máy chủ thiếu PHP extension cURL. Cần rebuild bản Docker đã cài extension curl.');
        }
        $headers = $opt['headers'] ?? [];
        $body = $opt['body'] ?? null;
        if (is_array($body)) {
            if (($opt['json'] ?? false) === true) {
                $body = json_encode($body, JSON_UNESCAPED_UNICODE);
                $headers[] = 'Content-Type: application/json';
            } else {
                $body = http_build_query($body);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Không đi theo redirect: một số API trả 302 về trang quảng cáo.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => (int)($opt['timeout'] ?? 15),
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => $opt['user_agent'] ?? 'Mozilla/5.0 (compatible; HTXG.PRO/1.0; +https://www.htxg.pro)',
        ]);
        // Setting POSTFIELDS (even null) changes cURL's request construction.
        // A bodyless GET must not be configured as a form submission.
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err = (string)curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        if ($resp === false) {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => $err !== '' ? 'curl: ' . $err : 'request failed'];
        }
        return [
            'ok'    => $code >= 200 && $code < 300,
            'http'  => $code,
            'body'  => (string)$resp,
            'error' => '',
        ];
    }

    /**
     * Gọi API và decode JSON.
     * @return array{ok:bool,http:int,data:array<string,mixed>,error:string,body:string}
     */
    public static function json(string $method, string $url, array $opt = []): array
    {
        $r = self::request($method, $url, $opt);
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            return [
                'ok'    => false,
                'http'  => $r['http'],
                'data'  => [],
                'error' => $r['error'] !== '' ? $r['error'] : 'Phản hồi không phải JSON.',
                'body'  => substr($r['body'], 0, 300),
            ];
        }
        return ['ok' => $r['ok'], 'http' => $r['http'], 'data' => $data, 'error' => $r['error'], 'body' => $r['body']];
    }

    /** Lấy giá trị lồng nhau theo đường dẫn "data.item.id". */
    public static function pick(array $data, string $path): mixed
    {
        $cur = $data;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

    /**
     * Trả về giá trị đầu tiên (khác null/rỗng) trong danh sách đường dẫn.
     * Provider khác nhau đặt tên field khác nhau (shortenedUrl / shortUrl / message / bbmktsUrl).
     *
     * @param list<string> $paths
     */
    public static function any(array $data, array $paths): mixed
    {
        foreach ($paths as $p) {
            $v = self::pick($data, $p);
            if ($v !== null && $v !== '' && $v !== []) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Ghép query string vào URL. Placeholder rỗng ('') bị BỎ (provider coi {} là giá trị sai).
     *
     * @param array<string,scalar|null> $query
     */
    public static function url(string $url, array $query = []): string
    {
        $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
        if ($query === []) {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query($query);
    }

    /**
     * `ok` coi như thành công: kiểm tra ok_path/ok_values nếu có, ngược lại dựa vào HTTP 2xx.
     *
     * @param array{ok_path?:string,ok_values?:list<mixed>} $spec
     */
    public static function isOk(array $data, bool $httpOk, array $spec): bool
    {
        if (!$httpOk) {
            return false;
        }
        $path = (string)($spec['ok_path'] ?? '');
        if ($path === '') {
            return $httpOk;
        }
        $v = self::pick($data, $path);
        if ($v === null) {
            return false;
        }
        $values = $spec['ok_values'] ?? [true, 'true', 1, '1', 'success', 'ok'];
        foreach ($values as $want) {
            if ($v === $want) {
                return true;
            }
        }
        return false;
    }
}
