<div class="page-head"><div><span class="eyebrow">FINANCE / LEDGER</span><h2>Sổ giao dịch</h2><p>Các biến động số dư và giao dịch được ghi nhận trong hệ thống.</p></div></div>
<div class="card">
<div class="tabs">
  <?php foreach (['' => 'Tất cả', 'task_reward' => 'Thưởng', 'task_pending' => 'Pending', 'task_chargeback' => 'Chargeback', 'withdraw_hold' => 'Rút', 'withdraw_refund' => 'Hoàn rút', 'admin_adjust' => 'Điều chỉnh'] as $t => $label): ?>
    <a class="tab <?= $type === $t ? 'active' : '' ?>" href="?type=<?= $t ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<div class="table-wrap"><table>
<thead><tr><th>ID</th><th>SĐT</th><th>Loại</th><th>Số tiền</th><th>Điểm</th><th>transId</th><th>Offer</th><th>IP</th><th>Thời gian</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="9"><div class="empty-state">Chưa có giao dịch phù hợp với bộ lọc này.</div></td></tr><?php endif; ?>
<?php foreach ($rows as $t): ?>
<tr>
  <td><?= (int)$t['id'] ?></td>
  <td><?= e(display_phone($t['phone'])) ?></td>
  <td><?= e($t['type']) ?></td>
  <td class="<?= (int)$t['amount_vnd'] >= 0 ? 'pos' : 'neg' ?>"><?= vnd((int)$t['amount_vnd']) ?></td>
  <td><?= number_format((float)$t['points'], 2) ?></td>
  <td><code><?= e($t['trans_id']) ?></code></td>
  <td><?= e($t['offer_name'] ?: '—') ?></td>
  <td><?= e($t['ip']) ?></td>
  <td><?= e($t['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
