// LevelUp SOCIAL Engine — v2.1.0
// Patches: direct social REST calls, correct publish endpoint, proper error handling

window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['social'] = true;

// Phase 3 — open unified media picker for social post image.
// Falls back to URL input if the picker script didn't load.
window._spPickImage = function() {
    if (typeof window.openMediaPicker !== 'function') {
        alert('Media picker is not loaded. Paste a URL instead.');
        return;
    }
    window.openMediaPicker({ type:'image', context:'social', multiple:false }, function(file) {
        if (!file || !file.url) return;
        var inp = document.getElementById('sp-img');
        if (inp) {
            inp.value = file.url;
            inp.dispatchEvent(new Event('input', { bubbles:true }));
        }
    });
};

// ═══════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════
var _soc = {
  posts:       [],
  accounts:    [],
  queue:       [],
  currentPost: null,
  filters:     { platform: '', status: '' },
  loading:     false,
};

// ═══════════════════════════════════════════════════════════════════
// API HELPER — all calls to social (Patches 4+5+6)
// ═══════════════════════════════════════════════════════════════════
async function _socApi(method, path, body) {
  const nonce = window.LU_CFG?.nonce || '';
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization':'Bearer '+(localStorage.getItem('lu_token')||'') },
    cache: 'no-store',
  };
  if (body) opts.body = JSON.stringify(body);
  let r;
  try { r = await fetch(window.location.origin + '/api' + path, opts); }
  catch(e) { throw new Error('Network error: ' + e.message); }

  if (r.status === 401) throw Object.assign(new Error('Session expired — please refresh.'), { code: 401 });
  if (r.status === 402) throw Object.assign(new Error('Insufficient credits.'), { code: 402 });
  if (r.status === 429) throw Object.assign(new Error('Rate limited — please wait.'), { code: 429 });
  if (!r.ok) {
    const d = await r.json().catch(() => ({}));
    throw new Error(d.message || d.error || 'Error ' + r.status);
  }
  return r.json();
}

// ═══════════════════════════════════════════════════════════════════
// LOAD + RENDER
// ═══════════════════════════════════════════════════════════════════
async function socialLoad(el) {
  if (!el) return;
  el.innerHTML = loadingCard(300);
  try {
    const [accounts, posts] = await Promise.all([
      _socApi('GET', '/social/accounts'),
      _socApi('GET', '/social/posts'),
    ]);
    _soc.accounts = Array.isArray(accounts) ? accounts : (accounts?.accounts || []);
    _soc.posts    = Array.isArray(posts)    ? posts    : (posts?.posts       || []);
    _socRender(el);
  } catch(e) {
    console.error('[Social]', e);
    el.innerHTML = `<div style="padding:60px;text-align:center;color:var(--t2)">
      <div style="font-size:32px;margin-bottom:12px">${window.icon('warning',14)}</div>
      <div style="font-size:14px;font-weight:600;margin-bottom:6px">Social failed to load</div>
      <div style="font-size:12px;color:var(--t3)">${friendlyError(e)}</div>
      <button class="btn btn-outline btn-sm" style="margin-top:16px" onclick="socialLoad(document.getElementById('social-root'))">↺ Retry</button></div>`;
  }
}

