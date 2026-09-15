// ARTHUR EDITOR FIX (2026-09-10) — wsSites and wsCurrentSite were never declared anywhere; they only
// became globals when wsLoadSites() assigned them. wsOpenSite()'s FIRST statement reads wsSites, so for
// anyone who had not already opened the Websites grid — which is every new signup coming straight out of
// the Arthur wizard — it threw "wsSites is not defined" before doing anything. _arthurOpenBuiltSite()
// called it un-awaited and uncaught, so the throw was silent: the modal closed and the customer was left
// looking at Sarah. Same class as the bld_ensureArray note below. Declared, so the read is always safe.
var wsSites = [];
var wsCurrentSite = null;
function _bldSafeText(v){
  if(typeof v==='string') return v;
  if(v===null||v===undefined) return '';
  if(Array.isArray(v)) return v.map(function(x){return typeof x==='string'?x:typeof x==='object'&&x&&x.text?String(x.text):String(x||'')}).join(' ');
  if(typeof v==='object'){if(v.text) return String(v.text); if(v.content) return String(v.content); if(v.label) return String(v.label); if(v.heading) return String(v.heading); try{return JSON.stringify(v)}catch(e){return '';}}
  return String(v);
}
function bld_esc(t){return _bldSafeText(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function bld_escH(t){return _bldSafeText(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
// Owner 2026-09-10: the standing guidance above the Arthur chat field is useful exactly once.
// It now carries a close button and remembers being closed, so a first-time customer still gets the
// hint and everybody else gets the space back. Dismissal is per-browser (localStorage) — it is a
// convenience, not state anyone needs on the server.
function _bldHintDismissed(id) {
  try { return localStorage.getItem('lu_hint_' + id) === '1'; } catch (e) { return false; }
}
function _bldHint(id, innerHtml, extraStyle) {
  if (_bldHintDismissed(id)) { return ''; }
  return '<div data-bld-hint="' + id + '" style="position:relative;background:var(--s2);border-radius:8px;' +
    'padding:9px 30px 9px 11px;font-size:11px;color:var(--t2);line-height:1.55;' + (extraStyle || '') + '">' +
    innerHtml +
    '<button type="button" data-bld-hint-close="' + id + '" aria-label="Dismiss this tip" ' +
      'style="position:absolute;top:5px;right:6px;width:18px;height:18px;line-height:1;padding:0;' +
      'background:none;border:none;color:var(--t3);font-size:14px;cursor:pointer;border-radius:4px">\u00d7</button>' +
  '</div>';
}
if (!window.__bldHintsWired) {
  window.__bldHintsWired = true;
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-bld-hint-close]') : null;
    if (!btn) { return; }
    e.preventDefault(); e.stopPropagation();
    var id = btn.getAttribute('data-bld-hint-close');
    try { localStorage.setItem('lu_hint_' + id, '1'); } catch (_e) {}
    var box = document.querySelector('[data-bld-hint="' + id + '"]');
    if (box && box.parentNode) { box.parentNode.removeChild(box); }
  });
}

// BUILDER888 P1 (2026-08-09) — was called by wsOpenSite() but never defined,
// so opening any website's page list threw ReferenceError and every site
// showed "Failed to load pages.". Mirrors core.js::ensureArray, but kept
// self-contained so it cannot depend on script load order.
function bld_ensureArray(val) {
  if (Array.isArray(val)) return val;
  if (val && typeof val === 'object') return Object.values(val);
  return [];
}
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
async function bld_openColors(wsId){
  if(!window.LUColorPicker){ showToast('Colour picker is still loading — try again in a moment.','info'); return; }
  var cur={primary:'#6C5CE7',secondary:'#00E5A8',accent:'#F4F7FB'};
  try{ var d=await bld_get(API+'workspace/brand'); if(d){cur={primary:d.primary_color||cur.primary,secondary:d.secondary_color||cur.secondary,accent:d.accent_color||cur.accent};} }catch(e){}
  window.LUColorPicker.open({colors:cur,onSave:async function(colors){
    try{
      var r=await fetch(API+'workspace/brand',{method:'PUT',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')},body:JSON.stringify({primary_color:colors.primary,secondary_color:colors.secondary,accent_color:colors.accent,website_id:wsId})});
      var jd=await r.json();
      if(jd&&jd.success){ showToast('Brand colours saved','success'); var fr=document.querySelector('iframe#pe-frame,.pe-frame,iframe'); if(fr){try{fr.src=fr.src;}catch(_){}} }
      else { showToast((jd&&jd.error)||'Could not save colours','error'); }
    }catch(e){ showToast('Could not save colours','error'); }
  }});
}


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
    prev.innerHTML=`${isImg?''+window.icon("image",14)+'':''+window.icon("attach",14)+''} <strong>${bld_esc(d.file.name)}</strong> ready — ${isImg?'team will analyse this image':'file attached'} <button type="button" aria-label="Remove attachment" onclick="bld_clearUpload()" style="background:none;border:none;color:inherit;font:inherit;cursor:pointer;opacity:.6;margin-left:8px">✕</button>`;
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
          <button class="dm-send" id="dm-send" onclick="bld_sendDm()" aria-label="Send" title="Send"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"></path><path d="m22 2-7 20-4-9-9-4Z"></path></svg></button>
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
        if (btn) { btn.disabled = false; /* icon button: nothing to re-label */ }
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
  luAlert("Workload Summary", lines.join("\n"));
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
    `<button class="ai-q-btn" onclick="bld_aiQuick(${JSON.stringify(prompt).replace(/"/g,'&quot;')})">${label}</button>`
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

  // CR-01 (2026-07-26) — errors render as a structured notice, never as an
  // assistant bubble (CHAT-CONTRACT-v1 clause X-08/G-06).
  if (role === 'error') {
    wrap.innerHTML = '<div class="ai-msg-error" role="alert" style="display:flex;gap:8px;align-items:flex-start;'
      + 'padding:10px 12px;border-radius:10px;background:rgba(239,68,68,.08);'
      + 'border:1px solid rgba(239,68,68,.25);color:var(--t1);font-size:13px;line-height:1.5">'
      + '<span class="ai-msg-error-ic" style="flex-shrink:0;display:inline-flex;color:#EF4444"></span>'
      + '<span class="ai-msg-error-text"></span></div>';
    var _eIc = wrap.querySelector('.ai-msg-error-ic');
    var _eTx = wrap.querySelector('.ai-msg-error-text');
    if (_eIc) _eIc.innerHTML = window.icon('warning', 14);
    if (_eTx) _eTx.textContent = content;
    feed.appendChild(wrap);
    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }

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
  wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
    // CR-01 (2026-07-26): a failure is not something the agent said. It renders
    // as a structured error now — icon as an element, message as text — so a
    // literal "<svg>" arriving in an error string stays inert.
    bld_aiAddMsg('error', (e.message||'Something went wrong. Please try again.'));
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
  const total=wsSites.length,pub=wsSites.filter(s=>s.publish_state==='published'||s.status==='published').length,dom=wsSites.filter(s=>s.custom_domain||s.domain).length;
  const el=id=>document.getElementById(id);
  // PATCH (plan-limit, 2026-05-09) — show "X / MAX" when wsMaxWebsites
  // is known (set by _luUpdateWebsiteUsage from /api/websites response).
  var max = (typeof wsMaxWebsites === 'number' && wsMaxWebsites > 0) ? wsMaxWebsites : null;
  if(el('ws-stat-total')) el('ws-stat-total').textContent = max ? (total + ' / ' + max) : total;
  if(el('ws-stat-pub'))   el('ws-stat-pub').textContent=pub;
  if(el('ws-stat-draft')) el('ws-stat-draft').textContent=total-pub;
  if(el('ws-stat-domain'))el('ws-stat-domain').textContent=dom;

  // Disable + New Website button if at limit.
  if (max && total >= max) {
    document.querySelectorAll('button.ct-btn.primary[onclick*="wsShowCreate"]').forEach(function(b){
      b.disabled = true;
      b.style.opacity = '0.5';
      b.style.cursor = 'not-allowed';
      b.title = 'Plan limit reached (' + max + ' websites). Upgrade to add more.';
    });
  } else {
    document.querySelectorAll('button.ct-btn.primary[onclick*="wsShowCreate"]').forEach(function(b){
      b.disabled = false;
      b.style.opacity = '';
      b.style.cursor = '';
      b.title = '';
    });
  }
}

// PATCH (plan-limit, 2026-05-09) — captures plan max_websites + current
// usage from /api/websites response so wsUpdateStats can render the
// "X / MAX" format. Called from wsLoadSites if the response includes
// a usage block (server already returns this — see routes/api.php
// /websites endpoint).
window.wsMaxWebsites = null;
window._luUpdateWebsiteUsage = function (usage) {
  if (!usage) return;
  if (typeof usage.max === 'number') wsMaxWebsites = usage.max;
  else if (typeof usage.max_websites === 'number') wsMaxWebsites = usage.max_websites;
  if (typeof wsUpdateStats === 'function') wsUpdateStats();
};

function wsRenderGrid(){
  const grid=document.getElementById('ws-grid'); if(!grid) return;
  if (!Array.isArray(wsSites)) { console.warn('[wsRenderGrid] wsSites not an array:', wsSites); wsSites = []; }
  if(!wsSites.length){
    grid.innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:80px 20px;color:var(--t3)"><div style="font-size:48px;margin-bottom:16px">${window.icon('globe',14)}</div><div style="font-size:16px;font-weight:600;color:var(--t2);margin-bottom:8px">No websites yet</div><div style="font-size:13px;margin-bottom:24px">Create your first multi-page website</div><button class="ct-btn primary" onclick="wsShowCreate()">+ New Website</button></div>`;
    return;
  }
  // SITE THUMBNAIL (2026-09-15): spinner styles once; while a preview is still being rendered, refresh the list every 20 s (10 min at most).
  if(!document.getElementById('ws-thumb-css')){var _st=document.createElement('style');_st.id='ws-thumb-css';_st.textContent='.ws-thumb{overflow:hidden}.ws-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:top center;transition:opacity .35s ease}.ws-thumb.ws-thumb-loading img{opacity:0}.ws-thumb-spin{display:none;width:28px;height:28px;border:3px solid rgba(255,255,255,.14);border-top-color:var(--pu,#6C5CE7);border-radius:50%;animation:wsThumbSpin .9s linear infinite}.ws-thumb.ws-thumb-loading .ws-thumb-spin{display:block}.ws-thumb-note{position:absolute;left:0;right:0;bottom:12px;text-align:center;font-size:11px;color:var(--t3,rgba(255,255,255,.55))}@keyframes wsThumbSpin{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.ws-thumb-spin{animation:none;border-top-color:rgba(255,255,255,.14)}}';document.head.appendChild(_st);}
  clearTimeout(window._wsThumbPoll);
  if(wsSites.some(function(s){return !(s.type==='external'||!!s.external_url)&&!s.thumbnail_url;})){window._wsThumbPollN=(window._wsThumbPollN||0)+1;if(window._wsThumbPollN<=30){window._wsThumbPoll=setTimeout(function(){if(document.getElementById('ws-grid')&&typeof wsLoadSites==='function')wsLoadSites();},20000);}}else{window._wsThumbPollN=0;}
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
    // SITE THUMBNAIL (2026-09-15): builder sites carry a home-page shot too (websites.thumbnail_url, re-shot after each change).
    // A spinner covers the card until the image has loaded; a site whose preview is still being rendered says so.
    const badgeHtml=isExt?`<span class="ws-badge" style="${badgeStyle};position:absolute;top:8px;left:8px;font-size:10px;padding:2px 8px;border-radius:5px;font-weight:600">${badgeText}</span>`:`<span class="ws-badge ${badgeClass}">${badgeText}</span>`;
    if(s.thumbnail_url){
      thumbContent=`<div class="ws-thumb ws-thumb-loading"><div class="ws-thumb-spin"></div><img src="${s.thumbnail_url}" alt="" loading="lazy" onload="this.parentNode.classList.remove('ws-thumb-loading')" onerror="this.parentNode.classList.remove('ws-thumb-loading');this.remove()">${badgeHtml}</div>`;
    }else if(!isExt){
      thumbContent=`<div class="ws-thumb ws-thumb-loading ws-thumb-pending"><div class="ws-thumb-spin"></div><span class="ws-thumb-note">Preparing preview…</span>${badgeHtml}</div>`;
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
        +`<button aria-label="Delete website" title="Delete website" onclick="event.stopPropagation();wsDelete(${s.id})" style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);border-radius:5px;color:#F87171;padding:4px 7px;font-size:11px;cursor:pointer">✕</button>`;
    }else{
      // PATCH (FIX 1, 2026-05-09) — published sites get View↗ instead of
      // Publish. Live URL precedence: custom_domain > subdomain > /storage
      // /sites/{id}/index.html. Publish only renders for drafts.
      var _isPub = s.publish_state === 'published' || s.status === 'published';
      var _liveUrlBtn;
      if (s.custom_domain) _liveUrlBtn = 'https://' + s.custom_domain;
      else if (s.subdomain) _liveUrlBtn = 'https://' + (String(s.subdomain).indexOf('.') === -1 ? s.subdomain + '.levelupgrowth.io' : s.subdomain);
      else _liveUrlBtn = '/storage/sites/' + s.id + '/index.html';

      var _publishOrView = _isPub
        ? `<a href="${bld_escH(_liveUrlBtn)}" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="ct-btn primary" style="font-size:11px;padding:4px 10px;text-decoration:none;display:inline-flex;align-items:center;gap:4px">View ↗</a>`
        : `<button class="ct-btn primary" onclick="wsShowPublish(${s.id})" style="font-size:11px;padding:4px 10px">Publish</button>`;

      actions=`<button class="ct-btn" onclick="wsOpenSite(${s.id})" style="font-size:11px;padding:4px 10px">Edit</button>`
        + _publishOrView
        +`<button aria-label="Delete website" title="Delete website" onclick="wsDelete(${s.id})" style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);border-radius:5px;color:#F87171;padding:4px 7px;font-size:11px;cursor:pointer">✕</button>`;
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

function _wsExtPluginInfo(siteId){
  var existing=document.getElementById('ws-plugin-modal');
  if(existing){existing.remove();return;}
  // WP-1 (2026-08-29, EV-0872): this modal used to end in showToast('Plugin download coming soon').
  // It now delivers the plugin, mints the connector key, and shows the real connection state.
  var ov=document.createElement('div');ov.id='ws-plugin-modal';
  ov.style.cssText='position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center';
  ov.innerHTML='<div role="dialog" aria-label="Connect WordPress" style="background:var(--s1);border:1px solid var(--bd);border-radius:16px;width:92%;max-width:560px;padding:28px;max-height:90vh;overflow:auto">'
    +'<div style="font-family:var(--fh);font-size:18px;font-weight:700;color:var(--t1);margin-bottom:6px">'+window.icon("link",14)+' Connect your WordPress site</div>'
    +'<div style="font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px">Three steps. Sarah can then publish articles, manage meta and run the chatbot on your WordPress site.</div>'
    +'<div id="wsp-status" style="font-size:12px;color:var(--t3);margin-bottom:14px">Checking connection…</div>'
    +'<div style="display:grid;gap:12px">'
    +'<div style="background:var(--s2);border-radius:10px;padding:14px"><div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:6px">1. Download the plugin</div><div style="font-size:12px;color:var(--t3);margin-bottom:10px" id="wsp-plugin-meta">…</div><button class="ct-btn primary" id="wsp-dl" style="padding:8px 16px">⬇ Download plugin (.zip)</button></div>'
    +'<div style="background:var(--s2);border-radius:10px;padding:14px"><div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:6px">2. Create your connector key</div><div style="font-size:12px;color:var(--t3);margin-bottom:10px">Shown once. Paste it into WordPress → Settings → LevelUpGrowth SEO → API Key, with Workspace ID <strong style="color:var(--t1)">'+bld_escH(String(localStorage.getItem('lu_workspace_id')||''))+'</strong>.</div><div style="display:flex;gap:8px;align-items:center"><button class="ct-btn" id="wsp-key" style="padding:8px 16px">Create key</button><code id="wsp-key-out" style="font-size:12px;color:var(--t1);word-break:break-all"></code></div></div>'
    +'<div style="background:var(--s2);border-radius:10px;padding:14px"><div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:6px">3. Install and test</div><div style="font-size:12px;color:var(--t3)">WordPress Admin → Plugins → Add New → Upload Plugin → Activate → Settings → LevelUpGrowth SEO → paste the key → <strong style="color:var(--t1)">Test connection</strong>. The site appears below as connected the moment the test passes.</div></div>'
    +'</div>'
    +'<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px"><button class="ct-btn" id="wsp-refresh" style="padding:8px 16px">↺ Refresh status</button><button class="ct-btn" style="padding:8px 16px" onclick="document.getElementById(\'ws-plugin-modal\').remove()">Close</button></div></div>';
  ov.addEventListener('click',function(e){if(e.target===ov)ov.remove();});
  document.body.appendChild(ov);
  var H={ 'Authorization':'Bearer '+(localStorage.getItem('lu_token')||''), 'Accept':'application/json', 'Content-Type':'application/json' };
  function loadStatus(){
    var el=document.getElementById('wsp-status'); if(!el) return;
    fetch('/api/settings/connector/connections',{headers:H}).then(function(r){return r.json();}).then(function(d){
      var rows=(d&&d.connections)||[];
      if(!rows.length){ el.innerHTML='<span style="color:var(--am)">No WordPress site is connected to this workspace yet.</span>'; return; }
      el.innerHTML=rows.map(function(c){ var ok=c.status==='active'; return '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><span style="font-weight:700;color:'+(ok?'var(--gn)':'var(--rd)')+'">'+(ok?'● Connected':'● '+bld_escH(c.status))+'</span><span style="color:var(--t1)">'+bld_escH(c.site_url)+'</span><span>plugin '+bld_escH(c.plugin_version||'?')+'</span>'+(c.last_seen_at?'<span>last seen '+new Date(c.last_seen_at).toLocaleString()+'</span>':'')+(c.last_push_at?'<span>last publish '+bld_escH(c.last_push_status||'')+' '+new Date(c.last_push_at).toLocaleString()+'</span>':'<span>never published</span>')+(c.last_error?'<span style="color:var(--rd)">'+bld_escH(String(c.last_error).slice(0,120))+'</span>':'')+'</div>'; }).join('');
    }).catch(function(){ el.textContent='Could not read the connection status.'; });
    fetch('/api/settings/connector-plugin/info',{headers:H}).then(function(r){return r.json();}).then(function(d){
      var m=document.getElementById('wsp-plugin-meta'); if(!m) return;
      m.textContent = d && d.available ? ('level-up-growth-seo-connector v'+(d.version||'?')+' · '+Math.round((d.size||0)/1024)+' KB · API URL '+(d.api_url||'')) : 'The plugin package is not available right now.';
      var b=document.getElementById('wsp-dl'); if(b && !(d&&d.available)) { b.disabled=true; b.style.opacity='.5'; }
    }).catch(function(){});
  }
  document.getElementById('wsp-dl').onclick=async function(){
    var b=this; b.disabled=true; b.textContent='Preparing…';
    try {
      var r=await fetch('/api/settings/connector-plugin/download',{headers:H});
      if(!r.ok){ var j=await r.json().catch(function(){return {};}); throw new Error(j.message||('HTTP '+r.status)); }
      var blob=await r.blob(); var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='level-up-growth-seo-connector.zip'; document.body.appendChild(a); a.click(); a.remove();
      showToast('Plugin downloaded.','success'); b.textContent='⬇ Download again';
    } catch(e){ showToast('Download failed: '+e.message,'error'); b.textContent='⬇ Download plugin (.zip)'; }
    b.disabled=false;
  };
  document.getElementById('wsp-key').onclick=async function(){
    var b=this; b.disabled=true; b.textContent='Creating…';
    try {
      var r=await fetch('/api/settings/api-keys',{method:'POST',headers:H,body:JSON.stringify({name:'WP Connector',type:'connector'})});
      var j=await r.json(); if(!r.ok||!j.key) throw new Error(j.message||j.error||('HTTP '+r.status));
      document.getElementById('wsp-key-out').textContent=j.key; b.textContent='Key created — copy it now';
      try { await navigator.clipboard.writeText(j.key); showToast('Connector key copied to your clipboard.','success'); } catch(e){ showToast('Copy the key now — it will not be shown again.','info'); }
    } catch(e){ showToast('Could not create a key: '+e.message,'error'); b.disabled=false; b.textContent='Create key'; }
  };
  document.getElementById('wsp-refresh').onclick=loadStatus;
  loadStatus();
}


// Template website editor view
function _wsShowTemplateEditor(site) {
  var wsId = site.id;
  var previewUrl = '/api/builder/websites/' + wsId + '/preview';
  var siteName = bld_escH(site.title || site.name || 'Website');

  var html =
    '<div id="template-editor-view" style="position:fixed;inset:0;z-index:9000;background:var(--bg,#0F1117);display:flex;flex-direction:column">' +
    // Toolbar
    '<div class="pe-bar" style="height:52px;background:var(--s1,#161927);border-bottom:1px solid var(--bd);display:flex;align-items:center;padding:0 16px;gap:12px;flex-shrink:0">' +
      '<button onclick="wsCloseTemplateEditor()" style="background:none;border:1px solid var(--bd);color:var(--t1);padding:5px 12px;border-radius:6px;cursor:pointer;font-size:13px">\u2190 Back</button>' +
      '<span class="pe-bar-title" style="color:var(--t1);font-weight:600;font-size:14px">' + siteName + '</span>' +
      '<span class="pe-bar-spacer" style="flex:1"></span>' +
      '<div role="group" aria-label="Preview device" style="display:flex;border:1px solid var(--bd);border-radius:6px;overflow:hidden">' +
        '<button type="button" id="t3-dev-desktop" onclick="_wsTplSetDevice(\'desktop\')" aria-label="Desktop preview" aria-pressed="true" title="Desktop" style="padding:5px 10px;border:none;background:var(--pu);color:#fff;cursor:pointer;font-size:13px">\uD83D\uDDA5</button>' +
        '<button type="button" id="t3-dev-mobile" onclick="_wsTplSetDevice(\'mobile\')" aria-label="Mobile preview" aria-pressed="false" title="Mobile" style="padding:5px 10px;border:none;background:transparent;color:var(--t2);cursor:pointer;font-size:13px">\uD83D\uDCF1</button>' +
      '</div>' +
      '<span class="pe-bar-hint" style="color:var(--t3);font-size:11px">Double-click text to edit \u00B7 click an image to replace it \u00B7 your own edits are free \u00B7 changes by Arthur cost 1 credit</span>' +
      '<button type="button" id="t3-undo" onclick="wsUndoLast(' + wsId + ')" title="Undo the last change — Arthur, palette or inline edit" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">↶ Undo</button>' +
      '<button type="button" onclick="wsShowVersions(' + wsId + ')" title="Earlier versions of this website" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">Versions</button>' +
      '<button onclick="wsSaveAllEdits(' + wsId + ')" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:13px">Save</button>' +
      '<button type="button" id="t3-site-btn" onclick="wsOpenSitePanel(' + wsId + ')" title="Tracking ids, download the site, domain" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">Site</button>' +
      '<button type="button" id="t3-catalogue-btn" hidden onclick="wsOpenCatalogue(' + wsId + ')" title="What you sell — listings, services and prices, menu; each kind gets its own page" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">Listings</button>' +
      '<button type="button" id="t3-layout-btn" onclick="wsOpenLayouts(' + wsId + ')" title="Switch to another layout of this design family — preview is free" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">Layout</button>' +
      '<button type="button" onclick="wsOpenPalettes(' + wsId + ')" title="Colour palettes — hover to preview, click to apply" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">Colours</button>' +
      '<button onclick="wsPublishFromEditor(' + wsId + ', ' + JSON.stringify(site.title || site.name || 'Website').replace(/"/g,'&quot;') + ')" style="background:var(--p,#6C5CE7);border:none;color:#fff;padding:5px 16px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">'+window.icon('rocket',18)+' Publish</button>' +
    '</div>' +
    // Main
    '<div class="pe-main" style="flex:1;display:flex;overflow:hidden">' +
      // Arthur sidebar
      '<div class="pe-side" style="width:300px;background:var(--s1,#161927);border-right:1px solid var(--bd);display:flex;flex-direction:column;flex-shrink:0">' +
        '<div style="padding:14px;border-bottom:1px solid var(--bd)">' +
          '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><div style="width:28px;height:28px;background:var(--p);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px">'+window.icon('ai',18)+'</div><div style="color:var(--t1);font-weight:600;font-size:13px">Arthur</div></div>' +
          _bldHint('ax-editor-intro', 'Ask Arthur to rewrite any text, or edit straight in the preview \u2014 double-click text, click an image to swap it. Colours switches the whole palette instantly; Undo puts anything back.', 'margin-top:2px') +
        '</div>' +
        '<div id="t3-arthur-feed" style="flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px">' +
          _bldHint('ax-editor-try', 'Try: \u201cChange the hero heading to \u2026\u201d, \u201cMake the buttons a gradient from navy to teal\u201d, \u201cMake it more luxurious\u201d or \u201cAdd a testimonials section\u201d.<br>You can also double-click text in the preview, or click an image to swap it.') +
        '</div>' +
        // BUILDER888 (2026-09-01): Arthur edits template sites correctly. ArthurEditService writes each
        // change through to storage/app/public/sites/{id}/index.html - the very export this editor
        // previews and the published site serves - and reports whether it landed via visible_on_site.
        // The only thing missing here was the page context _t3ArthurSend keys on, which the page editor
        // sets and this one never did; _wsTplBindPage() resolves it once the view is up.
        '<div class="pe-composer" style="padding:10px;border-top:1px solid var(--bd);display:flex;gap:6px">' +
          '<input id="t3-arthur-input" type="text" aria-label="Message Arthur" placeholder="Ask Arthur..." style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:6px;color:var(--t1);padding:9px 10px;font-size:13px;outline:none;font-family:inherit" onkeydown="if(event.key===\'Enter\')_t3ArthurSend(' + wsId + ')">' +
          '<button type="button" id="pe-send" aria-label="Send to Arthur" onclick="_t3ArthurSend(' + wsId + ')" style="background:var(--p);border:none;color:#fff;padding:9px 12px;border-radius:6px;cursor:pointer;font-size:13px">\u2192</button>' +
        '</div>' +
      '</div>' +
      // Preview iframe
      '<div class="pe-stage" style="flex:1;position:relative">' +
        '<iframe id="t3-preview" data-site="' + wsId + '" title="Site preview" style="width:100%;height:100%;border:none;background:#fff" onload="_t3InitEditing(this)"></iframe>' +
        '<div id="t3-saved" style="display:none;position:absolute;top:10px;right:10px;background:var(--ac,#00E5A8);color:#000;padding:5px 12px;border-radius:16px;font-size:11px;font-weight:600">\u2713 Saved</div>' +
      '</div>' +
    '</div>' +
    '</div>';

  document.body.insertAdjacentHTML('beforeend', html);
  _wsTplBindPage(wsId);
  _t3LoadPreview(wsId, document.getElementById('t3-preview'));   // PREVIEW GATE: fetched with the bearer token, never a public URL
  try { _t3CatalogueGate(wsId); } catch (_e) {}   // Catalogue button only on designs that carry one
}

/**
 * Give the template editor the page context Arthur needs.
 *
 * _t3ArthurSend keys entirely off bldCurrentPageId. The page editor sets it; this one never did, so every
 * message here came back refused. The preview shows the home page, so that is the page Arthur edits, and
 * because the edit is written through to the static export the change appears in this very preview.
 */
async function _wsTplBindPage(websiteId) {
  try {
    var tok = localStorage.getItem('lu_token') || '';
    var res = await fetch('/api/builder/websites/' + websiteId + '/pages', {
      headers: { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json' }
    });
    if (!res.ok) { window._t3BindError = 'HTTP ' + res.status; return false; }
    var body = await res.json();
    var pages = Array.isArray(body) ? body : (body.pages || body.data || []);
    if (!pages.length) { window._t3BindError = 'no pages in this workspace'; return false; }
    var home = pages.filter(function (pg) { return /^(home|index)$/i.test(String(pg.slug || '')); })[0];
    bldCurrentPageId = (home || pages[0]).id;
    window._t3BindError = null;
    return true;
  } catch (e) {
    // Unbound is the safe state: Arthur declines rather than editing the wrong page.
    window._t3BindError = (e && e.message) || 'bind failed';
    return false;
  }
}

async function wsCloseTemplateEditor() {
  // DEC-0046: the ONE confirmation in this editor. Arthur, palette and undo changes are already on the site
  // (Undo and Versions put them back); only inline text edits can still be pending, and those are what the
  // customer is asked about. Nothing pending: leave silently.
  var pending = 0;
  try { pending = Object.keys(_t3PendingFields).length; } catch (_e) {}
  if (pending > 0) {
    var choice = await _t3ExitChoice(pending);
    if (choice === 'stay') return;
    if (choice === 'save') {
      try {
        clearTimeout(_t3SaveTimer);
        var r = await _t3FlushSaves();
        if (r && r.conflicts) return;
        if (r && !r.ok) { if (typeof showToast === 'function') showToast("Some changes couldn\u2019t be saved \u2014 they\u2019re still here.", 'error'); return; }
      } catch (_e) {}
    } else {
      try { _t3PendingFields = {}; clearTimeout(_t3SaveTimer); } catch (_e) {}
    }
  }
  var pal = document.getElementById('t3-pal'); if (pal) pal.remove();
  var lay = document.getElementById('t3-lay'); if (lay) lay.remove(); window._t3LayoutPreviewing = false;
  var lst = document.getElementById('t3-cat'); if (lst) lst.remove();
  var stp = document.getElementById('t3-site'); if (stp) stp.remove();
  bldCurrentPageId = null;
  var v = document.getElementById('template-editor-view');
  if (v) v.remove();
}

// BUILDER888 D6 (2026-08-28) - desktop / tablet / mobile preview for the template editor
// (the editor had no responsive view at all; the image panel keeps anchoring to the
// iframe rect so click-to-replace still lines up).
// DEVICE FIT (2026-09-15): the preview lays out at the device's real width and is scaled to fit the stage, so a phone
// shows the true desktop layout zoomed out instead of the responsive mobile layout.
function _t3FitPreview(key) {
  var widths = { desktop: 1280, tablet: 820, mobile: 390 };
  var iframe = document.getElementById('t3-preview'); if (!iframe) return;
  var W = widths[key] || 1280, stage = iframe.parentElement, sw = stage ? stage.clientWidth : window.innerWidth, sh = stage ? stage.clientHeight : window.innerHeight;
  window._t3DeviceKey = key;
  if (key === 'desktop' && sw >= 900) { iframe.style.width = '100%'; /* a stage this wide already lays out as desktop; keep it readable */ iframe.style.height = '100%'; iframe.style.transform = ''; iframe.style.transformOrigin = ''; window._t3PreviewScale = 1; return; }
  var scale = sw < W ? Math.max(0.2, (sw / W)) : 1;
  iframe.style.width = W + 'px';
  iframe.style.transformOrigin = 'top left';
  iframe.style.transform = scale < 1 ? 'scale(' + scale.toFixed(4) + ')' : '';
  iframe.style.height = scale < 1 ? Math.round(sh / scale) + 'px' : '100%';
  iframe.style.margin = scale < 1 ? '0' : '0 auto';
  if (stage) stage.style.overflow = 'hidden';
  window._t3PreviewScale = scale;
}
window.addEventListener('resize', function () { clearTimeout(window._t3FitTimer); window._t3FitTimer = setTimeout(function () { if (window._t3DeviceKey && document.getElementById('t3-preview')) _t3FitPreview(window._t3DeviceKey); }, 150); });

function _wsTplSetDevice(key) {
  var iframe = document.getElementById('t3-preview');
  if (iframe) {
    iframe.style.display = 'block';
    iframe.style.margin = '0 auto';
    iframe.style.background = '#fff';
    _t3FitPreview(key);
    if (iframe.parentElement) iframe.parentElement.style.background = key === 'desktop' ? '' : '#0B0D13';
  }
  ['desktop', 'tablet', 'mobile'].forEach(function (k) {
    var b = document.getElementById('t3-dev-' + k);
    if (!b) return;
    var on = k === key;
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    b.style.background = on ? 'var(--pu)' : 'transparent';
    b.style.color = on ? '#fff' : 'var(--t2)';
  });
}

function _t3InitEditing(iframe) {
  try { _t3FitPreview(window._t3DeviceKey || 'desktop'); } catch (_f) {}   // DEVICE FIT: a phone opens on the true desktop view
  if (window._t3Reselect) { var _rf = window._t3Reselect; window._t3Reselect = null; setTimeout(function () { try { iframe.contentWindow.postMessage({ type: 'select-field', field: _rf }, '*'); } catch (_e) {} }, 700); }   // ELEMENT888: keep the element selected after a reload
  if (window._t3LayoutPreviewing) return; // a previewed layout is not the live site: nothing to edit yet
  // Editing is already injected server-side in the preview route
  // Listen for field changes from iframe
  window.addEventListener('message', _t3HandleMessage);
}

var _t3SaveTimer = null;
var _t3PendingFields = {};

// ELEMENT888 (DEC-0052): the preview toolbox / drag handle asks for a move, alignment or size change
async function _t3ElementOp(d) {
  var f = document.getElementById('t3-preview'); var m = /websites\/(\d+)\/preview/.exec((f && f.src) || '');
  var siteId = window._t3PreviewSiteId || (f && f.getAttribute('data-site')) || (m ? m[1] : null);
  if (!siteId || !d || !d.field || !d.op) return;
  var body = { field: d.field };
  if (d.op === 'move') { body.dir = d.dir; if (d.ref) body.ref = d.ref; } else if (d.op === 'align') { body.align = d.align; } else { body.dir = d.dir; }
  var feed = document.getElementById('t3-arthur-feed');
  var note = function (text, colour) { if (!feed) return; feed.innerHTML += '<div style="background:var(--s2);border-left:3px solid ' + colour + ';border-radius:8px;padding:7px 10px;font-size:12px;margin:4px 0">' + bld_escH(text) + '</div>'; feed.scrollTop = feed.scrollHeight; };
  try {
    var r = await fetch('/api/builder/websites/' + siteId + '/elements/' + d.op, { method: 'POST', headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) });
    var j = null; try { j = await r.json(); } catch (_j) { j = null; }
    if (r.ok && j && j.success) {
      note(j.message + (j.credits ? ' \u00B7 ' + j.credits + ' credit' + (j.credits === 1 ? '' : 's') : ''), '#00E5A8');
      // LIVE PREVIEW: the preview already shows the change; reload only when it could not apply it itself
      if (!d.applied) { window._t3Reselect = d.field; _t3ReloadPreview(); }
      if (typeof _luRefreshCredits === 'function') { try { _luRefreshCredits(); } catch (_c) {} }
    } else {
      note((j && j.message) || 'That did not work.', r.status === 402 ? '#F87171' : '#F59E0B');
      if (d.applied) { window._t3Reselect = d.field; _t3ReloadPreview(); }   // put the preview back in step with the saved page
    }
  } catch (e2) { note('The change could not be sent. Please try again.', '#F87171'); if (d.applied) { window._t3Reselect = d.field; _t3ReloadPreview(); } }
}

function _t3HandleMessage(e) {  if (!e.data || !e.data.type) return;  if (e.data.type === "element-op") { _t3ElementOp(e.data); return; }  if (e.data.type === "block-selected") {    window._t3SelectedBlock = e.data.block_id;    var lbl = document.getElementById("t3-context-label");    if (lbl) { lbl.textContent = "Editing: " + e.data.block_label; lbl.style.color = "#6C5CE7"; }    var inp = document.getElementById("t3-arthur-input");    if (inp) { inp.placeholder = "Change " + e.data.block_label + "..."; inp.focus(); }    if (typeof _t3ShowSuggestions === "function") _t3ShowSuggestions(e.data.block_id);    return;  }  if (e.data.type === "block-deselected") {    window._t3SelectedBlock = null;    window._t3SelectedElement = null;    var lbl = document.getElementById("t3-context-label");    if (lbl) { lbl.textContent = "Click a section"; lbl.style.color = ""; }    var inp = document.getElementById("t3-arthur-input");    if (inp) inp.placeholder = "Ask Arthur...";    if (typeof _t3ShowSuggestions === "function") _t3ShowSuggestions(null);    return;  }  if (e.data.type === "element-selected") {    if (e.data.block_id) window._t3SelectedBlock = e.data.block_id;    window._t3SelectedElement = e.data.element_key;    var lbl = document.getElementById("t3-context-label");    if (lbl) { lbl.textContent = "Editing: " + e.data.element_label; lbl.style.color = "#F97316"; }    var inp = document.getElementById("t3-arthur-input");    if (inp) { var tail = (e.data.element_label || "").split(" \u203A ").pop(); inp.placeholder = "Change " + tail + "..."; inp.focus(); }    return;  }  if (e.data.type === "element-deselected") {    window._t3SelectedElement = null;    var lbl2 = document.getElementById("t3-context-label");    if (lbl2 && window._t3SelectedBlock) { var bn = window._t3SelectedBlock; lbl2.textContent = "Editing: " + bn.charAt(0).toUpperCase() + bn.slice(1) + " Section"; lbl2.style.color = "#6C5CE7"; }    var inp2 = document.getElementById("t3-arthur-input");    if (inp2 && window._t3SelectedBlock) inp2.placeholder = "Change " + window._t3SelectedBlock + "...";    return;  }  if (e.data.type === "image-clicked") {
    _t3ShowImagePanel(e.data);
    return;
  }
  if (e.data.type === "field-changed") {    var _prev = _t3PendingFields[e.data.field];
    _t3PendingFields[e.data.field] = { value: e.data.value, websiteId: e.data.websiteId, base: (_prev && _prev.base !== undefined) ? _prev.base : e.data.base, force: !!(_prev && _prev.force) };
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
  var _sc = window._t3PreviewScale || 1;   // DEVICE FIT: rects inside a scaled preview are in its own pixels
  var panelLeft = Math.max(8, Math.min(window.innerWidth - panelW - 8, ir.left + r.left * _sc));
  var panelTop = Math.max(8, Math.min(window.innerHeight - 80, ir.top + r.bottom * _sc + 8));
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
    // CROP TOOL 2026-09-06: every placement has a fixed size — the crop frame is locked to it
    _t3CropForField(info, { url: url, media_id: file.id || null }, function (finalUrl) {
      _t3ReplaceImage(info.websiteId, info.field, finalUrl);
    });
  });
}

async function _t3ImgPasteUrl() {
  var info = _t3ImgPanelInfo;
  if (!info) return;
  // BUILDER888 D5 (2026-08-28) - native browser prompt/confirm dialogs block the page; use the app dialogs.
  var url = await luPrompt('Use image from URL', info.currentSrc || '', 'https://example.com/photo.jpg');
  if (!url) return;
  url = url.trim();
  if (!/^https?:\/\//.test(url) && !/^\//.test(url)) {
    showToast('URL must start with http(s):// or /', 'error');
    return;
  }
  _t3HideImagePanel();
  _t3CropForField(info, { url: url, media_id: null }, function (finalUrl) {
    _t3ReplaceImage(info.websiteId, info.field, finalUrl);
  });
}

// CROP TOOL 2026-09-06: open the fixed-frame crop for the field's placement; falls back to the plain URL when the
// placement has no fixed size (or the tool is unavailable) so a replace never silently stops.
function _t3CropForField(info, pick, proceed) {
  if (!window.luCrop || typeof window.luCrop.open !== 'function') { proceed(pick.url); return; }
  window.luCrop.open({ url: pick.url, media_id: pick.media_id, field: info.field }, function (res) {
    if (res === null) return; // cancelled
    proceed(res && res.url ? res.url : pick.url);
  });
}

async function _t3ImgRemove() {
  var info = _t3ImgPanelInfo;
  if (!info) return;
  if (!(await luConfirm('Remove image', 'Remove this image from the page?', { okLabel: 'Remove', cancelLabel: 'Keep', danger: true }))) return;
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
    luConfirm('Image size', msg.replace(/\n\nUse it anyway\?$/, ''), { okLabel: 'Use it anyway', cancelLabel: 'Choose another' }).then(function (ok) { if (ok) proceed(); });
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
    if (d && d.saved && d.export_patched === false) {
      // BUILDER888 D3/D4 — the value is stored but the served page could not be patched.
      if (typeof showToast === 'function') showToast('Saved, but the live page could not be updated for this element. Please refresh the preview and try again.', 'error');
      return;
    }
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
    showToast('Logo must be under 2MB.', 'error');
    return;
  }
  var allowed = ['image/png','image/jpeg','image/svg+xml','image/webp'];
  if (allowed.indexOf(file.type) === -1) {
    showToast('Logo must be PNG, JPG, SVG, or WEBP.', 'error');
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

// BUILDER888 P1-6-c — truthful save contract.
//
// The previous implementation fired requests without awaiting them, cleared
// the dirty map BEFORE any response arrived, and showed "saved" regardless of
// outcome. A failed save silently discarded the customer's edit while the UI
// claimed success. Now: await every request, keep failed fields dirty so they
// can be retried, and report only what actually happened.
//
// Returns { ok, saved, failed, attempted } — never throws.
var _t3SaveInFlight = null;

// BUILDER888 D9 (2026-08-29) — the editor autosaves 2 s after the last keystroke. A reload,
// tab close or "Back" inside that window silently discarded the edit (no guard, no flush):
// the customer saw "✓ Saved" for earlier edits and assumed the last one landed too.
// Flush immediately on pagehide (keepalive fetch) and warn on beforeunload while dirty.
window.addEventListener('pagehide', function () {
  try { if (Object.keys(_t3PendingFields).length) { clearTimeout(_t3SaveTimer); _t3FlushSaves({ unload: true }); } } catch (_e) {}
});
window.addEventListener('beforeunload', function (e) {
  var dirty = false;
  try { dirty = Object.keys(_t3PendingFields).length > 0 || !!_t3SaveInFlight; } catch (_e) {}
  if (!dirty) return;
  try { clearTimeout(_t3SaveTimer); _t3FlushSaves({ unload: true }); } catch (_e) {}
  e.preventDefault();
  e.returnValue = '';
});

async function _t3FlushSaves(opts) {
  // Coalesce concurrent presses/autosaves onto one in-flight run.
  if (_t3SaveInFlight) { return _t3SaveInFlight; }
  // fetch keepalive caps the body at 64 KiB, so use it ONLY for the unload flush (D9).
  var _keepalive = !!(opts && opts.unload);

  var fields = Object.keys(_t3PendingFields);
  if (!fields.length) {
    return { ok: true, saved: 0, failed: 0, attempted: 0, nothingToSave: true };
  }

  _t3SaveInFlight = (async function () {
    var token = localStorage.getItem('lu_token') || '';
    var saved = 0, failed = 0;
    var stillDirty = {};
    var firstError = null;

    var results = await Promise.all(fields.map(async function (field) {
      var p = _t3PendingFields[field];
      try {
        var res = await fetch('/api/builder/websites/' + p.websiteId + '/fields/' + field, {
          method: 'PUT',
          headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
          body: JSON.stringify(Object.assign({ value: p.value }, (p.base !== undefined ? { base_value: p.base } : {}), (p.force ? { force: true } : {}))),
          keepalive: _keepalive   // BUILDER888 D9 — let a flush started on unload complete
        });
        if (!res.ok) {
          var _j = null; try { _j = await res.json(); } catch (_je) {}
          if (res.status === 409 && _j && _j.conflict) { return { field: field, ok: false, status: 409, conflict: true, current: _j.current, p: p }; }
          return { field: field, ok: false, status: res.status, error: _j && (_j.error || _j.message) || null };
        }
        return { field: field, ok: true, status: res.status };
      } catch (e) {
        return { field: field, ok: false, status: 'network' };
      }
    }));

    var conflicts = results.filter(function (r) { return r && r.conflict; });
    results.forEach(function (r) {
      if (r.ok) { saved++; }
      else if (r.conflict) { /* resolved with the customer below */ }
      else {
        failed++;
        // RISK-0116 — a 4xx is a permanent client error (invalid field / value too large);
        // dropping it avoids an infinite silent retry loop. 5xx/network stays dirty for retry.
        var permanent = (typeof r.status === 'number' && r.status >= 400 && r.status < 500);
        if (!permanent) { stillDirty[r.field] = _t3PendingFields[r.field]; }
        if (!firstError && r.error) firstError = r.error;
        try { console.warn('[Builder888] field save failed', r.field, r.status); } catch (e) {}
      }
    });

    _t3PendingFields = stillDirty;

    // BUILDER888 D10 — a field changed elsewhere: never silently overwrite, never silently drop.
    var reflush = false, needReload = false;
    for (var ci = 0; ci < conflicts.length; ci++) {
      var c = conflicts[ci];
      var cur = String(c.current || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim().slice(0, 140);
      var overwrite = false;
      try {
        overwrite = await luConfirm('Changed elsewhere', 'This text was changed in another tab or by a teammate. It now reads: \u201C' + cur + '\u201D. Overwrite it with your version?', { okLabel: 'Overwrite', cancelLabel: 'Keep current', danger: true });
      } catch (_ce) {}
      if (overwrite) { _t3PendingFields[c.field] = Object.assign({}, c.p, { force: true }); reflush = true; }
      else { needReload = true; }
    }
    if (reflush) { setTimeout(function () { _t3FlushSaves(); }, 0); }
    if (needReload) {
      if (typeof window._luPageEditorReloadHook === 'function') { window._luPageEditorReloadHook(); }
      else { _t3ReloadPreview(); }
    }

    var ind = document.getElementById('t3-saved');
    if (ind && failed === 0) {
      ind.style.display = 'block';
      setTimeout(function () { ind.style.display = 'none'; }, 2000);
    } else if (failed > 0 && typeof showToast === 'function') {
      // RISK-0116 — surface the failure instead of only console.warn.
      showToast(failed + " change" + (failed === 1 ? "" : "s") + " couldn't be saved" + (firstError ? ": " + firstError : ". Please try again."), "error");
    }

    return { ok: failed === 0 && conflicts.length === 0, saved: saved, failed: failed, attempted: results.length, conflicts: conflicts.length };
  })();

  try { return await _t3SaveInFlight; }
  finally { _t3SaveInFlight = null; }
}

async function wsSaveAllEdits(websiteId) {
  var btn = (typeof event !== 'undefined' && event && event.target) ? event.target : null;
  var label = btn ? btn.textContent : null;
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

  var r = { ok: false, saved: 0, failed: 0, attempted: 0 };
  try {
    r = await _t3FlushSaves();
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = label || 'Save'; }
  }

  if (typeof showToast !== 'function') { return r; }

  if (r.conflicts)          { return r; } // the conflict dialog already spoke
  if (r.nothingToSave)      { showToast('No changes to save', 'info'); }
  else if (r.ok)            { showToast(r.saved === 1 ? '1 change saved' : r.saved + ' changes saved', 'success'); }
  else if (r.saved > 0)     { showToast(r.saved + ' saved, ' + r.failed + " couldn't be saved — still unsaved, please try again", 'error'); }
  else                      { showToast("We couldn't save your changes. They're still here — please try again.", 'error'); }

  return r;
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
    if (!pid && typeof _wsTplBindPage === 'function') {
      // DEC-0046: a bind that failed at open (slow network, grid not loaded) gets one more chance before Arthur declines.
      try { await _wsTplBindPage(websiteId); } catch (_e) {}
      pid = (typeof bldCurrentPageId !== 'undefined' && bldCurrentPageId) ? bldCurrentPageId : null;
    }
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
        // SELECTION888 (2026-09-15): the clicked element / section travels with the message so Arthur knows what 'this' is
        body: JSON.stringify({message: msg, section_index: null, selected: (window._t3SelectedBlock || window._t3SelectedElement) ? {block: window._t3SelectedBlock || null, field: window._t3SelectedElement || null} : null})
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
            credits_used: (typeof d.credits_used === 'number') ? d.credits_used : (typeof d.credits === 'number' ? d.credits : 0),
            _canonical: true
          };
        } else if (d && d.error) {
          // STRESS 2026-09-06: show the human message, never the error code ("insufficient_credits")
          var _human = d.message || (d.error === 'insufficient_credits' ? 'Not enough credits for this change (1 credit per edit).' : d.error);
          if (d.error === 'insufficient_credits' || d.error === 'INSUFFICIENT_CREDITS') _human += ' Add credits under Billing to continue.';
          d = { error: _human, conflict: !!d.conflict, legacy: !!d.legacy };
        } else if (d && (d.kind === 'clarify' || d.method === 'clarify')) {
          // ARTHUR LLM-FIRST (DEC-0050): a question with tappable options, never a dead end
          d = { method: 'clarify', message: d.message || d.question || '', options: d.options || [] };
        } else if (d && (d.kind === 'answer' || d.kind === 'unsupported')) {
          d = { method: 'chat', message: d.message || d.reply || '' };
        } else if (d && d.success === false) {
          // ARTHUR EDITOR FIX (2026-09-10) — the endpoint answers HTTP 200 with
          // {success:false, reply:"…"} when nothing on the site actually changed:
          // ArthurEditService returns success = $__changed, and the static-site
          // delegation returns the same shape. There was no branch for it, so the
          // render chain below fell through every method test to the literal
          // "Done." — the editor claimed an edit it had not made, which is the
          // one thing this surface must never do. The service already writes a
          // truthful customer-facing sentence; show that.
          d = { method: 'noop', message: d.reply || d.message || 'Nothing on your site was changed.' };
        }
      }
    }
    if (!pid) {
      // BUILDER888 D8 (2026-08-28) — the template editor has no page context, so no request
      // is made at all; the old branch then told every new customer their brand-new site was
      // a "legacy static layout". There is no template-scoped Arthur edit endpoint
      // (POST /builder/websites/{id}/arthur-edit was removed 2026-07-02); the structured
      // page path would edit the 2-section skeleton behind the polished export (RISK-0097).
      // Say what works instead of pretending.
      d = { error: "I couldn\u2019t load this site\u2019s page list" + (window._t3BindError ? " (" + window._t3BindError + ")" : "") + ", so I can\u2019t edit it from here. Go back to Websites and open it again \u2014 if it keeps happening, the site belongs to another workspace." };
    } else if (!triedCanonical || (r && r.status === 422 && d && d.legacy === true)) {
      // Legacy static-HTML edit path REMOVED 2026-07-02 — manual/legacy editing is
      // dead; only structured Arthur vibe editing is supported. A static-HTML page
      // (e.g. the old Chef Red layout) must be rebuilt as structured sections to edit.
      d = { error: 'This page is a legacy static layout — rebuild it as a structured page to edit it with Arthur.' };
    }

    var typing = document.getElementById('t3-typing');
    if (typing) typing.remove();

    if (d.error) {
      if (feed) feed.innerHTML += '<div style="background:rgba(248,113,113,0.08);padding:10px 12px;border-radius:8px;margin:4px 0"><div style="color:#F87171;font-size:13px">' + bld_escH(d.error) + '</div><div style="color:rgba(248,113,113,0.35);font-size:10px;margin-top:4px">block: ' + (window._t3SelectedBlock||"none selected") + '</div></div>';
    } else if (d.method === 'confirm') {
      if (feed) {
        var _cid = 'conf_' + Date.now();
        feed.innerHTML += '<div id="' + _cid + '" style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0;border-left:3px solid #F97316"><div style="color:var(--t1);font-size:13px;line-height:1.5">' + bld_escH(d.message) + '</div><div style="margin-top:10px;display:flex;gap:8px"><button onclick="_t3ConfirmTier4(' + websiteId + ', this, ' + JSON.stringify(d.confirm_action).replace(/"/g, "&quot;") + ', ' + JSON.stringify(d.confirm_data || {}).replace(/"/g, "&quot;") + ')" style="background:var(--p);border:none;color:#fff;padding:6px 12px;border-radius:5px;cursor:pointer;font-size:12px;font-weight:600">Confirm</button><button onclick="document.getElementById(\'' + _cid + '\').remove()" style="background:var(--s3);border:none;color:var(--t2);padding:6px 12px;border-radius:5px;cursor:pointer;font-size:12px">Cancel</button></div></div>';
        var _confEl = document.getElementById(_cid);
        if (_confEl) _confEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    } else if (d.method === 'action') {
      if (feed) feed.innerHTML += '<div style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0;border-left:3px solid #00E5A8"><div style="color:var(--t1);font-size:13px">' + bld_escH(d.message) + '</div><div style="color:rgba(255,255,255,0.3);font-size:10px;margin-top:4px">arthur \u00b7 tier 4</div></div>';
      if (d.reload_preview) {
        var iframe = document.getElementById('t3-preview');
        if (typeof window._luPageEditorReloadHook === 'function') { window._luPageEditorReloadHook(); }
        else if (iframe) _t3ReloadPreview();
      }
      if (typeof wsLoadSites === 'function' && (d.action === 'page_added' || d.action === 'page_deleted' || d.action === 'page_duplicated')) {
        wsLoadSites();
      }
    } else if (d.method === 'clarify') {
      if (feed) {
        var _qid = 'q_' + Date.now();
        feed.innerHTML += '<div id="' + _qid + '" style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0;border-left:3px solid var(--p)"><div style="color:var(--t1);font-size:13px;line-height:1.5">' + bld_escH(d.message) + '</div>'
          + ((d.options && d.options.length) ? '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">' + d.options.map(function (o) { return '<button type="button" class="lu-btn lu-btn--sm" data-arthur-opt="' + bld_escH(o) + '">' + bld_escH(o) + '</button>'; }).join('') + '</div>' : '') + '</div>';
        var _q = document.getElementById(_qid);
        if (_q) { _q.querySelectorAll('[data-arthur-opt]').forEach(function (b) { b.addEventListener('click', function () { var i = document.getElementById('t3-arthur-input'); if (i) { i.value = b.getAttribute('data-arthur-opt'); _t3ArthurSend(websiteId); } }); }); _q.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      }
    } else if (d.method === 'noop') {
      // Not an error — Arthur understood, but nothing on the site changed and nothing was charged.
      if (feed) feed.innerHTML += '<div style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0;border-left:3px solid #F59E0B"><div style="color:var(--t1);font-size:13px;line-height:1.5">' + bld_escH(d.message) + '</div><div style="color:var(--t3);font-size:10px;margin-top:4px">no change applied \u00b7 0 credits</div></div>';
    } else if (d.method === 'chat') {
      if (feed) feed.innerHTML += '<div style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0"><div style="color:var(--t1);font-size:13px;line-height:1.5">' + bld_escH(d.message) + '</div><div style="color:rgba(255,255,255,0.3);font-size:10px;margin-top:4px">arthur \u00b7 ' + (d.credits_used||0) + ' credit</div></div>';
    } else {
      if (feed) feed.innerHTML += '<div style="background:var(--s2);padding:10px 12px;border-radius:8px;margin:4px 0"><div style="color:var(--t1);font-size:13px">' + bld_escH(d.reply || d.message || 'Done.') + '</div><div style="margin-top:4px"><span onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'" style="color:rgba(255,255,255,0.3);font-size:10px;cursor:pointer;user-select:none">details</span><div style="display:none;margin-top:4px;color:rgba(255,255,255,0.3);font-size:10px;line-height:1.6">' + (d.method==="instant"?"'+window.icon('ai',18)+' instant":"\ud83e\udd16 deepseek") + " \u00b7 " + (d.credits_used||0) + " credit" + ((d.credits_used||0)>1?"s":"") + " \u00b7 block: " + (window._t3SelectedBlock||"page") + '</div></div></div>';
      // Reload iframe
      if (d.reload_preview) {
        var iframe = document.getElementById('t3-preview');
        if (typeof window._luPageEditorReloadHook === 'function') { window._luPageEditorReloadHook(); }
        else if (iframe) iframe.src = iframe.src.split('?')[0] + '?v=' + Date.now();
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
    + '<button onclick="document.getElementById(\'ws-connect-modal\').remove()" style="background:none;border:none;color:var(--t3);font-size:20px;cursor:pointer" aria-label="Close" title="Close">✕</button></div>'
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
  var site = (Array.isArray(wsSites) ? wsSites : []).find(function(s){ return s.id === siteId; });
  // BUILDER888 D1b (2026-08-28) — if the site is not in the loaded grid (deep link before
  // the grid loaded, or a site in another workspace) fetch its record instead of guessing
  // {title:'Website'} with no type, which routed template sites into the pages list and
  // showed a false "No pages yet".
  if (!site) {
    try {
      var _r = await fetch(API + 'builder/websites/' + siteId, {headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'}});
      var _j = _r.ok ? await _r.json() : null;
      var _w = _j && (_j.website || _j.data || _j);
      if (_w && _w.id) { site = _w; if (!site.title) site.title = site.name || 'Website'; }
    } catch (_e) {}
  }
  if (!site) {
    if (typeof showToast === 'function') showToast("This website isn’t in your current workspace.", 'error');
    return;
  }
  wsCurrentSite = site;

  // Template websites — show template view
  if (site.type === 'template') { _wsShowTemplateEditor(site); return; }
  // External websites — no editor
  if (site.type === 'external' || site.external_url) { return; }

  // v5.7.21 (2026-05-31) — Phase 2 URL: push /app/builder/{siteId} so
  // refresh / bookmark / share all work.
  try {
    if (window._luRouter && window._luRouter.enabled()) {
      window._luRouter.pushView('websites', String(siteId));
    }
  } catch (_e) {}

  document.getElementById('ws-site-list').style.display = 'none';
  document.getElementById('ws-site-pages').style.display = 'block';
  document.getElementById('ws-site-title').textContent = site.title + ' — Pages';
  wsRenderVersions(siteId); // P1R-2: version history + restore, the capability Advanced was missing

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

/* P1R-2b — versions dialog for the full-screen template editor (template sites are exactly the ones with history). */
async function wsShowVersions(siteId) {
  var auth = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' };
  var ov = document.createElement('div');
  ov.id = 'ws-ver-ov'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'Earlier versions');
  ov.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.62);display:flex;align-items:center;justify-content:center;padding:20px';
  var box = document.createElement('div');
  box.style.cssText = 'background:var(--s1);border:1px solid var(--bd2);border-radius:var(--rg);padding:18px;width:min(520px,100%);max-height:76vh;overflow:auto;font-family:var(--fb)';
  box.innerHTML = '<div style="font:700 15px var(--fh);color:var(--t1);margin-bottom:4px">Earlier versions</div>'
                + '<div style="font-size:12.5px;color:var(--t2);margin-bottom:12px">Every change keeps the version it replaced. Restoring puts that version live; the current one stays in this list.</div>'
                + '<div id="ws-ver-list"><div class="lu-skel" style="width:70%"></div></div>';
  ov.appendChild(box); document.body.appendChild(ov);
  var close = function () { ov.remove(); document.removeEventListener('keydown', esc); };
  var esc = function (e) { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', esc);
  ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
  var closeBtn = document.createElement('button'); closeBtn.type = 'button'; closeBtn.className = 'lu-btn lu-btn--sm'; closeBtn.textContent = 'Close';
  closeBtn.style.marginTop = '14px'; closeBtn.addEventListener('click', close); box.appendChild(closeBtn);
  var list = box.querySelector('#ws-ver-list');
  var items = [];
  try {
    var r = await fetch(API + 'builder/websites/' + siteId + '/history', { headers: auth, cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var j = await r.json(); items = (j && (j.history || (j.data && j.data.history))) || [];
  } catch (e) { list.innerHTML = '<div class="lu-empty"><b>Couldn\'t load versions</b>Try again in a moment.</div>'; return; }
  if (!items.length) { list.innerHTML = '<div class="lu-empty"><b>No earlier versions yet</b>Every change keeps the version it replaced.</div>'; return; }
  list.innerHTML = ''; list.style.cssText = 'display:flex;flex-direction:column;gap:6px';
  items.slice(0, 12).forEach(function (v, i) {
    var when = ''; try { when = new Date(v.saved_at).toLocaleString(); } catch (e) { when = String(v.saved_at || ''); }
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;align-items:center;gap:12px;min-height:44px;padding:6px 10px;border:1px solid var(--bd);border-radius:var(--r);background:var(--s2)';
    row.innerHTML = '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:600;color:var(--t1)">' + (i === 0 ? 'Most recent saved version' : 'Version ' + (i + 1)) + '</div><div style="font-size:11.5px;color:var(--t3)">' + bld_esc(when) + ' \u00B7 ' + Math.round((v.size || 0) / 1024) + ' KB</div></div>';
    var b = document.createElement('button'); b.type = 'button'; b.className = 'lu-btn lu-btn--sm'; b.textContent = 'Restore';
    b.addEventListener('click', function () {
      window.luConfirm('Restore this version?', 'This puts the saved version from ' + when + ' live straight away. Your current version is kept in this list, so you can put it back.', { okLabel: 'Restore it', cancelLabel: 'Keep current', danger: true }).then(function (ok) {
        if (!ok) return;
        b.disabled = true; b.textContent = 'Restoring\u2026';
        fetch(API + 'builder/websites/' + siteId + '/restore', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, auth), body: JSON.stringify({ file: v.file }) })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !(o.j && (o.j.restored || o.j.success))) throw new Error((o.j && (o.j.error || o.j.message)) || 'restore failed');
            if (typeof showToast === 'function') showToast('That version is live again.', 'success');
            _t3ReloadPreview();
            close(); wsShowVersions(siteId);
          })
          .catch(function (e) { b.disabled = false; b.textContent = 'Restore'; if (typeof showToast === 'function') showToast("Couldn't restore that version \u2014 " + e.message, 'error'); });
      });
    });
    row.appendChild(b); list.appendChild(row);
  });
}

/* ── P1R-2 (2026-08-31) — VERSION HISTORY + RESTORE in Advanced ───────────────────────────────────────────────
   Basic's Website surface could restore a published version; Advanced could not. Same endpoints
   (GET builder/websites/{id}/history, POST .../restore), same authoritative object, design-system components,
   luConfirm before a destructive restore. */
async function wsRenderVersions(siteId) {
  var host = document.getElementById('ws-versions');
  var pagesWrap = document.getElementById('ws-site-pages');
  if (!host) {
    host = document.createElement('div'); host.id = 'ws-versions'; host.className = 'lu-card'; host.style.margin = '0 0 16px';
    var grid = document.getElementById('ws-pages-grid');
    if (grid && grid.parentElement) grid.parentElement.insertBefore(host, grid); else if (pagesWrap) pagesWrap.appendChild(host);
  }
  host.innerHTML = '<div class="lu-card__h">Earlier versions</div><div class="lu-skel" style="width:60%"></div>';
  var auth = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' };
  var items = [];
  try {
    var r = await fetch(API + 'builder/websites/' + siteId + '/history', { headers: auth, cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var j = await r.json();
    items = (j && (j.history || (j.data && j.data.history))) || [];
  } catch (e) {
    host.innerHTML = '<div class="lu-card__h">Earlier versions</div><div class="lu-empty"><b>Couldn\'t load versions</b>Try again in a moment.</div>';
    return;
  }
  if (!items.length) {
    host.innerHTML = '<div class="lu-card__h">Earlier versions</div><div class="lu-empty"><b>No earlier versions yet</b>Every change keeps the version it replaced.</div>';
    return;
  }
  host.innerHTML = '<div class="lu-card__h">Earlier versions <span style="font-weight:400;font-size:12px;color:var(--t3)">' + items.length + ' saved · restoring puts that version live; the current one is kept</span></div>';
  var list = document.createElement('div'); list.style.cssText = 'display:flex;flex-direction:column;gap:6px'; host.appendChild(list);
  items.slice(0, 10).forEach(function (v, i) {
    var when = ''; try { when = new Date(v.saved_at).toLocaleString(); } catch (e) { when = String(v.saved_at || ''); }
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;align-items:center;gap:12px;min-height:44px;padding:6px 10px;border:1px solid var(--bd);border-radius:var(--r);background:var(--s2)';
    row.innerHTML = '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:600;color:var(--t1)">' + (i === 0 ? 'Most recent saved version' : 'Version ' + (i + 1)) + '</div>'
                  + '<div style="font-size:11.5px;color:var(--t3)">' + bld_esc(when) + ' · ' + Math.round((v.size || 0) / 1024) + ' KB</div></div>';
    var b = document.createElement('button'); b.type = 'button'; b.className = 'lu-btn lu-btn--sm'; b.textContent = 'Restore';
    b.addEventListener('click', function () {
      window.luConfirm('Restore this version?', 'This puts the saved version from ' + when + ' live straight away. Your current version is kept in this list, so you can put it back.', { okLabel: 'Restore it', cancelLabel: 'Keep current', danger: true })
        .then(function (ok) {
          if (!ok) return;
          b.disabled = true; b.textContent = 'Restoring…';
          fetch(API + 'builder/websites/' + siteId + '/restore', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, auth), body: JSON.stringify({ file: v.file }) })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (o) {
              if (!o.ok || !(o.j && (o.j.restored || o.j.success))) throw new Error((o.j && (o.j.error || o.j.message)) || 'restore failed');
              if (typeof showToast === 'function') showToast('That version is live again.', 'success');
              wsRenderVersions(siteId);
            })
            .catch(function (e) { b.disabled = false; b.textContent = 'Restore'; if (typeof showToast === 'function') showToast("Couldn't restore that version — " + e.message, 'error'); });
        });
    });
    row.appendChild(b); list.appendChild(row);
  });
}

// Live-resolve polling REMOVED 2026-04-10: backend now writes pages synchronously
// inside wizardGenerate(), so there is nothing to wait for. Polling was firing
// ~75 requests in 30s and causing the editor to feel hung. See changelog.
var _wsLiveResolveTimer = null;
function _wsLiveResolve(pages, siteId) { return; }

function wsCloseSite() {
  var _v = document.getElementById('ws-versions'); if (_v) _v.remove(); // P1R-2
  clearInterval(_wsLiveResolveTimer); // stop any active thumbnail polling
  wsCurrentSite = null;
  document.getElementById('ws-site-pages').style.display = 'none';
  document.getElementById('ws-site-list').style.display = 'block';
  // v5.7.21 (2026-05-31) — Phase 2 URL: drop the tail when returning to
  // the site list.
  try {
    if (window._luRouter && window._luRouter.enabled()) {
      window._luRouter.pushView('websites');
    }
  } catch (_e) {}
}
// v5.7.21 (2026-05-31) — expose for router deep links: /app/builder/{siteId}
// dispatches to nav('builder', {tail: id}) which calls this after the
// builder engine mounts. wsOpenSite is already top-level so it's global.
window.wsOpenSite = wsOpenSite;

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
      '<div class="ws-thumb-area" onclick="wsEditSitePage(' + pg.id + ')" style="position:relative;height:160px;overflow:hidden;background:var(--bg,#0F1117);cursor:pointer">' +
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
    features: '<div style="flex:1;display:flex;align-items:stretch;gap:3px;padding:4px 6px;background:var(--bg,#0F1117)">' +
      [c40,'rgba(255,255,255,.06)','rgba(255,255,255,.04)'].map(function(bg){
        return '<div style="flex:1;border-radius:3px;background:' + bg + ';display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:3px">' +
          '<div style="width:10px;height:10px;border-radius:50%;background:' + c + ';opacity:.7"></div>' +
          '<div style="width:80%;height:3px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
          '<div style="width:60%;height:2px;border-radius:1px;background:rgba(255,255,255,.1)"></div></div>';
      }).join('') + '</div>',
    grid: '<div style="flex:1;display:flex;flex-wrap:wrap;gap:2px;padding:4px 6px;background:var(--bg,#0F1117);align-content:flex-start">' +
      [c40,'rgba(255,255,255,.07)','rgba(255,255,255,.05)','rgba(255,255,255,.08)'].map(function(bg){
        return '<div style="width:calc(50% - 1px);height:20px;border-radius:3px;background:' + bg + '"></div>';
      }).join('') + '</div>',
    cta: '<div style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:6px;background:' + c20 + ';border-top:1px solid ' + c40 + '">' +
      '<div style="width:50%;height:5px;border-radius:2px;background:rgba(255,255,255,.3)"></div>' +
      '<div style="width:36px;height:11px;border-radius:3px;background:' + c + '"></div></div>',
    form: '<div style="flex:1.2;display:flex;flex-direction:column;gap:3px;padding:5px 8px;background:var(--bg,#0F1117)">' +
      ['rgba(255,255,255,.08)','rgba(255,255,255,.08)','rgba(255,255,255,.06)'].map(function(bg){
        return '<div style="height:9px;border-radius:3px;background:' + bg + ';border:1px solid rgba(255,255,255,.07)"></div>';
      }).join('') +
      '<div style="height:11px;border-radius:3px;background:' + c + ';margin-top:2px"></div></div>',
    pricing: '<div style="flex:1.5;display:flex;align-items:stretch;gap:3px;padding:4px 6px;background:var(--bg,#0F1117)">' +
      ['rgba(255,255,255,.05)',c20,'rgba(255,255,255,.04)'].map(function(bg,idx){
        var f = idx===1;
        return '<div style="flex:1;border-radius:4px;background:' + bg + ';border:1px solid ' + (f?c:'rgba(255,255,255,.06)') + ';display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:3px">' +
          '<div style="width:70%;height:4px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
          '<div style="width:50%;height:7px;border-radius:2px;background:' + (f?c:'rgba(255,255,255,.15)') + '"></div>' +
          '<div style="width:80%;height:8px;border-radius:3px;background:' + (f?c:'rgba(255,255,255,.07)') + ';margin-top:2px"></div></div>';
      }).join('') + '</div>',
    list: '<div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:4px 6px;background:var(--bg,#0F1117)">' +
      [1,2,3].map(function(){
        return '<div style="height:16px;border-radius:3px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:4px;padding:0 5px">' +
          '<div style="width:8px;height:8px;border-radius:2px;background:' + c40 + '"></div>' +
          '<div style="flex:1;height:3px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
          '<div style="width:25%;height:3px;border-radius:1px;background:rgba(255,255,255,.1)"></div></div>';
      }).join('') + '</div>',
    story: '<div style="flex:1;display:flex;gap:4px;padding:4px 6px;background:var(--bg,#0F1117);align-items:center">' +
      '<div style="flex:1;display:flex;flex-direction:column;gap:2px">' +
      '<div style="height:4px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
      '<div style="height:3px;border-radius:1px;background:rgba(255,255,255,.12)"></div>' +
      '<div style="height:3px;border-radius:1px;background:rgba(255,255,255,.1)"></div></div>' +
      '<div style="flex:1;height:40px;border-radius:4px;background:' + c20 + ';border:1px solid ' + c40 + '"></div></div>',
    faq: '<div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:4px 6px;background:var(--bg,#0F1117)">' +
      [1,2,3].map(function(_,idx){
        return '<div style="height:12px;border-radius:3px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.06);display:flex;align-items:center;justify-content:space-between;padding:0 5px">' +
          '<div style="width:65%;height:3px;border-radius:1px;background:rgba(255,255,255,.2)"></div>' +
          '<div style="width:8px;height:8px;border-radius:50%;background:' + (idx===0?c:'rgba(255,255,255,.1)') + ';display:flex;align-items:center;justify-content:center;font-size:6px;color:#fff">' + (idx===0?'−':'+') + '</div></div>';
      }).join('') + '</div>',
    content: '<div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:6px 8px;background:var(--bg,#0F1117)">' +
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

// ── PAGE EDITOR (structured + legacy pages) ── BUILDER888 D0 fix, 2026-08-28 ──
// Replaces the dead canvas-editor shell (#view-builder / bld-editor-state) whose
// handlers (bldOpenEditor, bldLeftTab, bldRenderCanvas, …) were removed on
// 2026-04-17 and stubbed on 2026-05-05: "Edit Page" left the customer on a
// permanent "Loading page…" canvas with a ReferenceError on every open.
// The editing model for non-template sites is Arthur (POST /builder/pages/{id}/
// arthur-edit → pages.sections_json); the preview is the same BuilderRenderer
// output the published site uses (GET /builder/preview/{id}). Legacy static-HTML
// pages (one raw <html> section) are deliberately not editable (decision
// 2026-07-02) — say so instead of pretending.
var _wsPageEditor = { pageId: null, siteId: null, legacy: false };

function wsEditSitePage(pageId) {
  var site = wsCurrentSite || { id: null, title: 'Website' };
  window._wsReturnToSite = site.id || null;
  _wsShowPageEditor(site, pageId);
}

function _wsShowPageEditor(site, pageId) {
  _wsClosePageEditor();
  _wsPageEditor = { pageId: pageId, siteId: site.id, legacy: false };
  // Canonical Arthur path keys off bldCurrentPageId (see _t3ArthurSend).
  bldCurrentPageId = pageId;
  var siteName = bld_escH(site.title || site.name || 'Website');
  var pubName = JSON.stringify(site.title || site.name || 'Website').replace(/"/g, '&quot;');
  var devBtn = function (key, label, glyph, on) {
    return '<button type="button" id="pe-dev-' + key + '" onclick="_wsPageEditorSetDevice(\'' + key + '\')" aria-label="' + label + ' preview" aria-pressed="' + (on ? 'true' : 'false') + '" title="' + label + '" ' +
      'style="padding:5px 10px;border:none;background:' + (on ? 'var(--pu)' : 'transparent') + ';color:' + (on ? '#fff' : 'var(--t2)') + ';cursor:pointer;font-size:13px">' + glyph + '</button>';
  };
  var html =
    '<div id="page-editor-view" role="dialog" aria-modal="true" aria-label="Page editor" style="position:fixed;inset:0;z-index:9000;background:var(--bg,#0F1117);display:flex;flex-direction:column">' +
      '<div class="pe-bar" style="height:52px;background:var(--s1,#161927);border-bottom:1px solid var(--bd);display:flex;align-items:center;padding:0 16px;gap:12px;flex-shrink:0">' +
        '<button type="button" id="pe-back" onclick="_wsClosePageEditor()" style="background:none;border:1px solid var(--bd);color:var(--t1);padding:5px 12px;border-radius:6px;cursor:pointer;font-size:13px">← Pages</button>' +
        '<span class="pe-bar-title" style="color:var(--t1);font-weight:600;font-size:14px">' + siteName + '</span>' +
        '<span id="pe-page-title" style="color:var(--t3);font-size:12px"></span>' +
        '<span class="pe-bar-spacer" style="flex:1"></span>' +
        '<div role="group" aria-label="Preview device" style="display:flex;border:1px solid var(--bd);border-radius:6px;overflow:hidden">' +
          devBtn('desktop', 'Desktop', '🖥', true) + devBtn('mobile', 'Mobile', '📱', false) +
        '</div>' +
        '<span id="pe-status" class="pe-bar-hint" style="color:var(--t3);font-size:11px">Changes made by Arthur save automatically</span>' +
        '<button type="button" id="t3-undo" onclick="wsUndoLast(' + (site.id || 0) + ')" title="Undo the last change" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">↶ Undo</button>' +
        '<button type="button" id="pe-refresh" onclick="_wsPageEditorReload()" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 12px;border-radius:6px;cursor:pointer;font-size:13px">Refresh preview</button>' +
        '<button type="button" onclick="wsOpenPalettes(' + (site.id || 0) + ')" title="Colour palettes — click to apply" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:13px">Colours</button>' +
        '<button type="button" id="pe-publish" onclick="wsPublishFromEditor(' + (site.id || 0) + ', ' + pubName + ')" style="background:var(--p,#6C5CE7);border:none;color:#fff;padding:5px 16px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">Publish</button>' +
      '</div>' +
      '<div class="pe-main" style="flex:1;display:flex;overflow:hidden">' +
        '<div class="pe-side" style="width:300px;background:var(--s1,#161927);border-right:1px solid var(--bd);display:flex;flex-direction:column;flex-shrink:0">' +
          '<div style="padding:14px;border-bottom:1px solid var(--bd)">' +
            '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><div style="width:28px;height:28px;background:var(--p);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px">' + window.icon('ai', 18) + '</div><div style="color:var(--t1);font-weight:600;font-size:13px">Arthur</div></div>' +
            '<div style="color:var(--t3);font-size:11px">Describe the change you want on this page</div>' +
          '</div>' +
          '<div id="pe-legacy-banner" role="alert" style="display:none;margin:10px;padding:10px 12px;border-radius:8px;background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);color:#FBBF24;font-size:12px;line-height:1.5">' +
            'This page is a legacy static layout, so Arthur can’t edit it here. Rebuild it with the Website Wizard to unlock editing. Your live site is unaffected.' +
          '</div>' +
          '<div id="t3-arthur-feed" style="flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px">' +
            _bldHint('ax-page-try', 'Try: \u201cChange the hero heading to \u2026\u201d or \u201cMake the call-to-action say \u2026\u201d') +
          '</div>' +
          '<div class=\"pe-composer\" style="padding:10px;border-top:1px solid var(--bd);display:flex;gap:6px">' +
            '<input id="t3-arthur-input" type="text" aria-label="Message Arthur" placeholder="Ask Arthur..." style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:6px;color:var(--t1);padding:7px 10px;font-size:12px;outline:none;font-family:inherit" onkeydown="if(event.key===\'Enter\'){_t3ArthurSend(' + (site.id || 0) + ')}">' +
            '<button type="button" id="pe-send" aria-label="Send to Arthur" onclick="_t3ArthurSend(' + (site.id || 0) + ')" style="background:var(--p);border:none;color:#fff;padding:7px 10px;border-radius:6px;cursor:pointer;font-size:12px">→</button>' +
          '</div>' +
        '</div>' +
        '<div id="pe-frame-wrap" class="pe-stage" style="flex:1;position:relative;display:flex;justify-content:center;background:#0B0D13;overflow:auto">' +
          // sandbox WITHOUT allow-same-origin: the page's own scripts run in an opaque
          // origin and cannot read the app's localStorage token (RISK-0095 class).
          '<iframe id="t3-preview" title="Page preview" sandbox="allow-scripts allow-forms allow-popups" style="width:100%;height:100%;border:none;background:#fff;transition:width .2s"></iframe>' +
          '<div id="pe-loading" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(15,17,23,.85);color:var(--t2);font-size:13px">Loading preview…</div>' +
        '</div>' +
      '</div>' +
    '</div>';
  document.body.insertAdjacentHTML('beforeend', html);
  document.addEventListener('keydown', _wsPageEditorKey);
  window._luPageEditorReloadHook = _wsPageEditorReload;
  var inp = document.getElementById('t3-arthur-input');
  if (inp) inp.focus();
  _wsPageEditorLoad();
}

function _wsPageEditorKey(e) {
  if (e.key === 'Escape') { e.preventDefault(); _wsClosePageEditor(); }
}

function _wsClosePageEditor() {
  var v = document.getElementById('page-editor-view');
  if (v) v.remove();
  document.removeEventListener('keydown', _wsPageEditorKey);
  window._luPageEditorReloadHook = null;
  bldCurrentPageId = null;
  _wsPageEditor = { pageId: null, siteId: null, legacy: false };
}

function _wsPageEditorSetDevice(key) {
  var iframe = document.getElementById('t3-preview');
  if (iframe) _t3FitPreview(key);   // DEVICE FIT (2026-09-15)
  ['desktop', 'tablet', 'mobile'].forEach(function (k) {
    var b = document.getElementById('pe-dev-' + k);
    if (!b) return;
    var on = k === key;
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    b.style.background = on ? 'var(--pu)' : 'transparent';
    b.style.color = on ? '#fff' : 'var(--t2)';
  });
}

async function _wsPageEditorLoad() {
  var pageId = _wsPageEditor.pageId;
  var H = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' };
  try {
    var pr = await fetch(API + 'builder/pages/' + pageId, { headers: H });
    var page = pr.ok ? await pr.json() : null;
    if (page) {
      var tEl = document.getElementById('pe-page-title');
      if (tEl) tEl.textContent = '— ' + (page.title || 'Page') + (page.slug ? ' (/' + page.slug + ')' : '');
      var secs = page.sections_json;
      if (typeof secs === 'string') { try { secs = JSON.parse(secs || '[]'); } catch (_e) { secs = []; } }
      if (!Array.isArray(secs)) secs = page.sections || [];
      var legacy = Array.isArray(secs) && secs.length === 1 && secs[0] && typeof secs[0].html === 'string' && /<html[\s>]/i.test(secs[0].html);
      _wsPageEditor.legacy = legacy;
      if (legacy) {
        var ban = document.getElementById('pe-legacy-banner'); if (ban) ban.style.display = 'block';
        var inp = document.getElementById('t3-arthur-input');
        if (inp) { inp.disabled = true; inp.placeholder = 'Editing unavailable for legacy pages'; }
        var snd = document.getElementById('pe-send'); if (snd) snd.disabled = true;
        var st = document.getElementById('pe-status'); if (st) st.textContent = 'Read-only preview';
      }
    }
  } catch (_e) { /* preview still loads below */ }
  await _wsPageEditorReload();
}

async function _wsPageEditorReload() {
  var pageId = _wsPageEditor.pageId;
  if (!pageId) return;
  var wrap = document.getElementById('pe-frame-wrap');
  var iframe = document.getElementById('t3-preview');
  var loading = document.getElementById('pe-loading');
  if (loading) { loading.style.display = 'flex'; loading.textContent = 'Loading preview…'; }
  var err = document.getElementById('pe-error'); if (err) err.remove();
  try {
    var r = await fetch(API + 'builder/preview/' + pageId, { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' } });
    var d = null; try { d = await r.json(); } catch (_j) { d = null; }
    if (!r.ok || !d || typeof d.preview_html !== 'string') {
      throw new Error((d && d.error) ? d.error : ('Preview unavailable (HTTP ' + r.status + ')'));
    }
    if (iframe) {
      // Keep the overlay until the document actually paints: a sandboxed srcdoc
      // frame blocks on its render-blocking font CSS and can sit white for seconds.
      iframe.onload = function () { if (loading) loading.style.display = 'none'; };
      iframe.srcdoc = d.preview_html;
      setTimeout(function () { if (loading) loading.style.display = 'none'; }, 20000);
    } else if (loading) { loading.style.display = 'none'; }
  } catch (e) {
    if (loading) loading.style.display = 'none';
    if (wrap) {
      wrap.insertAdjacentHTML('beforeend',
        '<div id="pe-error" role="alert" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;background:var(--bg,#0F1117);color:var(--t2);font-size:13px">' +
          '<div style="color:#F87171">' + bld_escH(e.message || 'Preview failed') + '</div>' +
          '<button type="button" onclick="_wsPageEditorReload()" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px">Try again</button>' +
        '</div>');
    }
    if (typeof showToast === 'function') showToast(e.message || 'Preview failed', 'error');
  }
}

// ADD PAGE PICKER (2026-09-06): the page templates Arthur can add for THIS site, with previews and prices, in site CSS.
// Arthur adds the page from the template in the site's palette (builder/create → ask_arthur). No free-text page names.
async function wsAddPageToSite() {
  if (!wsCurrentSite) return;
  var hdr = {'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json'};
  var lib = null;
  try { var lr = await fetch(API + 'builder/library?website_id=' + wsCurrentSite.id, { headers: hdr }); lib = await lr.json(); } catch (e) { lib = null; }
  var pages = (lib && Array.isArray(lib.pages)) ? lib.pages : [];
  if (!pages.length) { showToast('No page templates available for this site', 'warning'); return; }
  var price = (lib && lib.pricing && lib.pricing.page) ? lib.pricing.page : 5;
  var old = document.getElementById('ws-page-picker'); if (old) old.remove();
  var ov = document.createElement('div'); ov.id = 'ws-page-picker';
  ov.style.cssText = 'position:fixed;inset:0;z-index:var(--z-critical,9999);background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:20px';
  var cards = pages.map(function (p) {
    var dis = p.exists ? ' disabled' : '';
    return '<button type="button" class="ws-pp-card"' + dis + ' data-slug="' + bld_escH(p.slug) + '" style="text-align:left;border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--s1);color:var(--t1);cursor:' + (p.exists ? 'not-allowed;opacity:.5' : 'pointer') + ';display:flex;flex-direction:column;gap:6px">' +
      '<div style="font-weight:700;font-size:14px">' + bld_escH(p.label || p.slug) + (p.exists ? ' <span style="font-size:11px;color:var(--t3)">· already added</span>' : '') + '</div>' +
      '<div style="font-size:12px;color:var(--t3);line-height:1.4">' + bld_escH(p.description || '') + '</div>' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px"><span style="font-size:11px;color:var(--t2)">' + (p.universal ? 'All industries' : 'Industry page') + ' · ' + price + ' credits</span>' +
      '<a href="' + bld_escH(p.preview_url || '#') + '" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="font-size:11px;color:var(--p)">Preview ↗</a></div></button>';
  }).join('');
  ov.innerHTML = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:16px;width:min(880px,100%);max-height:86vh;display:flex;flex-direction:column;overflow:hidden">' +
    '<div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--bd)"><div><div style="font-weight:700;color:var(--t1)">Add a page to ' + bld_escH(wsCurrentSite.title || 'your site') + '</div><div style="font-size:12px;color:var(--t3)">Arthur builds it from the template, in your palette, with copy written for your business.</div></div>' +
    '<button type="button" id="ws-pp-close" style="background:none;border:none;color:var(--t3);font-size:20px;cursor:pointer">\u2715</button></div>' +
    '<div style="overflow:auto;padding:16px 20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">' + cards + '</div>' +
    '<div id="ws-pp-status" style="padding:10px 20px;border-top:1px solid var(--bd);font-size:12px;color:var(--t3);min-height:18px"></div></div>';
  document.body.appendChild(ov);
  ov.addEventListener('click', function (e) { if (e.target === ov || e.target.id === 'ws-pp-close') ov.remove(); });
  ov.querySelectorAll('.ws-pp-card:not([disabled])').forEach(function (b) {
    b.addEventListener('click', async function () {
      var slug = b.getAttribute('data-slug'); var st = document.getElementById('ws-pp-status');
      ov.querySelectorAll('.ws-pp-card').forEach(function (x) { x.disabled = true; });
      if (st) st.textContent = 'Asking Arthur to add the ' + slug.replace(/_/g, ' ') + ' page\u2026';
      try {
        var r = await fetch(API + 'builder/create', { method: 'POST', headers: Object.assign({'Content-Type': 'application/json'}, hdr),
          body: JSON.stringify({ website_id: wsCurrentSite.id, type: 'page', page_template: slug, title: slug.replace(/_/g, ' '), request: 'add a ' + slug.replace(/_/g, ' ') + ' page' }) });
        var d = await r.json();
        var ok = d && (d.success === true || (d.result && d.result.success === true));
        var msg = (d && (d.message || (d.result && d.result.message))) || (ok ? 'Page added' : 'Arthur could not add that page');
        if (ok) { showToast(msg, 'success'); ov.remove(); wsOpenSite(wsCurrentSite.id); }
        else { if (st) st.textContent = msg; ov.querySelectorAll('.ws-pp-card').forEach(function (x) { if (!x.dataset.exists) x.disabled = false; }); }
      } catch (e) { if (st) st.textContent = 'Failed: ' + e.message; }
    });
  });
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
    // REPORT-0023 (2026-08-30): POST /builder/create requires website_id (400 without it) and the old
    // bldOpenEditor was a stub — the new page now opens in the live page editor (wsEditSitePage).
    const r=await fetch(API+'builder/create',{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'},body:JSON.stringify({title:title,type:'page',website_id:siteId})});
    const d=await r.json().catch(function(){return {};});
    const pid=(d.page&&d.page.id)||(d.data&&d.data.page&&d.data.page.id)||d.page_id||(d.data&&(d.data.page_id||d.data.id))||null;
    if(r.ok && d.success!==false && pid){await wsOpenSite(siteId);wsEditSitePage(pid);}
    else if(d.pending_approval){showToast(d.message||'Page creation was sent to your approval queue.','info');await wsOpenSite(siteId);}
    else{showToast('Could not create the page: '+(d.error||d.message||('HTTP '+r.status)),'error');}
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
      if(msg)msg.innerHTML = ''+window.icon("check",14)+' Connected and verified!';
      var s=wsSites.find(function(s){return s.id===wsPubTarget?.id;});
      if(s)s.domain_status='verified';
      showToast('Domain verified!','success');
    } else {
      if(msg)msg.innerHTML = ''+window.icon("close",14)+' '+( d.error||'DNS not yet propagated. Try again later.');
      showToast(d.error||'Verification failed — DNS may still be propagating','warning');
    }
  }catch(e){showToast('Verify error: '+e.message,'error');}
}

async function wsDisconnectDomain(){
  if(!wsPubTarget)return;
  if(!await luConfirm('Disconnect custom domain?', 'Visitors will need to use the .levelupgrowth.io URL.', {okLabel:'Disconnect', cancelLabel:'Keep domain', danger:true}))return;
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
    // First publish — show subdomain picker (DEC-0046: and only that; the publish sheet steps aside)
    var _pm = document.getElementById('ws-pub-modal'); if (_pm) _pm.style.display = 'none';
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
      if(pubStatus){ pubStatus.style.display='flex'; pubStatus.innerHTML='<span>✓</span><a href="'+encodeURI(String(liveUrl||''))+'" target="_blank" rel="noopener" style="color:var(--ac);text-decoration:underline">View Live Site →</a>'; }   /* SEC-3 */
      btn.textContent='Published ✓';
      setTimeout(function(){ btn.innerHTML = ''+window.icon("rocket",14)+' Publish Now'; btn.disabled=false; },3000);
      if(typeof wsUpdateStats==='function')wsUpdateStats();
      if(typeof wsRenderGrid==='function')wsRenderGrid();
    } else {
      showToast(d.error||'Publish failed','error');
      btn.innerHTML = ''+window.icon("rocket",14)+' Publish Now';btn.disabled=false;
    }
  }catch(e){showToast('Publish failed: '+e.message,'error');btn.innerHTML = ''+window.icon("rocket",14)+' Publish Now';btn.disabled=false;}
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

    // PATCH (FIX 2, 2026-05-09) — null-safe modal close + remove any
    // sibling publish-modal so neither dialog stays open after success.
    var sm = document.getElementById('subdomain-modal');
    if (sm) sm.remove();
    var pm = document.getElementById('publish-modal');
    if (pm) pm.remove();
    var pmAlt = document.getElementById('ws-pub-modal');
    if (pmAlt) pmAlt.remove();

    var url = pubD.url || ('https://' + slug + '.levelupgrowth.io');
    showToast('Website published! ' + url, 'success', { duration: 8000 });

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

// PATCH (publish-flow-fix v2, 2026-05-09) — FIX 1
// Wrapper used by the template-editor toolbar Publish button. The plain
// wsDoPublish() takes no args and reads the global wsPubTarget; in the
// editor view that global isn't set, so clicking Publish was a silent
// no-op. This helper figures out whether the site is first-publish or
// republish and routes to the right path.
window.wsPublishFromEditor = function (wsId, siteName) {
  // Normalise site name (caller may double-quote-encode it)
  siteName = (siteName == null) ? '' : String(siteName);

  // Try to find the site in the loaded grid; if not present, fetch a
  // minimal record so we know its current subdomain + status.
  var site = (typeof wsSites !== 'undefined' && Array.isArray(wsSites))
    ? wsSites.find(function (s) { return s && s.id === wsId; })
    : null;

  function decideAndDispatch(s) {
    var hasSub  = s && s.subdomain && String(s.subdomain).length > 5;
    var isPub   = s && (s.status === 'published' || s.publish_state === 'published');
    if (hasSub && isPub) {
      // Already published with subdomain — re-publish through the
      // existing wsDoPublish flow (which expects wsPubTarget set).
      window.wsPubTarget = s;
      if (typeof wsDoPublish === 'function') wsDoPublish();
      return;
    }
    // First publish (or no subdomain yet) — show the picker.
    if (typeof _luShowSubdomainPicker === 'function') {
      _luShowSubdomainPicker(wsId, siteName);
    }
  }

  if (site) {
    decideAndDispatch(site);
    return;
  }

  // Fall back to a quick fetch so the toolbar button works even when
  // wsSites hasn't loaded yet (e.g. user navigated straight into the
  // editor without visiting the websites grid).
  fetch(API + 'websites/' + wsId, {
    headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' },
  })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (j) {
      var s = (j && (j.website || j.data || j)) || { id: wsId, name: siteName };
      decideAndDispatch(s);
    })
    .catch(function () {
      decideAndDispatch({ id: wsId, name: siteName });
    });
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
    setTimeout(()=>{ btn.innerHTML = ''+window.icon("save",14)+' Save'; btn.disabled=false; },2000);
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
  // DEC-0046: no confirmation on republish — Versions keeps what it replaced.
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
      setTimeout(function(){ btn.innerHTML = ''+window.icon("rocket",14)+' Publish'; btn.disabled = false; }, 3000);
      if (bldCurrentPage) bldCurrentPage.status = 'published';
      showToast('Changes published! Your site is updated.', 'success');
    } else {
      showToast(d.error || 'Publish failed', 'error');
      btn.innerHTML = ''+window.icon("rocket",14)+' Publish'; btn.disabled = false;
    }
  } catch(e) {
    showToast('Publish failed: ' + e.message, 'error');
    btn.innerHTML = ''+window.icon("rocket",14)+' Publish'; btn.disabled = false;
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
  h += '  fetch(__apiBase+"builder/websites/"+siteId+"/publish",{method:"POST",headers:{"Authorization":"Bearer "+(localStorage.getItem("lu_token")||""),"Content-Type":"application/json","Accept":"application/json"},body:"{}"})';
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
// W6 product decision: a signup section on the CUSTOMER'S OWN website is a
// retained lead-capture feature, not LevelUpGrowth Email Marketing. The
// legacy 'newsletter' key is kept so existing sections_json keeps rendering;
// 'email_signup' and 'lead_capture' are the unambiguous names to use going
// forward. Nothing here sends mail, broadcasts, or manages campaigns.
var _BLD_ALLOWED_SECTION_TYPES = ['header','hero','features','cta','contact_form','blog_list','footer','gallery','services','team','testimonials','faq','pricing','stats','generic','booking_form','travel_quiz','events_calendar','grid','filter_bar','map','related_listings','trust_signals','cart_summary','checkout_form','account_nav','account_panel','ticker','news_feed','category_strips','video_embed','directory','newsletter_signup','ad_slot','jobs_board']; // 2026-09-06: generated from SectionSchema::allowedTypes() — no phantom names // 2026-09-06: generated from SectionSchema::allowedTypes() — no phantom names // 2026-09-06: generated from SectionSchema::allowedTypes() — no phantom names
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

    // Execution toast — P0 (2026-08-30): the app's one toast layer, not a module-private element.
    showToast('Executing: ' + toolId + ' — processing request…', 'info');
  }
  function _execProgressHide() {
    const bar = document.getElementById('exec-progress');
    if (bar) { const b = bar.querySelector('.exec-progress-bar'); if (b) b.style.width = '100%'; setTimeout(() => bar.style.display = 'none', 400); }
  }

  // ── Inline policy badge for any tool ───────────────────────────────
  // 2026-05-30 — extended set after the approval-system audit: added
  // delete_lead, publish_website. Backend cap-map + Sarah's destructive
  // list were updated in lockstep so all three layers agree.
  const _PROTECTED = new Set(['publish_post','send_campaign','create_campaign','update_campaign','export_website','export_page','publish_builder_page','publish_website','create_lead','delete_lead','enroll_sequence','create_booking_slot']);
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
      const isProtected = ['publish_post','send_campaign','create_campaign','update_campaign','export_website','export_page','publish_builder_page','publish_website','create_lead','delete_lead','enroll_sequence','create_booking_slot'].includes(toolId);
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
        if (d.reload_preview) _t3ReloadPreview();
        if (typeof wsLoadSites === 'function' && (d.action === 'page_deleted' || d.action === 'page_duplicated')) wsLoadSites();
      }
    }
  } catch(err) {
    console.error('[Tier4 confirm]', err);
    btn.disabled = false; btn.textContent = 'Confirm';
  }
}


