document.querySelectorAll('[data-print]').forEach(function (b) {
  b.addEventListener('click', function () { window.print(); });
});
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (ev) { if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault(); });
});
// QR codes that link to the verification page
document.querySelectorAll('[data-qr]').forEach(function (el) {
  if (typeof qrcode !== 'function') return;
  var q = qrcode(0, 'M');
  q.addData(el.getAttribute('data-qr'));
  q.make();
  el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
});
// M-Pesa (STK push, then wait for Safaricom) and EVC Plus (answer comes back straight away)
(function () {
  var box = document.querySelector('[data-stk]');
  if (!box) return;
  var msg = box.querySelector('.pay-msg');
  var buttons = box.querySelectorAll('form button');
  function say(t, cls) { msg.textContent = t; msg.className = 'pay-msg ' + (cls || ''); }
  function lock(v) { buttons.forEach(function (b) { b.disabled = v; }); }
  function done() {
    say('Payment received. Thank you! Updating…', 'ok');
    setTimeout(function () { location.href = location.pathname + location.search.replace(/[?&]paid=1/, '') + (location.search ? '&' : '?') + 'paid=1'; }, 1500);
  }
  box.querySelectorAll('form').forEach(function (form) {
    var evc = !!form.querySelector('input[name=method][value=evc]');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      lock(true);
      say(evc ? 'Approve the payment on your phone (EVC Plus PIN). Waiting for the answer, this can take up to 2 minutes…' : 'Sending the M-Pesa prompt to your phone…');
      fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { say(j.error || 'Could not start the payment.', 'err'); lock(false); return; }
          if (j.status === 'paid') { done(); return; }
          say(j.message || 'Check your phone and enter your M-Pesa PIN.', 'ok');
          var tries = 0;
          var t = setInterval(function () {
            tries++;
            fetch('pay.php?status=' + encodeURIComponent(j.poll), { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (s) {
                if (s.status === 'paid') { clearInterval(t); done(); }
                else if (s.status === 'failed') { clearInterval(t); say(s.message || 'The payment was not completed. You can try again.', 'err'); lock(false); }
                else if (tries > 40) { clearInterval(t); say('Still waiting for confirmation. If you paid, this page will update shortly; refresh in a minute.', ''); lock(false); }
              });
          }, 3000);
        })
        .catch(function () { say('Network problem. Please try again.', 'err'); lock(false); });
    });
  });
})();
