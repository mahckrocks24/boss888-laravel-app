/* LU-CONTRAST-LIVE (Owner 2026-09-28: "we need to audit all and make sure we never ever encounter this same problem").
 *
 * Every published page and every editor preview carries this. After the page has painted, it measures each piece of
 * text against the background that is REALLY behind it — the colours layered up the tree, translucent layers blended,
 * the editor's overlay veils included — and only where the text is unreadable (contrast under 3:1) it switches that
 * text to the site's own ink or to white, whichever reads. Text over a photo or a strong gradient is left alone: the
 * background there is not knowable from styles, and designs already set those. Decorative faint patterns
 * (a 10% dotted wash) do not count as a background. Mark an element data-lu-cg-off to exempt it and its children.
 */
(function () {
  'use strict';
  if (window.__luContrastLive) return; window.__luContrastLive = 1;

  function parse(s) {
    if (!s) return null; s = String(s).trim();
    var m = s.match(/^rgba?\(([^)]+)\)$/i);
    if (m) { var v = m[1].split(/[\s,\/]+/).filter(Boolean).map(parseFloat); return { r: v[0], g: v[1], b: v[2], a: v.length > 3 ? v[3] : 1 }; }
    m = s.match(/^color\(srgb\s+([^)]+)\)$/i);
    if (m) { var w = m[1].split(/[\s\/]+/).filter(Boolean).map(parseFloat); return { r: w[0] * 255, g: w[1] * 255, b: w[2] * 255, a: w.length > 3 ? w[3] : 1 }; }
    if (s === 'transparent') return { r: 0, g: 0, b: 0, a: 0 };
    return null;
  }
  function colorsIn(s) {
    var out = [], re = /rgba?\([^)]*\)|color\(srgb[^)]*\)/gi, m;
    while ((m = re.exec(s))) { var c = parse(m[0]); if (c) out.push(c); }
    return out;
  }
  function blend(top, base) { var a = top.a; return { r: top.r * a + base.r * (1 - a), g: top.g * a + base.g * (1 - a), b: top.b * a + base.b * (1 - a), a: 1 }; }
  function lum(c) { function f(x) { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); } return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); }
  function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }

  var cache = new Map();
  /* the solid colour behind an element, or null when a photo / strong gradient makes it unknowable */
  function bgBehind(el) {
    if (cache.has(el)) return cache.get(el);
    var chain = [], n = el;
    while (n && n.nodeType === 1) { chain.push(n); n = n.parentElement; }
    var base = { r: 255, g: 255, b: 255, a: 1 };   // the canvas
    for (var i = chain.length - 1; i >= 0; i--) {
      var e = chain[i], cs = getComputedStyle(e);
      // something floating over other content (a header laid over the hero photo): what is behind it is not its parents
      if (/^(absolute|fixed|sticky)$/.test(cs.position)) base = null;
      var img = cs.backgroundImage;
      if (img && img !== 'none') {
        if (/url\(/i.test(img)) base = null;
        else {
          var stops = colorsIn(img);
          var strong = stops.some(function (c) { return c.a > 0.35; });
          if (strong || !stops.length) base = null;   // a real gradient paints the background; a faint wash does not
        }
      }
      var bc = parse(cs.backgroundColor);
      if (bc && bc.a > 0) {
        if (bc.a >= 0.95 || (!base && bc.a >= 0.85)) base = { r: bc.r, g: bc.g, b: bc.b, a: 1 };   // a near-solid bar paints its own background
        else if (base) base = blend(bc, base);
      }
      // the editor's section overlay: box-shadow: inset 0 0 0 100vmax rgba(...)
      var sh = cs.boxShadow;
      if (sh && sh !== 'none' && /inset/.test(sh) && /100vmax|\d{3,}px/.test(sh)) {
        var vc = colorsIn(sh)[0]; if (vc && base) base = blend(vc, base);
      }
    }
    cache.set(el, base);
    return base;
  }

  function ink() {
    var cs = getComputedStyle(document.documentElement);
    var names = ['--lu-text', '--ink', '--text', '--fg', '--body-color', '--dark', '--espresso'];
    for (var i = 0; i < names.length; i++) {
      var v = cs.getPropertyValue(names[i]).trim();
      if (/^#([0-9a-f]{6})$/i.test(v)) { var h = v.slice(1); return { css: v, c: { r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16), a: 1 } }; }
    }
    return { css: '#111827', c: { r: 17, g: 24, b: 39, a: 1 } };
  }

  var fixed = 0;
  function run() {
    cache = new Map();
    var INK = ink(), WHITE = { css: '#FFFFFF', c: { r: 255, g: 255, b: 255, a: 1 } };
    var seen = new Set();
    var tw = document.createTreeWalker(document.body || document.documentElement, NodeFilter.SHOW_TEXT, null);
    var t, count = 0;
    while ((t = tw.nextNode()) && count < 4000) {
      if (!t.nodeValue || t.nodeValue.trim().length < 2) continue;
      var el = t.parentElement; if (!el || seen.has(el)) continue; seen.add(el); count++;
      if (el.closest('[data-lu-cg-off],script,style,noscript,svg,iframe,[aria-hidden="true"]')) continue;
      if (!el.getClientRects().length) continue;
      var cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) continue;
      if (/text/.test(cs.backgroundClip || cs.webkitBackgroundClip || '')) continue;   // gradient-filled headline
      var fill = parse(cs.webkitTextFillColor); if (fill && fill.a === 0) continue;
      var fg = parse(cs.color); if (!fg || fg.a < 0.15) continue;
      var bg = bgBehind(el); if (!bg) continue;
      var fgOn = fg.a < 1 ? blend(fg, bg) : fg;
      if (ratio(fgOn, bg) >= 3) continue;
      var pick = ratio(INK.c, bg) >= ratio(WHITE.c, bg) ? INK : WHITE;
      if (ratio(pick.c, bg) < 3) pick = ratio(WHITE.c, bg) > ratio({ r: 0, g: 0, b: 0, a: 1 }, bg) ? WHITE : { css: '#000000' };
      el.style.setProperty('color', pick.css, 'important');
      el.setAttribute('data-lu-cg', '1'); fixed++;
    }
    document.documentElement.setAttribute('data-lu-cg-fixed', String(fixed));
  }

  var timer = null;
  function later(ms) { clearTimeout(timer); timer = setTimeout(function () { try { run(); } catch (e) {} }, ms); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { later(50); }); else later(50);
  window.addEventListener('load', function () { later(300); });
  setTimeout(function () { later(0); }, 2500);   // after reveal animations and web fonts
  // the editor re-renders sections in place: check again after changes (class and content only, never our own style writes)
  try {
    new MutationObserver(function (ms) {
      for (var i = 0; i < ms.length; i++) { if (ms[i].type === 'childList' || ms[i].attributeName === 'class') { later(400); return; } }
    }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
  } catch (e) {}
})();
