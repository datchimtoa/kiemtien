<div class="card">
<h3>Thông tin cá nhân</h3>
<table class="kv">
<tr><th>Số điện thoại</th><td><?= e(display_phone($user['phone'])) ?></td></tr>
<tr><th>Họ tên</th><td><?= e($user['full_name'] ?: '—') ?></td></tr>
<tr><th>Mã giới thiệu</th><td><code><?= e($user['referral_code']) ?></code></td></tr>
<tr><th>Ngày đăng ký</th><td><?= e($user['created_at']) ?></td></tr>
<tr><th>IP đăng ký</th><td><?= e($user['register_ip']) ?></td></tr>
<tr><th>Đăng nhập gần nhất</th><td><?= e((string)($user['last_login_at'] ?? '—')) ?> (<?= e((string)($user['last_login_ip'] ?? '')) ?>)</td></tr>
</table>
</div>
<div class="card">
<h3>Thiết bị của bạn</h3>
<?php if (!$devices): ?><p class="muted">Chưa có thiết bị nào.</p>
<?php else: ?>
<table>
<thead><tr><th>Device ID</th><th>IP</th><th>Đầu tiên</th><th>Gần nhất</th></tr></thead>
<tbody>
<?php foreach ($devices as $d): ?>
<tr><td><code><?= e($d['device_id']) ?></code></td><td><?= e($d['ip']) ?></td><td><?= e($d['first_seen']) ?></td><td><?= e($d['last_seen']) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
