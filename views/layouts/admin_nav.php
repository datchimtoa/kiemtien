<?php $navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); ?>
<nav aria-label="Điều hướng quản trị">
  <?php foreach ([
      '/admin' => 'Tổng quan', '/admin/providers' => 'Task2 · Lượt nhiệm vụ',
      '/admin/users' => 'Người dùng', '/admin/withdrawals' => 'Thanh toán',
      '/admin/transactions' => 'Sổ giao dịch', '/admin/postbacks' => 'Task1 · Postbacks',
      '/admin/risk' => 'Sự kiện rủi ro', '/admin/settings' => 'Cài đặt',
      '/admin/audit' => 'Nhật ký kiểm toán', '/admin/diag' => 'Chẩn đoán',
  ] as $href => $label): ?>
    <a href="<?= e($href) ?>" <?= ($navPath === $href || ($href === '/admin/users' && str_starts_with($navPath, '/admin/users/'))) ? 'class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
  <form method="post" action="/admin/logout" class="inline"><?= App\Csrf::field() ?><button class="btn-link" type="submit">Đăng xuất</button></form>
</nav>
