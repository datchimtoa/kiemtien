<div class="card">
  <h3>🩺 Chẩn đoán hệ thống</h3>
  <p class="hint">Trang này chỉ admin xem được (không cần shell/Render Shell).</p>
  <table class="kv">
    <?php foreach ($ok as $k => $v): ?>
    <tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($err): ?>
<div class="card">
  <h3>⚠️ Lỗi phát hiện được</h3>
  <table class="kv">
    <?php foreach ($err as $k => $v): ?>
    <tr><th><?= e($k) ?></th><td style="color:#fca5a5"><?= e($v) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="hint">Gửi nguyên dòng lỗi này cho hỗ trợ — nó là lỗi gốc (không phải lỗi phụ 25P02).</p>
</div>
<?php endif; ?>

<div class="card">
  <h3>Cấu hình đang dùng</h3>
  <table class="kv">
    <tr><th>BASE_URL</th><td><code><?= e($baseUrl !== '' ? $baseUrl : '(trống)') ?></code></td></tr>
    <tr><th>Postback URL (dán vào PubCrypto)</th><td><code><?= e($baseUrl . '/postback/pubcrypto/' . (string)(config('postback_token') ?? '')) ?></code></td></tr>
    <tr><th>Telegram webhook (setWebhook)</th><td><code><?= e($baseUrl . '/api/telegram/webhook') ?></code></td></tr>
  </table>
</div>
