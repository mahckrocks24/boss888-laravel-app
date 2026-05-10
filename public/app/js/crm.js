/**
 * LevelUp CRM Engine v3.6.0
 * Phase 8: Pipeline Kanban + Lead Scoring
 *
 * New:
 *   ${window.icon('chart',14)} Pipeline tab  — Kanban board, one column per stage
 *   Drag & drop lead cards between columns (native HTML5 drag API)
 *   On drop: PUT /lucrm/v1/leads/{id} → pipeline_stage_id updated
 *   After drop: POST /lucrm/v1/leads/{id}/score → score recalculated
 *   Score badges on every lead card: ${window.icon('info',14)} Hot (80+) · ${window.icon('info',14)} Warm (40-79) · ${window.icon('info',14)} Cold (<40)
 *   Score badges also visible in Leads table
 *   Batch recalculate button on Pipeline tab
 *   Auto-recalculate after: stage change · activity added · appointment added
 */

// ── Claim engine slot ─────────────────────────────────────────────────────────
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['crm'] = true;
console.log('[LuCRM] v3.6.0 — engine slot claimed');

// ── State ─────────────────────────────────────────────────────────────────────
var _crm = {
    leads:        [],
    projects:     [],
    stages:       [],
    modules:      [],
    settings:     {},
    dash:         null,
    tab:          'dashboard',
    detailLeadId: null,
    activities:   [],
    tasks:        [],
    taskFlags:    {},
    appointments: [],
    // Phase 8
    dragLeadId:   null,   // id of lead being dragged
};

// ── Helpers ───────────────────────────────────────────────────────────────────
function _crmUrl(p) {
    var b = (window.LU_API_BASE || '/api');
    return '/api/crm' + p;
}
function _crmNonce() { return (window.LU_CFG && '') || ''; }
async function _crmGet(path) {
    var res = await fetch(_crmUrl(path), { method:'GET', headers:{ 'Content-Type':'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')} });
    var body; try { body = await res.json(); } catch(e) { body = {}; }
    if (!res.ok) throw new Error((body && body.message) || 'HTTP ' + res.status);
    return body;
}
function _e(s) { return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Date helpers ──────────────────────────────────────────────────────────────
function _today() { return new Date().toISOString().substring(0,10); }
function _fmtDate(dt) { if(!dt)return'—'; var d=new Date((dt+'').replace(' ','T')); return isNaN(d.getTime())?dt:d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function _fmtDateTime(dt) { if(!dt)return'—'; var d=new Date((dt+'').replace(' ','T')); return isNaN(d.getTime())?dt:d.toLocaleDateString('en-US',{month:'short',day:'numeric'})+' '+d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); }
function _fmtTime(dt) { if(!dt)return''; var d=new Date((dt+'').replace(' ','T')); return isNaN(d.getTime())?'':d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); }
function _dateKey(dt) { return dt?(dt+'').substring(0,10):'Unknown'; }
function _relDate(k) { var t=_today(),y=new Date(Date.now()-86400000).toISOString().substring(0,10); if(k===t)return'Today'; if(k===y)return'Yesterday'; return new Date(k+'T00:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'}); }
function _dueStatus(dd) { if(!dd)return'none'; var t=_today(),d=(dd+'').substring(0,10); return d<t?'overdue':d===t?'today':'upcoming'; }
function _dueBadge(dd,status) {
    if(status==='done'||!dd)return '';
    var ds=_dueStatus(dd);
    if(ds==='overdue')return '<span style="font-size:10px;background:rgba(248,113,113,.2);color:var(--rd);padding:2px 6px;border-radius:4px;font-weight:600;margin-left:5px">Overdue</span>';
    if(ds==='today')  return '<span style="font-size:10px;background:rgba(245,158,11,.2);color:var(--am);padding:2px 6px;border-radius:4px;font-weight:600;margin-left:5px">Due Today</span>';
    return '<span style="font-size:10px;color:var(--t3);margin-left:5px">'+_fmtDate(dd)+'</span>';
}

// ── Score badge ───────────────────────────────────────────────────────────────
function _scoreBadge(score) {
    var n = parseInt(score) || 0;
    if (n >= 80) return '<span style="font-size:11px;font-weight:700;color:var(--rd)" title="Hot lead — score '+n+'">'+window.icon("info",14)+' '+n+'</span>';
    if (n >= 40) return '<span style="font-size:11px;font-weight:700;color:var(--am)" title="Warm lead — score '+n+'">'+window.icon("info",14)+' '+n+'</span>';
    return        '<span style="font-size:11px;font-weight:700;color:var(--t3)" title="Cold lead — score '+n+'">'+window.icon("info",14)+' '+n+'</span>';
}

// ── Metadata ──────────────────────────────────────────────────────────────────
var _priMeta = { high:{icon:''+window.icon("info",14)+'',label:'High',color:'var(--rd)'}, medium:{icon:''+window.icon("info",14)+'',label:'Medium',color:'var(--am)'}, low:{icon:''+window.icon("info",14)+'',label:'Low',color:'var(--t3)'} };
var _actMeta = { note:{icon:''+window.icon("edit",14)+'',label:'Note',color:'var(--bl)'}, call:{icon:''+window.icon("message",14)+'',label:'Call',color:'var(--ac)'}, email:{icon:''+window.icon("message",14)+'',label:'Email',color:'var(--pu)'}, meeting:{icon:''+window.icon("more",14)+'',label:'Meeting',color:'var(--am)'}, task:{icon:''+window.icon("check",14)+'',label:'Task',color:'var(--p)'} };
function _priBadge(p) { var m=_priMeta[p]||_priMeta.medium; return '<span style="font-size:10px;color:'+m.color+';font-weight:600">'+m.icon+' '+m.label+'</span>'; }

function _buildTaskFlags(tasks) {
    var f={}, t=_today();
    tasks.forEach(function(tk){ if(tk.status==='done')return; var lid=String(tk.lead_id); if(!f[lid])f[lid]={overdue:false,today:false}; var d=tk.due_date?(tk.due_date+'').substring(0,10):null; if(d&&d<t)f[lid].overdue=true; if(d&&d===t)f[lid].today=true; });
    return f;
}
function _modEnabled(slug) { return _crm.modules.some(function(m){return m.slug===slug&&(m.enabled||m.required);}); }

// =============================================================================
// CORE ENTRY POINT
// =============================================================================
window.crmLoad = async function(el) {
    if (!el) { console.error('[LuCRM] crmLoad: no element'); return; }
    console.log('[LuCRM] crmLoad() → #'+el.id);

    _crm.leads=[]; _crm.projects=[]; _crm.stages=[]; _crm.modules=[]; _crm.settings={}; _crm.dash=null;
    _crm.tab='dashboard'; _crm.detailLeadId=null; _crm.activities=[]; _crm.tasks=[]; _crm.taskFlags={}; _crm.appointments=[]; _crm.dragLeadId=null;

    el.innerHTML = loadingCard(300);

    var dash    = await _crmGet('/dashboard').catch(function(e){console.warn('[LuCRM] dash:',e.message);return null;});
    var stages  = await _crmGet('/pipeline/stages').catch(function(){return [];});
    var leads   = await _crmGet('/leads').catch(function(){return {leads:[]};});
    var contacts= await _crmGet('/contacts').catch(function(){return {contacts:[]};});  // PATCH 10 Fix 8
    var modules = await _crmGet('/modules').catch(function(){return {};});
    var settings= await _crmGet('/settings').catch(function(){return {};});
    var tasks   = await _crmGet('/tasks?status=pending').catch(function(){return {tasks:[]};});
    var appts   = await _crmGet('/appointments?upcoming=1').catch(function(){return {appointments:[]};});

    _crm.dash     = dash;
    _crm.stages   = Array.isArray(stages) ? stages : [];

    // PATCH 10 Fix 8 — surface website-form contacts in the Leads list.
    // PublicContactController::submit (T3.2) writes form submissions to the
    // `contacts` table, NOT `leads`. Without this merge, customers' inbound
    // leads from their own website never appeared in the CRM Leads UI.
    // Adapt contact rows to lead shape + tag with source='website_form' so
    // the existing renderer treats them as leads, with a visible source badge.
    var rawLeads = (leads && leads.leads) ? leads.leads : [];
    var rawContacts = (contacts && (contacts.contacts || contacts.data)) ? (contacts.contacts || contacts.data) : (Array.isArray(contacts) ? contacts : []);
    var contactsAsLeads = (rawContacts || []).map(function(c){
      return {
        id: 'contact_' + c.id,
        _origin: 'contact',
        _origin_id: c.id,
        first_name: c.first_name || (c.name ? c.name.split(' ')[0] : ''),
        last_name:  c.last_name  || (c.name ? c.name.split(' ').slice(1).join(' ') : ''),
        name:       c.name || ([c.first_name, c.last_name].filter(Boolean).join(' ')),
        email:      c.email,
        phone:      c.phone,
        source:     c.source || 'website_form',
        status:     c.status || 'new',
        notes:      c.notes,
        created_at: c.created_at,
        updated_at: c.updated_at
      };
    });
    _crm.leads = rawLeads.concat(contactsAsLeads);

    _crm.modules  = Object.entries(modules||{}).map(function(p){return Object.assign({slug:p[0]},p[1]);});
    _crm.settings = settings||{};
    _crm.tasks    = (tasks&&tasks.tasks) ? tasks.tasks : [];
    _crm.taskFlags= _buildTaskFlags(_crm.tasks);
    _crm.appointments = (appts&&appts.appointments) ? appts.appointments : [];

    console.log('[LuCRM] ready — leads:',_crm.leads.length,' stages:',_crm.stages.length);
    try { _crmRender(el); } catch(e) {
        console.error('[LuCRM] render error:',e);
        el.innerHTML='<div style="padding:60px;text-align:center"><div style="color:var(--rd);font-weight:600">'+_e(e.message)+'</div>'+
            '<button class="btn btn-outline btn-sm" style="margin-top:12px" onclick="window.crmLoad(document.getElementById(\'crm-root\'))">↺ Retry</button></div>';
    }
};

