# DEPLOY — Render (Docker)

## Trạng thái hiện tại
- Repo: https://github.com/datchimtoa/kiemtien
- Branch: `main`
- App: PHP 8.3 thuần (no composer) + PDO SQLite/MySQL
- Domain tạm: Cloudflare tunnel (máy local) — Render sẽ thay thế

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
⚠️ Nhưng code hiện tại có **SQL phụ thuộc SQLite** cần sửa trước khi chạy Postgres:
- `app/Schema.php:321` — `INSERT OR IGNORE` (Postgres: `INSERT ... ON CONFLICT DO NOTHING`)
- `app/Schema.php:256` — `PRAGMA table_info(...)` (Postgres: `information_schema.columns`)
- `app/Schema.php:17` — `AUTOINCREMENT` vs `SERIAL`
- `app/Database.php` — chưa có nhánh `pgsql` (cần `pdo_pgsql`)
- `lastInsertId()` — Postgres cần `RETURNING id`

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
| `DB_DRIVER` | `sqlite` \| `mysql` |
| `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD` | khi `DB_DRIVER=mysql` |
| `SMS_DRIVER` | `telegram` \| `log` \| `http` |
| `TELEGRAM_BOT_TOKEN` / `TELEGRAM_BOT_USERNAME` | bot OTP |
| `PUBCRYPTO_SITE_KEY` / `PUBCRYPTO_API_KEY` / `PUBCRYPTO_API_BASE` | PubCrypto |
| `POSTBACK_TOKEN` | đoạn bí mật trong URL postback |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` | tài khoản admin |
| `RENDER_EXTERNAL_URL` | Render tự set → dùng làm `base_url` |

## Ghi chú
- `config.php` **không** được commit (`.gitignore`). Dockerfile tự copy `config.sample.php` → `config.php` lúc build, và `config.sample.php` đọc từ env.
- `.dockerignore` chặn `config.php`, `storage/`, `secret.md` vào image → tránh lộ secret/user data.
- **Bảo mật:** token GitHub đã từng dán vào chat → nên **revoke và tạo token mới**.