/* ══════════════ DEC-0046 (2026-09-13) — palettes, undo, exit choice, preview reload ══════════════ */
// PREVIEW GATE (2026-09-15): the preview is fetched with the bearer token and written into the iframe (srcdoc); a <base>
// keeps relative links resolving as they did when the iframe pointed at the preview URL. Nothing token-bearing is in a URL.
async function _t3LoadPreview(siteId, iframe) {
  iframe = iframe || document.getElementById('t3-preview'); if (!iframe || !siteId) return;
  window._t3PreviewSiteId = siteId;
  var seq = (window._t3PreviewSeq = (window._t3PreviewSeq || 0) + 1);
  try {
    var r = await fetch('/api/builder/websites/' + siteId + '/preview', { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'text/html' }, cache: 'no-store' });
    if (seq !== window._t3PreviewSeq) return;   // a newer load won
    if (!r.ok) { iframe.srcdoc = '<div style="font:14px/1.5 system-ui,sans-serif;padding:28px;color:#334">' + (r.status === 401 || r.status === 403 ? 'Please sign in again to see this preview.' : (r.status === 404 ? 'This preview is available to members of its workspace only.' : 'The preview could not be loaded (HTTP ' + r.status + ').')) + '</div>'; return; }
    var html = await r.text();
    if (!/<base\s/i.test(html)) html = html.replace(/<head([^>]*)>/i, '<head$1><base href="' + location.origin + '/api/builder/websites/' + siteId + '/preview">');
    iframe.srcdoc = html;
  } catch (e) { if (seq === window._t3PreviewSeq) iframe.srcdoc = '<div style="font:14px/1.5 system-ui,sans-serif;padding:28px;color:#334">The preview could not be loaded. Check your connection and try again.</div>'; }
}