function _socRender(el) {
  const A = _soc.accounts, P = _soc.posts;
  const platIco = {facebook:''+window.icon("info",14)+'',instagram:''+window.icon("image",14)+'',twitter:''+window.icon("message",14)+'',x:''+window.icon("message",14)+'',linkedin:''+window.icon("more",14)+'',tiktok:''+window.icon("more",14)+'',youtube:'▶️'};
  const sCls    = {published:'db-pub',scheduled:'db-sched',draft:'db-draft',failed:'db-fail',deleted:'db-draft'};
  const published  = P.filter(p=>p.status==='published').length;
  const scheduled  = P.filter(p=>p.status==='scheduled').length;
  const totalLikes = P.reduce((s,p)=>s+(p.likes||0),0);
  const recentPosts = [...P].sort((a,b)=>new Date(b.scheduled_at||b.published_at||0)-new Date(a.scheduled_at||a.published_at||0)).slice(0,8);

  el.innerHTML = `
  <div style="max-width:1400px;padding-bottom:32px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div><h1 style="margin:0 0 3px;font-size:22px">Social</h1>
        <div style="font-size:13px;color:var(--t3)">${A.length} account${A.length!==1?'s':''} connected · ${P.length} post${P.length!==1?'s':''}</div></div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline btn-sm" onclick="socialLoad(document.getElementById('social-root'))">↺ Refresh</button>
        <button class="btn btn-outline btn-sm" onclick="socialSetView('accounts')">${window.icon('link',14)} Accounts</button>
        <button class="btn btn-primary btn-sm" onclick="socialNewPost()">+ New Post</button>
      </div>
    </div>

    <!-- NAV TABS -->
    <div style="display:flex;gap:0;margin-bottom:20px;border-bottom:1px solid var(--bd)">
      ${['dashboard','posts','queue','accounts'].map(v=>`
        <button class="dash-view-tab" data-sv="${v}" onclick="socialSetView('${v}',this)"
          style="color:${v==='dashboard'?'var(--da)':'var(--t3)'};font-weight:${v==='dashboard'?'600':'400'};
          border-bottom:2px solid ${v==='dashboard'?'var(--da)':'transparent'};
          padding:7px 14px;border-top:none;border-left:none;border-right:none;background:none;font-size:13px;cursor:pointer">
          ${{dashboard:'Dashboard',posts:'All Posts',queue:'Queue',accounts:'Accounts'}[v]}
        </button>`).join('')}
    </div>

    <!-- DASHBOARD -->
    <div id="social-view-dashboard">
      <div class="dash-grid dash-stats" style="margin-bottom:20px">
        <div class="dash-stat"><div class="dash-stat-val">${published}</div><div class="dash-stat-lbl">Published</div><div class="dash-stat-sub">this month</div></div>
        <div class="dash-stat"><div class="dash-stat-val">${scheduled}</div><div class="dash-stat-lbl">Scheduled</div><div class="dash-stat-sub">upcoming</div></div>
        <div class="dash-stat"><div class="dash-stat-val">${totalLikes>0?totalLikes.toLocaleString():'—'}</div><div class="dash-stat-lbl">Engagement</div><div class="dash-stat-sub">total likes</div></div>
        <div class="dash-stat"><div class="dash-stat-val">${A.length}</div><div class="dash-stat-lbl">Accounts</div><div class="dash-stat-sub">connected</div></div>
      </div>
      <div class="dash-grid dash-body" style="margin-bottom:20px">
        <div>
          <div class="dash-card" style="margin-bottom:14px">
            <div class="dash-card-hdr">Quick Actions</div>
            <div class="dash-card-body">
              <button class="dash-qa-btn" onclick="socialNewPost()"><span class="qa-ico">${window.icon('edit',14)}</span>Create Post</button>
              <button class="dash-qa-btn" onclick="socialNewPost()"><span class="qa-ico">${window.icon('calendar',14)}</span>Schedule Post</button>
              <button class="dash-qa-btn" onclick="socialGenerateCaption()"><span class="qa-ico">${window.icon('ai',14)}</span>Generate Caption (AI)</button>
            </div>
          </div>
          <div class="dash-card">
            <div class="dash-card-hdr">Platforms <span>${A.length}</span></div>
            <div class="dash-card-body" style="padding:10px 14px">
              ${A.length===0
                ? `<div style="font-size:12px;color:var(--t3);text-align:center;padding:12px 0">No accounts connected.<br><a href="#" onclick="socialSetView('accounts');return false" style="color:var(--da)">Connect one →</a></div>`
                : A.map(a=>`<div style="display:flex;align-items:center;gap:8px;padding:6px 0"><span style="font-size:18px">${platIco[a.platform]||''+window.icon("globe",14)+''}</span><div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${a.account_name||a.name||a.platform}</div><div style="font-size:10px;color:var(--t3)">${a.platform}</div></div><span class="dash-badge db-pub">Active</span></div>`).join('')}
            </div>
          </div>
        </div>
        <div>
          <div class="dash-card" style="margin-bottom:14px">
            <div class="dash-card-hdr">Content Calendar</div>
            <div class="dash-cal" id="social-cal"></div>
          </div>
          <div class="dash-card" id="social-day-panel" style="display:none">
            <div class="dash-card-hdr">Posts on <span id="social-day-label"></span></div>
            <div class="dash-card-body" id="social-day-posts" style="max-height:200px;overflow-y:auto"></div>
          </div>
        </div>
        <div>
          <div class="dash-card">
            <div class="dash-card-hdr">Recent &amp; Scheduled <span>${recentPosts.length}</span></div>
            <div class="dash-card-body" style="max-height:480px;overflow-y:auto;padding:8px 14px">
              ${recentPosts.length===0
                ? `<div style="text-align:center;padding:30px 0;color:var(--t3);font-size:12px">No posts yet</div>`
                : recentPosts.map(p=>{
                    const s=p.status||'draft';
                    const pl=(p.platforms||[p.platform]).filter(Boolean);
                    const date=p.scheduled_at?new Date(p.scheduled_at):p.published_at?new Date(p.published_at):null;
                    return `<div class="dash-post-row"><div class="dash-post-pl">${platIco[pl[0]]||''+window.icon("globe",14)+''}</div><div style="flex:1;min-width:0"><div class="dash-post-txt">${p.content||p.caption||'—'}</div><div class="dash-post-meta">${date?date.toLocaleDateString('en-GB',{day:'numeric',month:'short'})+' ':''}<span class="dash-badge ${sCls[s]||'db-draft'}">${s}</span></div></div></div>`;
                  }).join('')}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- POSTS VIEW -->
    <div id="social-view-posts" style="display:none">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div style="display:flex;gap:8px">
          <button class="tab active" data-social-tab="all"       onclick="socialSetTab(this,'all')">All</button>
          <button class="tab"        data-social-tab="scheduled" onclick="socialSetTab(this,'scheduled')">${window.icon('calendar',14)} Scheduled</button>
          <button class="tab"        data-social-tab="published" onclick="socialSetTab(this,'published')">${window.icon('check',14)} Published</button>
          <button class="tab"        data-social-tab="draft"     onclick="socialSetTab(this,'draft')">${window.icon('edit',14)} Drafts</button>
        </div>
        <button class="btn btn-primary btn-sm" onclick="socialNewPost()">+ New Post</button>
      </div>
      ${P.length===0
        ? `<div class="card card-body" style="text-align:center;padding:60px 20px"><div style="font-size:40px;margin-bottom:14px">${window.icon('message',14)}</div><h3>No posts yet</h3><button class="btn btn-primary" onclick="socialNewPost()" style="margin:16px auto 0">+ Create First Post</button></div>`
        : `<div class="card"><div class="table-wrap"><table id="social-tbl">
            <thead><tr><th>Content</th><th>Platforms</th><th>Status</th><th>Scheduled</th><th></th></tr></thead>
            <tbody>${P.filter(p=>p.status!=='deleted').map(p=>{
              const s=p.status||'draft';
              return `<tr data-status="${s}">
                <td style="max-width:260px"><div style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${p.content||p.caption||'—'}</div></td>
                <td>${(p.platforms||[p.platform]).filter(Boolean).map(pl=>`<span style="font-size:16px">${platIco[pl]||''+window.icon("globe",14)+''}</span>`).join('')||'—'}</td>
                <td><span class="badge ${sCls[s]||'badge-grey'}">${s}</span></td>
                <td style="font-size:12px;color:var(--t3)">${p.scheduled_at?new Date(p.scheduled_at).toLocaleString():p.published_at?new Date(p.published_at).toLocaleDateString():'—'}</td>
                <td><div style="display:flex;gap:4px">
                  ${(s==='draft'||s==='scheduled')?`<button class="btn btn-primary btn-sm" onclick="socialPublishPost(${p.id})" style="font-size:11px">▶ Publish</button>`:''}
                  <button class="btn btn-outline btn-sm" onclick="socialEditPost(${p.id})" style="font-size:11px">${window.icon('edit',14)}</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--rd);font-size:11px" onclick="socialDeletePost(${p.id},'${(p.content||'').slice(0,30).replace(/'/g,'')}')">${window.icon('delete',14)}</button>
                </div></td>
              </tr>`;}).join('')}</tbody>
          </table></div></div>`}
    </div>

    <!-- QUEUE VIEW -->
    <div id="social-view-queue" style="display:none">
      ${P.filter(p=>p.status==='scheduled').length===0
        ? `<div class="card card-body" style="text-align:center;padding:60px 20px"><div style="font-size:40px;margin-bottom:14px">${window.icon('calendar',14)}</div><h3>Queue is empty</h3><p style="color:var(--t3);font-size:13px;margin:0 0 16px">Schedule posts to fill your queue.</p><button class="btn btn-primary btn-sm" onclick="socialNewPost()">+ Schedule a Post</button></div>`
        : `<div class="card"><div class="card-header"><h3>Scheduled Queue</h3></div><div class="table-wrap"><table>
            <thead><tr><th>Content</th><th>Platforms</th><th>Scheduled For</th><th></th></tr></thead>
            <tbody>${P.filter(p=>p.status==='scheduled').sort((a,b)=>new Date(a.scheduled_at)-new Date(b.scheduled_at)).map(p=>`<tr>
              <td style="max-width:300px"><div style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${p.content||p.caption||'—'}</div></td>
              <td>${(p.platforms||[p.platform]).filter(Boolean).map(pl=>`<span style="font-size:16px">${platIco[pl]||''+window.icon("globe",14)+''}</span>`).join('')||'—'}</td>
              <td style="font-size:12px">${p.scheduled_at?new Date(p.scheduled_at).toLocaleString():'—'}</td>
              <td><div style="display:flex;gap:4px">
                <button class="btn btn-primary btn-sm" onclick="socialPublishPost(${p.id})" style="font-size:11px">▶ Publish Now</button>
                <button class="btn btn-outline btn-sm" onclick="socialEditPost(${p.id})" style="font-size:11px">${window.icon('edit',14)}</button>
                <button class="btn btn-ghost btn-sm" style="color:var(--rd);font-size:11px" onclick="socialDeletePost(${p.id},'')">${window.icon('delete',14)}</button>
              </div></td>
            </tr>`).join('')}</tbody>
          </table></div></div>`}
    </div>

    <!-- ACCOUNTS VIEW (v5.5.3 — OAuth, zero exposed credentials) -->
    <div id="social-view-accounts" style="display:none">
      <div style="display:flex;flex-direction:column;gap:14px">
        ${_svRenderPlatformCards(A)}
        <div class="card">
          <div class="card-header"><h3>All Connected Accounts</h3></div>
          <div class="card-body">
            ${A.length===0
              ? `<div style="text-align:center;padding:30px 20px;color:var(--t3)"><p style="font-size:13px;margin:0">No accounts connected yet. Click "Connect" above to link your first platform.</p></div>`
              : `<div style="display:flex;flex-direction:column;gap:10px">${A.map(a=>`<div style="display:flex;align-items:center;gap:14px;padding:12px 14px;border:1px solid var(--border);border-radius:var(--radius)"><span style="font-size:22px">${platIco[a.platform]||window.icon("globe",14)}</span><div style="flex:1;min-width:0"><strong style="color:var(--t1)">${_svEsc(a.account_name||a.name||a.platform)}</strong><div style="font-size:11px;color:var(--t3);text-transform:capitalize">${_svEsc(a.platform)} · ${a.status||'active'}${a.created_at?' · connected '+_svTimeAgo(a.created_at):''}</div></div><span class="badge badge-green">Active</span><button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="luSocialDisconnect(${a.id})">✕ Disconnect</button></div>`).join('')}</div>`}
          </div>
        </div>
      </div>
    </div>
  </div>`;

  _socialBuildCalendar(_soc.posts);

  window.socialSetTab = (btn, tab) => {
    document.querySelectorAll('[data-social-tab]').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#social-tbl tbody tr').forEach(r=>{
      r.style.display=(tab==='all'||r.dataset.status===tab)?'':'none';
    });
  };

  window.socialSetView = (view, btn) => {
    ['dashboard','posts','queue','accounts'].forEach(v=>{
      const e=document.getElementById('social-view-'+v);
      if(e) e.style.display=v===view?'':'none';
    });
    document.querySelectorAll('[data-sv]').forEach(b=>{
      const active=b.dataset.sv===view;
      b.style.borderBottomColor=active?'var(--da)':'transparent';
      b.style.color=active?'var(--da)':'var(--t3)';
      b.style.fontWeight=active?'600':'400';
    });
    if (view==='accounts') { setTimeout(window.luFacebookInit,100); }
  };

  // Facebook/Instagram init
  window.luFacebookInit = async () => {
    const nonce=window.LU_CFG?.nonce||'';
    const headers={'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')};
    const fbBadge=document.getElementById('fb-status-badge');
    const fbRedir=document.getElementById('fb-redirect-uri');
    if (!fbBadge) return;
    if (fbRedir) fbRedir.textContent=window.location.origin+'/api/social/oauth/facebook/callback';

    try {
      const accs=await fetch(window.location.origin+'/api/social/accounts',{headers,cache:'no-store'}).then(r=>r.json());
      const list=Array.isArray(accs)?accs:[];
      const fb=list.find(a=>a.platform==='facebook');
      const ig=list.find(a=>a.platform==='instagram');
      fbBadge.innerHTML=fb?'<span style="color:#00b894">'+window.icon("check",14)+' Connected — '+fb.account_name+'</span>':'<span style="color:var(--t3)">'+window.icon("edit",14)+' Not Connected</span>';
      const igB=document.getElementById('ig-status-badge');
      if(igB) igB.innerHTML=ig?'<span style="color:#00b894">'+window.icon("check",14)+' Connected — '+ig.account_name+'</span>':'<span style="color:var(--t3)">'+window.icon("edit",14)+' Not Connected</span>';
      const fbInfo=document.getElementById('fb-connected-info');
      if(fbInfo&&fb){fbInfo.style.display='block';fbInfo.innerHTML='<strong>'+window.icon("info",14)+' '+fb.account_name+'</strong>';}
    } catch(e) { if(fbBadge)fbBadge.innerHTML='<span style="color:var(--t3)">Could not load</span>'; }

    // v5.5.3 — Legacy credential-save handlers removed (FB/LI App ID + Secret).
    // Connect flow now uses popup-based _svConnectPlatform() defined below.
    _svInitAccountsTab();
  };
}

