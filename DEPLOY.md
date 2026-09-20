# DEPLOY — Render (Docker) + Neon Postgres

## Trạng thái hiện tại
- Repo: https://github.com/datchimtoa/kiemtien
- Branch: `main`
- App: PHP 8.3 thuần (no composer) + PDO SQLite / MySQL / **PostgreSQL**
- Domain chính: **https://htxg.pro** (High-Traffic X-Gain)
- Domain Render: `https://<ten>.onrender.com` (đã chạy OK — dùng để kiểm tra khi domain lỗi)

## ⚠️ CẢNH BÁO QUAN TRỌNG — Render Free tier
1. **Free web service KHÔNG có persistent disk.**
   → Filesystem là *ephemeral*: mọi file ghi vào `storage/` (bao gồm `earnmoney.sqlite3`)
     **MẤT SẠCH** mỗi lần redeploy/restart.
   → Với site có TIỀN THẬT, **không được dùng SQLite trên Render free**.
2. Free web service **spin down sau ~15 phút** không có request → request kế tiếp chờ ~50s (cold start).
3. Free PostgreSQL của Render: 1GB, **hết hạn sau 30 ngày** rồi bị xoá.
4. Free tier không có SSH/Shell.

### Phương án DB đã cân nhắc
| Phương án | Bền vững | Chi phí |
|---|---|---|
| SQLite trên Render free | ❌ mất data mỗi deploy | 0 |
| Render free Postgres | ⚠️ hết hạn 30 ngày | 0 |
| Neon free Postgres | ✅ free vĩnh viễn (0.5GB) | 0 |
| Render paid + disk | ✅ | $7/tháng |

**Khuyến nghị:** Neon (hoặc Supabase) free Postgres + Render free web.

✅ **Code đã hỗ trợ PostgreSQL (dialect-aware)** — không cần sửa gì thêm:
- `app/Database.php` — nhánh `pgsql` (đọc `DATABASE_URL` hoặc `DB_HOST/DB_PORT/...`), hỗ trợ SSL
- `app/Schema.php` — `BIGSERIAL`, `VARCHAR(n)`, `ON CONFLICT DO NOTHING`, `information_schema`, partial unique index
- `Database::upsert()` / `Database::insertIgnore()` / `lastId()` — tự chọn cú pháp theo driver
- `INSERT OR IGNORE` → chuyển hết sang helper, chạy được cả 3 driver

## Các bước deploy Render
1. Vào https://dashboard.render.com → **New → Blueprint** → chọn repo `datchimtoa/kiemtien`
2. Render đọc `render.yaml` → tạo web service (Docker, free, region Singapore)
3. Render hỏi giá trị các env var `sync: false` — nhập:
   - `TELEGRAM_BOT_TOKEN` — token từ @BotFather
   - `TELEGRAM_BOT_USERNAME` — vd `verifyearnmoneybot`
   - `PUBCRYPTO_SITE_KEY`, `PUBCRYPTO_API_KEY`, `POSTBACK_TOKEN`
   - `ADMIN_PASSWORD` — mật khẩu admin mạnh
4. Deploy xong → lấy URL `https://<ten>.onrender.com`
5. Set webhook Telegram:
   ```bash
   curl -s "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
     -d "url=https://<ten>.onrender.com/api/telegram/webhook"
   ```
6. Khai báo lại Postback URL trên dashboard PubCrypto:
   `https://<ten>.onrender.com/postback/pubcrypto/<POSTBACK_TOKEN>`

## Env vars được hỗ trợ (config.sample.php)
| Var | Ý nghĩa |
|---|---|
| `DB_DRIVER` | `sqlite` \| `mysql` \| `pgsql` |
| `DATABASE_URL` | Postgres connection string (Neon) — ưu tiên cao nhất khi `DB_DRIVER=pgsql` |
| `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD` | khi không dùng `DATABASE_URL` |
| `DB_SSL` / `DB_SSL_CA` | bật TLS (Aiven/Neon yêu cầu) |
| `SMS_DRIVER` | `telegram` \| `log` \| `http` \| `speedsms` |
| `TELEGRAM_BOT_TOKEN` / `TELEGRAM_BOT_USERNAME` | bot OTP |
| `PUBCRYPTO_SITE_KEY` / `PUBCRYPTO_API_KEY` / `PUBCRYPTO_FORWARD_SECRET` / `PUBCRYPTO_API_BASE` | PubCrypto |
| `POSTBACK_TOKEN` | đoạn bí mật trong URL postback |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` | tài khoản admin |
| `BASE_URL` | domain chính (vd `https://htxg.pro`) — ưu tiên cao nhất |
| `RENDER_EXTERNAL_URL` | Render tự set → fallback cho `base_url` |

## 🌐 Gắn custom domain htxg.pro (Cloudflare → Render)

> Triệu chứng khi cấu hình sai: `521` (Cloudflare không tới được origin) hoặc `Not Found` (Render không nhận hostname) — **không phải lỗi code**.

1. **Render** → service `kiemtienvip` → **Settings → Custom Domains → Add Custom Domain**
   → nhập `htxg.pro` và `www.htxg.pro` → Render hiện target CNAME (`<ten>.onrender.com`) + trạng thái certificate.
2. **Cloudflare DNS** (zone htxg.pro) → **DNS → Records**:
   | Type | Name | Content | Proxy |
   |---|---|---|---|
   | CNAME | `htxg.pro` (@) | `<ten>.onrender.com` | **DNS only (mây xám)** |
   | CNAME | `www` | `<ten>.onrender.com` | **DNS only (mây xám)** |
   - Xoá mọi A/CNAME cũ trỏ sai.
   - ⚠️ Phải để **DNS only** lúc đầu để Render verify domain + cấp chứng chỉ Let's Encrypt.
     Sau khi Render báo **"Certificate: Issued"** mới bật proxy (mây cam) nếu muốn.
3. **Cloudflare SSL/TLS** → mode **Full (strict)** (không dùng Flexible khi origin đã có TLS).
4. **Render env**: thêm `BASE_URL = https://htxg.pro` → **Manual Deploy / Clear build cache & deploy**.
5. Trỏ lại 2 thứ dùng domain mới:
   - Telegram webhook:
     ```bash
     curl -s "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
       -d "url=https://htxg.pro/api/telegram/webhook"
     ```
   - PubCrypto Postback URL: `https://htxg.pro/postback/pubcrypto/<POSTBACK_TOKEN>`
6. Kiểm tra: `curl -I https://htxg.pro/` → **200**. Nếu vẫn `521` → DNS chưa trỏ đúng hoặc proxy đang bật khi cert chưa cấp. Nếu `Not Found` → **chưa add domain trong Render**.

## Ghi chú
- `config.php` **không** được commit (`.gitignore`). Dockerfile tự copy `config.sample.php` → `config.php` lúc build, và `config.sample.php` đọc từ env.
- `.dockerignore` chặn `config.php`, `storage/`, `secret.md` vào image → tránh lộ secret/user data.
- **Bảo mật:** token GitHub đã từng dán vào chat → nên **revoke và tạo token mới**.
