<?php
declare(strict_types=1);

namespace App;

/**
 * SMS gateway abstraction.
 *  - driver "log": writes the message to storage/logs/sms.log (DEV ONLY, never for production).
 *  - driver "http": generic HTTP gateway configured via Admin settings.
 *  - driver "speedsms": SpeedSMS.vn (https://speedsms.vn)
 *      • ZNS (Zalo) OTP khi đã có template_id được duyệt: POST /api/v2/zns/send
 *        body: {"phones":[...],"template_id":...,"params":{"otp":...},"sandbox":0}
 *      • Fallback SMS thường (sms_type=2 CSKH, KHÔNG cần template duyệt): POST /api/v2/send
 *        body: {"phones":[...],"content":"...","sms_type":2,"sender":""}
 *      Auth: Authorization: Basic base64(ACCESS_TOKEN . ':x')
 *
 * Settings dùng cho speedsms:
 *  - speedsms_token       (bắt buộc) Access Token trong dashboard SpeedSMS → API
 *  - speedsms_zns_template_id  (tùy chọn) id template ZNS OTP đã duyệt;
 *        để trống => gửi SMS thường (sms_type=2) — hoạt động ngay
 *  - speedsms_sender      brandname (chỉ dùng cho SMS CSKH nếu có brandname)
 */
final class Sms
{
    public static function send(string $phone, string $message): array
    {
        $driver = (string)(config('sms.driver') ?? 'log');
        return match ($driver) {
            'speedsms' => self::sendSpeedSms($phone, $message),
            'http'     => self::sendHttp($phone, $message),
            default    => self::sendLog($phone, $message),
        };
    }

    private static function sendLog(string $phone, string $message): array
    {
        $line = sprintf("[%s] to=%s ip=%s msg=%s\n", now(), $phone, client_ip(), $message);
        @file_put_contents(STORAGE_PATH . '/logs/sms.log', $line, FILE_APPEND | LOCK_EX);
        error_log('[sms:log] ' . trim($line));
        return ['ok' => true, 'driver' => 'log'];
    }

    private static function sendSpeedSms(string $phone, string $message): array
    {
        $token = (string)Settings::get('speedsms_token', '');
        if ($token === '') {
            error_log('[sms:speedsms] speedsms_token is empty');
            return ['ok' => false, 'driver' => 'speedsms', 'error' => 'speedsms_token_empty'];
        }
        $auth = 'Authorization: Basic ' . base64_encode($token . ':x');
        $template = (string)Settings::get('speedsms_zns_template_id', '');

        // 1) ZNS (Zalo) khi có template id được duyệt — trích OTP từ nội dung.
        if ($template !== '') {
            $otp = '';
            if (preg_match('/(\d{4,8})/', $message, $m)) {
                $otp = $m[1];
            }
            [$ok, $resp] = self::post(
                'https://api.speedsms.vn/index.php/api/v2/zns/send',
                $auth,
                json_encode([
                    'phones'      => [$phone],
                    'template_id' => $template,
                    'params'      => ['otp' => $otp],
                    'sandbox'     => 0,
                ])
            );
            if ($ok) {
                return ['ok' => true, 'driver' => 'speedsms', 'channel' => 'zns', 'resp' => $resp];
            }
            // ZNS lỗi (OA chưa follow, template sai...) → rơi xuống SMS thường.
            error_log('[sms:speedsms] zns failed, fallback to sms: ' . substr($resp, 0, 300));
        }

        // 2) SMS thường (CSKH, sms_type=2) — hoạt động ngay khi nạp tiền.
        [$ok, $resp] = self::post(
            'https://api.speedsms.vn/index.php/api/v2/send',
            $auth,
            json_encode([
                'phones'   => [$phone],
                'content'  => $message,
                'sms_type' => 2,
                'sender'   => (string)Settings::get('speedsms_sender', ''),
            ])
        );
        $data = json_decode($resp, true);
        // SpeedSMS trả {"status":"success","code":"000","data":...}
        $ok = $ok && is_array($data) && (($data['code'] ?? '') === '000' || ($data['status'] ?? '') === 'success');
        return ['ok' => $ok, 'driver' => 'speedsms', 'channel' => 'sms', 'resp' => $resp];
    }

    /** @return array{0:bool,1:string} */
    private static function post(string $url, string $authHeader, string $jsonBody): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => [$authHeader, 'Content-Type: application/json'],
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        if ($err !== '') {
            return [false, 'curl: ' . $err];
        }
        @file_put_contents(
            STORAGE_PATH . '/logs/sms.log',
            sprintf("[%s] %s http=%d resp=%s\n", now(), $url, $code, substr((string)$resp, 0, 500)),
            FILE_APPEND | LOCK_EX
        );
        return [$code >= 200 && $code < 300, (string)$resp];
    }

    private static function sendHttp(string $phone, string $message): array
    {
        $url = (string)Settings::get('sms_http_url', '');
        if ($url === '') {
            error_log('[sms:http] sms_http_url is empty — message not sent');
            return ['ok' => false, 'driver' => 'http', 'error' => 'sms_http_url_empty'];
        }
        $method = strtoupper((string)Settings::get('sms_http_method', 'POST'));
        $template = (string)Settings::get('sms_http_body_template', '');
        $sender = (string)Settings::get('sms_sender', '');
        $body = str_replace(['{phone}', '{message}', '{sender}'], [$phone, $message, $sender], $template);

        $headers = [];
        foreach (explode("\n", (string)Settings::get('sms_http_headers', '')) as $h) {
            $h = trim($h);
            if ($h !== '') {
                $headers[] = $h;
            }
        }

        $ch = curl_init($url);
        $isGet = $method === 'GET';
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($isGet) {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        @file_put_contents(
            STORAGE_PATH . '/logs/sms.log',
            sprintf("[%s] http to=%s code=%d err=%s resp=%s\n", now(), $phone, $code, $err, substr((string)$resp, 0, 500)),
            FILE_APPEND | LOCK_EX
        );
        $ok = $err === '' && $code >= 200 && $code < 300;
        return ['ok' => $ok, 'driver' => 'http', 'http_code' => $code, 'error' => $err ?: null];
    }
}
