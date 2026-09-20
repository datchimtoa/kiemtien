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
            if ($payload !== '' && Telegram::handleStart($payload, $from)) {
                Telegram::sendMessage(
                    $chatId,
                    "✅ Xác thực thành công!\n\nQuay lại trang web và bấm \"Tôi đã xác thực\" để tiếp tục đăng ký."
                );
            } else {
                Telegram::sendMessage(
                    $chatId,
                    "❌ Link xác thực không hợp lệ hoặc đã hết hạn (15 phút).\nVui lòng quay lại trang web và tạo link mới."
                );
            }
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
