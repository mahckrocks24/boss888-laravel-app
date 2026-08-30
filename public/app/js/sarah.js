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
      '.sh-chip{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:4px 10px;border-radius:999px;background:var(--s2);border:1px solid var(--bd);font-size:12.5px;color:var(--t2)}',
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
      '.sh-empty{margin:auto;width:100%;max-width:520px;min-width:0;box-sizing:border-box;text-align:center;color:var(--t2);padding:24px}.sh-empty h2{font:700 22px var(--fh);color:var(--t1);margin:0 0 8px;text-wrap:balance}',
      /* SUGG-1 (Owner): the quick picks are ONE row that scrolls sideways — no wrapping, snap per chip, hidden scrollbar, soft edge fade. */
      '.sh-sugg{display:flex;flex-wrap:nowrap;gap:8px;margin:14px -24px 0;padding:2px 24px 6px;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x proximity;scroll-padding:0 24px;-webkit-overflow-scrolling:touch;scrollbar-width:none;-webkit-mask-image:linear-gradient(90deg,transparent,#000 24px,#000 calc(100% - 24px),transparent);mask-image:linear-gradient(90deg,transparent,#000 24px,#000 calc(100% - 24px),transparent)}',
      '.sh-sugg::-webkit-scrollbar{display:none}.sh-sugg button{flex:none;white-space:nowrap;scroll-snap-align:start}',
      '@media (prefers-reduced-motion:no-preference){.sh-sugg{scroll-behavior:smooth}}',
      '.sh-sugg button{min-height:40px;padding:0 14px;border-radius:999px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);font:500 13px var(--fb);cursor:pointer}',
      '.sh-sugg button:hover{border-color:var(--p)}.sh-sugg button:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.sh-compose{flex:none;border-top:1px solid var(--bd);background:var(--s1);padding:10px 12px calc(10px + var(--safe-bottom,0px))}',
      '.sh-compose-row{display:flex;align-items:flex-end;gap:8px;max-width:920px;margin:0 auto}',
      /* COMPOSER-1 (Owner): hairline border + a soft glow at rest, a little stronger with focus; the 2 px focus ring stays for keyboard users. */
      '.sh-ta{flex:1;min-height:44px;max-height:160px;resize:none;background:var(--s2);border:0.5px solid var(--bd);border-radius:14px;color:var(--t1);padding:11px 14px;font:400 14.5px var(--fb);line-height:1.4;box-shadow:0 0 0 1px var(--ps),0 0 18px var(--pg);transition:box-shadow var(--dur-fast,150ms),border-color var(--dur-fast,150ms)}',
      '.sh-ta:hover{box-shadow:0 0 0 1px var(--pg),0 0 22px var(--pg)}',
      '.sh-ta:focus{border-color:var(--p);box-shadow:0 0 0 1px var(--pg),0 0 28px var(--pg),0 0 6px var(--ps)}',
      '.sh-ta:focus-visible{outline:1px solid var(--p);outline-offset:0}',
      '@media (prefers-reduced-motion:reduce){.sh-ta{transition:none}}',
      '.sh-send{width:44px;height:44px;border-radius:12px;border:0;background:var(--p);color:#fff;font-size:18px;cursor:pointer;flex:none}',
      '.sh-send[disabled]{opacity:.5;cursor:default}.sh-send:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.sh-attach{width:44px;height:44px;border-radius:12px;border:1px solid var(--bd2);background:transparent;color:var(--t2);cursor:pointer;flex:none;font-size:16px}',
      '.sh-attach:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '.sh-hint{max-width:920px;margin:6px auto 0;font-size:11.5px;color:var(--t2);text-align:center}',
      '.sh-rail{flex:none;display:flex;flex-direction:column;gap:8px;padding:10px 16px 0;max-height:38vh;overflow:auto}',
      '.sh-rail-track{display:flex;flex-direction:column;gap:8px}',
      '.sh-rail-h{display:flex;align-items:center;justify-content:space-between;gap:8px}.sh-rail-h .pos{letter-spacing:0;text-transform:none;font-variant-numeric:tabular-nums;color:var(--t3)}',
      /* RAIL-2: minimise. The whole header is the button; the chevron shows the state. */
      '.sh-rail-h .tog{display:inline-flex;align-items:center;gap:8px;min-height:36px;padding:0 6px 0 2px;margin:-8px 0 -8px -2px;border:0;background:transparent;color:var(--t3);font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;border-radius:6px}',
      '.sh-rail-h .tog:hover{color:var(--t1)}.sh-rail-h .tog:focus-visible{outline:2px solid var(--p);outline-offset:1px}',
      '.sh-rail-h .tog .chev{width:16px;height:16px;transition:transform var(--dur-fast,150ms)}.sh-rail.min .sh-rail-h .tog .chev{transform:rotate(-90deg)}',
      '.sh-rail.min .sh-rail-track{display:none}.sh-rail.min{padding-bottom:6px}.sh-rail.min .sh-rail-h .pos .swipe{display:none}',
      '@media (prefers-reduced-motion:reduce){.sh-rail-h .tog .chev{transition:none}}',
      /* RAIL-1: on small screens the rail is a horizontal snap strip — one card tall, swipe for the rest. */
      '@media (max-width:767px){.sh-rail{max-height:none !important;overflow:visible;padding-bottom:0}.sh-rail-track{flex-direction:row;gap:10px;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;margin:0 -12px;padding:0 12px 6px;scroll-padding:0 12px}.sh-rail-track::-webkit-scrollbar{display:none}.sh-rail-track>.sh-item{flex:0 0 86%;max-width:340px;scroll-snap-align:start;scroll-snap-stop:always;box-sizing:border-box}.sh-rail-track>.sh-item .d{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.sh-rail-track>.sh-item .acts{margin-top:8px}}',
      '@media (max-width:767px) and (prefers-reduced-motion:no-preference){.sh-rail-track{scroll-behavior:smooth}}',
      '.sh-item{display:flex;gap:12px;align-items:flex-start;background:var(--s1);border:1px solid var(--bd);border-left:3px solid var(--am);border-radius:var(--rg);padding:12px 14px}',
      '.sh-item.gate{border-left-color:var(--bl)}.sh-item.book{border-left-color:var(--ac)}.sh-item.fail{border-left-color:var(--rd)}',
      '.sh-item .ic{flex:none;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--s2);font-size:14px}',
      '.sh-item .body{flex:1;min-width:0}.sh-item .t{font-weight:600;font-size:13.5px;color:var(--t1)}.sh-item .d{font-size:12.5px;color:var(--t2);margin-top:2px;line-height:1.45}',
      '.sh-item .acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}',
      '.sh-item .who{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--t2);margin-top:6px}.sh-item .who b{color:var(--t1);font-weight:600}',
      '.sh-btn.danger{border-color:rgba(248,113,113,.55);color:var(--rd)}',
      '.sh-reason{width:100%;box-sizing:border-box;margin-top:8px;background:var(--s2);border:1px solid var(--bd2);border-radius:var(--r);color:var(--t1);padding:9px 12px;font:400 13px var(--fb);min-height:44px}',
      '.sh-reason:focus-visible{outline:2px solid var(--p);outline-offset:1px}',
      '.sh-rail-h{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--t3);padding:0 2px}',
      '@media (max-width:640px){.sh-top{padding:10px 12px}.sh-feed{padding:12px}.sh-row{max-width:94%}.sh-ctx b{max-width:40vw}.sh-rail{padding:8px 12px 0;max-height:34vh}}'
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
          '<div class="sh-rail" id="sh-rail" aria-label="Needs your attention" hidden></div>' +
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
    loadContext(); loadBriefing(); loadRail(); loadThread(); startEvents();
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

  /* ── Business language for internal slugs ─────────────────────────────────────────────────────── */
  var ACTION_WORDS = { deep_audit: 'a technical check of your website', run_audit: 'a website health check', add_keyword: 'tracking a new search phrase',
    track_keywords: 'checking your search rankings', serp_analysis: 'researching what people search for', generate_links: 'connecting related pages',
    fix_orphans: 'linking pages nobody links to', generate_meta: 'writing page descriptions', meta_optimize: 'improving page descriptions',
    create_article: 'writing an article', write_article: 'writing an article', publish_article: 'publishing an article', improve_draft: 'polishing a draft',
    aeo_enrich: 'making an article easier for AI search to cite', generate_article: 'writing an article',
    create_post: 'writing a social post', social_create_post: 'writing a social post', social_ai_post: 'writing a social post', publish_post: 'publishing a social post', social_publish_post: 'publishing a social post', social_schedule_post: 'scheduling a social post',
    schedule_post: 'scheduling a social post', generate_hashtags: 'choosing hashtags',
    create_lead: 'adding a new enquiry', update_lead: 'updating a customer record', log_activity: 'noting a customer conversation', score_lead: 'prioritising an enquiry',
    generate_outreach: 'drafting a customer email', create_event: 'adding a calendar entry',
    generate_image: 'creating an image', generate_video: 'creating a video', generate_design: 'designing a graphic', export_design: 'exporting a design',
    wizard_generate: 'building your website', generate_page: 'building a page', publish_website: 'publishing your website', arthur_edit: 'editing your website',
    start_meeting: 'planning with the team', end_meeting: 'wrapping up a planning session' };
  function humanAction(a) { a = String(a || '').replace(/^[a-z]+\//, ''); if (ACTION_WORDS[a]) return ACTION_WORDS[a]; if (window.LU_humanize) { try { var h = window.LU_humanize(a); if (h && h !== a) return h; } catch (e) {} } return a.replace(/_/g, ' '); }
  function humanTitle(t) { t = String(t || ''); var k = t.replace(/^[a-z]+\//, ''); return ACTION_WORDS[k] ? ACTION_WORDS[k].charAt(0).toUpperCase() + ACTION_WORDS[k].slice(1) : (t.replace(/_/g, ' ')); }
  // The exact object in Advanced (same authoritative rows the specialist tools edit).
  function deepLink(engine, payload, taskId) {
    payload = payload || {};
    if (payload.article_id) return { view: 'write', tail: payload.article_id, label: 'Open the article' };
    if (payload.post_id) return { view: 'social', tail: payload.post_id, label: 'Open the post' };
    if (payload.design_id) return { view: 'studio', tail: payload.design_id, label: 'Open the design' };
    if (payload.lead_id) return { view: 'crm', tail: payload.lead_id, label: 'Open the enquiry' };
    if (payload.website_id) return { view: 'websites', tail: payload.website_id, label: 'Open the website' };
    var byEngine = { write: 'write', seo: 'seo', social: 'social', studio: 'studio', creative: 'studio', crm: 'crm', calendar: 'calendar', builder: 'websites', chatbot: 'chatbot' };
    if (byEngine[engine]) return { view: byEngine[engine], tail: null, label: 'Open in Advanced' };
    return null;
  }
  function openAdvanced(link) {
    if (!link || !window.nav) return;
    if (typeof window._lgsc_set_visibility_mode === 'function' && document.documentElement.getAttribute('data-mode') !== 'advanced') { window._lgsc_set_visibility_mode('advanced', { skipNav: true }); }
    window.nav(link.view, link.tail ? { tail: link.tail } : undefined);
  }

  /* ── Attention rail: approvals · booking requests · provider gates (all real, all actionable) ── */
  function railItem(cls, icon, title, desc, who, acts, id) {
    var el = document.createElement('div'); el.className = 'sh-item ' + cls; if (id) el.setAttribute('data-item', id);
    el.innerHTML = '<div class="ic" aria-hidden="true">' + icon + '</div><div class="body"><div class="t">' + esc(title) + '</div>' + (desc ? '<div class="d">' + esc(desc) + '</div>' : '') +
      (who ? '<div class="who"><b>' + esc(who) + '</b><span>on your team</span></div>' : '') + (acts && acts.length ? '<div class="acts"></div>' : '') + '</div>';
    var actsEl = el.querySelector('.acts');
    (acts || []).forEach(function (a) { var b = document.createElement('button'); b.type = 'button'; b.className = 'sh-btn' + (a.kind ? ' ' + a.kind : ''); b.textContent = a.label; b.addEventListener('click', function () { a.run(b, el); }); actsEl.appendChild(b); });
    return el;
  }
  function decide(approvalId, action, reason, btn, el) {
    var buttons = el.querySelectorAll('button'); buttons.forEach(function (b) { b.disabled = true; }); btn.textContent = action === 'approve' ? 'Approving…' : 'Sending…';
    api('POST', 'approvals/' + approvalId + '/' + action, reason ? { reason: reason } : {}).then(function (r) {
      var d = r.json || {};
      if (r.ok && (d.success !== false) && !d.error) {
        el.classList.remove('appr'); el.classList.add(action === 'approve' ? 'book' : 'fail');
        el.querySelector('.acts').innerHTML = '<span class="d">' + (action === 'approve' ? 'Approved — your team is on it.' : 'Rejected — nothing will run.') + '</span>';
        showToast(action === 'approve' ? 'Approved — your team is on it.' : 'Rejected — the task was cancelled.', action === 'approve' ? 'success' : 'info');
        setTimeout(function () { el.remove(); loadRail(); loadBriefing(); }, 2200);
      } else {
        buttons.forEach(function (b) { b.disabled = false; }); btn.textContent = action === 'approve' ? 'Approve' : 'Reject';
        showToast((action === 'approve' ? 'Couldn\'t approve: ' : 'Couldn\'t reject: ') + (d.message || d.error || ('HTTP ' + r.status)), 'error');
      }
    }).catch(function () { buttons.forEach(function (b) { b.disabled = false; }); btn.textContent = action === 'approve' ? 'Approve' : 'Reject'; showToast('Couldn\'t reach the server — try again.', 'error'); });
  }
  function approvalItem(a) {
    var t = a.task || {}; var eng = t.engine || 'system';
    var title = t.label || humanTitle(t.action || a.title || 'Something needs your OK');
    var cost = t.credit_cost ? (t.credit_cost + (t.credit_cost === 1 ? ' credit' : ' credits')) : 'no credits';
    var desc = (t.description ? t.description + ' · ' : '') + 'Uses ' + cost + (a.time_ago ? ' · asked ' + a.time_ago : '');
    var who = t.agent && t.agent.name ? t.agent.name : (t.primary_agent ? AGENT_NAMES[t.primary_agent] || t.primary_agent : null);
    var link = deepLink(eng, t.payload || {}, t.id);
    var acts = [
      { label: 'Approve', kind: 'primary', run: function (b, el) { decide(a.id, 'approve', null, b, el); } },
      { label: 'Reject', kind: 'danger', run: function (b, el) {
          var box = el.querySelector('.sh-reason'); if (!box) {
            box = document.createElement('textarea'); box.className = 'sh-reason'; box.rows = 2; box.placeholder = 'Why not? (Sarah learns from this)'; box.setAttribute('aria-label', 'Reason for rejecting'); el.querySelector('.body').insertBefore(box, el.querySelector('.acts')); box.focus(); b.textContent = 'Confirm reject'; return; }
          var reason = box.value.trim(); if (!reason) { box.focus(); showToast('Add a short reason so Sarah knows what to change.', 'warning'); return; }
          decide(a.id, 'reject', reason, b, el); } }
    ];
    if (link) acts.push({ label: link.label, run: function () { openAdvanced(link); } });
    return railItem('appr', '✓', title, desc, who, acts, 'appr-' + a.id);
  }
  function loadRail() {
    var rail = document.getElementById('sh-rail'); if (!rail) return;
    Promise.all([
      api('GET', 'approvals?status=pending&per_page=5').catch(function () { return { json: null }; }),
      api('GET', 'calendar/events').catch(function () { return { json: null }; }),
      api('GET', 'social/accounts').catch(function () { return { json: null }; }),
      api('GET', 'seo/gsc/status').catch(function () { return { json: null }; })
    ]).then(function (rs) {
      var items = [];
      var appr = (rs[0].json && rs[0].json.items) || [];
      appr.forEach(function (a) { items.push(approvalItem(a)); });
      var evs = Array.isArray(rs[1].json) ? rs[1].json : ((rs[1].json && (rs[1].json.events || rs[1].json.data)) || []);
      evs.filter(function (e) { return e && /booking_pending|pending/.test(String(e.status || e.booking_status || '')) && !/cancel|declin/.test(String(e.status || '')); }).slice(0, 3).forEach(function (e) {
        items.push(railItem('book', '📅', 'Booking request — ' + (e.title || e.name || 'a customer').replace(/^Booking request — /, ''), (e.starts_at ? 'Asked for ' + new Date(String(e.starts_at).replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '') , null,
          [{ label: 'Reply in Customers', kind: 'primary', run: function () { if (window.nav) nav('customers'); } }], 'book-' + e.id));
      });
      var accs = Array.isArray(rs[2].json) ? rs[2].json : ((rs[2].json && (rs[2].json.accounts || rs[2].json.data)) || []);
      var socialConnected = accs.some(function (x) { return x && (x.status === 'active' || x.connected || x.is_active); });
      S.gates = { social: socialConnected, gsc: !!(rs[3].json && rs[3].json.connected) };
      if (!socialConnected) items.push(railItem('gate', '🔗', 'Facebook / Instagram not connected', 'Sarah can write and schedule posts now; publishing them for real needs a connected account.', null,
        [{ label: 'Connect an account', run: function () { openAdvanced({ view: 'social', tail: null }); } }], 'gate-social'));
      if (!(rs[3].json && rs[3].json.connected)) items.push(railItem('gate', '📈', 'Google Search Console not connected', 'Until it is connected, Sarah can\'t see clicks or rankings — she\'ll say so instead of guessing.', null,
        [{ label: 'Connect Google', run: function () { openAdvanced({ view: 'seo', tail: null }); } }], 'gate-gsc'));
      rail.innerHTML = '';
      if (!items.length) { rail.hidden = true; return; }
      var title = appr.length ? 'Needs your OK' : 'Worth knowing';
      var h = document.createElement('div'); h.className = 'sh-rail-h';
      h.innerHTML = '<button type="button" class="tog" aria-expanded="true" aria-controls="sh-rail-track" title="Minimise"><svg class="chev" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 6l5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="ttl">' + title + '</span></button><span class="pos" aria-live="polite"></span>';
      rail.appendChild(h);
      /* RAIL-2: minimised state is remembered per device; a NEW approval reopens it. */
      var seenKey = 'lu_rail_seen', minKey = 'lu_rail_min', seen = [], isMin = false;
      try { seen = JSON.parse(localStorage.getItem(seenKey) || '[]'); isMin = localStorage.getItem(minKey) === '1'; } catch (e) {}
      var apprIds = appr.map(function (a) { return String(a.id); });
      var fresh = apprIds.filter(function (id) { return seen.indexOf(id) < 0; });
      if (fresh.length) { isMin = false; try { localStorage.setItem(minKey, '0'); localStorage.setItem(seenKey, JSON.stringify(seen.concat(fresh).slice(-50))); } catch (e) {} }
      function setMin(v) { isMin = !!v; rail.classList.toggle('min', isMin); var b = h.querySelector('.tog'); b.setAttribute('aria-expanded', isMin ? 'false' : 'true'); b.title = isMin ? 'Show' : 'Minimise'; try { localStorage.setItem(minKey, isMin ? '1' : '0'); } catch (e) {} if (typeof updPos === 'function') updPos(); }
      h.querySelector('.tog').addEventListener('click', function () { setMin(!isMin); });
      if (!appr.length && items.length) { /* gates only: keep them quiet, below the fold of the conversation */ rail.style.maxHeight = '22vh'; } else { rail.style.maxHeight = ''; }
      /* RAIL-1: items live in a track — vertical list on desktop, horizontal snap strip on small screens. */
      var track = document.createElement('div'); track.className = 'sh-rail-track'; track.id = 'sh-rail-track'; track.setAttribute('role', 'list');
      items.forEach(function (i) { i.setAttribute('role', 'listitem'); track.appendChild(i); }); rail.appendChild(track); rail.hidden = false;
      var pos = h.querySelector('.pos');
      function updPos() { if (!pos) return; var n = items.length; if (isMin) { pos.textContent = n + (n === 1 ? ' item' : ' items'); return; } var mobile = window.matchMedia && matchMedia('(max-width:767px)').matches; if (!mobile || n < 2) { pos.textContent = n > 1 ? n + ' items' : ''; return; } var w = (items[0].getBoundingClientRect().width || 1) + 10; var idx = Math.min(n, Math.round(track.scrollLeft / w) + 1); pos.innerHTML = idx + ' of ' + n + '<span class="swipe"> · swipe</span>'; }
      track.addEventListener('scroll', function () { if (track._t) return; track._t = setTimeout(function () { track._t = null; updPos(); }, 80); }, { passive: true });
      window.addEventListener('resize', updPos); setMin(isMin);
    });
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
            S.feed.appendChild(bubble(m)); S.rendered[String(m.id)] = 1; S.feed.scrollTop = S.feed.scrollHeight; loadBriefing(); loadRail(); return;   // the reply to what you just asked always comes into view
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
    if (d.link && d.link.view) acts.push({ label: d.link.label || 'Open in Advanced', view: d.link.view, tail: d.link.tail, advanced: true });
    if (d.retryText) acts.push({ label: 'Try again', primary: true, say: d.retryText });
    if (d.cta && d.cta.view) acts.push({ label: d.cta.label || 'Open', view: d.cta.view, primary: true });
    if (acts.length) html += '<div class="acts">' + acts.map(function (a, i) { return '<button type="button" class="sh-btn' + (a.primary ? ' primary' : '') + '" data-act="' + i + '">' + esc(a.label) + '</button>'; }).join('') + '</div>';
    el.innerHTML = html;
    el.querySelectorAll('[data-act]').forEach(function (b) { b.addEventListener('click', function () { var a = acts[Number(b.getAttribute('data-act'))]; if (!a) return;
      if (a.say) { S.input.value = a.say; S.input.focus(); return; }
      if (a.advanced) { openAdvanced({ view: a.view, tail: a.tail }); return; }
      if (window.nav) nav(a.view, a.tail ? { tail: a.tail } : undefined); }); });
    return el;
  }
  function handleEvents(events) {
    if (!Array.isArray(events)) return;
    events.forEach(function (ev) {
      if (!ev || !ev.id) return;
      var conv = String(ev.conversation_id || '').replace(/^ws\d+:/, '');
      // Sarah's own replies stay Sarah-only; her TEAM's task lifecycle (conversation_id = the assigned agent) is
      // exactly what the customer should see happening — that is the visible orchestration.
      var isTeamWork = /^task_|^progress_update$/.test(String(ev.type || ''));
      if (!isTeamWork && conv && conv !== CONV && conv !== SLUG) return;
      var key = String(ev.id); if (S.rendered[key]) return; S.rendered[key] = 1;
      if (ev.task_id && (ev.type === 'task_completed' || ev.type === 'task_failed')) { var tk = 'task:' + ev.task_id + ':' + ev.type; if (S.rendered[tk]) return; S.rendered[tk] = 1; }
      var was = nearBottom(S.feed);
      if (ev.type === 'message' || ev.type === 'agent_reply') {
        var rowId = key.indexOf('am_') === 0 ? key.slice(3) : key; if (S.rendered[rowId]) return; S.rendered[rowId] = 1;
        if (S.activePoll) { clearInterval(S.activePoll); S.activePoll = null; }
        hideOrch(); S.feed.appendChild(bubble({ from: 'Sarah', content: ev.content, ts: ev.timestamp, id: rowId, error: !!(ev.data && ev.data.error) })); loadBriefing();
      } else if (ev.type === 'task_created' || ev.type === 'task_started' || ev.type === 'delegation') {
        var d = ev.data || {}; var ag = String(ev.agent_id || d.agent_slug || d.agent || (d.assigned_agents && d.assigned_agents[0]) || '').toLowerCase();
        showOrch(ag, humanAction(d.title || d.action_label || d.label));
      } else if (ev.type === 'progress_update') {
        var dd = ev.data || {}; var ag2 = String(ev.agent_id || dd.agent_slug || dd.agent || (dd.assigned_agents && dd.assigned_agents[0]) || '').toLowerCase();
        if (ag2) showOrch(ag2, humanAction(dd.title || dd.action_label));
        // "Step executed"/"Step verified" are engine bookkeeping — the strip already shows the work; only a step
        // that says something in plain language (or carries progress) earns a card.
        var plain = String(ev.content || '').trim();
        if (plain && !/^step (executed|verified)\.?$/i.test(plain)) S.feed.appendChild(card(Object.assign({}, ev, { content: (AGENT_NAMES[ag2] ? AGENT_NAMES[ag2] + ': ' : '') + plain })));
      } else if (ev.type === 'task_completed') {
        hideOrch(); var d3 = ev.data || {}; var ag3 = String(ev.agent_id || (d3.assigned_agents && d3.assigned_agents[0]) || '').toLowerCase();
        var doneMsg = String(ev.content || '').trim(); if (/^task completed successfully\.?$/i.test(doneMsg)) doneMsg = '';
        S.feed.appendChild(card({ id: ev.id, type: 'output_preview', content: (AGENT_NAMES[ag3] || 'Your team') + ' finished ' + humanAction(d3.title) + (doneMsg ? ' — ' + doneMsg : '.'), data: { link: deepLink((d3.title || '').split('/')[0], d3.payload || d3, ev.task_id) } }));
        loadBriefing(); loadRail();
      } else if (ev.type === 'task_failed') {
        hideOrch(); var d4 = ev.data || {};
        S.feed.appendChild(card({ id: ev.id, type: 'failure_notice', content: 'Something went wrong while ' + humanAction(d4.title) + (ev.content ? ': ' + ev.content : '.') + ' Sarah can try again — just say so.', data: { retryText: 'Please try ' + humanAction(d4.title) + ' again.' } }));
        loadBriefing(); loadRail();
      } else if (ev.type === 'approval_request') {
        hideOrch(); S.feed.appendChild(card(ev)); loadBriefing(); loadRail();
      } else if (ev.type === 'output_preview' || ev.type === 'failure_notice') {
        hideOrch(); S.feed.appendChild(card(ev)); loadBriefing();
      }
      stick(S.feed, was);
    });
  }
  function startEvents() {
    stopEvents(); S.evFails = 0;
    var tick = function () {
      if (!document.getElementById('sarah-home')) return;
      // A hidden tab still receives its team's events (a customer switches tabs while work runs); it just polls
      // less often — every 4th tick — so the stream never goes silent and the cursor never falls behind.
      S.tickN = (S.tickN || 0) + 1; if (document.hidden && (S.tickN % 4) !== 0) return;
      api('GET', 'agent/events' + (S.cursor ? '?cursor=' + encodeURIComponent(S.cursor) : '')).then(function (r) {
        if (!r.ok) { if (++S.evFails >= 5) stopEvents(); return; }
        S.evFails = 0; var j = r.json || {}; if (j.cursor) S.cursor = j.cursor; handleEvents(j.events || []);
      }).catch(function () { if (++S.evFails >= 5) stopEvents(); });
    };
    tick(); S.evTimer = setInterval(tick, POLL_MS);
  }
  function stopEvents() { if (S.evTimer) { clearInterval(S.evTimer); S.evTimer = null; } if (S.activePoll) { clearInterval(S.activePoll); S.activePoll = null; } }
})();