function _t3ReloadPreview() {
  // The page editor (renderer sites) rebuilds its preview from the API; the template editor reloads the export.
  if (typeof window._luPageEditorReloadHook === 'function') { try { window._luPageEditorReloadHook(); return; } catch (_e) {} }
  var f = document.getElementById('t3-preview');
  if (!f) return;
  if (window._t3PreviewSiteId) { _t3LoadPreview(window._t3PreviewSiteId, f); return; }   // PREVIEW GATE
  var base = String(f.src || '').split('#')[0].split('?')[0];
  f.src = base + '?v=' + Date.now();
}

window.wsUndoLast = async function (siteId) {
  var btn = document.getElementById('t3-undo');
  if (btn) btn.disabled = true;
  var auth = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json', 'Content-Type': 'application/json' };
  try {
    var r = await fetch(API + 'builder/websites/' + siteId + '/undo', { method: 'POST', headers: auth, body: '{}' });
    var j = null; try { j = await r.json(); } catch (_e) {}
    if (r.ok && j && j.undone) {
      if (typeof showToast === 'function') showToast(j.remaining > 0 ? 'Undone. ' + j.remaining + ' more step' + (j.remaining === 1 ? '' : 's') + ' can be undone.' : 'Undone — that was the earliest saved step.', 'success');
      _t3ReloadPreview();
      var pal = document.getElementById('t3-pal'); if (pal) pal.remove();
    } else if (j && j.error === 'nothing_to_undo') {
      if (typeof showToast === 'function') showToast('Nothing to undo yet.', 'info');
    } else {
      if (typeof showToast === 'function') showToast("Couldn’t undo that — " + ((j && (j.message || j.error)) || ('HTTP ' + r.status)), 'error');
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast("Couldn’t undo — " + e.message, 'error');
  } finally { if (btn) btn.disabled = false; }
};

/* Palette panel: hover previews by writing the site's own :root variables into the iframe, click applies and
   persists. The server computes the variable map per site (the same painter every build uses), so what is
   previewed is byte-for-byte what is written. */
var _t3PalLive = null;
function _t3PaletteVars(vars) {
  var f = document.getElementById('t3-preview');
  var doc = null; try { doc = f && f.contentDocument; } catch (_e) {}
  if (!doc || !doc.documentElement) return false;
  Object.keys(vars || {}).forEach(function (k) { try { doc.documentElement.style.setProperty(k, vars[k]); } catch (_e) {} });
  return true;
}
function _t3PaletteRestore() {
  var f = document.getElementById('t3-preview');
  var doc = null; try { doc = f && f.contentDocument; } catch (_e) {}
  if (!doc || !doc.documentElement) return;
  Object.keys(_t3PalLive || {}).forEach(function (k) { try { doc.documentElement.style.removeProperty(k); } catch (_e) {} });
}
window.wsOpenPalettes = async function (siteId) {
  var old = document.getElementById('t3-pal');
  if (old) { old.remove(); _t3PaletteRestore(); return; }
  // Both editors: the template editor's stage or the page editor's frame wrap. Appending to <body> put the panel
  // underneath the page editor's fixed view (proven: elementFromPoint returned the iframe).
  var stage = document.querySelector('#template-editor-view .pe-stage') || document.getElementById('pe-frame-wrap') || document.body;
  var panel = document.createElement('div');
  panel.id = 't3-pal'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Colour palettes');
  panel.style.cssText = 'position:absolute;top:10px;right:10px;width:min(360px,calc(100% - 20px));max-height:calc(100% - 20px);overflow:auto;z-index:120;background:var(--s1);border:1px solid var(--bd2);border-radius:var(--rg,12px);box-shadow:0 18px 48px rgba(0,0,0,.45);padding:14px;font-family:var(--fb)';
  panel.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:2px"><div style="font:700 14px var(--fh);color:var(--t1);flex:1">Colour palettes</div><button type="button" id="t3-pal-x" aria-label="Close" style="background:none;border:1px solid var(--bd);color:var(--t2);width:28px;height:28px;border-radius:6px;cursor:pointer">×</button></div>'
    + '<div id="t3-pal-sub" style="font-size:12px;color:var(--t3);margin-bottom:12px">Hover to preview, click to apply. Every palette is contrast-checked. Undo puts the old colours back.</div>'
    + '<div id="t3-pal-list"><div class="lu-skel" style="width:80%"></div><div class="lu-skel" style="width:60%;margin-top:8px"></div></div>';
  stage.appendChild(panel);
  panel.querySelector('#t3-pal-x').addEventListener('click', function () { panel.remove(); _t3PaletteRestore(); });
  var auth = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' };
  var list = panel.querySelector('#t3-pal-list');
  var data = null;
  try {
    var r = await fetch(API + 'builder/websites/' + siteId + '/palettes', { headers: auth, cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    data = await r.json();
  } catch (e) {
    list.innerHTML = '<div class="lu-empty"><b>Couldn’t load palettes</b>' + bld_escH(e.message) + '</div>';
    return;
  }
  var pals = (data && data.palettes) || [];
  _t3PalLive = (data && data.live) || {};
  var current = (data && data.current) || null;
  if (!pals.length) { list.innerHTML = '<div class="lu-empty"><b>No palettes for this design</b></div>'; return; }
  var canPreview = pals.some(function (p) { return p.vars && Object.keys(p.vars).length; });
  if (!canPreview) { var sub = panel.querySelector('#t3-pal-sub'); if (sub) sub.textContent = 'Click a palette to apply it — the preview updates right after. Undo puts the old colours back.'; }
  list.innerHTML = '';
  list.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:8px';
  var applying = false;
  pals.forEach(function (p) {
    var card = document.createElement('button');
    card.type = 'button';
    card.setAttribute('data-pal', p.id);
    var isCur = current === p.id;
    card.style.cssText = 'text-align:left;padding:0;background:var(--s2);border:1px solid ' + (isCur ? 'var(--p)' : 'var(--bd)') + ';border-radius:10px;overflow:hidden;cursor:pointer;color:var(--t1);font-family:var(--fb)';
    card.innerHTML = '<div style="display:flex;height:34px"><span style="flex:1.4;background:' + p.primary + '"></span><span style="flex:1;background:' + p.secondary + '"></span><span style="flex:1;background:' + p.accent + '"></span></div>'
      + '<div style="padding:7px 9px 8px"><div style="font-size:12.5px;font-weight:600;line-height:1.2">' + bld_escH(p.label) + '</div>'
      + '<div style="font-size:10.5px;color:var(--t3);margin-top:3px;min-height:13px">' + (isCur ? '✓ Current' : (p.recommended ? 'Recommended for you' : '')) + '</div></div>';
    if (canPreview && p.vars && Object.keys(p.vars).length) {
      card.addEventListener('mouseenter', function () { if (!applying) _t3PaletteVars(p.vars); });
      card.addEventListener('mouseleave', function () { if (!applying) _t3PaletteRestore(); });
    }
    card.addEventListener('click', async function () {
      if (applying) return;
      applying = true;
      _t3PaletteVars(p.vars || {});
      var tag = card.querySelector('div > div:last-child'); if (tag) tag.textContent = 'Applying…';
      try {
        var rr = await fetch(API + 'builder/websites/' + siteId + '/palette', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, auth), body: JSON.stringify({ theme: p.id }) });
        var jj = null; try { jj = await rr.json(); } catch (_e) {}
        if (!rr.ok || !jj || !jj.success) throw new Error((jj && (jj.message || jj.error)) || ('HTTP ' + rr.status));
        current = p.id;
        _t3PalLive = Object.assign({}, _t3PalLive, p.vars || {});
        list.querySelectorAll('button[data-pal]').forEach(function (b) {
          var mine = b.getAttribute('data-pal') === p.id;
          b.style.borderColor = mine ? 'var(--p)' : 'var(--bd)';
          var t = b.querySelector('div > div:last-child');
          if (t) t.textContent = mine ? '✓ Current' : (pals.filter(function (q) { return q.id === b.getAttribute('data-pal'); })[0] || {}).recommended ? 'Recommended for you' : '';
        });
        if (typeof showToast === 'function') showToast(jj.message || ('Switched to ' + p.label), 'success');
        _t3ReloadPreview();
      } catch (e) {
        _t3PaletteRestore();
        if (tag) tag.textContent = isCur ? '✓ Current' : (p.recommended ? 'Recommended for you' : '');
        if (typeof showToast === 'function') showToast("Couldn’t switch palette — " + e.message, 'error');
      } finally { applying = false; }
    });
    list.appendChild(card);
  });
};

/* Three-way exit choice, in site CSS (never a native dialog). Resolves 'save' | 'discard' | 'stay'. */
function _t3ExitChoice(n) {
  return new Promise(function (resolve) {
    var ov = document.createElement('div');
    ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'Unsaved changes');
    ov.style.cssText = 'position:fixed;inset:0;z-index:100001;background:rgba(0,0,0,.62);display:flex;align-items:center;justify-content:center;padding:20px';
    var box = document.createElement('div');
    box.style.cssText = 'background:var(--s1);border:1px solid var(--bd2);border-radius:var(--rg,12px);padding:20px;width:min(440px,100%);font-family:var(--fb)';
    box.innerHTML = '<div style="font:700 15px var(--fh);color:var(--t1);margin-bottom:6px">Save your edits as a draft?</div>'
      + '<div style="font-size:13px;color:var(--t2);line-height:1.5;margin-bottom:16px">You have ' + n + ' unsaved text edit' + (n === 1 ? '' : 's') + ' in the preview. Save them as a draft on this website, or leave without them.</div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">'
      + '<button type="button" class="lu-btn lu-btn--sm" data-c="stay">Keep editing</button>'
      + '<button type="button" class="lu-btn lu-btn--sm" data-c="discard" style="color:#F87171">Leave without saving</button>'
      + '<button type="button" class="lu-btn lu-btn--sm lu-btn--primary" data-c="save" style="background:var(--p);color:#fff;border-color:var(--p)">Save draft</button>'
      + '</div>';
    ov.appendChild(box); document.body.appendChild(ov);
    function done(v) { try { ov.remove(); } catch (_e) {} document.removeEventListener('keydown', onKey, true); resolve(v); }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); done('stay'); } }
    document.addEventListener('keydown', onKey, true);
    box.querySelectorAll('button[data-c]').forEach(function (b) { b.addEventListener('click', function () { done(b.getAttribute('data-c')); }); });
    ov.addEventListener('click', function (e) { if (e.target === ov) done('stay'); });
    var s = box.querySelector('button[data-c=save]'); if (s) s.focus();
  });
}

