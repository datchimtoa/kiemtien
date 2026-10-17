<div class="page-head"><div><span class="eyebrow">Ví & thanh toán</span><h2>Rút tiền</h2><p>Theo dõi hạn mức, tạo yêu cầu và kiểm tra trạng thái thanh toán.</p></div><span class="pill">VND</span></div>
<div class="grid3">
  <div class="card"><div class="stat-label">Số dư khả dụng</div><div class="stat-val"><?= vnd($user['balance_vnd']) ?></div></div>
  <div class="card"><div class="stat-label">Rút tối thiểu</div><div class="stat-val"><?= vnd($min) ?></div></div>
  <div class="card"><div class="stat-label">Hạn mức đã dùng hôm nay</div><div class="stat-val"><?= vnd($sumToday) ?></div><span class="kpi-note">trên <?= vnd($dailyLimit) ?></span></div>
</div>

<div class="grid2">
<div class="card">
  <h3>Tạo yêu cầu rút tiền</h3>
  <p class="hint">Kiểm tra kỹ thông tin người nhận trước khi gửi yêu cầu.</p>
  <p class="muted">Tối thiểu <?= vnd($min) ?> · Tối đa <?= vnd($max) ?>/lần · Tối đa <?= $dailyCount ?> yêu cầu/ngày · Phí <?= $feePct ?>%</p>
  <?php if ($riskError): ?><div class="flash error"><?= e($riskError) ?></div>
  <?php elseif ($user['balance_vnd'] < $min): ?><div class="flash warn">Số dư chưa đủ để rút (tối thiểu <?= vnd($min) ?>).</div>
  <?php else: ?>
  <form method="post" action="/withdraw" class="stack">
    <?= App\Csrf::field() ?>
    <label>Số tiền (VND)
      <input type="number" name="amount" min="<?= $min ?>" max="<?= min($max, (int)$user['balance_vnd']) ?>" value="<?= max($min, (int)$user['balance_vnd']) ?>" required>
    </label>
    <label>Phương thức
      <select name="method" required>
        <option value="bank">Chuyển khoản ngân hàng</option>
        <option value="momo">MoMo</option>
        <option value="zalopay">ZaloPay</option>
      </select>
    </label>
    <label id="bank-wrap">Ngân hàng
      <select name="bank_name">
        <?php foreach ($banks as $b): ?><option value="<?= e(trim($b)) ?>"><?= e(trim($b)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Tên chủ tài khoản
      <input type="text" name="account_name" maxlength="120" required>
    </label>
    <label>Số tài khoản / SĐT ví
      <input type="text" name="account_number" maxlength="64" required>
    </label>
    <button class="btn" type="submit">Gửi yêu cầu</button>
  </form>
  <?php endif; ?>
</div>
<div class="card">
  <h3>Lịch sử rút tiền</h3>
  <?php if (!$history): ?><p class="muted">Chưa có yêu cầu nào.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="responsive-table">
    <thead><tr><th>Thời gian</th><th>Số tiền</th><th>Phương thức</th><th>Trạng thái</th></tr></thead>
    <tbody>
    <?php foreach ($history as $w): ?>
      <tr>
        <td data-label="Thời gian"><time><?= e($w['created_at']) ?></time></td>
        <td data-label="Số tiền"><?= vnd((int)$w['amount_vnd']) ?></td>
        <td data-label="Phương thức"><?= e(App\Withdrawals::methodLabel((string)$w['method'])) ?></td>
        <td data-label="Trạng thái"><div><span class="pill <?= e($w['status']) ?>"><?= e(['pending' => 'Chờ xử lý', 'processing' => 'Đang xử lý', 'completed' => 'Đã thanh toán', 'rejected' => 'Đã từ chối'][$w['status']] ?? $w['status']) ?></span><?php if (!empty($w['admin_note'])): ?><p class="hint"><?= e($w['admin_note']) ?></p><?php endif; ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
</div>
