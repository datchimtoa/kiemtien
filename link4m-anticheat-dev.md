# Link4m Anti-Cheat / Anti-Bypass / View Reconciliation: Tài liệu phát triển

Stack: PHP 8+, MySQL (PDO), Cloudflare Turnstile. Destination của shortlink luôn là trang của mình (`/cb.php`).

**Nguyên tắc:** không tin Link4m, chỉ tin dữ liệu first-party. Trả user theo số liệu verified của mình, đối soát với provider sau.

---

## 1. Mục tiêu

1. **Anti-bypass:** chặn tool vượt link tự động (chạy trên server của họ, vượt tức thời).
2. **Anti-cheat:** chặn multi-account, VPN/proxy/datacenter, bot, vượt giới hạn 2 view/IP.
3. **Check view:** đối soát view Link4m báo với view mình verified, phát hiện cắt/đớp view có bằng chứng số liệu.

---

## 2. Kiến trúc tổng quan

```
User bấm nhiệm vụ → start.php
   ├─ tạo task_session (token, HMAC, ip, ua, fp)
   ├─ set cookie `ts` = token (gắn với trình duyệt user)
   ├─ gọi API Link4m, url = /cb.php?t=TOKEN&sig=HMAC (mỗi lần 1 link riêng)
   └─ redirect sang shortlink
User vượt link → về cb.php
   ├─ GET: ghi arrived_at, referer; hiện Turnstile + đếm sự kiện chuột/chạm
   └─ POST: verify Turnstile → chấm điểm rủi ro → verified / hold / rejected
Cron hàng ngày
   ├─ import số liệu Link4m (CSV/dashboard) vào provider_reports
   ├─ tính ratio theo cohort (chốt sau 5 ngày)
   └─ chạy link test (nhóm đối chứng), cảnh báo nếu ratio thấp
```

---

## 3. Database

```sql
CREATE TABLE task_sessions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  token CHAR(32) NOT NULL UNIQUE,
  user_id BIGINT NOT NULL,
  ip VARCHAR(45) NOT NULL,
  ua VARCHAR(255),
  fp VARCHAR(64) NULL,
  short_url VARCHAR(255),
  alias VARCHAR(100),                -- phần cuối của short_url, dùng để join đối soát
  provider VARCHAR(20) DEFAULT 'link4m',
  status ENUM('pending','verified','hold','rejected') DEFAULT 'pending',
  risk_score INT NULL,
  reasons VARCHAR(255) NULL,
  is_control TINYINT(1) DEFAULT 0,   -- 1 = link test của mình
  link4m_countable TINYINT(1) NULL,  -- theo luật Link4m (IP<=2, không VPN, đúng quốc gia)
  created_at DATETIME NOT NULL,
  arrived_at DATETIME NULL,
  arrive_ref VARCHAR(255) NULL,
  arrive_ip VARCHAR(45) NULL,
  arrive_ua VARCHAR(255) NULL,
  completed_at DATETIME NULL,
  INDEX (ip, created_at), INDEX (user_id, created_at), INDEX (alias), INDEX (status, created_at)
);

CREATE TABLE provider_reports (
  day DATE,
  provider VARCHAR(20),
  alias VARCHAR(100),
  views_reported INT,
  earnings_reported DECIMAL(10,4),
  PRIMARY KEY (day, provider, alias)
);

CREATE TABLE provider_ratio_daily (
  day DATE,
  provider VARCHAR(20),
  expected INT,
  reported INT,
  ratio DECIMAL(5,3),
  control_expected INT DEFAULT 0,
  control_reported INT DEFAULT 0,
  control_ratio DECIMAL(5,3) NULL,
  PRIMARY KEY (day, provider)
);

CREATE TABLE ip_blacklist (ip VARCHAR(45) PRIMARY KEY, reason VARCHAR(100), created_at DATETIME);
```

---

## 4. Config: `config.php`

```php
<?php
const SECRET           = 'doi-chuoi-ngau-nhien-dai';
const LINK4M_TOKEN     = 'theapitoken';
const SITE             = 'https://yoursite.com';
const TURNSTILE_SITE   = '...';
const TURNSTILE_SECRET = '...';
const MIN_SECONDS      = 20;   // dưới mức này = vượt tức thời
const SOFT_SECONDS     = 30;
const TOKEN_TTL        = 3600;
const MAX_PER_IP_DAY   = 2;    // khớp luật Link4m

$pdo = new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'user', 'pass',
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function real_ip(): string {   // sau Cloudflare
  return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
}
function subnet24(string $ip): string { return implode('.', array_slice(explode('.', $ip), 0, 3)); }
function current_user_id() { /* theo hệ thống auth của bạn */ }
function credit_user(int $uid) { /* cộng thưởng, dùng transaction */ }
function is_vn(): bool { return ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '') === 'VN'; } // 100% Việt Nam, lấy từ Cloudflare
```