// ═══════════════════════════════════════════════════════════════════
// POST CRUD — all direct REST calls (Patch 4)
// ═══════════════════════════════════════════════════════════════════
window.socialNewPost = function() {
  const platIco={facebook:''+window.icon("info",14)+'',instagram:''+window.icon("image",14)+'',twitter:''+window.icon("message",14)+'',x:''+window.icon("message",14)+'',linkedin:''+window.icon("more",14)+'',tiktok:''+window.icon("more",14)+''};
  const platforms=['facebook','instagram','linkedin','twitter'];
  const checkboxes=platforms.map(p=>`<label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm)"><input type="checkbox" class="sp-pl" value="${p}"> ${platIco[p]||''} ${p[0].toUpperCase()+p.slice(1)}</label>`).join('');
  const bd=document.createElement('div');bd.className='modal-backdrop';bd.onclick=e=>{if(e.target===bd)bd.remove();};
  bd.innerHTML=`<div class="modal" style="max-width:500px">
    <div class="modal-header"><h3>New Post</h3><button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Content *</label><textarea class="form-input" id="sp-c" style="min-height:90px;resize:vertical" placeholder="What do you want to share?"></textarea></div>
      <div class="form-group"><label class="form-label">Platforms</label><div style="display:flex;flex-wrap:wrap;gap:8px">${checkboxes}</div></div>
      <div class="form-group"><label class="form-label">Schedule (optional — leave blank to publish now)</label><input type="datetime-local" class="form-input" id="sp-s"></div>
      <div class="form-group"><label class="form-label">Image URL <span style="font-size:10px;opacity:.5">(optional)</span></label>
        <div style="display:flex;gap:6px"><input class="form-input" id="sp-img" placeholder="Paste URL\u2026" style="flex:1"><button type="button" class="btn btn-primary" style="padding:0 14px;font-size:12px;white-space:nowrap" onclick="_spPickImage()">\uD83D\uDCF7 Library</button></div></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="this.closest('.modal-backdrop').remove()">Cancel</button>
      <button class="btn btn-outline" id="sp-draft-btn">Save Draft</button>
      <button class="btn btn-primary" id="sp-post-btn">+ Post Now</button>
    </div></div>`;
  document.body.appendChild(bd);bd.style.opacity='1';bd.style.pointerEvents='all';

  const doSave = async function(postImmediately) {
    const content=bd.querySelector('#sp-c').value.trim();
    if(!content){showToast('Enter post content.','error');return;}
    const pls=Array.from(bd.querySelectorAll('.sp-pl:checked')).map(x=>x.value);
    const sched=bd.querySelector('#sp-s').value;
    const media_url=bd.querySelector('#sp-img').value.trim();
    const status=sched?'scheduled':(postImmediately?'published':'draft');
    const btn2=postImmediately?bd.querySelector('#sp-post-btn'):bd.querySelector('#sp-draft-btn');
    btn2.disabled=true;btn2.textContent='Saving…';
    try {
      // PATCH 10 Fix 7 — DB column `social_posts.platform` is varchar(30) singular,
      // not an array. Send first platform as canonical; keep `platforms` for any
      // backend that may expand to multi-platform fan-out later.
      const primaryPlatform = pls[0] || 'facebook';
      const created=await _socApi('POST','/social/posts',{
        content,
        platform:  primaryPlatform,
        platforms: pls,
        status:'draft',
        scheduled_at:sched||null,
        media_url:media_url||null,
      });
      const postId=created.id||created.post?.id;

      // If publishing immediately, call publish endpoint
      let publishResult = null;
      let publishedOk = false;
      if(postImmediately&&!sched&&postId){
        try {
          publishResult = await _socApi('POST','/social/posts/'+postId+'/publish');
          // PATCH 10 Fix 7 — honest publish status. Backend SocialConnector::publish
          // is currently a stub (SOCIAL_CONNECTOR_URL empty in env), so server-side
          // publishPost flips status='failed' and returns published:false. Do NOT
          // show a green "Published!" toast in that case.
          publishedOk = !!(publishResult && (publishResult.published === true || publishResult.success === true));
        }
        catch(e){ showToast('Post created but publish failed: '+e.message,'error'); }
      }
      _soc.posts.unshift(created.post||created);
      bd.remove();
      if (status==='draft') {
        showToast('Draft saved.','success');
      } else if (postImmediately && !sched) {
        if (publishedOk) {
          showToast('Post published.','success');
        } else {
          showToast('Post saved as draft — connect a social account to publish live.','info');
        }
      } else if (sched) {
        showToast('Post scheduled.','success');
      } else {
        showToast('Post created.','success');
      }
      socialLoad(document.getElementById('social-root'));
    } catch(e) {
      showToast(e.message,'error');
      btn2.disabled=false;btn2.textContent=status==='draft'?'Save Draft':'+ Post Now';
    }
  };
  bd.querySelector('#sp-draft-btn').onclick=()=>doSave(false);
  bd.querySelector('#sp-post-btn').onclick=()=>doSave(true);
};

