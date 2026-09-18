<?php
/** Telegram webhook manager.
 *  Set:  php bin/telegram-webhook.php set <https-url>/api/telegram/webhook
 *  Del:  php bin/telegram-webhook.php remove
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Telegram;

if ($argc < 2) {
    fwrite(STDERR, "Usage:\n  php bin/telegram-webhook.php set <url>/api/telegram/webhook\n  php bin/telegram-webhook.php remove\n");
    exit(1);
}
if (!Telegram::enabled()) {
    fwrite(STDERR, "Chưa cấu hình telegram.bot_token / bot_username trong config.php\n");
    exit(1);
}
if ($argv[1] === 'set') {
    $url = trim($argv[2] ?? '');
    if (!str_starts_with($url, 'https://')) {
        fwrite(STDERR, "Webhook URL phải là HTTPS.\n");
        exit(1);
    }
    $r = Telegram::setWebhook($url);
    echo "HTTP {$r['http']}: {$r['resp']}\n";
    exit($r['http'] === 200 ? 0 : 1);
}
if ($argv[1] === 'remove') {
    echo "Webhook đã yêu cầu xóa (thêm method remove trong Telegram::setWebhook nếu cần).\n";
    exit(0);
}
fwrite(STDERR, "Unknown action.\n");
exit(1);
