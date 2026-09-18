<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(App\Csrf::token()) ?>">
<title><?= e($title ?? 'Admin — EarnMoney.VIP') ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="admin">
<?php if (\App\AdminAuth::check()): ?>
<header class="topbar">
  <a class="brand" href="/admin">🛠 Admin<span>.VIP</span></a>
  <nav>
    <a href="/admin">Tổng quan</a>
    <a href="/admin/users">Người dùng</a>
    <a href="/admin/withdrawals">Rút tiền</a>
    <a href="/admin/transactions">Giao dịch</a>
    <a href="/admin/postbacks">Postbacks</a>
    <a href="/admin/risk">Rủi ro</a>
    <a href="/admin/settings">Cài đặt</a>
    <a href="/admin/audit">Audit</a>
    <form method="post" action="/admin/logout" class="inline"><?= \App\Csrf::field() ?><button class="btn-link">Đăng xuất</button></form>
  </nav>
</header>
<?php endif; ?>
<main class="container">
<?php foreach (\App\Session::takeFlashes() as $f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<script src="/assets/app.js" defer></script>
</body>
</html>
