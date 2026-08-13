/*
 * STUDIO888 · Fixed-stage sizing regression (2026-08-13).
 *
 * Every animated template is ALSO designed to be opened standalone in a browser
 * tab, so each ships its own responsive downscale:
 *
 *     @media(max-width:1160px){ .sw{ transform:scale(.46); ... } }
 *
 * Studio presents those templates on a FIXED stage whose width is the canvas
 * width (1080 for a reel) — under 1160, so the rule fires. The template shrank
 * itself to 46% and then BOTH surfaces scaled it again:
 *
 *   EDITOR   _svAnimFit scales the iframe -> 0.46 x 0.287 = 0.13
 *   RECORDER studio-record.cjs clips the full viewport -> 46% reel on black
 *
 * On a fixed stage the stage must be the ONLY sizing authority. These tests pin
 * that in both surfaces, and prove the override is generic across every shipped
 * template rather than tuned to the two we eyeballed.
 */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '../..');
const EDITOR = fs.readFileSync(path.join(ROOT, 'public/app/js/studio-video.js'), 'utf8');
const RECORDER = fs.readFileSync(path.join(ROOT, 'tools/studio-record.cjs'), 'utf8');

function bodyOf(src, header) {
  const i = src.indexOf(header);
  assert.notStrictEqual(i, -1, 'not found: ' + header);
  let depth = 0, j = i, started = false;
  while (j < src.length) {
    const c = src[j];
    if (c === '{') { depth++; started = true; }
    else if (c === '}') { depth--; if (started && depth === 0) break; }
    j++;
  }
  return src.slice(i, j + 1);
}
const stripComments = s => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

// ── EDITOR ──────────────────────────────────────────────────────────────────
test('editor defines a stage neutraliser', () => {
  assert.ok(EDITOR.includes('function _svAnimNeutraliseSelfScale('),
    'the editor must define _svAnimNeutraliseSelfScale()');
});

test('editor neutraliser kills the template self-scale and its scene padding', () => {
  const fn = bodyOf(EDITOR, 'function _svAnimNeutraliseSelfScale(');
  assert.ok(/\.sw\{[^']*transform:none!important/.test(fn), '.sw transform must be forced to none');
  assert.ok(fn.includes('.scene'), '.scene must be pinned (it carries min-height:100vh + padding)');
  assert.ok(/min-height:0!important/.test(fn), '.scene min-height:100vh must be neutralised');
  assert.ok(/padding:0!important/.test(fn), '.scene padding must be neutralised');
  assert.ok(fn.includes('sv-anim-stage-style'), 'the injected style needs a stable id so it is idempotent');
});

test('editor applies the neutraliser from the fitter, before measuring', () => {
  const fit = stripComments(bodyOf(EDITOR, 'function _svAnimFit('));
  assert.ok(fit.includes('_svAnimNeutraliseSelfScale()'),
    '_svAnimFit must apply the neutraliser');
  const applyAt = fit.indexOf('_svAnimNeutraliseSelfScale()');
  const measureAt = fit.indexOf('wrap.clientWidth');
  assert.ok(applyAt > -1 && measureAt > -1 && applyAt < measureAt,
    'the neutraliser must run BEFORE the stage is measured, or the two scales multiply');
});

test('editor keeps exactly one scaling authority for the animated stage', () => {
  const fit = stripComments(bodyOf(EDITOR, 'function _svAnimFit('));
  const scales = (fit.match(/transform\s*=\s*'scale\(/g) || []).length;
  assert.strictEqual(scales, 1, 'exactly one scale transform must be applied by the fitter, found ' + scales);
});

// ── RECORDER ────────────────────────────────────────────────────────────────
test('recorder pins the template to the stage before capturing', () => {
  const rec = stripComments(RECORDER);
  assert.ok(rec.includes('addStyleTag'), 'the recorder must inject a stage override');
  assert.ok(/\.sw\{[^']*transform:none!important/.test(rec), '.sw transform must be forced to none');
  const injectAt = rec.indexOf('addStyleTag');
  const captureAt = rec.indexOf('page.screenshot');
  assert.ok(injectAt > -1 && captureAt > -1 && injectAt < captureAt,
    'the override must be injected BEFORE the first screenshot');
});

test('recorder sizes the override from the requested dimensions, not hardcoded', () => {
  const rec = stripComments(RECORDER);
  const i = rec.indexOf('addStyleTag');
  const seg = rec.slice(i, i + 900);
  assert.ok(seg.includes("' + width + '"), 'width must come from the requested render width');
  assert.ok(seg.includes("' + height + '"), 'height must come from the requested render height');
  assert.ok(!/\.sw\{[^']*1080px!important/.test(seg), 'dimensions must not be hardcoded to a reel');
});

// ── ENGINE-LEVEL: the override must cover every shipped template ────────────
const TPL_DIRS = [
  path.join(ROOT, 'storage/app/public/studio-video-templates'), // editor copy
  path.join(ROOT, 'storage/templates/studio/video'),            // recorder copy
];

function templates(dir) {
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir)
    .map(s => path.join(dir, s, 'template.html'))
    .filter(f => fs.existsSync(f));
}

test('every shipped template carries the hazard the override targets', () => {
  let checked = 0;
  for (const dir of TPL_DIRS) {
    for (const f of templates(dir)) {
      const src = fs.readFileSync(f, 'utf8');
      if (!/@media[^{]*max-width/.test(src)) continue; // no breakpoint, not a hazard
      checked++;
      const name = path.relative(ROOT, f);
      // The override selectors are html/body, .scene and .sw — the markup must use them.
      assert.ok(/class="scene"/.test(src), name + ' must use the .scene wrapper the override targets');
      assert.ok(/class="sw"/.test(src), name + ' must use the .sw wrapper the override targets');
    }
  }
  assert.ok(checked >= 40, 'expected at least 40 hazardous templates, saw ' + checked);
});

test('no shipped template uses a sizing mechanism the override cannot neutralise', () => {
  const offenders = [];
  for (const dir of TPL_DIRS) {
    for (const f of templates(dir)) {
      const src = fs.readFileSync(f, 'utf8');
      // zoom is not overridden by our rules; vw-based widths would also escape it.
      if (/\bzoom\s*:/.test(src)) offenders.push(path.relative(ROOT, f) + ' (zoom)');
      if (/width\s*:\s*[0-9.]+vw/.test(src)) offenders.push(path.relative(ROOT, f) + ' (vw width)');
    }
  }
  assert.deepStrictEqual(offenders, [], 'templates use sizing the stage override does not neutralise');
});
