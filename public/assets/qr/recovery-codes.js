// Adds "Download", "Print" and "Copy all" under a list of recovery codes. The codes are read from the page and never leave the browser.
(function () {
  var list = document.querySelector('[data-recovery]');
  if (!list) return;
  var codes = [].map.call(list.querySelectorAll('li'), function (li) { return li.textContent.trim(); }).filter(Boolean);
  if (!codes.length) return;
  var product = list.getAttribute('data-product') || 'Recovery codes';
  var when = new Date().toISOString().slice(0, 10);
  var text = product + ' recovery codes\r\nMade on ' + when + '\r\n\r\n' + codes.join('\r\n') +
    '\r\n\r\nEach code works once, when you cannot use your authenticator app. Keep this file somewhere safe and apart from your password. ' +
    'When you have used or lost them, make new ones from your account page and the old ones stop working.\r\n';
  var bar = document.createElement('p'); bar.className = 'recovery-actions';
  function button(label, fn) {
    var b = document.createElement('button'); b.type = 'button'; b.className = list.getAttribute('data-btn') || 'btn btn-secondary btn-sm'; b.textContent = label;
    b.addEventListener('click', fn); bar.appendChild(b); bar.appendChild(document.createTextNode(' ')); return b;
  }
  button('Download as a file', function () {
    var url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    var a = document.createElement('a'); a.href = url; a.download = product.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-recovery-codes.txt';
    document.body.appendChild(a); a.click(); document.body.removeChild(a); setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  });
  button('Print', function () {
    var f = document.createElement('iframe'); f.setAttribute('aria-hidden', 'true'); f.tabIndex = -1; f.width = 0; f.height = 0; f.style.position = 'absolute'; f.style.border = '0';
    document.body.appendChild(f); var d = f.contentWindow.document; d.open();
    d.write('<!doctype html><title>' + product + ' recovery codes</title><body><h1>' + product + ' recovery codes</h1><p>Made on ' + when + '. Each code works once. Keep this page somewhere safe.</p><pre>' + codes.join('\n') + '</pre></body>');
    d.close(); f.contentWindow.focus(); f.contentWindow.print(); setTimeout(function () { document.body.removeChild(f); }, 2000);
  });
  var copy = button('Copy all', function () {
    var done = function () { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy all'; }, 2000); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(codes.join('\n')).then(done);
  });
  list.parentNode.insertBefore(bar, list.nextSibling);
})();
