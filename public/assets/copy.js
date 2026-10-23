/* The "copy it now" panel: brings it into view, selects the value, and copies it with one click. */
(function () {
  var panel = document.getElementById('new-secret');
  if (!panel) { return; }
  var input = document.getElementById('reveal-value');
  var btn = document.getElementById('reveal-copy');
  var status = document.getElementById('reveal-status');
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  panel.scrollIntoView({ block: 'start', behavior: calm ? 'auto' : 'smooth' });
  panel.focus({ preventScroll: true });
  input.addEventListener('focus', function () { input.select(); });
  input.addEventListener('click', function () { input.select(); });
  btn.addEventListener('click', function () {
    var done = function () { status.textContent = 'Copied. Paste it somewhere safe now.'; btn.textContent = 'Copied'; };
    var failed = function () { input.focus(); input.select(); status.textContent = 'Select the text above and copy it with Ctrl+C (Cmd+C on a Mac).'; };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done, failed);
    } else {
      input.focus(); input.select();
      try { document.execCommand('copy') ? done() : failed(); } catch (e) { failed(); }
    }
  });
})();
