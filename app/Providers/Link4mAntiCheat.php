<?php
declare(strict_types=1);

namespace App\Providers;

use App\Database;
use App\Http;
use App\Settings;

/** First-party Link4m callback state and risk scoring. Never credits directly. */
final class Link4mAntiCheat
{
    public static function secret(): string
    {
        $configured = config('link4m.hmac_secret', '');
        $secret = (string)($configured ?: getenv('LINK4M_HMAC_SECRET') ?: Settings::get('provider_link4m_callback_secret', ''));
        return preg_match('/^[^\r\n]{32,512}$/D', $secret) ? $secret : '';
    }

    public static function siteKey(): string
    {
        return (string)(config('link4m.turnstile_site_key', '') ?: getenv('LINK4M_TURNSTILE_SITE_KEY'));
    }

    public static function callbackUrl(string $token): string
    {
        if (self::secret() === '') {
            throw new \RuntimeException('Cần cấu hình khóa xác minh Link4m dài ít nhất 32 ký tự.');
        }
        $base = rtrim((string)config('base_url', ''), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('Cần cấu hình base_url HTTPS cố định.');
        }
        return $base . '/task/link4m/callback?' . http_build_query([
            't' => $token,
            'sig' => hash_hmac('sha256', $token, self::secret()),
        ]);
    }

    public static function validSignature(string $token, string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && self::secret() !== ''
            && hash_equals(hash_hmac('sha256', $token, self::secret()), $signature);
    }

    public static function initialize(string $token, int $userId, string $ip, string $ua, string $fp): void
    {
        Database::run(
            'INSERT INTO link4m_sessions(token,user_id,ip,ua,fp,provider,status,created_at) VALUES(?,?,?,?,?,?,?,?)',
            [$token, $userId, $ip, $ua, $fp, 'link4m', 'pending', now()]
        );
    }

    public static function setCookie(string $token): void
    {
        $ttl = max(60, (int)(config('link4m.token_ttl', 0) ?: getenv('LINK4M_TOKEN_TTL') ?: 3600));
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie('ts', $token, [
            'expires' => time() + $ttl,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $token): ?array
    {
        return Database::one(
            'SELECT l.*, p.status AS attempt_status, p.expires_at, p.min_seconds, p.destination
             FROM link4m_sessions l JOIN provider_attempts p ON p.token = l.token WHERE l.token = ?',
            [$token]
        );
    }

    public static function markArrival(string $token): ?array
    {
        $row = self::find($token);
        if ($row === null || $row['status'] !== 'pending' || $row['attempt_status'] !== 'pending') {
            return $row;
        }
        Database::run(
            'UPDATE link4m_sessions SET arrived_at = ?, arrive_ref = ?, arrive_ip = ?, arrive_ua = ?
             WHERE token = ? AND status = ? AND arrived_at IS NULL',
            [now(), substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255), client_ip(), user_agent(), $token, 'pending']
        );
        return self::find($token);
    }

    /** @return array{status:string,score:int,reasons:list<string>,message:string} */
    public static function complete(string $token, string $signature, string $turnstile, int $events): array
    {
        if (!self::validSignature($token, $signature)) {
            throw new \InvalidArgumentException('Liên kết xác minh không hợp lệ.');
        }
        $row = self::find($token);
        if ($row === null || $row['status'] !== 'pending' || $row['attempt_status'] !== 'pending'
            || $row['arrived_at'] === null) {
            throw new \InvalidArgumentException('Lượt nhiệm vụ chưa sẵn sàng hoặc không còn khả dụng.');
        }
        $elapsed = time() - strtotime((string)$row['created_at']);
        $score = 100;
        $reasons = [];
        $penalize = static function (int $points, string $reason) use (&$score, &$reasons): void {
            $score -= $points;
            $reasons[] = $reason;
        };
        if (!self::turnstileValid($turnstile, client_ip())) {
            $penalize(60, 'turnstile');
        }
        if (($_COOKIE['ts'] ?? '') !== $token) {
            $penalize(60, 'cookie_mismatch');
        }
        $minSeconds = max(1, (int)($row['min_seconds'] ?? 20));
        $softSeconds = max($minSeconds, (int)(config('link4m.soft_seconds', 0) ?: getenv('LINK4M_SOFT_SECONDS') ?: 30));
        if ($elapsed < $minSeconds) {
            $penalize(60, 'too_fast');
        } elseif ($elapsed < $softSeconds) {
            $penalize(15, 'fast');
        }
        if ($elapsed > max(60, (int)(config('link4m.token_ttl', 0) ?: getenv('LINK4M_TOKEN_TTL') ?: 3600))) {
            $penalize(100, 'expired');
        }
        if (!preg_match('~link4m\.(?:co|com)(?:/|$)~i', (string)($row['arrive_ref'] ?? ''))) {
            $penalize(15, 'no_referer');
        }
        if (self::networkKey((string)$row['ip']) !== self::networkKey(client_ip())) {
            $penalize(15, 'ip_changed');
        }
        if ((string)$row['ua'] !== user_agent()) {
            $penalize(20, 'ua_changed');
        }
        if ($events < 1) {
            $penalize(30, 'no_interaction');
        }
        if (strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')) !== 'VN') {
            $penalize(100, 'not_vn');
        }
        $status = $score >= 70 ? 'verified' : ($score >= 40 ? 'hold' : 'rejected');
        $changed = 0;
        Database::begin();
        try {
            $changed = Database::run(
                'UPDATE link4m_sessions SET status = ?, risk_score = ?, reasons = ?, link4m_countable = ?, completed_at = ? WHERE token = ? AND status = ?',
                [$status, $score, substr(implode(',', $reasons), 0, 255), $status === 'verified' ? 1 : 0, now(), $token, 'pending']
            )->rowCount();
            if ($changed === 1) {
                if ($status === 'rejected') {
                    Database::run("UPDATE provider_attempts SET status = 'rejected' WHERE token = ? AND status = 'pending'", [$token]);
                } else {
                    Database::run('UPDATE provider_attempts SET returned_at = COALESCE(returned_at, ?) WHERE token = ? AND status = ?', [now(), $token, 'pending']);
                }
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            throw $e;
        }
        return [
            'status' => $changed === 1 ? $status : 'rejected',
            'score' => $score,
            'reasons' => $reasons,
            'message' => $status === 'verified'
                ? 'Đã ghi nhận kết quả xác minh. Lượt thưởng đang chờ kiểm tra.'
                : ($status === 'hold' ? 'Đã ghi nhận. Lượt này đang chờ kiểm tra thêm.' : 'Lượt xác minh chưa đạt yêu cầu.'),
        ];
    }

    private static function turnstileValid(string $response, string $ip): bool
    {
        $secret = (string)(config('link4m.turnstile_secret', '') ?: getenv('LINK4M_TURNSTILE_SECRET'));
        if ($secret === '' || $response === '') {
            return false;
        }
        $result = Http::request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => ['secret' => $secret, 'response' => $response, 'remoteip' => $ip],
        ]);
        $data = json_decode($result['body'], true);
        return $result['ok'] && is_array($data) && ($data['success'] ?? false) === true;
    }

    private static function networkKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3));
        }
        $packed = @inet_pton($ip);
        return $packed === false ? $ip : substr(bin2hex($packed), 0, 16);
    }
}
