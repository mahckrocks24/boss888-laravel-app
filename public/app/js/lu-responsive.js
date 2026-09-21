/*
 * LU responsive governor (2026-09-21) — the app shell on a phone or a narrow laptop window.
 *
 * Owner's rule: nothing goes beyond the screen; when content is wider than the screen it scrolls sideways
 * (a styled CSS scroller), it is not stacked into a tall column. Enterprise look, no browser chrome.
 *
 * The engines (SEO, CRM, Write, Social, Projects, Studio, Calendar…) render their own HTML with inline grids and
 * bare <table>s and carry no phone rules of their own. Rather than edit ~20 bundles, this governor watches the
 * DOM and applies two treatments, undone again when the window is wide:
 *
 *   1. every <table> not already inside a horizontal scroller is wrapped in .lu-tscroll (scrolls sideways, the
 *      table keeps its natural column widths);
 *   2. on a narrow window (≤ 767px) a grid of 3+ equal tracks that no longer fits its box becomes a .lu-hstrip
 *      (one row that scrolls sideways, snap points, styled scrollbar) — KPI rows, stat cards, metric strips.
 *
 * lu-mobile.css carries the rules. Marking is idempotent; engines that re-render are caught by the observer.
 */
(function (w, d) {
  'use strict';
  if (w.LUResponsive) { return; }
  var NARROW = 767;
  function narrow() { return w.innerWidth <= NARROW; }
  function xScroller(el) { var p = el.parentElement; while (p && p !== d.body) { var cs = w.getComputedStyle(p); if (/auto|scroll/.test(cs.overflowX)) { return p; } p = p.parentElement; } return null; }
  function wrapTables(root) {
    var ts = root.querySelectorAll ? root.querySelectorAll('table') : [];
    for (var i = 0; i < ts.length; i++) {
      var t = ts[i];
      if (t.closest('.lu-tscroll') || t.closest('[data-lu-nowrap]')) { continue; }
      var sc = xScroller(t);
      if (sc && sc !== t.parentElement && sc.clientHeight < w.innerHeight * 0.6) { continue; } /* an engine's own table scroller */
      if (t.parentElement && t.parentElement.classList.contains('table-wrap')) { t.parentElement.classList.add('lu-tscroll'); continue; }
      if (t.parentElement && t.parentElement.classList.contains('lgse-table-wrap')) { t.parentElement.classList.add('lu-tscroll'); continue; }
      var wr = d.createElement('div'); wr.className = 'lu-tscroll'; t.parentNode.insertBefore(wr, t); wr.appendChild(t);
    }
  }
  function tracks(cs) { var g = cs.gridTemplateColumns; if (!g || g === 'none') { return 0; } return g.trim().split(/\s+/).filter(function (x) { return x !== '/'; }).length; }
  function strips(root) {
    var isNarrow = narrow();
    var els = root.querySelectorAll ? root.querySelectorAll('.lu-hstrip, .lu-stack, [style*="grid-template-columns"], [class*="grid"], [class*="kpi"], [class*="stats"], [class*="metrics"], [class*="cards"]') : [];
    for (var i = 0; i < els.length; i++) {
      var e = els[i];
      if (e.offsetParent === null) { continue; } /* not laid out: another view or a closed panel */
      if (e.closest('[data-lu-nostrip], .lu-hstrip .lu-hstrip, table')) { continue; }
      if (e.classList.contains('lu-hstrip') || e.classList.contains('lu-stack')) {
        /* undo when wide again */
        if (!isNarrow) { e.classList.remove('lu-hstrip'); e.classList.remove('lu-stack'); }
        continue;
      }
      if (!isNarrow) { continue; }
      var cs = w.getComputedStyle(e); if (cs.display !== 'grid') { continue; }
      var n = tracks(cs); if (e.children.length < 2 || e.children.length > 8) { continue; }
      /* a KPI / metric / stat row: a handful of short cards. Long card lists stay vertical. */
      var tall = false, tops = {}, rows = 0; for (var c = 0; c < e.children.length; c++) { var cr = e.children[c].getBoundingClientRect(); if (cr.height > 160) { tall = true; } var key = Math.round(cr.top); if (!tops[key]) { tops[key] = 1; rows++; } }
      var r = e.getBoundingClientRect(); if (r.width < 100) { continue; }
      /* does it fit? the narrowest reasonable card is 150px */
      var tr = cs.gridTemplateColumns.trim().split(/\s+/).map(parseFloat).filter(function (x) { return !isNaN(x); }), minTrack = tr.length ? Math.min.apply(null, tr) : r.width;
      var overflow = e.scrollWidth > e.clientWidth + 2, tight = minTrack < 150;
      /* tall cards (a score dial, a chart) stack into one column instead: a strip of half-hidden tall cards reads as broken */
      if (tall) { if (n >= 2 && (tight || overflow)) { e.classList.add('lu-stack'); } continue; }
      if (n >= 2 && n <= 3 && tight && r.width < 480 && e.children.length <= 4) { e.classList.add('lu-stack'); continue; } /* a two-column layout with a squeezed column stacks */
      if (e.children.length < 3 || (n < 3 && rows < 2)) { continue; }
      var kids = e.children, textCut = false;
      for (var k = 0; k < kids.length && !textCut; k++) { if (kids[k].scrollWidth > kids[k].clientWidth + 2) { textCut = true; } }
      if (overflow || tight || textCut) { e.classList.add('lu-hstrip'); }
    }
  }
  /* Cheap by construction (a phone, a 1-core server): no polling. Added subtrees are processed after the mutation burst
     settles; a view switch or a resize re-reads only the view that is on screen. */
  var queue = [], timer = null;
  function visibleView() { var vs = d.querySelectorAll('[id^="view-"]'); for (var i = 0; i < vs.length; i++) { if (vs[i].offsetParent !== null && vs[i].getBoundingClientRect().height > 40) { return vs[i]; } } return null; }
  function run(roots) {
    try {
      var seen = [];
      for (var i = 0; i < roots.length; i++) { var r = roots[i]; if (!r || !r.isConnected || r.nodeType !== 1) { continue; } var covered = false; for (var j = 0; j < seen.length; j++) { if (seen[j].contains(r)) { covered = true; break; } } if (covered) { continue; } seen.push(r); wrapTables(r); strips(r); }
    } catch (e) {}
  }
  function schedule(root) { if (root) { queue.push(root); } if (timer) { clearTimeout(timer); } timer = setTimeout(function () { timer = null; var q = queue; queue = []; if (!q.length) { var v = visibleView(); if (v) { q = [v]; } } run(q); }, 120); }
  function start() {
    run([d.body]);
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.type === 'childList') { for (var k = 0; k < m.addedNodes.length; k++) { var n = m.addedNodes[k]; if (n.nodeType === 1 && !n.closest('.lu-sel-menu, .lucp')) { queue.push(n); } } }
        else if (m.target && m.target.id && m.target.id.indexOf('view-') === 0) { queue.push(m.target); } /* a view shown or hidden */
      }
      if (queue.length) { schedule(); }
    }).observe(d.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
    w.addEventListener('resize', function () { schedule(); });
    /* Messages on a phone: picking a conversation slides to the thread page; the header's back gesture is a swipe */
    d.addEventListener('click', function (e) {
      if (!narrow()) { return; }
      var list = e.target.closest ? e.target.closest('#lu-msg-page-agents') : null; if (!list || !list.nextElementSibling) { return; }
      setTimeout(function () { list.nextElementSibling.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' }); }, 60);
    }, true);
  }
  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', start); } else { start(); }
  w.LUResponsive = { run: function () { run([d.body]); }, narrow: narrow };
})(window, document);
