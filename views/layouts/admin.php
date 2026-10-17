<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(App\Csrf::token()) ?>">
<title><?= e($title ?? 'Quản trị — HTXG.PRO') ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="admin">
<a class="skip-link" href="#main">Đến nội dung</a>
<?php $adminSignedIn = App\AdminAuth::check(); ?>
<?php if ($adminSignedIn): ?>
<div class="workspace">
  <aside class="sidebar">
    <a class="brand" href="/admin"><span class="brand-mark" aria-hidden="true">HX</span> HTXG<span>.PRO</span></a>
    <div><p class="sidebar-label">Quản trị hệ thống</p><?php include APP_ROOT . '/views/layouts/admin_nav.php'; ?></div>
    <div class="sidebar-bottom">Operations workspace<br><span class="muted">Nhiệm vụ · Lưu lượng · Thanh toán</span></div>
  </aside>
  <div class="workspace-main">
    <header class="topbar">
      <details class="mobile-menu"><summary>Menu</summary><?php include APP_ROOT . '/views/layouts/admin_nav.php'; ?></details>
      <div class="breadcrumb">Không gian làm việc <span aria-hidden="true"> / </span> <strong>Quản trị</strong></div>
      <a class="btn ghost small" href="/">Xem website</a>
    </header>
<?php else: ?>
<header class="topbar"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">HX</span> HTXG<span>.PRO</span></a><span class="muted small">Quyền truy cập giới hạn</span></header>
<?php endif; ?>
    <main id="main" class="container">
    <?php foreach (App\Session::takeFlashes() as $f): ?>
      <div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
    </main>
<?php if ($adminSignedIn): ?></div></div><?php endif; ?>
<script src="/assets/app.js" defer></script>
</body>
</html>
