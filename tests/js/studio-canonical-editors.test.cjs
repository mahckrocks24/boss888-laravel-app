/*
 * STUDIO888 · LEGACY EDITOR EXTERMINATION regression.
 *
 * /app/manualedit rendered an ENTIRE second Studio — its own "Design Studio"
 * with Image Design / Video Editor tabs and its own design store. Its nav entry
 * was removed on 2026-04-20, but the core.js router entry, the index.html engine
 * URL, the view container and all three bundles survived, so any bookmark,
 * history entry or deep link resurrected it.
 *
 * These tests pin that there is exactly ONE canonical image editor and ONE
 * canonical video editor reachable, and that the retired implementations cannot
 * come back through a lazy loader, an engine URL or a router entry.
 */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '../..');
const JS = path.join(ROOT, 'public/app/js');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const stripComments = s =>
  s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

const RETIRED_BUNDLES = ['manualedit.js', 'manualedit-fabric.js', 'manualedit-video.js'];

test('the retired editor bundles are deleted, not merely unreferenced', () => {
  for (const f of RETIRED_BUNDLES) {
    assert.strictEqual(fs.existsSync(path.join(JS, f)), false,
      f + ' must be deleted — hiding it leaves it loadable');
  }
});

test('nothing can lazy-load a retired bundle', () => {
  const files = fs.readdirSync(JS).filter(f => f.endsWith('.js') && !f.includes('.bak'));
  const offenders = [];
  for (const f of files) {
    const src = stripComments(fs.readFileSync(path.join(JS, f), 'utf8'));
    for (const b of RETIRED_BUNDLES) {
      if (src.includes(b)) offenders.push(f + ' -> ' + b);
    }
  }
  assert.deepStrictEqual(offenders, [], 'live references to retired bundles remain');
});

test('index.html carries no engine URL for the retired Studio', () => {
  const html = read('public/app/index.html');
  assert.ok(!/manualedit\s*:\s*['"]\/app\/js\/manualedit\.js/.test(html),
    'the manualedit engine URL must be gone, or luLoadEngine can resurrect it');
  for (const b of RETIRED_BUNDLES) {
    assert.ok(!new RegExp('src=["\'][^"\']*' + b.replace('.', '\\.')).test(html),
      'index.html must not script-tag ' + b);
  }
});

test('the router sends the retired URL to Studio instead of executing it', () => {
  const core = read('public/app/js/core.js');
  const line = core.split('\n').find(l => l.includes("view==='manualedit'"));
  assert.ok(line, 'the retired view must still be handled — silently 404ing a bookmark is worse');
  assert.ok(/nav\('studio'\)/.test(line), 'it must redirect to Studio');
  assert.ok(!/luLoadEngine\('manualedit'\)/.test(line),
    'it must never load the retired engine again');
});

test('exactly one canonical image editor entry is exported', () => {
  const studio = stripComments(read('public/app/js/studio.js'));
  // window._mountEditor is the compatibility export; it must delegate to the
  // canonical Phase-Q editor rather than to the older in-file implementation.
  assert.ok(/window\._mountEditor\s*=\s*function\s*\(\)\s*\{\s*_stOpenImageEditor/.test(studio),
    'the image editor export must delegate to _stOpenImageEditor');
  assert.ok(studio.includes('function _stOpenImageEditor('),
    'the canonical image editor must exist');
});

test('exactly one canonical video editor answers each artifact kind', () => {
  const raw = read('public/app/js/studio-video.js');
  // Only the routing-decision count needs comment stripping — a comment could
  // otherwise fake a match. Function existence is checked against raw source,
  // because stripComments trips over an unbalanced /* inside a CSS string.
  const decisions = (stripComments(raw).match(/source === 'html_animated'/g) || []).length;
  assert.strictEqual(decisions, 1,
    'exactly one routing decision may choose the video surface; found ' + decisions);
  assert.ok(raw.includes('function _mountHtmlAnimatedEditor('), 'animated editor must exist');
  assert.ok(raw.includes('function _svOpenClipEditor('), 'clip editor must exist');
});

test('the kept AI-edit dependency is genuinely independent of the retired editors', () => {
  const ai = stripComments(read('public/app/js/manualedit-aiedit.js'));
  for (const b of RETIRED_BUNDLES) {
    assert.ok(!ai.includes(b), 'manualedit-aiedit.js must not depend on ' + b);
  }
  for (const g of ['manualeditLoad', 'meOpenEditor', 'meOpenProject']) {
    assert.ok(!new RegExp('\\b' + g + '\\s*\\(').test(ai),
      'manualedit-aiedit.js must not call the retired global ' + g);
  }
  assert.ok(/window\.meOpenAiEdit\s*=/.test(ai),
    'it must still provide the AI-edit entry Studio depends on');
});

test('canonical Studio never calls a retired global', () => {
  const both = stripComments(read('public/app/js/studio.js'))
             + stripComments(read('public/app/js/studio-video.js'));
  for (const g of ['manualeditLoad', 'manualeditLoad_fabric', 'manualeditVideoLoad',
                   'meOpenEditor', 'meOpenProject']) {
    assert.ok(!both.includes(g), 'canonical Studio must not reference ' + g);
  }
});

test('no source patch or backup is left in the public web root', () => {
  const leaked = fs.readdirSync(JS).filter(f => /\.(patch|diff|orig|rej)$/i.test(f));
  assert.deepStrictEqual(leaked, [], 'source artefacts must not be web-served');
});
