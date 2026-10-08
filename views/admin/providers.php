<div class="card">
  <h2>Duyệt nhiệm vụ provider</h2>
  <p><a href="/admin/settings">Cấu hình nguồn &amp; thù lao</a></p>
  <p class="hint">Chỉ duyệt sau khi đối chiếu mã application/link với tài khoản provider và doanh thu hợp lệ. Quay lại, screenshot hoặc timer không phải bằng chứng trả thưởng.</p>
  <?php foreach ($attempts as $attempt): ?>
    <fieldset>
      <legend>#<?= (int)$attempt['id'] ?> · User <?= (int)$attempt['user_id'] ?> · <?= e($attempt['provider']) ?></legend>
      <p><?= e($attempt['service']) ?> · <?= vnd($attempt['reward_vnd']) ?> · <?= e($attempt['status']) ?></p>
      <p>Mã provider: <?= e($attempt['remote_id']) ?> · IP: <?= e($attempt['ip']) ?> · Bắt đầu: <?= e($attempt['created_at']) ?> · Quay lại: <?= e($attempt['returned_at'] ?? 'Chưa') ?></p>
      <?php if ($attempt['status'] === 'pending'): ?>
        <form method="post" action="/admin/providers" class="stack">
          <?= \App\Csrf::field() ?>
          <input type="hidden" name="token" value="<?= e($attempt['token']) ?>">
          <label>Bằng chứng đối chiếu / lý do<input name="note" minlength="10" maxlength="300" required></label>
          <button class="btn" name="action" value="approve">Duyệt và cộng thưởng</button>
          <button class="btn" name="action" value="reject">Từ chối</button>
        </form>
      <?php endif; ?>
    </fieldset>
  <?php endforeach; ?>
</div>