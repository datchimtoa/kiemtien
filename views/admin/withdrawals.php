<div class="page-head"><div><span class="eyebrow">FINANCE / PAYOUTS</span><h2>Quản lý thanh toán</h2><p>Kiểm tra yêu cầu và thông tin đối chiếu trước khi xử lý.</p></div></div>
<div class="card">
<div class="tabs">
  <?php foreach (['' => 'Tất cả', 'pending' => 'Chờ duyệt', 'completed' => 'Đã chi', 'rejected' => 'Đã từ chối'] as $s => $label): ?>
    <a class="tab <?= $status === $s ? 'active' : '' ?>" href="?status=<?= $s ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<div class="table-wrap"><table class="wide-table">
<thead><tr><th>#</th><th>SĐT</th><th>Số tiền</th><th>PT</th><th>Người nhận</th><th>STK</th><th>Uy tín</th><th>High score</th><th>Tuổi TK</th><th>IP version</th><th>Thiết bị</th><th>Trạng thái</th><th>Thời gian</th><th>Xử lý</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="14"><div class="empty-state">Không có yêu cầu rút tiền phù hợp.</div></td></tr><?php endif; ?>
<?php foreach ($rows as $w): ?>
<tr>
  <td><?= (int)$w['id'] ?></td>
  <td><?= e(display_phone($w['phone'])) ?></td>
  <td><b><?= vnd((int)$w['amount_vnd']) ?></b></td>
  <td><?= App\Withdrawals::methodLabel((string)$w['method']) ?></td>
  <td><?= e($w['account_name']) ?></td>
  <td><code><?= e($w['account_number']) ?></code></td>
  <td>
    <div class="score-bar small"><div class="score-fill" style="width:<?= (int)$w['trust_score'] ?>%"></div><span class="score-val"><?= (int)$w['trust_score'] ?></span></div>
    <div class="hint small">Earned: <?= vnd((int)$w['total_earned']) ?> · Tasks: <?= (int)$w['tasks_done'] ?> · Bal: <?= vnd((int)$w['balance_vnd']) ?></div>
  </td>
  <td><b class="hl"><?= vnd((int)$w['high_score']) ?></b></td>
  <td><?= (int)$w['account_age_days'] ?>d</td>
  <td><span class="pill"><?= e($w['user_ip_version']) ?></span> <code class="small"><?= e($w['register_ip']) ?></code></td>
  <td><code><?= e($w['register_device_id'] ? substr($w['register_device_id'], 0, 12) : '—') ?></code></td>
  <td>
    <span class="pill <?= e($w['status']) ?>"><?= e(['pending' => 'Chờ xử lý', 'processing' => 'Đang xử lý', 'completed' => 'Đã thanh toán', 'rejected' => 'Đã từ chối'][$w['status']] ?? $w['status']) ?></span>
    <?php if ($w['telegram_user_id']): ?><span class="pill ok" title="Đã xác minh">✓ TG</span><?php endif; ?>
  </td>
  <td><?= e($w['created_at']) ?></td>
  <td>
    <?php if ($w['status'] === 'pending'): ?>
    <form method="post" action="/admin/withdrawals/<?= (int)$w['id'] ?>/complete" class="stack-compact"><?= App\Csrf::field() ?><button class="btn small">✓ Chi</button></form>
    <form method="post" action="/admin/withdrawals/<?= (int)$w['id'] ?>/reject" class="stack-compact"><?= App\Csrf::field() ?>
      <input type="text" name="note" placeholder="Lý do" aria-label="Lý do từ chối yêu cầu rút tiền" required style="width:110px">
      <button class="btn small danger">✗ Từ chối</button>
    </form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
