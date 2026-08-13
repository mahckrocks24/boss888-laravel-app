/*
 * STUDIO888 · WAVE 3 — canonical image editor regression.
 *
 * The editor's tool rail is a fixed ~320px column, but .st-tabs was
 * display:flex with no wrap. Measured live at a 1280 viewport: the strip was
 * 457px inside a 319px rail, so EXPORT and PRODUCTION sat past the rail edge
 * (right 584 and 677 vs a rail ending at 540) and were simply not clickable.
 * Export and the whole Production history were unreachable from the canonical
 * image editor.
 */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '../..');
const SRC = fs.readFileSync(path.join(ROOT, 'public/app/js/studio.js'), 'utf8');

function cssRule(selector) {
  const i = SRC.indexOf("'" + selector + '{');
  assert.notStrictEqual(i, -1, 'CSS rule not found: ' + selector);
  return SRC.slice(i, SRC.indexOf("'", i + 1 + selector.length + 1));
}

test('the editor tab rail wraps instead of clipping tabs', () => {
  const rule = cssRule('.st-tabs');
  assert.ok(/flex-wrap:\s*wrap/.test(rule),
    '.st-tabs must wrap — a fixed-width rail silently clips whatever overflows');
  assert.ok(!/overflow-x:\s*(hidden|scroll|auto)/.test(rule),
    'hiding the overflow would swap an unreachable tab for a hidden one');
});

test('every canonical image editor tab is declared', () => {
  for (const t of ['content', 'insert', 'layers', 'images', 'colors', 'export', 'production']) {
    assert.ok(SRC.includes('data-tab="' + t + '"'), 'missing tab: ' + t);
  }
});

test('each declared tab has a renderer', () => {
  // _studioSwitchTab dispatches by id; every declared tab must be handled or it
  // renders blank when reached.
  const i = SRC.indexOf('window._studioSwitchTab');
  assert.notStrictEqual(i, -1, '_studioSwitchTab not found');
  const seg = SRC.slice(i, i + 2600);
  for (const t of ['content', 'insert', 'layers', 'images', 'colors', 'export', 'production']) {
    assert.ok(seg.includes("'" + t + "'"), 'tab not dispatched: ' + t);
  }
});

test('image generation goes through a governed Studio endpoint', () => {
  assert.ok(SRC.includes("_fetchJson('/studio/ai/generate-image'"),
    'generation must use the governed Studio endpoint');
  for (const raw of ['api.openai.com', 'generate-minimax', 'dall-e', 'oaidalleapi']) {
    assert.ok(!SRC.includes(raw), 'raw provider reference must not appear: ' + raw);
  }
});

test('image editing and assets use the canonical services', () => {
  assert.ok(SRC.includes("_fetchJson('/creative/assets'"), 'assets must come from the canonical endpoint');
  assert.ok(/\/creative\/assets\/'\s*\+\s*assetId\s*\+\s*'\/versions/.test(SRC),
    'lineage must read the canonical versions endpoint');
});

test('no Phase-labelled or coming-soon controls face the customer', () => {
  const visible = SRC.split('\n').filter(l =>
    /(Phase\s*[0-9OPQ]|Coming soon)/i.test(l) &&
    /<button|st-btn|st-tab|placeholder=/.test(l) &&
    !l.trim().startsWith('//') && !l.trim().startsWith('*'));
  assert.deepStrictEqual(visible.map(l => l.trim().slice(0, 90)), [],
    'customer-facing controls must not carry internal phase labels');
});
