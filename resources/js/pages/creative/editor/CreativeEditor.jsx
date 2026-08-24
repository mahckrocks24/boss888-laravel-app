/**
 * STUDIO888 Phase O — interactive canvas image editor.
 *
 * The canvas is the primary interaction surface: click/drag a rectangle, paint a
 * brush mask, erase, or edit the full image, then describe the change in natural
 * language. Every edit produces a NON-DESTRUCTIVE child version (backend-enforced).
 *
 * Architecture:
 *   - ONE authoritative reducer (editorReducer) owns all UI-reactive state.
 *   - Imperative pixel buffers (mask + undo snapshots) live in refs; the reducer
 *     tracks only derived flags (maskDirty) so React never fights the canvas.
 *   - ALL geometry goes through the unit-tested ./coords module; ALL mask export
 *     through the unit-tested ./mask module. No coordinate/mask math is duplicated.
 */
import { useEffect, useReducer, useRef, useCallback } from 'react';
import * as C from './coords.js';
import * as M from './mask.js';

const TOOLS = { SELECT: 'select', RECTANGLE: 'rectangle', BRUSH: 'brush', ERASER: 'eraser', PAN: 'pan', FULL: 'full' };
const EDIT_COST = 2;
const UNDO_LIMIT = 25;

const initial = (asset) => ({
  asset,
  rootId: asset.root_asset_id || asset.id,
  sourceW: asset.width || 1024,
  sourceH: asset.height || 1024,
  view: { scale: 1, panX: 0, panY: 0 },
  vpW: 800, vpH: 600,
  tool: TOOLS.RECTANGLE,
  rectangle: null,          // normalized {x,y,w,h}
  brushSize: 48,            // source px
  maskDirty: false,
  prompt: '',
  status: 'ready',          // ready|selected|generating|completed|failed
  error: '',
  versions: [],
  selectedVersionId: asset.id,
  compareWith: null,        // version id to compare against (parent/original)
  idempotencyKey: null,
  canUndo: false, canRedo: false,
});

function reducer(s, a) {
  switch (a.type) {
    case 'SET_VIEW': return { ...s, view: a.view };
    case 'SET_VP': return { ...s, vpW: a.vpW, vpH: a.vpH };
    case 'SET_TOOL': return { ...s, tool: a.tool, rectangle: a.tool === TOOLS.FULL ? null : s.rectangle };
    case 'SET_RECT': return { ...s, rectangle: a.rectangle, maskDirty: true, status: 'selected' };
    case 'SET_BRUSH': return { ...s, brushSize: a.size };
    case 'MASK_CHANGED': return { ...s, maskDirty: a.dirty, status: a.dirty ? 'selected' : 'ready', canUndo: a.canUndo, canRedo: a.canRedo };
    case 'SET_PROMPT': return { ...s, prompt: a.prompt };
    case 'CLEAR_SELECTION': return { ...s, rectangle: null, maskDirty: false, status: 'ready', canUndo: a.canUndo, canRedo: a.canRedo };
    case 'GENERATING': return { ...s, status: 'generating', error: '', idempotencyKey: a.key };
    case 'GEN_OK': return { ...s, status: 'completed', versions: a.versions, selectedVersionId: a.childId, rectangle: null, maskDirty: false, compareWith: a.parentId, canUndo: false, canRedo: false };
    case 'GEN_FAIL': return { ...s, status: 'failed', error: a.error };            // prompt + mask retained
    case 'SET_VERSIONS': return { ...s, versions: a.versions };
    case 'SELECT_VERSION': return { ...s, selectedVersionId: a.id, asset: a.asset, sourceW: a.asset.width || s.sourceW, sourceH: a.asset.height || s.sourceH, rectangle: null, maskDirty: false, status: 'ready', compareWith: null };
    case 'SET_COMPARE': return { ...s, compareWith: a.id };
    case 'RESET_STATUS': return { ...s, status: s.maskDirty || s.rectangle ? 'selected' : 'ready' };
    default: return s;
  }
}

