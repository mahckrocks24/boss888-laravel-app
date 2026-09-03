/*!
 * LUColorPicker — ARTHUR888 Unit I enterprise colour picker (dependency-free, site-CSS, mobile-first).
 * Replaces the native <input type="color"> in onboarding + is the builder editor's colour tool.
 * Features: SV area + hue + opacity (all custom, no native controls), HEX/RGB/HSL(A) inputs with
 * validation, brand swatches, primary/secondary/accent roles, keyboard + touch (Pointer Events),
 * immediate preview, cancel/revert/save. Theming via the SPA's CSS vars with safe fallbacks.
 *
 * API:  LUColorPicker.open({ colors:{primary,secondary,accent}, swatches:[...],
 *                            onPreview(role,hex), onSave(colors), onCancel() })
 *       LUColorPicker.mount(el, opts)  // inline (non-modal) mount
 */
(function (w) {
  'use strict';
  if (w.LUColorPicker) return;

  // ---- colour-space math (the correctness core; unit-tested separately) ----
  function clamp(n, lo, hi) { return n < lo ? lo : (n > hi ? hi : n); }
  function hexToRgb(hex) {
    hex = String(hex || '').trim().replace(/^#/, '');
    if (/^[0-9a-fA-F]{3}$/.test(hex)) hex = hex.split('').map(function (c) { return c + c; }).join('');
    if (!/^[0-9a-fA-F]{6}$/.test(hex)) return null;
    return { r: parseInt(hex.slice(0, 2), 16), g: parseInt(hex.slice(2, 4), 16), b: parseInt(hex.slice(4, 6), 16) };
  }
  function rgbToHex(r, g, b) {
    function h(n) { n = clamp(Math.round(n), 0, 255).toString(16); return n.length === 1 ? '0' + n : n; }
    return '#' + h(r) + h(g) + h(b);
  }
  function rgbToHsv(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
    var h = 0, s = max === 0 ? 0 : d / max, v = max;
    if (d !== 0) {
      if (max === r) h = ((g - b) / d) % 6;
      else if (max === g) h = (b - r) / d + 2;
      else h = (r - g) / d + 4;
      h *= 60; if (h < 0) h += 360;
    }
    return { h: h, s: s * 100, v: v * 100 };
  }
  function hsvToRgb(h, s, v) {
    h = ((h % 360) + 360) % 360; s /= 100; v /= 100;
    var c = v * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = v - c, r = 0, g = 0, b = 0;
    if (h < 60) { r = c; g = x; } else if (h < 120) { r = x; g = c; }
    else if (h < 180) { g = c; b = x; } else if (h < 240) { g = x; b = c; }
    else if (h < 300) { r = x; b = c; } else { r = c; b = x; }
    return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
  }
  function rgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min, l = (max + min) / 2, h = 0, s = 0;
    if (d !== 0) {
      s = d / (1 - Math.abs(2 * l - 1));
      if (max === r) h = ((g - b) / d) % 6; else if (max === g) h = (b - r) / d + 2; else h = (r - g) / d + 4;
      h *= 60; if (h < 0) h += 360;
    }
    return { h: h, s: s * 100, l: l * 100 };
  }
  function parseAny(str) {
    str = String(str || '').trim();
    var m;
    if ((m = str.match(/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/))) { var c = hexToRgb(m[1]); return c ? { rgb: c, a: 1 } : null; }
    if ((m = str.match(/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s/]+([\d.]+%?))?\s*\)$/i))) {
      var a = m[4] == null ? 1 : (String(m[4]).indexOf('%') >= 0 ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
      return { rgb: { r: clamp(+m[1], 0, 255), g: clamp(+m[2], 0, 255), b: clamp(+m[3], 0, 255) }, a: clamp(a, 0, 1) };
    }
    if ((m = str.match(/^hsla?\(\s*([\d.]+)[,\s]+([\d.]+)%?[,\s]+([\d.]+)%?(?:[,\s/]+([\d.]+%?))?\s*\)$/i))) {
      var hh = +m[1], ss = clamp(+m[2], 0, 100) / 100, ll = clamp(+m[3], 0, 100) / 100;
      var q = ll < 0.5 ? ll * (1 + ss) : ll + ss - ll * ss, p = 2 * ll - q;
      function t(tc) { tc = ((tc % 1) + 1) % 1; if (tc < 1 / 6) return p + (q - p) * 6 * tc; if (tc < 1 / 2) return q; if (tc < 2 / 3) return p + (q - p) * (2 / 3 - tc) * 6; return p; }
      var aa = m[4] == null ? 1 : (String(m[4]).indexOf('%') >= 0 ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
      return { rgb: { r: t(hh / 360 + 1 / 3) * 255, g: t(hh / 360) * 255, b: t(hh / 360 - 1 / 3) * 255 }, a: clamp(aa, 0, 1) };
    }
    return null;
  }
  LUColorPicker._math = { hexToRgb: hexToRgb, rgbToHex: rgbToHex, rgbToHsv: rgbToHsv, hsvToRgb: hsvToRgb, rgbToHsl: rgbToHsl, parseAny: parseAny };

  function LUColorPicker() {}
  var DEFAULT_SWATCHES = ['#6C5CE7', '#00E5A8', '#F4F7FB', '#0F172A', '#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#EC4899', '#8B5CF6', '#14B8A6', '#F97316'];

  function css() {
    if (document.getElementById('lucp-css')) return;
    var s = document.createElement('style'); s.id = 'lucp-css';
    s.textContent = [
      '.lucp{--bg:var(--s1,#1a1a24);--pl:var(--s2,rgba(255,255,255,.06));--bd:var(--s3,rgba(255,255,255,.14));--tx:var(--t1,#fff);--t2c:var(--t2,rgba(255,255,255,.6));--ac:var(--pu,#6C5CE7);font-family:var(--fb,system-ui,-apple-system,sans-serif);color:var(--tx);width:100%;max-width:340px;box-sizing:border-box}',
      '.lucp *{box-sizing:border-box}',
      '.lucp-roles{display:flex;gap:6px;margin-bottom:12px}',
      '.lucp-role{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;padding:8px 4px;border:1px solid var(--bd);border-radius:10px;background:var(--pl);cursor:pointer;font-size:11px;color:var(--t2c);transition:border-color .15s,background .15s}',
      '.lucp-role[aria-selected="true"]{border-color:var(--ac);background:rgba(108,92,231,.10);color:var(--tx)}',
      '.lucp-role:focus-visible{outline:2px solid var(--ac);outline-offset:2px}',
      '.lucp-chip{width:26px;height:26px;border-radius:7px;border:1px solid rgba(0,0,0,.25);box-shadow:inset 0 0 0 1px rgba(255,255,255,.15)}',
      '.lucp-sv{position:relative;width:100%;height:150px;border-radius:12px;overflow:hidden;cursor:crosshair;touch-action:none;border:1px solid var(--bd)}',
      '.lucp-sv-sat{position:absolute;inset:0;background:linear-gradient(to right,#fff,rgba(255,255,255,0))}',
      '.lucp-sv-val{position:absolute;inset:0;background:linear-gradient(to top,#000,rgba(0,0,0,0))}',
      '.lucp-knob{position:absolute;width:16px;height:16px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.4),0 1px 3px rgba(0,0,0,.5);transform:translate(-50%,-50%);pointer-events:none}',
      '.lucp-sliders{margin-top:12px;display:flex;flex-direction:column;gap:12px}',
      '.lucp-slider{position:relative;height:16px;border-radius:8px;touch-action:none;cursor:pointer;border:1px solid var(--bd)}',
      '.lucp-hue{background:linear-gradient(to right,#f00,#ff0,#0f0,#0ff,#00f,#f0f,#f00)}',
      '.lucp-alpha{background-image:linear-gradient(45deg,#888 25%,transparent 25%),linear-gradient(-45deg,#888 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#888 75%),linear-gradient(-45deg,transparent 75%,#888 75%);background-size:10px 10px;background-position:0 0,0 5px,5px -5px,-5px 0}',
      '.lucp-alpha-fill{position:absolute;inset:0;border-radius:8px}',
      '.lucp-shandle{position:absolute;top:50%;width:16px;height:16px;border-radius:50%;background:#fff;border:1px solid rgba(0,0,0,.4);box-shadow:0 1px 3px rgba(0,0,0,.5);transform:translate(-50%,-50%);pointer-events:none}',
      '.lucp-slider:focus-visible{outline:2px solid var(--ac);outline-offset:2px}',
      '.lucp-inputs{display:grid;grid-template-columns:1.4fr 1fr;gap:8px;margin-top:12px}',
      '.lucp-fld{display:flex;flex-direction:column;gap:3px}',
      '.lucp-fld label{font-size:10px;color:var(--t2c);text-transform:uppercase;letter-spacing:.04em}',
      '.lucp-in{background:var(--pl);border:1px solid var(--bd);border-radius:8px;color:var(--tx);padding:8px 9px;font:600 13px/1 var(--fb,system-ui);width:100%;font-variant-numeric:tabular-nums}',
      '.lucp-in:focus{outline:none;border-color:var(--ac)}',
      '.lucp-in.lucp-bad{border-color:#EF4444}',
      '.lucp-mode{display:flex;gap:4px;margin-top:10px}',
      '.lucp-mode button{flex:1;background:var(--pl);border:1px solid var(--bd);color:var(--t2c);border-radius:7px;padding:6px;font-size:11px;font-weight:600;cursor:pointer}',
      '.lucp-mode button[aria-pressed="true"]{border-color:var(--ac);color:var(--tx);background:rgba(108,92,231,.10)}',
      '.lucp-swatches{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}',
      '.lucp-sw{width:24px;height:24px;border-radius:6px;cursor:pointer;border:1px solid rgba(0,0,0,.25);box-shadow:inset 0 0 0 1px rgba(255,255,255,.12)}',
      '.lucp-sw:hover,.lucp-sw:focus-visible{transform:scale(1.12);outline:2px solid var(--ac);outline-offset:1px}',
      '.lucp-actions{display:flex;gap:8px;margin-top:16px}',
      '.lucp-btn{flex:1;padding:10px;border-radius:9px;font:600 13px/1 var(--fb,system-ui);cursor:pointer;border:1px solid var(--bd);background:var(--pl);color:var(--tx)}',
      '.lucp-btn--save{background:var(--ac);border-color:var(--ac);color:#fff}',
      '.lucp-btn--ghost{background:transparent;color:var(--t2c)}',
      '.lucp-ov{position:fixed;inset:0;z-index:100001;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);display:flex;align-items:flex-end;justify-content:center;padding:0}',
      '.lucp-sheet{background:var(--s1,#1a1a24);border:1px solid var(--bd);border-top-left-radius:20px;border-top-right-radius:20px;padding:18px 18px 22px;width:100%;max-width:420px;max-height:92vh;overflow:auto}',
      '@media(min-width:600px){.lucp-ov{align-items:center;padding:20px}.lucp-sheet{border-radius:18px}}',
      '.lucp-title{font-size:15px;font-weight:700;margin:0 0 14px}'
    ].join('');
    document.head.appendChild(s);
  }

  function build(host, opts) {
    css();
    opts = opts || {};
    var roles = ['primary', 'secondary', 'accent'];
    var colors = {
      primary: (opts.colors && opts.colors.primary) || '#6C5CE7',
      secondary: (opts.colors && opts.colors.secondary) || '#00E5A8',
      accent: (opts.colors && opts.colors.accent) || '#F4F7FB'
    };
    var orig = JSON.parse(JSON.stringify(colors));
    var swatches = opts.swatches || DEFAULT_SWATCHES;
    var role = 'primary', mode = 'hex';
    var hsv = { h: 0, s: 0, v: 0 }, alpha = 1;

    var root = document.createElement('div'); root.className = 'lucp'; root.setAttribute('role', 'group'); root.setAttribute('aria-label', 'Colour picker');
    root.innerHTML =
      '<div class="lucp-roles">' + roles.map(function (r) {
        return '<div class="lucp-role" tabindex="0" role="tab" data-role="' + r + '" aria-selected="' + (r === role) + '"><span class="lucp-chip" data-chip="' + r + '"></span>' + r.charAt(0).toUpperCase() + r.slice(1) + '</div>';
      }).join('') + '</div>' +
      '<div class="lucp-sv" tabindex="0" role="slider" aria-label="Saturation and brightness"><div class="lucp-sv-sat"></div><div class="lucp-sv-val"></div><div class="lucp-knob"></div></div>' +
      '<div class="lucp-sliders">' +
        '<div class="lucp-slider lucp-hue" tabindex="0" role="slider" aria-label="Hue" aria-valuemin="0" aria-valuemax="360"><div class="lucp-shandle" data-h="hue"></div></div>' +
        '<div class="lucp-slider lucp-alpha" tabindex="0" role="slider" aria-label="Opacity" aria-valuemin="0" aria-valuemax="100"><div class="lucp-alpha-fill"></div><div class="lucp-shandle" data-h="alpha"></div></div>' +
      '</div>' +
      '<div class="lucp-mode"><button data-mode="hex" aria-pressed="true">HEX</button><button data-mode="rgb" aria-pressed="false">RGB</button><button data-mode="hsl" aria-pressed="false">HSL</button></div>' +
      '<div class="lucp-inputs"></div>' +
      '<div class="lucp-swatches">' + swatches.map(function (c) { return '<div class="lucp-sw" tabindex="0" role="button" aria-label="' + c + '" data-sw="' + c + '" style="background:' + c + '"></div>'; }).join('') + '</div>' +
      (opts.modal ? '<div class="lucp-actions"><button class="lucp-btn lucp-btn--ghost" data-act="revert">Revert</button><button class="lucp-btn" data-act="cancel">Cancel</button><button class="lucp-btn lucp-btn--save" data-act="save">Save</button></div>' : '');

    host.appendChild(root);
    var svEl = root.querySelector('.lucp-sv'), satEl = root.querySelector('.lucp-sv-sat'), knob = root.querySelector('.lucp-knob');
    var hueEl = root.querySelector('.lucp-hue'), alphaEl = root.querySelector('.lucp-alpha'), alphaFill = root.querySelector('.lucp-alpha-fill');
    var hueH = root.querySelector('[data-h="hue"]'), alphaH = root.querySelector('[data-h="alpha"]');
    var inputsEl = root.querySelector('.lucp-inputs');

    function curHex() { var c = hsvToRgb(hsv.h, hsv.s, hsv.v); return rgbToHex(c.r, c.g, c.b); }
    function loadRole() {
      var p = parseAny(colors[role]) || { rgb: { r: 108, g: 92, b: 231 }, a: 1 };
      hsv = rgbToHsv(p.rgb.r, p.rgb.g, p.rgb.b); alpha = p.a;
      paint();
    }
    function paint() {
      var pure = hsvToRgb(hsv.h, 100, 100);
      satEl.parentNode.style.background = rgbToHex(pure.r, pure.g, pure.b);
      knob.style.left = hsv.s + '%'; knob.style.top = (100 - hsv.v) + '%';
      knob.style.background = curHex();
      hueH.style.left = (hsv.h / 360 * 100) + '%';
      alphaH.style.left = (alpha * 100) + '%';
      var c = hsvToRgb(hsv.h, hsv.s, hsv.v);
      alphaFill.style.background = 'linear-gradient(to right, rgba(' + Math.round(c.r) + ',' + Math.round(c.g) + ',' + Math.round(c.b) + ',0), ' + rgbToHex(c.r, c.g, c.b) + ')';
      root.querySelectorAll('[data-chip]').forEach(function (el) { el.style.background = colors[el.getAttribute('data-chip')]; });
      renderInputs();
      var hex = curHex();
      colors[role] = alpha < 1 ? 'rgba(' + Math.round(c.r) + ',' + Math.round(c.g) + ',' + Math.round(c.b) + ',' + (Math.round(alpha * 100) / 100) + ')' : hex;
      if (opts.onPreview) try { opts.onPreview(role, colors[role]); } catch (e) {}
    }
    function renderInputs() {
      var c = hsvToRgb(hsv.h, hsv.s, hsv.v), hex = curHex();
      var html = '';
      if (mode === 'hex') {
        html = '<div class="lucp-fld" style="grid-column:1/2"><label>Hex</label><input class="lucp-in" data-k="hex" value="' + hex + '" maxlength="7" autocomplete="off" spellcheck="false"></div>' +
               '<div class="lucp-fld"><label>Opacity</label><input class="lucp-in" data-k="a" value="' + Math.round(alpha * 100) + '%"></div>';
      } else if (mode === 'rgb') {
        html = ['r', 'g', 'b'].map(function (k) { return '<div class="lucp-fld"><label>' + k.toUpperCase() + '</label><input class="lucp-in" data-k="' + k + '" value="' + Math.round(c[k]) + '"></div>'; }).join('') +
               '<div class="lucp-fld"><label>A</label><input class="lucp-in" data-k="a" value="' + Math.round(alpha * 100) + '%"></div>';
        inputsEl.style.gridTemplateColumns = 'repeat(4,1fr)';
      } else {
        var hsl = rgbToHsl(c.r, c.g, c.b);
        html = [['h', Math.round(hsl.h)], ['s', Math.round(hsl.s) + '%'], ['l', Math.round(hsl.l) + '%'], ['a', Math.round(alpha * 100) + '%']]
          .map(function (p) { return '<div class="lucp-fld"><label>' + p[0].toUpperCase() + '</label><input class="lucp-in" data-k="' + p[0] + '" value="' + p[1] + '"></div>'; }).join('');
        inputsEl.style.gridTemplateColumns = 'repeat(4,1fr)';
      }
      if (mode === 'hex') inputsEl.style.gridTemplateColumns = '1.4fr 1fr';
      inputsEl.innerHTML = html;
      inputsEl.querySelectorAll('.lucp-in').forEach(function (inp) {
        inp.addEventListener('input', function () { commitInput(inp); });
      });
    }
    function commitInput(inp) {
      var k = inp.getAttribute('data-k'), v = inp.value.trim();
      inp.classList.remove('lucp-bad');
      if (k === 'a') { var a = parseFloat(v.replace('%', '')); if (isNaN(a)) { inp.classList.add('lucp-bad'); return; } alpha = clamp(a / 100, 0, 1); paint(); return; }
      if (mode === 'hex') { var p = parseAny(v); if (!p) { inp.classList.add('lucp-bad'); return; } hsv = rgbToHsv(p.rgb.r, p.rgb.g, p.rgb.b); paint(); return; }
      if (mode === 'rgb') { var c = hsvToRgb(hsv.h, hsv.s, hsv.v); c[k] = clamp(+v, 0, 255); if (isNaN(+v)) { inp.classList.add('lucp-bad'); return; } var nh = rgbToHsv(c.r, c.g, c.b); hsv = nh; paint(); return; }
      // hsl
      var cc = hsvToRgb(hsv.h, hsv.s, hsv.v), hsl = rgbToHsl(cc.r, cc.g, cc.b);
      var num = parseFloat(v); if (isNaN(num)) { inp.classList.add('lucp-bad'); return; }
      if (k === 'h') hsl.h = num; if (k === 's') hsl.s = clamp(num, 0, 100); if (k === 'l') hsl.l = clamp(num, 0, 100);
      var pp = parseAny('hsl(' + hsl.h + ',' + hsl.s + '%,' + hsl.l + '%)'); if (pp) { hsv = rgbToHsv(pp.rgb.r, pp.rgb.g, pp.rgb.b); paint(); }
    }

    // pointer drag helpers (mouse + touch via Pointer Events)
    function drag(el, fn) {
      function move(e) { var r = el.getBoundingClientRect(); var x = clamp((e.clientX - r.left) / r.width, 0, 1), y = clamp((e.clientY - r.top) / r.height, 0, 1); fn(x, y); }
      el.addEventListener('pointerdown', function (e) { el.setPointerCapture(e.pointerId); move(e); function up() { el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); } el.addEventListener('pointermove', move); el.addEventListener('pointerup', up); });
    }
    drag(svEl, function (x, y) { hsv.s = x * 100; hsv.v = (1 - y) * 100; paint(); });
    drag(hueEl, function (x) { hsv.h = x * 360; paint(); });
    drag(alphaEl, function (x) { alpha = x; paint(); });

    // keyboard on sliders
    hueEl.addEventListener('keydown', function (e) { if (e.key === 'ArrowLeft') { hsv.h = (hsv.h - (e.shiftKey ? 10 : 1) + 360) % 360; paint(); e.preventDefault(); } if (e.key === 'ArrowRight') { hsv.h = (hsv.h + (e.shiftKey ? 10 : 1)) % 360; paint(); e.preventDefault(); } });
    alphaEl.addEventListener('keydown', function (e) { if (e.key === 'ArrowLeft') { alpha = clamp(alpha - (e.shiftKey ? 0.1 : 0.01), 0, 1); paint(); e.preventDefault(); } if (e.key === 'ArrowRight') { alpha = clamp(alpha + (e.shiftKey ? 0.1 : 0.01), 0, 1); paint(); e.preventDefault(); } });
    svEl.addEventListener('keydown', function (e) { var st = e.shiftKey ? 10 : 2; if (e.key === 'ArrowRight') hsv.s = clamp(hsv.s + st, 0, 100); else if (e.key === 'ArrowLeft') hsv.s = clamp(hsv.s - st, 0, 100); else if (e.key === 'ArrowUp') hsv.v = clamp(hsv.v + st, 0, 100); else if (e.key === 'ArrowDown') hsv.v = clamp(hsv.v - st, 0, 100); else return; paint(); e.preventDefault(); });

    root.querySelectorAll('.lucp-role').forEach(function (el) {
      function sel() { role = el.getAttribute('data-role'); root.querySelectorAll('.lucp-role').forEach(function (r) { r.setAttribute('aria-selected', r === el); }); loadRole(); }
      el.addEventListener('click', sel);
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { sel(); e.preventDefault(); } });
    });
    root.querySelectorAll('.lucp-mode button').forEach(function (b) {
      b.addEventListener('click', function () { mode = b.getAttribute('data-mode'); root.querySelectorAll('.lucp-mode button').forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); renderInputs(); });
    });
    root.querySelectorAll('.lucp-sw').forEach(function (el) {
      function pick() { var p = parseAny(el.getAttribute('data-sw')); if (p) { hsv = rgbToHsv(p.rgb.r, p.rgb.g, p.rgb.b); alpha = p.a; paint(); } }
      el.addEventListener('click', pick);
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { pick(); e.preventDefault(); } });
    });

    var api = {
      root: root,
      getColors: function () { return JSON.parse(JSON.stringify(colors)); },
      revert: function () { colors = JSON.parse(JSON.stringify(orig)); loadRole(); },
      destroy: function () { if (root.parentNode) root.parentNode.removeChild(root); }
    };
    if (opts.modal) {
      root.querySelector('[data-act="revert"]').addEventListener('click', function () { api.revert(); });
      root.querySelector('[data-act="cancel"]').addEventListener('click', function () { if (opts.onCancel) opts.onCancel(); });
      root.querySelector('[data-act="save"]').addEventListener('click', function () { if (opts.onSave) opts.onSave(api.getColors()); });
    }
    loadRole();
    return api;
  }

  LUColorPicker.mount = function (el, opts) { return build(el, opts || {}); };
  LUColorPicker.open = function (opts) {
    css(); opts = opts || {};
    var ov = document.createElement('div'); ov.className = 'lucp-ov';
    var sheet = document.createElement('div'); sheet.className = 'lucp-sheet';
    sheet.innerHTML = '<h3 class="lucp-title">Brand colours</h3>';
    ov.appendChild(sheet); document.body.appendChild(ov);
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); }
    var api = build(sheet, Object.assign({}, opts, {
      modal: true,
      onSave: function (c) { if (opts.onSave) opts.onSave(c); close(); },
      onCancel: function () { if (opts.onCancel) opts.onCancel(); close(); }
    }));
    ov.addEventListener('click', function (e) { if (e.target === ov) { if (opts.onCancel) opts.onCancel(); close(); } });
    document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); } });
    return api;
  };

  w.LUColorPicker = LUColorPicker;
})(window);
