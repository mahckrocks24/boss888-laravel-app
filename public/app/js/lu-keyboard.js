/* LU-KEYBOARD — KB-3 (Owner 2026-09-25: "on mobile, on ALL areas where text input is needed and keyboard opens, the place
   where text is entered must be visible … on all devices including iPhones").

   One guard for every document: the app shell, the marketing site, every published website and its editor preview,
   the chatbot widget (also when embedded elsewhere). Whenever a text field has focus and the keyboard is open, the field
   is brought into the VISIBLE part of the screen, whatever it sits in:

     - iPhone/iPad Safari (and any browser that lays the keyboard OVER the page): the layout viewport keeps its height,
       window.visualViewport shrinks. Fixed/sticky containers (chat composers, floaters, dialogs) do not move by
       themselves, so they end up under the keyboard → they are lifted by exactly what is needed, and capped to the
       visible height when they are taller than it.
     - Android Chrome with interactive-widget=resizes-content (the layout shrinks): fields inside non-scrolling panels
       can still be pushed out → the nearest scrollable ancestor, the iframe document, then the window, are scrolled.
     - Fields inside a same-origin iframe (the website editor's preview) are found through the frame chain and
       measured in top-window coordinates.

   It re-checks at 60/320/700/1200 ms after focus (keyboards animate at different speeds) and on every visual-viewport
   change while a field is focused; everything it moved is put back when the keyboard closes. It sets html.lu-kb-open
   and --lu-kb (the height the layout did NOT absorb) so existing CSS keeps working, and retires the older KB-2 handler
   by claiming its flag. No dependencies; safe to load twice. */
