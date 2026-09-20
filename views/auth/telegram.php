<div class="card auth">
<h2>Xác thực qua Telegram</h2>
<input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
<ol class="muted" style="padding-left:18px;margin:0 0 12px">
  <li>Bấm nút dưới để mở Telegram, rồi bấm <b>START</b> trong bot.</li>
  <li>Quay lại tab này — hệ thống tự phát hiện (link dùng 1 lần, hết hạn sau 15 phút).</li>
</ol>
<p><a class="btn btn-tg" href="<?= e($link) ?>" target="_blank" rel="noopener">🚀 Mở bot @<?= e($bot) ?></a></p>
<p id="tg-status" class="muted">⏳ Đang chờ bạn bấm START trong Telegram...</p>
<p><button id="tg-check" class="btn ghost" type="button">⟳ Tôi đã bấm START — kiểm tra ngay</button></p>
<p id="tg-retry" style="display:none" class="muted"><button class="btn ghost" type="button" onclick="location.reload()">⟳ Tải lại trang</button></p>
<p class="muted">Không mở được Telegram? <a href="/register">← Quay lại đăng ký</a> (chọn cách khác).</p>
</div>
<script src="/assets/telegram.js" defer></script>
