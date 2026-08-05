/*
 * STUDIO888 · Browser Projection Adapter (Phase 5B) — studio-projection-adapter.js
 *
 * A concrete, browser-loadable implementation of the committed Phase-3B projection
 * protocol. It operates on the SERIALIZED HTML STRING of a Studio design (the
 * shadow document) using the exact targeted-string semantics proven server-side
 * in Phase-4B (RawHtmlDocument / HtmlProjectionAdapter):
 *
 *   - exact data-field target lookup (no selectors/XPath/fuzzy/fallback)
 *   - content-hash document version + per-target before-snapshot hash
 *   - text-content-escaped text writes (never innerHTML)
 *   - element-scoped inline style only (never :root / palette)
 *   - actual read-back + verification (never success from acceptance)
 *   - idempotent replay, stale-version / snapshot / unsupported / no-op handling
 *
 * SHADOW-SAFE: it mutates only the string it is given (a detached serialization),
 * never the live iframe DOM. It performs no persistence, no autosave, no network.
 *
 * Dual export: window.StudioProjectionAdapter (browser) + module.exports (Node tests).
 */
(function () {
  'use strict';

  var SCHEMA_VERSION = 1;

  var STATUS = {
    APPLIED: 'applied', PARTIAL: 'partial', REJECTED: 'rejected',
    STALE_VERSION: 'stale_version', TARGET_MISSING: 'target_missing',
    UNSUPPORTED: 'unsupported', FAILED: 'failed'
  };
  var REPLAY = { FIRST: 'first', COMPLETED: 'completed', IN_PROGRESS: 'in_progress', EXPIRED: 'expired' };
  var REASON = {
    STALE_VERSION: 'stale_version', SNAPSHOT_MISMATCH: 'snapshot_mismatch',
    UNSUPPORTED_PROPERTY: 'unsupported_property', TARGET_MISSING: 'target_missing',
    AMBIGUOUS_TARGET: 'ambiguous_target', NON_LEAF_TARGET: 'non_leaf_target',
    NO_CHANGE: 'no_change', VERIFICATION_FAILED: 'verification_failed',
    INVALID_COLOR: 'invalid_color', INVALID_VALUE: 'invalid_value',
    INVALID_UNIT: 'invalid_unit', UNSAFE_VALUE: 'unsafe_value',
    IN_PROGRESS: 'in_progress', EXPIRED: 'expired'
  };

  var STYLE_PROPS = ['color', 'background-color', 'opacity', 'font-size', 'font-weight', 'text-align'];
  var STYLE_UNITS = ['px', '%', 'rem', 'em'];

  // ---- deterministic hashing (non-crypto; identical in browser + Node) ----
  function hash32(str, seed) {
    var h = seed >>> 0;
    for (var i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 0x01000193) >>> 0; }
    return h >>> 0;
  }
  function hash16(str) {
    var a = hash32(str, 0x811c9dc5), b = hash32(str, 0x9e3779b9);
    return ('00000000' + a.toString(16)).slice(-8) + ('00000000' + b.toString(16)).slice(-8);
  }
  function hashState(map) {
    var keys = Object.keys(map).sort();
    var parts = keys.map(function (k) { return JSON.stringify(k) + ':' + JSON.stringify(map[k]); });
    return hash16('{' + parts.join(',') + '}').slice(0, 16);
  }

  // ---- text escaping (text-content semantics) ----
  function escapeText(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function decodeText(s) {
    return String(s).replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'").replace(/&amp;/g, '&');
  }

  // ---- colour normalization (mirrors Phase-1 ColorNormalizer) ----
  var NAMED = {
    black: '#000000', white: '#ffffff', red: '#ff0000', green: '#008000', lime: '#00ff00',
    blue: '#0000ff', yellow: '#ffff00', cyan: '#00ffff', magenta: '#ff00ff', gray: '#808080',
    grey: '#808080', orange: '#ffa500', gold: '#ffd700', pink: '#ffc0cb', purple: '#800080',
    navy: '#000080', teal: '#008080', silver: '#c0c0c0', maroon: '#800000', transparent: '#00000000'
  };
  var UNSAFE = ['javascript:', 'url(', 'expression(', 'calc(', 'var(', '<', '>', ';', '{', '}', '/*', '\\'];
  function normalizeColor(value) {
    var raw = String(value == null ? '' : value).trim();
    if (raw === '') { return null; }
    var low = raw.toLowerCase();
    for (var i = 0; i < UNSAFE.length; i++) { if (low.indexOf(UNSAFE[i]) !== -1) { return null; } }
    if (low[0] === '#') {
      var hex = low.slice(1);
      if (!/^[0-9a-f]+$/.test(hex)) { return null; }
      if (hex.length === 3) { return '#' + hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2]; }
      if (hex.length === 4) { return '#' + hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3]; }
      if (hex.length === 6 || hex.length === 8) { return '#' + hex; }
      return null;
    }
    var m = low.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(0|1|0?\.\d+)\s*)?\)$/);
    if (m) {
      var r = +m[1], g = +m[2], b = +m[3];
      if (r > 255 || g > 255 || b > 255) { return null; }
      var hx = '#' + [r, g, b].map(function (n) { return ('0' + n.toString(16)).slice(-2); }).join('');
      if (m[4] != null && m[4] !== '') { var a = parseFloat(m[4]); if (a < 0 || a > 1) { return null; } hx += ('0' + Math.round(a * 255).toString(16)).slice(-2); }
      return hx;
    }
    return Object.prototype.hasOwnProperty.call(NAMED, low) ? NAMED[low] : null;
  }

  // ---- style value validation (mirrors Phase-1 OperationValidator, style subset) ----
  function validateStyle(prop, value) {
    if (prop === 'color' || prop === 'background-color') {
      var hex = normalizeColor(value);
      return hex === null ? [false, null, REASON.INVALID_COLOR] : [true, hex, null];
    }
    var s = String(value == null ? '' : value).trim();
    var low = s.toLowerCase();
    for (var i = 0; i < UNSAFE.length; i++) { if (low.indexOf(UNSAFE[i]) !== -1) { return [false, null, REASON.UNSAFE_VALUE]; } }
    if (prop === 'opacity') {
      if (!/^-?\d+(\.\d+)?$/.test(s)) { return [false, null, REASON.INVALID_VALUE]; }
      var o = parseFloat(s); if (o < 0 || o > 1) { return [false, null, REASON.INVALID_VALUE]; } return [true, s, null];
    }
    if (prop === 'font-size') {
      var fm = s.match(/^(-?\d+(?:\.\d+)?)([a-z%]+)$/);
      if (!fm) { return [false, null, REASON.INVALID_VALUE]; }
      if (STYLE_UNITS.indexOf(fm[2]) === -1) { return [false, null, REASON.INVALID_UNIT]; }
      return [true, s, null];
    }
    if (prop === 'text-align') {
      return ['left', 'center', 'right', 'justify'].indexOf(low) === -1 ? [false, null, REASON.INVALID_VALUE] : [true, low, null];
    }
    if (prop === 'font-weight') {
      if (/[\x00-\x1f]/.test(s)) { return [false, null, REASON.INVALID_VALUE]; }
      return [true, s, null];
    }
    return [false, null, REASON.UNSUPPORTED_PROPERTY];
  }

  // ---- targeted HTML-string document (mirrors Phase-4B RawHtmlDocument) ----
  function StringDoc(html) { this.html = String(html == null ? '' : html); }
  StringDoc.prototype.sanitize = function (h) {
    return h.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
      .replace(/\s+contenteditable(\s*=\s*("[^"]*"|'[^']*'|[^\s>]+))?/gi, '')
      .replace(/\s+spellcheck(\s*=\s*("[^"]*"|'[^']*'|[^\s>]+))?/gi, '')
      .replace(/\s+on[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
  };
  StringDoc.prototype.payload = function () { return this.sanitize(this.html); };
  StringDoc.prototype.version = function () {
    return 'h:' + hash16(this.sanitize(this.html.replace(/\r\n/g, '\n').trim())).slice(0, 16);
  };
  StringDoc.prototype.count = function (field) {
    var re = new RegExp('\\bdata-field="' + field.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '"', 'g');
    return (this.html.match(re) || []).length;
  };
  StringDoc.prototype._open = function (field) {
    var q = field.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var re = new RegExp('<([a-zA-Z][\\w-]*)\\b([^>]*\\bdata-field="' + q + '"[^>]*)>');
    var m = re.exec(this.html);
    if (!m) { return null; }
    return { tag: m[1], attrs: m[2], start: m.index, end: m.index + m[0].length };
  };
  StringDoc.prototype._innerRange = function (field) {
    var ot = this._open(field); if (!ot) { return null; }
    var close = '</' + ot.tag; var pos = this.html.toLowerCase().indexOf(close.toLowerCase(), ot.end);
    if (pos === -1) { return null; }
    return { start: ot.end, endPos: pos, inner: this.html.slice(ot.end, pos) };
  };
  StringDoc.prototype.isLeaf = function (field) {
    var r = this._innerRange(field); return !!r && r.inner.indexOf('<') === -1;
  };
  StringDoc.prototype.getText = function (field) {
    var r = this._innerRange(field); return r === null ? null : decodeText(r.inner);
  };
  StringDoc.prototype.setText = function (field, text) {
    var r = this._innerRange(field); if (!r || r.inner.indexOf('<') !== -1) { return false; }
    var esc = escapeText(text); if (esc === r.inner) { return false; }
    this.html = this.html.slice(0, r.start) + esc + this.html.slice(r.endPos); return true;
  };
  StringDoc.prototype._styleAttr = function (field) {
    var ot = this._open(field); if (!ot) { return ''; }
    var m = ot.attrs.match(/\bstyle="([^"]*)"/i); return m ? m[1] : '';
  };
  StringDoc.prototype._parseStyle = function (str) {
    var out = {}; String(str).split(';').forEach(function (d) {
      d = d.trim(); if (!d || d.indexOf(':') === -1) { return; }
      var idx = d.indexOf(':'); out[d.slice(0, idx).trim().toLowerCase()] = d.slice(idx + 1).trim();
    }); return out;
  };
  StringDoc.prototype._serializeStyle = function (decls) {
    return Object.keys(decls).map(function (k) { return k + ':' + decls[k]; }).join('; ');
  };
  StringDoc.prototype.getStyle = function (field, prop) {
    var d = this._parseStyle(this._styleAttr(field)); var v = d[String(prop).toLowerCase()]; return v == null ? null : v;
  };
  StringDoc.prototype._applyDecls = function (field, decls) {
    var ot = this._open(field); if (!ot) { return false; }
    var st = this._serializeStyle(decls); var newAttrs;
    if (st === '') { newAttrs = ot.attrs.replace(/\s*\bstyle="[^"]*"/i, ''); }
    else if (/\bstyle="[^"]*"/i.test(ot.attrs)) { newAttrs = ot.attrs.replace(/\bstyle="[^"]*"/i, 'style="' + st + '"'); }
    else { newAttrs = ot.attrs.replace(/\s+$/, '') + ' style="' + st + '"'; }
    this.html = this.html.slice(0, ot.start) + '<' + ot.tag + newAttrs + '>' + this.html.slice(ot.end); return true;
  };
  StringDoc.prototype.setStyle = function (field, prop, value) {
    prop = String(prop).toLowerCase(); var d = this._parseStyle(this._styleAttr(field));
    if (d[prop] === value) { return false; } d[prop] = value; return this._applyDecls(field, d);
  };
  StringDoc.prototype.getVisible = function (field) { return this.getStyle(field, 'display') !== 'none'; };
  StringDoc.prototype.setVisible = function (field, visible) {
    var cur = this.getStyle(field, 'display');
    if (visible) { if (cur !== 'none') { return false; } var d = this._parseStyle(this._styleAttr(field)); delete d.display; return this._applyDecls(field, d); }
    if (cur === 'none') { return false; } return this.setStyle(field, 'display', 'none');
  };

  function supportsField(doc, field, path, form) {
    if (doc.count(field) !== 1) { return false; }
    if (path === 'text') { return doc.isLeaf(field); }
    if (path === 'visible') { return true; }
    if (path.indexOf('style.') === 0) { return STYLE_PROPS.indexOf(path.slice(6)) !== -1; }
    return false;
  }

  function capability() {
    return {
      schema_version: SCHEMA_VERSION,
      target_types: ['*'],
      operations: ['replace_text', 'append_text', 'prepend_text', 'set_style', 'hide', 'show'],
      properties: STYLE_PROPS.slice(),
      fields: ['text', 'visible'],
      media_types: [],
      supports_styling: true, supports_state_change: true, supports_batching: true,
      supports_transactions: true, supports_verification: true, supports_versioning: true,
      supports_geometry: false, supports_undo_integration: false, supports_collaboration: false
    };
  }

  // ---- the adapter ----
  function Adapter(html) {
    this.doc = new StringDoc(html);
    this.ledger = {};
  }
  Adapter.prototype.capability = function () { return capability(); };
  Adapter.prototype.version = function () { return this.doc.version(); };
  Adapter.prototype.payload = function () { return this.doc.payload(); };
  Adapter.prototype.markInProgress = function (k) { this.ledger[k] = { status: REPLAY.IN_PROGRESS }; };
  Adapter.prototype.expire = function (k) { this.ledger[k] = { status: REPLAY.EXPIRED }; };

  function reject(req, doc, status, reason, explanation, replay) {
    var actual = {};
    if (doc.count(req.target_id) === 1) { (req.changed_fields || []).forEach(function (p) { actual[p] = getField(doc, p, req.target_id); }); }
    return {
      schema_version: SCHEMA_VERSION, status: status, operation_id: req.operation_id, target_id: req.target_id,
      applied_fields: [], rejected_fields: (req.changed_fields || []).slice(), renderer_version: doc.version(),
      actual_after_state: actual, verification: { verified: false, matched: 0, total: (req.changed_fields || []).length },
      error_reason: reason, explanation: explanation || '', duration_ms: 0, replay: replay || REPLAY.FIRST,
      correlation_id: req.correlation_id || ''
    };
  }
  function getField(doc, path, field) {
    if (path === 'text') { return doc.getText(field); }
    if (path === 'visible') { return doc.getVisible(field); }
    if (path.indexOf('style.') === 0) { return doc.getStyle(field, path.slice(6)); }
    return null;
  }
  function setField(doc, path, field, value) {
    if (path === 'text') { return doc.setText(field, value); }
    if (path === 'visible') { return doc.setVisible(field, !!value); }
    if (path.indexOf('style.') === 0) { return doc.setStyle(field, path.slice(6), value); }
    return false;
  }
  function eq(a, b) {
    if (typeof a === 'boolean' || typeof b === 'boolean') { return !!a === !!b; }
    return String(a) === String(b);
  }

  Adapter.prototype.project = function (req) {
    var doc = this.doc, self = this;
    req = req || {};
    if (req.schema_version != null && req.schema_version !== SCHEMA_VERSION) {
      return reject(req, doc, STATUS.REJECTED, REASON.INVALID_VALUE, 'Unsupported schema version.');
    }
    var key = req.idempotency_key;
    if (key != null && self.ledger[key]) {
      var e = self.ledger[key];
      if (e.status === REPLAY.COMPLETED) { var r = JSON.parse(JSON.stringify(e.result)); r.replay = REPLAY.COMPLETED; return r; }
      if (e.status === REPLAY.IN_PROGRESS) { return reject(req, doc, STATUS.REJECTED, REASON.IN_PROGRESS, 'In progress.', REPLAY.IN_PROGRESS); }
      if (e.status === REPLAY.EXPIRED) { return reject(req, doc, STATUS.REJECTED, REASON.EXPIRED, 'Expired.', REPLAY.EXPIRED); }
    }
    if (req.expected_document_version != null && req.expected_document_version !== doc.version()) {
      return reject(req, doc, STATUS.STALE_VERSION, REASON.STALE_VERSION, 'Version mismatch.');
    }
    var cnt = doc.count(req.target_id);
    if (cnt === 0) { return reject(req, doc, STATUS.TARGET_MISSING, REASON.TARGET_MISSING, 'Target not found.'); }
    if (cnt > 1) { return reject(req, doc, STATUS.REJECTED, REASON.AMBIGUOUS_TARGET, 'Target ambiguous.'); }

    var fields = req.changed_fields || [];
    if (req.before_snapshot_hash != null) {
      var cur = {}; fields.forEach(function (p) { cur[p] = getField(doc, p, req.target_id); });
      if (hashState(cur) !== req.before_snapshot_hash) { return reject(req, doc, STATUS.STALE_VERSION, REASON.SNAPSHOT_MISMATCH, 'Snapshot changed.'); }
    }

    var desired = req.desired_after_state || {};
    var plan = {}, rejected = [], firstReason = null, hadValidation = false;
    fields.forEach(function (p) {
      if (!capability().operations.length || !supportsField(doc, req.target_id, p, 'raw')) {
        if (!supportsField(doc, req.target_id, p, 'raw')) {
          rejected.push(p);
          firstReason = firstReason || (p === 'text' ? REASON.NON_LEAF_TARGET : REASON.UNSUPPORTED_PROPERTY);
          return;
        }
      }
      if (p.indexOf('style.') === 0) {
        var v = validateStyle(p.slice(6), desired[p]);
        if (!v[0]) { rejected.push(p); firstReason = firstReason || v[2]; hadValidation = true; return; }
        plan[p] = v[1];
      } else if (p === 'visible') { plan[p] = !!desired[p]; }
      else { plan[p] = String(desired[p] == null ? '' : desired[p]); }
    });

    var planKeys = Object.keys(plan);
    if (planKeys.length === 0) {
      var st = hadValidation ? STATUS.REJECTED : STATUS.UNSUPPORTED;
      return reject(req, doc, st, firstReason || REASON.UNSUPPORTED_PROPERTY, 'No field applied.');
    }

    var applied = [];
    planKeys.forEach(function (p) { if (setField(doc, p, req.target_id, plan[p])) { applied.push(p); } });
    var actual = {}; fields.forEach(function (p) { actual[p] = getField(doc, p, req.target_id); });
    var matched = 0; applied.forEach(function (p) { if (eq(actual[p], plan[p])) { matched++; } });
    var verified = applied.length > 0 && matched === applied.length;

    var result;
    if (applied.length === 0 && rejected.length === 0) {
      result = build(req, doc, STATUS.FAILED, [], [], actual, false, REASON.NO_CHANGE, 'No change.');
    } else if (!verified) {
      result = build(req, doc, STATUS.FAILED, applied, rejected, actual, false, REASON.VERIFICATION_FAILED, 'Actual != desired.');
    } else if (rejected.length > 0) {
      result = build(req, doc, STATUS.PARTIAL, applied, rejected, actual, true, firstReason, 'Applied supported; rejected the rest.');
    } else {
      result = build(req, doc, STATUS.APPLIED, applied, [], actual, true, null, 'Applied and verified against actual state.');
    }
    if (key != null && (result.status === STATUS.APPLIED || result.status === STATUS.PARTIAL)) {
      self.ledger[key] = { status: REPLAY.COMPLETED, result: JSON.parse(JSON.stringify(result)) };
    }
    return result;
  };

  function build(req, doc, status, applied, rejected, actual, verified, reason, explanation) {
    return {
      schema_version: SCHEMA_VERSION, status: status, operation_id: req.operation_id, target_id: req.target_id,
      applied_fields: applied, rejected_fields: rejected, renderer_version: doc.version(),
      actual_after_state: actual, verification: { verified: verified, matched: applied.length, total: (req.changed_fields || []).length },
      error_reason: verified && (status === STATUS.APPLIED) ? null : reason, explanation: explanation,
      duration_ms: 0, replay: REPLAY.FIRST, correlation_id: req.correlation_id || ''
    };
  }

  var API = {
    SCHEMA_VERSION: SCHEMA_VERSION, STATUS: STATUS, REPLAY: REPLAY, REASON: REASON,
    capability: capability, hashState: hashState, normalizeColor: normalizeColor,
    create: function (html) { return new Adapter(html); }
  };
  if (typeof module !== 'undefined' && module.exports) { module.exports = API; }
  var _g = (typeof window !== 'undefined') ? window
    : (typeof self !== 'undefined') ? self
    : (typeof globalThis !== 'undefined') ? globalThis : null;
  if (_g) { _g.StudioProjectionAdapter = API; }
})();