(function () {
  if (window.__luKb3) return;
  window.__luKb3 = true;
  window.__luKbInstalled = true;   // KB-2 (core.js) checks this and steps aside

  var vv = window.visualViewport || null;
  var root = document.documentElement;
  var TEXT = /^(text|email|search|url|tel|number|password|date|time|datetime-local|month|week)$/;
  var PAD = 12;
  var lifted = [];          // [{el, transform, transition, maxHeight, overflowY}]
  var ticks = [];           // pending timers after a focus
  var debounce = null;
  var baseH = window.innerHeight;   // tallest layout viewport seen: Android's keyboard shrinks it
  var focusedAt = 0;

  function isField(el) {
    if (!el || el.nodeType !== 1) return false;
    var t = el.tagName;
    if (t === 'TEXTAREA') return true;
    if (t === 'INPUT') return TEXT.test((el.getAttribute('type') || 'text').toLowerCase());
    return !!el.isContentEditable;
  }

  /** The visible area in top-window coordinates, and how much of the layout the keyboard covers. */
  function vis() {
    if (vv) { return { top: vv.offsetTop, bottom: vv.offsetTop + vv.height, kb: Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop)) }; }
    return { top: 0, bottom: window.innerHeight, kb: 0 };
  }

  /** The focused element, following same-origin iframes down; offsets translate its rect to top-window space. */
  function deepActive() {
    var doc = document, el = doc.activeElement, off = { x: 0, y: 0 }, frames = [];
    var guard = 0;
    while (el && el.tagName === 'IFRAME' && guard++ < 5) {
      var cd = null;
      try { cd = el.contentDocument; } catch (e) { cd = null; }
      if (!cd) break;
      var fr = el.getBoundingClientRect();
      frames.push(el); off.x += fr.left; off.y += fr.top;
      doc = cd; el = cd.activeElement;
    }
    return { el: el, off: off, frames: frames, doc: doc };
  }

  function rectOf(el, off) { var r = el.getBoundingClientRect(); return { top: r.top + off.y, bottom: r.bottom + off.y, height: r.height }; }

  /** Positive = the field sticks out below the visible area; negative = above; 0 = fine. */
  function need(el, off, v) {
    var r = rectOf(el, off);
    if (r.height > (v.bottom - v.top) - 2 * PAD) {
      // taller than the screen (long textarea/contenteditable): the caret end is what matters — keep the bottom visible
      return r.bottom > v.bottom - PAD ? r.bottom - (v.bottom - PAD) : 0;
    }
    if (r.bottom > v.bottom - PAD) return r.bottom - (v.bottom - PAD);
    if (r.top < v.top + PAD) return r.top - (v.top + PAD);
    return 0;
  }

  function scrollParent(el, doc) {
    var win = doc.defaultView, p = el.parentElement;
    while (p && p !== doc.body && p !== doc.documentElement) {
      var cs = win.getComputedStyle(p);
      if (/(auto|scroll|overlay)/.test(cs.overflowY) && p.scrollHeight > p.clientHeight + 2) return p;
      p = p.parentElement;
    }
    return null;
  }

  function fixedAncestor(el, doc) {
    var win = doc.defaultView, p = el;
    while (p && p !== doc.body && p !== doc.documentElement) {
      var cs = win.getComputedStyle(p);
      if (cs.position === 'fixed' || cs.position === 'sticky') return p;
      p = p.parentElement;
    }
    return null;
  }

  function lift(el, by, capTo) {
    var rec = null;
    for (var i = 0; i < lifted.length; i++) if (lifted[i].el === el) rec = lifted[i];
    if (!rec) { rec = { el: el, transform: el.style.transform, transition: el.style.transition, maxHeight: el.style.maxHeight, overflowY: el.style.overflowY, by: 0 }; lifted.push(rec); }
    rec.by += by;
    el.style.transition = 'transform .15s ease-out';
    el.style.transform = (rec.transform && rec.transform !== 'none' ? rec.transform + ' ' : '') + 'translateY(' + (-rec.by) + 'px)';
    if (capTo) { el.style.maxHeight = capTo + 'px'; el.style.overflowY = 'auto'; }
  }

  function restore() {
    for (var i = 0; i < lifted.length; i++) {
      var x = lifted[i];
      try { x.el.style.transform = x.transform; x.el.style.transition = x.transition; x.el.style.maxHeight = x.maxHeight; x.el.style.overflowY = x.overflowY; } catch (e) {}
    }
    lifted = [];
    unshrink();
  }

  /* An app-shaped document — html/body at 100%, nothing to scroll, panels pinned to the bottom (a chat composer, an
     editor bar) — cannot be scrolled into the visible area: on iPhone the keyboard lies over the bottom third of it.
     The only fix is the one Android does natively: give the layout the visible height. Done inline, put back on close. */
  var shrunk = null;
  function shrink(v) {
    if (v.kb <= 0) return;
    var se = document.scrollingElement || root;
    var appLike = se.scrollHeight <= window.innerHeight + 2;
    if (!appLike && !shrunk) return;
    var h = Math.round(v.bottom - v.top);
    if (!shrunk) { shrunk = { html: root.getAttribute('style'), body: document.body.getAttribute('style') }; }
    root.style.setProperty('height', h + 'px', 'important'); root.style.setProperty('min-height', '0', 'important'); root.style.setProperty('max-height', h + 'px', 'important');
    document.body.style.setProperty('height', h + 'px', 'important'); document.body.style.setProperty('min-height', '0', 'important'); document.body.style.setProperty('max-height', h + 'px', 'important');
    if (v.top > 0) { try { window.scrollTo(0, 0); } catch (e) {} }
  }
  function unshrink() {
    if (!shrunk) return;
    try { if (shrunk.html === null) root.removeAttribute('style'); else root.setAttribute('style', shrunk.html); } catch (e) {}
    try { if (shrunk.body === null) document.body.removeAttribute('style'); else document.body.setAttribute('style', shrunk.body); } catch (e) {}
    shrunk = null;
  }

  function reveal() {
    var a = deepActive(), el = a.el;
    if (!isField(el)) return;
    var v = vis(), n = need(el, a.off, v);
    if (Math.abs(n) < 2) return;

    // 0. iPhone: the keyboard lies over a layout that has nowhere to scroll — shrink the layout to what is visible.
    if (v.kb > 0) { shrink(v); n = need(el, a.off, v); if (Math.abs(n) < 2) return; }

    // 1. Something fixed or sticky holds the field (composer bar, floater, dialog, sheet). When the keyboard
    //    covers the layout, move that container up by what is needed — no more than the room above it — and,
    //    if it is taller than what is visible, cap it and scroll inside it.
    var fx = fixedAncestor(el, a.doc);
    if (fx && n > 0) {
      var fr = fx.getBoundingClientRect(), fxTop = fr.top + a.off.y;
      var room = Math.max(0, fxTop - (v.top + PAD));
      var by = Math.min(n, room);
      if (by > 0) { lift(fx, by); n = need(el, a.off, v); }
      if (Math.abs(n) > 2 && fx.scrollHeight > fx.clientHeight + 2) { fx.scrollTop += n; n = need(el, a.off, v); }
      if (Math.abs(n) > 2) {
        var visibleH = (v.bottom - v.top) - 2 * PAD;
        if (fr.height > visibleH) {
          lift(fx, 0, visibleH);
          fr = fx.getBoundingClientRect(); fxTop = fr.top + a.off.y;
          if (fxTop < v.top + PAD || fxTop + visibleH > v.bottom - PAD) { lift(fx, fxTop - (v.top + PAD)); }
          try { el.scrollIntoView({ block: 'center' }); } catch (e) {}
          n = need(el, a.off, v);
        }
      }
      if (Math.abs(n) < 2) return;
    }

    // 2. Scrollable ancestors, nearest first.
    var sp = scrollParent(el, a.doc), guard = 0;
    while (sp && Math.abs(n) > 2 && guard++ < 5) {
      var before = sp.scrollTop;
      sp.scrollTop += n;
      n = need(el, a.off, v);
      if (sp.scrollTop === before || Math.abs(n) > 2) sp = scrollParent(sp, a.doc);
    }
    if (Math.abs(n) < 2) return;

    // 3. The iframe's own document, then whatever scrolls around the iframe (an editor stage), then the window.
    if (a.frames.length) {
      try { var se = a.doc.scrollingElement || a.doc.documentElement; se.scrollTop += n; n = need(el, a.off, v); } catch (e) {}
      if (Math.abs(n) < 2) return;
      for (var fi = a.frames.length - 1; fi >= 0 && Math.abs(n) > 2; fi--) {
        var fdoc = a.frames[fi].ownerDocument, fsp = scrollParent(a.frames[fi], fdoc), fg = 0;
        while (fsp && Math.abs(n) > 2 && fg++ < 5) { var fb = fsp.scrollTop; fsp.scrollTop += n; n = need(el, a.off, v); if (fsp.scrollTop === fb || Math.abs(n) > 2) fsp = scrollParent(fsp, fdoc); }
      }
      if (Math.abs(n) < 2) return;
    }
    try { window.scrollBy(0, n); } catch (e) {}
    n = need(el, a.off, v);
    if (Math.abs(n) < 2) return;

    // 4. Last resort: let the browser do what it can.
    try { el.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (e) {}
  }

  function apply() {
    var v = vis();
    if (window.innerHeight > baseH) baseH = window.innerHeight;
    var shrunk = window.innerHeight < baseH - 120;      // Android: the layout itself gave the keyboard its room
    var open = v.kb > 120 || shrunk;
    var a = deepActive();
    var hasField = isField(a.el);
    if (open && hasField) {
      root.classList.add('lu-kb-open');
      root.style.setProperty('--lu-kb', v.kb + 'px');
      root.style.setProperty('--lu-vvh', Math.round(v.bottom - v.top) + 'px');
      reveal();
    } else {
      if (!hasField || !open) { root.classList.remove('lu-kb-open'); root.style.removeProperty('--lu-kb'); restore(); }
      if (hasField && !open && v.kb === 0 && Date.now() - focusedAt < 1500) reveal();   // a field focused with no keyboard yet: still keep it in view
    }
  }

  function later() { clearTimeout(debounce); debounce = setTimeout(apply, 60); }
  function schedule() {
    ticks.forEach(clearTimeout); ticks = [];
    [60, 320, 700, 1200].forEach(function (ms) { ticks.push(setTimeout(apply, ms)); });
  }

  document.addEventListener('focusin', function (e) { if (isField(e.target) || (e.target && e.target.tagName === 'IFRAME')) { focusedAt = Date.now(); schedule(); } }, true);
  document.addEventListener('focusout', function () { ticks.forEach(clearTimeout); ticks = []; later(); }, true);
  if (vv) { vv.addEventListener('resize', later); vv.addEventListener('scroll', later); }
  window.addEventListener('resize', later);
  window.addEventListener('orientationchange', function () { baseH = 0; later(); });

  // A field inside an iframe (the editor's preview) is focused without a focusin on this document: poll lightly while
  // an iframe holds focus so the keyboard opening is not missed on browsers that report it late.
  setInterval(function () { if (document.activeElement && document.activeElement.tagName === 'IFRAME') { var a = deepActive(); if (isField(a.el)) apply(); } }, 500);

  apply();
})();
