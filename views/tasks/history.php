<div class="page-head"><h2>Lịch sử nhiệm vụ</h2><a href="/tasks">← Danh sách nhiệm vụ</a></div>
<?php if ($providerAttempts): ?>
<div class="card">
  <h3>Lượt nhiệm vụ gần đây</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Nhiệm vụ</th><th>Thưởng</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
    <tbody>
  <?php foreach ($providerAttempts as $attempt):
    $expired = $attempt['status'] === 'pending' && $attempt['expires_at'] < now();
    $status = $expired ? 'expired' : $attempt['status'];
    $manualV2 = $attempt['provider'] === 'xtask' || ($attempt['provider'] === 'yeujob' && str_starts_with((string)($attempt['remote_id'] ?? ''), 'v2:'));
    $labels = ['creating' => 'Đang tạo', 'pending' => $manualV2 ? (empty($attempt['returned_at']) ? 'Đang làm nhiệm vụ' : 'Chờ admin duyệt') : 'Chờ xác nhận', 'credited' => 'Đã cộng thưởng', 'rejected' => 'Đã từ chối', 'failed' => 'Tạo thất bại', 'creation_failed' => 'Chưa tạo được', 'expired' => 'Hết hạn']; ?>
    <tr><td>#<?= (int)$attempt['id'] ?> · <?= e($providers[$attempt['provider']]['label'] ?? $attempt['provider']) ?><br><span class="muted"><?= e($attempt['service']) ?> · <?= e($attempt['created_at']) ?></span></td>
    <td><?= vnd($attempt['reward_vnd']) ?></td>
    <td><span class="pill <?= $status === 'credited' ? 'ok' : ($status === 'pending' ? 'pending' : 'warn') ?>"><?= e($labels[$status] ?? $status) ?></span></td><td>
    <?php if ($status === 'pending'): ?>
      <form method="post" action="/tasks/provider/verify">
        <?php if (!$manualV2): ?>
          <?= \App\Csrf::field() ?>
          <input type="hidden" name="token" value="<?= e($attempt['token']) ?>">
          <button class="btn" type="submit">Kiểm tra xác nhận</button>
        <?php else: ?><span class="hint">Hoàn thành job rồi chờ admin đối chiếu; quay lại không tự cộng tiền.</span><?php endif; ?>
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
  <h3>Lượt mở nhiệm vụ PubCrypto gần đây</h3>
  <p class="hint">Lượt mở không xác nhận hoàn thành hay trả thưởng. Kiểm tra tiền đã nhận trong lịch sử ví.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Nhiệm vụ</th><th>Thời điểm mở</th></tr></thead>
    <tbody><?php foreach ($taskClicks as $click): ?>
      <tr><td><?= e($click['task_name']) ?></td><td><?= e($click['created_at']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
<?php if (!$providerAttempts && empty($taskClicks)): ?><div class="card"><p class="muted">Chưa có lượt nhiệm vụ.</p></div><?php endif; ?>
