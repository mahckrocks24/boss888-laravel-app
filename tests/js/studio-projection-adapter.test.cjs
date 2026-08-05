/*
 * STUDIO888 · Phase 5B — Node test harness for the browser projection adapter.
 * Pure Node (node:test + node:assert). No jsdom, no DB, no browser, no network.
 * Proves the adapter mirrors the committed Phase-4B semantics + shadow isolation.
 */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
// The adapter is a browser .js (ESM under this package's type:module). Load it as a
// script into the current global so it assigns globalThis.StudioProjectionAdapter.
vm.runInThisContext(fs.readFileSync(path.join(__dirname, '../../public/app/js/studio-projection-adapter.js'), 'utf8'),
  { filename: 'studio-projection-adapter.js' });
const SPA = globalThis.StudioProjectionAdapter;

const P0 = [
  '<!doctype html><html><head><style>:root{--primary:#FFD60A;--accent:#FFD60A}</style></head><body>',
  '<span data-field="img_stat_val" style="color:#ffd60a;font-size:72px">98%</span>',
  '<span data-field="cta_label" style="color:#ffd60a">Buy now</span>',
  '<h1 data-field="headline" style="color:#111111">Big Sale</h1>',
  '<div data-field="wrapper"><img src="x.png"/></div>',
  '</body></html>'
].join('\n');

function req(target, fields, desired, extra) {
  return Object.assign({ operation_id: 'o1', target_id: target, changed_fields: fields, desired_after_state: desired, correlation_id: 'c1' }, extra || {});
}
function styleReq(target, prop, value, extra) { return req(target, ['style.' + prop], (function (o) { o['style.' + prop] = value; return o; })({}), extra); }
function textReq(target, value, extra) { return req(target, ['text'], { text: value }, extra); }

test('capability declaration', () => {
  const c = SPA.capability();
  assert.deepStrictEqual(c.operations.sort(), ['append_text', 'hide', 'prepend_text', 'replace_text', 'set_style', 'show']);
  assert.deepStrictEqual(c.properties.sort(), ['background-color', 'color', 'font-size', 'font-weight', 'opacity', 'text-align']);
  assert.strictEqual(c.supports_verification, true);
  assert.strictEqual(c.supports_versioning, true);
});

test('version deterministic + changes on mutation', () => {
  const a = SPA.create(P0), b = SPA.create(P0);
  assert.strictEqual(a.version(), b.version());
  const before = a.version();
  a.project(styleReq('headline', 'color', 'red'));
  assert.notStrictEqual(a.version(), before);
});

test('exact target lookup + missing + ambiguous', () => {
  assert.strictEqual(SPA.create(P0).project(textReq('ghost', 'x')).status, SPA.STATUS.TARGET_MISSING);
  const dup = SPA.create('<span data-field="d">a</span><span data-field="d">b</span>');
  const r = dup.project(textReq('d', 'x'));
  assert.strictEqual(r.status, SPA.STATUS.REJECTED);
  assert.strictEqual(r.error_reason, SPA.REASON.AMBIGUOUS_TARGET);
});

test('text replace + escaping', () => {
  const a = SPA.create(P0);
  assert.strictEqual(a.project(textReq('headline', 'Mega Sale')).status, SPA.STATUS.APPLIED);
  assert.match(a.payload(), />Mega Sale<\/h1>/);
  const b = SPA.create(P0);
  const r = b.project(textReq('headline', '<b>x</b>'));
  assert.strictEqual(r.status, SPA.STATUS.APPLIED);
  assert.strictEqual(r.actual_after_state.text, '<b>x</b>');      // decoded value matches
  assert.match(b.payload(), /&lt;b&gt;x&lt;\/b&gt;/);              // escaped in markup
  assert.doesNotMatch(b.payload(), /<b>x<\/b>/);
});

test('text on non-leaf is unsupported', () => {
  const r = SPA.create(P0).project(textReq('wrapper', 'x'));
  assert.strictEqual(r.status, SPA.STATUS.UNSUPPORTED);
  assert.strictEqual(r.error_reason, SPA.REASON.NON_LEAF_TARGET);
});

test('style properties', () => {
  const cases = [
    ['color', 'red', '#ff0000'], ['background-color', '#000', '#000000'], ['opacity', '0.5', '0.5'],
    ['font-size', '60px', '60px'], ['font-weight', '700', '700'], ['text-align', 'center', 'center']
  ];
  for (const [prop, val, exp] of cases) {
    const a = SPA.create(P0);
    const r = a.project(styleReq('headline', prop, val));
    assert.strictEqual(r.status, SPA.STATUS.APPLIED, prop);
    assert.strictEqual(r.actual_after_state['style.' + prop], exp, prop);
  }
});

test('invalid + unsupported style rejected', () => {
  assert.strictEqual(SPA.create(P0).project(styleReq('headline', 'color', 'not-a-color')).error_reason, SPA.REASON.INVALID_COLOR);
  assert.strictEqual(SPA.create(P0).project(styleReq('headline', 'color', 'var(--x)')).error_reason, SPA.REASON.INVALID_COLOR);
  const r = SPA.create(P0).project(styleReq('headline', 'border-radius', '8px'));
  assert.strictEqual(r.status, SPA.STATUS.UNSUPPORTED);
});

