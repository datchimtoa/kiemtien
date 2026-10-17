<div class="page-head"><div><span class="eyebrow">Tài khoản / Bảo mật</span><h2>Hồ sơ & thiết bị</h2><p>Thông tin tài khoản và lịch sử thiết bị đã ghi nhận.</p></div><span class="pill">ID #<?= (int)$user['id'] ?></span></div>

<div class="card">
<h3>Thông tin cá nhân</h3>
<div class="table-wrap"><table class="kv">
<tr><th>Số điện thoại</th><td><?= e(display_phone($user['phone'])) ?></td></tr>
<tr><th>Họ tên</th><td><?= e($user['full_name'] ?: '—') ?></td></tr>
<tr><th>Mã giới thiệu</th><td><code><?= e($user['referral_code']) ?></code></td></tr>
<tr><th>Ngày đăng ký</th><td><?= e($user['created_at']) ?></td></tr>
<tr><th>IP đăng ký</th><td><?= e($user['register_ip']) ?></td></tr>
<tr><th>Đăng nhập gần nhất</th><td><?= e((string)($user['last_login_at'] ?? '—')) ?> (<?= e((string)($user['last_login_ip'] ?? '')) ?>)</td></tr>
</table></div>
</div>
<div class="card">
<h3>Thiết bị của bạn</h3>
<?php if (!$devices): ?><p class="muted">Chưa có thiết bị nào.</p>
<?php else: ?>
<div class="table-wrap"><table class="responsive-table">
<thead><tr><th>Device ID</th><th>IP</th><th>Đầu tiên</th><th>Gần nhất</th></tr></thead>
<tbody>
<?php foreach ($devices as $d): ?>
<tr><td data-label="Thiết bị"><code><?= e($d['device_id']) ?></code></td><td data-label="IP"><span class="mono"><?= e($d['ip']) ?></span></td><td data-label="Đầu tiên"><time><?= e($d['first_seen']) ?></time></td><td data-label="Gần nhất"><time><?= e($d['last_seen']) ?></time></td></tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php endif; ?>
</div>
