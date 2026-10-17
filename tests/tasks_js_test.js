'use strict';

// Execute the shipped script with a tiny DOM and fake clock: no browser/network needed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.resolve(__dirname, '../public/assets/app.js'), 'utf8');

function fixture(refresh = true, claimDone = false) {
  let now = 0;
  let reloads = 0;
  const timers = [];
  const notices = [];
  const events = {};
  const windowEvents = {};
  const countdown = { dataset: { taskCooldown: '5' }, textContent: '' };
  const button = { dataset: { url: '/tasks/start', task: '12' }, disabled: false,
    textContent: 'Bắt đầu', addEventListener: function (name, callback) { this[name] = callback; } };
  const doc = {
    readyState: 'loading', hidden: false,
    addEventListener: function (name, callback) { events[name] = callback; },
    querySelector: function (selector) {
      if (selector === 'meta[name=csrf-token]') { return { content: 'mock-csrf' }; }
      if (selector === '[data-task-refresh]' && refresh) { return { dataset: { taskRefresh: '5' } }; }
      if (selector === '.page-head') { return { insertAdjacentElement: (position, notice) => notices.push(notice) }; }
      return null;
    },
    querySelectorAll: function (selector) {
      if (selector === '.do-task') { return [button]; }
      if (selector === '[data-task-cooldown]') { return [countdown]; }
      return [];
    },
    getElementById: function () { return null; },
    createElement: function () { return {}; }
  };
  const context = { document: doc, Date: { now: () => now }, URLSearchParams,
    window: { location: { search: claimDone ? '?claim_done=1' : '' }, addEventListener: (name, callback) => { windowEvents[name] = callback; } },
    location: { reload: () => { reloads++; } },
    setInterval: callback => { timers.push(callback); },
    fetch: () => new Promise(() => {}) };
  vm.runInNewContext(script, context);
  return { doc, countdown, button, timers, notices, windowEvents, setTime: value => { now = value; }, reloads: () => reloads };
}

const page = fixture();
page.setTime(2000);
page.timers[0]();
assert.equal(page.countdown.textContent, 'Chờ 3s');
assert.equal(page.reloads(), 0);
page.doc.hidden = true;
page.setTime(6000);
page.timers[0]();
assert.equal(page.reloads(), 0, 'background pages must not poll');
assert.equal(page.countdown.textContent, 'Đang cập nhật...');
page.doc.hidden = false;
page.windowEvents.focus();
assert.equal(page.reloads(), 1, 'returning to expired page revalidates with server');
assert.equal(page.button.disabled, false, 'timer does not alter task availability');
console.log('PASS countdown, hidden-tab suppression and focus refresh');

const starting = fixture();
starting.button.click({ preventDefault: () => {} });
starting.setTime(6000);
starting.timers[0]();
assert.equal(starting.reloads(), 0, 'do not reload while creating a one-time link');
assert.equal(starting.button.disabled, true);
console.log('PASS refresh does not interrupt task creation');

assert.equal(fixture(false).timers.length, 0, 'unrelated pages do not auto-refresh');
console.log('PASS no task refresh outside task list');

const returned = fixture(false, true);
assert.equal(returned.notices.length, 1);
assert.equal(returned.notices[0].className, 'flash info', 'browser return is not a payment success state');
assert.match(returned.notices[0].textContent, /sau khi có xác nhận hợp lệ/);
assert.equal(returned.button.disabled, false, 'browser return never changes availability');
console.log('PASS browser return only explains pending verification');
