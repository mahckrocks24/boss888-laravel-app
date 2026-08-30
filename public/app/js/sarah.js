/* ═══════════════════════════════════════════════════════════════════════════════════════════════════
   sarah.js — the Basic home. P1-U2 (2026-08-30, REPORT-0023 §6/§7, Owner direction: BASIC = SARAH
   OPERATING ENVIRONMENT). One conversation with Sarah over the SAME authoritative thread every other
   surface uses (GET/POST /agents/dmm/messages, two-phase ack/final, GET /agent/events), so nothing the
   customer says here is a parallel universe: the agent drawer, Messages page and this home read one row.

   What it renders (top → bottom):
     · briefing strip   — grounded facts from /dashboard/overview (work done today, attention items)
     · the thread       — user / Sarah bubbles; activity cards from /agent/events (progress, approval,
                          output preview, failure) rendered inline; orchestration strip while work runs
     · composer         — one textarea, attachments via LU_attachComposer, Enter to send
   Accessibility: semantic buttons, labelled composer, role=log feed with aria-live=polite, 44 px targets,
   focus-visible, mobile-first (the thread IS the app at 390 px).
   ═══════════════════════════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var SLUG = 'dmm';                       // Sarah's UI slug (server maps dmm → sarah)
  var CONV = 'sarah';                     // conversation id used by /agent/events
  var POLL_MS = 2500, POLL_MAX_MS = 90000;
  var S = { root: null, feed: null, input: null, sendBtn: null, cursor: null, evTimer: null, evFails: 0,
            rendered: {}, activePoll: null, lastFacts: null, orchestration: null, mounted: false };

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function fmtBody(t) { return (typeof window.fmt === 'function') ? window.fmt(t || '') : esc(t || ''); }
  function hdr() { var h = { Accept: 'application/json' }; var t = localStorage.getItem('lu_token'); if (t) h.Authorization = 'Bearer ' + t; return h; }
  function api(method, path, body) {
    var o = { method: method, headers: hdr() };
    if (body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    return fetch('/api/' + path.replace(/^\//, ''), o).then(function (r) {
      return r.text().then(function (t) { var j = null; try { j = t ? JSON.parse(t) : null; } catch (e) {} return { ok: r.ok, status: r.status, json: j }; });
    });
  }
  function ago(ts) { if (!ts) return ''; var d = window._luParseTs ? window._luParseTs(ts) : new Date(String(ts).replace(' ', 'T') + 'Z'); var m = Math.round((Date.now() - d) / 60000); if (m < 1) return 'now'; if (m < 60) return m + 'm ago'; if (m < 1440) return Math.floor(m / 60) + 'h ago'; return Math.floor(m / 1440) + 'd ago'; }
  function nearBottom(el) { return el && (el.scrollHeight - (el.scrollTop + el.clientHeight)) < 120; }
  function stick(el, was) { if (el && was) el.scrollTop = el.scrollHeight; }

  /* ── CSS (tokens only; mobile-first) ─────────────────────────────────────────────────────────── */
  function ensureCss() {
    if (document.getElementById('sarah-css')) return;
    var st = document.createElement('style'); st.id = 'sarah-css';
    st.textContent = [
      '#sarah-home{display:flex;flex-direction:column;height:100%;min-height:0;background:var(--bg);color:var(--t1);font-family:var(--fb)}',
      '.sh-top{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--bd);background:var(--s1);flex:none;flex-wrap:wrap}',
      '.sh-avatar{width:40px;height:40px;border-radius:50%;background:radial-gradient(circle at 35% 35%,#FFD27A,#F59E0B 55%,#B7700A);box-shadow:0 0 0 3px rgba(245,158,11,.18);flex:none}',
      '.sh-who{min-width:0;flex:1}.sh-name{font:700 15px var(--fh);letter-spacing:-.01em}.sh-role{font-size:12px;color:var(--t2)}',
      '.sh-ctx{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--t2);background:var(--s2);border:1px solid var(--bd);border-radius:999px;padding:6px 10px;max-width:100%}',
      '.sh-ctx b{color:var(--t1);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:46vw}',
      '.sh-brief{display:flex;gap:8px;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid var(--bd);background:var(--s1);flex:none}',
      '.sh-chip{display:inline-flex;align-items:center;gap:6px;min-height:32px;padding:4px 10px;border-radius:999px;background:var(--s2);border:1px solid var(--bd);font-size:12.5px;color:var(--t2)}',
      '.sh-chip b{color:var(--t1);font-weight:600}.sh-chip.att{border-color:rgba(245,158,11,.5);color:var(--am)}.sh-chip.att b{color:var(--am)}',
      '.sh-chip[role=button]{cursor:pointer}.sh-chip[role=button]:hover{border-color:var(--p)}.sh-chip:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.sh-feed{flex:1;min-height:0;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth}',
      '@media (prefers-reduced-motion:reduce){.sh-feed{scroll-behavior:auto}}',
      '.sh-row{display:flex;flex-direction:column;max-width:min(720px,92%)}.sh-row.me{align-self:flex-end;align-items:flex-end}.sh-row.her{align-self:flex-start}',
      '.sh-bubble{padding:12px 16px;border-radius:16px;font-size:14.5px;line-height:1.55;word-break:break-word}',
      '.sh-row.me .sh-bubble{background:var(--p);color:#fff;border-bottom-right-radius:6px}',
      '.sh-row.her .sh-bubble{background:var(--s2);border:1px solid var(--bd);border-bottom-left-radius:6px}',
      '.sh-row.her .sh-bubble.err{border-color:rgba(248,113,113,.5)}',
      '.sh-meta{font-size:11px;color:var(--t2);margin:4px 8px 0}',
      '.sh-bubble p{margin:0 0 8px}.sh-bubble p:last-child{margin:0}.sh-bubble ul,.sh-bubble ol{margin:6px 0 6px 18px;padding:0}',
      '.sh-card{align-self:flex-start;max-width:min(720px,92%);background:var(--s1);border:1px solid var(--bd);border-left:3px solid var(--am);border-radius:var(--rg);padding:10px 14px;font-size:13px;color:var(--t2);line-height:1.5}',
      '.sh-card.appr{border-left-color:var(--p)}.sh-card.fail{border-left-color:var(--rd)}.sh-card.done{border-left-color:var(--ac)}',
      '.sh-card .bar{height:4px;background:var(--s3);border-radius:2px;overflow:hidden;margin-top:8px}.sh-card .bar i{display:block;height:100%;background:var(--am)}',
      '.sh-card .acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}',
      '.sh-btn{min-height:40px;padding:0 14px;border-radius:var(--r);font:600 13px var(--fb);cursor:pointer;border:1px solid var(--bd2);background:transparent;color:var(--t1)}',
      '.sh-btn.primary{background:var(--p);border-color:var(--p);color:#fff}.sh-btn:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.sh-orch{align-self:flex-start;display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:var(--s1);border:1px solid var(--bd);font-size:12.5px;color:var(--t2)}',
      '.sh-orch .dot{width:8px;height:8px;border-radius:50%;background:var(--am);animation:shPulse 1.4s ease-in-out infinite}',
      '.sh-orch .who{display:inline-flex;align-items:center;gap:6px}.sh-orch .who b{color:var(--t1);font-weight:600}.sh-orch .arrow{color:var(--t3)}',
      '@keyframes shPulse{0%,100%{opacity:.35;transform:scale(.85)}50%{opacity:1;transform:scale(1)}}',
      '@media (prefers-reduced-motion:reduce){.sh-orch .dot{animation:none}}',
      '.sh-empty{margin:auto;max-width:520px;text-align:center;color:var(--t2);padding:24px}.sh-empty h2{font:700 22px var(--fh);color:var(--t1);margin:0 0 8px;text-wrap:balance}',
      '.sh-sugg{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:14px}',
      '.sh-sugg button{min-height:40px;padding:0 14px;border-radius:999px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);font:500 13px var(--fb);cursor:pointer}',
      '.sh-sugg button:hover{border-color:var(--p)}.sh-sugg button:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.sh-compose{flex:none;border-top:1px solid var(--bd);background:var(--s1);padding:10px 12px calc(10px + var(--safe-bottom,0px))}',
      '.sh-compose-row{display:flex;align-items:flex-end;gap:8px;max-width:920px;margin:0 auto}',
      '.sh-ta{flex:1;min-height:44px;max-height:160px;resize:none;background:var(--s2);border:1px solid var(--bd2);border-radius:14px;color:var(--t1);padding:11px 14px;font:400 14.5px var(--fb);line-height:1.4}',
      '.sh-ta:focus-visible{outline:2px solid var(--p);outline-offset:1px;border-color:var(--p)}',
      '.sh-send{width:44px;height:44px;border-radius:12px;border:0;background:var(--p);color:#fff;font-size:18px;cursor:pointer;flex:none}',
      '.sh-send[disabled]{opacity:.5;cursor:default}.sh-send:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.sh-attach{width:44px;height:44px;border-radius:12px;border:1px solid var(--bd2);background:transparent;color:var(--t2);cursor:pointer;flex:none;font-size:16px}',
      '.sh-attach:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.sh-hint{max-width:920px;margin:6px auto 0;font-size:11.5px;color:var(--t2);text-align:center}',
      '@media (max-width:640px){.sh-top{padding:10px 12px}.sh-feed{padding:12px}.sh-row{max-width:94%}.sh-ctx b{max-width:40vw}}'
    ].join('');
    document.head.appendChild(st);
  }

  /* ── Mount ───────────────────────────────────────────────────────────────────────────────────── */
  window.sarahLoad = function (root) {
    if (!root) return;
    ensureCss();
    S.root = root;
    if (!S.mounted) {
      root.innerHTML =
        '<div id="sarah-home">' +
          '<div class="sh-top">' +
            '<div class="sh-avatar" aria-hidden="true"></div>' +
            '<div class="sh-who"><div class="sh-name">Sarah</div><div class="sh-role">Your digital marketing manager</div></div>' +
            '<div class="sh-ctx" id="sh-ctx" title="The business Sarah is working for"><span aria-hidden="true">◎</span><b id="sh-ctx-name">…</b></div>' +
          '</div>' +
          '<div class="sh-brief" id="sh-brief" aria-label="Today at a glance"></div>' +
          '<div class="sh-feed" id="sh-feed" role="log" aria-live="polite" aria-relevant="additions" aria-label="Conversation with Sarah"></div>' +
          '<div class="sh-compose">' +
            '<div class="sh-compose-row">' +
              '<button type="button" class="sh-attach" id="sh-attach" aria-label="Attach a file or image" title="Attach a file or image">📎</button>' +
              '<label for="sh-input" style="position:absolute;left:-9999px">Message Sarah</label>' +
              '<textarea id="sh-input" class="sh-ta" rows="1" placeholder="Tell Sarah what you want to achieve…" autocomplete="off"></textarea>' +
              '<button type="button" class="sh-send" id="sh-send" aria-label="Send to Sarah" title="Send (Enter)">↑</button>' +
            '</div>' +
            '<div class="sh-hint" id="sh-hint">Sarah plans, her team does the work, and you approve anything that matters.</div>' +
          '</div>' +
        '</div>';
      S.feed = document.getElementById('sh-feed'); S.input = document.getElementById('sh-input'); S.sendBtn = document.getElementById('sh-send');
      S.sendBtn.addEventListener('click', send);
      S.input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
      S.input.addEventListener('input', function () { S.input.style.height = 'auto'; S.input.style.height = Math.min(160, S.input.scrollHeight) + 'px'; });
      document.getElementById('sh-attach').addEventListener('click', function () {
        if (window.LU_attachComposer && typeof window.LU_attachComposer.pick === 'function') { window.LU_attachComposer.pick('sh-input'); }
        else if (window.LU_attachComposer && typeof window.LU_attachComposer.observe === 'function') { window.LU_attachComposer.observe(S.input); showToast('Drop or paste an image into the message box to attach it.', 'info'); }
        else { showToast('Attachments are not available right now.', 'info'); }
      });
      try { if (window.LU_attachComposer && typeof window.LU_attachComposer.observe === 'function') window.LU_attachComposer.observe(S.input); } catch (e) {}
      S.mounted = true;
    }
    loadContext(); loadBriefing(); loadThread(); startEvents();
  };
  window.sarahUnload = function () { stopEvents(); };

  /* ── Context + briefing (grounded, never invented) ─────────────────────────────────────────── */
  function loadContext() {
    api('GET', 'workspace/status').then(function (r) {
      var w = r.json && (r.json.workspace || {}); var name = w.business_name || w.name || (window.LU_CFG && window.LU_CFG.bn) || '';
      var el = document.getElementById('sh-ctx-name'); if (el) el.textContent = name || 'your business';
    }).catch(function () {});
  }
  function chip(label, value, cls, onclick) {
    var c = document.createElement(onclick ? 'button' : 'div'); c.className = 'sh-chip' + (cls ? ' ' + cls : '');
    if (onclick) { c.type = 'button'; c.setAttribute('role', 'button'); c.addEventListener('click', onclick); }
    c.innerHTML = '<b>' + esc(value) + '</b> ' + esc(label);
    return c;
  }
  function loadBriefing() {
    var wrap = document.getElementById('sh-brief'); if (!wrap) return;
    api('GET', 'dashboard/overview').then(function (r) {
      var d = r.json || {}; var s = d.stats || {}; S.lastFacts = d; wrap.innerHTML = '';
      var att = (d.approvals_pending_total || (d.pending_approvals || []).length || 0);
      if (att > 0) wrap.appendChild(chip(att === 1 ? 'thing needs your OK' : 'things need your OK', att, 'att', function () { if (window.nav) nav('attention'); }));
      if (s.tasks_today > 0) wrap.appendChild(chip('done today', s.tasks_today));
      else if (s.tasks_this_week > 0) wrap.appendChild(chip('done this week', s.tasks_this_week));
      if (s.leads_this_week > 0) wrap.appendChild(chip(s.leads_this_week === 1 ? 'new enquiry this week' : 'new enquiries this week', s.leads_this_week, '', function () { if (window.nav) nav('customers'); }));
      if (s.articles_published > 0) wrap.appendChild(chip(s.articles_published === 1 ? 'article live' : 'articles live', s.articles_published, '', function () { if (window.nav) nav('results'); }));
      if (!wrap.children.length) {
        var w = s.websites_total > 0 ? 'Nothing needs your attention right now.' : 'Nothing is running yet — tell Sarah what you want to achieve.';
        wrap.appendChild(chip('', w));
      }
    }).catch(function () { wrap.innerHTML = ''; });
  }

  /* ── Thread ──────────────────────────────────────────────────────────────────────────────────── */
  function bubble(m) {
    var isUser = m.from === 'User' || m.from === 'user' || m.role === 'user';
    var row = document.createElement('div'); row.className = 'sh-row ' + (isUser ? 'me' : 'her');
    row.innerHTML = '<div class="sh-bubble' + (m.error ? ' err' : '') + '">' + (isUser ? esc(m.content) : fmtBody(m.content)) + '</div>' +
                    '<div class="sh-meta">' + (isUser ? 'You' : 'Sarah') + (m.ts ? ' · ' + esc(ago(m.ts)) : '') + '</div>';
    if (m.id) row.setAttribute('data-mid', String(m.id));
    return row;
  }
  function renderEmpty() {
    var e = document.createElement('div'); e.className = 'sh-empty';
    e.innerHTML = '<h2>What would you like to achieve?</h2><div>Tell Sarah the business outcome — she works out the plan, her team does the work, and you approve anything that matters.</div>' +
      '<div class="sh-sugg">' + ['Get more enquiries this month', 'Write a post about our latest offer', 'How did we do this week?', 'Update the homepage headline'].map(function (t) { return '<button type="button">' + esc(t) + '</button>'; }).join('') + '</div>';
    e.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { S.input.value = b.textContent; S.input.focus(); }); });
    return e;
  }
  function loadThread() {
    S.feed.innerHTML = '<div class="sh-meta" style="align-self:center">Loading your conversation…</div>';
    api('GET', 'agents/' + SLUG + '/messages').then(function (r) {
      var arr = Array.isArray(r.json) ? r.json : [];
      S.feed.innerHTML = ''; S.rendered = {};
      if (!arr.length) { S.feed.appendChild(renderEmpty()); return; }
      arr.forEach(function (m) { if (m.is_ack) return; S.feed.appendChild(bubble(m)); if (m.id) S.rendered[String(m.id)] = 1; });
      S.feed.scrollTop = S.feed.scrollHeight;
    }).catch(function () { S.feed.innerHTML = '<div class="sh-card fail">Couldn\'t load the conversation — <button type="button" class="sh-btn" onclick="sarahLoad(document.getElementById(\'sarah-root\'))">try again</button></div>'; });
  }

  /* ── Send (two-phase: ack → final) ───────────────────────────────────────────────────────────── */
  function setBusy(b) { S.sendBtn.disabled = b; S.input.disabled = b; }
  function send() {
    var text = (S.input.value || '').trim(); if (!text || S.sendBtn.disabled) return;
    var empty = S.feed.querySelector('.sh-empty'); if (empty) empty.remove();
    S.input.value = ''; S.input.style.height = 'auto';
    S.feed.appendChild(bubble({ from: 'User', content: text, ts: null })); S.feed.scrollTop = S.feed.scrollHeight;
    var typing = document.createElement('div'); typing.className = 'sh-orch'; typing.id = 'sh-typing'; typing.innerHTML = '<span class="dot"></span><span>Sarah is thinking…</span>'; S.feed.appendChild(typing); S.feed.scrollTop = S.feed.scrollHeight;
    var body = { content: text, from: 'User' };
    if (window._lgseActiveSiteUrl) body.site_url = window._lgseActiveSiteUrl;
    try { if (window.LU_attachComposer) { var atts = window.LU_attachComposer.getPending('sh-input'); if (atts && atts.length) body.attachments = atts; window.LU_attachComposer.clear('sh-input'); } } catch (e) {}
    setBusy(true);
    api('POST', 'agents/' + SLUG + '/messages', body).then(function (r) {
      setBusy(false); S.input.focus();
      var d = r.json || {};
      try { if (d.chat_meter && typeof window._lgseUpdateChatMeter === 'function') window._lgseUpdateChatMeter(d.chat_meter.counter, !!d.chat_meter.debited); } catch (e) {}
      var t = document.getElementById('sh-typing'); if (t) t.remove();
      if (r.status === 402) { S.feed.appendChild(card({ type: 'failure_notice', content: (d.message || d.error || 'You are out of credits.'), data: { cta: { label: 'See plans', view: 'account' } } })); return; }
      if (!r.ok) { S.feed.appendChild(card({ type: 'failure_notice', content: 'Sarah couldn\'t take that right now' + (d.message || d.error ? ': ' + (d.message || d.error) : '.') })); return; }
      if (d.pending && d.ack) {
        S.feed.appendChild(bubble({ from: 'Sarah', content: d.ack, ts: null })); S.feed.scrollTop = S.feed.scrollHeight;
        showOrch(null); pollFinal(d.ack_message_id || 0, d.poll_interval_ms || POLL_MS); return;
      }
      if (d.reply) { S.feed.appendChild(bubble({ from: 'Sarah', content: d.reply, ts: null, id: d.id })); if (d.id) S.rendered[String(d.id)] = 1; }
      S.feed.scrollTop = S.feed.scrollHeight;
    }).catch(function (e) { setBusy(false); var t = document.getElementById('sh-typing'); if (t) t.remove(); S.feed.appendChild(card({ type: 'failure_notice', content: 'Couldn\'t reach Sarah — check your connection and try again.' })); });
  }
  function pollFinal(ackId, every) {
    var started = Date.now(); if (S.activePoll) clearInterval(S.activePoll);
    S.activePoll = setInterval(function () {
      if (Date.now() - started > POLL_MAX_MS) { clearInterval(S.activePoll); S.activePoll = null; hideOrch(); S.feed.appendChild(card({ type: 'progress_update', content: 'Sarah is still working on this — her reply will appear here when it\'s ready.' })); return; }
      api('GET', 'agents/' + SLUG + '/messages').then(function (r) {
        var arr = Array.isArray(r.json) ? r.json : [];
        for (var i = 0; i < arr.length; i++) { var m = arr[i];
          if (m && m.id && m.id > ackId && !m.is_ack && (m.role === 'agent' || (m.from !== 'User' && m.from !== 'user')) && !S.rendered[String(m.id)]) {
            clearInterval(S.activePoll); S.activePoll = null; hideOrch();
            S.feed.appendChild(bubble(m)); S.rendered[String(m.id)] = 1; S.feed.scrollTop = S.feed.scrollHeight; loadBriefing(); return;   // the reply to what you just asked always comes into view
          } }
      }).catch(function () {});
    }, every);
  }

  /* ── Orchestration strip (real events only) ─────────────────────────────────────────────────── */
  var AGENT_NAMES = { sarah: 'Sarah', dmm: 'Sarah', james: 'James', priya: 'Priya', elena: 'Elena', marcus: 'Marcus', alex: 'Alex', arthur: 'Arthur', studio: 'Studio', diana: 'Diana', ryan: 'Ryan', sofia: 'Sofia' };
  var AGENT_WORK = { james: 'search visibility', priya: 'writing', elena: 'customers', marcus: 'social', alex: 'site health', arthur: 'your website', studio: 'images & video' };
  function showOrch(agentSlug, what) {
    var el = document.getElementById('sh-orch');
    if (!el) { el = document.createElement('div'); el.className = 'sh-orch'; el.id = 'sh-orch'; el.setAttribute('aria-live', 'polite'); S.feed.appendChild(el); }
    var chain = '<span class="who"><b>Sarah</b></span>';
    if (agentSlug && AGENT_NAMES[agentSlug]) chain += '<span class="arrow" aria-hidden="true">→</span><span class="who"><b>' + esc(AGENT_NAMES[agentSlug]) + '</b><span>' + esc(what || AGENT_WORK[agentSlug] || 'working') + '</span></span>';
    el.innerHTML = '<span class="dot" aria-hidden="true"></span>' + chain;
    var was = nearBottom(S.feed); S.feed.appendChild(el); stick(S.feed, was);
  }
  function hideOrch() { var el = document.getElementById('sh-orch'); if (el) el.remove(); }

  /* ── Activity cards from /agent/events ───────────────────────────────────────────────────────── */
  function card(ev) {
    var d = ev.data || {}; var cls = ev.type === 'approval_request' ? 'appr' : ev.type === 'failure_notice' ? 'fail' : ev.type === 'output_preview' ? 'done' : '';
    var el = document.createElement('div'); el.className = 'sh-card ' + cls;
    var html = '<div>' + esc(ev.content || 'Update') + '</div>';
    if (ev.type === 'progress_update' && typeof d.progress === 'number') html += '<div class="bar"><i style="width:' + Math.max(0, Math.min(100, Math.round(d.progress))) + '%"></i></div>';
    var acts = [];
    if (ev.type === 'approval_request') acts.push({ label: 'Review and approve', view: 'attention', primary: true });
    if (ev.type === 'output_preview' && d.view) acts.push({ label: 'Open', view: d.view, tail: d.id });
    if (d.cta && d.cta.view) acts.push({ label: d.cta.label || 'Open', view: d.cta.view, primary: true });
    if (acts.length) html += '<div class="acts">' + acts.map(function (a, i) { return '<button type="button" class="sh-btn' + (a.primary ? ' primary' : '') + '" data-act="' + i + '">' + esc(a.label) + '</button>'; }).join('') + '</div>';
    el.innerHTML = html;
    el.querySelectorAll('[data-act]').forEach(function (b) { b.addEventListener('click', function () { var a = acts[Number(b.getAttribute('data-act'))]; if (a && window.nav) nav(a.view, a.tail ? { tail: a.tail } : undefined); }); });
    return el;
  }
  function handleEvents(events) {
    if (!Array.isArray(events)) return;
    events.forEach(function (ev) {
      if (!ev || !ev.id) return;
      var conv = String(ev.conversation_id || '').replace(/^ws\d+:/, '');
      if (conv && conv !== CONV && conv !== SLUG) return;
      var key = String(ev.id); if (S.rendered[key]) return; S.rendered[key] = 1;
      var was = nearBottom(S.feed);
      if (ev.type === 'message' || ev.type === 'agent_reply') {
        var rowId = key.indexOf('am_') === 0 ? key.slice(3) : key; if (S.rendered[rowId]) return; S.rendered[rowId] = 1;
        if (S.activePoll) { clearInterval(S.activePoll); S.activePoll = null; }
        hideOrch(); S.feed.appendChild(bubble({ from: 'Sarah', content: ev.content, ts: ev.timestamp, id: rowId, error: !!(ev.data && ev.data.error) })); loadBriefing();
      } else if (ev.type === 'task_created' || ev.type === 'task_started' || ev.type === 'delegation') {
        var d = ev.data || {}; showOrch((d.agent_slug || d.agent || '').toLowerCase(), d.action_label || d.label);
      } else if (ev.type === 'progress_update') {
        var dd = ev.data || {}; if (dd.agent_slug || dd.agent) showOrch(String(dd.agent_slug || dd.agent).toLowerCase(), dd.action_label);
        S.feed.appendChild(card(ev));
      } else if (ev.type === 'approval_request' || ev.type === 'output_preview' || ev.type === 'failure_notice' || ev.type === 'task_completed') {
        hideOrch(); S.feed.appendChild(card(ev)); loadBriefing();
      }
      stick(S.feed, was);
    });
  }
  function startEvents() {
    stopEvents(); S.evFails = 0;
    var tick = function () {
      if (!document.getElementById('sarah-home') || document.hidden) return;
      api('GET', 'agent/events?conversation_id=' + encodeURIComponent(CONV) + (S.cursor ? '&cursor=' + encodeURIComponent(S.cursor) : '')).then(function (r) {
        if (!r.ok) { if (++S.evFails >= 5) stopEvents(); return; }
        S.evFails = 0; var j = r.json || {}; if (j.cursor) S.cursor = j.cursor; handleEvents(j.events || []);
      }).catch(function () { if (++S.evFails >= 5) stopEvents(); });
    };
    tick(); S.evTimer = setInterval(tick, POLL_MS);
  }
  function stopEvents() { if (S.evTimer) { clearInterval(S.evTimer); S.evTimer = null; } if (S.activePoll) { clearInterval(S.activePoll); S.activePoll = null; } }
})();