export default function CreativeEditor({ asset, api, onClose }) {
  const [s, dispatch] = useReducer(reducer, asset, initial);
  const wrapRef = useRef(null);
  const baseRef = useRef(null);
  const maskRef = useRef(null);
  const overlayRef = useRef(null);
  const imgRef = useRef(null);
  const maskBufRef = useRef(null);           // offscreen mask at source dims
  const undoRef = useRef([]);                 // ImageData snapshots
  const redoRef = useRef([]);
  const dragRef = useRef(null);               // active pointer interaction
  const sRef = useRef(s); sRef.current = s;   // latest state for event handlers

  const dpr = () => Math.max(1, Math.min(3, window.devicePixelRatio || 1));

  // ── load the selected source image + (re)fit ──
  const loadImage = useCallback((url, w, h) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      imgRef.current = img;
      const sw = img.naturalWidth, sh = img.naturalHeight;
      maskBufRef.current = M.createMaskBuffer(sw, sh);
      undoRef.current = []; redoRef.current = [];
      const wrap = wrapRef.current;
      const vpW = wrap ? wrap.clientWidth : 800, vpH = wrap ? wrap.clientHeight : 600;
      dispatch({ type: 'SET_VP', vpW, vpH });
      dispatch({ type: 'SET_VIEW', view: C.fitView(sw, sh, vpW, vpH) });
      requestAnimationFrame(redrawAll);
    };
    img.src = url;
  }, []);

  useEffect(() => { loadImage(asset.url, asset.width, asset.height); }, []); // eslint-disable-line
  // load version history
  useEffect(() => {
    api.assetVersions(asset.id).then((r) => { if (r?.ok) dispatch({ type: 'SET_VERSIONS', versions: r.data?.versions || [] }); });
  }, []); // eslint-disable-line

  // ── canvas sizing (CSS px + DPR backing store) ──
  const sizeCanvas = (cvs, vpW, vpH) => {
    const ratio = dpr();
    cvs.width = Math.round(vpW * ratio); cvs.height = Math.round(vpH * ratio);
    cvs.style.width = vpW + 'px'; cvs.style.height = vpH + 'px';
    const ctx = cvs.getContext('2d'); ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    return ctx;
  };

  const redrawAll = useCallback(() => {
    const st = sRef.current; const img = imgRef.current;
    if (!img || !baseRef.current) return;
    const { vpW, vpH, view } = st;
    const bctx = sizeCanvas(baseRef.current, vpW, vpH);
    bctx.clearRect(0, 0, vpW, vpH);
    bctx.imageSmoothingQuality = 'high';
    bctx.drawImage(img, view.panX, view.panY, st.sourceW * view.scale, st.sourceH * view.scale);
    // mask layer (tinted overlay of edit region)
    const mctx = sizeCanvas(maskRef.current, vpW, vpH);
    mctx.clearRect(0, 0, vpW, vpH);
    if (maskBufRef.current) {
      mctx.globalAlpha = 0.45;
      mctx.drawImage(maskBufRef.current, view.panX, view.panY, st.sourceW * view.scale, st.sourceH * view.scale);
      mctx.globalAlpha = 1;
    }
    // overlay: rectangle-in-progress + border, brush cursor
    const octx = sizeCanvas(overlayRef.current, vpW, vpH);
    octx.clearRect(0, 0, vpW, vpH);
    if (st.rectangle) {
      const tl = C.sourceToScreen(st.rectangle.x * st.sourceW, st.rectangle.y * st.sourceH, view);
      octx.strokeStyle = '#2d9cff'; octx.lineWidth = 2; octx.setLineDash([6, 4]);
      octx.strokeRect(tl.x, tl.y, st.rectangle.w * st.sourceW * view.scale, st.rectangle.h * st.sourceH * view.scale);
      octx.setLineDash([]);
    }
  }, []);

  useEffect(() => { redrawAll(); }, [s.view, s.rectangle, s.vpW, s.vpH, redrawAll]);

  // ── resize-safe: refit keeps SOURCE coords stable ──
  useEffect(() => {
    const onResize = () => {
      const wrap = wrapRef.current; if (!wrap) return;
      const vpW = wrap.clientWidth, vpH = wrap.clientHeight;
      dispatch({ type: 'SET_VP', vpW, vpH });
      dispatch({ type: 'SET_VIEW', view: C.fitView(sRef.current.sourceW, sRef.current.sourceH, vpW, vpH) });
    };
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

  // ── mask snapshot helpers (bounded undo) ──
  const snapshot = () => {
    const buf = maskBufRef.current; if (!buf) return;
    const ctx = buf.getContext('2d');
    undoRef.current.push(ctx.getImageData(0, 0, buf.width, buf.height));
    if (undoRef.current.length > UNDO_LIMIT) undoRef.current.shift();
    redoRef.current = [];
  };
  const restore = (fromStack, toStack) => {
    const buf = maskBufRef.current; if (!buf || !fromStack.current.length) return;
    const ctx = buf.getContext('2d');
    toStack.current.push(ctx.getImageData(0, 0, buf.width, buf.height));
    const snap = fromStack.current.pop();
    ctx.putImageData(snap, 0, 0);
    const dirty = M.maskHasContent(buf);
    dispatch({ type: 'MASK_CHANGED', dirty, canUndo: undoRef.current.length > 0, canRedo: redoRef.current.length > 0 });
    redrawAll();
  };
  const undo = () => restore(undoRef, redoRef);
  const redo = () => restore(redoRef, undoRef);

  // ── pointer handling ──
  const screenPt = (e) => C.eventToScreen(e.clientX, e.clientY, overlayRef.current.getBoundingClientRect());
  const onPointerDown = (e) => {
    const st = sRef.current; overlayRef.current.setPointerCapture?.(e.pointerId);
    const p = screenPt(e);
    if (st.tool === TOOLS.PAN || e.button === 1 || e.spaceKey) { dragRef.current = { mode: 'pan', last: p, startView: st.view }; return; }
    if (st.tool === TOOLS.RECTANGLE) { dragRef.current = { mode: 'rect', p0: p }; return; }
    if (st.tool === TOOLS.BRUSH || st.tool === TOOLS.ERASER) {
      snapshot();
      const src = C.screenToSource(p.x, p.y, st.view);
      M.strokeMask(maskBufRef.current, src, src, st.brushSize / 2, st.tool === TOOLS.ERASER);
      dragRef.current = { mode: 'paint', lastSrc: src, erase: st.tool === TOOLS.ERASER };
      redrawAll();
    }
  };
  const onPointerMove = (e) => {
    const st = sRef.current; const d = dragRef.current;
    // brush cursor
    if (!d && (st.tool === TOOLS.BRUSH || st.tool === TOOLS.ERASER)) drawBrushCursor(screenPt(e));
    if (!d) return;
    const p = screenPt(e);
    if (d.mode === 'pan') {
      const v = { ...st.view, panX: d.startView.panX + (p.x - d.last.x), panY: d.startView.panY + (p.y - d.last.y) };
      dispatch({ type: 'SET_VIEW', view: C.clampPan(v, st.sourceW, st.sourceH, st.vpW, st.vpH) });
    } else if (d.mode === 'rect') {
      const region = C.screenRectToNormalizedRegion(d.p0, p, st.view, st.sourceW, st.sourceH);
      if (region) dispatch({ type: 'SET_RECT', rectangle: region });
    } else if (d.mode === 'paint') {
      const src = C.screenToSource(p.x, p.y, st.view);
      M.strokeMask(maskBufRef.current, d.lastSrc, src, st.brushSize / 2, d.erase);
      d.lastSrc = src; redrawAll();
    }
  };
  const onPointerUp = () => {
    const d = dragRef.current; dragRef.current = null;
    if (!d) return;
    if (d.mode === 'rect') {
      // bake the rectangle into the mask buffer so brush can refine it
      const st = sRef.current;
      if (st.rectangle) { snapshot(); M.fillRegion(maskBufRef.current, st.rectangle, st.sourceW, st.sourceH); redrawAll(); }
    }
    if (d.mode === 'paint' || d.mode === 'rect') {
      dispatch({ type: 'MASK_CHANGED', dirty: M.maskHasContent(maskBufRef.current), canUndo: undoRef.current.length > 0, canRedo: redoRef.current.length > 0 });
    }
  };
  const drawBrushCursor = (p) => {
    const st = sRef.current; redrawAll();
    const octx = overlayRef.current.getContext('2d');
    octx.strokeStyle = st.tool === TOOLS.ERASER ? '#ff6b6b' : '#2d9cff';
    octx.lineWidth = 1.5; octx.beginPath();
    octx.arc(p.x, p.y, C.brushScreenRadius(st.brushSize / 2, st.view), 0, Math.PI * 2); octx.stroke();
  };

  // ── zoom ──
  const zoom = (factor, cx, cy) => {
    const st = sRef.current; const fit = C.fitScale(st.sourceW, st.sourceH, st.vpW, st.vpH);
    const px = cx ?? st.vpW / 2, py = cy ?? st.vpH / 2;
    dispatch({ type: 'SET_VIEW', view: C.zoomAt(st.view, factor, px, py, fit) });
  };
  const onWheel = (e) => { e.preventDefault(); const p = screenPt(e); zoom(e.deltaY < 0 ? 1.1 : 1 / 1.1, p.x, p.y); };
  const fit = () => { const st = sRef.current; dispatch({ type: 'SET_VIEW', view: C.fitView(st.sourceW, st.sourceH, st.vpW, st.vpH) }); };
  const oneHundred = () => { const st = sRef.current; const c = C.screenToSource(st.vpW / 2, st.vpH / 2, st.view); dispatch({ type: 'SET_VIEW', view: { scale: 1, panX: st.vpW / 2 - c.sx, panY: st.vpH / 2 - c.sy } }); };

  // ── keyboard ──
  useEffect(() => {
    const onKey = (e) => {
      const meta = e.metaKey || e.ctrlKey;
      if (meta && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
      else if (meta && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) { e.preventDefault(); redo(); }
      else if (e.key === 'Escape') { dragRef.current = null; dispatch({ type: 'CLEAR_SELECTION', canUndo: undoRef.current.length > 0, canRedo: redoRef.current.length > 0 }); if (maskBufRef.current) { M.clearMask(maskBufRef.current); redrawAll(); } }
      else if (!e.metaKey && !e.ctrlKey && e.target.tagName !== 'TEXTAREA') {
        const k = e.key.toLowerCase();
        if (k === 'v') dispatch({ type: 'SET_TOOL', tool: TOOLS.SELECT });
        else if (k === 'r') dispatch({ type: 'SET_TOOL', tool: TOOLS.RECTANGLE });
        else if (k === 'b') dispatch({ type: 'SET_TOOL', tool: TOOLS.BRUSH });
        else if (k === 'e') dispatch({ type: 'SET_TOOL', tool: TOOLS.ERASER });
        else if (k === 'h') dispatch({ type: 'SET_TOOL', tool: TOOLS.PAN });
        else if (k === '0') fit();
        else if (k === '1') oneHundred();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []); // eslint-disable-line

  // ── scope + submit ──
  const scope = s.tool === TOOLS.FULL ? 'full' : (s.maskDirty ? 'mask' : 'none');
  const scopeLabel = s.tool === TOOLS.FULL ? 'Editing full image' : (s.maskDirty ? 'Editing selected region' : 'Select a region or paint a mask to edit');
  const canSubmit = s.status !== 'generating' && s.prompt.trim().length > 0 && (scope === 'full' || scope === 'mask');

  const clearMask = () => { snapshot(); M.clearMask(maskBufRef.current); dispatch({ type: 'CLEAR_SELECTION', canUndo: undoRef.current.length > 0, canRedo: redoRef.current.length > 0 }); redrawAll(); };

  const submit = async () => {
    if (!canSubmit) return;
    const key = `edit-${s.selectedVersionId}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    dispatch({ type: 'GENERATING', key });
    const payload = { source_asset_id: s.selectedVersionId, prompt: s.prompt.trim(), idempotency_key: key };
    if (scope === 'full') { payload.selection_type = 'full'; }
    else { payload.selection_type = 'mask'; payload.mask_base64 = await M.exportProviderMaskDataURL(maskBufRef.current); payload.source_width = s.sourceW; payload.source_height = s.sourceH; }
    let res;
    try { res = await api.editImage(payload); } catch (err) { res = { ok: false, data: { error: 'Network error — please retry.' } }; }
    const d = res?.data?.data || res?.data || {};
    if (res?.ok && (res.data?.success)) {
      const vr = await api.assetVersions(asset.id);
      const versions = vr?.ok ? (vr.data?.versions || []) : s.versions;
      // switch to the new child; clear transient mask
      if (maskBufRef.current) M.clearMask(maskBufRef.current);
      const child = versions.find((v) => v.id === d.id) || d;
      imgRef.current = null; loadImage(child.url, child.width, child.height);
      dispatch({ type: 'GEN_OK', versions, childId: d.id, parentId: d.parent_asset_id });
      dispatch({ type: 'SELECT_VERSION', id: d.id, asset: child });
    } else {
      dispatch({ type: 'GEN_FAIL', error: res?.data?.error || d.error || 'The edit could not be completed.' });
    }
  };

  const selectVersion = (v) => {
    if (s.status === 'generating') return;
    if (maskBufRef.current) M.clearMask(maskBufRef.current);
    imgRef.current = null; loadImage(v.url, v.width, v.height);
    dispatch({ type: 'SELECT_VERSION', id: v.id, asset: v });
  };

  // ── render ──
  const current = s.versions.find((v) => v.id === s.selectedVersionId) || s.asset;
  const compareVersion = s.compareWith ? s.versions.find((v) => v.id === s.compareWith) : null;

  return (
    <div style={ui.root}>
      <div style={ui.topbar}>
        <button onClick={onClose} style={ui.back} aria-label="Back to gallery">← Gallery</button>
        <div style={ui.tools} role="toolbar" aria-label="Editor tools">
          {tool('Select (V)', TOOLS.SELECT, s.tool, dispatch, '⤢')}
          {tool('Rectangle (R)', TOOLS.RECTANGLE, s.tool, dispatch, '▭')}
          {tool('Brush (B)', TOOLS.BRUSH, s.tool, dispatch, '🖌')}
          {tool('Eraser (E)', TOOLS.ERASER, s.tool, dispatch, '⌫')}
          {tool('Pan (H)', TOOLS.PAN, s.tool, dispatch, '✋')}
          {tool('Full image', TOOLS.FULL, s.tool, dispatch, '🖼')}
          <span style={ui.sep} />
          <button style={ui.tbtn} onClick={() => zoom(1.1)} aria-label="Zoom in">＋</button>
          <button style={ui.tbtn} onClick={() => zoom(1 / 1.1)} aria-label="Zoom out">－</button>
          <button style={ui.tbtn} onClick={fit} aria-label="Fit to screen">Fit</button>
          <button style={ui.tbtn} onClick={oneHundred} aria-label="Actual size">100%</button>
          <span style={ui.sep} />
          <button style={ui.tbtn} onClick={undo} disabled={!s.canUndo} aria-label="Undo">↶</button>
          <button style={ui.tbtn} onClick={redo} disabled={!s.canRedo} aria-label="Redo">↷</button>
          <button style={ui.tbtn} onClick={clearMask} disabled={!s.maskDirty} aria-label="Clear mask">Clear</button>
          {(s.tool === TOOLS.BRUSH || s.tool === TOOLS.ERASER) && (
            <label style={ui.brush}>Brush
              <input type="range" min="8" max="200" value={s.brushSize} onChange={(e) => dispatch({ type: 'SET_BRUSH', size: +e.target.value })} aria-label="Brush size" />
            </label>
          )}
        </div>
        <div style={ui.statusPill} data-status={s.status}>{s.status}</div>
      </div>

      <div style={ui.body}>
        {/* LEFT — version rail */}
        <aside style={ui.rail} aria-label="Version history">
          <div style={ui.railHead}>Versions</div>
          {[...s.versions].sort((a, b) => a.version - b.version).map((v) => (
            <button key={v.id} onClick={() => selectVersion(v)} style={{ ...ui.railItem, ...(v.id === s.selectedVersionId ? ui.railActive : {}) }}>
              <img src={v.thumbnail_url || v.url} alt="" style={ui.railThumb} />
              <div style={ui.railMeta}>
                <strong>{v.version === 1 ? 'Original' : `Edit v${v.version}`}</strong>
                <span style={ui.railSub}>{v.parent_asset_id ? `from v${(s.versions.find((p) => p.id === v.parent_asset_id) || {}).version || '?'}` : 'base'} · {v.status}</span>
              </div>
            </button>
          ))}
        </aside>

        {/* CENTER — canvas */}
        <main ref={wrapRef} style={ui.canvasWrap}>
          <div style={ui.canvasStack}
            onPointerDown={onPointerDown} onPointerMove={onPointerMove} onPointerUp={onPointerUp}
            onPointerLeave={() => redrawAll()} onWheel={onWheel}>
            <canvas ref={baseRef} style={ui.layer} />
            <canvas ref={maskRef} style={ui.layer} />
            <canvas ref={overlayRef} style={{ ...ui.layer, cursor: s.tool === TOOLS.PAN ? 'grab' : 'crosshair' }} />
          </div>
          {compareVersion && current && (
            <Comparison before={compareVersion} after={current} onClose={() => dispatch({ type: 'SET_COMPARE', id: null })} />
          )}
          {s.status === 'generating' && (
            <div style={ui.genOverlay} role="status" aria-live="polite">
              <div style={ui.spinner} /> Generating your edit… (does not affect the original)
            </div>
          )}
        </main>

        {/* RIGHT — composer */}
        <aside style={ui.composer} aria-label="Edit controls">
          <div style={ui.scopeBox} aria-live="polite"><span style={ui.scopeDot} data-scope={scope} /> {scopeLabel}</div>
          <label style={ui.label} htmlFor="edit-prompt">Describe your edit</label>
          <textarea id="edit-prompt" style={ui.textarea} value={s.prompt}
            onChange={(e) => dispatch({ type: 'SET_PROMPT', prompt: e.target.value })}
            onKeyDown={(e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); submit(); } }}
            placeholder="e.g. Replace the selected object with a yellow rubber duck" />
          {scope !== 'full' && (
            <p style={ui.disclosure}>Edits are concentrated in the selected area, but the AI may make subtle changes elsewhere. Your original is always preserved.</p>
          )}
          {s.error && <div style={ui.error} role="alert">{s.error} {s.status === 'failed' && <button style={ui.retry} onClick={submit}>Retry</button>}</div>}
          <div style={ui.costRow}><span>Cost</span><strong>{EDIT_COST} credits</strong></div>
          <button style={{ ...ui.generate, ...(canSubmit ? {} : ui.generateOff) }} onClick={submit} disabled={!canSubmit}>
            {s.status === 'generating' ? 'Generating…' : 'Generate edit'}
          </button>
          {s.compareWith == null && current?.parent_asset_id && (
            <button style={ui.compareBtn} onClick={() => dispatch({ type: 'SET_COMPARE', id: current.parent_asset_id })}>Compare with previous</button>
          )}
          {s.compareWith == null && s.rootId !== s.selectedVersionId && (
            <button style={ui.compareBtn} onClick={() => dispatch({ type: 'SET_COMPARE', id: s.rootId })}>Compare with original</button>
          )}
        </aside>
      </div>
    </div>
  );
}

function tool(label, t, active, dispatch, icon) {
  return (
    <button title={label} aria-label={label} aria-pressed={active === t}
      onClick={() => dispatch({ type: 'SET_TOOL', tool: t })}
      style={{ ...ui.tbtn, ...(active === t ? ui.tbtnActive : {}) }}>{icon}</button>
  );
}

/** Draggable before/after split. */
function Comparison({ before, after, onClose }) {
  const [pos, setPos] = useReducer((_, v) => v, 50);
  return (
    <div style={ui.compare}>
      <div style={ui.compareInner}>
        <img src={after.url} alt="After" style={ui.compareImg} />
        <div style={{ ...ui.compareClip, width: pos + '%' }}><img src={before.url} alt="Before" style={ui.compareImgFixed} /></div>
        <input type="range" min="0" max="100" value={pos} onChange={(e) => setPos(+e.target.value)} style={ui.compareSlider} aria-label="Comparison position" />
        <span style={{ ...ui.compareTag, left: 8 }}>Before</span>
        <span style={{ ...ui.compareTag, right: 8 }}>After</span>
      </div>
      <button style={ui.compareClose} onClick={onClose}>Close comparison</button>
    </div>
  );
}

const ui = {
  root: { position: 'fixed', inset: 0, background: '#0b0f1a', color: '#e6ebf5', display: 'flex', flexDirection: 'column', zIndex: 50 },
  topbar: { display: 'flex', alignItems: 'center', gap: 12, padding: '8px 12px', borderBottom: '1px solid #1c2740', flexWrap: 'wrap' },
  back: { background: '#182338', color: '#e6ebf5', border: '1px solid #29354f', borderRadius: 8, padding: '6px 12px', cursor: 'pointer' },
  tools: { display: 'flex', alignItems: 'center', gap: 4, flexWrap: 'wrap' },
  tbtn: { minWidth: 34, height: 34, background: '#131c30', color: '#cdd6ea', border: '1px solid #263349', borderRadius: 8, cursor: 'pointer', fontSize: 15 },
  tbtnActive: { background: '#2d5cff', borderColor: '#2d5cff', color: '#fff' },
  sep: { width: 1, height: 22, background: '#263349', margin: '0 4px' },
  brush: { display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#9fb0cc' },
  statusPill: { marginLeft: 'auto', fontSize: 12, textTransform: 'uppercase', letterSpacing: 1, color: '#7dd3fc', border: '1px solid #263349', borderRadius: 20, padding: '4px 12px' },
  body: { flex: 1, display: 'flex', minHeight: 0 },
  rail: { width: 176, borderRight: '1px solid #1c2740', overflowY: 'auto', padding: 8, flexShrink: 0 },
  railHead: { fontSize: 11, textTransform: 'uppercase', letterSpacing: 1, color: '#6b7a99', margin: '4px 6px 10px' },
  railItem: { display: 'flex', gap: 8, width: '100%', background: 'transparent', border: '1px solid transparent', borderRadius: 10, padding: 6, cursor: 'pointer', textAlign: 'left', color: '#cdd6ea', marginBottom: 6 },
  railActive: { background: '#132038', borderColor: '#2d5cff' },
  railThumb: { width: 42, height: 42, objectFit: 'cover', borderRadius: 6, flexShrink: 0 },
  railMeta: { display: 'flex', flexDirection: 'column', fontSize: 12, minWidth: 0 },
  railSub: { color: '#6b7a99', fontSize: 11 },
  canvasWrap: { flex: 1, position: 'relative', overflow: 'hidden', background: 'repeating-conic-gradient(#0d1424 0% 25%, #0a0f1c 0% 50%) 50%/24px 24px' },
  canvasStack: { position: 'absolute', inset: 0 },
  layer: { position: 'absolute', inset: 0, width: '100%', height: '100%', touchAction: 'none' },
  composer: { width: 300, borderLeft: '1px solid #1c2740', padding: 14, display: 'flex', flexDirection: 'column', gap: 10, flexShrink: 0, overflowY: 'auto' },
  scopeBox: { display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, background: '#101a2e', border: '1px solid #22314c', borderRadius: 8, padding: '8px 10px' },
  scopeDot: { width: 8, height: 8, borderRadius: 8, background: '#2d9cff', display: 'inline-block' },
  label: { fontSize: 12, color: '#9fb0cc', marginTop: 4 },
  textarea: { minHeight: 96, resize: 'vertical', background: '#0e1626', color: '#e6ebf5', border: '1px solid #263349', borderRadius: 8, padding: 10, fontSize: 14, fontFamily: 'inherit' },
  disclosure: { fontSize: 11.5, lineHeight: 1.5, color: '#8ea3c6', background: '#101a2e', borderRadius: 8, padding: '8px 10px', margin: 0 },
  error: { fontSize: 13, color: '#ffb4b4', background: '#2a1418', border: '1px solid #52222a', borderRadius: 8, padding: '8px 10px' },
  retry: { marginLeft: 8, background: '#52222a', color: '#ffd7d7', border: 'none', borderRadius: 6, padding: '3px 10px', cursor: 'pointer' },
  costRow: { display: 'flex', justifyContent: 'space-between', fontSize: 13, color: '#9fb0cc', marginTop: 'auto' },
  generate: { background: 'linear-gradient(90deg,#6d3bff,#2d7bff)', color: '#fff', border: 'none', borderRadius: 10, padding: '12px', fontSize: 15, fontWeight: 700, cursor: 'pointer' },
  generateOff: { opacity: 0.4, cursor: 'not-allowed' },
  compareBtn: { background: '#131c30', color: '#cdd6ea', border: '1px solid #263349', borderRadius: 8, padding: '8px', cursor: 'pointer', fontSize: 13 },
  genOverlay: { position: 'absolute', bottom: 16, left: '50%', transform: 'translateX(-50%)', background: '#0e1626ee', border: '1px solid #2d5cff', borderRadius: 12, padding: '10px 16px', display: 'flex', alignItems: 'center', gap: 10, fontSize: 13 },
  spinner: { width: 16, height: 16, border: '2px solid #2d5cff', borderTopColor: 'transparent', borderRadius: 8, animation: 'spin 0.8s linear infinite' },
  compare: { position: 'absolute', inset: 0, background: '#0b0f1aee', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 12 },
  compareInner: { position: 'relative', maxWidth: '80%', maxHeight: '80%' },
  compareImg: { display: 'block', maxWidth: '100%', maxHeight: '70vh' },
  compareClip: { position: 'absolute', top: 0, left: 0, height: '100%', overflow: 'hidden', borderRight: '2px solid #2d9cff' },
  compareImgFixed: { position: 'absolute', top: 0, left: 0, height: '100%', maxHeight: '70vh' },
  compareSlider: { position: 'absolute', bottom: 8, left: '10%', width: '80%' },
  compareTag: { position: 'absolute', top: 8, background: '#0e1626cc', padding: '2px 8px', borderRadius: 6, fontSize: 12 },
  compareClose: { background: '#182338', color: '#e6ebf5', border: '1px solid #29354f', borderRadius: 8, padding: '8px 16px', cursor: 'pointer' },
};
