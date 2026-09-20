(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';

  // ---------- device fingerprint (stable device_id in localStorage + soft hash) ----------
  function deviceId() {
    try {
      var k = 'emv_device_id';
      var v = localStorage.getItem(k);
      if (!v) {
        v = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2));
        localStorage.setItem(k, v);
      }
      return v;
    } catch (e) { return 'sid-' + Math.random().toString(36).slice(2); }
  }
  function collectComponents() {
    var c = [
      screen.width + 'x' + screen.height,
      screen.colorDepth, (window.devicePixelRatio || 1),
      (navigator.language || ''), (navigator.languages || []).join(','),
      (Intl.DateTimeFormat().resolvedOptions().timeZone || ''),
      (navigator.hardwareConcurrency || 0), (navigator.deviceMemory || navigator.deviceMemory === 0 ? navigator.deviceMemory : ''),
      (navigator.maxTouchPoints || 0), (navigator.platform || '')
    ].join('|');
    return c;
  }
  function hashStr(s) {
    // FNV-1a 32-bit, hex. Enough for a soft fingerprint (server stores raw too).
    var h = 0x811c9dc5;
    for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = (h * 0x01000193) >>> 0; }
    return ('00000000' + h.toString(16)).slice(-8);
  }
  function sendFingerprint() {
    var comps = collectComponents();
    var payload = { device_id: deviceId(), fp: hashStr(comps), components: comps.slice(0, 480) };
    fetch('/api/fingerprint', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload), credentials: 'same-origin'
    }).catch(function () {});
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', sendFingerprint); }
  else { sendFingerprint(); }

  // ---------- min-form-time guard ----------
  document.querySelectorAll('form[data-started]').forEach(function (f) {
    var started = f.querySelector('input[name=form_started]');
    if (started && !started.value) { started.value = Math.floor(Date.now() / 1000); }
  });

  // ---------- task starter (PubCrypto Link) — auto redirect back to /tasks ----------
  document.querySelectorAll('.do-task').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      var orig = btn.textContent;
      btn.disabled = true; btn.textContent = 'Đang tạo link...';
      fetch(btn.dataset.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ task_id: btn.dataset.task, _csrf: csrf }),
        credentials: 'same-origin'
      }).then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
        .then(function (o) {
          if (o.j && o.j.ok && o.j.redirect) {
            // Open the one-time PubCrypto link in a new tab so the user can complete it,
            // then automatically return to /tasks to pick the next task.
            window.open(o.j.redirect, '_blank', 'noopener');
            btn.textContent = '✅ Đã mở — làm tiếp nhiệm vụ khác';
            setTimeout(function () {
              btn.disabled = false;
              btn.textContent = '🚀 Bắt đầu';
              location.href = '/tasks'; // refresh cooldown/quota state
            }, 3000);
            return;
          }
          alert((o.j && o.j.error) || 'Không tạo được nhiệm vụ. Thử lại sau.');
          btn.disabled = false; btn.textContent = orig;
        })
        .catch(function () {
          alert('Lỗi mạng. Thử lại sau.');
          btn.disabled = false; btn.textContent = orig;
        });
    });
  });

  // ---------- withdraw: hide bank select for wallets ----------
  var methodSel = document.querySelector('select[name=method]');
  var bankWrap = document.getElementById('bank-wrap');
  if (methodSel && bankWrap) {
    var sync = function () { bankWrap.style.display = methodSel.value === 'bank' ? '' : 'none'; };
    methodSel.addEventListener('change', sync); sync();
  }

  // ---------- manual claim-result redirect (PubCrypto may keep user on their site) ----------
  // If user returns to /tasks and PubCrypto set a "back" URL, redirect back to /tasks.
  // This is a safety net in case the postback opens in a popup.
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('claim_done') === '1') {
    // User just completed a task (PubCrypto redirected back) → show a flash-like notice.
    var notice = document.createElement('div');
    notice.className = 'flash success';
    notice.textContent = '✅ Nhiệm vụ đang được xác nhận. Tiền sẽ tự cộng vào ví trong ít phút.';
    var first = document.querySelector('.page-head');
    if (first) { first.insertAdjacentElement('afterend', notice); }
  }
})();
