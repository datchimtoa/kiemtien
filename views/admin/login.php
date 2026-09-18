<div class="card auth">
<h2>Admin đăng nhập</h2>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/admin/login" class="stack">
  <?= App\Csrf::field() ?>
  <label>Tên đăng nhập<input type="text" name="username" required autofocus></label>
  <label>Mật khẩu<input type="password" name="password" required></label>
  <button class="btn" type="submit">Đăng nhập</button>
</form>
</div>