window.socialPublishPost = async function(postId) {
  if (!postId) { showToast('Invalid post ID.','error'); return; }
  if (window._socialPubInFlight===postId) return;
  window._socialPubInFlight=postId;
  showToast('Publishing post…','info');
  try {
    // PATCH 10 Fix 7 — honest publish status. Backend SocialConnector::publish
    // is a stub today (empty SOCIAL_CONNECTOR_URL); server-side publishPost
    // flips status='failed' and returns {published:false}. UI must check the
    // real result, not show "Published!" unconditionally.
    var resp = await _socApi('POST', '/social/posts/'+postId+'/publish');
    var ok = !!(resp && (resp.published === true || resp.success === true));
    if (ok) {
      showToast('Post published.','success');
    } else {
      var why = (resp && (resp.error || resp.message)) || 'social connector not configured';
      showToast('Publish failed (' + why + '). Saved as draft — connect a social account to retry.','info');
    }
    socialLoad(document.getElementById('social-root'));
  } catch(e) { showToast('Publish failed: '+e.message,'error'); }
  finally { window._socialPubInFlight=null; }
};

window.socialEditPost = async function(postId) {
  if (!postId) return;
  let post=_soc.posts.find(p=>p.id===postId)||{};
  if (!post.id) {
    try { post=await _socApi('GET','/social/posts/'+postId); }
    catch(e) { showToast('Load failed: '+e.message,'error'); return; }
  }
  const platIco={facebook:''+window.icon("info",14)+'',instagram:''+window.icon("image",14)+'',twitter:''+window.icon("message",14)+'',x:''+window.icon("message",14)+'',linkedin:''+window.icon("more",14)+'',tiktok:''+window.icon("more",14)+''};
  const platforms=['facebook','instagram','linkedin','twitter'];
  const existingPls=Array.isArray(post.platforms)?post.platforms:(post.platform?[post.platform]:[]);
  const checkboxes=platforms.map(p=>`<label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm)"><input type="checkbox" class="sep-pl" value="${p}"${existingPls.includes(p)?' checked':''}> ${platIco[p]||''} ${p[0].toUpperCase()+p.slice(1)}</label>`).join('');
  const bd=document.createElement('div');bd.className='modal-backdrop';bd.onclick=e=>{if(e.target===bd)bd.remove();};
  bd.innerHTML=`<div class="modal" style="max-width:500px">
    <div class="modal-header"><h3>Edit Post</h3><button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Content *</label><textarea class="form-input" id="sep-c" style="min-height:90px;resize:vertical">${(post.content||post.caption||'').replace(/</g,'&lt;')}</textarea></div>
      <div class="form-group"><label class="form-label">Platforms</label><div style="display:flex;flex-wrap:wrap;gap:8px">${checkboxes}</div></div>
      <div class="form-group"><label class="form-label">Schedule</label><input type="datetime-local" class="form-input" id="sep-s" value="${post.scheduled_at?post.scheduled_at.replace(' ','T').slice(0,16):''}"></div>
      <div class="form-group"><label class="form-label">Status</label>
        <select class="form-select" id="sep-st">
          <option value="draft"${post.status==='draft'?' selected':''}>Draft</option>
          <option value="scheduled"${post.status==='scheduled'?' selected':''}>Scheduled</option>
        </select></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="this.closest('.modal-backdrop').remove()">Cancel</button>
      <button class="btn btn-primary" id="sep-save">${window.icon('save',14)} Save Changes</button>
    </div></div>`;
  document.body.appendChild(bd);bd.style.opacity='1';bd.style.pointerEvents='all';

  bd.querySelector('#sep-save').onclick=async()=>{
    const content=bd.querySelector('#sep-c').value.trim();
    if(!content){showToast('Enter content.','error');return;}
    const pls=Array.from(bd.querySelectorAll('.sep-pl:checked')).map(x=>x.value);
    const sched=bd.querySelector('#sep-s').value;
    const status=bd.querySelector('#sep-st').value;
    const btn2=bd.querySelector('#sep-save');btn2.disabled=true;btn2.textContent='Saving…';
    try {
      const updated=await _socApi('PUT','/social/posts/'+postId,{content,platforms:pls,scheduled_at:sched||null,status});
      const idx=_soc.posts.findIndex(p=>p.id===postId);
      if(idx>=0) _soc.posts[idx]=Object.assign(_soc.posts[idx],updated.post||updated);
      bd.remove();showToast('Post updated!','success');
      socialLoad(document.getElementById('social-root'));
    } catch(e) { showToast(e.message,'error');btn2.disabled=false;btn2.textContent=''+window.icon("save",14)+' Save Changes'; }
  };
};

