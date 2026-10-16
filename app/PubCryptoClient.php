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
        $opt = ['headers' => $headers, 'user_agent' => ''];
        if ($body !== null) {
            $opt['body'] = $body;
            $opt['json'] = true;
        }
        $res = Http::json($method, $url, $opt);
        // No upstream bodies, URLs or cURL messages in user-facing errors.
        return ['ok' => $res['ok'], 'http' => $res['http'], 'data' => $res['data'],
            'error' => $res['ok'] ? null : 'PubCrypto request failed'];
    }

    /**
     * Fetch PubCrypto Link tasks for a member (sub_id = our user id). Cached with TTL.
     * @return array [ok, error, http, tasks, meta]
     */
    public static function links(int $userId, bool $fresh = false): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Chưa cấu hình PubCrypto (site_key / api_key). Liên hệ quản trị viên.', 'http' => 0, 'tasks' => [], 'meta' => []];
        }
        $siteKey = self::siteKey();
        $subId = (string)$userId;
        $cacheKey = $siteKey . '|' . $subId; // quota/cooldown are per-member
        $now = time();

        $cached = $fresh ? null : Database::one('SELECT payload, fetched_at FROM task_cache WHERE site_key = ?', [$cacheKey]);
        if ($cached !== null) {
            $payload = json_decode((string)$cached['payload'], true);
            $age = $now - strtotime((string)$cached['fetched_at']);
            if (is_array($payload) && $age >= 0 && $age < self::cacheLifetime($payload)) {
                return self::shape($payload, true, $age);
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

    public static function forgetLinks(int $userId): void
    {
        Database::run('DELETE FROM task_cache WHERE site_key = ?', [self::siteKey() . '|' . $userId]);
    }

    /** Never keep a snapshot beyond a link expiry or a known cooldown boundary. */
    private static function cacheLifetime(array $data): int
    {
        $ttl = min(max(10, Settings::getInt('pubcrypto_task_cache_ttl', 45)), max(0, (int)($data['link_expires_in'] ?? 600)));
        foreach (($data['tasks'] ?? []) as $task) {
            $cooldown = (int)($task['cooldown_remaining'] ?? 0);
            if ($cooldown > 0) {
                $ttl = min($ttl, $cooldown);
            }
        }
        return $ttl;
    }

    private static function shape(array $data, bool $fromCache, int $age = 0): array
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
                'pending_in_cycle'     => (int)($t['pending_in_cycle'] ?? 0),
                'remaining_in_cycle'   => (int)($t['remaining_in_cycle'] ?? 0),
                'available'            => (bool)($t['available'] ?? false),
                'cooldown_remaining'   => max(0, (int)($t['cooldown_remaining'] ?? 0) - $age),
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
                'refresh_after'   => max(1, self::cacheLifetime($data) - $age),
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
