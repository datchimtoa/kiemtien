<div class="card">
  <div class="page-head">
    <h2>#<?= (int)$user['id'] ?> — <?= e(display_phone($user['phone'])) ?></h2>
    <span class="muted"><?= e($user['full_name'] ?: 'Chưa đặt tên') ?> · <?= e($user['created_at']) ?></span>
  </div>

  <div class="grid2">
    <div>
      <div class="card">
        <h3>Thông tin tài khoản</h3>
        <table class="kv">
          <tr><th>Trạng thái</th><td><span class="pill <?= $user['status'] === 'active' ? 'ok' : ($user['status'] === 'banned' ? 'danger' : 'warn') ?>"><?= e($user['status']) ?></span><?= (int)$user['risk_flag'] ? ' ⚠' : '' ?></td></tr>
          <tr><th>SĐT</th><td><?= e(display_phone($user['phone'])) ?></td></tr>
          <tr><th>Tên</th><td><?= e($user['full_name'] ?: '—') ?></td></tr>
          <tr><th>Mã giới thiệu</th><td><?= e($user['referral_code'] ?: '—') ?></td></tr>
          <tr><th>Ngày đăng ký</th><td><?= e($user['created_at']) ?></td></tr>
          <tr><th>Tuổi tài khoản</th><td><?= (int)$stats['age_days'] ?> ngày</td></tr>
          <tr><th>IP đăng ký</th><td><code><?= e($user['register_ip']) ?></code> <span class="pill"><?= e($stats['ip_version']) ?></span></td></tr>
          <tr><th>IP đăng nhập gần nhất</th><td><?= e($user['last_login_ip'] ?: '—') ?> <span class="pill"><?= $user['last_login_ip'] ? e(ip_version((string)$user['last_login_ip'])) : '' ?></span></td></tr>
          <tr><th>Lần hoạt động cuối</th><td><?= e($user['last_login_at'] ?: 'Chưa bao giờ') ?></td></tr>
        </table>
      </div>

      <div class="card">
        <h3>Điểm số</h3>
        <table class="kv">
          <tr><th>Uy tín</th><td><div class="score-bar"><div class="score-fill" style="width:<?= (int)$stats['trust_score'] ?>%"></div><span class="score-val"><?= (int)$stats['trust_score'] ?></span></div></td></tr>
          <tr><th>High Score</th><td><b class="hl"><?= vnd((int)$stats['high_score']) ?></b></td></tr>
          <tr><th>Nhiệm vụ</th><td><?= (int)$stats['tasks_done'] ?></td></tr>
          <tr><th>Tổng thu</th><td><?= vnd((int)$stats['total_earned']) ?></td></tr>
          <tr><th>Chargeback</th><td><?= (int)$stats['chargebacks'] ? '<span class="pill danger">' . (int)$stats['chargebacks'] . '</span>' : '<span class="pill ok">0</span>' ?></td></tr>
        </table>
      </div>

      <div class="card">
        <h3>Telegram</h3>
        <?php if (!empty($user['telegram_user_id'])): ?>
          <table class="kv">
            <tr><th>User ID</th><td><?= e($user['telegram_user_id']) ?></td></tr>
            <tr><th>Username</th><td><?= e($user['telegram_username'] ?: '—') ?></td></tr>
            <tr><th>Verified</th><td><?= e($user['telegram_verified_at'] ?: '—') ?></td></tr>
          </table>
        <?php else: ?>
          <p class="muted">Chưa xác minh Telegram.</p>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <div class="card">
        <h3>Thiết bị (<?= count($stats['devices']) ?>)</h3>
        <?php if (!$stats['devices']): ?>
          <p class="muted">Không có thiết bị nào.</p>
        <?php else: ?>
        <table>
          <thead><tr><th>Device ID</th><th>Platform</th><th>IP</th><th>Trusted</th><th>Lần cuối</th></tr></thead>
          <tbody>
          <?php foreach ($stats['devices'] as $d): ?>
            <tr>
              <td><code><?= e(substr((string)$d['device_id'], 0, 32)) ?></code></td>
              <td><?= e($d['platform'] ?: '—') ?></td>
              <td><code><?= e($d['ip']) ?></code> <span class="pill small"><?= $d['ip'] ? e(ip_version((string)$d['ip'])) : '' ?></span></td>
              <td><?= (int)$d['trusted'] ? '<span class="pill ok">✓</span>' : '<span class="pill">Mới</span>' ?></td>
              <td><?= e($d['last_seen']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>Lịch sử IP (<?= count($stats['ips']) ?>)</h3>
        <?php if (!$stats['ips']): ?>
          <p class="muted">Không có.</p>
        <?php else: ?>
        <table>
          <thead><tr><th>IP</th><th>Loại</th><th>Lượt</th><th>Cập nhật</th></tr></thead>
          <tbody>
          <?php foreach ($stats['ips'] as $i): ?>
            <tr>
              <td><code><?= e($i['ip']) ?></code></td>
              <td><span class="pill"><?= e(ip_version((string)$i['ip'])) ?></span></td>
              <td><?= (int)$i['hits'] ?></td>
              <td><?= e($i['last_seen']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>Rủi ro (<?= (int)$stats['high_events'] ?> high, <?= (int)$stats['med_events'] ?> medium)</h3>
        <?php if (!$stats['risk_events']): ?>
          <p class="muted">Không có.</p>
        <?php else: ?>
        <table>
          <thead><tr><th>Loại</th><th>Mức</th><th>Chi tiết</th><th>IP</th><th>Thời gian</th></tr></thead>
          <tbody>
          <?php foreach ($stats['risk_events'] as $r): ?>
            <tr>
              <td><?= e($r['type']) ?></td>
              <td><span class="pill <?= $r['severity'] === 'high' ? 'danger' : 'warn' ?>"><?= e($r['severity']) ?></span></td>
              <td><?= e($r['detail']) ?></td>
              <td><code><?= e($r['ip']) ?></code></td>
              <td><?= e($r['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>Giao dịch</h3>
        <table>
          <thead><tr><th>Loại</th><th>Số lượng</th><th>Tổng</th></tr></thead>
          <tbody>
          <?php foreach ($stats['tx_summary'] as $tx): ?>
            <tr>
              <td><?= e($tx['type']) ?></td>
              <td><?= (int)$tx['cnt'] ?></td>
              <td class="<?= (int)$tx['sum'] >= 0 ? 'pos' : 'neg' ?>"><?= vnd(abs((int)$tx['sum'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="hint">Rút: 
          <?php foreach ($stats['wd_summary'] as $wd): ?>
            <span class="pill <?= e($wd['status']) ?>"><?= e($wd['status']) ?>: <?= vnd(abs((int)$wd['sum'])) ?> (<?= (int)$wd['cnt'] ?>)</span>
          <?php endforeach; ?>
        </p>
      </div>
    </div>
  </div>
</div>
