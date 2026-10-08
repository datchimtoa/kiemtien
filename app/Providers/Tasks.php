<?php
declare(strict_types=1);

namespace App\Providers;

use App\Database;
use App\Http;
use App\Settings;
use App\Wallet;

final class Tasks
{
    public static function setting(string $provider, string $field): int
    {
        return Settings::getInt('provider_' . $provider . '_' . $field, (int)(Catalog::get($provider)['defaults'][$field] ?? 0));
    }

    public static function deviceHash(): string
    {
        return hash('sha256', session_id() . '|' . user_agent());
    }

    /** Reserve quotas before remote calls; release only definite pre-submission failures. */
    public static function start(int $userId, string $provider, string $service): array
    {
        $def = Catalog::get($provider);
        if ($def === null || Settings::getInt('provider_' . $provider . '_enabled') !== 1) {
            throw new \RuntimeException('Nguồn nhiệm vụ chưa bật.');
        }
        $selected = null;
        foreach ($def['services'] as $candidate) {
            if ($candidate['id'] === $service) {
                $selected = $candidate;
            }
        }
        if ($selected === null || $service === 'no_ads') {
            throw new \RuntimeException('Dịch vụ không hợp lệ cho nhiệm vụ trả thưởng.');
        }
        $token = bin2hex(random_bytes(32));
        $base = rtrim((string)config('base_url', ''), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('Cần cấu hình base_url HTTPS cố định.');
        }
        $destination = $base . '/task/return/' . $token;
        $reward = Settings::getInt('provider_' . $provider . '_service_' . $service . '_reward_vnd', self::setting($provider, 'reward_vnd'));
        if ($reward < 0 || $reward > Settings::getInt('postback_max_reward_vnd', 2000000)) {
            throw new \RuntimeException('Thù lao không hợp lệ.');
        }
        if ($provider === 'yeujob' && $reward === 0) {
            throw new \RuntimeException('YeuJob V2 cần cấu hình thưởng cố định VND/lượt lớn hơn 0 trong Admin.');
        }
        $ip = client_ip();
        $share = max(0, min(100, self::setting($provider, 'share_percent')));
        $lockKey = $provider . ':' . hash('sha256', $ip);
        Database::upsert('provider_locks', ['lock_key' => $lockKey], 'lock_key', ['lock_key']);
        Database::begin();
        try {
            $lock = Database::isSqlite() ? '' : ' FOR UPDATE';
            Database::one('SELECT lock_key FROM provider_locks WHERE lock_key = ?' . $lock, [$lockKey]);
            $user = Database::one('SELECT status, risk_flag FROM users WHERE id = ?' . $lock, [$userId]);
            if ($user === null || $user['status'] !== 'active' || (int)$user['risk_flag'] !== 0) {
                throw new \RuntimeException('Tài khoản không đủ điều kiện làm nhiệm vụ.');
            }
            $since = date('Y-m-d H:i:s', time() - 86400);
            $userCount = (int)Database::value("SELECT COUNT(*) FROM provider_attempts WHERE provider = ? AND user_id = ? AND created_at >= ? AND status <> 'creation_failed'", [$provider, $userId, $since]);
            $ipCount = (int)Database::value("SELECT COUNT(*) FROM provider_attempts WHERE provider = ? AND ip = ? AND created_at >= ? AND status <> 'creation_failed'", [$provider, $ip, $since]);
            $hourCount = (int)Database::value('SELECT COUNT(*) FROM provider_attempts WHERE user_id = ? AND created_at >= ?', [$userId, date('Y-m-d H:i:s', time() - 3600)]);
            $last = Database::value('SELECT MAX(created_at) FROM provider_attempts WHERE user_id = ?', [$userId]);
            $dailyLimit = self::setting($provider, 'daily_limit');
            $ipLimit = self::setting($provider, 'ip_daily_limit');
            $hourLimit = Settings::getInt('task_max_completions_per_hour', 30);
            if ($userCount >= $dailyLimit) {
                throw new \RuntimeException("Tài khoản đã dùng {$userCount}/{$dailyLimit} lượt của nguồn này trong 24 giờ gần nhất. Lượt lỗi sau khi gửi yêu cầu vẫn giữ chỗ; cần admin đối soát, không tự đặt lại lúc 0 giờ.");
            }
            if ($ipCount >= $ipLimit) {
                throw new \RuntimeException("IP hiện tại đã dùng {$ipCount}/{$ipLimit} lượt của nguồn này trong 24 giờ gần nhất (tính cả tài khoản dùng chung IP). Vui lòng chờ lượt cũ hết hạn hoặc liên hệ admin đối soát.");
            }
            if ($hourCount >= $hourLimit) {
                throw new \RuntimeException("Đã đạt giới hạn {$hourCount}/{$hourLimit} lần bắt đầu trong 60 phút gần nhất, tính cả lần tạo lỗi để chống spam. Vui lòng chờ lượt cũ ra khỏi khung 60 phút.");
            }
            $wait = $last ? max(1, Settings::getInt('task_click_cooldown_seconds', 20)) - (time() - strtotime((string)$last)) : 0;
            if ($wait > 0) {
                throw new \RuntimeException("Vui lòng chờ thêm {$wait} giây trước khi bắt đầu nhiệm vụ tiếp theo (kể cả lần tạo trước bị lỗi).");
            }
            Database::run('INSERT INTO provider_attempts(token,user_id,provider,service,status,reward_vnd,share_percent,min_seconds,ip,device_hash,destination,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $token, $userId, $provider, $service, 'creating', $reward,
                $share, max(1, self::setting($provider, 'min_seconds')),
                $ip, self::deviceHash(), $destination, now(), date('Y-m-d H:i:s', time() + 7 * 86400),
            ]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            throw $e;
        }
        $remoteRequested = false;
        try {
            $vars = ['url' => $destination, 'service' => $service, 'service_type' => $selected['type'] ?? '', 'title' => $selected['label']];
            if (Settings::get('provider_' . $provider . '_api_key', '') === '') {
                throw new \RuntimeException('Chưa cấu hình API key hợp lệ.');
            }
            if ($def['kind'] === 'job') {
                $list = Client::call($provider, $def['job']['list']);
                $items = Http::pick($list, $def['job']['list']['items']);
                if (isset($items['jobs'])) {
                    $items = $items['jobs'];
                }
                $job = null;
                foreach (is_array($items) ? $items : [] as $item) {
                    if (is_array($item) && (int)($item['slots_left'] ?? 0) > 0 && empty($item['vip_daily_full'])) {
                        $job = $item;
                        break;
                    }
                }
                if ($job === null) {
                    throw new \RuntimeException('Chưa có job còn lượt.');
                }
                $remoteRequested = true;
                $data = Client::call($provider, $def['job']['accept'], ['job_id' => $job['id']]);
                $link = Client::safeLink($provider, Http::pick($data, 'data.friend_url'));
                $remote = (string)Http::pick($data, 'data.application_id');
                $gross = Http::pick($data, 'data.reward');
                if (!is_numeric($gross) || (float)$gross <= 0 || (float)$gross > 2000000) {
                    throw new \RuntimeException('Phần thưởng job không hợp lệ.');
                }
                $reward = $reward > 0 ? $reward : intdiv((int)$gross * $share, 100);
            } else {
                $vars['alias'] = substr($token, 0, 20);
                $remoteRequested = true;
                $data = Client::call($provider, $def['shorten'], $vars);
                $link = Client::safeLink($provider, Http::any($data, $def['shorten']['result']));
                $remote = (string)(Http::any($data, $def['shorten']['code'] ?? []) ?? basename((string)parse_url($link, PHP_URL_PATH)));
                if ($provider === 'yeujob') {
                    if ($remote === '') {
                        throw new \RuntimeException('Link YeuJob thiếu mã nhiệm vụ.');
                    }
                    $remote = 'v2:' . $remote;
                }
                if (isset($data['destination']) && $data['destination'] !== $destination) {
                    throw new \RuntimeException('Link cũ có URL đích khác.');
                }
                if (!empty($data['reused'])) {
                    throw new \RuntimeException('Không sử dụng lại link đã có.');
                }
            }
            if ($remote === '' || strlen($remote) > 100 || $reward <= 0 || $reward > Settings::getInt('postback_max_reward_vnd', 2000000)) {
                throw new \RuntimeException('Mã nhiệm vụ hoặc phần thưởng không hợp lệ.');
            }
            Database::run("UPDATE provider_attempts SET status = 'pending', remote_id = ?, short_url = ?, reward_vnd = ? WHERE token = ? AND status = 'creating'", [$remote, $link, $reward, $token]);
            return ['token' => $token, 'redirect' => $link];
        } catch (\Throwable $e) {
            try {
                Database::run("UPDATE provider_attempts SET status = ? WHERE token = ? AND status = 'creating'", [$remoteRequested ? 'failed' : 'creation_failed', $token]);
            } catch (\Throwable $cleanupError) {
                // Cleanup is best-effort; never replace the original provider/SQL failure.
                error_log('[provider start] failed-state cleanup: ' . $cleanupError->getMessage());
            }
            // Before submission these runtime messages come from our validation/client,
            // never raw response bodies. Keep SQL and unexpected errors private.
            $message = !$remoteRequested && get_class($e) === \RuntimeException::class
                ? 'Chưa gửi yêu cầu nhận job: ' . $e->getMessage()
                : 'Không tạo được nhiệm vụ. Vui lòng thử lại sau.';
            throw new \RuntimeException($message, 0, $e);
        }
    }

