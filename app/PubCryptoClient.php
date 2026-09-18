<?php
declare(strict_types=1);

namespace App;

/**
 * PubCrypto publisher API client (server-side only).
 *  - GET  /api/publisher/pubcrypto-links?site_key=&sub_id=
 *  - POST /api/publisher/embed-tokens (signed iframe, optional use)
 * Error codes per docs: 400 missing site_key, 401 bad key, 404 not owned/approved, 429 rate limit.
 */
final class PubCryptoClient
{
    public static function configured(): bool
    {
        // Ưu tiên env (Render: PUBCRYPTO_SITE_KEY/API_KEY), fallback DB settings (Admin → Cài đặt).
        $sk = (string)(config('pubcrypto.site_key') ?: Settings::get('pubcrypto_site_key', ''));
        $ak = (string)(config('pubcrypto.api_key') ?: Settings::get('pubcrypto_api_key', ''));
        return $sk !== '' && $ak !== '';
    }

    private static function siteKey(): string
    {
        return (string)(config('pubcrypto.site_key') ?: Settings::get('pubcrypto_site_key', ''));
    }

    private static function apiKey(): string
    {
        return (string)(config('pubcrypto.api_key') ?: Settings::get('pubcrypto_api_key', ''));
    }

    private static function apiBase(): string
    {
        return rtrim((string)(config('pubcrypto.api_base') ?: Settings::get('pubcrypto_api_base', 'https://pub.cryptolinkforearn.com')), '/');
    }

    private static function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $base = self::apiBase();
        $url = $base . $path . (empty($query) ? '' : '?' . http_build_query($query));
        $headers = [
            'Authorization: Bearer ' . self::apiKey(),
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body),
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($err !== '') {
            return ['ok' => false, 'http' => 0, 'error' => 'curl: ' . $err];
        }
        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            return ['ok' => false, 'http' => $code, 'error' => 'invalid response', 'raw' => substr((string)$resp, 0, 500)];
        }
        return ['ok' => $code >= 200 && $code < 300, 'http' => $code, 'data' => $data];
    }

    /**
     * Fetch PubCrypto Link tasks for a member (sub_id = our user id). Cached with TTL.
     * @return array [ok, error, http, tasks, meta]
     */
    public static function links(int $userId): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Chưa cấu hình PubCrypto (site_key / api_key). Liên hệ quản trị viên.', 'http' => 0, 'tasks' => [], 'meta' => []];
        }
        $siteKey = self::siteKey();
        $subId = (string)$userId;
        $cacheKey = $siteKey . '|' . $subId; // quota/cooldown are per-member
        $ttl = max(10, Settings::getInt('pubcrypto_task_cache_ttl', 45));
        $now = time();

        $cached = Database::one('SELECT payload, fetched_at FROM task_cache WHERE site_key = ?', [$cacheKey]);
        if ($cached !== null && ($now - strtotime((string)$cached['fetched_at'])) < $ttl) {
            $payload = json_decode((string)$cached['payload'], true);
            if (is_array($payload)) {
                return self::shape($payload, true);
            }
        }

        $res = self::request('GET', '/api/publisher/pubcrypto-links', ['site_key' => $siteKey, 'sub_id' => $subId]);
        if (!$res['ok']) {
            $httpError = match ($res['http']) {
                400 => 'Thiếu site_key.',
                401 => 'API key không hợp lệ hoặc đã bị vô hiệu.',
                404 => 'App chưa được duyệt hoặc không thuộc sở hữu của bạn.',
                429 => 'Quá giới hạn truy vấn API, thử lại sau ít phút.',
                0   => 'Không kết nối được máy chủ PubCrypto.',
                default => 'Lỗi API PubCrypto (HTTP ' . $res['http'] . ').',
            };
            return ['ok' => false, 'error' => $httpError, 'http' => $res['http'], 'tasks' => [], 'meta' => []];
        }
        // Cache kết quả API (upsert tương thích SQLite + MySQL).
        Database::upsert(
            'task_cache',
            ['site_key' => $cacheKey, 'payload' => json_encode($res['data'], JSON_UNESCAPED_UNICODE), 'fetched_at' => date('Y-m-d H:i:s', $now)],
            'site_key',
            ['payload', 'fetched_at']
        );
        return self::shape($res['data'], false);
    }

    private static function shape(array $data, bool $fromCache): array
    {
        $tasks = [];
        foreach (($data['tasks'] ?? []) as $t) {
            $tasks[] = [
                'id'                   => (int)($t['id'] ?? 0),
                'task_name'            => (string)($t['task_name'] ?? ''),
                'link_dotask'          => (string)($t['link_dotask'] ?? ''),
                'reward_usd'           => (float)($t['reward_usd'] ?? 0),
                'member_reward'        => (float)($t['member_reward'] ?? 0),
                'publisher_reward_usd' => (float)($t['publisher_reward_usd'] ?? 0),
                'total_max'            => (int)($t['total_max'] ?? 0),
                'cooldown_seconds'     => (int)($t['cooldown_seconds'] ?? 0),
                'done_today'           => (int)($t['done_today'] ?? 0),
                'remaining_in_cycle'   => (int)($t['remaining_in_cycle'] ?? 0),
                'available'            => (bool)($t['available'] ?? false),
                'cooldown_remaining'   => (int)($t['cooldown_remaining'] ?? 0),
            ];
        }
        return [
            'ok'    => true,
            'error' => null,
            'tasks' => $tasks,
            'meta'  => [
                'currency_name'   => (string)($data['currency_name'] ?? ''),
                'link_expires_in' => (int)($data['link_expires_in'] ?? 600),
                'member_percent'  => (int)($data['site']['Member Percent'] ?? 0),
                'from_cache'      => $fromCache,
            ],
        ];
    }

    /** Create a signed one-time offerwall iframe URL (optional feature). */
    public static function embedToken(int $userId, string $origin, string $theme = 'dark'): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $res = self::request('POST', '/api/publisher/embed-tokens', [], [
            'site_key' => self::siteKey(),
            'sub_id'   => (string)$userId,
            'origin'   => $origin,
            'theme'    => $theme,
        ]);
        return ['ok' => $res['ok'], 'error' => $res['error'] ?? null, 'data' => $res['data'] ?? []];
    }
}
