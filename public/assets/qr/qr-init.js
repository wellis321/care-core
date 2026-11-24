// Draws the two-step set-up QR code in the browser from the otpauth address on the page, so the secret never goes to any other site.
// Uses the MIT-licensed qrcode-generator (qrcode.js, same folder). Without JavaScript the typed key below it still works.
(function () {
  var box = document.querySelector('[data-qr]');
  if (!box || typeof qrcode !== 'function') return;
  try {
    var q = qrcode(0, 'M'); q.addData(box.getAttribute('data-qr')); q.make();
    var n = q.getModuleCount(), m = 4, d = '';
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (q.isDark(r, c)) d += 'M' + (c + m) + ' ' + (r + m) + 'h1v1h-1z';
    var s = n + m * 2;
    box.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + s + ' ' + s + '" width="220" height="220" role="img" aria-label="QR code to scan with your authenticator app" shape-rendering="crispEdges"><rect width="' + s + '" height="' + s + '" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
    // Styled from here, not in the page, because some products forbid inline style attributes.
    var st = box.style; st.display = 'inline-block'; st.background = '#fff'; st.padding = '4px'; st.border = '1px solid #d9d6cf'; st.borderRadius = '6px'; st.lineHeight = '0';
    box.hidden = false;
  } catch (e) { /* leave the typed key as the way in */ }
})();
