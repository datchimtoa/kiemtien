<div class="page-head"><div><span class="eyebrow">Tổng quan tài khoản</span><h2>Chào <?= e($user['full_name'] ?: display_phone($user['phone'])) ?>.</h2><p>Phần thưởng, hoạt động và thanh toán trong một không gian.</p></div><a class="btn" href="/tasks">Làm nhiệm vụ <span aria-hidden="true">↗</span></a></div>
<div class="grid4">
  <div class="card stat"><div class="stat-label">Số dư khả dụng</div><div class="stat-val"><?= vnd($user['balance_vnd']) ?></div><div class="kpi-note">Số tiền có thể yêu cầu rút</div></div>
  <div class="card stat"><div class="stat-label">Tổng thu nhiệm vụ</div><div class="stat-val"><?= vnd($sumTasks) ?></div><div class="kpi-note">Phần thưởng được ghi vào sổ ví</div></div>
  <div class="card stat"><div class="stat-label">Nhiệm vụ hoàn thành</div><div class="stat-val"><?= (int)$countTasks ?></div><div class="kpi-note">Các giao dịch thưởng đã ghi nhận</div></div>
  <div class="card stat"><div class="stat-label">Tổng đã rút</div><div class="stat-val"><?= vnd((int)$user['withdrawn_total_vnd']) ?></div><div class="kpi-note">Thanh toán từ tài khoản</div></div>
</div>
<div class="grid2">
  <section class="balance-card"><span class="eyebrow">Ví của bạn</span><div class="bal-label">Số dư khả dụng</div><div class="bal"><?= vnd($user['balance_vnd']) ?></div><div class="bal-sub">Tổng điểm: <?= number_format((float)$user['points_total'], 2) ?></div><div class="cta"><a class="btn" href="/withdraw">Rút tiền</a><a class="btn ghost" href="#transactions">Xem giao dịch</a></div></section>
  <section class="card"><div class="section-head"><h3>Trạng thái tài khoản</h3><span class="pill <?= $user['status'] === 'active' ? 'ok' : 'warn' ?>"><?= e(['active' => 'Đang hoạt động', 'blocked' => 'Tạm khóa', 'banned' => 'Đã khóa'][$user['status']] ?? $user['status']) ?></span></div><div class="metric-row"><span>Uy tín</span><strong><?= (int)$trustScore ?>/100</strong></div><div class="metric-row"><span>Tổng thưởng trọn đời</span><strong><?= vnd((int)$highScore) ?></strong></div><div class="metric-row"><span>Yêu cầu rút</span><strong><?= $pendingWd ? vnd((int)$pendingWd['amount_vnd']) . ' (chờ)' : 'Không có' ?></strong></div><div class="metric-row"><span>Tỷ giá cố định / USDT</span><strong><?= vnd((int)($rateInfo['rate'] ?? App\RateUpdater::FIXED_RATE)) ?></strong></div><p class="hint">Phần chia thành viên <?= (int)($rateInfo['member_share'] ?? 100) ?>%. Quay lại liên kết không tự xác nhận trả thưởng.</p></section>
</div>
<?php include APP_ROOT . '/views/dashboard/activity.php'; ?>

<section class="card" id="transactions">
  <div class="section-head"><div><h3>Giao dịch gần đây</h3><p>12 bản ghi mới nhất từ sổ ví của bạn.</p></div><span class="mono muted">VND / LEDGER</span></div>
  <?php if (!$tx): ?><div class="empty-state">Chưa có giao dịch nào. Hãy làm nhiệm vụ đầu tiên của bạn!</div>
  <?php else: ?>
  <div class="table-wrap"><table class="responsive-table">
    <thead><tr><th>Thời gian / ID</th><th>Loại</th><th>Số tiền</th><th>Số dư sau</th></tr></thead>
    <tbody>
    <?php foreach ($tx as $t): ?>
      <tr>
        <td data-label="Thời gian"><div><time><?= e($t['created_at']) ?></time><br><span class="mono muted">#<?= (int)$t['id'] ?></span></div></td>
        <td data-label="Loại"><div><?= e(App\Controllers\TransactionPresenter::typeLabel($t)) ?><?= $t['offer_name'] ? '<br><small class="muted">' . e($t['offer_name']) . '</small>' : '' ?></div></td>
        <td data-label="Số tiền" class="<?= (int)$t['amount_vnd'] >= 0 ? 'pos' : 'neg' ?>"><?= (int)$t['amount_vnd'] >= 0 ? '+' : '−' ?><?= vnd(abs((int)$t['amount_vnd'])) ?></td>
        <td data-label="Số dư sau"><?= vnd((int)$t['balance_after']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