    public static function returned(int $userId, string $token): void
    {
        $row = Database::one('SELECT * FROM provider_attempts WHERE token = ? AND user_id = ?', [$token, $userId]);
        if ($row === null || $row['status'] !== 'pending' || $row['expires_at'] < now()
            || !hash_equals($row['device_hash'], self::deviceHash()) || $row['ip'] !== client_ip()
            || time() - strtotime($row['created_at']) < (int)$row['min_seconds']) {
            throw new \RuntimeException('Lượt quay lại không hợp lệ, đã hết hạn hoặc quá nhanh.');
        }
        Database::run('UPDATE provider_attempts SET returned_at = ? WHERE token = ? AND returned_at IS NULL', [now(), $token]);
    }

    public static function poll(int $userId, string $token): bool
    {
        $row = Database::one('SELECT * FROM provider_attempts WHERE token = ? AND user_id = ?', [$token, $userId]);
        if ($row === null || $row['status'] !== 'pending' || $row['expires_at'] < now()) {
            return false;
        }
        $def = Catalog::get($row['provider']);
        if ($row['provider'] === 'yeujob') {
            if (str_starts_with($row['remote_id'], 'v2:')) {
                return false; // V2 redirects are not payment proof; admin review only.
            }
            $data = Client::call('yeujob', $def['job']['status'], ['app_id' => $row['remote_id']]);
            if (in_array(Http::pick($data, 'data.status'), ['rejected', 'cancelled'], true)) {
                Database::run("UPDATE provider_attempts SET status = 'rejected' WHERE token = ? AND status = 'pending'", [$token]);
                return false;
            }
            if (Http::pick($data, 'data.status') !== 'approved' || Http::pick($data, 'data.reward_status') !== 'paid') {
                return false;
            }
            if (isset($data['data']['application_id']) && (string)$data['data']['application_id'] !== $row['remote_id']) {
                return false;
            }
            $gross = Http::pick($data, 'data.reward');
            if (!is_numeric($gross) || (float)$gross < (int)$row['reward_vnd']) {
                return false;
            }
        } elseif ($row['provider'] === 'traffic24h') {
            if ($row['returned_at'] === null) {
                return false;
            }
            $data = Client::call('traffic24h', $def['status'], ['code' => $row['remote_id']]);
            // Link is unique to this attempt; held/raw views are never proof.
            if (($data['slug'] ?? null) !== $row['remote_id'] || ($data['target_url'] ?? null) !== $row['destination']
                || !is_numeric($data['views_valid'] ?? null) || (int)$data['views_valid'] < 1) {
                return false;
            }
        } else {
            return false; // No documented authenticated per-attempt payment proof.
        }
        return self::credit($token, 'provider_api');
    }

