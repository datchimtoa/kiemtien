<?php $navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); ?>
<nav aria-label="Điều hướng thành viên">
  <?php foreach ([
      '/dashboard' => ['01', 'Tổng quan'],
      '/tasks' => ['02', 'Nhiệm vụ'],
      '/tasks/history' => ['03', 'Lịch sử nhiệm vụ'],
      '/withdraw' => ['04', 'Ví & rút tiền'],
      '/profile' => ['05', 'Hồ sơ & thiết bị'],
  ] as $href => [$number, $label]): ?>
    <a href="<?= e($href) ?>" <?= $navPath === $href ? 'class="active" aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><?= e($number) ?></span><?= e($label) ?></a>
  <?php endforeach; ?>
  <form method="post" action="/logout" class="inline"><?= App\Csrf::field() ?><button class="btn-link" type="submit">Đăng xuất</button></form>
</nav>
