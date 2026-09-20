#!/usr/bin/env bash
# E2E test: đăng ký qua Telegram (end-to-end tới webhook bot), login, trang chẩn đoán admin.
set -u
cd /home/devroom/earnmoney.vip
B=http://127.0.0.1:8092
PORT=8092

pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null
sleep 1
TELEGRAM_BOT_TOKEN=123456:TESTTOKEN TELEGRAM_BOT_USERNAME=htxg_test_bot \
  php -S 127.0.0.1:$PORT -t public public/index.php >/tmp/srv.log 2>&1 &
sleep 2

j() { php -r 'require "app/bootstrap.php"; echo json_encode(App\Database::one($argv[1]) ?: []);' "$1"; }
csrf() { grep -o 'name="csrf-token" content="[^"]*"' "$1" | head -1 | sed 's/.*content="//;s/"$//'; }
code() { curl -s -o "$2" -w '%{http_code}' "${@:3}"; }

# Số điện thoại + Telegram user id + IP ngẫu nhiên để test đúng luồng "số mới"
# (tránh chặn chống gian lận "max 3 tài khoản/IP" của chính app).
PHONE="09$(printf '%08d' $(( (RANDOM * 32768 + RANDOM) % 100000000 )))"
TGID=$(( 100000000 + (RANDOM * 32768 + RANDOM) % 800000000 ))
IPX="113.$((RANDOM % 255)).$((RANDOM % 255)).$((RANDOM % 254 + 1))"
FF=(-H "X-Forwarded-For: $IPX")
echo "  (test với phone=$PHONE, telegram_id=$TGID, ip=$IPX)"

echo "=============== 1) Bấm nút Telegram ở bước 1 (bug cũ: chỉ reload) ==============="
rm -f /tmp/cj.txt
curl -s -c /tmp/cj.txt "$B/register" -o /tmp/p1.html
TOK=$(csrf /tmp/p1.html)
echo "  CSRF lấy được: ${TOK:0:12}..."
C1=$(curl -s -b /tmp/cj.txt -c /tmp/cj.txt -o /tmp/tg.html -w '%{http_code}' "${FF[@]}" \
     -X POST "$B/register/telegram" --data-urlencode "_csrf=$TOK" --data-urlencode "phone=$PHONE")
echo "  POST /register/telegram -> HTTP $C1"
echo "  Link bot trong trang: $(grep -o 'https://t.me/[^"]*' /tmp/tg.html | head -1)"
echo "  Số điện thoại lưu DB: $(j 'SELECT phone,status,purpose FROM telegram_verify ORDER BY id DESC LIMIT 1')"
TGTOKEN=$(php -r 'require "app/bootstrap.php"; echo (string)App\Database::value("SELECT token FROM telegram_verify ORDER BY id DESC LIMIT 1");')

echo "=============== 2) Bot nhận /start (gọi webhook như Telegram gọi) ==============="
curl -s -X POST "$B/api/telegram/webhook" -H 'Content-Type: application/json' \
  -d "{\"message\":{\"chat\":{\"id\":$TGID},\"from\":{\"id\":$TGID,\"username\":\"tester\"},\"text\":\"/start $TGTOKEN\"}}" \
  -o /tmp/wh.txt -w '  webhook HTTP %{http_code} body=%{size_download}B\n'
echo "  Trạng thái row: $(j 'SELECT status,telegram_user_id FROM telegram_verify ORDER BY id DESC LIMIT 1')"

echo "=============== 2b) Các trường hợp /start (bot phải nói rõ lý do) ==============="
TG2=$(( TGID + 1 ))
# a) /start trống (người dùng tự mở bot, không qua link)
curl -s -o /dev/null -X POST "$B/api/telegram/webhook" -H 'Content-Type: application/json' \
  -d "{\"message\":{\"chat\":{\"id\":$TG2},\"from\":{\"id\":$TG2,\"username\":\"tester\"},\"text\":\"/start\"}}"
# b) token không tồn tại
curl -s -o /dev/null -X POST "$B/api/telegram/webhook" -H 'Content-Type: application/json' \
  -d "{\"message\":{\"chat\":{\"id\":$TG2},\"from\":{\"id\":$TG2,\"username\":\"tester\"},\"text\":\"/start khong_ton_tai_zzz\"}}"
# c) token đã hết hạn
php -r 'require "app/bootstrap.php"; App\Database::run("INSERT INTO telegram_verify(token,phone,purpose,ip,created_at,expires_at) VALUES(?,?,?,?,?,?)", ["expiredtoken123","84900000001","register","127.0.0.1",date("Y-m-d H:i:s",time()-7200),date("Y-m-d H:i:s",time()-3600)]);' 2>/dev/null
curl -s -o /dev/null -X POST "$B/api/telegram/webhook" -H 'Content-Type: application/json' \
  -d "{\"message\":{\"chat\":{\"id\":$TG2},\"from\":{\"id\":$TG2,\"username\":\"tester\"},\"text\":\"/start expiredtoken123\"}}"
sleep 1
grep -o '\[telegram\] /start token=[^ ]* chat=[0-9]* => [a-z_]*' /tmp/srv.log | tail -4 | sed 's/^/  /'
echo "  Row token hết hạn vẫn pending: $(j "SELECT status FROM telegram_verify WHERE token = 'expiredtoken123'")"
echo

