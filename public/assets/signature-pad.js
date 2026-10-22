/* Draw a signature with a mouse, finger or pen. Writes a PNG data URL into the hidden field when the form is sent. */
(function () {
  var canvas = document.getElementById('sigpad');
  if (!canvas) { return; }
  var form = canvas.closest('form');
  var field = form.querySelector('input[name="signature_data"]');
  var ctx = canvas.getContext('2d');
  var drawing = false, dirty = false;

  function size() {
    var r = canvas.getBoundingClientRect(), ratio = window.devicePixelRatio || 1;
    canvas.width = Math.round(r.width * ratio);
    canvas.height = Math.round(r.height * ratio);
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0d2b2e';
    dirty = false;
  }
  function pos(e) { var r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
  canvas.addEventListener('pointerdown', function (e) { drawing = true; canvas.setPointerCapture(e.pointerId); var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); });
  canvas.addEventListener('pointermove', function (e) { if (!drawing) { return; } var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); dirty = true; e.preventDefault(); });
  ['pointerup', 'pointercancel'].forEach(function (n) { canvas.addEventListener(n, function () { drawing = false; }); });
  var clear = document.getElementById('sigclear');
  if (clear) { clear.addEventListener('click', function () { size(); field.value = ''; }); }
  form.addEventListener('submit', function (e) {
    if (dirty) { field.value = canvas.toDataURL('image/png'); }
    else if (!form.querySelector('input[type="file"]').value) {
      e.preventDefault();
      var m = document.getElementById('sigmsg'); if (m) { m.hidden = false; m.textContent = 'Draw your signature in the box, or choose a picture of it.'; }
    }
  });
  size();
}());
