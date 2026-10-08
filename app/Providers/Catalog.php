<?php
declare(strict_types=1);

namespace App\Providers;

/**
 * Danh mục 10 provider (nguồn nhiệm vụ) theo tài liệu add.md.
 *
 * MỌI thứ phụ thuộc môi trường (API key, bật/tắt, thù lao trả member, hạn mức/ngày,
 * URL đích) đều nằm trong Settings (Admin → Cấu hình hệ thống) — KHÔNG hardcode:
 *   provider_<id>_enabled         '0' | '1'
 *   provider_<id>_api_key         key do provider cấp
 *   provider_<id>_reward_vnd      thù lao cố định trả member mỗi lượt (VND)
 *   provider_<id>_share_percent   % chia khi provider trả về số tiền thật (job model)
 *   provider_<id>_daily_limit     số lượt tối đa / member / ngày
 *   provider_<id>_targets         danh sách URL đích, mỗi dòng 1 URL (shortlink model)
 *   provider_<id>_min_seconds     thời gian giữ tối thiểu trước khi được tính (verify=return)
 *   provider_<id>_ip_daily_limit  giới hạn số lượt / IP / 24h
 *
 *   provider_<id>_alias           alias/slug tùy chọn (link999, site2s, traffic24h)
 *   provider_<id>_sub_link        link dự phòng (traffictop)
 *   provider_<id>_fallback_url    link dự phòng (trafficvn)
 *
 * kind   : 'shortlink' (rút gọn link, provider trả theo view) | 'job' (nhận job có trạng thái)
 * verify : 'return' (member quay lại /task/return/<token>) | 'poll' (tra cứu trạng thái)
 *
 * Browser returns are NOT payment proof. Only authenticated server verification
 * or explicit audited admin approval may authorize a credit.
 */