// =============================================================================
// RENDER
// =============================================================================
function _crmRender(el) {
    if (!el) el = document.getElementById('crm-root');
    if (!el) { console.error('[LuCRM] render: #crm-root not found'); return; }

    if (_crm.tab==='detail'&&_crm.detailLeadId) { _renderDetail(el); return; }

    var hasPrj  = _modEnabled('projects');
    var hasAppt = _modEnabled('appointments');
    if (_crm.tab==='projects'&&!hasPrj)     _crm.tab='dashboard';
    if (_crm.tab==='appointments'&&!hasAppt) _crm.tab='dashboard';

    var pendingCnt = _crm.tasks.filter(function(t){return t.status==='pending';}).length;
    var todayCnt   = _crm.tasks.filter(function(t){var ds=_dueStatus(t.due_date);return(ds==='overdue'||ds==='today')&&t.status!=='done';}).length;

    var tabs = [
        {id:'dashboard', icon:''+window.icon("chart",14)+'', label:'Dashboard'},
        {id:'today',     icon:''+window.icon("clock",14)+'', label:'Today'+(todayCnt?' ('+todayCnt+')':''), warn:todayCnt>0},
        {id:'leads',     icon:''+window.icon("more",14)+'', label:'Leads ('+_crm.leads.length+')'},
        {id:'pipeline',  icon:''+window.icon("chart",14)+'', label:'Pipeline'},
        {id:'tasks',     icon:''+window.icon("more",14)+'', label:'Tasks'+(pendingCnt?' ('+pendingCnt+')':'')},
    ];
    if (hasAppt) tabs.push({id:'appointments',icon:''+window.icon("calendar",14)+'',label:'Appointments'});
    if (hasPrj)  tabs.push({id:'projects',    icon:''+window.icon("more",14)+'',label:'Projects ('+_crm.projects.length+')'});
    tabs.push({id:'settings',icon:''+window.icon("edit",14)+'',label:'Settings'});

    var tabsHtml = tabs.map(function(t){
        return '<button class="tab'+(_crm.tab===t.id?' active':'')+'" onclick="window._crmTab(\''+t.id+'\')"'+(t.warn?' style="color:var(--rd)"':'')+'>'+t.icon+' '+t.label+'</button>';
    }).join('');

    var body = '';
    switch(_crm.tab) {
        case 'dashboard':    body=_dashHtml();     break;
        case 'today':        body=_todayHtml();    break;
        case 'leads':        body=_leadsHtml();    break;
        case 'pipeline':     body=_pipelineHtml(); break;
        case 'tasks':        body=_tasksHtml();    break;
        case 'appointments': body=_apptHtml();     break;
        case 'projects':     body=_prjHtml();      break;
        case 'settings':     body=_setHtml();      break;
        default:             body=_dashHtml();     break;
    }

    el.innerHTML =
        '<div style="padding:24px;min-height:100%;box-sizing:border-box">' +
            '<div class="page-header" style="margin-top:10px"><div class="page-header-left"><h1>CRM</h1></div></div>' +
            '<div class="crm-tab-bar" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;border-bottom:1px solid var(--bd);padding-bottom:16px">'+tabsHtml+'</div>' +
            body +
        '</div>';
}

window._crmTab = async function(tab) {
    _crm.tab=tab; _crm.detailLeadId=null; _crm.activities=[];
    var el=document.getElementById('crm-root');
    if(tab==='tasks'||tab==='today') {
        if(el)el.innerHTML=loadingCard(200);
        var d=await _crmGet('/tasks?status=pending').catch(function(){return null;});
        _crm.tasks=(d&&d.tasks)?d.tasks:[]; _crm.taskFlags=_buildTaskFlags(_crm.tasks);
    }
    if(tab==='appointments') {
        if(el)el.innerHTML=loadingCard(200);
        var a=await _crmGet('/appointments?upcoming=1').catch(function(){return null;});
        _crm.appointments=(a&&a.appointments)?a.appointments:[];
    }
    if(tab==='projects'&&!_crm.projects.length) {
        if(el)el.innerHTML=loadingCard(200);
        var p=await _crmGet('/projects').catch(function(){return null;});
        _crm.projects=(p&&p.projects)?p.projects:[];
    }
    try{_crmRender(el);}catch(e){console.error('[LuCRM] tab render:',e);}
};

async function _crmReload(tab) {
    var el=document.getElementById('crm-root'); if(!el)return;
    if(tab)_crm.tab=tab;
    var d=await _crmGet('/leads').catch(function(e){console.error('[LuCRM] reload leads:',e.message);showToast('Could not reload leads.','error');return null;});
    if(d&&d.leads)_crm.leads=d.leads;
    var dsh=await _crmGet('/dashboard').catch(function(){return null;}); if(dsh)_crm.dash=dsh;
    var td=await _crmGet('/tasks?status=pending').catch(function(){return null;});
    if(td&&td.tasks){_crm.tasks=td.tasks;_crm.taskFlags=_buildTaskFlags(_crm.tasks);}
    try{_crmRender(el);}catch(e){console.error('[LuCRM] reload render:',e);}
}

// =============================================================================
// PIPELINE KANBAN
// =============================================================================
function _pipelineHtml() {
    var stages  = _crm.stages || [];
    var leads   = _crm.leads  || [];

    if (!stages.length) {
        return '<div style="padding:40px;text-align:center;color:var(--t3)">No pipeline stages configured.</div>';
    }

    // Total leads + score summary
    var hot  = leads.filter(function(l){return parseInt(l.score||0)>=80;}).length;
    var warm = leads.filter(function(l){var s=parseInt(l.score||0);return s>=40&&s<80;}).length;
    var cold = leads.filter(function(l){return parseInt(l.score||0)<40;}).length;

    var summary =
        '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">' +
            '<div style="display:flex;gap:12px;font-size:12px">' +
                '<span><span style="color:var(--rd);font-weight:700">'+window.icon("info",14)+' Hot</span> <span style="color:var(--t1)">'+hot+'</span></span>' +
                '<span><span style="color:var(--am);font-weight:700">'+window.icon("info",14)+' Warm</span> <span style="color:var(--t1)">'+warm+'</span></span>' +
                '<span><span style="color:var(--t3);font-weight:700">'+window.icon("info",14)+' Cold</span> <span style="color:var(--t1)">'+cold+'</span></span>' +
            '</div>' +
            '<button class="btn btn-outline btn-sm" id="recalc-btn" onclick="window._batchRecalcScore()">↺ Recalculate Scores</button>' +
        '</div>';

    // One column per stage
    var cols = stages.map(function(stage) {
        var stageLeads = leads.filter(function(l) {
            return String(l.pipeline_stage_id) === String(stage.id);
        });

        var isClosed  = parseInt(stage.is_closed);
        var isWon     = isClosed && (stage.name||'').toLowerCase().indexOf('won')  !== -1;
        var isLost    = isClosed && (stage.name||'').toLowerCase().indexOf('lost') !== -1;
        var hdrColor  = isWon ? 'var(--ac)' : isLost ? 'var(--rd)' : 'var(--p)';
        var hdrBg     = isWon ? 'rgba(0,229,168,.1)' : isLost ? 'rgba(248,113,113,.1)' : 'rgba(108,92,231,.1)';

        var cards = stageLeads.map(function(l) {
            return _leadCard(l, stage);
        }).join('');

        var emptySlot = !cards ?
            '<div style="padding:20px;text-align:center;color:var(--t3);font-size:12px;border:2px dashed var(--bd);border-radius:8px;margin:4px 0">Drop here</div>' : '';

        return '<div style="min-width:200px;max-width:220px;flex-shrink:0">' +
            // Column header
            '<div style="background:'+hdrBg+';border-radius:8px 8px 0 0;padding:10px 12px;margin-bottom:0;border:1px solid var(--bd);border-bottom:none">' +
                '<div style="display:flex;justify-content:space-between;align-items:center">' +
                    '<span style="font-size:12px;font-weight:700;color:'+hdrColor+';text-transform:uppercase;letter-spacing:.06em">'+_e(stage.name)+'</span>' +
                    '<span style="font-size:11px;font-weight:600;background:'+hdrBg+';color:'+hdrColor+';padding:2px 7px;border-radius:10px">'+stageLeads.length+'</span>' +
                '</div>' +
            '</div>' +
            // Drop zone
            '<div class="crm-kanban-col" data-stage-id="'+stage.id+'" ' +
                'style="min-height:120px;background:var(--s1);border:1px solid var(--bd);border-radius:0 0 8px 8px;padding:8px;overflow-y:auto;max-height:calc(100vh - 300px)" ' +
                'ondragover="event.preventDefault();this.style.background=\'rgba(108,92,231,.07)\'" ' +
                'ondragleave="this.style.background=\'var(--s1)\'" ' +
                'ondrop="window._kanbanDrop(event,'+stage.id+')">' +
                    cards + emptySlot +
            '</div>' +
        '</div>';
    }).join('');

    return summary +
        '<div style="display:flex;gap:12px;overflow-x:auto;padding-bottom:12px;align-items:flex-start">' +
            cols +
        '</div>' +
        '<div style="font-size:11px;color:var(--t3);margin-top:8px;text-align:center">Drag and drop lead cards to move between stages</div>';
}

