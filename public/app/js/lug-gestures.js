/* ==========================================================================
   LUG GESTURES — swipe left / right to change tab on phones (Liquid Glass).
   Runs only while <html> has .lg-ui.
   - Today's screens need no changes: on a phone, a sideways swipe inside the
     open page finds that page's tab bar and CLICKS the next or previous tab,
     so the page's own tab logic runs exactly as if it were tapped.
   - New screens may opt in explicitly: data-lg-swipe="panel|track" + data-lg-tabs.
   - Vertical scrolling is never hijacked (|dx| > 1.2·|dy| after an 8px dead zone);
     anything that still scrolls sideways (carousels, boards, tables, the tab bar)
     and form fields win the gesture; [data-swipe-ignore] opts out.
   ========================================================================== */
(function () {
  if (window.__lugSwipe) return;
  window.__lugSwipe = true;
  if (!document.documentElement.classList.contains('lg-ui')) return;

  var SPRING = 'cubic-bezier(.32,.72,0,1)';
  var reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  var BARS = '[role="tablist"], .lu-tabs, .crm-tab-bar, .lgse-subtabs, .ar-tabs, .tabs';

  function visible(el) { var r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; }
  function itemsOf(bar) {
    var l = [].slice.call(bar.querySelectorAll('[role="tab"]'));
    if (l.length < 2) l = [].slice.call(bar.children).filter(function (c) { return c.matches('button, a') && visible(c); });
    return l;
  }
  function activeIndex(items) {
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      if (it.classList.contains('active') || it.classList.contains('is-on') || it.getAttribute('aria-selected') === 'true' || it.getAttribute('aria-current') === 'page') return i;
    }
    return -1;
  }
  function yields(el, root, dx) {
    for (var n = el; n && n !== root; n = n.parentElement) {
      if (n.matches && n.matches('input, textarea, select, [contenteditable="true"], [data-swipe-ignore], canvas, iframe, video')) return true;
      var ox = getComputedStyle(n).overflowX;
      if ((ox === 'auto' || ox === 'scroll') && n.scrollWidth > n.clientWidth + 1) {
        if (dx < 0 && n.scrollLeft + n.clientWidth < n.scrollWidth - 1) return true;
        if (dx > 0 && n.scrollLeft > 0) return true;
      }
    }
    return false;
  }
  function select(tab) {
    tab.click();
    try { if (navigator.vibrate) navigator.vibrate(4); } catch (e) {}
    try { tab.scrollIntoView({ behavior: reduce.matches ? 'auto' : 'smooth', inline: 'center', block: 'nearest' }); } catch (e) {}
  }
  function resolve(start) {
    var root = start.closest && start.closest('[data-lg-swipe]');
    if (root) {
      var sel = root.getAttribute('data-lg-tabs'), bar = sel ? (root.querySelector(sel) || document.querySelector(sel)) : null;
      if (!bar) return null;
      var mode = root.getAttribute('data-lg-swipe') || 'panel';
      return { root: root, bar: bar, items: itemsOf(bar), mode: mode,
        target: mode === 'track' ? root.querySelector('.lg-swipe__track') : (root.querySelector('.lg-swipe__panel') || root) };
    }
    // today's screens, phones only: the open page and its first visible tab bar
    if (window.innerWidth >= 768) return null;
    if (document.querySelector('.sidebar.mob-open, .lu-dlg-overlay, .lu-drawer.open')) return null;
    var view = start.closest && start.closest('.view.active, .view[style*="display: flex"], .view[style*="display:flex"], .view[style*="display: block"]');
    if (!view) return null;
    var bars = [].slice.call(view.querySelectorAll(BARS)).filter(visible);
    for (var i = 0; i < bars.length; i++) {
      var it = itemsOf(bars[i]);
      if (it.length >= 2 && activeIndex(it) >= 0) return { root: view, bar: bars[i], items: it, mode: 'panel', target: view };
    }
    return null;
  }

  // touch uses touch events (the browser never cancels them the way it cancels pointer events when it starts a pan);
  // mouse / pen use pointer events (desktop testing, tablets with a pen)
  function begin(e, touch) {
    var p0 = touch ? e.touches[0] : e;
    var t = resolve(e.target);
    if (!t || t.items.length < 2 || t.bar.contains(e.target) || !t.target) return;
    var idx = activeIndex(t.items); if (idx < 0) idx = 0;
    var n = t.items.length, w = t.root.clientWidth || 1, target = t.target, mode = t.mode;
    var x0 = p0.clientX, y0 = p0.clientY, lastX = x0, lastT = performance.now(), dx = 0, vel = 0, locked = null;
    var dur = reduce.matches ? 1 : 380;

    function move(ev) {
      var p = touch ? ev.touches[0] : ev; if (!p) return;
      var mx = p.clientX - x0, my = p.clientY - y0;
      if (locked === null) {
        if (Math.abs(mx) < 8 && Math.abs(my) < 8) return;
        if (Math.abs(mx) > Math.abs(my) * 1.2 && !yields(e.target, t.root, mx)) { locked = true; target.style.transition = 'none'; }
        else { locked = false; stop(); return; }
      }
      var now = performance.now(); vel = (p.clientX - lastX) / Math.max(1, now - lastT); lastX = p.clientX; lastT = now;
      dx = mx; if ((idx === 0 && dx > 0) || (idx === n - 1 && dx < 0)) dx = dx / 3;
      if (mode === 'track') target.style.transform = 'translate3d(' + (-idx * w + dx) + 'px,0,0)';
      else { target.style.transform = 'translate3d(' + dx + 'px,0,0)'; target.style.opacity = String(1 - Math.min(Math.abs(dx) / w, 1) * 0.4); }
    }
    function end() {
      stop(); if (!locked) return;
      var dir = 0;
      if ((dx < -w * 0.22 || vel < -0.45) && idx < n - 1) dir = 1; else if ((dx > w * 0.22 || vel > 0.45) && idx > 0) dir = -1;
      var ease = 'transform ' + dur + 'ms ' + SPRING + ', opacity ' + dur + 'ms ' + SPRING;
      if (mode === 'track') {
        target.style.transition = ease; target.style.transform = 'translate3d(' + (-(idx + dir) * w) + 'px,0,0)';
        if (dir) select(t.items[idx + dir]);
        setTimeout(function () { target.style.transition = ''; }, dur + 20); return;
      }
      if (!dir) { target.style.transition = ease; target.style.transform = ''; target.style.opacity = ''; clean(); return; }
      target.style.transition = 'transform ' + (dur * .45) + 'ms ease-in, opacity ' + (dur * .45) + 'ms ease-in';
      target.style.transform = 'translate3d(' + (-dir * w * .35) + 'px,0,0)'; target.style.opacity = '0';
      setTimeout(function () {
        select(t.items[idx + dir]);
        target.style.transition = 'none'; target.style.transform = 'translate3d(' + (dir * w * .35) + 'px,0,0)';
        void target.offsetWidth;
        target.style.transition = ease; target.style.transform = ''; target.style.opacity = ''; clean();
      }, dur * .45);
    }
    function clean() { setTimeout(function () { if (!target.style.transform) { target.style.transition = ''; target.style.opacity = ''; } }, dur + 40); }
    var MV = touch ? 'touchmove' : 'pointermove', UP = touch ? 'touchend' : 'pointerup', CX = touch ? 'touchcancel' : 'pointercancel';
    function stop() { window.removeEventListener(MV, move); window.removeEventListener(UP, end); window.removeEventListener(CX, end); }
    window.addEventListener(MV, move, { passive: true });
    window.addEventListener(UP, end);
    window.addEventListener(CX, end);
  }
  document.addEventListener('touchstart', function (e) { if (e.touches.length === 1) begin(e, true); }, { passive: true });
  document.addEventListener('pointerdown', function (e) { if ((e.pointerType === 'mouse' && e.button === 0) || e.pointerType === 'pen') begin(e, false); }, { passive: true });
})();