/* ══════════════ LAYOUT SWITCHER (2026-09-14) — sibling designs, free preview, apply with undo ══════════════ */
window._t3LayoutPreviewing = false;
function _t3LayoutBar(text, onApply, onBack) {
  var old = document.getElementById('t3-lay-bar'); if (old) old.remove();
  var stage = document.querySelector('#template-editor-view .pe-stage'); if (!stage) return;
  var bar = document.createElement('div'); bar.id = 't3-lay-bar';
  bar.style.cssText = 'position:absolute;left:50%;top:10px;transform:translateX(-50%);z-index:125;background:var(--s1);border:1px solid var(--bd2);border-radius:999px;padding:8px 10px 8px 16px;display:flex;align-items:center;gap:10px;box-shadow:0 12px 32px rgba(0,0,0,.4);font-family:var(--fb);font-size:13px;color:var(--t1)';
  bar.innerHTML = '<span>' + bld_escH(text) + '</span>'
    + '<button type="button" class="lu-btn lu-btn--sm" data-a="back">Back to current</button>'
    + '<button type="button" class="lu-btn lu-btn--sm" data-a="apply" style="background:var(--p);color:#fff;border-color:var(--p)">Apply this layout</button>';
  bar.querySelector('[data-a=back]').addEventListener('click', onBack);
  bar.querySelector('[data-a=apply]').addEventListener('click', onApply);
  stage.appendChild(bar);
}
function _t3LayoutEndPreview() {
  window._t3LayoutPreviewing = false;
  var bar = document.getElementById('t3-lay-bar'); if (bar) bar.remove();
  var f = document.getElementById('t3-preview');
  if (f) { try { f.removeAttribute('srcdoc'); } catch (_e) {} }
  _t3ReloadPreview();
}
window.wsOpenLayouts = async function (siteId) {
  var old = document.getElementById('t3-lay');
  if (old) { old.remove(); return; }
  var pal = document.getElementById('t3-pal'); if (pal) pal.remove();
  var stage = document.querySelector('#template-editor-view .pe-stage') || document.body;
  var panel = document.createElement('div');
  panel.id = 't3-lay'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Layouts');
  panel.style.cssText = 'position:absolute;top:10px;right:10px;width:min(420px,calc(100% - 20px));max-height:calc(100% - 20px);overflow:auto;z-index:120;background:var(--s1);border:1px solid var(--bd2);border-radius:var(--rg,12px);box-shadow:0 18px 48px rgba(0,0,0,.45);padding:14px;font-family:var(--fb)';
  panel.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:2px"><div style="font:700 14px var(--fh);color:var(--t1);flex:1">Layouts</div><button type="button" id="t3-lay-x" aria-label="Close" style="background:none;border:1px solid var(--bd);color:var(--t2);width:28px;height:28px;border-radius:6px;cursor:pointer">×</button></div>'
    + '<div id="t3-lay-sub" style="font-size:12px;color:var(--t3);margin-bottom:12px">Other layouts in this design family. Click one to preview it with your own content — free. Apply keeps your text, images, colours and added sections; Undo puts the old layout back.</div>'
    + '<div id="t3-lay-list"><div class="lu-skel" style="width:80%"></div><div class="lu-skel" style="width:60%;margin-top:8px"></div></div>';
  stage.appendChild(panel);
  panel.querySelector('#t3-lay-x').addEventListener('click', function () { panel.remove(); if (window._t3LayoutPreviewing) _t3LayoutEndPreview(); });
  var auth = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' };
  var list = panel.querySelector('#t3-lay-list');
  var data = null;
  try {
    var r = await fetch(API + 'builder/websites/' + siteId + '/layouts', { headers: auth, cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    data = await r.json();
  } catch (e) { list.innerHTML = '<div class="lu-empty"><b>Couldn’t load layouts</b>' + bld_escH(e.message) + '</div>'; return; }
  var lays = (data && data.layouts) || [];
  if (lays.length < 2) { list.innerHTML = '<div class="lu-empty"><b>This design family has one layout</b>Ask Arthur to build a new site for a different structure.</div>'; return; }
  list.innerHTML = ''; list.style.cssText = 'display:flex;flex-direction:column;gap:10px';
  var busy = false;
  lays.forEach(function (L) {
    var card = document.createElement('div');
    card.setAttribute('data-lay', L.slug);
    card.style.cssText = 'border:1px solid ' + (L.current ? 'var(--p)' : 'var(--bd)') + ';border-radius:10px;overflow:hidden;background:var(--s2)';
    card.innerHTML = (L.screenshot ? '<img src="' + L.screenshot + '" alt="" loading="lazy" style="display:block;width:100%;aspect-ratio:16/9;object-fit:cover;object-position:top;background:#0B0D13">' : '<div style="width:100%;aspect-ratio:16/9;background:#0B0D13"></div>')
      + '<div style="padding:9px 10px 10px;display:flex;align-items:center;gap:10px"><div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:600;color:var(--t1)">' + bld_escH(L.name) + '</div>'
      + '<div style="font-size:11px;color:var(--t3);margin-top:2px">' + (L.current ? '✓ Current layout' : (L.carry_over + '% of your content carries over · ' + (L.credits > 0 ? L.credits + ' credits to fill the rest' : 'free to apply'))) + '</div></div>'
      + (L.current ? '' : '<button type="button" class="lu-btn lu-btn--sm" data-a="preview">Preview</button>') + '</div>';
    var btn = card.querySelector('[data-a=preview]');
    if (btn) btn.addEventListener('click', async function () {
      if (busy) return; busy = true; btn.disabled = true; btn.textContent = 'Rendering…';
      try {
        var rr = await fetch(API + 'builder/websites/' + siteId + '/layout/preview', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, auth), body: JSON.stringify({ design: L.slug }) });
        var jj = null; try { jj = await rr.json(); } catch (_e) {}
        if (!rr.ok || !jj || !jj.success || !jj.html) throw new Error((jj && (jj.message || jj.error)) || ('HTTP ' + rr.status));
        window._t3LayoutPreviewing = true;
        var f = document.getElementById('t3-preview'); if (f) f.srcdoc = jj.html;
        if (window.innerWidth <= 760) { panel.remove(); }   // phone: the bar carries Apply / Back; the page must be visible
        _t3LayoutBar('Previewing “' + L.name + '”' + (L.credits > 0 ? ' — applying fills ' + L.gaps + ' missing texts for ' + L.credits + ' credits' : ' — free to apply'),
          async function () {
            var b = document.querySelector('#t3-lay-bar [data-a=apply]'); if (b) { b.disabled = true; b.textContent = 'Applying…'; }
            try {
              var ra = await fetch(API + 'builder/websites/' + siteId + '/layout', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, auth), body: JSON.stringify({ design: L.slug }) });
              var ja = null; try { ja = await ra.json(); } catch (_e) {}
              if (!ra.ok || !ja || !ja.success) throw new Error((ja && (ja.message || ja.error)) || ('HTTP ' + ra.status));
              if (typeof showToast === 'function') showToast(ja.message || ('Switched to ' + L.name + '. Undo puts the old layout back.'), 'success');
              window._t3LayoutPreviewing = false;
              var bar = document.getElementById('t3-lay-bar'); if (bar) bar.remove();
              var fr = document.getElementById('t3-preview'); if (fr) { try { fr.removeAttribute('srcdoc'); } catch (_e) {} }
              panel.remove();
              _t3ReloadPreview();
            } catch (e) {
              if (typeof showToast === 'function') showToast("Couldn’t apply that layout — " + e.message, 'error');
              if (b) { b.disabled = false; b.textContent = 'Apply this layout'; }
            }
          },
          function () { _t3LayoutEndPreview(); });
      } catch (e) {
        if (typeof showToast === 'function') showToast("Couldn’t preview that layout — " + e.message, 'error');
      } finally { busy = false; btn.disabled = false; btn.textContent = 'Preview'; }
    });
    list.appendChild(card);
  });
};

