(function () {
  'use strict';
  if (!document.getElementById('tg-status')) return;
  var csrfEl = document.querySelector('input[name=_csrf]');
  if (!csrfEl) return;
  var tries = 0, max = 300;
  function poll() {
    if (tries++ > max) {
      document.getElementById('tg-status').textContent = '⌛ Link hết hạn. Vui lòng tạo lại.';
      return;
    }
    fetch('/register/telegram-status', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfEl.value },
      body: new URLSearchParams({ _csrf: csrfEl.value })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.verified) {
        document.getElementById('tg-status').textContent = '✅ Đã xác thực! Đang chuyển tiếp...';
        location.href = '/register/step2';
        return;
      }
      setTimeout(poll, 1000);
    }).catch(function () { setTimeout(poll, 3000); });
  }
  poll();
})();
