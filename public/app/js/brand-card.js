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

  /* ── DESIGN-LIBRARY-2 (Owner 2026-10-01): the searchable library replaces the ten fixed styles ──
     A business searches and filters all the reference designs and picks up to three. Tiles show OUR rendering of each
     recipe. Site CSS only; phone first; a tap picks, the (i) opens the details sheet. Used in Settings › Business and in
     Sarah's intake card (with her shortlist first and "Browse all" underneath). */
  var LBL_CSS = '.lbl{position:relative;color:var(--t1);font:13.5px/1.4 var(--fb,inherit)}.lbl *{box-sizing:border-box}' +
    '.lbl-q{display:flex;align-items:center;gap:8px;border:1px solid var(--bd2,var(--bd));background:var(--s2);border-radius:14px;padding:0 12px;min-height:44px;margin:10px 0 8px}.lbl-q svg{width:16px;height:16px;color:var(--t3);flex:none}' +
    '.lbl-q input{flex:1;min-width:0;border:0;background:transparent;color:var(--t1);font:inherit;font-size:14px;outline:none;min-height:42px}.lbl-q input::placeholder{color:var(--t3)}.lbl-q button{border:0;background:transparent;color:var(--t3);cursor:pointer;font:inherit;padding:6px}' +
    '.lbl-chips{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;padding:2px 0 6px;margin:0 -2px}.lbl-chips::-webkit-scrollbar{display:none}' +
    '.lbl-chip{flex:none;border:1px solid var(--bd2,var(--bd));background:var(--s1);color:var(--t2);border-radius:99px;padding:7px 12px;font:600 12px/1 var(--fb,inherit);cursor:pointer;white-space:nowrap;min-height:32px}.lbl-chip.on{background:var(--p);border-color:var(--p);color:#fff}.lbl-chip:focus-visible{outline:2px solid var(--p);outline-offset:2px}' +
    '.lbl-more{margin:0 0 8px}.lbl-more[hidden]{display:none}.lbl-grp{margin:6px 0}.lbl-grp b{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--t3);margin:0 0 6px}.lbl-grp .lbl-chips{flex-wrap:wrap;overflow:visible}' +
    '.lbl-meta{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12px;color:var(--t3);margin:2px 0 8px}' +
    '.lbl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}@media(min-width:640px){.lbl-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(min-width:1000px){.lbl-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}' +
    '.lbl-tile{position:relative;border:2px solid transparent;border-radius:14px;overflow:hidden;background:var(--s2);cursor:pointer;-webkit-tap-highlight-color:transparent;outline:none}.lbl-tile:focus-visible{outline:2px solid var(--p);outline-offset:2px}.lbl-tile.on{border-color:var(--p)}' +
    '.lbl-art{position:relative;aspect-ratio:4/5;background:var(--s3,var(--s2));display:flex;align-items:flex-end;padding:10px}.lbl-art img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.lbl-art .ph{position:relative;font:700 15px/1.15 var(--fh,var(--fb,inherit));text-shadow:0 1px 8px rgba(0,0,0,.25)}' +
    '.lbl-n{position:absolute;top:8px;left:8px;width:26px;height:26px;border-radius:50%;background:var(--p);color:#fff;display:none;align-items:center;justify-content:center;font:800 13px/1 var(--fb,inherit);box-shadow:0 2px 8px rgba(0,0,0,.3)}.lbl-tile.on .lbl-n{display:flex}' +
    '.lbl-i{position:absolute;top:8px;right:8px;width:28px;height:28px;border-radius:50%;border:0;background:rgba(0,0,0,.45);color:#fff;font:700 13px/1 serif;cursor:pointer;display:flex;align-items:center;justify-content:center}' +
    '.lbl-cap{padding:8px 10px 10px}.lbl-cap b{display:block;font-size:13px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.lbl-cap span{display:block;font-size:11.5px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}' +
    '.lbl-foot{display:flex;justify-content:center;margin:12px 0 4px}.lbl-bar{position:sticky;bottom:0;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0 2px;margin-top:8px;background:linear-gradient(to top,var(--s1) 70%,transparent)}' +
    '.lbl-empty{text-align:center;color:var(--t3);padding:28px 12px}' +
    '.lbl-sheet{position:fixed;inset:0;z-index:100010;display:flex;align-items:flex-end;justify-content:center;background:rgba(0,0,0,.5)}@media(min-width:700px){.lbl-sheet{align-items:center}}' +
    '.lbl-sheet .box{width:min(560px,100%);max-height:88vh;overflow:auto;background:var(--s1);border:1px solid var(--bd);border-radius:18px 18px 0 0;padding:14px 16px 18px}@media(min-width:700px){.lbl-sheet .box{border-radius:18px}}' +
    '.lbl-sheet img{width:100%;border-radius:12px;display:block;margin:6px 0 10px}.lbl-sheet h3{margin:0;font:700 17px/1.25 var(--fh,var(--fb,inherit))}.lbl-sheet p{margin:6px 0;color:var(--t2)}.lbl-sheet ul{margin:6px 0;padding-left:18px;color:var(--t2)}.lbl-sheet .row{display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}' +
    '.lbl-saved{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin:10px 0}.lbl-saved .lbl-tile{cursor:default}';
  function lblCss() { if (document.getElementById('lbl-css')) return; var s = document.createElement('style'); s.id = 'lbl-css'; s.textContent = LBL_CSS; document.head.appendChild(s); }
  function library2(el, card, opts) {
    opts = opts || {}; css(); lblCss();
    var MAX = card.max || 5, biz = card.business_id || null, bizQ = biz ? '&business_id=' + encodeURIComponent(biz) : '';
    var st = { picks: (card.recipes || []).slice(), saved: !!(card.recipes || []).length && !opts.alwaysOpen, q: '', f: { industry: '', archetype: '', style: '', format: '', people: '' }, page: 1, total: 0, items: [], facets: null, home: card.home_industry || null, more: false, shortlist: card.shortlist || [] };
    var byId = {}; (st.picks || []).forEach(function (r) { byId[r.id] = r; }); (st.shortlist || []).forEach(function (r) { byId[r.id] = r; });
    var IC = { search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>', check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>' };
    function pickedIds() { return st.picks.map(function (r) { return r.id; }); }
    function tile(r) {
      var i = pickedIds().indexOf(r.id); var c = r.colours || {};
      var art = r.thumb ? '<img src="' + esc(r.thumb) + '" alt="" loading="lazy">' : '<div class="ph" style="color:' + esc(c.text || '#fff') + '">' + esc(r.title) + '</div>';
      return '<div class="lbl-tile' + (i >= 0 ? ' on' : '') + '" role="button" tabindex="0" aria-pressed="' + (i >= 0) + '" data-id="' + r.id + '"><div class="lbl-art" style="background:' + esc(c.ground || 'var(--s2)') + '">' + art + '<span class="lbl-n">' + (i >= 0 ? i + 1 : '') + '</span><button type="button" class="lbl-i" data-info="' + r.id + '" aria-label="About this design">i</button></div>' +
        '<div class="lbl-cap"><b>' + esc(r.title) + '</b><span>' + esc(r.industry) + ' · ' + esc(r.archetype_label || r.archetype) + '</span></div></div>';
    }
    function refresh() {
      var ids = pickedIds();
      el.querySelectorAll('.lbl-tile').forEach(function (t) { var id = +t.getAttribute('data-id'), i = ids.indexOf(id); t.classList.toggle('on', i >= 0); t.setAttribute('aria-pressed', String(i >= 0)); t.querySelector('.lbl-n').textContent = i >= 0 ? String(i + 1) : ''; });
      el.querySelectorAll('[data-count]').forEach(function (c) { c.textContent = st.picks.length + ' of ' + MAX + ' chosen'; });
      var sv = el.querySelector('[data-a=save]'); if (sv) { sv.disabled = !st.picks.length; sv.innerHTML = st.picks.length ? IC.check + 'Save ' + st.picks.length + ' look' + (st.picks.length > 1 ? 's' : '') : 'Choose up to ' + MAX; }
    }
    function toggle(id) {
      var ids = pickedIds(), i = ids.indexOf(id);
      if (i >= 0) st.picks.splice(i, 1); else { if (st.picks.length >= MAX) { toast('Up to ' + MAX + ' looks — untick one first.'); return; } if (byId[id]) st.picks.push(byId[id]); }
      refresh();
    }
    function chips(list, key, allLabel) {
      return '<div class="lbl-chips">' + (allLabel ? '<button type="button" class="lbl-chip' + (!st.f[key] ? ' on' : '') + '" data-f="' + key + '" data-v="">' + allLabel + '</button>' : '') +
        list.map(function (x) { return '<button type="button" class="lbl-chip' + (st.f[key] === String(x.value) ? ' on' : '') + '" data-f="' + key + '" data-v="' + esc(String(x.value)) + '">' + esc(x.label) + '</button>'; }).join('') + '</div>';
    }
    function load(append) {
      var p = 'brand/library?per_page=24&page=' + st.page + '&q=' + encodeURIComponent(st.q) + bizQ;
      Object.keys(st.f).forEach(function (k) { if (st.f[k] !== '') p += '&' + k + '=' + encodeURIComponent(st.f[k]); });
      var grid = el.querySelector('.lbl-grid'); if (grid && !append) grid.innerHTML = '<div class="lbl-empty" style="grid-column:1/-1">Loading…</div>';
      return api('GET', p).then(function (r) {
        var j = r.json || {}; if (!r.ok || !j.success) { if (grid) grid.innerHTML = '<div class="lbl-empty" style="grid-column:1/-1">Could not load the library — try again.</div>'; return; }
        st.total = j.total || 0; st.facets = st.facets || j.facets; st.home = st.home || j.home_industry;
        (j.items || []).forEach(function (x) { byId[x.id] = x; });
        st.items = append ? st.items.concat(j.items || []) : (j.items || []);
        drawGrid();
      });
    }
    function drawGrid() {
      var grid = el.querySelector('.lbl-grid'); if (!grid) return;
      grid.innerHTML = st.items.length ? st.items.map(tile).join('') : '<div class="lbl-empty" style="grid-column:1/-1">No designs match — try fewer words or clear a filter.</div>';
      var meta = el.querySelector('.lbl-meta span'); if (meta) meta.textContent = st.total + ' design' + (st.total === 1 ? '' : 's');
      var more = el.querySelector('[data-a=more]'); if (more) more.hidden = st.items.length >= st.total;
      wireTiles(grid); refresh();
    }
    function wireTiles(root) {
      root.querySelectorAll('.lbl-tile').forEach(function (t) {
        t.addEventListener('click', function (e) { if (e.target.closest('.lbl-i')) return; toggle(+t.getAttribute('data-id')); });
        t.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(+t.getAttribute('data-id')); } });
      });
      root.querySelectorAll('.lbl-i').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); sheet(+b.getAttribute('data-info')); }); });
    }
    function sheet(id) {
      api('GET', 'brand/library/' + id).then(function (r) {
        var d = (r.json || {}).design; if (!d) return;
        var ov = document.createElement('div'); ov.className = 'lbl-sheet'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true');
        var on = pickedIds().indexOf(id) >= 0;
        ov.innerHTML = '<div class="box">' + (d.thumb ? '<img src="' + esc(d.thumb.replace('/thumbs/', '/')) + '" alt="">' : '') + '<h3>' + esc(d.title) + '</h3><p>' + esc(d.industry) + ' · ' + esc(d.archetype_label) + (d.styles && d.styles.length ? ' · ' + esc(d.styles.join(', ')) : '') + '</p>' +
          (d.description ? '<p>' + esc(d.description) + '</p>' : '') + (d.why && d.why.length ? '<ul>' + d.why.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul>' : '') +
          (d.asks && d.asks.length ? '<p><b>Sarah will ask you for:</b> ' + esc(d.asks.join('; ')) + '.</p>' : '<p>No people, place or product to choose — Sarah fills the words and your brand.</p>') +
          '<div class="row"><button type="button" class="lbc-btn ghost" data-a="close">Close</button><button type="button" class="lbc-btn primary" data-a="pick">' + (on ? 'Remove from my looks' : 'Pick this look') + '</button></div></div>';
        document.body.appendChild(ov);
        function close() { ov.remove(); }
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        ov.querySelector('[data-a=close]').onclick = close;
        ov.querySelector('[data-a=pick]').onclick = function () { byId[id] = byId[id] || d; toggle(id); close(); };
      });
    }
    function drawSaved() {
      el.innerHTML = '<div class="lbl"><div class="lbc-hd"><span class="lbc-ic">' + IC.check + '</span><div class="lbc-hdt"><div class="lbc-t">Design looks saved</div><div class="lbc-s">Sarah designs every banner, image and video from these' + (opts.settings ? '.' : ' — change them here, by telling her, or in Settings › Business.') + '</div></div></div>' +
        '<div class="lbl-saved">' + st.picks.map(tile).join('') + '</div><div class="lbc-bar lbc-bar-static"><span class="lbc-hint">Up to ' + MAX + ' looks. Sarah uses the best fit for each request.</span><button type="button" class="lbc-btn" data-a="edit">Change looks</button></div></div>';
      el.querySelectorAll('.lbl-saved .lbl-i').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); sheet(+b.getAttribute('data-info')); }); });
      el.querySelector('[data-a=edit]').onclick = function () { st.saved = false; draw(); };
    }
    /* ONE-SAVE-1: the overlay's footer saves; the picker exposes its save and hides its own bar */
    function savePicks() { return api('POST', 'brand/library/picks', { business_id: biz, recipe_ids: pickedIds(), from: opts.settings ? 'settings' : 'chat' }).then(function (r) { if (r.ok && r.json.success) { st.picks = r.json.recipes || st.picks; st.saved = true; draw(); return true; } toast((r.json && r.json.error) || 'Could not save the looks.', 'error'); return false; }); }
    if (opts.embedded && opts.host) { opts.host.__lbcSavePicks = function () { var ids = pickedIds(); var same = st.saved && ids.length === (card.recipes || []).length && ids.every(function (id, i) { return (card.recipes || [])[i] && (card.recipes || [])[i].id === id; }); return same ? Promise.resolve(null) : savePicks(); }; }
    function draw() {
      if (st.saved) { drawSaved(); return; }
      var fac = st.facets || {};
      var quick = [{ value: '', label: 'All industries' }].concat(st.home ? [{ value: st.home, label: st.home }] : []);
      el.innerHTML = '<div class="lbl"><div class="lbc-hd"><span class="lbc-ic">' + IC.search + '</span><div class="lbc-hdt"><div class="lbc-t">' + (opts.title || 'Choose your design looks') + '</div><div class="lbc-s">Search all the designs, filter them, and pick up to ' + MAX + '. Sarah makes them yours: your name, colours, words and people.</div></div><span class="lbc-count" data-count></span></div>' +
        (st.shortlist.length && !opts.settings ? '<div class="lbc-s" style="margin:10px 0 6px"><b>Sarah\'s shortlist for you</b></div><div class="lbl-grid" data-short>' + st.shortlist.map(tile).join('') + '</div><div class="lbl-foot"><button type="button" class="lbc-btn" data-a="browse">Browse all designs</button></div>' : '') +
        '<div data-lib' + (st.shortlist.length && !opts.settings ? ' hidden' : '') + '>' +
        '<div class="lbl-q">' + IC.search + '<input type="search" placeholder="Search: dark, playful, offer, quote, minimal…" aria-label="Search designs" value="' + esc(st.q) + '"><button type="button" data-a="filters" aria-expanded="false">Filters</button></div>' +
        '<div class="lbl-chips" data-quick>' + quick.map(function (x) { return '<button type="button" class="lbl-chip' + (st.f.industry === x.value ? ' on' : '') + '" data-f="industry" data-v="' + esc(x.value) + '">' + esc(x.label) + '</button>'; }).join('') + '<button type="button" class="lbl-chip' + (st.f.people === '1' ? ' on' : '') + '" data-f="people" data-v="1">With people</button><button type="button" class="lbl-chip' + (st.f.people === '0' ? ' on' : '') + '" data-f="people" data-v="0">No people</button></div>' +
        '<div class="lbl-more" hidden><div class="lbl-grp"><b>Industry</b>' + chips(fac.industry || [], 'industry', 'All') + '</div><div class="lbl-grp"><b>Layout</b>' + chips(fac.archetype || [], 'archetype', 'Any') + '</div><div class="lbl-grp"><b>Style</b>' + chips(fac.style || [], 'style', 'Any') + '</div><div class="lbl-grp"><b>Shape</b>' + chips(fac.format || [], 'format', 'Any') + '</div></div>' +
        '<div class="lbl-meta"><span>' + st.total + ' designs</span><span>Tap to pick · (i) for details</span></div><div class="lbl-grid" data-main></div><div class="lbl-foot"><button type="button" class="lbc-btn" data-a="more" hidden>Show more</button></div></div>' +
        (opts.embedded ? '<div class="lbl-bar" style="position:static;background:none"><span class="lbc-hint">Sarah uses the best fit for each request. <b>Save changes</b> below keeps your picks.</span><span class="lbc-count" data-count></span></div></div>' : '<div class="lbl-bar"><span class="lbc-hint">' + (opts.settings ? 'Sarah uses the best fit for each request.' : 'Or send your own brand files with the <b>+</b> button.') + '</span><button type="button" class="lbc-btn primary" data-a="save"></button></div></div>');
      var q = el.querySelector('.lbl-q input'), t = null;
      q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { st.q = q.value.trim(); st.page = 1; load(false); }, 260); });
      el.querySelector('[data-a=filters]').onclick = function () { var m = el.querySelector('.lbl-more'); m.hidden = !m.hidden; this.setAttribute('aria-expanded', String(!m.hidden)); };
      el.addEventListener('click', function (e) { var c = e.target.closest('.lbl-chip'); if (!c) return; var k = c.getAttribute('data-f'), v = c.getAttribute('data-v'); st.f[k] = (st.f[k] === v && v !== '') ? '' : v; st.page = 1; el.querySelectorAll('.lbl-chip[data-f="' + k + '"]').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-v') === st.f[k]); }); load(false); });
      var more = el.querySelector('[data-a=more]'); more.onclick = function () { st.page++; load(true); };
      var br = el.querySelector('[data-a=browse]'); if (br) br.onclick = function () { el.querySelector('[data-lib]').hidden = false; br.hidden = true; load(false); };
      var short = el.querySelector('[data-short]'); if (short) wireTiles(short);
      var __sv = el.querySelector('[data-a=save]'); if (__sv) __sv.onclick = function () {
        var sv = this; sv.disabled = true; sv.textContent = 'Saving…';
        api('POST', 'brand/library/picks', { business_id: biz, recipe_ids: pickedIds(), from: opts.settings ? 'settings' : 'chat' }).then(function (r) {
          if (r.ok && r.json.success) { st.picks = r.json.recipes || st.picks; st.saved = true; draw(); toast(opts.settings ? 'Design looks saved.' : 'Saved — Sarah will design from these looks.', 'success'); if (opts.onSaved) opts.onSaved(); }
          else { toast((r.json && r.json.error) || 'Could not save — try again.', 'error'); refresh(); }
        });
      };
      refresh();
      if (!(st.shortlist.length && !opts.settings)) load(false); else if (!st.facets) api('GET', 'brand/library?per_page=12' + bizQ).then(function (r) { var j = r.json || {}; st.facets = j.facets || null; st.home = st.home || j.home_industry; });
    }
    draw();
  }

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

  /* ── VISION-INSPIRE-1: an inspiration Sarah studied and remembered ── */
  var ICO_EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
  var ICO_SPARK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.8 4.9L19 9.7l-4.3 3.1L16 18l-4-2.9L8 18l1.3-5.2L5 9.7l5.2-1.8Z"/></svg>';
  var ICO_PIN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4h6l-1 6 3 3H7l3-3-1-6Z"/><path d="M12 13v7"/></svg>';
  var ICO_COPY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>';
  var CSS3 = '.lbi-body{display:grid;grid-template-columns:minmax(120px,190px) 1fr;gap:18px;align-items:start}.lbi-img{width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:12px;border:1px solid var(--bd);background:var(--s2);display:block;cursor:zoom-in}' +
    '.lbi-title{font:700 17px/1.3 var(--fh,var(--fb,inherit));margin:0 0 8px;color:var(--t1)}.lbi-tags{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}.lbi-tag{font-size:12px;padding:4px 10px;border-radius:99px;background:var(--s2);border:1px solid var(--bd);color:var(--t2)}' +
    '.lbi-sw{display:flex;gap:6px;margin:0 0 12px}.lbi-sw i{width:22px;height:22px;border-radius:6px;border:1px solid var(--bd)}.lbi-h{font:600 11px/1 var(--fb,inherit);letter-spacing:.08em;text-transform:uppercase;color:var(--t3);margin:12px 0 6px}' +
    '.lbi-why{margin:0;padding-left:18px;color:var(--t2);font-size:13px;line-height:1.5}.lbi-why li{margin:2px 0}' +
    '.lbi-prompt{margin-top:14px;border:1px solid var(--bd);border-radius:10px;background:var(--s2)}.lbi-prompt summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;font:600 13px var(--fb,inherit);color:var(--t1)}.lbi-prompt summary::-webkit-details-marker{display:none}.lbi-prompt summary:after{content:"Show";font-weight:500;color:var(--t3);font-size:12px}.lbi-prompt[open] summary:after{content:"Hide"}' +
    '.lbi-prompt pre{margin:0;padding:0 12px 12px;white-space:pre-wrap;word-break:break-word;font:12.5px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--t2)}.lbi-copy{margin:0 12px 12px}' +
    '.lbc-btn.on{border-color:var(--p);color:var(--p)}.lbi-gone{opacity:.6}' +
    '.lbi-lib{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}.lbi-item{border:1px solid var(--bd);border-radius:12px;background:var(--s2);padding:6px}.lbi-item img{width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:8px;display:block}.lbi-item b{display:block;font-size:13px;margin:8px 4px 2px}.lbi-item span{display:block;font-size:11.5px;color:var(--t3);margin:0 4px 6px}.lbi-item .lbi-acts{display:flex;gap:4px;flex-wrap:wrap}' +
    '.lbi-bar .lbc-hint{flex:1 1 100%}.lbi-acts2{display:flex;flex-wrap:wrap;gap:8px;margin-left:auto;justify-content:flex-end}.lbi-acts2 .lbc-btn{white-space:nowrap}' +
    '@media (max-width:560px){.lbi-body{grid-template-columns:1fr}.lbi-img{max-width:220px}.lbi-acts2{width:100%}.lbi-acts2 .lbc-btn.primary{flex:1 1 100%;order:-1}}';
  function css3() { if (document.getElementById('lbi-css')) return; var s = document.createElement('style'); s.id = 'lbi-css'; s.textContent = CSS3; document.head.appendChild(s); }
  function composer(text) {
    var ta = document.getElementById('sh-input') || document.querySelector('#lu-messages-floater textarea, textarea[id*="msg"]');
    if (!ta) { toast('Open Sarah\'s chat and ask: ' + text); return; }
    ta.value = text; ta.dispatchEvent(new Event('input', { bubbles: true })); ta.focus();
    try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (e) {}
    toast('Ready — press send and Sarah will make it in this style.', 'success');
  }
  function inspiration(el, card) {
    css(); css3();
    var st = { pinned: false, gone: false };
    function draw() {
      if (st.gone) { el.innerHTML = '<div class="lbc-hd"><span class="lbc-ic">' + ICO_EYE + '</span><div class="lbc-hdt"><div class="lbc-t">Inspiration forgotten</div><div class="lbc-s">Sarah will no longer use “' + esc(card.title) + '”.</div></div></div>'; return; }
      var sw = (card.palette || []).map(function (h) { return '<i style="background:' + esc(h) + '" title="' + esc(h) + '"></i>'; }).join('');
      el.innerHTML = '<div class="lbc-hd"><span class="lbc-ic">' + ICO_EYE + '</span><div class="lbc-hdt"><div class="lbc-t">Saved to your inspiration library</div><div class="lbc-s">For ' + esc(card.business_name || 'your business') + ' · Sarah borrows the concept, never the content, in your own colours</div></div></div>' +
        '<div class="lbi-body"><img class="lbi-img" src="' + esc(card.image_url) + '" alt="Your inspiration" loading="lazy"><div>' +
        '<div class="lbi-title">' + esc(card.title) + '</div>' +
        '<div class="lbi-tags">' + (card.traits || []).concat(card.directions || []).map(function (t) { return '<span class="lbi-tag">' + esc(t) + '</span>'; }).join('') + '</div>' +
        (sw ? '<div class="lbi-h">Colour mood</div><div class="lbi-sw">' + sw + '</div>' : '') +
        ((card.why || []).length ? '<div class="lbi-h">Why it works</div><ul class="lbi-why">' + card.why.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul>' : '') +
        ((card.effects || []).length ? '<div class="lbi-h">Effects</div><div class="lbi-tags">' + card.effects.map(function (t) { return '<span class="lbi-tag">' + esc(t) + '</span>'; }).join('') + '</div>' : '') +
        '</div></div>' +
        '<div class="lbc-bar lbc-bar-static lbi-bar"><span class="lbc-hint">' + ICO_SPARK + '<span>Future banners that fit will use this look automatically.</span></span><div class="lbi-acts2">' +
        '<button type="button" class="lbc-btn ghost" data-a="forget">Forget</button>' +
        '<button type="button" class="lbc-btn' + (st.pinned ? ' on' : '') + '" data-a="pin" aria-pressed="' + st.pinned + '">' + ICO_PIN + (st.pinned ? 'Always used' : 'Always use this look') + '</button>' +
        '<button type="button" class="lbc-btn primary" data-a="make">' + ICO_SPARK + 'Make one like this</button></div></div>';
      el.querySelector('.lbi-img').onclick = function () { if (typeof window.shOpenLightbox === 'function') window.shOpenLightbox(card.image_url); else window.open(card.image_url, '_blank', 'noopener'); };
      el.querySelector('[data-a=forget]').onclick = function () { api('DELETE', 'brand/inspirations/' + card.id).then(function (r) { if (r.ok) { st.gone = true; draw(); } else toast('Could not forget it — try again.', 'error'); }); };
      el.querySelector('[data-a=pin]').onclick = function () { api('POST', 'brand/inspirations/' + card.id + '/pin', { pinned: !st.pinned }).then(function (r) { if (r.ok) { st.pinned = !st.pinned; draw(); toast(st.pinned ? 'Sarah will use this look on every banner.' : 'Used only when it fits.', 'success'); } }); };
      el.querySelector('[data-a=make]').onclick = function () { api('POST', 'brand/inspirations/' + card.id + '/focus', {}).then(function (r) { if (r.ok && r.json.suggested_message) composer(r.json.suggested_message); else toast((r.json && r.json.error) || 'Could not start — try again.', 'error'); }); };
    }
    draw();
    api('GET', 'brand/inspirations/' + card.id).then(function (r) { var x = (r.json || {}).inspiration; if (!x) return; st.pinned = !!x.pinned; st.gone = x.status !== 'active'; draw(); });
  }
  function library(el, bizId) {
    css(); css3();
    api('GET', 'brand/inspirations' + (bizId ? '?business_id=' + encodeURIComponent(bizId) : '')).then(function (r) {
      var list = ((r.json || {}).inspirations) || [];
      if (!list.length) { el.innerHTML = '<div class="lbc-s" style="margin:0">Nothing saved yet. Send Sarah any design you like — a post, a flyer, a screenshot — and she will study it and remember the look.</div>'; return; }
      el.innerHTML = '<div class="lbi-lib">' + list.map(function (x) {
        return '<div class="lbi-item" data-id="' + x.id + '"><img src="' + esc(x.image_url) + '" alt="" loading="lazy"><b>' + esc(x.title) + '</b><span>' + (x.pinned ? 'Always used · ' : '') + 'Used ' + x.uses + ' time' + (x.uses === 1 ? '' : 's') + '</span>' +
          '<div class="lbi-acts"><button type="button" class="lbc-nv" data-pin="' + (x.pinned ? 0 : 1) + '">' + (x.pinned ? 'Unpin' : 'Always use') + '</button><button type="button" class="lbc-nv" data-del>Forget</button></div></div>';
      }).join('') + '</div>';
      el.querySelectorAll('.lbi-item').forEach(function (it) {
        var id = it.getAttribute('data-id');
        it.querySelector('[data-pin]').onclick = function (e) { api('POST', 'brand/inspirations/' + id + '/pin', { pinned: e.currentTarget.getAttribute('data-pin') === '1' }).then(function () { library(el, bizId); }); };
        it.querySelector('[data-del]').onclick = function () { api('DELETE', 'brand/inspirations/' + id).then(function () { library(el, bizId); }); };
      });
    });
  }

  /* ── CAMPAIGNS-1: Sarah's campaign ideas in her chat ── */
  var CMP_CSS = '.lbk{display:flex;flex-direction:column;gap:10px}.lbk-i{border:1px solid var(--bd);border-radius:12px;background:var(--s2);padding:14px}.lbk-i h5{margin:0 0 4px;font:700 15px/1.3 var(--fh,var(--fb,inherit));color:var(--t1)}' +
    '.lbk-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12px;color:var(--t3);margin:0 0 6px}.lbk-why{margin:0 0 8px;color:var(--t2);font-size:13px}.lbk-steps{margin:0 0 10px;padding:0;list-style:none;font-size:12.5px;color:var(--t2)}.lbk-steps li{display:flex;gap:8px;padding:3px 0}.lbk-steps b{flex:none;min-width:52px;color:var(--t1);font-weight:600}' +
    '.lbk-acts{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.lbk-kpi{color:var(--t1);font-weight:600}.lbk-done{font-weight:600;font-size:13px}.lbk-reasons{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.lbk-reasons button{border:1px solid var(--bd);background:var(--s1);color:var(--t1);border-radius:99px;padding:5px 10px;font:600 12px var(--fb,inherit);cursor:pointer}';
  function cmpCss() { if (document.getElementById('lbk-css')) return; var s = document.createElement('style'); s.id = 'lbk-css'; s.textContent = CMP_CSS; document.head.appendChild(s); }
  function campaignIdeas(el, card) {
    css(); cmpCss();
    var st = {};
    var fmtd = function (s) { var x = new Date(String(s).length <= 10 ? s + 'T00:00:00' : s); return isNaN(x) ? '' : x.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); };
    function openCampaign(id) { if (typeof window.nav === 'function') { window.__luOpenCampaign = id; window.nav('projects'); var t = 0; (function w() { if (typeof window.campaignsOpen === 'function') window.campaignsOpen(id); else if (t++ < 30) setTimeout(w, 150); })(); } }
    function draw() {
      el.innerHTML = '<div class="lbc-hd"><span class="lbc-ic">' + ICO_SPARK + '</span><div class="lbc-hdt"><div class="lbc-t">Campaign ideas for ' + esc(card.business_name || 'your business') + '</div><div class="lbc-s">Launch approves the plan once; your team runs each step on its date and you okay every post before it goes out.</div></div></div>' +
        '<div class="lbk">' + (card.ideas || []).map(function (c) {
          var s = st[c.id] || 'idea';
          var head = '<h5>' + esc(c.title) + '</h5><div class="lbk-meta"><span>' + esc(fmtd(c.starts_on)) + ' – ' + esc(fmtd(c.ends_on)) + '</span>' + (c.kpi && c.kpi.label ? '<span class="lbk-kpi">· ' + esc(c.kpi.label) + '</span>' : '') + '<span>· ' + (c.steps_total || 0) + ' steps</span>' + ((c.channels || []).length ? '<span>· ' + esc(c.channels.join(', ').replace(/_/g, ' ')) + '</span>' : '') + '</div>';
          if (s === 'launched') return '<div class="lbk-i">' + head + '<div class="lbk-done" style="color:#22A06B">Launched — Sarah’s team is on it.</div><div class="lbk-acts" style="margin-top:8px"><button type="button" class="lbc-btn" data-open="' + c.id + '">Open campaign</button></div></div>';
          if (s === 'declined') return '<div class="lbk-i" style="opacity:.7">' + head + '<div class="lbk-done" style="color:var(--t3)">Not now — Sarah will learn from it.</div></div>';
          if (s === 'gone') return '';
          return '<div class="lbk-i" data-id="' + c.id + '">' + head + (c.why_now ? '<p class="lbk-why">' + esc(c.why_now) + '</p>' : '') +
            '<ul class="lbk-steps">' + (c.steps || []).slice(0, 3).map(function (x) { return '<li><b>' + esc(fmtd(x.at)) + '</b><span>' + esc(x.title) + '</span></li>'; }).join('') + ((c.steps_total || 0) > 3 ? '<li><b></b><span style="color:var(--t3)">+ ' + (c.steps_total - 3) + ' more steps</span></li>' : '') + '</ul>' +
            (s === 'confirm' ? '<div class="lbk-acts"><button type="button" class="lbc-btn primary" data-go="' + c.id + '">Confirm launch' + (c.credit_estimate != null ? ' · up to ' + (function (n) { return n + (n === 1 ? ' credit' : ' credits'); })(Math.max(1, Math.ceil(c.credit_estimate * 1.25))) : '') + '</button><button type="button" class="lbc-btn ghost" data-cancel="' + c.id + '">Cancel</button></div>'
              : s === 'why' ? '<div class="lbk-done" style="color:var(--t2)">Why not now?</div><div class="lbk-reasons">' + ['Not the right time', 'Too much for us now', 'Not our style', 'We tried something similar'].map(function (r) { return '<button type="button" data-reason="' + c.id + '">' + esc(r) + '</button>'; }).join('') + '</div>'
              : '<div class="lbk-acts"><button type="button" class="lbc-btn primary" data-launch="' + c.id + '">' + ICO_SPARK + 'Launch</button><button type="button" class="lbc-btn" data-open="' + c.id + '">View plan</button><button type="button" class="lbc-btn ghost" data-no="' + c.id + '">Not now</button></div>') + '</div>';
        }).join('') + '</div>';
      el.querySelectorAll('[data-open]').forEach(function (b) { b.onclick = function () { openCampaign(+b.getAttribute('data-open')); }; });
      el.querySelectorAll('[data-launch]').forEach(function (b) { b.onclick = function () { st[b.getAttribute('data-launch')] = 'confirm'; draw(); }; });
      el.querySelectorAll('[data-cancel]').forEach(function (b) { b.onclick = function () { st[b.getAttribute('data-cancel')] = 'idea'; draw(); }; });
      el.querySelectorAll('[data-no]').forEach(function (b) { b.onclick = function () { st[b.getAttribute('data-no')] = 'why'; draw(); }; });
      el.querySelectorAll('[data-reason]').forEach(function (b) { b.onclick = function () { var id = b.getAttribute('data-reason'); api('POST', 'growth/campaigns/' + id + '/decline', { reason: b.textContent }).then(function () { st[id] = 'declined'; draw(); }); }; });
      el.querySelectorAll('[data-go]').forEach(function (b) {
        b.onclick = function () { var id = b.getAttribute('data-go'); b.disabled = true; b.textContent = 'Launching…';
          api('POST', 'growth/campaigns/' + id + '/launch', {}).then(function (r) { if (r.ok && r.json.success) { st[id] = 'launched'; toast('Launched — Sarah’s team is on it.', 'success'); } else { st[id] = 'idea'; toast((r.json && r.json.error) || 'Could not launch.', 'error'); } draw(); }); };
      });
    }
    draw();
    (card.ideas || []).forEach(function (c) {
      api('GET', 'growth/campaigns/' + c.id).then(function (r) { var x = (r.json || {}).campaign; if (!x) { st[c.id] = 'gone'; } else if (['active', 'launching', 'paused', 'completed'].indexOf(x.status) >= 0) st[c.id] = 'launched'; else if (x.status === 'declined') st[c.id] = 'declined'; else if (x.status === 'archived') st[c.id] = 'gone'; draw(); });
    });
  }


  /* ── WATCH-1 (RFC-0019): Sarah asks once how often to watch the market; Sarah suggests a campaign update ── */
  var LBW_CSS = '.lbw-opts{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin:0 0 10px}.lbw-opt{border:1px solid var(--bd);background:var(--s2);border-radius:12px;padding:11px 12px;text-align:left;cursor:pointer;color:var(--t1);font:inherit;transition:border-color .15s,box-shadow .15s}' +
    '.lbw-opt b{display:block;font-size:13.5px}.lbw-opt span{display:block;font-size:11.5px;color:var(--t3);margin-top:2px}.lbw-opt.on{border-color:var(--p);box-shadow:0 0 0 1px var(--p) inset;background:color-mix(in srgb,var(--p) 8%,var(--s2))}' +
    '.lbw-tgs{display:flex;flex-wrap:wrap;gap:6px}.lbw-tg{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--bd);background:var(--s1);color:var(--t2);border-radius:99px;padding:6px 11px 6px 7px;font:600 12px var(--fb,inherit);cursor:pointer}' +
    '.lbw-tg i{width:16px;height:16px;border-radius:5px;border:1.5px solid var(--bd2,var(--bd));display:grid;place-items:center}.lbw-tg i svg{width:11px;height:11px;opacity:0}.lbw-tg[aria-pressed=true]{color:var(--t1);border-color:color-mix(in srgb,var(--p) 55%,var(--bd))}.lbw-tg[aria-pressed=true] i{background:var(--p);border-color:var(--p);color:#fff}.lbw-tg[aria-pressed=true] i svg{opacity:1}' +
    '.lbw-opt:focus-visible,.lbw-tg:focus-visible{outline:2px solid var(--p);outline-offset:2px}.lbw-ok{display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px;color:#22A06B}.lbw-ok i{width:9px;height:9px;border-radius:50%;background:#22A06B;box-shadow:0 0 0 4px color-mix(in srgb,#22A06B 22%,transparent)}' +
    '.lbw-ch{margin:0 0 4px;padding:0;list-style:none}.lbw-ch li{display:flex;gap:10px;align-items:baseline;padding:4px 0;font-size:13px;color:var(--t1)}.lbw-ch em{flex:none;font-style:normal;font:700 10.5px var(--fb,inherit);letter-spacing:.06em;text-transform:uppercase;border-radius:6px;padding:2px 7px;background:var(--s2);border:1px solid var(--bd);color:var(--t2)}' +
    '.lbw-why{margin:0 0 10px;color:var(--t2);font-size:13px}@media (max-width:560px){.lbw-opts{grid-template-columns:1fr 1fr}}';
  function lbwCss() { if (document.getElementById('lbw-css')) return; var s = document.createElement('style'); s.id = 'lbw-css'; s.textContent = LBW_CSS; document.head.appendChild(s); }
  var ICO_RADAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><path d="M12 12l5.5-5.5"/></svg>';
  var ICO_TICK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>';
  function openWatch() { if (typeof window.nav === 'function') { window.nav('projects'); var t = 0; (function w() { if (typeof window.campaignsWatch === 'function') window.campaignsWatch(); else if (t++ < 30) setTimeout(w, 150); })(); } }
  function watchSetup(el, card) {
    css(); lbwCss();
    var st = { s: 'ask', freq: 'twice_weekly', areas: { trends: true, competitors: true, listening: true } };
    var COST = card.cost || { trends: 6, competitors: 4, listening: 4 }, RUNS = { daily: 7, every_2_days: 3.5, twice_weekly: 2, weekly: 1 };
    var AR = [['trends', 'Trends & local moments'], ['competitors', 'Competitors'], ['listening', 'What people say online']];
    var LAB = { daily: 'Every day', every_2_days: 'Every 2 days', twice_weekly: 'Twice a week', weekly: 'Once a week' };
    function draw() {
      var per = 0; Object.keys(st.areas).forEach(function (k) { if (st.areas[k]) per += (COST[k] || 0); });
      var hd = '<div class="lbc-hd"><span class="lbc-ic">' + ICO_RADAR + '</span><div class="lbc-hdt"><div class="lbc-t">Keep an eye on the market' + (card.business_name ? ' for ' + esc(card.business_name) : '') + '</div><div class="lbc-s">Trends, competitors and what people say about you — turned into campaign moves you approve.</div></div></div>';
      if (st.s === 'on') { el.innerHTML = hd + '<div class="lbw-ok"><i></i>Sarah is watching · ' + esc(LAB[st.freq] || '') + '</div><div class="lbc-s" style="margin:6px 0 0">Tell her “stop monitoring” any time, or change it in Campaigns › Market watch.</div><div class="lbc-acts"><button type="button" class="lbc-btn" data-mw>Open Market watch</button></div>'; el.querySelector('[data-mw]').onclick = openWatch; return; }
      if (st.s === 'no') { el.innerHTML = hd + '<div class="lbc-s" style="margin:0">Not now. Sarah won’t spend anything on this. Turn it on any time in Campaigns › Market watch, or just ask her.</div>'; return; }
      el.innerHTML = hd + '<div class="lbw-opts" role="radiogroup" aria-label="How often">' + ['daily', 'every_2_days', 'twice_weekly', 'weekly'].map(function (f) { return '<button type="button" role="radio" aria-checked="' + (st.freq === f) + '" class="lbw-opt' + (st.freq === f ? ' on' : '') + '" data-f="' + f + '"><b>' + LAB[f] + '</b><span>About ' + Math.round(per * RUNS[f]) + ' credits a week</span></button>'; }).join('') + '</div>' +
        '<div class="lbw-tgs">' + AR.map(function (a) { return '<button type="button" class="lbw-tg" aria-pressed="' + !!st.areas[a[0]] + '" data-ar="' + a[0] + '"><i>' + ICO_TICK + '</i>' + a[1] + '</button>'; }).join('') + '</div>' +
        '<div class="lbc-acts"><button type="button" class="lbc-btn primary" data-go' + (per ? '' : ' disabled') + '>' + ICO_SPARK + 'Start watching</button><button type="button" class="lbc-btn ghost" data-no>Not now</button><span class="lbc-hint">One yes keeps it running until you say stop.</span></div>';
      el.querySelectorAll('[data-f]').forEach(function (b) { b.onclick = function () { st.freq = b.getAttribute('data-f'); draw(); }; });
      el.querySelectorAll('[data-ar]').forEach(function (b) { b.onclick = function () { var k = b.getAttribute('data-ar'); st.areas[k] = !st.areas[k]; draw(); }; });
      el.querySelector('[data-no]').onclick = function () { api('POST', 'growth/watch/stop', { business_id: card.business_id }).then(function () { st.s = 'no'; draw(); }); };
      el.querySelector('[data-go]').onclick = function (e) {
        e.currentTarget.disabled = true;
        api('POST', 'growth/watch', { business_id: card.business_id, frequency: st.freq, areas: st.areas }).then(function (r) { if (r.ok && r.json.success) { st.s = 'on'; toast('Sarah is on it — her first look starts in a few minutes.', 'success'); } else toast((r.json && r.json.error) || 'Could not start.', 'error'); draw(); });
      };
    }
    draw();
    api('GET', 'growth/watch' + (card.business_id ? '?business_id=' + card.business_id : '')).then(function (r) { var w = (r.json || {}).watch; if (!w) return; if (w.status === 'on') { st.s = 'on'; st.freq = w.frequency; } else if (w.status === 'declined' || w.status === 'off') st.s = 'no'; draw(); });
  }
  function campaignChange(el, card) {
    css(); lbwCss();
    var st = 'proposed';
    var fmtd = function (s) { var x = new Date(String(s).length <= 10 ? s + 'T00:00:00' : s); return isNaN(x) ? '' : x.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }); };
    var KN = { post: 'Social post', article: 'Article', image: 'Design', event: 'Event', owner_task: 'You' };
    function draw() {
      var hd = '<div class="lbc-hd"><span class="lbc-ic">' + ICO_SPARK + '</span><div class="lbc-hdt"><div class="lbc-t">Update “' + esc(card.campaign_title) + '”</div><div class="lbc-s">Sarah’s suggestion — nothing changes until you approve.</div></div></div>';
      var list = '<ul class="lbw-ch">' + (card.changes || []).map(function (x) {
        return '<li><em>' + (x.op === 'add' ? 'Add' : x.op === 'move' ? 'Move' : 'Drop') + '</em><span>' + esc(x.title) + (x.op === 'add' ? ' · ' + esc(KN[x.kind] || x.kind) + ' · ' + esc(fmtd(x.date)) : x.op === 'move' ? ' · to ' + esc(fmtd(x.date)) : '') + '</span></li>';
      }).join('') + '</ul>';
      var body = (card.reason ? '<p class="lbw-why">' + esc(card.reason) + '</p>' : '') + list;
      if (st === 'applied') { el.innerHTML = hd + body + '<div class="lbw-ok"><i></i>Approved — the campaign is updated.</div><div class="lbc-acts"><button type="button" class="lbc-btn" data-open>Open campaign</button></div>'; el.querySelector('[data-open]').onclick = function () { if (typeof window.nav === 'function') { window.__luOpenCampaign = card.campaign_id; window.nav('projects'); var t = 0; (function w() { if (typeof window.campaignsOpen === 'function') window.campaignsOpen(card.campaign_id); else if (t++ < 30) setTimeout(w, 150); })(); } }; return; }
      if (st === 'declined' || st === 'expired') { el.innerHTML = hd + body + '<div class="lbc-s" style="margin:0">Kept as is. Sarah will learn from it.</div>'; return; }
      el.innerHTML = hd + body + '<div class="lbc-acts"><button type="button" class="lbc-btn primary" data-ok>' + ICO_TICK + 'Approve' + (card.extra_credits ? ' · up to ' + Math.max(1, Math.ceil(card.extra_credits * 1.25)) + ' credits' : '') + '</button><button type="button" class="lbc-btn ghost" data-no>Keep as is</button></div>';
      el.querySelector('[data-ok]').onclick = function (e) { e.currentTarget.disabled = true; api('POST', 'growth/changes/' + card.change_id + '/approve', {}).then(function (r) { if (r.ok && r.json.success) { st = 'applied'; toast('Updated — the new steps are on the calendar.', 'success'); } else toast((r.json && r.json.error) || 'Could not update.', 'error'); draw(); }); };
      el.querySelector('[data-no]').onclick = function () { api('POST', 'growth/changes/' + card.change_id + '/decline', {}).then(function () { st = 'declined'; draw(); }); };
    }
    draw();
    api('GET', 'growth/changes/' + card.change_id).then(function (r) { var x = (r.json || {}).change; if (x && x.status !== 'proposed') { st = x.status; draw(); } });
  }

  /* ── Settings: Your design styles (per business) ── */
  /* BIZ-BRAND-1 (Owner 2026-09-28): each business's brand in one place — colours, styles, rules, inspirations — in both modes */
  function colCss() { if (document.getElementById('lbx-col-css')) return; var s = document.createElement('style'); s.id = 'lbx-col-css';
    s.textContent = '.lbx-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin:14px 0 4px}'
      + '.lbx-col{position:relative;display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid var(--bd2,rgba(127,127,127,.25));border-radius:12px;padding:12px;background:var(--s1,#fff);min-width:0}'
      + '.lbx-cn{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1}.lbx-cn b{font-size:13px;color:var(--t1)}.lbx-cn label{cursor:pointer}.lbx-cn small{font-size:11.5px;color:var(--t3)}'
      + '.lbx-pv{margin-top:16px}.lbx-pv-l{font:600 11px var(--fb,system-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--t3);margin-bottom:8px}'
      + '.lbx-pv-card{border-radius:14px;overflow:hidden;border:1px solid var(--bd,rgba(127,127,127,.2));background:#fff;max-width:520px;box-shadow:0 6px 20px rgba(15,23,42,.08)}'
      + '.lbx-pv-bar{padding:16px 18px;color:#fff;font:700 17px var(--fh,system-ui);letter-spacing:-.01em;transition:background .2s}'
      + '.lbx-pv-in{display:flex;align-items:center;gap:14px;padding:14px 18px;border-left:5px solid transparent;transition:border-color .2s,background .2s}'
      + '.lbx-pv-t{flex:1;font-size:12.5px;color:#334155;line-height:1.45}.lbx-pv-btn{flex:0 0 auto;padding:9px 16px;border-radius:999px;color:#fff;font:700 12.5px var(--fb,system-ui);transition:background .2s}'
      + '.lbx-logo{display:flex;align-items:center;gap:12px;margin-top:16px;padding-top:16px;border-top:1px solid var(--bd,rgba(127,127,127,.18))}.lbx-logo img{height:44px;max-width:160px;object-fit:contain;border-radius:8px;background:rgba(127,127,127,.08);padding:4px}'
      + '.lbx-logo .ph{width:44px;height:44px;border-radius:10px;border:1px dashed var(--bd2,rgba(127,127,127,.35));display:flex;align-items:center;justify-content:center;color:var(--t3);font-size:18px}'
      + '.lbx-logo span{display:flex;flex-direction:column;gap:2px}.lbx-logo b{font-size:13px;color:var(--t1)}.lbx-logo small{font-size:11.5px;color:var(--t3)}';
    document.head.appendChild(s); }
  function settings(el, bizId, opts) {
    opts = opts || {};
    css(); loadFonts(); colCss();
    var P = opts.panes || null;   // BIZ-BRAND-2: inside the business profile, the parts fill its Brand / Design styles / Rules tabs
    var slots = P ? [P.brand, P.styles, P.rules] : [el];
    slots.forEach(function (s) { if (s) s.innerHTML = '<div class="lbc-s" style="padding:8px 0">Loading…</div>'; });
    api('GET', 'brand/profile' + (bizId ? '?business_id=' + encodeURIComponent(bizId) : '')).then(function (r) {
      var j = r.json || {};
      if (!r.ok || !j.success) { slots.forEach(function (s) { if (s) s.innerHTML = '<div class="lbc-s">Couldn’t load the brand. Try again.</div>'; }); return; }
      var col = j.colors || {}, CL = { primary: 'Main colour', secondary: 'Second colour', accent: 'Highlight colour' };
      var HINT = { primary: 'Buttons, headings and banners', secondary: 'Backgrounds and panels', accent: 'Highlights and calls to action' };
      var colours = '<div class="lbc" style="margin:0 0 12px"><div class="lbc-t">Colours</div><div class="lbc-s">' + (j.brand_set ? 'Used on ' + esc(j.business_name) + '’s website, banners, images and videos.' : 'Not set yet for ' + esc(j.business_name) + ' — pick its colours so everything Sarah makes looks like this business.') + '</div>'
        + '<div class="lbx-cols">' + ['primary', 'secondary', 'accent'].map(function (k) { var v = col[k] || '#1F2937';
            return '<div class="lbx-col" data-k="' + k + '"><span class="lbx-cn"><label for="bzc-' + k + '-' + (j.business_id || 0) + '"><b>' + CL[k] + '</b></label><small>' + HINT[k] + '</small></span>'
              + '<input type="color" class="lbx-cp" id="bzc-' + k + '-' + (j.business_id || 0) + '" data-c="' + k + '" value="' + esc(v) + '"></div>'; }).join('') + '</div>'
        + '<div class="lbx-pv" aria-hidden="true"><div class="lbx-pv-l">Preview</div><div class="lbx-pv-card"><div class="lbx-pv-bar">' + esc(j.business_name) + '</div><div class="lbx-pv-in"><div class="lbx-pv-t">How the colours sit together on a banner, a page section and a button.</div><span class="lbx-pv-btn">Book now</span></div></div></div>'
        + '<div class="lbx-logo">' + (j.logo_url ? '<img src="' + esc(j.logo_url) + '" alt="' + esc(j.business_name) + ' logo"><span><b>Logo</b><small>From the website — Sarah uses it on banners and videos.</small></span>' : '<div class="ph">+</div><span><b>Logo</b><small>No logo yet — send it to Sarah in chat and she will use it everywhere.</small></span>') + '</div>'
        + (P ? '' : '<div class="lbc-acts" style="margin-top:12px"><button type="button" class="lbc-btn primary" data-a="colsave">Save colours</button></div>') + '</div>';
      var biz = (!opts.single && (j.businesses || []).length > 1) ? '<div class="lbc-biz">' + j.businesses.map(function (b) { return '<button type="button" data-b="' + b.id + '" class="' + (b.id === j.business_id ? 'on' : '') + '">' + esc(b.name) + '</button>'; }).join('') + '</div>' : '';
      var stylesHtml = '<div class="lbc" style="margin:0"><div data-slot="pick"></div></div>';
      var rulesHtml = '<div class="lbc" style="margin:' + (P ? '0' : '10px 0 0') + '"><div class="lbc-t">Brand rules</div><div class="lbc-s">Sarah follows these on every banner, image, caption and video for ' + esc(j.business_name) + '.</div>' +
        ((j.rules || []).length ? '<ul class="lbc-rules">' + j.rules.map(function (x, i) { return '<li>' + esc(x) + '<button type="button" class="lbc-x" data-rm="' + i + '" aria-label="Remove rule">Remove</button></li>'; }).join('') + '</ul>' : '<div class="lbc-s" style="margin:0">No rules yet — for example “Never use red” or “Always show our Instagram handle”.</div>') +
        '<div class="lbc-add"><input type="text" autocomplete="off" maxlength="200" placeholder="Add a rule" aria-label="New brand rule"><button type="button" class="lbc-btn" data-a="add">Add</button></div></div>' +
        '<div class="lbc" style="margin-top:12px"><div class="lbc-t">Inspiration library</div><div class="lbc-s">Designs you sent Sarah. She studied each one and borrows the look, in your colours, when it fits.</div><div data-slot="insp"></div></div>';
      var brandRoot, stylesRoot, rulesRoot;
      if (P) { P.brand.innerHTML = colours; P.styles.innerHTML = stylesHtml; P.rules.innerHTML = rulesHtml; brandRoot = P.brand; stylesRoot = P.styles; rulesRoot = P.rules; }
      else { el.innerHTML = biz + colours + stylesHtml + rulesHtml; brandRoot = stylesRoot = rulesRoot = el; }
      var again = function () { settings(el, j.business_id, opts); };
      el.querySelectorAll('[data-b]').forEach(function (b) { b.onclick = function () { settings(el, +b.getAttribute('data-b'), opts); }; });
      // colours: swatch opens the picker, the hex code is editable, the preview follows both
      var dirty = false;
      function paint() {
        var c = {}; brandRoot.querySelectorAll('.lbx-cp').forEach(function (i) { c[i.getAttribute('data-c')] = i.value; });
        var pv = brandRoot.querySelector('.lbx-pv-card'); if (pv) { pv.querySelector('.lbx-pv-bar').style.background = c.primary; var inn = pv.querySelector('.lbx-pv-in'); inn.style.borderLeftColor = c.secondary; inn.style.background = c.secondary + '14'; pv.querySelector('.lbx-pv-btn').style.background = c.accent; }
        if (typeof opts.onColours === 'function') opts.onColours(c);
      }
      // the site colour control (LUColorPicker) edits the trio together; every change repaints the preview
      brandRoot.querySelectorAll('.lbx-cp').forEach(function (cp) { cp.addEventListener('input', function () { dirty = true; paint(); }); cp.addEventListener('change', function () { dirty = true; paint(); }); });
      paint();
      el.__lbcColours = function () { if (!dirty) return null; var c = {}; brandRoot.querySelectorAll('.lbx-cp').forEach(function (i) { c[i.getAttribute('data-c') + '_color'] = i.value; }); return c; };
      var cs = brandRoot.querySelector('[data-a=colsave]');
      if (cs) cs.onclick = function () { var body = el.__lbcColours() || {}; body.business_id = j.business_id; cs.disabled = true;
        api('PUT', 'workspace/brand', body).then(function (x) { cs.disabled = false; if (x.ok && (x.json || {}).success !== false) { toast('Colours saved for ' + j.business_name + '.', 'success'); again(); } else toast(((x.json || {}).error) || 'Could not save the colours.', 'error'); }); };
      library(rulesRoot.querySelector('[data-slot=insp]'), j.business_id);   // VISION-INSPIRE-1
      library2(stylesRoot.querySelector('[data-slot=pick]'), { business_id: j.business_id, max: 5, recipes: j.recipe_picks || [] }, { settings: true, embedded: !!P, host: el });   // DESIGN-LIBRARY-2 + ONE-SAVE-1: in the overlay the footer's Save changes saves the looks
      // rules re-render only their own part, so an unsaved colour change in the profile is never lost
      function rulesAgain() { api('GET', 'brand/profile?business_id=' + encodeURIComponent(j.business_id)).then(function (x) { var jj = x.json || {}; j.rules = jj.rules || []; var box = rulesRoot.querySelector('.lbc-rules, .lbc-rules-empty'); var list = (j.rules || []).length ? '<ul class="lbc-rules">' + j.rules.map(function (y, i) { return '<li>' + esc(y) + '<button type="button" class="lbc-x" data-rm="' + i + '" aria-label="Remove rule">Remove</button></li>'; }).join('') + '</ul>' : '<div class="lbc-s lbc-rules-empty" style="margin:0">No rules yet — for example “Never use red” or “Always show our Instagram handle”.</div>';
        var cur = rulesRoot.querySelector('.lbc-rules') || rulesRoot.querySelector('.lbc-add').previousElementSibling; if (cur) cur.outerHTML = list; wireRules(); }); }
      function wireRules() { rulesRoot.querySelectorAll('[data-rm]').forEach(function (b) { b.onclick = function () { api('DELETE', 'brand/rules/' + b.getAttribute('data-rm') + (j.business_id ? '?business_id=' + j.business_id : '')).then(function () { P ? rulesAgain() : again(); }); }; }); }
      wireRules();
      var inp = rulesRoot.querySelector('.lbc-add input'), add = rulesRoot.querySelector('[data-a=add]');
      function doAdd() { var v = (inp.value || '').trim(); if (!v) return; add.disabled = true; api('POST', 'brand/rules', { business_id: j.business_id, rule: v }).then(function (x) { add.disabled = false; if (x.ok && x.json.success) { inp.value = ''; P ? rulesAgain() : again(); } else toast((x.json && x.json.error) || 'Could not add the rule.', 'error'); }); }
      add.onclick = doAdd; inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });
    });
  }

  /* REPORT-CARDS-1 (Owner 2026-09-28: "make those reports scrollable cards so it looks nice rather than pure text"):
     what the team finished, one card per piece of work, in a strip that scrolls sideways under Sarah's words. */
  var LTR_CSS = '.ltr{position:relative;margin:8px 0 2px;max-width:min(760px,100%)}' +
    '.ltr-track{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-padding:0 2px;padding:2px 2px 10px;scrollbar-width:thin;-webkit-overflow-scrolling:touch}' +
    '.ltr-card{flex:0 0 232px;scroll-snap-align:start;background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg,14px);padding:12px 13px;display:flex;flex-direction:column;gap:8px;color:var(--t1);font:13.5px/1.4 var(--fb,inherit);box-shadow:0 1px 2px rgba(0,0,0,.04)}' +
    '.ltr-who{display:flex;align-items:center;gap:9px;min-width:0}.ltr-av{width:34px;height:34px;border-radius:50%;flex:0 0 34px;background:var(--s2) center/cover no-repeat;border:1px solid var(--bd)}' +
    '.ltr-nm{font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ltr-rl{font-size:11.5px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.ltr-k{align-self:flex-start;display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--t2);background:var(--s2);border:1px solid var(--bd);border-radius:999px;padding:3px 9px}' +
    '.ltr-k i{width:6px;height:6px;border-radius:50%;background:var(--p,#7c5cff)}' +
    '.ltr-t{font-weight:600;font-size:14px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}' +
    '.ltr-d{font-size:12.5px;color:var(--t2);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}' +
    '.ltr-go{margin-top:auto;align-self:flex-start;font:600 12.5px var(--fb,inherit);color:var(--t1);background:var(--s2);border:1px solid var(--bd2,var(--bd));border-radius:10px;padding:7px 12px;cursor:pointer}.ltr-go:hover{border-color:var(--t3)}' +
    '.ltr-nav{position:absolute;top:calc(50% - 22px);width:32px;height:32px;border-radius:50%;border:1px solid var(--bd);background:var(--s1);color:var(--t1);box-shadow:0 2px 8px rgba(0,0,0,.12);cursor:pointer;display:none;align-items:center;justify-content:center;font-size:16px;line-height:1;z-index:2}' +
    '.ltr-nav.l{left:-10px}.ltr-nav.r{right:-10px}@media (hover:hover) and (min-width:768px){.ltr.can-l .ltr-nav.l,.ltr.can-r .ltr-nav.r{display:flex}}' +
    '.ltr-cnt{font-size:11.5px;color:var(--t3);margin:0 0 6px 2px}';
  function ltrCss() { if (document.getElementById('ltr-css')) return; var s = document.createElement('style'); s.id = 'ltr-css'; s.textContent = LTR_CSS; document.head.appendChild(s); }
  var LTR_KIND = { post: 'Social post', article: 'Article', link: 'Internal link', page: 'Web page', image: 'Design', video: 'Video', seo: 'Search', email: 'Email', lead: 'Enquiry', other: 'Done' };
  function teamReport(el, card) {
    ltrCss(); el.classList.remove('lbc'); el.classList.add('ltr');
    var items = Array.isArray(card.items) ? card.items : [];
    if (!items.length) { el.remove(); return; }
    el.innerHTML = '<div class="ltr-cnt">' + items.length + ' finished · scroll for more</div><div class="ltr-track" role="list"></div><button type="button" class="ltr-nav l" aria-label="Previous">‹</button><button type="button" class="ltr-nav r" aria-label="Next">›</button>';
    var track = el.querySelector('.ltr-track');
    items.forEach(function (it) {
      var c = document.createElement('div'); c.className = 'ltr-card'; c.setAttribute('role', 'listitem');
      var av = it.avatar && /^(https?:\/\/|\/)/.test(String(it.avatar)) ? ' style="background-image:url(' + esc(it.avatar) + ')"' : '';
      c.innerHTML = '<div class="ltr-who"><span class="ltr-av"' + av + ' aria-hidden="true"></span><div style="min-width:0"><div class="ltr-nm">' + esc(it.who || 'Sarah') + '</div>' + (it.role ? '<div class="ltr-rl">' + esc(it.role) + '</div>' : '') + '</div></div>' +
        '<span class="ltr-k"><i></i>' + esc(LTR_KIND[it.kind] || LTR_KIND.other) + '</span>' +
        '<div class="ltr-t">' + esc(it.title || '') + '</div>' + (it.detail ? '<div class="ltr-d">' + esc(it.detail) + '</div>' : '');
      var act = ltrAction(it);   /* REPORT-CARDS-1b: every card has somewhere to go */
      if (act) { var b = document.createElement('button'); b.type = 'button'; b.className = 'ltr-go'; b.textContent = act[0]; b.addEventListener('click', act[1]); c.appendChild(b); }
      track.appendChild(c);
    });
    function upd() { var max = track.scrollWidth - track.clientWidth - 2; el.classList.toggle('can-l', track.scrollLeft > 2); el.classList.toggle('can-r', track.scrollLeft < max);
      el.querySelector('.ltr-cnt').textContent = items.length + ' finished' + (max > 0 ? ' \u00b7 scroll for more' : ''); }
    el.querySelector('.ltr-nav.l').addEventListener('click', function () { track.scrollBy({ left: -242, behavior: 'smooth' }); });
    el.querySelector('.ltr-nav.r').addEventListener('click', function () { track.scrollBy({ left: 242, behavior: 'smooth' }); });
    track.addEventListener('scroll', upd, { passive: true }); window.addEventListener('resize', upd); setTimeout(upd, 50);
  }
  function ltrAction(it) {
    function go(v) { return function () { if (typeof window.nav === 'function') window.nav(v); }; }
    if (it.post_id) return ['View post', function () { var c = document.querySelector('.sh-inline-post[data-post="' + it.post_id + '"]'); if (c) { c.scrollIntoView({ behavior: 'smooth', block: 'center' }); c.classList.add('sh-flash'); setTimeout(function () { c.classList.remove('sh-flash'); }, 1600); } else go('social')(); }];
    if (it.article_id) return ['Open article', function () { ltrOpen('/app/write/' + it.article_id); }];
    var k = { post: ['Open Social', 'social'], article: ['Open articles', 'write'], link: ['Open Search', 'seo'], seo: ['Open Search', 'seo'], page: ['Open website', 'websites'], image: ['Open Studio', 'studio'], video: ['Open Studio', 'studio'], lead: ['Open Clients', 'crm'], email: ['Open Email', 'email'] }[it.kind];
    if (k) return [k[0], go(k[1])];   /* the kind of work picks the place; a generic link is the last resort */
    return it.link ? ['Open', function () { ltrOpen(String(it.link)); }] : null;
  }
  function ltrOpen(link) {
    var m = link.match(/^\/app\/?#\/?([a-z0-9_-]+)/i);
    if (m && typeof window.nav === 'function') { window.nav(m[1]); return; }
    var v = link.match(/^\/app\/([a-z0-9_-]+)(?:\/([^?#\/]+))?\/?$/i);   /* /app/write/1103 opens the article in place, no reload */
    if (v && typeof window.nav === 'function') { window.nav(v[1], v[2] ? { tail: v[2] } : undefined); return; }
    if (/^https?:\/\//i.test(link)) { window.open(link, '_blank', 'noopener'); return; }
    if (link.charAt(0) === '/') location.href = link;
  }
  /* ── hydrate card slots wherever they appear (Sarah view, Advanced floater, Messages page) ── */
  function hydrate(slot) {
    if (slot.__lbc) return; slot.__lbc = 1;
    var card; try { card = JSON.parse(decodeURIComponent(slot.getAttribute('data-card') || '')); } catch (e) { return; }
    slot.classList.add('lbc');
    if (card.type === 'brand_library') library2(slot, card);   // DESIGN-LIBRARY-2
    else if (card.type === 'brand_directions') picker(slot, card);
    else if (card.type === 'brand_summary') summary(slot, card);
    else if (card.type === 'inspiration') inspiration(slot, card);   // VISION-INSPIRE-1
    else if (card.type === 'campaign_ideas') campaignIdeas(slot, card);   // CAMPAIGNS-1
    else if (card.type === 'watch_setup') watchSetup(slot, card);   // WATCH-1
    else if (card.type === 'campaign_change') campaignChange(slot, card);   // WATCH-1
    else if (card.type === 'team_report') teamReport(slot, card);   // REPORT-CARDS-1
  }
  function scan(root) { (root || document).querySelectorAll && (root || document).querySelectorAll('.lu-brand-slot').forEach(hydrate); }
  function slotHtml(card) { if (!card || !card.type) return ''; return '<div class="lu-brand-slot" data-card="' + encodeURIComponent(JSON.stringify(card)) + '"></div>'; }
  new MutationObserver(function (ms) { ms.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.classList && n.classList.contains('lu-brand-slot')) hydrate(n); else scan(n); } }); }); })
    .observe(document.documentElement, { childList: true, subtree: true });
  window.LU_brandCard = { slotHtml: slotHtml, hydrate: hydrate, scan: scan, settings: settings, tile: tile, library: library };
  /* Settings › Business: mount the panel the first time it becomes visible, and refresh it each time the tab is reopened */
  function mountSettings() {
    var r = document.getElementById('brand-styles-root'); if (!r || r.__lbcObs || !window.IntersectionObserver) return; r.__lbcObs = 1;
    new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting && !r.__lbcOpen) { r.__lbcOpen = 1; settings(r); } else if (!e.isIntersecting) { r.__lbcOpen = 0; } }); }).observe(r);
  }
  function boot() { scan(); mountSettings(); }
  if (document.readyState !== 'loading') boot(); else document.addEventListener('DOMContentLoaded', boot);
})();
