(function () {
  var form = document.getElementById('docForm');
  if (!form) return;
  var tbody = document.getElementById('items');
  var fmt = function (n) { return n.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var num = function (v) { return parseFloat(String(v).replace(/,/g, '')) || 0; };
  var units = {};
  document.querySelectorAll('#catalogue option').forEach(function (o) { units[o.value] = o.getAttribute('data-unit'); });

  function recalc() {
    var sub = 0;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var q = num(tr.querySelector('[name="items[qty][]"]').value);
      var p = num(tr.querySelector('[name="items[price][]"]').value);
      tr.querySelector('.amt').textContent = fmt(q * p);
      sub += q * p;
    });
    var disc = Math.min(Math.max(num(form.elements.discount.value), 0), sub);
    var opt = form.elements.tax ? form.elements.tax.selectedOptions[0] : null;
    var rate = opt ? num(opt.getAttribute('data-rate')) : 0;
    var vat = Math.round((sub - disc) * rate) / 100;
    document.getElementById('tSub').textContent = fmt(sub);
    document.getElementById('tDisc').textContent = fmt(disc);
    document.getElementById('tVat').textContent = fmt(vat);
    document.getElementById('tTotal').textContent = fmt(sub - disc + vat);
  }

  function blankRow() {
    var tr = tbody.querySelector('tr').cloneNode(true);
    tr.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'items[qty][]' ? '1' : (i.name === 'items[price][]' ? '0' : ''); });
    return tr;
  }

  document.getElementById('addItem').addEventListener('click', function () {
    var tr = blankRow();
    tbody.appendChild(tr);
    tr.querySelector('input').focus();
    recalc();
  });

  tbody.addEventListener('click', function (e) {
    var b = e.target.closest('[data-remove-row]');
    if (!b) return;
    if (tbody.querySelectorAll('tr').length > 1) b.closest('tr').remove();
    else b.closest('tr').querySelectorAll('input').forEach(function (i) { i.value = ''; });
    recalc();
  });

  tbody.addEventListener('change', function (e) {
    if (e.target.name === 'items[desc][]' && units[e.target.value]) {
      var u = e.target.closest('tr').querySelector('[name="items[unit][]"]');
      if (!u.value) u.value = units[e.target.value];
    }
  });
  form.addEventListener('input', recalc);
  form.addEventListener('change', recalc);
  recalc();

  // Proposal sections
  var secWrap = document.getElementById('sections');
  if (secWrap) {
    secWrap.addEventListener('click', function (e) {
      var sec = e.target.closest('.sec');
      if (!sec) return;
      if (e.target.closest('[data-remove]')) {
        if (confirm('Remove this section?')) sec.remove();
      } else if (e.target.closest('[data-move]')) {
        var dir = e.target.closest('[data-move]').getAttribute('data-move');
        if (dir === '-1' && sec.previousElementSibling) secWrap.insertBefore(sec, sec.previousElementSibling);
        if (dir === '1' && sec.nextElementSibling) secWrap.insertBefore(sec.nextElementSibling, sec);
      }
    });
    document.getElementById('addSection').addEventListener('click', function () {
      var first = secWrap.querySelector('.sec');
      var sec;
      if (first) {
        sec = first.cloneNode(true);
        sec.querySelector('input').value = '';
        sec.querySelector('textarea').value = '';
      } else {
        sec = document.createElement('div');
        sec.className = 'sec';
        sec.innerHTML = '<div class="sec-head"><input name="sec[heading][]" aria-label="Section heading">' +
          '<button type="button" class="icon" data-move="-1" aria-label="Move up">↑</button>' +
          '<button type="button" class="icon" data-move="1" aria-label="Move down">↓</button>' +
          '<button type="button" class="icon danger" data-remove aria-label="Remove section">×</button></div>' +
          '<textarea name="sec[body][]" rows="5" aria-label="Section text"></textarea>';
      }
      secWrap.appendChild(sec);
      sec.querySelector('input').focus();
    });
  }

  // Warn before leaving with unsaved changes
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
