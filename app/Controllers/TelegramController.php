<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Telegram;

/**
 * Receives Telegram bot updates. Only /start with a valid payload token matters;
 * everything else gets a friendly hint. Always answer 200 so Telegram stops retrying.
 */
final class TelegramController
{
    public static function webhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $update = json_decode($raw, true);
        if (!is_array($update)) {
            http_response_code(200);
            echo 'ok';
            return;
        }
        $message = $update['message'] ?? null;
        $chatId = (string)($message['chat']['id'] ?? '');
        $text = (string)($message['text'] ?? '');
        $from = $message['from'] ?? [];

        if ($chatId === '' || $from === []) {
            http_response_code(200);
            echo 'ok';
            return;
        }

        if (preg_match('#^/start(?:\s+(\S+))?#', $text, $m)) {
            $payload = $m[1] ?? '';
            // Trả lời theo đúng lý do (link sai / hết hạn / Telegram đã có tài khoản / ...)
            // để người dùng biết cần làm gì, thay vì luôn báo "link không hợp lệ".
            $reason = Telegram::verifyStart($payload, $from);
            error_log('[telegram] /start token=' . ($payload !== '' ? substr($payload, 0, 8) . '…' : '(trống)')
                . ' chat=' . $chatId . ' => ' . $reason);
            Telegram::sendMessage($chatId, Telegram::reasonMessage($reason));
            http_response_code(200);
            echo 'ok';
            return;
        }

        Telegram::sendMessage(
            $chatId,
            "Bot xác thực của HTXG.PRO.\nMở trang web → đăng ký → chọn \"Xác thực qua Telegram\" để nhận link."
        );
        http_response_code(200);
        echo 'ok';
    }
}