---

## 5. `start.php`: tạo nhiệm vụ

```php
<?php
require 'config.php';
$uid = current_user_id();
$ip  = real_ip();
$ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$q = $pdo->prepare("SELECT COUNT(*) FROM task_sessions
  WHERE ip=? AND DATE(created_at)=CURDATE() AND status!='rejected' AND is_control=0");
$q->execute([$ip]);
if ($q->fetchColumn() >= MAX_PER_IP_DAY) die('Hết lượt hôm nay cho IP này');

$token = bin2hex(random_bytes(16));
$sig   = hash_hmac('sha256', $token, SECRET);
$cb    = SITE . "/cb.php?t=$token&sig=$sig";

$res = json_decode(@file_get_contents(
  "https://link4m.co/api-shorten/v2?api=" . LINK4M_TOKEN . "&url=" . urlencode($cb)), true);
if (($res['status'] ?? '') !== 'success') die('Lỗi tạo link');

$alias = basename(parse_url($res['shortenedUrl'], PHP_URL_PATH));
$pdo->prepare("INSERT INTO task_sessions (token,user_id,ip,ua,short_url,alias,created_at)
               VALUES (?,?,?,?,?,?,NOW())")
    ->execute([$token, $uid, $ip, $ua, $res['shortenedUrl'], $alias]);

setcookie('ts', $token, ['expires'=>time()+TOKEN_TTL,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
header('Location: ' . $res['shortenedUrl']);
```

---

## 6. `cb.php`: callback và chấm điểm

```php
<?php
require 'config.php';
$t   = $_REQUEST['t']   ?? '';
$sig = $_REQUEST['sig'] ?? '';
if (!hash_equals(hash_hmac('sha256', $t, SECRET), $sig)) die('Invalid');

$s = $pdo->prepare("SELECT * FROM task_sessions WHERE token=?");
$s->execute([$t]);
$row = $s->fetch(PDO::FETCH_ASSOC);
if (!$row || $row['status'] !== 'pending') die('Link đã dùng/không hợp lệ');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $pdo->prepare("UPDATE task_sessions SET arrived_at=NOW(), arrive_ref=?, arrive_ip=?, arrive_ua=?
                 WHERE id=? AND arrived_at IS NULL")
      ->execute([$_SERVER['HTTP_REFERER'] ?? '', real_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,255), $row['id']]);
  ?>
  <form method="post">
    <input type="hidden" name="t" value="<?=htmlspecialchars($t)?>">
    <input type="hidden" name="sig" value="<?=htmlspecialchars($sig)?>">
    <input type="hidden" name="ev" id="ev" value="0">
    <div class="cf-turnstile" data-sitekey="<?=TURNSTILE_SITE?>"></div>
    <button id="b" disabled>Nhận thưởng</button>
  </form>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async></script>
  <script>
    let ev=0;
    ['mousemove','touchstart','scroll','keydown'].forEach(e =>
      addEventListener(e, () => { ev++; document.getElementById('ev').value = ev; }, {passive:true}));
    setTimeout(() => document.getElementById('b').disabled = false, 3000);
  </script>
  <?php exit;
}

$ts = json_decode(file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false,
  stream_context_create(['http'=>[
    'method'=>'POST','header'=>'Content-type: application/x-www-form-urlencoded',
    'content'=>http_build_query(['secret'=>TURNSTILE_SECRET,
      'response'=>$_POST['cf-turnstile-response'] ?? '','remoteip'=>real_ip()])]])), true);

$score = 100; $why = [];
$pen = function($p, $r) use (&$score, &$why) { $score -= $p; $why[] = $r; };

if (empty($ts['success']))                                    $pen(60, 'turnstile');
if (($_COOKIE['ts'] ?? '') !== $t)                            $pen(60, 'cookie_mismatch');
$elapsed = time() - strtotime($row['created_at']);
if ($elapsed < MIN_SECONDS)                                   $pen(60, 'too_fast');
elseif ($elapsed < SOFT_SECONDS)                              $pen(15, 'fast');
if ($elapsed > TOKEN_TTL)                                     $pen(100, 'expired');
if (!preg_match('~link4m\.(co|com)~i', $row['arrive_ref']))   $pen(15, 'no_referer');
if (subnet24($row['ip']) !== subnet24(real_ip()))             $pen(15, 'ip_changed');
if ($row['ua'] !== substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,255)) $pen(20, 'ua_changed');
if ((int)($_POST['ev'] ?? 0) < 1)                             $pen(30, 'no_interaction');
if (!is_vn())                                                 $pen(100, 'not_vn');

$status = $score >= 70 ? 'verified' : ($score >= 40 ? 'hold' : 'rejected');

$u = $pdo->prepare("UPDATE task_sessions SET status=?, risk_score=?, reasons=?, completed_at=NOW()
                    WHERE id=? AND status='pending'");   // atomic, chống double-claim
$u->execute([$status, $score, implode(',', $why), $row['id']]);
if ($u->rowCount() === 1 && $status === 'verified' && !$row['is_control']) credit_user($row['user_id']);

echo $status === 'verified' ? 'Đã cộng thưởng' : ($status === 'hold' ? 'Đang chờ duyệt' : 'Bị từ chối');
```

