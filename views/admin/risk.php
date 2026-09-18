<div class="card">
<table>
<thead><tr><th>ID</th><th>User</th><th>Loại</th><th>Mức</th><th>Chi tiết</th><th>IP</th><th>Thời gian</th></tr></thead>
<tbody>
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
</table>
</div>