function _leadCard(lead, stage) {
    var score   = parseInt(lead.score) || 0;
    var flags   = _crm.taskFlags[String(lead.id)] || {};
    var dueFlag = flags.overdue ? ''+window.icon("info",14)+'' : flags.today ? ''+window.icon("info",14)+'' : '';
    var isDragging = String(_crm.dragLeadId) === String(lead.id);

    return '<div class="crm-kanban-card" ' +
        'draggable="true" ' +
        'data-lead-id="'+lead.id+'" ' +
        'style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;margin-bottom:8px;cursor:grab;transition:all .15s;opacity:'+(isDragging?'0.4':'1')+'" ' +
        'ondragstart="window._kanbanDragStart(event,'+lead.id+')" ' +
        'ondragend="window._kanbanDragEnd(event)" ' +
        'onmouseover="this.style.borderColor=\'var(--p)\';this.style.background=\'var(--s3)\'" ' +
        'onmouseout="this.style.borderColor=\'var(--bd)\';this.style.background=\'var(--s2)\'">' +

        // Name + task flag
        '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' +
            _e(lead.name) + (dueFlag ? ' <span title="Overdue/due task">'+dueFlag+'</span>' : '') +
        '</div>' +

        // Company
        (lead.company ? '<div style="font-size:11px;color:var(--t3);margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+_e(lead.company)+'</div>' : '') +

        // Source
        (lead.source_website ? '<div style="font-size:11px;color:var(--ac);margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+_e(lead.source_website)+'</div>' : '') +

        // Score badge
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">' +
            _scoreBadge(score) +
            '<button class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 6px;color:var(--t3)" ' +
                'onclick="event.stopPropagation();window._crmOpenDetail('+lead.id+')" title="View lead">'+window.icon("eye",14)+'</button>' +
        '</div>' +
    '</div>';
}

// ── Drag & Drop handlers ──────────────────────────────────────────────────────
window._kanbanDragStart = function(event, leadId) {
    _crm.dragLeadId = leadId;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(leadId));
    // Slight delay so the drag ghost renders before we change opacity
    setTimeout(function() {
        var el = document.getElementById('crm-root');
        try { _crmRender(el); } catch(e) {}
    }, 0);
};

window._kanbanDragEnd = function(event) {
    _crm.dragLeadId = null;
    // Remove all drag-hover highlights
    document.querySelectorAll('.crm-kanban-col').forEach(function(col) {
        col.style.background = 'var(--s1)';
    });
};

window._kanbanDrop = async function(event, stageId) {
    event.preventDefault();
    var col = event.currentTarget;
    col.style.background = 'var(--s1)';

    var leadId = parseInt(event.dataTransfer.getData('text/plain')) || _crm.dragLeadId;
    _crm.dragLeadId = null;

    if (!leadId || !stageId) return;

    // Check lead already in this stage
    var lead = _crm.leads.find(function(l){ return l.id == leadId; });
    if (!lead) return;
    if (String(lead.pipeline_stage_id) === String(stageId)) return;

    console.log('[LuCRM] Kanban drop: lead', leadId, '→ stage', stageId);

    // Optimistic update
    lead.pipeline_stage_id = stageId;
    var el = document.getElementById('crm-root');
    try { _crmRender(el); } catch(e) {}

    // PUT to API
    var url   = _crmUrl('/leads/' + leadId);
    var nonce = _crmNonce();
    var res;
    try {
        res = await fetch(url, {
            method:  'PUT',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},
            body:    JSON.stringify({ pipeline_stage_id: parseInt(stageId) }),
        });
    } catch(e) {
        console.error('[LuCRM] kanban drop fetch error:', e);
        showToast('Network error moving lead.', 'error');
        // Revert
        await _crmReload('pipeline');
        return;
    }

    if (!res.ok) {
        showToast('Could not move lead.', 'error');
        await _crmReload('pipeline');
        return;
    }

    console.log('[LuCRM] Stage updated ✓ — recalculating score…');

    // Recalculate score server-side
    var scoreRes = await fetch(_crmUrl('/leads/' + leadId + '/score'), {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},
        body:    '{}',
    }).catch(function() { return null; });

    if (scoreRes && scoreRes.ok) {
        var scoreData;
        try { scoreData = await scoreRes.json(); } catch(e) { scoreData = null; }
        if (scoreData && typeof scoreData.score !== 'undefined') {
            lead.score = scoreData.score;
            console.log('[LuCRM] Score updated → ', scoreData.score);
        }
    }

    try { _crmRender(el); } catch(e) {}
    showToast('Lead moved!', 'success');
};

// ── Batch recalculate all scores ──────────────────────────────────────────────
window._batchRecalcScore = async function() {
    var btn = document.getElementById('recalc-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Recalculating…'; }

    var nonce = _crmNonce();
    var res;
    try {
        res = await fetch(_crmUrl('/scores/recalculate'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},
            body:    '{}',
        });
    } catch(e) { showToast('Network error.', 'error'); return; }

    var body; try { body = await res.json(); } catch(e) { body = {}; }

    if (!res.ok) { showToast('Recalculate failed.', 'error'); return; }
    showToast('Scores updated for ' + (body.updated || 0) + ' leads!', 'success');

    // Reload leads to get fresh scores
    var d = await _crmGet('/leads').catch(function(){ return null; });
    if (d && d.leads) _crm.leads = d.leads;
    var el = document.getElementById('crm-root');
    try { _crmRender(el); } catch(e) {}
};

// ── Recalculate score for current lead after mutations ────────────────────────
async function _recalcCurrentScore(leadId) {
    if (!leadId) return;
    var nonce = _crmNonce();
    try {
        var res = await fetch(_crmUrl('/leads/' + leadId + '/score'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},
            body: '{}',
        });
        if (res.ok) {
            var d; try { d = await res.json(); } catch(e) { d = null; }
            if (d && typeof d.score !== 'undefined') {
                var lead = _crm.leads.find(function(l){ return l.id == leadId; });
                if (lead) { lead.score = d.score; console.log('[LuCRM] Score recalculated →', d.score); }
            }
        }
    } catch(e) { console.warn('[LuCRM] Score recalc failed:', e.message); }
}

// =============================================================================
// DASHBOARD
// =============================================================================
function _dashHtml() {
    var d=_crm.dash||{};
    var total=parseInt(d.total_leads)||0;
    var stgs=Array.isArray(d.leads_by_stage)?d.leads_by_stage:[];
    var srcs=Array.isArray(d.leads_by_source)?d.leads_by_source:[];
    var todayTasks=Array.isArray(d.today_tasks)?d.today_tasks:[];
    var upAppts=Array.isArray(d.upcoming_appointments)?d.upcoming_appointments:[];
    var maxCnt=stgs.reduce(function(m,s){return Math.max(m,parseInt(s.count)||0);},1);
    var overdueCnt=_crm.tasks.filter(function(t){return _dueStatus(t.due_date)==='overdue'&&t.status!=='done';}).length;
    var todayCnt=  _crm.tasks.filter(function(t){return _dueStatus(t.due_date)==='today'  &&t.status!=='done';}).length;
    var hot=_crm.leads.filter(function(l){return parseInt(l.score||0)>=80;}).length;
    var pipelineVal=_crm.leads.reduce(function(sum,l){return sum+(parseFloat(l.deal_value)||0);},0);
    var uniqSrc=[]; srcs.forEach(function(s){if(s.source_website&&uniqSrc.indexOf(s.source_website)===-1)uniqSrc.push(s.source_website);});

    var alert='';
    if(overdueCnt||todayCnt){
        alert='<div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px">' +
            '<span style="font-size:18px">'+window.icon("warning",14)+'</span><div style="flex:1">'+(overdueCnt?'<span style="color:var(--rd);font-weight:600;font-size:13px">'+overdueCnt+' overdue</span>':'')+(overdueCnt&&todayCnt?' · ':'')+
            (todayCnt?'<span style="color:var(--am);font-weight:600;font-size:13px">'+todayCnt+' due today</span>':'')+'</div>'+
            '<button class="btn btn-outline btn-sm" onclick="window._crmTab(\'today\')" style="font-size:11px">View Today →</button></div>';
    }

    var bars=stgs.map(function(s){ var pct=Math.round(((parseInt(s.count)||0)/maxCnt)*100);
        return '<div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px"><span style="color:var(--t2)">'+_e(s.name||'?')+'</span><span style="color:var(--p);font-weight:600">'+(s.count||0)+'</span></div>'+
            '<div style="background:var(--s3);height:5px;border-radius:3px"><div style="background:var(--p);height:100%;width:'+pct+'%;border-radius:3px"></div></div></div>';
    }).join('')||'<div style="color:var(--t3);font-size:12px;text-align:center;padding:20px">No data</div>';

    var taskRows=todayTasks.slice(0,5).map(function(t){ return '<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--bd);font-size:12px"><span style="cursor:pointer;color:var(--t1)" onclick="window._crmOpenDetail('+t.lead_id+')">'+_e(t.title)+'</span>'+_dueBadge(t.due_date,t.status)+'</div>'; }).join('')||'<div style="color:var(--t3);font-size:12px;text-align:center;padding:12px">No urgent tasks '+window.icon("star",14)+'</div>';
    var apptRows=upAppts.slice(0,4).map(function(a){ return '<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--bd);font-size:12px"><div><div style="color:var(--t1)">'+_e(a.title)+'</div><div style="color:var(--t3);font-size:11px">'+_e(a.lead_name||'')+'</div></div><span style="color:var(--ac);font-size:11px;white-space:nowrap">'+_fmtDateTime(a.start_at)+'</span></div>'; }).join('')||'<div style="color:var(--t3);font-size:12px;text-align:center;padding:12px">No upcoming appointments</div>';

    var pvLabel='$'+(pipelineVal>=1000000?(pipelineVal/1000000).toFixed(1)+'M':pipelineVal>=1000?(pipelineVal/1000).toFixed(1)+'K':pipelineVal.toFixed(0));
    return alert+
        '<div class="crm-kpi-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px">'+
            _stat('Total Leads',total,'var(--t1)')+_stat('Pipeline Value',pvLabel,'var(--ac)')+_stat('Hot Leads '+window.icon("info",14)+'',hot,'var(--rd)')+
            _stat('Sources',uniqSrc.length,'var(--t2)')+_stat('Tasks',_crm.tasks.length,overdueCnt?'var(--rd)':'var(--p)')+
        '</div>'+
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">'+
            '<div class="card" style="padding:20px"><h3 style="margin:0 0 12px;font-size:13px;font-weight:600">Leads by Stage</h3>'+bars+'</div>'+
            '<div class="card" style="padding:20px"><h3 style="margin:0 0 12px;font-size:13px;font-weight:600">Today\'s Tasks</h3>'+taskRows+(todayTasks.length>5?'<button class="btn btn-ghost btn-sm" style="margin-top:8px;width:100%;font-size:11px" onclick="window._crmTab(\'today\')">View all →</button>':'')+
            '</div>'+
        '</div>'+
        '<div class="card" style="padding:20px;margin-bottom:20px"><h3 style="margin:0 0 12px;font-size:13px;font-weight:600">Upcoming Appointments</h3>'+apptRows+
            '<button class="btn btn-outline btn-sm" style="margin-top:10px;width:100%;font-size:11px" onclick="window._crmTab(\'appointments\')">View All →</button></div>'+
        '<div style="display:flex;gap:10px">'+
            '<button class="btn btn-primary" onclick="window._crmTab(\'leads\')" style="flex:1">→ Leads</button>'+
            '<button class="btn btn-outline" onclick="window._crmTab(\'pipeline\')" style="flex:1">→ Pipeline</button>'+
            '<button class="btn btn-outline" onclick="window._crmTab(\'today\')" style="flex:1">→ Today</button>'+
        '</div>';
}
function _stat(l,v,c){return '<div class="card crm-kpi" style="padding:20px"><div class="crm-kpi-label" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:8px">'+l+'</div><div class="crm-kpi-value" style="font-size:36px;font-weight:700;color:'+c+'">'+v+'</div></div>';}