**Bảng điểm:**

| Điều kiện | Trừ |
|---|---|
| Turnstile fail | 60 |
| Cookie không khớp token (bypass server-side) | 60 |
| Vượt dưới 20s | 60 |
| Vượt 20-30s | 15 |
| Không có referer Link4m | 15 |
| Đổi subnet /24 | 15 |
| Đổi UA | 20 |
| Không có sự kiện chuột/chạm | 30 |
| IP ngoài Việt Nam (Cloudflare `CF-IPCountry`) | 100 (loại) |

Ngưỡng: `>=70` verified, `40-69` hold (duyệt tay), `<40` rejected. Sau 1-2 tuần dữ liệu thật, chỉnh lại trọng số theo tỉ lệ false positive.

---

## 7. Anti-cheat phía user (cron/middleware)

- Giới hạn theo IP, fingerprint, account mỗi ngày.
- Velocity: quá N task/giờ theo IP/fp/account thì hold.
- Gom cụm multi-account: cùng fp hoặc cùng IP/24 hoặc cùng thiết bị thì gắn cờ.
- Không check VPN/datacenter (quy ước: IP mới là tính). Bù lại phải giới hạn chặt theo account/fp/ngày để chặn farm bằng đổi IP 4G.
- Account có tỉ lệ `rejected/hold` cao thì giảm hạn mức hoặc khóa rút.
- Bảng `ip_blacklist` cho IP vi phạm lặp.

---

## 8. Đối soát view

### 8.1 Nguồn số liệu (Link4m không có CSV, không có postback)

Chỉ còn dashboard để lấy số liệu. Hai chế độ, dùng được cái nào tùy dashboard:

**Mode A, per-link:** nếu dashboard có bảng link kèm view/doanh thu, viết scraper đăng nhập bằng cookie session (lấy từ trình duyệt, hết hạn thì cập nhật) rồi đọc bảng.

```php
<?php // scrape.php (selector/URL chỉnh theo HTML thật của dashboard)
require 'config.php';
$ch = curl_init('https://link4m.com/member/links');   // URL placeholder
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_COOKIE=>LINK4M_COOKIE, CURLOPT_USERAGENT=>'Mozilla/5.0']);
$html = curl_exec($ch);
$dom = new DOMDocument; @$dom->loadHTML($html);
$xp = new DOMXPath($dom);
$st = $pdo->prepare("REPLACE INTO provider_reports VALUES (CURDATE(),'link4m',?,?,?)");
foreach ($xp->query("//table//tr[td]") as $tr) {           // placeholder
  $td = $tr->getElementsByTagName('td');
  $alias = basename(trim($td->item(0)->textContent));      // cột alias/link
  $views = (int)preg_replace('/\D/','',$td->item(1)->textContent);
  $earn  = (float)preg_replace('/[^\d.]/','',$td->item(2)->textContent);
  $st->execute([$alias,$views,$earn]);
}
```

**Mode B, tổng theo ngày:** nếu dashboard chỉ có tổng, nhập tay (hoặc scrape) mỗi ngày vào `provider_reports` với `alias='*'`:

```sql
REPLACE INTO provider_reports VALUES ('2026-10-16','link4m','*',1234,5.67);
```

Mode B kém chính xác hơn (không biết view nào bị cắt), bù bằng link test (mục 8.4).

### 8.2 Tính `link4m_countable`

Quy ước: IP mới là tính, mỗi IP tối đa 2 view/ngày, 100% Việt Nam (đã loại IP ngoài VN ở `cb.php`). Chạy sau khi session hoàn tất (cron mỗi giờ), cần MySQL 8:

```sql
UPDATE task_sessions t
JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY ip, DATE(created_at) ORDER BY completed_at) rn
      FROM task_sessions WHERE status='verified' AND is_control=0) x ON x.id = t.id
SET t.link4m_countable = (x.rn <= 2);
```

### 8.3 Ratio theo cohort (chốt sau 5 ngày)

```sql
SELECT DATE(t.created_at) d,
       SUM(t.link4m_countable) expected,
       SUM(r.views_reported)   reported,
       ROUND(SUM(r.views_reported)/NULLIF(SUM(t.link4m_countable),0),3) ratio
FROM task_sessions t
LEFT JOIN provider_reports r ON r.alias = t.alias AND r.provider = t.provider
WHERE t.status='verified' AND t.is_control=0
  AND t.created_at < NOW() - INTERVAL 5 DAY
GROUP BY d ORDER BY d;
```

