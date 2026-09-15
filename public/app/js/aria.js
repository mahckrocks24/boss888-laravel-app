/* ═══════════════════════════════════════════════════════════════════════════════════════════════════
   aria.js — Aria, the platform FAQ (ARIA888, DEC-0054, 2026-09-15). One view, in the sidebar of BOTH Basic and
   Advanced. Reads GET /api/aria/suggestions and POST /api/aria/ask. Read-only: Aria explains; work is handed to
   Sarah or Arthur with one tap. Conversation is kept per workspace in sessionStorage (last 12 turns) and sent as
   history — nothing is persisted server-side. Site CSS only (tokens from index.html), 44 px targets, mobile-first.
   ═══════════════════════════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var MAX_TURNS = 12;
  var busy = false;

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function md(t) { return (typeof window.fmt === 'function') ? window.fmt(t || '') : esc(t || '').replace(/\n/g, '<br>'); }
  function hdr() { var h = { Accept: 'application/json' }; var t = localStorage.getItem('lu_token'); if (t) h.Authorization = 'Bearer ' + t; return h; }
  function api(method, path, body) {
    var o = { method: method, headers: hdr() };
    if (body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    return fetch('/api/' + path.replace(/^\//, ''), o).then(function (r) { return r.text().then(function (t) { var j = null; try { j = t ? JSON.parse(t) : null; } catch (e) {} return { ok: r.ok, status: r.status, json: j }; }); });
  }
  function wsId() { try { return localStorage.getItem('lu_workspace_id') || '0'; } catch (e) { return '0'; } }
  function histKey() { return 'aria_hist_' + wsId(); }
  function loadHist() { try { var h = JSON.parse(sessionStorage.getItem(histKey()) || '[]'); return Array.isArray(h) ? h : []; } catch (e) { return []; } }
  function saveHist(h) { try { sessionStorage.setItem(histKey(), JSON.stringify(h.slice(-MAX_TURNS))); } catch (e) {} }
  function mode() { return document.documentElement.getAttribute('data-mode') === 'advanced' ? 'advanced' : 'basic'; }

  function ensureCss() {
    if (document.getElementById('aria-css')) return;
    var st = document.createElement('style'); st.id = 'aria-css';
    st.textContent = [
      '.ar{display:flex;flex-direction:column;height:100%;min-height:0;background:var(--bg);color:var(--t1);font-family:var(--fb)}',
      '.ar-head{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--bd);background:var(--s1)}',
      '.ar-av{width:36px;height:36px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font:700 15px var(--fh);color:#fff;background:linear-gradient(135deg,#6D4AFF,#00E5A8)}',
      '.ar-head h1{font:700 16px var(--fh);margin:0;letter-spacing:-.01em}.ar-head p{margin:2px 0 0;font-size:12.5px;color:var(--t2)}',
      '.ar-head .ar-new{margin-left:auto;min-height:36px;padding:0 12px;border-radius:var(--r);border:1px solid var(--bd2);background:transparent;color:var(--t2);font:600 12.5px var(--fb);cursor:pointer}',
      '.ar-head .ar-new:hover{color:var(--t1);border-color:var(--p)}.ar-head .ar-new:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.ar-scroll{flex:1;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch}',
      '.ar-feed{max-width:820px;width:100%;margin:0 auto;padding:16px 16px 8px;display:flex;flex-direction:column;gap:12px;box-sizing:border-box}',
      '.ar-msg{display:flex;flex-direction:column;gap:6px;max-width:92%}.ar-msg.user{align-self:flex-end;align-items:flex-end}.ar-msg.aria{align-self:flex-start}',
      '.ar-b{padding:11px 14px;border-radius:14px;font-size:14px;line-height:1.55;word-break:break-word}',
      '.ar-msg.user .ar-b{background:var(--p);color:#fff;border-bottom-right-radius:4px}',
      '.ar-msg.aria .ar-b{background:var(--s1);border:1px solid var(--bd);border-bottom-left-radius:4px}',
      '.ar-b strong{font-weight:700}.ar-b ul{margin:6px 0 4px;padding-left:18px}.ar-b li{margin:2px 0}',
      '.ar-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:12px;color:var(--t2)}',
      '.ar-src{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;background:var(--s2);border:1px solid var(--bd);color:var(--t2);text-decoration:none;font-size:12px;min-height:26px}',
      '.ar-src:hover{color:var(--t1);border-color:var(--p)}',
      '.ar-acts{display:flex;flex-wrap:wrap;gap:8px}',
      '.ar-btn{min-height:40px;padding:0 14px;border-radius:var(--r);font:600 13px var(--fb);cursor:pointer;border:1px solid var(--bd2);background:var(--s1);color:var(--t1);display:inline-flex;align-items:center;gap:8px}',
      '.ar-btn.primary{background:var(--p);border-color:var(--p);color:#fff}.ar-btn:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.ar-chips{display:flex;flex-wrap:wrap;gap:8px}',
      '.ar-chip{min-height:40px;padding:8px 14px;border-radius:999px;border:1px solid var(--bd2);background:var(--s1);color:var(--t1);font:500 13px var(--fb);cursor:pointer;text-align:left;line-height:1.3}',
      '.ar-chip:hover{border-color:var(--p);color:var(--p)}.ar-chip:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.ar-note{font-size:12px;color:var(--t2);padding:2px 4px}.ar-note.warn{color:var(--am)}.ar-note.bad{color:var(--rd)}',
      '.ar-typing{display:inline-flex;gap:4px;padding:12px 14px}.ar-typing i{width:6px;height:6px;border-radius:50%;background:var(--t2);animation:ar-blink 1.2s infinite}.ar-typing i:nth-child(2){animation-delay:.2s}.ar-typing i:nth-child(3){animation-delay:.4s}',
      '@keyframes ar-blink{0%,80%,100%{opacity:.25}40%{opacity:1}}',
      '.ar-compose{flex:none;display:flex;gap:8px;align-items:flex-end;padding:10px 12px calc(10px + var(--safe-bottom,0px));border-top:1px solid var(--bd);background:var(--s1)}',
      '.ar-compose textarea{flex:1;min-height:44px;max-height:140px;resize:none;box-sizing:border-box;padding:11px 12px;border-radius:var(--r);border:1px solid var(--bd2);background:var(--bg);color:var(--t1);font:400 14px var(--fb);line-height:1.4}',
      '.ar-compose textarea:focus-visible{outline:2px solid var(--p);outline-offset:1px}',
      '.ar-send{flex:none;width:44px;height:44px;border-radius:var(--r);border:0;background:var(--p);color:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}',
      '.ar-send[disabled]{opacity:.5;cursor:default}.ar-send:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.ar-topics{display:flex;flex-direction:column;gap:6px}.ar-topics details{border:1px solid var(--bd);border-radius:var(--r);background:var(--s1)}',
      '.ar-topics summary{cursor:pointer;padding:10px 12px;font:600 13px var(--fb);min-height:44px;display:flex;align-items:center;color:var(--t1)}',
      '.ar-topics .ar-chips{padding:0 12px 12px}',
      '.ar-head .ar-new{white-space:nowrap}',
      '@media (min-width:900px){.ar-feed{padding:24px 24px 8px}.ar-msg{max-width:80%}.ar-compose{padding-left:84px}}'   /* room for the floating Sarah orb at bottom-left */
    ].join('\n');
    document.head.appendChild(st);
  }

  var root = null, feed = null, scroll = null, input = null, sendBtn = null, sugg = null;

  function el(tag, cls, html) { var d = document.createElement(tag); if (cls) d.className = cls; if (html != null) d.innerHTML = html; return d; }
  function toBottom() { if (scroll) scroll.scrollTop = scroll.scrollHeight; }

  function renderUser(text) { var m = el('div', 'ar-msg user'); m.appendChild(el('div', 'ar-b', esc(text))); feed.appendChild(m); toBottom(); return m; }

  function renderAria(r) {
    var m = el('div', 'ar-msg aria');
    m.appendChild(el('div', 'ar-b', md(r.answer || '')));
    var meta = el('div', 'ar-meta');
    (r.sources || []).forEach(function (s) {
      if (s.url) { var a = el('a', 'ar-src', '↗ ' + esc(s.title)); a.href = s.url; a.target = '_blank'; a.rel = 'noopener'; meta.appendChild(a); }
      else meta.appendChild(el('span', 'ar-src', esc(s.title)));
    });
    if (r.chat_meter && r.chat_meter.debited) meta.appendChild(el('span', 'ar-note', '1 credit — every 10th chat message'));
    if (r.mode === 'out_of_credits') meta.appendChild(el('span', 'ar-note warn', 'Documentation answer — no credits left in this workspace'));
    if (meta.childNodes.length) m.appendChild(meta);
    if (r.handoff && r.handoff.to) {
      var acts = el('div', 'ar-acts');
      var b = el('button', 'ar-btn primary', r.handoff.to === 'arthur' ? 'Ask Arthur to do this' : 'Ask Sarah to do this');
      b.type = 'button'; b.addEventListener('click', function () { handoff(r.handoff); });
      acts.appendChild(b); m.appendChild(acts);
    }
    if (r.followups && r.followups.length) {
      var chips = el('div', 'ar-chips');
      r.followups.forEach(function (q) { chips.appendChild(chip(q)); });
      m.appendChild(chips);
    }
    feed.appendChild(m); toBottom(); return m;
  }

  function chip(q) { var c = el('button', 'ar-chip', esc(q)); c.type = 'button'; c.addEventListener('click', function () { ask(q); }); return c; }

  function handoff(h) {
    var text = h.prompt || '';
    if (h.to === 'arthur') {
      if (window.nav) window.nav(mode() === 'advanced' ? 'websites' : 'website');
      if (typeof window.showToast === 'function') showToast('Open your site and tell Arthur: ' + text, 'info');
      return;
    }
    if (typeof window._lgsc_set_visibility_mode === 'function' && mode() !== 'basic') window._lgsc_set_visibility_mode('basic', { skipNav: true });
    if (window.nav) window.nav('sarah');
    setTimeout(function () { var i = document.getElementById('sh-input'); if (i) { i.value = text; i.focus(); } }, 400);
  }

  function ask(q) {
    q = String(q || '').trim();
    if (!q || busy) return;
    busy = true; if (sendBtn) sendBtn.disabled = true;
    if (sugg) { sugg.remove(); sugg = null; }
    var hist = loadHist();
    renderUser(q);
    var typing = el('div', 'ar-msg aria'); typing.appendChild(el('div', 'ar-b ar-typing', '<i></i><i></i><i></i>')); feed.appendChild(typing); toBottom();
    api('POST', 'aria/ask', { message: q, history: hist.slice(-6) }).then(function (r) {
      typing.remove();
      var j = r.json || {};
      if (!r.ok) {
        var msg = r.status === 402 ? (j.error || 'This workspace has no credits left. Chat is 1 credit for every 10 messages; top up under Billing.')
          : r.status === 429 ? 'Too many questions in a minute. Give it a moment.'
          : (j.error || j.message || 'Aria could not answer just now. Try again.');
        renderAria({ answer: msg, sources: [], followups: [] });
        return;
      }
      renderAria(j);
      hist.push({ role: 'user', content: q }); hist.push({ role: 'assistant', content: j.answer || '' }); saveHist(hist);
      if (j.chat_meter && typeof window.luRefreshCredits === 'function') { try { window.luRefreshCredits(); } catch (e) {} }
    }).catch(function () {
      typing.remove(); renderAria({ answer: 'Aria could not answer just now. Check your connection and try again.', sources: [], followups: [] });
    }).then(function () { busy = false; if (sendBtn) sendBtn.disabled = false; if (input) input.focus(); });
  }

  function renderSuggestions(s) {
    sugg = el('div', 'ar-msg aria'); sugg.style.maxWidth = '100%';
    sugg.appendChild(el('div', 'ar-b', md(s.greeting || 'Hi, I am Aria. Ask me anything about the platform.')));
    var chips = el('div', 'ar-chips'); (s.starters || []).forEach(function (q) { chips.appendChild(chip(q)); }); sugg.appendChild(chips);
    var topics = s.topics || {}; var keys = Object.keys(topics);
    if (keys.length) {
      var t = el('div', 'ar-topics');
      keys.forEach(function (k) {
        var d = el('details', ''); d.appendChild(el('summary', '', esc(k)));
        var c = el('div', 'ar-chips'); (topics[k] || []).forEach(function (q) { c.appendChild(chip(q)); }); d.appendChild(c); t.appendChild(d);
      });
      sugg.appendChild(t);
    }
    feed.appendChild(sugg);
  }

  function newConversation() { saveHist([]); feed.innerHTML = ''; sugg = null; api('GET', 'aria/suggestions').then(function (r) { renderSuggestions((r.json && r.ok) ? r.json : {}); }); }

  window.ariaLoad = function (r) {
    ensureCss(); root = r;
    // both sidebar entries light up (nav() only knows ni-aria)
    var nb = document.getElementById('ni-aria-basic'); if (nb) nb.classList.add('active');
    root.innerHTML = '';
    var shell = el('div', 'ar');
    var head = el('div', 'ar-head');
    head.appendChild(el('div', 'ar-av', 'A'));
    head.appendChild(el('div', '', '<h1>Aria</h1><p>Platform help. Ask how anything works, what a plan includes, what something costs.</p>'));
    var nb2 = el('button', 'ar-new', 'New chat'); nb2.type = 'button'; nb2.addEventListener('click', newConversation); head.appendChild(nb2);
    shell.appendChild(head);
    scroll = el('div', 'ar-scroll'); feed = el('div', 'ar-feed'); scroll.appendChild(feed); shell.appendChild(scroll);
    var comp = el('div', 'ar-compose');
    input = el('textarea', ''); input.id = 'aria-input'; input.rows = 1; input.placeholder = 'Ask Aria about the platform…'; input.setAttribute('aria-label', 'Ask Aria');
    input.addEventListener('input', function () { input.style.height = 'auto'; input.style.height = Math.min(140, input.scrollHeight) + 'px'; });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); var v = input.value; input.value = ''; input.style.height = 'auto'; ask(v); } });
    sendBtn = el('button', 'ar-send', '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h13M11 5l5 5-5 5"/></svg>');
    sendBtn.type = 'button'; sendBtn.id = 'aria-send'; sendBtn.setAttribute('aria-label', 'Send');
    sendBtn.addEventListener('click', function () { var v = input.value; input.value = ''; input.style.height = 'auto'; ask(v); });
    comp.appendChild(input); comp.appendChild(sendBtn); shell.appendChild(comp);
    root.appendChild(shell);
    var hist = loadHist();
    if (hist.length) {
      hist.forEach(function (h) { if (h.role === 'user') renderUser(h.content); else renderAria({ answer: h.content, sources: [], followups: [] }); });
      toBottom();
    } else {
      api('GET', 'aria/suggestions').then(function (r) { renderSuggestions((r.json && r.ok) ? r.json : {}); });
    }
  };
})();