// =============================================================================
// TODAY VIEW
// =============================================================================
function _todayHtml() {
    var tasks=_crm.tasks||[], t=_today();
    var overdue =tasks.filter(function(tk){return(tk.due_date+'').substring(0,10)<t&&tk.status!=='done';});
    var dueToday=tasks.filter(function(tk){return(tk.due_date+'').substring(0,10)===t&&tk.status!=='done';});
    var highPri =tasks.filter(function(tk){return tk.priority==='high'&&(tk.due_date+'').substring(0,10)>t&&tk.status!=='done';});
    if(!overdue.length&&!dueToday.length&&!highPri.length) return '<div class="card" style="padding:60px;text-align:center;color:var(--t3)"><div style="font-size:40px;margin-bottom:12px">'+window.icon("star",14)+'</div><div style="font-size:16px;font-weight:600;color:var(--t1);margin-bottom:6px">You\'re all caught up!</div></div>';
    var html='';
    if(overdue.length)  html+='<div style="margin-bottom:20px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--rd);margin-bottom:10px">'+window.icon("info",14)+' Overdue ('+overdue.length+')</div><div class="card" style="overflow:hidden">'+_taskRows(overdue)+'</div></div>';
    if(dueToday.length) html+='<div style="margin-bottom:20px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--am);margin-bottom:10px">'+window.icon("info",14)+' Due Today ('+dueToday.length+')</div><div class="card" style="overflow:hidden">'+_taskRows(dueToday)+'</div></div>';
    if(highPri.length)  html+='<div style="margin-bottom:20px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--p);margin-bottom:10px">'+window.icon("rocket",14)+' High Priority — Upcoming ('+highPri.length+')</div><div class="card" style="overflow:hidden">'+_taskRows(highPri)+'</div></div>';
    return html;
}
function _taskRows(tasks){
    return tasks.map(function(t){var ds=_dueStatus(t.due_date);var bg=ds==='overdue'?'rgba(248,113,113,.04)':ds==='today'?'rgba(245,158,11,.04)':'';
        return '<div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid var(--bd);background:'+bg+'"><div style="flex:1"><div style="font-size:13px;font-weight:500;color:var(--t1)">'+_e(t.title)+'</div>'+
            '<div style="display:flex;gap:8px;margin-top:3px;align-items:center"><span style="font-size:11px;color:var(--ac);cursor:pointer" onclick="window._crmOpenDetail('+t.lead_id+')">'+_e(t.lead_name||'Lead #'+t.lead_id)+'</span>'+(t.due_date?_dueBadge(t.due_date,t.status):'')+' '+_priBadge(t.priority||'medium')+'</div></div>'+
            '<div style="display:flex;gap:6px"><button class="btn btn-outline btn-sm" style="font-size:11px;color:var(--ac)" onclick="window._taskMarkDone('+t.id+')">✓ Done</button><button class="btn btn-ghost btn-sm" style="font-size:11px" onclick="window._crmOpenDetail('+t.lead_id+')">'+window.icon("eye",14)+'</button></div></div>';
    }).join('');
}

// =============================================================================
// LEADS TABLE — with score column
// =============================================================================
function _leadsHtml() {
    var sources=[];
    _crm.leads.forEach(function(l){if(l.source_website&&sources.indexOf(l.source_website)===-1)sources.push(l.source_website);});
    var rows=_crm.leads.map(function(l){
        var st=_crm.stages.find(function(s){return String(s.id)===String(l.pipeline_stage_id);});
        var flags=_crm.taskFlags[String(l.id)]||{};
        var ind=flags.overdue?'<span style="margin-left:5px">'+window.icon("info",14)+'</span>':flags.today?'<span style="margin-left:5px">'+window.icon("info",14)+'</span>':'';
        return '<tr onmouseover="this.style.background=\'rgba(255,255,255,.03)\'" onmouseout="this.style.background=\'\'">'+
            '<td style="padding:11px 14px;font-weight:500;color:var(--t1)">'+_e(l.name)+ind+'</td>'+
            '<td style="padding:11px 14px;color:var(--t2);font-size:12px">'+_e(l.email||'—')+'</td>'+
            '<td style="padding:11px 14px;color:var(--t2);font-size:12px">'+_e(l.company||'—')+'</td>'+
            '<td style="padding:11px 14px"><span style="color:var(--ac);font-size:12px">'+_e(l.source_website||'—')+'</span></td>'+
            '<td style="padding:11px 14px"><span class="badge badge-purple">'+_e((st&&st.name)||'?')+'</span></td>'+
            '<td style="padding:11px 14px">'+_scoreBadge(l.score)+'</td>'+
            '<td style="padding:11px 14px;color:var(--ac);font-size:12px">'+(l.deal_value&&parseFloat(l.deal_value)>0?'$'+parseFloat(l.deal_value).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0}):'—')+'</td>'+
            '<td style="padding:11px 14px;text-align:center;white-space:nowrap">'+
                '<button class="btn btn-ghost btn-sm" style="color:var(--ac)" onclick="window._crmOpenDetail('+l.id+')">'+window.icon("eye",14)+' View</button> '+
                '<button class="btn btn-ghost btn-sm" onclick="window._crmEditLead('+l.id+')">'+window.icon("edit",14)+'</button> '+
                '<button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="window._crmDelLead('+l.id+')">'+window.icon("delete",14)+'</button>'+
            '</td></tr>';
    }).join('');
    var empty='<tr><td colspan="8" style="padding:50px;text-align:center;color:var(--t3)"><div style="font-size:36px;margin-bottom:12px">'+window.icon("more",14)+'</div><div style="font-weight:600;color:var(--t1);margin-bottom:8px">No leads yet</div><button class="btn btn-primary btn-sm" onclick="window._crmNewLead()">+ Create first lead</button></td></tr>';
    var srcOpts=sources.map(function(s){return '<option value="'+_e(s)+'">'+_e(s)+'</option>';}).join('');
    return '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">'+
        '<div>'+(sources.length?'<select class="form-select" style="width:180px;font-size:12px" onchange="window._crmFilterSrc(this.value)"><option value="">All Sources</option>'+srcOpts+'</select>':'')+
        '</div><div style="display:flex;gap:8px"><button class="btn btn-outline btn-sm" onclick="window._crmExport(\'csv\')">'+window.icon("download",14)+' CSV</button><button class="btn btn-primary" onclick="window._crmNewLead()">+ New Lead</button></div></div>'+
        '<div class="card crm-table-wrap" style="overflow:hidden"><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:760px">'+
        '<thead><tr style="border-bottom:1px solid var(--bd)">'+_th('Name')+_th('Email')+_th('Company')+_th('Source')+_th('Stage')+_th('Score')+_th('Value')+
            '<th style="padding:10px 14px;text-align:center;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase">Actions</th></tr></thead>'+
        '<tbody>'+(rows||empty)+'</tbody></table></div></div>';
}
function _th(l){return '<th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase">'+l+'</th>';}

