(() => {
  const form = document.querySelector('[data-link4m-form]');
  if (!form) return;
  const events = form.querySelector('[name=ev]');
  const button = form.querySelector('button[type=submit]');
  let count = 0;
  ['mousemove', 'touchstart', 'scroll', 'keydown'].forEach((name) => {
    window.addEventListener(name, () => {
      count += 1;
      if (events) events.value = String(count);
    }, { passive: true });
  });
  window.setTimeout(() => {
    if (button) button.disabled = false;
  }, 3000);
})();