echo "=============== 3) Poll trạng thái (JS đang gọi) ==============="
POLL=$(curl -s -b /tmp/cj.txt -c /tmp/cj.txt -X POST "$B/register/telegram-status" \
  -H "X-CSRF-Token: $TOK" --data-urlencode "_csrf=$TOK")
echo "  Response: $POLL"

echo "=============== 4) Hoàn tất đăng ký (mật khẩu) ==============="
sleep 4
curl -s -b /tmp/cj.txt -c /tmp/cj.txt "$B/register/step2" -o /tmp/step2.html
echo "  step2 có form mật khẩu: $(grep -c 'name="password"' /tmp/step2.html) | đã xác thực TG: $(grep -c 'đã xác thực qua Telegram' /tmp/step2.html)"
C2=$(curl -s -b /tmp/cj.txt -c /tmp/cj.txt -o /tmp/after.html -w '%{http_code}|%{redirect_url}' "${FF[@]}" \
   -X POST "$B/register/verify" --data-urlencode "_csrf=$TOK" --data-urlencode 'password=Th4nhd4tmc9' \
   --data-urlencode 'password_confirm=Th4nhd4tmc9' --data-urlencode 'full_name=Tester' --data-urlencode "form_started=0")
echo "  POST /register/verify -> $C2"
echo "  Flash (nếu có): $(grep -o 'class=\"flash[^\"]*\"[^<]*' /tmp/after.html | head -1)"
echo "  User trong DB: $(j 'SELECT phone,status,telegram_user_id FROM users ORDER BY id DESC LIMIT 1')"

echo "=============== 5) Đăng xuất + đăng nhập (lỗi 25P02 trước đây) ==============="
curl -s -b /tmp/cj.txt -c /tmp/cj.txt -X POST "$B/logout" --data-urlencode "_csrf=$TOK" -o /dev/null -w '  logout HTTP %{http_code}\n'
curl -s -c /tmp/cj2.txt "$B/login" -o /tmp/login.html
TOK2=$(csrf /tmp/login.html)
C3=$(curl -s -b /tmp/cj2.txt -c /tmp/cj2.txt -o /tmp/login2.html -w '%{http_code}' \
     -X POST "$B/login" --data-urlencode "_csrf=$TOK2" --data-urlencode "phone=$PHONE" --data-urlencode 'password=Th4nhd4tmc9')
echo "  POST /login -> HTTP $C3 (không được 500)"
echo "  Có 'Fatal error' trong body: $(grep -c 'Fatal error' /tmp/login2.html)"
echo "  Có bước OTP (đúng vì máy mới): $(grep -c 'name="otp_code"' /tmp/login2.html)"

echo "=============== 6) Sai mật khẩu nhiều lần (rate limiter + transaction) ==============="
for i in 1 2 3; do
  curl -s -b /tmp/cj2.txt -c /tmp/cj2.txt -o /tmp/bad.html -w "  lần $i: HTTP %{http_code}\n" \
    -X POST "$B/login" --data-urlencode "_csrf=$TOK2" --data-urlencode "phone=$PHONE" --data-urlencode 'password=wrongpass'
done
echo "  rate_buckets: $(j 'SELECT bucket,hits FROM rate_buckets ORDER BY bucket LIMIT 3')"

echo "=============== 7) Trang chẩn đoán admin ==============="
php bin/create-admin.php >/tmp/admin.txt 2>&1 || true
tail -2 /tmp/admin.txt | sed 's/^/  /'
rm -f /tmp/cja.txt
curl -s -c /tmp/cja.txt "$B/admin/login" -o /tmp/al.html
TOK3=$(csrf /tmp/al.html)
curl -s -b /tmp/cja.txt -c /tmp/cja.txt -o /dev/null -w '  POST /admin/login -> HTTP %{http_code}\n' \
  -X POST "$B/admin/login" --data-urlencode "_csrf=$TOK3" --data-urlencode 'username=admin' --data-urlencode 'password=Th4nhd4tmc9'
C4=$(curl -s -b /tmp/cja.txt -o /tmp/diag.html -w '%{http_code}' "$B/admin/diag")
echo "  GET /admin/diag -> HTTP $C4"
sed -e 's/<[^>]*>/ /g' /tmp/diag.html | tr -s ' \n' ' \n' | grep -A2 -iE 'Test transaction|Cột của rate_buckets|Driver DB|RateLimiter|Số bản ghi|Telegram' | head -30 | sed 's/^/  /'

echo "=============== 8) Nut dat lai webhook Telegram (admin) ==============="
curl -s -b /tmp/cja.txt "$B/admin/diag" -o /tmp/diag2.html
TA=$(csrf /tmp/diag2.html)
curl -s -b /tmp/cja.txt -c /tmp/cja.txt -o /tmp/wset.html -w '  POST /admin/diag/telegram-webhook -> HTTP %{http_code}\n' -X POST "$B/admin/diag/telegram-webhook" --data-urlencode "_csrf=$TA"
grep -o 'class="flash[^"]*"[^<]*' /tmp/wset.html | head -1 | sed 's/^/  /'
echo "  (token gia lap o local -> phai bao loi that bai, khong duoc 500)"

echo "=============== log server (chỉ dòng lỗi) ==============="
grep -iE 'fatal|\[db\]|\[schema\]|\[rate\]|Uncaught' /tmp/srv.log | head -20 | sed 's/^/  /'
echo "(hết)"
