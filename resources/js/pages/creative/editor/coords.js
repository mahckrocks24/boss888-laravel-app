/**
 * STUDIO888 Phase O — canvas coordinate transforms (pure, framework-free).
 *
 * Coordinate spaces (kept explicit — never mixed inline in handlers):
 *   SCREEN  — CSS pixels relative to the canvas element's top-left (what pointer
 *             events give after subtracting getBoundingClientRect()).
 *   SOURCE  — source-image pixels (0..sourceW, 0..sourceH). The mask + all edit
 *             payloads are expressed here so they are independent of zoom/pan/DPR.
 *   NORMALIZED — SOURCE / dimensions (0..1). What the backend `region` expects.
 *
 * View model: a source pixel (sx,sy) is drawn at
 *     screenX = sx * scale + panX
 *     screenY = sy * scale + panY
 * `scale` folds fit-to-screen AND user zoom into one number; pan is in CSS px.
 * devicePixelRatio affects only the backing-store size, NOT this math — the 2D
 * context is pre-scaled by dpr so all drawing and conversion stays in CSS px.
 */

export const MIN_SCALE_FACTOR = 0.1;   // 10% of fit
export const MAX_SCALE = 8;            // 800% absolute

/** Base scale so the whole image fits inside the viewport (contain). */
export function fitScale(sourceW, sourceH, vpW, vpH) {
  if (sourceW <= 0 || sourceH <= 0 || vpW <= 0 || vpH <= 0) return 1;
  return Math.min(vpW / sourceW, vpH / sourceH);
}

/** Centered fit view. Returns {scale, panX, panY}. */
export function fitView(sourceW, sourceH, vpW, vpH) {
  const scale = fitScale(sourceW, sourceH, vpW, vpH);
  return {
    scale,
    panX: (vpW - sourceW * scale) / 2,
    panY: (vpH - sourceH * scale) / 2,
  };
}

/** SCREEN → SOURCE. Inverse of the draw transform. */
export function screenToSource(screenX, screenY, view) {
  return {
    sx: (screenX - view.panX) / view.scale,
    sy: (screenY - view.panY) / view.scale,
  };
}

/** SOURCE → SCREEN. */
export function sourceToScreen(sx, sy, view) {
  return {
    x: sx * view.scale + view.panX,
    y: sy * view.scale + view.panY,
  };
}

/** SOURCE → NORMALIZED (0..1), clamped. */
export function sourceToNormalized(sx, sy, sourceW, sourceH) {
  return {
    x: clamp01(sx / sourceW),
    y: clamp01(sy / sourceH),
  };
}

/**
 * A drag rectangle given by two SCREEN points → a normalized region
 * {x,y,w,h} in 0..1 SOURCE space, always with positive width/height and
 * clamped to the image bounds. Returns null if degenerate.
 */
export function screenRectToNormalizedRegion(p0, p1, view, sourceW, sourceH) {
  const a = screenToSource(p0.x, p0.y, view);
  const b = screenToSource(p1.x, p1.y, view);
  let x0 = clamp01(Math.min(a.sx, b.sx) / sourceW);
  let y0 = clamp01(Math.min(a.sy, b.sy) / sourceH);
  let x1 = clamp01(Math.max(a.sx, b.sx) / sourceW);
  let y1 = clamp01(Math.max(a.sy, b.sy) / sourceH);
  const w = x1 - x0, h = y1 - y0;
  if (w <= 0 || h <= 0) return null;
  return { x: round4(x0), y: round4(y0), w: round4(w), h: round4(h) };
}

/**
 * Zoom by `factor` while keeping the SOURCE point under the cursor fixed on
 * SCREEN. Clamps to [fitScale*MIN_SCALE_FACTOR, MAX_SCALE].
 */
export function zoomAt(view, factor, screenX, screenY, fit) {
  const min = fit * MIN_SCALE_FACTOR;
  const newScale = clamp(view.scale * factor, min, MAX_SCALE);
  if (newScale === view.scale) return view;
  // source point currently under the cursor
  const s = screenToSource(screenX, screenY, view);
  // solve pan so that s maps back to (screenX,screenY) at newScale
  return {
    scale: newScale,
    panX: screenX - s.sx * newScale,
    panY: screenY - s.sy * newScale,
  };
}

/** Brush radius given in SOURCE px → SCREEN px at the current zoom. */
export function brushScreenRadius(sourceRadius, view) {
  return sourceRadius * view.scale;
}

/** SCREEN pointer from a raw mouse/touch event + canvas rect. */
export function eventToScreen(clientX, clientY, rect) {
  return { x: clientX - rect.left, y: clientY - rect.top };
}

/**
 * Keep at least `margin` CSS px of the image on-screen so it can never be lost.
 * Returns a corrected view.
 */
export function clampPan(view, sourceW, sourceH, vpW, vpH, margin = 40) {
  const imgW = sourceW * view.scale;
  const imgH = sourceH * view.scale;
  let { panX, panY } = view;
  panX = Math.min(vpW - margin, Math.max(margin - imgW, panX));
  panY = Math.min(vpH - margin, Math.max(margin - imgH, panY));
  return { ...view, panX, panY };
}

function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }
function clamp01(v) { return clamp(v, 0, 1); }
function round4(v) { return Math.round(v * 1e4) / 1e4; }
