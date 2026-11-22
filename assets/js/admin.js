document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
  el.addEventListener('change', function () { el.form.submit(); });
});
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (ev) {
    if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
  });
});
// Staff: picking a role ticks that role's usual permissions
(function () {
  var holder = document.querySelector('[data-role-map]');
  if (!holder) return;
  var map = JSON.parse(holder.getAttribute('data-role-map'));
  holder.querySelectorAll('select[data-role]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var want = map[sel.value] || [];
      sel.form.querySelectorAll('input[name="perms[]"]').forEach(function (cb) { cb.checked = want.indexOf(cb.value) !== -1; });
    });
  });
})();
// Authenticator QR on "My account" and other [data-qr] holders in the admin
document.querySelectorAll('[data-qr]').forEach(function (el) {
  if (typeof qrcode !== 'function' || el.querySelector('svg')) return;
  var q = qrcode(0, 'M'); q.addData(el.getAttribute('data-qr')); q.make();
  el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
});
