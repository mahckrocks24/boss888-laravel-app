/**
 * STUDIO888 Phase O — edit-mask representation + provider export.
 *
 * UI semantics (kept decoupled from the provider's alpha convention):
 *   the mask buffer stores the EDIT region as OPAQUE marker pixels (alpha>0);
 *   protected area is fully transparent in the buffer. This is intuitive to draw.
 *
 * Provider export (OpenAI /v1/images/edits) is the INVERSE:
 *   edit region  → TRANSPARENT (alpha 0)
 *   protected    → OPAQUE black (alpha 255)
 *
 * The conversion lives in exactly ONE place (providerMaskRGBA below) and is unit
 * tested, so the "transparent = edit" contract can never be silently inverted.
 */

/** Marker colour painted into the UI mask buffer for edit pixels. */
export const MASK_MARKER = { r: 45, g: 156, b: 255 }; // brand blue, 50% alpha on screen

/** Create an offscreen mask canvas at SOURCE pixel dimensions. */
export function createMaskBuffer(sourceW, sourceH) {
  const c = (typeof OffscreenCanvas !== 'undefined')
    ? new OffscreenCanvas(sourceW, sourceH)
    : Object.assign(document.createElement('canvas'), { width: sourceW, height: sourceH });
  return c;
}

/** Stamp/interpolate a brush dab between two SOURCE points into the buffer. */
export function strokeMask(buffer, from, to, radius, erase) {
  const ctx = buffer.getContext('2d');
  ctx.globalCompositeOperation = erase ? 'destination-out' : 'source-over';
  ctx.strokeStyle = `rgba(${MASK_MARKER.r},${MASK_MARKER.g},${MASK_MARKER.b},1)`;
  ctx.fillStyle = ctx.strokeStyle;
  ctx.lineWidth = radius * 2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.beginPath();
  ctx.moveTo(from.x, from.y);
  ctx.lineTo(to.x, to.y);
  ctx.stroke();
  // guarantee a solid dab even for a zero-length (single click) stroke
  ctx.beginPath();
  ctx.arc(to.x, to.y, radius, 0, Math.PI * 2);
  ctx.fill();
  ctx.globalCompositeOperation = 'source-over';
}

/** Fill a normalized rectangle region into the buffer as edit pixels. */
export function fillRegion(buffer, region, sourceW, sourceH, erase = false) {
  const ctx = buffer.getContext('2d');
  ctx.globalCompositeOperation = erase ? 'destination-out' : 'source-over';
  ctx.fillStyle = `rgba(${MASK_MARKER.r},${MASK_MARKER.g},${MASK_MARKER.b},1)`;
  ctx.fillRect(region.x * sourceW, region.y * sourceH, region.w * sourceW, region.h * sourceH);
  ctx.globalCompositeOperation = 'source-over';
}

export function clearMask(buffer) {
  buffer.getContext('2d').clearRect(0, 0, buffer.width, buffer.height);
}

/** True if any edit pixel exists. */
export function maskHasContent(buffer) {
  const { data } = buffer.getContext('2d').getImageData(0, 0, buffer.width, buffer.height);
  for (let i = 3; i < data.length; i += 4) if (data[i] !== 0) return true;
  return false;
}

/**
 * PURE conversion (unit-tested): UI mask alpha → provider RGBA bytes.
 * Input:  Uint8ClampedArray RGBA of the UI buffer (edit = alpha>0).
 * Output: Uint8ClampedArray RGBA where edit → transparent, else opaque black.
 */
export function providerMaskRGBA(uiRGBA) {
  const out = new Uint8ClampedArray(uiRGBA.length);
  for (let i = 0; i < uiRGBA.length; i += 4) {
    const edit = uiRGBA[i + 3] > 0;
    out[i] = 0; out[i + 1] = 0; out[i + 2] = 0;
    out[i + 3] = edit ? 0 : 255; // edit → transparent, protected → opaque
  }
  return out;
}

/** Export the buffer to a provider-format PNG data URL (edit = transparent). */
export async function exportProviderMaskDataURL(buffer) {
  const ctx = buffer.getContext('2d');
  const ui = ctx.getImageData(0, 0, buffer.width, buffer.height);
  const providerData = providerMaskRGBA(ui.data);
  const out = createMaskBuffer(buffer.width, buffer.height);
  const octx = out.getContext('2d');
  octx.putImageData(new ImageData(providerData, buffer.width, buffer.height), 0, 0);
  if (out.convertToBlob) {
    const blob = await out.convertToBlob({ type: 'image/png' });
    return await blobToDataURL(blob);
  }
  return out.toDataURL('image/png');
}

function blobToDataURL(blob) {
  return new Promise((res) => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(blob); });
}
