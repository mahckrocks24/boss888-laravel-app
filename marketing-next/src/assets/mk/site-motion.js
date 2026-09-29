/* GLASS-SITE-1 (2026-09-30): the motion every page shares, driven by Motion (vanilla build, /assets/mk/motion.js).
   Reveals: each block of a section rises in once as it enters the screen (never waits for mid-screen). Press: every button
   scales to .96 and springs back. Hover: cards lift 4px on a real pointer. A thin scroll-linked progress line on the top edge.
   Reduced motion: nothing runs, every block is shown at rest. The home has its own script and is skipped here. */
(function () {
  var M = window.Motion;
  var doc = document.documentElement;
  if (doc.classList.contains('mk-home')) return;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // aurora: the slow lean toward the mouse (desktop only); nothing moves on its own
  (function () {
    var TAU = 2000, tx = 0, ty = 0, cx = 0, cy = 0, raf = 0, last = 0, el = $('.lg-site > .lg-aurora');
    if (!el || reduce) return;
    function frame(now) { var dt = last ? Math.min(now - last, 100) : 16; last = now; var k = 1 - Math.exp(-dt / TAU); cx += (tx - cx) * k; cy += (ty - cy) * k; el.style.setProperty('--ax', cx.toFixed(4)); el.style.setProperty('--ay', cy.toFixed(4)); if (Math.abs(tx - cx) > .0004 || Math.abs(ty - cy) > .0004) raf = requestAnimationFrame(frame); else { raf = 0; last = 0; } }
    window.addEventListener('pointermove', function (e) { if (e.pointerType !== 'mouse' && e.pointerType !== 'pen') return; tx = (e.clientX / window.innerWidth) * 2 - 1; ty = (e.clientY / window.innerHeight) * 2 - 1; if (!raf) raf = requestAnimationFrame(frame); }, { passive: true });
  })();

  if (!M || reduce) return;

  try { var s = String(M.spring(0.4, 0.3)); if (/linear\(/.test(s)) doc.style.setProperty('--spring-t', s); } catch (e) {}

  // 1. REVEALS: the direct blocks of every section (and every card in a grid), once, as they enter the screen
  (function () {
    var SEL = '.section > .container > *, .section-tight > .container > *, .page-head > .container > *, .hero > .container > *, .hero-sarah > .container > *, .sec > .container > *, .grid-2 > *, .grid-3 > *, .grid-4 > *, .row-5-7 > *, .row-7-5 > *, .card, .plan, .post-card, .faq-item, .prose > *';
    var all = $$(SEL).filter(function (el) { return el.offsetParent !== null || getComputedStyle(el).position === 'fixed'; });
    var items = all.filter(function (el) { for (var p = el.parentElement; p; p = p.parentElement) if (all.indexOf(p) >= 0) return false; return true; });   // no nesting: a block inside a revealed block rides with it
    var pending = [];
    items.forEach(function (el) {
      if (el.getBoundingClientRect().top < window.innerHeight * .9) return;   // on screen at load: at rest
      el.setAttribute('data-lg-rv', ''); el.style.opacity = 0; pending.push(el);
    });
    // stagger by parent: siblings entering together land one after the other
    var order = new Map();
    M.inView(pending, function (el) {
      var p = el.parentElement, n = order.get(p) || 0; order.set(p, n + 1); setTimeout(function () { order.set(p, Math.max(0, (order.get(p) || 1) - 1)); }, 400);
      M.animate(el, { opacity: [0, 1], y: [22, 0] }, { duration: .6, delay: Math.min(n, 6) * .07, ease: [.25, .8, .3, 1] });
      pending = pending.filter(function (e) { return e !== el; });
    }, { amount: 0, margin: '0px 0px 10% 0px' });
    setTimeout(function () { pending.forEach(function (el) { el.style.opacity = 1; }); }, 5000);   // nothing may stay hidden
  })();

  // 2. SCROLL-LINKED progress line
  var bar = $('#mk-progress'); if (bar) M.scroll(M.animate(bar, { scaleX: [0, 1] }, { ease: 'linear' }));

  // 3. PRESS and HOVER
  M.press('.btn, .btn-glow, .lg-btn', function (el) { M.animate(el, { scale: .96 }, { duration: .12 }); return function () { M.animate(el, { scale: 1 }, { type: 'spring', bounce: .45, visualDuration: .45 }); }; });
  if (fine) M.hover('.card, .plan, .post-card', function (el) { M.animate(el, { y: -4 }, { duration: .25, ease: [.22, 1, .36, 1] }); return function () { M.animate(el, { y: 0 }, { duration: .3, ease: [.22, 1, .36, 1] }); }; });
})();
