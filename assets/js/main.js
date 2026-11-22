(function () {
  // Mobile menu
  var btn = document.querySelector('.menu-btn');
  var nav = document.getElementById('nav');
  if (btn && nav) {
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', String(!open));
      nav.classList.toggle('open', !open);
    });
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) { btn.setAttribute('aria-expanded', 'false'); nav.classList.remove('open'); } });
  }

  document.querySelectorAll('[data-autosubmit]').forEach(function (el) { el.addEventListener('change', function () { el.form.submit(); }); });

  // Service "Talk to us" links preselect the quote form
  document.querySelectorAll('[data-for]').forEach(function (a) {
    a.addEventListener('click', function () {
      var sel = document.querySelector('[data-service]');
      if (sel) Array.prototype.forEach.call(sel.options, function (o) { if (o.text === a.getAttribute('data-for')) sel.value = o.value || o.text; });
    });
  });

  // Hero carousel: images and videos, auto-advances, pauses on hover / hidden tab
  var hero = document.querySelector('[data-carousel]');
  if (hero) {
    var slides = hero.querySelectorAll('.slide');
    var dots = hero.querySelectorAll('.dots button');
    var cur = 0, timer = null;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function show(n) {
      if (!slides.length) return;
      var prev = slides[cur];
      var pv = prev.querySelector('video');
      if (pv) pv.pause();
      prev.classList.remove('active'); prev.setAttribute('aria-hidden', 'true');
      if (dots[cur]) dots[cur].classList.remove('on');
      cur = (n + slides.length) % slides.length;
      var s = slides[cur];
      s.classList.add('active'); s.setAttribute('aria-hidden', 'false');
      if (dots[cur]) dots[cur].classList.add('on');
      var v = s.querySelector('video');
      if (v) { v.preload = 'auto'; try { v.currentTime = 0; } catch (e) {} var p = v.play(); if (p && p.catch) p.catch(function () {}); }
      schedule();
    }
    function schedule() {
      clearTimeout(timer);
      if (slides.length < 2 || reduce) return;
      var v = slides[cur].querySelector('video');
      // let a video play for up to 12 s, images 6 s
      timer = setTimeout(function () { show(cur + 1); }, v ? 12000 : 6000);
    }
    dots.forEach(function (d, i) { d.addEventListener('click', function () { show(i); }); });
    var p = hero.querySelector('.prev'), nx = hero.querySelector('.next');
    if (p) p.addEventListener('click', function () { show(cur - 1); });
    if (nx) nx.addEventListener('click', function () { show(cur + 1); });
    hero.addEventListener('mouseenter', function () { clearTimeout(timer); });
    hero.addEventListener('mouseleave', schedule);
    document.addEventListener('visibilitychange', function () { if (document.hidden) clearTimeout(timer); else schedule(); });
    var sx = null;
    hero.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    hero.addEventListener('touchend', function (e) { if (sx === null) return; var dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 50) show(cur + (dx < 0 ? 1 : -1)); sx = null; });
    schedule();
  }

  // ---------- Cart (stored in this browser only) ----------
  var KEY = 'sb_cart';
  function read() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
  function write(c) { try { localStorage.setItem(KEY, JSON.stringify(c)); } catch (e) {} count(); }
  function count() {
    var n = read().reduce(function (a, i) { return a + (i.qty || 0); }, 0);
    document.querySelectorAll('[data-cart-count]').forEach(function (el) { el.textContent = n; el.hidden = n === 0; });
  }
  var fmt = function (n) { return Number(n).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); };
  function toast(t) {
    var el = document.createElement('div');
    el.className = 'toast'; el.setAttribute('role', 'status');
    el.innerHTML = '';
    el.appendChild(document.createTextNode(t + ' '));
    var a = document.createElement('a'); a.href = 'cart.php'; a.textContent = 'View cart'; el.appendChild(a);
    document.body.appendChild(el);
    setTimeout(function () { el.classList.add('show'); }, 10);
    setTimeout(function () { el.classList.remove('show'); setTimeout(function () { el.remove(); }, 300); }, 3200);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-add]');
    if (!b) return;
    var id = parseInt(b.getAttribute('data-add'), 10);
    var qtyEl = b.getAttribute('data-qty-from') ? document.querySelector(b.getAttribute('data-qty-from')) : null;
    var qty = Math.max(1, parseInt(qtyEl ? qtyEl.value : 1, 10) || 1);
    var c = read();
    var found = c.find(function (i) { return i.id === id; });
    if (found) found.qty += qty;
    else c.push({ id: id, name: b.getAttribute('data-name'), price: parseFloat(b.getAttribute('data-price')) || 0, img: b.getAttribute('data-img') || '', qty: qty });
    write(c);
    toast('Added to cart.');
  });

  var page = document.querySelector('[data-cart-page]');
  if (page) {
    var rows = page.querySelector('[data-cart-rows]');
    var empty = page.querySelector('[data-cart-empty]');
    var totalEl = page.querySelector('[data-cart-total]');
    var form = document.querySelector('[data-checkout]');
    function render() {
      var c = read(), total = 0;
      rows.innerHTML = '';
      c.forEach(function (i, idx) {
        total += i.price * i.qty;
        var tr = document.createElement('tr');
        var td1 = document.createElement('td');
        td1.className = 'cart-item';
        if (i.img) { var im = document.createElement('img'); im.src = i.img; im.alt = ''; td1.appendChild(im); }
        td1.appendChild(document.createTextNode(i.name));
        var td2 = document.createElement('td'); td2.className = 'r'; td2.textContent = fmt(i.price);
        var td3 = document.createElement('td');
        var q = document.createElement('input'); q.type = 'number'; q.min = '1'; q.value = i.qty; q.className = 'qty'; q.setAttribute('aria-label', 'Quantity');
        q.addEventListener('change', function () { var cc = read(); cc[idx].qty = Math.max(1, parseInt(q.value, 10) || 1); write(cc); render(); });
        td3.appendChild(q);
        var td4 = document.createElement('td'); td4.className = 'r'; td4.textContent = fmt(i.price * i.qty);
        var td5 = document.createElement('td');
        var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'link danger'; rm.textContent = 'Remove';
        rm.addEventListener('click', function () { var cc = read(); cc.splice(idx, 1); write(cc); render(); });
        td5.appendChild(rm);
        [td1, td2, td3, td4, td5].forEach(function (t) { tr.appendChild(t); });
        rows.appendChild(tr);
      });
      empty.hidden = c.length > 0;
      totalEl.textContent = 'KES ' + fmt(total);
      if (form) {
        form.querySelector('[data-cart-json]').value = JSON.stringify(c.map(function (i) { return { id: i.id, qty: i.qty }; }));
        form.querySelector('button').disabled = c.length === 0;
      }
    }
    render();
  }
  if (document.querySelector('[data-clear-cart]')) write([]);
  count();
})();

