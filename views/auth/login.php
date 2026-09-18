<div class="card auth">
<h2>Đăng nhập</h2>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$otpStep): ?>
  <form method="post" action="/login" class="stack" data-started>
    <?= App\Csrf::field() ?>
    <input type="hidden" name="form_started" value="<?= time() ?>">
    <label>Số điện thoại
      <input type="tel" name="phone" value="<?= e($phone) ?>" placeholder="0912345678" required autofocus>
    </label>
    <label>Mật khẩu
      <input type="password" name="password" required>
    </label>
    <button class="btn" type="submit">Đăng nhập</button>
  </form>
  <p class="muted">Chưa có tài khoản? <a href="/register">Đăng ký</a></p>
<?php else: ?>
  <p>Thiết bị mới được phát hiện. Nhập mã OTP gửi tới <b><?= e($phone) ?></b><?= $devCode ? ' (DEV: <code>' . e($devCode) . '</code>)' : '' ?>:</p>
  <form method="post" action="/login/otp" class="stack">
    <?= App\Csrf::field() ?>
    <label>Mã OTP
      <input type="text" name="otp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus>
    </label>
    <button class="btn" type="submit">Xác nhận</button>
  </form>
<?php endif; ?>
</div>
