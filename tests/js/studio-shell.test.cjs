/*
 * STUDIO888 · WAVE 1B — enterprise shell regression.
 *
 * Before this wave Studio opened straight into a design gallery: no Home, no
 * Assets destination, Production reachable only as a tab buried inside the image
 * editor, and customer copy that named an internal engine. "Designs" (editable
 * Studio documents) and generated media were conflated.
 *
 * These tests pin the shell's structure and — importantly — that the Assets
 * destination stays HONEST until Wave 2 ships the real library.
 */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '../..');
const SRC = fs.readFileSync(path.join(ROOT, 'public/app/js/studio.js'), 'utf8');

// _ST_NAV is an array literal — brace-walking would stop at its first element.
function arrayOf(header) {
  const i = SRC.indexOf(header);
  assert.notStrictEqual(i, -1, 'not found: ' + header);
  const end = SRC.indexOf('];', i);
  assert.notStrictEqual(end, -1, 'unterminated array: ' + header);
  return SRC.slice(i, end + 2);
}

function bodyOf(header) {
  const i = SRC.indexOf(header);
  assert.notStrictEqual(i, -1, 'not found: ' + header);
  let depth = 0, j = i, started = false;
  while (j < SRC.length) {
    const c = SRC[j];
    if (c === '{') { depth++; started = true; }
    else if (c === '}') { depth--; if (started && depth === 0) break; }
    j++;
  }
  return SRC.slice(i, j + 1);
}
const stripComments = s => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

test('the shell exposes exactly the four Wave-1 destinations', () => {
  const nav = arrayOf('var _ST_NAV = [');
  for (const id of ['home', 'designs', 'assets', 'production']) {
    assert.ok(nav.includes("id: '" + id + "'"), 'nav must contain ' + id);
  }
  const ids = (nav.match(/id: '/g) || []).length;
  assert.strictEqual(ids, 4, 'expected exactly 4 destinations, found ' + ids);
});

test('Studio opens on the shell Home, not the design gallery', () => {
  const load = stripComments(bodyOf('window.studioLoad = function (rootEl) {'));
  assert.ok(load.includes("_stGo('home')"), 'entry must route to the shell Home');
  assert.ok(!/_mountGallery\(\);\s*\};\s*$/.test(load),
    'entry must no longer mount the gallery directly');
});

test('every destination has a mount and the router covers them', () => {
  const go = stripComments(bodyOf('window._stGo = function (view) {'));
  for (const [view, fn] of [['designs', '_mountGallery'], ['assets', '_stMountAssets'],
                            ['production', '_stMountProduction']]) {
    assert.ok(go.includes("'" + view + "'") && go.includes(fn),
      view + ' must route to ' + fn);
  }
  assert.ok(go.includes('_stMountHome'), 'home must have a mount');
  for (const fn of ['function _stMountHome(', 'function _stMountAssets(', 'function _stMountProduction(']) {
    assert.ok(SRC.includes(fn), 'missing ' + fn);
  }
});

test('the Designs surface renders under the shell nav', () => {
  const gal = bodyOf('function _mountGallery() {');
  assert.ok(gal.includes("_stShellHeader('designs')"),
    'the gallery must render the shell header so navigation is always present');
});

test('Assets does NOT fabricate a media library', () => {
  const a = stripComments(bodyOf('function _stMountAssets() {'));
  // It must not build cards from designs or any other record set.
  ['_st2Designs', '_prodCard', 'st2-card'].forEach(f => {
    assert.ok(!a.includes(f), 'Assets must not fabricate a grid from ' + f);
  });
  assert.ok(!a.includes('_fetchJson'), 'Assets must not claim data it cannot yet serve');
  assert.ok(/not available yet/i.test(a), 'Assets must state its real status');
  // The markup is built inside JS string literals, so the file text carries
  // escaped quotes: _stGo(\'production\').
  assert.ok(/_stGo\(\\?'production\\?'\)/.test(a),
    'Assets should point at where media is genuinely visible');
});

test('Production uses the real job list, not a placeholder', () => {
  const p = stripComments(bodyOf('function _stMountProduction() {'));
  assert.ok(p.includes("_loadProductionJobs('all')"),
    'Production must load the real creative_jobs-backed list');
  assert.ok(!/\b(42|99|100)%/.test(p), 'Production must not show invented progress');
});

test('Home derives its counts from real endpoints and admits failure', () => {
  const h = stripComments(bodyOf('function _stMountHome() {'));
  assert.ok(h.includes('/studio/designs'), 'designs count must come from the designs endpoint');
  assert.ok(h.includes('/studio/production/jobs'), 'production count must come from the jobs endpoint');
  assert.ok(h.includes("'unavailable'"), 'a count that cannot be read must say so, not show 0');
});

test('the workspace chip reads the authoritative identity endpoint', () => {
  const c = stripComments(bodyOf('function _stRenderContext() {'));
  assert.ok(c.includes('/auth/me'), 'the chip must use /auth/me (authoritative as of d7fc09b)');
  assert.ok(c.includes('current_workspace_id'), 'it must name the ACTIVE workspace');
  assert.ok(c.includes('credit_balance'), 'credits must come from the real field');
});

test('no internal engine name is visible to the customer', () => {
  const visible = SRC.split('\n').filter(l =>
    /Arthur/.test(l) && /['"]/.test(l) && !l.trim().startsWith('//') && !l.trim().startsWith('*'));
  assert.deepStrictEqual(visible.map(l => l.trim().slice(0, 80)), [],
    'customer-visible strings must not name internal engines');
});

test('the shell never reintroduces a raw provider path', () => {
  assert.ok(!/generate-minimax/.test(SRC), 'studio.js must not reference the retired raw provider path');
});

test('shell controls are real buttons with accessible names', () => {
  const head = bodyOf('function _stShellHeader(active) {');
  assert.ok(head.includes('<nav') && head.includes('aria-label'), 'nav needs a landmark label');
  assert.ok(head.includes('aria-current'), 'the active destination must be announced');
  const btns = (head.match(/<button/g) || []).length;
  assert.ok(btns >= 3, 'shell actions must be real buttons, found ' + btns);
  assert.ok(!/<div[^>]*onclick/.test(head), 'no click-handling divs in the shell header');
});
