/**
 * LevelUpGrowth — Clients (CRM-UX-2, Clients revamp Phase 2, 2026-09-29)
 *
 * One engine, set up per BUSINESS by its industry pack (words, stages, details, ready lists).
 *   Today     what needs the owner now: enquiries waiting for a reply, tasks due, bookings, clients gone quiet
 *   Clients   the list: ready views, search, filters, bulk actions, export
 *   Board     the business's stages as columns (drag on desktop, "Move" sheet everywhere)
 *   Record    who they are + actions / stage bar, summary, timeline / tasks, bookings, their other businesses
 *   Reports   where enquiries come from, how fast they are answered, where they go
 *   Settings  the setup per business (pack and words)
 * Entry points kept for the shell router: window.crmLoad(el), window._crmOpenDetail(id).
 */
(function () {
'use strict';
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['crm'] = true;

// ── icons the shell set does not carry ───────────────────────────────────────
try {
    var P = window.icon && window.icon._paths;
    if (P) {
        if (!P.phone) P.phone = '<path d="M5 3h3l1.5 4-2 1.2a9 9 0 004.3 4.3L13 10.5l4 1.5v3a2 2 0 01-2 2A13 13 0 013 5a2 2 0 012-2z"/>';
        if (!P.mail) P.mail = '<rect x="2.5" y="4.5" width="15" height="11" rx="2"/><path d="M3 6l7 5 7-5"/>';
        if (!P.users) P.users = '<circle cx="8" cy="7" r="3"/><path d="M2.5 17a5.5 5.5 0 0111 0"/><path d="M13 4.2a3 3 0 010 5.6M15.5 17a5 5 0 00-2.5-4.3"/>';
        if (!P.whatsapp) P.whatsapp = '<path d="M4 16l1-3.2A7 7 0 1110 17a7 7 0 01-3.3-.8L4 16z"/><path d="M7.5 7.5c.3 2 2 3.8 4 4.2l1-1-1.5-.8-.6.6c-.8-.4-1.4-1-1.8-1.8l.6-.6-.8-1.5-.9.9z"/>';
        if (!P.sms) P.sms = '<path d="M3 5a2 2 0 012-2h10a2 2 0 012 2v7a2 2 0 01-2 2H8l-4 3v-3.5A2 2 0 013 12V5z"/>';
        if (!P.board) P.board = '<rect x="2.5" y="3" width="4" height="14" rx="1"/><rect x="8" y="3" width="4" height="9" rx="1"/><rect x="13.5" y="3" width="4" height="11" rx="1"/>';
        if (!P.sun) P.sun = '<circle cx="10" cy="10" r="3.5"/><path d="M10 2v2M10 16v2M2 10h2M16 10h2M4.3 4.3l1.4 1.4M14.3 14.3l1.4 1.4M4.3 15.7l1.4-1.4M14.3 5.7l1.4-1.4"/>';
        if (!P.settings) P.settings = '<circle cx="10" cy="10" r="2.5"/><path d="M10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4"/>';
    }
} catch (e) {}
function I(n, s) { return window.icon ? window.icon(n, s || 16) : ''; }

// ── state ───────────────────────────────────────────────────────────────────
var S = {
    tab: 'today', biz: '', setup: null, pack: null,
    list: {view: '', stage: '', channel: '', search: '', sort: 'created_at', dir: 'desc', rows: [], total: 0, counts: {}, sel: {}},
    board: {rows: [], total: 0}, rec: null, recId: null, today: null, report: null, days: 30, composer: 'note'
};
var BIZ_KEY = 'lu_crm_biz';
try { S.biz = localStorage.getItem(BIZ_KEY) || ''; } catch (e) {}

// ── helpers ─────────────────────────────────────────────────────────────────
function esc(s) { return (s == null ? '' : String(s)).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function hdr() { return {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}; }
async function api(method, path, body) {
    var res = await fetch('/api/crm' + path, {method: method, headers: hdr(), body: body ? JSON.stringify(body) : undefined});
    var j = {}; try { j = await res.json(); } catch (e) {}
    if (!res.ok || (j && j.success === false)) throw new Error((j && (j.message || j.error)) || ('HTTP ' + res.status));
    return j;
}
function toast(m, t) { try { showToast(m, t || 'success'); } catch (e) {} }
function qs(o) { var p = []; Object.keys(o).forEach(function (k) { if (o[k] !== '' && o[k] != null) p.push(encodeURIComponent(k) + '=' + encodeURIComponent(o[k])); }); return p.length ? '?' + p.join('&') : ''; }
function bizQ() { return S.biz ? {business_id: S.biz} : {}; }
function ago(dt) {
    if (!dt) return '';
    var t = new Date(String(dt).replace(' ', 'T')); if (isNaN(t)) return '';
    var s = (Date.now() - t.getTime()) / 1000;
    if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago'; if (s < 86400) return Math.floor(s / 3600) + ' h ago';
    var d = Math.floor(s / 86400); if (d < 30) return d + (d === 1 ? ' day ago' : ' days ago');
    return t.toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
}
function when(dt) {
    if (!dt) return ''; var t = new Date(String(dt).replace(' ', 'T')); if (isNaN(t)) return String(dt);
    var today = new Date(); var tm = new Date(today.getTime() + 86400000);
    var same = function (a, b) { return a.toDateString() === b.toDateString(); };
    var hm = t.getHours() || t.getMinutes() ? ' ' + t.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'}) : '';
    if (same(t, today)) return 'Today' + hm; if (same(t, tm)) return 'Tomorrow' + hm;
    return t.toLocaleDateString('en-US', {weekday: 'short', month: 'short', day: 'numeric'}) + hm;
}
function money(v) { v = parseFloat(v) || 0; return v ? '$' + v.toLocaleString('en-US', {maximumFractionDigits: 0}) : ''; }
var CH = {website: 'Website', booking: 'Booking', chatbot: 'Chatbot', facebook: 'Facebook', instagram: 'Instagram', messenger: 'Messenger', store: 'Store order', import: 'Import', manual: 'Added by you', other: 'Other'};
function chName(c) { return CH[c] || (c ? String(c).charAt(0).toUpperCase() + String(c).slice(1) : '—'); }
function initials(n) { return (n || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase(); }
function words() { var p = S.pack || {}; return {one: p.one || 'Client', many: p.many || 'Clients'}; }
function multi() { return S.setup && S.setup.businesses && S.setup.businesses.length > 1; }
function bizById(id) { return (S.setup && S.setup.businesses || []).find(function (b) { return String(b.id) === String(id); }); }
function stageTone(pack, key) {
    var st = (pack && pack.stages || []).find(function (s) { return s.key === key; }); var s = st ? st.status : 'new';
    return {new: 'crm2-t-new', contacted: 'crm2-t-contacted', qualified: 'crm2-t-qualified', converted: 'crm2-t-won', lost: 'crm2-t-lost'}[s] || 'crm2-t-new';
}
function toneS(st) { return {new: 'crm2-t-new', contacted: 'crm2-t-contacted', qualified: 'crm2-t-qualified', converted: 'crm2-t-won', lost: 'crm2-t-lost'}[st] || 'crm2-t-new'; }
function packOf(row) { if (row && row.business_id) { var b = bizById(row.business_id); if (b && S.setup.packs_full && S.setup.packs_full[b.id]) return S.setup.packs_full[b.id]; } return S.pack; }
function phoneDigits(p) { return String(p || '').replace(/[^0-9+]/g, ''); }

// ── styles (theme variables only; light and dark follow the shell) ──────────
(function css() {
    if (document.getElementById('crm2-css')) return;
    var st = document.createElement('style'); st.id = 'crm2-css';
    st.textContent = [
        '#crm-root .crm2{max-width:1440px;margin:0 auto;font-family:var(--fb)}',
        '.crm2-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:6px 0 18px}',
        '.crm2-title{font-family:var(--fh);font-size:28px;font-weight:600;letter-spacing:-.01em;color:var(--t1);margin:0;line-height:1.15}',
        '.crm2-sub{font-size:13px;color:var(--t3);margin-top:4px}',
        '.crm2-head-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}',
        '.crm2-biz{min-width:220px;max-width:320px}',
        '.crm2-tabs{display:flex;gap:4px;border-bottom:1px solid var(--bd);margin-bottom:20px;overflow-x:auto;scrollbar-width:none}',
        '.crm2-tabs::-webkit-scrollbar{display:none}',
        '.crm2-tab{flex:0 0 auto;appearance:none;background:none;border:0;border-bottom:2px solid transparent;color:var(--t2);font:500 14px var(--fb);padding:12px 14px;display:flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap;min-height:44px}',
        '.crm2-tab:hover{color:var(--t1)}.crm2-tab[aria-selected=true]{color:var(--t1);border-bottom-color:var(--p)}',
        '.crm2-tab .n{font-size:11px;font-weight:700;background:var(--ps);color:var(--pu);border-radius:999px;padding:1px 7px}',
        '.crm2-card{background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);}',
        '.crm2-card-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 18px 10px}',
        '.crm2-card-h h3{margin:0;font:500 15px var(--fb);color:var(--t1)}.crm2-card-h .more{font-size:12px;color:var(--t3)}',
        '.crm2-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}',
        '.crm2-kpi{padding:16px 18px}.crm2-kpi .l{font-size:12px;color:var(--t3);font-weight:600}.crm2-kpi .v{font:600 28px var(--fh);color:var(--t1);margin-top:6px}.crm2-kpi .h{font-size:12px;color:var(--t3);margin-top:2px}',
        '.crm2-grid2{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:16px;align-items:start}',
        '.crm2-rows{list-style:none;margin:0;padding:0 8px 8px}',
        '.crm2-row{display:flex;align-items:center;gap:12px;padding:10px;border-radius:10px;cursor:pointer}',
        '.crm2-row:hover{background:var(--s2)}.crm2-row+.crm2-row{border-top:1px solid var(--bd)}',
        '.crm2-av{width:36px;height:36px;border-radius:50%;background:var(--ps);color:var(--pu);display:flex;align-items:center;justify-content:center;font:600 13px var(--fb);flex-shrink:0}',
        '.crm2-row .main{flex:1;min-width:0}.crm2-row .nm{font-weight:500;color:var(--t1);font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
        '.crm2-row .meta{font-size:12px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}',
        '.crm2-row .side{font-size:12px;color:var(--t3);white-space:nowrap}',
        '.crm2-empty{padding:28px 18px 30px;text-align:center;color:var(--t3);font-size:13px}',
        '.crm2-empty b{display:block;color:var(--t1);font-size:14px;margin-bottom:4px}',
        '.crm2-pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;white-space:nowrap;border:1px solid transparent}',
        '.crm2-t-new{background:var(--ps);color:var(--pu)}.crm2-t-contacted{background:rgba(111,168,255,.14);color:var(--bl)}',
        '.crm2-t-qualified{background:rgba(245,158,11,.14);color:var(--am)}.crm2-t-won{background:var(--as);color:var(--ac)}.crm2-t-lost{background:var(--s2);color:var(--t3)}',
        '.crm2-chip{flex:0 0 auto;display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--t2);background:var(--s2);border:1px solid var(--bd);border-radius:999px;padding:5px 12px;cursor:pointer;white-space:nowrap;min-height:34px}',
        '.crm2-chip[aria-pressed=true]{background:var(--ps);color:var(--t1);border-color:var(--pg)}.crm2-chip .n{font-weight:700;color:var(--t3)}',
        '.crm2-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}',
        '.crm2-views{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;margin-bottom:12px;scrollbar-width:none}.crm2-views::-webkit-scrollbar{display:none}',
        '.crm2-search{position:relative;flex:1 1 240px;max-width:360px}.crm2-search input{padding-left:36px;width:100%}.crm2-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--t3)}',
        '.crm2-table{width:100%;border-collapse:collapse;font-size:13px}',
        '.crm2-table th{text-align:left;font-size:12px;font-weight:600;color:var(--t3);padding:12px 14px;border-bottom:1px solid var(--bd);white-space:nowrap}',
        '.crm2-table td{padding:12px 14px;border-bottom:1px solid var(--bd);color:var(--t2);vertical-align:middle}',
        '.crm2-table tr.r{cursor:pointer}.crm2-table tr.r:hover td{background:var(--s2)}.crm2-table tr.r.sel td{background:var(--ps)}',
        '.crm2-table .who{display:flex;align-items:center;gap:12px;min-width:0}.crm2-table .who .nm{color:var(--t1);font-weight:500}',
        '.crm2-table .who .sub{font-size:12px;color:var(--t3);max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
        '.crm2-cb{width:18px;height:18px;accent-color:var(--p);cursor:pointer}',
        '.crm2-bulk{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--s3);border:1px solid var(--bd2);border-radius:12px;padding:10px 14px;margin-bottom:12px}',
        '.crm2-more{text-align:center;padding:16px}',
        '.crm2-cards{display:none}',
        '.crm2-board{display:flex;gap:12px;overflow-x:auto;padding-bottom:12px;align-items:flex-start}',
        '.crm2-col{flex:0 0 272px;background:var(--s2);border:1px solid var(--bd);border-radius:var(--rg);display:flex;flex-direction:column;max-height:calc(100vh - 260px)}',
        '.crm2-col-h{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid var(--bd)}',
        '.crm2-col-h b{font-size:13px;color:var(--t1)}.crm2-col-h span{font-size:12px;color:var(--t3)}',
        '.crm2-col-b{padding:10px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;min-height:80px}',
        '.crm2-col-b.over{background:var(--ps);border-radius:0 0 var(--rg) var(--rg)}',
        '.crm2-bc{background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:12px;cursor:grab}',
        '.crm2-bc:hover{border-color:var(--bd2)}.crm2-bc .nm{font-weight:500;color:var(--t1);font-size:14px}.crm2-bc .meta{font-size:12px;color:var(--t3);margin-top:4px}',
        '.crm2-bc .foot{display:flex;align-items:center;justify-content:space-between;margin-top:10px;gap:8px}',
        '.crm2-rec{display:grid;grid-template-columns:300px minmax(0,1fr) 300px;gap:16px;align-items:start}',
        '.crm2-rec-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}',
        '.crm2-rec-name{display:flex;align-items:center;gap:14px}.crm2-rec-name .crm2-av{width:52px;height:52px;font-size:17px}',
        '.crm2-rec-name h2{margin:0;font:600 24px var(--fh);color:var(--t1)}.crm2-rec-name .sub{font-size:13px;color:var(--t3);margin-top:4px}',
        '.crm2-acts{display:flex;gap:8px;flex-wrap:wrap}',
        '.crm2-act{display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:0 14px;border-radius:10px;border:1px solid var(--bd2);background:var(--s1);color:var(--t1);font:600 13px var(--fb);text-decoration:none;cursor:pointer}',
        '.crm2-act:hover{background:var(--s2)}.crm2-act[aria-disabled=true]{opacity:.45;pointer-events:none}',
        '.crm2-path{display:flex;gap:4px;overflow-x:auto;margin-bottom:16px;scrollbar-width:none}.crm2-path::-webkit-scrollbar{display:none}',
        '.crm2-step{flex:1 0 auto;min-width:108px;appearance:none;border:0;background:var(--s2);color:var(--t2);font:600 12px var(--fb);padding:12px 14px;cursor:pointer;clip-path:polygon(0 0,calc(100% - 10px) 0,100% 50%,calc(100% - 10px) 100%,0 100%,10px 50%);min-height:44px}',
        '.crm2-step:first-child{clip-path:polygon(0 0,calc(100% - 10px) 0,100% 50%,calc(100% - 10px) 100%,0 100%);border-radius:10px 0 0 10px}',
        '.crm2-step.done{background:var(--ps);color:var(--t1)}.crm2-step.cur{background:var(--p);color:#fff}.crm2-step:hover:not(.cur){background:var(--s3)}',
        '.crm2-sec{padding:16px 18px}.crm2-sec+.crm2-sec{border-top:1px solid var(--bd)}.crm2-sec h4{margin:0 0 10px;font:600 12px var(--fb);letter-spacing:.04em;text-transform:uppercase;color:var(--t3)}',
        '.crm2-kv{display:grid;grid-template-columns:96px minmax(0,1fr);gap:8px 12px;font-size:13px}.crm2-kv dt{color:var(--t3)}.crm2-kv dd{margin:0;color:var(--t1);word-break:break-word}',
        '.crm2-kv a{color:var(--ac);text-decoration:none}',
        '.crm2-field{margin-bottom:12px}.crm2-field label{display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px}',
        '.crm2-summary{padding:16px 18px;border-left:3px solid var(--p);background:var(--s1);border-radius:var(--rg);border-top:1px solid var(--bd);border-right:1px solid var(--bd);border-bottom:1px solid var(--bd);margin-bottom:14px}',
        '.crm2-summary p{margin:0;color:var(--t1);font-size:14px;line-height:1.55}.crm2-summary q{display:block;margin-top:10px;color:var(--t2);font-style:italic;font-size:13px}',
        '.crm2-comp{margin-bottom:14px}.crm2-comp-tabs{display:flex;gap:4px;padding:10px 12px 0}',
        '.crm2-comp-tabs button{appearance:none;border:0;background:none;color:var(--t3);font:600 13px var(--fb);padding:8px 12px;border-radius:8px;cursor:pointer;min-height:40px}',
        '.crm2-comp-tabs button[aria-pressed=true]{background:var(--s2);color:var(--t1)}',
        '.crm2-comp-b{padding:10px 12px 12px}.crm2-comp-b textarea{width:100%;min-height:76px;resize:vertical;box-sizing:border-box}',
        '.crm2-comp-f{display:flex;gap:8px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-top:8px}',
        '.crm2-tl{list-style:none;margin:0;padding:6px 18px 12px}.crm2-tl li{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--bd)}.crm2-tl li:last-child{border-bottom:0}',
        '.crm2-tl .dot{width:30px;height:30px;border-radius:50%;background:var(--s2);color:var(--t2);display:flex;align-items:center;justify-content:center;flex-shrink:0}',
        '.crm2-tl .dot.h{background:var(--ps);color:var(--pu)}.crm2-tl .b{flex:1;min-width:0}.crm2-tl .t{font-size:13px;color:var(--t1);font-weight:500}',
        '.crm2-tl .d{font-size:13px;color:var(--t2);margin-top:3px;white-space:pre-wrap;word-break:break-word}.crm2-tl .w{font-size:12px;color:var(--t3);white-space:nowrap}',
        '.crm2-tl .x{appearance:none;border:0;background:none;color:var(--t3);cursor:pointer;padding:4px;border-radius:6px;min-width:32px;min-height:32px}.crm2-tl .x:hover{color:var(--rd);background:var(--s2)}',
        '.crm2-task{display:flex;align-items:flex-start;gap:10px;padding:8px 0}.crm2-task+.crm2-task{border-top:1px solid var(--bd)}.crm2-task .t{font-size:13px;color:var(--t1)}.crm2-task .w{font-size:12px;color:var(--t3)}.crm2-task .w.late{color:var(--rd)}',
        '.crm2-bars{padding:6px 18px 16px}.crm2-bar{display:grid;grid-template-columns:130px minmax(0,1fr) 44px;gap:12px;align-items:center;padding:6px 0;font-size:13px}',
        '.crm2-bar .l{color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crm2-bar .tr{height:10px;background:var(--s2);border-radius:999px;overflow:hidden}',
        '.crm2-bar .f{height:100%;background:var(--p);border-radius:999px}.crm2-bar .n{text-align:right;color:var(--t1);font-weight:500}',
        '.crm2-packs{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}',
        '.crm2-pack{text-align:left;appearance:none;background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:14px;cursor:pointer;color:var(--t2);font:13px var(--fb);min-height:44px}',
        '.crm2-pack b{display:block;color:var(--t1);font-size:14px;margin-bottom:4px}.crm2-pack[aria-pressed=true]{border-color:var(--p);box-shadow:0 0 0 1px var(--p)}',
        '.crm2-sheet-list{display:flex;flex-direction:column;gap:6px}.crm2-sheet-list button{justify-content:flex-start}',
        '.crm2-note{font-size:12px;color:var(--t3)}',
        '.crm2-hide-d{display:none}',
        '.crm2-sarah{padding:16px 18px;border-radius:var(--rg);border:1px solid var(--pg);background:linear-gradient(0deg,var(--ps),var(--ps)),var(--s1);margin-bottom:14px}',
        '.crm2-sarah .h{display:flex;align-items:center;gap:8px;font:600 12px var(--fb);color:var(--pu);letter-spacing:.04em;text-transform:uppercase;margin-bottom:8px}',
        '.crm2-sarah p{margin:0;color:var(--t1);font-size:14px;line-height:1.55}.crm2-sarah .nx{margin-top:10px;padding-top:10px;border-top:1px solid var(--pg);font-size:14px;color:var(--t1)}',
        '.crm2-sarah .nx b{color:var(--pu)}.crm2-sarah .q{margin-top:10px;color:var(--t2);font-style:italic;font-size:13px}',
        '.crm2-draft{border:1px solid var(--bd2);border-radius:12px;padding:14px;margin-bottom:10px;background:var(--s1)}',
        '.crm2-draft .top{display:flex;justify-content:space-between;gap:8px;align-items:baseline;flex-wrap:wrap;margin-bottom:8px}',
        '.crm2-draft .top b{color:var(--t1);font-size:14px}.crm2-draft .top span{font-size:12px;color:var(--t3)}',
        '.crm2-draft textarea{width:100%;min-height:120px;box-sizing:border-box;resize:vertical;font-size:14px;line-height:1.5}',
        '.crm2-draft .row{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:10px}',
        '.crm2-radio{display:flex;flex-direction:column;gap:8px}.crm2-radio label{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--bd);border-radius:12px;cursor:pointer;background:var(--s1)}',
        '.crm2-radio input{accent-color:var(--p);margin-top:3px;width:18px;height:18px}.crm2-radio label b{display:block;color:var(--t1);font-size:14px}.crm2-radio label span{font-size:13px;color:var(--t3)}',
        '.crm2-radio label:has(input:checked){border-color:var(--p);background:var(--ps)}',
        '.crm2-chart{padding:8px 18px 16px}.crm2-chart svg{width:100%;height:auto;display:block}',
        '.crm2-legend{display:flex;gap:14px;font-size:12px;color:var(--t3);padding:0 18px 12px}.crm2-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}',
        '.crm2-rt{width:100%;border-collapse:collapse;font-size:13px}.crm2-rt th{text-align:left;color:var(--t3);font-weight:600;font-size:12px;padding:8px 18px;border-bottom:1px solid var(--bd)}.crm2-rt td{padding:10px 18px;border-bottom:1px solid var(--bd);color:var(--t1)}',
        '.crm2-rt td.n,.crm2-rt th.n{text-align:right;font-variant-numeric:tabular-nums}',
        '.crm2-stack{display:flex;height:14px;border-radius:999px;overflow:hidden;background:var(--s2);margin:8px 18px}.crm2-stack span{height:100%}',
        '.crm2-it{display:flex;gap:10px;align-items:center;padding:8px 0}.crm2-it+.crm2-it{border-top:1px solid var(--bd)}',
        '.crm2-it img{width:56px;height:42px;object-fit:cover;border-radius:6px;flex-shrink:0;background:var(--s2)}.crm2-it .t{font-size:13px;color:var(--t1);font-weight:500;line-height:1.3}',
        '.crm2-it .w{font-size:12px;color:var(--t3)}.crm2-it .why{font-size:11px;color:var(--ac)}.crm2-it input{accent-color:var(--p);width:18px;height:18px;flex-shrink:0}',
        '.crm2-pay{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;padding:10px 0}.crm2-pay+.crm2-pay{border-top:1px solid var(--bd)}',
        '.crm2-pay .t{font-size:13px;color:var(--t1);font-weight:500}.crm2-pay .w{font-size:12px;color:var(--t3);margin-top:2px}.crm2-pay .a{display:flex;gap:4px;flex-wrap:wrap;margin-top:6px}',
        '.crm2-lines{display:grid;gap:8px}.crm2-line{display:grid;grid-template-columns:minmax(0,1fr) 64px 104px 36px;gap:6px;align-items:center}',
        '.crm2-line input{min-width:0}.crm2-tot{display:flex;justify-content:space-between;font:600 15px var(--fb);color:var(--t1);padding-top:6px;border-top:1px solid var(--bd)}',
        '@media (max-width:760px){.crm2-line{grid-template-columns:minmax(0,1fr) 56px 88px 36px}}',
        '@media (max-width:1180px){.crm2-rec{grid-template-columns:280px minmax(0,1fr)}.crm2-rec .rc{grid-column:1 / -1;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}}',
        '@media (max-width:900px){.crm2-grid2{grid-template-columns:minmax(0,1fr)}.crm2-grid2>*{min-width:0}.crm2-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}',
        '@media (max-width:760px){',
        '  .crm2-title{font-size:24px}.crm2-head{margin-top:0}.crm2-head-actions{width:100%}.crm2-biz{flex:1 1 100%;max-width:none;min-width:0}',
        '  .crm2-rec{grid-template-columns:1fr}.crm2-rec .lc{order:2}.crm2-rec .mc{order:1}.crm2-rec .rc{order:3;display:flex;flex-direction:column}',
        '  .crm2-tablewrap{display:none}.crm2-cards{display:flex;flex-direction:column;gap:10px}',
        '  .crm2-mc{background:var(--s1);border:1px solid var(--bd);border-radius:14px;padding:14px;display:flex;gap:12px;align-items:flex-start}',
        '  .crm2-mc .main{flex:1;min-width:0}.crm2-mc .nm{font-weight:500;color:var(--t1);font-size:15px}.crm2-mc .meta{font-size:13px;color:var(--t3);margin-top:4px}',
        '  .crm2-mc .row2{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px}',
        '  .crm2-col{flex:0 0 calc(100vw - 56px);max-height:none}.crm2-board{scroll-snap-type:x mandatory}.crm2-col{scroll-snap-align:start}',
        '  .crm2-acts{width:100%;display:grid;grid-template-columns:repeat(4,1fr)}.crm2-act{justify-content:center;min-height:48px;padding:0 6px;flex-direction:column;gap:4px;font-size:12px}',
        '  #crm-root .btn,#crm-root .crm2-chip,#crm-root .form-select,#crm-root .form-input{min-height:44px}',
        '  #crm-root .form-input,#crm-root .form-select,#crm-root textarea{font-size:16px}',
        '  .crm2-search{max-width:none;flex-basis:100%}',
        '  .crm2-toolbar .form-select{flex:1 1 calc(50% - 5px);max-width:none!important;min-width:0}.crm2-toolbar>span{display:none}.crm2-toolbar .btn{flex:1 1 calc(50% - 5px);justify-content:center}',
        '  .crm2-comp-tabs button{min-height:44px}.crm2-tl .x{min-width:44px;min-height:44px}',
        '  .crm2-bar{grid-template-columns:96px minmax(0,1fr) 36px}',
        '}'
    ].join('\n');
    document.head.appendChild(st);
})();

// ── data loads ──────────────────────────────────────────────────────────────
async function loadSetup() {
    var q = bizQ();
    var j = await api('GET', '/setup' + qs(q)).catch(function (e) {
        if (S.biz) { S.biz = ''; try { localStorage.removeItem(BIZ_KEY); } catch (x) {} return api('GET', '/setup'); } throw e;
    });
    S.setup = j; S.pack = j.pack;
    if (S.biz && !bizById(S.biz)) { S.biz = ''; }
    if (!S.biz && j.businesses && j.businesses.length === 1) { S.biz = String(j.businesses[0].id); var k = await api('GET', '/setup' + qs(bizQ())); S.setup = k; S.pack = k.pack; }
}
async function loadList(append) {
    var L = S.list;
    var p = Object.assign({limit: 50, offset: append ? L.rows.length : 0, view: L.view, stage: L.stage, channel: L.channel, search: L.search, sort: L.sort, dir: L.dir}, bizQ());
    var j = await api('GET', '/clients' + qs(p));
    L.rows = append ? L.rows.concat(j.clients) : j.clients; L.total = j.total; L.counts = j.view_counts || {};
    if (!append) L.sel = {};
}
async function loadBoard() { var j = await api('GET', '/clients' + qs(Object.assign({limit: 500}, bizQ()))); S.board.rows = j.clients; S.board.total = j.total; }
async function loadToday() { var r = await Promise.all([api('GET', '/today-v2' + qs(bizQ())), api('GET', '/drafts' + qs(bizQ())).catch(function () { return {drafts: []}; })]); S.today = r[0]; S.drafts = r[1].drafts || []; }
async function loadReport() { S.report = await api('GET', '/reports-v2' + qs(Object.assign({days: S.days}, bizQ()))); }
async function loadRecord(id) { S.rec = await api('GET', '/clients/' + id); S.recId = id; refreshSummary(id); refreshCatalogue(id); }
function refreshCatalogue(id) {
    api('GET', '/clients/' + id + '/catalogue').then(function (j) {
        if (!S.rec || S.recId != id) return; S.rec.cat = j; S.catSel = {};
        var box = document.getElementById('crm2-cat'); if (box) box.outerHTML = catCard(S.rec);
    }).catch(function () { var box = document.getElementById('crm2-cat'); if (box) box.remove(); });
}
function catRow(it, pick, extra) {
    return '<div class="crm2-it">' + (pick ? '<input type="checkbox" aria-label="Pick ' + esc(it.title) + '" onchange="window._crm2.catPick(' + it.id + ',this.checked)"' + ((S.catSel || {})[it.id] ? ' checked' : '') + '>' : '') +
        (it.photo ? '<img src="' + esc(it.photo) + '" alt="" loading="lazy">' : '') + '<div style="min-width:0;flex:1"><a class="t" href="' + esc(it.url) + '" target="_blank" rel="noopener" style="text-decoration:none">' + esc(it.title) + '</a>' +
        '<div class="w">' + esc([it.price, it.beds ? it.beds + ' bed' : '', it.location].filter(Boolean).join(' · ')) + '</div>' + (extra || '') + '</div></div>';
}
function catCard(R) {
    var j = R.cat; if (!j) return '<section class="crm2-card" id="crm2-cat"><div class="crm2-sec"><h4>Interested in</h4><div class="crm2-note">Loading…</div></div></section>';
    if (!j.items.length && !j.is_property) return '<div id="crm2-cat"></div>';
    var first = R.client.name.split(' ')[0];
    var ints = j.interests.map(function (it) { return catRow(it, true); }).join('');
    var m = j.is_property ? (j.matches.length ? j.matches.map(function (it) { return catRow(it, true, it.why && it.why.length ? '<div class="why">' + esc(it.why.join(' · ')) + '</div>' : ''); }).join('')
        : '<div class="crm2-note">Add their budget, bedrooms or areas in Details to see matching listings.</div>') : '';
    var any = Object.keys(S.catSel || {}).length;
    return '<section class="crm2-card" id="crm2-cat"><div class="crm2-sec"><h4>' + (j.is_property ? 'Listings for ' + esc(first) : 'Interested in') + '</h4>' +
        (j.is_property ? '<div class="crm2-note" style="margin-bottom:4px">Best matches</div>' + m + (ints ? '<div class="crm2-note" style="margin:10px 0 4px">Saved</div>' + ints : '') : (ints || '<div class="crm2-note">Nothing picked yet.</div>')) +
        '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">' +
        '<button class="btn btn-outline btn-sm" onclick="window._crm2.catAdd()">' + I('add', 14) + ' ' + esc(j.label) + '</button>' +
        '<button class="btn btn-primary btn-sm" id="crm2-cat-send"' + (any ? '' : ' disabled') + ' onclick="window._crm2.catSend()">' + I('send', 14) + ' Send picked</button>' +
        (j.is_property ? '<button class="btn btn-outline btn-sm" id="crm2-cat-view"' + (any === 1 ? '' : ' disabled') + ' onclick="window._crm2.catViewing()">' + I('calendar', 14) + ' Book a viewing</button>' : '') +
        '</div></div></section>';
}
function refreshSummary(id) {
    api('GET', '/clients/' + id + '/summary').then(function (j) {
        if (!S.rec || S.recId != id) return;
        var changed = !S.rec.sarah || S.rec.sarah.summary !== j.summary || S.rec.sarah.next_step !== j.next_step;
        S.rec.sarah = {summary: j.summary, next_step: j.next_step};
        var box = document.getElementById('crm2-sarah'); if (box && changed) box.outerHTML = sarahBox(S.rec);
    }).catch(function () { var box = document.getElementById('crm2-sarah'); if (box && !S.rec.sarah) box.querySelector('p').textContent = factsLine(S.rec); });
}
function factsLine(R) {
    var c = R.client, facts = R.facts || {};
    var s = 'Came in through ' + chName(c.channel).toLowerCase() + ' ' + ago(c.created_at) + '. ';
    s += facts.human_touches ? 'Last contact ' + ago(c.last_activity_at) + '.' : 'Nobody has replied yet.';
    return s;
}
function sarahBox(R) {
    var c = R.client, sm = R.sarah;
    return '<section class="crm2-sarah" id="crm2-sarah" aria-live="polite"><div class="h">' + I('ai', 14) + ' Sarah\'s summary</div>' +
        '<p>' + esc(sm ? sm.summary : 'Reading ' + c.name.split(' ')[0] + '\'s history…') + '</p>' +
        (sm && sm.next_step ? '<div class="nx"><b>Next:</b> ' + esc(sm.next_step) + '</div>' : '') +
        (c.first_message_full ? '<div class="q">“' + esc(c.first_message_full.slice(0, 400)) + '”</div>' : '') + '</section>';
}
var PAYST = {draft: ['Not sent', 'crm2-t-lost'], sent: ['Sent', 'crm2-t-contacted'], viewed: ['Opened', 'crm2-t-qualified'], accepted: ['Accepted', 'crm2-t-won'], paid: ['Paid', 'crm2-t-won'], cancelled: ['Withdrawn', 'crm2-t-lost']};
var PAYK = {quote: 'Quote', deposit: 'Deposit', invoice: 'Invoice'};
function visitsLine(R) {
    var v = R.visits || {}; if (!v.count && !v.no_shows) return '';
    var due = v.due_on ? new Date(v.due_on + 'T09:00:00') : null, overdue = due && due < new Date();
    return '<div class="crm2-note" style="margin-bottom:8px;display:flex;flex-wrap:wrap;gap:6px 12px">' + (v.count ? '<span>' + v.count + ' visit' + (v.count === 1 ? '' : 's') + (v.last ? ', last ' + esc(ago(v.last)) : '') + '</span>' : '') +
        (v.no_shows ? '<span class="crm2-pill crm2-t-lost" style="color:var(--rd)">' + v.no_shows + ' no-show' + (v.no_shows === 1 ? '' : 's') + '</span>' : '') +
        (due ? '<span' + (overdue ? ' style="color:var(--am);font-weight:600"' : '') + '>' + (overdue ? 'Due for a visit since ' : 'Next visit due ') + esc(due.toLocaleDateString('en-US', {month: 'short', day: 'numeric'})) + '</span>' : '') + '</div>';
}
function payCard(R) {
    var list = (R.payments || []).map(function (p) {
        var st = PAYST[p.status] || PAYST.sent;
        return '<div class="crm2-pay"><div style="min-width:0"><div class="t">' + esc(PAYK[p.kind] || p.kind) + ' ' + esc(p.number) + ' · ' + esc(p.total_text) + '</div><div class="w">' + esc(p.title) +
            (p.paid_at ? ' · paid ' + esc(ago(p.paid_at)) : p.accepted_at ? ' · accepted ' + esc(ago(p.accepted_at)) : p.viewed_at ? ' · opened ' + esc(ago(p.viewed_at)) : p.sent_at ? ' · sent ' + esc(ago(p.sent_at)) : '') + '</div>' +
            (p.status !== 'paid' && p.status !== 'cancelled' ? '<div class="a"><button class="btn btn-ghost btn-sm" onclick="window._crm2.payCopy(\'' + esc(p.link) + '\')">Copy link</button><button class="btn btn-ghost btn-sm" onclick="window._crm2.paySend(' + p.id + ')">' + (p.sent_at ? 'Send again' : 'Send') + '</button><button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="window._crm2.payCancel(' + p.id + ')">Withdraw</button></div>' : '') +
            '</div><span class="crm2-pill ' + st[1] + '">' + st[0] + '</span></div>';
    }).join('');
    return '<section class="crm2-card"><div class="crm2-sec"><h4>Quotes and payments</h4>' + (list || '<div class="crm2-note">Nothing sent yet.</div>') +
        '<button class="btn btn-outline btn-sm" style="width:100%;margin-top:12px" onclick="window._crm2.payNew()">' + I('add', 14) + ' Quote or payment request</button>' +
        (R.payments_account ? '' : '<div class="crm2-note" style="margin-top:8px">To take card payments online, connect your Stripe account in Settings → Payments. Requests still go out; clients reply to arrange payment.</div>') + '</div></section>';
}
function draftCard(d, withName) {
    return '<article class="crm2-draft" data-draft="' + d.id + '"><div class="top"><b>' + esc(withName ? (d.name + ' · ' + (d.subject || '')) : (d.subject || 'Reply')) + '</b><span>' +
        esc(d.source === 'daily' ? (d.reason || 'Follow-up') : 'Reply to their enquiry') + ' · written by Sarah ' + esc(ago(d.created_at)) + '</span></div>' +
        '<label class="crm2-hide-d" for="crm2-d-' + d.id + '">Message</label><textarea class="form-input" id="crm2-d-' + d.id + '">' + esc(d.body) + '</textarea>' +
        '<div class="row"><button class="btn btn-ghost btn-sm" onclick="window._crm2.skipDraft(' + d.id + ')">Skip</button>' +
        (withName ? '<button class="btn btn-outline btn-sm" onclick="window._crm2.open(' + d.lead_id + ')">Open ' + esc(String(d.name).split(' ')[0]) + '</button>' : '') +
        '<button class="btn btn-primary btn-sm" onclick="window._crm2.sendDraft(' + d.id + ')">' + I('send', 14) + ' Send email</button></div></article>';
}

function root() { return document.getElementById('crm-root'); }
function busy(el) { if (el) el.innerHTML = (typeof loadingCard === 'function') ? loadingCard(260) : '<div class="crm2-empty">Loading…</div>'; }

// ── shell ───────────────────────────────────────────────────────────────────
function shell(body) {
    var w = words(); var b = S.biz ? bizById(S.biz) : null;
    var tabs = [
        ['today', 'sun', 'Today', S.today && S.today.waiting_total ? S.today.waiting_total : 0],
        ['clients', 'users', w.many, 0], ['board', 'board', 'Board', 0], ['reports', 'chart', 'Reports', 0], ['settings', 'settings', 'Setup', 0]
    ];
    var bizSel = multi() ? '<select class="form-select crm2-biz" id="crm2-biz" aria-label="Business" onchange="window._crm2.biz(this.value)"><option value="">All businesses</option>' +
        S.setup.businesses.map(function (x) { return '<option value="' + x.id + '"' + (String(S.biz) === String(x.id) ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') +
        (S.setup.unassigned ? '<option value="none"' + (S.biz === 'none' ? ' selected' : '') + '>Not tied to a business yet</option>' : '') + '</select>' : '';
    var sub = b ? esc(b.name) + ' · ' + esc(S.pack.label) + ' setup' : (multi() ? 'All businesses' : '');
    return '<div class="crm2">' +
        '<div class="crm2-head"><div><h1 class="crm2-title">' + esc(S.biz && S.biz !== 'none' ? w.many : 'Clients') + '</h1>' + (sub ? '<div class="crm2-sub">' + sub + '</div>' : '') + '</div>' +
        '<div class="crm2-head-actions">' + bizSel + '<button class="btn btn-primary" onclick="window._crm2.newClient()">' + I('add', 16) + ' New ' + esc(words().one.toLowerCase()) + '</button></div></div>' +
        '<div class="crm2-tabs" role="tablist">' + tabs.map(function (t) {
            return '<button class="crm2-tab" role="tab" aria-selected="' + (S.tab === t[0]) + '" onclick="window._crm2.tab(\'' + t[0] + '\')">' + I(t[1], 16) + esc(t[2]) + (t[3] ? '<span class="n">' + t[3] + '</span>' : '') + '</button>';
        }).join('') + '</div>' + body + '</div>';
}
async function render() {
    var el = root(); if (!el) return;
    try {
        var body = '';
        if (S.tab === 'record') body = recordHtml();
        else if (S.tab === 'clients') body = listHtml();
        else if (S.tab === 'board') body = boardHtml();
        else if (S.tab === 'reports') body = reportHtml();
        else if (S.tab === 'settings') body = setupHtml();
        else body = todayHtml();
        el.innerHTML = S.tab === 'record' ? '<div class="crm2">' + body + '</div>' : shell(body);
        if (S.tab === 'board') wireBoard();
        if (S.tab === 'settings' && S.biz && S.biz !== 'none') loadAutoreply();
    } catch (e) {
        console.error('[Clients] render', e);
        el.innerHTML = '<div class="crm2-empty"><b>Something went wrong showing this page.</b>' + esc(e.message) + '<div style="margin-top:12px"><button class="btn btn-outline" onclick="window.crmLoad(document.getElementById(\'crm-root\'))">Try again</button></div></div>';
    }
}
async function go(tab) {
    S.tab = tab; var el = root(); busy(el);
    try {
        if (tab === 'today') await loadToday();
        if (tab === 'clients') await loadList(false);
        if (tab === 'board') await loadBoard();
        if (tab === 'reports') await loadReport();
    } catch (e) { toast('Could not load: ' + e.message, 'error'); }
    try { if (window._luRouter && window._luRouter.enabled()) window._luRouter.pushView('crm'); } catch (e) {}
    render();
}

// ── Today ───────────────────────────────────────────────────────────────────
function personRow(c, side) {
    return '<li class="crm2-row" onclick="window._crm2.open(' + c.id + ')"><div class="crm2-av" aria-hidden="true">' + esc(initials(c.name)) + '</div>' +
        '<div class="main"><div class="nm">' + esc(c.name) + '</div><div class="meta">' + esc(chName(c.channel)) + (multi() && !S.biz && c.business_name ? ' · ' + esc(c.business_name) : '') + (c.first_message ? ' · “' + esc(c.first_message) + '”' : '') + '</div></div>' +
        '<div class="side">' + (side || '') + '</div></li>';
}
function decideBtns(id) { return '<span style="display:inline-flex;gap:6px" onclick="event.stopPropagation()"><button class="btn btn-primary btn-sm" onclick="window._crm2.decide(' + id + ',\'confirm\')">Confirm</button><button class="btn btn-outline btn-sm" onclick="window._crm2.decide(' + id + ',\'decline\')">Decline</button></span>'; }
function todayHtml() {
    var t = S.today || {}; var w = words(); var wk = t.week || {};
    var kpi = function (l, v, h) { return '<div class="crm2-card crm2-kpi"><div class="l">' + l + '</div><div class="v">' + v + '</div>' + (h ? '<div class="h">' + h + '</div>' : '') + '</div>'; };
    var waiting = (t.waiting || []).map(function (c) { return personRow(c, ago(c.created_at)); }).join('');
    var tasks = (t.tasks || []).map(function (k) {
        return '<li class="crm2-row" style="cursor:default"><input type="checkbox" class="crm2-cb" aria-label="Mark done" onclick="event.stopPropagation();window._crm2.done(' + k.id + ',this)">' +
            '<div class="main" onclick="window._crm2.open(' + k.lead_id + ')" style="cursor:pointer"><div class="nm">' + esc(k.title) + '</div><div class="meta">' + esc(k.lead_name || '') + '</div></div>' +
            '<div class="side" style="' + (k.overdue ? 'color:var(--rd)' : '') + '">' + (k.due ? (k.overdue ? 'Overdue · ' : '') + esc(when(k.due)) : 'No date') + '</div></li>';
    }).join('');
    var bookings = (t.bookings || []).map(function (b) {
        return '<li class="crm2-row"' + (b.lead_id ? ' onclick="window._crm2.open(' + b.lead_id + ')"' : ' style="cursor:default"') + '><div class="crm2-av" aria-hidden="true">' + I('calendar', 16) + '</div>' +
            '<div class="main"><div class="nm">' + esc(b.lead_name || b.title) + '</div><div class="meta">' + esc(b.title) + ' · ' + esc(when(b.starts_at)) + '</div></div><div class="side">' + (b.pending ? decideBtns(b.id) : '') + '</div></li>';
    }).join('');
    var stalled = (t.stalled || []).map(function (c) { return personRow(c, '<span class="crm2-pill ' + toneS(c.status) + '">' + esc(c.stage_name) + '</span>'); }).join('');
    var empty = function (b, s) { return '<div class="crm2-empty"><b>' + b + '</b>' + s + '</div>'; };
    return '<div class="crm2-kpis">' +
        kpi('Waiting for a reply', t.waiting_total || 0, (t.waiting_total ? 'Answer these first' : 'All answered')) +
        kpi('New this week', wk.new || 0, '') + kpi('Won this week', wk.won || 0, '') + kpi('All ' + esc(w.many.toLowerCase()), wk.total || 0, '') + '</div>' +
        '<div class="crm2-grid2"><div style="display:flex;flex-direction:column;gap:16px">' +
        ((S.drafts || []).length ? '<section class="crm2-card"><div class="crm2-card-h"><h3>' + I('ai', 16) + ' Replies Sarah wrote for you</h3><span class="more">Edit if you like, then send</span></div><div style="padding:0 14px 6px">' + S.drafts.map(function (d) { return draftCard(d, true); }).join('') + '</div></section>' : '') +
        '<section class="crm2-card"><div class="crm2-card-h"><h3>Waiting for your reply</h3>' + (t.waiting_total > 8 ? '<button class="btn btn-ghost btn-sm" onclick="window._crm2.view(\'new_enquiries\')">See all ' + t.waiting_total + '</button>' : '') + '</div>' +
        (waiting ? '<ul class="crm2-rows">' + waiting + '</ul>' : empty('Nobody is waiting.', 'New enquiries from your website, chatbot and social pages land here.')) + '</section>' +
        '<section class="crm2-card"><div class="crm2-card-h"><h3>Gone quiet</h3><span class="more">No contact in 14 days</span></div>' +
        (stalled ? '<ul class="crm2-rows">' + stalled + '</ul>' : empty('Nothing has gone quiet.', 'People you are talking to show here when two weeks pass without contact.')) + '</section>' +
        '</div><div style="display:flex;flex-direction:column;gap:16px">' +
        '<section class="crm2-card"><div class="crm2-card-h"><h3>Tasks due</h3></div>' + (tasks ? '<ul class="crm2-rows">' + tasks + '</ul>' : empty('No tasks due.', 'Add a task on any ' + esc(w.one.toLowerCase()) + ' and it shows here on the day.')) + '</section>' +
        '<section class="crm2-card"><div class="crm2-card-h"><h3>Bookings, next 7 days</h3></div>' + (bookings ? '<ul class="crm2-rows">' + bookings + '</ul>' : empty('No bookings this week.', 'Bookings from your website appear here.')) + '</section>' +
        '</div></div>';
}

// ── Clients list ────────────────────────────────────────────────────────────
function listHtml() {
    var L = S.list, p = S.pack || {stages: [], views: []}, w = words();
    var chips = '<button class="crm2-chip" aria-pressed="' + (!L.view && !L.stage) + '" onclick="window._crm2.view(\'\')">All <span class="n">' + (L.view || L.stage ? '' : L.total) + '</span></button>' +
        (p.views || []).map(function (v) { return '<button class="crm2-chip" aria-pressed="' + (L.view === v.key) + '" onclick="window._crm2.view(\'' + v.key + '\')">' + esc(v.name) + ' <span class="n">' + (L.counts[v.key] != null ? L.counts[v.key] : '') + '</span></button>'; }).join('');
    var stageOpts = '<option value="">Any stage</option>' + (p.stages || []).map(function (s) { return '<option value="' + s.key + '"' + (L.stage === s.key ? ' selected' : '') + '>' + esc(s.name) + '</option>'; }).join('');
    var chOpts = '<option value="">Any channel</option>' + Object.keys(CH).map(function (k) { return '<option value="' + k + '"' + (L.channel === k ? ' selected' : '') + '>' + CH[k] + '</option>'; }).join('');
    var sortOpts = [['created_at|desc', 'Newest first'], ['created_at|asc', 'Oldest first'], ['updated_at|desc', 'Recently updated'], ['name|asc', 'Name A to Z'], ['score|desc', 'Highest score']].map(function (o) {
        return '<option value="' + o[0] + '"' + (L.sort + '|' + L.dir === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('');
    var nSel = Object.keys(L.sel).length;
    var bulk = nSel ? '<div class="crm2-bulk" role="region" aria-label="Selected"><b style="color:var(--t1)">' + nSel + ' selected</b>' +
        (S.biz && S.biz !== 'none' ? '<select class="form-select" style="max-width:220px" aria-label="Move selected to" onchange="window._crm2.bulkStage(this.value)"><option value="">Move to…</option>' + (p.stages || []).map(function (s) { return '<option value="' + s.key + '">' + esc(s.name) + '</option>'; }).join('') + '</select>' : '<span class="crm2-note">Pick one business to move stages in bulk</span>') +
        '<button class="btn btn-outline btn-sm" onclick="window._crm2.bulkArchive()">' + I('delete', 14) + ' Archive</button><button class="btn btn-ghost btn-sm" onclick="window._crm2.clearSel()">Clear</button></div>' : '';
    var showBiz = multi() && !S.biz;
    var rows = L.rows.map(function (c) {
        var pk = packOf(c);
        return '<tr class="r' + (L.sel[c.id] ? ' sel' : '') + '" onclick="window._crm2.open(' + c.id + ')">' +
            '<td style="width:36px" onclick="event.stopPropagation()"><input type="checkbox" class="crm2-cb" aria-label="Select ' + esc(c.name) + '"' + (L.sel[c.id] ? ' checked' : '') + ' onchange="window._crm2.sel(' + c.id + ',this.checked)"></td>' +
            '<td><div class="who"><div class="crm2-av" aria-hidden="true">' + esc(initials(c.name)) + '</div><div style="min-width:0"><div class="nm">' + esc(c.name) + (c.duplicate ? ' <span class="crm2-pill crm2-t-qualified" title="Possibly the same person as another record">Possible duplicate</span>' : '') + '</div>' +
            '<div class="sub">' + esc(c.email || c.phone || '') + (c.first_message ? ' · “' + esc(c.first_message) + '”' : '') + '</div></div></div></td>' +
            (showBiz ? '<td>' + esc(c.business_name || 'No business yet') + '</td>' : '') +
            '<td><span class="crm2-pill ' + toneS(c.status) + '">' + esc(c.stage_name) + '</span></td>' +
            '<td>' + esc(chName(c.channel)) + '</td>' +
            '<td>' + (c.last_activity_at ? esc(ago(c.last_activity_at)) : '<span style="color:var(--am)">Not yet</span>') + '</td>' +
            '<td>' + (c.next_task_at ? esc(when(c.next_task_at)) : '—') + '</td>' +
            '<td style="text-align:right;color:var(--t1)">' + (money(c.value) || '—') + '</td></tr>';
    }).join('');
    var cards = L.rows.map(function (c) {
        return '<div class="crm2-mc" onclick="window._crm2.open(' + c.id + ')"><div class="crm2-av" aria-hidden="true">' + esc(initials(c.name)) + '</div><div class="main"><div class="nm">' + esc(c.name) + '</div>' +
            '<div class="meta">' + esc(chName(c.channel)) + ' · ' + esc(ago(c.created_at)) + (showBiz && c.business_name ? ' · ' + esc(c.business_name) : '') + '</div>' +
            '<div class="row2"><span class="crm2-pill ' + toneS(c.status) + '">' + esc(c.stage_name) + '</span>' + (c.last_activity_at ? '' : '<span class="crm2-note" style="color:var(--am)">Not contacted yet</span>') + '</div></div></div>';
    }).join('');
    var anyFilter = L.view || L.stage || L.channel || L.search;
    var empty = '<div class="crm2-card"><div class="crm2-empty"><b>' + (anyFilter ? 'No ' + esc(w.many.toLowerCase()) + ' match.' : 'No ' + esc(w.many.toLowerCase()) + ' yet.') + '</b>' +
        (anyFilter ? '<button class="btn btn-ghost btn-sm" style="margin-top:8px" onclick="window._crm2.reset()">Clear filters</button>' : 'Enquiries from your website, bookings, chatbot and social pages arrive here by themselves.<div style="margin-top:12px"><button class="btn btn-primary" onclick="window._crm2.newClient()">' + I('add', 14) + ' Add a ' + esc(w.one.toLowerCase()) + '</button></div>') + '</div></div>';
    var undo = S.lastImport && S.lastImport.created ? '<div class="crm2-bulk" role="status"><span style="color:var(--t1)">' + S.lastImport.created + ' ' + esc(w.many.toLowerCase()) + ' imported.</span><button class="btn btn-outline btn-sm" onclick="window._crm2.undoImport()">Undo import</button><button class="btn btn-ghost btn-sm" onclick="S_clearImport()">Dismiss</button></div>' : '';
    return undo + '<div class="crm2-views" role="toolbar" aria-label="Ready lists">' + chips + '</div>' +
        '<div class="crm2-toolbar"><div class="crm2-search">' + I('search', 16) + '<input class="form-input" type="search" id="crm2-q" placeholder="Search ' + esc(w.many.toLowerCase()) + '" aria-label="Search by name, email or phone" value="' + esc(L.search) + '" onkeydown="if(event.key===\'Enter\')window._crm2.search(this.value)" onsearch="window._crm2.search(this.value)"></div>' +
        (S.biz && S.biz !== 'none' ? '<select class="form-select" style="max-width:190px" aria-label="Stage" onchange="window._crm2.filter(\'stage\',this.value)">' + stageOpts + '</select>' : '') +
        '<select class="form-select" style="max-width:170px" aria-label="Channel" onchange="window._crm2.filter(\'channel\',this.value)">' + chOpts + '</select>' +
        '<select class="form-select" style="max-width:190px" aria-label="Sort" onchange="window._crm2.sort(this.value)">' + sortOpts + '</select>' +
        '<span style="flex:1"></span><button class="btn btn-outline btn-sm" onclick="window._crm2.importCsv()">' + I('upload', 14) + ' Import</button><button class="btn btn-outline btn-sm" onclick="window._crm2.exportCsv()">' + I('download', 14) + ' Export</button></div>' + bulk +
        (L.rows.length ? '<div class="crm2-card crm2-tablewrap" data-lu-nowrap style="overflow-x:auto"><table class="crm2-table"><thead><tr><th><input type="checkbox" class="crm2-cb" aria-label="Select all shown" onchange="window._crm2.selAll(this.checked)"' + (nSel && nSel === L.rows.length ? ' checked' : '') + '></th><th>' + esc(w.one) + '</th>' + (showBiz ? '<th>Business</th>' : '') + '<th>Stage</th><th>Came from</th><th>Last contact</th><th>Next task</th><th style="text-align:right">Value</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<div class="crm2-cards">' + cards + '</div>' +
            '<div class="crm2-more crm2-note">Showing ' + L.rows.length + ' of ' + L.total + (L.total > L.rows.length ? ' <button class="btn btn-outline btn-sm" style="margin-left:10px" onclick="window._crm2.more(this)">Show more</button>' : '') + '</div>' : empty);
}

// ── Board ───────────────────────────────────────────────────────────────────
function boardHtml() {
    if (multi() && (!S.biz || S.biz === 'none')) {
        return '<div class="crm2-card"><div class="crm2-empty"><b>Pick a business to see its board.</b>Each business has its own stages.<div class="crm2-sheet-list" style="max-width:360px;margin:16px auto 0">' +
            S.setup.businesses.map(function (b) { return '<button class="btn btn-outline" onclick="window._crm2.biz(\'' + b.id + '\')">' + esc(b.name) + ' <span class="crm2-note" style="margin-left:auto">' + esc(b.many) + '</span></button>'; }).join('') + '</div></div></div>';
    }
    var p = S.pack, rows = S.board.rows;
    var cols = p.stages.map(function (s) {
        var cs = rows.filter(function (c) { return c.stage === s.key; });
        var val = cs.reduce(function (a, c) { return a + (parseFloat(c.value) || 0); }, 0);
        return '<section class="crm2-col" aria-label="' + esc(s.name) + '"><div class="crm2-col-h"><b>' + esc(s.name) + '</b><span>' + cs.length + (val ? ' · ' + money(val) : '') + '</span></div>' +
            '<div class="crm2-col-b" data-stage="' + s.key + '">' + (cs.map(function (c) {
                return '<article class="crm2-bc" draggable="true" data-id="' + c.id + '" tabindex="0" onclick="window._crm2.open(' + c.id + ')" onkeydown="if(event.key===\'Enter\')window._crm2.open(' + c.id + ')">' +
                    '<div class="nm">' + esc(c.name) + '</div><div class="meta">' + esc(chName(c.channel)) + ' · ' + esc(ago(c.created_at)) + '</div>' +
                    '<div class="foot"><span class="crm2-note">' + (c.last_activity_at ? 'Contact ' + esc(ago(c.last_activity_at)) : '<span style="color:var(--am)">Not contacted</span>') + '</span>' +
                    '<button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();window._crm2.moveSheet(' + c.id + ')" aria-label="Move ' + esc(c.name) + '">Move</button></div></article>';
            }).join('') || '<div class="crm2-note" style="text-align:center;padding:14px 4px">Nobody here</div>') + '</div></section>';
    }).join('');
    return (S.board.total > rows.length ? '<div class="crm2-note" style="margin-bottom:10px">Showing the newest ' + rows.length + ' of ' + S.board.total + '.</div>' : '') + '<div class="crm2-board">' + cols + '</div>';
}
function wireBoard() {
    var dragId = null;
    document.querySelectorAll('.crm2-bc').forEach(function (c) {
        c.addEventListener('dragstart', function (e) { dragId = c.getAttribute('data-id'); e.dataTransfer.setData('text/plain', dragId); e.dataTransfer.effectAllowed = 'move'; c.style.opacity = '.5'; });
        c.addEventListener('dragend', function () { c.style.opacity = ''; });
    });
    document.querySelectorAll('.crm2-col-b').forEach(function (col) {
        col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('over'); });
        col.addEventListener('dragleave', function () { col.classList.remove('over'); });
        col.addEventListener('drop', function (e) { e.preventDefault(); col.classList.remove('over'); var id = e.dataTransfer.getData('text/plain') || dragId; if (id) moveTo(parseInt(id), col.getAttribute('data-stage')); });
    });
}
async function moveTo(id, stage) {
    var row = S.board.rows.find(function (c) { return c.id == id; }) || (S.rec && S.rec.client.id == id ? S.rec.client : null);
    if (row && row.stage === stage) return;
    try {
        var j = await api('PUT', '/clients/' + id + '/stage', {stage: stage});
        S.board.rows.forEach(function (c) { if (c.id == id) { c.stage = j.stage; c.stage_name = j.stage_name; } });
        S.list.rows.forEach(function (c) { if (c.id == id) { c.stage = j.stage; c.stage_name = j.stage_name; } });
        toast('Moved to ' + j.stage_name + '.');
        if (S.tab === 'record') { await loadRecord(id); }
    } catch (e) { toast('Not moved: ' + e.message, 'error'); }
    render();
}

// ── Record ──────────────────────────────────────────────────────────────────
function recordHtml() {
    var R = S.rec; if (!R) return '<div class="crm2-empty">Not found.</div>';
    var c = R.client, p = R.pack, w = {one: p.one, many: p.many};
    var idx = p.stages.findIndex(function (s) { return s.key === c.stage; });
    var path = '<nav class="crm2-path" aria-label="Stage">' + p.stages.map(function (s, i) {
        var cls = i === idx ? 'cur' : (i < idx && p.stages[idx] && p.stages[idx].status !== 'lost' ? 'done' : '');
        return '<button class="crm2-step ' + cls + '" aria-current="' + (i === idx ? 'step' : 'false') + '" onclick="window._crm2.stage(' + c.id + ',\'' + s.key + '\')">' + esc(s.name) + '</button>';
    }).join('') + '</nav>';
    var tel = phoneDigits(c.phone), wa = tel.replace(/^\+/, '');
    var act = function (href, icon, label, ok) { return '<a class="crm2-act" ' + (ok ? 'href="' + esc(href) + '"' + (href.indexOf('http') === 0 ? ' target="_blank" rel="noopener"' : '') : 'aria-disabled="true" tabindex="-1"') + '>' + I(icon, 18) + '<span>' + label + '</span></a>'; };
    var acts = '<div class="crm2-acts">' + act('tel:' + tel, 'phone', 'Call', !!tel) + act('sms:' + tel, 'sms', 'Text', !!tel) + act('https://wa.me/' + wa, 'whatsapp', 'WhatsApp', !!wa) + act('mailto:' + (c.email || ''), 'mail', 'Email', !!c.email) + '</div>';
    // summary: facts only (Sarah's written summary arrives in Phase 3)
    var facts = R.facts || {};
    var sum = 'Came in through ' + chName(c.channel).toLowerCase() + ' ' + ago(c.created_at) + '. ';
    sum += facts.human_touches ? 'Last contact ' + ago(c.last_activity_at) + '.' : 'Nobody has replied yet.';
    if (R.tasks && R.tasks.length) sum += ' Next: ' + R.tasks[0].title + (R.tasks[0].due ? ' (' + when(R.tasks[0].due) + ')' : '') + '.';
    var summary = '<div class="crm2-summary"><p>' + esc(sum) + '</p>' + (c.first_message_full ? '<q>' + esc(c.first_message_full.slice(0, 500)) + '</q>' : '') + '</div>';
    var dl = function (k, v, href) { return v ? '<dt>' + k + '</dt><dd>' + (href ? '<a href="' + esc(href) + '">' + esc(v) + '</a>' : esc(v)) + '</dd>' : ''; };
    var left = '<div class="lc" style="display:flex;flex-direction:column;gap:16px"><section class="crm2-card"><div class="crm2-sec"><h4>Contact</h4><dl class="crm2-kv">' +
        dl('Phone', c.phone, tel ? 'tel:' + tel : '') + dl('Email', c.email, c.email ? 'mailto:' + c.email : '') + dl('Company', c.company) + dl('City', c.city) +
        dl('Came from', chName(c.channel)) + dl('Added', ago(c.created_at)) + (multi() ? dl('Business', c.business_name || 'Not tied to a business yet') : '') + dl('Value', money(c.value)) +
        '</dl><button class="btn btn-outline btn-sm" style="margin-top:14px;width:100%" onclick="window._crm2.edit()">' + I('edit', 14) + ' Edit contact</button></div>' +
        '<div class="crm2-sec"><h4>Details</h4><form onsubmit="event.preventDefault();window._crm2.saveFields(' + c.id + ',this)">' + p.fields.map(function (f) {
            var v = (R.fields || {})[f.key] || ''; var id = 'crm2-f-' + f.key;
            var input = f.type === 'textarea' ? '<textarea class="form-input" id="' + id + '" name="' + f.key + '" rows="2">' + esc(v) + '</textarea>'
                : f.type === 'select' ? '<select class="form-select" id="' + id + '" name="' + f.key + '"><option value="">—</option>' + f.options.map(function (o) { return '<option' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>'
                : '<input class="form-input" id="' + id + '" name="' + f.key + '" type="' + (f.type === 'date' ? 'date' : 'text') + '" value="' + esc(v) + '">';
            return '<div class="crm2-field"><label for="' + id + '">' + esc(f.label) + '</label>' + input + '</div>';
        }).join('') + '<button class="btn btn-outline btn-sm" type="submit" style="width:100%">' + I('save', 14) + ' Save details</button></form></div></section></div>';
    var comp = S.composer;
    var tl = (R.timeline || []).map(function (a) {
        var human = !a.system;
        var ic = {note: 'edit', call: 'phone', email: 'mail', meeting: 'calendar', task: 'check', booked: 'calendar', payment: 'check'}[a.type] || (a.type === 'repeat_enquiry' ? 'message' : 'clock');
        var lab = {note: 'Note', call: 'Call', email: 'Email', meeting: 'Meeting', task: 'Task', status_changed: 'Stage changed', lead_created: 'Added', repeat_enquiry: 'Came back', assigned: 'Assigned', form_submission: 'Form', booked: 'Booking', payment: 'Payment'}[a.type] || 'History';
        return '<li><div class="dot' + (human ? ' h' : '') + '" aria-hidden="true">' + I(ic, 14) + '</div><div class="b"><div class="t">' + esc(lab) + (a.type === 'task' ? (a.status === 'done' ? ' · done' : (a.due_date ? ' · due ' + esc(when(a.due_date)) : '')) : '') + '</div>' +
            '<div class="d">' + esc(a.type === 'lead_created' ? 'Added to ' + p.many : a.title) + (a.description ? '\n' + esc(a.description) : '') + '</div></div><div class="w">' + esc(ago(a.created_at)) +
            (human ? '<br><button class="x" aria-label="Delete this ' + esc(lab.toLowerCase()) + '" onclick="window._crm2.delAct(\'' + a.id + '\')">' + I('delete', 14) + '</button>' : '') + '</div></li>';
    }).join('');
    var drafts = (R.drafts || []).map(function (d) { return draftCard(d, false); }).join('');
    var mid = '<div class="mc">' + sarahBox(R) + (drafts ? '<section class="crm2-card" style="margin-bottom:14px"><div class="crm2-card-h"><h3>Reply ready to send</h3><span class="more">Sent as ' + esc(c.business_name || 'your business') + '</span></div><div style="padding:0 14px 6px">' + drafts + '</div></section>' : '') +
        '<section class="crm2-card crm2-comp"><div class="crm2-comp-tabs" role="toolbar" aria-label="Add to the timeline">' + [['note', 'Note'], ['call', 'Log a call'], ['task', 'Task'], ['meeting', 'Meeting']].map(function (x) {
            return '<button aria-pressed="' + (comp === x[0]) + '" onclick="window._crm2.comp(\'' + x[0] + '\')">' + x[1] + '</button>'; }).join('') + '</div>' +
        '<form class="crm2-comp-b" onsubmit="event.preventDefault();window._crm2.addAct(' + c.id + ',this)"><label for="crm2-comp-t" class="crm2-hide-d">Text</label><textarea class="form-input" id="crm2-comp-t" name="t" placeholder="' +
        esc({note: 'Write a note about ' + c.name + '…', call: 'What did you talk about?', task: 'What needs doing?', meeting: 'Meeting notes…'}[comp]) + '" required></textarea>' +
        '<div class="crm2-comp-f">' + (comp === 'task' ? '<label class="crm2-note" style="display:flex;align-items:center;gap:8px">Due <input class="form-input" type="date" name="due" style="width:auto"></label>' : '<span></span>') +
        '<button class="btn btn-primary btn-sm" type="submit">' + I('add', 14) + ' Add</button></div></form></section>' +
        '<section class="crm2-card"><div class="crm2-card-h"><h3>Timeline</h3></div>' + (tl ? '<ul class="crm2-tl">' + tl + '</ul>' : '<div class="crm2-empty">Nothing yet.</div>') + '</section></div>';
    var tasks = (R.tasks || []).map(function (t) {
        var late = t.due && new Date(String(t.due).replace(' ', 'T')) < new Date(new Date().toDateString());
        return '<div class="crm2-task"><input type="checkbox" class="crm2-cb" aria-label="Mark done" onclick="window._crm2.done(' + t.id + ',this,true)"><div><div class="t">' + esc(t.title) + '</div><div class="w' + (late ? ' late' : '') + '">' + (t.due ? (late ? 'Overdue · ' : '') + esc(when(t.due)) : 'No date') + '</div></div></div>';
    }).join('');
    var appts = (R.appointments || []).map(function (a) { var pend = /pending/.test(a.category || ''); return '<div class="crm2-task"><span aria-hidden="true" style="color:var(--t3)">' + I('calendar', 16) + '</span><div style="flex:1"><div class="t">' + esc(a.title) + '</div><div class="w">' + esc(when(a.starts_at)) + (pend ? ' · waiting for you' : (/confirmed/.test(a.category || '') ? ' · confirmed' : '')) + '</div>' + (pend ? '<div style="margin-top:8px">' + decideBtns(a.id) + '</div>' : '') + '</div></div>'; }).join('');
    var others = (R.others || []).map(function (o) { return '<button class="btn btn-ghost btn-sm" style="width:100%;justify-content:space-between" onclick="window._crm2.open(' + o.id + ')"><span>' + esc(o.business_name) + '</span><span class="crm2-note">' + esc(o.stage_name) + '</span></button>'; }).join('');
    var right = '<div class="rc" style="display:flex;flex-direction:column;gap:16px">' +
        '<section class="crm2-card"><div class="crm2-sec"><h4>Open tasks</h4>' + (tasks || '<div class="crm2-note">No open tasks.</div>') + '</div></section>' +
        '<section class="crm2-card"><div class="crm2-sec"><h4>Bookings</h4>' + visitsLine(R) + (appts || '<div class="crm2-note">No bookings.</div>') + '<button class="btn btn-outline btn-sm" style="width:100%;margin-top:12px" onclick="window._crm2.book(\'' + esc((R.visits && R.visits.due_on) || '') + '\')">' + I('calendar', 14) + ' ' + (R.visits && R.visits.count ? 'Book next visit' : 'Book ' + esc(c.name.split(' ')[0])) + '</button><div class="crm2-note" style="margin-top:8px">Bookings also show in your Calendar.</div></div></section>' +
        catCard(R) + payCard(R) +
        (others ? '<section class="crm2-card"><div class="crm2-sec"><h4>Also a client of</h4>' + others + '</div></section>' : '') +
        ((c.duplicate_of || []).length ? '<section class="crm2-card"><div class="crm2-sec"><h4>Possible duplicate</h4><div class="crm2-note" style="margin-bottom:8px">Another record looks like the same person in this business.</div>' + c.duplicate_of.map(function (d) { return '<button class="btn btn-ghost btn-sm" onclick="window._crm2.open(' + d + ')">Open record #' + d + '</button>'; }).join('') + '</div></section>' : '') +
        '<button class="btn btn-ghost btn-sm" style="color:var(--rd);align-self:flex-start" onclick="window._crm2.archiveOne(' + c.id + ')">' + I('delete', 14) + ' Archive this ' + esc(w.one.toLowerCase()) + '</button></div>';
    return '<button class="btn btn-ghost btn-sm" onclick="window._crm2.back()" style="margin-bottom:10px">' + I('back', 14) + ' ' + esc(S.pack && S.biz ? words().many : 'Clients') + '</button>' +
        '<div class="crm2-rec-top"><div class="crm2-rec-name"><div class="crm2-av" aria-hidden="true">' + esc(initials(c.name)) + '</div><div><h2>' + esc(c.name) + '</h2><div class="sub">' +
        '<span class="crm2-pill ' + stageTone(p, c.stage) + '">' + esc(c.stage_name) + '</span>' + (c.business_name && multi() ? ' &nbsp;' + esc(c.business_name) : '') + '</div></div></div>' + acts + '</div>' +
        path + '<div class="crm2-rec">' + left + mid + right + '</div>';
}

// ── Reports ─────────────────────────────────────────────────────────────────
function reportHtml() {
    var R = S.report || {}, t = R.totals || {};
    var days = [7, 30, 90, 365].map(function (d) { return '<button class="crm2-chip" aria-pressed="' + (S.days === d) + '" onclick="window._crm2.days(' + d + ')">' + (d === 365 ? '12 months' : 'Last ' + d + ' days') + '</button>'; }).join('');
    var kpi = function (l, v, h) { return '<div class="crm2-card crm2-kpi"><div class="l">' + l + '</div><div class="v">' + v + '</div>' + (h ? '<div class="h">' + h + '</div>' : '') + '</div>'; };
    var ch = R.channels || {}; var chMax = Math.max.apply(null, [1].concat(Object.keys(ch).map(function (k) { return ch[k]; })));
    var chBars = Object.keys(ch).map(function (k) { return '<div class="crm2-bar"><span class="l">' + esc(chName(k)) + '</span><span class="tr"><span class="f" style="width:' + Math.round(ch[k] / chMax * 100) + '%"></span></span><span class="n">' + ch[k] + '</span></div>'; }).join('');
    var st = R.stages || []; var stMax = Math.max.apply(null, [1].concat(st.map(function (s) { return s.count; })));
    var stBars = st.map(function (s) { return '<div class="crm2-bar"><span class="l">' + esc(s.name) + '</span><span class="tr"><span class="f" style="width:' + Math.round(s.count / stMax * 100) + '%"></span></span><span class="n">' + s.count + '</span></div>'; }).join('');
    var rh = t.median_reply_hours;
    return '<div class="crm2-views">' + days + '</div><div class="crm2-kpis">' +
        kpi('New ' + esc(words().many.toLowerCase()), t.new || 0, '') + kpi('Won', t.won || 0, (t.conversion || 0) + '% of new') +
        kpi('Typical time to first reply', rh == null ? '—' : (rh < 1 ? Math.round(rh * 60) + ' min' : (rh < 48 ? rh + ' h' : Math.round(rh / 24) + ' days')), t.answered ? t.answered + ' answered' : 'None answered yet') +
        kpi('Won value', money(t.won_value) || '$0', '') + '</div>' +
        moreKpis(R) + trendChart(R.trend || []) +
        '<div class="crm2-grid2" style="margin-top:16px"><section class="crm2-card"><div class="crm2-card-h"><h3>Where they came from</h3><span class="more">and how many were won</span></div>' + channelTable(R.by_channel || []) + '</section>' +
        '<div style="display:flex;flex-direction:column;gap:16px"><section class="crm2-card"><div class="crm2-card-h"><h3>Where they are now</h3></div>' + (stBars ? '<div class="crm2-bars">' + stBars + '</div>' : '<div class="crm2-empty">Nothing yet.</div>') + '</section>' +
        speedCard(R.reply_speed) + '</div></div>' + bizTable(R.per_business || []);
}

function moreKpis(R) {
    var m = R.money || {}, v = R.visits || {}, cur = m.currency || 'USD', out = [];
    var mny = function (x) { return (cur === 'USD' ? '$' : cur + ' ') + (parseFloat(x) || 0).toLocaleString('en-US', {maximumFractionDigits: 0}); };
    if (m.collected || m.outstanding) out.push(['Collected online', mny(m.collected), m.outstanding ? mny(m.outstanding) + ' still to pay' : 'Nothing outstanding']);
    if (m.quotes_open) out.push(['Quotes waiting', m.quotes_open, 'sent, not yet accepted']);
    if (v.held) out.push(['No-show rate', v.no_show_rate + '%', v.no_shows + ' of ' + v.held + ' bookings']);
    if (!out.length) return '';
    return '<div class="crm2-kpis" style="grid-template-columns:repeat(' + Math.min(4, out.length) + ',minmax(0,1fr))">' + out.map(function (k) { return '<div class="crm2-card crm2-kpi"><div class="l">' + k[0] + '</div><div class="v">' + k[1] + '</div><div class="h">' + k[2] + '</div></div>'; }).join('') + '</div>';
}
function trendChart(t) {
    if (!t.length) return '';
    var W = 720, H = 190, pad = 28, max = Math.max.apply(null, [1].concat(t.map(function (w) { return Math.max(w.new, w.won); }))), bw = (W - pad * 2) / t.length;
    var step = max <= 5 ? 1 : Math.ceil(max / 4), ticks = []; for (var y = 0; y <= max; y += step) ticks.push(y);
    var Y = function (v) { return H - 24 - (v / (ticks[ticks.length - 1] || 1)) * (H - 44); };
    var g = ticks.map(function (tk) { return '<line x1="' + pad + '" x2="' + (W - 8) + '" y1="' + Y(tk) + '" y2="' + Y(tk) + '" stroke="var(--bd)"/><text x="' + (pad - 6) + '" y="' + (Y(tk) + 4) + '" text-anchor="end" font-size="10" fill="var(--t3)">' + tk + '</text>'; }).join('');
    var bars = t.map(function (w, i) { var x = pad + i * bw + bw * 0.14, b = bw * 0.34;
        return '<rect x="' + x + '" y="' + Y(w.new) + '" width="' + b + '" height="' + Math.max(0, H - 24 - Y(w.new)) + '" rx="3" fill="var(--p)"><title>' + w.week + ': ' + w.new + ' new</title></rect>' +
            '<rect x="' + (x + b + 2) + '" y="' + Y(w.won) + '" width="' + b + '" height="' + Math.max(0, H - 24 - Y(w.won)) + '" rx="3" fill="var(--ac)"><title>' + w.week + ': ' + w.won + ' won</title></rect>' +
            (i % 2 === 0 || t.length < 8 ? '<text x="' + (pad + i * bw + bw / 2) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="10" fill="var(--t3)">' + w.week + '</text>' : ''); }).join('');
    return '<section class="crm2-card"><div class="crm2-card-h"><h3>Last 12 weeks</h3></div><div class="crm2-chart"><svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="New and won each week for the last 12 weeks">' + g + bars + '</svg></div>' +
        '<div class="crm2-legend"><span><i style="background:var(--p)"></i>New</span><span><i style="background:var(--ac)"></i>Won</span></div></section>';
}
function channelTable(rows) {
    if (!rows.length) return '<div class="crm2-empty">No enquiries in this period.</div>';
    return '<div style="overflow-x:auto"><table class="crm2-rt"><thead><tr><th>Channel</th><th class="n">New</th><th class="n">Won</th><th class="n">Won rate</th></tr></thead><tbody>' +
        rows.map(function (r) { return '<tr><td>' + esc(chName(r.channel)) + '</td><td class="n">' + r.new + '</td><td class="n">' + r.won + '</td><td class="n">' + r.rate + '%</td></tr>'; }).join('') + '</tbody></table></div>';
}
function speedCard(sp) {
    if (!sp) return '';
    var tot = sp.within_1h + sp.within_24h + sp.later + sp.not_yet; if (!tot) return '';
    var seg = [['within_1h', 'Within an hour', 'var(--ac)'], ['within_24h', 'Same day', 'var(--bl)'], ['later', 'Later', 'var(--am)'], ['not_yet', 'Not yet', 'var(--rd)']];
    return '<section class="crm2-card"><div class="crm2-card-h"><h3>How fast they heard back</h3></div><div class="crm2-stack" role="img" aria-label="' + seg.map(function (s) { return s[1] + ' ' + sp[s[0]]; }).join(', ') + '">' +
        seg.map(function (s) { return sp[s[0]] ? '<span style="width:' + (sp[s[0]] / tot * 100) + '%;background:' + s[2] + '"></span>' : ''; }).join('') + '</div>' +
        '<div class="crm2-legend" style="flex-wrap:wrap;padding-top:6px">' + seg.map(function (s) { return '<span><i style="background:' + s[2] + '"></i>' + s[1] + ' ' + sp[s[0]] + '</span>'; }).join('') + '</div></section>';
}
function bizTable(rows) {
    if (!rows.length || S.biz) return '';
    return '<section class="crm2-card" style="margin-top:16px"><div class="crm2-card-h"><h3>Your businesses side by side</h3></div><div style="overflow-x:auto"><table class="crm2-rt"><thead><tr><th>Business</th><th class="n">New</th><th class="n">Won</th><th class="n">Won value</th></tr></thead><tbody>' +
        rows.map(function (r) { return '<tr><td>' + esc(r.name) + '</td><td class="n">' + r.new + '</td><td class="n">' + r.won + '</td><td class="n">' + (money(r.value) || '—') + '</td></tr>'; }).join('') + '</tbody></table></div></section>';
}

// ── Setup ───────────────────────────────────────────────────────────────────
function setupHtml() {
    var list = (S.setup && S.setup.businesses) || [];
    if (!list.length) return '<div class="crm2-card"><div class="crm2-empty"><b>Add your business first.</b>Clients is set up per business: its words, stages and details follow its industry.</div></div>';
    if (!S.biz || S.biz === 'none') {
        return '<div class="crm2-card"><div class="crm2-card-h"><h3>Your businesses</h3><span class="more">Each one has its own setup</span></div><ul class="crm2-rows">' + list.map(function (b) {
            return '<li class="crm2-row" onclick="window._crm2.biz(\'' + b.id + '\',\'settings\')"><div class="crm2-av" aria-hidden="true">' + esc(initials(b.name)) + '</div><div class="main"><div class="nm">' + esc(b.name) + '</div><div class="meta">Calls them ' + esc(b.many.toLowerCase()) + '</div></div><div class="side">Change ›</div></li>';
        }).join('') + '</ul></div>';
    }
    var p = S.pack, packs = S.setup.packs || [];
    return '<div class="crm2-grid2"><section class="crm2-card"><div class="crm2-sec"><h4>How ' + esc(bizById(S.biz).name) + ' works</h4><div class="crm2-note" style="margin-bottom:12px">Picked from the business\'s industry. Change it if it does not fit.</div><div class="crm2-packs">' +
        packs.map(function (k) { return '<button class="crm2-pack" aria-pressed="' + (p.key === k.key) + '" onclick="window._crm2.setPack(\'' + k.key + '\')"><b>' + esc(k.label) + (p.auto === k.key ? ' · suggested' : '') + '</b>' + esc(k.about) + '</button>'; }).join('') + '</div></div>' +
        '<form class="crm2-sec" onsubmit="event.preventDefault();window._crm2.saveWords(this)"><h4>What you call them</h4><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div class="crm2-field"><label for="crm2-one">One</label><input class="form-input" id="crm2-one" name="one" maxlength="30" value="' + esc(p.one) + '"></div>' +
        '<div class="crm2-field"><label for="crm2-many">Many</label><input class="form-input" id="crm2-many" name="many" maxlength="30" value="' + esc(p.many) + '"></div></div><button class="btn btn-primary btn-sm" type="submit">Save</button></form></section>' +
        '<section class="crm2-card"><div class="crm2-sec"><h4>Sarah answers new enquiries</h4><div id="crm2-ar" class="crm2-note">Loading…</div></div></section>' +
        '<section class="crm2-card"><div class="crm2-sec"><h4>Stages</h4>' + p.stages.map(function (s, i) { return '<div class="crm2-task"><span class="crm2-pill ' + stageTone(p, s.key) + '">' + (i + 1) + '</span><div class="t">' + esc(s.name) + '</div></div>'; }).join('') + '</div>' +
        '<div class="crm2-sec"><h4>Details kept about each ' + esc(p.one.toLowerCase()) + '</h4>' + p.fields.map(function (f) { return '<div class="crm2-task"><div class="t">' + esc(f.label) + '</div></div>'; }).join('') + '</div><div class="crm2-sec"><h4>Your own details</h4>' + ((p.fields || []).filter(function (f) { return f.custom; }).map(function (f) { return '<div class="crm2-task" style="justify-content:space-between;align-items:center"><div class="t">' + esc(f.label) + ' <span class="crm2-note">' + esc({text: 'text', textarea: 'long text', number: 'number', date: 'date', select: 'choice: ' + (f.options || []).join(', ')}[f.type] || f.type) + '</span></div><button class="btn btn-ghost btn-sm" aria-label="Remove ' + esc(f.label) + '" onclick="window._crm2.cfRemove(\'' + esc(f.key) + '\')">' + I('delete', 14) + '</button></div>'; }).join('') || '<div class="crm2-note">None yet.</div>') +
     '<form onsubmit="event.preventDefault();window._crm2.cfAdd(this)" style="display:grid;grid-template-columns:minmax(0,1fr) 120px;gap:8px;margin-top:10px"><label class="crm2-hide-d" for="cf-l">Name</label><input class="form-input" id="cf-l" name="l" maxlength="60" placeholder="e.g. Insurance provider" required><label class="crm2-hide-d" for="cf-t">Kind</label><select class="form-select" id="cf-t" name="t"><option value="text">Text</option><option value="textarea">Long text</option><option value="number">Number</option><option value="date">Date</option><option value="select">Choice</option></select>' +
     '<input class="form-input" name="o" placeholder="Choices, separated by commas (for Choice)" style="grid-column:1 / -1"><button class="btn btn-outline btn-sm" type="submit" style="grid-column:1 / -1">' + I('add', 14) + ' Add detail</button></form></div></section></div>';
}

async function loadAutoreply() {
    var box = document.getElementById('crm2-ar'); if (!box) return;
    try {
        var j = await api('GET', '/autoreply/' + S.biz);
        var opt = function (v, t, d) { return '<label><input type="radio" name="crm2-ar" value="' + v + '"' + (j.setting === v ? ' checked' : '') + ' onchange="window._crm2.setAutoreply(this.value)"><span><b>' + t + '</b><span>' + d + '</span></span></label>'; };
        box.className = '';
        box.innerHTML = '<div class="crm2-note" style="margin-bottom:10px">When someone enquires through your website, booking form, chatbot or social pages and leaves an email, Sarah writes the reply as your business within about a minute. She never promises prices or times you have not given her.</div>' +
            '<div class="crm2-radio">' + opt('send', 'Send for me', 'Sarah replies straight away and tells you in chat.') + opt('draft', 'Show me each one first', 'Sarah writes the reply; you send or skip it.') + opt('off', 'Off', 'New enquiries wait for you.') + '</div>' +
            (j.sent_count ? '<div class="crm2-note" style="margin-top:10px">' + j.sent_count + ' repl' + (j.sent_count === 1 ? 'y' : 'ies') + ' sent so far' + (j.last_sent_at ? ', last ' + ago(j.last_sent_at) : '') + '.</div>' : '') +
            (j.available === false ? '<div class="crm2-note" style="margin-top:10px">This is paused platform-wide right now.</div>' : '');
    } catch (e) { box.textContent = 'Could not load this setting.'; }
}

function parseCsv(t) {
    t = t.replace(/^\ufeff/, ''); var rows = [], row = [], cur = '', q = false;
    var sep = (t.split('\n')[0].split(';').length > t.split('\n')[0].split(',').length) ? ';' : ',';
    for (var i = 0; i < t.length; i++) { var c = t[i];
        if (q) { if (c === '"') { if (t[i + 1] === '"') { cur += '"'; i++; } else q = false; } else cur += c; }
        else if (c === '"') q = true; else if (c === sep) { row.push(cur); cur = ''; }
        else if (c === '\n' || c === '\r') { if (c === '\r' && t[i + 1] === '\n') i++; row.push(cur); cur = ''; if (row.some(function (x) { return x.trim() !== ''; })) rows.push(row); row = []; }
        else cur += c; }
    row.push(cur); if (row.some(function (x) { return x.trim() !== ''; })) rows.push(row);
    return rows;
}

// ── dialogs (the shell's modal) ─────────────────────────────────────────────
function modal(title, inner, onSave, saveLabel) {
    var bd = document.createElement('div'); bd.className = 'modal-backdrop';
    bd.innerHTML = '<div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(title) + '" style="max-width:520px;width:calc(100% - 24px);max-height:90vh;overflow-y:auto">' +
        '<div class="modal-header"><h3>' + esc(title) + '</h3><button class="btn btn-ghost btn-sm" aria-label="Close" data-x>' + I('close', 16) + '</button></div>' +
        '<form style="padding:20px;display:grid;gap:14px">' + inner + '<div data-err role="alert" style="display:none;color:var(--rd);font-size:13px"></div>' +
        '<div style="display:flex;gap:10px;justify-content:flex-end"><button type="button" class="btn btn-outline" data-x>Cancel</button><button type="submit" class="btn btn-primary">' + esc(saveLabel || 'Save') + '</button></div></form></div>';
    document.body.appendChild(bd); requestAnimationFrame(function () { bd.classList.add('visible'); });
    var close = function () { bd.remove(); };
    bd.querySelectorAll('[data-x]').forEach(function (x) { x.onclick = close; });
    bd.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    var f = bd.querySelector('form'), err = bd.querySelector('[data-err]');
    f.onsubmit = async function (e) {
        e.preventDefault(); err.style.display = 'none'; var btn = f.querySelector('[type=submit]'); btn.disabled = true;
        try { await onSave(f); close(); } catch (x) { err.textContent = x.message; err.style.display = 'block'; btn.disabled = false; }
    };
    setTimeout(function () { var first = f.querySelector('input,select,textarea'); if (first) first.focus(); }, 60);
    return bd;
}
function fld(id, label, type, val, extra) { return '<div class="crm2-field" style="margin:0"><label for="' + id + '">' + label + '</label><input class="form-input" id="' + id + '" name="' + id + '" type="' + (type || 'text') + '" value="' + esc(val || '') + '"' + (extra || '') + '></div>'; }

// ── actions ─────────────────────────────────────────────────────────────────
window.S_clearImport = function () { S.lastImport = null; render(); };
window._crm2 = {
    tab: function (t) { go(t); },
    biz: async function (v, tab) {
        S.biz = v || ''; try { v ? localStorage.setItem(BIZ_KEY, v) : localStorage.removeItem(BIZ_KEY); } catch (e) {}
        S.list.view = ''; S.list.stage = ''; busy(root());
        try { await loadSetup(); } catch (e) { toast(e.message, 'error'); }
        go(tab || S.tab === 'record' ? (tab || 'clients') : S.tab);
    },
    open: async function (id) {
        var el = root(); busy(el);
        try { await loadRecord(id); S.tab = 'record'; } catch (e) { toast('Could not open: ' + e.message, 'error'); return go('clients'); }
        try { if (window._luRouter && window._luRouter.enabled()) window._luRouter.pushView('crm', String(id)); } catch (e) {}
        render(); try { window.scrollTo(0, 0); el.scrollTop = 0; } catch (e) {}
    },
    back: function () { go(S.list.rows.length ? 'clients' : 'today'); },
    view: function (v) { S.list.view = v; S.list.stage = ''; go('clients'); },
    filter: function (k, v) { S.list[k] = v; if (k === 'stage') S.list.view = ''; go('clients'); },
    sort: function (v) { var a = v.split('|'); S.list.sort = a[0]; S.list.dir = a[1]; go('clients'); },
    search: function (q) { S.list.search = String(q || '').trim(); go('clients').then(function () { var i = document.getElementById('crm2-q'); if (i) { i.focus(); } }); },
    reset: function () { S.list.view = S.list.stage = S.list.channel = S.list.search = ''; go('clients'); },
    more: async function (b) { if (b) { b.disabled = true; b.textContent = 'Loading…'; } try { await loadList(true); } catch (e) { toast(e.message, 'error'); } render(); },
    sel: function (id, on) { if (on) S.list.sel[id] = 1; else delete S.list.sel[id]; render(); },
    selAll: function (on) { S.list.sel = {}; if (on) S.list.rows.forEach(function (c) { S.list.sel[c.id] = 1; }); render(); },
    clearSel: function () { S.list.sel = {}; render(); },
    bulkStage: async function (stage) {
        if (!stage) return; var ids = Object.keys(S.list.sel).map(Number);
        try { var j = await api('POST', '/clients/bulk', {ids: ids, action: 'stage', stage: stage}); toast(j.done + ' moved' + (j.skipped ? ', ' + j.skipped + ' not moved' : '') + '.'); } catch (e) { toast(e.message, 'error'); }
        go('clients');
    },
    bulkArchive: async function () {
        var ids = Object.keys(S.list.sel).map(Number);
        var ok = typeof luConfirm === 'function' ? await luConfirm('Archive ' + ids.length + ' ' + (ids.length === 1 ? words().one.toLowerCase() : words().many.toLowerCase()) + '? You can restore them later.', 'Archive', 'Archive', 'Cancel') : true;
        if (!ok) return;
        try { var j = await api('POST', '/clients/bulk', {ids: ids, action: 'archive'}); toast(j.done + ' archived.'); } catch (e) { toast(e.message, 'error'); }
        go('clients');
    },
    archiveOne: async function (id) {
        var ok = typeof luConfirm === 'function' ? await luConfirm('Archive this ' + words().one.toLowerCase() + '? You can restore it later.', 'Archive', 'Archive', 'Cancel') : true;
        if (!ok) return;
        try { await api('POST', '/clients/bulk', {ids: [id], action: 'archive'}); toast('Archived.'); } catch (e) { return toast(e.message, 'error'); }
        go('clients');
    },
    moveSheet: function (id) {
        var row = S.board.rows.find(function (c) { return c.id == id; }) || {};
        var inner = '<div class="crm2-sheet-list">' + S.pack.stages.map(function (s) {
            return '<button type="button" class="btn ' + (s.key === row.stage ? 'btn-primary' : 'btn-outline') + '" data-st="' + s.key + '">' + esc(s.name) + (s.key === row.stage ? ' · now' : '') + '</button>'; }).join('') + '</div>';
        var bd = modal('Move ' + (row.name || ''), inner, async function () {}, 'Done');
        bd.querySelectorAll('[data-st]').forEach(function (b) { b.onclick = function () { bd.remove(); moveTo(id, b.getAttribute('data-st')); }; });
        var sub = bd.querySelector('[type=submit]'); if (sub) sub.style.display = 'none';
    },
    stage: function (id, st) { moveTo(id, st); },
    cfAdd: async function (f) {
        var cur = (S.pack.fields || []).filter(function (x) { return x.custom; }).map(function (x) { return {key: x.key, label: x.label, type: x.type, options: x.options}; });
        cur.push({label: f.l.value.trim(), type: f.t.value, options: f.o.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean)});
        try { var j = await api('PUT', '/setup/' + S.biz + '/fields', {fields: cur}); S.pack = j.pack; toast('Added. It shows on every ' + words().one.toLowerCase() + '.'); } catch (e) { return toast(e.message, 'error'); }
        render();
    },
    cfRemove: async function (key) {
        var cur = (S.pack.fields || []).filter(function (x) { return x.custom && x.key !== key; }).map(function (x) { return {key: x.key, label: x.label, type: x.type, options: x.options}; });
        try { var j = await api('PUT', '/setup/' + S.biz + '/fields', {fields: cur}); S.pack = j.pack; toast('Removed. What was written is kept.'); } catch (e) { return toast(e.message, 'error'); }
        render();
    },
    undoImport: async function () {
        var ok = typeof luConfirm === 'function' ? await luConfirm('Undo the import? The ' + S.lastImport.created + ' ' + words().many.toLowerCase() + ' it added are archived (people who were already here are not touched).', 'Undo import', 'Undo', 'Keep') : true;
        if (!ok) return;
        try { var j = await api('POST', '/imports/' + S.lastImport.batch + '/undo'); toast(j.archived + ' archived.'); } catch (e) { return toast(e.message, 'error'); }
        S.lastImport = null; go('clients');
    },
    importCsv: function () {
        var needBiz = multi() && (!S.biz || S.biz === 'none'), p = S.pack;
        var targets = [['', 'Skip this column'], ['name', 'Name'], ['first', 'First name'], ['last', 'Last name'], ['email', 'Email'], ['phone', 'Phone'], ['company', 'Company'], ['stage', 'Stage'], ['value', 'Value'], ['notes', 'Notes'], ['source', 'Where they came from']]
            .concat(needBiz ? [] : (p.fields || []).map(function (f) { return ['f:' + f.key, esc(f.label)]; }));
        var guess = function (h) { h = h.toLowerCase().trim();
            if (/^(full ?)?name$|^client$|^customer$|^contact$/.test(h)) return 'name'; if (/first/.test(h)) return 'first'; if (/last|surname/.test(h)) return 'last';
            if (/mail/.test(h)) return 'email'; if (/phone|mobile|cell|tel/.test(h)) return 'phone'; if (/company|organi|business/.test(h)) return 'company';
            if (/stage|status/.test(h)) return 'stage'; if (/value|amount|budget|deal/.test(h)) return 'value'; if (/note|comment|message/.test(h)) return 'notes'; if (/source|channel|from/.test(h)) return 'source';
            var f = (p.fields || []).find(function (x) { return x.label.toLowerCase() === h; }); return f && !needBiz ? 'f:' + f.key : ''; };
        var bd = modal('Import ' + words().many.toLowerCase(),
            (needBiz ? '<div class="crm2-field" style="margin:0"><label for="ib">Into which business?</label><select class="form-select" id="ib" name="ib" required><option value="">Pick a business</option>' + S.setup.businesses.map(function (b) { return '<option value="' + b.id + '">' + esc(b.name) + '</option>'; }).join('') + '</select></div>' : '') +
            '<div class="crm2-field" style="margin:0"><label for="if">Spreadsheet (CSV)</label><input class="form-input" type="file" id="if" name="if" accept=".csv,text/csv" required></div>' +
            '<div id="imap"></div><div class="crm2-field" style="margin:0"><label for="id">If someone is already in Clients</label><select class="form-select" id="id" name="id"><option value="skip">Leave them as they are</option><option value="update">Update them with this sheet</option></select></div>' +
            '<div class="crm2-note">Up to 5,000 rows. People already in Clients are matched by email or phone. You can undo the whole import for 24 hours.</div>',
            async function (f) {
                if (!bd._rows) throw new Error('Choose a CSV file first.');
                var map = Array.prototype.map.call(bd.querySelectorAll('[data-col]'), function (x) { return x.value; });
                if (map.indexOf('name') < 0 && map.indexOf('first') < 0 && map.indexOf('email') < 0 && map.indexOf('phone') < 0) throw new Error('Match at least a name, email or phone column.');
                var rows = bd._rows.slice(1).map(function (r) { var o = {fields: {}}; map.forEach(function (t, i) { var v = (r[i] || '').trim(); if (!t || !v) return; if (t.indexOf('f:') === 0) o.fields[t.slice(2)] = v; else if (t === 'first') o.name = (v + ' ' + (o.name || '')).trim(); else if (t === 'last') o.name = ((o.name || '') + ' ' + v).trim(); else o[t] = v; }); return o; })
                    .filter(function (o) { return o.name || o.email || o.phone; }).slice(0, 5000);
                var biz = needBiz ? f.ib.value : (S.biz && S.biz !== 'none' ? S.biz : '');
                var btn = f.querySelector('[type=submit]'), tot = {created: 0, updated: 0, skipped: 0, errors: []}, batch = '';
                for (var i = 0; i < rows.length; i += 400) {
                    btn.textContent = 'Importing ' + Math.min(i + 400, rows.length) + ' of ' + rows.length + '…';
                    var j = await api('POST', '/clients/import', {business_id: biz || undefined, rows: rows.slice(i, i + 400), on_duplicate: f.id.value, batch: batch || undefined});
                    batch = j.batch; tot.created += j.created; tot.updated += j.updated; tot.skipped += j.skipped; tot.errors = tot.errors.concat(j.errors || []);
                }
                S.lastImport = {batch: batch, created: tot.created};
                toast(tot.created + ' added' + (tot.updated ? ', ' + tot.updated + ' updated' : '') + (tot.skipped ? ', ' + tot.skipped + ' left as they were' : '') + (tot.errors.length ? ', ' + tot.errors.length + ' with problems' : '') + '.');
                go('clients');
            }, 'Import');
        bd.querySelector('#if').addEventListener('change', function (e) {
            var file = e.target.files[0]; if (!file) return;
            if (file.size > 8 * 1024 * 1024) { toast('That file is over 8 MB. Split it into smaller files.', 'error'); return; }
            var rd = new FileReader(); rd.onload = function () {
                var rows = parseCsv(String(rd.result || '')); bd._rows = rows;
                if (rows.length < 2) { bd.querySelector('#imap').innerHTML = '<div class="crm2-note" style="color:var(--rd)">That file has no rows.</div>'; return; }
                var h = rows[0];
                bd.querySelector('#imap').innerHTML = '<div class="crm2-field" style="margin:0"><label>Match your columns (' + (rows.length - 1) + ' rows)</label><div style="display:grid;gap:6px;max-height:40vh;overflow:auto">' + h.map(function (col, i) {
                    var g = guess(col); return '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px;align-items:center"><span style="font-size:13px;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(col) + '">' + esc(col) + ' <span class="crm2-note">e.g. ' + esc((rows[1][i] || '').slice(0, 24)) + '</span></span><select class="form-select" data-col="' + i + '" aria-label="' + esc(col) + ' goes to">' + targets.map(function (t) { return '<option value="' + t[0] + '"' + (t[0] === g ? ' selected' : '') + '>' + t[1] + '</option>'; }).join('') + '</select></div>';
                }).join('') + '</div></div>';
            }; rd.readAsText(file);
        });
    },
    catPick: function (id, on) { S.catSel = S.catSel || {}; if (on) S.catSel[id] = 1; else delete S.catSel[id]; var n = Object.keys(S.catSel).length;
        var b = document.getElementById('crm2-cat-send'); if (b) b.disabled = !n; var v = document.getElementById('crm2-cat-view'); if (v) v.disabled = n !== 1; },
    catAdd: function () {
        var j = S.rec.cat, have = {}; (j.interests || []).forEach(function (i) { have[i.id] = 1; });
        var list = j.items.map(function (it) { return '<label class="crm2-it" style="cursor:pointer"><input type="checkbox" name="ci" value="' + it.id + '"' + (have[it.id] ? ' checked' : '') + '>' + (it.photo ? '<img src="' + esc(it.photo) + '" alt="">' : '') +
            '<span style="min-width:0"><span class="t" style="display:block">' + esc(it.title) + '</span><span class="w">' + esc([it.price, it.location].filter(Boolean).join(' · ')) + '</span></span></label>'; }).join('');
        modal(j.label + ' ' + S.rec.client.name.split(' ')[0] + ' is interested in', list ? '<div style="max-height:52vh;overflow:auto">' + list + '</div>' : '<div class="crm2-note">Nothing in your catalogue yet. Add items to your website\'s catalogue first.</div>',
            async function (f) { var ids = Array.prototype.map.call(f.querySelectorAll('input[name=ci]:checked'), function (x) { return parseInt(x.value, 10); });
                await api('PUT', '/clients/' + S.recId + '/interests', {ids: ids}); toast('Saved.'); refreshCatalogue(S.recId); }, 'Save');
    },
    catSend: function () {
        var ids = Object.keys(S.catSel || {}).map(Number), c = S.rec.client; if (!ids.length) return;
        modal('Send ' + ids.length + ' to ' + c.name, '<div class="crm2-field" style="margin:0"><label for="cn">A line from you (optional)</label><textarea class="form-input" id="cn" name="cn" rows="3" placeholder="These came up this week and fit what you are looking for."></textarea></div><div class="crm2-note">Sent as your business to ' + esc(c.email || 'their email') + ', each with a photo and a link to its page on your website.</div>',
            async function (f) { var j = await api('POST', '/clients/' + c.id + '/send-items', {ids: ids, note: f.cn.value.trim()}); toast('Sent ' + j.sent + ' to ' + c.name + '.'); S.catSel = {}; await loadRecord(c.id); render(); }, 'Send');
    },
    catViewing: function () {
        var id = Object.keys(S.catSel || {})[0]; var j = S.rec.cat; var it = (j.matches.concat(j.interests, j.items)).find(function (x) { return String(x.id) === String(id); });
        window._crm2.book('', it ? {title: 'Viewing — ' + it.title, where: it.location || ''} : null);
    },
    payNew: function () {
        var R = S.rec, c = R.client, cur = R.payments_currency || 'USD';
        var line = function (i) { return '<div class="crm2-line"><input class="form-input" aria-label="Line ' + i + ' description" name="d' + i + '" placeholder="' + (i === 1 ? 'What it is for' : '') + '"><input class="form-input" aria-label="Line ' + i + ' quantity" name="q' + i + '" type="number" min="0" step="1" value="1"><input class="form-input" aria-label="Line ' + i + ' price" name="u' + i + '" type="number" min="0" step="0.01" placeholder="0.00"><span></span></div>'; };
        var bd = modal('Quote or payment request for ' + c.name,
            '<div class="crm2-field" style="margin:0"><label for="pk">What are you sending?</label><select class="form-select" id="pk" name="pk"><option value="quote">A quote to accept</option><option value="deposit">A deposit to pay</option><option value="invoice" selected>An invoice to pay</option></select></div>' +
            fld('pt', 'Title', 'text', '', ' maxlength="190" placeholder="e.g. Wedding catering, 14 June"') +
            '<div class="crm2-field" style="margin:0"><label>Lines (' + esc(cur) + ')</label><div class="crm2-lines" id="plines">' + line(1) + line(2) + line(3) + '</div><button type="button" class="btn btn-ghost btn-sm" id="padd" style="margin-top:6px">' + I('add', 14) + ' Another line</button><div class="crm2-tot" style="margin-top:8px"><span>Total</span><span id="ptot">0.00</span></div></div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' + fld('pdue', 'Due or valid until', 'date', '') + '<div></div></div>' +
            '<div class="crm2-field" style="margin:0"><label for="pn">Note to the client (optional)</label><textarea class="form-input" id="pn" name="pn" rows="2"></textarea></div>' +
            '<div class="crm2-note">' + (c.email ? 'It goes to ' + esc(c.email) + ' as an email from your business, with a link to ' + (R.payments_account ? 'accept or pay online.' : 'view it (card payment needs Stripe connected).') : 'This client has no email: it will be saved and you can copy the link to share it.') + '</div>',
            async function (f) {
                var items = []; for (var i = 1; i <= 12; i++) { if (!f['d' + i]) break; var d = f['d' + i].value.trim(); if (d) items.push({description: d, qty: parseFloat(f['q' + i].value) || 1, unit: parseFloat(f['u' + i].value) || 0}); }
                if (!items.length) throw new Error('Add at least one line.');
                var j = await api('POST', '/clients/' + c.id + '/payments', {kind: f.pk.value, title: f.pt.value.trim(), items: items, due_date: f.pdue.value || null, note: f.pn.value.trim(), send: !!c.email});
                toast(c.email ? (j.sent ? 'Sent to ' + c.name + '.' : 'Saved, but not sent: ' + (j.error || '')) : 'Saved. Copy the link to share it.');
                await loadRecord(c.id); render();
            }, c.email ? 'Send' : 'Save');
        var sum = function () { var t = 0; for (var i = 1; i <= 12; i++) { if (!bd.querySelector('[name=d' + i + ']')) break; t += (parseFloat(bd.querySelector('[name=q' + i + ']').value) || 0) * (parseFloat(bd.querySelector('[name=u' + i + ']').value) || 0); } bd.querySelector('#ptot').textContent = t.toFixed(2); };
        bd.addEventListener('input', sum);
        bd.querySelector('#padd').onclick = function () { var n = bd.querySelectorAll('.crm2-line').length + 1; if (n > 12) return; bd.querySelector('#plines').insertAdjacentHTML('beforeend', line(n)); };
    },
    payCopy: function (link) { try { navigator.clipboard.writeText(link); toast('Link copied.'); } catch (e) { toast(link); } },
    paySend: async function (id) { try { var j = await api('POST', '/payments/' + id + '/send'); toast('Sent.'); } catch (e) { return toast('Not sent: ' + e.message, 'error'); } await loadRecord(S.recId); render(); },
    payCancel: async function (id) {
        var ok = typeof luConfirm === 'function' ? await luConfirm('Withdraw this request? The client\'s link will say it was withdrawn.', 'Withdraw', 'Withdraw', 'Keep') : true;
        if (!ok) return;
        try { await api('POST', '/payments/' + id + '/cancel'); toast('Withdrawn.'); } catch (e) { return toast(e.message, 'error'); } await loadRecord(S.recId); render();
    },
    sendDraft: async function (id) {
        var ta = document.getElementById('crm2-d-' + id); var body = ta ? ta.value.trim() : '';
        if (!body) return toast('The message is empty.', 'error');
        var btn = document.querySelector('[data-draft="' + id + '"] .btn-primary'); if (btn) btn.disabled = true;
        try { await api('POST', '/drafts/' + id + '/send', {body: body}); toast('Sent. It is on their timeline.'); } catch (e) { if (btn) btn.disabled = false; return toast('Not sent: ' + e.message, 'error'); }
        if (S.tab === 'record' && S.recId) { await loadRecord(S.recId); } else { await loadToday(); } render();
    },
    skipDraft: async function (id) {
        try { await api('POST', '/drafts/' + id + '/skip'); } catch (e) { return toast(e.message, 'error'); }
        if (S.tab === 'record' && S.recId) { await loadRecord(S.recId); } else { await loadToday(); } render();
    },
    setAutoreply: async function (v) {
        try { await api('PUT', '/autoreply/' + S.biz, {setting: v}); toast({send: 'Sarah will reply to new enquiries for you.', draft: 'Sarah will show you each reply first.', off: 'Off. New enquiries wait for you.'}[v]); } catch (e) { toast(e.message, 'error'); }
        loadAutoreply();
    },
    decide: async function (eventId, decision) {
        try {
            var res = await fetch('/api/calendar/events/' + eventId + '/decision', {method: 'POST', headers: hdr(), body: JSON.stringify({decision: decision})});
            var j = {}; try { j = await res.json(); } catch (e) {}
            if (!res.ok || j.success === false) throw new Error(j.error || j.message || ('HTTP ' + res.status));
            toast(decision === 'confirm' ? 'Booking confirmed. It shows as confirmed in your Calendar.' : 'Booking declined.');
        } catch (e) { return toast('Not saved: ' + e.message, 'error'); }
        if (S.tab === 'record' && S.recId) { await loadRecord(S.recId); } else { await loadToday(); }
        render();
    },
    book: function (defDay, preset) {
        var c = S.rec.client, p = S.rec.pack;
        var word = {property: 'Viewing', stays: 'Booking', projects: 'Consultation', enrolment: 'Trial', guests: 'Reservation'}[p.key] || 'Appointment';
        var d = new Date(Date.now() + 86400000), pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        var day = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        if (defDay && /^d{4}-d{2}-d{2}$/.test(defDay) && new Date(defDay + 'T23:59:00') > new Date()) day = defDay;
        modal('Book ' + c.name, fld('bt', 'What', 'text', preset && preset.title ? preset.title : word + ' — ' + c.name, ' required maxlength="150"') + (preset && preset.where ? '<input type="hidden" name="bw" value="' + esc(preset.where) + '">' : '') +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' + fld('bd', 'Day', 'date', day, ' required') + fld('bh', 'Time', 'time', '10:00', ' required') + '</div>' +
            '<div class="crm2-field" style="margin:0"><label for="bl">How long</label><select class="form-select" id="bl" name="bl">' + [[30, '30 minutes'], [45, '45 minutes'], [60, '1 hour'], [90, '1½ hours'], [120, '2 hours'], [240, 'Half a day']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === 60 ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></div>' +
            '<div class="crm2-note">It goes into your Calendar and onto ' + esc(c.name.split(' ')[0]) + '\'s timeline. Nothing is sent to them automatically.</div>',
            async function (f) {
                var start = new Date(f.bd.value + 'T' + f.bh.value); if (isNaN(start)) throw new Error('Pick a day and time.');
                var end = new Date(start.getTime() + parseInt(f.bl.value, 10) * 60000);
                var fmt = function (x) { return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate()) + ' ' + pad(x.getHours()) + ':' + pad(x.getMinutes()) + ':00'; };
                var res = await fetch('/api/calendar/schedule', {method: 'POST', headers: hdr(), body: JSON.stringify({kind: 'appointment', title: f.bt.value.trim(), starts_at: fmt(start), ends_at: fmt(end), lead_id: c.id, business_id: c.business_id || undefined, remind_minutes: 30, location: f.bw ? f.bw.value : undefined})});
                var j = {}; try { j = await res.json(); } catch (e) {}
                if (!res.ok || j.success === false) throw new Error(j.error || j.message || ('HTTP ' + res.status));
                toast('Booked. It is in your Calendar.'); await loadRecord(c.id); render();
            }, 'Book');
    },
    comp: function (k) { S.composer = k; render(); setTimeout(function () { var t = document.getElementById('crm2-comp-t'); if (t) t.focus(); }, 30); },
    addAct: async function (id, f) {
        var txt = f.t.value.trim(); if (!txt) return;
        var type = S.composer, body = {lead_id: id, type: type, title: txt.split('\n')[0].slice(0, 180), description: txt.indexOf('\n') > 0 ? txt : ''};
        if (type === 'task' && f.due && f.due.value) body.due_date = f.due.value;
        var b = f.querySelector('[type=submit]'); b.disabled = true;
        try { await api('POST', '/activities', body); toast('Added.'); await loadRecord(id); } catch (e) { toast('Not added: ' + e.message, 'error'); b.disabled = false; return; }
        render();
    },
    delAct: async function (aid) {
        var ok = typeof luConfirm === 'function' ? await luConfirm('Delete this from the timeline?', 'Delete', 'Delete', 'Cancel') : true;
        if (!ok) return;
        try { await api('DELETE', '/activities/' + aid); await loadRecord(S.recId); } catch (e) { return toast(e.message, 'error'); }
        render();
    },
    done: async function (tid, box, inRecord) {
        box.disabled = true;
        try { await api('PUT', '/activities/' + tid, {status: 'done'}); toast('Done.'); } catch (e) { box.checked = false; box.disabled = false; return toast(e.message, 'error'); }
        if (inRecord) { await loadRecord(S.recId); render(); } else { await loadToday(); render(); }
    },
    saveFields: async function (id, f) {
        var fields = {}; S.rec.pack.fields.forEach(function (x) { if (f[x.key]) fields[x.key] = f[x.key].value; });
        try { await api('PUT', '/clients/' + id + '/fields', {fields: fields}); toast('Details saved.'); await loadRecord(id); render(); } catch (e) { toast('Not saved: ' + e.message, 'error'); }
    },
    edit: function () {
        var c = S.rec.client;
        modal('Edit contact', fld('name', 'Name', 'text', c.name, ' required maxlength="190" autocomplete="name"') + fld('phone', 'Phone', 'tel', c.phone, ' autocomplete="tel"') +
            fld('email', 'Email', 'email', c.email, ' autocomplete="email"') + fld('company', 'Company', 'text', c.company) + fld('value', 'Value ($)', 'number', c.value || '', ' min="0" step="1"'),
            async function (f) {
                await api('PUT', '/leads/' + c.id, {name: f.name.value.trim(), phone: f.phone.value.trim(), email: f.email.value.trim(), company: f.company.value.trim(), deal_value: parseFloat(f.value.value) || 0});
                toast('Saved.'); await loadRecord(c.id); render();
            });
    },
    newClient: function () {
        var needBiz = multi() && (!S.biz || S.biz === 'none');
        var p = S.pack, w = words();
        var bizSel = needBiz ? '<div class="crm2-field" style="margin:0"><label for="biz">Business</label><select class="form-select" id="biz" name="biz" required><option value="">Pick a business</option>' +
            S.setup.businesses.map(function (b) { return '<option value="' + b.id + '">' + esc(b.name) + '</option>'; }).join('') + '</select></div>' : '';
        var stageSel = needBiz ? '' : '<div class="crm2-field" style="margin:0"><label for="stage">Stage</label><select class="form-select" id="stage" name="stage">' + p.stages.map(function (s) { return '<option value="' + s.key + '">' + esc(s.name) + '</option>'; }).join('') + '</select></div>';
        var srcSel = '<div class="crm2-field" style="margin:0"><label for="source">Where did they come from?</label><select class="form-select" id="source" name="source">' +
            [['manual', 'Added by you'], ['phone', 'Phone call'], ['walk_in', 'Walk-in'], ['referral', 'Referral'], ['email', 'Email'], ['social', 'Social media'], ['other', 'Other']].map(function (o) { return '<option value="' + o[0] + '">' + o[1] + '</option>'; }).join('') + '</select></div>';
        var extra = needBiz ? '' : p.fields.slice(0, 3).map(function (f) {
            return f.type === 'select' ? '<div class="crm2-field" style="margin:0"><label for="f_' + f.key + '">' + esc(f.label) + '</label><select class="form-select" id="f_' + f.key + '" name="f_' + f.key + '"><option value="">—</option>' + f.options.map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select></div>'
                : fld('f_' + f.key, esc(f.label), f.type === 'date' ? 'date' : 'text', '');
        }).join('');
        modal('New ' + w.one.toLowerCase(), bizSel + fld('name', 'Name', 'text', '', ' required maxlength="190" autocomplete="name"') + fld('phone', 'Phone', 'tel', '', ' autocomplete="tel"') + fld('email', 'Email', 'email', '', ' autocomplete="email"') + stageSel + srcSel + extra,
            async function (f) {
                var body = {name: f.name.value.trim(), phone: f.phone.value.trim(), email: f.email.value.trim(), source: f.source.value, business_id: needBiz ? f.biz.value : (S.biz && S.biz !== 'none' ? S.biz : '')};
                if (!needBiz) { body.stage = f.stage.value; body.fields = {}; p.fields.slice(0, 3).forEach(function (x) { var el = f['f_' + x.key]; if (el && el.value) body.fields[x.key] = el.value; }); }
                if (!body.name) throw new Error('Add their name.');
                if (!body.business_id) delete body.business_id;
                var j = await api('POST', '/clients', body);
                toast('Added.'); window._crm2.open(j.id);
            }, 'Add');
    },
    exportCsv: async function () {
        try {
            var j = await fetch('/api/crm/leads/export/csv' + qs(Object.assign({search: S.list.search, channel: S.list.channel}, bizQ())), {headers: hdr()}).then(function (r) { return r.json(); });
            var blob = new Blob([j.csv || ''], {type: 'text/csv'}); var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
            a.download = (words().many.toLowerCase().replace(/\s+/g, '-')) + '-' + new Date().toISOString().slice(0, 10) + '.csv'; document.body.appendChild(a); a.click(); a.remove();
            toast('Export ready.');
        } catch (e) { toast('Export failed: ' + e.message, 'error'); }
    },
    days: function (d) { S.days = d; go('reports'); },
    setPack: async function (k) {
        try { var j = await api('PUT', '/setup/' + S.biz, {pack: k}); S.pack = j.pack; await loadSetup(); toast(j.pack.label + ' setup in use.'); } catch (e) { toast(e.message, 'error'); }
        render();
    },
    saveWords: async function (f) {
        try { var j = await api('PUT', '/setup/' + S.biz, {one: f.one.value.trim(), many: f.many.value.trim()}); S.pack = j.pack; await loadSetup(); toast('Saved.'); } catch (e) { toast(e.message, 'error'); }
        render();
    }
};

// ── entry points ────────────────────────────────────────────────────────────
async function boot() {
    var j = await api('GET', '/boot' + qs(bizQ()));
    S.biz = j.business_id || (S.biz === 'none' ? 'none' : ''); S.setup = j.setup; S.pack = j.setup.pack; S.today = j.today; S.drafts = j.drafts || [];
    if (S.biz && S.biz !== 'none' && !bizById(S.biz)) S.biz = '';
    try { if (S.biz) localStorage.setItem(BIZ_KEY, S.biz); } catch (x) {}
    try { sessionStorage.setItem(BOOT_KEY(), JSON.stringify({biz: S.biz, setup: S.setup, today: S.today, drafts: S.drafts})); } catch (x) {}
}
function BOOT_KEY() { var w = ''; try { w = localStorage.getItem('lu_workspace_id') || ''; } catch (x) {} return 'lu_crm_boot_' + w; }
function bootCached() {
    try { var w = localStorage.getItem('lu_workspace_id'); if (!w) return false; var c = JSON.parse(sessionStorage.getItem(BOOT_KEY()) || 'null');
        if (!c || !c.setup || !c.today) return false; S.biz = c.biz || ''; S.setup = c.setup; S.pack = c.setup.pack; S.today = c.today; S.drafts = c.drafts || []; return true; } catch (x) { return false; }
}
window.crmLoad = async function (el) {
    if (!el) return;
    var curWs = ''; try { curWs = localStorage.getItem('lu_workspace_id') || ''; } catch (x) {}
    if (S._ws !== curWs) { S.setup = null; S.today = null; S.drafts = []; S.rec = null; }   // another workspace: never show the last one's clients
    S._ws = curWs;
    if (!S.setup) bootCached();
    if (S.setup && S.today) {   // came back to Clients: show what we had, then refresh it
        S.tab = 'today'; render();
        try { await boot(); if (S.tab === 'today' && root() === el) render(); } catch (e) {}
        try { if (window._luRouter && window._luRouter.enabled()) window._luRouter.pushView('crm'); } catch (e) {}
        return;
    }
    busy(el);
    try { await boot(); S.tab = 'today'; try { if (window._luRouter && window._luRouter.enabled()) window._luRouter.pushView('crm'); } catch (e) {} render(); return; } catch (e) { console.warn('[Clients] boot', e.message); }
    try { await loadSetup(); } catch (e) {
        el.innerHTML = '<div class="crm2-empty"><b>Clients could not load.</b>' + esc(e.message) + '<div style="margin-top:12px"><button class="btn btn-outline" onclick="window.crmLoad(document.getElementById(\'crm-root\'))">Try again</button></div></div>';
        return;
    }
    await go('today');
};
window._crmOpenDetail = function (id) { return window._crm2.open(parseInt(id)); };
console.log('[Clients] CRM-UX-2 loaded');
})();
