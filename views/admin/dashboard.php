<div class="grid4">
<?php foreach ([
  'users' => 'Tổng người dùng', 'usersToday' => 'ĐK hôm nay',
  'balanceSum' => 'Tổng số dư (VND)', 'taskRewards' => 'Tổng chi thưởng',
  'tasksToday' => 'Nhiệm vụ hôm nay', 'wdPending' => 'Rút chờ duyệt',
  'wdPaid' => 'Đã chi rút (VND)', 'postbacksToday' => 'Postback hôm nay',
] as $k => $label): ?>
  <div class="card stat"><div class="stat-label"><?= e($label) ?></div><div class="stat-val"><?= number_format((float)$stats[$k], 0, ',', '.') ?></div></div>
<?php endforeach; ?>
</div>
<div class="card">
  <h3>Yêu cầu rút đang chờ</h3>
  <?php if (!$pendingWd): ?><p class="muted">Không có.</p>
  <?php else: ?>
  <table><thead><tr><th>#</th><th>SĐT</th><th>Số tiền</th><th>Thời gian</th></tr></thead><tbody>
  <?php foreach ($pendingWd as $w): ?>
    <tr><td><?= (int)$w['id'] ?></td><td><?= e($w['phone']) ?></td><td><?= vnd((int)$w['amount_vnd']) ?></td><td><?= e($w['created_at']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<div class="card">
  <h3>Postback gần đây</h3>
  <table><thead><tr><th>transId</th><th>subId</th><th>status</th><th>Kết quả</th><th>Thời gian</th></tr></thead><tbody>
  <?php foreach ($recentPostbacks as $p): ?>
    <tr><td><?= e($p['trans_id']) ?></td><td><?= e($p['sub_id']) ?></td><td><?= (int)$p['status_raw'] ?></td><td><?= e($p['result']) ?></td><td><?= e($p['created_at']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
