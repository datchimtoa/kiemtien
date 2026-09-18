<div class="card">
<form method="get" action="/admin/users" class="inline-form">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm SĐT / tên / ID">
  <button class="btn ghost" type="submit">Tìm</button>
</form>
<p class="muted">Tổng: <?= $total ?> người dùng · Trang <?= $page ?>/<?= max(1, (int)ceil($total / $perPage)) ?></p>
<table>
<thead><tr><th>ID</th><th>SĐT</th><th>Tên</th><th>Số dư</th><th>Điểm</th><th>Uy tín</th><th>High score</th><th>Nhiệm vụ</th><th>Đã làm</th><th>Đã rút</th><th>Trạng thái</th><th>Thời gian</th><th>Hành động</th></tr></thead>
<tbody>
<?php foreach ($rows as $u): ?>
<tr>
  <td><?= (int)$u['id'] ?></td>
  <td><?= e(display_phone($u['phone'])) ?></td>
  <td><?= e($u['full_name'] ?: '—') ?></td>
  <td><?= vnd((int)$u['balance_vnd']) ?></td>
  <td><?= number_format((float)$u['points_total'], 2) ?></td>
  <td>
    <div class="score-bar">
      <div class="score-fill" style="width:<?= (int)$u['trust_score'] ?>%"></div>
      <span class="score-val"><?= (int)$u['trust_score'] ?></span>
    </div>
  </td>
  <td><?= vnd((int)$u['high_score']) ?></td>
  <td><?= (int)$u['task_count'] ?></td>
  <td><?= vnd((int)$u['total_earned']) ?></td>
  <td><?= vnd((int)$u['wd_total']) ?> <span class="muted">(<?= (int)$u['wd_count'] ?>)</span></td>
  <td><span class="pill <?= $u['status'] === 'active' ? 'ok' : 'warn' ?>"><?= e($u['status']) ?><?= (int)$u['risk_flag'] ? ' ⚠' : '' ?></span></td>
  <td><?= e($u['created_at']) ?></td>
  <td class="actions">
    <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/<?= $u['status'] === 'active' ? 'block' : 'unblock' ?>" class="inline"><?= App\Csrf::field() ?><button class="btn-link"><?= $u['status'] === 'active' ? 'Khoá' : 'Mở' ?></button></form>
    <?php if ((int)$u['risk_flag']): ?><form method="post" action="/admin/users/<?= (int)$u['id'] ?>/clear_risk" class="inline"><?= App\Csrf::field() ?><button class="btn-link">Bỏ cờ</button></form><?php endif; ?>
    <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/adjust" class="inline"><?= App\Csrf::field() ?>
      <input type="number" name="amount" placeholder="±VND" style="width:90px" required>
      <button class="btn-link">Điều chỉnh</button>
    </form>
    <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/delete" class="inline"><?= App\Csrf::field() ?><button class="btn-link danger">Xóa</button></form>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn ghost" href="?q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>">← Trước</a><?php endif; ?>
  <?php if ($page * $perPage < $total): ?><a class="btn ghost" href="?q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>">Sau →</a><?php endif; ?>
</div>
</div>
