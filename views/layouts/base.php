<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(App\Csrf::token()) ?>">
<title><?= e($title ?? 'HTXG.PRO — High-Traffic X-Gain') ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/">🚀 HTXG<span>.PRO</span></a>
  <nav>
    <?php if (App\Session::userId() !== null): ?>
      <a href="/dashboard">Tổng quan</a>
      <a href="/tasks">Nhiệm vụ</a>
      <a href="/withdraw">Rút tiền</a>
      <a href="/profile">Hồ sơ</a>
      <form method="post" action="/logout" class="inline"><?= App\Csrf::field() ?><button class="btn-link">Đăng xuất</button></form>
    <?php else: ?>
      <a href="/login">Đăng nhập</a>
      <a class="btn" href="/register">Đăng ký</a>
    <?php endif; ?>
  </nav>
</header>
<main class="container">
<?php foreach (App\Session::takeFlashes() as $f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="footer">
  <p>© <?= date('Y') ?> <?= e($siteName ?? 'HTXG.PRO') ?> — High-Traffic X-Gain · Kiếm tiền online từ nhiệm vụ.</p>
</footer>
<script src="/assets/app.js" defer></script>
</body>
</html>