/* ══════════════ PHONE LAYOUT for the template editor overlays (2026-09-14) ══════════════ */
(function () {
  if (document.getElementById('t3-mobile-css')) return;
  var st = document.createElement('style'); st.id = 't3-mobile-css';
  st.textContent = '@media (max-width:760px){'
    + '#template-editor-view .pe-bar{height:auto!important;flex-wrap:wrap;padding:8px 10px!important;gap:6px!important}'
    + '#template-editor-view .pe-bar button{padding:6px 10px!important;font-size:12px!important}'
    + '#template-editor-view .pe-bar-hint,#template-editor-view .pe-bar-spacer{display:none!important}'
    + '#template-editor-view .pe-bar-title{flex:1 1 auto;font-size:13px!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '#t3-lay,#t3-pal,#t3-cat,#t3-site{top:auto!important;bottom:0!important;left:0!important;right:0!important;width:100%!important;max-height:min(62vh,100%)!important;border-radius:14px 14px 0 0!important;box-shadow:0 -12px 40px rgba(0,0,0,.5)!important}'
    + '#t3-lay-bar{left:8px!important;right:8px!important;top:8px!important;transform:none!important;flex-wrap:wrap;border-radius:12px!important;padding:10px 12px!important;gap:8px!important}'
    + '#t3-lay-bar span{flex:1 1 100%;font-size:12px;line-height:1.35}'
    + '#t3-lay-bar button{flex:1 1 calc(50% - 4px);white-space:nowrap}'
    + '#t3-pal-list{grid-template-columns:1fr 1fr!important}'
    + '}';
  document.head.appendChild(st);
})();

