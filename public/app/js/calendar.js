/**
 * LevelUpGrowth — Calendar (CAL-2, 2026-09-29)
 * The OWNER's own calendar: calls, meetings, appointments, client bookings and requests, follow-ups, emails,
 * to-dos and strategy meetings with the AI agents. Engine content schedules (campaign posts, social posts, SEO)
 * live in their own engines' calendars and are not shown here.
 * Views: Agenda (today + 7 days, default), Week, Month. Reminders are sent by Sarah (chat, notification, email).
 * Entry: calLoad(el). Kept for other engines: calBookingDecide(id, decision).
 */
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['calendar'] = true;

(function () {
'use strict';
var C = { view: 'agenda', anchor: null, items: [], biz: '', bizList: null };
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
function I(n, s) { return window.icon ? window.icon(n, s || 16) : ''; }
function hdr() { return {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}; }
async function api(m, p, b) {
    var r = await fetch('/api' + p, {method: m, headers: hdr(), body: b ? JSON.stringify(b) : undefined, cache: 'no-store'});
    var j = {}; try { j = await r.json(); } catch (e) {}
    if (!r.ok || (j && j.success === false)) throw new Error((j && (j.message || j.error)) || ('HTTP ' + r.status));
    return j;
}
function toast(m, t) { try { showToast(m, t || 'success'); } catch (e) {} }
function pad(n) { return n < 10 ? '0' + n : '' + n; }
function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
function dt(d) { return ymd(d) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00'; }
function parse(s) { if (!s) return null; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? null : d; }
function tm(d) { return d ? d.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'}) : ''; }
function dayName(d) { var t = new Date(); var tmr = new Date(Date.now() + 86400000); if (d.toDateString() === t.toDateString()) return 'Today'; if (d.toDateString() === tmr.toDateString()) return 'Tomorrow'; return d.toLocaleDateString('en-US', {weekday: 'long', month: 'short', day: 'numeric'}); }

// kinds the owner sees
var K = {
    call: {l: 'Call', i: 'phone', c: 'var(--bl)'}, meeting: {l: 'Meeting', i: 'users', c: 'var(--pu)'}, appointment: {l: 'Appointment', i: 'calendar', c: 'var(--ac)'},
    booking: {l: 'Booking', i: 'calendar', c: 'var(--ac)'}, callback: {l: 'Callback', i: 'phone', c: 'var(--bl)'}, strategy: {l: 'Strategy meeting', i: 'ai', c: 'var(--p)'},
    follow_up: {l: 'Follow-up', i: 'check', c: 'var(--am)'}, email: {l: 'Email', i: 'mail', c: 'var(--pu)'}, chat: {l: 'Chat', i: 'message', c: 'var(--bl)'},
    todo: {l: 'To-do', i: 'check', c: 'var(--am)'}, reminder: {l: 'Reminder', i: 'clock', c: 'var(--t2)'}, personal: {l: 'Personal', i: 'star', c: 'var(--t2)'}, event: {l: 'Event', i: 'calendar', c: 'var(--t2)'}
};
var ST = {pending: ['Waiting for you', 'cal2-s-pending'], confirmed: ['Confirmed', 'cal2-s-ok'], done: ['Done', 'cal2-s-done'], no_show: ['No-show', 'cal2-s-bad'], cancelled: ['Cancelled', 'cal2-s-bad'], scheduled: ['', '']};

try {
    var P = window.icon && window.icon._paths;
    if (P) {
        if (!P.phone) P.phone = '<path d="M5 3h3l1.5 4-2 1.2a9 9 0 004.3 4.3L13 10.5l4 1.5v3a2 2 0 01-2 2A13 13 0 013 5a2 2 0 012-2z"/>';
        if (!P.mail) P.mail = '<rect x="2.5" y="4.5" width="15" height="11" rx="2"/><path d="M3 6l7 5 7-5"/>';
        if (!P.users) P.users = '<circle cx="8" cy="7" r="3"/><path d="M2.5 17a5.5 5.5 0 0111 0"/><path d="M13 4.2a3 3 0 010 5.6M15.5 17a5 5 0 00-2.5-4.3"/>';
    }
} catch (e) {}

(function css() {
    if (document.getElementById('cal2-css')) return;
    var s = document.createElement('style'); s.id = 'cal2-css';
    s.textContent = [
        '#calendar-root .cal2{max-width:1280px;margin:0 auto;font-family:var(--fb)}#calendar-root a.btn,.cal2-sheet a.btn{text-decoration:none}',
        '.cal2-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:6px 0 18px}',
        '.cal2-head h1{font:700 28px var(--fh);color:var(--t1);margin:0}.cal2-head p{margin:4px 0 0;color:var(--t3);font-size:13px}',
        '.cal2-acts{display:flex;gap:10px;align-items:center;flex-wrap:wrap}',
        '.cal2-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:16px}',
        '.cal2-seg{display:inline-flex;background:var(--s2);border:1px solid var(--bd);border-radius:10px;padding:3px}',
        '.cal2-seg button{appearance:none;border:0;background:none;color:var(--t2);font:600 13px var(--fb);padding:8px 14px;border-radius:8px;cursor:pointer;min-height:38px}',
        '.cal2-seg button[aria-pressed=true]{background:var(--s1);color:var(--t1);box-shadow:0 1px 2px rgba(0,0,0,.15)}',
        '.cal2-nav{display:flex;align-items:center;gap:8px}.cal2-nav b{font:600 15px var(--fb);color:var(--t1);min-width:160px;text-align:center}',
        '.cal2-day{margin-bottom:18px}.cal2-day h2{font:600 13px var(--fb);color:var(--t3);margin:0 0 8px;letter-spacing:.02em}.cal2-day h2.today{color:var(--t1)}',
        '.cal2-list{background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);overflow:hidden}',
        '.cal2-it{display:grid;grid-template-columns:78px 4px minmax(0,1fr) auto;gap:14px;align-items:center;padding:12px 16px;cursor:pointer}',
        '.cal2-it+.cal2-it{border-top:1px solid var(--bd)}.cal2-it:hover{background:var(--s2)}',
        '.cal2-it .t{font:600 13px var(--fb);color:var(--t1);text-align:right}.cal2-it .t small{display:block;font-weight:400;color:var(--t3);font-size:12px}',
        '.cal2-it .rail{align-self:stretch;border-radius:4px}',
        '.cal2-it .ti{font:600 14px var(--fb);color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
        '.cal2-it .me{font-size:12px;color:var(--t3);margin-top:3px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}',
        '.cal2-it.past .ti{color:var(--t2)}.cal2-it.off .ti{text-decoration:line-through;color:var(--t3)}',
        '.cal2-pill{font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px}',
        '.cal2-s-pending{background:rgba(245,158,11,.14);color:var(--am)}.cal2-s-ok{background:var(--as);color:var(--ac)}.cal2-s-done{background:var(--s2);color:var(--t2)}.cal2-s-bad{background:rgba(248,113,113,.12);color:var(--rd)}',
        '.cal2-empty{padding:26px 16px;text-align:center;color:var(--t3);font-size:13px}.cal2-empty b{display:block;color:var(--t1);font-size:14px;margin-bottom:4px}',
        '.cal2-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}',
        '.cal2-wd{background:var(--s1);border:1px solid var(--bd);border-radius:12px;min-height:180px;display:flex;flex-direction:column}',
        '.cal2-wd h3{margin:0;padding:10px 12px;font:600 12px var(--fb);color:var(--t3);border-bottom:1px solid var(--bd)}.cal2-wd h3.today{color:var(--t1);background:var(--ps)}',
        '.cal2-wd .b{padding:8px;display:flex;flex-direction:column;gap:6px}',
        '.cal2-chip{border-left:3px solid;border-radius:6px;background:var(--s2);padding:6px 8px;font-size:12px;color:var(--t1);cursor:pointer;text-align:left;border-top:0;border-right:0;border-bottom:0;font-family:var(--fb)}',
        '.cal2-chip small{display:block;color:var(--t3)}',
        '.cal2-month{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}',
        '.cal2-mh{font:600 11px var(--fb);color:var(--t3);text-align:center;padding:6px 0;text-transform:uppercase}',
        '.cal2-md{background:var(--s1);border:1px solid var(--bd);border-radius:10px;min-height:96px;padding:6px;cursor:pointer;display:flex;flex-direction:column;gap:3px;appearance:none;text-align:left;font-family:var(--fb)}',
        '.cal2-md .n{font:600 12px var(--fb);color:var(--t2)}.cal2-md.today .n{color:#fff;background:var(--p);border-radius:999px;width:22px;height:22px;display:flex;align-items:center;justify-content:center}',
        '.cal2-md.out{opacity:.45}.cal2-md .d{font-size:11px;border-radius:4px;padding:2px 5px;background:var(--s2);color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
        '.cal2-sheet .row{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-top:1px solid var(--bd);font-size:14px;color:var(--t1)}.cal2-sheet .row span.k{color:var(--t3);min-width:84px;font-size:13px}',
        '.cal2-sheet .btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}',
        '.cal2-field{display:grid;gap:6px}.cal2-field label{font-size:12px;font-weight:600;color:var(--t3)}',
        '.cal2-kinds{display:flex;gap:6px;flex-wrap:wrap}.cal2-kinds button{appearance:none;border:1px solid var(--bd2);background:var(--s1);color:var(--t2);border-radius:999px;padding:8px 12px;font:600 13px var(--fb);cursor:pointer;min-height:40px;display:inline-flex;gap:6px;align-items:center}',
        '.cal2-kinds button[aria-pressed=true]{border-color:var(--p);color:var(--t1);background:var(--ps)}',
        '.cal2-client{position:relative}.cal2-client ul{position:absolute;z-index:20;left:0;right:0;top:100%;margin:4px 0 0;padding:4px;list-style:none;background:var(--s1);border:1px solid var(--bd2);border-radius:10px;max-height:220px;overflow:auto;box-shadow:0 8px 24px rgba(0,0,0,.25)}',
        '.cal2-client li{padding:10px;border-radius:8px;cursor:pointer;font-size:14px;color:var(--t1)}.cal2-client li:hover,.cal2-client li[aria-selected=true]{background:var(--s2)}.cal2-client li small{color:var(--t3);margin-left:6px}',
        '@media (max-width:900px){.cal2-week{grid-template-columns:1fr}.cal2-wd{min-height:0}}',
        '@media (max-width:760px){.cal2-head h1{font-size:24px}.cal2-acts{width:100%}.cal2-acts>*{flex:1 1 auto}',
        ' .cal2-it{grid-template-columns:64px 4px minmax(0,1fr);}.cal2-it .x{grid-column:3}',
        ' .cal2-md{min-height:58px}.cal2-md .d{display:none}.cal2-md .dots{display:flex;gap:3px;flex-wrap:wrap}.cal2-md .dots i{width:6px;height:6px;border-radius:50%;display:block}',
        ' #calendar-root .btn,#calendar-root .cal2-seg button{min-height:44px}#calendar-root .form-input,#calendar-root .form-select{font-size:16px;min-height:44px}}',
        '@media (min-width:761px){.cal2-md .dots{display:none}}'
    ].join('\n');
    document.head.appendChild(s);
})();

function range() {
    var a = C.anchor ? new Date(C.anchor) : new Date(); a.setHours(0, 0, 0, 0);
    if (C.view === 'agenda') return [a, new Date(a.getTime() + 8 * 86400000 - 1000)];
    if (C.view === 'week') { var s = new Date(a); s.setDate(a.getDate() - a.getDay()); return [s, new Date(s.getTime() + 7 * 86400000 - 1000)]; }
    var f = new Date(a.getFullYear(), a.getMonth(), 1); f.setDate(1 - f.getDay());
    return [f, new Date(f.getTime() + 42 * 86400000 - 1000)];
}
async function load() {
    if (!C.bizList) { try { var b = await api('GET', '/businesses'); C.bizList = (b.businesses || b.data || []).map(function (x) { return {id: x.id, name: x.name}; }); } catch (e) { C.bizList = []; } }
    try { C.biz = localStorage.getItem('lu_crm_biz') || ''; } catch (e) {}
    if (C.biz && C.biz !== 'none' && !C.bizList.some(function (b) { return String(b.id) === C.biz; })) C.biz = '';
    if (C.bizList.length < 2) C.biz = '';
    var r = range();
    var j = await api('GET', '/calendar/agenda?from=' + encodeURIComponent(dt(r[0])) + '&to=' + encodeURIComponent(dt(r[1])) + (C.biz ? '&business_id=' + encodeURIComponent(C.biz) : ''));
    C.items = j.items || [];
}
function root() { return document.getElementById('calendar-root'); }

function label(it) { return String(it.title || '').replace(/^(booking|quote|callback) requests*[—-]s*/i, ''); }
function itemRow(it) {
    var k = K[it.kind] || K.event, s = parse(it.starts_at), e = parse(it.ends_at), st = ST[it.status] || ST.scheduled;
    var past = s && s < new Date() && it.status !== 'pending';
    var off = it.status === 'cancelled';
    var who = it.client ? esc(it.client.name) : '';
    var meta = [k.l, who, it.location ? esc(it.location) : '', C.bizList.length > 1 && !C.biz && it.business_name ? esc(it.business_name) : ''].filter(Boolean).join(' · ');
    return '<div class="cal2-it' + (past ? ' past' : '') + (off ? ' off' : '') + '" role="button" tabindex="0" onclick="calOpen(\'' + it.id + '\')" onkeydown="if(event.key===\'Enter\')calOpen(\'' + it.id + '\')">' +
        '<div class="t">' + (it.all_day ? 'All day' : esc(tm(s))) + (e && !it.all_day ? '<small>' + esc(tm(e)) + '</small>' : '') + '</div>' +
        '<div class="rail" style="background:' + k.c + '"></div>' +
        '<div style="min-width:0"><div class="ti">' + esc(label(it)) + '</div><div class="me">' + I(k.i, 13) + '<span>' + meta + '</span>' + (st[0] ? '<span class="cal2-pill ' + st[1] + '">' + st[0] + '</span>' : '') + '</div></div>' +
        '<div class="x" onclick="event.stopPropagation()">' + quick(it) + '</div></div>';
}
function quick(it) {
    if (it.can_decide) return '<span style="display:inline-flex;gap:6px"><button class="btn btn-primary btn-sm" onclick="calBookingDecide(' + it.id + ',\'confirm\')">Confirm</button><button class="btn btn-outline btn-sm" onclick="calBookingDecide(' + it.id + ',\'decline\')">Decline</button></span>';
    if (it.kind === 'strategy' && it.status !== 'done' && String(it.id).charAt(0) !== 'm') return '<button class="btn btn-outline btn-sm" onclick="calJoin(\'' + it.id + '\')">' + I('ai', 14) + ' Join</button>';
    var tel = it.client && it.client.phone ? String(it.client.phone).replace(/[^0-9+]/g, '') : '';
    if ((it.kind === 'call' || it.kind === 'callback') && tel && it.status !== 'done') return '<a class="btn btn-outline btn-sm" href="tel:' + esc(tel) + '">' + I('phone', 14) + ' Call</a>';
    return '';
}
function agendaHtml() {
    var r = range(), days = [], d = new Date(r[0]);
    for (var i = 0; i < 8; i++) { days.push(new Date(d)); d.setDate(d.getDate() + 1); }
    var any = false;
    var html = days.map(function (day) {
        var its = C.items.filter(function (it) { var s = parse(it.starts_at); return s && s.toDateString() === day.toDateString(); });
        if (!its.length && day.toDateString() !== new Date().toDateString()) return '';
        any = any || its.length > 0;
        return '<section class="cal2-day"><h2 class="' + (day.toDateString() === new Date().toDateString() ? 'today' : '') + '">' + esc(dayName(day)) + '</h2><div class="cal2-list">' +
            (its.length ? its.map(itemRow).join('') : '<div class="cal2-empty">Nothing booked today.</div>') + '</div></section>';
    }).join('');
    return html + (any ? '' : '<div class="cal2-list"><div class="cal2-empty"><b>Your next 7 days are clear.</b>Calls, meetings, client bookings and strategy meetings you schedule show up here, with reminders before each one.<div style="margin-top:12px"><button class="btn btn-primary" onclick="calNew()">' + I('add', 14) + ' Schedule something</button></div></div></div>');
}
function weekHtml() {
    var r = range(), out = '', d = new Date(r[0]);
    for (var i = 0; i < 7; i++) {
        var day = new Date(d);
        var its = C.items.filter(function (it) { var s = parse(it.starts_at); return s && s.toDateString() === day.toDateString(); });
        out += '<div class="cal2-wd"><h3 class="' + (day.toDateString() === new Date().toDateString() ? 'today' : '') + '">' + esc(day.toLocaleDateString('en-US', {weekday: 'short', day: 'numeric'})) + '</h3><div class="b">' +
            (its.map(function (it) { var k = K[it.kind] || K.event; return '<button class="cal2-chip" style="border-left-color:' + k.c + '" onclick="calOpen(\'' + it.id + '\')">' + esc(it.title) + '<small>' + esc(it.all_day ? 'All day' : tm(parse(it.starts_at))) + (it.client ? ' · ' + esc(it.client.name) : '') + '</small></button>'; }).join('') || '<span style="font-size:12px;color:var(--t3);padding:4px">—</span>') + '</div></div>';
        d.setDate(d.getDate() + 1);
    }
    return '<div class="cal2-week">' + out + '</div>';
}
function monthHtml() {
    var r = range(), a = C.anchor ? new Date(C.anchor) : new Date(), d = new Date(r[0]), cells = '';
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (n) { cells += '<div class="cal2-mh">' + n + '</div>'; });
    for (var i = 0; i < 42; i++) {
        var day = new Date(d);
        var its = C.items.filter(function (it) { var s = parse(it.starts_at); return s && s.toDateString() === day.toDateString(); });
        cells += '<button class="cal2-md' + (day.getMonth() !== a.getMonth() ? ' out' : '') + (day.toDateString() === new Date().toDateString() ? ' today' : '') + '" onclick="calGoDay(\'' + ymd(day) + '\')" aria-label="' + esc(day.toDateString()) + ', ' + its.length + ' items"><span class="n">' + day.getDate() + '</span>' +
            its.slice(0, 3).map(function (it) { var k = K[it.kind] || K.event; return '<span class="d" style="border-left:3px solid ' + k.c + '">' + esc(tm(parse(it.starts_at))) + ' ' + esc(it.title) + '</span>'; }).join('') +
            (its.length > 3 ? '<span class="d">+' + (its.length - 3) + ' more</span>' : '') +
            '<span class="dots">' + its.slice(0, 5).map(function (it) { return '<i style="background:' + (K[it.kind] || K.event).c + '"></i>'; }).join('') + '</span></button>';
        d.setDate(d.getDate() + 1);
    }
    return '<div class="cal2-month">' + cells + '</div>';
}
function title() {
    var a = C.anchor ? new Date(C.anchor) : new Date(), r = range();
    if (C.view === 'month') return a.toLocaleDateString('en-US', {month: 'long', year: 'numeric'});
    if (C.view === 'week') return r[0].toLocaleDateString('en-US', {month: 'short', day: 'numeric'}) + ' – ' + new Date(r[1]).toLocaleDateString('en-US', {month: 'short', day: 'numeric'});
    return 'From ' + a.toLocaleDateString('en-US', {weekday: 'short', month: 'short', day: 'numeric'});
}
function render() {
    var el = root(); if (!el) return;
    var pending = C.items.filter(function (i) { return i.can_decide; }).length;
    var today = C.items.filter(function (i) { var s = parse(i.starts_at); return s && s.toDateString() === new Date().toDateString() && i.status !== 'cancelled'; }).length;
    var bizSel = C.bizList.length > 1 ? '<select class="form-select" aria-label="Business" style="max-width:260px" onchange="calSetBiz(this.value)"><option value="">All businesses</option>' + C.bizList.map(function (b) { return '<option value="' + b.id + '"' + (String(C.biz) === String(b.id) ? ' selected' : '') + '>' + esc(b.name) + '</option>'; }).join('') + '</select>' : '';
    el.innerHTML = '<div class="cal2">' +
        '<div class="cal2-head"><div><h1>Calendar</h1><p>' + today + ' today' + (pending ? ' · ' + pending + ' request' + (pending > 1 ? 's' : '') + ' waiting for you' : '') + ' · reminders on</p></div>' +
        '<div class="cal2-acts">' + bizSel + '<button class="btn btn-primary" onclick="calNew()">' + I('add', 16) + ' Schedule</button></div></div>' +
        '<div class="cal2-bar"><div class="cal2-seg" role="group" aria-label="View">' + [['agenda', 'Agenda'], ['week', 'Week'], ['month', 'Month']].map(function (v) { return '<button aria-pressed="' + (C.view === v[0]) + '" onclick="calView(\'' + v[0] + '\')">' + v[1] + '</button>'; }).join('') + '</div>' +
        '<div class="cal2-nav"><button class="btn btn-outline btn-sm" aria-label="Earlier" onclick="calStep(-1)">‹</button><b>' + esc(title()) + '</b><button class="btn btn-outline btn-sm" aria-label="Later" onclick="calStep(1)">›</button><button class="btn btn-ghost btn-sm" onclick="calToday()">Today</button></div></div>' +
        (C.view === 'agenda' ? agendaHtml() : C.view === 'week' ? weekHtml() : monthHtml()) + '</div>';
}
async function go() {
    var el = root(); if (!el) return;
    if (!C.items.length) el.innerHTML = (typeof loadingCard === 'function') ? loadingCard(300) : '';
    try { await load(); render(); } catch (e) {
        el.innerHTML = '<div class="cal2-empty"><b>Calendar did not load.</b>' + esc(e.message) + '<div style="margin-top:12px"><button class="btn btn-outline" onclick="calLoad(document.getElementById(\'calendar-root\'))">Try again</button></div></div>';
    }
}

// ── dialogs ──────────────────────────────────────────────────────────────────
function modal(title, inner, onSave, saveLabel, hideSave) {
    var bd = document.createElement('div'); bd.className = 'modal-backdrop';
    bd.innerHTML = '<div class="modal cal2-sheet" role="dialog" aria-modal="true" aria-label="' + esc(title) + '" style="max-width:520px;width:calc(100% - 24px);max-height:90vh;overflow-y:auto">' +
        '<div class="modal-header"><h3>' + esc(title) + '</h3><button class="btn btn-ghost btn-sm" aria-label="Close" data-x>' + I('close', 16) + '</button></div>' +
        '<form style="padding:18px 20px 20px;display:grid;gap:14px">' + inner + '<div data-err role="alert" style="display:none;color:var(--rd);font-size:13px"></div>' +
        (hideSave ? '' : '<div style="display:flex;gap:10px;justify-content:flex-end"><button type="button" class="btn btn-outline" data-x>Cancel</button><button type="submit" class="btn btn-primary">' + esc(saveLabel || 'Save') + '</button></div>') + '</form></div>';
    document.body.appendChild(bd); requestAnimationFrame(function () { bd.classList.add('visible'); });
    var close = function () { bd.remove(); };
    bd.querySelectorAll('[data-x]').forEach(function (x) { x.onclick = close; });
    bd.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    var f = bd.querySelector('form'), err = bd.querySelector('[data-err]');
    f.onsubmit = async function (e) { e.preventDefault(); if (!onSave) return; err.style.display = 'none'; var b = f.querySelector('[type=submit]'); if (b) b.disabled = true;
        try { await onSave(f); close(); } catch (x) { err.textContent = x.message; err.style.display = 'block'; if (b) b.disabled = false; } };
    return bd;
}
function field(id, label, input) { return '<div class="cal2-field"><label for="' + id + '">' + label + '</label>' + input + '</div>'; }

window.calOpen = function (id) {
    var it = C.items.find(function (x) { return String(x.id) === String(id); }); if (!it) return;
    var k = K[it.kind] || K.event, s = parse(it.starts_at), e = parse(it.ends_at), st = ST[it.status] || ST.scheduled;
    var row = function (kk, v) { return v ? '<div class="row"><span class="k">' + kk + '</span><span>' + v + '</span></div>' : ''; };
    var tel = it.client && it.client.phone ? String(it.client.phone).replace(/[^0-9+]/g, '') : '';
    var real = String(it.id).charAt(0) !== 'm';
    var open = it.status === 'scheduled' || it.status === 'confirmed';
    var btns = [];
    if (it.can_decide) { btns.push('<button type="button" class="btn btn-primary" data-a="confirm">Confirm</button>', '<button type="button" class="btn btn-outline" data-a="decline">Decline</button>'); }
    if (it.kind === 'strategy' && real && it.status !== 'done') btns.push('<button type="button" class="btn btn-primary" data-a="join">' + I('ai', 14) + ' Join the meeting</button>');
    if (tel) btns.push('<a class="btn btn-outline" href="tel:' + esc(tel) + '">' + I('phone', 14) + ' Call</a>');
    if (it.client) btns.push('<button type="button" class="btn btn-outline" data-a="client">Open ' + esc(it.client.name.split(' ')[0]) + '</button>');
    if (real && open) btns.push('<button type="button" class="btn btn-outline" data-a="done">' + I('check', 14) + ' Mark done</button>');
    if (real && open && it.client) btns.push('<button type="button" class="btn btn-outline" data-a="no_show">No-show</button>');
    if (real && open) btns.push('<button type="button" class="btn btn-outline" data-a="edit">Reschedule</button>', '<button type="button" class="btn btn-ghost" data-a="cancelled" style="color:var(--rd)">Cancel it</button>');
    if (real && (it.status === 'cancelled' || it.status === 'no_show')) btns.push('<button type="button" class="btn btn-outline" data-a="scheduled">Put it back</button>');
    var bd = modal(it.title,
        '<div><span class="cal2-pill" style="background:var(--s2);color:var(--t2)">' + I(k.i, 12) + ' ' + k.l + '</span> ' + (st[0] ? '<span class="cal2-pill ' + st[1] + '">' + st[0] + '</span>' : '') + '</div>' +
        row('When', esc(s ? dayName(s) + (it.all_day ? ', all day' : ', ' + tm(s) + (e ? ' – ' + tm(e) : '')) : '')) +
        row('With', it.client ? esc(it.client.name) + (it.client.phone ? ' · ' + esc(it.client.phone) : '') : (it.kind === 'strategy' ? 'Sarah and your AI team' : '')) +
        row('Where', esc(it.location || '')) + row('Business', esc(it.business_name || '')) +
        row('Reminder', it.remind_minutes != null && it.remind_minutes !== '' ? (it.remind_minutes >= 60 ? (it.remind_minutes / 60) + ' h before' : it.remind_minutes + ' min before') + ', by Sarah in chat, notification and email' : '') +
        row('Notes', it.notes ? '<span style="white-space:pre-wrap">' + esc(it.notes) + '</span>' : '') +
        (it.source === 'campaigns' ? row('From', 'A campaign step waiting for you') : '') +
        '<div class="btns">' + btns.join('') + '</div>', null, null, true);
    bd.querySelectorAll('[data-a]').forEach(function (b) {
        b.onclick = async function () {
            var a = b.getAttribute('data-a');
            if (a === 'confirm' || a === 'decline') { bd.remove(); return calBookingDecide(it.id, a); }
            if (a === 'join') { bd.remove(); return calJoin(it.id); }
            if (a === 'client') { bd.remove(); try { nav('crm'); setTimeout(function () { if (window._crmOpenDetail) window._crmOpenDetail(it.client.id); }, 400); } catch (e) {} return; }
            if (a === 'edit') { bd.remove(); return calNew(it); }
            if (a === 'done' && (it.client || it.kind === 'strategy' || it.kind === 'meeting' || it.kind === 'call')) { bd.remove(); return calOutcome(it); }
            try { await api('PUT', '/calendar/events/' + it.id + '/status', {status: a}); toast({done: 'Marked done.', no_show: 'Marked as a no-show. It is on their timeline.', cancelled: 'Cancelled.', scheduled: 'Back on your calendar.'}[a] || 'Saved.'); } catch (e) { return toast(e.message, 'error'); }
            bd.remove(); go();
        };
    });
};
window.calOutcome = function (it) {
    modal('How did it go?', field('cal-note', 'A short note' + (it.client ? ' for ' + esc(it.client.name.split(' ')[0]) + '\'s timeline' : ''), '<textarea class="form-input" id="cal-note" name="note" rows="3" placeholder="What was agreed, what happens next"></textarea>'),
        async function (f) { await api('PUT', '/calendar/events/' + it.id + '/status', {status: 'done', note: f.note.value.trim()}); toast('Marked done.' + (it.client ? ' The note is on their timeline.' : '')); go(); }, 'Mark done');
};
window.calJoin = function (id) {
    var it = C.items.find(function (x) { return String(x.id) === String(id); });
    try { window._pendingMeetingTopic = it ? it.title : ''; nav('meeting'); } catch (e) {}
    setTimeout(function () { var t = document.querySelector('#meeting-topic, #meeting-root textarea, #meeting-root input[type=text]'); if (t && it) { t.value = it.title; t.dispatchEvent(new Event('input', {bubbles: true})); } }, 700);
};
window.calNew = function (edit) {
    var kinds = [['call', 'Call'], ['meeting', 'Meeting'], ['appointment', 'Appointment'], ['strategy', 'Strategy meeting'], ['follow_up', 'Follow-up'], ['email', 'Email'], ['personal', 'Personal']];
    var kind = edit ? ({strategy: 'strategy', booking: 'appointment', callback: 'call', todo: 'follow_up'}[edit.kind] || edit.kind) : 'call';
    var s = edit ? parse(edit.starts_at) : new Date(Math.ceil(Date.now() / 1800000) * 1800000 + 3600000);
    var e = edit ? parse(edit.ends_at) : null;
    var dur = e && s ? Math.round((e - s) / 60000) : 30;
    var client = edit && edit.client ? edit.client : null;
    var bizOpts = C.bizList.length > 1 ? field('cal-biz', 'Business', '<select class="form-select" id="cal-biz" name="biz"><option value="">—</option>' + C.bizList.map(function (b) { return '<option value="' + b.id + '"' + (String((edit && edit.business_id) || C.biz) === String(b.id) ? ' selected' : '') + '>' + esc(b.name) + '</option>'; }).join('') + '</select>') : '';
    var inner = (edit ? '' : field('cal-kind', 'What is it', '<div class="cal2-kinds" id="cal-kind" role="group">' + kinds.map(function (x) { return '<button type="button" data-k="' + x[0] + '" aria-pressed="' + (x[0] === kind) + '">' + I((K[x[0]] || K.event).i, 14) + x[1] + '</button>'; }).join('') + '</div>')) +
        field('cal-title', 'Title', '<input class="form-input" id="cal-title" name="title" required maxlength="190" value="' + esc(edit ? edit.title : '') + '" placeholder="Call Maria about her whitening">') +
        field('cal-who', 'With (a client, optional)', '<div class="cal2-client"><input class="form-input" id="cal-who" autocomplete="off" placeholder="Start typing a name" value="' + esc(client ? client.name : '') + '"' + (edit ? ' disabled' : '') + '><ul hidden role="listbox"></ul></div>') +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' + field('cal-day', 'Day', '<input class="form-input" type="date" id="cal-day" name="day" required value="' + ymd(s) + '">') + field('cal-time', 'Time', '<input class="form-input" type="time" id="cal-time" name="time" required value="' + pad(s.getHours()) + ':' + pad(s.getMinutes()) + '">') + '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' + field('cal-dur', 'How long', '<select class="form-select" id="cal-dur" name="dur">' + [[15, '15 min'], [30, '30 min'], [45, '45 min'], [60, '1 hour'], [90, '1½ hours'], [120, '2 hours'], [240, 'Half a day']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === dur ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>') +
        field('cal-rem', 'Remind me', '<select class="form-select" id="cal-rem" name="rem">' + [['0', 'At the time'], ['10', '10 min before'], ['30', '30 min before'], ['60', '1 hour before'], ['1440', '1 day before'], ['none', 'No reminder']].map(function (o) { var cur = edit ? (edit.remind_minutes == null ? '30' : String(edit.remind_minutes)) : '30'; return '<option value="' + o[0] + '"' + (o[0] === cur ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>') + '</div>' +
        field('cal-where', 'Where (address, phone or video link)', '<input class="form-input" id="cal-where" name="where" maxlength="255" value="' + esc(edit ? (edit.location || '') : '') + '">') + bizOpts +
        field('cal-notes', 'Notes', '<textarea class="form-input" id="cal-notes" name="notes" rows="2">' + esc(edit ? (edit.notes || '') : '') + '</textarea>') +
        '<div style="font-size:12px;color:var(--t3)">Sarah reminds you in chat, with a notification and by email. Nothing is sent to the client automatically.</div>';
    var bd = modal(edit ? 'Reschedule' : 'Schedule', inner, async function (f) {
        var start = new Date(f.day.value + 'T' + f.time.value); if (isNaN(start)) throw new Error('Pick a day and time.');
        var end = new Date(start.getTime() + parseInt(f.dur.value, 10) * 60000);
        var rem = f.rem.value;
        if (edit) {
            await api('PUT', '/calendar/events/' + edit.id, {title: f.title.value.trim(), starts_at: dt(start), ends_at: dt(end), location: f.where.value.trim(), description: f.notes.value.trim(), remind_minutes: rem === 'none' ? null : parseInt(rem, 10), no_reminder: rem === 'none'});
            toast('Rescheduled. Sarah will remind you again.');
        } else {
            await api('POST', '/calendar/schedule', {kind: kind, title: f.title.value.trim(), starts_at: dt(start), ends_at: dt(end), lead_id: client ? client.id : undefined, location: f.where.value.trim(), notes: f.notes.value.trim(), remind_minutes: rem === 'none' ? 'none' : parseInt(rem, 10), business_id: f.biz ? f.biz.value : (C.biz && C.biz !== 'none' ? C.biz : '')});
            toast('On your calendar. Sarah will remind you.');
        }
        go();
    }, edit ? 'Save' : 'Schedule');
    bd.querySelectorAll('[data-k]').forEach(function (b) { b.onclick = function () { kind = b.getAttribute('data-k'); bd.querySelectorAll('[data-k]').forEach(function (x) { x.setAttribute('aria-pressed', x === b); });
        var t = bd.querySelector('#cal-title'); if (kind === 'strategy' && !t.value) t.value = 'Strategy meeting with Sarah and the team'; }; });
    // client picker: search Clients as you type
    var who = bd.querySelector('#cal-who'), ul = bd.querySelector('.cal2-client ul'), timer = null;
    if (who && !edit) who.addEventListener('input', function () {
        client = null; clearTimeout(timer); var q = who.value.trim(); if (q.length < 2) { ul.hidden = true; return; }
        timer = setTimeout(async function () {
            try {
                var j = await api('GET', '/crm/clients?limit=8&search=' + encodeURIComponent(q) + (C.biz && C.biz !== 'none' ? '&business_id=' + C.biz : ''));
                ul.innerHTML = (j.clients || []).map(function (c) { return '<li role="option" data-id="' + c.id + '" data-n="' + esc(c.name) + '" data-b="' + (c.business_id || '') + '">' + esc(c.name) + '<small>' + esc(c.phone || c.email || '') + (c.business_name ? ' · ' + esc(c.business_name) : '') + '</small></li>'; }).join('') || '<li style="cursor:default;color:var(--t3)">No client by that name</li>';
                ul.hidden = false;
                ul.querySelectorAll('li[data-id]').forEach(function (li) { li.onclick = function () { client = {id: +li.getAttribute('data-id'), name: li.getAttribute('data-n')}; who.value = client.name; ul.hidden = true;
                    var bs = bd.querySelector('#cal-biz'); if (bs && li.getAttribute('data-b')) bs.value = li.getAttribute('data-b');
                    var t = bd.querySelector('#cal-title'); if (!t.value) t.value = ((kinds.find(function (x) { return x[0] === kind; }) || [0, 'Call'])[1]) + ' with ' + client.name; }; });
            } catch (e) { ul.hidden = true; }
        }, 220);
    });
};

// ── actions ─────────────────────────────────────────────────────────────────
window.calView = function (v) { C.view = v; go(); };
window.calStep = function (n) { var a = C.anchor ? new Date(C.anchor) : new Date(); if (C.view === 'month') a.setMonth(a.getMonth() + n); else a.setDate(a.getDate() + n * 7); C.anchor = a.getTime(); go(); };
window.calToday = function () { C.anchor = null; go(); };
window.calGoDay = function (d) { C.anchor = new Date(d + 'T00:00:00').getTime(); C.view = 'agenda'; go(); };
window.calSetBiz = function (v) { try { v ? localStorage.setItem('lu_crm_biz', v) : localStorage.removeItem('lu_crm_biz'); } catch (e) {} go(); };
window.calBookingDecide = async function (id, decision) {
    var ok = typeof luConfirm === 'function' ? await luConfirm(decision === 'confirm' ? 'Confirm this booking? The client moves to booked in Clients. Nothing is emailed to them automatically.' : 'Decline this request? Nothing is emailed to them automatically.', decision === 'confirm' ? 'Confirm booking' : 'Decline request', decision === 'confirm' ? 'Confirm' : 'Decline', 'Back') : true;
    if (!ok) return;
    try { await api('POST', '/calendar/events/' + id + '/decision', {decision: decision}); toast(decision === 'confirm' ? 'Booking confirmed.' : 'Request declined.'); } catch (e) { return toast(e.message, 'error'); }
    go();
};
window.calLoad = function (el) { if (!el) return; C.items = []; go(); };
window.calendarLoad = window.calLoad;
// older entry names other screens may still call
window.calNewEvent = function () { window.calNew(); };
window.calEditEventById = function (id) { var it = C.items.find(function (x) { return String(x.id) === String(id); }); if (it) window.calNew(it); };
console.log('[Calendar] CAL-2 loaded');
})();