Mode B (tổng theo ngày):

```sql
SELECT d.day, d.expected, r.views_reported,
       ROUND(r.views_reported/d.expected,3) ratio
FROM (SELECT DATE(created_at) day, SUM(link4m_countable) expected
      FROM task_sessions WHERE status='verified' AND is_control=0 GROUP BY day) d
JOIN provider_reports r ON r.day=d.day AND r.provider='link4m' AND r.alias='*'
WHERE d.day < CURDATE() - INTERVAL 5 DAY;
```

### 8.4 Nhóm đối chứng (link test)

- Mỗi ngày tự vượt N link test (`is_control=1`) bằng IP sạch (4G/residential, mỗi view 1 IP), thao tác như người thật.
- So control_ratio với dashboard. Cỡ mẫu vài trăm lượt mới kết luận (dùng khoảng tin cậy Wilson).
- Control ratio dưới ~90% kéo dài thì provider cắt view.
- Mode B: chụp tổng view dashboard **trước và sau** mỗi batch link test (làm lúc ít traffic user, hoặc khung giờ riêng), lấy chênh lệch làm số provider tính cho batch đó.

```php
// Khoảng tin cậy Wilson 95%
function wilson(int $s, int $n): array {
  if (!$n) return [0, 0];
  $z = 1.96; $p = $s / $n;
  $d = 1 + $z*$z/$n;
  $c = ($p + $z*$z/(2*$n)) / $d;
  $m = $z * sqrt(($p*(1-$p) + $z*$z/(4*$n)) / $n) / $d;
  return [max(0, $c - $m), min(1, $c + $m)];
}
```

### 8.5 Dấu hiệu đớp view

- Ratio giảm khi volume tăng.
- Ratio thấp ở link/quốc gia giá trị cao, bình thường ở link giá trị thấp.
- Ratio cố định một số (vd luôn 80% ±1%): lọc hợp lệ thì dao động, cắt thẳng thì đều.
- Control ratio thấp tương đương ratio user thường.

---

## 9. Tiền và đa provider

- Trả user theo số verified của mình, không chờ provider duyệt. Giữ quỹ đệm 10-20%.
- `effective_RPM = tiền thực nhận / expected` theo provider.
- Khi có từ 2 provider trở lên: chia traffic theo effective_RPM cao nhất, provider ratio thấp thì giảm tỉ lệ.

---

## 10. Cron

| Việc | Tần suất |
|---|---|
| Hết hạn session `pending` quá TTL → `rejected` | 5 phút |
| Import số liệu provider | Hàng ngày |
| Tính `provider_ratio_daily` | Hàng ngày (cohort D-5) |
| Chạy link test | Hàng ngày |
| Cảnh báo (Telegram) khi ratio/control_ratio dưới ngưỡng | Hàng ngày |

---

## 11. Lộ trình triển khai

1. **Phase 1:** schema + `start.php` + `cb.php` (HMAC, cookie, thời gian, 1 lần dùng). Chạy thật, ghi `reasons`, chưa chặn cứng.
2. **Phase 2:** Turnstile, referer/IP/UA, datacenter check, middleware giới hạn. Bật chặn theo ngưỡng.
3. **Phase 3:** import số liệu Link4m, `link4m_countable`, ratio theo cohort, dashboard admin.
4. **Phase 4:** link test tự động, Wilson, cảnh báo, chia traffic đa provider.

---

## 12. Lưu ý

- Cloudflare: bật Authenticated Origin Pulls hoặc chỉ cho phép IP Cloudflare, nếu không `CF-Connecting-IP` có thể bị giả.
- Mobile đổi IP giữa chừng là bình thường, nên IP đổi chỉ trừ điểm, không chặn cứng.
- Referer có thể bị strip, chỉ trừ điểm nhẹ.
- Cookie `ts` là lớp mạnh nhất chống bypass server-side. Nhớ set trước khi redirect sang Link4m và dùng `SameSite=Lax` để cookie còn khi user quay về từ trang ngoài.
- Dữ liệu cá nhân (IP, UA, fp): nên có điều khoản sử dụng và định kỳ xóa log cũ.

## 13. Đã chốt và còn thiếu

Đã chốt: Link4m không có CSV/postback; 100% Việt Nam; không check VPN/ASN, IP mới là tính.

Còn thiếu:
- Dashboard Link4m có bảng từng link kèm view/doanh thu không (quyết định Mode A hay B)? Nếu có, gửi ảnh hoặc HTML trang đó để viết scraper đúng selector.
- Cách đăng nhập (cookie session có hết hạn nhanh không, có captcha không).
