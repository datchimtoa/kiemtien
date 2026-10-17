<div class="page-head"><div><span class="eyebrow">OPERATIONS / AUDIT LOG</span><h2>Nhật ký kiểm toán</h2><p>Theo dõi người thực hiện và lịch sử thao tác trên hệ thống.</p></div></div>
<div class="card">
<div class="table-wrap"><table>
<thead><tr><th>ID</th><th>Actor</th><th>Hành động</th><th>Đối tượng</th><th>Chi tiết</th><th>IP</th><th>Thời gian</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="7"><div class="empty-state">Chưa có thao tác trong nhật ký kiểm toán.</div></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= (int)$r['id'] ?></td>
  <td><?= e($r['actor_type']) ?>#<?= (int)$r['actor_id'] ?></td>
  <td><?= e($r['action']) ?></td>
  <td><?= e($r['target']) ?></td>
  <td><?= e((string)$r['detail']) ?></td>
  <td><?= e($r['ip']) ?></td>
  <td><?= e($r['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
