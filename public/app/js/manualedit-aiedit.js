/**
 * STUDIO888 Phase O — AI masked-edit overlay for the live Fabric editor.
 *
 * Invoked from manualedit's AI panel: meOpenAiEdit(asset). Presents a focused
 * three-layer canvas (base image / mask / interaction overlay) where the user
 * draws a rectangle or brush mask directly on the image, describes the change,
 * and generates a NON-DESTRUCTIVE edited child version via POST /creative/edit.
 *
 * Geometry + mask semantics are the SAME logic proven by the Phase O unit tests
 * (coords: 23/23, mask export: 8/8). Ported to vanilla; no framework.
 * Mask contract: the region the user paints (edit) is exported TRANSPARENT;
 * everything else OPAQUE — exactly what OpenAI /v1/images/edits expects.
 */
(function () {
  'use strict';
  var CREATIVE = window.location.origin + '/api/creative';
  var token = function () { return localStorage.getItem('lu_token') || ''; };
  var headers = function () { return { 'Authorization': 'Bearer ' + token(), 'Content-Type': 'application/json', 'Accept': 'application/json' }; };
  var EDIT_COST = 2, MAX_SCALE = 8, MIN_FACTOR = 0.1, UNDO_LIMIT = 25;

  // ── coordinate transforms (proven) ──
  function fitScale(sw, sh, vw, vh) { return (sw <= 0 || sh <= 0) ? 1 : Math.min(vw / sw, vh / sh); }
  function fitView(sw, sh, vw, vh) { var s = fitScale(sw, sh, vw, vh); return { scale: s, panX: (vw - sw * s) / 2, panY: (vh - sh * s) / 2 }; }
  function screenToSource(x, y, v) { return { sx: (x - v.panX) / v.scale, sy: (y - v.panY) / v.scale }; }
  function sourceToScreen(sx, sy, v) { return { x: sx * v.scale + v.panX, y: sy * v.scale + v.panY }; }
  function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }
  function zoomAt(v, f, x, y, fit) {
    var ns = clamp(v.scale * f, fit * MIN_FACTOR, MAX_SCALE);
    if (ns === v.scale) return v;
    var s = screenToSource(x, y, v);
    return { scale: ns, panX: x - s.sx * ns, panY: y - s.sy * ns };
  }

  var S = null; // active session state

  // opts (optional): { onApply(version), applyLabel } — Phase P: when opened from
  // a Studio design on a selected image, onApply lets the user explicitly apply
  // the chosen version back into Studio (undoable there).
  window.meOpenAiEdit = function (asset, opts) {
    if (!asset || !asset.url) { showToast('Select a completed image to edit.', 'warning'); return; }
    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = function () { start(asset, img, opts || {}); };
    img.onerror = function () { showToast('Could not load this image for editing.', 'error'); };
    img.src = asset.url;
  };

  function start(asset, img, opts) {
    var sw = img.naturalWidth, sh = img.naturalHeight;
    var host = document.createElement('div');
    host.id = 'ai-edit-overlay';
    host.setAttribute('role', 'dialog');
    host.setAttribute('aria-label', 'AI image editor');
    host.style.cssText = 'position:fixed;inset:0;z-index:9000;background:#0b0f1a;color:#e6ebf5;display:flex;flex-direction:column;font:14px system-ui,sans-serif';
    host.innerHTML =
      '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid #1c2740;flex-wrap:wrap">' +
        '<button id="ai-close" style="background:#182338;color:#e6ebf5;border:1px solid #29354f;border-radius:8px;padding:6px 12px;cursor:pointer">← Back</button>' +
        '<div id="ai-tools" role="toolbar" aria-label="Edit tools" style="display:flex;gap:4px;flex-wrap:wrap"></div>' +
        '<span id="ai-status" style="margin-left:auto;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#7dd3fc;border:1px solid #263349;border-radius:20px;padding:4px 12px">ready</span>' +
      '</div>' +
      '<div style="flex:1;display:flex;min-height:0">' +
        '<aside id="ai-rail" aria-label="Versions" style="width:172px;border-right:1px solid #1c2740;overflow:auto;padding:8px;flex-shrink:0"></aside>' +
        '<main id="ai-stage" style="flex:1;position:relative;overflow:hidden;background:repeating-conic-gradient(#0d1424 0 25%,#0a0f1c 0 50%) 50%/24px 24px">' +
          '<canvas id="ai-base" style="position:absolute;inset:0;width:100%;height:100%"></canvas>' +
          '<canvas id="ai-mask" style="position:absolute;inset:0;width:100%;height:100%"></canvas>' +
          '<canvas id="ai-ov" style="position:absolute;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair"></canvas>' +
        '</main>' +
        '<aside style="width:300px;border-left:1px solid #1c2740;padding:14px;display:flex;flex-direction:column;gap:10px;flex-shrink:0;overflow:auto">' +
          '<div id="ai-scope" aria-live="polite" style="font-size:13px;background:#101a2e;border:1px solid #22314c;border-radius:8px;padding:8px 10px">Select a region or paint a mask to edit</div>' +
          '<label for="ai-prompt" style="font-size:12px;color:#9fb0cc">Describe your edit</label>' +
          '<textarea id="ai-prompt" style="min-height:92px;resize:vertical;background:#0e1626;color:#e6ebf5;border:1px solid #263349;border-radius:8px;padding:10px;font:inherit" placeholder="e.g. Replace the selected object with a yellow rubber duck"></textarea>' +
          '<p id="ai-disclose" style="font-size:11.5px;line-height:1.5;color:#8ea3c6;background:#101a2e;border-radius:8px;padding:8px 10px;margin:0">Edits are concentrated in the selected area, but the AI may make subtle changes elsewhere. Your original is always preserved.</p>' +
          '<div id="ai-error" role="alert" style="display:none;font-size:13px;color:#ffb4b4;background:#2a1418;border:1px solid #52222a;border-radius:8px;padding:8px 10px"></div>' +
          '<div style="display:flex;justify-content:space-between;font-size:13px;color:#9fb0cc;margin-top:auto"><span>Cost</span><strong>' + EDIT_COST + ' credits</strong></div>' +
          '<button id="ai-generate" style="background:linear-gradient(90deg,#6d3bff,#2d7bff);color:#fff;border:none;border-radius:10px;padding:12px;font-size:15px;font-weight:700;cursor:pointer">Generate edit</button>' +
          // Phase P — explicit "apply into Studio" action (shown only when opened from a Studio design).
          ((opts && typeof opts.onApply === 'function')
            ? '<button id="ai-apply" style="display:none;background:#00b37e;color:#04150f;border:none;border-radius:10px;padding:11px;font-size:14px;font-weight:700;cursor:pointer">' + ((opts.applyLabel || 'Use in Studio').replace(/</g, '&lt;')) + '</button>'
            : '') +
          '<button id="ai-compare" style="display:none;background:#131c30;color:#cdd6ea;border:1px solid #263349;border-radius:8px;padding:8px;cursor:pointer;font-size:13px">Compare with original</button>' +
        '</aside>' +
      '</div>';
    document.body.appendChild(host);

    var base = host.querySelector('#ai-base'), mask = host.querySelector('#ai-mask'), ov = host.querySelector('#ai-ov');
    var maskBuf = document.createElement('canvas'); maskBuf.width = sw; maskBuf.height = sh;
    var stage = host.querySelector('#ai-stage');
    S = {
      host: host, asset: asset, sourceId: asset.id, rootId: asset.root_asset_id || asset.id,
      img: img, sw: sw, sh: sh, base: base, mask: mask, ov: ov, maskBuf: maskBuf,
      vpW: stage.clientWidth, vpH: stage.clientHeight, view: fitView(sw, sh, stage.clientWidth, stage.clientHeight),
      tool: 'rectangle', brush: 48, rect: null, drag: null, undo: [], redo: [], busy: false, versions: [], currentId: asset.id,
      opts: opts || {}, currentUrl: asset.url,
    };

    buildTools(host);
    host.querySelector('#ai-close').onclick = close;
    host.querySelector('#ai-generate').onclick = submit;
    var applyBtn = host.querySelector('#ai-apply');
    if (applyBtn) applyBtn.onclick = function () {
      var v = { id: S.currentId, url: S.currentUrl, width: S.sw, height: S.sh };
      try { S.opts.onApply(v); } catch (e) {}
      close();
    };
    host.querySelector('#ai-compare').onclick = toggleCompare;
    bindPointer();
    bindKeys();
    window.addEventListener('resize', onResize);
    redraw();
    loadVersions();
  }

  function buildTools(host) {
    var defs = [['rectangle', '▭', 'Rectangle'], ['brush', '🖌', 'Brush'], ['eraser', '⌫', 'Eraser'], ['pan', '✋', 'Pan'], ['full', '🖼', 'Full image']];
    var wrap = host.querySelector('#ai-tools'); var h = '';
    defs.forEach(function (d) { h += '<button data-tool="' + d[0] + '" title="' + d[2] + '" aria-label="' + d[2] + '" style="min-width:34px;height:34px;border-radius:8px;cursor:pointer;border:1px solid #263349;background:#131c30;color:#cdd6ea">' + d[1] + '</button>'; });
    h += '<span style="width:1px;height:22px;background:#263349;margin:0 4px"></span>';
    h += btn('ai-zin', '＋', 'Zoom in') + btn('ai-zout', '－', 'Zoom out') + btn('ai-fit', 'Fit', 'Fit') + btn('ai-100', '100%', 'Actual size');
    h += '<span style="width:1px;height:22px;background:#263349;margin:0 4px"></span>';
    h += btn('ai-undo', '↶', 'Undo') + btn('ai-redo', '↷', 'Redo') + btn('ai-clear', 'Clear', 'Clear mask');
    wrap.innerHTML = h;
    wrap.querySelectorAll('[data-tool]').forEach(function (b) { b.onclick = function () { setTool(b.getAttribute('data-tool')); }; });
    host.querySelector('#ai-zin').onclick = function () { zoom(1.1); };
    host.querySelector('#ai-zout').onclick = function () { zoom(1 / 1.1); };
    host.querySelector('#ai-fit').onclick = function () { S.view = fitView(S.sw, S.sh, S.vpW, S.vpH); redraw(); };
    host.querySelector('#ai-100').onclick = function () { var c = screenToSource(S.vpW / 2, S.vpH / 2, S.view); S.view = { scale: 1, panX: S.vpW / 2 - c.sx, panY: S.vpH / 2 - c.sy }; redraw(); };
    host.querySelector('#ai-undo').onclick = undo;
    host.querySelector('#ai-redo').onclick = redo;
    host.querySelector('#ai-clear').onclick = clearMask;
    setTool('rectangle');
  }
  function btn(id, label, title) { return '<button id="' + id + '" title="' + title + '" aria-label="' + title + '" style="min-width:34px;height:34px;border-radius:8px;cursor:pointer;border:1px solid #263349;background:#131c30;color:#cdd6ea">' + label + '</button>'; }

  function setTool(t) {
    S.tool = t;
    S.host.querySelectorAll('[data-tool]').forEach(function (b) {
      var on = b.getAttribute('data-tool') === t;
      b.style.background = on ? '#2d5cff' : '#131c30'; b.style.borderColor = on ? '#2d5cff' : '#263349'; b.style.color = on ? '#fff' : '#cdd6ea';
    });
    S.ov.style.cursor = t === 'pan' ? 'grab' : 'crosshair';
    updateScope();
  }

  function sizeCtx(cvs) { var r = Math.max(1, Math.min(3, window.devicePixelRatio || 1)); cvs.width = Math.round(S.vpW * r); cvs.height = Math.round(S.vpH * r); cvs.style.width = S.vpW + 'px'; cvs.style.height = S.vpH + 'px'; var c = cvs.getContext('2d'); c.setTransform(r, 0, 0, r, 0, 0); return c; }

  function redraw() {
    var v = S.view;
    var b = sizeCtx(S.base); b.clearRect(0, 0, S.vpW, S.vpH); b.imageSmoothingQuality = 'high';
    b.drawImage(S.img, v.panX, v.panY, S.sw * v.scale, S.sh * v.scale);
    var m = sizeCtx(S.mask); m.clearRect(0, 0, S.vpW, S.vpH); m.globalAlpha = 0.45;
    m.drawImage(S.maskBuf, v.panX, v.panY, S.sw * v.scale, S.sh * v.scale); m.globalAlpha = 1;
    var o = sizeCtx(S.ov); o.clearRect(0, 0, S.vpW, S.vpH);
    if (S.rect) { var tl = sourceToScreen(S.rect.x * S.sw, S.rect.y * S.sh, v); o.strokeStyle = '#2d9cff'; o.lineWidth = 2; o.setLineDash([6, 4]); o.strokeRect(tl.x, tl.y, S.rect.w * S.sw * v.scale, S.rect.h * S.sh * v.scale); o.setLineDash([]); }
  }

  function snapshot() { var c = S.maskBuf.getContext('2d'); S.undo.push(c.getImageData(0, 0, S.sw, S.sh)); if (S.undo.length > UNDO_LIMIT) S.undo.shift(); S.redo = []; }
  function stroke(from, to, erase) { var c = S.maskBuf.getContext('2d'); c.globalCompositeOperation = erase ? 'destination-out' : 'source-over'; c.strokeStyle = 'rgba(45,156,255,1)'; c.fillStyle = c.strokeStyle; c.lineWidth = S.brush; c.lineCap = 'round'; c.beginPath(); c.moveTo(from.sx, from.sy); c.lineTo(to.sx, to.sy); c.stroke(); c.beginPath(); c.arc(to.sx, to.sy, S.brush / 2, 0, 7); c.fill(); c.globalCompositeOperation = 'source-over'; }
  function fillRect(r) { var c = S.maskBuf.getContext('2d'); c.fillStyle = 'rgba(45,156,255,1)'; c.fillRect(r.x * S.sw, r.y * S.sh, r.w * S.sw, r.h * S.sh); }
  function hasMask() { var d = S.maskBuf.getContext('2d').getImageData(0, 0, S.sw, S.sh).data; for (var i = 3; i < d.length; i += 4) if (d[i] !== 0) return true; return false; }
  function clearMask() { snapshot(); S.maskBuf.getContext('2d').clearRect(0, 0, S.sw, S.sh); S.rect = null; redraw(); updateScope(); }
  function restore(from, to) { if (!from.length) return; var c = S.maskBuf.getContext('2d'); to.push(c.getImageData(0, 0, S.sw, S.sh)); c.putImageData(from.pop(), 0, 0); redraw(); updateScope(); }
  function undo() { restore(S.undo, S.redo); }
  function redo() { restore(S.redo, S.undo); }

  // export provider mask: painted(edit) -> transparent, else opaque black (proven contract)
  function exportMask() {
    var src = S.maskBuf.getContext('2d').getImageData(0, 0, S.sw, S.sh);
    var out = document.createElement('canvas'); out.width = S.sw; out.height = S.sh;
    var od = out.getContext('2d').createImageData(S.sw, S.sh);
    for (var i = 0; i < src.data.length; i += 4) { var edit = src.data[i + 3] > 0; od.data[i] = 0; od.data[i + 1] = 0; od.data[i + 2] = 0; od.data[i + 3] = edit ? 0 : 255; }
    out.getContext('2d').putImageData(od, 0, 0);
    return out.toDataURL('image/png');
  }

  function scope() { return S.tool === 'full' ? 'full' : (hasMask() ? 'mask' : 'none'); }
  function updateScope() {
    var sc = scope(); var el = S.host.querySelector('#ai-scope');
    el.textContent = sc === 'full' ? 'Editing full image' : (sc === 'mask' ? 'Editing selected region' : 'Select a region or paint a mask to edit');
    S.host.querySelector('#ai-disclose').style.display = sc === 'full' ? 'none' : 'block';
    S.host.querySelector('#ai-status').textContent = S.busy ? 'generating' : (sc !== 'none' ? 'selected' : 'ready');
  }

  function pt(e) { var r = S.ov.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
  function bindPointer() {
    S.ov.addEventListener('pointerdown', function (e) {
      S.ov.setPointerCapture(e.pointerId); var p = pt(e);
      if (S.tool === 'pan') { S.drag = { m: 'pan', last: p, v0: S.view }; return; }
      if (S.tool === 'rectangle') { S.drag = { m: 'rect', p0: p }; return; }
      if (S.tool === 'brush' || S.tool === 'eraser') { snapshot(); var s = screenToSource(p.x, p.y, S.view); stroke(s, s, S.tool === 'eraser'); S.drag = { m: 'paint', last: s, erase: S.tool === 'eraser' }; redraw(); }
    });
    S.ov.addEventListener('pointermove', function (e) {
      var d = S.drag; if (!d) return; var p = pt(e);
      if (d.m === 'pan') { S.view = { scale: S.view.scale, panX: d.v0.panX + (p.x - d.last.x), panY: d.v0.panY + (p.y - d.last.y) }; redraw(); }
      else if (d.m === 'rect') { var a = screenToSource(d.p0.x, d.p0.y, S.view), b = screenToSource(p.x, p.y, S.view); var x0 = clamp(Math.min(a.sx, b.sx) / S.sw, 0, 1), y0 = clamp(Math.min(a.sy, b.sy) / S.sh, 0, 1), x1 = clamp(Math.max(a.sx, b.sx) / S.sw, 0, 1), y1 = clamp(Math.max(a.sy, b.sy) / S.sh, 0, 1); S.rect = (x1 > x0 && y1 > y0) ? { x: x0, y: y0, w: x1 - x0, h: y1 - y0 } : null; redraw(); }
      else if (d.m === 'paint') { var s = screenToSource(p.x, p.y, S.view); stroke(d.last, s, d.erase); d.last = s; redraw(); }
    });
    S.ov.addEventListener('pointerup', function () {
      var d = S.drag; S.drag = null; if (!d) return;
      if (d.m === 'rect' && S.rect) { snapshot(); fillRect(S.rect); redraw(); }
      if (d.m === 'paint' || d.m === 'rect') updateScope();
    });
    S.ov.addEventListener('wheel', function (e) { e.preventDefault(); var p = pt(e); zoom(e.deltaY < 0 ? 1.1 : 1 / 1.1, p.x, p.y); }, { passive: false });
  }
  function zoom(f, x, y) { var fit = fitScale(S.sw, S.sh, S.vpW, S.vpH); S.view = zoomAt(S.view, f, x == null ? S.vpW / 2 : x, y == null ? S.vpH / 2 : y, fit); redraw(); }

  function bindKeys() {
    S._key = function (e) {
      if (!document.getElementById('ai-edit-overlay')) return;
      var meta = e.metaKey || e.ctrlKey;
      if (meta && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
      else if (meta && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) { e.preventDefault(); redo(); }
      else if (e.key === 'Escape') { if (e.target.tagName === 'TEXTAREA') return; clearMask(); }
      else if (e.target.tagName !== 'TEXTAREA') { var k = e.key.toLowerCase(); var map = { r: 'rectangle', b: 'brush', e: 'eraser', h: 'pan', f: 'full' }; if (map[k]) setTool(map[k]); }
    };
    window.addEventListener('keydown', S._key);
  }
  function onResize() { if (!S) return; var st = S.host.querySelector('#ai-stage'); S.vpW = st.clientWidth; S.vpH = st.clientHeight; S.view = fitView(S.sw, S.sh, S.vpW, S.vpH); redraw(); }

  function loadVersions() {
    fetch(CREATIVE + '/assets/' + S.asset.id + '/versions', { headers: headers(), cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(function (d) { S.versions = (d && d.versions) || []; renderRail(); }).catch(function () {});
  }
  function renderRail() {
    var el = S.host.querySelector('#ai-rail'); if (!el) return;
    var vs = S.versions.slice().sort(function (a, b) { return a.version - b.version; });
    el.innerHTML = '<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7a99;margin:4px 6px 10px">Versions</div>' +
      vs.map(function (v) {
        var active = v.id === S.currentId;
        return '<button data-vid="' + v.id + '" style="display:flex;gap:8px;width:100%;background:' + (active ? '#132038' : 'transparent') + ';border:1px solid ' + (active ? '#2d5cff' : 'transparent') + ';border-radius:10px;padding:6px;cursor:pointer;text-align:left;color:#cdd6ea;margin-bottom:6px">' +
          '<img src="' + (v.thumbnail_url || v.url) + '" alt="" style="width:42px;height:42px;object-fit:cover;border-radius:6px;flex-shrink:0">' +
          '<div style="display:flex;flex-direction:column;font-size:12px;min-width:0"><strong>' + (v.version === 1 ? 'Original' : 'Edit v' + v.version) + '</strong><span style="color:#6b7a99;font-size:11px">' + (v.status || '') + '</span></div></button>';
      }).join('');
    el.querySelectorAll('[data-vid]').forEach(function (b) { b.onclick = function () { switchVersion(+b.getAttribute('data-vid')); }; });
  }
  function switchVersion(id) {
    if (S.busy) return; var v = S.versions.find(function (x) { return x.id === id; }); if (!v) return;
    var img = new Image(); img.crossOrigin = 'anonymous';
    img.onload = function () {
      S.img = img; S.sw = img.naturalWidth; S.sh = img.naturalHeight; S.currentId = id; S.currentUrl = v.url;
      S.maskBuf = document.createElement('canvas'); S.maskBuf.width = S.sw; S.maskBuf.height = S.sh; S.rect = null; S.undo = []; S.redo = [];
      S.view = fitView(S.sw, S.sh, S.vpW, S.vpH); redraw(); renderRail(); updateScope();
      // Phase P — once an EDITED version (not the original) is current, allow applying it into Studio.
      var ab = S.host.querySelector('#ai-apply');
      if (ab && S.opts && typeof S.opts.onApply === 'function') ab.style.display = (id !== S.rootId) ? 'block' : 'none';
    };
    img.src = v.url;
  }

  function submit() {
    if (S.busy) return;
    var sc = scope(); var prompt = S.host.querySelector('#ai-prompt').value.trim();
    if (!prompt) { showError('Describe the change you want to make.'); return; }
    if (sc === 'none') { showError('Select a region or paint a mask first.'); return; }
    S.busy = true; showError(''); setBusy(true);
    var payload = { source_asset_id: S.currentId, prompt: prompt, idempotency_key: 'aiedit-' + S.currentId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8) };
    if (sc === 'full') payload.selection_type = 'full';
    else { payload.selection_type = 'mask'; payload.mask_base64 = exportMask(); payload.source_width = S.sw; payload.source_height = S.sh; }
    fetch(CREATIVE + '/edit', { method: 'POST', headers: headers(), body: JSON.stringify(payload), cache: 'no-store' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, j: j }; }); })
      .then(function (res) {
        S.busy = false; setBusy(false);
        var d = (res.j && res.j.data) || res.j || {};
        if (res.ok && res.j && res.j.success) {
          if (S.maskBuf) S.maskBuf.getContext('2d').clearRect(0, 0, S.sw, S.sh);
          S.rect = null;
          loadVersionsThen(function () { switchVersion(d.id); S.host.querySelector('#ai-compare').style.display = 'block'; });
        } else if (res.status === 409) { showError('An identical edit is already being processed.'); }
        else { showError((res.j && res.j.error) || d.error || 'The edit could not be completed. Please try again.'); }
        updateScope();
      })
      .catch(function () { S.busy = false; setBusy(false); showError('Network error — your prompt and mask are kept. Please retry.'); });
  }
  function loadVersionsThen(cb) { fetch(CREATIVE + '/assets/' + S.asset.id + '/versions', { headers: headers(), cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) { S.versions = (d && d.versions) || []; renderRail(); cb && cb(); }).catch(function () { cb && cb(); }); }

  var comparing = false;
  function toggleCompare() {
    comparing = !comparing; var stage = S.host.querySelector('#ai-stage');
    var ex = document.getElementById('ai-compare-view'); if (ex) ex.remove();
    if (!comparing) return;
    var orig = S.versions.find(function (v) { return v.id === S.rootId; }) || S.versions[0];
    var cur = S.versions.find(function (v) { return v.id === S.currentId; });
    if (!orig || !cur) return;
    var d = document.createElement('div'); d.id = 'ai-compare-view';
    d.style.cssText = 'position:absolute;inset:0;background:#0b0f1aee;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px';
    d.innerHTML = '<div style="position:relative;max-width:80%"><img src="' + cur.url + '" style="display:block;max-width:100%;max-height:68vh"><div id="ai-clip" style="position:absolute;top:0;left:0;height:100%;width:50%;overflow:hidden;border-right:2px solid #2d9cff"><img src="' + orig.url + '" style="position:absolute;top:0;left:0;height:100%;max-height:68vh"></div>' +
      '<span style="position:absolute;top:8px;left:8px;background:#0e1626cc;padding:2px 8px;border-radius:6px;font-size:12px">Before</span><span style="position:absolute;top:8px;right:8px;background:#0e1626cc;padding:2px 8px;border-radius:6px;font-size:12px">After</span>' +
      '<input type="range" min="0" max="100" value="50" aria-label="Comparison" style="position:absolute;bottom:8px;left:10%;width:80%"></div>' +
      '<button id="ai-cmp-close" style="background:#182338;color:#e6ebf5;border:1px solid #29354f;border-radius:8px;padding:8px 16px;cursor:pointer">Close comparison</button>';
    stage.appendChild(d);
    d.querySelector('input').oninput = function () { d.querySelector('#ai-clip').style.width = this.value + '%'; };
    d.querySelector('#ai-cmp-close').onclick = function () { comparing = false; d.remove(); };
  }

  function setBusy(b) {
    var g = S.host.querySelector('#ai-generate'); g.disabled = b; g.style.opacity = b ? 0.5 : 1; g.style.cursor = b ? 'not-allowed' : 'pointer'; g.textContent = b ? 'Generating…' : 'Generate edit';
    var ex = document.getElementById('ai-genov');
    if (b && !ex) { var o = document.createElement('div'); o.id = 'ai-genov'; o.setAttribute('role', 'status'); o.style.cssText = 'position:absolute;bottom:16px;left:50%;transform:translateX(-50%);background:#0e1626ee;border:1px solid #2d5cff;border-radius:12px;padding:10px 16px;font-size:13px'; o.textContent = 'Generating your edit… (the original is preserved)'; S.host.querySelector('#ai-stage').appendChild(o); }
    else if (!b && ex) ex.remove();
  }
  function showError(m) { var e = S.host.querySelector('#ai-error'); if (!m) { e.style.display = 'none'; return; } e.textContent = m; e.style.display = 'block'; }

  function close() { window.removeEventListener('keydown', S._key); window.removeEventListener('resize', onResize); if (S.host) S.host.remove(); S = null; }
})();
