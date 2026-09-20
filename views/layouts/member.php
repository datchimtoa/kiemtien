<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(App\Csrf::token()) ?>">
<title><?= e($title ?? 'High-Traffic X-Gain — HTXG.PRO') ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="member">
<header class="topbar">
  <a class="brand" href="/dashboard">🚀 HTXG<span>.PRO</span></a>
  <div class="bal-chip" title="Số dư khả dụng"><?= vnd((int)(App\Session::user()['balance_vnd'] ?? 0)) ?></div>
  <nav>
    <a href="/dashboard" <?= ($_SERVER['REQUEST_URI'] ?? '') === '/dashboard' ? 'class="active"' : '' ?>>🏠 Tổng quan</a>
    <a href="/tasks" <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/tasks') ? 'class="active"' : '' ?>>🎯 Nhiệm vụ</a>
    <a href="/withdraw" <?= (($_SERVER['REQUEST_URI'] ?? '') === '/withdraw') ? 'class="active"' : '' ?>>🏦 Rút tiền</a>
    <a href="/profile">👤 Hồ sơ</a>
    <form method="post" action="/logout" class="inline"><?= App\Csrf::field() ?><button class="btn-link">Đăng xuất</button></form>
  </nav>
</header>
<main class="container">
<?php foreach (App\Session::takeFlashes() as $f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="footer">© <?= date('Y') ?> High-Traffic X-Gain (htxg.pro) — Kiếm tiền mỗi ngày. 1 USD ≈ <?= vnd((int)\App\Settings::get('usd_to_vnd_rate', '26000')) ?> (member <?= (int)\App\Settings::get('site_member_share_percent', 100) ?>%). Cập nhật: <?= e(\App\Settings::get('usd_rate_updated_at', 'chưa có')) ?></footer>
<script src="/assets/app.js" defer></script>
</body>
</html>
