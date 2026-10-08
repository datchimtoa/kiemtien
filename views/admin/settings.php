<div class="card">
  <h3>📈 Tỉ giá &amp; Thời gian uptime</h3>
  <table class="kv">
    <tr><th>Tỉ giá USD→VND hiện tại</th><td><b class="hl"><?= number_format($rateInfo['rate'], 0, ',', '.') ?>₫</b></td></tr>
    <tr><th>Cập nhật lúc</th><td><?= e($rateInfo['updated_at'] ?: 'Chưa có') ?></td></tr>
    <tr><th>Chế độ</th><td><span class="pill ok">Cố định 24.000 VND/USDT</span></td></tr>
    <tr><th>% chia cho member</th><td><?= (int)$rateInfo['member_share'] ?>%</td></tr>
  </table>
  <p class="hint">Tỷ giá quy đổi cố định, không gọi API thị trường. PubCrypto reward_pb đã là VND cuối cho thành viên và không được quy đổi hay chia phần trăm lần nữa.</p>
  <p class="hint">Ví dụ: 1 USD = <?= number_format($rateInfo['rate'], 0, ',', '.') ?>₫ → nếu member được <?= (int)$rateInfo['member_share'] ?>%, với nhiệm vụ trị giá $0.08 → member nhận <?= vnd((int)floor(0.08 * $rateInfo['rate'] * $rateInfo['member_share'] / 100)) ?>.</p>
</div>

<div class="card">
  <h3>PubCrypto Postback URL (paste vào PubCrypto Dashboard)</h3>
  <p><code class="copybox"><?= e($postbackUrl) ?></code></p>
