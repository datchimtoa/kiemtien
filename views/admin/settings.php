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
    <label>Tỷ giá USD→VND<input name="usd_to_vnd_rate" type="number" value="<?= e($settings['usd_to_vnd_rate'] ?? '26000') ?>"></label>
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
  <button class="btn" type="submit">Lưu cài đặt</button>
</form>