    /** Atomic state transition and wallet credit, with stable ledger deduplication. */
    public static function credit(string $token, string $proof, ?string $provider = null): bool
    {
        $ownsTransaction = !Database::inTransaction();
        if ($ownsTransaction) {
            Database::begin();
        }
        try {
            $lock = Database::isSqlite() ? '' : ' FOR UPDATE';
            $row = Database::one('SELECT * FROM provider_attempts WHERE token = ?' . $lock, [$token]);
            if ($row === null || $row['status'] !== 'pending' || $row['expires_at'] < now()
                || ($row['provider'] === 'yeujob' && str_starts_with($row['remote_id'], 'v2:') && $proof !== 'admin_review')
                || ($provider !== null && $row['provider'] !== $provider)
                || time() - strtotime($row['created_at']) < (int)$row['min_seconds']) {
                if ($ownsTransaction) {
                    Database::rollback();
                }
                return false;
            }
            $user = Database::one('SELECT status, risk_flag FROM users WHERE id = ?' . $lock, [$row['user_id']]);
            if ($user === null || $user['status'] !== 'active' || (int)$user['risk_flag'] !== 0) {
                if ($ownsTransaction) {
                    Database::rollback();
                }
                return false;
            }
            Wallet::post((int)$row['user_id'], Wallet::TYPE_TASK_REWARD, (int)$row['reward_vnd'], [
                'trans_id' => 'pv:' . $row['id'], 'source' => $row['provider'],
                'offer_name' => $row['service'], 'note' => $proof,
            ]);
            Database::run("UPDATE provider_attempts SET status = 'credited', credited_at = ? WHERE id = ?", [now(), $row['id']]);
            if ($ownsTransaction) {
                Database::commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                Database::rollback();
            }
            throw $e;
        }
    }
}