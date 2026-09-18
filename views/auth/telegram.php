<div class="card auth">
<h2>Xác thực qua Telegram</h2>
<input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
<p>Bước 1 — Bấm nút dưới để mở bot và bấm <b>START</b> trong Telegram:</p>
<p><a class="btn btn-tg" href="<?= e($link) ?>" target="_blank" rel="noopener">🚀 Mở bot @<?= e($bot) ?></a></p>
<p class="muted">Bước 2 — Đợi trang tự chuyển (tối đa 15 phút, link dùng 1 lần).</p>
<p id="tg-status" class="muted">⏳ Đang chờ bạn bấm START trong Telegram...</p>
<p id="tg-retry" style="display:none" class="muted"><button class="btn ghost" onclick="location.reload()">⟳ Tải lại trang nếu không chuyển自动</button></p>
<p class="muted"><a href="/register">← Dùng SMS thay thế</a></p>
</div>
<script src="/assets/telegram.js" defer></script>
