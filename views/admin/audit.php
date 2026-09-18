<div class="card">
<table>
<thead><tr><th>ID</th><th>Actor</th><th>Hành động</th><th>Đối tượng</th><th>Chi tiết</th><th>IP</th><th>Thời gian</th></tr></thead>
<tbody>
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
</table>
</div>