// =============================================================================
// TASKS TAB
// =============================================================================
function _tasksHtml(){
    var tasks=_crm.tasks||[];
    var filters='<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap"><select class="form-select" style="width:130px;font-size:12px" id="tf-pri" onchange="window._filterTasks()"><option value="">All Priorities</option><option value="high">'+window.icon("info",14)+' High</option><option value="medium">'+window.icon("info",14)+' Medium</option><option value="low">'+window.icon("info",14)+' Low</option></select><select class="form-select" style="width:130px;font-size:12px" id="tf-due" onchange="window._filterTasks()"><option value="">All Due</option><option value="overdue">'+window.icon("info",14)+' Overdue</option><option value="today">'+window.icon("info",14)+' Today</option><option value="upcoming">'+window.icon("info",14)+' Upcoming</option></select></div>';
    if(!tasks.length)return filters+'<div class="card" style="padding:60px;text-align:center;color:var(--t3)"><div style="font-size:40px;margin-bottom:12px">'+window.icon("check",14)+'</div><div style="font-weight:600;color:var(--t1);margin-bottom:4px">All caught up!</div></div>';
    var rows=tasks.map(function(t){var ds=_dueStatus(t.due_date);var bg=ds==='overdue'?'rgba(248,113,113,.04)':ds==='today'?'rgba(245,158,11,.04)':'';var dot=ds==='overdue'?'var(--rd)':ds==='today'?'var(--am)':'var(--t3)';
        return '<tr data-priority="'+_e(t.priority||'medium')+'" data-due="'+_e(ds)+'" style="background:'+bg+'" onmouseover="this.style.background=\'rgba(255,255,255,.03)\'" onmouseout="this.style.background=\''+bg+'\'">'+
            '<td style="padding:10px 14px;width:10px"><div style="width:8px;height:8px;border-radius:50%;background:'+dot+'"></div></td>'+
            '<td style="padding:10px 14px"><div style="font-size:13px;font-weight:500;color:var(--t1)">'+_e(t.title)+'</div>'+(t.description?'<div style="font-size:11px;color:var(--t3)">'+_e((t.description+'').substring(0,70))+'</div>':'')+
            '</td><td style="padding:10px 14px"><span style="font-size:11px;color:var(--ac);cursor:pointer" onclick="window._crmOpenDetail('+t.lead_id+')">'+_e(t.lead_name||'Lead #'+t.lead_id)+'</span></td>'+
            '<td style="padding:10px 14px">'+_priBadge(t.priority||'medium')+'</td>'+
            '<td style="padding:10px 14px">'+(t.due_date?_dueBadge(t.due_date,t.status):'<span style="color:var(--t3);font-size:11px">—</span>')+'</td>'+
            '<td style="padding:10px 14px;text-align:center;white-space:nowrap"><button class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--ac)" onclick="window._taskMarkDone('+t.id+')">✓ Done</button> <button class="btn btn-ghost btn-sm" style="font-size:11px" onclick="window._crmOpenDetail('+t.lead_id+')">'+window.icon("eye",14)+'</button> <button class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--rd)" onclick="window._actDelete('+t.id+')">✕</button></td></tr>';
    }).join('');
    return filters+'<div class="card crm-table-wrap" style="overflow:hidden"><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:640px"><thead><tr style="border-bottom:1px solid var(--bd)"><th style="padding:10px 14px;width:10px"></th>'+_th('Task')+_th('Lead')+_th('Priority')+_th('Due')+'<th style="padding:10px 14px;text-align:center;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase">Actions</th></tr></thead><tbody id="tasks-tbody">'+rows+'</tbody></table></div></div>';
}
window._filterTasks=function(){var pri=(document.getElementById('tf-pri')||{}).value||'';var due=(document.getElementById('tf-due')||{}).value||'';document.querySelectorAll('#tasks-tbody tr').forEach(function(r){var show=(!pri||r.getAttribute('data-priority')===pri)&&(!due||r.getAttribute('data-due')===due);r.style.display=show?'':'none';});};
window._taskMarkDone=async function(id){var res;try{res=await fetch(_crmUrl('/activities/'+id),{method:'PUT',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify({status:'done'})});}catch(e){showToast('Network error.','error');return;}if(!res.ok){showToast('Could not mark done.','error');return;}showToast('Task done!','success');_crm.tasks=_crm.tasks.filter(function(t){return t.id!=id;});_crm.taskFlags=_buildTaskFlags(_crm.tasks);var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){}};

// =============================================================================
// APPOINTMENTS TAB
// =============================================================================
function _apptHtml(){
    var appts=_crm.appointments||[];
    var rows=appts.map(function(a){var stCls={scheduled:'badge-blue',completed:'badge-green',cancelled:'badge-grey',no_show:'badge-red'}[a.status]||'badge-grey';
        return '<tr onmouseover="this.style.background=\'rgba(255,255,255,.03)\'" onmouseout="this.style.background=\'\'"><td style="padding:11px 14px;font-weight:500;color:var(--t1)">'+_e(a.title)+'</td><td style="padding:11px 14px"><span style="font-size:12px;color:var(--ac);cursor:pointer" onclick="window._crmOpenDetail('+a.lead_id+')">'+_e(a.lead_name||'Lead #'+a.lead_id)+'</span></td><td style="padding:11px 14px;color:var(--t1);font-size:12px">'+_fmtDateTime(a.start_at)+'</td><td style="padding:11px 14px;color:var(--t3);font-size:12px">'+_fmtDateTime(a.end_at)+'</td><td style="padding:11px 14px"><span class="badge '+stCls+'">'+_e(a.status)+'</span></td><td style="padding:11px 14px;text-align:center;white-space:nowrap"><button class="btn btn-ghost btn-sm" onclick="window._apptEdit('+a.id+')">'+window.icon("edit",14)+'</button> <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="window._apptCancel('+a.id+')">✕</button></td></tr>';
    }).join('');
    var empty='<tr><td colspan="6" style="padding:50px;text-align:center;color:var(--t3)"><div style="font-size:36px;margin-bottom:12px">'+window.icon("calendar",14)+'</div><div style="font-weight:600;color:var(--t1);margin-bottom:8px">No upcoming appointments</div></td></tr>';
    return '<div class="card crm-table-wrap" style="overflow:hidden"><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:700px"><thead><tr style="border-bottom:1px solid var(--bd)">'+_th('Title')+_th('Lead')+_th('Start')+_th('End')+_th('Status')+'<th style="padding:10px 14px;text-align:center;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase">Actions</th></tr></thead><tbody>'+(rows||empty)+'</tbody></table></div></div>';
}
window._apptEdit=async function(id){var appt=(_crm.appointments||[]).find(function(a){return a.id==id;});if(!appt){showToast('Not found.','error');return;}_apptModal(appt.lead_id,appt);};
window._apptCancel=async function(id){var ok=await luConfirm('Cancel this appointment?','Cancel','Cancel It','Keep');if(!ok)return;var res;try{res=await fetch(_crmUrl('/appointments/'+id),{method:'PUT',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify({status:'cancelled'})});}catch(e){showToast('Network error.','error');return;}if(!res.ok){showToast('Could not cancel.','error');return;}showToast('Appointment cancelled.','success');_crm.appointments=_crm.appointments.filter(function(a){return a.id!=id;});var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){}};
window._apptModal=function(leadId,appt){var isEdit=!!(appt&&appt.id);var now=new Date(),pad=function(n){return n<10?'0'+n:n;};var ds=now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate())+'T'+pad(now.getHours()+1)+':00';var de=now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate())+'T'+pad(now.getHours()+2)+':00';var stOpts=['scheduled','completed','cancelled','no_show'].map(function(s){return '<option value="'+s+'"'+(appt&&appt.status===s?' selected':'')+(s==='scheduled'&&!appt?' selected':'')+'>'+s.replace('_',' ').replace(/\b\w/g,function(c){return c.toUpperCase();})+'</option>';}).join('');
    var bd=document.createElement('div');bd.className='modal-backdrop';bd.innerHTML='<div class="modal" style="max-width:480px"><div class="modal-header"><h3>'+(isEdit?'Edit Appointment':''+window.icon("calendar",14)+' Schedule Meeting')+'</h3><button class="btn btn-ghost btn-sm" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div><div style="padding:20px;display:grid;gap:14px">'+_fi('Title','ap-t','text','e.g. Discovery Call',appt?appt.title:'',true)+'<div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Description</label><textarea class="form-input" id="ap-d" rows="2" style="resize:vertical">'+_e(appt?(appt.description||''):'')+'</textarea></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Start <span style="color:var(--rd)">*</span></label><input class="form-input" id="ap-s" type="datetime-local" value="'+(appt?appt.start_at.replace(' ','T').substring(0,16):ds)+'"></div><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">End <span style="color:var(--rd)">*</span></label><input class="form-input" id="ap-e" type="datetime-local" value="'+(appt?appt.end_at.replace(' ','T').substring(0,16):de)+'"></div></div>'+(isEdit?'<div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Status</label><select class="form-select" id="ap-st">'+stOpts+'</select></div>':'')+'<div id="ap-err" style="display:none;color:var(--rd);font-size:12px;padding:8px 12px;background:rgba(248,113,113,.1);border-radius:6px"></div></div><div style="padding:0 20px 20px;display:flex;gap:10px;justify-content:flex-end"><button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button><button class="btn btn-primary" id="ap-btn">'+(isEdit?''+window.icon("save",14)+' Save':''+window.icon("calendar",14)+' Schedule & Create Task')+'</button></div></div>';
    document.body.appendChild(bd);requestAnimationFrame(function(){bd.classList.add('visible');});try{bd.querySelector('#ap-t').focus();}catch(e){}
    bd.querySelector('#ap-btn').onclick=async function(){var title=bd.querySelector('#ap-t').value.trim();var start=bd.querySelector('#ap-s').value;var end=bd.querySelector('#ap-e').value;var errEl=bd.querySelector('#ap-err');var btn=bd.querySelector('#ap-btn');errEl.style.display='none';if(!title){errEl.textContent='Title is required.';errEl.style.display='block';return;}if(!start){errEl.textContent='Start is required.';errEl.style.display='block';return;}if(!end){errEl.textContent='End is required.';errEl.style.display='block';return;}var payload={title:title,description:bd.querySelector('#ap-d').value.trim(),start_at:start,end_at:end};if(!isEdit)payload.lead_id=leadId;if(isEdit&&bd.querySelector('#ap-st'))payload.status=bd.querySelector('#ap-st').value;btn.disabled=true;btn.textContent='Saving…';var res,body;try{res=await fetch(_crmUrl(isEdit?'/appointments/'+appt.id:'/appointments'),{method:isEdit?'PUT':'POST',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify(payload)});}catch(e){errEl.textContent='Network error: '+e.message;errEl.style.display='block';btn.disabled=false;btn.textContent=isEdit?''+window.icon("save",14)+' Save':''+window.icon("calendar",14)+' Schedule';return;}try{body=await res.json();}catch(e){body={};}if(!res.ok){errEl.textContent='Error: '+((body&&body.message)||res.status);errEl.style.display='block';btn.disabled=false;btn.textContent=isEdit?''+window.icon("save",14)+' Save':''+window.icon("calendar",14)+' Schedule';return;}bd.remove();showToast(isEdit?'Appointment updated!':'Meeting scheduled!','success');var a=await _crmGet('/appointments?upcoming=1').catch(function(){return null;});_crm.appointments=(a&&a.appointments)?a.appointments:[];if(_crm.detailLeadId){await _reloadTimeline();await _recalcCurrentScore(_crm.detailLeadId);}else{var td=await _crmGet('/tasks?status=pending').catch(function(){return null;});if(td&&td.tasks){_crm.tasks=td.tasks;_crm.taskFlags=_buildTaskFlags(_crm.tasks);}var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){}}};
};