/* ══════════════ CATALOGUE panel — template editor (DEC-0049, 2026-09-14) ══════════════
 * One panel for everything the site sells: a tab per kind the design carries (Listings, Treatments, Menu …), forms
 * generated from the kind's schema (GET /builder/websites/{id}/catalogue). Every save re-projects the home block,
 * the kind's page and its item pages, then the preview reloads. Site CSS controls only — no native dialogs. */
(function () {
  if (document.getElementById('t3-cat-css')) return;
  var st = document.createElement('style'); st.id = 't3-cat-css';
  st.textContent = '#t3-cat .tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}#t3-cat .tabs button{background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:999px;padding:5px 12px;font:inherit;font-size:12px;cursor:pointer}#t3-cat .tabs button[aria-selected=true]{background:var(--p);border-color:var(--p);color:#fff}'
    + '#t3-cat .row{display:flex;gap:10px;align-items:center;border:1px solid var(--bd);border-radius:10px;padding:8px 10px;background:var(--s2)}'
    + '#t3-cat .row img{width:64px;height:48px;object-fit:cover;border-radius:6px;background:#0B0D13;flex-shrink:0}'
    + '#t3-cat .row .m{flex:1;min-width:0}#t3-cat .row .t{font-size:13px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '#t3-cat .row .s{font-size:11.5px;color:var(--t3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '#t3-cat .pill{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 7px;border-radius:999px;background:var(--p);color:#fff;margin-right:6px;vertical-align:1px}'
    + '#t3-cat .pill.closed{background:#4b5563}#t3-cat .pill.off{background:#7c3aed}'
    + '#t3-cat .acts{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}#t3-cat .lu-btn--sm{padding:5px 9px;font-size:12px}'
    + '#t3-cat label{display:block;font-size:11.5px;font-weight:600;color:var(--t2);margin:10px 0 4px}'
    + '#t3-cat input[type=text],#t3-cat input[type=number],#t3-cat textarea{width:100%;box-sizing:border-box;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px}'
    + '#t3-cat textarea{min-height:72px;resize:vertical}'
    + '#t3-cat .seg{display:flex;flex-wrap:wrap;gap:6px}#t3-cat .seg button{background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:999px;padding:5px 11px;font:inherit;font-size:12px;cursor:pointer}#t3-cat .seg button[aria-pressed=true]{background:var(--p);border-color:var(--p);color:#fff}'
    + '#t3-cat .two{display:grid;grid-template-columns:1fr 1fr;gap:10px}#t3-cat .three{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}'
    + '#t3-cat .photos{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}#t3-cat .photos .ph{position:relative;width:72px;height:54px;border-radius:6px;overflow:hidden;background:#0B0D13}'
    + '#t3-cat .photos .ph img{width:100%;height:100%;object-fit:cover}#t3-cat .photos .ph button{position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:0;background:rgba(0,0,0,.7);color:#fff;font-size:11px;cursor:pointer;line-height:18px;padding:0}'
    + '#t3-cat .inl{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--t2)}'
    + '#t3-cat .sw{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--t2);margin-bottom:10px}#t3-cat .sw input{accent-color:var(--p)}'
    + '@media (max-width:760px){#t3-cat .three{grid-template-columns:1fr 1fr}#t3-cat .row{flex-wrap:wrap}#t3-cat .row .m{flex:1 1 55%}#t3-cat .acts{flex:1 1 100%;justify-content:flex-start}}';
  document.head.appendChild(st);
})();