/* Horizontal scrollers: arrows, swipe (native), auto-scroll every 4 s, paused on hover, focus or touch */
(function () {
  document.querySelectorAll('[data-scroller]').forEach(function (sc) {
    var wrap = sc.closest('section') || document;
    var step = function () { var c = sc.querySelector(':scope > *'); return c ? c.getBoundingClientRect().width + 20 : sc.clientWidth; };
    var go = function (dir) {
      var max = sc.scrollWidth - sc.clientWidth - 2;
      if (dir > 0 && sc.scrollLeft >= max) sc.scrollTo({ left: 0 });
      else if (dir < 0 && sc.scrollLeft <= 0) sc.scrollTo({ left: max });
      else sc.scrollBy({ left: dir * step() });
    };
    var prev = wrap.querySelector('[data-sc-prev]'), next = wrap.querySelector('[data-sc-next]');
    if (prev) prev.addEventListener('click', function () { go(-1); });
    if (next) next.addEventListener('click', function () { go(1); });
    var paused = false, reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    ['mouseenter', 'focusin', 'touchstart'].forEach(function (ev) { sc.addEventListener(ev, function () { paused = true; }, { passive: true }); });
    ['mouseleave', 'focusout'].forEach(function (ev) { sc.addEventListener(ev, function () { paused = false; }); });
    sc.addEventListener('touchend', function () { setTimeout(function () { paused = false; }, 6000); }, { passive: true });
    if (!reduce && sc.scrollWidth > sc.clientWidth + 4) setInterval(function () { if (!paused && !document.hidden) go(1); }, 4000);
  });
})();
