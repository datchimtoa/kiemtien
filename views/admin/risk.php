<div class="page-head"><div><span class="eyebrow">SECURITY / RISK EVENTS</span><h2>Sự kiện rủi ro</h2><p>Các tín hiệu tài khoản, thiết bị và IP đã được ghi nhận.</p></div></div>
<div class="card">
<div class="table-wrap"><table>
<thead><tr><th>ID</th><th>User</th><th>Loại</th><th>Mức</th><th>Chi tiết</th><th>IP</th><th>Thời gian</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="7"><div class="empty-state">Chưa ghi nhận sự kiện rủi ro.</div></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= (int)$r['id'] ?></td>
  <td><?= $r['phone'] ? e(display_phone($r['phone'])) : (int)$r['user_id'] ?></td>
  <td><?= e($r['type']) ?></td>
  <td><span class="pill <?= $r['severity'] === 'high' ? 'danger' : 'warn' ?>"><?= e($r['severity']) ?></span></td>
  <td><?= e($r['detail']) ?></td>
  <td><?= e($r['ip']) ?></td>
  <td><?= e($r['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
