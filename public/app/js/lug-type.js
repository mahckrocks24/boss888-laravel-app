/* ==========================================================================
   LUG TYPE — one type scale for today's screens (Liquid Glass).
   Runs only while <html> has .lg-ui.
   Today's modules set ~42 different font sizes, many inline. Until each module
   moves onto the .t-* classes, this pass snaps every piece of app text to the
   nearest step of the platform scale and its weight to one of four weights:
     sizes   snap to 12 · 13 · 14 · 15 · 16 · 18 · 22 · 28 · 34, then desktop renders
             12 · 13 · 13 · 13 · 14 · 15 · 18 · 22 · 26 px and phones 12 · 13 · 14 · 15 · 16 · 17 · 20 · 24 · 28 px
     weights 400 · 500 · 600 · 700
   Today's UI fonts (Inter, Bricolage, DM Sans, Syne…) become the platform font.
   Skipped: previews in other fonts (website/template previews keep their type), editors, inputs' placeholder-only nodes, [data-type-keep].
   New content is handled as it appears (MutationObserver, batched per frame).
   ========================================================================== */
(function () {
  if (window.__lugType) return;
  window.__lugType = true;
  var root = document.documentElement;
  if (!root.classList.contains('lg-ui')) return;

  var SCALE = [12, 13, 14, 15, 16, 18, 22, 28, 34];
  var SKIP = 'script, style, svg, canvas, iframe, video, img, code, pre, [contenteditable="true"], [data-type-keep], .lg-no-snap, [class*="preview"], [class*="thumb"]';
  var OURS = /plus jakarta sans/i;
  // the app's own UI families (today's modules hard-code these); anything else is a preview or special type
  var UI = /plus jakarta sans|inter|bricolage|dm sans|syne|manrope|space grotesk|jetbrains mono|system-ui|-apple-system|segoe ui/i;

  function snapSize(px) {
    if (px > 40) return px;                 // true display sizes (hero numbers) keep their size
    var best = SCALE[0];
    for (var i = 0; i < SCALE.length; i++) if (Math.abs(SCALE[i] - px) < Math.abs(best - px)) best = SCALE[i];
    return best;
  }
  // compact on desktop (Owner: text read too large), a touch larger on phones for readability
  var DESK = { 12: 12, 13: 13, 14: 13, 15: 13, 16: 14, 18: 15, 22: 18, 28: 22, 34: 26 };
  var PHONE = { 12: 12, 13: 13, 14: 14, 15: 15, 16: 16, 18: 17, 22: 20, 28: 24, 34: 28 };
  function fit(step) { var m = window.innerWidth >= 1024 ? DESK : PHONE; return m[step] || step; }
  function snapWeight(w) { w = parseInt(w, 10) || 400; return w <= 450 ? 400 : w <= 550 ? 500 : w <= 650 ? 600 : 700; }

  function hasOwnText(el) {
    if (el.matches('button, a, input, select, textarea, label, th, td, li, h1, h2, h3, h4, h5, h6, p')) return true;
    for (var n = el.firstChild; n; n = n.nextSibling) if (n.nodeType === 3 && /\S/.test(n.nodeValue)) return true;
    return false;
  }
  function fix(el) {
    if (el.nodeType !== 1 || el.closest(SKIP)) return;
    if (!hasOwnText(el)) return;
    var cs = getComputedStyle(el);
    if (!UI.test(cs.fontFamily)) return;              // previews and special surfaces keep their own type
    if (!OURS.test(cs.fontFamily)) el.style.setProperty('font-family', 'var(--lg-font)', 'important');
    var cur = parseFloat(cs.fontSize);
    // first visit, or the page changed the size since our last pass: take the page's size as the original
    if (el.__lgSet == null || Math.abs(cur - el.__lgSet) > 0.25) el.__lgPx = cur;
    var px = el.__lgPx;
    var s = snapSize(px); s = s === px && px > 40 ? px : fit(s); var w = snapWeight(cs.fontWeight);
    if (Math.abs(s - cur) > 0.25) el.style.setProperty('font-size', s + 'px', 'important');
    el.__lgSet = s;
    if (String(w) !== cs.fontWeight) el.style.setProperty('font-weight', String(w), 'important');
    // Plus Jakarta Sans has a narrow word space: negative tracking inherited from today's CSS crams words together.
    // Below 20px, tracking goes back to normal; headings keep a slight tightening; uppercase labels keep theirs.
    var ls = parseFloat(cs.letterSpacing);
    if (s < 20 && ls < 0) el.style.setProperty('letter-spacing', '0', 'important');
    else if (s >= 20 && ls < -0.02 * s) el.style.setProperty('letter-spacing', '-0.015em', 'important');
  }
  function sweep(node) {
    if (!node || node.nodeType !== 1) return;
    fix(node);
    var all = node.getElementsByTagName('*');
    for (var i = 0; i < all.length; i++) fix(all[i]);
  }
  function scope() { return [document.querySelector('.sidebar'), document.querySelector('.lu-topbar'), document.querySelector('.main')]; }

  var pending = [], self = [], queued = false;
  function later() { if (!queued) { queued = true; requestAnimationFrame(flush); } }
  function flush() {
    queued = false;
    var list = pending; pending = [];
    var one = self; self = [];
    for (var k = 0; k < one.length; k++) if (one[k].isConnected) fix(one[k]);
    for (var i = 0; i < list.length; i++) if (list[i].isConnected) sweep(list[i]);
  }
  function queue(n) { pending.push(n); if (!queued) { queued = true; requestAnimationFrame(flush); } }

  function start() {
    scope().forEach(function (s) { if (s) sweep(s); });
    var mo = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var a = muts[i].addedNodes;
        for (var j = 0; j < a.length; j++) if (a[j].nodeType === 1) queue(a[j]);
        if (muts[i].type === 'characterData' && muts[i].target.parentElement) queue(muts[i].target.parentElement);
        // a changed class/style re-checks that element only (never its subtree): cheap even during swipes and animations
        if (muts[i].type === 'attributes' && !muts[i].target.closest('.lg-aurora')) self.push(muts[i].target), later();
      }
    });
    mo.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'style'] });
    // fonts arriving late change nothing in size, but views often render after a data fetch: one late pass
    setTimeout(function () { scope().forEach(function (s) { if (s) sweep(s); }); }, 2500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
