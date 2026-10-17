<div class="card auth">
  <h2>Hoàn tất nhiệm vụ Link4m</h2>
  <?php if (!empty($result)): ?>
    <div class="flash <?= $result['status'] === 'verified' ? 'success' : ($result['status'] === 'hold' ? 'warn' : 'error') ?>"><?= e($result['message']) ?></div>
    <p class="muted">Điểm xác minh: <?= (int)$result['score'] ?>/100.</p>
    <p><a href="/tasks/history">Xem lịch sử nhiệm vụ</a></p>
  <?php elseif ($error): ?>
    <div class="flash error"><?= e($error) ?></div>
    <p><a href="/tasks">Quay lại nhiệm vụ</a></p>
  <?php elseif ($row): ?>
    <p class="hint">Hoàn tất bước xác minh để ghi nhận lượt làm nhiệm vụ. Kết quả xác minh không tự cộng tiền.</p>
    <?php if ($siteKey === ''): ?>
      <div class="flash error">Nhiệm vụ này chưa được cấu hình xác minh. Vui lòng thử lại sau.</div>
    <?php else: ?>
      <form method="post" action="/task/link4m/callback?t=<?= e($token) ?>&amp;sig=<?= e($signature) ?>" class="stack" data-link4m-form>
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="ev" value="0">
        <div class="cf-turnstile" data-sitekey="<?= e($siteKey) ?>"></div>
        <button class="btn" type="submit" disabled>Gửi kết quả xác minh</button>
      </form>
      <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
      <script src="/assets/link4m.js" defer></script>
    <?php endif; ?>
  <?php endif; ?>
</div>