function _t3CatAuth() { return { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' }; }
function _t3CatKinds(d) { return d && d.catalogues ? Object.keys(d.catalogues) : []; }

/* Reveal the button when — and only when — the design carries at least one catalogue. */
window._t3CatalogueGate = async function (siteId) {
  var btn = document.getElementById('t3-catalogue-btn');
  if (!btn) return;
  btn.hidden = true;
  try {
    var r = await fetch(API + 'builder/websites/' + siteId + '/catalogue', { headers: _t3CatAuth(), cache: 'no-store' });
    if (!r.ok) return;
    var d = await r.json();
    var kinds = _t3CatKinds(d);
    if (kinds.length) { btn.hidden = false; btn.textContent = kinds.length === 1 ? d.catalogues[kinds[0]].label : 'Catalogue'; window._t3CatalogueData = d; }
  } catch (_e) {}
};

window.wsOpenCatalogue = async function (siteId, kind) {
  var old = document.getElementById('t3-cat');
  if (old && !kind) { old.remove(); return; }
  if (old) old.remove();
  var pal = document.getElementById('t3-pal'); if (pal) pal.remove();
  var lay = document.getElementById('t3-lay'); if (lay) lay.remove();
  var stage = document.querySelector('#template-editor-view .pe-stage') || document.body;
  var panel = document.createElement('div');
  panel.id = 't3-cat'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Catalogue');
  panel.style.cssText = 'position:absolute;top:10px;right:10px;width:min(520px,calc(100% - 20px));max-height:calc(100% - 20px);overflow:auto;z-index:120;background:var(--s1);border:1px solid var(--bd2);border-radius:var(--r,12px);box-shadow:0 20px 60px rgba(0,0,0,.45);padding:14px 14px 16px;font-family:var(--fb)';
  panel.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:2px"><div id="t3-cat-title" style="font:700 14px var(--fh);color:var(--t1);flex:1">What you sell</div><button type="button" id="t3-cat-x" aria-label="Close" style="background:none;border:0;color:var(--t2);font-size:18px;cursor:pointer;line-height:1">×</button></div>'
    + '<div id="t3-cat-sub" style="font-size:12px;color:var(--t3);margin-bottom:12px">Each item here shows on the home page and on its own list page with an enquiry form. Change it here and the site follows.</div>'
    + '<div class="tabs" id="t3-cat-tabs"></div><div id="t3-cat-body"><div class="lu-skel" style="width:80%"></div><div class="lu-skel" style="width:60%;margin-top:8px"></div></div>';
  stage.appendChild(panel);
  panel.querySelector('#t3-cat-x').addEventListener('click', function () { panel.remove(); });
  panel.setAttribute('data-kind', kind || '');
  _t3CatLoad(siteId, panel);
};

async function _t3CatLoad(siteId, panel) {
  var body = panel.querySelector('#t3-cat-body');
  try {
    var r = await fetch(API + 'builder/websites/' + siteId + '/catalogue', { headers: _t3CatAuth(), cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var d = await r.json();
    window._t3CatalogueData = d;
    var kinds = _t3CatKinds(d);
    if (!kinds.length) { body.innerHTML = '<div class="lu-empty"><b>No catalogue on this design</b>This design has no list of things to sell that I can manage.</div>'; return; }
    var kind = panel.getAttribute('data-kind') || kinds[0];
    if (kinds.indexOf(kind) < 0) kind = kinds[0];
    panel.setAttribute('data-kind', kind);
    var tabs = panel.querySelector('#t3-cat-tabs');
    tabs.innerHTML = kinds.length > 1 ? kinds.map(function (k) { return '<button type="button" role="tab" data-k="' + k + '" aria-selected="' + (k === kind ? 'true' : 'false') + '">' + bld_escH(d.catalogues[k].label) + ' (' + d.catalogues[k].items.length + ')</button>'; }).join('') : '';
    tabs.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { panel.setAttribute('data-kind', b.getAttribute('data-k')); _t3CatLoad(siteId, panel); }); });
    panel.querySelector('#t3-cat-title').textContent = d.catalogues[kind].label;
    _t3CatRenderList(siteId, panel, d.catalogues[kind]);
  } catch (e) { body.innerHTML = '<div class="lu-empty"><b>Couldn’t load the catalogue</b>' + bld_escH(e.message) + '</div>'; }
}

function _t3CatIsClosed(spec, L) { return (spec.closed_statuses || []).indexOf(L.status) >= 0; }

function _t3CatRenderList(siteId, panel, spec) {
  var body = panel.querySelector('#t3-cat-body');
  var rows = spec.items || [];
  var open = rows.filter(function (L) { return (spec.open || []).indexOf(L.status) >= 0; }).length;
  var h = '<label class="sw"><input type="checkbox" id="t3-cat-on"' + (spec.enabled ? ' checked' : '') + '> Show ' + bld_escH(spec.label.toLowerCase()) + ' on this site (' + bld_escH(spec.label) + ' page' + (spec.pages === 'index+detail' ? ' + one page per item' : '') + ')</label>';
  if (!spec.enabled) h += '<div class="lu-empty" style="margin-bottom:10px"><b>' + bld_escH(spec.label) + ' are off</b>The home page keeps what it shows; the ' + bld_escH(spec.label) + ' page is removed until you switch this back on.</div>';
  h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><div style="flex:1;font-size:12px;color:var(--t3)">' + rows.length + ' ' + bld_escH(rows.length === 1 ? spec.singular : spec.label.toLowerCase()) + ' · ' + open + ' shown · first ' + spec.slots + ' on the home page</div>'
    + '<button type="button" class="lu-btn lu-btn--sm" id="t3-cat-add"' + (spec.enabled ? '' : ' disabled') + '>+ Add ' + bld_escH(spec.singular) + '</button></div>';
  h += '<div id="t3-cat-rows" style="display:flex;flex-direction:column;gap:8px">';
  if (!rows.length) h += '<div class="lu-empty"><b>Nothing here yet</b>Add your first ' + bld_escH(spec.singular) + ' — or tell Arthur: “Add a ' + bld_escH(spec.singular) + ': …”.</div>';
  rows.forEach(function (L) {
    var closed = _t3CatIsClosed(spec, L);
    var hidden = (spec.open || []).indexOf(L.status) < 0 && !closed;
    var pill = (L.status !== spec.default_status || spec.kind === 'listing') ? '<span class="pill' + (closed ? ' closed' : (hidden ? ' off' : '')) + '">' + bld_escH(L.status_label) + '</span>' : '';
    var priceTxt = (L.price === null && !L.price_label) ? '' : L.price_display;
    var bits = [priceTxt, L.attrs && L.attrs.location ? L.attrs.location : '', L.specs_display || '', closed && L.closed_note ? L.closed_note : ''].filter(Boolean);
    h += '<div class="row" data-id="' + L.id + '">' + ((L.photos && L.photos[0]) || spec.kind === 'listing' ? '<img src="' + bld_escH((L.photos && L.photos[0]) || '/storage/template-images/listing-placeholder.svg') + '" alt="">' : '')
      + '<div class="m"><div class="t">' + pill + bld_escH(L.title) + '</div><div class="s">' + bld_escH(bits.join(' · ') || (L.summary || '')) + '</div></div>'
      + '<div class="acts"><button type="button" class="lu-btn lu-btn--sm" data-a="edit">Edit</button><button type="button" class="lu-btn lu-btn--sm" data-a="status">Status</button><button type="button" class="lu-btn lu-btn--sm" data-a="del" title="Remove">✕</button></div></div>';
  });
  h += '</div>';
  body.innerHTML = h;
  var on = body.querySelector('#t3-cat-on');
  on.addEventListener('change', async function () {
    on.disabled = true;
    try {
      var r = await fetch(API + 'builder/websites/' + siteId + '/catalogue/' + spec.kind + '/settings', { method: 'PUT', headers: Object.assign({ 'Content-Type': 'application/json' }, _t3CatAuth()), body: JSON.stringify({ enabled: on.checked }) });
      var j = null; try { j = await r.json(); } catch (_e) {}
      if (!r.ok || !j || !j.success) throw new Error((j && j.message) || ('HTTP ' + r.status));
      if (typeof showToast === 'function') showToast(j.message || 'Saved.', 'success');
      _t3ReloadPreview(); _t3CatalogueGate(siteId); _t3CatLoad(siteId, panel);
    } catch (e) { on.checked = !on.checked; on.disabled = false; if (typeof showToast === 'function') showToast("Couldn’t change that — " + e.message, 'error'); }
  });
  var add = body.querySelector('#t3-cat-add');
  if (add) add.addEventListener('click', function () { _t3CatForm(siteId, panel, spec, null); });
  body.querySelectorAll('.row').forEach(function (row) {
    var id = parseInt(row.getAttribute('data-id'), 10);
    var L = rows.filter(function (x) { return x.id === id; })[0];
    row.querySelector('[data-a=edit]').addEventListener('click', function () { _t3CatForm(siteId, panel, spec, L); });
    row.querySelector('[data-a=status]').addEventListener('click', function () { _t3CatStatusInline(siteId, panel, spec, row, L); });
    row.querySelector('[data-a=del]').addEventListener('click', function () { _t3CatDeleteInline(siteId, panel, spec, row, L); });
  });
}

/* Inline status change — the kind's own statuses as pills; an optional note only for closed ones (sold, let). */
function _t3CatStatusInline(siteId, panel, spec, row, L) {
  var acts = row.querySelector('.acts');
  var wasHtml = acts.innerHTML;
  var status = L.status;
  var keys = Object.keys(spec.statuses || {});
  acts.innerHTML = '<div style="width:100%"><div class="seg" style="margin-bottom:6px">' + keys.map(function (k) { return '<button type="button" data-s="' + k + '" aria-pressed="' + (k === status ? 'true' : 'false') + '">' + bld_escH(spec.statuses[k]) + '</button>'; }).join('') + '</div>'
    + '<input type="text" data-note placeholder="Note shown on the site (optional) — e.g. Sold in 5 days, over asking" maxlength="190" value="' + bld_escH(L.closed_note || '') + '" style="margin-bottom:6px"' + ((spec.closed_statuses || []).indexOf(status) >= 0 ? '' : ' hidden') + '>'
    + '<div class="inl"><button type="button" class="lu-btn lu-btn--sm" data-a="ok">Save</button><button type="button" class="lu-btn lu-btn--sm" data-a="cancel">Cancel</button></div></div>';
  acts.querySelectorAll('.seg button').forEach(function (b) { b.addEventListener('click', function () { status = b.getAttribute('data-s'); acts.querySelectorAll('.seg button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); acts.querySelector('[data-note]').hidden = (spec.closed_statuses || []).indexOf(status) < 0; }); });
  acts.querySelector('[data-a=cancel]').addEventListener('click', function () { acts.innerHTML = wasHtml; _t3CatLoad(siteId, panel); });
  acts.querySelector('[data-a=ok]').addEventListener('click', async function () {
    var b = acts.querySelector('[data-a=ok]'); b.disabled = true; b.textContent = 'Saving…';
    try {
      var payload = { status: status }; var note = acts.querySelector('[data-note]').value.trim(); if ((spec.closed_statuses || []).indexOf(status) >= 0) payload.closed_note = note;
      var r = await fetch(API + 'builder/websites/' + siteId + '/catalogue/' + spec.kind + '/' + L.id + '/status', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, _t3CatAuth()), body: JSON.stringify(payload) });
      var j = null; try { j = await r.json(); } catch (_e) {}
      if (!r.ok || !j || !j.success) throw new Error((j && j.message) || ('HTTP ' + r.status));
      if (typeof showToast === 'function') showToast(j.message || 'Saved.', 'success');
      _t3ReloadPreview(); _t3CatLoad(siteId, panel);
    } catch (e) { b.disabled = false; b.textContent = 'Save'; if (typeof showToast === 'function') showToast("Couldn’t save — " + e.message, 'error'); }
  });
}

function _t3CatDeleteInline(siteId, panel, spec, row, L) {
  var acts = row.querySelector('.acts');
  var wasHtml = acts.innerHTML;
  acts.innerHTML = '<div class="inl">Remove “' + bld_escH(L.title.length > 28 ? L.title.slice(0, 28) + '…' : L.title) + '”?<button type="button" class="lu-btn lu-btn--sm" data-a="yes">Yes, remove</button><button type="button" class="lu-btn lu-btn--sm" data-a="no">Keep</button></div>';
  acts.querySelector('[data-a=no]').addEventListener('click', function () { acts.innerHTML = wasHtml; _t3CatLoad(siteId, panel); });
  acts.querySelector('[data-a=yes]').addEventListener('click', async function () {
    var b = acts.querySelector('[data-a=yes]'); b.disabled = true; b.textContent = 'Removing…';
    try {
      var r = await fetch(API + 'builder/websites/' + siteId + '/catalogue/' + spec.kind + '/' + L.id, { method: 'DELETE', headers: _t3CatAuth() });
      var j = null; try { j = await r.json(); } catch (_e) {}
      if (!r.ok || !j || !j.success) throw new Error((j && j.message) || ('HTTP ' + r.status));
      if (typeof showToast === 'function') showToast(j.message || 'Removed.', 'success');
      _t3ReloadPreview(); _t3CatLoad(siteId, panel);
    } catch (e) { b.disabled = false; b.textContent = 'Yes, remove'; if (typeof showToast === 'function') showToast("Couldn’t remove — " + e.message, 'error'); }
  });
}

/* Add / edit form, generated from the kind's schema. Photos: paste a web address or upload from the device. */
function _t3CatForm(siteId, panel, spec, L) {
  var body = panel.querySelector('#t3-cat-body');
  var v = function (k, def) { return L && L[k] !== null && L[k] !== undefined ? L[k] : (def === undefined ? '' : def); };
  var attrs = (L && L.attrs) ? Object.assign({}, L.attrs) : {};
  var cur = v('currency', spec.currency || 'USD');
  var photos = (L && L.photos ? L.photos.slice() : []);
  var status = v('status', spec.default_status);
  var period = v('price_period', '') || '';
  var hasPrice = (spec.suffixes || []).indexOf('price') >= 0 || spec.kind === 'listing';
  var isListing = spec.kind === 'listing';
  var h = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><button type="button" class="lu-btn lu-btn--sm" id="t3-cat-back">‹ Back</button><div style="font:600 13px var(--fh);color:var(--t1)">' + (L ? 'Edit ' : 'New ') + bld_escH(spec.singular) + '</div></div>'
    + '<label>Name</label><input type="text" data-f="title" maxlength="190" placeholder="' + bld_escH(isListing ? '3-bed townhouse in Travis Heights' : 'Deep tissue massage') + '" value="' + bld_escH(v('title')) + '">'
    + '<label>Status</label><div class="seg" id="t3-cat-status">' + Object.keys(spec.statuses).map(function (k) { return '<button type="button" data-s="' + k + '" aria-pressed="' + (k === status ? 'true' : 'false') + '">' + bld_escH(spec.statuses[k]) + '</button>'; }).join('') + '</div>'
    + '<div class="three"><div><label>Price</label><input type="number" data-f="price" min="0" step="0.01" placeholder="' + (isListing ? '925000' : '90') + '" value="' + bld_escH(v('price')) + '"></div><div><label>Currency</label><input type="text" data-f="currency" maxlength="3" value="' + bld_escH(cur) + '"></div><div><label>Per</label><div class="seg" id="t3-cat-period">' + ['', 'month', 'week', 'night', 'hour', 'person', 'session'].map(function (p) { return '<button type="button" data-p="' + p + '" aria-pressed="' + (period === p ? 'true' : 'false') + '">' + (p || 'Total') + '</button>'; }).join('') + '</div></div></div>'
    + '<label>Price wording (optional — replaces the number, e.g. “From $120”, “POA”)</label><input type="text" data-f="price_label" maxlength="80" value="' + bld_escH(v('price_label')) + '">';
  (spec.attrs || []).forEach(function (a) {
    var val = attrs[a.key] !== undefined && attrs[a.key] !== null ? attrs[a.key] : (a.default || '');
    if (a.type === 'select') { h += '<label>' + bld_escH(a.label) + '</label><div class="seg" data-attr-seg="' + a.key + '">' + (a.options || []).map(function (o) { return '<button type="button" data-o="' + bld_escH(o) + '" aria-pressed="' + (String(val) === o ? 'true' : 'false') + '">' + bld_escH(o) + '</button>'; }).join('') + '</div>'; }
    else if (a.type === 'textarea') { h += '<label>' + bld_escH(a.label) + '</label><textarea data-attr="' + a.key + '">' + bld_escH(String(val)) + '</textarea>'; }
    else { h += '<label>' + bld_escH(a.label) + '</label><input type="' + (a.type === 'number' ? 'number' : 'text') + '" data-attr="' + a.key + '"' + (a.type === 'number' ? ' step="0.5" min="0"' : ' maxlength="190"') + ' value="' + bld_escH(String(val)) + '">'; }
  });
  h += '<label>Short text (on the card)</label><input type="text" data-f="summary" maxlength="300" value="' + bld_escH(v('summary')) + '">'
    + '<label>Full description (on the page)</label><textarea data-f="description" maxlength="6000">' + bld_escH(v('description')) + '</textarea>'
    + '<label>Features (one per line, optional)</label><textarea data-f="features" style="min-height:52px">' + bld_escH((v('features', []) || []).join('\n')) + '</textarea>'
    + '<label>Photos (first one is the card photo)</label><div class="photos" id="t3-cat-photos"></div>'
    + '<div class="inl" style="margin-top:6px"><input type="text" id="t3-cat-photo-url" placeholder="Paste a photo address (https://…)" style="flex:1;min-width:160px"><button type="button" class="lu-btn lu-btn--sm" id="t3-cat-photo-add">Add</button><button type="button" class="lu-btn lu-btn--sm" id="t3-cat-photo-up">Upload</button><input type="file" id="t3-cat-photo-file" accept="image/jpeg,image/png,image/webp" hidden></div>'
    + '<label class="sw" style="margin-top:12px"><input type="checkbox" data-f="featured"' + (v('featured', false) ? ' checked' : '') + '> Featured (shown first)</label>'
    + '<div id="t3-cat-closedwrap"' + ((spec.closed_statuses || []).indexOf(status) >= 0 ? '' : ' hidden') + '><label>Outcome note shown on the site (only what you want to state)</label><input type="text" data-f="closed_note" maxlength="190" placeholder="Sold in 5 days, over asking" value="' + bld_escH(v('closed_note')) + '"></div>'
    + '<div class="inl" style="margin-top:14px"><button type="button" class="lu-btn" id="t3-cat-save">' + (L ? 'Save changes' : 'Add ' + bld_escH(spec.singular)) + '</button><button type="button" class="lu-btn lu-btn--sm" id="t3-cat-cancel">Cancel</button><span id="t3-cat-err" style="color:#f87171;font-size:12px"></span></div>';
  body.innerHTML = h;
  if (!hasPrice) { /* the design has no price slot on the card; the price still shows on the list page */ }
  var renderPhotos = function () {
    var box = body.querySelector('#t3-cat-photos');
    box.innerHTML = photos.length ? photos.map(function (p, i) { return '<div class="ph"><img src="' + bld_escH(p) + '" alt=""><button type="button" data-i="' + i + '" aria-label="Remove photo">×</button></div>'; }).join('') : '<div style="font-size:12px;color:var(--t3)">' + (isListing ? 'No photo yet — the card shows a “photo coming soon” placeholder, never someone else’s house.' : 'No photo — the card keeps the design’s own look.') + '</div>';
    box.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { photos.splice(parseInt(b.getAttribute('data-i'), 10), 1); renderPhotos(); }); });
  };
  renderPhotos();
  var seg = function (sel, onPick) { body.querySelectorAll(sel + ' button').forEach(function (b) { b.addEventListener('click', function () { body.querySelectorAll(sel + ' button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); onPick(b); }); }); };
  seg('#t3-cat-status', function (b) { status = b.getAttribute('data-s'); body.querySelector('#t3-cat-closedwrap').hidden = (spec.closed_statuses || []).indexOf(status) < 0; });
  seg('#t3-cat-period', function (b) { period = b.getAttribute('data-p'); });
  body.querySelectorAll('[data-attr-seg]').forEach(function (s) { var key = s.getAttribute('data-attr-seg'); seg('[data-attr-seg="' + key + '"]', function (b) { attrs[key] = b.getAttribute('data-o'); }); });
  body.querySelector('#t3-cat-photo-add').addEventListener('click', function () { var u = body.querySelector('#t3-cat-photo-url').value.trim(); if (!u) return; if (!/^(https?:\/\/|\/storage\/)/i.test(u)) { body.querySelector('#t3-cat-err').textContent = 'A photo address starts with https://'; return; } photos.push(u); body.querySelector('#t3-cat-photo-url').value = ''; body.querySelector('#t3-cat-err').textContent = ''; renderPhotos(); });
  body.querySelector('#t3-cat-photo-up').addEventListener('click', function () { body.querySelector('#t3-cat-photo-file').click(); });
  body.querySelector('#t3-cat-photo-file').addEventListener('change', async function (e) {
    var f = e.target.files && e.target.files[0]; if (!f) return;
    var up = body.querySelector('#t3-cat-photo-up'); up.disabled = true; up.textContent = 'Uploading…';
    try {
      var fd = new FormData(); fd.append('photo', f);
      var r = await fetch(API + 'builder/websites/' + siteId + '/catalogue/photo', { method: 'POST', headers: _t3CatAuth(), body: fd });
      var j = null; try { j = await r.json(); } catch (_e) {}
      if (!r.ok || !j || !j.success) throw new Error((j && j.message) || ('HTTP ' + r.status));
      photos.push(j.url); renderPhotos();
    } catch (err) { body.querySelector('#t3-cat-err').textContent = "Couldn’t upload — " + err.message; }
    finally { up.disabled = false; up.textContent = 'Upload'; e.target.value = ''; }
  });
  var back = function () { _t3CatLoad(siteId, panel); };
  body.querySelector('#t3-cat-back').addEventListener('click', back);
  body.querySelector('#t3-cat-cancel').addEventListener('click', back);
  body.querySelector('#t3-cat-save').addEventListener('click', async function () {
    var b = body.querySelector('#t3-cat-save'); var err = body.querySelector('#t3-cat-err'); err.textContent = '';
    var g = function (k) { var el = body.querySelector('[data-f=' + k + ']'); return el ? (el.type === 'checkbox' ? el.checked : el.value) : ''; };
    if (!g('title').trim()) { err.textContent = 'Give it a name.'; return; }
    body.querySelectorAll('[data-attr]').forEach(function (el) { attrs[el.getAttribute('data-attr')] = el.value; });
    var payload = { title: g('title').trim(), status: status, price: g('price'), currency: g('currency').trim().toUpperCase() || cur, price_period: period, price_label: g('price_label'), summary: g('summary'), description: g('description'), features: g('features'), photos: photos, featured: g('featured') ? 1 : 0, closed_note: g('closed_note'), attrs: attrs };
    b.disabled = true; b.textContent = 'Saving…';
    try {
      var r = await fetch(API + 'builder/websites/' + siteId + '/catalogue/' + spec.kind + (L ? '/' + L.id : ''), { method: L ? 'PUT' : 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, _t3CatAuth()), body: JSON.stringify(payload) });
      var j = null; try { j = await r.json(); } catch (_e) {}
      if (!r.ok || !j || !j.success) throw new Error((j && j.message) || ('HTTP ' + r.status));
      if (typeof showToast === 'function') showToast(j.message || 'Saved.', 'success');
      _t3ReloadPreview(); _t3CatLoad(siteId, panel);
    } catch (e2) { b.disabled = false; b.textContent = L ? 'Save changes' : 'Add ' + spec.singular; err.textContent = e2.message; }
  });
}

/* ══════════════ SITE panel — tracking ids, export, domain (DEC-0051 gap closure, 2026-09-15) ══════════════ */
window.wsOpenSitePanel = async function (siteId) {
  var old = document.getElementById('t3-site');
  if (old) { old.remove(); return; }
  ['t3-pal', 't3-lay', 't3-cat'].forEach(function (id) { var e = document.getElementById(id); if (e) e.remove(); });
  var stage = document.querySelector('#template-editor-view .pe-stage') || document.body;
  var panel = document.createElement('div');
  panel.id = 't3-site'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Site settings');
  panel.style.cssText = 'position:absolute;top:10px;right:10px;width:min(460px,calc(100% - 20px));max-height:calc(100% - 20px);overflow:auto;z-index:120;background:var(--s1);border:1px solid var(--bd2);border-radius:var(--r,12px);box-shadow:0 20px 60px rgba(0,0,0,.45);padding:14px 14px 16px;font-family:var(--fb)';
  var auth = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' };
  panel.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:2px"><div style="font:700 14px var(--fh);color:var(--t1);flex:1">Site settings</div><button type="button" id="t3-site-x" aria-label="Close" style="background:none;border:0;color:var(--t2);font-size:18px;cursor:pointer;line-height:1">×</button></div>'
    + '<div style="font-size:12px;color:var(--t3);margin-bottom:12px">Tracking, export and domain for this website.</div><div id="t3-site-body"><div class="lu-skel" style="width:80%"></div></div>';
  stage.appendChild(panel);
  panel.querySelector('#t3-site-x').addEventListener('click', function () { panel.remove(); });
  var body = panel.querySelector('#t3-site-body');
  var d = null;
  try { var r = await fetch(API + 'builder/websites/' + siteId + '/site-settings', { headers: auth, cache: 'no-store' }); if (!r.ok) throw new Error('HTTP ' + r.status); d = await r.json(); }
  catch (e) { body.innerHTML = '<div class="lu-empty"><b>Couldn’t load settings</b>' + bld_escH(e.message) + '</div>'; return; }
  var t = d.tracking || {};
  var inp = function (k, label, ph) { return '<label style="display:block;font-size:11.5px;font-weight:600;color:var(--t2);margin:10px 0 4px">' + label + '</label><input type="text" data-t="' + k + '" placeholder="' + ph + '" value="' + bld_escH(t[k] || '') + '" style="width:100%;box-sizing:border-box;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px">'; };
  body.innerHTML = '<div style="font:600 13px var(--fh);color:var(--t1)">Tracking</div><div style="font-size:12px;color:var(--t3)">Paste the ids from your analytics or ads account. They go into every page of the site.</div>'
    + inp('ga4', 'Google Analytics 4 measurement id', 'G-XXXXXXXXXX') + inp('gtm', 'Google Tag Manager container id', 'GTM-XXXXXXX') + inp('meta_pixel', 'Meta (Facebook) pixel id', '1234567890123456') + inp('tiktok_pixel', 'TikTok pixel id', 'C0XXXXXXXXXXXXXXXX')
    + '<div style="display:flex;gap:8px;align-items:center;margin-top:12px"><button type="button" class="lu-btn" id="t3-site-save">Save tracking</button><span id="t3-site-msg" style="font-size:12px;color:var(--t3)"></span></div>'
    + '<hr style="border:0;border-top:1px solid var(--bd);margin:16px 0">'
    + '<div style="font:600 13px var(--fh);color:var(--t1)">Export</div><div style="font-size:12px;color:var(--t3);margin-bottom:8px">Download the whole site as a zip of plain HTML, CSS and images — it is yours.</div><button type="button" class="lu-btn lu-btn--sm" id="t3-site-export">Download site (.zip)</button><span id="t3-site-exp-msg" style="font-size:12px;color:var(--t3);margin-left:8px"></span>'
    + '<hr style="border:0;border-top:1px solid var(--bd);margin:16px 0">'
    + '<div style="font:600 13px var(--fh);color:var(--t1)">Payments</div><div id="t3-site-pay" style="font-size:12px;color:var(--t2);margin-top:4px">Loading…</div>'
    + '<hr style="border:0;border-top:1px solid var(--bd);margin:16px 0">'
    + '<div style="font:600 13px var(--fh);color:var(--t1)">Domain</div><div style="font-size:12px;color:var(--t2);margin-top:4px">' + bld_escH(d.domain && d.domain.text ? d.domain.text : 'No domain connected yet.') + '</div>';
  (async function renderPay() {
    var box = body.querySelector('#t3-site-pay'); var st = null;
    try { var rp = await fetch(API + 'builder/store-payments', { headers: auth, cache: 'no-store' }); st = await rp.json(); } catch (e) { box.textContent = 'Could not load payment settings.'; return; }
    if (st && st.connected) {
      box.innerHTML = '<div>' + bld_escH(st.message) + '</div><div style="margin-top:4px">Key ' + bld_escH(st.key_hint || '') + ' · ' + bld_escH(st.currency || '') + ' · ' + st.orders + ' order' + (st.orders === 1 ? '' : 's') + ', ' + st.paid + ' paid</div><div style="margin-top:8px"><button type="button" class="lu-btn lu-btn--sm" id="t3-site-pay-off">Disconnect</button></div>';
      box.querySelector('#t3-site-pay-off').addEventListener('click', async function () { var b = this; b.disabled = true; try { var rd = await fetch(API + 'builder/store-payments', { method: 'DELETE', headers: auth }); var jd = await rd.json(); if (typeof showToast === 'function') showToast(jd.message || 'Disconnected.', 'success'); renderPay(); } catch (e) { b.disabled = false; } });
      return;
    }
    box.innerHTML = '<div>Take payments for priced items (listings deposits, rooms, services, dishes) through your own Stripe account. Create a <b>restricted key</b> in Stripe with Checkout Sessions (write), Webhook Endpoints (write) and Balance (read), and paste it here. It is stored encrypted and never shown again.</div>'
      + '<input type="password" id="t3-pay-key" placeholder="rk_live_… or sk_test_…" autocomplete="off" style="width:100%;box-sizing:border-box;margin-top:8px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px">'
      + '<div style="display:flex;gap:8px;margin-top:8px"><input type="text" id="t3-pay-cur" placeholder="Currency (USD)" maxlength="3" style="width:120px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px"><button type="button" class="lu-btn lu-btn--sm" id="t3-site-pay-on">Connect Stripe</button></div><div id="t3-pay-msg" style="margin-top:6px"></div>';
    box.querySelector('#t3-site-pay-on').addEventListener('click', async function () {
      var b = this, m = box.querySelector('#t3-pay-msg'); var key = box.querySelector('#t3-pay-key').value.trim(); if (!key) { m.textContent = 'Paste the key first.'; return; } b.disabled = true; m.textContent = 'Checking with Stripe…';
      try { var rc = await fetch(API + 'builder/store-payments', { method: 'PUT', headers: Object.assign({ 'Content-Type': 'application/json' }, auth), body: JSON.stringify({ secret_key: key, currency: box.querySelector('#t3-pay-cur').value.trim() || 'USD' }) }); var jc = await rc.json(); if (!rc.ok || !jc.success) throw new Error(jc.message || ('HTTP ' + rc.status)); if (typeof showToast === 'function') showToast(jc.message, 'success'); _t3ReloadPreview(); renderPay(); }
      catch (e) { m.textContent = e.message; b.disabled = false; }
    });
  })();
  body.querySelector('#t3-site-save').addEventListener('click', async function () {
    var b = body.querySelector('#t3-site-save'), m = body.querySelector('#t3-site-msg'); b.disabled = true; m.textContent = 'Saving…';
    var payload = {}; body.querySelectorAll('[data-t]').forEach(function (el) { payload[el.getAttribute('data-t')] = el.value.trim(); });
    try {
      var r = await fetch(API + 'builder/websites/' + siteId + '/tracking', { method: 'PUT', headers: Object.assign({ 'Content-Type': 'application/json' }, auth), body: JSON.stringify(payload) });
      var j = null; try { j = await r.json(); } catch (_e) {}
      if (!r.ok || !j || !j.success) throw new Error((j && j.message) || ('HTTP ' + r.status));
      m.textContent = j.message || 'Saved.'; if (typeof showToast === 'function') showToast(j.message || 'Tracking saved.', 'success'); _t3ReloadPreview();
    } catch (e) { m.textContent = e.message; if (typeof showToast === 'function') showToast("Couldn’t save — " + e.message, 'error'); }
    finally { b.disabled = false; }
  });
  body.querySelector('#t3-site-export').addEventListener('click', async function () {
    var b = body.querySelector('#t3-site-export'), m = body.querySelector('#t3-site-exp-msg'); b.disabled = true; m.textContent = 'Preparing…';
    try {
      var r = await fetch(API + 'builder/websites/' + siteId + '/export', { headers: auth });
      if (!r.ok) { var j = null; try { j = await r.json(); } catch (_e) {} throw new Error((j && j.message) || ('HTTP ' + r.status)); }
      var blob = await r.blob(); var url = URL.createObjectURL(blob); var a = document.createElement('a'); a.href = url; a.download = 'site-' + siteId + '.zip'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
      m.textContent = 'Downloaded (' + Math.round(blob.size / 1024) + ' KB).';
    } catch (e) { m.textContent = e.message; }
    finally { b.disabled = false; }
  });
};