test('hide / show', () => {
  const a = SPA.create(P0);
  assert.strictEqual(a.project(req('headline', ['visible'], { visible: false })).status, SPA.STATUS.APPLIED);
  assert.match(a.payload(), /display:none/);
  assert.strictEqual(a.project(req('headline', ['visible'], { visible: true })).status, SPA.STATUS.APPLIED);
  assert.doesNotMatch(a.payload(), /display:none/);
});

test('no-op is failed not applied', () => {
  const r = SPA.create(P0).project(styleReq('headline', 'color', '#111111'));
  assert.strictEqual(r.status, SPA.STATUS.FAILED);
  assert.strictEqual(r.error_reason, SPA.REASON.NO_CHANGE);
});

test('stale version + snapshot mismatch refuse', () => {
  assert.strictEqual(SPA.create(P0).project(styleReq('headline', 'color', 'red', { expected_document_version: 'h:0000000000000000' })).status, SPA.STATUS.STALE_VERSION);
  assert.strictEqual(SPA.create(P0).project(styleReq('headline', 'color', 'red', { before_snapshot_hash: 'deadbeefdeadbeef' })).error_reason, SPA.REASON.SNAPSHOT_MISMATCH);
  // matching snapshot applies
  const a = SPA.create(P0);
  const hash = SPA.hashState({ 'style.color': a.project(styleReq('headline', 'color', '#111111')) && '#111111' });
  const b = SPA.create(P0);
  assert.strictEqual(b.project(styleReq('headline', 'color', 'red', { before_snapshot_hash: SPA.hashState({ 'style.color': '#111111' }) })).status, SPA.STATUS.APPLIED);
});

test('idempotent replay (completed / in-progress / expired)', () => {
  const a = SPA.create(P0);
  const first = a.project(styleReq('headline', 'color', 'red', { idempotency_key: 'k1' }));
  assert.strictEqual(first.status, SPA.STATUS.APPLIED);
  const v = a.version();
  const replay = a.project(styleReq('headline', 'color', 'red', { idempotency_key: 'k1' }));
  assert.strictEqual(replay.replay, SPA.REPLAY.COMPLETED);
  assert.strictEqual(a.version(), v);                     // not re-applied
  const b = SPA.create(P0); b.markInProgress('k2');
  assert.strictEqual(b.project(styleReq('headline', 'color', 'red', { idempotency_key: 'k2' })).error_reason, SPA.REASON.IN_PROGRESS);
  const c = SPA.create(P0); c.expire('k3');
  assert.strictEqual(c.project(styleReq('headline', 'color', 'red', { idempotency_key: 'k3' })).error_reason, SPA.REASON.EXPIRED);
});

test('SHADOW ISOLATION — original serialized string is never mutated', () => {
  const original = P0;
  const a = SPA.create(original);
  a.project(styleReq('img_stat_val', 'color', 'red'));
  assert.strictEqual(P0, original);                       // the source string is untouched
  assert.notStrictEqual(a.payload(), original);           // only the adapter's own copy changed
});

test('P0 ACCEPTANCE — Change 98% to red (element-scoped, no palette, verified, replay-safe, stale-refuses)', () => {
  const a = SPA.create(P0);
  const r = a.project(styleReq('img_stat_val', 'color', 'red', { idempotency_key: 'p0' }));
  assert.strictEqual(r.status, SPA.STATUS.APPLIED);
  assert.strictEqual(r.target_id, 'img_stat_val');
  assert.strictEqual(r.actual_after_state['style.color'], '#ff0000');
  assert.strictEqual(r.verification.verified, true);
  const html = a.payload();
  assert.match(html, /data-field="img_stat_val"[^>]*color:#ff0000/);   // element-scoped
  assert.match(html, />98%<\/span>/);                                   // text unchanged
  assert.match(html, /--primary:#FFD60A/);                              // NO palette mutation
  assert.doesNotMatch(html, /--primary:#ff0000/);
  assert.match(html, /data-field="cta_label" style="color:#ffd60a"/);  // other yellow unchanged
  assert.match(html, /data-field="headline" style="color:#111111"/);   // unrelated unchanged
  // replay does not reapply
  const v = a.version();
  assert.strictEqual(a.project(styleReq('img_stat_val', 'color', 'red', { idempotency_key: 'p0' })).replay, SPA.REPLAY.COMPLETED);
  assert.strictEqual(a.version(), v);
  // stale version refuses
  assert.strictEqual(SPA.create(P0).project(styleReq('img_stat_val', 'color', 'red', { expected_document_version: 'h:deadbeefdeadbeef' })).status, SPA.STATUS.STALE_VERSION);
  // failed projection cannot be reported as success
  const noop = SPA.create(P0).project(styleReq('img_stat_val', 'color', '#ffd60a'));
  assert.strictEqual(noop.status, SPA.STATUS.FAILED);
  assert.notStrictEqual(noop.status, SPA.STATUS.APPLIED);
});