// =============================================================================
// LEAD DETAIL
// =============================================================================
window._crmOpenDetail=async function(id){
    _crm.tab='detail';_crm.detailLeadId=id;_crm.activities=[];
    var el=document.getElementById('crm-root');if(el)el.innerHTML=loadingCard(300);
    var lead=_crm.leads.find(function(l){return l.id==id;});
    if(!lead)lead=await _crmGet('/leads/'+id).catch(function(){return null;});
    var actData=await _crmGet('/activities?lead_id='+id).catch(function(e){console.error('[LuCRM] activities:',e.message);return null;});
    _crm.activities=(actData&&actData.activities)?actData.activities:[];
    if(!lead){if(el)el.innerHTML='<div style="padding:40px;text-align:center;color:var(--rd)">Lead not found.</div>';return;}
    try{_renderDetail(el,lead);}catch(e){console.error('[LuCRM] detail render:',e);}
};

function _renderDetail(el,lead){
    if(!el)el=document.getElementById('crm-root');if(!el)return;
    if(!lead)lead=_crm.leads.find(function(l){return l.id==_crm.detailLeadId;})||{};
    var st=_crm.stages.find(function(s){return String(s.id)===String(lead.pipeline_stage_id);});
    var initials=(lead.name||'?').split(' ').slice(0,2).map(function(w){return w[0];}).join('').toUpperCase();
    var flags=_crm.taskFlags[String(lead.id)]||{};
    var alert=flags.overdue?'<div style="background:rgba(248,113,113,.1);border:1px solid var(--rd);border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:var(--rd)">'+window.icon("warning",14)+' Overdue tasks</div>':flags.today?'<div style="background:rgba(245,158,11,.1);border:1px solid var(--am);border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:var(--am)">'+window.icon("info",14)+' Task due today</div>':'';
    el.innerHTML='<div style="padding:24px;min-height:100%;box-sizing:border-box">'+
        '<button class="btn btn-ghost btn-sm" onclick="window._crmTab(\'leads\')" style="margin-bottom:16px">← Back to Leads</button>'+
        '<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start">'+
            '<div>'+alert+
                '<div class="card" style="padding:24px">'+
                    '<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--bd)">'+
                        '<div style="width:48px;height:48px;border-radius:50%;background:var(--pg);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:var(--pu);flex-shrink:0">'+_e(initials)+'</div>'+
                        '<div><div style="font-size:15px;font-weight:700;color:var(--t1)">'+_e(lead.name||'—')+'</div><span class="badge badge-purple" style="margin-top:4px">'+_e((st&&st.name)||'—')+'</span></div>'+
                    '</div>'+
                    '<div style="margin-bottom:12px">'+_scoreBadge(lead.score)+'</div>'+
                    _df('Email',lead.email)+_df('Phone',lead.phone)+_df('Company',lead.company)+_df('Source',lead.source_website)+_df('Status',lead.status)+_df('Created',_fmtDate(lead.created_at))+
                    '<button class="btn btn-outline btn-sm" style="width:100%;margin-top:16px" onclick="window._crmEditLead('+lead.id+')">'+window.icon("edit",14)+' Edit Lead</button>'+
                '</div>'+
            '</div>'+
            '<div>'+
                '<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">'+
                    '<button class="btn btn-primary btn-sm" onclick="window._actModal('+lead.id+',\'note\')">'+window.icon("edit",14)+' Note</button>'+
                    '<button class="btn btn-outline btn-sm" onclick="window._actModal('+lead.id+',\'call\')">'+window.icon("message",14)+' Call</button>'+
                    '<button class="btn btn-outline btn-sm" onclick="window._actModal('+lead.id+',\'task\')">'+window.icon("check",14)+' Task</button>'+
                    (_modEnabled('appointments')?'<button class="btn btn-outline btn-sm" onclick="window._apptModal('+lead.id+')">'+window.icon("calendar",14)+' Schedule Meeting</button>':'')+
                '</div>'+
                '<div id="crm-timeline">'+_timelineHtml(_crm.activities)+'</div>'+
            '</div>'+
        '</div></div>';
}

function _df(label,value){if(!value)return '';return '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--bd);font-size:13px"><span style="color:var(--t3);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">'+label+'</span><span style="color:var(--t1);text-align:right;max-width:175px;word-break:break-word">'+_e(value)+'</span></div>';}

// =============================================================================
// TIMELINE
// =============================================================================
function _timelineHtml(activities){
    if(!activities||!activities.length)return '<div class="card" style="padding:40px;text-align:center;color:var(--t3)"><div style="font-size:32px;margin-bottom:8px">'+window.icon("clock",14)+'</div><div style="font-weight:600;color:var(--t1);margin-bottom:4px">No activity yet</div><div style="font-size:12px">Add a note, call, or task to start the timeline.</div></div>';
    var groups={},order=[];
    activities.forEach(function(a){var k=_dateKey(a.created_at);if(!groups[k]){groups[k]=[];order.push(k);}groups[k].push(a);});
    return order.map(function(dk){
        var items=groups[dk].map(function(a){var meta=_actMeta[a.type]||_actMeta.note;var isDone=a.status==='done';var isTask=a.type==='task';
            return '<div style="display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--bd)">'+
                '<div style="width:30px;height:30px;border-radius:50%;background:'+meta.color+'22;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;margin-top:2px">'+meta.icon+'</div>'+
                '<div style="flex:1;min-width:0"><div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px">'+
                    '<div style="flex:1"><span style="font-size:10px;font-weight:700;text-transform:uppercase;color:'+meta.color+';letter-spacing:.05em">'+meta.label+'</span>'+(isTask?' · '+_priBadge(a.priority||'medium'):'')+
                        '<div style="font-size:13px;font-weight:500;color:var(--t1);margin-top:2px'+(isDone?';text-decoration:line-through;opacity:.5':'')+'" >'+_e(a.title)+'</div>'+
                        (a.description?'<div style="font-size:12px;color:var(--t2);margin-top:3px;white-space:pre-wrap">'+_e(a.description)+'</div>':'')+
                        (isTask&&a.due_date?'<div style="margin-top:4px">'+_dueBadge(a.due_date,a.status)+'</div>':'')+
                    '</div>'+
                    '<div style="display:flex;align-items:center;gap:4px;flex-shrink:0"><span style="font-size:11px;color:var(--t3);white-space:nowrap">'+_fmtTime(a.created_at)+'</span>'+
                        '<button class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 5px;color:'+(isDone?'var(--t3)':'var(--ac)')+'" onclick="window._actToggle('+a.id+',\''+(isDone?'pending':'done')+'\')">'+( isDone?'↩':'✓')+'</button>'+
                        '<button class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 5px;color:var(--rd)" onclick="window._actDelete('+a.id+')">✕</button>'+
                    '</div></div></div></div>';
        }).join('');
        return '<div style="margin-bottom:14px"><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);padding:6px 0 4px;border-bottom:2px solid var(--bd);margin-bottom:2px">'+_relDate(dk)+'</div><div class="card" style="padding:0 14px">'+items+'</div></div>';
    }).join('');
}

async function _reloadTimeline(){
    var id=_crm.detailLeadId;if(!id)return;
    var data=await _crmGet('/activities?lead_id='+id).catch(function(e){console.error('[LuCRM] timeline reload:',e.message);return null;});
    if(data&&data.activities)_crm.activities=data.activities;
    var tl=document.getElementById('crm-timeline');if(tl)try{tl.innerHTML=_timelineHtml(_crm.activities);}catch(e){console.error('[LuCRM] timeline render:',e);}
    var td=await _crmGet('/tasks?status=pending').catch(function(){return null;});if(td&&td.tasks){_crm.tasks=td.tasks;_crm.taskFlags=_buildTaskFlags(_crm.tasks);}
}

