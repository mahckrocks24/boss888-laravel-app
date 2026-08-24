/* ═══════════════════════════════════════════════════════════════════════
   STUDIO888 — Overlay Handles Editor (Phase R implementation)
   A production-quality INTERACTION layer over the LIVE iframe HTML renderer.

   The iframe (srcdoc = content_html) remains the single source of truth for
   RENDERING and PERSISTENCE. This module adds selection, 8-handle resize,
   move, rotate, multi-select, keyboard, snapping/guides, undo/redo, AI-edit,
   and navigation suppression — WITHOUT a canvas renderer or an HTML importer.

   Undo model: IIFE1 (the iframe editor) has NO history of its own; _stSaveHistory
   belongs to the separate native element editor (studio_elements). So history
   here is THE history for the iframe surface — element-level style/src snapshots
   (undo/redo closures), NOT a parallel system. Each committed action persists
   via the existing serialize-html → _stPersistHtml path.

   Feature-flagged: active only when localStorage.st_overlay_spike === '1'.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
  var SNAP_TOL = 6;      // px (frame space) snap tolerance
  var ROT_SNAP = 15;     // deg

  var S = {
    on: false,
    iframe: null,
    layer: null,          // overlay layer (inside #st-canvas-frame)
    hover: null,          // hover outline element
    boxes: [],            // {el, node, handles{}, rot} per selected element
    sel: [],              // selected iframe nodes (data-field elements)
    drag: null,           // active gesture
    raf: 0,               // requestAnimationFrame id
    pendingEvt: null,
    docClick: null,
    hist: { stack: [], index: -1 },
    guides: [],           // guide line elements
    committedThisDrag: false,
  };

  // ── geometry ────────────────────────────────────────────────
  function _scale() {
    if (typeof window._zoom === 'number' && window._zoom > 0) return window._zoom; // rarely accurate
    try { var t = document.getElementById('st-canvas-transform'); var m = new WebKitCSSMatrix(getComputedStyle(t).transform); if (m && m.a) return m.a; } catch (_e) {}
    return 1;
  }
  function _rectInFrame(node) {
    if (!node || typeof node.getBoundingClientRect !== 'function') return { x: 0, y: 0, w: 0, h: 0 };
    var doc = S.iframe.contentDocument;
    var r = node.getBoundingClientRect();
    var base = doc.documentElement.getBoundingClientRect();
    return { x: r.left - base.left, y: r.top - base.top, w: r.width, h: r.height };
  }
  function _fieldKind(el) {
    if (el.tagName === 'IMG') return 'image';
    if (el.querySelector && el.querySelector('img')) return 'image';
    return 'text';
  }
  function _imgOf(el) { return el.tagName === 'IMG' ? el : (el.querySelector && el.querySelector('img')); }

  // Hit-test the REAL element under a parent-viewport point, seeing THROUGH the
  // overlay (elementFromPoint runs in the iframe's own document, which ignores
  // the parent overlay layer). Lets a click on a selection box re-select an
  // overlapping element behind it, so the box never blocks selection.
  function _hitTestField(clientX, clientY) {
    try {
      var ir = S.iframe.getBoundingClientRect(), scale = _scale();
      var ix = (clientX - ir.left) / scale, iy = (clientY - ir.top) / scale;
      var el = S.iframe.contentDocument.elementFromPoint(ix, iy);
      return el && el.closest ? el.closest('[data-field]') : null;
    } catch (_e) { return null; }
  }

  // transform parsing (translate + rotate)
  function _parseXform(node) {
    var tr = node.style.transform || '';
    var t = /translate\(\s*(-?[\d.]+)px\s*,\s*(-?[\d.]+)px\s*\)/.exec(tr);
    var r = /rotate\(\s*(-?[\d.]+)deg\s*\)/.exec(tr);
    return { x: t ? parseFloat(t[1]) : 0, y: t ? parseFloat(t[2]) : 0, deg: r ? parseFloat(r[1]) : 0 };
  }
  function _writeXform(node, x, y, deg) {
    var parts = [];
    if (x || y) parts.push('translate(' + Math.round(x) + 'px,' + Math.round(y) + 'px)');
    if (deg) parts.push('rotate(' + (Math.round(deg * 10) / 10) + 'deg)');
    // preserve any non-translate/rotate transform the template had
    var rest = (node.style.transform || '').replace(/translate\([^)]*\)/g, '').replace(/rotate\([^)]*\)/g, '').trim();
    node.style.transform = (parts.join(' ') + ' ' + rest).trim();
  }

  // ── overlay DOM ─────────────────────────────────────────────
  function _ensureLayer() {
    var frame = document.getElementById('st-canvas-frame');
    if (!frame) return null;
    var layer = frame.querySelector('#st-overlay-layer');
    if (!layer) {
      layer = document.createElement('div');
      layer.id = 'st-overlay-layer';
      layer.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:6;';
      // hover outline
      var hv = document.createElement('div');
      hv.id = 'st-ov-hover';
      hv.style.cssText = 'position:absolute;display:none;border:1px solid rgba(108,92,231,.6);pointer-events:none;box-sizing:border-box;';
      layer.appendChild(hv);
      frame.appendChild(layer);
      S.hover = hv;
      _injectCss();
    } else {
      S.hover = layer.querySelector('#st-ov-hover');
    }
    S.layer = layer;
    return layer;
  }
  function _injectCss() {
    if (document.getElementById('st-ov-css')) return;
    var s = document.createElement('style'); s.id = 'st-ov-css';
    s.textContent =
      '.st-ov-box{position:absolute;border:1.5px solid #6C5CE7;box-shadow:0 0 0 1px rgba(255,255,255,.35);pointer-events:auto;cursor:move;box-sizing:border-box}' +
      '.st-ov-h{position:absolute;width:10px;height:10px;background:#fff;border:1.5px solid #6C5CE7;border-radius:2px;pointer-events:auto;box-sizing:border-box}' +
      '.st-ov-h.nw{left:-5px;top:-5px;cursor:nwse-resize}.st-ov-h.n{left:calc(50% - 5px);top:-5px;cursor:ns-resize}.st-ov-h.ne{right:-5px;top:-5px;cursor:nesw-resize}' +
      '.st-ov-h.e{right:-5px;top:calc(50% - 5px);cursor:ew-resize}.st-ov-h.se{right:-5px;bottom:-5px;cursor:nwse-resize}.st-ov-h.s{left:calc(50% - 5px);bottom:-5px;cursor:ns-resize}' +
      '.st-ov-h.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.st-ov-h.w{left:-5px;top:calc(50% - 5px);cursor:ew-resize}' +
      '.st-ov-rot{position:absolute;left:calc(50% - 6px);top:-26px;width:12px;height:12px;border-radius:50%;background:#6C5CE7;border:2px solid #fff;pointer-events:auto;cursor:grab}' +
      '.st-ov-rot::before{content:"";position:absolute;left:5px;top:12px;width:2px;height:14px;background:#6C5CE7}' +
      '.st-ov-label{position:absolute;left:0;top:-20px;font:600 10px/1 system-ui;background:#6C5CE7;color:#fff;padding:2px 6px;border-radius:3px;white-space:nowrap;pointer-events:none}' +
      '.st-ov-toolbar{position:absolute;right:0;top:-24px;display:flex;gap:4px;pointer-events:auto}' +
      '.st-ov-toolbar button{font:600 10px/1 system-ui;background:#171b23;color:#fff;border:1px solid #6C5CE7;border-radius:4px;padding:3px 6px;cursor:pointer}' +
      '.st-ov-guide{position:absolute;background:#FF3D7F;pointer-events:none;z-index:7}' +
      '.st-field.st-ov-synced{outline:2px solid #6C5CE7 !important;outline-offset:-2px;background:rgba(108,92,231,.12) !important;border-radius:6px}';
    document.head.appendChild(s);
  }

  // build box(es) for current selection
  function _renderBoxes() {
    // clear
    if (typeof _closeCopyPop === 'function') _closeCopyPop();
    S.boxes.forEach(function (b) { if (b.box.parentNode) b.box.parentNode.removeChild(b.box); });
    S.boxes = [];
    if (!S.layer) return;
    S.sel.forEach(function (node, idx) {
      var box = document.createElement('div');
      box.className = 'st-ov-box';
      box.dataset.idx = idx;
      // label (only on the primary/first)
      if (idx === 0) {
        var lbl = document.createElement('div'); lbl.className = 'st-ov-label'; box.appendChild(lbl);
        var tb = document.createElement('div'); tb.className = 'st-ov-toolbar';
        if (_fieldKind(node) === 'image') {
          tb.innerHTML = '<button data-act="replace" title="Replace image">Replace</button><button data-act="aiedit" title="AI Edit">✦ AI</button>';
        } else {
          tb.innerHTML = '<button data-act="font" title="Font">Aa</button><button data-act="suggestcopy" title="AI copy ideas">✦ Copy</button>';
        }
        box.appendChild(tb);
        var rot = document.createElement('div'); rot.className = 'st-ov-rot'; rot.dataset.act = 'rotate'; box.appendChild(rot);
      }
      HANDLES.forEach(function (h) { var el = document.createElement('div'); el.className = 'st-ov-h ' + h; el.dataset.handle = h; box.appendChild(el); });
      S.layer.appendChild(box);
      var rec = { node: node, box: box };
      S.boxes.push(rec);
      _wireBox(rec);
    });
    _positionBoxes();
    _alignBar();
  }
  function _positionBoxes() {
    S.boxes.forEach(function (rec) {
      var r = _rectInFrame(rec.node);
      var x = _parseXform(rec.node);
      rec.box.style.left = r.x + 'px'; rec.box.style.top = r.y + 'px';
      rec.box.style.width = r.w + 'px'; rec.box.style.height = r.h + 'px';
      rec.box.style.transform = x.deg ? ('rotate(' + x.deg + 'deg)') : '';
      rec.box.style.transformOrigin = 'center center';
      var lbl = rec.box.querySelector('.st-ov-label');
      if (lbl) lbl.textContent = (rec.node.getAttribute('data-field') || rec.node.tagName.toLowerCase()) + '  ' + Math.round(r.w) + '×' + Math.round(r.h) + (x.deg ? '  ' + Math.round(x.deg) + '°' : '');
    });
  }

  // ── selection ───────────────────────────────────────────────
  function _select(node, additive) {
    // locked elements are not selectable/editable on the canvas (unlock via Layers panel)
    if (node && node.getAttribute && node.getAttribute('data-locked') === '1') { if (!additive) { _deselectAll(); } return; }
    if (!additive) S.sel = [];
    if (S.sel.indexOf(node) === -1) S.sel.push(node);
    _renderBoxes();
    _syncPanel();
    _syncSidebar(node);
  }

  // Canvas → sidebar sync: selecting an element on the canvas highlights (and
  // scrolls to) the matching field row in the left panel, switching to the
  // correct tab so it's visible. Satisfies "Click WE → HEADLINE 1 in sidebar".
  function _syncSidebar(node) {
    try {
      if (!node) return;
      var name = node.getAttribute('data-field');
      var kind = _fieldKind(node);
      var wantTab = kind === 'image' ? 'images' : 'content';
      var tabEl = document.querySelector('.st-tab[data-tab="' + wantTab + '"]');
      if (tabEl && !tabEl.classList.contains('active') && typeof window._studioSwitchTab === 'function') {
        window._studioSwitchTab(wantTab);
      }
      var rows = document.querySelectorAll('#st-tab-body .st-field');
      var match = null;
      Array.prototype.forEach.call(rows, function (r) {
        r.classList.remove('st-ov-synced');
        var oc = r.getAttribute('onclick') || '';
        if (oc.indexOf("'" + name + "'") !== -1) match = r;
      });
      if (match) { match.classList.add('st-ov-synced'); try { match.scrollIntoView({ block: 'nearest' }); } catch (_e) {} }
    } catch (_e) {}
  }
  function _deselectAll() {
    S.sel = []; _renderBoxes(); if (S.hover) S.hover.style.display = 'none';
    try { Array.prototype.forEach.call(document.querySelectorAll('#st-tab-body .st-field.st-ov-synced'), function (r) { r.classList.remove('st-ov-synced'); }); } catch (_e) {}
  }

  function _setHover(node) {
    if (!S.hover) return;
    if (!node || S.sel.indexOf(node) !== -1) { S.hover.style.display = 'none'; return; }
    var r = _rectInFrame(node);
    S.hover.style.display = 'block';
    S.hover.style.left = r.x + 'px'; S.hover.style.top = r.y + 'px';
    S.hover.style.width = r.w + 'px'; S.hover.style.height = r.h + 'px';
  }

  // ── history (element-level, the iframe surface's own undo) ──
  function _snapStyle(node) { return node.getAttribute('style') || ''; }
  function _histPushStyle(node, before) {
    var after = _snapStyle(node);
    if (before === after) return;
    _histPush({ undo: function () { node.setAttribute('style', before); _afterHist(node); },
                redo: function () { node.setAttribute('style', after); _afterHist(node); } });
  }
  function _histPush(entry) {
    S.hist.stack = S.hist.stack.slice(0, S.hist.index + 1);
    S.hist.stack.push(entry);
    if (S.hist.stack.length > 60) S.hist.stack.shift();
    S.hist.index = S.hist.stack.length - 1;
  }
  function _undo() { if (S.hist.index < 0) return; S.hist.stack[S.hist.index].undo(); S.hist.index--; }
  function _redo() { if (S.hist.index >= S.hist.stack.length - 1) return; S.hist.index++; S.hist.stack[S.hist.index].redo(); }
  function _afterHist(node) { _positionBoxes(); _commit(); }

  // ── commit / persist (reuse existing serialize→_stPersistHtml) ──
  function _commit() {
    try { S.iframe.contentWindow.postMessage({ type: 'serialize-html' }, '*'); } catch (_e) {}
  }

  // ── drag (move / resize / rotate) with RAF batching ─────────
  function _wireBox(rec) {
    var box = rec.box;
    box.addEventListener('pointerdown', function (e) {
      var act = e.target.dataset ? e.target.dataset.act : null;
      var handle = e.target.dataset ? e.target.dataset.handle : null;
      if (act === 'replace') { e.stopPropagation(); _replaceImage(rec.node); return; }
      if (act === 'aiedit') { e.stopPropagation(); _aiEdit(rec.node); return; }
      if (act === 'suggestcopy') { e.stopPropagation(); _suggestCopy(rec.node); return; }
      if (act === 'font') { e.stopPropagation(); _fontPopup(rec.node); return; }
      if (act === 'rotate') { _startRotate(rec, e); return; }
      if (handle) { _startResize(rec, handle, e); return; }
      // interior press: if a DIFFERENT data-field sits under the cursor (an
      // overlapping/nearby element hidden behind this box), select & move THAT
      // one instead — the selection box must never block selecting elements.
      var under = _hitTestField(e.clientX, e.clientY);
      if (under && S.sel.indexOf(under) === -1) {
        _select(under, e.shiftKey);
        var nr = null; for (var i = 0; i < S.boxes.length; i++) { if (S.boxes[i].node === under) { nr = S.boxes[i]; break; } }
        if (nr) { _startMove(nr, e); return; }
      }
      _startMove(rec, e);
    });
  }
  function _beforeSnapshots() {
    // capture style of all selected for a single grouped undo entry
    var recs = S.sel.map(function (n) { return { n: n, before: _snapStyle(n) }; });
    return function commit() {
      var afters = recs.map(function (r) { return { n: r.n, before: r.before, after: _snapStyle(r.n) }; })
                       .filter(function (r) { return r.before !== r.after; });
      if (!afters.length) return;
      _histPush({ undo: function () { afters.forEach(function (a) { a.n.setAttribute('style', a.before); }); _positionBoxes(); _commit(); },
                  redo: function () { afters.forEach(function (a) { a.n.setAttribute('style', a.after); }); _positionBoxes(); _commit(); } });
      _commit();
    };
  }
  function _startMove(rec, e) {
    e.preventDefault();
    var scale = _scale();
    var starts = S.sel.map(function (n) { var x = _parseXform(n); return { n: n, tx: x.x, ty: x.y, deg: x.deg }; });
    var commitHist = _beforeSnapshots();
    S.drag = { mode: 'move', sx: e.clientX, sy: e.clientY, scale: scale, starts: starts, commitHist: commitHist };
    _bindWindowDrag(e);
  }
  function _startResize(rec, handle, e) {
    e.preventDefault(); e.stopPropagation();
    var scale = _scale();
    var r = _rectInFrame(rec.node); var x = _parseXform(rec.node);
    var commitHist = _beforeSnapshots();
    S.drag = { mode: 'resize', handle: handle, sx: e.clientX, sy: e.clientY, scale: scale, node: rec.node, w0: r.w, h0: r.h, tx0: x.x, ty0: x.y, deg: x.deg, commitHist: commitHist };
    _bindWindowDrag(e);
  }
  function _startRotate(rec, e) {
    e.preventDefault(); e.stopPropagation();
    var r = _rectInFrame(rec.node);
    var ir = S.iframe.getBoundingClientRect(); var scale = _scale();
    var cx = ir.left + (r.x + r.w / 2) * scale, cy = ir.top + (r.y + r.h / 2) * scale;
    var commitHist = _beforeSnapshots();
    S.drag = { mode: 'rotate', node: rec.node, cx: cx, cy: cy, commitHist: commitHist };
    _bindWindowDrag(e);
  }
  function _bindWindowDrag(e) {
    S.committedThisDrag = false;
    window.addEventListener('pointermove', _onDragMove, true);
    window.addEventListener('pointerup', _onDragUp, true);
    try { e.target.setPointerCapture && e.target.setPointerCapture(e.pointerId); } catch (_e) {}
  }
  function _onDragMove(e) {
    if (!S.drag) return;
    S.pendingEvt = e;
    if (!S.raf) S.raf = requestAnimationFrame(_applyDrag);
  }
  function _applyDrag() {
    S.raf = 0;
    var e = S.pendingEvt; if (!e || !S.drag) return;
    var d = S.drag, scale = d.scale || _scale();
    if (d.mode === 'move') {
      var dx = (e.clientX - d.sx) / scale, dy = (e.clientY - d.sy) / scale;
      // snapping on the primary element
      var snapped = _applySnap(d.starts[0].n, dx, dy);
      dx = snapped.dx; dy = snapped.dy;
      d.starts.forEach(function (s) { _writeXform(s.n, s.tx + dx, s.ty + dy, s.deg); });
    } else if (d.mode === 'resize') {
      var ddx = (e.clientX - d.sx) / scale, ddy = (e.clientY - d.sy) / scale;
      var w = d.w0, h = d.h0, tx = d.tx0, ty = d.ty0, hn = d.handle;
      if (hn.indexOf('e') !== -1) w = Math.max(8, d.w0 + ddx);
      if (hn.indexOf('s') !== -1) h = Math.max(8, d.h0 + ddy);
      if (hn.indexOf('w') !== -1) { w = Math.max(8, d.w0 - ddx); tx = d.tx0 + (d.w0 - w); }
      if (hn.indexOf('n') !== -1) { h = Math.max(8, d.h0 - ddy); ty = d.ty0 + (d.h0 - h); }
      var img = _imgOf(d.node);
      if (_fieldKind(d.node) === 'image' && img) { img.style.width = Math.round(w) + 'px'; img.style.height = Math.round(h) + 'px'; }
      else { d.node.style.width = Math.round(w) + 'px'; d.node.style.height = Math.round(h) + 'px'; }
      if (tx !== d.tx0 || ty !== d.ty0) _writeXform(d.node, tx, ty, d.deg);
    } else if (d.mode === 'rotate') {
      var ang = Math.atan2(e.clientY - d.cy, e.clientX - d.cx) * 180 / Math.PI + 90;
      if (!e.shiftKey) ang = Math.round(ang / ROT_SNAP) * ROT_SNAP;
      var xf = _parseXform(d.node); _writeXform(d.node, xf.x, xf.y, ang);
    }
    _positionBoxes();
    _syncPanel();
  }
  function _onDragUp() {
    if (!S.drag) return;
    if (S.raf) { cancelAnimationFrame(S.raf); S.raf = 0; if (S.pendingEvt) _applyDragNow(); }
    _clearGuides();
    var commit = S.drag.commitHist; S.drag = null;
    window.removeEventListener('pointermove', _onDragMove, true);
    window.removeEventListener('pointerup', _onDragUp, true);
    if (commit) commit();
  }
  function _applyDragNow() { var r = S.raf; S.raf = 0; _applyDrag(); }

  // ── snapping + guides ───────────────────────────────────────
  function _snapTargets(exclude) {
    var doc = S.iframe.contentDocument;
    var W = doc.documentElement.clientWidth || doc.body.clientWidth;
    var H = doc.documentElement.scrollHeight || doc.body.clientHeight;
    var xs = [0, W / 2, W], ys = [0, H / 2, H];
    Array.prototype.forEach.call(doc.querySelectorAll('[data-field]'), function (n) {
      if (n === exclude) return;
      var r = _rectInFrame(n);
      xs.push(r.x, r.x + r.w / 2, r.x + r.w); ys.push(r.y, r.y + r.h / 2, r.y + r.h);
    });
    return { xs: xs, ys: ys, W: W, H: H };
  }
  function _applySnap(node, dx, dy) {
    _clearGuides();
    if (!node) return { dx: dx, dy: dy };
    var r0 = _rectInFrame(node); // current (already includes prior translate)
    var x0 = _parseXform(node);
    // predicted position edges after this drag delta (relative to base rect minus current translate)
    var baseX = r0.x - x0.x, baseY = r0.y - x0.y;
    var startTx = S.drag.starts ? S.drag.starts[0].tx : x0.x;
    var startTy = S.drag.starts ? S.drag.starts[0].ty : x0.y;
    var left = baseX + startTx + dx, top = baseY + startTy + dy;
    var edgesX = [left, left + r0.w / 2, left + r0.w], edgesY = [top, top + r0.h / 2, top + r0.h];
    var T = _snapTargets(node), tol = SNAP_TOL / (S.drag.scale || 1) * (S.drag.scale || 1);
    var bestX = null, bestY = null;
    edgesX.forEach(function (ex, i) { T.xs.forEach(function (tx) { if (Math.abs(ex - tx) <= SNAP_TOL && (bestX === null || Math.abs(ex - tx) < Math.abs(bestX.d))) bestX = { d: ex - tx, at: tx, i: i }; }); });
    edgesY.forEach(function (ey, i) { T.ys.forEach(function (ty) { if (Math.abs(ey - ty) <= SNAP_TOL && (bestY === null || Math.abs(ey - ty) < Math.abs(bestY.d))) bestY = { d: ey - ty, at: ty, i: i }; }); });
    if (bestX) { dx -= bestX.d; _guide('v', bestX.at); }
    if (bestY) { dy -= bestY.d; _guide('h', bestY.at); }
    return { dx: dx, dy: dy };
  }
  function _guide(orient, at) {
    if (!S.layer) return;
    var g = document.createElement('div'); g.className = 'st-ov-guide';
    if (orient === 'v') g.style.cssText += 'left:' + at + 'px;top:0;width:1px;height:100%;';
    else g.style.cssText += 'top:' + at + 'px;left:0;height:1px;width:100%;';
    g.classList.add('st-ov-guide');
    g.style.position = 'absolute'; g.style.background = '#FF3D7F';
    S.layer.appendChild(g); S.guides.push(g);
  }
  function _clearGuides() { S.guides.forEach(function (g) { if (g.parentNode) g.parentNode.removeChild(g); }); S.guides = []; }

  // ── property panel sync (lightweight; existing tabs remain source of truth for text/color) ──
  function _syncPanel() {
    // reflect position/size on the primary selection into a small readout if present
    var panel = document.getElementById('st-ov-props');
    if (!panel || !S.sel.length) return;
    var r = _rectInFrame(S.sel[0]); var x = _parseXform(S.sel[0]);
    panel.textContent = 'x ' + Math.round(x.x) + '  y ' + Math.round(x.y) + '  w ' + Math.round(r.w) + '  h ' + Math.round(r.h) + '  ' + Math.round(x.deg) + '°';
  }

  // ── image actions ───────────────────────────────────────────
  function _replaceImage(node) {
    var name = node.getAttribute('data-field');
    if (name && typeof window._studioReplaceImage === 'function') { window._studioReplaceImage(name); }
  }
  function _aiEdit(node) {
    var img = _imgOf(node); if (!img) return;
    var url = img.currentSrc || img.src || img.getAttribute('src') || '';
    if (!url) return;
    var core = window.__studioCore;
    var fetchJson = (core && core.fetchJson) || window._fetchJson;
    if (!fetchJson || typeof window.meOpenAiEdit !== 'function') { try { window.showToast && window.showToast('AI editor still loading', 'info'); } catch (_e) {} return; }
    fetchJson('/creative/resolve-asset?url=' + encodeURIComponent(url)).then(function (d) {
      var asset = d && (d.asset || d.data || d); if (!asset || !asset.id) return;
      window.meOpenAiEdit(asset, {
        applyLabel: 'Use in this design',
        onApply: function (version) {
          if (!version || !version.url) return;
          var before = _snapStyle(node);
          var img2 = _imgOf(node); var prevSrc = img2 ? img2.getAttribute('src') : null;
          if (img2) img2.setAttribute('src', version.url);
          _histPush({ undo: function () { if (img2) img2.setAttribute('src', prevSrc); _positionBoxes(); _commit(); },
                      redo: function () { if (img2) img2.setAttribute('src', version.url); _positionBoxes(); _commit(); } });
          _positionBoxes(); _commit();
        }
      });
    }).catch(function () {});
  }

  // ── C3: AI copy suggestions for a selected text field ──────────
  function _fieldTypeOf(name) {
    name = (name || '').toLowerCase();
    if (/cta|button|action|reserve|book|buy|shop|order|call/.test(name)) return 'call-to-action';
    if (/sub|desc|body|para|blurb|caption/.test(name)) return 'subheading';
    if (/tag|badge|label|est|amen|feature|location/.test(name)) return 'short label';
    return 'headline';
  }
  function _closeCopyPop() { Array.prototype.forEach.call(document.querySelectorAll('.st-ov-pop'), function (p) { if (p.parentNode) p.parentNode.removeChild(p); }); }
  function _suggestCopy(node) {
    var core = window.__studioCore;
    var fetchJson = (core && core.fetchJson) || window._fetchJson;
    var toast = (core && core.toast) || window.showToast || function () {};
    if (!fetchJson) { toast('AI still loading', 'info'); return; }
    _closeCopyPop();
    var name = node.getAttribute('data-field') || '';
    var cur = (node.textContent || '').trim();
    var ftype = _fieldTypeOf(name);
    var pop = document.createElement('div'); pop.id = 'st-ov-copypop'; pop.className = 'st-ov-pop';
    pop.style.cssText = 'position:fixed;z-index:100001;background:#0f131a;border:1px solid #6C5CE7;border-radius:10px;padding:8px;min-width:260px;max-width:340px;box-shadow:0 10px 40px rgba(0,0,0,.6);font:400 13px/1.4 system-ui,-apple-system,sans-serif;color:#fff';
    var fr = S.iframe ? S.iframe.getBoundingClientRect() : { left: 60, top: 60 };
    pop.style.left = Math.max(12, Math.min(window.innerWidth - 360, fr.left + 24)) + 'px';
    pop.style.top = Math.max(12, fr.top + 24) + 'px';
    pop.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">' +
      '<strong style="font-size:12px">✦ Copy ideas — ' + ftype + '</strong>' +
      '<span id="st-ov-copyx" style="cursor:pointer;opacity:.6;padding:0 4px">✕</span></div>' +
      '<div id="st-ov-copylist"><div style="opacity:.6;padding:8px">Thinking…</div></div>';
    document.body.appendChild(pop);
    pop.querySelector('#st-ov-copyx').onclick = _closeCopyPop;
    fetchJson('/studio/ai/suggest-copy', { method: 'POST', body: JSON.stringify({ context: cur, field_type: ftype }) }).then(function (d) {
      var list = pop.querySelector('#st-ov-copylist'); if (!list) return;
      if (!d || !d.success || !(d.suggestions && d.suggestions.length)) {
        list.innerHTML = '<div style="opacity:.7;padding:8px">No suggestions (' + ((d && (d.message || d.error)) || 'failed') + ')</div>'; return;
      }
      list.innerHTML = '';
      d.suggestions.forEach(function (s) {
        var it = document.createElement('div');
        it.textContent = s;
        it.style.cssText = 'padding:8px;border-radius:6px;cursor:pointer;margin:2px 0;border:1px solid transparent';
        it.onmouseenter = function () { it.style.background = '#171b23'; it.style.borderColor = '#6C5CE7'; };
        it.onmouseleave = function () { it.style.background = ''; it.style.borderColor = 'transparent'; };
        it.onclick = function () {
          var prev = node.textContent;
          node.textContent = s;
          _histPush({ undo: function () { node.textContent = prev; _positionBoxes(); _commit(); },
                      redo: function () { node.textContent = s; _positionBoxes(); _commit(); } });
          _positionBoxes(); _commit();
          _closeCopyPop();
          toast('Copy updated', 'success');
        };
        list.appendChild(it);
      });
    }).catch(function (e) {
      var list = pop.querySelector('#st-ov-copylist'); if (list) list.innerHTML = '<div style="opacity:.7;padding:8px">Error: ' + ((e && e.message) || e) + '</div>';
    });
  }

  // ── P5e: per-element font family ────────────────────────────
  var _fontsCache = null;
  function _fontPopup(node) {
    var core = window.__studioCore;
    var fetchJson = (core && core.fetchJson) || window._fetchJson;
    if (!fetchJson) return;
    _closeCopyPop();
    var pop = document.createElement('div'); pop.id = 'st-ov-fontpop'; pop.className = 'st-ov-pop';
    pop.style.cssText = 'position:fixed;z-index:100001;background:#0f131a;border:1px solid #6C5CE7;border-radius:10px;padding:8px;min-width:220px;max-width:300px;max-height:60vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.6);font:400 13px/1.4 system-ui,-apple-system,sans-serif;color:#fff';
    var fr = S.iframe ? S.iframe.getBoundingClientRect() : { left: 60, top: 60 };
    pop.style.left = Math.max(12, Math.min(window.innerWidth - 320, fr.left + 24)) + 'px';
    pop.style.top = Math.max(12, fr.top + 24) + 'px';
    pop.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px"><strong style="font-size:12px">Font</strong><span id="st-ov-fx" style="cursor:pointer;opacity:.6;padding:0 4px">✕</span></div><div id="st-ov-fontlist"><div style="opacity:.6;padding:8px">Loading…</div></div>';
    document.body.appendChild(pop);
    pop.querySelector('#st-ov-fx').onclick = _closeCopyPop;
    function apply(fam) {
      var targets = S.sel.filter(function (n) { return _fieldKind(n) !== 'image'; });
      if (!targets.length) targets = [node];
      var befores = targets.map(function (n) { return { n: n, before: n.style.fontFamily }; });
      targets.forEach(function (n) { n.style.fontFamily = fam; });
      _histPush({ undo: function () { befores.forEach(function (b) { b.n.style.fontFamily = b.before; }); _positionBoxes(); _commit(); },
                  redo: function () { targets.forEach(function (n) { n.style.fontFamily = fam; }); _positionBoxes(); _commit(); } });
      _positionBoxes(); _commit(); _closeCopyPop();
    }
    function render(fonts) {
      var list = pop.querySelector('#st-ov-fontlist'); if (!list) return;
      if (!fonts || !fonts.length) { list.innerHTML = '<div style="opacity:.7;padding:8px">No fonts.</div>'; return; }
      list.innerHTML = '';
      fonts.forEach(function (f) {
        var fam = f.family || f;
        var it = document.createElement('div');
        it.textContent = fam;
        it.style.cssText = 'padding:7px 8px;border-radius:6px;cursor:pointer;margin:1px 0;border:1px solid transparent;font-family:' + JSON.stringify(fam);
        it.onmouseenter = function () { it.style.background = '#171b23'; it.style.borderColor = '#6C5CE7'; };
        it.onmouseleave = function () { it.style.background = ''; it.style.borderColor = 'transparent'; };
        it.onclick = function () { apply(fam); };
        list.appendChild(it);
      });
    }
    if (_fontsCache) { render(_fontsCache); }
    else { fetchJson('/studio/fonts').then(function (d) { _fontsCache = (d && d.fonts) || []; render(_fontsCache); }).catch(function () { render([]); }); }
  }

  // ── P5a: add a new element to the canvas (operates on content_html) ──
  function _canvasRoot() {
    var doc = S.iframe && S.iframe.contentDocument; if (!doc) return null;
    return doc.querySelector('.canvas') || doc.querySelector('.post') || doc.body;
  }
  function _uniqueField(prefix) {
    var doc = S.iframe.contentDocument, n = 1;
    while (doc.querySelector('[data-field="' + prefix + '_' + n + '"]')) n++;
    return prefix + '_' + n;
  }
  function _topZ() {
    var doc = S.iframe.contentDocument, z = 0;
    doc.querySelectorAll('[data-field]').forEach(function (el) {
      var v = parseInt(el.style.zIndex || doc.defaultView.getComputedStyle(el).zIndex, 10);
      if (!isNaN(v) && v > z) z = v;
    });
    return z;
  }
  function _notifyFields() { try { S.iframe.contentWindow.postMessage({ type: 'list-fields' }, '*'); } catch (_e) {} }
  function _addElement(kind, opts) {
    opts = opts || {};
    var doc = S.iframe && S.iframe.contentDocument; if (!doc) return null;
    var root = _canvasRoot(); if (!root) return null;
    try { if (doc.defaultView.getComputedStyle(root).position === 'static') root.style.position = 'relative'; } catch (_e) {}
    var cw = root.offsetWidth || 1080, ch = root.offsetHeight || 1080;
    var el, field, w, eh;
    if (kind === 'image') {
      field = _uniqueField('image'); w = opts.w || Math.round(cw * 0.4); eh = opts.h || Math.round(w * 0.66);
      el = doc.createElement('img');
      el.setAttribute('src', opts.url || '');
      try { el.crossOrigin = 'anonymous'; } catch (_e) {}
      el.style.cssText = 'position:absolute;left:0;top:0;width:' + w + 'px;height:' + eh + 'px;object-fit:cover;display:block;';
    } else if (kind === 'shape') {
      field = _uniqueField('shape'); w = opts.w || Math.round(cw * 0.25); eh = opts.h || Math.round(cw * 0.16);
      el = doc.createElement('div');
      el.style.cssText = 'position:absolute;left:0;top:0;width:' + w + 'px;height:' + eh + 'px;background:' + (opts.color || '#6C5CE7') + ';border-radius:' + (opts.radius != null ? opts.radius : 12) + 'px;';
    } else { // text or heading
      field = _uniqueField('text'); var heading = (kind === 'heading');
      var size = opts.size || (heading ? Math.round(cw * 0.06) : Math.round(cw * 0.028));
      w = opts.w || Math.round(cw * 0.6); eh = 120;
      el = doc.createElement('div');
      el.textContent = opts.text || (heading ? 'Add a heading' : 'Add a line of text');
      el.style.cssText = 'position:absolute;left:0;top:0;width:' + w + 'px;font-family:' + (opts.font || 'inherit') + ';font-size:' + size + 'px;font-weight:' + (heading ? '800' : '400') + ';color:' + (opts.color || '#111111') + ';line-height:1.15;white-space:pre-wrap;word-break:break-word;';
    }
    el.setAttribute('data-field', field);
    el.style.zIndex = String(_topZ() + 1);
    var tx = Math.max(0, Math.round((cw - w) / 2)), ty = Math.max(0, Math.round((ch - eh) / 2));
    _writeXform(el, tx, ty, 0);
    root.appendChild(el);
    _histPush({
      undo: function () { if (el.parentNode) el.parentNode.removeChild(el); _deselectAll(); _renderBoxes(); _notifyFields(); _commit(); },
      redo: function () { root.appendChild(el); _select(el, false); _notifyFields(); _commit(); }
    });
    _select(el, false);
    _notifyFields();
    _commit();
    return el;
  }

  // ── P5f: resize / reformat design (uniform scale, fit + center) ──
  function _resizeDesign(newW, newH) {
    var doc = S.iframe && S.iframe.contentDocument; if (!doc) return false;
    var root = _canvasRoot(); if (!root) return false;
    var oldW = root.offsetWidth || newW, oldH = root.offsetHeight || newH;
    if (oldW === newW && oldH === newH) return true;
    var s = Math.min(newW / oldW, newH / oldH);
    var wrap = doc.createElement('div');
    wrap.setAttribute('data-resize-wrap', '1');
    wrap.style.cssText = 'position:absolute;left:' + Math.round((newW - oldW * s) / 2) + 'px;top:' + Math.round((newH - oldH * s) / 2) +
      'px;width:' + oldW + 'px;height:' + oldH + 'px;transform:scale(' + s + ');transform-origin:top left';
    while (root.firstChild) { wrap.appendChild(root.firstChild); }
    root.appendChild(wrap);
    try { if (doc.defaultView.getComputedStyle(root).position === 'static') root.style.position = 'relative'; } catch (_e) {}
    root.style.width = newW + 'px'; root.style.height = newH + 'px'; root.style.overflow = 'hidden';
    _deselectAll();
    try { if (window._studioFit) window._studioFit(); } catch (_e) {}
    _commit();
    return true;
  }

  // ── P5b: layer order (z-index; ensure stacking so template elements obey) ──
  function _ensureStack(el) { try { var doc = S.iframe.contentDocument; if (doc.defaultView.getComputedStyle(el).position === 'static') el.style.position = 'relative'; } catch (_e) {} }
  function _zOf(el) { var v = parseInt(el.style.zIndex, 10); if (!isNaN(v)) return v; try { var c = parseInt(S.iframe.contentDocument.defaultView.getComputedStyle(el).zIndex, 10); return isNaN(c) ? 0 : c; } catch (_e) { return 0; } }
  function _order(el, dir) {
    if (!el) return;
    var doc = S.iframe && S.iframe.contentDocument; if (!doc) return;
    _ensureStack(el);
    var zs = Array.prototype.slice.call(doc.querySelectorAll('[data-field]')).map(_zOf);
    var top = Math.max.apply(null, zs.concat([0])), bot = Math.min.apply(null, zs.concat([0]));
    var prev = el.style.zIndex, nz;
    if (dir === 'front') nz = top + 1;
    else if (dir === 'back') nz = bot - 1;
    else if (dir === 'forward') nz = _zOf(el) + 1;
    else if (dir === 'backward') nz = _zOf(el) - 1;
    else return;
    el.style.zIndex = String(nz);
    _histPush({ undo: function () { el.style.zIndex = prev; _positionBoxes(); _commit(); },
                redo: function () { el.style.zIndex = String(nz); _positionBoxes(); _commit(); } });
    _positionBoxes(); _commit();
  }

  // ── P5d: align + distribute (multi-select) ──────────────────
  function _alignDistribute(mode) {
    if (S.sel.length < 2) return;
    if ((mode === 'dist-h' || mode === 'dist-v') && S.sel.length < 3) return;
    var commit = _beforeSnapshots();
    var items = S.sel.map(function (n) { return { n: n, r: _rectInFrame(n), x: _parseXform(n) }; });
    var minL = Math.min.apply(null, items.map(function (i) { return i.r.x; }));
    var maxR = Math.max.apply(null, items.map(function (i) { return i.r.x + i.r.w; }));
    var minT = Math.min.apply(null, items.map(function (i) { return i.r.y; }));
    var maxB = Math.max.apply(null, items.map(function (i) { return i.r.y + i.r.h; }));
    var cx = (minL + maxR) / 2, cy = (minT + maxB) / 2;
    if (mode === 'dist-h' || mode === 'dist-v') {
      var horiz = (mode === 'dist-h');
      var key = horiz ? function (it) { return it.r.x + it.r.w / 2; } : function (it) { return it.r.y + it.r.h / 2; };
      var sorted = items.slice().sort(function (a, b) { return key(a) - key(b); });
      var first = key(sorted[0]), last = key(sorted[sorted.length - 1]), n = sorted.length;
      for (var i = 1; i < n - 1; i++) {
        var target = first + (last - first) * i / (n - 1);
        var d = target - key(sorted[i]), it = sorted[i];
        if (horiz) _writeXform(it.n, it.x.x + d, it.x.y, it.x.deg);
        else _writeXform(it.n, it.x.x, it.x.y + d, it.x.deg);
      }
    } else {
      items.forEach(function (it) {
        var dx = 0, dy = 0;
        if (mode === 'left') dx = minL - it.r.x;
        else if (mode === 'right') dx = maxR - (it.r.x + it.r.w);
        else if (mode === 'center-h') dx = cx - (it.r.x + it.r.w / 2);
        else if (mode === 'top') dy = minT - it.r.y;
        else if (mode === 'bottom') dy = maxB - (it.r.y + it.r.h);
        else if (mode === 'middle') dy = cy - (it.r.y + it.r.h / 2);
        if (dx || dy) _writeXform(it.n, it.x.x + dx, it.x.y + dy, it.x.deg);
      });
    }
    _positionBoxes();
    commit();
  }
  function _alignBar() {
    var bar = document.getElementById('st-ov-alignbar');
    if (!S.on || S.sel.length < 2) { if (bar) bar.style.display = 'none'; return; }
    if (!bar) {
      bar = document.createElement('div'); bar.id = 'st-ov-alignbar';
      bar.style.cssText = 'position:fixed;z-index:100000;top:64px;left:50%;transform:translateX(-50%);display:flex;gap:3px;background:#0f131a;border:1px solid #6C5CE7;border-radius:8px;padding:5px;box-shadow:0 6px 24px rgba(0,0,0,.5)';
      var mk = function (m, label, title) { return '<button data-am="' + m + '" title="' + title + '" style="background:#171b23;border:1px solid #2a2f3a;color:#fff;border-radius:4px;height:26px;min-width:30px;cursor:pointer;font-size:13px">' + label + '</button>'; };
      var sep = '<span style="width:1px;background:#2a2f3a;margin:2px 3px"></span>';
      bar.innerHTML = mk('left', '⇤', 'Align left') + mk('center-h', '⇔', 'Align center') + mk('right', '⇥', 'Align right') + sep +
        mk('top', '⤒', 'Align top') + mk('middle', '⇕', 'Align middle') + mk('bottom', '⤓', 'Align bottom') + sep +
        mk('dist-h', '⇹', 'Distribute horizontally') + mk('dist-v', '⤨', 'Distribute vertically');
      bar.addEventListener('pointerdown', function (e) { var m = e.target && e.target.getAttribute && e.target.getAttribute('data-am'); if (m) { e.preventDefault(); e.stopPropagation(); _alignDistribute(m); } });
      document.body.appendChild(bar);
    }
    bar.querySelectorAll('[data-am="dist-h"],[data-am="dist-v"]').forEach(function (b) { b.style.opacity = S.sel.length >= 3 ? '1' : '.35'; });
    bar.style.display = 'flex';
  }

  // ── P5c: lock + visibility ──────────────────────────────────
  function _setLock(el, lock) {
    if (!el) return;
    var prev = el.getAttribute('data-locked');
    if (lock) { el.setAttribute('data-locked', '1'); if (S.sel.indexOf(el) >= 0) _deselectAll(); }
    else { el.removeAttribute('data-locked'); }
    _histPush({ undo: function () { if (prev) el.setAttribute('data-locked', prev); else el.removeAttribute('data-locked'); _commit(); },
                redo: function () { if (lock) el.setAttribute('data-locked', '1'); else el.removeAttribute('data-locked'); _commit(); } });
    _commit();
  }
  function _setHidden(el, hide) {
    if (!el) return;
    var prev = el.style.display, after = hide ? 'none' : '';
    el.style.display = after;
    if (hide && S.sel.indexOf(el) >= 0) _deselectAll();
    _histPush({ undo: function () { el.style.display = prev; _positionBoxes(); _commit(); },
                redo: function () { el.style.display = after; _positionBoxes(); _commit(); } });
    _positionBoxes(); _commit();
  }

  // ── click selection + marquee + nav suppression on the iframe doc ──
  function _bindDoc() {
    try {
      var doc = S.iframe && S.iframe.contentDocument; if (!doc) return;
      doc.removeEventListener('click', S.docClick, true);
      doc.addEventListener('click', S.docClick, true);
      // hover
      doc.removeEventListener('mousemove', S.docMove, true);
      doc.addEventListener('mousemove', S.docMove, true);
      // double-click an image field → open Media Library (single-click only selects)
      doc.removeEventListener('dblclick', S.docDbl, true);
      doc.addEventListener('dblclick', S.docDbl, true);
      // navigation suppression (capture): anchors, forms, window.open, target=_blank
      doc.removeEventListener('click', S.navSuppress, true);
      doc.addEventListener('click', S.navSuppress, true);
      doc.removeEventListener('submit', S.formSuppress, true);
      doc.addEventListener('submit', S.formSuppress, true);
      try { doc.defaultView.open = function () { return null; }; } catch (_e) {}
    } catch (_e) {}
  }

  function enable() {
    if (S.on) return true;
    var iframe = document.getElementById('st-iframe');
    if (!iframe || !iframe.contentDocument) return false;
    S.iframe = iframe;
    if (!_ensureLayer()) return false;

    S.docClick = function (ev) {
      var node = ev.target.closest && ev.target.closest('[data-field]');
      // image select must NOT trigger the iframe media-picker — suppress the
      // iframe's own image-click handler by stopping propagation here (capture).
      if (node && _fieldKind(node) === 'image') { ev.stopPropagation(); ev.preventDefault(); }
      if (node) _select(node, ev.shiftKey); else if (!ev.shiftKey) _deselectAll();
    };
    S.docMove = function (ev) {
      var node = ev.target.closest && ev.target.closest('[data-field]');
      _setHover(node);
    };
    S.docDbl = function (ev) {
      var node = ev.target.closest && ev.target.closest('[data-field]');
      if (node && _fieldKind(node) === 'image') { ev.stopPropagation(); ev.preventDefault(); _replaceImage(node); }
    };
    S.navSuppress = function (ev) {
      var a = ev.target.closest && ev.target.closest('a[href]');
      if (a) { ev.preventDefault(); ev.stopPropagation(); }
    };
    S.formSuppress = function (ev) { ev.preventDefault(); ev.stopPropagation(); };

    S._onIfrLoad = function () { _deselectAll(); _bindDoc(); };
    iframe.addEventListener('load', S._onIfrLoad);
    _bindDoc();
    [200, 600, 1200, 2000].forEach(function (t) { setTimeout(function () { if (S.on) _bindDoc(); }, t); });

    S._reposition = function () { _positionBoxes(); };
    window.addEventListener('resize', S._reposition);
    // global keyboard (parent document)
    S._onKey = _onKey;
    window.addEventListener('keydown', S._onKey, true);

    S.on = true;
    return true;
  }

  function _onKey(e) {
    if (!S.on) return;
    // don't hijack typing inside inputs / contenteditable
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
    var mod = e.ctrlKey || e.metaKey;
    if (mod && (e.key === 'z' || e.key === 'Z')) { e.preventDefault(); if (e.shiftKey) _redo(); else _undo(); return; }
    if (mod && (e.key === 'y' || e.key === 'Y')) { e.preventDefault(); _redo(); return; }
    if (e.key === 'Escape') { _deselectAll(); return; }
    if (!S.sel.length) return;
    // layer order: Ctrl/Cmd+] = to front, Ctrl/Cmd+[ = to back
    if (mod && e.key === ']') { e.preventDefault(); _order(S.sel[S.sel.length - 1], 'front'); return; }
    if (mod && e.key === '[') { e.preventDefault(); _order(S.sel[S.sel.length - 1], 'back'); return; }
    var step = e.shiftKey ? 10 : 1, dx = 0, dy = 0;
    if (e.key === 'ArrowLeft') dx = -step; else if (e.key === 'ArrowRight') dx = step;
    else if (e.key === 'ArrowUp') dy = -step; else if (e.key === 'ArrowDown') dy = step;
    if (dx || dy) {
      e.preventDefault();
      var recs = S.sel.map(function (n) { return { n: n, before: _snapStyle(n) }; });
      S.sel.forEach(function (n) { var x = _parseXform(n); _writeXform(n, x.x + dx, x.y + dy, x.deg); });
      _positionBoxes();
      var afters = recs.map(function (r) { return { n: r.n, before: r.before, after: _snapStyle(r.n) }; });
      _histPush({ undo: function () { afters.forEach(function (a) { a.n.setAttribute('style', a.before); }); _positionBoxes(); _commit(); },
                  redo: function () { afters.forEach(function (a) { a.n.setAttribute('style', a.after); }); _positionBoxes(); _commit(); } });
      _commit();
    }
  }

  function disable() {
    if (!S.on) return;
    try { var _ab = document.getElementById('st-ov-alignbar'); if (_ab) _ab.style.display = 'none'; } catch (_e) {}
    try { if (typeof _closeCopyPop === 'function') _closeCopyPop(); } catch (_e) {}
    try { var doc = S.iframe && S.iframe.contentDocument; if (doc) { doc.removeEventListener('click', S.docClick, true); doc.removeEventListener('mousemove', S.docMove, true); doc.removeEventListener('dblclick', S.docDbl, true); doc.removeEventListener('click', S.navSuppress, true); doc.removeEventListener('submit', S.formSuppress, true); } } catch (_e) {}
    try { if (S.iframe && S._onIfrLoad) S.iframe.removeEventListener('load', S._onIfrLoad); } catch (_e) {}
    if (S._reposition) window.removeEventListener('resize', S._reposition);
    if (S._onKey) window.removeEventListener('keydown', S._onKey, true);
    _deselectAll(); _clearGuides();
    var layer = document.getElementById('st-overlay-layer'); if (layer && layer.parentNode) layer.parentNode.removeChild(layer);
    S.on = false; S.iframe = null; S.layer = null; S.boxes = []; S.sel = []; S.hist = { stack: [], index: -1 };
  }

  // Live enablement check (re-evaluated continuously, NOT once at load — the
  // read-once-at-load gate was the P1: setting the flag without a hard refresh,
  // or a slow/deep-link mount, left the overlay dormant even with the editor up).
  // Opt-in is EITHER the localStorage flag OR a `?overlay=1` URL param (which we
  // persist so it survives in-app SPA navigation). Not global — dormant unless
  // one of these is present.
  function _shouldEnable() {
    try {
      // Kill switches (instant OFF): localStorage.st_overlay_off='1' or ?overlay=0
      if (localStorage.getItem('st_overlay_off') === '1') return false;
      var href = location.href || '';
      if (/[?&#]overlay=0(\b|&|$)/.test(href)) { try { localStorage.setItem('st_overlay_off', '1'); } catch (_e) {} return false; }
      // Acceptance-candidate DEFAULT: on wherever the Studio iframe editor is
      // present (Studio runs only in Advanced mode). Fully reversible — the two
      // kill switches above, or rolling back this asset version. Not shown to
      // Basic-mode customers (Studio requires Advanced).
      return true;
    } catch (_e) { return true; }
  }

  window.studioOverlayEditor = { enable: enable, disable: disable, _state: S,
    addElement: _addElement, order: _order, setLock: _setLock, setHidden: _setHidden, alignDistribute: _alignDistribute, resizeDesign: _resizeDesign,
    _api: { select: _select, undo: _undo, redo: _redo, deselect: _deselectAll, addElement: _addElement, order: _order, setLock: _setLock, setHidden: _setHidden, alignDistribute: _alignDistribute, resizeDesign: _resizeDesign },
    // explicit review helper: turns it on for THIS browser + activates immediately
    enableForReview: function () { try { localStorage.setItem('st_overlay_spike', '1'); } catch (_e) {} var ifr = document.getElementById('st-iframe'); if (ifr) { disable(); return enable(); } return false; } };
  window.studioOverlaySpike = window.studioOverlayEditor; // back-compat

  try {
    var _last = null;
    function _sync() {
      var ifr = document.getElementById('st-iframe');
      if (ifr && ifr.contentDocument && _shouldEnable()) {
        if (ifr !== _last || !S.on) { _last = ifr; disable(); setTimeout(enable, 250); }
      } else if (S.on && (!ifr || !_shouldEnable())) { disable(); _last = null; }
    }
    var mo = new MutationObserver(_sync);
    mo.observe(document.documentElement, { childList: true, subtree: true });
    // backstop: catches flag set after load (no refresh needed), slow deep-link
    // mounts, and route navigations between designs. ~1s, trivial cost, self-heals.
    setInterval(_sync, 1000);
    setTimeout(_sync, 800);
  } catch (_e) {}
})();
