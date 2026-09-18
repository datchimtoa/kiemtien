<div class="grid2">
<div class="card">
  <h3>Tạo yêu cầu rút tiền</h3>
  <p>Số dư: <b><?= vnd($user['balance_vnd']) ?></b> · Hạn mức hôm nay: <?= vnd($sumToday) ?>/<?= vnd($dailyLimit) ?></p>
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
  <table>
    <thead><tr><th>Thời gian</th><th>Số tiền</th><th>Phương thức</th><th>Trạng thái</th></tr></thead>
    <tbody>
    <?php foreach ($history as $w): ?>
      <tr>
        <td><?= e($w['created_at']) ?></td>
        <td><?= vnd((int)$w['amount_vnd']) ?></td>
        <td><?= e(App\Withdrawals::methodLabel((string)$w['method'])) ?></td>
        <td><span class="pill <?= e($w['status']) ?>"><?= e($w['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
</div>
