function _bldSafeText(v){
  if(typeof v==='string') return v;
  if(v===null||v===undefined) return '';
  if(Array.isArray(v)) return v.map(function(x){return typeof x==='string'?x:typeof x==='object'&&x&&x.text?String(x.text):String(x||'')}).join(' ');
  if(typeof v==='object'){if(v.text) return String(v.text); if(v.content) return String(v.content); if(v.label) return String(v.label); if(v.heading) return String(v.heading); try{return JSON.stringify(v)}catch(e){return '';}}
  return String(v);
}
function bld_esc(t){return _bldSafeText(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function bld_escH(t){return _bldSafeText(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function bld_safeUrl(base, id) {
  if (id === undefined || id === null || id === "" || id === "undefined") {
    console.warn("[bld] blocked fetch with undefined id:", base);
    return null;
  }
  return base + id;
}


// bldExpandFlatSection - render-time fallback for sections that use the
// flat AI-wizard schema (heading/subheading/items/cta_text on the section)
// instead of the editor-native components-array schema. One-way: render-only.
// Save path is untouched; DB stays flat. Added 2026-04-10.
function bldExpandFlatSection(sec) {
  if (!sec || typeof sec !== "object") return [];
  var t = (sec.type || "").toLowerCase();
  var out = [];
  function pushHeading() { if (sec.heading) out.push({component_type:"heading", text: sec.heading}); }
  function pushBody()    { if (sec.body)    out.push({component_type:"text",    text: sec.body}); }
  function pushSub()     { if (sec.subheading) out.push({component_type:"text", text: sec.subheading}); }
  function pushCta()     { if (sec.cta_text) out.push({component_type:"button", text: sec.cta_text, url: sec.cta_link || ""}); }

  if (t === "hero") {
    pushHeading(); pushSub(); pushCta();
  } else if (t === "features") {
    pushHeading(); pushBody();
    (sec.items || []).forEach(function(it){
      out.push({component_type:"feature_item", icon: it.icon || "", heading: it.heading || it.title || "", text: it.text || it.description || ""});
    });
  } else if (t === "cta") {
    pushHeading(); pushBody(); pushCta();
  } else if (t === "contact" || t === "contact_form") {
    pushHeading(); pushBody();
    out.push({component_type:"contact_form"});
  } else if (t === "pricing") {
    pushHeading(); pushBody();
    (sec.tiers || sec.items || []).forEach(function(tier){
      out.push(Object.assign({component_type:"price_card"}, tier));
    });
  } else if (t === "faq") {
    pushHeading();
    (sec.items || []).forEach(function(q){
      out.push({component_type:"faq_item", question: q.question || q.heading || "", answer: q.answer || q.text || ""});
    });
  } else if (t === "testimonials") {
    pushHeading();
    (sec.items || []).forEach(function(tt){
      out.push({component_type:"testimonial", quote: tt.quote || tt.text || "", name: tt.name || "", role: tt.role || ""});
    });
  } else if (t === "services" || t === "services_grid" || t === "service_grid") {
    pushHeading(); pushBody();
    (sec.items || []).forEach(function(it){
      out.push({component_type:"service_card", icon: it.icon || "", heading: it.heading || it.title || "", text: it.text || it.description || ""});
    });
  } else if (t === "about" || t === "story") {
    pushHeading(); pushBody();
  } else if (t === "gallery") {
    pushHeading();
    (sec.images || sec.items || []).forEach(function(img){
      out.push({component_type:"image", src: img.src || img.url || "", alt: img.alt || ""});
    });
  } else {
    if (sec.heading) {
      pushHeading();
      var fallbackBody = sec.body || sec.subheading || "";
      if (fallbackBody) out.push({component_type:"text", text: fallbackBody});
    }
  }
  return out;
}

// Deep-sanitize sections from AI: ensure all text fields are strings
function _bldSanitizeSections(sections) {
  if (!Array.isArray(sections)) return [];
  var fixed = 0;
  for (var s = 0; s < sections.length; s++) {
    var sec = sections[s];
    if (!sec || typeof sec !== 'object') continue;
    // Sanitize section-level fields
    if (sec.label && typeof sec.label !== 'string') { sec.label = _bldSafeText(sec.label); fixed++; }
    if (sec.type && typeof sec.type !== 'string') { sec.type = String(sec.type); fixed++; }
    // Preserve and normalize props (layout, columns, variant)
    if (sec.props && typeof sec.props === 'object') {
      if (!sec.style) sec.style = {};
      // Merge layout props into section for renderer access
      if (sec.props.layout) sec._layout = sec.props.layout;
      if (sec.props.columns) sec._columns = parseInt(sec.props.columns) || 1;
      if (sec.props.variant) sec._variant = sec.props.variant;
      // Preserve bgImage from props
      if (sec.props.backgroundImage && !sec.style.bgImage) sec.style.bgImage = sec.props.backgroundImage;
      if (sec.props.backgroundColor && !sec.style.bg) sec.style.bg = sec.props.backgroundColor;
    }
    if (!Array.isArray(sec.components)) sec.components = [];
    for (var c = 0; c < sec.components.length; c++) {
      var cmp = sec.components[c];
      if (!cmp || typeof cmp !== 'object') continue;
      // Normalize component_type → type for old renderer compatibility
      if (cmp.component_type && !cmp.type) { cmp.type = cmp.component_type; fixed++; }
      if (cmp.type && !cmp.component_type) { cmp.component_type = cmp.type; }

      // Flatten new component types from content
      if (cmp.type === 'gallery' && cmp.content && Array.isArray(cmp.content.images) && !cmp.images) { cmp.images = cmp.content.images; }
      if (cmp.type === 'catalog' && cmp.content && Array.isArray(cmp.content.items) && !cmp.items) { cmp.items = cmp.content.items; }
      if (cmp.type === 'carousel' && cmp.content && Array.isArray(cmp.content.slides) && !cmp.slides) { cmp.slides = cmp.content.slides; }
      if (cmp.type === 'map' && cmp.content && (cmp.content.embedUrl || cmp.content.src) && !cmp.embedUrl) { cmp.embedUrl = cmp.content.embedUrl || cmp.content.src; }
      if (cmp.type === 'embed' && cmp.content && (cmp.content.src || cmp.content.html) && !cmp.src) { cmp.src = cmp.content.src; cmp.html = cmp.content.html; }
      if (cmp.type === 'video' && cmp.content && cmp.content.src && !cmp.src) { cmp.src = cmp.content.src; }

      // Flatten content into component if needed (AI sometimes nests wrong)
      if (typeof cmp.content === 'object' && cmp.content !== null && !Array.isArray(cmp.content)) {
        // Merge content fields up
        var ct = cmp.content;
        for (var k in ct) {
          if (typeof ct[k] !== 'string' && ct[k] !== null && ct[k] !== undefined && k !== 'items' && k !== 'fields' && k !== 'buttons' && k !== 'level') {
            ct[k] = _bldSafeText(ct[k]); fixed++;
          }
        }
        // Populate flat fields for old renderer compatibility
        if (ct.text && !cmp.text) cmp.text = ct.text;
        if (ct.label && !cmp.text) cmp.text = ct.label;
        if (ct.heading && !cmp.text) cmp.text = ct.heading;
        if (ct.src && !cmp.src) cmp.src = ct.src;
        if (ct.alt && !cmp.alt) cmp.alt = ct.alt;
        if (ct.href && !cmp.href) cmp.href = ct.href;
        if (ct.variant && !cmp.variant) cmp.variant = ct.variant;
        if (ct.level && !cmp.tag) cmp.tag = 'h' + ct.level;
        // Cards items
        if (Array.isArray(ct.items) && !cmp.items) cmp.items = ct.items;
        // Form fields
        if (Array.isArray(ct.fields) && !cmp.fields) cmp.fields = ct.fields;
        // Button label → text for old renderer
        if (cmp.type === 'button' && ct.label && !cmp.text) cmp.text = ct.label;
      }
      // Sanitize flat fields
      var textFields = ['text','label','heading','title','description','src','alt','href','variant','tag','quote','author','role','name','price','period'];
      for (var f = 0; f < textFields.length; f++) {
        var fk = textFields[f];
        if (cmp[fk] !== undefined && typeof cmp[fk] !== 'string') { cmp[fk] = _bldSafeText(cmp[fk]); fixed++; }
      }
      if (cmp.content && typeof cmp.content === 'object') {
        for (var f = 0; f < textFields.length; f++) {
          var fk = textFields[f];
          if (cmp.content[fk] !== undefined && typeof cmp.content[fk] !== 'string') { cmp.content[fk] = _bldSafeText(cmp.content[fk]); fixed++; }
        }
      }
      // Sanitize card items
      var items = (cmp.items || (cmp.content && cmp.content.items));
      if (Array.isArray(items)) {
        for (var i = 0; i < items.length; i++) {
          if (!items[i] || typeof items[i] !== 'object') continue;
          for (var fk in items[i]) {
            if (typeof items[i][fk] !== 'string' && items[i][fk] !== null && items[i][fk] !== undefined && !Array.isArray(items[i][fk]) && typeof items[i][fk] !== 'number') {
              items[i][fk] = _bldSafeText(items[i][fk]); fixed++;
            }
          }
        }
      }
    }
  }
  if (fixed > 0) console.log('[BLD] Sanitized ' + fixed + ' non-string fields in sections');
  return sections;
}

function bld_fmt(t){return _bldSafeText(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/^## (.+)$/gm,'<h2>$1</h2>').replace(/^### (.+)$/gm,'<h3 style="font-size:11px;color:var(--bl);margin:10px 0 4px;font-weight:700">$1</h3>').replace(/\n\n/g,'<br><br>').replace(/\n/g,'<br>');}
async function bld_post(url,data){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')},body:JSON.stringify(data)});const d=await r.json();if(!r.ok)throw new Error(d.message||d.error||'Request failed');return d;}
async function bld_get(url){const r=await fetch(url,{cache:'no-store',headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')}});return r.json();}

// ── File upload ────────────────────────────────────────────────────────────
let bld_pendingAttachments = [];
function bld_triggerUpload(){ document.getElementById('file-input')?.click(); }
async function bld_handleFileUpload(e){
  const file = e.target.files[0]; if(!file||!mid) return;
  const prev = document.getElementById('upload-preview');
  prev.style.display='flex';
  prev.innerHTML=`<div class="spinner" style="width:14px;height:14px;border-width:1.5px"></div><span>Uploading ${bld_esc(file.name)}…</span>`;
  try{
    const fd = new FormData(); fd.append('file', file);
    const r = await fetch(API+'meeting/'+mid+'/upload', {
      method:'POST', headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')}, body:fd,
    });
    const d = await r.json();
    if(!r.ok) throw new Error(d.error||'Upload failed');
    bld_pendingAttachments.push(d.file);
    const isImg = d.file.type?.startsWith('image/');
    prev.innerHTML=`${isImg?''+window.icon("image",14)+'':''+window.icon("attach",14)+''} <strong>${bld_esc(d.file.name)}</strong> ready — ${isImg?'team will analyse this image':'file attached'} <span onclick="bld_clearUpload()" style="cursor:pointer;opacity:.6;margin-left:8px">✕</span>`;
    // Show preview if image
    if(isImg){
      const img = document.createElement('img');
      img.src = d.file.url; img.style.cssText='max-width:120px;max-height:60px;border-radius:6px;margin-left:8px;border:1px solid var(--bd)';
      prev.appendChild(img);
    }
  }catch(err){
    prev.innerHTML=`<span style="color:var(--rd)">${window.icon('warning',14)} ${bld_esc(err.message)}</span>`;
    setTimeout(()=>{prev.style.display='none';},3000);
  }
  e.target.value=''; // reset
}
function bld_clearUpload(){
  bld_pendingAttachments=[];
  const p=document.getElementById('upload-preview');
  if(p){p.style.display='none';p.innerHTML='';}
}

// ── Vision analysis message render ─────────────────────────────────────────
// Handled by renderMsg — role=vision_analysis gets a special badge

// ── Patch sendMessage to include attachments ───────────────────────────────
const bld_origSend = sendMessage;
sendMessage = async function(){
  const ta=document.getElementById('cmd-input');
  const content=ta.value.trim();
  // If we have attachments and no text, use a default caption
  const caption = content || (bld_pendingAttachments.length ? 'Analyse this' : '');
  if((!caption&&!bld_pendingAttachments.length)||!mid||busy) return;
  ta.value=''; ta.style.height='auto'; busy=true;
  if(caption) renderMsg({agent_id:'user',name:'You',title:'',emoji:'👤',color:'var(--t2)',role:'user',content:caption,attachments:bld_pendingAttachments});
  try{
    await bld_post(API+'meeting/'+mid+'/message',{content:caption||'Analyse the uploaded file.',attachments:bld_pendingAttachments});
    bld_clearUpload();
  }catch(err){showMsgErr('Send failed: '+err.message);}finally{busy=false;}
};

// Patch renderMsg to show vision analysis badge and file attachments
const bld_origRender = renderMsg;
renderMsg = function(msg){
  // Add vision badge rendering
  if(msg.role==='vision_analysis'){
    const c=AGENTS[msg.agent_id]?.color||'var(--t2)',em=AGENTS[msg.agent_id]?.emoji||'🔍';
    const div=document.createElement('div');
    div.className='msg-card'; div.style.animation='fadeUp .3s ease';
    div.innerHTML=`<div class="msg-av" style="background:${c}12;border-color:${c}25">${em}</div><div class="msg-body"><div class="msg-meta"><span class="msg-name" style="color:${c}">${msg.name}</span><span class="msg-title-lbl">${msg.title}</span><span class="msg-badge" style="background:rgba(0,229,168,.1);color:var(--ac);border:1px solid rgba(0,229,168,.25)">Vision</span>${msg.analyzed_file?`<span class="msg-title-lbl">${window.icon('attach',14)} ${bld_esc(msg.analyzed_file)}</span>`:''}</div><div class="msg-bubble">${bld_fmt(msg.content)}</div></div>`;
    document.getElementById('disc-feed').appendChild(div); scrollFeed();
    return;
  }
  // Show attachments in user messages
  if(msg.attachments?.length && msg.role==='user'){
    msg.content += msg.attachments.map(a=>a.type?.startsWith('image/')?`\n<img src="${bld_esc(a.url)}" style="max-width:200px;border-radius:6px;margin-top:6px;border:1px solid var(--bd);display:block">`:`\n<a href="${bld_esc(a.url)}" target="_blank" style="color:var(--ac);font-size:10px">${window.icon('attach',14)} ${bld_esc(a.name)}</a>`).join('');
  }
  bld_origRender(msg);
};

// ── DM Modal ────────────────────────────────────────────────────────────────
let bld_dmAgentId = null;
let bld_dmModal   = null;

function bld_openDmModal(agentId, name, role, emoji, color, cardEl) {
    if (!mid) return; // only works inside an active meeting
    bld_dmAgentId = agentId;
    const modal = document.getElementById('dm-modal');
    bld_dmModal = modal;

    // Populate header
    const av = document.getElementById('dm-av');
    av.textContent  = emoji;
    av.style.cssText = `background:${color}22;border:1px solid ${color}44`;
    setEl('dm-name', name);
    setEl('dm-role', role);

    // Reset body
    document.getElementById('dm-body').innerHTML = `
        <textarea class="dm-ta" id="dm-ta" placeholder="Message ${name} privately…" rows="3"
          onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();bld_sendDm();}"></textarea>
        <div class="dm-footer">
          <span class="dm-hint">Shift+Enter new line</span>
          <button class="dm-send" id="dm-send" onclick="bld_sendDm()">Send →</button>
        </div>`;

    // Position anchored to the card, to its right
    const rect  = cardEl.getBoundingClientRect();
    const top   = Math.min(rect.top, window.innerHeight - 220);
    const left  = rect.right + 10;
    modal.style.cssText = `display:flex;flex-direction:column;top:${top}px;left:${left}px`;

    // Close on outside click
    setTimeout(() => document.addEventListener('click', dmOutsideClick), 10);
    document.getElementById('dm-ta')?.focus();
}

function dmOutsideClick(e) {
    const modal = document.getElementById('dm-modal');
    if (modal && !modal.contains(e.target) && !e.target.closest('.mac')) {
        bld_closeDmModal();
    }
}

function bld_closeDmModal() {
    const modal = document.getElementById('dm-modal');
    if (modal) modal.style.display = 'none';
    bld_dmAgentId = null;
    document.removeEventListener('click', dmOutsideClick);
}

async function bld_sendDm() {
    if (!bld_dmAgentId || !mid) return;
    const ta  = document.getElementById('dm-ta');
    const btn = document.getElementById('dm-send');
    const content = ta?.value?.trim();
    if (!content) return;

    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
    try {
        await bld_post(API + 'meeting/' + mid + '/dm', { agentId: bld_dmAgentId, content });
        // Show sent confirmation then close
        const body = document.getElementById('dm-body');
        if (body) body.innerHTML = `<div class="dm-sent">✓ Message sent to ${document.getElementById('dm-name')?.textContent || 'agent'}.<br><span style="color:var(--t3);font-size:9px">Reply will appear in the main feed.</span></div>`;
        setTimeout(bld_closeDmModal, 1800);
    } catch(e) {
        if (btn) { btn.disabled = false; btn.textContent = 'Send →'; }
        console.error('DM failed:', e);
    }
}

// Close DM modal on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') bld_closeDmModal(); });

// ── Direct assign modal ─────────────────────────────────────────────────────
function bld_openDirectAssign(){
  if(!selectedAgents.size) return;
  const chips=document.getElementById('da-chips');
  chips.innerHTML=[...selectedAgents].map(id=>{
    const a=AGENTS[id]||{};
    return `<div class="da-chip">${a.emoji||'👤'} ${a.name||id}</div>`;
  }).join('');
  const sub=document.getElementById('da-sub');
  if(sub) sub.textContent=`Assigning to: ${[...selectedAgents].map(id=>AGENTS[id]?.name||id).join(', ')} — created immediately.`;
  document.getElementById('da-title').value='';
  document.getElementById('da-desc').value='';
  document.getElementById('da-metric').value='';
  document.getElementById('da-priority').value='medium';
  document.getElementById('da-time').value='60';
  document.getElementById('da-backdrop').classList.add('visible');
}
function bld_closeDirectAssign(){
  document.getElementById('da-backdrop').classList.remove('visible');
}
async function bld_createDirectTask(){
  const title=document.getElementById('da-title').value.trim();
  if(!title||!selectedAgents.size){showToast('Task title and at least one agent are required.','warning');return;}
  const assignees=[...selectedAgents];
  const btn=document.querySelector('.da-btn-create');
  if(btn){btn.disabled=true;btn.textContent='Creating…';}
  try{
    await bld_post(API+'tasks/create',{
      title,
      description: document.getElementById('da-desc').value.trim(),
      assignees,
      coordinator: assignees.length>1?assignees[0]:'',
      priority: document.getElementById('da-priority').value,
      estimated_time: parseInt(document.getElementById('da-time').value)||60,
      estimated_tokens: 4000,
      success_metric: document.getElementById('da-metric').value.trim(),
    });
    bld_closeDirectAssign();
    clearSelection();
    await loadTasks();
  }catch(e){showToast('Error creating task: '+e.message,'error');}
  finally{if(btn){btn.disabled=false;btn.textContent='Create Task →';}}
}

function bld_startMeetingWithSelected(){
  // Pre-populate meeting topic with selected agents, navigate to Strategy Room
  const names=[...selectedAgents].map(id=>AGENTS[id]?.name||id).join(', ');
  clearSelection();
  nav('meeting');
  const ti=document.getElementById('topic-input');
  if(ti && !ti.value) ti.focus();
}

function bld_analyzeWorkload(){
  // Show workload summary in a simple alert for now (Sprint G: modal)
  const lines=[...selectedAgents].map(id=>{
    const a=AGENTS[id]||{};
    const active=allTasks.filter(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&(t.status==='ongoing'||t.status==='in_progress'||t.status==='upcoming')).length;
    const state=active===0?'Available':active<=3?'Moderate ('+active+' tasks)':'Overloaded ('+active+' tasks)';
    return `${a.emoji||''} ${a.name||id}: ${state}`;
  });
  luAlert(lines.join("\n"), "Workload Summary");
}

// ── Init ───────────────────────────────────────────────────────────────────
// [builder] extracted to builder.js (lines 2754-2791)

// ══════════════════════════════════════════════════════════════
// GLOBAL AI ASSISTANT
// ══════════════════════════════════════════════════════════════

let bld_aiOpen = false;
let bld_aiHistory = [];
let bld_aiBusy = false;
let bld_aiUnread = 0;

const BLD_AI_QUICK_BY_VIEW = {
  workspace: [
    [''+window.icon("info",14)+' Who\'s overloaded?',       'Which agents are overloaded?'],
    [''+window.icon("more",14)+' Active tasks',             'What tasks are active right now?'],
    [''+window.icon("chart",14)+' Platform status',          'Give me a quick platform status summary'],
  ],
  meeting: [
    [''+window.icon("edit",14)+' Summarize meeting',        'Summarize this meeting so far'],
    [''+window.icon("check",14)+' What was agreed?',          'What has the team agreed on so far?'],
    [''+window.icon("ai",14)+' Action items',              'List the action items from this session'],
  ],
  projects: [
    [''+window.icon("warning",14)+' Behind schedule?',         'Which projects or tasks are behind schedule?'],
    [''+window.icon("more",14)+' In progress',              'List all tasks currently in progress'],
    [''+window.icon("back",14)+' High priority',            'Show me all high priority tasks'],
  ],
  agents: [
    [''+window.icon("more",14)+' Workload overview',        'Give me a workload overview of all agents'],
    [''+window.icon("star",14)+' Most active',              'Which agent has the most active tasks?'],
  ],
  reports: [
    [''+window.icon("chart",14)+' Performance summary',      'Summarize overall platform performance'],
    [''+window.icon("check",14)+' Completed work',           'What has been completed recently?'],
  ],
};

function bld_toggleAssistant() {
  bld_aiOpen = !bld_aiOpen;
  document.getElementById('ai-panel').classList.toggle('open', bld_aiOpen);
  document.getElementById('ai-fab').classList.toggle('open', bld_aiOpen);
  if (bld_aiOpen) {
    bld_aiUnread = 0;
    const badge = document.getElementById('ai-fab-badge');
    if (badge) badge.classList.remove('visible');
    bld_updateAiContext();
    document.getElementById('ai-input')?.focus();
  }
}

function bld_updateAiContext() {
  const labels = {workspace:'Workspace',meeting:'Strategy Room',projects:'Projects',agents:'Agents',reports:'Reports & History'};
  const lbl = document.getElementById('ai-ctx-label');
  if (lbl) lbl.textContent = (labels[currentView]||currentView) + ' · ' + Object.keys(AGENTS).length + ' agents';

  // Update quick actions
  const quick = document.getElementById('ai-quick');
  if (!quick) return;
  const btns = BLD_AI_QUICK_BY_VIEW[currentView] || BLD_AI_QUICK_BY_VIEW.workspace;
  quick.innerHTML = btns.map(([label, prompt]) =>
    `<button class="ai-q-btn" onclick="bld_aiQuick(${JSON.stringify(prompt)})">${label}</button>`
  ).join('');
}

function bld_aiQuick(prompt) {
  const inp = document.getElementById('ai-input');
  if (inp) { inp.value = prompt; inp.style.height = 'auto'; }
  bld_sendAssistant();
}

function bld_buildAiContext() {
  const tasks = allTasks || [];
  const workload = Object.keys(AGENTS).map(id => {
    const active = tasks.filter(t =>
      (t.assignee === id || (t.assignees||[]).includes(id)) &&
      ['ongoing','in_progress','upcoming'].includes(t.status)
    ).length;
    const state = active === 0 ? 'available' : active <= 3 ? 'moderate' : 'overloaded';
    return { id, name: AGENTS[id]?.name||id, active, state };
  });
  const ctx = { view: currentView, workload };
  // Inject meeting context if in meeting view
  if (currentView === 'meeting' && mid) {
    ctx.meeting = { topic: document.getElementById('mtg-topic')?.textContent||'', message_count: document.querySelectorAll('.disc-msg').length };
  }
  return ctx;
}

function bld_aiAddMsg(role, content, opts={}) {
  const feed = document.getElementById('ai-feed');
  if (!feed) return;

  const wrap = document.createElement('div');
  wrap.className = `ai-msg ai-msg-${role}`;

  if (role === 'assistant' || role === 'agent') {
    const name = opts.agentName ? `${opts.agentEmoji||'✦'} ${opts.agentName}` : '✦ Assistant';
    const color = opts.agentColor ? `style="color:${opts.agentColor}"` : '';
    wrap.innerHTML = `<span class="ai-msg-label" ${color}>${name}</span><div class="ai-msg-bubble" ${opts.agentColor?`style="border-color:${opts.agentColor}33"`:''}>${bld_fmt(content)}</div>`;
  } else {
    wrap.innerHTML = `<span class="ai-msg-label">You</span><div class="ai-msg-bubble">${bld_esc(content)}</div>`;
  }

  // If there's a tool action to show
  if (opts.toolCall) {
    const tc = opts.toolCall;
    const toolLabels = {
      assign_task:         ['＋','Assign Task',       `To: ${(tc.params?.assignees||[]).join(', ')} — "${tc.params?.title||''}"`],
      start_meeting:       [''+window.icon("more",14)+'','Start Meeting',      `Topic: "${tc.params?.topic||''}"`],
      navigate:            ['→', 'Navigate',           `Go to ${tc.params?.view||''}`],
      show_agent_workload: [''+window.icon("chart",14)+'','Show Workload',       'Viewing agent workload'],
      list_tasks:          [''+window.icon("more",14)+'','List Tasks',         'Filtering task list'],
      summarize_meeting:   [''+window.icon("edit",14)+'','Summarize Meeting',  'Pulling session summary'],
    };
    const [icon, label, desc] = toolLabels[tc.tool] || [''+window.icon("ai",14)+'', tc.tool, ''];
    const actionDiv = document.createElement('div');
    actionDiv.className = 'ai-tool-action';
    actionDiv.innerHTML = `<span class="ata-icon">${icon}</span><div><div class="ata-label">${label}</div><div class="ata-desc">${desc}</div></div>`;
    actionDiv.onclick = () => bld_executeAiTool(tc);
    wrap.appendChild(actionDiv);
  }

  feed.appendChild(wrap);
  feed.scrollTop = feed.scrollHeight;
}

function bld_aiShowTyping() {
  const feed = document.getElementById('ai-feed');
  if (!feed) return;
  const d = document.createElement('div');
  d.id = 'ai-typing';
  d.className = 'ai-msg ai-msg-assistant';
  d.innerHTML = `<span class="ai-msg-label">✦ Assistant</span><div class="ai-typing"><div class="ai-typing-d"></div><div class="ai-typing-d"></div><div class="ai-typing-d"></div></div>`;
  feed.appendChild(d);
  feed.scrollTop = feed.scrollHeight;
}
function bld_aiHideTyping() { document.getElementById('ai-typing')?.remove(); }

async function bld_sendAssistant() {
  const inp = document.getElementById('ai-input');
  const message = inp?.value?.trim();
  if (!message || bld_aiBusy) return;

  inp.value = ''; inp.style.height = 'auto';
  bld_aiBusy = true;
  document.getElementById('ai-send').disabled = true;

  bld_aiAddMsg('user', message);
  bld_aiHistory.push({ role:'user', content: message });
  bld_aiShowTyping();

  // Open panel if closed (command via keyboard shortcut etc.)
  if (!bld_aiOpen) bld_toggleAssistant();

  try {
    const ctx = bld_buildAiContext();
    const r = await bld_post(API + 'assistant', { message, context: ctx, history: bld_aiHistory.slice(-8) });
    bld_aiHideTyping();

    const resp = r.response || '';
    const opts = {};

    if (r.agent_response) {
      opts.agentName  = r.agent_name;
      opts.agentEmoji = r.agent_emoji;
      opts.agentColor = r.agent_color;
    }
    if (r.tool_call) {
      opts.toolCall = r.tool_call;
      // Auto-execute non-destructive navigations silently
      if (r.tool_call.tool === 'navigate') bld_executeAiTool(r.tool_call);
    }

    if (resp) {
      bld_aiAddMsg(r.agent_response ? 'agent' : 'assistant', resp, opts);
      bld_aiHistory.push({ role:'assistant', content: resp });
    } else if (r.tool_call && !resp) {
      bld_aiAddMsg('assistant', `I'll ${(r.tool_call.tool||'').replace(/_/g,' ')} that for you.`, opts);
    }

    // Unread badge if panel closed
    if (!bld_aiOpen) {
      bld_aiUnread++;
      const badge = document.getElementById('ai-fab-badge');
      if (badge) { badge.textContent = bld_aiUnread; badge.classList.add('visible'); }
    }
  } catch(e) {
    bld_aiHideTyping();
    bld_aiAddMsg('assistant', ''+window.icon("warning",14)+' ' + (e.message||'Something went wrong. Please try again.'));
  } finally {
    bld_aiBusy = false;
    document.getElementById('ai-send').disabled = false;
  }
}

function bld_executeAiTool(tc) {
  if (!tc?.tool) return;
  switch(tc.tool) {
    case 'assign_task': {
      const assignees = tc.params?.assignees || [];
      assignees.forEach(id => { selectedAgents.add(id); document.getElementById('node-'+id)?.classList.add('selected'); });
      updateSelectionToolbar();
      if (currentView !== 'workspace') nav('workspace');
      setTimeout(() => {
        bld_openDirectAssign();
        if (tc.params?.title)       setTimeout(()=>{ const el=document.getElementById('da-title'); if(el) el.value=tc.params.title; },100);
        if (tc.params?.description) setTimeout(()=>{ const el=document.getElementById('da-desc');  if(el) el.value=tc.params.description; },100);
        if (tc.params?.priority)    setTimeout(()=>{ const el=document.getElementById('da-priority'); if(el) el.value=tc.params.priority; },100);
      }, currentView !== 'workspace' ? 400 : 50);
      break;
    }
    case 'start_meeting': {
      nav('meeting');
      if (tc.params?.topic) setTimeout(()=>{ const el=document.getElementById('topic-input'); if(el){el.value=tc.params.topic;el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';} },300);
      break;
    }
    case 'navigate': {
      const v = tc.params?.view;
      if (v && ['workspace','meeting','projects','agents','reports'].includes(v)) nav(v);
      break;
    }
    case 'show_agent_workload': {
      nav('workspace');
      break;
    }
    case 'list_tasks': {
      nav('projects');
      break;
    }
    case 'summarize_meeting': {
      if (currentView !== 'meeting') nav('meeting');
      break;
    }
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// LEGACY VISUAL EDITOR REMOVED — 2026-04-17
// ~9624 lines of section-based editor, Arthur copilot,
// AI copy generation, drag-drop, inline edit, canvas renderer removed.
// Website CRUD, publish flow, and connect-existing preserved below.
// ═══════════════════════════════════════════════════════════════════════════════



async function wsLoadSites(){
  try{
    // GET lu/v1/websites — Core endpoint reading lu_websites table
    const r=await fetch(API+'websites',{headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'}});
    if(!r.ok) throw new Error('HTTP '+r.status);
    var _wsResp = (await r.json());
    if (_wsResp == null) _wsResp = {};
    // Dual-shape handling: accept legacy array response AND current {websites,usage} object.
    if (Array.isArray(_wsResp)) {
      wsSites = _wsResp;
    } else if (_wsResp && Array.isArray(_wsResp.websites)) {
      wsSites = _wsResp.websites;
    } else if (_wsResp && _wsResp.data && Array.isArray(_wsResp.data.websites)) {
      wsSites = _wsResp.data.websites;
    } else {
      console.warn('[wsLoadSites] unexpected response shape:', _wsResp);
      wsSites = [];
    }
    console.log('[wsLoadSites] parsed', wsSites.length, 'site(s):', wsSites.map(function(s){return s && (s.id+':'+(s.name||s.title||'?'));}).join(', '));
    if (_wsResp && _wsResp.usage && typeof _luUpdateWebsiteUsage === 'function') _luUpdateWebsiteUsage(_wsResp.usage);
    wsUpdateStats(); wsRenderGrid();
  }catch(e){
    console.error('[wsLoadSites]',e);
    wsSites=[]; wsUpdateStats(); wsRenderGrid();
  }
}

function wsUpdateStats(){
  const total=wsSites.length,pub=wsSites.filter(s=>s.publish_state==='published').length,dom=wsSites.filter(s=>s.domain).length;
  const el=id=>document.getElementById(id);
  if(el('ws-stat-total')) el('ws-stat-total').textContent=total;
  if(el('ws-stat-pub'))   el('ws-stat-pub').textContent=pub;
  if(el('ws-stat-draft')) el('ws-stat-draft').textContent=total-pub;
  if(el('ws-stat-domain'))el('ws-stat-domain').textContent=dom;
}

function wsRenderGrid(){
  const grid=document.getElementById('ws-grid'); if(!grid) return;
  if (!Array.isArray(wsSites)) { console.warn('[wsRenderGrid] wsSites not an array:', wsSites); wsSites = []; }
  if(!wsSites.length){
    grid.innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:80px 20px;color:var(--t3)"><div style="font-size:48px;margin-bottom:16px">${window.icon('globe',14)}</div><div style="font-size:16px;font-weight:600;color:var(--t2);margin-bottom:8px">No websites yet</div><div style="font-size:13px;margin-bottom:24px">Create your first multi-page website</div><button class="ct-btn primary" onclick="wsShowCreate()">+ New Website</button></div>`;
    return;
  }
  grid.innerHTML=wsSites.map(function(s){ try {
    const isExt=s.type==='external'||!!s.external_url;
    const platform=s.platform||(s.settings_json?((typeof s.settings_json==='string'?JSON.parse(s.settings_json):s.settings_json).platform||''):'');

    // Badge
    let badgeClass,badgeText,badgeStyle='';
    if(isExt){
      if(platform==='wordpress'){badgeText=''+window.icon("link",14)+' WordPress';badgeStyle='background:rgba(59,130,246,.15);color:#3B82F6;border:1px solid rgba(59,130,246,.25)';}
      else{badgeText=''+window.icon("link",14)+' '+(platform||'external');badgeStyle='background:rgba(0,229,168,.12);color:#00E5A8;border:1px solid rgba(0,229,168,.25)';}
      badgeClass='connected';
    }else{
      const pub=s.publish_state==='published'?'published':(s.status==='draft'?'draft':'unpublished');
      badgeClass=pub;badgeText=pub;badgeStyle='';
    }

    // Thumbnail
    let thumbContent;
    if(isExt&&s.thumbnail_url){
      thumbContent=`<div class="ws-thumb" style="background-image:url(${s.thumbnail_url});background-size:cover;background-position:center"><span class="ws-badge" style="${badgeStyle};position:absolute;top:8px;left:8px;font-size:10px;padding:2px 8px;border-radius:5px;font-weight:600">${badgeText}</span></div>`;
    }else{
      thumbContent=`<div class="ws-thumb"><span>${window.icon('globe',14)}</span><span class="ws-badge ${badgeClass}" ${badgeStyle?'style="'+badgeStyle+'"':''}>${badgeText}</span></div>`;
    }

    // Meta line
    const upd=s.updated_at?new Date(s.updated_at).toLocaleDateString():'';
    let metaLine;
    if(isExt){
      let host='';try{host=new URL(s.external_url||'').hostname;}catch(e){}
      metaLine=host+(platform?' · '+platform.charAt(0).toUpperCase()+platform.slice(1):'')+' · External';
    }else{
      metaLine='/'+bld_escH(s.slug)+' · '+(s.page_count||0)+' pages'+(upd?' · '+upd:'');
    }

    // Action buttons
    let actions;
    if(isExt){
      const extUrl=bld_escH(s.external_url||'');
      actions=`<a href="${extUrl}" target="_blank" rel="noopener" class="ct-btn" style="font-size:11px;padding:4px 10px;text-decoration:none" onclick="event.stopPropagation()">Visit ↗</a>`
        +`<button class="ct-btn" onclick="event.stopPropagation();_wsExtSeoAudit(${s.id},'${extUrl.replace(/'/g,"\\'")}')" style="font-size:11px;padding:4px 10px">SEO Audit</button>`
        +(platform==='wordpress'?`<button class="ct-btn" onclick="event.stopPropagation();_wsExtPluginInfo()" style="font-size:11px;padding:4px 10px;color:var(--bl)">Install Plugin</button>`:'')
        +`<button onclick="event.stopPropagation();wsDelete(${s.id})" style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);border-radius:5px;color:#F87171;padding:4px 7px;font-size:11px;cursor:pointer">✕</button>`;
    }else{
      actions=`<button class="ct-btn" onclick="wsOpenSite(${s.id})" style="font-size:11px;padding:4px 10px">Edit</button>`
        +`<button class="ct-btn primary" onclick="wsShowPublish(${s.id})" style="font-size:11px;padding:4px 10px">Publish</button>`
        +`<button onclick="wsDelete(${s.id})" style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);border-radius:5px;color:#F87171;padding:4px 7px;font-size:11px;cursor:pointer">✕</button>`;
    }

    // PATCH (clickable site names, 2026-05-09) — title links to the live
    // site in a new tab. URL precedence: external_url > custom_domain >
    // subdomain (when published) > /storage/sites/{id}/index.html. The
    // anchor calls event.stopPropagation() so clicking the title doesn't
    // also trigger the card-click editor (wsOpenSite).
    var _liveUrl;
    if (isExt && s.external_url) {
      _liveUrl = s.external_url;
    } else if (s.custom_domain) {
      _liveUrl = 'https://' + s.custom_domain;
    } else if (s.subdomain && (s.publish_state === 'published' || s.status === 'published')) {
      _liveUrl = 'https://' + (String(s.subdomain).indexOf('.') === -1
        ? s.subdomain + '.levelupgrowth.io'
        : s.subdomain);
    } else if (s.domain) {
      _liveUrl = 'https://' + s.domain;
    } else {
      _liveUrl = '/storage/sites/' + s.id + '/index.html';
    }
    var _titleHtml = '<a href="' + bld_escH(_liveUrl) + '" target="_blank" rel="noopener" '
      + 'onclick="event.stopPropagation()" '
      + 'style="color:inherit;text-decoration:none;border-bottom:1px dashed rgba(255,255,255,0.20)" '
      + 'onmouseover="this.style.color=\'var(--p)\';this.style.borderBottomColor=\'var(--p)\'" '
      + 'onmouseout="this.style.color=\'inherit\';this.style.borderBottomColor=\'rgba(255,255,255,0.20)\'">'
      + bld_escH(s.title || s.name) + '</a>';

    return `<div class="ws-card" onclick="${isExt?'':'wsOpenSite('+s.id+')'}">
      ${thumbContent}
      <div class="ws-info">
        <div class="ws-title">${_titleHtml}</div>
        <div class="ws-meta">${metaLine}</div>
        ${s.domain&&!isExt?`<div style="margin-bottom:6px;font-size:10px;color:var(--bl)">${window.icon('globe',14)} ${bld_escH(s.domain)}</div>`:''}
        <div class="ws-footer">
          <div class="ws-stat" style="font-size:11px;color:var(--t3)">${bld_escH((s.description||'').slice(0,40))||'No description'}</div>
          <div class="ws-actions" onclick="event.stopPropagation()">
            ${actions}
          </div>
        </div>
      </div>
    </div>`;
  } catch(_cardErr) { console.error('[wsRenderGrid card]', s && s.id, _cardErr); return '<div class="ws-card" style="padding:16px;color:#F87171;border:1px solid rgba(248,113,113,.3);border-radius:8px">Error rendering site '+(s&&s.id)+': '+(_cardErr && _cardErr.message)+'</div>'; }
  }).join('');
}

// External website helpers
async function _wsExtSeoAudit(websiteId,url){
  if(!url)return;
  showToast('Running SEO audit on '+url+'…','info');
  try{
    var r=await fetch(API+'seo/deep-audit',{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},body:JSON.stringify({url:url})});
    var d=await r.json();
    if(d.score!==undefined)showToast('SEO audit complete! Score: '+d.score+'/100','success');
    else showToast('Audit submitted','info');
  }catch(e){showToast('Audit failed: '+e.message,'error');}
}

function _wsExtPluginInfo(){
  var existing=document.getElementById('ws-plugin-modal');
  if(existing){existing.remove();return;}
  var ov=document.createElement('div');ov.id='ws-plugin-modal';
  ov.style.cssText='position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center';
  ov.innerHTML='<div style="background:var(--s1);border:1px solid var(--bd);border-radius:16px;width:90%;max-width:480px;padding:28px">'
    +'<div style="font-family:var(--fh);font-size:18px;font-weight:700;color:var(--t1);margin-bottom:16px">'+window.icon("link",14)+' Install LevelUp WP Connector</div>'
    +'<div style="font-size:13px;color:var(--t2);line-height:1.7;margin-bottom:16px">Install the LevelUp WP Connector plugin on your WordPress site to enable:<br><br>'
    +'<strong>'+window.icon("check",14)+' AI content publishing</strong> — Sarah can publish articles directly to your blog<br>'
    +'<strong>'+window.icon("check",14)+' Full SEO management</strong> — Manage meta tags, schema, and sitemaps<br>'
    +'<strong>'+window.icon("check",14)+' Real-time sync</strong> — Changes reflect immediately on your live site</div>'
    +'<div style="background:var(--s2);border-radius:8px;padding:14px;font-size:12px;color:var(--t3);margin-bottom:16px"><strong>Installation:</strong><br>1. Download the plugin zip<br>2. WordPress Admin → Plugins → Add New → Upload Plugin<br>3. Activate and enter your LevelUp API key</div>'
    +'<div style="display:flex;gap:8px"><button class="ct-btn primary" style="padding:8px 16px" onclick="showToast(\'Plugin download coming soon\',\'info\')">⬇ Download Plugin</button>'
    +'<button class="ct-btn" style="padding:8px 16px" onclick="document.getElementById(\'ws-plugin-modal\').remove()">Close</button></div></div>';
  ov.addEventListener('click',function(e){if(e.target===ov)ov.remove();});
  document.body.appendChild(ov);
}


// Template website editor view
function _wsShowTemplateEditor(site) {
  var wsId = site.id;
  var previewUrl = '/api/builder/websites/' + wsId + '/preview';
  var siteName = bld_escH(site.title || site.name || 'Website');

  var html =
    '<div id="template-editor-view" style="position:fixed;inset:0;z-index:9000;background:#0F1117;display:flex;flex-direction:column">' +
    // Toolbar
    '<div style="height:52px;background:var(--s1,#161927);border-bottom:1px solid var(--bd);display:flex;align-items:center;padding:0 16px;gap:12px;flex-shrink:0">' +
      '<button onclick="wsCloseTemplateEditor()" style="background:none;border:1px solid var(--bd);color:var(--t1);padding:5px 12px;border-radius:6px;cursor:pointer;font-size:13px">\u2190 Back</button>' +
      '<span style="color:var(--t1);font-weight:600;font-size:14px">' + siteName + '</span>' +
      '<span style="flex:1"></span>' +
      '<span style="color:var(--t3);font-size:11px">Click any text to edit</span>' +
      '<button onclick="wsSaveAllEdits(' + wsId + ')" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:13px">Save</button>' +
      '<button onclick="wsDoPublish(' + wsId + ')" style="background:var(--p,#6C5CE7);border:none;color:#fff;padding:5px 16px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">'+window.icon('rocket',18)+' Publish</button>' +
    '</div>' +
    // Main
    '<div style="flex:1;display:flex;overflow:hidden">' +
      // Arthur sidebar
      '<div style="width:300px;background:var(--s1,#161927);border-right:1px solid var(--bd);display:flex;flex-direction:column;flex-shrink:0">' +
        '<div style="padding:14px;border-bottom:1px solid var(--bd)">' +
          '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><div style="width:28px;height:28px;background:var(--p);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px">'+window.icon('ai',18)+'</div><div style="color:var(--t1);font-weight:600;font-size:13px">Arthur</div></div>' +
          '<div style="color:var(--t3);font-size:11px">Ask me to change colors, layout, or generate images</div>' +
        '</div>' +
        '<div id="t3-arthur-feed" style="flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px">' +
          '<div style="background:var(--s2);border-radius:8px;padding:8px 10px;font-size:11px;color:var(--t2)">Try: "Change colors to dark blue" or "Make the hero taller"</div>' +
        '</div>' +
        '<div style="padding:10px;border-top:1px solid var(--bd);display:flex;gap:6px">' +
          '<input id="t3-arthur-input" type="text" placeholder="Ask Arthur..." style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:6px;color:var(--t1);padding:7px 10px;font-size:12px;outline:none;font-family:inherit" onkeydown="if(event.key===\'Enter\')_t3ArthurSend(' + wsId + ')">' +
          '<button onclick="_t3ArthurSend(' + wsId + ')" style="background:var(--p);border:none;color:#fff;padding:7px 10px;border-radius:6px;cursor:pointer;font-size:12px">\u2192</button>' +
        '</div>' +
      '</div>' +
      // Preview iframe
      '<div style="flex:1;position:relative">' +
        '<iframe id="t3-preview" src="' + previewUrl + '" style="width:100%;height:100%;border:none" onload="_t3InitEditing(this)"></iframe>' +
        '<div id="t3-saved" style="display:none;position:absolute;top:10px;right:10px;background:var(--ac,#00E5A8);color:#000;padding:5px 12px;border-radius:16px;font-size:11px;font-weight:600">\u2713 Saved</div>' +
      '</div>' +
    '</div>' +
    '</div>';

  document.body.insertAdjacentHTML('beforeend', html);
}

function wsCloseTemplateEditor() {
  var v = document.getElementById('template-editor-view');
  if (v) v.remove();
}

function _t3InitEditing(iframe) {
  // Editing is already injected server-side in the preview route
  // Listen for field changes from iframe
  window.addEventListener('message', _t3HandleMessage);
}

var _t3SaveTimer = null;
var _t3PendingFields = {};

function _t3HandleMessage(e) {  if (!e.data || !e.data.type) return;  if (e.data.type === "block-selected") {    window._t3SelectedBlock = e.data.block_id;    var lbl = document.getElementById("t3-context-label");    if (lbl) { lbl.textContent = "Editing: " + e.data.block_label; lbl.style.color = "#6C5CE7"; }    var inp = document.getElementById("t3-arthur-input");    if (inp) { inp.placeholder = "Change " + e.data.block_label + "..."; inp.focus(); }    if (typeof _t3ShowSuggestions === "function") _t3ShowSuggestions(e.data.block_id);    return;  }  if (e.data.type === "block-deselected") {    window._t3SelectedBlock = null;    window._t3SelectedElement = null;    var lbl = document.getElementById("t3-context-label");    if (lbl) { lbl.textContent = "Click a section"; lbl.style.color = ""; }    var inp = document.getElementById("t3-arthur-input");    if (inp) inp.placeholder = "Ask Arthur...";    if (typeof _t3ShowSuggestions === "function") _t3ShowSuggestions(null);    return;  }  if (e.data.type === "element-selected") {    if (e.data.block_id) window._t3SelectedBlock = e.data.block_id;    window._t3SelectedElement = e.data.element_key;    var lbl = document.getElementById("t3-context-label");    if (lbl) { lbl.textContent = "Editing: " + e.data.element_label; lbl.style.color = "#F97316"; }    var inp = document.getElementById("t3-arthur-input");    if (inp) { var tail = (e.data.element_label || "").split(" \u203A ").pop(); inp.placeholder = "Change " + tail + "..."; inp.focus(); }    return;  }  if (e.data.type === "element-deselected") {    window._t3SelectedElement = null;    var lbl2 = document.getElementById("t3-context-label");    if (lbl2 && window._t3SelectedBlock) { var bn = window._t3SelectedBlock; lbl2.textContent = "Editing: " + bn.charAt(0).toUpperCase() + bn.slice(1) + " Section"; lbl2.style.color = "#6C5CE7"; }    var inp2 = document.getElementById("t3-arthur-input");    if (inp2 && window._t3SelectedBlock) inp2.placeholder = "Change " + window._t3SelectedBlock + "...";    return;  }  if (e.data.type === "image-clicked") {
    _t3ShowImagePanel(e.data);
    return;
  }
  if (e.data.type === "field-changed") {    _t3PendingFields[e.data.field] = { value: e.data.value, websiteId: e.data.websiteId };
    // Debounce save — 2 seconds after last edit
    clearTimeout(_t3SaveTimer);
    _t3SaveTimer = setTimeout(_t3FlushSaves, 2000);
  }
}

// ── Builder image click-to-replace panel (2026-04-19) ──────────
var _t3ImgPanelEl = null;
var _t3ImgPanelInfo = null;

function _t3ShowImagePanel(info) {
  _t3HideImagePanel();
  _t3ImgPanelInfo = info;
  var iframe = document.getElementById('t3-preview');
  if (!iframe) return;
  var ir = iframe.getBoundingClientRect();
  var r = info.rect || { left:0, top:0, right:0, bottom:0, width:0, height:0 };
  var isLogo = info.field === 'logo_url';
  var panel = document.createElement('div');
  panel.id = 't3-img-panel';
  var panelW = isLogo ? 320 : 420;
  panel.style.cssText = 'position:fixed;z-index:99999;background:var(--s1,#1a1a24);border:1px solid var(--s3,rgba(255,255,255,0.12));border-radius:12px;padding:' + (isLogo ? '14px' : '10px') + ';box-shadow:0 12px 40px rgba(0,0,0,0.5);display:flex;flex-direction:' + (isLogo ? 'column' : 'row') + ';gap:' + (isLogo ? '10px' : '8px') + ';align-items:' + (isLogo ? 'stretch' : 'center') + ';font-family:var(--fb,system-ui);color:var(--t1,#fff);min-width:' + panelW + 'px';
  var panelLeft = Math.max(8, Math.min(window.innerWidth - panelW - 8, ir.left + r.left));
  var panelTop = Math.max(8, Math.min(window.innerHeight - 80, ir.top + r.bottom + 8));
  panel.style.left = panelLeft + 'px';
  panel.style.top  = panelTop + 'px';

  var btnCss = 'background:var(--s2,rgba(255,255,255,0.06));border:1px solid var(--s3,rgba(255,255,255,0.12));color:var(--t1,#fff);padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-family:var(--fb,system-ui);text-align:left;';
  var primaryCss = btnCss + 'background:var(--p,#6C5CE7);border-color:var(--p,#6C5CE7);color:#fff;';
  var dangerCss = btnCss + 'color:#F87171;';

  if (isLogo) {
    panel.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">' +
        '<div style="font-size:14px;font-weight:600;color:var(--t1,#fff)">\uD83C\uDFF7 Website Logo</div>' +
        '<button type="button" id="t3-img-close" style="' + btnCss + 'padding:4px 8px;font-size:14px" title="Close">&times;</button>' +
      '</div>' +
      '<button type="button" id="t3-img-choose" style="' + primaryCss + '">\uD83D\uDCF7 Choose from Library</button>' +
      '<button type="button" id="t3-img-upload" style="' + btnCss + '">\u2B06 Upload Logo</button>' +
      '<button type="button" id="t3-img-remove" style="' + dangerCss + '">\u2715 Remove Logo</button>' +
      '<div style="font-size:11px;color:var(--t2,rgba(255,255,255,0.55));line-height:1.5;margin-top:4px;padding-top:8px;border-top:1px solid var(--s3,rgba(255,255,255,0.08))">PNG with transparent background recommended.<br>Minimum 300 \u00d7 100 px.</div>' +
      '<input type="file" id="t3-img-file" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="display:none">';
  } else {
    panel.innerHTML =
      '<div style="font-size:12px;color:var(--t2,rgba(255,255,255,0.65));margin-right:6px">Image:</div>' +
      '<button type="button" id="t3-img-choose" style="' + primaryCss + '">Choose Image</button>' +
      '<button type="button" id="t3-img-url" style="' + btnCss + '">Paste URL</button>' +
      '<button type="button" id="t3-img-remove" style="' + dangerCss + '">Remove</button>' +
      '<button type="button" id="t3-img-close" style="' + btnCss + 'padding:6px 10px" title="Close">&times;</button>';
  }

  document.body.appendChild(panel);
  _t3ImgPanelEl = panel;

  document.getElementById('t3-img-choose').onclick = _t3ImgChoose;
  if (!isLogo) document.getElementById('t3-img-url').onclick = _t3ImgPasteUrl;
  if (isLogo) {
    document.getElementById('t3-img-upload').onclick = _t3LogoUpload;
    document.getElementById('t3-img-file').onchange  = _t3LogoFileChosen;
  }
  document.getElementById('t3-img-remove').onclick = _t3ImgRemove;
  document.getElementById('t3-img-close').onclick  = _t3HideImagePanel;

  // Dismiss on outside click
  setTimeout(function(){ document.addEventListener('mousedown', _t3ImgPanelOutsideClick, { once: false }); }, 50);
}

function _t3ImgPanelOutsideClick(ev) {
  if (!_t3ImgPanelEl) return;
  if (_t3ImgPanelEl.contains(ev.target)) return;
  // Ignore clicks inside the preview iframe (re-click = re-show panel)
  // or inside any media-picker / modal overlay (class or id starts with mp-).
  var t = ev.target;
  if (t && t.closest) {
    if (t.closest('#t3-preview')) return;
    if (t.closest('[id^="mp-"],[class*="mp-"],.modal,[role="dialog"]')) return;
  }
  _t3HideImagePanel();
}

function _t3HideImagePanel() {
  if (_t3ImgPanelEl && _t3ImgPanelEl.parentNode) _t3ImgPanelEl.parentNode.removeChild(_t3ImgPanelEl);
  _t3ImgPanelEl = null;
  _t3ImgPanelInfo = null;
  document.removeEventListener('mousedown', _t3ImgPanelOutsideClick);
}

function _t3ImgChoose() {
  var info = _t3ImgPanelInfo;
  if (!info) return;
  _t3HideImagePanel();
  if (typeof window.openMediaPicker !== 'function') {
    if (typeof showToast === 'function') showToast('Media picker unavailable', 'error');
    return;
  }
  window.openMediaPicker({ type: 'image', context: 'builder', field: info.field }, function(file) {
    if (!file) return;
    var url = file.file_url || file.url || file.src || '';
    if (!url) return;
    _t3CheckImageDims(url, info.recommended, function() {
      _t3ReplaceImage(info.websiteId, info.field, url);
    });
  });
}

function _t3ImgPasteUrl() {
  var info = _t3ImgPanelInfo;
  if (!info) return;
  var url = prompt('Paste image URL:', info.currentSrc || '');
  if (!url) return;
  url = url.trim();
  if (!/^https?:\/\//.test(url) && !/^\//.test(url)) {
    alert('URL must start with http(s):// or /');
    return;
  }
  _t3HideImagePanel();
  _t3CheckImageDims(url, info.recommended, function() {
    _t3ReplaceImage(info.websiteId, info.field, url);
  });
}

function _t3ImgRemove() {
  var info = _t3ImgPanelInfo;
  if (!info) return;
  if (!confirm('Remove this image?')) return;
  _t3HideImagePanel();
  _t3ReplaceImage(info.websiteId, info.field, '');
}

function _t3CheckImageDims(url, rec, proceed) {
  if (!rec || !rec.recommended_width || !rec.recommended_height) { proceed(); return; }
  var rw = rec.recommended_width, rh = rec.recommended_height;
  var img = new Image();
  img.onload = function() {
    var wOk = img.naturalWidth  >= rw * 0.85;
    var hOk = img.naturalHeight >= rh * 0.85;
    if (wOk && hOk) { proceed(); return; }
    var msg = 'This image is ' + img.naturalWidth + '×' + img.naturalHeight + '. '
            + 'Recommended: ' + rw + '×' + rh
            + (rec.aspect_ratio ? ' (' + rec.aspect_ratio + ')' : '')
            + '.\n\nUse it anyway?';
    if (confirm(msg)) proceed();
  };
  img.onerror = function() { proceed(); };
  img.src = url;
}

// Targeted DOM update via postMessage to the preview iframe.
// No cross-origin concerns, no contentDocument access, no silent failures.
// Iframe handles its own DOM update via a matching listener injected in api.php.
function _t3UpdateImageInIframe(field, newUrl) {
  var iframe = document.getElementById('t3-preview');
  if (!iframe || !iframe.contentWindow) return;
  iframe.contentWindow.postMessage({
    type: 'lu-update-image',
    field: field,
    url: newUrl || ''
  }, '*');
}

function _t3ReplaceImage(websiteId, field, url) {
  if (!websiteId || !field) return;
  var token = localStorage.getItem('lu_token') || '';
  // Instant client-side update via postMessage — iframe updates its own DOM.
  _t3UpdateImageInIframe(field, url);
  // Persist server-side. No reload on success — the iframe already reflects the change.
  fetch('/api/builder/websites/' + websiteId + '/fields/' + field, {
    method: 'PUT',
    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
    body: JSON.stringify({ value: url })
  }).then(function(r){
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json().catch(function(){ return {}; });
  }).then(function(d){
    if (typeof showToast === 'function') showToast(url ? 'Image updated' : 'Image removed', 'success');
  }).catch(function(err){
    if (typeof showToast === 'function') showToast('Save failed: ' + err.message, 'error');
    console.error('[t3 image replace]', err);
  });
}

function _t3LogoUpload() {
  var f = document.getElementById('t3-img-file');
  if (f) f.click();
}

function _t3LogoFileChosen(ev) {
  var info = _t3ImgPanelInfo;
  var file = ev.target.files && ev.target.files[0];
  if (!info || !file) return;
  if (file.size > 2 * 1024 * 1024) {
    alert('Logo must be under 2MB.');
    return;
  }
  var allowed = ['image/png','image/jpeg','image/svg+xml','image/webp'];
  if (allowed.indexOf(file.type) === -1) {
    alert('Logo must be PNG, JPG, SVG, or WEBP.');
    return;
  }
  var websiteId = info.websiteId;
  _t3HideImagePanel();
  var fd = new FormData();
  fd.append('logo', file);
  var token = localStorage.getItem('lu_token') || '';
  if (typeof showToast === 'function') showToast('Uploading logo...', 'info');
  fetch('/api/builder/websites/' + websiteId + '/logo', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token },
    body: fd
  }).then(function(r){
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json().catch(function(){ return {}; });
  }).then(function(d){
    if (!d || d.success === false) {
      if (typeof showToast === 'function') showToast((d && d.error) || 'Logo upload failed', 'error');
      return;
    }
    _t3UpdateImageInIframe('logo_url', d.logo_url || '');
    if (typeof showToast === 'function') showToast('Logo uploaded', 'success');
  }).catch(function(err){
    if (typeof showToast === 'function') showToast('Upload failed: ' + err.message, 'error');
    console.error('[t3 logo upload]', err);
  });
}

function _t3FlushSaves() {
  var token = localStorage.getItem('lu_token') || '';
  Object.keys(_t3PendingFields).forEach(function(field) {
    var p = _t3PendingFields[field];
    fetch('/api/builder/websites/' + p.websiteId + '/fields/' + field, {
      method: 'PUT',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ value: p.value })
    });
  });
  _t3PendingFields = {};
  // Show saved indicator
  var ind = document.getElementById('t3-saved');
  if (ind) { ind.style.display = 'block'; setTimeout(function() { ind.style.display = 'none'; }, 2000); }
}

function wsSaveAllEdits(websiteId) {
  _t3FlushSaves();
  if (typeof showToast === 'function') showToast('Changes saved', 'success');
}

async function _t3ArthurSend(websiteId) {
  var inp = document.getElementById('t3-arthur-input');
  if (!inp) return;
  var msg = inp.value.trim();
  if (!msg) return;
  inp.value = '';
  inp.disabled = true;

  var feed = document.getElementById('t3-arthur-feed');
  if (feed) {
    feed.innerHTML += '<div style="display:flex;justify-content:flex-end"><div style="background:var(--p);color:#fff;border-radius:10px;padding:7px 10px;font-size:12px;max-width:85%">' + bld_escH(msg) + '</div></div>';
    feed.innerHTML += '<div id="t3-typing" style="background:var(--s2);border-radius:10px;padding:7px 10px;font-size:12px;color:var(--t3)">Arthur is working on it... '+window.icon('ai',18)+'</div>';
    feed.scrollTop = feed.scrollHeight;
  }

  try {
    var t = localStorage.getItem('lu_token') || '';
    console.log('[Arthur send]', { block_id: window._t3SelectedBlock || null, element_key: window._t3SelectedElement || null, message: msg, pageId: (typeof bldCurrentPageId !== 'undefined' ? bldCurrentPageId : null) });
    var r, d;
    var pid = (typeof bldCurrentPageId !== 'undefined' && bldCurrentPageId) ? bldCurrentPageId : null;
    var triedCanonical = false;
    if (pid) {
      // PATCH 10 Fix 2 — Try canonical Patch 8.5 endpoint first.
      // This mutates pages.sections_json with snapshots; works only on
      // sites that have sections_json populated (i.e. not legacy static-HTML
      // sites like Chef Red). The endpoint has a 422 legacy gate that
      // returns {error,legacy:true} when sections_json is empty — fall back
      // to the legacy regex closure in that case to keep Chef Red working
      // until T3.4.
      triedCanonical = true;
      r = await fetch('/api/builder/pages/' + pid + '/arthur-edit', {
        method: 'POST',
        headers: {'Authorization': 'Bearer ' + t, 'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({message: msg, section_index: null})
      });
      d = await r.json();
      if (r.status === 422 && d && d.legacy === true) {
        console.log('[Arthur] canonical 422 legacy gate — falling back to legacy closure');
        triedCanonical = false; // signal fallback path
      } else {
        // Adapt Patch 8.5 response shape to the legacy `method/message` shape
        // the rest of this function expects, so the UI rendering branches keep working.
        if (d && d.success) {
          d = {
            method: 'action',
            message: d.reply || (d.actions_applied ? (d.actions_applied + ' edit' + (d.actions_applied === 1 ? '' : 's') + ' applied') : 'Done.'),
            reload_preview: true,
            credits_used: 0,
            _canonical: true
          };
        } else if (d && d.error) {
          // d.error already in the shape the legacy branch handles
        }
      }
    }
    if (!triedCanonical || (r && r.status === 422 && d && d.legacy === true)) {
      // Legacy fallback — works for static-HTML sites (Chef Red era)
      r = await fetch('/api/builder/websites/' + websiteId + '/arthur-edit', {
        method: 'POST',
        headers: {'Authorization': 'Bearer ' + t, 'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({message: msg, block_id: window._t3SelectedBlock || null, element_key: window._t3SelectedElement || null})
      });
      d = await r.json();
    }

    var typing = document.getElementById('t3-typing');
    if (typing) typing.remove();

    if (d.error) {
      if (feed) feed.innerHTML += '<div style="background:rgba(248,113,113,0.08);padding:10px 12px;border-radius:8px;margin:4px 0"><div style="color:#F87171;font-size:13px">' + bld_escH(d.error) + '</div><div style="color:rgba(248,113,113,0.35);font-size:10px;margin-top:4px">block: ' + (window._t3SelectedBlock||"none selected") + '</div></div>';
    } else if (d.method === 'confirm') {
      if (feed) {
        var _cid = 'conf_' + Date.now();
        feed.innerHTML += '<div id="' + _cid + '" style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0;border-left:3px solid #F97316"><div style="color:var(--t1);font-size:13px;line-height:1.5">' + bld_escH(d.message) + '</div><div style="margin-top:10px;display:flex;gap:8px"><button onclick="_t3ConfirmTier4(' + websiteId + ', this, ' + JSON.stringify(d.confirm_action).replace(/"/g, "&quot;") + ', ' + JSON.stringify(d.confirm_data || {}).replace(/"/g, "&quot;") + ')" style="background:var(--p);border:none;color:#fff;padding:6px 12px;border-radius:5px;cursor:pointer;font-size:12px;font-weight:600">Confirm</button><button onclick="document.getElementById(\'' + _cid + '\').remove()" style="background:var(--s3);border:none;color:var(--t2);padding:6px 12px;border-radius:5px;cursor:pointer;font-size:12px">Cancel</button></div></div>';
        feed.scrollTop = feed.scrollHeight;
      }
    } else if (d.method === 'action') {
      if (feed) feed.innerHTML += '<div style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0;border-left:3px solid #00E5A8"><div style="color:var(--t1);font-size:13px">' + bld_escH(d.message) + '</div><div style="color:rgba(255,255,255,0.3);font-size:10px;margin-top:4px">arthur \u00b7 tier 4</div></div>';
      if (d.reload_preview) {
        var iframe = document.getElementById('t3-preview');
        if (iframe) iframe.src = iframe.src;
      }
      if (typeof wsLoadSites === 'function' && (d.action === 'page_added' || d.action === 'page_deleted' || d.action === 'page_duplicated')) {
        wsLoadSites();
      }
    } else if (d.method === 'chat') {
      if (feed) feed.innerHTML += '<div style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0"><div style="color:var(--t1);font-size:13px;line-height:1.5">' + bld_escH(d.message) + '</div><div style="color:rgba(255,255,255,0.3);font-size:10px;margin-top:4px">arthur \u00b7 ' + (d.credits_used||0) + ' credit</div></div>';
    } else {
      if (feed) feed.innerHTML += '<div style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0"><div style="color:var(--t1);font-size:13px">Done.</div><div style="margin-top:4px"><span onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'" style="color:rgba(255,255,255,0.3);font-size:10px;cursor:pointer;user-select:none">details</span><div style="display:none;margin-top:4px;color:rgba(255,255,255,0.3);font-size:10px;line-height:1.6">' + (d.method==="instant"?"'+window.icon('ai',18)+' instant":"\ud83e\udd16 deepseek") + " \u00b7 " + (d.credits_used||0) + " credit" + ((d.credits_used||0)>1?"s":"") + " \u00b7 block: " + (window._t3SelectedBlock||"page") + '</div></div></div>';
      // Reload iframe
      if (d.reload_preview) {
        var iframe = document.getElementById('t3-preview');
        if (iframe) iframe.src = iframe.src.split('?')[0] + '?v=' + Date.now();
      }
    }
  } catch (e) {
    var typing = document.getElementById('t3-typing');
    if (typing) typing.remove();
    if (feed) feed.innerHTML += '<div style="color:#F87171;font-size:12px;padding:4px">Error: ' + e.message + '</div>';
  }

  inp.disabled = false;
  inp.focus();
  if (feed) feed.scrollTop = feed.scrollHeight;
}
// wsShowCreate defined in arthur-chat.js

// ═══════════════════════════════════════════════════════════════════
// WEBSITE CLONING — clone external website via URL
// ═══════════════════════════════════════════════════════════════════
// ── Clone Debug — call from console: luCloneDebug('https://chefredraymundo.com') ──
async function luCloneDebug(url) {
  console.log('[CLONE DEBUG] Fetching:', url);
  try {
    var r = await fetch(API + 'builder/clone', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' },
      body: JSON.stringify({ url: url, debug: true })
    });
    var d = await r.json();
    console.log('[CLONE DEBUG] Result:', d);
    console.log('[CLONE DEBUG] HTML size:', d.html_size, '→ clean:', d.clean_html_size);
    console.log('[CLONE DEBUG] Headings:', d.headings);
    console.log('[CLONE DEBUG] Paragraphs:', d.paragraphs);
    console.log('[CLONE DEBUG] Images:', d.images);
    console.log('[CLONE DEBUG] Buttons:', d.buttons);
    console.log('[CLONE DEBUG] Backgrounds:', d.backgrounds);
    console.log('[CLONE DEBUG] Content blocks:', d.content_blocks);
    console.log('[CLONE DEBUG] Raw HTML (first 2000):', d.raw_html_first_2000);
    return d;
  } catch(e) {
    console.error('[CLONE DEBUG] Error:', e);
    return null;
  }
}
window.luCloneDebug = luCloneDebug;

function wsShowConnectModal() {
  var existing = document.getElementById('ws-connect-modal');
  if (existing) { existing.remove(); return; }

  var overlay = document.createElement('div');
  overlay.id = 'ws-connect-modal';
  overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center';

  var modal = document.createElement('div');
  modal.style.cssText = 'background:var(--s1);border:1px solid var(--bd);border-radius:16px;width:90%;max-width:540px;overflow:hidden';

  modal.innerHTML = '<div style="padding:20px 24px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between">'
    + '<div><div style="font-family:var(--fh);font-size:18px;font-weight:700;color:var(--t1)">'+window.icon("link",14)+' Connect Your Existing Website</div>'
    + '<div style="font-size:12px;color:var(--t3)">Your website stays as-is. We just connect it so you can manage it from here.</div></div>'
    + '<button onclick="document.getElementById(\'ws-connect-modal\').remove()" style="background:none;border:none;color:var(--t3);font-size:20px;cursor:pointer">✕</button></div>'
    + '<div style="padding:24px">'
    + '<label style="font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px">Website URL</label>'
    + '<div style="display:flex;gap:8px"><input id="ws-connect-url" type="url" placeholder="https://yourwebsite.com" style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:12px 14px;color:var(--t1);font-size:14px;outline:none;box-sizing:border-box" />'
    + '<button id="ws-connect-btn" onclick="wsConnectExisting()" class="ct-btn primary" style="padding:12px 20px;white-space:nowrap;font-weight:600">Connect →</button></div>'
    + '<div style="margin-top:20px;display:grid;gap:8px">'
    + '<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--t2)"><span style="color:var(--gn)">'+window.icon("check",14)+'</span> We take a snapshot of your website</div>'
    + '<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--t2)"><span style="color:var(--gn)">'+window.icon("check",14)+'</span> It appears in your websites list</div>'
    + '<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--t2)"><span style="color:var(--gn)">'+window.icon("check",14)+'</span> Sarah can analyze and improve it</div>'
    + '<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--t2)"><span style="color:var(--gn)">'+window.icon("check",14)+'</span> SEO engine starts auditing it</div></div>'
    + '<div id="ws-connect-status" style="margin-top:16px;min-height:20px"></div></div>';

  overlay.appendChild(modal);
  overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
  document.body.appendChild(overlay);
  setTimeout(function() { var inp = document.getElementById('ws-connect-url'); if (inp) inp.focus(); }, 100);
}

async function wsConnectExisting() {
  var urlInput = document.getElementById('ws-connect-url');
  var btn = document.getElementById('ws-connect-btn');
  var status = document.getElementById('ws-connect-status');
  var url = (urlInput ? urlInput.value.trim() : '');

  if (!url) { status.innerHTML = '<span style="color:#F87171">Please enter a URL.</span>'; return; }
  if (!url.match(/^https?:\/\//)) url = 'https://' + url;
  try { new URL(url); } catch(e) { status.innerHTML = '<span style="color:#F87171">Invalid URL format.</span>'; return; }

  btn.disabled = true;
  btn.textContent = 'Connecting…';
  status.innerHTML = '<div style="display:flex;align-items:center;gap:8px"><div style="width:16px;height:16px;border:2px solid var(--pu);border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite"></div><span>Taking a snapshot of your website…</span></div><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';

  try {
    var r = await fetch(API + 'builder/websites/connect-existing', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json'},
      body: JSON.stringify({ url: url })
    });
    var d = await r.json();

    if (!d.success) {
      status.innerHTML = '<span style="color:#F87171">' + (d.error || 'Could not connect.') + '</span>';
      btn.disabled = false;
      btn.textContent = 'Connect →';
      return;
    }

    var platformBadge = d.platform ? '<span style="padding:2px 8px;background:rgba(108,92,231,.15);color:var(--pu);border-radius:4px;font-size:11px;font-weight:600">' + d.platform.charAt(0).toUpperCase() + d.platform.slice(1) + '</span>' : '';
    var thumbHtml = d.thumbnail_url ? '<img src="' + d.thumbnail_url + '" style="width:100%;height:120px;object-fit:cover;border-radius:8px;margin-bottom:12px;border:1px solid var(--bd)" onerror="this.style.display=\'none\'">' : '';

    status.innerHTML = '<div style="background:rgba(0,229,168,.06);border:1px solid rgba(0,229,168,.2);border-radius:12px;padding:16px;margin-top:8px">'
      + thumbHtml
      + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><span style="color:var(--gn);font-size:20px">'+window.icon("check",14)+'</span><span style="font-size:15px;font-weight:600;color:var(--t1)">' + (d.name || url) + ' connected!</span></div>'
      + '<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">' + platformBadge + '</div>'
      + (d.platform === 'wordpress' ? '<div style="margin-top:8px;padding:12px;background:var(--s2);border-radius:8px;font-size:12px;color:var(--t2)">'+window.icon("ai",14)+' <strong>WordPress detected!</strong> Install the LevelUp WP Connector plugin to publish AI content directly to your site.</div>' : '')
      + '<div style="display:flex;gap:8px;margin-top:12px"><button onclick="document.getElementById(\'ws-connect-modal\').remove();wsLoadSites();" class="ct-btn primary" style="padding:8px 16px">Done →</button></div></div>';

    btn.style.display = 'none';
    try { fetch(API + 'seo/deep-audit', { method: 'POST', headers: {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json'}, body: JSON.stringify({ url: url }) }); } catch(e) {}

  } catch(e) {
    status.innerHTML = '<span style="color:#F87171">Error: ' + e.message + '</span>';
    btn.disabled = false;
    btn.textContent = 'Connect →';
  }
}
function wsHideCreate(){const m=document.getElementById('ws-create-modal');if(m)m.style.display='none';}

async function wsCreate(){
  const title=document.getElementById('ws-create-title')?.value.trim();
  const desc=document.getElementById('ws-create-desc')?.value.trim();
  if(!title){showToast('Website name required.','warning');return;}
  const btn=document.getElementById('ws-create-btn');
  btn.textContent='Creating…';btn.disabled=true;
  try{
    // POST lu/v1/websites — Core endpoint that returns {id, title, status}
    const r=await fetch(API+'websites',{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},body:JSON.stringify({title,description:desc,site_config:{created_from:'saas_builder'}})});
    if(!r.ok){
      const eBody = await r.text().catch(()=>'');
      let eMsg;
      try { const ej=JSON.parse(eBody); eMsg=ej.message||ej.error||('HTTP '+r.status); } catch(_){ eMsg='HTTP '+r.status; }
      // Strip any HTML tags from the error message
      eMsg = eMsg.replace(/<[^>]+>/g,'').replace(/&lt;/g,'<').replace(/&gt;/g,'>').trim();
      throw new Error(eMsg||('HTTP '+r.status));
    }
    const d=await r.json();
    const newId = d.id || d.website_id;
    if(newId){
      wsHideCreate();
      document.getElementById('ws-create-title').value='';
      document.getElementById('ws-create-desc').value='';
      await wsLoadSites();
      wsOpenSite(newId);
    } else {
      throw new Error(d.error || 'Server returned no site ID');
    }
  }catch(e){
    showToast('Create failed: '+e.message,'error');
    console.error('[wsCreate]',e);
  }
  btn.textContent='Create Website →';btn.disabled=false;
}

async function wsOpenSite(siteId){
  var site = wsSites.find(function(s){ return s.id === siteId; }) || {id:siteId, title:'Website'};
  wsCurrentSite = site;

  // Template websites — show template view
  if (site.type === 'template') { _wsShowTemplateEditor(site); return; }
  // External websites — no editor
  if (site.type === 'external' || site.external_url) { return; }

  document.getElementById('ws-site-list').style.display = 'none';
  document.getElementById('ws-site-pages').style.display = 'block';
  document.getElementById('ws-site-title').textContent = site.title + ' — Pages';

  try {
    var r = await fetch(API + 'builder/pages?website_id=' + siteId, {headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'}});
    var res = await r.json();
    var pages = bld_ensureArray(res.pages ?? res);
    wsRenderSitePages(pages, siteId);
    // Kick off live-resolve for any skeleton pages
    _wsLiveResolve(pages, siteId);
  } catch(e) {
    console.error('wsOpenSite', e);
    document.getElementById('ws-pages-grid').innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--t3)">Failed to load pages.</div>';
  }
}

// Live-resolve polling REMOVED 2026-04-10: backend now writes pages synchronously
// inside wizardGenerate(), so there is nothing to wait for. Polling was firing
// ~75 requests in 30s and causing the editor to feel hung. See changelog.
var _wsLiveResolveTimer = null;
function _wsLiveResolve(pages, siteId) { return; }

function wsCloseSite() {
  clearInterval(_wsLiveResolveTimer); // stop any active thumbnail polling
  wsCurrentSite = null;
  document.getElementById('ws-site-pages').style.display = 'none';
  document.getElementById('ws-site-list').style.display = 'block';
}

function wsRenderSitePages(pages, siteId) {
  var grid = document.getElementById('ws-pages-grid');
  if (!grid) return;

  if (!pages.length) {
    grid.innerHTML =
      '<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--t3)">' +
      '<div style="font-size:40px;margin-bottom:12px">'+window.icon("more",14)+'</div>' +
      '<div style="font-size:16px;font-weight:600;color:var(--t2);margin-bottom:8px">No pages yet</div>' +
      '<div style="font-size:13px;margin-bottom:20px">Add pages to your website</div>' +
      '<button class="ct-btn primary" onclick="wsAddPageToSite()">+ Add First Page</button></div>';
    return;
  }

  var html = '';
  for (var i = 0; i < pages.length; i++) {
    var pg      = pages[i];
    var primary = pg.thumb_color || '#6C5CE7';
    var ready   = !!pg.has_content;
    var statusBg  = pg.status === 'published' ? 'rgba(16,185,129,.15)' : pg.status === 'ready' ? 'rgba(0,229,168,.12)' : 'rgba(245,158,11,.15)';
    var statusClr = pg.status === 'published' ? '#10B981' : pg.status === 'ready' ? '#00E5A8' : 'var(--am)';
    var thumb = _wsPageThumbnail(pg, primary, ready);

    html +=
      '<div id="ws-card-' + pg.id + '" style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden;transition:border-color .2s,box-shadow .2s" ' +
      'onmouseenter="this.style.borderColor=\'var(--pu)\';this.style.boxShadow=\'0 4px 20px rgba(108,92,231,.15)\'" ' +
      'onmouseleave="this.style.borderColor=\'var(--bd)\';this.style.boxShadow=\'none\'">' +
      '<div class="ws-thumb-area" onclick="wsEditSitePage(' + pg.id + ')" style="position:relative;height:160px;overflow:hidden;background:#0F1117;cursor:pointer">' +
      thumb +
      '<div class="ws-thumb-overlay" style="position:absolute;inset:0;background:rgba(108,92,231,0);display:flex;align-items:center;justify-content:center;opacity:0;transition:all .2s" ' +
      'onmouseenter="this.style.opacity=\'1\';this.style.background=\'rgba(108,92,231,.5)\'" ' +
      'onmouseleave="this.style.opacity=\'0\';this.style.background=\'rgba(108,92,231,0)\'">' +
      '<span style="background:#fff;color:#6C5CE7;border-radius:8px;padding:8px 18px;font-size:13px;font-weight:700;font-family:var(--fb)">'+window.icon("edit",14)+' Edit Page</span>' +
      '</div>' +
      '</div>' +
      '<div style="padding:14px 16px">' +
      '<div class="ws-card-title" style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + bld_escH(pg.title || pg.slug || 'Untitled Page') + '</div>' +
      '<div class="ws-card-slug" style="font-size:11px;color:var(--t3);margin-bottom:10px">/' + bld_escH(pg.slug || '') + '</div>' +
      '<div style="display:flex;align-items:center;justify-content:space-between">' +
      '<span style="background:' + statusBg + ';color:' + statusClr + ';padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">' + (pg.status || 'draft') + '</span>' +
      '<div style="display:flex;gap:6px">' +
      '<button onclick="wsEditSitePage(' + pg.id + ')" style="background:var(--pu);border:none;border-radius:6px;color:#fff;padding:5px 12px;font-size:12px;cursor:pointer;font-weight:600">Edit</button>' +
      '<button onclick="wsDeleteSitePage(' + pg.id + ',' + siteId + ')" title="Delete" style="background:rgba(248,113,113,.1);border:none;border-radius:6px;color:#F87171;padding:5px 9px;font-size:12px;cursor:pointer">\u2715</button>' +
      '</div></div></div></div>';
  }
  grid.innerHTML = html;
}

// Shimmer keyframe — injected once
(function() {
  if (document.getElementById('ws-shimmer-css')) return;
  var s = document.createElement('style');
  s.id = 'ws-shimmer-css';
  s.textContent = '@keyframes wsShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}';
  document.head.appendChild(s);
})();

function _wsShimmer() {
  return 'background:linear-gradient(90deg,rgba(255,255,255,.04) 25%,rgba(255,255,255,.09) 50%,rgba(255,255,255,.04) 75%);background-size:200% 100%;animation:wsShimmer 1.6s ease-in-out infinite';
}

function _wsPageThumbnail(pg, primaryColor, hasContent) {
  var key = pg.page_type || pg.slug || 'page';
  var c   = primaryColor;
  var c20 = c + '33';
  var c40 = c + '66';

  if (!hasContent) {
    return (
      '<div style="width:100%;height:100%;display:flex;flex-direction:column;overflow:hidden">' +
      '<div style="height:18px;background:rgba(255,255,255,.04);display:flex;align-items:center;padding:0 10px;gap:6px;flex-shrink:0">' +
      '<div style="width:40px;height:6px;border-radius:3px;' + _wsShimmer() + '"></div>' +
      '<div style="flex:1"></div>' +
      '<div style="width:20px;height:5px;border-radius:3px;' + _wsShimmer() + '"></div>' +
      '<div style="width:20px;height:5px;border-radius:3px;' + _wsShimmer() + '"></div>' +
      '</div>' +
      '<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;padding:10px;background:rgba(255,255,255,.015)">' +
      '<div style="width:70%;height:8px;border-radius:4px;' + _wsShimmer() + '"></div>' +
      '<div style="width:50%;height:6px;border-radius:3px;' + _wsShimmer() + '"></div>' +
      '<div style="width:30%;height:6px;border-radius:3px;' + _wsShimmer() + '"></div>' +
      '<div style="width:50px;height:14px;border-radius:5px;margin-top:4px;' + _wsShimmer() + '"></div>' +
      '</div>' +
      '<div style="height:40px;display:flex;gap:4px;padding:4px 6px;background:rgba(255,255,255,.02)">' +
      '<div style="flex:1;border-radius:4px;' + _wsShimmer() + '"></div>' +
      '<div style="flex:1;border-radius:4px;' + _wsShimmer() + '"></div>' +
      '<div style="flex:1;border-radius:4px;' + _wsShimmer() + '"></div>' +
      '</div>' +
      '</div>' +
      '<div style="position:absolute;bottom:8px;left:50%;transform:translateX(-50%);background:rgba(15,17,23,.85);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:3px 10px;font-size:10px;color:rgba(255,255,255,.45);white-space:nowrap;font-family:var(--fb)">Preparing your page\u2026</div>'
    );
  }

  var sectionMap = {
    home:['hero','features','cta'], landing:['hero','cta'], about:['hero','story','cta'],
    services:['hero','grid','cta'], contact:['hero','form'], pricing:['hero','pricing'],
    blog:['hero','list'], faq:['hero','faq'], portfolio:['hero','grid'],
  };
  var sections = sectionMap[key] || ['hero','content','cta'];

  var out = '<div style="width:100%;height:100%;display:flex;flex-direction:column;overflow:hidden">';
  // Nav bar
  out += '<div style="height:16px;background:#171A21;display:flex;align-items:center;padding:0 8px;gap:5px;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,.06)">';
  out += '<div style="width:28px;height:5px;border-radius:2px;background:' + c + ';opacity:.9"></div><div style="flex:1"></div>';
  out += '<div style="width:18px;height:4px;border-radius:2px;background:rgba(255,255,255,.15)"></div>';
  out += '<div style="width:18px;height:4px;border-radius:2px;background:rgba(255,255,255,.15)"></div>';
  out += '<div style="width:22px;height:9px;border-radius:3px;background:' + c + ';opacity:.8"></div></div>';

  var renders = {
    hero: '<div style="flex:2;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:8px;background:linear-gradient(160deg,#171A21 0%,' + c20 + ' 100%)">' +
      '<div style="width:60%;height:7px;border-radius:3px;background:' + c + ';opacity:.9"></div>' +
      '<div style="width:45%;height:4px;border-radius:2px;background:rgba(255,255,255,.25)"></div>' +
      '<div style="width:35%;height:4px;border-radius:2px;background:rgba(255,255,255,.15)"></div>' +
      '<div style="display:flex;gap:5px;margin-top:4px">' +
      '<div style="width:40px;height:10px;border-radius:4px;background:' + c + '"></div>' +
      '<div style="width:40px;height:10px;border-radius:4px;border:1px solid ' + c + ';opacity:.6"></div>' +
      '</div></div>',
    features: '<div style="flex:1;display:flex;align-items:stretch;gap:3px;padding:4px 6px;background:#0F1117">' +
      [c40,'rgba(255,255,255,.06)','rgba(255,255,255,.04)'].map(function(bg){
        return '<div style="flex:1;border-radius:3px;background:' + bg + ';display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:3px">' +
          '<div style="width:10px;height:10px;border-radius:50%;background:' + c + ';opacity:.7"></div>' +
          '<div style="width:80%;height:3px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
          '<div style="width:60%;height:2px;border-radius:1px;background:rgba(255,255,255,.1)"></div></div>';
      }).join('') + '</div>',
    grid: '<div style="flex:1;display:flex;flex-wrap:wrap;gap:2px;padding:4px 6px;background:#0F1117;align-content:flex-start">' +
      [c40,'rgba(255,255,255,.07)','rgba(255,255,255,.05)','rgba(255,255,255,.08)'].map(function(bg){
        return '<div style="width:calc(50% - 1px);height:20px;border-radius:3px;background:' + bg + '"></div>';
      }).join('') + '</div>',
    cta: '<div style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:6px;background:' + c20 + ';border-top:1px solid ' + c40 + '">' +
      '<div style="width:50%;height:5px;border-radius:2px;background:rgba(255,255,255,.3)"></div>' +
      '<div style="width:36px;height:11px;border-radius:3px;background:' + c + '"></div></div>',
    form: '<div style="flex:1.2;display:flex;flex-direction:column;gap:3px;padding:5px 8px;background:#0F1117">' +
      ['rgba(255,255,255,.08)','rgba(255,255,255,.08)','rgba(255,255,255,.06)'].map(function(bg){
        return '<div style="height:9px;border-radius:3px;background:' + bg + ';border:1px solid rgba(255,255,255,.07)"></div>';
      }).join('') +
      '<div style="height:11px;border-radius:3px;background:' + c + ';margin-top:2px"></div></div>',
    pricing: '<div style="flex:1.5;display:flex;align-items:stretch;gap:3px;padding:4px 6px;background:#0F1117">' +
      ['rgba(255,255,255,.05)',c20,'rgba(255,255,255,.04)'].map(function(bg,idx){
        var f = idx===1;
        return '<div style="flex:1;border-radius:4px;background:' + bg + ';border:1px solid ' + (f?c:'rgba(255,255,255,.06)') + ';display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:3px">' +
          '<div style="width:70%;height:4px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
          '<div style="width:50%;height:7px;border-radius:2px;background:' + (f?c:'rgba(255,255,255,.15)') + '"></div>' +
          '<div style="width:80%;height:8px;border-radius:3px;background:' + (f?c:'rgba(255,255,255,.07)') + ';margin-top:2px"></div></div>';
      }).join('') + '</div>',
    list: '<div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:4px 6px;background:#0F1117">' +
      [1,2,3].map(function(){
        return '<div style="height:16px;border-radius:3px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:4px;padding:0 5px">' +
          '<div style="width:8px;height:8px;border-radius:2px;background:' + c40 + '"></div>' +
          '<div style="flex:1;height:3px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
          '<div style="width:25%;height:3px;border-radius:1px;background:rgba(255,255,255,.1)"></div></div>';
      }).join('') + '</div>',
    story: '<div style="flex:1;display:flex;gap:4px;padding:4px 6px;background:#0F1117;align-items:center">' +
      '<div style="flex:1;display:flex;flex-direction:column;gap:2px">' +
      '<div style="height:4px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
      '<div style="height:3px;border-radius:1px;background:rgba(255,255,255,.12)"></div>' +
      '<div style="height:3px;border-radius:1px;background:rgba(255,255,255,.1)"></div></div>' +
      '<div style="flex:1;height:40px;border-radius:4px;background:' + c20 + ';border:1px solid ' + c40 + '"></div></div>',
    faq: '<div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:4px 6px;background:#0F1117">' +
      [1,2,3].map(function(_,idx){
        return '<div style="height:12px;border-radius:3px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.06);display:flex;align-items:center;justify-content:space-between;padding:0 5px">' +
          '<div style="width:65%;height:3px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
          '<div style="width:8px;height:8px;border-radius:50%;background:' + (idx===0?c:'rgba(255,255,255,.1)') + ';display:flex;align-items:center;justify-content:center;font-size:6px;color:#fff">' + (idx===0?'−':'+') + '</div></div>';
      }).join('') + '</div>',
    content: '<div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:6px 8px;background:#0F1117">' +
      '<div style="width:50%;height:5px;border-radius:2px;background:' + c40 + '"></div>' +
      '<div style="height:3px;border-radius:1px;background:rgba(255,255,255,.15)"></div>' +
      '<div style="height:3px;border-radius:1px;background:rgba(255,255,255,.1)"></div>' +
      '<div style="height:3px;width:80%;border-radius:1px;background:rgba(255,255,255,.08)"></div></div>',
  };

  sections.forEach(function(sec) {
    out += renders[sec] || renders.content;
  });
  out += '</div>';
  return out;
}

// PHASE 1: wsRegeneratePages REMOVED — pages are always fully generated at wizard creation
// and persisted immediately. No on-demand regeneration ever needed.
function wsRegeneratePages() {
  console.warn('[Builder] wsRegeneratePages() called but regeneration has been removed. Pages are always persisted at creation.');
}
function wsGenNowClick() {
  console.warn('[Builder] wsGenNowClick() called but regeneration has been removed.');
}

function wsEditSitePage(pageId) {
  // Track that we came from websites
  window._wsReturnToSite = wsCurrentSite ? wsCurrentSite.id : null;
  // Pre-load website pages for the Pages panel
  _bldWebsitePages = []; // clear so panel fetches fresh
  // Update back button to say "← Website"
  var backBtn = document.getElementById('bld-back-btn');
  if (backBtn) backBtn.textContent = '\u2190 Website';
  // Hide websites view, show builder editor
  document.getElementById('view-websites').style.display = 'none';
  document.getElementById('view-builder').style.display = 'flex';
  document.getElementById('bld-list-state').style.display = 'none';
  document.getElementById('bld-editor-state').style.display = 'flex';
  bldOpenEditor(pageId, 'standalone');
  // Auto-open Pages tab so user sees sibling pages
  setTimeout(function() { bldLeftTab('pages'); }, 300);
}

async function wsAddPageToSite() {
  if (!wsCurrentSite) return;
  var title = await luPrompt('Page title:', '', 'Add Page to ' + wsCurrentSite.title);
  if (!title) return;
  try {
    var r = await fetch(API + 'builder/create', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json'},
      body: JSON.stringify({title: title, type: 'page', website_id: wsCurrentSite.id})
    });
    var d = await r.json();
    if (d.success) {
      showToast('Page "' + title + '" added', 'success');
      wsOpenSite(wsCurrentSite.id);
    }
  } catch(e) { showToast('Failed: ' + e.message, 'error'); }
}

async function wsDeleteSitePage(pageId, siteId) {
  var ok = await luConfirm('This page will be permanently deleted.', 'Delete Page', 'Delete', 'Cancel');
  if (!ok) return;
  try {
    console.log('[WS DELETE] Page:', pageId, 'Site:', siteId);
    var resp = await fetch(API + 'builder/delete/' + pageId, {
      method: 'POST',
      headers: {'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json', 'Content-Type': 'application/json'},
      body: JSON.stringify({confirm: true})
    });
    var result = await resp.json();
    console.log('[WS DELETE] Response:', resp.status, result);
    if (!resp.ok || !result.success) {
      showToast('Delete failed: ' + (result.error || 'Server error'), 'error');
      return;
    }
    showToast('Page deleted permanently', 'success');
    wsOpenSite(siteId); // Reload from DB
  } catch(e) {
    console.error('[WS DELETE] Error:', e);
    showToast('Delete failed: ' + e.message, 'error');
  }
}

async function wsNewSitePage(siteId){
  const title=await luPrompt('Enter page title:','','New Page');if(!title)return;
  try{
    const r=await fetch(API+'builder/create',{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},body:JSON.stringify({title:title,type:'page'})});
    const d=await r.json();
    if(d.success && d.page?.id){await wsOpenSite(siteId);bldOpenEditor(d.page.id,'standalone');}
  }catch(e){showToast('Failed: '+e.message,'error');}
}

async function wsDelete(siteId){
  var ok = await luConfirm('This website and ALL its pages will be permanently removed.', 'Delete Website', 'Delete', 'Cancel'); if (!ok) return;
  try{
    console.log('[WS DELETE] Website:', siteId);
    var resp = await fetch(API + 'websites/' + siteId + '/delete', {
      method: 'POST',
      headers: {'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json', 'Content-Type': 'application/json'},
      body: JSON.stringify({confirm: true})
    });
    var result = await resp.json();
    console.log('[WS DELETE] Response:', resp.status, result);
    if (!resp.ok || !result.success) {
      showToast('Delete failed: ' + (result.error || 'Server error'), 'error');
      return;
    }
    showToast('Website deleted permanently (' + (result.pages_deleted||0) + ' pages removed)', 'success');
    // Reload from DB
    await wsLoadSites();
  }catch(e){
    console.error('[WS DELETE] Error:', e);
    showToast('Delete failed: '+e.message,'error');
  }
}

function wsShowPublish(siteId){
  const site=wsSites.find(s=>s.id===siteId);if(!site)return;
  wsPubTarget={type:'site',id:siteId};
  const m=document.getElementById('ws-pub-modal');
  document.getElementById('ws-pub-title').textContent=`Publish "${site.title}"`;
  document.getElementById('ws-pub-subtitle').textContent=`${site.page_count||0} pages · ${site.publish_state||'unpublished'}`;
  const dom=document.getElementById('ws-pub-domain');if(dom)dom.value=site.domain||'';
  document.getElementById('ws-pub-domain-status').style.display='none';
  document.getElementById('ws-pub-dns-guide').style.display='none';
  if(m)m.style.display='flex';
}

function wsHidePubModal(){const m=document.getElementById('ws-pub-modal');if(m)m.style.display='none';wsPubTarget=null;}

async function wsConnectDomain(){
  if(!wsPubTarget)return;
  var domain=document.getElementById('ws-pub-domain')?.value.trim();
  if(!domain){showToast('Enter a domain name.','warning');return;}
  var btn=document.querySelector('.ws-domain-connect-btn');
  if(btn){btn.textContent='Connecting…';btn.disabled=true;}
  try{
    var r=await fetch(bldApi+'websites/'+wsPubTarget.id+'/custom-domain',{
      method:'POST',
      headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},
      body:JSON.stringify({domain:domain})
    });
    var d=await r.json();
    if(d.success){
      document.getElementById('ws-pub-domain-status').style.display='flex';
      document.getElementById('ws-pub-domain-msg').textContent='⏳ Pending DNS verification — may take up to 24hrs';
      document.getElementById('ws-pub-dns-guide').style.display='block';
      var s=wsSites.find(function(s){return s.id===wsPubTarget?.id;});
      if(s){s.domain=domain;s.domain_status='pending';}
      showToast('Domain connected! Configure your DNS, then click Verify.','success');
    } else {
      showToast(d.error||'Failed to connect domain','error');
    }
  }catch(e){showToast('Domain error: '+e.message,'error');}
  if(btn){btn.textContent='Connect';btn.disabled=false;}
}

async function wsVerifyDomain(){
  if(!wsPubTarget)return;
  try{
    var r=await fetch(bldApi+'websites/'+wsPubTarget.id+'/custom-domain/verify',{
      headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'}
    });
    var d=await r.json();
    var msg=document.getElementById('ws-pub-domain-msg');
    if(d.verified){
      if(msg)msg.textContent=''+window.icon("check",14)+' Connected and verified!';
      var s=wsSites.find(function(s){return s.id===wsPubTarget?.id;});
      if(s)s.domain_status='verified';
      showToast('Domain verified!','success');
    } else {
      if(msg)msg.textContent=''+window.icon("close",14)+' '+( d.error||'DNS not yet propagated. Try again later.');
      showToast(d.error||'Verification failed — DNS may still be propagating','warning');
    }
  }catch(e){showToast('Verify error: '+e.message,'error');}
}

async function wsDisconnectDomain(){
  if(!wsPubTarget)return;
  if(!confirm('Disconnect custom domain? Visitors will need to use the .levelupgrowth.io URL.'))return;
  try{
    var r=await fetch(bldApi+'websites/'+wsPubTarget.id+'/custom-domain',{
      method:'DELETE',
      headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'}
    });
    var d=await r.json();
    if(d.success){
      document.getElementById('ws-pub-domain-status').style.display='none';
      document.getElementById('ws-pub-dns-guide').style.display='none';
      var domInput=document.getElementById('ws-pub-domain');if(domInput)domInput.value='';
      var s=wsSites.find(function(s){return s.id===wsPubTarget?.id;});
      if(s){s.domain='';s.domain_status='';}
      showToast('Custom domain disconnected','success');
    } else {
      showToast(d.error||'Failed to disconnect','error');
    }
  }catch(e){showToast('Disconnect error: '+e.message,'error');}
}

async function wsDoPublish(){
  if(!wsPubTarget)return;
  // Check if website already has a subdomain and is published
  var site = wsSites ? wsSites.find(function(s){return s.id===wsPubTarget.id;}) : null;
  var hasSub = site && site.subdomain && site.subdomain.length > 5;
  var alreadyPublished = site && (site.status === 'published' || site.publish_state === 'published');

  if (!hasSub || !alreadyPublished) {
    // First publish — show subdomain picker
    _luShowSubdomainPicker(wsPubTarget.id, site ? (site.title || site.name) : '');
    return;
  }

  // Already published — just republish (update content)
  const btn=document.getElementById('ws-pub-btn');btn.textContent='Publishing…';btn.disabled=true;
  try{
    const r=await fetch(API+'builder/websites/'+wsPubTarget.id+'/publish',{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},body:'{}'});
    const d=await r.json();
    if(d.success){
      if(site){site.publish_state='published';site.status='published';}
      var liveUrl = d.url || ('https://' + site.subdomain.replace('.levelupgrowth.io','') + '.levelupgrowth.io');
      showToast('Website republished! Changes are live.','success');
      const pubStatus = document.getElementById('ws-pub-domain-status');
      if(pubStatus){ pubStatus.style.display='flex'; pubStatus.innerHTML='<span>✓</span><a href="'+liveUrl+'" target="_blank" style="color:var(--ac);text-decoration:underline">View Live Site →</a>'; }
      btn.textContent='Published ✓';
      setTimeout(function(){ btn.textContent=''+window.icon("rocket",14)+' Publish Now'; btn.disabled=false; },3000);
      if(typeof wsUpdateStats==='function')wsUpdateStats();
      if(typeof wsRenderGrid==='function')wsRenderGrid();
    } else {
      showToast(d.error||'Publish failed','error');
      btn.textContent=''+window.icon("rocket",14)+' Publish Now';btn.disabled=false;
    }
  }catch(e){showToast('Publish failed: '+e.message,'error');btn.textContent=''+window.icon("rocket",14)+' Publish Now';btn.disabled=false;}
}

// ── SUBDOMAIN PICKER MODAL (publish-flow-fix, 2026-05-09) ─────────────
// Shown by wsDoPublish when a website has no subdomain yet.
// Calls /builder/check-subdomain on every keystroke (300ms debounced),
// then on confirm: POST /builder/websites/{id}/set-subdomain ->
// POST /builder/websites/{id}/publish.
window._luShowSubdomainPicker = function (websiteId, businessName) {
  // Generate a sane suggestion from the business name
  var suggested = String(businessName || '')
    .toLowerCase()
    .replace(/[^a-z0-9\s\-]/g, '')
    .trim()
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '')
    .substring(0, 30);
  if (suggested.length < 3) suggested = 'my-site';

  var existing = document.getElementById('subdomain-modal');
  if (existing) existing.remove();

  var modal = document.createElement('div');
  modal.id = 'subdomain-modal';
  modal.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,sans-serif';

  modal.innerHTML =
    '<div style="background:var(--s1,#161927);border:1px solid var(--bd,#333);border-radius:16px;padding:28px;width:90%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.5)">' +
      '<h3 style="margin:0 0 6px;font-size:18px;font-weight:700;color:var(--t1,#fff)">Choose your web address</h3>' +
      '<p style="margin:0 0 20px;font-size:13px;color:var(--t3,#888);line-height:1.5">This will be your website\'s public URL on the internet.</p>' +
      '<div style="display:flex;align-items:stretch;gap:0;border:1.5px solid var(--bd,#333);border-radius:10px;overflow:hidden;margin-bottom:6px">' +
        '<div style="padding:12px 14px;background:var(--s2,#1a1a1a);color:var(--t3,#888);font-size:13px;white-space:nowrap;display:flex;align-items:center">https://</div>' +
        '<input id="subdomain-input" type="text" value="' + bld_escH(suggested) + '" placeholder="your-business-name" style="flex:1;border:none;outline:none;padding:12px;background:var(--s1,#161927);color:var(--t1,#fff);font-size:14px;font-family:inherit">' +
        '<div style="padding:12px 14px;background:var(--s2,#1a1a1a);color:var(--t3,#888);font-size:13px;white-space:nowrap;display:flex;align-items:center">.levelupgrowth.io</div>' +
      '</div>' +
      '<div id="subdomain-status" style="font-size:12px;min-height:18px;margin-bottom:18px;color:var(--t3,#888)">Checking availability…</div>' +
      '<div style="display:flex;gap:10px">' +
        '<button onclick="document.getElementById(\'subdomain-modal\').remove()" style="flex:1;padding:11px;border-radius:8px;border:1.5px solid var(--bd,#333);background:transparent;color:var(--t1,#fff);cursor:pointer;font-size:13px;font-weight:500;font-family:inherit">Cancel</button>' +
        '<button id="subdomain-confirm" onclick="_luConfirmSubdomain(' + websiteId + ')" disabled style="flex:2;padding:11px;border-radius:8px;border:none;background:var(--p,#6C5CE7);color:#fff;cursor:pointer;font-size:13px;font-weight:600;font-family:inherit;opacity:0.5">Publish Website ⚡</button>' +
      '</div>' +
    '</div>';

  document.body.appendChild(modal);

  var input  = document.getElementById('subdomain-input');
  var status = document.getElementById('subdomain-status');
  var btn    = document.getElementById('subdomain-confirm');
  var debounceTimer;

  function setBtnEnabled(on) {
    btn.disabled = !on;
    btn.style.opacity = on ? '1' : '0.5';
    btn.style.cursor = on ? 'pointer' : 'not-allowed';
  }

  function checkAvailability(slug) {
    if (!slug || slug.length < 3) {
      status.textContent = 'Enter at least 3 characters';
      status.style.color = 'var(--t3,#888)';
      setBtnEnabled(false);
      return;
    }
    if (!/^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(slug)) {
      status.textContent = 'Use lowercase letters, numbers and hyphens only';
      status.style.color = '#f87171';
      setBtnEnabled(false);
      return;
    }
    status.textContent = 'Checking…';
    status.style.color = 'var(--t3,#888)';
    setBtnEnabled(false);

    fetch(API + 'builder/check-subdomain?slug=' + encodeURIComponent(slug) + '&exclude=' + encodeURIComponent(websiteId), {
      headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.available) {
          status.textContent = '✅ ' + slug + '.levelupgrowth.io is available';
          status.style.color = '#10b981';
          setBtnEnabled(true);
        } else {
          var msg = d && d.error ? d.error : 'Already taken';
          if (d && d.suggestion) msg += ' — try ' + d.suggestion;
          status.textContent = '❌ ' + msg;
          status.style.color = '#f87171';
          setBtnEnabled(false);
        }
      })
      .catch(function () {
        status.textContent = 'Could not check availability — try again';
        status.style.color = '#f87171';
        setBtnEnabled(false);
      });
  }

  input.addEventListener('input', function () {
    var slug = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
    if (slug !== this.value) this.value = slug;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () { checkAvailability(slug); }, 300);
  });

  // Auto-check the suggested value
  setTimeout(function () { checkAvailability(suggested); }, 200);
  setTimeout(function () { input.focus(); input.select(); }, 250);
};

window._luConfirmSubdomain = async function (websiteId) {
  var input  = document.getElementById('subdomain-input');
  var status = document.getElementById('subdomain-status');
  var btn    = document.getElementById('subdomain-confirm');
  if (!input || !btn) return;

  var slug = input.value.trim();
  if (!slug) return;

  btn.textContent = 'Publishing…';
  btn.disabled = true;
  btn.style.opacity = '0.6';

  try {
    // 1. Set subdomain
    var setR = await fetch(API + 'builder/websites/' + websiteId + '/set-subdomain', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' },
      body: JSON.stringify({ subdomain: slug }),
    });
    var setD = await setR.json();
    if (!setR.ok || !setD || !setD.success) {
      status.textContent = (setD && setD.error) || 'Could not save subdomain';
      status.style.color = '#f87171';
      btn.textContent = 'Try Again';
      btn.disabled = false;
      btn.style.opacity = '1';
      return;
    }

    // 2. Publish
    var pubR = await fetch(API + 'builder/websites/' + websiteId + '/publish', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' },
      body: '{}',
    });
    var pubD = await pubR.json();
    if (!pubR.ok || !pubD || !pubD.success) {
      status.textContent = (pubD && pubD.error) || 'Publish failed after subdomain set';
      status.style.color = '#f87171';
      btn.textContent = 'Try Again';
      btn.disabled = false;
      btn.style.opacity = '1';
      return;
    }

    document.getElementById('subdomain-modal').remove();

    var url = pubD.url || ('https://' + slug + '.levelupgrowth.io');
    var toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10001;background:#10b981;color:#fff;padding:14px 18px;border-radius:10px;font-size:13px;box-shadow:0 4px 20px rgba(0,0,0,0.35);max-width:320px;font-family:system-ui,sans-serif;line-height:1.5';
    toast.innerHTML = '🚀 <strong>Website published!</strong><br><a href="' + bld_escH(url) + '" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline">' + bld_escH(url) + '</a>';
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 7000);

    // Refresh the websites grid + any open editor's status
    if (typeof wsLoadSites === 'function') wsLoadSites();
  } catch (e) {
    status.textContent = 'Network error: ' + (e && e.message ? e.message : 'unknown');
    status.style.color = '#f87171';
    btn.textContent = 'Try Again';
    btn.disabled = false;
    btn.style.opacity = '1';
  }
};

// ── DEVICE TOGGLE ─────────────────────────────────────────────────────

// ── SAVE & PUBLISH ────────────────────────────────────────────────────
async function bldSave() {
  if (!bldCurrentPage) return;
  const btn = document.getElementById('bld-save-btn');
  btn.textContent = 'Saving…'; btn.disabled = true;
  try {
    if (bldCurrentPage._source === 'standalone' || _bldPageSource === 'standalone') {
      // Save via core builder
      // PATCH 10 Fix 3 — unwrap `layout` so backend BuilderService::updatePage
      // (`app/Engines/Builder/Services/BuilderService.php:205`) actually
      // receives the `sections` field. The previous nested `layout: {sections}`
      // shape silently dropped to BuilderService and the API returned a lying
      // `{updated:true}` while `pages.sections_json` was never written.
      // Backend whitelist: title, slug, type, status, is_homepage, position, sections, sections_json, seo.
      await fetch(API+'builder/save', {
        method:'POST', headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},
        body: JSON.stringify({
          page_id: bldCurrentPageId,
          source:  'user',
          title:   bldCurrentPage.title,
          type:    bldCurrentPage.type || bldCurrentPage.page_type || 'landing',
          sections: bldCurrentPage.sections || []
        })
      });
    } else {
      // Legacy lubld fallback
      await _bldSafeFetch(bldApi+'pages/'+bldCurrentPageId+'/save-json',{
        method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},
        body: JSON.stringify({page_json: bldCurrentPage})
      });
    }
    bldDirty = false;
    btn.textContent = '✓ Saved';
    // Push updated sections into preview iframe cache
    var previewOverlay = document.getElementById('bld-preview-overlay');
    if (previewOverlay) {
      var previewFrame = previewOverlay.querySelector('iframe');
      if (previewFrame && previewFrame.contentWindow && previewFrame.contentWindow.__pageCache) {
        var savedSlug = (bldCurrentPage && bldCurrentPage.slug) || 'home';
        previewFrame.contentWindow.__pageCache[savedSlug] = {
          id: bldCurrentPageId, slug: savedSlug,
          title: bldCurrentPage.title,
          sections: JSON.parse(JSON.stringify(bldCurrentPage.sections || [])),
        };
        if (previewFrame.contentWindow.__currentSlug === savedSlug &&
            typeof previewFrame.contentWindow.__renderPage === 'function') {
          previewFrame.contentWindow.__renderPage(savedSlug);
        }
      }
    }
    setTimeout(()=>{ btn.textContent=''+window.icon("save",14)+' Save'; btn.disabled=false; },2000);
    // PHASE 6: Write to sessionStorage for recovery on reload
    _bldSessionWrite();
  } catch(e) { btn.textContent='Save failed'; btn.disabled=false; }
}

// PHASE 4: Silent autosave — no UI disruption, no button flash
// Called 2s after any canvas mutation via the bldRenderCanvas debounce.

// PHASE 6: Write current page state to sessionStorage for reload recovery

// PHASE 6: On page load, check if we have a recovery snapshot newer than server data
// Called from bldLoadEditorPage after fetching server data.

// PHASE 6: Clear recovery store when user explicitly exits builder

async function bldPublish() {
  // Determine the website ID from context
  var wsId = null;
  if (typeof wsCurrentSite !== 'undefined' && wsCurrentSite) wsId = wsCurrentSite.id;
  else if (typeof bldCurrentPage !== 'undefined' && bldCurrentPage) wsId = bldCurrentPage.website_id;
  else if (typeof window._wsReturnToSite !== 'undefined') wsId = window._wsReturnToSite;

  if (!wsId) { showToast('No website context — cannot publish.', 'error'); return; }

  // Check if this website already has a subdomain
  var site = typeof wsCurrentSite !== 'undefined' && wsCurrentSite && wsCurrentSite.id === wsId ? wsCurrentSite : null;
  var hasSub = site && site.subdomain && site.subdomain.length > 5;
  var alreadyPublished = site && (site.status === 'published' || site.publish_state === 'published');

  if (!hasSub || !alreadyPublished) {
    // First publish — show subdomain picker
    _luShowSubdomainPicker(wsId, site ? (site.title || site.name) : '');
    return;
  }

  // Already published — save + republish
  var ok = await luConfirm('Republish this page with your latest changes?', 'Republish', 'Republish', 'Cancel');
  if (!ok) return;
  const btn = document.getElementById('bld-publish-btn');
  btn.textContent='Publishing…'; btn.disabled=true;
  try {
    await bldSave();
    const r = await fetch(API+'builder/websites/'+wsId+'/publish',{
      method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},body:'{}'
    });
    const d = await r.json();
    if (d.success) {
      const badge = document.getElementById('bld-page-status-badge');
      if (badge) { badge.textContent = 'published'; badge.style.color = 'var(--ac)'; }
      btn.textContent = 'Published ✓';
      setTimeout(function(){ btn.textContent = ''+window.icon("rocket",14)+' Publish'; btn.disabled = false; }, 3000);
      if (bldCurrentPage) bldCurrentPage.status = 'published';
      showToast('Changes published! Your site is updated.', 'success');
    } else {
      showToast(d.error || 'Publish failed', 'error');
      btn.textContent = ''+window.icon("rocket",14)+' Publish'; btn.disabled = false;
    }
  } catch(e) {
    showToast('Publish failed: ' + e.message, 'error');
    btn.textContent = ''+window.icon("rocket",14)+' Publish'; btn.disabled = false;
  }
}

// ── Preview renderer helpers (module-level so all callers can access) ──





// Returns just the rendered page sections HTML (used for iframe nav reload)

// Bind __nav calls in an iframe to use __parentNav bridge

// Alias so the dead-code block in bldPreview doesn't break
var _bldBuildPreviewHtml = function() {
  if (!bldCurrentPage) return '<html><body>No page</body></html>';
  var sections = bldCurrentPage.sections || [];
  var theme    = bldCurrentPage.theme || {};
  var bg       = theme.bg      || '#ffffff';
  var txt      = theme.text    || '#1a1a2e';
  var primary  = theme.primary || '#6C5CE7';
  var font     = theme.font    || 'DM Sans';
  var currentSlug = bldCurrentPage.slug || 'home';

  // allPages
  var allPages = [];
  if (_bldWebsitePages && _bldWebsitePages.length) {
    allPages = _bldWebsitePages.map(function(p) { return {id:p.id, slug:p.slug, title:p.title, sections:null}; });
  }
  var foundCurrent = false;
  for (var pi = 0; pi < allPages.length; pi++) {
    if (allPages[pi].id == bldCurrentPageId || allPages[pi].slug === currentSlug) {
      allPages[pi].sections = bldCurrentPage.sections || [];
      foundCurrent = true;
    }
  }
  if (!foundCurrent) {
    allPages.unshift({id:bldCurrentPageId, slug:currentSlug, title:bldCurrentPage.title||'Home', sections:bldCurrentPage.sections||[]});
  }

  var pageBundle = {};
  pageBundle[currentSlug] = {id:bldCurrentPageId, slug:currentSlug, title:bldCurrentPage.title||currentSlug, sections:bldCurrentPage.sections||[]};
  var otherSlugs = [];
  for (var oi = 0; oi < allPages.length; oi++) {
    if (allPages[oi].slug !== currentSlug) otherSlugs.push({id:allPages[oi].id, slug:allPages[oi].slug, title:allPages[oi].title});
  }

  // Use the same isLight/escP as renderSectionsHTML needs
  function isLight(c) {
    if (!c || c === 'transparent') return false;
    var hex = c.replace('#','');
    if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    if (hex.length !== 6) return false;
    var r = parseInt(hex.substr(0,2),16), g = parseInt(hex.substr(2,2),16), b = parseInt(hex.substr(4,2),16);
    return (r*299 + g*587 + b*114) / 1000 > 140;
  }
  function escP(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  var currentHTML = renderSectionsHTML(bldCurrentPage.sections || [], currentSlug, primary);

  var fontH = (bldCurrentPage.theme && bldCurrentPage.theme.font_heading) || '';
  var gf    = (bldCurrentPage.theme && bldCurrentPage.theme.gfonts) || '';
  var h = bldPreviewBuildHead(bg, txt, primary, font, bldCurrentPage.title || 'Preview', fontH, gf);
  h += '<div id="loading-overlay"><div class="spinner"></div><div style="font-size:13px;color:#666">Loading page...</div></div>';

  // Preview bar lives in the DOM overlay (bldPreview), not inside iframe
  h += '<div id="page-content">' + currentHTML + '</div>';

  // Embedded router script
  h += '<script>';
  h += 'var __primary = ' + JSON.stringify(primary) + ';';
  // Must define isLightColor + escPreview BEFORE renderSectionsHTML runs
  // because renderSectionsHTML body contains: var isLight = isLightColor; var escP = escPreview;
  h += 'var isLightColor = ' + isLightColor.toString() + ';';
  h += 'var escPreview   = ' + escPreview.toString() + ';';
  h += 'var isLight = isLightColor;';
  h += 'var escP   = escPreview;';
  h += 'var renderSectionsHTML = ' + renderSectionsHTML.toString() + ';';
  h += 'var __pageCache = ' + JSON.stringify(pageBundle) + ';';
  h += 'var __otherPages = ' + JSON.stringify(otherSlugs) + ';';
  h += 'var __currentSlug = ' + JSON.stringify(currentSlug) + ';';
  h += 'var __apiBase = ' + JSON.stringify(window.luApi) + ';';
  h += 'var __nonce = ' + JSON.stringify(bldNonce) + ';';
  h += 'function __nav(slug){';
  h += '  if(!slug){return;}';
  h += '  var norm=slug.replace(/^\\/+/,\'\').toLowerCase();';
  h += '  if(norm===__currentSlug){window.scrollTo({top:0,behavior:\"smooth\"});return;}';
  h += '  if(__pageCache[slug]){__renderPage(slug);return;}';
  h += '  var found=null;for(var i=0;i<__otherPages.length;i++){if(__otherPages[i].slug===slug){found=__otherPages[i];break;}}';
  h += '  if(!found)return;';
  h += '  var ov=document.getElementById("loading-overlay");if(ov)ov.classList.add("active");';
  h += '  fetch(__apiBase+"builder/load/"+found.id,{headers:{"Authorization":"Bearer "+(localStorage.getItem("lu_token")||""),"Accept":"application/json"}})';
  h += '  .then(function(r){return r.json();})';
  h += '  .then(function(d){__pageCache[slug]={id:found.id,slug:slug,title:d.title,sections:d.sections||[]};__renderPage(slug);})';
  h += '  .catch(function(e){var ov=document.getElementById("loading-overlay");if(ov)ov.classList.remove("active");});';
  h += '}';
  h += 'function __renderPage(slug){';
  h += '  var data=__pageCache[slug];if(!data)return;';
  h += '  var html=renderSectionsHTML(data.sections,slug,__primary);';
  h += '  document.getElementById("page-content").innerHTML=html;';
  h += '  document.title=(data.title||slug)+" — Preview";';
  h += '  __currentSlug=slug;';
  h += '  window.scrollTo({top:0,behavior:"smooth"});';
  h += '  var ov=document.getElementById("loading-overlay");if(ov)ov.classList.remove("active");';
  h += '}';
  h += 'window.__publishSite=function(){';
  h += '  var siteId=' + JSON.stringify(window._wsReturnToSite || 0) + ';';
  h += '  var btn=document.querySelector("#preview-bar button:last-child");';
  h += '  if(btn){btn.textContent="Publishing...";btn.disabled=true;}';
  h += '  fetch(__apiBase+"websites/"+siteId+"/publish",{method:"POST",headers:{"X-WP-Nonce":__nonce,"Content-Type":"application/json"},body:"{}"})';
  h += '  .then(function(r){return r.json();})';
  h += '  .then(function(d){if(btn){btn.textContent=d.success?"Published!":"Publish Failed";btn.disabled=false;}})';
  h += '  .catch(function(){if(btn){btn.textContent="Publish";btn.disabled=false;}});';
  h += '};';
  h += 'document.addEventListener("click",function(e){';
  h += '  var a=e.target.closest("a[href]");if(!a)return;';
  h += '  var href=a.getAttribute("href")||"";';
  h += '  var sm={"/services":"services","/about":"about","/contact":"contact","/home":"home","#contact":"contact","#services":"services","#about":"about","#home":"home"};';
  h += '  if(sm[href]){e.preventDefault();__nav(sm[href]);}';
  h += '});';
  h += '<\/script>';
  h += '</body></html>';
  return h;
};


// ── AI BUILDER ────────────────────────────────────────────────────────


// ═══════════════════════════════════════════════════════════════════════════
// AI VALIDATOR — rejects invalid/unsafe AI responses before canvas mutation
// ═══════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════
// BUILDER AI — TYPE REGISTRIES + COMPONENT SCHEMAS
// ═══════════════════════════════════════════════════════════════════════════
var _BLD_ALLOWED_SECTION_TYPES = ['header','navigation','footer','banner','sidebar','hero','features','cta','text_block','testimonials','service_grid','faq','contact_form','pricing','custom','about','team','gallery','stats','logos','newsletter'];
var _BLD_ALLOWED_COMPONENT_TYPES = ['heading','text','button','cards','form','image','divider','spacer','list'];
var _BLD_ALLOWED_ACTIONS = ['create_section','update_section','update_page','create_page','update_component','update_header','update_footer','update_navigation','create_website'];

// Required fields per component type
var _BLD_COMPONENT_SCHEMA = {
  heading: {required: ['text'], defaults: {tag:'h2', text:'Heading'}},
  text:    {required: ['text'], defaults: {text:'Text content'}},
  button:  {required: ['text'], defaults: {text:'Button', variant:'primary', href:'#'}},
  image:   {required: ['src'],  defaults: {src:'', alt:'Image'}},
  cards:   {required: ['items'], defaults: {items:[]}},
  form:    {required: ['fields'], defaults: {fields:[{label:'Name',type:'text',required:true},{label:'Email',type:'email',required:true}]}},
  divider: {required: [], defaults: {}},
  spacer:  {required: [], defaults: {}},
  list:    {required: ['items'], defaults: {items:[]}}
};

var _BLD_HTML_BAN_PATTERNS = ['<div','<section','<p>','<p ','<h1','<h2','<h3','<h4','<span','<style','<script','<table','<ul>','<ol>','<li>','class="','style="','innerHTML'];

// ═══════════════════════════════════════════════════════════════════════════
// AI RESPONSE NORMALIZATION — fills defaults, trims junk, enforces structure
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// AI VALIDATOR — rejects invalid/unsafe AI responses AFTER normalization
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// AI UNDO — snapshot state before every AI mutation
// ═══════════════════════════════════════════════════════════════════════════
var _bldAIUndoStack = null;



// ═══════════════════════════════════════════════════════════════════════════
// AI CONTEXT — builds full context for AI requests
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// AI APPLY — applies validated AI responses to builder state
// ═══════════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════
// FORMAT C SAFETY LAYER — scope guard + style guard for legacy actions
// Applied before every _bldApplyAIResponse call.
// ══════════════════════════════════════════════════════════════════════

// Allowed style keys for incoming sections from AI
var _BLD_LEGACY_ALLOWED_STYLE_KEYS = [
  'bg','gradient','color','paddingTop','paddingBottom','paddingX','padding',
  'opacity','borderRadius','border','shadow','textAlign','maxWidth','overlay',
  'fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','display',
  'alignItems','justifyContent','flexDirection','gap','minHeight',
];

// Guard 1: Scope — verify action targets are within bounds and match intended section
// Returns { ok:true } or { ok:false, reason:string }

// Guard 2: Style — strip unknown style keys from incoming section objects
// Modifies in-place (does not throw). Returns cleaned section.

// Guard 3: Structure — reject responses that would break editability
// Returns { ok:true } or { ok:false, reason:string }

// ── Combined legacy safety check — call before every _bldApplyAIResponse ──
// Returns { ok:true } or { ok:false, reason:string }


// Find section by type — returns FIRST match index, warns on duplicates

// ═══════════════════════════════════════════════════════════════════════════
// AI ACTION BUTTONS — all go through validation + undo pipeline
// ═══════════════════════════════════════════════════════════════════════════



// Create multiple pages from AI website response

// ── AI GENERATE ───────────────────────────────────────────────────────



// ── AI INPUT ENTER KEY ────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
  const input = document.getElementById('bld-ai-input');
  if (e.key === 'Enter' && !e.shiftKey && document.activeElement === input) { e.preventDefault(); bldSendAI(); }
});



// ══════════════════════════════════════════════════════════════
// ENGINE API CLIENT
// ══════════════════════════════════════════════════════════════
(function(){
  const _nonce = () => (typeof bldNonce !== 'undefined' ? bldNonce : (typeof wpNonce !== 'undefined' ? wpNonce : ''));
  const _base  = (ns) => window.location.origin + '/api/' + ns + '/';
  // Fix: suppress console 404 noise for optional engine plugin routes (CRM, Marketing etc)
  // r.ok check prevents "Unexpected token <" on HTML error responses
  // 404 = engine plugin not installed; return empty gracefully, no console error
  const _get   = (ns,p) => fetch(_base(ns)+p+(p.includes('?')?'&':'?')+'_t='+Date.now(),{headers:{'X-WP-Nonce':_nonce(),'Cache-Control':'no-cache'}})
      .then(r => { if (r.status === 404) return []; if (!r.ok) return []; return r.json(); })
      .catch(()=>[]);
  const _post  = (ns,p,b,m) => fetch(_base(ns)+p,{method:m||'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':_nonce()},body:JSON.stringify(b)})
      .then(r => { if (!r.ok) return {}; return r.json(); })
      .catch(()=>({}));
  const _del   = (ns,p) => fetch(_base(ns)+p,{method:'DELETE',headers:{'X-WP-Nonce':_nonce()}})
      .then(r => { if (!r.ok) return {}; return r.json(); })
      .catch(()=>({}));
  LuAPI.crm = {
    list:(p)=>{const q=p?'?'+new URLSearchParams(p):'';return _get('lu','crm/contacts'+q);},
    bld_get:(id)=>_get('lu','crm/contacts/'+id),
    create:(b)=>_post('lu','crm/contacts',b),
    update:(id,b)=>_post('lu','crm/contacts/'+id,b,'PUT'),
    del:(id)=>_del('lu','crm/contacts/'+id),
    archive:(id,b)=>_post('lu','crm/contacts/'+id+'/archive',b),
    bulk:(b)=>_post('lu','crm/contacts/bulk',b),
    notes:(cid)=>_get('lu','crm/contacts/'+cid+'/notes'),
    noteCreate:(cid,b)=>_post('lu','crm/contacts/'+cid+'/notes',b),
    noteUpdate:(id,b)=>_post('lu','crm/notes/'+id,b,'PUT'),
    noteDel:(id)=>_del('lu','crm/notes/'+id),
    tasks:(cid)=>_get('lu','crm/contacts/'+cid+'/tasks'),
    taskCreate:(cid,b)=>_post('lu','crm/contacts/'+cid+'/tasks',b),
    taskUpdate:(id,b)=>_post('lu','crm/tasks/'+id,b,'PUT'),
    taskDel:(id)=>_del('lu','crm/tasks/'+id),
    attachments:(cid)=>_get('lu','crm/contacts/'+cid+'/attachments'),
    attachDel:(id)=>_del('lu','crm/attachments/'+id),
    emails:(cid)=>_get('lu','crm/contacts/'+cid+'/emails'),
    emailCreate:(cid,b)=>_post('lu','crm/contacts/'+cid+'/emails',b),
    activity:(cid)=>_get('lu','crm/contacts/'+cid+'/activity'),
    settings:()=>_get('lu','crm/settings'),
    settingsUpdate:(b)=>_post('lu','crm/settings',b,'PUT'),
    views:()=>_get('lu','crm/views'),
    viewCreate:(b)=>_post('lu','crm/views',b),
    viewUpdate:(id,b)=>_post('lu','crm/views/'+id,b,'PUT'),
    viewDel:(id)=>_del('lu','crm/views/'+id),
    getLeads:()=>_get('lu','crm/contacts'),
    createLead:(b)=>_post('lu','crm/contacts',b),
    deleteLead:(id)=>_del('lu','crm/contacts/'+id),
    getContacts:()=>_get('lu','crm/contacts'),
    createContact:(b)=>_post('lu','crm/contacts',b),
    getCompanies:()=>Promise.resolve([]),
    getSequences:()=>Promise.resolve([]),
  };
  LuAPI.mkt    = { getCampaigns:()=>_get('lumkt','campaigns'), createCampaign:(b)=>_post('lumkt','campaigns',b), getTemplates:()=>_get('lumkt','templates') };
  LuAPI.social = { getAccounts:()=>_get('lu','social/accounts'), getPosts:()=>_get('lu','social/posts'), createPost:(b)=>_post('lu','social/posts',b) };
  LuAPI.cal    = { getEvents:()=>_get('lucal','events'), getBookings:()=>_get('lucal','bookings'), createEvent:(b)=>_post('lucal','events',b), getEvent:(id)=>_get('lucal','events/'+id), deleteEvent:(id)=>fetch(window.location.origin+'/api/calendar/events/'+id,{method:'DELETE',headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')}}).then(r=>r.json()) };
  LuAPI._safeFetch = (ns,m,p,b) => m==='GET'?_get(ns,p.replace(/^\//,'')):_post(ns,p.replace(/^\//,''),b,m);
  LuAPI._fetch     = LuAPI._safeFetch;

  // ═══════════════════════════════════════════════════════════════════════
  // POLICY CACHE — loaded once on init, refreshed on settings change
  // ═══════════════════════════════════════════════════════════════════════
  let _policyCache = null;
  async function _loadPolicy() {
    try { const r = await bld_get(API+'policy?_t='+Date.now()); _policyCache = {}; (r.policies||[]).forEach(p => _policyCache[p.tool_id] = p); } catch(e) { _policyCache = null; }
  }
  _loadPolicy(); // fire on init
  LuAPI.refreshPolicy = _loadPolicy; // expose globally for savePolicies

  function _policyAutoOk(toolId) {
    if (!_policyCache) return EXEC_SAFE.has(toolId); // fallback to hardcoded
    const p = _policyCache[toolId];
    return p ? !!parseInt(p.auto_execute) : false;
  }

  // ═══════════════════════════════════════════════════════════════════════
  // LuAPI.exec — UNIFIED EXECUTION WRAPPER
  // Routes through /lu/v1/tools/run → respects policy + approval/autopilot
  // ═══════════════════════════════════════════════════════════════════════
  LuAPI.exec = async function(toolId, params, opts) {
    opts = opts || {};
    const autoOk = _policyAutoOk(toolId);
    const mode = execMode || 'approval';

    // In approval mode: non-auto tools need user confirmation
    if (mode === 'approval' && !autoOk && !opts.skipApproval) {
      const confirmed = await _execConfirm(toolId, params);
      if (!confirmed) {
        const rejReason = window._lastRejectReason || 'no_reason';
        window._lastRejectReason = null;
        try { await bld_post(API+'decisions', {type:'tool_execution', agent_id:'user', title:'Rejected: '+toolId, rationale:rejReason, status:'rejected', tools:[toolId]}); } catch(e){}
        try { await bld_post(API+'policy/track', {tool_id:toolId, approved:false, reason:rejReason}); } catch(e){}
        throw new Error('Action cancelled by user');
      }
    }

    _execProgressShow(toolId);

    try {
      const result = await bld_post(API+'tools/run', {
        tool_id: toolId,
        params: params,
        agent_id: opts.agent || 'user',
        rationale: opts.rationale || '',
        approval_status: 'approved',
        skip_policy: opts.skipApproval ? 1 : (autoOk ? 1 : 0),
      });

      // Log decision for successful execution
      try { await bld_post(API+'decisions', {type:'tool_execution', agent_id:'user', title:toolId+' executed from UI', status:'approved', tools:[toolId]}); } catch(e){}
      if (!autoOk) { try { await bld_post(API+'policy/track', {tool_id:toolId, approved:true}); } catch(e){} }

      _execProgressHide();

      if (result.status === 'preview_created') {
        showToast(''+window.icon("eye",14)+' Action queued — '+window.icon("lock",14)+' requires approval per your policy', 'info');
        return result;
      }
      if (result.success !== false) {
        const policyLabel = autoOk ? ''+window.icon("ai",14)+' Auto-executed (your policy allows this)' : ''+window.icon("check",14)+' Executed (you approved)';
        showToast(policyLabel + ' — ' + toolId, 'success');
        return result;
      } else {
        showToast(''+window.icon("close",14)+' ' + (result.data?.error || 'Execution failed'), 'error');
        return result;
      }
    } catch(e) {
      _execProgressHide();
      showToast(''+window.icon("close",14)+' ' + (e.message || 'Execution error'), 'error');
      throw e;
    }
  };

  // ── Execution progress bar + toast ──────────────────────────────────
  function _execProgressShow(toolId) {
    // Progress bar at top of screen
    let bar = document.getElementById('exec-progress');
    if (!bar) { bar = document.createElement('div'); bar.id = 'exec-progress'; bar.className = 'exec-progress'; document.body.appendChild(bar); }
    bar.innerHTML = '<div class="exec-progress-bar" style="width:15%"></div>';
    bar.style.display = 'block';
    // Animate progress bar
    setTimeout(() => { const b = bar.querySelector('.exec-progress-bar'); if (b) b.style.width = '60%'; }, 300);
    setTimeout(() => { const b = bar.querySelector('.exec-progress-bar'); if (b) b.style.width = '85%'; }, 2000);

    // Execution toast
    let toast = document.getElementById('exec-toast');
    if (toast) toast.remove();
    toast = document.createElement('div');
    toast.id = 'exec-toast';
    toast.className = 'exec-toast';
    toast.innerHTML = `<div class="et-spinner"></div><div><div style="font-size:12px;font-weight:600;color:var(--t1)">Executing: ${toolId}</div><div style="font-size:10px;color:var(--t3)">Processing request…</div></div>`;
    document.body.appendChild(toast);
  }
  function _execProgressHide() {
    const bar = document.getElementById('exec-progress');
    if (bar) { const b = bar.querySelector('.exec-progress-bar'); if (b) b.style.width = '100%'; setTimeout(() => bar.style.display = 'none', 400); }
    const toast = document.getElementById('exec-toast');
    if (toast) { toast.style.opacity = '0'; toast.style.transition = 'opacity .3s'; setTimeout(() => toast.remove(), 300); }
  }

  // ── Inline policy badge for any tool ───────────────────────────────
  const _PROTECTED = new Set(['publish_post','send_campaign','create_campaign','update_campaign','export_website','export_page','publish_builder_page','create_lead','enroll_sequence','create_booking_slot']);
  window.policyBadge = function(toolId) {
    if (_PROTECTED.has(toolId)) return '<span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:3px;background:rgba(248,113,113,.12);color:var(--rd);margin-left:4px">'+window.icon("lock",14)+' PROTECTED</span>';
    if (_policyAutoOk(toolId)) return '<span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:3px;background:rgba(0,229,168,.12);color:var(--ac);margin-left:4px">'+window.icon("ai",14)+' AUTO</span>';
    return '<span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:3px;background:rgba(255,183,77,.12);color:var(--am);margin-left:4px">'+window.icon("lock",14)+' REVIEW</span>';
  };

  // Confirmation dialog with rejection reason support
  function _execConfirm(toolId, params) {
    return new Promise(resolve => {
      const overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;animation:fadeIn .2s ease';
      const paramStr = Object.entries(params||{}).filter(([k,v])=>v).map(([k,v])=>`<div style="font-size:11px;color:var(--t3)"><strong>${k}:</strong> ${typeof v==='object'?JSON.stringify(v):v}</div>`).join('');
      const isSafe = EXEC_SAFE && EXEC_SAFE.has(toolId);
      const isProtected = ['publish_post','send_campaign','create_campaign','update_campaign','export_website','export_page','publish_builder_page','create_lead','enroll_sequence','create_booking_slot'].includes(toolId);
      overlay.innerHTML = `<div style="background:var(--s2);border:1px solid var(--bd2);border-radius:16px;padding:28px 32px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.5)">
        <div style="font-size:32px;text-align:center;margin-bottom:12px">${isProtected?''+window.icon("lock",14)+'':isSafe?''+window.icon("search",14)+'':''+window.icon("warning",14)+''}</div>
        <div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1);text-align:center;margin-bottom:6px">Confirm Action</div>
        <div style="font-size:13px;color:var(--t2);text-align:center;margin-bottom:16px">Tool: <strong>${toolId}</strong> <span style="font-size:10px;padding:2px 6px;border-radius:4px;background:${isProtected?'rgba(248,113,113,.15);color:var(--rd)':isSafe?'rgba(0,229,168,.15);color:var(--ac)':'rgba(255,183,77,.15);color:var(--am)'}">${isProtected?'PROTECTED':isSafe?'SAFE':'REVIEW'}</span></div>
        ${isProtected?'<div style="font-size:11px;color:var(--rd);text-align:center;margin-bottom:12px">'+window.icon("lock",14)+' This is a high-impact action that always requires your approval.</div>':''}
        ${paramStr?'<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;margin-bottom:16px">'+paramStr+'</div>':''}
        <div id="exec-reject-section" style="display:none;margin-bottom:14px">
          <div style="font-size:11px;font-weight:700;color:var(--t3);margin-bottom:6px">Why are you rejecting this?</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
            <button class="rej-reason-btn" data-reason="not_right_time" style="font-size:10px;padding:4px 10px;border-radius:6px;border:1px solid var(--bd);background:var(--s1);color:var(--t2);cursor:pointer">Not the right time</button>
            <button class="rej-reason-btn" data-reason="not_relevant" style="font-size:10px;padding:4px 10px;border-radius:6px;border:1px solid var(--bd);background:var(--s1);color:var(--t2);cursor:pointer">Not relevant</button>
            <button class="rej-reason-btn" data-reason="needs_adjustment" style="font-size:10px;padding:4px 10px;border-radius:6px;border:1px solid var(--bd);background:var(--s1);color:var(--t2);cursor:pointer">Needs adjustment</button>
            <button class="rej-reason-btn" data-reason="prefer_manual" style="font-size:10px;padding:4px 10px;border-radius:6px;border:1px solid var(--bd);background:var(--s1);color:var(--t2);cursor:pointer">Prefer manual control</button>
          </div>
          <input id="rej-reason-text" type="text" placeholder="Other reason (optional)" style="width:100%;font-size:11px;padding:6px 10px;border:1px solid var(--bd);border-radius:6px;background:var(--s1);color:var(--t1);box-sizing:border-box">
          <button id="exec-reject-confirm" style="margin-top:8px;background:var(--rd);border:none;color:#fff;border-radius:6px;padding:7px 16px;font-size:11px;cursor:pointer;font-weight:600">Confirm Rejection</button>
        </div>
        <div id="exec-btn-row" style="display:flex;gap:10px;justify-content:center">
          <button id="exec-cancel" style="background:var(--s3);border:1px solid var(--bd2);color:var(--rd);border-radius:8px;padding:9px 20px;font-size:13px;cursor:pointer;font-family:var(--fh)">✕ Reject</button>
          <button id="exec-approve" style="background:var(--p);border:none;color:#fff;border-radius:8px;padding:9px 20px;font-size:13px;cursor:pointer;font-family:var(--fh);font-weight:600">✓ Execute</button>
        </div>
      </div>`;
      document.body.appendChild(overlay);

      let selectedReason = '';
      document.getElementById('exec-cancel').onclick = () => {
        // Show rejection reason section
        document.getElementById('exec-reject-section').style.display = 'block';
        document.getElementById('exec-btn-row').style.display = 'none';
      };
      // Reason button clicks
      overlay.querySelectorAll('.rej-reason-btn').forEach(btn => {
        btn.onclick = () => {
          overlay.querySelectorAll('.rej-reason-btn').forEach(b => { b.style.background = 'var(--s1)'; b.style.borderColor = 'var(--bd)'; });
          btn.style.background = 'rgba(248,113,113,.15)';
          btn.style.borderColor = 'var(--rd)';
          selectedReason = btn.dataset.reason;
        };
      });
      document.getElementById('exec-reject-confirm').onclick = () => {
        const textReason = document.getElementById('rej-reason-text')?.value?.trim() || '';
        window._lastRejectReason = selectedReason || textReason || 'no_reason';
        overlay.remove();
        resolve(false);
      };
      document.getElementById('exec-approve').onclick = () => { overlay.remove(); resolve(true); };
      overlay.onclick = (e) => { if(e.target===overlay){ window._lastRejectReason='dismissed'; overlay.remove(); resolve(false); } };
    });
  }
})();

// ── Inject policy badges into module action buttons ──────────────────────

// --- block from core.js lines 9663-9663 ---
_arthurSelection = null;

// --- block from core.js lines 9666-9859 ---



// Send custom prompt from Arthur input


// ══════════════════════════════════════════════════════════════════════════
// ARTHUR EXECUTION ENGINE v3 — Deterministic Builder Controller
// ══════════════════════════════════════════════════════════════════════════

// ── TASK 1: CENTRALIZED BUILDER SCHEMA ────────────────────────────────────
var ARTHUR_SCHEMA = {
  section: {
    style: {
      bg:            { type:'color' },
      gradient:      { type:'gradient' },
      color:         { type:'color' },
      paddingTop:    { type:'px' },
      paddingBottom: { type:'px' },
      paddingX:      { type:'px' },
      opacity:       { type:'float' },
      borderRadius:  { type:'px' },
      border:        { type:'string' },
      shadow:        { type:'string' },
      fontFamily:    { type:'string' },
      textAlign:     { type:'enum',   values:['left','center','right'] },
      maxWidth:      { type:'px' },
    },
    layout: {
      width:   { type:'enum', values:['full','contained','narrow'] },
      align:   { type:'enum', values:['left','center','right'] },
      columns: { type:'int' },
    }
  },
  components: {
    heading: {
      text:               { type:'string' },
      tag:                { type:'enum', values:['h1','h2','h3','h4'] },
      'style.color':      { type:'color' },
      'style.fontSize':   { type:'px' },
      'style.fontWeight': { type:'enum', values:['400','500','600','700','800','900'] },
      'style.fontFamily': { type:'string' },
      'style.textAlign':  { type:'enum', values:['left','center','right'] },
      'style.lineHeight': { type:'string' },
      'style.letterSpacing':{ type:'string' },
    },
    text: {
      text:               { type:'string' },
      'style.color':      { type:'color' },
      'style.fontSize':   { type:'px' },
      'style.fontFamily': { type:'string' },
      'style.lineHeight': { type:'string' },
    },
    button: {
      text:                 { type:'string' },
      href:                 { type:'string' },
      variant:              { type:'enum', values:['primary','secondary','outline','ghost'] },
      'style.bg':           { type:'color' },
      'style.color':        { type:'color' },
      'style.borderRadius': { type:'px' },
      'style.fontSize':     { type:'px' },
    },
    image: { src:{ type:'url' }, alt:{ type:'string' } },
    cards: {
      'items[].icon':    { type:'string' },
      'items[].heading': { type:'string' },
      'items[].text':    { type:'string' },
    },
    form: {
      'fields[].label':   { type:'string' },
      'fields[].type':    { type:'enum', values:['text','email','tel','textarea','select'] },
      'fields[].required':{ type:'bool' },
    },
  }
};

// ── TASK 3: KEY ALIAS MAP — loose input → schema key ─────────────────────
var ARTHUR_KEY_ALIASES = {
  // background
  'background':        'style.bg',
  'bg':                'style.bg',
  'background-color':  'style.bg',
  'backgroundcolor':   'style.bg',
  'background_color':  'style.bg',
  'bgcolor':           'style.bg',
  // gradient
  'gradient':          'style.gradient',
  'background-gradient':'style.gradient',
  // text color
  'textcolor':         'style.color',
  'text-color':        'style.color',
  'text_color':        'style.color',
  'color':             'style.color',
  'fontcolor':         'style.color',
  // padding
  'padding':           'style.paddingTop', // handled specially
  'paddingtop':        'style.paddingTop',
  'paddingbottom':     'style.paddingBottom',
  'paddingx':          'style.paddingX',
  // typography
  'fontsize':          'style.fontSize',
  'font-size':         'style.fontSize',
  'fontweight':        'style.fontWeight',
  'textalign':         'style.textAlign',
  'text-align':        'style.textAlign',
  'align':             'style.textAlign',
  // layout
  'width':             'layout.width',
  'maxwidth':          'style.maxWidth',
  'opacity':           'style.opacity',
  'borderradius':      'style.borderRadius',
  'border-radius':     'style.borderRadius',
  'shadow':            'style.shadow',
  // typography
  'font':              'style.fontFamily',
  'fontfamily':        'style.fontFamily',
  'font-family':       'style.fontFamily',
  'font_family':       'style.fontFamily',
  'fontstyle':         'style.fontFamily',
  'font-style':        'style.fontFamily',
  'typeface':          'style.fontFamily',
  'fonttype':          'style.fontFamily',
  'font-type':         'style.fontFamily',
  'fontsize':          'style.fontSize',
  'font-size':         'style.fontSize',
  'font_size':         'style.fontSize',
  'size':              'style.fontSize',
  'fontweight':        'style.fontWeight',
  'font-weight':       'style.fontWeight',
  'weight':            'style.fontWeight',
  'bold':              'style.fontWeight', // handled specially
  'linespacing':       'style.lineHeight',
  'line-spacing':      'style.lineHeight',
  'lineheight':        'style.lineHeight',
  'line-height':       'style.lineHeight',
  'letterspacing':     'style.letterSpacing',
};

// ── Color map ─────────────────────────────────────────────────────────────
var _arthurColorMap = {
  red:'#ef4444',crimson:'#dc2626',rose:'#f43f5e',
  blue:'#3b82f6',navy:'#1e3a5f',sky:'#0ea5e9',cobalt:'#2563eb',
  green:'#22c55e',emerald:'#10b981',lime:'#84cc16',
  yellow:'#eab308',amber:'#f59e0b',gold:'#d97706',
  orange:'#f97316',burnt:'#c2410c',
  purple:'#a855f7',violet:'#7c3aed',indigo:'#6366f1',lavender:'#c4b5fd',
  pink:'#ec4899',fuchsia:'#d946ef',
  teal:'#14b8a6',cyan:'#06b6d4',turquoise:'#0d9488',
  white:'#ffffff',black:'#000000','off-white':'#f8f9fa',
  gray:'#6b7280',grey:'#6b7280',dark:'#0F1117',light:'#f8f9fa',
  charcoal:'#1f2937',slate:'#475569',stone:'#78716c',
  brand:'#6C5CE7',accent:'#00E5A8',primary:'#6C5CE7',secondary:'#A78BFA',
  silver:'#94a3b8',transparent:'transparent',
};



// ── Gradient resolver ─────────────────────────────────────────────────────

// ── TASK 3: NORMALIZATION LAYER ───────────────────────────────────────────
// Maps loose/aliased keys to canonical schema keys.
// Returns { normalized: {}, rejected: [] }

// ── TASK 4: HARD VALIDATION GATE ─────────────────────────────────────────
// Returns { valid: true } or { valid: false, errors: [] }
// Validates canonical keys against ARTHUR_SCHEMA.

// ── TASK 5: COMPONENT TARGETING ───────────────────────────────────────────
// Find component in section by type and optional index.
// Returns { component, index } or null.
// TASK 2: Find ALL components of a type within a SPECIFIC section (si-bound)

// Single-match helper (uses _arthurFindComponents internally)

// TASK 3: Expand a component-targeted change into one action per matching component,
// all scoped to section si. Never leaks to other sections.

// TASK 4: Scope violation guard — verify action targets stay within si

// ── TASK 6: SCHEMA SUMMARY FOR AI PAYLOAD ────────────────────────────────

// ── TASK 5+7: APPLY ENGINE — strict, no silent failures ──────────────────
// Returns { applied: true, summary } or throws with reason

// ══════════════════════════════════════════════════════════════════════════
// ARTHUR MULTI-ACTION EXECUTION ENGINE
// All-or-nothing: validates all actions, snapshots state, executes atomically.
// ══════════════════════════════════════════════════════════════════════════

// TASK 6: Max actions guard
var ARTHUR_MAX_ACTIONS = 5;

// TASK 2+3: Execute an array of validated actions atomically.
// Returns { success:true, applied:N, summaries:[] } or throws with { error, failed_at }.

// ══════════════════════════════════════════════════════════════════════════
// ARTHUR GROUP EXECUTION ENGINE
// Groups actions by section_index + component_type before execution.
// Ensures deterministic ordering, clean logs, and full-batch rollback.
// ══════════════════════════════════════════════════════════════════════════

// TASK 1: Group actions by section_index + component_type
// Returns: { 'si:section': [...], 'si:heading': [...], 'si:button': [...] }

// TASK 2+3: Execute groups sequentially — all-or-nothing per batch
// Returns { success, groups_result, total_applied } or throws

// Helper: wrap interpreter changes into a single-action array (TASK 4)

// ══════════════════════════════════════════════════════════════════════════
// INTERPRETER — Deterministic pattern matching, zero AI
// ══════════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════════
// TASK 5 — FONT NORMALIZATION
// ══════════════════════════════════════════════════════════════════════════
var _arthurFontMap = {
  // Sans-serif
  'arial':        'Arial, sans-serif',
  'helvetica':    'Helvetica Neue, Helvetica, Arial, sans-serif',
  'calibri':      'Calibri, Candara, Segoe UI, sans-serif',
  'verdana':      'Verdana, Geneva, Tahoma, sans-serif',
  'tahoma':       'Tahoma, Verdana, Segoe, sans-serif',
  'trebuchet':    'Trebuchet MS, Helvetica, sans-serif',
  'gill sans':    'Gill Sans, Optima, sans-serif',
  'century gothic':'Century Gothic, CenturyGothic, AppleGothic, sans-serif',
  'franklin gothic':'Franklin Gothic Medium, Arial Narrow, Arial, sans-serif',
  'optima':       'Optima, Segoe UI, sans-serif',
  'futura':       'Futura, Century Gothic, AppleGothic, sans-serif',
  'open sans':    '"Open Sans", sans-serif',
  'lato':         'Lato, sans-serif',
  'roboto':       'Roboto, Arial, sans-serif',
  'montserrat':   'Montserrat, sans-serif',
  'raleway':      'Raleway, sans-serif',
  'nunito':       'Nunito, sans-serif',
  'inter':        'Inter, sans-serif',
  'poppins':      'Poppins, sans-serif',
  'dm sans':      '"DM Sans", sans-serif',
  'syne':         'Syne, sans-serif',
  // Serif
  'times':        'Times New Roman, Times, serif',
  'times new roman':'Times New Roman, Times, serif',
  'georgia':      'Georgia, Times New Roman, serif',
  'garamond':     'Garamond, Georgia, serif',
  'palatino':     'Palatino Linotype, Book Antiqua, Palatino, serif',
  'book antiqua': 'Book Antiqua, Palatino, serif',
  'cambria':      'Cambria, Georgia, serif',
  'merriweather': 'Merriweather, Georgia, serif',
  'playfair':     '"Playfair Display", Georgia, serif',
  'lora':         'Lora, Georgia, serif',
  // Monospace
  'courier':      'Courier New, Courier, monospace',
  'courier new':  'Courier New, Courier, monospace',
  'consolas':     'Consolas, Monaco, monospace',
  'monaco':       'Monaco, Consolas, monospace',
  'lucida console':'Lucida Console, Monaco, monospace',
  // Display / modern
  'impact':       'Impact, Haettenschweiler, Arial Narrow Bold, sans-serif',
  'oswald':       'Oswald, sans-serif',
  'bebas':        '"Bebas Neue", Impact, sans-serif',
  'bebas neue':   '"Bebas Neue", Impact, sans-serif',
};


// ══════════════════════════════════════════════════════════════════════════
// TASKS 1+2 — COMPOUND COMMAND PARSER
// Splits multi-part commands and runs each through the interpreter,
// collecting all resolved actions for atomic multi-action execution.
// ══════════════════════════════════════════════════════════════════════════

// Split compound input into individual commands

// Run the interpreter on each split command, return actions array or {handled:false}
// If ALL parts resolve deterministically → returns { handled:true, actions:[] }
// If ANY part doesn't resolve → returns { handled:false } (falls through to AI)


// ── TASK 1+2+6: Public entry point — handles single and compound commands ──
// Returns: { handled:true, summary, actions[] } or { handled:false, hint }

// Collect-mode wrapper: runs interpreter but returns actions without executing



// ── TASK 1: Strict JSON parse — no extraction, no fallback ───────────────
// Returns parsed object or throws with reason.
// ── Stubs for removed editor functions ──
var bldSetDevice=function(){};var bldOpenEditor=function(){};var bldRenderCanvas=function(){};
var bldToggleAI=function(){};var bldDirty=false;var bldPages=[];
var bldPreview=function(){};var bldCurrentPageId=null;var bldCurrentPage=null;
var arthurShow=function(){};var arthurContext=null;var arthurBusy=false;
var arthurHide=function(){};
var arthurUpdateSelection=function(){};


// Tier 4 confirm helper — posts to /tier4-confirm and replaces the confirmation bubble with the result.
async function _t3ConfirmTier4(websiteId, btn, confirmAction, confirmData) {
  try {
    btn.disabled = true; btn.textContent = 'Working...';
    var t = localStorage.getItem('lu_token') || '';
    var r = await fetch('/api/builder/websites/' + websiteId + '/tier4-confirm', {
      method: 'POST',
      headers: {'Authorization':'Bearer '+t, 'Content-Type':'application/json', 'Accept':'application/json'},
      body: JSON.stringify({confirm_action: confirmAction, confirm_data: confirmData})
    });
    var d = await r.json();
    var bubble = btn.closest('div[id^="conf_"]');
    if (bubble) bubble.remove();
    var feed = document.getElementById('t3-arthur-feed');
    if (feed) {
      if (d.error) {
        feed.innerHTML += '<div style="background:rgba(248,113,113,.08);padding:10px 12px;border-radius:8px;margin:4px 0"><div style="color:#F87171;font-size:13px">' + bld_escH(d.error) + '</div></div>';
      } else {
        feed.innerHTML += '<div style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0;border-left:3px solid #00E5A8"><div style="color:var(--t1);font-size:13px">' + bld_escH(d.message || 'Done.') + '</div></div>';
        if (d.reload_preview) { var ifr = document.getElementById('t3-preview'); if (ifr) ifr.src = ifr.src; }
        if (typeof wsLoadSites === 'function' && (d.action === 'page_deleted' || d.action === 'page_duplicated')) wsLoadSites();
      }
    }
  } catch(err) {
    console.error('[Tier4 confirm]', err);
    btn.disabled = false; btn.textContent = 'Confirm';
  }
}