</div>
<form method="post" action="/admin/settings" class="stack">
  <?= App\Csrf::field() ?>
  <div class="grid2">
  <div class="card">
    <h3>Chung</h3>
    <label>Tên site<input name="site_name" value="<?= e($settings['site_name'] ?? '') ?>"></label>
    <label class="check"><input type="checkbox" name="maintenance" value="1" <?= ($settings['maintenance'] ?? '0') === '1' ? 'checked' : '' ?>> Bảo trì (khoá người dùng)</label>
  </div>
  <div class="card">
    <h3>PubCrypto</h3>
    <label>Site key (embed key)<input name="pubcrypto_site_key" value="<?= e($settings['pubcrypto_site_key'] ?? '') ?>"></label>
    <label>API key (để trống giữ nguyên)<input name="pubcrypto_api_key" value=""></label>
    <label>Forward secret (để trống giữ nguyên)<input name="pubcrypto_forward_secret" value=""></label>
    <label>API base<input name="pubcrypto_api_base" value="<?= e($settings['pubcrypto_api_base'] ?? '') ?>"></label>
    <label>Cache TTL (giây)<input name="pubcrypto_task_cache_ttl" type="number" min="10" value="<?= e($settings['pubcrypto_task_cache_ttl'] ?? '45') ?>"></label>
  </div>
  <div class="card">
    <h3>Kinh tế</h3>
    <p>Tỷ giá cố định: 1 USDT/USD = <?= vnd(\App\RateUpdater::FIXED_RATE) ?>. Không tự cập nhật theo thị trường.</p>
    <label>% chia cho thành viên (0-100)<input name="site_member_share_percent" type="number" min="0" max="100" value="<?= e($settings['site_member_share_percent'] ?? '100') ?>"></label>
    <label>Rút tối thiểu (VND)<input name="min_withdraw_vnd" type="number" value="<?= e($settings['min_withdraw_vnd'] ?? '') ?>"></label>
    <label>Rút tối đa/lần (VND)<input name="max_withdraw_vnd" type="number" value="<?= e($settings['max_withdraw_vnd'] ?? '') ?>"></label>
    <label>Giới hạn rút/ngày (VND)<input name="daily_withdraw_limit_vnd" type="number" value="<?= e($settings['daily_withdraw_limit_vnd'] ?? '') ?>"></label>
    <label>Phí rút (%)<input name="withdraw_fee_percent" type="number" step="0.1" value="<?= e($settings['withdraw_fee_percent'] ?? '0') ?>"></label>
    <label>Số ngày tối thiểu trước khi rút<input name="withdraw_min_account_age_days" type="number" value="<?= e($settings['withdraw_min_account_age_days'] ?? '0') ?>"></label>
    <label>Số yêu cầu rút tối đa/ngày<input name="withdraw_daily_count_limit" type="number" value="<?= e($settings['withdraw_daily_count_limit'] ?? '3') ?>"></label>
    <label>Danh sách bank (mỗi dòng 1 ngân hàng)<textarea name="withdraw_bank_list" rows="5"><?= e($settings['withdraw_bank_list'] ?? '') ?></textarea></label>
  </div>
  <div class="card">
    <h3>SMS Gateway — SpeedSMS.vn (Zalo ZNS / SMS)</h3>
    <label>Access Token (SpeedSMS → Quản lý API)<input name="speedsms_token" value="<?= e($settings['speedsms_token'] ?? '') ?>"></label>
    <label>Template ID ZNS OTP (để trống = gửi SMS thường, có rồi thì điền)<input name="speedsms_zns_template_id" value="<?= e($settings['speedsms_zns_template_id'] ?? '') ?>"></label>
    <label>Sender brandname (tùy chọn, SMS CSKH)<input name="speedsms_sender" value="<?= e($settings['speedsms_sender'] ?? '') ?>"></label>
    <p class="hint">Driver đang dùng: <b><?= e(\App\Settings::get('sms_driver_label', config('sms.driver') ?? 'log')) ?></b> — đổi trong config.php `sms.driver` = 'speedsms'.</p>
  </div>
  <div class="card">
    <h3>SMS Gateway (HTTP tổng quát)</h3>
    <label>URL<input name="sms_http_url" value="<?= e($settings['sms_http_url'] ?? '') ?>"></label>
    <label>Method<select name="sms_http_method"><option <?= ($settings['sms_http_method'] ?? '') === 'POST' ? 'selected' : '' ?>>POST</option><option <?= ($settings['sms_http_method'] ?? '') === 'GET' ? 'selected' : '' ?>>GET</option></select></label>
    <label>Headers (mỗi dòng 1 header)<textarea name="sms_http_headers" rows="3"><?= e($settings['sms_http_headers'] ?? '') ?></textarea></label>
    <label>Body template ({phone} {message} {sender})<textarea name="sms_http_body_template" rows="4"><?= e($settings['sms_http_body_template'] ?? '') ?></textarea></label>
    <label>Sender<input name="sms_sender" value="<?= e($settings['sms_sender'] ?? '') ?>"></label>
  </div>
  <div class="card">
    <h3>Chống gian lận</h3>
    <label class="check"><input type="checkbox" name="anticheat_enabled" value="1" <?= ($settings['anticheat_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> Bật anticheat</label>
    <label>Tài khoản tối đa / IP<input name="max_accounts_per_ip" type="number" value="<?= e($settings['max_accounts_per_ip'] ?? '3') ?>"></label>
    <label>Tài khoản tối đa / fingerprint<input name="max_accounts_per_fp" type="number" value="<?= e($settings['max_accounts_per_fp'] ?? '2') ?>"></label>
    <label>Cooldown giữa 2 nhiệm vụ (giây)<input name="task_click_cooldown_seconds" type="number" value="<?= e($settings['task_click_cooldown_seconds'] ?? '20') ?>"></label>
    <label>Tối đa nhiệm vụ / giờ<input name="task_max_completions_per_hour" type="number" value="<?= e($settings['task_max_completions_per_hour'] ?? '30') ?>"></label>
    <label>Trần thưởng mỗi postback (VND)<input name="postback_max_reward_vnd" type="number" value="<?= e($settings['postback_max_reward_vnd'] ?? '') ?>"></label>
    <label class="check"><input type="checkbox" name="login_new_device_otp" value="1" <?= ($settings['login_new_device_otp'] ?? '1') === '1' ? 'checked' : '' ?>> OTP khi đăng nhập thiết bị mới</label>
    <label>Thời gian điền form tối thiểu (giây)<input name="min_form_seconds" type="number" value="<?= e($settings['min_form_seconds'] ?? '3') ?>"></label>
  </div>
  </div>
  <div class="card">
    <h3>Nguồn nhiệm vụ &amp; thù lao</h3>
    <p><a href="/admin/providers">Xem và duyệt nhiệm vụ provider</a></p>
    <p class="hint">Quay lại trình duyệt không phải bằng chứng hoàn thành. YeuJob V2 chờ admin duyệt tay và cần thưởng cố định VND/lượt lớn hơn 0. Traffic24h (views_valid) có xác minh tự động; lượt YeuJob V1 cũ vẫn giữ tra cứu approved + paid. XTASK thiếu định dạng phản hồi status. Không cộng thưởng theo timer.</p>
    <?php foreach (\App\Providers\Catalog::definitions() as $providerId => $definition):
        $prefix = 'provider_' . $providerId . '_'; ?>
      <fieldset>
        <legend><?= e($definition['label']) ?></legend>
        <p class="hint"><?= e($definition['docs']) ?></p>
        <label class="check"><input type="checkbox" name="<?= e($prefix) ?>enabled" value="1" <?= ($settings[$prefix . 'enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Bật nguồn</label>
        <label>API key (trống = giữ nguyên)<input type="password" autocomplete="new-password" name="<?= e($prefix) ?>api_key" value=""></label>
        <label>Callback HMAC secret (tùy chọn, ≥32 ký tự, trống = giữ nguyên)<input type="password" autocomplete="new-password" name="<?= e($prefix) ?>callback_secret" value=""></label>
        <p class="hint">Không phải API key. Chỉ cấu hình khóa bí mật dùng chung với server provider khi họ hỗ trợ đúng giao thức callback; nếu chưa hỗ trợ thì không cần nhập. HMAC không dùng để tạo nhiệm vụ.</p>
        <p class="hint">Endpoint S2S tùy chọn: /postback/provider/<?= e($providerId) ?> — chỉ dùng nếu provider hỗ trợ hợp đồng chữ ký trong PROVIDERS.md, không phải URL quay lại.</p>
        <?php foreach (['reward_vnd' => ['Thưởng mặc định (VND)', 0, 2000000], 'share_percent' => ['% chia thưởng (chỉ dùng cho mô hình job V1, không dùng YeuJob V2)', 0, 100], 'daily_limit' => ['Lượt bắt đầu / tài khoản / 24h', 1, 1000], 'ip_daily_limit' => ['Lượt bắt đầu / IP / 24h', 1, 1000], 'min_seconds' => ['Thời gian tối thiểu (giây)', 1, 86400]] as $field => [$label, $min, $max]): ?>
          <label><?= e($label) ?><input type="number" min="<?= $min ?>" max="<?= $max ?>" name="<?= e($prefix . $field) ?>" value="<?= e($settings[$prefix . $field] ?? $definition['defaults'][$field] ?? 70) ?>" required></label>
        <?php endforeach; ?>
        <?php foreach ($definition['services'] as $service): if ($service['id'] === 'no_ads') continue;
          $field = 'service_' . $service['id'] . '_reward_vnd'; ?>
          <label><?= e($service['label']) ?> — VND/lượt (YeuJob V2: phải lớn hơn 0, admin duyệt tay)<input type="number" min="<?= $providerId === 'yeujob' ? 1 : 0 ?>" max="2000000" name="<?= e($prefix . $field) ?>" value="<?= e($settings[$prefix . $field] ?? $settings[$prefix . 'reward_vnd'] ?? $definition['defaults']['reward_vnd']) ?>" required></label>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>
  </div>
  <button class="btn" type="submit">Lưu cài đặt</button>
</form>