// =============================================================================
// ACTIVITY CRUD
// =============================================================================
window._actModal=function(leadId,defaultType){
    var types=['note','call','email','meeting','task'];var isTask=defaultType==='task';
    var typeOpts=types.map(function(t){var m=_actMeta[t];return '<option value="'+t+'"'+(t===defaultType?' selected':'')+'>'+ m.icon+' '+m.label+'</option>';}).join('');
    var priOpts=['low','medium','high'].map(function(p){var m=_priMeta[p];return '<option value="'+p+'"'+(p==='medium'?' selected':'')+'>'+m.icon+' '+m.label+'</option>';}).join('');
    var bd=document.createElement('div');bd.className='modal-backdrop';
    bd.innerHTML='<div class="modal" style="max-width:460px"><div class="modal-header"><h3 id="act-modal-title">'+(_actMeta[defaultType]||_actMeta.note).icon+' '+(_actMeta[defaultType]||_actMeta.note).label+'</h3><button class="btn btn-ghost btn-sm" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div><div style="padding:20px;display:grid;gap:14px"><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Type</label><select class="form-select" id="act-type" onchange="window._actTypeChange(this.value)">'+typeOpts+'</select></div><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Title <span style="color:var(--rd)">*</span></label><input class="form-input" id="act-title" type="text" placeholder="e.g. Follow-up call…"></div><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Notes</label><textarea class="form-input" id="act-desc" rows="2" style="resize:vertical" placeholder="Optional…"></textarea></div><div id="act-task-fields" style="display:'+(isTask?'grid':'none')+';grid-template-columns:1fr 1fr;gap:12px"><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Due Date</label><input class="form-input" id="act-due" type="date"></div><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Priority</label><select class="form-select" id="act-priority">'+priOpts+'</select></div></div><div id="act-err" style="display:none;color:var(--rd);font-size:12px;padding:8px 12px;background:rgba(248,113,113,.1);border-radius:6px"></div></div><div style="padding:0 20px 20px;display:flex;gap:10px;justify-content:flex-end"><button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button><button class="btn btn-primary" id="act-save">Save</button></div></div>';
    document.body.appendChild(bd);requestAnimationFrame(function(){bd.classList.add('visible');});try{bd.querySelector('#act-title').focus();}catch(e){}
    bd.querySelector('#act-save').onclick=async function(){
        var title=bd.querySelector('#act-title').value.trim();var errEl=bd.querySelector('#act-err');var btn=bd.querySelector('#act-save');var type=bd.querySelector('#act-type').value;
        errEl.style.display='none';if(!title){errEl.textContent='Title is required.';errEl.style.display='block';return;}
        var payload={lead_id:leadId,type:type,title:title,description:bd.querySelector('#act-desc').value.trim(),status:'pending'};
        if(type==='task'){payload.priority=bd.querySelector('#act-priority').value;var dv=bd.querySelector('#act-due').value;if(dv)payload.due_date=dv;}
        btn.disabled=true;btn.textContent='Saving…';
        var res,body;
        try{res=await fetch(_crmUrl('/activities'),{method:'POST',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify(payload)});}
        catch(e){errEl.textContent='Network error: '+e.message;errEl.style.display='block';btn.disabled=false;btn.textContent='Save';return;}
        try{body=await res.json();}catch(e){body={};}
        if(!res.ok){errEl.textContent='Error: '+((body&&body.message)||res.status);errEl.style.display='block';btn.disabled=false;btn.textContent='Save';return;}
        bd.remove();showToast('Activity saved!','success');
        await _reloadTimeline();
        await _recalcCurrentScore(leadId);
        // Update score badge in lead array for live display
        var lead=_crm.leads.find(function(l){return l.id==leadId;});
        if(lead){var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){}}
    };
};
window._actTypeChange=function(type){var tf=document.getElementById('act-task-fields');if(tf)tf.style.display=type==='task'?'grid':'none';var title=document.getElementById('act-modal-title');if(title){var m=_actMeta[type]||_actMeta.note;title.textContent=m.icon+' '+m.label;}};
window._actToggle=async function(id,newStatus){var res;try{res=await fetch(_crmUrl('/activities/'+id),{method:'PUT',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify({status:newStatus})});}catch(e){showToast('Network error.','error');return;}if(!res.ok){showToast('Update failed.','error');return;}await _reloadTimeline();};
window._actDelete=async function(id){var ok=await luConfirm('Delete this activity?','Delete','Delete','Cancel');if(!ok)return;var res;try{res=await fetch(_crmUrl('/activities/'+id),{method:'DELETE',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}});}catch(e){showToast('Network error.','error');return;}if(!res.ok){showToast('Delete failed.','error');return;}showToast('Deleted.','success');if(_crm.tab==='tasks'||_crm.tab==='today'){_crm.tasks=_crm.tasks.filter(function(t){return t.id!=id;});_crm.taskFlags=_buildTaskFlags(_crm.tasks);var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){}}else{await _reloadTimeline();}};

// =============================================================================
// PROJECTS
// =============================================================================
function _prjHtml(){var rows=_crm.projects.map(function(p){var lead=_crm.leads.find(function(l){return String(l.id)===String(p.lead_id);});var cls={active:'badge-green',on_hold:'badge-amber',completed:'badge-blue',cancelled:'badge-red'}[p.status]||'badge-grey';return '<tr onmouseover="this.style.background=\'rgba(255,255,255,.03)\'" onmouseout="this.style.background=\'\'"><td style="padding:11px 14px;font-weight:500;color:var(--t1)">'+_e(p.name)+'</td><td style="padding:11px 14px;color:var(--t2);font-size:12px">'+(lead?_e(lead.name):'<span style="color:var(--t3)">Unassigned</span>')+'</td><td style="padding:11px 14px"><span class="badge '+cls+'">'+_e(p.status||'—')+'</span></td><td style="padding:11px 14px;color:var(--ac);font-size:12px">$'+parseFloat(p.budget||0).toFixed(2)+'</td><td style="padding:11px 14px;color:var(--t3);font-size:12px">'+_e(p.start_date||'—')+'</td><td style="padding:11px 14px;text-align:center;white-space:nowrap"><button class="btn btn-ghost btn-sm" onclick="window._crmEditPrj('+p.id+')">'+window.icon("edit",14)+' Edit</button> <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="window._crmDelPrj('+p.id+')">'+window.icon("delete",14)+'</button></td></tr>';}).join('');var empty='<tr><td colspan="6" style="padding:50px;text-align:center;color:var(--t3)"><div style="font-size:36px;margin-bottom:12px">'+window.icon("more",14)+'</div><div style="font-weight:600;color:var(--t1);margin-bottom:8px">No projects yet</div><button class="btn btn-primary btn-sm" onclick="window._crmNewPrj()">+ Create first project</button></td></tr>';return '<div style="display:flex;justify-content:flex-end;margin-bottom:14px"><button class="btn btn-primary" onclick="window._crmNewPrj()">+ New Project</button></div><div class="card crm-table-wrap" style="overflow:hidden"><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:600px"><thead><tr style="border-bottom:1px solid var(--bd)">'+_th('Project')+_th('Lead')+_th('Status')+_th('Budget')+_th('Start')+'<th style="padding:10px 14px;text-align:center;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase">Actions</th></tr></thead><tbody>'+(rows||empty)+'</tbody></table></div></div>';}

// =============================================================================
// SETTINGS
// =============================================================================
function _setHtml(){var s=_crm.settings||{};var industries=[{val:'general',label:''+window.icon("more",14)+' General'},{val:'clinic',label:''+window.icon("more",14)+' Clinic / Healthcare'},{val:'real_estate',label:''+window.icon("more",14)+' Real Estate'},{val:'agency',label:''+window.icon("rocket",14)+' Agency'},{val:'contractor',label:''+window.icon("edit",14)+' Contractor'}];var bizOpts=industries.map(function(i){return '<option value="'+i.val+'"'+(s.business_type===i.val?' selected':'')+'>'+i.label+'</option>';}).join('');var mods=_crm.modules.map(function(m){if(m.required)return '<div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--s2);border-radius:8px;margin-bottom:8px"><input type="checkbox" checked disabled style="width:16px;height:16px"><span style="flex:1;font-size:13px">'+_e(m.icon||'')+' '+_e(m.label)+'</span><span class="badge badge-grey">Required</span></div>';return '<div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--s2);border-radius:8px;margin-bottom:8px"><input type="checkbox" id="cmod_'+_e(m.slug)+'"'+(m.enabled?' checked':'')+' style="width:16px;height:16px;cursor:pointer"><label for="cmod_'+_e(m.slug)+'" style="flex:1;font-size:13px;cursor:pointer">'+_e(m.icon||'')+' '+_e(m.label)+'</label></div>';}).join('');return '<div class="card" style="padding:24px;max-width:560px"><h3 style="margin:0 0 20px;font-size:16px;font-weight:600">CRM Settings</h3><div style="margin-bottom:8px"><label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:8px">Industry / Business Type</label><select id="crmBizType" class="form-select" onchange="window._onBizTypeChange(this.value)">'+bizOpts+'</select><div style="font-size:11px;color:var(--t3);margin-top:6px">Changing industry auto-configures module defaults.</div></div><div style="margin-top:20px;margin-bottom:24px"><label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:12px">Modules</label>'+(mods||'<div style="color:var(--t3);font-size:12px">No modules</div>')+'</div><button class="btn btn-primary" onclick="window._crmSaveSet()">Save Settings</button></div>';}
window._onBizTypeChange=async function(biz){var payload={business_type:biz};var res;try{res=await fetch(_crmUrl('/settings'),{method:'PUT',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify(payload)});}catch(e){return;}if(!res.ok)return;var mf=await _crmGet('/modules').catch(function(){return null;});var sf=await _crmGet('/settings').catch(function(){return null;});if(mf)_crm.modules=Object.entries(mf).map(function(p){return Object.assign({slug:p[0]},p[1]);});if(sf)_crm.settings=sf;showToast('Industry updated!','success');var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){}};
window._crmSaveSet=async function(){var bizEl=document.getElementById('crmBizType');if(!bizEl){showToast('Settings form not found.','error');return;}var mods={};_crm.modules.forEach(function(m){var cb=document.getElementById('cmod_'+m.slug);mods[m.slug]=m.required?true:(cb?cb.checked:false);});var res,body;try{res=await fetch(_crmUrl('/settings'),{method:'PUT',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify({business_type:bizEl.value,enabled_modules:mods})});}catch(e){showToast('Network error.','error');return;}try{body=await res.json();}catch(e){body={};}if(!res.ok){showToast('Save failed: '+((body&&body.message)||res.status),'error');return;}showToast('Settings saved!','success');var mf=await _crmGet('/modules').catch(function(){return null;});var sf=await _crmGet('/settings').catch(function(){return null;});if(mf)_crm.modules=Object.entries(mf).map(function(p){return Object.assign({slug:p[0]},p[1]);});if(sf)_crm.settings=sf;var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){console.error('[LuCRM] settings render:',e);}};

