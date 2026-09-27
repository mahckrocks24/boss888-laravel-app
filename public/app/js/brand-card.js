/* BRAND-B1 (RFC-0017 5d, 2026-09-27) — the brand cards Sarah posts in her thread, and the Brand styles panel in Settings.
 *   brand_directions: the ten design directions rendered as real mini-banners with the business's own name, headline,
 *                     colours and photo; the owner taps 3-4 in order of preference and may mark any as "never".
 *   brand_summary:    what Sarah extracted from uploaded guidelines, logos, fonts or example posts, to Save or Discard.
 * Any element with class lu-brand-slot and data-card="<uri-encoded JSON>" is hydrated automatically (both chat views).
 */
(function () {
  'use strict';
  if (window.LU_brandCard) return;
  var API = (window.API || '/api/');
  function api(method, path, body) {
    var h = { 'Accept': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') };
    if (body) h['Content-Type'] = 'application/json';
    return fetch(API + path, { method: method, headers: h, body: body ? JSON.stringify(body) : undefined, cache: 'no-store' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; }); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(m, k) { if (typeof window.showToast === 'function') window.showToast(m, k || 'info'); }

  /* ── fonts for the previews (once) ── */
  var fontsLoaded = false;
  function loadFonts() {
    if (fontsLoaded) return; fontsLoaded = true;
    var l = document.createElement('link'); l.rel = 'stylesheet';
    l.href = 'https://fonts.googleapis.com/css2?family=Anton&family=Archivo+Black&family=Baloo+2:wght@600;800&family=Bebas+Neue&family=Cormorant+Garamond:wght@500;600&family=DM+Sans:wght@500;700&family=Fraunces:opsz,wght@9..144,600;9..144,800&family=Space+Grotesk:wght@500;700&display=swap';
    document.head.appendChild(l);
  }
  function loadFamily(name) {
    name = String(name || '').split(',')[0].replace(/['"]/g, '').trim();
    if (!name || /^(inter|system-ui|sans-serif|serif|arial|helvetica)$/i.test(name) || document.querySelector('link[data-lubf="' + name + '"]')) return;
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.setAttribute('data-lubf', name);
    l.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(name).replace(/%20/g, '+') + ':wght@400;700&display=swap';
    document.head.appendChild(l);
  }

  /* ── colour helpers ── */
  function rgb(h) { h = String(h || '').replace('#', ''); if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; if (!/^[0-9a-f]{6}$/i.test(h)) return null; return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)]; }
  function lum(h) { var c = rgb(h); if (!c) return 0.5; var a = c.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2]; }
  function sat(h) { var c = rgb(h); if (!c) return 0; var mx = Math.max.apply(0, c), mn = Math.min.apply(0, c); return mx === 0 ? 0 : (mx - mn) / mx; }
  function mix(h, w, t) { var a = rgb(h) || [80, 80, 80], b = rgb(w) || [255, 255, 255]; return '#' + a.map(function (v, i) { return ('0' + Math.round(v * (1 - t) + b[i] * t).toString(16)).slice(-2); }).join(''); }
  function palette(c) {
    var list = [c.primary, c.secondary, c.accent].filter(function (x) { return !!rgb(x); });
    if (!list.length) list = ['#1F2937', '#C9943A', '#F2EBDF'];
    var byL = list.slice().sort(function (a, b) { return lum(a) - lum(b); });
    var vivid = list.slice().sort(function (a, b) { return sat(b) - sat(a); });
    var accentOnDark = vivid.filter(function (x) { return lum(x) > 0.12 && sat(x) > 0.25; })[0] || '#D9A441';
    var accentOnLight = vivid.filter(function (x) { return lum(x) < 0.45; })[0] || byL[0];
    var block = vivid[0]; if (lum(block) > 0.6) block = mix(block, '#000000', 0.45);
    return { dark: lum(byL[0]) < 0.08 ? byL[0] : mix(byL[0], '#000000', 0.7), light: lum(byL[byL.length - 1]) > 0.75 ? byL[byL.length - 1] : '#F6F4EF', accentOnDark: accentOnDark, accentOnLight: accentOnLight, block: block, all: list };
  }
  function splitLast(h) { var w = String(h || '').trim().split(/\s+/); if (w.length < 2) return [esc(h), '']; var last = w.pop(); return [esc(w.join(' ')), esc(last)]; }

  /* ── one mini banner per direction, with the business's own material ── */
  function tile(d, pv) {
    var p = palette(pv.brand_set === false ? {} : (pv.colors || {})), photo = pv.photo ? String(pv.photo) : '', ph = photo ? 'url(\'' + photo.replace(/['"()\s]/g, function (c) { return '%' + c.charCodeAt(0).toString(16).toUpperCase(); }) + '\')' : 'none';
    var h = pv.headline || pv.business_name || '', n = esc(pv.business_name || ''), e = esc(pv.eyebrow || ''), f = (d.preview && d.preview.font) || 'inherit';
    var hl = splitLast(h), upper = d.preview && d.preview.case === 'upper';
    var k = Math.max(0.5, Math.min(1, Math.sqrt(16 / Math.max(String(h).length, 1))));   // longer headlines set smaller, like a designer would
    var sz = function (base) { return 'font-size:' + (base * k).toFixed(2) + 'em;'; };
    var H = function (style, inner, lines) { return '<div class="lbc-h" style="font-family:' + f + ';' + (upper ? 'text-transform:uppercase;' : '') + '-webkit-line-clamp:' + (lines || 4) + ';' + style + '">' + inner + '</div>'; };
    var g = (d.preview && d.preview.ground) || 'light', body = '';
    switch (g) {
      case 'dark_fade':
        body = '<div class="lbc-bg" style="background-image:linear-gradient(90deg,' + p.dark + 'F2 0%,' + p.dark + 'B8 50%,' + p.dark + '00 88%),' + ph + ';background-color:' + p.dark + '"></div>' +
          '<div class="lbc-in" style="justify-content:center">' + H(sz(1.7) + 'color:#fff;line-height:.98;letter-spacing:.01em;max-width:86%', hl[0] + ' <span style="color:' + p.accentOnDark + '">' + hl[1] + '</span>') +
          '<i class="lbc-rule" style="background:' + p.accentOnDark + '"></i><div class="lbc-foot" style="color:#fff;position:absolute;left:9%;bottom:8%">' + n + '</div></div>'; break;
      case 'light':
        body = '<div class="lbc-bg" style="background:' + p.light + '"></div><div class="lbc-in">' + (e ? '<div class="lbc-eb" style="color:' + p.accentOnLight + '">' + e + '</div>' : '') +
          H(sz(1.15) + 'color:#16181D;font-weight:700;line-height:1.1', esc(h), 3) + '<i class="lbc-accbar" style="background:' + p.accentOnLight + '"></i>' +
          '<div class="lbc-ph" style="flex:1 1 auto;min-height:34%;background-image:' + ph + ';background-color:' + mix(p.accentOnLight, '#ffffff', .8) + '"></div><div class="lbc-foot" style="color:#16181D">' + n + '</div></div>'; break;
      case 'brand_block':
        body = '<div class="lbc-bg" style="background:' + p.block + '"></div><div class="lbc-cut" style="background-image:' + ph + ';border-color:#fff"></div><div class="lbc-in">' +
          H(sz(1.6) + 'color:#fff;line-height:.95;max-width:74%', esc(h), 4) + '<span class="lbc-pill" style="background:#fff;color:' + p.block + '">Book now</span><div class="lbc-foot" style="color:#fff">' + n + '</div></div>'; break;
      case 'paper':
        body = '<div class="lbc-bg lbc-paper" style="background-color:' + mix(p.light, '#E9DCC6', .55) + '"></div><div class="lbc-in"><div class="lbc-ph lbc-round" style="flex:0 0 42%;margin:0 0 .5em;background-image:' + ph + ';background-color:#d9ccb6"></div>' +
          H(sz(1.2) + 'color:#2B2118;font-weight:800;line-height:1.06', hl[0] + ' <span style="color:' + p.accentOnLight + ';font-style:italic">' + hl[1] + '</span>', 3) +
          '<svg class="lbc-swash" viewBox="0 0 100 8" preserveAspectRatio="none"><path d="M2 6 C 30 1, 60 1, 98 5" stroke="' + p.accentOnLight + '" stroke-width="2" fill="none" stroke-linecap="round"/></svg><div class="lbc-foot" style="color:#2B2118">' + n + '</div></div>'; break;
      case 'photo':
        body = '<div class="lbc-bg" style="background-image:' + ph + ';background-color:#555"></div><div class="lbc-bg" style="background:linear-gradient(0deg,rgba(0,0,0,.45),transparent 45%)"></div><div class="lbc-in" style="justify-content:flex-end"><div class="lbc-label" style="border-left:3px solid ' + p.accentOnDark + '">' + esc(h.length > 42 ? h.slice(0, 40) + '…' : h) + '</div><div class="lbc-foot" style="color:#fff;margin-top:0">' + n + '</div></div>'; break;
      case 'blur':
        body = '<div class="lbc-bg" style="background-image:' + ph + ';background-color:#777;filter:blur(3px) saturate(1.1);transform:scale(1.08)"></div><div class="lbc-bg" style="background:radial-gradient(circle at 30% 30%,' + p.all[0] + '99,transparent 60%),radial-gradient(circle at 75% 70%,' + (p.all[1] || p.all[0]) + '88,transparent 60%);mix-blend-mode:soft-light"></div><div class="lbc-bg" style="background:rgba(0,0,0,.18)"></div>' +
          '<div class="lbc-bg lbc-grain"></div><div class="lbc-in" style="justify-content:center;align-items:center;text-align:center">' + H(sz(1.4) + 'color:#fff;font-weight:500;letter-spacing:.04em;line-height:1.08;text-shadow:0 0 18px rgba(255,255,255,.5)', esc(h)) + '<div class="lbc-foot" style="color:#fff;letter-spacing:.2em;text-transform:uppercase;font-size:.4em;position:absolute;bottom:8%;left:0;right:0">' + n + '</div></div>'; break;
      case 'neon':
        body = '<div class="lbc-bg" style="background:radial-gradient(circle at 80% 20%,' + p.accentOnDark + '55,transparent 55%),radial-gradient(circle at 10% 90%,' + (p.all[1] || p.accentOnDark) + '44,transparent 50%),#07060B"></div><div class="lbc-neonframe" style="border-color:' + p.accentOnDark + ';box-shadow:0 0 10px ' + p.accentOnDark + ',inset 0 0 10px ' + p.accentOnDark + '"></div>' +
          '<div class="lbc-in" style="justify-content:center;padding:14%">' + H(sz(1.6) + 'color:#fff;line-height:1;text-shadow:0 0 6px ' + p.accentOnDark + ',0 0 16px ' + p.accentOnDark + ',0 0 30px ' + p.accentOnDark, esc(h)) + '<div class="lbc-foot" style="color:' + p.accentOnDark + ';position:absolute;left:14%;bottom:11%">' + n + '</div></div>'; break;
      case 'collage':
        body = '<div class="lbc-bg" style="background:#F4F1EA"></div><div class="lbc-scrap" style="background:' + p.all[0] + ';transform:rotate(-8deg);left:-6%;top:6%"></div><div class="lbc-scrap" style="background:' + (p.all[1] || p.accentOnLight) + ';transform:rotate(6deg);right:-8%;top:40%"></div>' +
          '<div class="lbc-polaroid" style="background-image:' + ph + ';background-color:#bbb"></div><div class="lbc-in" style="justify-content:flex-end">' + H(sz(1.0) + 'line-height:1.18', '<span class="lbc-hi">' + esc(h) + '</span>', 3) + '<div class="lbc-foot" style="color:#111;margin-top:.5em">' + n + '</div></div>'; break;
      case 'pastel':
        var pb = mix(p.all[0], '#ffffff', .8);
        body = '<div class="lbc-bg" style="background:' + pb + '"></div><div class="lbc-blob" style="background:' + mix(p.accentOnLight, '#ffffff', .45) + '"></div><div class="lbc-circle" style="background-image:' + ph + ';background-color:#ccc;border-color:#1b1b1b"></div>' +
          '<div class="lbc-in">' + H(sz(1.35) + 'color:#1b1b1b;font-weight:800;line-height:1;max-width:80%', esc(h), 3) + '<span class="lbc-pill" style="background:' + p.accentOnLight + ';color:#fff;border:2px solid #1b1b1b">Say hi!</span><div class="lbc-foot" style="color:#1b1b1b">' + n + '</div></div>'; break;
      case 'grid':
        body = '<div class="lbc-bg lbc-grid" style="background-color:' + mix(p.dark, '#0B1A2E', .5) + '"></div><div class="lbc-mono" style="position:absolute;right:8%;top:9%;width:44%;height:32%;background-image:' + ph + ';background-color:#445;background-size:cover;background-position:center;z-index:1"></div>' +
          '<div class="lbc-in"><div class="lbc-stat" style="color:' + p.accentOnDark + '">01</div><div style="flex:1"></div>' + H(sz(1.1) + 'color:#EAF0F7;font-weight:700;line-height:1.12;max-width:92%', esc(h), 3) + '<div class="lbc-mono-l">— ' + esc((pv.industry || 'studio').toUpperCase().slice(0, 28)) + '</div><div class="lbc-foot" style="color:#EAF0F7;margin-top:.6em">' + n + '</div></div>'; break;
    }
    return '<div class="lbc-art">' + body + '</div>';
  }


  var CSS = '.lbc{align-self:stretch;max-width:min(760px,100%);background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg,14px);padding:14px;color:var(--t1);font:14px/1.45 var(--fb,inherit);margin:6px 0}' +
    '.lbc-t{font-weight:700;font-size:15px;margin:0 0 2px}.lbc-s{color:var(--t3);font-size:12.5px;margin:0 0 12px}' +
    '.lbc-grid10{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:10px}' +
    '.lbc-opt{position:relative;border:2px solid transparent;border-radius:12px;padding:0;background:transparent;text-align:left;cursor:pointer;color:inherit;font:inherit}' +
    '.lbc-opt:focus-visible{outline:2px solid var(--p);outline-offset:2px}.lbc-opt.on{border-color:var(--p);box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 25%,transparent)}.lbc-opt.never .lbc-art{opacity:.35;filter:grayscale(1)}' +
    '.lbc-art{position:relative;aspect-ratio:4/5;border-radius:10px;overflow:hidden;font-size:clamp(15px,4.2vw,19px);isolation:isolate;background:#222}' +
    '.lbc-bg{position:absolute;inset:0;background-size:cover;background-position:center;z-index:0}.lbc-in{position:absolute;inset:0;padding:9% 9% 8%;display:flex;flex-direction:column;z-index:2}' +
    '.lbc-h{flex:none;word-break:break-word;overflow:hidden;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical}.lbc-eb{font:700 .42em/1.2 var(--fb,inherit);letter-spacing:.14em;text-transform:uppercase;margin-bottom:.4em}' +
    '.lbc-rule{display:block;width:28%;height:2px;margin:.7em 0 0}.lbc-accbar{display:block;flex:none;width:22%;height:4px;border-radius:2px;margin:.55em 0}.lbc-foot{margin-top:auto;font:700 .42em/1.2 var(--fb,inherit);letter-spacing:.04em;opacity:.95}' +
    '.lbc-ph{background-size:cover;background-position:center;border-radius:8px;flex:1;margin:.5em 0 .6em}.lbc-round{flex:none;border-radius:10px}' +
    '.lbc-cut{position:absolute;right:-10%;bottom:-4%;width:58%;aspect-ratio:1;border-radius:50%;background-size:cover;background-position:center;border:4px solid;z-index:1}' +
    '.lbc-pill{align-self:flex-start;margin-top:.6em;padding:.35em .9em;border-radius:99px;font:800 .45em/1 var(--fb,inherit);text-transform:uppercase;letter-spacing:.06em}' +
    '.lbc-paper{background-image:radial-gradient(rgba(0,0,0,.05) 1px,transparent 1px);background-size:4px 4px}.lbc-swash{width:40%;height:8px;margin-top:2px}' +
    '.lbc-label{align-self:flex-start;background:rgba(255,255,255,.94);color:#111;font:700 .5em/1.25 var(--fb,inherit);padding:.45em .7em;border-radius:3px;max-width:88%;margin-bottom:.5em}' +
    '.lbc-grain{background-image:radial-gradient(rgba(255,255,255,.08) 1px,transparent 1px);background-size:3px 3px;mix-blend-mode:overlay}' +
    '.lbc-neonframe{position:absolute;inset:6%;border:2px solid;border-radius:6px;z-index:1}' +
    '.lbc-scrap{position:absolute;width:62%;height:26%;z-index:1;opacity:.95;clip-path:polygon(0 8%,12% 0,30% 6%,52% 0,70% 5%,88% 0,100% 7%,98% 92%,80% 100%,60% 94%,38% 100%,18% 94%,0 100%)}' +
    '.lbc-polaroid{position:absolute;left:16%;top:8%;width:62%;height:44%;background-size:cover;background-position:center;border:5px solid #fff;box-shadow:0 4px 10px rgba(0,0,0,.25);transform:rotate(-4deg);z-index:1}' +
    '.lbc-hi{background:#111;color:#fff;padding:.08em .3em;box-decoration-break:clone;-webkit-box-decoration-break:clone;line-height:1.5}' +
    '.lbc-blob{position:absolute;width:80%;aspect-ratio:1;border-radius:46% 54% 60% 40%;right:-24%;top:-18%;z-index:1}.lbc-circle{position:absolute;width:40%;aspect-ratio:1;border-radius:50%;right:8%;bottom:12%;border:3px solid;background-size:cover;background-position:center;z-index:1}' +
    '.lbc-grid{background-image:linear-gradient(rgba(255,255,255,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.07) 1px,transparent 1px);background-size:12px 12px}' +
    '.lbc-mono{filter:grayscale(1) contrast(1.1);border:1px solid rgba(255,255,255,.5)}.lbc-stat{font:700 1.6em/1 "Space Grotesk",monospace}.lbc-mono-l{font:500 .42em/1.3 "Space Grotesk",monospace;color:#9fb2c8;margin-top:.5em;letter-spacing:.08em}' +
    '.lbc-cap{padding:7px 2px 2px}.lbc-cr{display:flex;align-items:flex-start;justify-content:space-between;gap:6px}.lbc-cap b{font-size:13px;display:flex;align-items:center;gap:6px;line-height:1.25}.lbc-cap span{display:block;color:var(--t3);font-size:11.5px;line-height:1.35;margin-top:2px}' +
    '.lbc-num{flex:none;min-width:20px;height:20px;border-radius:10px;background:var(--p);color:#fff;font:700 12px/24px var(--fb,inherit);text-align:center;line-height:20px;font-size:11px}' +
    '.lbc-nv{margin-top:6px;border:1px solid var(--bd);border-radius:99px;padding:2px 8px;min-height:24px;font:600 11px/1.4 var(--fb,inherit);background:transparent;color:var(--t3);cursor:pointer}.lbc-nv:hover{color:var(--t1);border-color:var(--t3)}.lbc-opt.never .lbc-nv{background:#C0392B;border-color:#C0392B;color:#fff}' +
    '.lbc-acts{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}.lbc-btn{min-height:40px;padding:0 16px;border-radius:var(--r,10px);font:600 13px var(--fb,inherit);cursor:pointer;border:1px solid var(--bd2,var(--bd));background:transparent;color:var(--t1)}' +
    '.lbc-btn.primary{background:var(--p);border-color:var(--p);color:#fff}.lbc-btn[disabled]{opacity:.5;cursor:default}.lbc-hint{color:var(--t3);font-size:12px;flex:1 1 220px}' +
    '.lbc-done{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.lbc-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--bd);border-radius:99px;padding:4px 10px;font-size:12.5px;background:var(--s2)}' +
    '.lbc-sw{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 10px}.lbc-swc{display:flex;flex-direction:column;align-items:center;gap:4px;font:600 11px var(--fb,inherit);color:var(--t2)}.lbc-swc i{width:42px;height:42px;border-radius:10px;border:1px solid var(--bd)}' +
    '.lbc-row{display:flex;gap:10px;align-items:baseline;margin:6px 0}.lbc-row>b{min-width:72px;color:var(--t3);font-size:12px;font-weight:600}' +
    '.lbc-logo{width:120px;height:72px;border-radius:10px;border:1px solid var(--bd);background:repeating-conic-gradient(#e9e9e9 0 25%,#fff 0 50%) 0 0/14px 14px;display:flex;align-items:center;justify-content:center;overflow:hidden}.lbc-logo img{max-width:90%;max-height:90%}' +
    '.lbc-rules{margin:4px 0 0;padding-left:18px}.lbc-rules li{margin:2px 0}.lbc-x{border:0;background:transparent;color:var(--t3);cursor:pointer;font-size:12px;margin-left:6px}' +
    '.lbc-biz{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}.lbc-biz button{border:1px solid var(--bd);background:var(--s2);color:var(--t1);border-radius:99px;padding:6px 12px;font:600 12.5px var(--fb,inherit);cursor:pointer}.lbc-biz button.on{background:var(--p);border-color:var(--p);color:#fff}' +
    '.lbc-add{display:flex;gap:8px;margin-top:8px}.lbc-add input{flex:1;min-height:40px;border:1px solid var(--bd);border-radius:var(--r,10px);background:var(--s2);color:var(--t1);padding:0 12px;font:inherit}';
  /* SaaS polish (Owner 2026-09-27: "enterprise quality css and UI/UX … saas quality") — layered after CSS so it wins */
  var CSS2 = '.lbc-flag[hidden],.lbc-check[hidden]{display:none!important}.lbc{padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.05),0 10px 30px rgba(0,0,0,.10)}' +
    '.lbc-hd{display:flex;align-items:center;gap:12px;margin:0 0 12px}.lbc-ic{flex:none;width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:color-mix(in srgb,var(--p) 15%,transparent);color:var(--p)}.lbc-ic svg{width:21px;height:21px}' +
    '.lbc-hdt{flex:1;min-width:0}.lbc-hd .lbc-t{font:700 16px/1.3 var(--fh,var(--fb,inherit));margin:0;color:var(--t1)}.lbc-hd .lbc-s{margin:3px 0 0;line-height:1.4}' +
    '.lbc-count{flex:none;font:600 12px/1 var(--fb,inherit);padding:8px 12px;border-radius:99px;border:1px solid var(--bd);color:var(--t2);background:var(--s2);white-space:nowrap;transition:background .2s,color .2s,border-color .2s}.lbc-count.ok{color:#fff;background:var(--p);border-color:var(--p)}' +
    '.lbc-steps{list-style:none;display:flex;flex-wrap:wrap;gap:6px 20px;margin:0 0 16px;padding:0;color:var(--t2);font-size:12.5px}.lbc-steps li{display:flex;align-items:center;gap:8px}.lbc-steps b{flex:none;width:20px;height:20px;border-radius:50%;display:grid;place-items:center;font-size:11px;background:var(--s2);border:1px solid var(--bd);color:var(--t1)}' +
    '.lbc-grid10{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px}' +
    '.lbc-opt{padding:6px;border:1.5px solid var(--bd);border-radius:14px;background:var(--s2);transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}' +
    '.lbc-opt:hover{transform:translateY(-2px);border-color:var(--bd2,var(--bd));box-shadow:0 10px 24px rgba(0,0,0,.20)}.lbc-opt.on{border-color:var(--p);box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 30%,transparent),0 10px 24px rgba(0,0,0,.18)}' +
    '.lbc-artwrap{position:relative}.lbc-art{border-radius:10px}' +
    '.lbc-check{position:absolute;top:8px;right:8px;z-index:4;width:28px;height:28px;border-radius:50%;background:var(--p);color:#fff;font:700 13px/24px var(--fb,inherit);text-align:center;border:2px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.4)}' +
    '.lbc-flag{position:absolute;top:8px;left:8px;z-index:4;display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border-radius:99px;background:#B83227;color:#fff;font:600 11px/1 var(--fb,inherit)}.lbc-flag svg{width:12px;height:12px}' +
    '.lbc-cap{padding:10px 4px 4px}.lbc-cr{display:flex;align-items:center;justify-content:space-between;gap:6px}.lbc-cap b{font-size:13.5px}.lbc-cap span{margin-top:3px}' +
    '.lbc-nv{margin:0;display:inline-flex;align-items:center;gap:4px;border:0;background:transparent;color:var(--t3);padding:4px 6px;min-height:28px;border-radius:7px;font:600 11px/1 var(--fb,inherit);cursor:pointer;flex:none}.lbc-nv svg{width:13px;height:13px}.lbc-nv:hover{color:var(--t1);background:var(--s1)}' +
    '.lbc-opt.never .lbc-nv{background:transparent;border:0;color:#E0685A}.lbc-opt.never{border-style:dashed}' +
    '.lbc-bar{position:sticky;bottom:0;z-index:6;display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:18px -18px -18px;padding:12px 18px;background:color-mix(in srgb,var(--s1) 88%,transparent);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);border-top:1px solid var(--bd);border-radius:0 0 var(--rg,14px) var(--rg,14px)}.lbc-bar-static{position:static;margin-top:14px}' +
    '.lbc-hint{display:flex;align-items:center;gap:7px;flex:1 1 240px;color:var(--t3);font-size:12.5px;line-height:1.4}.lbc-hint svg{width:17px;height:17px;flex:none}.lbc-hint b{color:var(--t1)}' +
    '.lbc-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;transition:filter .15s,background .15s,color .15s}.lbc-btn svg{width:16px;height:16px}.lbc-btn.ghost{border-color:transparent;color:var(--t2)}.lbc-btn.ghost:hover{color:var(--t1);background:var(--s2)}.lbc-btn.primary:not([disabled]):hover{filter:brightness(1.08)}.lbc-btn:focus-visible{outline:2px solid var(--p);outline-offset:2px}' +
    '.lbc-chip b{display:inline-grid;place-items:center;width:18px;height:18px;border-radius:50%;background:var(--p);color:#fff;font-size:10.5px}.lbc-chip-nv{color:var(--t3)}.lbc-chip svg{width:13px;height:13px}' +
    '@media (max-width:560px){.lbc{padding:14px}.lbc-grid10{grid-template-columns:1fr 1fr;gap:10px}.lbc-bar{margin:14px -14px -14px;padding:10px 14px}.lbc-hint{flex-basis:100%}.lbc-bar .lbc-btn.primary{flex:1}.lbc-steps{gap:6px 14px}.lbc-hd .lbc-t{font-size:15px}}' +
    '@media (prefers-reduced-motion:reduce){.lbc-opt,.lbc-count,.lbc-btn{transition:none}.lbc-opt:hover{transform:none}}';
  function css() { if (document.getElementById('lbc-css')) return; var s = document.createElement('style'); s.id = 'lbc-css'; s.textContent = CSS + CSS2; document.head.appendChild(s); }

  /* ── the picker ── */
  function picker(el, card, opts) {
    opts = opts || {}; css(); loadFonts();
    var st = { picks: (card.picks || []).slice(), never: (card.never || []).slice(), saved: !!opts.saved, pv: card.preview || {}, dirs: card.directions || [] };
    var bizQ = card.business_id ? '?business_id=' + encodeURIComponent(card.business_id) : '';
    var MAX = card.max || 4;
    var ICON = {
      palette: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.8-.9 1.8-1.9 0-.5-.2-.9-.5-1.3-.3-.3-.5-.8-.5-1.3 0-1 .8-1.8 1.8-1.8H16a5 5 0 0 0 5-5C21 6.4 17 3 12 3Z"/><circle cx="7.5" cy="11.5" r="1.2"/><circle cx="10.5" cy="7.5" r="1.2"/><circle cx="15.5" cy="7.5" r="1.2"/></svg>',
      upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>',
      check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>',
      ban: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/></svg>'
    };
    function nameOf(id) { var d = st.dirs.filter(function (x) { return x.id === id; })[0]; return d ? d.name : id; }
    function header(title, sub, right) {
      return '<div class="lbc-hd"><span class="lbc-ic">' + ICON.palette + '</span><div class="lbc-hdt"><div class="lbc-t">' + title + '</div><div class="lbc-s">' + sub + '</div></div>' + (right || '') + '</div>';
    }
    function drawSaved() {
      var name = esc(st.pv.business_name || 'your business');
      el.innerHTML = header('Design styles saved', 'For ' + name + ' · Sarah picks the best fit for each banner, image and video') +
        '<div class="lbc-done">' + st.picks.map(function (id, i) { return '<span class="lbc-chip"><b>' + (i + 1) + '</b>' + esc(nameOf(id)) + '</span>'; }).join('') +
        (st.never.length ? '<span class="lbc-chip lbc-chip-nv">' + ICON.ban + 'Never: ' + st.never.map(function (id) { return esc(nameOf(id)); }).join(', ') + '</span>' : '') + '</div>' +
        '<div class="lbc-bar lbc-bar-static"><span class="lbc-hint">You can change these any time' + (opts.settings ? '.' : ' — here, by telling Sarah, or in Settings › Business.') + '</span><button type="button" class="lbc-btn" data-a="edit">Change styles</button></div>';
      el.querySelector('[data-a=edit]').onclick = function () { st.saved = false; draw(); };
    }
    function drawSkipped() {
      el.innerHTML = header('Skipped for now', 'Sarah will work from your website. Choose styles any time in Settings › Business.');
    }
    function refresh() {
      el.querySelectorAll('.lbc-opt').forEach(function (o) {
        var id = o.getAttribute('data-id'), i = st.picks.indexOf(id), nv = st.never.indexOf(id) >= 0;
        o.classList.toggle('on', i >= 0); o.classList.toggle('never', nv); o.setAttribute('aria-pressed', String(i >= 0));
        var b = o.querySelector('.lbc-check'); b.innerHTML = i >= 0 ? String(i + 1) : ''; b.hidden = i < 0;
        var n = o.querySelector('.lbc-nv'); n.setAttribute('aria-pressed', String(nv)); n.innerHTML = ICON.ban + (nv ? 'Never' : 'Never');
        o.querySelector('.lbc-flag').hidden = !nv;
      });
      var c = el.querySelector('[data-count]'); if (c) { c.textContent = st.picks.length + ' of ' + MAX + ' chosen'; c.classList.toggle('ok', st.picks.length >= 3); }
      var sv = el.querySelector('[data-a=save]');
      if (sv) { sv.disabled = !st.picks.length; sv.innerHTML = st.picks.length ? ICON.check + 'Save ' + st.picks.length + ' style' + (st.picks.length > 1 ? 's' : '') : 'Choose at least one'; }
    }
    function draw() {
      if (st.saved && !opts.alwaysOpen) { drawSaved(); return; }
      var name = esc(st.pv.business_name || 'your business');
      el.innerHTML = header('Choose your design styles', 'For ' + name + ' · previews use your own name, colours and photo', '<span class="lbc-count" data-count></span>') +
        '<ol class="lbc-steps"><li><b>1</b>Tap the 3 or 4 you like, in order of preference</li><li><b>2</b>Mark any you never want</li></ol>' +
        '<div class="lbc-grid10">' + st.dirs.map(function (d) {
          return '<div class="lbc-opt" role="button" tabindex="0" aria-pressed="false" data-id="' + d.id + '"><div class="lbc-artwrap">' + tile(d, st.pv) +
            '<span class="lbc-check" hidden></span><span class="lbc-flag" hidden>' + ICON.ban + 'Never</span></div>' +
            '<div class="lbc-cap"><div class="lbc-cr"><b>' + esc(d.name) + '</b><button type="button" class="lbc-nv" data-nv="' + d.id + '" aria-label="Never use ' + esc(d.name) + '"></button></div><span>' + esc(d.blurb) + '</span></div></div>';
        }).join('') + '</div>' +
        '<div class="lbc-bar">' + (opts.settings ? '<span class="lbc-hint">Sarah uses the best fit for each request.</span>' : '<span class="lbc-hint">' + ICON.upload + '<span>Have brand guidelines, a logo, fonts or posts you like? Send them with the <b>+</b> button below.</span></span><button type="button" class="lbc-btn ghost" data-a="skip">Skip for now</button>') +
        '<button type="button" class="lbc-btn primary" data-a="save"></button></div>';
      refresh();
      el.querySelectorAll('.lbc-opt').forEach(function (o) {
        function toggle() {
          var id = o.getAttribute('data-id'), i = st.picks.indexOf(id);
          if (i >= 0) st.picks.splice(i, 1); else { if (st.picks.length >= MAX) { toast('Up to ' + MAX + ' styles — untick one first.'); return; } st.picks.push(id); st.never = st.never.filter(function (x) { return x !== id; }); }
          refresh();
        }
        o.addEventListener('click', function (e) { if (e.target.closest('.lbc-nv')) return; toggle(); });
        o.addEventListener('keydown', function (e) { if (e.target.closest('.lbc-nv')) return; if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
      });
      el.querySelectorAll('.lbc-nv').forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.stopPropagation(); var id = b.getAttribute('data-nv'), i = st.never.indexOf(id);
          if (i >= 0) st.never.splice(i, 1); else { st.never.push(id); st.picks = st.picks.filter(function (x) { return x !== id; }); }
          refresh();
        });
      });
      var sv = el.querySelector('[data-a=save]');
      sv.onclick = function () {
        sv.disabled = true; sv.textContent = 'Saving…';
        api('POST', 'brand/directions', { business_id: card.business_id || null, picks: st.picks, never: st.never, from: opts.settings ? 'settings' : 'chat' }).then(function (r) {
          if (r.ok && r.json.success) { st.saved = true; draw(); toast(opts.settings ? 'Design styles saved.' : 'Saved — Sarah will design in these styles.', 'success'); if (opts.onSaved) opts.onSaved(); }
          else { toast((r.json && r.json.error) || 'Could not save — try again.', 'error'); refresh(); }
        });
      };
      var sk = el.querySelector('[data-a=skip]');
      if (sk) sk.onclick = function () { api('POST', 'brand/intake/skip', { business_id: card.business_id || null }).then(drawSkipped); };
    }
    draw();
    if (!opts.settings) {
      api('GET', 'brand/profile' + bizQ).then(function (r) {
        var j = r.json || {}; if (!r.ok || !j.success) return;
        if (j.preview) st.pv = j.preview;
        if (j.picks && j.picks.length && j.picks_source !== 'suggested_from_upload') { st.picks = j.picks; st.never = j.never || []; st.saved = true; }
        else if (j.intake_status === 'skipped') { drawSkipped(); return; }
        draw();
      });
    }
  }

  /* ── the summary to confirm ── */
  function summary(el, card) {
    css();
    (card.fonts ? Object.keys(card.fonts).map(function (k) { return card.fonts[k]; }) : []).forEach(loadFamily);
    function draw(state) {
      var name = esc(card.business_name || 'your business');
      if (state === 'saved') { el.innerHTML = '<div class="lbc-t">Saved to ' + name + '’s brand</div><div class="lbc-s" style="margin:0">Every new banner, image and video for ' + name + ' uses it. Change it any time in Settings › Business.</div>'; return; }
      if (state === 'gone') { el.innerHTML = '<div class="lbc-s" style="margin:0">This brand summary was saved or discarded.</div>'; return; }
      var cols = (card.colors || []).map(function (c) { return '<span class="lbc-swc"><i style="background:' + esc(c.hex) + '"></i>' + esc(c.hex) + (c.role ? '<span style="color:var(--t3);font-weight:500">' + esc(c.role) + '</span>' : '') + '</span>'; }).join('');
      var fonts = card.fonts ? Object.keys(card.fonts).filter(function (k) { return card.fonts[k]; }).map(function (k) { return '<span class="lbc-chip" style="font-family:\'' + esc(card.fonts[k]) + '\',inherit">' + esc(k) + ': ' + esc(card.fonts[k]) + '</span>'; }).join(' ') : '';
      el.innerHTML = '<div class="lbc-t">Brand for ' + name + '</div><div class="lbc-s">Check what Sarah found. Nothing changes until you tap Save.</div>' +
        (cols ? '<div class="lbc-row"><b>Colours</b><div class="lbc-sw">' + cols + '</div></div>' : '') +
        (fonts ? '<div class="lbc-row"><b>Fonts</b><div>' + fonts + '</div></div>' : '') +
        (card.logo_url ? '<div class="lbc-row"><b>Logo</b><div class="lbc-logo"><img src="' + esc(card.logo_url) + '" alt="Logo"></div></div>' : '') +
        (card.tone ? '<div class="lbc-row"><b>Tone</b><div>' + esc(card.tone) + '</div></div>' : '') +
        (card.visual_style ? '<div class="lbc-row"><b>Style</b><div>' + esc(card.visual_style) + '</div></div>' : '') +
        ((card.rules || []).length ? '<div class="lbc-row"><b>Rules</b><ul class="lbc-rules">' + card.rules.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></div>' : '') +
        ((card.directions || []).length ? '<div class="lbc-row"><b>Fits</b><div class="lbc-done">' + card.directions.map(function (d) { return '<span class="lbc-chip">' + esc(d.name) + '</span>'; }).join('') + '</div></div>' : '') +
        '<div class="lbc-acts"><button type="button" class="lbc-btn primary" data-a="ok">Save to my brand</button><button type="button" class="lbc-btn" data-a="no">Discard</button></div>';
      el.querySelector('[data-a=ok]').onclick = function (e) {
        e.target.disabled = true; e.target.textContent = 'Saving…';
        api('POST', 'brand/proposals/' + encodeURIComponent(card.token) + '/confirm', {}).then(function (r) { if (r.ok && r.json.success) { draw('saved'); toast('Brand saved.', 'success'); } else { toast((r.json && r.json.error) || 'Could not save.', 'error'); draw(); } });
      };
      el.querySelector('[data-a=no]').onclick = function () { api('POST', 'brand/proposals/' + encodeURIComponent(card.token) + '/discard', {}).then(function () { draw('gone'); }); };
    }
    draw();
    api('GET', 'brand/proposals/' + encodeURIComponent(card.token)).then(function (r) {
      var st = (r.json || {}).status; if (st === 'saved') draw('saved'); else if (st && st !== 'pending') draw('gone');
    });
  }

  /* ── Settings: Your design styles (per business) ── */
  function settings(el, bizId) {
    css(); loadFonts();
    el.innerHTML = '<div class="lbc-s">Loading your design styles…</div>';
    api('GET', 'brand/profile' + (bizId ? '?business_id=' + encodeURIComponent(bizId) : '')).then(function (r) {
      var j = r.json || {};
      if (!r.ok || !j.success) { el.innerHTML = '<div class="lbc-s">Couldn’t load your design styles.</div>'; return; }
      var biz = (j.businesses || []).length > 1 ? '<div class="lbc-biz">' + j.businesses.map(function (b) { return '<button type="button" data-b="' + b.id + '" class="' + (b.id === j.business_id ? 'on' : '') + '">' + esc(b.name) + '</button>'; }).join('') + '</div>' : '';
      el.innerHTML = biz + '<div class="lbc" style="margin:0"><div data-slot="pick"></div></div>' +
        '<div class="lbc" style="margin-top:10px"><div class="lbc-t">Brand rules</div><div class="lbc-s">Sarah follows these on every banner, image, caption and video for ' + esc(j.business_name) + '.</div>' +
        ((j.rules || []).length ? '<ul class="lbc-rules">' + j.rules.map(function (x, i) { return '<li>' + esc(x) + '<button type="button" class="lbc-x" data-rm="' + i + '" aria-label="Remove rule">Remove</button></li>'; }).join('') + '</ul>' : '<div class="lbc-s" style="margin:0">No rules yet — for example “Never use red” or “Always show our Instagram handle”.</div>') +
        '<div class="lbc-add"><input type="text" maxlength="200" placeholder="Add a rule" aria-label="New brand rule"><button type="button" class="lbc-btn" data-a="add">Add</button></div></div>';
      el.querySelectorAll('[data-b]').forEach(function (b) { b.onclick = function () { settings(el, +b.getAttribute('data-b')); }; });
      picker(el.querySelector('[data-slot=pick]'), { business_id: j.business_id, preview: j.preview, directions: j.directions, picks: j.picks, never: j.never, max: 4 }, { settings: true, saved: !!(j.picks && j.picks.length) });
      el.querySelectorAll('[data-rm]').forEach(function (b) { b.onclick = function () { api('DELETE', 'brand/rules/' + b.getAttribute('data-rm') + (j.business_id ? '?business_id=' + j.business_id : '')).then(function () { settings(el, j.business_id); }); }; });
      var inp = el.querySelector('.lbc-add input'), add = el.querySelector('[data-a=add]');
      function doAdd() { var v = (inp.value || '').trim(); if (!v) return; add.disabled = true; api('POST', 'brand/rules', { business_id: j.business_id, rule: v }).then(function (x) { add.disabled = false; if (x.ok && x.json.success) settings(el, j.business_id); else toast((x.json && x.json.error) || 'Could not add the rule.', 'error'); }); }
      add.onclick = doAdd; inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });
    });
  }

  /* ── hydrate card slots wherever they appear (Sarah view, Advanced floater, Messages page) ── */
  function hydrate(slot) {
    if (slot.__lbc) return; slot.__lbc = 1;
    var card; try { card = JSON.parse(decodeURIComponent(slot.getAttribute('data-card') || '')); } catch (e) { return; }
    slot.classList.add('lbc');
    if (card.type === 'brand_directions') picker(slot, card);
    else if (card.type === 'brand_summary') summary(slot, card);
  }
  function scan(root) { (root || document).querySelectorAll && (root || document).querySelectorAll('.lu-brand-slot').forEach(hydrate); }
  function slotHtml(card) { if (!card || !card.type) return ''; return '<div class="lu-brand-slot" data-card="' + encodeURIComponent(JSON.stringify(card)) + '"></div>'; }
  new MutationObserver(function (ms) { ms.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.classList && n.classList.contains('lu-brand-slot')) hydrate(n); else scan(n); } }); }); })
    .observe(document.documentElement, { childList: true, subtree: true });
  window.LU_brandCard = { slotHtml: slotHtml, hydrate: hydrate, scan: scan, settings: settings, tile: tile };
  /* Settings › Business: mount the panel the first time it becomes visible, and refresh it each time the tab is reopened */
  function mountSettings() {
    var r = document.getElementById('brand-styles-root'); if (!r || r.__lbcObs || !window.IntersectionObserver) return; r.__lbcObs = 1;
    new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting && !r.__lbcOpen) { r.__lbcOpen = 1; settings(r); } else if (!e.isIntersecting) { r.__lbcOpen = 0; } }); }).observe(r);
  }
  function boot() { scan(); mountSettings(); }
  if (document.readyState !== 'loading') boot(); else document.addEventListener('DOMContentLoaded', boot);
})();
