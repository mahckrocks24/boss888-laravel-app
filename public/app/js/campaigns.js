/* LevelUpGrowth CAMPAIGNS — CAMPAIGNS-1 (RFC-0018, 2026-09-27). Replaces the Projects list.
 * A campaign is a business goal with dates, channels, phases of dated work and results. Sarah designs the ideas; the
 * owner launches one with a single approval; the team runs every step on its date; each post or email still gets the
 * owner's OK before it goes out. List · Campaign · Calendar, all in the app theme.
 */
(function () {
  'use strict';
  var API = '/api/';
  var S = { view: 'list', id: null, month: null, data: null, biz: 0, poll: null };
  function api(m, p, b) {
    var h = { 'Accept': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') };
    if (b) h['Content-Type'] = 'application/json';
    return fetch(API + p, { method: m, headers: h, body: b ? JSON.stringify(b) : undefined, cache: 'no-store' }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; }); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(m, k) { if (typeof window.showToast === 'function') window.showToast(m, k || 'info'); }
  function d(s) { if (!s) return null; var x = new Date(String(s).length <= 10 ? s + 'T00:00:00' : s); return isNaN(x) ? null : x; }
  function fmt(s, o) { var x = d(s); return x ? x.toLocaleDateString(undefined, o || { month: 'short', day: 'numeric' }) : ''; }
  function range(a, b) { return fmt(a) + ' – ' + fmt(b, { month: 'short', day: 'numeric', year: 'numeric' }); }

  var I = {
    spark: '<path d="M12 3l1.8 4.9L19 9.7l-4.3 3.1L16 18l-4-2.9L8 18l1.3-5.2L5 9.7l5.2-1.8Z"/>',
    back: '<path d="M15 18l-6-6 6-6"/>', chev: '<path d="M9 6l6 6-6 6"/>', cal: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>', check: '<path d="M5 12.5l4.2 4.2L19 7"/>', target: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".8"/>',
    pause: '<path d="M9 5v14M15 5v14"/>', play: '<path d="M7 5l12 7-12 7Z"/>', flag: '<path d="M5 21V4h11l-2 4 2 4H5"/>', clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    facebook: '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H8v4h2v7h4v-7h3l1-4h-4V8Z"/>', instagram: '<rect x="4" y="4" width="16" height="16" rx="4.5"/><circle cx="12" cy="12" r="3.6"/><circle cx="17" cy="7" r=".6"/>',
    linkedin: '<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M8 10.5V16M8 7.8v.2M11.5 16v-3.2a2 2 0 0 1 4 0V16M11.5 10.5V16"/>', website: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.6 2.5 14.4 0 17M12 3.5c-2.5 2.6-2.5 14.4 0 17"/>',
    email: '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>', in_person: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.2-3.6 4-5.5 7-5.5s5.8 1.9 7 5.5"/>', phone: '<path d="M6 4h3l1.5 4-2 1.5a11 11 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4 6a2 2 0 0 1 2-2Z"/>',
    post: '<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M4 15l4.5-4.5 4 4 2.5-2.5L20 17"/>', article: '<path d="M7 3.5h7l4 4V20a.5.5 0 0 1-.5.5h-10A.5.5 0 0 1 7 20Z"/><path d="M10 11h5M10 14.5h5M10 18h3"/>',
    image: '<rect x="3.5" y="5" width="17" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M4 17l5-4.5 4 3.5 3-2.5 4 3.5"/>', event: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M8 14h3"/>',
    owner_task: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8.5 12.3l2.4 2.4L15.8 9.6"/>',
    radar: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><path d="M12 12l5.5-5.5"/>'
  };
  function ic(n, s) { return '<svg class="cm-ic" viewBox="0 0 24 24" width="' + (s || 16) + '" height="' + (s || 16) + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (I[n] || I.flag) + '</svg>'; }
  var KIND = { post: 'Social post', article: 'Article', email: 'Email you send', image: 'Design', event: 'Event', owner_task: 'You' };
  var KCOL = { post: '#6C8CFF', article: '#2FB39A', email: '#E0A13A', image: '#C77DFF', event: '#E0685A', owner_task: '#8A93A6' };
  var STAT = { idea: ['Idea', 'var(--p)'], launching: ['Starting', '#3B82F6'], active: ['Running', '#22A06B'], paused: ['Paused', '#D97706'], completed: ['Finished', '#64748B'], declined: ['Not now', '#94A3B8'] };
  var ISTAT = { planned: ['Planned', 'var(--t3)'], in_progress: ['In progress', '#3B82F6'], needs_you: ['Needs you', '#D97706'], done: ['Done', '#22A06B'], skipped: ['Skipped', 'var(--t3)'], failed: ['Needs attention', '#DC2626'], held: ['On hold', '#D97706'] };
  function credits(e) { var n = Math.max(1, Math.ceil((+e || 0) * 1.25)); return n + (n === 1 ? ' credit' : ' credits'); }
  function pill(t, c) { return '<span class="cm-pill" style="--c:' + c + '">' + esc(t) + '</span>'; }

  var CSS = '#cm{max-width:1180px;margin:0 auto;color:var(--t1);font:14px/1.5 var(--fb,inherit)}#cm .cm-ic{flex:none;vertical-align:-3px}' +
    '.cm-hd{display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px;margin:0 0 18px}.cm-hd h1{font:800 22px/1.2 var(--fh,var(--fb,inherit));margin:0;color:var(--t1)}.cm-hd p{margin:4px 0 0;color:var(--t3);font-size:13px}.cm-hdl{flex:1 1 320px}' +
    '.cm-seg{display:inline-flex;padding:3px;border:1px solid var(--bd);border-radius:10px;background:var(--s2)}.cm-seg button{border:0;background:transparent;color:var(--t2);font:600 13px var(--fb,inherit);padding:7px 12px;border-radius:8px;cursor:pointer;display:inline-flex;gap:6px;align-items:center}.cm-seg button.on{background:var(--s1);color:var(--t1);box-shadow:0 1px 3px rgba(0,0,0,.2)}' +
    '.cm-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:40px;padding:0 16px;border-radius:var(--r,10px);border:1px solid var(--bd2,var(--bd));background:transparent;color:var(--t1);font:600 13px var(--fb,inherit);cursor:pointer;white-space:nowrap;transition:filter .15s,background .15s}' +
    '.cm-btn:hover{background:var(--s2)}.cm-btn.primary{background:var(--p);border-color:var(--p);color:#fff}.cm-btn.primary:hover{filter:brightness(1.08);background:var(--p)}.cm-btn.ghost{border-color:transparent;color:var(--t2)}.cm-btn[disabled]{opacity:.55;cursor:default}.cm-btn.sm{min-height:32px;padding:0 11px;font-size:12.5px}.cm-btn:focus-visible{outline:2px solid var(--p);outline-offset:2px}' +
    '.cm-biz{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 16px}.cm-biz button{border:1px solid var(--bd);background:var(--s2);color:var(--t2);border-radius:99px;padding:6px 12px;font:600 12.5px var(--fb,inherit);cursor:pointer}.cm-biz button.on{background:var(--p);border-color:var(--p);color:#fff}' +
    '.cm-sec{margin:0 0 26px}.cm-sec h2{display:flex;align-items:center;gap:8px;font:700 13px var(--fb,inherit);letter-spacing:.06em;text-transform:uppercase;color:var(--t3);margin:0 0 10px}.cm-sec h2 .n{font-weight:600;color:var(--t2);background:var(--s2);border:1px solid var(--bd);border-radius:99px;padding:1px 8px;letter-spacing:0}' +
    '.cm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}' +
    '.cm-card{background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg,14px);padding:16px;display:flex;flex-direction:column;gap:10px;cursor:pointer;transition:border-color .15s,box-shadow .15s,transform .15s}.cm-card:hover{border-color:var(--bd2,var(--bd));box-shadow:0 10px 26px rgba(0,0,0,.16);transform:translateY(-1px)}' +
    '.cm-card h3{font:700 16px/1.3 var(--fh,var(--fb,inherit));margin:0;color:var(--t1)}.cm-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px;color:var(--t3);font-size:12.5px}.cm-why{color:var(--t2);font-size:13px;margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}' +
    '.cm-pill{display:inline-flex;align-items:center;gap:6px;font:600 11.5px/1 var(--fb,inherit);padding:5px 9px;border-radius:99px;color:var(--c);background:color-mix(in srgb,var(--c) 14%,transparent);border:1px solid color-mix(in srgb,var(--c) 30%,transparent)}.cm-pill:before{content:"";width:6px;height:6px;border-radius:50%;background:var(--c)}' +
    '.cm-tag{display:inline-flex;align-items:center;border:1px solid var(--bd);border-radius:6px;padding:2px 8px;font-size:12px;color:var(--t2);background:var(--s2)}.cm-chans{display:inline-flex;gap:4px;color:var(--t2)}.cm-kpi{display:inline-flex;align-items:center;gap:6px;color:var(--t1);font-weight:600;font-size:12.5px}' +
    '.cm-prog{height:6px;border-radius:99px;background:var(--s2);overflow:hidden;border:1px solid var(--bd)}.cm-prog i{display:block;height:100%;background:var(--p);border-radius:99px}' +
    '.cm-next{font-size:12.5px;color:var(--t2);display:flex;gap:6px;align-items:center}.cm-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:2px}' +
    '.cm-idea{border-color:color-mix(in srgb,var(--p) 40%,var(--bd));background:linear-gradient(180deg,color-mix(in srgb,var(--p) 7%,var(--s1)),var(--s1) 60%)}' +
    '.cm-wait{display:flex;flex-direction:column;border:1px solid color-mix(in srgb,#D97706 35%,var(--bd));border-radius:var(--rg,14px);background:var(--s1);overflow:hidden}.cm-wait .it{display:flex;align-items:center;gap:12px;padding:12px 16px;border-top:1px solid var(--bd)}.cm-wait .it:first-child{border-top:0}' +
    '.cm-wait .ttl{flex:1;min-width:0}.cm-wait .ttl b{display:block;font-size:13.5px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cm-wait .ttl span{font-size:12px;color:var(--t3)}.cm-kic{flex:none;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;color:#fff}' +
    '.cm-empty{border:1px dashed var(--bd2,var(--bd));border-radius:var(--rg,14px);padding:34px 24px;text-align:center;color:var(--t2);background:var(--s1)}.cm-empty h3{margin:10px 0 6px;font:700 17px var(--fh,var(--fb,inherit));color:var(--t1)}.cm-empty p{margin:0 auto 16px;max-width:520px;font-size:13.5px}' +
    '.cm-think{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:var(--rg,14px);border:1px solid color-mix(in srgb,var(--p) 40%,var(--bd));background:color-mix(in srgb,var(--p) 8%,var(--s1));margin:0 0 18px;color:var(--t1);font-weight:600}.cm-dot{width:10px;height:10px;border-radius:50%;background:var(--p);animation:cmp 1.2s ease-in-out infinite}@keyframes cmp{50%{opacity:.3;transform:scale(.7)}}' +
    '.cm-back{display:inline-flex;align-items:center;gap:4px;border:0;background:transparent;color:var(--t2);font:600 13px var(--fb,inherit);cursor:pointer;padding:6px 0;margin:0 0 10px}.cm-back:hover{color:var(--t1)}' +
    '.cm-dh{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;margin:0 0 18px}.cm-dh .l{flex:1 1 420px}.cm-dh h1{font:800 24px/1.2 var(--fh,var(--fb,inherit));margin:8px 0 6px}.cm-dh .l p{color:var(--t2);margin:0 0 4px}' +
    '.cm-cols{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start}.cm-panel{background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg,14px);padding:16px}.cm-panel h4{margin:0 0 10px;font:700 12px var(--fb,inherit);letter-spacing:.08em;text-transform:uppercase;color:var(--t3)}' +
    '.cm-facts dt{font-size:12px;color:var(--t3);margin:10px 0 2px}.cm-facts dd{margin:0;color:var(--t1);font-size:13.5px}.cm-facts dt:first-child{margin-top:0}' +
    '.cm-res{display:grid;grid-template-columns:1fr 1fr;gap:10px}.cm-res .m{border:1px solid var(--bd);border-radius:12px;padding:10px 12px;background:var(--s2)}.cm-res .m b{display:block;font:800 22px/1.1 var(--fh,var(--fb,inherit));color:var(--t1)}.cm-res .m span{font-size:12px;color:var(--t3)}.cm-res .m.big{grid-column:1/-1}' +
    '.cm-note{font-size:12px;color:var(--t3);margin:10px 0 0}' +
    '.cm-ph{margin:0 0 16px}.cm-ph h3{display:flex;align-items:center;gap:10px;font:700 14px var(--fb,inherit);margin:0 0 8px;color:var(--t1)}.cm-ph h3 .no{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:12px;background:var(--s2);border:1px solid var(--bd);color:var(--t2)}' +
    '.cm-it{display:grid;grid-template-columns:74px 34px minmax(0,1fr) auto;gap:12px;align-items:start;padding:12px;border:1px solid var(--bd);border-radius:12px;background:var(--s1);margin:0 0 8px}.cm-it .dt{font:700 12.5px/1.25 var(--fb,inherit);color:var(--t1)}.cm-it .dt span{display:block;font-weight:500;color:var(--t3)}' +
    '.cm-it .bd b{display:block;font-size:13.5px;color:var(--t1)}.cm-it .bd .meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12px;color:var(--t3);margin:2px 0 0}.cm-it .bd p{margin:6px 0 0;font-size:12.5px;color:var(--t2);display:none}.cm-it.open .bd p{display:block}.cm-it .ac{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.cm-it.done{opacity:.75}' +
    '.cm-cal{background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg,14px);overflow:hidden}.cm-calh{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--bd)}.cm-calh b{font:700 16px var(--fh,var(--fb,inherit));flex:1}' +
    '.cm-dow,.cm-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}.cm-dow div{padding:8px 10px;font:600 11px var(--fb,inherit);letter-spacing:.06em;text-transform:uppercase;color:var(--t3);border-bottom:1px solid var(--bd)}' +
    '.cm-day{min-height:118px;border-right:1px solid var(--bd);border-bottom:1px solid var(--bd);padding:6px;display:flex;flex-direction:column;gap:4px;min-width:0}.cm-day:nth-child(7n){border-right:0}.cm-day .num{font:600 12px var(--fb,inherit);color:var(--t2);margin:0 0 2px}.cm-day.out{background:color-mix(in srgb,var(--s2) 55%,transparent)}.cm-day.out .num{color:var(--t3)}.cm-day.today .num{color:#fff;background:var(--p);border-radius:99px;display:inline-block;padding:0 7px;align-self:flex-start}' +
    '.cm-chip{display:flex;align-items:center;gap:5px;font-size:11.5px;line-height:1.25;padding:4px 6px;border-radius:6px;background:color-mix(in srgb,var(--k) 16%,transparent);color:var(--t1);border-left:3px solid var(--k);cursor:pointer;min-width:0}.cm-chip span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cm-chip.idea{opacity:.55;border-left-style:dashed}.cm-chip.done span{text-decoration:line-through;opacity:.8}.cm-more{font-size:11px;color:var(--t3)}' +
    '.cm-leg{display:flex;flex-wrap:wrap;gap:12px;padding:10px 14px;border-top:1px solid var(--bd);font-size:12px;color:var(--t2)}.cm-leg i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}' +
    '.cm-dlg{position:fixed;inset:0;z-index:4000;display:grid;place-items:center;background:rgba(0,0,0,.5);padding:16px}.cm-dlg .box{width:min(460px,100%);background:var(--s1);border:1px solid var(--bd);border-radius:16px;padding:20px;box-shadow:0 24px 60px rgba(0,0,0,.4)}.cm-dlg h3{margin:0 0 6px;font:700 17px var(--fh,var(--fb,inherit))}.cm-dlg p{margin:0 0 14px;color:var(--t2);font-size:13.5px}.cm-dlg .ch{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}.cm-dlg .ch button{border:1px solid var(--bd);background:var(--s2);color:var(--t1);border-radius:99px;padding:7px 12px;font:600 12.5px var(--fb,inherit);cursor:pointer}.cm-dlg .ch button.on{border-color:var(--p);color:var(--p)}.cm-dlg .ft{display:flex;justify-content:flex-end;gap:8px}' +
    '.cm-dlg ul{margin:0 0 14px;padding-left:18px;color:var(--t2);font-size:13px}.cm-dlg li{margin:3px 0}' +
    '@media (max-width:900px){.cm-cols{grid-template-columns:1fr}}' +
    '@media (max-width:640px){.cm-grid{grid-template-columns:1fr}.cm-it{grid-template-columns:56px 30px minmax(0,1fr)}.cm-it .ac{grid-column:1/-1;justify-content:flex-start}.cm-day{min-height:74px;padding:4px}.cm-chip span{display:none}.cm-chip{justify-content:center;padding:3px}.cm-dow div{padding:6px 4px;text-align:center}.cm-hd .cm-btn.primary{flex:1 1 100%}}';
  function css() { if (document.getElementById('cm-css')) return; var s = document.createElement('style'); s.id = 'cm-css'; s.textContent = CSS; document.head.appendChild(s); }

  var root;
  window.campaignsLoad = function (el) { root = el; css(); if (S.poll) { clearTimeout(S.poll); S.poll = null; } render(); };
  window.campaignsOpen = function (id) { S.view = 'one'; S.id = id; render(); };
  window.campaignsWatch = function () { S.view = 'watch'; render(); };   // WATCH-1

  function render() {
    if (!root) return;
    if (S.view === 'one' && S.id) return renderOne();
    if (S.view === 'calendar') return renderCal();
    if (S.view === 'watch') return renderWatch();   // WATCH-1
    return renderList();
  }
  function head(sub) {
    return '<div class="cm-hd"><div class="cm-hdl"><h1>Campaigns</h1><p>' + (sub || 'Sarah plans campaigns to grow your business. You launch one with a single approval; your team runs every step on its date.') + '</p></div>' +
      '<div class="cm-seg" role="tablist" aria-label="Campaign views"><button type="button" role="tab" data-v="list" class="' + (S.view === 'list' ? 'on' : '') + '" aria-selected="' + (S.view === 'list') + '">' + ic('list', 15) + 'Campaigns</button><button type="button" role="tab" data-v="calendar" class="' + (S.view === 'calendar' ? 'on' : '') + '" aria-selected="' + (S.view === 'calendar') + '">' + ic('cal', 15) + 'Calendar</button><button type="button" role="tab" data-v="watch" class="' + (S.view === 'watch' ? 'on' : '') + '" aria-selected="' + (S.view === 'watch') + '">' + ic('radar', 15) + 'Market watch</button></div>' +
      '<button type="button" class="cm-btn primary" data-a="ideas">' + ic('spark', 16) + 'Get campaign ideas</button></div>';
  }
  function wireHead() {
    root.querySelectorAll('.cm-seg [data-v]').forEach(function (b) { b.onclick = function () { S.view = b.getAttribute('data-v'); render(); }; });
    var g = root.querySelector('[data-a=ideas]'); if (g) g.onclick = askIdeas;
  }
  function askIdeas() {
    var biz = S.biz || null;
    api('POST', 'growth/campaigns/ideas', { business_id: biz }).then(function (r) {
      if (!r.ok || !r.json.success) { toast((r.json && r.json.error) || 'Could not ask Sarah just now.', 'error'); return; }
      toast('Sarah is designing ideas — about a minute.', 'success'); S.view = 'list'; renderList(true);
    });
  }

  function card(c) {
    var st = STAT[c.status] || [c.status, 'var(--t3)'];
    var pct = c.steps_total ? Math.round(100 * c.steps_done / c.steps_total) : 0;
    var chans = (c.channels || []).map(function (x) { return '<span title="' + esc(x) + '">' + ic(x, 15) + '</span>'; }).join('');
    var isIdea = c.status === 'idea';
    return '<article class="cm-card' + (isIdea ? ' cm-idea' : '') + '" data-id="' + c.id + '" tabindex="0" aria-label="' + esc(c.title) + '">' +
      '<div class="cm-row">' + pill(isIdea ? 'Idea from Sarah' : st[0], st[1]) + '<span>' + esc(range(c.starts_on, c.ends_on)) + '</span>' + (c.business_name ? '<span class="cm-tag">' + esc(c.business_name) + '</span>' : '') + '</div>' +
      '<h3>' + esc(c.title) + '</h3>' + (c.why_now || c.objective ? '<p class="cm-why">' + esc(isIdea ? (c.why_now || c.objective) : (c.objective || c.why_now)) + '</p>' : '') +
      '<div class="cm-row"><span class="cm-chans">' + chans + '</span>' + (c.kpi && c.kpi.label ? '<span class="cm-kpi">' + ic('target', 14) + esc(c.kpi.label) + '</span>' : '') + '<span>· ' + c.steps_total + ' steps</span></div>' +
      (isIdea ? '<div class="cm-acts"><button type="button" class="cm-btn primary sm" data-launch="' + c.id + '">' + ic('play', 14) + 'Launch</button><button type="button" class="cm-btn sm" data-open="' + c.id + '">View plan</button><button type="button" class="cm-btn ghost sm" data-decline="' + c.id + '">Not now</button></div>'
        : (c.status === 'declined' ? '' : '<div class="cm-prog" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"><i style="width:' + pct + '%"></i></div>' +
          '<div class="cm-next">' + (c.needs_you ? '<span style="color:#D97706;font-weight:600">' + c.needs_you + ' waiting for you</span> · ' : '') + (c.status === 'completed' ? 'Finished · ' + c.steps_done + ' of ' + c.steps_total + ' steps' : (c.next_step ? ic('clock', 14) + 'Next: ' + esc(c.next_step.title) + ' · ' + esc(fmt(c.next_step.at, { weekday: 'short', month: 'short', day: 'numeric' })) : c.steps_done + ' of ' + c.steps_total + ' steps done')) + '</div>')) +
      '</article>';
  }

  function renderList(thinking) {
    root.innerHTML = '<div id="cm">' + head() + '<div class="cm-sec"><div class="cm-empty" style="padding:22px">Loading campaigns…</div></div></div>';
    wireHead();
    api('GET', 'growth/campaigns' + (S.biz ? '?business_id=' + S.biz : '')).then(function (r) {
      var j = r.json || {}; if (!r.ok || !j.success) { root.querySelector('#cm').innerHTML = head() + '<div class="cm-empty"><h3>Campaigns could not load</h3><p>Please try again.</p></div>'; wireHead(); return; }
      S.data = j;
      var all = j.campaigns || [], by = function (s) { return all.filter(function (c) { return s.indexOf(c.status) >= 0; }); };
      var run = by(['active', 'launching', 'paused']), ideas = by(['idea']), done = by(['completed']), no = by(['declined']);
      var pending = thinking || j.ideas_pending;
      var biz = (j.businesses || []).length > 1 ? '<div class="cm-biz" role="group" aria-label="Business"><button type="button" data-b="0" class="' + (!S.biz ? 'on' : '') + '">All businesses</button>' + j.businesses.map(function (b) { return '<button type="button" data-b="' + b.id + '" class="' + (S.biz === b.id ? 'on' : '') + '">' + esc(b.name) + '</button>'; }).join('') + '</div>' : '';
      var wait = (j.needs_you || []).length ? '<section class="cm-sec"><h2>' + ic('flag', 15) + 'Waiting for you <span class="n">' + j.needs_you.length + '</span></h2><div class="cm-wait">' + j.needs_you.map(function (w) {
        return '<div class="it"><span class="cm-kic" style="background:' + (KCOL[w.kind] || '#64748B') + '">' + ic(w.kind === 'post' ? (w.channel || 'post') : w.kind, 17) + '</span><div class="ttl"><b>' + esc(w.title) + '</b><span>' + esc(KIND[w.kind] || w.kind) + ' · ' + esc(w.campaign_title) + ' · ' + esc(fmt(w.scheduled_at, { weekday: 'short', month: 'short', day: 'numeric' })) + '</span></div>' +
          '<button type="button" class="cm-btn sm" data-review="' + w.id + '" data-kind="' + esc(w.kind) + '" data-cid="' + w.campaign_id + '">' + (w.kind === 'post' ? 'Review & post' : w.kind === 'owner_task' ? 'Mark done' : 'Review') + '</button></div>';
      }).join('') + '</div></section>' : '';
      var think = pending ? '<div class="cm-think" role="status"><span class="cm-dot"></span>Sarah is designing campaign ideas for you. They will appear here and in her chat.</div>' : '';
      var body = '';
      if (!all.length && !pending) {
        body = '<div class="cm-empty">' + ic('spark', 30) + '<h3>Let Sarah plan your first campaign</h3><p>She looks at your business, the season, your audience and what has worked, then designs campaigns with a clear goal, dates and every step. You choose one and launch it with a single approval.</p><button type="button" class="cm-btn primary" data-a="ideas2">' + ic('spark', 16) + 'Get campaign ideas</button></div>';
      } else {
        if (run.length) body += '<section class="cm-sec"><h2>Running <span class="n">' + run.length + '</span></h2><div class="cm-grid">' + run.map(card).join('') + '</div></section>';
        if (ideas.length) body += '<section class="cm-sec"><h2>' + ic('spark', 15) + 'Ideas from Sarah <span class="n">' + ideas.length + '</span></h2><div class="cm-grid">' + ideas.map(card).join('') + '</div></section>';
        if (done.length) body += '<section class="cm-sec"><h2>Finished <span class="n">' + done.length + '</span></h2><div class="cm-grid">' + done.map(card).join('') + '</div></section>';
        if (no.length) body += '<section class="cm-sec"><details><summary style="cursor:pointer;color:var(--t3);font:600 12.5px var(--fb,inherit)">Not now (' + no.length + ')</summary><div class="cm-grid" style="margin-top:10px">' + no.map(card).join('') + '</div></details></section>';
      }
      root.querySelector('#cm').innerHTML = head() + biz + think + wait + body;
      wireHead();
      var g2 = root.querySelector('[data-a=ideas2]'); if (g2) g2.onclick = askIdeas;
      root.querySelectorAll('.cm-biz [data-b]').forEach(function (b) { b.onclick = function () { S.biz = +b.getAttribute('data-b') || 0; renderList(); }; });
      wireCards(root);
      root.querySelectorAll('[data-review]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); review(b.getAttribute('data-review'), b.getAttribute('data-kind'), b.getAttribute('data-cid')); }; });
      if (pending) { S.poll = setTimeout(function () { if (document.body.contains(root) && S.view === 'list') renderList(false); }, 9000); }
    });
  }
  function wireCards(scope) {
    scope.querySelectorAll('.cm-card[data-id]').forEach(function (c) {
      function open() { S.view = 'one'; S.id = +c.getAttribute('data-id'); render(); }
      c.addEventListener('click', function (e) { if (e.target.closest('button')) return; open(); });
      c.addEventListener('keydown', function (e) { if (e.key === 'Enter') open(); });
    });
    scope.querySelectorAll('[data-open]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); S.view = 'one'; S.id = +b.getAttribute('data-open'); render(); }; });
    scope.querySelectorAll('[data-launch]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); launchDialog(+b.getAttribute('data-launch')); }; });
    scope.querySelectorAll('[data-decline]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); declineDialog(+b.getAttribute('data-decline')); }; });
  }
  function review(itemId, kind, cid) {
    if (kind === 'owner_task') { api('PATCH', 'growth/campaigns/items/' + itemId, { done: true }).then(function () { toast('Marked done.', 'success'); render(); }); return; }
    if (kind === 'post' && typeof window.nav === 'function') { window.nav('sarah'); toast('Your post is ready in Sarah’s chat — tap Post it.', 'info'); return; }
    S.view = 'one'; S.id = +cid; render();
  }

  function dialog(html, onReady) {
    var w = document.createElement('div'); w.className = 'cm-dlg'; w.setAttribute('role', 'dialog'); w.setAttribute('aria-modal', 'true'); w.innerHTML = '<div class="box">' + html + '</div>';
    document.body.appendChild(w);
    function close() { w.remove(); document.removeEventListener('keydown', key); }
    function key(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', key); w.addEventListener('click', function (e) { if (e.target === w) close(); });
    onReady(w, close); var f = w.querySelector('button'); if (f) f.focus();
  }
  function launchDialog(id) {
    api('GET', 'growth/campaigns/' + id).then(function (r) {
      var c = (r.json || {}).campaign; if (!c) { toast('That campaign is no longer available.', 'error'); return; }
      var steps = []; (c.phases || []).forEach(function (p) { p.items.forEach(function (it) { steps.push(it); }); });
      dialog('<h3>Launch “' + esc(c.title) + '”?</h3><p>This approves the whole plan once. Your team runs each step on its date. You still approve every post and email before it goes out.</p>' +
        '<ul><li>' + esc(range(c.starts_on, c.ends_on)) + ' · ' + steps.length + ' steps</li>' + (c.kpi && c.kpi.label ? '<li>Target: ' + esc(c.kpi.label) + '</li>' : '') + (c.credit_estimate != null ? '<li>Up to ' + credits(c.credit_estimate) + '</li>' : '') + '</ul>' +
        '<div class="ft"><button type="button" class="cm-btn ghost" data-x>Cancel</button><button type="button" class="cm-btn primary" data-go>' + ic('play', 14) + 'Launch campaign</button></div>', function (w, close) {
        w.querySelector('[data-x]').onclick = close;
        w.querySelector('[data-go]').onclick = function (e) {
          e.target.disabled = true; e.target.textContent = 'Launching…';
          api('POST', 'growth/campaigns/' + id + '/launch', {}).then(function (x) { close(); if (x.ok && x.json.success) { toast('Launched — Sarah’s team is on it.', 'success'); S.view = 'one'; S.id = id; render(); } else toast((x.json && x.json.error) || 'Could not launch.', 'error'); });
        };
      });
    });
  }
  function declineDialog(id) {
    var reasons = ['Not the right time', 'Too much for us now', 'Not our style', 'We tried something similar'];
    dialog('<h3>Not now?</h3><p>Tell Sarah why, so her next ideas fit you better.</p><div class="ch">' + reasons.map(function (x) { return '<button type="button">' + esc(x) + '</button>'; }).join('') + '</div><div class="ft"><button type="button" class="cm-btn ghost" data-x>Cancel</button><button type="button" class="cm-btn primary" data-go>Save</button></div>', function (w, close) {
      var pick = null;
      w.querySelectorAll('.ch button').forEach(function (b) { b.onclick = function () { w.querySelectorAll('.ch button').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); pick = b.textContent; }; });
      w.querySelector('[data-x]').onclick = close;
      w.querySelector('[data-go]').onclick = function () { api('POST', 'growth/campaigns/' + id + '/decline', { reason: pick }).then(function () { close(); toast('Noted — Sarah will learn from it.', 'success'); S.view = 'list'; render(); }); };
    });
  }

  function renderOne() {
    root.innerHTML = '<div id="cm"><button type="button" class="cm-back">' + ic('back', 16) + 'All campaigns</button><div class="cm-empty" style="padding:22px">Loading…</div></div>';
    root.querySelector('.cm-back').onclick = function () { S.view = 'list'; render(); };
    api('GET', 'growth/campaigns/' + S.id).then(function (r) {
      var c = (r.json || {}).campaign; if (!c) { S.view = 'list'; render(); return; }
      var st = STAT[c.status] || [c.status, 'var(--t3)'];
      var acts = c.status === 'idea' ? '<button type="button" class="cm-btn primary" data-launch="' + c.id + '">' + ic('play', 14) + 'Launch</button><button type="button" class="cm-btn ghost" data-decline="' + c.id + '">Not now</button>'
        : c.status === 'active' ? '<button type="button" class="cm-btn" data-do="pause">' + ic('pause', 14) + 'Pause</button><button type="button" class="cm-btn ghost" data-do="complete">Finish now</button>'
        : c.status === 'paused' ? '<button type="button" class="cm-btn primary" data-launch="' + c.id + '">' + ic('play', 14) + 'Resume</button><button type="button" class="cm-btn ghost" data-do="complete">Finish now</button>'
        : (c.status === 'completed' || c.status === 'declined') ? '<button type="button" class="cm-btn ghost" data-do="archive">Archive</button>' : '';
      var res = c.results || null, k = c.kpi || {};
      var resHtml = res ? '<div class="cm-panel"><h4>Results so far</h4><div class="cm-res">' +
        (res.kpi_actual != null && k.target ? '<div class="m big"><b>' + res.kpi_actual + ' <span style="font-size:14px;color:var(--t3);font-weight:600">of ' + k.target + ' ' + esc(k.metric || '') + '</span></b><span>Target: ' + esc(k.label || '') + '</span></div>' : '') +
        '<div class="m"><b>' + (res.steps_done || 0) + '/' + (res.steps_total || 0) + '</b><span>Steps done</span></div>' +
        (res.leads != null ? '<div class="m"><b>' + res.leads + '</b><span>New leads' + (res.leads_before != null ? ' (' + res.leads_before + ' before)' : '') + '</span></div>' : '') +
        (res.buying_comments != null ? '<div class="m"><b>' + res.buying_comments + '</b><span>Buying comments</span></div>' : '') + (res.link_clicks != null ? '<div class="m"><b>' + res.link_clicks + '</b><span>Link clicks</span></div>' : '') +
        '</div><p class="cm-note">Counted during the campaign, next to the same length of time before it.</p></div>' : '';
      var phases = (c.phases || []).map(function (p) {
        return '<div class="cm-ph"><h3><span class="no">' + p.order + '</span>' + esc(p.name) + '</h3>' + p.items.map(function (it) {
          var s = ISTAT[it.status] || [it.status, 'var(--t3)'], at = d(it.scheduled_at);
          var canAct = c.status !== 'idea' && c.status !== 'declined';
          var ac = canAct ? (((it.kind === 'owner_task' || it.kind === 'email') && ['planned', 'needs_you', 'held'].indexOf(it.status) >= 0 ? '<button type="button" class="cm-btn sm" data-done="' + it.id + '">' + ic('check', 14) + 'Done</button>' : '') +
            (it.status === 'needs_you' && it.kind === 'post' ? '<button type="button" class="cm-btn sm primary" data-post="1">Review & post</button>' : '') +
            (it.result_url ? '<a class="cm-btn sm" href="' + esc(it.result_url) + '" target="_blank" rel="noopener">Open</a>' : '') +
            (['planned', 'needs_you', 'held', 'failed'].indexOf(it.status) >= 0 ? '<button type="button" class="cm-btn ghost sm" data-skip="' + it.id + '">Skip</button>' : '')) : '';
          return '<div class="cm-it' + (it.status === 'done' || it.status === 'skipped' ? ' done' : '') + '" data-it="' + it.id + '"><div class="dt">' + (at ? at.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + '<span>' + at.toLocaleDateString(undefined, { weekday: 'short' }) + '</span>' : '') + '</div>' +
            '<span class="cm-kic" style="background:' + (KCOL[it.kind] || '#64748B') + ';width:30px;height:30px">' + ic(it.kind === 'post' ? (it.channel || 'post') : it.kind, 15) + '</span>' +
            '<div class="bd"><b>' + esc(it.title) + '</b><div class="meta">' + esc(KIND[it.kind] || it.kind) + (it.channel && it.kind !== 'owner_task' ? ' · ' + esc(it.channel.replace('_', ' ')) : '') + ' ' + pill(s[0], s[1]) + (it.note && ['held', 'failed'].indexOf(it.status) >= 0 ? '<span>' + esc(it.note) + '</span>' : '') + '</div>' + (it.brief ? '<p>' + esc(it.brief) + '</p>' : '') + '</div>' +
            '<div class="ac">' + ac + '</div></div>';
        }).join('') + '</div>';
      }).join('');
      root.querySelector('#cm').innerHTML = '<button type="button" class="cm-back">' + ic('back', 16) + 'All campaigns</button>' +
        '<div class="cm-dh"><div class="l"><div class="cm-row">' + pill(c.status === 'idea' ? 'Idea from Sarah' : st[0], st[1]) + '<span>' + esc(range(c.starts_on, c.ends_on)) + '</span>' + (c.business_name ? '<span class="cm-tag">' + esc(c.business_name) + '</span>' : '') + '</div><h1>' + esc(c.title) + '</h1>' + (c.objective ? '<p>' + esc(c.objective) + '</p>' : '') + '</div><div class="cm-acts">' + acts + '</div></div>' +
        '<div class="cm-cols"><div>' + phases + '</div><div style="display:flex;flex-direction:column;gap:14px">' + resHtml +
        '<div class="cm-panel"><h4>The plan</h4><dl class="cm-facts">' + (c.why_now ? '<dt>Why now</dt><dd>' + esc(c.why_now) + '</dd>' : '') + (c.audience ? '<dt>Who it is for</dt><dd>' + esc(c.audience) + '</dd>' : '') + (c.offer ? '<dt>Offer</dt><dd>' + esc(c.offer) + '</dd>' : '') +
        (k.label ? '<dt>Target</dt><dd>' + esc(k.label) + '</dd>' : '') + '<dt>Channels</dt><dd class="cm-chans" style="gap:10px">' + (c.channels || []).map(function (x) { return '<span title="' + esc(x) + '">' + ic(x, 18) + '</span>'; }).join('') + '</dd>' +
        (c.status === 'idea' && c.credit_estimate != null ? '<dt>Cost</dt><dd>Up to ' + credits(c.credit_estimate) + '</dd>' : '') + '</dl>' +
        '<p class="cm-note">' + (c.status === 'idea' ? 'Launch approves the whole plan once. Each post and email still waits for your OK before it goes out.' : 'Each post and email waits for your OK before it goes out.') + '</p></div></div></div>';
      root.querySelector('.cm-back').onclick = function () { S.view = 'list'; render(); };
      wireCards(root);
      root.querySelectorAll('.cm-it').forEach(function (x) { x.addEventListener('click', function (e) { if (e.target.closest('button,a')) return; x.classList.toggle('open'); }); });
      root.querySelectorAll('[data-done]').forEach(function (b) { b.onclick = function () { api('PATCH', 'growth/campaigns/items/' + b.getAttribute('data-done'), { done: true }).then(renderOne); }; });
      root.querySelectorAll('[data-skip]').forEach(function (b) { b.onclick = function () { api('PATCH', 'growth/campaigns/items/' + b.getAttribute('data-skip'), { skip: true }).then(renderOne); }; });
      root.querySelectorAll('[data-post]').forEach(function (b) { b.onclick = function () { if (typeof window.nav === 'function') { window.nav('sarah'); toast('Your post is ready in Sarah’s chat — tap Post it.', 'info'); } }; });
      root.querySelectorAll('[data-do]').forEach(function (b) {
        b.onclick = function () {
          var a = b.getAttribute('data-do');
          var p = a === 'archive' ? api('DELETE', 'growth/campaigns/' + c.id) : api('POST', 'growth/campaigns/' + c.id + '/' + a, {});
          p.then(function (x) { if (x.ok) { toast(a === 'pause' ? 'Paused.' : a === 'complete' ? 'Finished — Sarah will report the results.' : 'Archived.', 'success'); if (a === 'archive') { S.view = 'list'; } render(); } else toast((x.json && x.json.error) || 'Could not do that.', 'error'); });
        };
      });
    });
  }


  /* ── WATCH-1 (RFC-0019): Market watch — trends, competitors, what people say, and what Sarah did about it ── */
  var WCSS = '.mw-top{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:14px;margin:0 0 18px;align-items:start}.mw-st{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px}.mw-st .big{font:700 16px var(--fh,var(--fb,inherit));color:var(--t1);display:flex;align-items:center;gap:8px}' +
    '.mw-live{width:9px;height:9px;border-radius:50%;background:#22A06B;box-shadow:0 0 0 4px color-mix(in srgb,#22A06B 22%,transparent)}.mw-off{width:9px;height:9px;border-radius:50%;background:var(--t3)}.mw-sub{color:var(--t3);font-size:12.5px;display:flex;flex-wrap:wrap;gap:4px 14px}' +
    '.mw-opts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:12px 0}.mw-opt{border:1px solid var(--bd);background:var(--s2);border-radius:12px;padding:12px;text-align:left;cursor:pointer;color:var(--t1);font:inherit;transition:border-color .15s,box-shadow .15s}.mw-opt b{display:block;font-size:14px}.mw-opt span{display:block;font-size:12px;color:var(--t3);margin-top:2px}' +
    '.mw-opt.on{border-color:var(--p);box-shadow:0 0 0 1px var(--p) inset;background:color-mix(in srgb,var(--p) 8%,var(--s2))}.mw-opt:focus-visible,.mw-tg:focus-visible{outline:2px solid var(--p);outline-offset:2px}' +
    '.mw-tgs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}.mw-tg{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--bd);background:var(--s1);color:var(--t2);border-radius:99px;padding:7px 12px 7px 8px;font:600 12.5px var(--fb,inherit);cursor:pointer}.mw-tg i{width:18px;height:18px;border-radius:6px;border:1.5px solid var(--bd2,var(--bd));display:grid;place-items:center;flex:none}' +
    '.mw-tg[aria-pressed=true]{color:var(--t1);border-color:color-mix(in srgb,var(--p) 55%,var(--bd))}.mw-tg[aria-pressed=true] i{background:var(--p);border-color:var(--p);color:#fff}.mw-tg i svg{opacity:0}.mw-tg[aria-pressed=true] i svg{opacity:1}' +
    '.mw-cols{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.mw-list{display:flex;flex-direction:column}.mw-li{display:flex;gap:12px;padding:11px 0;border-top:1px solid var(--bd)}.mw-li:first-child{border-top:0;padding-top:2px}.mw-li .tx{flex:1;min-width:0}.mw-li b{display:block;font-size:13.5px;color:var(--t1);font-weight:600}.mw-li p{margin:3px 0 0;font-size:12.5px;color:var(--t2)}.mw-li .mt{font-size:11.5px;color:var(--t3);margin-top:4px;display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center}' +
    '.mw-li a{color:var(--p);text-decoration:none;font-weight:600}.mw-li a:hover{text-decoration:underline}.mw-av{flex:none;width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font:700 13px var(--fb,inherit);color:#fff}' +
    '.mw-add{display:flex;gap:8px;margin-top:12px}.mw-add input{flex:1;min-width:0;height:36px;border:1px solid var(--bd);border-radius:9px;background:var(--s2);color:var(--t1);padding:0 11px;font:13px var(--fb,inherit)}.mw-add input:focus{outline:none;border-color:var(--p)}' +
    '.mw-emp{font-size:12.5px;color:var(--t3);padding:6px 0}.mw-sug{border:1px solid color-mix(in srgb,var(--p) 45%,var(--bd));background:linear-gradient(180deg,color-mix(in srgb,var(--p) 7%,var(--s1)),var(--s1) 70%);border-radius:var(--rg,14px);padding:16px;margin:0 0 12px}' +
    '.mw-sug h3{margin:0 0 4px;font:700 15px var(--fh,var(--fb,inherit));color:var(--t1)}.mw-sug p{margin:0 0 10px;color:var(--t2);font-size:13px}.mw-ch{margin:0 0 12px;padding:0;list-style:none}.mw-ch li{display:flex;gap:10px;align-items:baseline;padding:4px 0;font-size:13px;color:var(--t1)}.mw-ch em{flex:none;font-style:normal;font:700 10.5px var(--fb,inherit);letter-spacing:.06em;text-transform:uppercase;border-radius:6px;padding:2px 7px;background:var(--s2);border:1px solid var(--bd);color:var(--t2)}' +
    '.mw-seg2{display:inline-flex;flex-wrap:wrap;padding:3px;border:1px solid var(--bd);border-radius:10px;background:var(--s2);margin-top:8px}.mw-seg2 button{border:0;background:transparent;color:var(--t2);font:600 12.5px var(--fb,inherit);padding:6px 10px;border-radius:8px;cursor:pointer}.mw-seg2 button.on{background:var(--s1);color:var(--t1);box-shadow:0 1px 3px rgba(0,0,0,.2)}' +
    '@media (max-width:900px){.mw-top,.mw-cols{grid-template-columns:1fr}}@media (max-width:640px){.mw-opts{grid-template-columns:1fr}}';
  function wcss() { if (document.getElementById('mw-css')) return; var s = document.createElement('style'); s.id = 'mw-css'; s.textContent = WCSS; document.head.appendChild(s); }
  var TICK = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>';
  var AREAS = [['trends', 'Trends & local moments'], ['competitors', 'Competitors'], ['listening', 'What people say online']];
  function ago(s) { var x = d(s); if (!x) return ''; var m = Math.round((Date.now() - x) / 60000); if (m < 60) return m <= 1 ? 'just now' : m + ' min ago'; var h = Math.round(m / 60); if (h < 24) return h + ' h ago'; var dd = Math.round(h / 24); return dd === 1 ? 'yesterday' : dd + ' days ago'; }
  function changeList(chs) {
    return '<ul class="mw-ch">' + (chs || []).map(function (x) {
      var lab = x.op === 'add' ? 'Add' : x.op === 'move' ? 'Move' : 'Drop';
      return '<li><em>' + lab + '</em><span>' + esc(x.title) + (x.op === 'add' ? ' · ' + esc(KIND[x.kind] || x.kind) + ' · ' + esc(fmt(x.date, { weekday: 'short', month: 'short', day: 'numeric' })) : x.op === 'move' ? ' · ' + esc(fmt(x.from)) + ' → ' + esc(fmt(x.date, { weekday: 'short', month: 'short', day: 'numeric' })) : '') + '</span></li>';
    }).join('') + '</ul>';
  }
  window.LU_changeList = changeList;
  function setupPanel(j, st) {
    var w = j.watch || {}, opts = w.options || [];
    st.freq = st.freq || w.frequency || 'twice_weekly'; st.areas = st.areas || JSON.parse(JSON.stringify(w.areas || { trends: true, competitors: true, listening: true }));
    var per = 0; var COST = { trends: 6, competitors: 4, listening: 4 }; Object.keys(st.areas).forEach(function (k) { if (st.areas[k]) per += COST[k]; });
    var runs = { daily: 7, twice_weekly: 2, weekly: 1 };
    return '<div class="mw-opts" role="radiogroup" aria-label="How often">' + opts.map(function (o) { return '<button type="button" role="radio" aria-checked="' + (st.freq === o.frequency) + '" class="mw-opt' + (st.freq === o.frequency ? ' on' : '') + '" data-f="' + o.frequency + '"><b>' + esc(o.label) + '</b><span>About ' + (per * runs[o.frequency]) + ' credits a week</span></button>'; }).join('') + '</div>' +
      '<div class="mw-tgs">' + AREAS.map(function (a) { return '<button type="button" class="mw-tg" aria-pressed="' + !!st.areas[a[0]] + '" data-ar="' + a[0] + '"><i>' + TICK + '</i>' + esc(a[1]) + '</button>'; }).join('') + '</div>';
  }
  function renderWatch() {
    wcss();
    root.innerHTML = '<div id="cm">' + head('Sarah keeps an eye on trends, competitors and what people say about you, and turns it into campaign moves you approve.') + '<div class="cm-empty" style="padding:22px">Loading…</div></div>';
    wireHead();
    var st = S.wst || (S.wst = {});
    api('GET', 'growth/watch' + (S.biz ? '?business_id=' + S.biz : '')).then(function (r) {
      var j = r.json || {}; if (!r.ok || !j.success) { root.querySelector('#cm').innerHTML = head() + '<div class="cm-empty"><h3>Market watch could not load</h3><p>Please try again.</p></div>'; wireHead(); return; }
      var w = j.watch || {}, on = w.status === 'on', editing = !on || st.edit;
      var biz = (j.businesses || []).length > 1 ? '<div class="cm-biz" role="group" aria-label="Business">' + j.businesses.map(function (b) { var sel = (S.biz || 0) === b.id || (!S.biz && b.is_default); return '<button type="button" data-b="' + b.id + '" class="' + (sel ? 'on' : '') + '">' + esc(b.name) + '</button>'; }).join('') + '</div>' : '';
      var freqLab = { daily: 'Every day', twice_weekly: 'Twice a week', weekly: 'Once a week' }[w.frequency] || '';
      var areasOn = AREAS.filter(function (a) { return (w.areas || {})[a[0]]; }).map(function (a) { return a[1]; }).join(' · ');
      var status = on && !st.edit
        ? '<div class="mw-st"><div class="big"><span class="mw-live"></span>Sarah is watching · ' + esc(freqLab) + '</div><div class="cm-acts" style="margin-left:auto"><button type="button" class="cm-btn sm" data-w="now">Look now</button><button type="button" class="cm-btn sm" data-w="edit">Change</button><button type="button" class="cm-btn ghost sm" data-w="stop">Stop</button></div></div>' +
          '<div class="mw-sub" style="margin-top:8px"><span>' + esc(areasOn) + '</span><span>About ' + (w.credits_per_week || 0) + ' credits a week</span>' + (w.last_run_at ? '<span>Last look ' + esc(ago(w.last_run_at)) + '</span>' : '<span>First look in a few minutes</span>') + (w.next_run_at ? '<span>Next ' + esc(fmt(w.next_run_at, { weekday: 'short', month: 'short', day: 'numeric' })) + '</span>' : '') + '</div>'
        : '<div class="mw-st"><div class="big"><span class="mw-off"></span>' + (on ? 'Change what Sarah watches' : w.stopped_by === 'sarah' ? 'Sarah paused the watch' : 'Let Sarah watch the market for you') + '</div></div>' +
          '<p class="mw-sub" style="margin:6px 0 0;display:block">' + esc(w.stopped_by === 'sarah' && !on ? (w.stopped_reason || '') + '. Turn it back on when you are ready.' : 'She looks at what is trending in your industry and area, what competitors offer and change, and what people say about you — then suggests campaign moves you approve. It uses a few credits each time; one yes keeps it running until you say stop.') + '</p>' +
          setupPanel(j, st) + '<div class="cm-acts"><button type="button" class="cm-btn primary" data-w="save">' + (on ? 'Save changes' : 'Start watching') + '</button>' + (on ? '<button type="button" class="cm-btn ghost" data-w="cancel">Cancel</button>' : '') + '</div>';
      var ci = j.checkins || 'on';
      var side = '<div class="cm-panel"><h4>Sarah’s check-ins</h4><p class="mw-sub" style="display:block;margin:0">A morning brief, a quick afternoon hello, an evening wrap-up and a Friday question about how it’s going. Your answers teach her your business.</p>' +
        '<div class="mw-seg2" role="radiogroup" aria-label="Check-ins">' + [['on', 'Afternoon & evening'], ['no_night', 'Afternoon only'], ['off', 'Brief only']].map(function (o) { return '<button type="button" role="radio" aria-checked="' + (ci === o[0]) + '" class="' + (ci === o[0] ? 'on' : '') + '" data-ci="' + o[0] + '">' + o[1] + '</button>'; }).join('') + '</div></div>';
      var sug = (j.changes_waiting || []).map(function (c) {
        return '<div class="mw-sug" data-ch="' + c.change_id + '"><div class="cm-row" style="margin:0 0 6px">' + pill('Sarah suggests', 'var(--p)') + '<span>' + esc(c.campaign_title) + '</span></div><h3>Update “' + esc(c.campaign_title) + '”</h3><p>' + esc(c.reason) + '</p>' + changeList(c.changes) +
          '<div class="cm-acts"><button type="button" class="cm-btn primary sm" data-ok="' + c.change_id + '">' + ic('check', 14) + 'Approve' + (c.extra_credits ? ' · up to ' + credits(c.extra_credits) : '') + '</button><button type="button" class="cm-btn ghost sm" data-no="' + c.change_id + '">Keep as is</button></div></div>';
      }).join('');
      var li = function (items, fn, empty) { return items.length ? '<div class="mw-list">' + items.map(fn).join('') + '</div>' : '<div class="mw-emp">' + empty + '</div>'; };
      var trends = li(j.trends || [], function (t) { return '<div class="mw-li"><span class="mw-av" style="background:' + (t.kind === 'moment' ? '#E0685A' : '#6C8CFF') + '">' + ic(t.kind === 'moment' ? 'cal' : 'spark', 16) + '</span><div class="tx"><b>' + esc(t.title) + '</b>' + (t.detail ? '<p>' + esc(t.detail) + '</p>' : '') + '<div class="mt">' + (t.kind === 'moment' ? '<span>Local moment' + (t.date ? ' · ' + esc(fmt(t.date)) : '') + '</span>' : '<span>Trend</span>') + '<span>' + esc(ago(t.at)) + '</span>' + (t.acted ? '<span style="color:#22A06B;font-weight:600">Sarah acted on this</span>' : '') + (t.url ? '<a href="' + esc(t.url) + '" target="_blank" rel="noopener">Source</a>' : '') + '</div></div></div>'; }, on ? 'Nothing yet — Sarah shares what she finds after her next look.' : 'Turn on the watch and Sarah will bring trends and local moments that matter to your business.');
      var comps = li(j.competitors || [], function (c) { return '<div class="mw-li"><span class="mw-av" style="background:#8A93A6">' + esc((c.name || '?').charAt(0).toUpperCase()) + '</span><div class="tx"><b>' + esc(c.name) + '</b>' + (c.last_change ? '<p><span style="color:#D97706;font-weight:600">Changed:</span> ' + esc(c.last_change) + '</p>' : c.summary ? '<p>' + esc(c.summary) + '</p>' : '') + '<div class="mt">' + (c.domain ? '<a href="https://' + esc(c.domain) + '" target="_blank" rel="noopener">' + esc(c.domain) + '</a>' : '') + '<span>' + (c.last_checked_at ? 'Checked ' + esc(ago(c.last_checked_at)) : 'Not checked yet') + '</span>' + (c.source === 'owner' ? '<span>Added by you</span>' : '') + '<button type="button" class="cm-btn ghost sm" style="min-height:24px;padding:0 6px" data-rmc="' + c.id + '" aria-label="Stop watching ' + esc(c.name) + '">Remove</button></div></div></div>'; }, on ? 'Sarah finds your competitors on her first look. You can also add one below.' : 'Sarah finds your competitors when the watch is on. You can add ones you know now.') +
        '<div class="mw-add"><input type="text" maxlength="200" placeholder="Add a competitor’s website" aria-label="Competitor website"><button type="button" class="cm-btn sm" data-addc>Add</button></div>';
      var ment = li(j.mentions || [], function (m) { var col = m.sentiment === 'negative' ? '#DC2626' : m.sentiment === 'positive' ? '#22A06B' : '#8A93A6'; return '<div class="mw-li"><span class="mw-av" style="background:' + col + '">' + ic('website', 16) + '</span><div class="tx"><b>' + esc(m.source_title || m.source_domain) + '</b>' + (m.excerpt ? '<p>' + esc(m.excerpt) + '</p>' : '') + '<div class="mt">' + pill(m.sentiment === 'negative' ? 'Critical' : m.sentiment === 'positive' ? 'Positive' : 'Neutral', col) + '<span>' + esc(m.source_domain || '') + '</span><a href="' + esc(m.source_url) + '" target="_blank" rel="noopener">Open</a></div></div></div>'; }, on ? 'No mentions found yet.' : 'Sarah searches the web for your business name when the watch is on.');
      var did = li(j.activity || [], function (a) { var lab = { adjust: 'Suggested a campaign update', propose: 'Suggested a new campaign', tell: 'Told you' }[a.did] || a.did; return '<div class="mw-li"><span class="mw-av" style="background:var(--p)">' + ic('spark', 15) + '</span><div class="tx"><b>' + esc(lab) + '</b><p>' + esc(a.why || a.title) + '</p><div class="mt"><span>' + esc(a.title) + '</span><span>' + esc(ago(a.at)) + '</span></div></div></div>'; }, 'When something happens that matters — a trend, a competitor move, a slow week or a campaign behind target — Sarah suggests what to do here and in her chat.');
      root.querySelector('#cm').innerHTML = head('Sarah keeps an eye on trends, competitors and what people say about you, and turns it into campaign moves you approve.') + biz +
        '<div class="mw-top"><div class="cm-panel">' + status + '</div>' + side + '</div>' + sug +
        '<div class="mw-cols"><section class="cm-panel"><h4>Trends & local moments</h4>' + trends + '</section><section class="cm-panel"><h4>Competitors</h4>' + comps + '</section>' +
        '<section class="cm-panel"><h4>What people say online</h4>' + ment + '</section><section class="cm-panel"><h4>What Sarah did about it</h4>' + did + '</section></div>';
      wireHead();
      var bizId = S.biz || null;
      root.querySelectorAll('.cm-biz [data-b]').forEach(function (b) { b.onclick = function () { S.biz = +b.getAttribute('data-b') || 0; S.wst = {}; renderWatch(); }; });
      root.querySelectorAll('.mw-opt').forEach(function (b) { b.onclick = function () { st.freq = b.getAttribute('data-f'); renderWatch(); }; });
      root.querySelectorAll('.mw-tg').forEach(function (b) { b.onclick = function () { var k = b.getAttribute('data-ar'); st.areas[k] = !st.areas[k]; renderWatch(); }; });
      root.querySelectorAll('[data-ci]').forEach(function (b) { b.onclick = function () { api('POST', 'growth/checkins', { value: b.getAttribute('data-ci') }).then(function (x) { if (x.ok) { toast('Saved.', 'success'); renderWatch(); } }); }; });
      root.querySelectorAll('[data-w]').forEach(function (b) {
        b.onclick = function () {
          var a = b.getAttribute('data-w');
          if (a === 'edit') { st.edit = true; st.freq = w.frequency; st.areas = JSON.parse(JSON.stringify(w.areas)); renderWatch(); return; }
          if (a === 'cancel') { S.wst = {}; renderWatch(); return; }
          b.disabled = true;
          var p = a === 'save' ? api('POST', 'growth/watch', { business_id: bizId, frequency: st.freq, areas: st.areas }) : a === 'stop' ? api('POST', 'growth/watch/stop', { business_id: bizId }) : api('POST', 'growth/watch/run', { business_id: bizId });
          p.then(function (x) { var ok = x.ok && x.json.success; toast(ok ? (a === 'save' ? (on ? 'Saved.' : 'Sarah is on it — her first look starts in a few minutes.') : a === 'stop' ? 'Stopped. Nothing more is spent.' : x.json.message) : ((x.json && x.json.error) || 'Could not do that.'), ok ? 'success' : 'error'); if (ok) S.wst = {}; renderWatch(); });
        };
      });
      var inp = root.querySelector('.mw-add input'), add = root.querySelector('[data-addc]');
      function doAdd() { var v = (inp.value || '').trim(); if (!v) return; add.disabled = true; api('POST', 'growth/competitors', { business_id: bizId, url: v, name: '' }).then(function (x) { if (x.ok && x.json.success) { toast('Sarah will watch them from her next look.', 'success'); renderWatch(); } else { add.disabled = false; toast((x.json && x.json.error) || 'Could not add.', 'error'); } }); }
      if (add) { add.onclick = doAdd; inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } }); }
      root.querySelectorAll('[data-rmc]').forEach(function (b) { b.onclick = function () { api('DELETE', 'growth/competitors/' + b.getAttribute('data-rmc')).then(function () { renderWatch(); }); }; });
      root.querySelectorAll('[data-ok]').forEach(function (b) { b.onclick = function () { b.disabled = true; api('POST', 'growth/changes/' + b.getAttribute('data-ok') + '/approve', {}).then(function (x) { toast(x.ok && x.json.success ? 'Updated — the new steps are on the calendar.' : ((x.json && x.json.error) || 'Could not update.'), x.ok && x.json.success ? 'success' : 'error'); renderWatch(); }); }; });
      root.querySelectorAll('.mw-sug [data-no]').forEach(function (b) { b.onclick = function () { api('POST', 'growth/changes/' + b.getAttribute('data-no') + '/decline', {}).then(function () { toast('Kept as is — Sarah will learn from it.', 'success'); renderWatch(); }); }; });
    });
  }

  function renderCal() {
    var m = S.month || new Date(); m = new Date(m.getFullYear(), m.getMonth(), 1); S.month = m;
    var first = new Date(m); first.setDate(1 - ((m.getDay() + 6) % 7));
    var last = new Date(first); last.setDate(first.getDate() + 41);
    var iso = function (x) { return x.getFullYear() + '-' + ('0' + (x.getMonth() + 1)).slice(-2) + '-' + ('0' + x.getDate()).slice(-2); };
    root.innerHTML = '<div id="cm">' + head('Every campaign step on its date, with your bookings. Tap a step to open its campaign.') + '<div class="cm-cal"><div class="cm-empty" style="border:0;padding:30px">Loading the calendar…</div></div></div>';
    wireHead();
    api('GET', 'growth/campaigns/calendar?from=' + iso(first) + '&to=' + iso(last)).then(function (r) {
      var j = r.json || {}, by = {};
      (j.items || []).forEach(function (it) { var k = iso(d(it.scheduled_at)); (by[k] = by[k] || []).push({ t: it.title, k: it.kind, c: it.campaign_id, idea: it.campaign_status === 'idea', done: it.status === 'done', title: it.campaign_title }); });
      (j.bookings || []).forEach(function (b) { var k = iso(d(b.starts_at)); (by[k] = by[k] || []).push({ t: b.title, k: 'booking' }); });
      (j.events || []).forEach(function (b) { var k = iso(d(b.starts_at)); (by[k] = by[k] || []).push({ t: b.title, k: 'other' }); });
      var today = iso(new Date()), cells = '';
      for (var i = 0; i < 42; i++) {
        var x = new Date(first); x.setDate(first.getDate() + i); var k = iso(x), evs = by[k] || [];
        cells += '<div class="cm-day' + (x.getMonth() !== m.getMonth() ? ' out' : '') + (k === today ? ' today' : '') + '"><span class="num">' + x.getDate() + '</span>' +
          evs.slice(0, 3).map(function (e) { var col = e.k === 'booking' ? '#14B8A6' : e.k === 'other' ? '#94A3B8' : (KCOL[e.k] || '#64748B'); return '<div class="cm-chip' + (e.idea ? ' idea' : '') + (e.done ? ' done' : '') + '" style="--k:' + col + '" ' + (e.c ? 'data-c="' + e.c + '"' : '') + ' title="' + esc(e.t + (e.title ? ' — ' + e.title : '')) + '">' + ic(e.k === 'booking' ? 'event' : e.k === 'other' ? 'cal' : e.k, 12) + '<span>' + esc(e.t) + '</span></div>'; }).join('') +
          (evs.length > 3 ? '<div class="cm-more">+' + (evs.length - 3) + ' more</div>' : '') + '</div>';
      }
      var dows = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      root.querySelector('.cm-cal').innerHTML = '<div class="cm-calh"><button type="button" class="cm-btn ghost sm" data-m="-1" aria-label="Previous month">' + ic('back', 16) + '</button><b>' + m.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }) + '</b><button type="button" class="cm-btn ghost sm" data-m="0">Today</button><button type="button" class="cm-btn ghost sm" data-m="1" aria-label="Next month">' + ic('chev', 16) + '</button></div>' +
        '<div class="cm-dow">' + dows.map(function (x) { return '<div>' + x + '</div>'; }).join('') + '</div><div class="cm-days">' + cells + '</div>' +
        '<div class="cm-leg">' + ['post', 'article', 'email', 'image', 'event', 'owner_task'].map(function (k) { return '<span><i style="background:' + KCOL[k] + '"></i>' + KIND[k] + '</span>'; }).join('') + '<span><i style="background:#14B8A6"></i>Bookings</span><span><i style="background:transparent;border:1px dashed var(--t3)"></i>Idea (not launched)</span></div>';
      root.querySelectorAll('[data-m]').forEach(function (b) { b.onclick = function () { var v = +b.getAttribute('data-m'); S.month = v === 0 ? new Date() : new Date(m.getFullYear(), m.getMonth() + v, 1); renderCal(); }; });
      root.querySelectorAll('.cm-chip[data-c]').forEach(function (ch) { ch.onclick = function () { S.view = 'one'; S.id = +ch.getAttribute('data-c'); render(); }; });
    });
  }
})();
