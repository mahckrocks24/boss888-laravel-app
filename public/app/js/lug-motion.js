/* ==========================================================================
   LUG MOTION — the one platform script for ambient motion (Liquid Glass).
   Runs only while <html> has .lg-ui. Styles live in lug-glass.css; this file
   only writes a few numbers and adds the background layer.
   - Aurora: the logo gradient behind the app, drifting, leaning toward the
     pointer with a 2 s ease (writes --lg-ax / --lg-ay).
   - Scroll edges: the page scroller fades under its top / bottom edges while
     there is more to scroll (--lg-fade-t / --lg-fade-b) and bounces at the ends.
   - Reduced motion: no follow, no bounce.
   ========================================================================== */
(function () {
  if (window.__lugMotion) return;
  window.__lugMotion = true;
  var root = document.documentElement;
  if (!root.classList.contains('lg-ui')) return;

  var reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };

  /* ---- background layer ---- */
  function addAurora() {
    if (document.querySelector('.lg-aurora--page') || !document.body) return;
    var a = document.createElement('div');
    a.className = 'lg-aurora lg-aurora--page';
    a.setAttribute('aria-hidden', 'true');
    a.innerHTML = '<i></i><i></i><i></i>';
    document.body.insertBefore(a, document.body.firstChild);
  }
  if (document.body) addAurora(); else document.addEventListener('DOMContentLoaded', addAurora);

  /* ---- pointer follow ---- */
  var TAU = 2000, tx = 0, ty = 0, cx = 0, cy = 0, raf = 0, last = 0;
  function frame(now) {
    var dt = last ? Math.min(now - last, 100) : 16; last = now;
    var k = 1 - Math.exp(-dt / TAU);
    cx += (tx - cx) * k; cy += (ty - cy) * k;
    var l = document.querySelectorAll('.lg-aurora');
    for (var i = 0; i < l.length; i++) { l[i].style.setProperty('--lg-ax', cx.toFixed(4)); l[i].style.setProperty('--lg-ay', cy.toFixed(4)); }
    if (Math.abs(tx - cx) > 0.0004 || Math.abs(ty - cy) > 0.0004) raf = requestAnimationFrame(frame); else { raf = 0; last = 0; }
  }
  function aim(x, y) {
    if (reduce.matches) return;
    tx = Math.max(-1, Math.min(1, (x / (window.innerWidth || 1)) * 2 - 1));
    ty = Math.max(-1, Math.min(1, (y / (window.innerHeight || 1)) * 2 - 1));
    if (!raf) raf = requestAnimationFrame(frame);
  }
  window.addEventListener('pointermove', function (e) { if (e.pointerType === 'mouse' || e.pointerType === 'pen') aim(e.clientX, e.clientY); }, { passive: true });
  window.addEventListener('pointerdown', function (e) { aim(e.clientX, e.clientY); }, { passive: true });

  /* ---- scroll edge fades ---- */
  var FADE = 28;
  function isScroller(el) {
    if (!el || el.nodeType !== 1 || el === document.documentElement) return false;
    if (el.classList.contains('lg-scroll-fade')) return true;
    // the page scroller of today's screens: a tall element that scrolls vertically inside .main
    return el.scrollHeight > el.clientHeight + 2 && el.clientHeight > window.innerHeight * 0.5 && !!el.closest('.main');
  }
  function fade(el) {
    if (!el.classList.contains('lg-scroll-fade')) el.classList.add('lg-fade-mask');
    var above = el.scrollTop, below = el.scrollHeight - el.clientHeight - above;
    el.style.setProperty('--lg-fade-t', Math.min(Math.max(above, 0), FADE) + 'px');
    el.style.setProperty('--lg-fade-b', Math.min(Math.max(below, 0), FADE) + 'px');
  }
  document.addEventListener('scroll', function (e) { if (isScroller(e.target)) fade(e.target); }, { capture: true, passive: true });

  /* ---- edge bounce: content stretches at the very top / bottom and springs back ---- */
  var MAX = 64;
  function rubber(d) { var s = d < 0 ? -1 : 1; return s * MAX * (1 - Math.exp(-Math.abs(d) / (MAX * 2.2))); }
  function atTop(el) { return el.scrollTop <= 0; }
  function atEnd(el) { return el.scrollTop + el.clientHeight >= el.scrollHeight - 1; }
  function target(el) { return el.classList.contains('lg-scroll-fade') ? (el.firstElementChild || el) : el; }
  function stretch(el, y, release) {
    var t = target(el);
    t.style.transition = release ? 'transform 560ms cubic-bezier(.32,.72,0,1)' : 'none';
    t.style.transform = y ? 'translate3d(0,' + y.toFixed(1) + 'px,0)' : '';
    if (release) setTimeout(function () { if (!t.style.transform) t.style.transition = ''; }, 600);
  }
  function scrollerFrom(node) {
    for (var n = node; n && n !== document.body; n = n.parentElement) if (isScroller(n)) return n;
    return null;
  }
  var tEl = null, base = 0, pulled = 0;
  document.addEventListener('touchstart', function (e) { tEl = scrollerFrom(e.target); base = e.touches[0].clientY; pulled = 0; }, { passive: true });
  document.addEventListener('touchmove', function (e) {
    if (!tEl || reduce.matches) return;
    var y = e.touches[0].clientY, dy = y - base;
    if ((dy > 0 && atTop(tEl)) || (dy < 0 && atEnd(tEl))) { pulled = rubber(dy); stretch(tEl, pulled, false); }
    else { if (pulled) { pulled = 0; stretch(tEl, 0, false); } base = y; }
  }, { passive: true });
  document.addEventListener('touchend', function () { if (tEl && pulled) stretch(tEl, 0, true); tEl = null; pulled = 0; }, { passive: true });
  var wOff = 0, wTimer = 0;
  document.addEventListener('wheel', function (e) {
    if (reduce.matches || e.ctrlKey) return;
    var el = scrollerFrom(e.target); if (!el) return;
    if ((e.deltaY < 0 && atTop(el)) || (e.deltaY > 0 && atEnd(el))) {
      wOff -= e.deltaY * 0.5; stretch(el, rubber(wOff), false);
      clearTimeout(wTimer); wTimer = setTimeout(function () { wOff = 0; stretch(el, 0, true); }, 120);
    }
  }, { passive: true });
})();
