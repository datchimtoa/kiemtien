<div class="card auth">
<h2>Đăng ký tài khoản</h2>
<?php if ($step === 'phone'): ?>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/register/phone" class="stack" data-started>
    <?= App\Csrf::field() ?>
    <input type="hidden" name="form_started" value="<?= time() ?>">
    <input type="text" name="website" value="" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label>Số điện thoại
      <input type="tel" name="phone" value="<?= e($phone) ?>" placeholder="0912345678" required autofocus>
    </label>
    <div class="btn-row">
      <?php if (App\Settings::get('register_channel_sms', '0') === '1'): ?>
      <button class="btn" type="submit" formaction="/register/phone">Nhận OTP qua SMS</button>
      <?php endif; ?>
      <?php if (App\Telegram::enabled() && App\Settings::get('register_channel_telegram', '1') === '1'): ?>
      <button class="btn btn-tg" type="submit" formaction="/register/telegram">Xác thực qua Telegram (miễn phí)</button>
      <?php endif; ?>
    </div>
  </form>
  <p class="muted">Đã có tài khoản? <a href="/login">Đăng nhập</a></p>
<?php else: ?>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <p class="muted">Số điện thoại: <b><?= e($phone) ?></b><?= !empty($tgVerified) ? ' — đã xác thực qua Telegram ✅' : ' — mã OTP đã gửi' ?><?= isset($devCode) && $devCode ? ' (DEV: <code>' . e($devCode) . '</code>)' : '' ?>.</p>
  <form method="post" action="/register/verify" class="stack" data-started>
    <?= App\Csrf::field() ?>
    <input type="hidden" name="form_started" value="<?= time() ?>">
    <?php if (empty($tgVerified)): ?>
    <label>Mã OTP (6 số)
      <input type="text" name="otp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus>
    </label>
    <?php endif; ?>
    <label>Họ và tên (không bắt buộc)
      <input type="text" name="full_name" maxlength="120">
    </label>
    <label>Mật khẩu (tối thiểu 8 ký tự)
      <input type="password" name="password" minlength="8" required>
    </label>
    <label>Nhập lại mật khẩu
      <input type="password" name="password_confirm" minlength="8" required>
    </label>
    <button class="btn" type="submit">Hoàn tất đăng ký</button>
  </form>
<?php endif; ?>
</div>
