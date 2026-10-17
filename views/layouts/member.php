<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(App\Csrf::token()) ?>">
<title><?= e($title ?? 'Không gian thành viên — HTXG.PRO') ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="member">
<a class="skip-link" href="#main">Đến nội dung</a>
<div class="workspace">
  <aside class="sidebar">
    <a class="brand" href="/dashboard"><span class="brand-mark" aria-hidden="true">HX</span> HTXG<span>.PRO</span></a>
    <div><p class="sidebar-label">Thành viên</p><?php include APP_ROOT . '/views/layouts/member_nav.php'; ?></div>
    <div class="sidebar-bottom"><span class="status-dot" aria-hidden="true"></span> Thu nhập theo kết quả xác minh<br><span class="muted">Lịch sử rõ ràng. Số dư minh bạch.</span></div>
  </aside>
  <div class="workspace-main">
    <header class="topbar">
      <details class="mobile-menu"><summary>Menu</summary><?php include APP_ROOT . '/views/layouts/member_nav.php'; ?></details>
      <div class="breadcrumb">Không gian làm việc <span aria-hidden="true"> / </span> <strong>Thành viên</strong></div>
      <div class="topbar-tools">
        <a class="balance-chip" href="/withdraw"><small>Số dư khả dụng</small><?= vnd((int)(App\Session::user()['balance_vnd'] ?? 0)) ?></a>
        <a class="avatar" href="/profile" aria-label="Hồ sơ của bạn">HX</a>
      </div>
    </header>
    <main id="main" class="container">
    <?php foreach (App\Session::takeFlashes() as $f): ?>
      <div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
    </main>
    <footer class="footer">© <?= date('Y') ?> HTXG.PRO · Tỷ giá cố định: 1 USDT/USD = <?= vnd(App\RateUpdater::FIXED_RATE) ?> · Phần chia thành viên <?= (int)App\Settings::get('site_member_share_percent', 100) ?>%</footer>
  </div>
</div>
<script src="/assets/app.js" defer></script>
</body>
</html>