window.socialDeletePost = async function(postId, preview) {
  if (!postId) return;
  const ok=await luConfirm('Delete post'+(preview?': "'+preview+'"':'')+' permanently?','Delete Post','Delete','Keep');
  if(!ok) return;
  showToast('Deleting…','info');
  try {
    await _socApi('DELETE', '/social/posts/'+postId);
    _soc.posts=_soc.posts.filter(p=>p.id!==postId);
    showToast('Post deleted.','success');
    socialLoad(document.getElementById('social-root'));
  } catch(e) { showToast('Delete failed: '+e.message,'error'); }
};

window.luSocialDisconnect = async function(accountId) {
  const ok=await luConfirm('Disconnect this social account?','Disconnect','Disconnect','Keep');
  if(!ok) return;
  try {
    await _socApi('DELETE', '/social/accounts/'+accountId);
    showToast('Account disconnected.','success');
    socialLoad(document.getElementById('social-root'));
  } catch(e) { showToast('Disconnect failed: '+e.message,'error'); }
};

window.socialGenerateCaption = async function() {
  const topic=await luPrompt('What is the post about?','','Generate Caption (AI)');
  if(!topic) return;
  showToast('Generating caption…','info');
  try {
    const result=await LuAPI.exec('generate_social_caption',{topic},{skipApproval:true});
    const caption=result?.caption||result?.content||result?.text||JSON.stringify(result);
    const bd=document.createElement('div');bd.className='modal-backdrop';bd.onclick=e=>{if(e.target===bd)bd.remove();};
    bd.innerHTML=`<div class="modal" style="max-width:460px"><div class="modal-header"><h3>Generated Caption</h3><button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button></div><div class="modal-body"><textarea class="form-input" style="min-height:120px" id="gen-cap-ta">${caption}</textarea></div><div class="modal-footer"><button class="btn btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('gen-cap-ta').value);showToast('Copied!','success')">${window.icon('more',14)} Copy</button><button class="btn btn-primary" onclick="this.closest('.modal-backdrop').remove()">Use in Post</button></div></div>`;
    document.body.appendChild(bd);bd.style.opacity='1';bd.style.pointerEvents='all';
  } catch(e) { showToast('Failed: '+e.message,'error'); }
};