final class Catalog
{
    /** @return array<string,array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            // 1. YeuJob (yeujob.com)
            'yeujob' => [
                'label'  => 'YeuJob (yeujob.com)',
                'kind'   => 'shortlink',
                'verify' => 'manual',
                'base'   => 'https://yeujob.com',
                'docs'   => 'API V2 /st → shortenedUrl → làm job → quay lại chờ admin đối chiếu và duyệt thưởng. Không tự cộng tiền.',
                'services' => [
                    ['id' => 'friend', 'label' => 'Nhận job Review Map / VIP'],
                ],
                'target_label' => 'URL quay lại được tạo tự động; admin duyệt thưởng cố định VND/lượt.',
                'shorten' => [
                    'method' => 'GET',
                    'url' => 'https://yeujob.com/st',
                    'user_agent' => 'EarnMoneyVIP/1.0 (YeuJob API client)',
                    'headers' => ['Accept: application/json'],
                    'query' => ['api' => '{key}', 'url' => '{url}'],
                    'ok' => 'success',
                    'result' => ['shortenedUrl', 'data.shortenedUrl'],
                ],
                // Retained only to verify already-created V1 attempts.
                'job' => [
                    'list' => [
                        'method'  => 'GET',
                        'url'     => 'https://yeujob.com/api/v1?action=friend_jobs&page=1&limit=20',
                        'headers' => ['Authorization: Bearer {key}'],
                        'items'   => 'data',
                        'id'      => 'id',
                        'slots'   => 'slots_left',
                        'ok'      => 'success',
                        'error'   => 'message',
                    ],
                    'accept' => [
                        'method'  => 'POST',
                        'url'     => 'https://yeujob.com/api/v1',
                        'headers' => ['Authorization: Bearer {key}'],
                        'body'    => ['action' => 'accept_friend_job', 'job_id' => '{job_id}'],
                        'ok'      => ['path' => 'success', 'values' => [true, 'true', 1, '1']],
                        'app_id'  => 'data.application_id',
                        'link'    => 'data.friend_url',
                        'reward'  => 'data.reward',
                        'error'   => 'message',
                    ],
                    'status' => [
                        'method'  => 'GET',
                        'url'     => 'https://yeujob.com/api/v1?action=friend_job_status&application_id={app_id}',
                        'headers' => ['Authorization: Bearer {key}'],
                        'state'   => 'data.status',
                        'paid'    => 'data.reward_status',
                        'reward'  => 'data.reward',
                        'error'   => 'message',
                    ],
                    'paid_values'  => ['paid'],
                    'done_values'  => ['approved'],
                    'fail_values'  => ['rejected', 'cancelled'],
                ],
                'defaults' => [
                    'reward_vnd'     => 0, // Admin must configure a positive fixed V2 reward.
                    'share_percent'  => 70,
                    'daily_limit'    => 10,
                    'min_seconds'    => 30,
                    'ip_daily_limit' => 3,
                ],
            ],

            // 2. XTASK (xtask.top)
            'xtask' => [
                'label'  => 'XTASK (xtask.top)',
                'kind'   => 'shortlink',
                'verify' => 'poll',
                'base'   => 'https://xtask.top',
                'auth'   => 'bearer',
                'docs'   => 'Tạo link: POST /api/public/v1/shorten (JSON). Tra trạng thái: /api/public/v1/status/{code}.',
                'services' => [
                    ['id' => 'traffic',            'label' => 'Traffic Google',      'type' => 'traffic'],
                    ['id' => 'direct',             'label' => 'Traffic Direct',      'type' => 'direct'],
                    ['id' => 'backlink',           'label' => 'Traffic Backlink',    'type' => 'backlink'],
                    ['id' => 'map_review',         'label' => 'Review Google Map',   'type' => 'map_review'],
                    ['id' => 'map_report',         'label' => 'Tố cáo Review Maps',  'type' => 'map_report'],
                    ['id' => 'tripadvisor_review', 'label' => 'Review TripAdvisor',  'type' => 'tripadvisor_review'],
                    ['id' => 'map_traffic',        'label' => 'Traffic User Map',    'type' => 'map_traffic'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ nếu để trống)',
                'shorten' => [
                    'method'    => 'POST',
                    'url'       => 'https://xtask.top/api/public/v1/shorten',
                    'headers'   => ['Authorization: Bearer {key}', 'Content-Type: application/json'],
                    'json_body' => ['type' => '{service}', 'url' => '{url}', 'title' => '{title}'],
                    'ok'        => ['path' => 'success', 'values' => [true, 'true', 1, '1']],
                    'result'    => ['data.shortUrl', 'data.url'],
                    'code'      => ['data.shortCode', 'data.id'],
                    'error'     => ['message', 'error'],
                ],
                'status' => [
                    'method'  => 'GET',
                    'url'     => 'https://xtask.top/api/public/v1/status/{code}',
                    'headers' => ['Authorization: Bearer {key}'],
                    'views'   => 'views',
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],

            // 3. TrafficTop (traffictop.net)
            'traffictop' => [
                'label'  => 'TrafficTop (traffictop.net)',
                'kind'   => 'shortlink',
                'verify' => 'return',
                'base'   => 'https://traffictop.net',
                'auth'   => 'api',
                'docs'   => 'Rút gọn link: GET /api?api={key}&url={url}&sub_link={sub_link}. Header Bearer hỗ trợ.',
                'services' => [
                    ['id' => 'default', 'label' => 'Rút gọn link tiêu chuẩn'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ)',
                'shorten' => [
                    'method'  => 'GET',
                    'url'     => 'https://traffictop.net/api',
                    'query'   => ['api' => '{key}', 'url' => '{url}', 'sub_link' => '{sub_link}'],
                    'headers' => ['Authorization: Bearer {key}'],
                    'ok'      => ['path' => 'status', 'values' => ['success', true, 1]],
                    'result'  => ['shortenedUrl'],
                    'error'   => ['message'],
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],

            // 4. Vượt Nhanh (bbmkts.com)
            'bbmkts' => [
                'label'  => 'Vượt Nhanh (bbmkts.com)',
                'kind'   => 'shortlink',
                'verify' => 'return',
                'base'   => 'https://bbmkts.com',
                'auth'   => 'token',
                'docs'   => 'Rút gọn liên kết qua bbmkts.com / dapi?token={key}&longurl={url}.',
                'services' => [
                    ['id' => 'default', 'label' => 'Rút gọn link tiêu chuẩn'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ)',
                'shorten' => [
                    'method' => 'GET',
                    'url'    => 'https://bbmkts.com/dapi',
                    'query'  => ['token' => '{key}', 'longurl' => '{url}'],
                    'ok'     => ['path' => 'status', 'values' => ['success', true, 1]],
                    'result' => ['bbmktsUrl'],
                    'error'  => ['message'],
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],

            // 5. SiteTop (sitetop.net)
            'sitetop' => [
                'label'  => 'SiteTop (sitetop.net)',
                'kind'   => 'shortlink',
                'verify' => 'return',
                'base'   => 'https://sitetop.net',
                'auth'   => 'api',
                'docs'   => 'Rút gọn link: GET /api?url={url} với header Authorization: Bearer {key}.',
                'services' => [
                    ['id' => 'default', 'label' => 'Rút gọn link tiêu chuẩn'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ)',
                'shorten' => [
                    'method'  => 'GET',
                    'url'     => 'https://sitetop.net/api',
                    'query'   => ['api' => '{key}', 'url' => '{url}'],
                    'headers' => ['Authorization: Bearer {key}'],
                    'ok'      => ['path' => 'status', 'values' => ['success', true, 1]],
                    'result'  => ['shortenedUrl'],
                    'error'   => ['message'],
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],

            // 6. TrafficVN (trafficvn.com)
            'trafficvn' => [
                'label'  => 'TrafficVN (trafficvn.com)',
                'kind'   => 'shortlink',
                'verify' => 'return',
                'base'   => 'https://trafficvn.com',
                'auth'   => 'api',
                'docs'   => 'Rút gọn link: GET /apidevelop?api={key}&url={url}&fallback_url={fallback_url}.',
                'services' => [
                    ['id' => 'default', 'label' => 'Rút gọn link tiêu chuẩn'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ)',
                'shorten' => [
                    'method' => 'GET',
                    'url'    => 'https://trafficvn.com/apidevelop',
                    'query'  => ['api' => '{key}', 'url' => '{url}', 'fallback_url' => '{fallback_url}'],
                    'ok'     => ['path' => 'status', 'values' => ['success', true, 1]],
                    'result' => ['shortenedUrl'],
                    'error'  => ['message'],
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],

            // 7. TrafficUserr (trafficuserr.com)
            'trafficuserr' => [
                'label'  => 'TrafficUserr (trafficuserr.com)',
                'kind'   => 'shortlink',
                'verify' => 'return',
                'base'   => 'https://www.trafficuserr.com',
                'auth'   => 'api_key',
                'docs'   => 'Rút gọn: GET /service/shorten?api_key={key}&url={url}.',
                'services' => [
                    ['id' => 'default', 'label' => 'Rút gọn link tiêu chuẩn'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ)',
                'shorten' => [
                    'method' => 'GET',
                    'url'    => 'https://www.trafficuserr.com/service/shorten',
                    'query'  => ['api_key' => '{key}', 'url' => '{url}'],
                    'ok'     => ['path' => 'status', 'values' => [true, 'true', 1, '1']],
                    'result' => ['message'],
                    'error'  => ['message'],
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],

            // 8. Link999 (link999.app)
            'link999' => [
                'label'  => 'Link999 (link999.app)',
                'kind'   => 'shortlink',
                'verify' => 'return',
                'base'   => 'https://link999.app',
                'auth'   => 'api',
                'docs'   => 'Rút gọn: GET /api?api={key}&url={url}&alias={alias}&type={type}.',
                'services' => [
                    ['id' => 'default',       'label' => 'Mặc định (Quảng cáo tiêu chuẩn)', 'type' => ''],
                    ['id' => 'social',        'label' => 'Chiến dịch Social (&type=1)',    'type' => '1'],
                    ['id' => 'google_search', 'label' => 'Google Search (&type=2)',         'type' => '2'],
                    ['id' => 'google_3step',  'label' => 'Google Search 3 Bước (&type=3)',  'type' => '3'],
                    ['id' => 'google_2step',  'label' => 'Google Search 2 Bước (&type=4)',  'type' => '4'],
                    ['id' => 'google_4step',  'label' => 'Google Search 4 Bước (&type=5)',  'type' => '5'],
                    ['id' => 'no_ads',        'label' => 'Không quảng cáo (&type=0)',       'type' => '0'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ)',
                'shorten' => [
                    'method' => 'GET',
                    'url'    => 'https://link999.app/api',
                    'query'  => ['api' => '{key}', 'url' => '{url}', 'alias' => '{alias}', 'type' => '{service_type}'],
                    'ok'     => ['path' => 'status', 'values' => ['success', true, 1]],
                    'result' => ['shortenedUrl'],
                    'error'  => ['message'],
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],

            // 9. Site2S (site2s.com)
            'site2s' => [
                'label'  => 'Site2S (site2s.com)',
                'kind'   => 'shortlink',
                'verify' => 'return',
                'base'   => 'https://site2s.com',
                'auth'   => 'api',
                'docs'   => 'Rút gọn: GET /api?api={key}&url={url}&alias={alias}&type={type}. Tra cứu views: /{slug}/info/json.',
                'services' => [
                    ['id' => 'default',      'label' => 'Mặc định',                        'type' => ''],
                    ['id' => 'interstitial', 'label' => 'Quảng cáo xen kẽ (&type=1)',     'type' => '1'],
                    ['id' => 'no_ads',       'label' => 'Không quảng cáo (&type=0)',       'type' => '0'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ)',
                'shorten' => [
                    'method' => 'GET',
                    'url'    => 'https://site2s.com/api',
                    'query'  => ['api' => '{key}', 'url' => '{url}', 'alias' => '{alias}', 'type' => '{service_type}'],
                    'ok'     => ['path' => 'status', 'values' => ['success', true, 1]],
                    'result' => ['shortenedUrl'],
                    'error'  => ['message'],
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],

            // 10. Traffic24h (traffic24h.top)
            'traffic24h' => [
                'label'  => 'Traffic24h (traffic24h.top)',
                'kind'   => 'shortlink',
                'verify' => 'poll',
                'base'   => 'https://traffic24h.top',
                'auth'   => 'X-API-Key',
                'docs'   => 'Rút gọn: GET /api/quicklink/api?url={url}&alias={alias}&api={key}. Tra link: /api/publisher/v1/links/{slug}.',
                'services' => [
                    ['id' => 'default', 'label' => 'Rút gọn link tiêu chuẩn'],
                ],
                'target_label' => 'URL đích (mặc định lấy trang chủ)',
                'shorten' => [
                    'method'  => 'GET',
                    'url'     => 'https://traffic24h.top/api/quicklink/api',
                    'query'   => ['url' => '{url}', 'alias' => '{alias}', 'api' => '{key}'],
                    'headers' => ['X-API-Key: {key}'],
                    'ok'      => ['path' => 'status', 'values' => ['success', true, 1]],
                    'result'  => ['shortenedUrl'],
                    'code'    => ['slug'],
                    'error'   => ['message'],
                ],
                'status' => [
                    'method'  => 'GET',
                    'url'     => 'https://traffic24h.top/api/publisher/v1/links/{code}',
                    'headers' => ['X-API-Key: {key}'],
                    'views'   => 'views_valid',
                ],
                'defaults' => [
                    'reward_vnd'     => 400,
                    'daily_limit'    => 20,
                    'min_seconds'    => 15,
                    'ip_daily_limit' => 1,
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function get(string $id): ?array
    {
        return self::definitions()[$id] ?? null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::definitions());
    }
}
