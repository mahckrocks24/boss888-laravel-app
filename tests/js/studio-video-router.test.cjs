/*
 * STUDIO888 · Video editor routing regression (P0 black canvas, 2026-08-13).
 * Pure Node (node:test + node:assert). No jsdom, no DB, no browser, no network.
 *
 * Two defects put every legacy animated design on a black canvas:
 *
 *   1. CROSS-CLOSURE CALL. studio-video.js holds two editor generations in two
 *      IIFEs. The clip editor (second IIFE, 'use strict') carried a copy of the
 *      html_animated branch that referenced _mountHtmlAnimatedEditor/_designId/
 *      _svAnimLastExport — symbols private to the FIRST IIFE. Under strict mode
 *      that can only throw ReferenceError.
 *
 *   2. SINGLE-SOURCE MARKER READ. The router read {source, template_slug} only
 *      from video_data. The server writes those markers into layers_json at
 *      creation, and every design authored before the video_data column existed
 *      has video_data empty with its composition in content_html — so the
 *      animated branch never matched and the design fell through to the clip
 *      editor, which normalised to clips:[] and painted black.
 *
 * These tests pin both invariants structurally, plus the resolution rule itself.
 */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const SRC = fs.readFileSync(
  path.join(__dirname, '../../public/app/js/studio-video.js'), 'utf8');

// ── Locate the two IIFEs so we can assert per-closure ownership ──────────────
function iifeBounds(src) {
  const lines = src.split('\n');
  const starts = [], ends = [];
  lines.forEach((l, i) => {
    if (/^\(function\s*\(/.test(l)) starts.push(i);
    if (/^\}\)\(\s*\)?\s*;/.test(l)) ends.push(i);
  });
  return { lines, starts, ends };
}
function sliceOf(name) {
  const { lines, starts, ends } = iifeBounds(SRC);
  assert.ok(starts.length >= 2, 'expected at least two IIFEs in studio-video.js');
  const idx = name === 'first' ? 0 : 1;
  return lines.slice(starts[idx], ends[idx] + 1).join('\n');
}
function bodyOf(fnHeader) {
  const i = SRC.indexOf(fnHeader);
  assert.notStrictEqual(i, -1, 'not found: ' + fnHeader);
  let depth = 0, j = i, started = false;
  while (j < SRC.length) {
    const c = SRC[j];
    if (c === '{') { depth++; started = true; }
    else if (c === '}') { depth--; if (started && depth === 0) break; }
    j++;
  }
  return SRC.slice(i, j + 1);
}

// Comments legitimately NAME these symbols to explain why they must not be called
// here; only executable code matters, so strip comments before asserting.
function stripComments(src) {
  return src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
}

test('the clip editor never calls the animated editor across closures', () => {
  const clip = stripComments(bodyOf('function _svOpenClipEditor('));
  for (const sym of ['_mountHtmlAnimatedEditor', '_svAnimLastExport', '_designName']) {
    assert.ok(!clip.includes(sym),
      '_svOpenClipEditor must not reference ' + sym + ' — it lives in the other IIFE and throws under \'use strict\'');
  }
});

test('the animated editor and the clip editor stay in separate closures', () => {
  const first = sliceOf('first'), second = sliceOf('second');
  assert.ok(first.includes('function _mountHtmlAnimatedEditor('),
    'the animated editor must be defined in the first IIFE');
  assert.ok(second.includes('function _svOpenClipEditor('),
    'the clip editor must be defined in the second IIFE');
  assert.ok(!second.includes('function _mountHtmlAnimatedEditor('),
    'the animated editor must not be duplicated into the clip IIFE');
});

test('the single router reads the markers from BOTH video_data and layers_json', () => {
  const router = bodyOf('window.studioVideoOpenDesign = function(');
  assert.ok(router.includes('layers_json'),
    'router must fall back to layers_json — legacy designs keep their markers there');
  assert.ok(router.includes('video_data'),
    'router must still read video_data — new designs keep their markers there');
  assert.ok(/meta\.template_slug/.test(router),
    'router must resolve template_slug from the layers_json metadata');
  assert.ok(router.includes('_mountHtmlAnimatedEditor'),
    'router must be able to mount the animated editor (it owns that symbol)');
  assert.ok(router.includes('_svOpenClipEditor'),
    'router must delegate non-animated designs to the clip editor');
});

test('the router is the only place that decides the editor surface', () => {
  const hits = (SRC.match(/source === 'html_animated'/g) || []).length;
  assert.strictEqual(hits, 1,
    'exactly one html_animated routing decision must exist; found ' + hits);
});

// ── The resolution rule itself, mirrored exactly as the router applies it ────
function resolve(row) {
  let vd = {}, meta = {};
  try { vd = typeof row.video_data === 'string' ? JSON.parse(row.video_data || '{}') : (row.video_data || {}); } catch (_) {}
  try { meta = typeof row.layers_json === 'string' ? JSON.parse(row.layers_json || '{}') : (row.layers_json || {}); } catch (_) {}
  const source = vd.source || meta.source;
  const slug = vd.template_slug || vd.slug || meta.template_slug || meta.slug;
  return (source === 'html_animated' && slug) ? { editor: 'animated', slug } : { editor: 'clip' };
}

test('legacy design: markers in layers_json only, video_data empty → animated', () => {
  const r = resolve({
    video_data: null,
    layers_json: '{"template_slug":"r01-construction","source":"html_animated"}',
  });
  assert.deepStrictEqual(r, { editor: 'animated', slug: 'r01-construction' });
});

test('legacy design: layers_json already parsed to an object → animated', () => {
  const r = resolve({
    video_data: null,
    layers_json: { template_slug: 'r02-legal', source: 'html_animated' },
  });
  assert.deepStrictEqual(r, { editor: 'animated', slug: 'r02-legal' });
});

test('self-healed design: markers present in video_data → animated', () => {
  const r = resolve({
    video_data: '{"source":"html_animated","template_slug":"b01-saas","fields":{}}',
    layers_json: '{}',
  });
  assert.deepStrictEqual(r, { editor: 'animated', slug: 'b01-saas' });
});

test('genuinely empty clip project → clip editor, not animated', () => {
  const r = resolve({
    video_data: '{"clips":[],"text_overlays":[],"elements":[],"duration":15}',
    layers_json: '{"source":"video","template_slug":null}',
  });
  assert.deepStrictEqual(r, { editor: 'clip' });
});

test('html_animated without a slug must not mount the animated editor', () => {
  const r = resolve({ video_data: '{"source":"html_animated"}', layers_json: '{}' });
  assert.deepStrictEqual(r, { editor: 'clip' });
});

test('malformed JSON must not throw — it degrades to the clip editor', () => {
  const r = resolve({ video_data: '{not json', layers_json: '{also not json' });
  assert.deepStrictEqual(r, { editor: 'clip' });
});