// ═══════════════════════════════════════════════════════════════════
// CALENDAR WIDGET
// ═══════════════════════════════════════════════════════════════════
function _socialBuildCalendar(posts) {
  const calEl=document.getElementById('social-cal');
  if(!calEl) return;
  let year=new Date().getFullYear(), month=new Date().getMonth();
  function getPostDays(y,m,posts){const days={};posts.forEach(p=>{const d=p.scheduled_at?new Date(p.scheduled_at):p.published_at?new Date(p.published_at):null;if(d&&d.getFullYear()===y&&d.getMonth()===m){const k=d.getDate();if(!days[k])days[k]=[];days[k].push(p);}});return days;}
  function render(y,m){
    const pd=getPostDays(y,m,posts);
    const today=new Date(),fd=new Date(y,m,1).getDay(),dim=new Date(y,m+1,0).getDate();
    const mn=['January','February','March','April','May','June','July','August','September','October','November','December'];
    let html=`<div class="cal-nav"><button class="cal-nav-btn" onclick="window._socialCalPrev()">‹</button><div class="cal-month">${mn[m]} ${y}</div><button class="cal-nav-btn" onclick="window._socialCalNext()">›</button></div><div class="cal-grid">`;
    ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(d=>html+=`<div class="cal-dow">${d}</div>`);
    for(let i=0;i<fd;i++) html+=`<div class="cal-day empty other-month"></div>`;
    for(let d=1;d<=dim;d++){
      const iT=today.getFullYear()===y&&today.getMonth()===m&&today.getDate()===d;
      html+=`<div class="cal-day${iT?' today':''}${pd[d]?' has-post':''}" onclick="window._socialCalDay(${y},${m},${d})" title="${pd[d]?pd[d].length+' post(s)':''}">${d}</div>`;
    }
    html+=`</div>`;
    calEl.innerHTML=html;calEl._y=y;calEl._m=m;calEl._posts=posts;
  }
  render(year,month);
  window._socialCalPrev=()=>{let y=calEl._y,m=calEl._m-1;if(m<0){m=11;y--;}render(y,m);};
  window._socialCalNext=()=>{let y=calEl._y,m=calEl._m+1;if(m>11){m=0;y++;}render(y,m);};
  window._socialCalDay=(y,m,d)=>{
    const pd=getPostDays(y,m,calEl._posts||[]);
    const panel=document.getElementById('social-day-panel');
    const postsEl=document.getElementById('social-day-posts');
    const lbl=document.getElementById('social-day-label');
    if(!panel||!postsEl) return;
    const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    if(lbl) lbl.textContent=months[m]+' '+d+', '+y;
    panel.style.display='block';
    const dayPosts=pd[d]||[];
    postsEl.innerHTML=dayPosts.length===0
      ? `<div style="text-align:center;padding:16px;color:var(--t3);font-size:12px">No posts this day. <a href="#" onclick="socialNewPost();return false" style="color:var(--da)">Create one →</a></div>`
      : dayPosts.map(p=>{const s=p.status||'draft';const sCls={published:'db-pub',scheduled:'db-sched',draft:'db-draft'};return`<div class="dash-post-row"><div style="flex:1;min-width:0"><div class="dash-post-txt">${p.content||p.caption||'—'}</div><div class="dash-post-meta"><span class="dash-badge ${sCls[s]||'db-draft'}">${s}</span></div></div><button class="btn btn-ghost btn-sm" onclick="socialEditPost(${p.id})">${window.icon('edit',14)}</button></div>`;}).join('');
  };
}

