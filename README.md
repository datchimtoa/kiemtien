# EarnMoney.VIP — Nền tảng kiếm tiền từ nhiệm vụ PubCrypto Link

Website tái phân phối nhiệm vụ **PubCrypto Link** (pub.cryptolinkforearn.com) cho thành viên Việt Nam:
ng ký bằng **SĐT + OTP**, làm nhiệm vụ **vượt link**, nhận tiền về **VND**, rút qua **bank/MoMo/ZaloPay**.
Không framework, không Composer — PHP 8.1+ thuần (PDO SQLite/MySQL).

## Tính năng
- **Đăng ký/Đăng nhập bằng SĐT** — OTP SMS (driver `log` cho dev, `http` cho gateway thật), chuẩn hoá số VN (0/+84/84), chống spam OTP (rate-limit theo SĐT + IP), chống enumeration lỗi đăng nhập.
- **OTP thiết bị lạ khi đăng nhập** — fingerprint (screen/tz/lang/hw) + device_id lưu localStorage; thiết bị/IP mới phải xác thực OTP.
- **Anti-cheat / Anti-fraud** — honeypot + min-form-time, giới hạn tài khoản/IP & tài khoản/fingerprint, rate-limit click nhiệm vụ (cooldown + velocity/giờ), trần tiền postback, cờ risk + sự kiện risk_events, kiểm tra rút (tuổi TK, IP dùng chung, risk_flag).
- **Nhiệm vụ (PubCrypto Link)** — server gọi `GET /api/publisher/pubcrypto-links` bằng **Publisher API key** (sub_id = user id), cache theo thành viên (TTL 45s), chỉ mở link one-time `link_dotask` sau khi qua kiểm tra.
- **Postback S2S** — `/postback/pubcrypto[/{token}]`: verify `md5(subId+transId+reward_pb+secret)` (Forward Secret), dedupe `transId` trong transaction, status 0/1/2 = pending/credit/chargeback, luôn trả `ok`, log `postback_logs`.
- **Ví & sổ cái** — mọi biến động ghi bảng `transactions` cùng transaction DB với số dư; chuyển USD→VND qua `usd_to_vnd_rate` × `site_member_share_percent`.
- **Rút tiền** — hold số dư, hạn mức min/max/ngày/số-lần, phí %, admin duyệt (complete/refund khi từ chối), audit log.
- **Admin** — dashboard, users (khoá/mở, cờ rút, điều chỉnh số dư), withdrawals, transactions, postbacks, risk events, settings, audit.
- **Bảo mật** — CSRF token mọi form, CSP + security headers, Argon2id/bcrypt, session hardening (httponly/samesite/secure), prepared statements 100%, escape `e()` mọi output, HTTPS redirect optional, secret postback path.

## Cài đặt
```bash
# 1. Cấu hình
cp config.sample.php config.php   # sửa db, base_url, postback_token

# 2. Tạo admin (tối thiểu 10 ký tự mật khẩu)
php bin/create-admin.php admin 'MatKhauCucManh!' 

# 3. Chạy (dev)
php -S 127.0.0.1:8080 -t public public/index.php
# Production (nginx): root trỏ về public/, try_files $uri /index.php?$query_string;
#   PHP-FPM: fastcgi_pass unix:/run/php/php8.2-fpm.sock;
```

## Kết nối PubCrypto (bắt buộc cho nhiệm vụ)
1. Tạo App trên dashboard PubCrypto → chờ duyệt.
2. Vào **Admin → Cài đặt**, điền:
   - `pubcrypto_site_key` (embed key), `pubcrypto_api_key` (Publisher API key — **giữ server-side**), `pubcrypto_forward_secret` (Forward Secret — **giữ server-side**).
   - **Postback URL** khai báo trên PubCrypto: `https://<domain>/postback/pubcrypto/<postback_token>` (token lấy từ `config.php`).
3. Số dư thành viên = `payout_pb × usd_to_vnd_rate × site_member_share_percent / 100`.

## SMS gateway thật
Admin → Cài đặt → chuyển `sms.driver` = `http` (trong `config.php`), rồi điền:
- `sms_http_url`, `sms_http_method` (GET/POST), `sms_http_headers` (mỗi dòng 1 header),
- `sms_http_body_template` với placeholder `{phone}`, `{message}`, `{sender}`.
Ví dụ (eSMS):
```
{"api_key":"KEY","secret":"SEC","campaign":"OTP","phone":"{phone}","content":"{message}"}
```

## Lệnh hữu ích
```bash
php -S 127.0.0.1:8080 -t public public/index.php   # dev server
php bin/create-admin.php <user> <pass>             # tạo/đổi admin
sqlite3 storage/earnmoney.sqlite3                  # xem DB (dev)
```
Log: `storage/logs/sms.log` (OTP dev), `storage/logs/devserver.log`, error_log của PHP.

## Cấu trúc
```
public/          index.php (router) + assets (css/js)
app/             Core: Database, Schema, Settings, Session, Csrf, RateLimiter,
                 Audit, Risk, Fingerprint, Otp, Sms, Auth, Wallet, Withdrawals,
                 PubCryptoClient, PostbackProcessor, TransactionPresenter,
                 AdminAuth, View, Controllers/*
views/           layouts (base/member/admin) + templates
storage/         sqlite db, sessions, logs (CHMOD 770, không public)
bin/             create-admin.php
```