// =============================================================================
// LEAD CRUD
// =============================================================================
window._crmNewLead=function(){_leadModal(null);};
window._crmEditLead=async function(id){var lead=_crm.leads.find(function(l){return l.id==id;});if(!lead){lead=await _crmGet('/leads/'+id).catch(function(){return null;});}if(!lead){showToast('Could not load lead.','error');return;}_leadModal(lead);};
function _leadModal(lead){var isEdit=!!(lead&&lead.id);var stOpts=_crm.stages.map(function(s){var sel=lead?(String(lead.pipeline_stage_id)===String(s.id)?' selected':''):(s.position===1?' selected':'');return '<option value="'+s.id+'"'+sel+'>'+_e(s.name)+'</option>';}).join('');var bd=document.createElement('div');bd.className='modal-backdrop';bd.innerHTML='<div class="modal" style="max-width:500px;max-height:90vh;overflow-y:auto"><div class="modal-header"><h3>'+(isEdit?'Edit Lead':'New Lead')+'</h3><button class="btn btn-ghost btn-sm" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div><div style="padding:20px;display:grid;gap:14px">'+_fi('Name','cl-n','text','Full name',lead?lead.name:'',true)+_fi('Email','cl-e','email','email@example.com',lead?(lead.email||''):'')+_fi('Phone','cl-p','tel','+971 50 000 0000',lead?(lead.phone||''):'')+_fi('Company','cl-c','text','Company name',lead?(lead.company||''):'')+_fi('Source Website','cl-s','text','example.com',lead?(lead.source_website||''):'',true)+'<div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Pipeline Stage</label><select class="form-select" id="cl-stg">'+stOpts+'</select></div>'+_fi('Deal Value (optional)','cl-dv','number','0.00',lead?(lead.deal_value||''):'')+'<div id="cl-err" style="display:none;color:var(--rd);font-size:12px;padding:8px 12px;background:rgba(248,113,113,.1);border-radius:6px"></div></div><div style="padding:0 20px 20px;display:flex;gap:10px;justify-content:flex-end"><button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button><button class="btn btn-primary" id="cl-btn">'+(isEdit?''+window.icon("save",14)+' Save Changes':'+ Create Lead')+'</button></div></div>';document.body.appendChild(bd);requestAnimationFrame(function(){bd.classList.add('visible');});try{bd.querySelector('#cl-n').focus();}catch(e){}
    bd.querySelector('#cl-btn').onclick=async function(){var name=bd.querySelector('#cl-n').value.trim();var source=bd.querySelector('#cl-s').value.trim();var errEl=bd.querySelector('#cl-err');var btn=bd.querySelector('#cl-btn');errEl.style.display='none';if(!name){errEl.textContent='Name is required.';errEl.style.display='block';return;}if(!source){errEl.textContent='Source Website is required.';errEl.style.display='block';return;}var payload={name:name,email:bd.querySelector('#cl-e').value.trim(),phone:bd.querySelector('#cl-p').value.trim(),company:bd.querySelector('#cl-c').value.trim(),source_website:source,pipeline_stage_id:parseInt(bd.querySelector('#cl-stg').value)||1,deal_value:parseFloat(bd.querySelector('#cl-dv').value)||0};btn.disabled=true;btn.textContent='Saving…';var url=_crmUrl(isEdit?'/leads/'+lead.id:'/leads');var nonce=_crmNonce();console.log('[LuCRM] Saving lead…',url);var res,body;try{res=await fetch(url,{method:isEdit?'PUT':'POST',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify(payload)});}catch(e){errEl.textContent='Network error: '+e.message;errEl.style.display='block';btn.disabled=false;btn.textContent=isEdit?''+window.icon("save",14)+' Save Changes':'+ Create Lead';return;}console.log('[LuCRM] Lead response:',res.status,res.ok?'✓':'✗');try{body=await res.json();}catch(e){body={};}if(!res.ok){errEl.textContent='Error: '+((body&&body.message)||res.status);errEl.style.display='block';btn.disabled=false;btn.textContent=isEdit?''+window.icon("save",14)+' Save Changes':'+ Create Lead';return;}console.log('[LuCRM] Lead saved ✓');bd.remove();showToast(isEdit?'Lead updated!':'Lead created!','success');await _crmReload('leads');};}
function _fi(label,id,type,ph,val,required){return '<div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">'+label+(required?' <span style="color:var(--rd)">*</span>':'')+'</label><input class="form-input" id="'+id+'" type="'+type+'" placeholder="'+_e(ph)+'" value="'+_e(val)+'"></div>';}
window._crmDelLead=async function(id){var ok=await luConfirm('Delete this lead permanently?','Delete Lead','Delete','Cancel');if(!ok)return;var res;try{res=await fetch(_crmUrl('/leads/'+id),{method:'DELETE',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}});}catch(e){showToast('Network error.','error');return;}var body;try{body=await res.json();}catch(e){body={};}if(!res.ok){showToast('Delete failed: '+((body&&body.message)||res.status),'error');return;}showToast('Lead deleted.','success');await _crmReload('leads');};

// =============================================================================
// PROJECT CRUD (compact)
// =============================================================================
window._crmNewPrj=function(){_prjModal(null);};window._crmEditPrj=async function(id){var prj=_crm.projects.find(function(p){return p.id==id;});if(!prj){prj=await _crmGet('/projects/'+id).catch(function(){return null;});}if(!prj){showToast('Could not load.','error');return;}_prjModal(prj);};
function _prjModal(prj){var isEdit=!!(prj&&prj.id);var lOpts='<option value="">No Lead</option>'+_crm.leads.map(function(l){return '<option value="'+l.id+'"'+(prj&&String(prj.lead_id)===String(l.id)?' selected':'')+'>'+_e(l.name)+'</option>';}).join('');var sOpts=['active','on_hold','completed','cancelled'].map(function(st){var lbl=st.replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();});return '<option value="'+st+'"'+(prj?(prj.status===st?' selected':''):(st==='active'?' selected':''))+'>'+lbl+'</option>';}).join('');var bd=document.createElement('div');bd.className='modal-backdrop';bd.innerHTML='<div class="modal" style="max-width:500px;max-height:90vh;overflow-y:auto"><div class="modal-header"><h3>'+(isEdit?'Edit Project':'New Project')+'</h3><button class="btn btn-ghost btn-sm" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div><div style="padding:20px;display:grid;gap:14px">'+_fi('Project Name','cp-n','text','Project name',prj?prj.name:'',true)+'<div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Description</label><textarea class="form-input" id="cp-d" rows="2" style="resize:vertical">'+_e(prj?(prj.description||''):'')+'</textarea></div><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Link to Lead</label><select class="form-select" id="cp-l">'+lOpts+'</select></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Status</label><select class="form-select" id="cp-st">'+sOpts+'</select></div><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Budget</label><input class="form-input" id="cp-b" type="number" step="0.01" placeholder="0.00" value="'+_e(prj?(prj.budget||''):'')+'"></div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">Start</label><input class="form-input" id="cp-s" type="date" value="'+_e(prj?(prj.start_date||''):'')+'"></div><div><label style="display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:6px">End</label><input class="form-input" id="cp-e" type="date" value="'+_e(prj?(prj.end_date||''):'')+'"></div></div><div id="cp-err" style="display:none;color:var(--rd);font-size:12px;padding:8px 12px;background:rgba(248,113,113,.1);border-radius:6px"></div></div><div style="padding:0 20px 20px;display:flex;gap:10px;justify-content:flex-end"><button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button><button class="btn btn-primary" id="cp-btn">'+(isEdit?''+window.icon("save",14)+' Save Changes':'+ Create Project')+'</button></div></div>';document.body.appendChild(bd);requestAnimationFrame(function(){bd.classList.add('visible');});try{bd.querySelector('#cp-n').focus();}catch(e){}
    bd.querySelector('#cp-btn').onclick=async function(){var name=bd.querySelector('#cp-n').value.trim();var errEl=bd.querySelector('#cp-err');var btn=bd.querySelector('#cp-btn');errEl.style.display='none';if(!name){errEl.textContent='Project name required.';errEl.style.display='block';return;}var lv=bd.querySelector('#cp-l').value;var bv=bd.querySelector('#cp-b').value;var payload={name:name,description:bd.querySelector('#cp-d').value.trim(),lead_id:lv?parseInt(lv):null,status:bd.querySelector('#cp-st').value,budget:bv?parseFloat(bv):0,start_date:bd.querySelector('#cp-s').value||null,end_date:bd.querySelector('#cp-e').value||null};btn.disabled=true;btn.textContent='Saving…';var url=_crmUrl(isEdit?'/projects/'+prj.id:'/projects');var res,body;try{res=await fetch(url,{method:isEdit?'PUT':'POST',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},body:JSON.stringify(payload)});}catch(e){errEl.textContent='Network error.';errEl.style.display='block';btn.disabled=false;btn.textContent=isEdit?''+window.icon("save",14)+' Save Changes':'+ Create Project';return;}try{body=await res.json();}catch(e){body={};}if(!res.ok){errEl.textContent='Error: '+((body&&body.message)||res.status);errEl.style.display='block';btn.disabled=false;btn.textContent=isEdit?''+window.icon("save",14)+' Save Changes':'+ Create Project';return;}bd.remove();showToast(isEdit?'Project updated!':'Project created!','success');var fresh=await _crmGet('/projects').catch(function(){return null;});if(fresh&&fresh.projects)_crm.projects=fresh.projects;_crm.tab='projects';var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){};};}
window._crmDelPrj=async function(id){var ok=await luConfirm('Delete this project?','Delete','Delete','Cancel');if(!ok)return;var res;try{res=await fetch(_crmUrl('/projects/'+id),{method:'DELETE',headers:{'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}});}catch(e){showToast('Network error.','error');return;}if(!res.ok){showToast('Delete failed.','error');return;}showToast('Deleted.','success');var f=await _crmGet('/projects').catch(function(){return null;});if(f&&f.projects)_crm.projects=f.projects;var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){}};

// =============================================================================
// FILTER + EXPORT
// =============================================================================
window._crmFilterSrc=async function(src){var d=await _crmGet(src?'/leads?source_website='+encodeURIComponent(src):'/leads').catch(function(e){showToast('Filter failed.','error');return null;});if(d&&d.leads)_crm.leads=d.leads;var el=document.getElementById('crm-root');try{_crmRender(el);}catch(e){}};
window._crmExport=async function(format){var d=await _crmGet('/leads/export/'+format).catch(function(e){showToast('Export failed: '+e.message,'error');return null;});if(!d)return;var txt=format==='csv'?(d.csv||''):format==='xml'?(d.xml||''):JSON.stringify(d);var mime={csv:'text/csv',xml:'application/xml',json:'application/json'}[format]||'text/plain';var blob=new Blob([txt],{type:mime});var url=URL.createObjectURL(blob);var a=document.createElement('a');a.href=url;a.download='leads-'+new Date().toISOString().split('T')[0]+'.'+format;document.body.appendChild(a);a.click();URL.revokeObjectURL(url);a.remove();showToast('Export ready!','success');};

console.log('[LuCRM] v3.6.0 loaded — window.crmLoad ready');