// Handle OAuth callback params
(function(){
  const params=new URLSearchParams(window.location.search);
  if(params.get('linkedin_connected')==='1'){showToast(''+window.icon("check",14)+' LinkedIn connected!','success');history.replaceState({},'',window.location.pathname+window.location.hash);}
  if(params.get('linkedin_error')){showToast('LinkedIn error: '+params.get('linkedin_error'),'error');history.replaceState({},'',window.location.pathname+window.location.hash);}
})();

console.log('[LevelUp] social engine v2.1.0 loaded');

// ── SV-ACCOUNTS-5.5.3 — popup OAuth, zero exposed credentials ──────────────
/* SV-ACCOUNTS-5.5.3 */

window._svEsc = function(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); };

window._svTimeAgo = function(ts){
  if(!ts) return '';
  var t = new Date(ts).getTime(); if(isNaN(t)) return '';
  var s = Math.floor((Date.now() - t)/1000);
  if(s < 60) return s+' sec ago';
  var m = Math.floor(s/60); if(m < 60) return m+' min ago';
  var h = Math.floor(m/60); if(h < 24) return h+' hour'+(h===1?'':'s')+' ago';
  var d = Math.floor(h/24); if(d < 30) return d+' day'+(d===1?'':'s')+' ago';
  return new Date(ts).toLocaleDateString();
};

window._svRenderPlatformCards = function(accounts){
  var A = accounts || [];
  var byPlat = {};
  A.forEach(function(a){ (byPlat[a.platform] = byPlat[a.platform] || []).push(a); });
  var fb = (byPlat.facebook  || [])[0];
  var ig = (byPlat.instagram || [])[0];
  var li = (byPlat.linkedin  || [])[0];

  return [
    _svPlatformCard({
      platform: 'facebook', label: 'Facebook',
      icon: window.icon('info',14), ready: true,
      blurb: 'Connect your Facebook Page so Marcus can publish and schedule posts.',
      connectLabel: 'Connect Facebook Page →',
      account: fb,
    }),
    _svPlatformCard({
      platform: 'instagram', label: 'Instagram',
      icon: window.icon('image',14), ready: true,
      requires: { platform: 'facebook', connected: !!fb, message: 'Instagram publishing runs through a linked Facebook Page. Connect Facebook first.' },
      blurb: 'Connect your Instagram Business account (via Facebook) for reels and feed posts.',
      connectLabel: 'Connect Instagram →',
      account: ig,
    }),
    _svPlatformCard({
      platform: 'linkedin', label: 'LinkedIn',
      icon: window.icon('more',14), ready: false, setupRequired: true,
      blurb: 'LinkedIn publishing is not yet enabled on this workspace. Contact support to activate it.',
      account: li,
    }),
    _svPlatformCard({
      platform: 'tiktok', label: 'TikTok',
      icon: window.icon('more',14), ready: false, comingSoon: true,
      blurb: 'TikTok publishing is coming soon. Aria can still generate TikTok-ready scripts and content you can post manually.',
    }),
  ].join('');
};

