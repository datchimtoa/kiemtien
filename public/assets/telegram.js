(function () {
  'use strict';
  var status = document.getElementById('tg-status');
  if (!status) return;
  var csrfEl = document.querySelector('input[name=_csrf]');
  if (!csrfEl) return;
  var check = document.getElementById('tg-check');
  var tries = 0, max = 300, started = Date.now(), busy = false;

  function say(msg) { status.textContent = msg; }

  function poll() {
    if (busy) return;
    busy = true;
    if (tries++ > max) {
      busy = false;
      say('⌛ Link đã hết hạn. Vui lòng quay lại đăng ký để tạo link mới.');
      var retry = document.getElementById('tg-retry');
      if (retry) retry.style.display = '';
      return;
    }
    // Nhắc người dùng sau ~20s nếu bot chưa nhận được START.
    var waited = Math.round((Date.now() - started) / 1000);
    if (waited > 20 && tries % 10 === 1 && status.dataset.done !== '1') {
      say('⏳ Chưa nhận được xác thực (' + waited + 's). Nhớ bấm nút START trong bot Telegram.');
    }
    fetch('/register/telegram-status', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfEl.value },
      body: new URLSearchParams({ _csrf: csrfEl.value })
    }).then(function (r) { return r.json(); }).then(function (j) {
      busy = false;
      if (j && j.verified) {
        status.dataset.done = '1';
        say('✅ Đã xác thực! Đang chuyển tiếp...');
        location.href = '/register/step2';
        return;
      }
      if (j && j.error === 'not_started') {
        say('⌛ Phiên đăng ký đã hết. Vui lòng quay lại đăng ký lại.');
        return;
      }
      setTimeout(poll, 1000);
    }).catch(function () { busy = false; setTimeout(poll, 3000); });
  }

  if (check) {
    check.addEventListener('click', function () {
      tries = 0;
      started = Date.now();
      say('⏳ Đang kiểm tra...');
      poll();
    });
  }
  poll();
})();
