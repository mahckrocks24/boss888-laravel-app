/* lu-tour.js — TOUR-1 (RFC-0013, Owner go 2026-09-23): a spotlight-and-coach-mark product tour in the app's own tokens.
   luTour.start(steps, opts) · luTour.next() · luTour.back() · luTour.skip() · luTour.end()
   step: { title, body, target?: selector | fn -> element, placement?: 'bottom'|'top', waitFor?: message type, tryLabel?: string }
   opts: { onEvent(name, info), onFinish(kind, lastStep), version } */
(function () {
  if (window.luTour) return;
  var S = { steps: [], i: 0, opts: {}, active: false, els: null, paused: false, waitHandler: null };

  function css() {
    if (document.getElementById('lu-tour-css')) return;
    var st = document.createElement('style'); st.id = 'lu-tour-css';
    st.textContent =
      '.lu-tour-pane{position:fixed;transition:none!important;background:rgba(5,7,14,.66);-webkit-backdrop-filter:blur(3px) saturate(.85);backdrop-filter:blur(3px) saturate(.85);z-index:100200;left:0;top:0;width:0;height:0}' +
      '.lu-tour-pane.free{pointer-events:none}' +
      '.lu-tour-ring{position:fixed;transition:none!important;z-index:100201;border:2px solid #A79CFF;border-radius:12px;pointer-events:none;display:none;box-shadow:0 0 0 3px rgba(139,124,246,.45),0 0 22px 4px rgba(124,92,255,.6),0 0 70px 16px rgba(124,92,255,.3),inset 0 0 22px rgba(124,92,255,.22);animation:luTourPulse 1.8s ease-in-out infinite}' +
      '@keyframes luTourPulse{0%,100%{box-shadow:0 0 0 3px rgba(139,124,246,.45),0 0 22px 4px rgba(124,92,255,.6),0 0 70px 16px rgba(124,92,255,.3),inset 0 0 22px rgba(124,92,255,.22)}50%{box-shadow:0 0 0 6px rgba(139,124,246,.22),0 0 34px 10px rgba(124,92,255,.42),0 0 96px 26px rgba(124,92,255,.18),inset 0 0 30px rgba(124,92,255,.14)}}' +
      '.lu-tour-card{position:fixed;transition:none!important;z-index:100202;width:min(380px,calc(100vw - 24px));color:var(--t1,#E8EDF5);border:1px solid transparent;border-radius:18px;padding:18px 18px 14px;font-family:var(--fb,system-ui,sans-serif);box-sizing:border-box;' +
        'background:linear-gradient(color-mix(in srgb,var(--s1,#171A21) 88%,transparent),color-mix(in srgb,var(--s1,#171A21) 88%,transparent)) padding-box,linear-gradient(135deg,rgba(167,156,255,.95),rgba(56,189,248,.75) 55%,rgba(108,92,231,.95)) border-box;' +
        '-webkit-backdrop-filter:blur(18px) saturate(1.3);backdrop-filter:blur(18px) saturate(1.3);box-shadow:0 28px 70px rgba(0,0,0,.5),0 0 0 1px rgba(124,92,255,.15),0 0 48px rgba(124,92,255,.22);animation:luTourIn .34s cubic-bezier(.2,.8,.2,1) both}' +
      '@supports not (background:color-mix(in srgb,red 50%,transparent)){.lu-tour-card{background:linear-gradient(var(--s1,#171A21),var(--s1,#171A21)) padding-box,linear-gradient(135deg,#A79CFF,#38BDF8 55%,#6C5CE7) border-box}.lu-tour-dots i{background:rgba(139,151,176,.3)}.lu-tour-btn{border-color:rgba(139,151,176,.3);background:var(--s2,#1E2230)}}' +
      '@keyframes luTourIn{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:none}}' +
      '.lu-tour-card.hero{width:min(420px,calc(100vw - 24px));text-align:center;padding:26px 22px 18px}' +
      '.lu-tour-badge{width:58px;height:58px;border-radius:17px;margin:0 auto 14px;display:grid;place-items:center;background:linear-gradient(135deg,#6C5CE7,#38BDF8);box-shadow:0 12px 32px rgba(108,92,231,.5),0 0 0 6px rgba(124,92,255,.14);animation:luTourFloat 3s ease-in-out infinite}' +
      '.lu-tour-badge img{width:32px;height:32px;filter:drop-shadow(0 2px 6px rgba(0,0,0,.35))}' +
      '@keyframes luTourFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}' +
      '.lu-tour-kicker{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--p,#6C5CE7);margin-bottom:6px}' +
      '.lu-tour-card h3{margin:0 0 6px;font:700 17px/1.25 var(--fh,inherit);letter-spacing:-.015em}.lu-tour-card.hero h3{font-size:21px}' +
      '.lu-tour-card p{margin:0 0 14px;font-size:13.5px;line-height:1.6;color:var(--t2,#B6BFCF)}.lu-tour-card.hero p{font-size:14px}' +
      '.lu-tour-try{margin:-4px 0 12px;padding:9px 12px;border-radius:10px;font-size:12.5px;font-weight:600;color:var(--p,#6C5CE7);background:rgba(124,92,255,.13);border:1px dashed rgba(139,124,246,.6);display:flex;align-items:center;gap:8px;text-align:left}' +
      '.lu-tour-try::before{content:"";width:8px;height:8px;border-radius:50%;background:#A79CFF;box-shadow:0 0 0 0 rgba(167,156,255,.7);animation:luTourDot 1.4s ease-out infinite;flex:0 0 auto}' +
      '@keyframes luTourDot{to{box-shadow:0 0 0 9px rgba(167,156,255,0)}}' +
      '.lu-tour-dots{display:flex;gap:4px;margin-bottom:12px}.lu-tour-dots i{flex:1 1 0;height:3px;border-radius:2px;background:color-mix(in srgb,var(--t3,#8B97B0) 28%,transparent)}.lu-tour-dots i.done{background:rgba(124,92,255,.5)}.lu-tour-dots i.on{background:linear-gradient(90deg,#A79CFF,#38BDF8);box-shadow:0 0 10px rgba(124,92,255,.75)}' +
      '.lu-tour-foot{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.lu-tour-card.hero .lu-tour-foot{justify-content:center}.lu-tour-step{font-size:11px;font-weight:600;letter-spacing:.02em;color:var(--t3,#8B97B0);flex:1 1 auto;text-align:left}.lu-tour-card.hero .lu-tour-step{flex:0 0 100%;text-align:center;order:9;margin-top:6px}' +
      '.lu-tour-btn{border:1px solid color-mix(in srgb,var(--t3,#8B97B0) 30%,transparent);background:color-mix(in srgb,var(--s2,#1E2230) 70%,transparent);color:var(--t1,#E8EDF5);border-radius:10px;padding:8px 14px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;min-height:38px;transition:transform .12s ease,box-shadow .12s ease}' +
      '.lu-tour-btn:hover{transform:translateY(-1px)}.lu-tour-btn.primary{background:linear-gradient(135deg,#7C6CF6,#5B8DEF);border-color:transparent;color:#fff;box-shadow:0 8px 22px rgba(108,92,231,.45),inset 0 1px 0 rgba(255,255,255,.18)}.lu-tour-btn.primary:hover{box-shadow:0 10px 28px rgba(108,92,231,.6),inset 0 1px 0 rgba(255,255,255,.18)}' +
      '.lu-tour-btn.ghost{background:none;border-color:transparent;color:var(--t3,#8B97B0)}.lu-tour-btn:focus-visible{outline:2px solid #A79CFF;outline-offset:2px}' +
      '@media (max-width:820px){.lu-tour-card{left:8px!important;right:8px!important;width:auto!important;top:auto!important;bottom:calc(8px + env(safe-area-inset-bottom))!important;max-height:54dvh;overflow:auto;border-radius:20px}.lu-tour-card.hero{padding:22px 18px 16px}}' +
      '@media (prefers-reduced-motion:reduce){.lu-tour-ring,.lu-tour-card,.lu-tour-badge,.lu-tour-try::before{animation:none}}' +
      'html.lu-kb-open .lu-tour-pane,html.lu-kb-open .lu-tour-ring,html.lu-kb-open .lu-tour-card{display:none!important}';
    document.head.appendChild(st);
  }

  function build() {
    css();
    var mk = function (cls) { var d = document.createElement('div'); d.className = cls; document.body.appendChild(d); return d; };
    S.els = { panes: [mk('lu-tour-pane'), mk('lu-tour-pane'), mk('lu-tour-pane'), mk('lu-tour-pane')], ring: mk('lu-tour-ring'), card: mk('lu-tour-card') };
    S.els.card.setAttribute('role', 'dialog'); S.els.card.setAttribute('aria-modal', 'true'); S.els.card.setAttribute('aria-labelledby', 'lu-tour-title');
    S.els.panes.forEach(function (p) { p.addEventListener('click', function (e) { e.stopPropagation(); }); });
  }

  function destroy() {
    if (!S.els) return;
    S.els.panes.forEach(function (p) { try { p.remove(); } catch (e) {} }); try { S.els.ring.remove(); } catch (e) {} try { S.els.card.remove(); } catch (e) {}
    S.els = null;
  }

  function resolveTarget(step) {
    var t = null;
    try { t = typeof step.target === 'function' ? step.target() : (step.target ? document.querySelector(step.target) : null); } catch (e) { t = null; }
    if (t && t.getBoundingClientRect().width === 0 && t.getBoundingClientRect().height === 0) t = null;
    return t;
  }

  function layout() {
    if (!S.active || !S.els) return;
    var step = S.steps[S.i], t = resolveTarget(step), vw = window.innerWidth, vh = window.innerHeight;
    var panes = S.els.panes, ring = S.els.ring, card = S.els.card;
    var free = !!step.waitFor;
    panes.forEach(function (p) { p.classList.toggle('free', free); });
    if (t) {
      try { t.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) {}
      var r = t.getBoundingClientRect(), x = Math.max(0, r.left - 6), y = Math.max(0, r.top - 6), w = Math.min(vw - x, r.width + 12), h = Math.min(vh - y, r.height + 12);
      var set = function (p, l, tp, ww, hh) { p.style.left = l + 'px'; p.style.top = tp + 'px'; p.style.width = Math.max(0, ww) + 'px'; p.style.height = Math.max(0, hh) + 'px'; p.style.display = 'block'; };
      var px = function (v) { return Math.round(v) + 'px'; };
      var st0 = panes[0].style, maskOk = !!(window.CSS && CSS.supports && (CSS.supports('mask-composite', 'exclude') || CSS.supports('-webkit-mask-composite', 'xor')));
      if (maskOk) {   /* TOUR-2c: one seamless pane, the spotlight cut out by a mask (clip-path let the blur bleed into the hole) */
        set(panes[0], 0, 0, vw, vh); st0.clipPath = ''; var mi = 'linear-gradient(#000 0 0),linear-gradient(#000 0 0)';
        st0.webkitMaskImage = mi; st0.maskImage = mi; st0.webkitMaskSize = st0.maskSize = '100% 100%,' + px(w) + ' ' + px(h); st0.webkitMaskPosition = st0.maskPosition = '0 0,' + px(x) + ' ' + px(y); st0.webkitMaskRepeat = st0.maskRepeat = 'no-repeat'; st0.webkitMaskComposite = 'xor'; st0.maskComposite = 'exclude';
        panes.slice(1).forEach(function (p) { p.style.display = 'none'; });
      } else { st0.webkitMaskImage = st0.maskImage = ''; st0.clipPath = ''; set(panes[0], 0, 0, vw, y); set(panes[1], 0, y, x, h); set(panes[2], x + w, y, vw - x - w, h); set(panes[3], 0, y + h, vw, vh - y - h); }
      ring.style.left = x + 'px'; ring.style.top = y + 'px'; ring.style.width = w + 'px'; ring.style.height = h + 'px'; ring.style.display = 'block';
      var mobile = window.matchMedia && window.matchMedia('(max-width:820px)').matches;
      if (!mobile) {
        card.style.visibility = 'hidden'; card.style.display = 'block';
        var cw = card.offsetWidth, ch = card.offsetHeight;
        var below = y + h + 12, above = y - 12 - ch;
        var top = (step.placement === 'top' || below + ch > vh - 8) && above >= 8 ? above : Math.min(below, vh - ch - 8);
        var left = Math.min(Math.max(12, x + w / 2 - cw / 2), vw - cw - 12);
        card.style.top = top + 'px'; card.style.left = left + 'px'; card.style.bottom = ''; card.style.visibility = '';
      } else { card.style.top = ''; card.style.left = ''; card.style.display = 'block'; }
    } else {
      panes[0].style.left = '0'; panes[0].style.top = '0'; panes[0].style.width = vw + 'px'; panes[0].style.height = vh + 'px'; panes[0].style.display = 'block'; panes[0].style.clipPath = ''; panes[0].style.webkitMaskImage = panes[0].style.maskImage = '';
      panes.slice(1).forEach(function (p) { p.style.display = 'none'; }); ring.style.display = 'none';
      var mob = window.matchMedia && window.matchMedia('(max-width:820px)').matches;
      card.style.display = 'block';
      if (!mob) { card.style.visibility = 'hidden'; var cw2 = card.offsetWidth, ch2 = card.offsetHeight; card.style.left = Math.max(12, (vw - cw2) / 2) + 'px'; card.style.top = Math.max(12, (vh - ch2) / 2) + 'px'; card.style.bottom = ''; card.style.visibility = ''; }
    }
  }

  function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  function render() {
    var step = S.steps[S.i], n = S.steps.length, last = S.i === n - 1, card = S.els.card;
    var hero = !step.target, dots = ''; for (var k = 0; k < n; k++) dots += '<i class="' + (k === S.i ? 'on' : (k < S.i ? 'done' : '')) + '"></i>';
    card.className = 'lu-tour-card' + (hero ? ' hero' : '') + (step.waitFor ? ' try' : '');
    card.style.animation = 'none'; void card.offsetWidth; card.style.animation = '';   // the entrance motion plays again on every step
    var kicker = step.kicker || (hero ? (S.i === 0 ? 'Editor tour' : 'You are ready') : '');
    card.innerHTML = (hero ? '<div class="lu-tour-badge" aria-hidden="true"><img src="/img/logo-icon-48.png" alt=""></div>' : '')
      + (kicker ? '<span class="lu-tour-kicker">' + esc(kicker) + '</span>' : '')
      + '<h3 id="lu-tour-title">' + esc(step.title) + '</h3><p>' + (step.html ? step.html : esc(step.body)) + '</p>'
      + '<div class="lu-tour-dots" aria-hidden="true">' + dots + '</div>'
      + '<div class="lu-tour-foot"><span class="lu-tour-step">' + (S.i + 1) + ' of ' + n + '</span>'
      + (S.i > 0 ? '<button type="button" class="lu-tour-btn" data-tour="back">Back</button>' : '')
      + (last ? '' : '<button type="button" class="lu-tour-btn ghost" data-tour="skip">Skip tour</button>')
      + (step.waitFor ? '<button type="button" class="lu-tour-btn" data-tour="next">' + esc(step.tryLabel ? 'Skip this' : 'Next') + '</button>' : '<button type="button" class="lu-tour-btn primary" data-tour="next">' + (last ? 'Finish' : (S.i === 0 ? 'Start the tour' : 'Next →')) + '</button>')
      + '</div>';
    if (step.waitFor && step.tryLabel) { var hint = document.createElement('div'); hint.className = 'lu-tour-try'; hint.textContent = step.tryLabel; card.querySelector('.lu-tour-dots').before(hint); }
    card.querySelectorAll('[data-tour]').forEach(function (b) { b.addEventListener('click', function () { var a = b.getAttribute('data-tour'); if (a === 'next') last ? end('completed') : next(); else if (a === 'back') back(); else skip(); }); });
    var prim = card.querySelector('.primary') || card.querySelector('[data-tour="next"]'); if (prim) setTimeout(function () { try { prim.focus({ preventScroll: true }); } catch (e) {} }, 30);
    if (S.waitHandler) { window.removeEventListener('message', S.waitHandler); S.waitHandler = null; }
    if (step.waitFor) { S.waitHandler = function (e) { if (e.data && e.data.type === step.waitFor) { window.removeEventListener('message', S.waitHandler); S.waitHandler = null; setTimeout(function () { if (S.active && S.steps[S.i] === step) next(); }, 350); } }; window.addEventListener('message', S.waitHandler); }
    emit('step', { index: S.i });
    layout();
  }

  function emit(name, info) { try { if (S.opts.onEvent) S.opts.onEvent(name, Object.assign({ index: S.i, total: S.steps.length }, info || {})); } catch (e) {} }
  function next() { if (!S.active) return; if (S.i < S.steps.length - 1) { S.i++; render(); } else end('completed'); }
  function back() { if (!S.active) return; if (S.i > 0) { S.i--; render(); } }
  function skip() { end('skipped'); }
  function end(kind) {
    if (!S.active) return; S.active = false;
    var last = S.i; destroy();
    document.removeEventListener('keydown', onKey, true); window.removeEventListener('resize', onResize); try { window.visualViewport && window.visualViewport.removeEventListener('resize', onResize); } catch (e) {}
    if (S.waitHandler) { window.removeEventListener('message', S.waitHandler); S.waitHandler = null; }
    emit(kind, { index: last });
    try { if (S.opts.onFinish) S.opts.onFinish(kind, last); } catch (e) {}
  }
  function onKey(e) {
    if (!S.active) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); skip(); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); next(); return; }
    if (e.key === 'ArrowLeft') { e.preventDefault(); back(); return; }
    if (e.key === 'Tab' && S.els) { var f = S.els.card.querySelectorAll('button'); if (!f.length) return; var first = f[0], lastB = f[f.length - 1]; if (e.shiftKey && document.activeElement === first) { e.preventDefault(); lastB.focus(); } else if (!e.shiftKey && document.activeElement === lastB) { e.preventDefault(); first.focus(); } }
  }
  var rt = null; function onResize() { clearTimeout(rt); rt = setTimeout(layout, 80); }

  function start(steps, opts) {
    if (S.active) end('replaced');
    S.steps = (steps || []).filter(Boolean); S.opts = opts || {}; S.i = 0; if (!S.steps.length) return;
    S.active = true; build();
    document.addEventListener('keydown', onKey, true); window.addEventListener('resize', onResize); try { window.visualViewport && window.visualViewport.addEventListener('resize', onResize); } catch (e) {}
    emit('started', {}); render();
  }

  window.luTour = { start: start, next: next, back: back, skip: skip, end: end, active: function () { return S.active; }, layout: layout, version: 1 };
})();
