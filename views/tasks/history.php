<div class="page-head"><div><span class="eyebrow">Activity / Task history</span><h2>Lịch sử nhiệm vụ</h2><p>Theo dõi các lượt mở, xác minh và phần thưởng đã ghi nhận.</p></div><a class="btn ghost" href="/tasks">Danh sách nhiệm vụ <span aria-hidden="true">↗</span></a></div>
<?php if ($providerAttempts): ?>
<div class="card">
  <div class="section-head"><h3>Task2 · Lượt nhiệm vụ gần đây</h3><span class="mono muted">HTXG MANAGED</span></div>
  <div class="table-wrap"><table class="responsive-table">
    <thead><tr><th>Nhiệm vụ</th><th>Thưởng</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
    <tbody>
  <?php foreach ($providerAttempts as $attempt):
    $expired = $attempt['status'] === 'pending' && $attempt['expires_at'] < now();
    $status = $expired ? 'expired' : $attempt['status'];
    $manualReview = ($providers[$attempt['provider']]['verify'] ?? '') === 'manual';
    $labels = ['creating' => 'Đang tạo', 'pending' => $manualReview ? (empty($attempt['returned_at']) ? 'Đang làm nhiệm vụ' : 'Chờ xác minh') : 'Chờ xác nhận', 'credited' => 'Đã cộng thưởng', 'rejected' => 'Đã từ chối', 'failed' => 'Tạo thất bại', 'creation_failed' => 'Chưa tạo được', 'expired' => 'Hết hạn']; ?>
    <tr><td data-label="Nhiệm vụ">#<?= (int)$attempt['id'] ?> · <?= e($providers[$attempt['provider']]['label'] ?? $attempt['provider']) ?><br><span class="muted"><?= e($attempt['service']) ?> · <?= e($attempt['created_at']) ?></span></td>
    <td data-label="Thưởng"><?= vnd($attempt['reward_vnd']) ?></td>
    <td data-label="Trạng thái"><span class="pill <?= $status === 'credited' ? 'ok' : ($status === 'pending' ? 'pending' : (in_array($status, ['failed', 'creation_failed', 'rejected'], true) ? 'danger' : 'warn')) ?>"><?= e($labels[$status] ?? $status) ?></span></td><td data-label="Thao tác">
    <?php if ($status === 'pending'): ?>
      <form method="post" action="/tasks/provider/verify">
        <?= \App\Csrf::field() ?>
        <?php if (!$manualReview): ?>
          <input type="hidden" name="token" value="<?= e($attempt['token']) ?>">
          <button class="btn" type="submit">Kiểm tra xác nhận</button>
        <?php else: ?><span class="hint">Hoàn thành job rồi chờ xác minh; quay lại không tự cộng tiền.</span><?php endif; ?>
        <?php if ($attempt['short_url']): ?><a href="<?= e($attempt['short_url']) ?>" target="_blank" rel="noopener noreferrer">Tiếp tục nhiệm vụ</a><?php endif; ?>
      </form>
    <?php endif; ?>
    </td></tr>
  <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
<?php if (!empty($taskClicks)): ?>
<div class="card">
  <div class="section-head"><h3>Task1 · Lượt mở PubCrypto gần đây</h3><span class="mono muted">OFFICIAL LINKS</span></div>
  <p class="hint">Lượt mở không xác nhận hoàn thành hay trả thưởng. Kiểm tra tiền đã nhận trong lịch sử ví.</p>
  <div class="table-wrap"><table class="responsive-table">
    <thead><tr><th>Nhiệm vụ</th><th>Thời điểm mở</th></tr></thead>
    <tbody><?php foreach ($taskClicks as $click): ?>
      <tr><td data-label="Nhiệm vụ"><?= e($click['task_name']) ?></td><td data-label="Thời điểm mở"><time><?= e($click['created_at']) ?></time></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
<?php if (!$providerAttempts && empty($taskClicks)): ?><div class="card"><p class="muted">Chưa có lượt nhiệm vụ.</p></div><?php endif; ?>
