<div class="balance-card">
  <div class="bal-label">Số dư khả dụng</div>
  <div class="bal"><?= vnd($user['balance_vnd']) ?></div>
  <div class="bal-sub">Tổng điểm: <b><?= number_format((float)$user['points_total'], 2) ?></b> · Đã rút: <?= vnd((int)$user['withdrawn_total_vnd']) ?></div>
  <div class="cta">
    <a class="btn" href="/tasks">Làm nhiệm vụ</a>
    <a class="btn ghost" href="/withdraw">Rút tiền</a>
  </div>
</div>
<div class="grid3">
  <div class="card"><h3>Uy tín</h3><div class="score-bar"><div class="score-fill" style="width:<?= (int)$trustScore ?>%"></div><span class="score-val"><?= (int)$trustScore ?>/100</span></div></div>
  <div class="card"><h3>High Score</h3><p class="big hl"><?= vnd((int)$highScore) ?></p></div>
  <div class="card"><h3>Tỉ giá hiện tại</h3><p class="big"><?= number_format((int)($rateInfo['rate'] ?? 26000), 0, ',', '.') ?>₫</p><span class="muted small">1 USD · member <?= (int)($rateInfo['member_share'] ?? 100) ?>%</span></div>
</div>
<div class="grid3">
  <div class="card"><h3>Tổng thu nhiệm vụ</h3><p class="big"><?= vnd($sumTasks) ?></p></div>
  <div class="card"><h3>Nhiệm vụ hoàn thành</h3><p class="big"><?= $countTasks ?></p></div>
  <div class="card"><h3>Yêu cầu rút</h3><p class="big"><?= $pendingWd ? vnd((int)$pendingWd['amount_vnd']) . ' (chờ)' : 'Không có' ?></p></div>
</div>
<div class="card">
  <h3>Giao dịch gần đây</h3>
  <?php if (!$tx): ?><p class="muted">Chưa có giao dịch nào. Hãy làm nhiệm vụ đầu tiên của bạn!</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Thời gian</th><th>Loại</th><th>Số tiền</th><th>Số dư sau</th></tr></thead>
    <tbody>
    <?php foreach ($tx as $t): ?>
      <tr>
        <td><?= e($t['created_at']) ?></td>
        <td><?= e(App\Controllers\TransactionPresenter::typeLabel($t)) ?><?= $t['offer_name'] ? '<br><small class="muted">' . e($t['offer_name']) . '</small>' : '' ?></td>
        <td class="<?= (int)$t['amount_vnd'] >= 0 ? 'pos' : 'neg' ?>"><?= (int)$t['amount_vnd'] >= 0 ? '+' : '' ?><?= vnd(abs((int)$t['amount_vnd'])) ?></td>
        <td><?= vnd((int)$t['balance_after']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
