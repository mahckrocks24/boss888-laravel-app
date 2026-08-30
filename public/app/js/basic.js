/* ═══════════════════════════════════════════════════════════════════════════════════════════════════
   basic.js — the supporting Basic surfaces. P3 (2026-08-30, REPORT-0023 §6, Owner direction).
   Needs attention · Results · Website · Customers · Account. Each reads the SAME endpoints and opens the SAME
   objects the Advanced tools use (no parallel data), speaks business language, and offers "Ask Sarah" /
   "Open in Advanced". Grounding rule (CP-0415): MEASURED ≠ INFERRED ≠ NOT CONNECTED — never a number the
   workspace doesn't own. Semantic, keyboard-operable, 44 px targets, focus-visible, mobile-first.
   ═══════════════════════════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function hdr() { var h = { Accept: 'application/json' }; var t = localStorage.getItem('lu_token'); if (t) h.Authorization = 'Bearer ' + t; return h; }
  function api(method, path, body) {
    var o = { method: method, headers: hdr() };
    if (body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    return fetch('/api/' + path.replace(/^\//, ''), o).then(function (r) { return r.text().then(function (t) { var j = null; try { j = t ? JSON.parse(t) : null; } catch (e) {} return { ok: r.ok, status: r.status, json: j }; }); });
  }
  function ago(ts) { if (!ts) return ''; var d = window._luParseTs ? window._luParseTs(ts) : new Date(String(ts).replace(' ', 'T') + (String(ts).indexOf('Z') > -1 || String(ts).indexOf('+') > -1 ? '' : 'Z')); var m = Math.round((Date.now() - d) / 60000); if (m < 1) return 'just now'; if (m < 60) return m + ' min ago'; if (m < 1440) return Math.floor(m / 60) + ' h ago'; return Math.floor(m / 1440) + ' d ago'; }
  function when(ts) { if (!ts) return ''; try { return new Date(String(ts).replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return String(ts); } }
  var AGENT_NAMES = { sarah: 'Sarah', dmm: 'Sarah', james: 'James', priya: 'Priya', elena: 'Elena', marcus: 'Marcus', alex: 'Alex', arthur: 'Arthur' };
  function askSarah(text) { if (typeof window._lgsc_set_visibility_mode === 'function' && document.documentElement.getAttribute('data-mode') !== 'basic') { window._lgsc_set_visibility_mode('basic', { skipNav: true }); } if (window.nav) { window.nav('sarah'); } setTimeout(function () { var i = document.getElementById('sh-input'); if (i) { i.value = text || ''; i.focus(); } }, 400); }
  function openAdvanced(view, tail) { if (typeof window._lgsc_set_visibility_mode === 'function' && document.documentElement.getAttribute('data-mode') !== 'advanced') { window._lgsc_set_visibility_mode('advanced', { skipNav: true }); } if (window.nav) window.nav(view, tail ? { tail: tail } : undefined); }

  function ensureCss() {
    if (document.getElementById('basic-css')) return;
    var st = document.createElement('style'); st.id = 'basic-css';
    st.textContent = [
      '.bs{display:flex;flex-direction:column;height:100%;min-height:0;overflow:auto;background:var(--bg);color:var(--t1);font-family:var(--fb)}',
      '.bs-wrap{max-width:980px;width:100%;margin:0 auto;padding:20px 16px 48px;display:flex;flex-direction:column;gap:18px}',
      '.bs-h{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap}',
      '.bs-h h1{font:700 26px var(--fh);letter-spacing:-.02em;margin:0;text-wrap:balance}.bs-h p{margin:4px 0 0;color:var(--t2);font-size:13.5px;max-width:62ch}',
      '.bs-acts{display:flex;gap:8px;flex-wrap:wrap}',
      '.bs-btn{min-height:44px;padding:0 16px;border-radius:var(--r);font:600 13.5px var(--fb);cursor:pointer;border:1px solid var(--bd2);background:var(--s1);color:var(--t1);display:inline-flex;align-items:center;gap:8px}',
      '.bs-btn.primary{background:var(--p);border-color:var(--p);color:#fff}.bs-btn.quiet{background:transparent}.bs-btn.danger{border-color:rgba(248,113,113,.55);color:var(--rd)}',
      '.bs-btn:focus-visible{outline:2px solid var(--p);outline-offset:2px}.bs-btn[disabled]{opacity:.55;cursor:default}',
      '.bs-sec{background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:16px 18px;display:flex;flex-direction:column;gap:12px}',
      '.bs-sec h2{font:700 15px var(--fh);margin:0;letter-spacing:-.01em;display:flex;align-items:center;gap:10px}.bs-sec h2 .n{font:600 12px var(--fb);color:var(--t2);background:var(--s2);border-radius:999px;padding:2px 9px}',
      '.bs-sub{color:var(--t2);font-size:13px;margin:-6px 0 0}',
      '.bs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}',
      '.bs-kpi{background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:12px 14px;min-height:88px;display:flex;flex-direction:column;justify-content:space-between}',
      '.bs-kpi .l{font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--t2)}.bs-kpi .v{font:700 26px var(--fh);font-variant-numeric:tabular-nums;line-height:1.1;margin-top:6px}',
      '.bs-kpi .m{font-size:12px;color:var(--t2);margin-top:4px}.bs-kpi.gated .v{color:var(--t2);font-size:18px}',
      '.bs-tag{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;border-radius:999px;padding:3px 9px;background:var(--s3);color:var(--t2)}',
      '.bs-tag.measured{color:var(--ac);background:rgba(0,229,168,.12)}.bs-tag.gated{color:var(--bl);background:rgba(59,139,245,.14)}.bs-tag.warn{color:var(--am);background:rgba(245,158,11,.14)}.bs-tag.bad{color:var(--rd);background:rgba(248,113,113,.14)}.bs-tag.ok{color:var(--ac);background:rgba(0,229,168,.12)}',
      '.bs-list{display:flex;flex-direction:column;gap:8px}',
      '.bs-row{display:flex;gap:12px;align-items:flex-start;background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:12px 14px}',
      '.bs-row .ic{flex:none;width:34px;height:34px;border-radius:50%;background:var(--s3);display:flex;align-items:center;justify-content:center;font-size:14px}',
      '.bs-row .b{flex:1;min-width:0}.bs-row .t{font-weight:600;font-size:14px}.bs-row .d{font-size:12.5px;color:var(--t2);margin-top:2px;line-height:1.45}.bs-row .a{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}',
      '.bs-row.att{border-left:3px solid var(--am)}.bs-row.gate{border-left:3px solid var(--bl)}.bs-row.book{border-left:3px solid var(--ac)}.bs-row.fail{border-left:3px solid var(--rd)}',
      '.bs-empty{padding:22px;text-align:center;color:var(--t2);font-size:13.5px;border:1px dashed var(--bd2);border-radius:var(--r)}.bs-empty b{color:var(--t1);display:block;font:700 15px var(--fh);margin-bottom:4px}',
      '.bs-reason{width:100%;box-sizing:border-box;margin-top:8px;background:var(--s1);border:1px solid var(--bd2);border-radius:var(--r);color:var(--t1);padding:9px 12px;font:400 13px var(--fb);min-height:44px}.bs-reason:focus-visible{outline:2px solid var(--p);outline-offset:1px}',
      '.bs-field{display:flex;flex-direction:column;gap:6px}.bs-field label{font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--t2)}',
      '.bs-field input,.bs-field textarea,.bs-field select{width:100%;box-sizing:border-box;background:var(--s2);border:1px solid var(--bd2);border-radius:var(--r);color:var(--t1);padding:11px 13px;font:400 14px var(--fb);min-height:44px}',
      '.bs-field input:focus-visible,.bs-field textarea:focus-visible,.bs-field select:focus-visible{outline:2px solid var(--p);outline-offset:1px;border-color:var(--p)}',
      '.bs-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}',
      '.bs-swatch{display:inline-flex;align-items:center;gap:8px}.bs-swatch i{width:20px;height:20px;border-radius:6px;border:1px solid var(--bd2)}',
      '.bs-skel{height:14px;border-radius:6px;background:linear-gradient(90deg,var(--s2),var(--s3),var(--s2));background-size:200% 100%;animation:bsSk 1.2s infinite}',
      '@keyframes bsSk{0%{background-position:200% 0}100%{background-position:-200% 0}}@media (prefers-reduced-motion:reduce){.bs-skel{animation:none}}',
      '.bs-ver{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--bd)}.bs-ver:last-child{border-bottom:0}',
      '@media (max-width:640px){.bs-wrap{padding:14px 12px 40px}.bs-h h1{font-size:22px}.bs-sec{padding:14px}}'
    ].join('');
    document.head.appendChild(st);
  }
  function shell(title, sub, acts) {
    return '<div class="bs"><div class="bs-wrap"><div class="bs-h"><div><h1>' + esc(title) + '</h1>' + (sub ? '<p>' + esc(sub) + '</p>' : '') + '</div><div class="bs-acts">' + (acts || '') + '</div></div><div id="bs-body" style="display:flex;flex-direction:column;gap:18px"></div></div></div>';
  }
  function btn(label, cls, onclick, attrs) { var b = document.createElement('button'); b.type = 'button'; b.className = 'bs-btn' + (cls ? ' ' + cls : ''); b.textContent = label; if (onclick) b.addEventListener('click', function () { onclick(b); }); if (attrs) Object.keys(attrs).forEach(function (k) { b.setAttribute(k, attrs[k]); }); return b; }
  function sec(title, count, sub) { var s = document.createElement('section'); s.className = 'bs-sec'; s.innerHTML = '<h2>' + esc(title) + (count != null ? '<span class="n">' + esc(count) + '</span>' : '') + '</h2>' + (sub ? '<p class="bs-sub">' + esc(sub) + '</p>' : ''); return s; }
  function empty(title, text) { var e = document.createElement('div'); e.className = 'bs-empty'; e.innerHTML = '<b>' + esc(title) + '</b>' + esc(text || ''); return e; }
  function row(cls, icon, title, desc, actions) { var r = document.createElement('div'); r.className = 'bs-row ' + (cls || ''); r.innerHTML = '<div class="ic" aria-hidden="true">' + icon + '</div><div class="b"><div class="t">' + esc(title) + '</div>' + (desc ? '<div class="d">' + esc(desc) + '</div>' : '') + '</div>'; if (actions && actions.length) { var a = document.createElement('div'); a.className = 'a'; actions.forEach(function (x) { a.appendChild(x); }); r.querySelector('.b').appendChild(a); } return r; }
  function kpi(label, value, meta, cls) { var k = document.createElement('div'); k.className = 'bs-kpi' + (cls ? ' ' + cls : ''); k.innerHTML = '<div class="l">' + esc(label) + '</div><div class="v">' + esc(value) + '</div>' + (meta ? '<div class="m">' + meta + '</div>' : ''); return k; }
  function skel(root, n) { root.innerHTML = ''; for (var i = 0; i < (n || 3); i++) { var s = document.createElement('div'); s.className = 'bs-sec'; s.innerHTML = '<div class="bs-skel" style="width:40%"></div><div class="bs-skel"></div><div class="bs-skel" style="width:70%"></div>'; root.appendChild(s); } }
  function fail(root, what, retry) { root.innerHTML = ''; var e = empty("Couldn't load " + what, 'Check your connection and try again.'); var b = btn('Try again', 'primary', retry); b.style.marginTop = '10px'; e.appendChild(b); root.appendChild(e); }

  /* ═══ NEEDS ATTENTION ═══════════════════════════════════════════════════════════════════════════ */
  window.basicAttentionLoad = function (root) {
    ensureCss(); root.innerHTML = shell('Needs attention', 'Approvals, booking requests and anything only you can decide. Everything else, Sarah handles.',
      '');
    var body = root.querySelector('#bs-body'); skel(body, 2);
    Promise.all([api('GET', 'approvals?status=pending&per_page=20'), api('GET', 'calendar/events'), api('GET', 'social/accounts'), api('GET', 'seo/gsc/status'), api('GET', 'tasks')])
      .then(function (rs) {
        body.innerHTML = '';
        var appr = (rs[0].json && rs[0].json.items) || [];
        var s1 = sec('Waiting for your OK', appr.length, appr.length ? 'Each of these was proposed by Sarah. Approve to let the team run it, or reject with a reason so she adjusts.' : null); body.appendChild(s1);
        if (!appr.length) s1.appendChild(empty('Nothing needs your OK', 'Sarah will ask here — and in your conversation — when something matters.'));
        appr.forEach(function (a) { s1.appendChild(approvalRow(a)); });

        var evs = Array.isArray(rs[1].json) ? rs[1].json : ((rs[1].json && (rs[1].json.events || rs[1].json.data)) || []);
        var pend = evs.filter(function (e) { return /^(booking|callback)_pending$/.test(String(e.category || '')); });
        var s2 = sec('Booking requests', pend.length, pend.length ? 'Customers who asked for a time. Confirm or decline — they are told either way.' : null); body.appendChild(s2);
        if (!pend.length) s2.appendChild(empty('No booking requests waiting', 'New requests from your website or chatbot land here.'));
        pend.forEach(function (e) { s2.appendChild(bookingRow(e)); });

        var failed = Array.isArray(rs[4].json && (rs[4].json.tasks || rs[4].json)) ? (rs[4].json.tasks || rs[4].json) : [];
        failed = failed.filter(function (t) { return t && t.status === 'failed'; }).slice(0, 5);
        if (failed.length) { var s3 = sec('Something went wrong', failed.length, 'Work that stopped. Ask Sarah to try again, or open it in Advanced.'); body.appendChild(s3);
          failed.forEach(function (t) { var agents = t.assigned_agents_json || t.assigned_agents || []; if (typeof agents === 'string') { try { agents = JSON.parse(agents); } catch (e) { agents = []; } } var name = agents[0] || ({ social: 'marcus', seo: 'james', write: 'priya', crm: 'elena', builder: 'arthur' })[t.engine] || null;
            var act = humanAction(t.action || ''); var why = String(t.progress_message || t.error_message || '').replace(/^Failed:\s*/i, '').replace(new RegExp('^' + String(t.action || '').replace(/_/g, ' ') + '$', 'i'), '');
            s3.appendChild(row('fail', '!', (AGENT_NAMES[name] || 'Your team') + ' couldn\'t finish ' + act, (why ? why + ' · ' : '') + (t.updated_at ? ago(t.updated_at) : ''), [btn('Ask Sarah to try again', 'primary', function () { askSarah('Please try ' + act + ' again.'); }), btn('Open in Advanced', 'quiet', function () { openAdvanced('command'); })])); }); }

        var accs = Array.isArray(rs[2].json) ? rs[2].json : ((rs[2].json && (rs[2].json.accounts || rs[2].json.data)) || []);
        var social = accs.some(function (x) { return x && (x.status === 'active' || x.connected || x.is_active); });
        var gsc = !!(rs[3].json && rs[3].json.connected);
        var s4 = sec('Connections', null, 'What is and isn\'t connected. Sarah works around gaps and tells you when one limits her.'); body.appendChild(s4);
        s4.appendChild(row(social ? 'book' : 'gate', '🔗', social ? 'Facebook / Instagram connected' : 'Facebook / Instagram not connected', social ? 'Sarah can publish posts for you.' : 'Sarah can write and schedule posts now; publishing them for real needs a connected account.', social ? [] : [btn('Connect an account', 'primary', function () { openAdvanced('social'); })]));
        s4.appendChild(row(gsc ? 'book' : 'gate', '📈', gsc ? 'Google Search Console connected' : 'Google Search Console not connected', gsc ? 'Sarah can see clicks and rankings.' : 'Until it is connected, Sarah can\'t see clicks or rankings — she says so instead of guessing.', gsc ? [] : [btn('Connect Google', 'primary', function () { openAdvanced('seo'); })]));
      }).catch(function () { fail(body, 'what needs attention', function () { window.basicAttentionLoad(root); }); });
  };
  var ACTION_WORDS = { deep_audit: 'a technical check of your website', run_audit: 'a website health check', add_keyword: 'tracking a new search phrase', track_keywords: 'checking your search rankings', serp_analysis: 'researching what people search for', generate_links: 'connecting related pages', fix_orphans: 'linking pages nobody links to', generate_meta: 'writing page descriptions', create_article: 'writing an article', write_article: 'writing an article', publish_article: 'publishing an article', improve_draft: 'polishing a draft', aeo_enrich: 'making an article easier for AI search to cite', create_post: 'writing a social post', social_create_post: 'writing a social post', social_ai_post: 'writing a social post', publish_post: 'publishing a social post', social_publish_post: 'publishing a social post', social_schedule_post: 'scheduling a social post', schedule_post: 'scheduling a social post', create_lead: 'adding a new enquiry', update_lead: 'updating a customer record', log_activity: 'noting a customer conversation', generate_image: 'creating an image', generate_video: 'creating a video', generate_design: 'designing a graphic', wizard_generate: 'building your website', publish_website: 'publishing your website', arthur_edit: 'editing your website' };
  function humanAction(a) { a = String(a || '').replace(/^[a-z]+\//, ''); return ACTION_WORDS[a] || (window.LU_humanize ? window.LU_humanize(a) : a.replace(/_/g, ' ')); }
  function deepLink(engine, payload) { payload = payload || {}; if (payload.article_id) return ['write', payload.article_id, 'Open the article']; if (payload.post_id) return ['social', payload.post_id, 'Open the post']; if (payload.design_id) return ['studio', payload.design_id, 'Open the design']; if (payload.lead_id) return ['crm', payload.lead_id, 'Open the enquiry']; if (payload.website_id) return ['websites', payload.website_id, 'Open the website']; var m = { write: 'write', seo: 'seo', social: 'social', studio: 'studio', crm: 'crm', builder: 'websites', calendar: 'calendar' }; return m[engine] ? [m[engine], null, 'Open in Advanced'] : null; }
  function approvalRow(a) {
    var t = a.task || {}; var who = (t.agent && t.agent.name) || AGENT_NAMES[t.primary_agent] || 'Sarah';
    var cost = t.credit_cost ? t.credit_cost + (t.credit_cost === 1 ? ' credit' : ' credits') : 'no credits';
    var link = deepLink(t.engine, t.payload); var acts = [];
    var approve = btn('Approve', 'primary', function (b) { decide(a.id, 'approve', null, b, r); });
    var reject = btn('Reject', 'danger', function (b) { var box = r.querySelector('.bs-reason'); if (!box) { box = document.createElement('textarea'); box.className = 'bs-reason'; box.rows = 2; box.placeholder = 'Why not? Sarah learns from this'; box.setAttribute('aria-label', 'Reason for rejecting'); r.querySelector('.b').insertBefore(box, r.querySelector('.a')); box.focus(); b.textContent = 'Confirm reject'; return; } var reason = box.value.trim(); if (!reason) { box.focus(); showToast('Add a short reason so Sarah knows what to change.', 'warning'); return; } decide(a.id, 'reject', reason, b, r); });
    acts.push(approve, reject); if (link) acts.push(btn(link[2], 'quiet', function () { openAdvanced(link[0], link[1]); }));
    var r = row('att', '✓', t.label || humanAction(t.action) || 'Something needs your OK', (t.description ? t.description + ' · ' : '') + who + ' · uses ' + cost + (a.time_ago ? ' · asked ' + a.time_ago : ''), acts);
    return r;
  }
  function decide(id, action, reason, b, r) {
    var all = r.querySelectorAll('button'); all.forEach(function (x) { x.disabled = true; }); b.textContent = action === 'approve' ? 'Approving…' : 'Sending…';
    api('POST', 'approvals/' + id + '/' + action, reason ? { reason: reason } : {}).then(function (res) { var d = res.json || {};
      if (res.ok && d.success !== false && !d.error) { r.classList.remove('att'); r.classList.add(action === 'approve' ? 'book' : 'fail'); r.querySelector('.a').innerHTML = '<span class="d">' + (action === 'approve' ? 'Approved — your team is on it.' : 'Rejected — nothing will run.') + '</span>'; showToast(action === 'approve' ? 'Approved — your team is on it.' : 'Rejected — the task was cancelled.', action === 'approve' ? 'success' : 'info'); }
      else { all.forEach(function (x) { x.disabled = false; }); b.textContent = action === 'approve' ? 'Approve' : 'Reject'; showToast((action === 'approve' ? "Couldn't approve: " : "Couldn't reject: ") + (d.message || d.error || ('HTTP ' + res.status)), 'error'); }
    }).catch(function () { all.forEach(function (x) { x.disabled = false; }); showToast("Couldn't reach the server — try again.", 'error'); });
  }
  function bookingRow(e) {
    var name = String(e.title || '').replace(/^Booking request — /, '') || 'a customer';
    var r = row('book', '📅', name + ' asked for ' + when(e.starts_at), (e.description || '').replace(/\nStatus:.*$/m, '').slice(0, 160), [
      btn('Confirm', 'primary', function (b) { bookDecide(e.id, 'confirm', b, r); }), btn('Decline', 'danger', function (b) { bookDecide(e.id, 'decline', b, r); }), btn('See the customer', 'quiet', function () { if (e.reference_type === 'Lead' && e.reference_id) openAdvanced('crm', e.reference_id); else openAdvanced('calendar'); })]);
    return r;
  }
  function bookDecide(id, decision, b, r) {
    r.querySelectorAll('button').forEach(function (x) { x.disabled = true; }); b.textContent = decision === 'confirm' ? 'Confirming…' : 'Declining…';
    api('POST', 'calendar/events/' + id + '/decision', { decision: decision }).then(function (res) { var d = res.json || {};
      if (res.ok && d.success !== false) { r.querySelector('.a').innerHTML = '<span class="d">' + (decision === 'confirm' ? 'Confirmed — it\'s in your calendar.' : 'Declined.') + '</span>'; showToast(decision === 'confirm' ? 'Booking confirmed.' : 'Booking declined.', 'success'); }
      else { r.querySelectorAll('button').forEach(function (x) { x.disabled = false; }); b.textContent = decision === 'confirm' ? 'Confirm' : 'Decline'; showToast("Couldn't update the booking: " + (d.error || d.message || res.status), 'error'); }
    }).catch(function () { r.querySelectorAll('button').forEach(function (x) { x.disabled = false; }); showToast("Couldn't reach the server — try again.", 'error'); });
  }

  /* ═══ RESULTS (MEASURED ≠ INFERRED ≠ NOT CONNECTED) ═════════════════════════════════════════════ */
  window.basicResultsLoad = function (root) {
    ensureCss(); root.innerHTML = shell('Results', 'What your team has done and what it produced. Every number here is measured in this workspace — when something isn\'t connected, it says so.', '');
    var body = root.querySelector('#bs-body'); skel(body, 3);
    var acts = root.querySelector('.bs-acts'); acts.appendChild(btn('Ask Sarah how we did', 'primary', function () { askSarah('How did we do this week, and what should we do next?'); })); acts.appendChild(btn('Open in Advanced', 'quiet', function () { openAdvanced('command'); }));
    Promise.all([api('GET', 'dashboard/overview'), api('GET', 'seo/knowledge'), api('GET', 'seo/gsc/status'), api('GET', 'calendar/events')]).then(function (rs) {
      body.innerHTML = ''; var d = rs[0].json || {}; var s = d.stats || {}; var k = rs[1].json || {}; var gsc = !!(rs[2].json && rs[2].json.connected);
      var evs = Array.isArray(rs[3].json) ? rs[3].json : []; var confirmed = evs.filter(function (e) { return /_confirmed$/.test(String(e.category || '')); }).length; var pendingB = evs.filter(function (e) { return /_pending$/.test(String(e.category || '')); }).length;
      var m = '<span class="bs-tag measured">Measured</span>';
      var g1 = sec('Work done', null); body.appendChild(g1); var grid = document.createElement('div'); grid.className = 'bs-grid'; g1.appendChild(grid);
      grid.appendChild(kpi('Tasks completed', s.tasks_completed || 0, (s.tasks_today ? '+' + s.tasks_today + ' today · ' : '') + (s.tasks_this_week ? '+' + s.tasks_this_week + ' this week · ' : '') + m));
      grid.appendChild(kpi('Articles live', s.articles_published || 0, (Math.max(0, (s.articles_total || 0) - (s.articles_published || 0))) + ' in draft · ' + m));
      grid.appendChild(kpi('Enquiries', s.leads_captured || 0, (s.leads_this_week ? '+' + s.leads_this_week + ' this week · ' : '') + m));
      grid.appendChild(kpi('Bookings', confirmed, (pendingB ? pendingB + ' waiting for your reply · ' : '') + m));
      var g2 = sec('Search visibility', null); body.appendChild(g2); var grid2 = document.createElement('div'); grid2.className = 'bs-grid'; g2.appendChild(grid2);
      var pagesChecked = (k.content_health && k.content_health.total_pages != null) ? k.content_health.total_pages : (k.pages_indexed != null ? k.pages_indexed : null);
      grid2.appendChild(kpi('Pages LevelUp has checked', pagesChecked != null ? pagesChecked : 'None yet', (k.health_score != null ? 'Average page score ' + Math.round(k.health_score) + ' · ' : '') + m));
      grid2.appendChild(kpi('Search phrases tracked', s.keywords_tracked || 0, m));
      grid2.appendChild(gsc ? kpi('Clicks from Google', '—', '<span class="bs-tag measured">Measured</span> Connected — figures appear as Google reports them') : kpi('Clicks & rankings', 'Not connected', '<span class="bs-tag gated">Not connected</span> Connect Google Search Console to see real clicks and positions', 'gated'));
      if (!gsc) { var b2 = btn('Connect Google Search Console', 'quiet', function () { openAdvanced('seo'); }); g2.appendChild(b2); }
      var feed = (d.activity_feed || []).slice(0, 8); var g3 = sec('Recently', feed.length || null); body.appendChild(g3);
      if (!feed.length) g3.appendChild(empty('Nothing has run yet', 'Tell Sarah what you want to achieve and the first results will appear here.'));
      var list = document.createElement('div'); list.className = 'bs-list'; g3.appendChild(list);
      feed.forEach(function (f) { var lbl = String(f.label || '');
        // The feed label comes from the Command Center map ("James completed add keyword") — swap the slug tail for business words.
        var m = lbl.match(/^(\w+)\s(completed|delegated|failed at|cancelled)\s(.+?)(?:\sto\s(\w+))?$/);
        if (m) { var act = humanAction(m[3].trim().replace(/\s+/g, '_'));
          if (m[2] === 'delegated') lbl = m[1] + ' asked ' + (m[4] || 'the team') + ' to start ' + act;
          else if (m[2] === 'completed') lbl = m[1] + ' finished ' + act;
          else lbl = m[1] + ' ' + m[2] + ' ' + act; }
        list.appendChild(row('', (f.agent && f.agent.name ? f.agent.name.charAt(0) : '•'), lbl, f.time_ago || '')); });
    }).catch(function () { fail(body, 'your results', function () { window.basicResultsLoad(root); }); });
  };

  /* ═══ WEBSITE ═══════════════════════════════════════════════════════════════════════════════════ */
  window.basicWebsiteLoad = function (root) {
    ensureCss(); root.innerHTML = shell('Website', 'Your website, in plain terms: is it live, what changed, and how to go back to an earlier version.', '');
    var body = root.querySelector('#bs-body'); skel(body, 2);
    var acts = root.querySelector('.bs-acts'); acts.appendChild(btn('Ask Sarah to change something', 'primary', function () { askSarah('On my website, please change '); })); acts.appendChild(btn('Open in Advanced', 'quiet', function () { openAdvanced('websites'); }));
    api('GET', 'websites').then(function (r) {
      body.innerHTML = ''; var sites = (r.json && (r.json.websites || r.json.data)) || []; var usage = (r.json && r.json.usage) || {};
      if (!sites.length) { var e = empty('No website yet', 'Ask Sarah to build one — she\'ll ask a few questions and Arthur, her website specialist, does the rest.'); e.appendChild(btn('Ask Sarah to build my website', 'primary', function () { askSarah('Please build a website for my business.'); })); body.appendChild(e); return; }
      sites.forEach(function (sd) {
        var live = sd.publish_state === 'published' || sd.status === 'published'; var isWp = String(sd.platform || '').toLowerCase() === 'wordpress' || String(sd.type || '') === 'external';
        var sub = String(sd.subdomain || '').replace(/^https?:\/\//, '');
        var addr = sd.custom_domain || sd.domain || (sub ? (sub.indexOf('.') > -1 ? sub : sub + '.levelupgrowth.io') : (sd.external_url || ''));
        var external = isWp || /^https?:\/\//.test(String(sd.external_url || '')) && !sub;
        var s1 = sec(sd.name || sd.title || 'Website', null); body.appendChild(s1);
        var head = document.createElement('div'); head.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;align-items:center';
        head.innerHTML = '<span class="bs-tag ' + (live ? 'ok' : 'warn') + '">' + (live ? 'Live' : 'Draft') + '</span>' + (isWp ? '<span class="bs-tag">WordPress</span>' : '<span class="bs-tag">Built with LevelUp</span>') + (sd.page_count ? '<span class="bs-tag">' + sd.page_count + ' pages</span>' : '') + (addr ? '<span class="bs-sub" style="margin:0">' + esc(addr) + '</span>' : '');
        s1.appendChild(head);
        var a = document.createElement('div'); a.className = 'bs-acts'; s1.appendChild(a);
        if (addr) a.appendChild(btn('Open website', 'quiet', function () { window.open(/^https?:/.test(addr) ? addr : 'https://' + addr, '_blank', 'noopener'); }));
        a.appendChild(btn('Ask Sarah to change it', 'primary', function () { askSarah('On the ' + (sd.name || 'website') + ' website, please change '); }));
        a.appendChild(btn('Open in Advanced', 'quiet', function () { openAdvanced('websites', sd.id); }));
        var info = document.createElement('div'); info.className = 'bs-grid'; s1.appendChild(info);
        info.appendChild(kpi('Address', addr || 'Not set yet', external ? (/^https:/.test(addr) ? 'Secure (HTTPS) on your own platform' : 'On your own platform') : (live ? 'HTTPS included with LevelUp hosting' : 'Appears when published')));
        info.appendChild(kpi('Your own domain', sd.custom_domain ? sd.custom_domain : 'Not connected', sd.custom_domain ? '' : 'Connecting a domain you own is coming soon'));
        if (!isWp) {
          var vs = sec('Earlier versions', null, 'Every publish keeps the previous version. Restoring puts that version live again; the current one stays in this list.'); body.appendChild(vs);
          var vl = document.createElement('div'); vs.appendChild(vl); vl.innerHTML = '<div class="bs-skel"></div>';
          api('GET', 'builder/websites/' + sd.id + '/history').then(function (h) { var items = (h.json && (h.json.history || (h.json.data && h.json.data.history))) || []; vl.innerHTML = ''; if (!items.length) { vl.appendChild(empty('No earlier versions yet', 'They appear after your first change.')); return; }
            items.slice(0, 10).forEach(function (v, i) { var d = document.createElement('div'); d.className = 'bs-ver'; d.innerHTML = '<div><div class="t" style="font-weight:600">' + esc(when(v.saved_at)) + (i === 0 ? ' <span class="bs-tag">most recent</span>' : '') + '</div><div class="d" style="color:var(--t2);font-size:12px">' + esc(Math.max(1, Math.round((Number(v.size) || 0) / 1024)) + ' KB') + '</div></div>';
              d.appendChild(btn('Restore this version', 'quiet', function (b) { window.luConfirm('Restore version', 'Put this saved version live now? Your current version stays in the list, so you can come back to it.', { okLabel: 'Restore', cancelLabel: 'Keep current' }).then(function (ok) { if (!ok) return; b.disabled = true; b.textContent = 'Restoring…'; api('POST', 'builder/websites/' + sd.id + '/restore', { file: v.file }).then(function (rr) { var j = rr.json || {}; if (rr.ok && j.restored) { showToast('Restored — that version is live now.', 'success'); window.basicWebsiteLoad(root); } else { b.disabled = false; b.textContent = 'Restore this version'; showToast('Not restored: ' + (j.error || j.message || ('HTTP ' + rr.status)), 'error'); } }); }); })); vl.appendChild(d); });
          }).catch(function () { vl.innerHTML = ''; vl.appendChild(empty("Couldn't load versions", 'Try again in a moment.')); });
        } else {
          s1.appendChild(row('', '🔌', 'Connected to your WordPress site', 'Sarah publishes articles straight into WordPress. Versions live on your own platform.', [btn('Open in Advanced', 'quiet', function () { openAdvanced('websites', sd.id); })]));
        }
      });
      if (usage.limit) { var u = document.createElement('p'); u.className = 'bs-sub'; u.textContent = sites.length + ' of ' + usage.limit + ' websites on your ' + (usage.plan || '') + ' plan.'; body.appendChild(u); }
    }).catch(function () { fail(body, 'your website', function () { window.basicWebsiteLoad(root); }); });
  };

  /* ═══ CUSTOMERS ═════════════════════════════════════════════════════════════════════════════════ */
  window.basicCustomersLoad = function (root) {
    ensureCss(); root.innerHTML = shell('Customers', 'New enquiries, who needs a reply, and upcoming appointments — in plain language.', '');
    var body = root.querySelector('#bs-body'); skel(body, 2);
    var acts = root.querySelector('.bs-acts'); acts.appendChild(btn('Ask Sarah about a customer', 'primary', function () { askSarah('Tell me about my newest enquiries and who I should follow up with.'); })); acts.appendChild(btn('Open in Advanced', 'quiet', function () { openAdvanced('crm'); }));
    Promise.all([api('GET', 'crm/leads?per_page=50'), api('GET', 'calendar/events')]).then(function (rs) {
      body.innerHTML = ''; var lj = rs[0].json || {}; var leads = Array.isArray(lj) ? lj : (lj.leads || lj.data || lj.items || []);
      var evs = Array.isArray(rs[1].json) ? rs[1].json : [];
      var newLeads = leads.filter(function (l) { return String(l.status || '') === 'new'; });
      var SRC = { chatbot888: 'via your website chat', website_form: 'via a website form', manual: 'added by hand', import: 'imported', sarah: 'added by Sarah' };
      var s1 = sec('Needs a reply', newLeads.length, newLeads.length ? 'New enquiries nobody has contacted yet.' : null); body.appendChild(s1);
      if (!newLeads.length) s1.appendChild(empty('Everyone has been contacted', 'New enquiries from your website and chatbot appear here first.'));
      newLeads.slice(0, 10).forEach(function (l) { s1.appendChild(row('att', (l.name || '?').charAt(0).toUpperCase(), l.name || l.email || 'Enquiry', [l.email, l.phone, SRC[l.source] || l.source, l.created_at ? ago(l.created_at) : ''].filter(Boolean).join(' · '), [btn('Ask Sarah to follow up', 'primary', function () { askSarah('Please follow up with ' + (l.name || 'this enquiry') + ' and note what they need.'); }), btn('Open the enquiry', 'quiet', function () { openAdvanced('crm', l.id); })])); });
      var upcoming = evs.filter(function (e) { return e.starts_at && new Date(String(e.starts_at).replace(' ', 'T')) >= new Date() && !/_declined$/.test(String(e.category || '')); }).sort(function (a, b) { return String(a.starts_at).localeCompare(String(b.starts_at)); });
      var s2 = sec('Appointments', upcoming.length, upcoming.length ? 'Confirmed appointments and requests still waiting for your reply.' : null); body.appendChild(s2);
      if (!upcoming.length) s2.appendChild(empty('No appointments coming up', 'Booking requests from your website land here.'));
      upcoming.slice(0, 8).forEach(function (e) { var pending = /_pending$/.test(String(e.category || '')); s2.appendChild(row(pending ? 'att' : 'book', '📅', String(e.title || '').replace(/^Booking request — /, ''), when(e.starts_at) + (pending ? ' · waiting for your reply' : ' · confirmed'), pending ? [btn('Reply in Needs attention', 'primary', function () { if (window.nav) nav('attention'); })] : [])); });
      var s3 = sec('All customers', leads.length); body.appendChild(s3);
      if (!leads.length) s3.appendChild(empty('No customers yet', 'Once your website or chatbot captures an enquiry, it appears here.'));
      var STAGE = { new: 'New', contacted: 'Contacted', qualified: 'Interested', proposal: 'Quoted', won: 'Customer', lost: 'Not now' };
      leads.slice(0, 30).forEach(function (l) { if (String(l.status) === 'new') return; s3.appendChild(row('', (l.name || '?').charAt(0).toUpperCase(), l.name || l.email || 'Customer', [STAGE[l.status] || l.status, l.email, SRC[l.source] || l.source].filter(Boolean).join(' · '), [btn('Open the customer', 'quiet', function () { openAdvanced('crm', l.id); })])); });
    }).catch(function () { fail(body, 'your customers', function () { window.basicCustomersLoad(root); }); });
  };

  /* ═══ ACCOUNT ═══════════════════════════════════════════════════════════════════════════════════ */
  window.basicAccountLoad = function (root) {
    ensureCss(); root.innerHTML = shell('Account', 'You, your business, your brand and your plan.', '');
    var body = root.querySelector('#bs-body'); skel(body, 3);
    var acts = root.querySelector('.bs-acts'); acts.appendChild(btn('Advanced settings', 'quiet', function () { openAdvanced('settings'); }));
    Promise.all([api('GET', 'auth/me'), api('GET', 'workspace/status'), api('GET', 'workspace/brand'), api('GET', 'billing/status')]).then(function (rs) {
      body.innerHTML = ''; var me = (rs[0].json && rs[0].json.user) || {}; var ws = rs[1].json || {}; var w = ws.workspace || {}; var brand = rs[2].json || {}; var bill = rs[3].json || {};
      // You
      var s1 = sec('You', null); body.appendChild(s1); var f1 = document.createElement('div'); f1.className = 'bs-2'; s1.appendChild(f1);
      f1.innerHTML = '<div class="bs-field"><label for="ac-name">Your name</label><input id="ac-name" value="' + esc(me.name || '') + '" autocomplete="name"></div><div class="bs-field"><label for="ac-email">Email</label><input id="ac-email" type="email" value="' + esc(me.email || '') + '" autocomplete="email"></div>';
      var a1 = document.createElement('div'); a1.className = 'bs-acts'; s1.appendChild(a1);
      a1.appendChild(btn('Save', 'primary', function (b) { b.disabled = true; api('PUT', 'auth/profile', { name: document.getElementById('ac-name').value, email: document.getElementById('ac-email').value }).then(function (r) { b.disabled = false; if (r.ok) showToast('Saved.', 'success'); else showToast("Couldn't save: " + ((r.json && (r.json.message || r.json.error)) || r.status), 'error'); }); }));
      a1.appendChild(btn('Change password', 'quiet', function () { openAdvanced('settings'); }));
      // Business
      var s2 = sec('Your business', null, 'Sarah uses this to understand what you do and who you serve.'); body.appendChild(s2); var f2 = document.createElement('div'); f2.className = 'bs-2'; s2.appendChild(f2);
      f2.innerHTML = '<div class="bs-field"><label for="ac-biz">Business name</label><input id="ac-biz" value="' + esc(w.business_name || w.name || '') + '"></div><div class="bs-field"><label for="ac-ind">What you do</label><input id="ac-ind" value="' + esc(w.industry || '') + '" placeholder="e.g. Artisan bakery"></div><div class="bs-field"><label for="ac-loc">Where you are</label><input id="ac-loc" value="' + esc(w.location || '') + '" placeholder="e.g. Brighton, UK"></div><div class="bs-field"><label for="ac-tz">Time zone</label><input id="ac-tz" value="' + esc(w.timezone || '') + '" placeholder="e.g. Europe/London"></div>';
      var a2 = document.createElement('div'); a2.className = 'bs-acts'; s2.appendChild(a2);
      a2.appendChild(btn('Save', 'primary', function (b) { b.disabled = true; api('PUT', 'workspace/settings', { business_name: document.getElementById('ac-biz').value, industry: document.getElementById('ac-ind').value, location: document.getElementById('ac-loc').value, timezone: document.getElementById('ac-tz').value }).then(function (r) { b.disabled = false; if (r.ok) showToast('Saved — Sarah will use this from now on.', 'success'); else showToast("Couldn't save: " + ((r.json && (r.json.message || r.json.error)) || r.status), 'error'); }); }));
      a2.appendChild(btn('Tell Sarah more about my business', 'quiet', function () { askSarah('Here is more about my business: '); }));
      // Brand
      var s3 = sec('Your brand', null, 'Colours Arthur and Studio use on your website and images.'); body.appendChild(s3); var f3 = document.createElement('div'); f3.className = 'bs-2'; s3.appendChild(f3);
      f3.innerHTML = ['primary_color', 'secondary_color', 'accent_color'].map(function (k, i) { return '<div class="bs-field"><label for="ac-' + k + '">' + ['Main colour', 'Second colour', 'Highlight colour'][i] + '</label><div class="bs-swatch"><input id="ac-' + k + '" type="color" value="' + esc(brand[k] || '#6C5CE7') + '" style="width:64px;min-height:44px;padding:4px"><span id="ac-' + k + '-v" class="bs-sub" style="margin:0">' + esc(brand[k] || '') + '</span></div></div>'; }).join('');
      var a3 = document.createElement('div'); a3.className = 'bs-acts'; s3.appendChild(a3);
      a3.appendChild(btn('Save brand colours', 'primary', function (b) { b.disabled = true; api('PUT', 'workspace/brand', { primary_color: document.getElementById('ac-primary_color').value, secondary_color: document.getElementById('ac-secondary_color').value, accent_color: document.getElementById('ac-accent_color').value }).then(function (r) { b.disabled = false; if (r.ok) showToast('Brand colours saved and applied to your websites.', 'success'); else showToast("Couldn't save: " + ((r.json && (r.json.message || r.json.error)) || r.status), 'error'); }); }));
      // Plan
      var s4 = sec('Your plan', null); body.appendChild(s4); var g = document.createElement('div'); g.className = 'bs-grid'; s4.appendChild(g);
      var planName = bill.is_platform_trial ? (bill.plan || 'Trial') + ' trial' : (bill.plan || ws.plan && ws.plan.name || 'Free');
      g.appendChild(kpi('Plan', planName, bill.plan_price ? '$' + Number(bill.plan_price).toFixed(0) + '/month' : (bill.is_platform_trial && bill.trial_ends_at ? 'Trial ends ' + when(bill.trial_ends_at) : '')));
      g.appendChild(kpi('Credits available', (bill.credit_available != null ? bill.credit_available : ws.credit_balance || 0), 'of ' + (bill.monthly_credit_limit || ws.monthly_credit_limit || 0) + ' this month'));
      g.appendChild(kpi('Websites', ws.website_count != null ? ws.website_count : '—', ws.plan && ws.plan.max_websites ? 'of ' + ws.plan.max_websites + ' on your plan' : ''));
      var a4 = document.createElement('div'); a4.className = 'bs-acts'; s4.appendChild(a4);
      a4.appendChild(btn('Change plan or top up', 'primary', function () { if (window.nav) { if (typeof window._lgsc_set_visibility_mode === 'function' && document.documentElement.getAttribute('data-mode') !== 'advanced') window._lgsc_set_visibility_mode('advanced', { skipNav: true }); nav('billing'); } }));
      if (bill.stripe_customer_id) a4.appendChild(btn('Invoices & payment method', 'quiet', function () { if (typeof window._openBillingPortal === 'function') window._openBillingPortal(); else openAdvanced('billing'); }));
    }).catch(function () { fail(body, 'your account', function () { window.basicAccountLoad(root); }); });
  };
})();
