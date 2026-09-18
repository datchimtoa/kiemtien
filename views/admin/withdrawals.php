<div class="card">
<div class="tabs">
  <?php foreach (['' => 'Tất cả', 'pending' => 'Chờ duyệt', 'completed' => 'Đã chi', 'rejected' => 'Đã từ chối'] as $s => $label): ?>
    <a class="tab <?= $status === $s ? 'active' : '' ?>" href="?status=<?= $s ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<table>
<thead><tr><th>#</th><th>SĐT</th><th>Số tiền</th><th>Phí</th><th>PT</th><th>Người nhận</th><th>STK</th><th>Bank</th><th>Uy tín</th><th>High score</th><th>Trạng thái</th><th>Thời gian</th><th>Xử lý</th></tr></thead>
<tbody>
<?php foreach ($rows as $w): ?>
<tr>
  <td><?= (int)$w['id'] ?></td>
  <td><?= e(display_phone($w['phone'])) ?></td>
  <td><b><?= vnd((int)$w['amount_vnd']) ?></b></td>
  <td><?= vnd((int)$w['fee_vnd']) ?></td>
  <td><?= e($w['method']) ?></td>
  <td><?= e($w['account_name']) ?></td>
  <td><?= e($w['account_number']) ?></td>
  <td><?= e($w['bank_name'] ?: '—') ?></td>
  <td>
     <span class="score-mini"><?= (int)$w['trust_score'] ?></span>
     <span class="muted">/ <?= vnd((int)$w['high_score']) ?></span>
     <br><span class="pill <?= e($w['status']) ?>"><?= e($w['status']) ?></span>
   </td>
  <td><?= e($w['created_at']) ?></td>
  <td>
    <?php if ($w['status'] === 'pending'): ?>
    <form method="post" action="/admin/withdrawals/<?= (int)$w['id'] ?>/complete" class="stack-compact"><?= App\Csrf::field() ?><button class="btn small">✓ Chi</button></form>
    <form method="post" action="/admin/withdrawals/<?= (int)$w['id'] ?>/reject" class="stack-compact"><?= App\Csrf::field() ?>
      <input type="text" name="note" placeholder="Lý do" required style="width:110px">
      <button class="btn small danger">✗ Từ chối</button>
    </form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
