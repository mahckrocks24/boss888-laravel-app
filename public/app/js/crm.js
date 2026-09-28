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
        '.crm2-title{font-family:var(--fh);font-size:28px;font-weight:700;letter-spacing:-.01em;color:var(--t1);margin:0;line-height:1.15}',
        '.crm2-sub{font-size:13px;color:var(--t3);margin-top:4px}',
        '.crm2-head-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}',
        '.crm2-biz{min-width:220px;max-width:320px}',
        '.crm2-tabs{display:flex;gap:4px;border-bottom:1px solid var(--bd);margin-bottom:20px;overflow-x:auto;scrollbar-width:none}',
        '.crm2-tabs::-webkit-scrollbar{display:none}',
        '.crm2-tab{flex:0 0 auto;appearance:none;background:none;border:0;border-bottom:2px solid transparent;color:var(--t2);font:600 14px var(--fb);padding:12px 14px;display:flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap;min-height:44px}',
        '.crm2-tab:hover{color:var(--t1)}.crm2-tab[aria-selected=true]{color:var(--t1);border-bottom-color:var(--p)}',
        '.crm2-tab .n{font-size:11px;font-weight:700;background:var(--ps);color:var(--pu);border-radius:999px;padding:1px 7px}',
        '.crm2-card{background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);}',
        '.crm2-card-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 18px 10px}',
        '.crm2-card-h h3{margin:0;font:600 15px var(--fb);color:var(--t1)}.crm2-card-h .more{font-size:12px;color:var(--t3)}',
        '.crm2-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}',
        '.crm2-kpi{padding:16px 18px}.crm2-kpi .l{font-size:12px;color:var(--t3);font-weight:600}.crm2-kpi .v{font:700 28px var(--fh);color:var(--t1);margin-top:6px}.crm2-kpi .h{font-size:12px;color:var(--t3);margin-top:2px}',
        '.crm2-grid2{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:16px;align-items:start}',
        '.crm2-rows{list-style:none;margin:0;padding:0 8px 8px}',
        '.crm2-row{display:flex;align-items:center;gap:12px;padding:10px;border-radius:10px;cursor:pointer}',
        '.crm2-row:hover{background:var(--s2)}.crm2-row+.crm2-row{border-top:1px solid var(--bd)}',
        '.crm2-av{width:36px;height:36px;border-radius:50%;background:var(--ps);color:var(--pu);display:flex;align-items:center;justify-content:center;font:700 13px var(--fb);flex-shrink:0}',
        '.crm2-row .main{flex:1;min-width:0}.crm2-row .nm{font-weight:600;color:var(--t1);font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
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
        '.crm2-table .who{display:flex;align-items:center;gap:12px;min-width:0}.crm2-table .who .nm{color:var(--t1);font-weight:600}',
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
        '.crm2-bc:hover{border-color:var(--bd2)}.crm2-bc .nm{font-weight:600;color:var(--t1);font-size:14px}.crm2-bc .meta{font-size:12px;color:var(--t3);margin-top:4px}',
        '.crm2-bc .foot{display:flex;align-items:center;justify-content:space-between;margin-top:10px;gap:8px}',
        '.crm2-rec{display:grid;grid-template-columns:300px minmax(0,1fr) 300px;gap:16px;align-items:start}',
        '.crm2-rec-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}',
        '.crm2-rec-name{display:flex;align-items:center;gap:14px}.crm2-rec-name .crm2-av{width:52px;height:52px;font-size:17px}',
        '.crm2-rec-name h2{margin:0;font:700 24px var(--fh);color:var(--t1)}.crm2-rec-name .sub{font-size:13px;color:var(--t3);margin-top:4px}',
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
        '.crm2-tl .dot.h{background:var(--ps);color:var(--pu)}.crm2-tl .b{flex:1;min-width:0}.crm2-tl .t{font-size:13px;color:var(--t1);font-weight:600}',
        '.crm2-tl .d{font-size:13px;color:var(--t2);margin-top:3px;white-space:pre-wrap;word-break:break-word}.crm2-tl .w{font-size:12px;color:var(--t3);white-space:nowrap}',
        '.crm2-tl .x{appearance:none;border:0;background:none;color:var(--t3);cursor:pointer;padding:4px;border-radius:6px;min-width:32px;min-height:32px}.crm2-tl .x:hover{color:var(--rd);background:var(--s2)}',
        '.crm2-task{display:flex;align-items:flex-start;gap:10px;padding:8px 0}.crm2-task+.crm2-task{border-top:1px solid var(--bd)}.crm2-task .t{font-size:13px;color:var(--t1)}.crm2-task .w{font-size:12px;color:var(--t3)}.crm2-task .w.late{color:var(--rd)}',
        '.crm2-bars{padding:6px 18px 16px}.crm2-bar{display:grid;grid-template-columns:130px minmax(0,1fr) 44px;gap:12px;align-items:center;padding:6px 0;font-size:13px}',
        '.crm2-bar .l{color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crm2-bar .tr{height:10px;background:var(--s2);border-radius:999px;overflow:hidden}',
        '.crm2-bar .f{height:100%;background:var(--p);border-radius:999px}.crm2-bar .n{text-align:right;color:var(--t1);font-weight:600}',
        '.crm2-packs{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}',
        '.crm2-pack{text-align:left;appearance:none;background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:14px;cursor:pointer;color:var(--t2);font:13px var(--fb);min-height:44px}',
        '.crm2-pack b{display:block;color:var(--t1);font-size:14px;margin-bottom:4px}.crm2-pack[aria-pressed=true]{border-color:var(--p);box-shadow:0 0 0 1px var(--p)}',
        '.crm2-sheet-list{display:flex;flex-direction:column;gap:6px}.crm2-sheet-list button{justify-content:flex-start}',
        '.crm2-note{font-size:12px;color:var(--t3)}',
        '.crm2-hide-d{display:none}',
        '@media (max-width:1180px){.crm2-rec{grid-template-columns:280px minmax(0,1fr)}.crm2-rec .rc{grid-column:1 / -1;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}}',
        '@media (max-width:900px){.crm2-grid2{grid-template-columns:minmax(0,1fr)}.crm2-grid2>*{min-width:0}.crm2-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}',
        '@media (max-width:760px){',
        '  .crm2-title{font-size:24px}.crm2-head{margin-top:0}.crm2-head-actions{width:100%}.crm2-biz{flex:1 1 100%;max-width:none;min-width:0}',
        '  .crm2-rec{grid-template-columns:1fr}.crm2-rec .lc{order:2}.crm2-rec .mc{order:1}.crm2-rec .rc{order:3;display:flex;flex-direction:column}',
        '  .crm2-tablewrap{display:none}.crm2-cards{display:flex;flex-direction:column;gap:10px}',
        '  .crm2-mc{background:var(--s1);border:1px solid var(--bd);border-radius:14px;padding:14px;display:flex;gap:12px;align-items:flex-start}',
        '  .crm2-mc .main{flex:1;min-width:0}.crm2-mc .nm{font-weight:600;color:var(--t1);font-size:15px}.crm2-mc .meta{font-size:13px;color:var(--t3);margin-top:4px}',
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
async function loadToday() { S.today = await api('GET', '/today-v2' + qs(bizQ())); }
async function loadReport() { S.report = await api('GET', '/reports-v2' + qs(Object.assign({days: S.days}, bizQ()))); }
async function loadRecord(id) { S.rec = await api('GET', '/clients/' + id); S.recId = id; }

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
    return '<div class="crm2-views" role="toolbar" aria-label="Ready lists">' + chips + '</div>' +
        '<div class="crm2-toolbar"><div class="crm2-search">' + I('search', 16) + '<input class="form-input" type="search" id="crm2-q" placeholder="Search ' + esc(w.many.toLowerCase()) + '" aria-label="Search by name, email or phone" value="' + esc(L.search) + '" onkeydown="if(event.key===\'Enter\')window._crm2.search(this.value)" onsearch="window._crm2.search(this.value)"></div>' +
        (S.biz && S.biz !== 'none' ? '<select class="form-select" style="max-width:190px" aria-label="Stage" onchange="window._crm2.filter(\'stage\',this.value)">' + stageOpts + '</select>' : '') +
        '<select class="form-select" style="max-width:170px" aria-label="Channel" onchange="window._crm2.filter(\'channel\',this.value)">' + chOpts + '</select>' +
        '<select class="form-select" style="max-width:190px" aria-label="Sort" onchange="window._crm2.sort(this.value)">' + sortOpts + '</select>' +
        '<span style="flex:1"></span><button class="btn btn-outline btn-sm" onclick="window._crm2.exportCsv()">' + I('download', 14) + ' Export</button></div>' + bulk +
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
        var ic = {note: 'edit', call: 'phone', email: 'mail', meeting: 'calendar', task: 'check', booked: 'calendar'}[a.type] || (a.type === 'repeat_enquiry' ? 'message' : 'clock');
        var lab = {note: 'Note', call: 'Call', email: 'Email', meeting: 'Meeting', task: 'Task', status_changed: 'Stage changed', lead_created: 'Added', repeat_enquiry: 'Came back', assigned: 'Assigned', form_submission: 'Form', booked: 'Booking'}[a.type] || 'History';
        return '<li><div class="dot' + (human ? ' h' : '') + '" aria-hidden="true">' + I(ic, 14) + '</div><div class="b"><div class="t">' + esc(lab) + (a.type === 'task' ? (a.status === 'done' ? ' · done' : (a.due_date ? ' · due ' + esc(when(a.due_date)) : '')) : '') + '</div>' +
            '<div class="d">' + esc(a.type === 'lead_created' ? 'Added to ' + p.many : a.title) + (a.description ? '\n' + esc(a.description) : '') + '</div></div><div class="w">' + esc(ago(a.created_at)) +
            (human ? '<br><button class="x" aria-label="Delete this ' + esc(lab.toLowerCase()) + '" onclick="window._crm2.delAct(\'' + a.id + '\')">' + I('delete', 14) + '</button>' : '') + '</div></li>';
    }).join('');
    var mid = '<div class="mc">' + summary +
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
        '<section class="crm2-card"><div class="crm2-sec"><h4>Bookings</h4>' + (appts || '<div class="crm2-note">No bookings.</div>') + '<button class="btn btn-outline btn-sm" style="width:100%;margin-top:12px" onclick="window._crm2.book()">' + I('calendar', 14) + ' Book ' + esc(c.name.split(' ')[0]) + '</button><div class="crm2-note" style="margin-top:8px">Bookings also show in your Calendar.</div></div></section>' +
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
        '<div class="crm2-grid2"><section class="crm2-card"><div class="crm2-card-h"><h3>Where they came from</h3></div>' + (chBars ? '<div class="crm2-bars">' + chBars + '</div>' : '<div class="crm2-empty">No enquiries in this period.</div>') + '</section>' +
        '<section class="crm2-card"><div class="crm2-card-h"><h3>Where they are now</h3></div>' + (stBars ? '<div class="crm2-bars">' + stBars + '</div>' : '<div class="crm2-empty">Nothing yet.</div>') + '</section></div>';
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
        '<section class="crm2-card"><div class="crm2-sec"><h4>Stages</h4>' + p.stages.map(function (s, i) { return '<div class="crm2-task"><span class="crm2-pill ' + stageTone(p, s.key) + '">' + (i + 1) + '</span><div class="t">' + esc(s.name) + '</div></div>'; }).join('') + '</div>' +
        '<div class="crm2-sec"><h4>Details kept about each ' + esc(p.one.toLowerCase()) + '</h4>' + p.fields.map(function (f) { return '<div class="crm2-task"><div class="t">' + esc(f.label) + '</div></div>'; }).join('') + '<div class="crm2-note" style="margin-top:8px">Your own stages and fields come next.</div></div></section></div>';
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
    book: function () {
        var c = S.rec.client, p = S.rec.pack;
        var word = {property: 'Viewing', stays: 'Booking', projects: 'Consultation', enrolment: 'Trial', guests: 'Reservation'}[p.key] || 'Appointment';
        var d = new Date(Date.now() + 86400000), pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        var day = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        modal('Book ' + c.name, fld('bt', 'What', 'text', word + ' — ' + c.name, ' required maxlength="150"') +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' + fld('bd', 'Day', 'date', day, ' required') + fld('bh', 'Time', 'time', '10:00', ' required') + '</div>' +
            '<div class="crm2-field" style="margin:0"><label for="bl">How long</label><select class="form-select" id="bl" name="bl">' + [[30, '30 minutes'], [45, '45 minutes'], [60, '1 hour'], [90, '1½ hours'], [120, '2 hours'], [240, 'Half a day']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === 60 ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></div>' +
            '<div class="crm2-note">It goes into your Calendar and onto ' + esc(c.name.split(' ')[0]) + '\'s timeline. Nothing is sent to them automatically.</div>',
            async function (f) {
                var start = new Date(f.bd.value + 'T' + f.bh.value); if (isNaN(start)) throw new Error('Pick a day and time.');
                var end = new Date(start.getTime() + parseInt(f.bl.value, 10) * 60000);
                var fmt = function (x) { return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate()) + ' ' + pad(x.getHours()) + ':' + pad(x.getMinutes()) + ':00'; };
                var res = await fetch('/api/calendar/events', {method: 'POST', headers: hdr(), body: JSON.stringify({title: f.bt.value.trim(), starts_at: fmt(start), ends_at: fmt(end), category: 'appointment', engine: 'crm', reference_type: 'Lead', reference_id: c.id, business_id: c.business_id || undefined})});
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
window.crmLoad = async function (el) {
    if (!el) return;
    busy(el);
    try { await loadSetup(); } catch (e) {
        el.innerHTML = '<div class="crm2-empty"><b>Clients could not load.</b>' + esc(e.message) + '<div style="margin-top:12px"><button class="btn btn-outline" onclick="window.crmLoad(document.getElementById(\'crm-root\'))">Try again</button></div></div>';
        return;
    }
    await go('today');
};
window._crmOpenDetail = function (id) { return window._crm2.open(parseInt(id)); };
console.log('[Clients] CRM-UX-2 loaded');
})();
