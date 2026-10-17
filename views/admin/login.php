<div class="card auth">
<span class="eyebrow auth-eyebrow">HTXG / OPERATIONS</span>
<h2>Đăng nhập quản trị</h2>
<p class="muted">Truy cập không gian điều hành và kiểm soát thanh toán.</p>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/admin/login" class="stack">
  <?= App\Csrf::field() ?>
  <label>Tên đăng nhập<input type="text" name="username" required autofocus></label>
  <label>Mật khẩu<input type="password" name="password" required></label>
  <button class="btn" type="submit">Đăng nhập</button>
</form>
</div>