window._svPlatformCard = function(cfg){
  var connected = !!cfg.account;
  var blockedByReq = cfg.requires && !cfg.requires.connected;
  var stateBadge, bodyHtml;

  if (connected) {
    stateBadge = '<span style="font-size:12px;font-weight:700;color:var(--ac)">✓ Connected</span>';
    var a = cfg.account;
    bodyHtml =
      '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">' +
        (a.avatar_url ? '<img src="'+_svEsc(a.avatar_url)+'" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover">' : '<span style="width:36px;height:36px;border-radius:50%;background:var(--s2);display:inline-flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:var(--t2)">'+_svEsc((a.account_name||cfg.label).charAt(0))+'</span>') +
        '<div style="flex:1;min-width:0">' +
          '<div style="font-weight:700;color:var(--t1);font-size:14px">'+_svEsc(a.account_name||cfg.label)+'</div>' +
          '<div style="font-size:11px;color:var(--t3)">'+_svEsc(cfg.platform)+' · '+_svEsc(a.status||'active')+(a.created_at?' · connected '+_svTimeAgo(a.created_at):'')+'</div>' +
        '</div>' +
      '</div>' +
      '<div style="display:flex;gap:8px;justify-content:flex-end">' +
        '<button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="luSocialDisconnect('+a.id+')">Disconnect</button>' +
      '</div>';
  } else if (cfg.comingSoon) {
    stateBadge = '<span style="font-size:10px;font-weight:700;color:var(--am);background:rgba(245,158,11,.15);padding:3px 10px;border-radius:10px;text-transform:uppercase;letter-spacing:.04em">Coming Soon</span>';
    bodyHtml = '<p style="color:var(--t2);font-size:13px;margin:0;line-height:1.5">'+_svEsc(cfg.blurb)+'</p>';
  } else if (cfg.setupRequired) {
    stateBadge = '<span style="font-size:11px;font-weight:700;color:var(--am)">Setup required</span>';
    bodyHtml =
      '<p style="color:var(--t2);font-size:13px;margin:0 0 12px;line-height:1.5">'+_svEsc(cfg.blurb)+'</p>' +
      '<button class="btn btn-outline btn-sm" onclick="window.open(\'mailto:support@levelupgrowth.io?subject=Enable%20LinkedIn%20publishing\', \'_blank\')">Contact support →</button>';
  } else if (blockedByReq) {
    stateBadge = '<span style="font-size:11px;font-weight:700;color:var(--t3)">Connect '+_svEsc(cfg.requires.platform)+' first</span>';
    bodyHtml =
      '<p style="color:var(--t2);font-size:13px;margin:0 0 12px;line-height:1.5">'+_svEsc(cfg.requires.message || cfg.blurb)+'</p>' +
      '<button class="btn btn-primary btn-sm" disabled style="opacity:.5;cursor:not-allowed">'+_svEsc(cfg.connectLabel || 'Connect')+'</button>';
  } else {
    stateBadge = '<span id="sv-state-'+cfg.platform+'" style="font-size:11px;font-weight:700;color:var(--t3)">Not connected</span>';
    bodyHtml =
      '<p style="color:var(--t2);font-size:13px;margin:0 0 14px;line-height:1.5">'+_svEsc(cfg.blurb)+'</p>' +
      '<button class="btn btn-primary btn-sm" id="sv-connect-'+cfg.platform+'" onclick="_svConnectPlatform(\''+cfg.platform+'\')">'+(cfg.icon||'')+' '+_svEsc(cfg.connectLabel||'Connect')+'</button>' +
      '<div id="sv-connecting-'+cfg.platform+'" style="display:none;margin-top:10px;padding:8px 10px;background:var(--s1);border-radius:6px;font-size:12px;color:var(--t3)"><span class="spinner" style="display:inline-block;width:10px;height:10px;border:2px solid var(--t3);border-top-color:var(--ac);border-radius:50%;animation:spin 600ms linear infinite;vertical-align:-2px;margin-right:6px"></span>Opening authorisation window… please allow popups for this site.</div>';
  }

  return '<div class="card"><div class="card-header" style="display:flex;align-items:center;justify-content:space-between"><h3>'+(cfg.icon||'')+' '+_svEsc(cfg.label)+'</h3>'+stateBadge+'</div><div class="card-body" style="padding:20px;max-width:560px">'+bodyHtml+'</div></div>';
};

window._svConnectPlatform = async function(platform){
  var btn = document.getElementById('sv-connect-'+platform);
  var spinner = document.getElementById('sv-connecting-'+platform);
  var state = document.getElementById('sv-state-'+platform);
  if (btn) btn.disabled = true;
  if (spinner) spinner.style.display = 'block';
  if (state) state.textContent = 'Connecting…';

  var redirectUrl = null;
  try {
    var r = await _socApi('GET', '/social/oauth/'+platform+'/connect');
    if (r && r.redirect_url) redirectUrl = r.redirect_url;
    else throw new Error(r && r.error ? r.error : 'Failed to get authorisation URL');
  } catch (e) {
    if (btn) btn.disabled = false;
    if (spinner) spinner.style.display = 'none';
    if (state) state.textContent = 'Not connected';
    showToast('Could not start '+platform+' connection: '+(e.message||'unknown'), 'error');
    return;
  }

  var w = 600, h = 700;
  var left = Math.max(0, (screen.width/2) - (w/2));
  var top  = Math.max(0, (screen.height/2) - (h/2));
  var popup = window.open(redirectUrl, 'lu_social_connect', 'width='+w+',height='+h+',left='+left+',top='+top+',scrollbars=yes,resizable=yes');

  if (!popup || popup.closed || typeof popup.closed === 'undefined') {
    if (btn) btn.disabled = false;
    if (spinner) spinner.style.display = 'none';
    if (state) state.textContent = 'Not connected';
    showToast('Popup blocked. Please allow popups for this site and try again.', 'warning');
    return;
  }

  function handler(e){
    if (!e.data || !e.data.type) return;
    if (e.data.type !== 'social_connected' && e.data.type !== 'social_error') return;
    window.removeEventListener('message', handler);
    try { popup.close(); } catch(_) {}
    if (btn) btn.disabled = false;
    if (spinner) spinner.style.display = 'none';
    if (e.data.type === 'social_connected') {
      showToast('Connected — '+(e.data.account_name||platform)+' is ready.', 'success');
      socialLoad(document.getElementById('social-root'));
    } else {
      if (state) state.textContent = 'Not connected';
      showToast('Connection failed: '+(e.data.message||'unknown'), 'error');
    }
  }
  window.addEventListener('message', handler);

  var pollClose = setInterval(function(){
    if (!popup || popup.closed) {
      clearInterval(pollClose);
      window.removeEventListener('message', handler);
      if (btn && btn.disabled) {
        btn.disabled = false;
        if (spinner) spinner.style.display = 'none';
        if (state) state.textContent = 'Not connected';
      }
    }
  }, 500);
};

window._svInitAccountsTab = function(){ /* no-op — bindings inline in rendered HTML */ };

