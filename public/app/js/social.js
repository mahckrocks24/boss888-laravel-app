// LevelUp SOCIAL Engine — v3.0.0 (2026-08-29, RISK-0099 re-inclusion)
// Rebuilt from the v2.1.0 file deleted at 8c7cc1e, against the CURRENT API contract:
//   - writes go through the execution pipeline → {success, data:{post_id,status}} | {pending_approval}
//   - AI generate / hashtags are real (POST /social/ai/generate, /social/ai/hashtags)
//   - PUT /social/posts/{id} exists now (edit / reschedule)
//   - publish failures are TRUTHFUL: the server refuses to mark a post published without a
//     confirmed external id; the UI shows the server's reason, never a green toast on a stub.

window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['social'] = true;

window._spPickImage = function() {
  if (typeof window.openMediaPicker !== 'function') { showToast('Media picker is not loaded — paste an image URL instead.', 'warning'); return; }
  window.openMediaPicker({ type:'image', context:'social', multiple:false }, function(file) {
    if (!file || !file.url) return;
    var inp = document.getElementById('sp-img');
    if (inp) { inp.value = file.url; inp.dispatchEvent(new Event('input', { bubbles:true })); }
  });
};

var _soc = { posts: [], accounts: [], filters: { platform: '', status: '' }, loading: false };
var _SOC_PLATFORMS = ['facebook', 'instagram', 'linkedin', 'twitter'];
var _SOC_LABEL = { facebook:'Facebook', instagram:'Instagram', linkedin:'LinkedIn', twitter:'X / Twitter', x:'X / Twitter', tiktok:'TikTok', youtube:'YouTube' };

function _socEsc(s) { if (s == null) return ''; return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function _socPlat(p) { return _socEsc(_SOC_LABEL[p] || (p ? p[0].toUpperCase() + p.slice(1) : '—')); }
function _socTags(p) { var t = p.hashtags_json || p.hashtags; if (typeof t === 'string') { try { t = JSON.parse(t); } catch (e) { t = []; } } return Array.isArray(t) ? t : []; }

// ── API ────────────────────────────────────────────────────────────────────
async function _socApi(method, path, body) {
  var opts = { method: method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') }, cache: 'no-store' };
  if (body) opts.body = JSON.stringify(body);
  var r;
  try { r = await fetch(window.location.origin + '/api' + path, opts); }
  catch (e) { throw new Error('Network error: ' + e.message); }
  if (r.status === 401) throw Object.assign(new Error('Session expired — please refresh.'), { code: 401 });
  if (r.status === 402) throw Object.assign(new Error('Not enough credits for this action.'), { code: 402 });
  if (r.status === 429) throw Object.assign(new Error('Rate limited — please wait a moment.'), { code: 429 });
  var d = await r.json().catch(function(){ return {}; });
  if (!r.ok) throw new Error(d.message || d.error || ('Error ' + r.status));
  return d;
}
/* Unwrap an execution-pipeline envelope. Throws a truthful error when the server refused. */
function _socUnwrap(d) {
  if (d && d.pending_approval) return { pending: true, approval_id: d.approval_id, message: d.message || 'Sent to your approval queue.' };
  if (d && d.success === false) throw new Error(d.error || d.message || 'The server refused this action.');
  return (d && d.data !== undefined) ? d.data : d;
}

// ── LOAD ───────────────────────────────────────────────────────────────────
async function socialLoad(el) {
  if (!el) return;
  if (window.innerWidth < 768) el.style.flexDirection = 'column';
  el.innerHTML = loadingCard(300);
  try {
    var res = await Promise.all([_socApi('GET', '/social/accounts'), _socApi('GET', '/social/posts')]);
    var accounts = res[0], posts = res[1];
    _soc.accounts = Array.isArray(accounts) ? accounts : (accounts && accounts.accounts) || [];
    _soc.posts    = Array.isArray(posts) ? posts : (posts && posts.posts) || [];
    _socRender(el);
  } catch (e) {
    console.error('[Social]', e);
    el.innerHTML = '<div style="padding:60px;text-align:center;color:var(--t2)"><div style="font-size:14px;font-weight:600;margin-bottom:6px">Social failed to load</div><div style="font-size:12px;color:var(--t3)">' + _socEsc(friendlyError(e)) + '</div><button class="btn btn-outline btn-sm" style="margin-top:16px" onclick="socialLoad(document.getElementById(\'social-root\'))">↺ Retry</button></div>';
  }
}

function _socStatusBadge(s) {
  var cls = { published:'db-pub', scheduled:'db-sched', draft:'db-draft', failed:'db-fail', pending_approval:'db-sched' };
  var label = { pending_approval: 'awaiting approval' };
  return '<span class="dash-badge ' + (cls[s] || 'db-draft') + '">' + _socEsc(label[s] || s || 'draft') + '</span>';
}

function _socRender(el) {
  var A = _soc.accounts, P = _soc.posts.filter(function(p){ return p.status !== 'deleted'; });
  var published = P.filter(function(p){ return p.status === 'published'; }).length;
  var scheduled = P.filter(function(p){ return p.status === 'scheduled'; }).length;
  var failed    = P.filter(function(p){ return p.status === 'failed'; }).length;
  var recent = P.slice().sort(function(a,b){ return new Date(b.scheduled_at||b.published_at||b.created_at||0) - new Date(a.scheduled_at||a.published_at||a.created_at||0); }).slice(0, 8);
  var connectedNote = A.length === 0
    ? '<div style="margin-bottom:16px;padding:12px 14px;border:1px solid var(--am);border-radius:var(--rg,10px);background:rgba(245,158,11,.08);font-size:13px;color:var(--t2)"><strong style="color:var(--t1)">No social account is connected yet.</strong> You can write, generate and schedule posts now; publishing for real needs a connected account. <a href="#" onclick="socialSetView(\'accounts\');return false" style="color:var(--da)">Connect one →</a></div>'
    : '';

  el.innerHTML =
  '<div style="max-width:1400px;padding-bottom:32px">' +
    '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">' +
      '<div><h1 style="margin:0 0 3px;font-size:22px">Social</h1><div style="font-size:13px;color:var(--t3)">' + A.length + ' account' + (A.length !== 1 ? 's' : '') + ' connected · ' + P.length + ' post' + (P.length !== 1 ? 's' : '') + '</div></div>' +
      '<div style="display:flex;gap:8px">' +
        '<button class="btn btn-outline btn-sm" onclick="socialLoad(document.getElementById(\'social-root\'))">↺ Refresh</button>' +
        '<button class="btn btn-outline btn-sm" onclick="socialSetView(\'accounts\')">' + window.icon('link',14) + ' Accounts</button>' +
        '<button class="btn btn-outline btn-sm" onclick="socialGenerateWithAI()">' + window.icon('ai',14) + ' Generate with AI</button>' +
        '<button class="btn btn-primary btn-sm" onclick="socialNewPost()">+ New Post</button>' +
      '</div>' +
    '</div>' +
    connectedNote +
    '<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:1px solid var(--bd)">' +
      ['dashboard','posts','queue','accounts'].map(function(v){ return '<button class="dash-view-tab" data-sv="' + v + '" onclick="socialSetView(\'' + v + '\',this)" style="color:' + (v==='dashboard'?'var(--da)':'var(--t3)') + ';font-weight:' + (v==='dashboard'?'600':'400') + ';border-bottom:2px solid ' + (v==='dashboard'?'var(--da)':'transparent') + ';padding:7px 14px;border-top:none;border-left:none;border-right:none;background:none;font-size:13px;cursor:pointer">' + ({dashboard:'Dashboard',posts:'All Posts',queue:'Queue',accounts:'Accounts'})[v] + '</button>'; }).join('') +
    '</div>' +

    '<div id="social-view-dashboard">' +
      '<div class="dash-grid dash-stats" style="margin-bottom:20px">' +
        '<div class="dash-stat"><div class="dash-stat-val">' + published + '</div><div class="dash-stat-lbl">Published</div><div class="dash-stat-sub">confirmed by the platform</div></div>' +
        '<div class="dash-stat"><div class="dash-stat-val">' + scheduled + '</div><div class="dash-stat-lbl">Scheduled</div><div class="dash-stat-sub">upcoming</div></div>' +
        '<div class="dash-stat"><div class="dash-stat-val">' + failed + '</div><div class="dash-stat-lbl">Failed</div><div class="dash-stat-sub">need attention</div></div>' +
        '<div class="dash-stat"><div class="dash-stat-val">' + A.length + '</div><div class="dash-stat-lbl">Accounts</div><div class="dash-stat-sub">connected</div></div>' +
      '</div>' +
      '<div class="dash-grid dash-body" style="margin-bottom:20px">' +
        '<div>' +
          '<div class="dash-card" style="margin-bottom:14px"><div class="dash-card-hdr">Quick Actions</div><div class="dash-card-body">' +
            '<button class="dash-qa-btn" onclick="socialNewPost()"><span class="qa-ico">' + window.icon('edit',14) + '</span>Write a post</button>' +
            '<button class="dash-qa-btn" onclick="socialGenerateWithAI()"><span class="qa-ico">' + window.icon('ai',14) + '</span>Generate a post with AI</button>' +
            '<button class="dash-qa-btn" onclick="socialSetView(\'accounts\')"><span class="qa-ico">' + window.icon('link',14) + '</span>Connect an account</button>' +
          '</div></div>' +
          '<div class="dash-card"><div class="dash-card-hdr">Platforms <span>' + A.length + '</span></div><div class="dash-card-body" style="padding:10px 14px">' +
            (A.length === 0 ? '<div style="font-size:12px;color:var(--t3);text-align:center;padding:12px 0">No accounts connected.<br><a href="#" onclick="socialSetView(\'accounts\');return false" style="color:var(--da)">Connect one →</a></div>'
              : A.map(function(a){ return '<div style="display:flex;align-items:center;gap:8px;padding:6px 0"><div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + _socEsc(a.account_name||a.name||a.platform) + '</div><div style="font-size:10px;color:var(--t3)">' + _socPlat(a.platform) + '</div></div><span class="dash-badge ' + ((a.status||'active')==='active'?'db-pub':'db-fail') + '">' + _socEsc(a.status||'active') + '</span></div>'; }).join('')) +
          '</div></div>' +
        '</div>' +
        '<div>' +
          '<div class="dash-card" style="margin-bottom:14px"><div class="dash-card-hdr">Content Calendar</div><div class="dash-cal" id="social-cal"></div></div>' +
          '<div class="dash-card" id="social-day-panel" style="display:none"><div class="dash-card-hdr">Posts on <span id="social-day-label"></span></div><div class="dash-card-body" id="social-day-posts" style="max-height:200px;overflow-y:auto"></div></div>' +
        '</div>' +
        '<div>' +
          '<div class="dash-card"><div class="dash-card-hdr">Recent &amp; Scheduled <span>' + recent.length + '</span></div><div class="dash-card-body" style="max-height:480px;overflow-y:auto;padding:8px 14px">' +
            (recent.length === 0 ? '<div style="text-align:center;padding:30px 0;color:var(--t3);font-size:12px">No posts yet</div>'
              : recent.map(function(p){ var date = p.scheduled_at ? new Date(p.scheduled_at) : p.published_at ? new Date(p.published_at) : null; return '<div class="dash-post-row" style="cursor:pointer" onclick="socialEditPost(' + p.id + ')"><div class="dash-post-pl" style="font-size:10px;color:var(--t3)">' + _socPlat(p.platform) + '</div><div style="flex:1;min-width:0"><div class="dash-post-txt">' + _socEsc(p.content||'—') + '</div><div class="dash-post-meta">' + (date ? date.toLocaleDateString('en-GB',{day:'numeric',month:'short'}) + ' ' : '') + _socStatusBadge(p.status) + '</div></div></div>'; }).join('')) +
          '</div></div>' +
        '</div>' +
      '</div>' +
    '</div>' +

    '<div id="social-view-posts" style="display:none">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">' +
        '<div style="display:flex;gap:8px">' +
          '<button class="tab active" data-social-tab="all" onclick="socialSetTab(this,\'all\')">All</button>' +
          '<button class="tab" data-social-tab="scheduled" onclick="socialSetTab(this,\'scheduled\')">Scheduled</button>' +
          '<button class="tab" data-social-tab="published" onclick="socialSetTab(this,\'published\')">Published</button>' +
          '<button class="tab" data-social-tab="draft" onclick="socialSetTab(this,\'draft\')">Drafts</button>' +
          '<button class="tab" data-social-tab="failed" onclick="socialSetTab(this,\'failed\')">Failed</button>' +
        '</div>' +
        '<button class="btn btn-primary btn-sm" onclick="socialNewPost()">+ New Post</button>' +
      '</div>' +
      (P.length === 0 ? '<div class="card card-body" style="text-align:center;padding:60px 20px"><h3>No posts yet</h3><p style="color:var(--t3);font-size:13px">Write one yourself or let the AI draft it from a topic.</p><div style="display:flex;gap:8px;justify-content:center;margin-top:16px"><button class="btn btn-outline" onclick="socialGenerateWithAI()">' + window.icon('ai',14) + ' Generate with AI</button><button class="btn btn-primary" onclick="socialNewPost()">+ Create First Post</button></div></div>'
        : '<div class="card"><div class="table-wrap"><table id="social-tbl"><thead><tr><th>Content</th><th>Platform</th><th>Status</th><th>When</th><th></th></tr></thead><tbody>' +
          P.map(function(p){ var s = p.status || 'draft'; return '<tr data-status="' + _socEsc(s) + '">' +
            '<td style="max-width:320px"><div style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + _socEsc(p.content||'—') + '</div>' + (_socTags(p).length ? '<div style="font-size:11px;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + _socEsc(_socTags(p).slice(0,6).join(' ')) + '</div>' : '') + '</td>' +
            '<td style="font-size:12px">' + _socPlat(p.platform) + '</td>' +
            '<td>' + _socStatusBadge(s) + '</td>' +
            '<td style="font-size:12px;color:var(--t3)">' + (p.scheduled_at ? new Date(p.scheduled_at).toLocaleString() : p.published_at ? new Date(p.published_at).toLocaleDateString() : '—') + '</td>' +
            '<td><div style="display:flex;gap:4px">' +
              ((s==='draft'||s==='scheduled'||s==='failed') ? '<button class="btn btn-primary btn-sm" onclick="socialPublishPost(' + p.id + ')" style="font-size:11px">▶ Publish</button>' : '') +
              '<button class="btn btn-outline btn-sm" onclick="socialEditPost(' + p.id + ')" style="font-size:11px">' + window.icon('edit',14) + '</button>' +
              '<button class="btn btn-outline btn-sm" style="color:var(--rd);font-size:11px" onclick="socialDeletePost(' + p.id + ')">' + window.icon('delete',14) + '</button>' +
            '</div></td></tr>'; }).join('') +
          '</tbody></table></div></div>') +
    '</div>' +

    '<div id="social-view-queue" style="display:none">' +
      (scheduled === 0 ? '<div class="card card-body" style="text-align:center;padding:60px 20px"><h3>Queue is empty</h3><p style="color:var(--t3);font-size:13px;margin:0 0 16px">Schedule posts to fill your queue.</p><button class="btn btn-primary btn-sm" onclick="socialNewPost()">+ Schedule a Post</button></div>'
        : '<div class="card"><div class="card-header"><h3>Scheduled Queue</h3></div><div class="table-wrap"><table><thead><tr><th>Content</th><th>Platform</th><th>Scheduled for</th><th></th></tr></thead><tbody>' +
          P.filter(function(p){ return p.status==='scheduled'; }).sort(function(a,b){ return new Date(a.scheduled_at) - new Date(b.scheduled_at); }).map(function(p){ return '<tr><td style="max-width:300px"><div style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + _socEsc(p.content||'—') + '</div></td><td style="font-size:12px">' + _socPlat(p.platform) + '</td><td style="font-size:12px">' + (p.scheduled_at ? new Date(p.scheduled_at).toLocaleString() : '—') + '</td><td><div style="display:flex;gap:4px"><button class="btn btn-primary btn-sm" onclick="socialPublishPost(' + p.id + ')" style="font-size:11px">▶ Publish now</button><button class="btn btn-outline btn-sm" onclick="socialEditPost(' + p.id + ')" style="font-size:11px">' + window.icon('edit',14) + '</button></div></td></tr>'; }).join('') +
          '</tbody></table></div></div>') +
    '</div>' +

    '<div id="social-view-accounts" style="display:none"><div style="display:flex;flex-direction:column;gap:14px">' + _svRenderPlatformCards(A) +
      '<div class="card"><div class="card-header"><h3>All connected accounts</h3></div><div class="card-body">' +
        (A.length === 0 ? '<div style="text-align:center;padding:30px 20px;color:var(--t3)"><p style="font-size:13px;margin:0">No accounts connected yet. Use "Connect" above to link your first platform.</p></div>'
          : '<div style="display:flex;flex-direction:column;gap:10px">' + A.map(function(a){ return '<div style="display:flex;align-items:center;gap:14px;padding:12px 14px;border:1px solid var(--bd);border-radius:var(--rg,10px)"><div style="flex:1;min-width:0"><strong style="color:var(--t1)">' + _socEsc(a.account_name||a.name||a.platform) + '</strong><div style="font-size:11px;color:var(--t3)">' + _socPlat(a.platform) + ' · ' + _socEsc(a.status||'active') + (a.created_at ? ' · connected ' + _svTimeAgo(a.created_at) : '') + '</div></div><button class="btn btn-outline btn-sm" style="color:var(--rd)" onclick="luSocialDisconnect(' + a.id + ')">Disconnect</button></div>'; }).join('') + '</div>') +
      '</div></div>' +
    '</div></div>' +
  '</div>';

  _socialBuildCalendar(P);

  window.socialSetTab = function(btn, tab) {
    document.querySelectorAll('[data-social-tab]').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('#social-tbl tbody tr').forEach(function(r){ r.style.display = (tab==='all' || r.dataset.status===tab) ? '' : 'none'; });
  };
  window.socialSetView = function(view) {
    ['dashboard','posts','queue','accounts'].forEach(function(v){ var e = document.getElementById('social-view-' + v); if (e) e.style.display = v===view ? '' : 'none'; });
    document.querySelectorAll('[data-sv]').forEach(function(b){ var active = b.dataset.sv===view; b.style.borderBottomColor = active ? 'var(--da)' : 'transparent'; b.style.color = active ? 'var(--da)' : 'var(--t3)'; b.style.fontWeight = active ? '600' : '400'; });
  };
}

// ── COMPOSER ───────────────────────────────────────────────────────────────
function _socComposer(opts) {
  opts = opts || {};
  var post = opts.post || null;
  var title = post ? 'Edit post' : 'New post';
  var platform = (post && post.platform) || opts.platform || 'instagram';
  var tags = post ? _socTags(post) : (opts.hashtags || []);
  var sched = post && post.scheduled_at ? String(post.scheduled_at).replace(' ', 'T').slice(0, 16) : '';
  var bd = document.createElement('div'); bd.className = 'modal-backdrop'; bd.onclick = function(e){ if (e.target === bd) bd.remove(); };
  bd.innerHTML = '<div class="modal" style="max-width:560px" role="dialog" aria-label="' + _socEsc(title) + '">' +
    '<div class="modal-header"><h3>' + _socEsc(title) + '</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div>' +
    '<div class="modal-body">' +
      '<div class="form-group"><label class="form-label">Platform</label><select class="form-select" id="sp-pl">' + _SOC_PLATFORMS.map(function(p){ return '<option value="' + p + '"' + (p===platform?' selected':'') + '>' + _socPlat(p) + '</option>'; }).join('') + '</select></div>' +
      '<div class="form-group"><div style="display:flex;justify-content:space-between;align-items:baseline"><label class="form-label">Content *</label><span id="sp-count" style="font-size:11px;color:var(--t3)"></span></div><textarea class="form-input" id="sp-c" style="min-height:110px;resize:vertical" placeholder="What do you want to share?">' + _socEsc(post ? (post.content||'') : (opts.content||'')) + '</textarea>' +
        '<div style="display:flex;gap:6px;margin-top:6px"><button type="button" class="btn btn-outline btn-sm" id="sp-ai">' + window.icon('ai',14) + ' Draft with AI (1 credit)</button><button type="button" class="btn btn-outline btn-sm" id="sp-tags">#  Suggest hashtags (1 credit)</button></div></div>' +
      '<div class="form-group"><label class="form-label">Hashtags <span style="font-size:10px;opacity:.6">(space-separated)</span></label><input class="form-input" id="sp-h" value="' + _socEsc(tags.join(' ')) + '" placeholder="#bakery #sourdough"></div>' +
      '<div class="form-group"><label class="form-label">Schedule <span style="font-size:10px;opacity:.6">(optional — leave blank to keep as a draft)</span></label><input type="datetime-local" class="form-input" id="sp-s" value="' + _socEsc(sched) + '"></div>' +
      '<div class="form-group"><label class="form-label">Image URL <span style="font-size:10px;opacity:.6">(optional)</span></label><div style="display:flex;gap:6px"><input class="form-input" id="sp-img" placeholder="Paste a URL…" style="flex:1" value="' + _socEsc(post && post.media_json ? ((function(){ try { var m = typeof post.media_json==='string' ? JSON.parse(post.media_json) : post.media_json; return (m && m[0] && (m[0].url||m[0])) || ''; } catch(e){ return ''; } })()) : (opts.image||'')) + '"><button type="button" class="btn btn-outline" style="padding:0 14px;font-size:12px;white-space:nowrap" onclick="_spPickImage()">Library</button></div></div>' +
    '</div>' +
    '<div class="modal-footer">' +
      '<button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button>' +
      '<button class="btn btn-outline" id="sp-save">' + (post ? 'Save changes' : 'Save draft') + '</button>' +
      (post ? '' : '<button class="btn btn-primary" id="sp-post">Publish now</button>') +
    '</div></div>';
  document.body.appendChild(bd); bd.style.opacity = '1'; bd.style.pointerEvents = 'all';

  var ta = bd.querySelector('#sp-c'), count = bd.querySelector('#sp-count');
  var limits = { twitter: 280, x: 280, instagram: 2200, facebook: 63206, linkedin: 3000 };
  function refreshCount(){ var pl = bd.querySelector('#sp-pl').value; var lim = limits[pl] || 0; var n = ta.value.length; count.textContent = lim ? (n + ' / ' + lim + (n > lim ? ' — too long for ' + _SOC_LABEL[pl] : '')) : (n + ' chars'); count.style.color = (lim && n > lim) ? 'var(--rd)' : 'var(--t3)'; }
  ta.addEventListener('input', refreshCount); bd.querySelector('#sp-pl').addEventListener('change', refreshCount); refreshCount();

  bd.querySelector('#sp-ai').onclick = async function(){
    var topic = ta.value.trim() || await luPrompt('Draft with AI', '', 'What should the post be about?');
    if (!topic) return;
    var b = this; b.disabled = true; b.textContent = 'Drafting…';
    try {
      var d = _socUnwrap(await _socApi('POST', '/social/ai/generate', { platform: bd.querySelector('#sp-pl').value, topic: topic, persist: false }));
      if (d.pending) { showToast(d.message, 'info'); return; }
      var content = d.content || (d.post && d.post.content) || '';
      if (!content) throw new Error(d.error || 'The AI returned no content.');
      ta.value = content; refreshCount();
      var hs = d.hashtags || []; if (hs.length) bd.querySelector('#sp-h').value = hs.join(' ');
      bd.dataset.aiPostId = d.post_id || '';
      showToast('Draft written' + (d.best_time ? ' · best time: ' + d.best_time : '') + '.', 'success');
    } catch (e) { showToast('AI draft failed: ' + e.message, 'error'); }
    finally { b.disabled = false; b.innerHTML = window.icon('ai',14) + ' Draft with AI (1 credit)'; }
  };
  bd.querySelector('#sp-tags').onclick = async function(){
    var content = ta.value.trim(); if (!content) { showToast('Write or draft the post first.', 'warning'); return; }
    var b = this; b.disabled = true; b.textContent = 'Suggesting…';
    try {
      var d = _socUnwrap(await _socApi('POST', '/social/ai/hashtags', { platform: bd.querySelector('#sp-pl').value, content: content }));
      if (d.pending) { showToast(d.message, 'info'); return; }
      var hs = d.hashtags || []; if (!hs.length) throw new Error('No hashtags came back.');
      bd.querySelector('#sp-h').value = hs.join(' ');
      showToast(hs.length + ' hashtags suggested.', 'success');
    } catch (e) { showToast('Hashtags failed: ' + e.message, 'error'); }
    finally { b.disabled = false; b.textContent = '#  Suggest hashtags (1 credit)'; }
  };

  function readForm(){
    var media = bd.querySelector('#sp-img').value.trim();
    return {
      platform: bd.querySelector('#sp-pl').value,
      content: ta.value.trim(),
      hashtags: bd.querySelector('#sp-h').value.split(/\s+/).filter(Boolean).map(function(h){ return h[0]==='#' ? h : '#' + h; }),
      scheduled_at: bd.querySelector('#sp-s').value || null,
      media: media ? [{ url: media }] : [],
    };
  }
  async function save(publishNow){
    var f = readForm();
    if (!f.content) { showToast('Enter the post content.', 'error'); return; }
    var btn = publishNow ? bd.querySelector('#sp-post') : bd.querySelector('#sp-save');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      var postId;
      if (post) {
        var u = await _socApi('PUT', '/social/posts/' + post.id, f);
        postId = post.id;
        showToast(f.scheduled_at ? 'Post updated and scheduled.' : 'Post updated.', 'success');
      } else {
        var created = _socUnwrap(await _socApi('POST', '/social/posts', f));
        if (created.pending) { showToast(created.message, 'info'); bd.remove(); socialLoad(document.getElementById('social-root')); return; }
        postId = created.post_id || created.id;
        if (f.scheduled_at && postId) {
          var sch = _socUnwrap(await _socApi('POST', '/social/posts/' + postId + '/schedule', { scheduled_at: f.scheduled_at }));
          showToast(sch.pending ? sch.message : 'Post scheduled for ' + new Date(f.scheduled_at).toLocaleString() + '.', sch.pending ? 'info' : 'success');
        } else if (!publishNow) {
          showToast('Draft saved.', 'success');
        }
      }
      bd.remove();
      if (publishNow && postId) await socialPublishPost(postId);
      else socialLoad(document.getElementById('social-root'));
    } catch (e) {
      showToast(e.message, 'error');
      btn.disabled = false; btn.textContent = publishNow ? 'Publish now' : (post ? 'Save changes' : 'Save draft');
    }
  }
  bd.querySelector('#sp-save').onclick = function(){ save(false); };
  var pb = bd.querySelector('#sp-post'); if (pb) pb.onclick = function(){ save(true); };
  setTimeout(function(){ ta.focus(); }, 50);
}

window.socialNewPost = function(opts) { _socComposer(opts || {}); };

window.socialGenerateWithAI = async function() {
  var topic = await luPrompt('Generate a post with AI', '', 'What should the post be about?');
  if (!topic) return;
  var platform = 'instagram';
  showToast('Drafting with AI…', 'info');
  try {
    var d = _socUnwrap(await _socApi('POST', '/social/ai/generate', { platform: platform, topic: topic }));
    if (d.pending) { showToast(d.message, 'info'); return; }
    var content = d.content || (d.post && d.post.content) || '';
    if (!content && d.post_id) { // persisted server-side; open it
      await socialLoad(document.getElementById('social-root'));
      return socialEditPost(d.post_id);
    }
    if (!content) throw new Error(d.error || 'The AI returned no content.');
    if (d.post_id) { await socialLoad(document.getElementById('social-root')); return socialEditPost(d.post_id); }
    _socComposer({ platform: platform, content: content, hashtags: d.hashtags || [] });
  } catch (e) { showToast('AI draft failed: ' + e.message, 'error'); }
};

window.socialPublishPost = async function(postId) {
  if (!postId) { showToast('Invalid post.', 'error'); return; }
  if (window._socialPubInFlight === postId) return;
  window._socialPubInFlight = postId;
  showToast('Publishing…', 'info');
  try {
    var raw = await _socApi('POST', '/social/posts/' + postId + '/publish');
    var d;
    try { d = _socUnwrap(raw); } catch (refused) { showToast(refused.message, 'error'); return; }
    if (d.pending) { showToast('Publishing needs your approval — it is in the Review Queue.', 'info'); return; }
    if (d.published === true && d.external_id) showToast('Published — confirmed by the platform (id ' + d.external_id + ').', 'success');
    else showToast('The platform did not confirm the post' + (d.error ? ': ' + d.error : '.') , 'error');
  } catch (e) { showToast('Publish failed: ' + e.message, 'error'); }
  finally { window._socialPubInFlight = null; socialLoad(document.getElementById('social-root')); }
};

window.socialEditPost = async function(postId) {
  if (!postId) return;
  var post = _soc.posts.find(function(p){ return p.id === postId; });
  if (!post) { try { post = await _socApi('GET', '/social/posts/' + postId); } catch (e) { showToast('Load failed: ' + e.message, 'error'); return; } }
  if (!post || !post.id) { showToast('Post not found.', 'error'); return; }
  _socComposer({ post: post });
};

window.socialDeletePost = async function(postId) {
  if (!postId) return;
  var p = _soc.posts.find(function(x){ return x.id === postId; }) || {};
  var ok = await luConfirm('Delete this post' + (p.content ? ': "' + _socEsc(String(p.content).slice(0, 60)) + '…"' : '') + '?', 'Delete post', 'Delete', 'Keep');
  if (!ok) return;
  try { await _socApi('DELETE', '/social/posts/' + postId); _soc.posts = _soc.posts.filter(function(x){ return x.id !== postId; }); showToast('Post deleted.', 'success'); socialLoad(document.getElementById('social-root')); }
  catch (e) { showToast('Delete failed: ' + e.message, 'error'); }
};

window.luSocialDisconnect = async function(accountId) {
  var ok = await luConfirm('Disconnect this social account? Scheduled posts for it will fail to publish until it is reconnected.', 'Disconnect account', 'Disconnect', 'Keep');
  if (!ok) return;
  try { await _socApi('DELETE', '/social/accounts/' + accountId); showToast('Account disconnected.', 'success'); socialLoad(document.getElementById('social-root')); }
  catch (e) { showToast('Disconnect failed: ' + e.message, 'error'); }
};

// ── CALENDAR ───────────────────────────────────────────────────────────────
function _socialBuildCalendar(posts) {
  var calEl = document.getElementById('social-cal'); if (!calEl) return;
  function postDays(y, m){ var days = {}; posts.forEach(function(p){ var d = p.scheduled_at ? new Date(p.scheduled_at) : p.published_at ? new Date(p.published_at) : null; if (d && d.getFullYear()===y && d.getMonth()===m) { (days[d.getDate()] = days[d.getDate()] || []).push(p); } }); return days; }
  function render(y, m){
    var pd = postDays(y, m), today = new Date(), fd = new Date(y, m, 1).getDay(), dim = new Date(y, m+1, 0).getDate();
    var mn = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var html = '<div class="cal-nav"><button class="cal-nav-btn" onclick="window._socialCalPrev()">‹</button><div class="cal-month">' + mn[m] + ' ' + y + '</div><button class="cal-nav-btn" onclick="window._socialCalNext()">›</button></div><div class="cal-grid">';
    ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(function(d){ html += '<div class="cal-dow">' + d + '</div>'; });
    for (var i = 0; i < fd; i++) html += '<div class="cal-day empty other-month" aria-hidden="true"></div>';
    for (var d = 1; d <= dim; d++) { var iT = today.getFullYear()===y && today.getMonth()===m && today.getDate()===d; var lbl = new Date(y, m, d).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }) + (pd[d] ? ', ' + pd[d].length + ' post' + (pd[d].length === 1 ? '' : 's') : ', no posts'); html += '<button type="button" class="cal-day' + (iT?' today':'') + (pd[d]?' has-post':'') + '" onclick="window._socialCalDay(' + y + ',' + m + ',' + d + ')" aria-label="' + lbl + '" title="' + (pd[d] ? pd[d].length + ' post(s)' : '') + '">' + d + '</button>'; }
    calEl.innerHTML = html + '</div>'; calEl._y = y; calEl._m = m;
  }
  var now = new Date(); render(now.getFullYear(), now.getMonth());
  window._socialCalPrev = function(){ var y = calEl._y, m = calEl._m - 1; if (m < 0) { m = 11; y--; } render(y, m); };
  window._socialCalNext = function(){ var y = calEl._y, m = calEl._m + 1; if (m > 11) { m = 0; y++; } render(y, m); };
  window._socialCalDay = function(y, m, d){
    var pd = postDays(y, m), panel = document.getElementById('social-day-panel'), postsEl = document.getElementById('social-day-posts'), lbl = document.getElementById('social-day-label');
    if (!panel || !postsEl) return;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    if (lbl) lbl.textContent = months[m] + ' ' + d + ', ' + y;
    panel.style.display = 'block';
    var dayPosts = pd[d] || [];
    postsEl.innerHTML = dayPosts.length === 0 ? '<div style="text-align:center;padding:16px;color:var(--t3);font-size:12px">No posts this day. <a href="#" onclick="socialNewPost();return false" style="color:var(--da)">Create one →</a></div>'
      : dayPosts.map(function(p){ return '<div class="dash-post-row"><div style="flex:1;min-width:0"><div class="dash-post-txt">' + _socEsc(p.content||'—') + '</div><div class="dash-post-meta">' + _socStatusBadge(p.status) + '</div></div><button class="btn btn-outline btn-sm" onclick="socialEditPost(' + p.id + ')">' + window.icon('edit',14) + '</button></div>'; }).join('');
  };
}

// ── ACCOUNTS (OAuth popup; zero credentials in the browser) ────────────────
window._svTimeAgo = function(ts){
  if (!ts) return '';
  var t = (window._luParseTs ? window._luParseTs(ts) : new Date(ts)).getTime(); if (isNaN(t)) return '';
  var s = Math.floor((Date.now() - t) / 1000);
  if (s < 60) return s + ' sec ago'; var m = Math.floor(s/60); if (m < 60) return m + ' min ago';
  var h = Math.floor(m/60); if (h < 24) return h + ' hour' + (h===1?'':'s') + ' ago';
  var d = Math.floor(h/24); if (d < 30) return d + ' day' + (d===1?'':'s') + ' ago';
  return new Date(ts).toLocaleDateString();
};

window._svRenderPlatformCards = function(accounts){
  var A = accounts || [], byPlat = {};
  A.forEach(function(a){ (byPlat[a.platform] = byPlat[a.platform] || []).push(a); });
  var fb = (byPlat.facebook||[])[0], ig = (byPlat.instagram||[])[0], li = (byPlat.linkedin||[])[0];
  return [
    _svPlatformCard({ platform:'facebook', label:'Facebook', ready:true, blurb:'Connect your Facebook Page to publish and schedule posts.', connectLabel:'Connect Facebook Page →', account: fb }),
    _svPlatformCard({ platform:'instagram', label:'Instagram', ready:true, requires:{ platform:'facebook', connected: !!fb, message:'Instagram publishing runs through a linked Facebook Page. Connect Facebook first.' }, blurb:'Connect your Instagram Business account (via Facebook) for feed posts.', connectLabel:'Connect Instagram →', account: ig }),
    _svPlatformCard({ platform:'linkedin', label:'LinkedIn', ready:false, setupRequired:true, blurb:'LinkedIn publishing is not enabled on this workspace yet. You can still draft and copy LinkedIn posts.', account: li }),
    _svPlatformCard({ platform:'tiktok', label:'TikTok', ready:false, comingSoon:true, blurb:'TikTok publishing is not available yet. Draft TikTok-ready captions here and post them manually.' }),
  ].join('');
};

window._svPlatformCard = function(cfg){
  var connected = !!cfg.account, blockedByReq = cfg.requires && !cfg.requires.connected, stateBadge, bodyHtml;
  if (connected) {
    var a = cfg.account;
    stateBadge = '<span style="font-size:12px;font-weight:700;color:var(--ac)">✓ Connected</span>';
    bodyHtml = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px"><div style="flex:1;min-width:0"><div style="font-weight:700;color:var(--t1);font-size:14px">' + _socEsc(a.account_name||cfg.label) + '</div><div style="font-size:11px;color:var(--t3)">' + _socEsc(cfg.platform) + ' · ' + _socEsc(a.status||'active') + (a.created_at ? ' · connected ' + _svTimeAgo(a.created_at) : '') + '</div></div></div><div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn btn-outline btn-sm" style="color:var(--rd)" onclick="luSocialDisconnect(' + a.id + ')">Disconnect</button></div>';
  } else if (cfg.comingSoon) {
    stateBadge = '<span style="font-size:10px;font-weight:700;color:var(--am);background:rgba(245,158,11,.15);padding:3px 10px;border-radius:10px;text-transform:uppercase;letter-spacing:.04em">Not yet</span>';
    bodyHtml = '<p style="color:var(--t2);font-size:13px;margin:0;line-height:1.5">' + _socEsc(cfg.blurb) + '</p>';
  } else if (cfg.setupRequired) {
    stateBadge = '<span style="font-size:11px;font-weight:700;color:var(--am)">Setup required</span>';
    bodyHtml = '<p style="color:var(--t2);font-size:13px;margin:0 0 12px;line-height:1.5">' + _socEsc(cfg.blurb) + '</p><a class="btn btn-outline btn-sm" href="mailto:support@levelupgrowth.io?subject=Enable%20LinkedIn%20publishing">Contact support →</a>';
  } else if (blockedByReq) {
    stateBadge = '<span style="font-size:11px;font-weight:700;color:var(--t3)">Connect ' + _socEsc(cfg.requires.platform) + ' first</span>';
    bodyHtml = '<p style="color:var(--t2);font-size:13px;margin:0 0 12px;line-height:1.5">' + _socEsc(cfg.requires.message || cfg.blurb) + '</p><button class="btn btn-primary btn-sm" disabled style="opacity:.5;cursor:not-allowed">' + _socEsc(cfg.connectLabel || 'Connect') + '</button>';
  } else {
    stateBadge = '<span id="sv-state-' + cfg.platform + '" style="font-size:11px;font-weight:700;color:var(--t3)">Not connected</span>';
    bodyHtml = '<p style="color:var(--t2);font-size:13px;margin:0 0 14px;line-height:1.5">' + _socEsc(cfg.blurb) + '</p><button class="btn btn-primary btn-sm" id="sv-connect-' + cfg.platform + '" onclick="_svConnectPlatform(\'' + cfg.platform + '\')">' + _socEsc(cfg.connectLabel || 'Connect') + '</button><div id="sv-connecting-' + cfg.platform + '" style="display:none;margin-top:10px;padding:8px 10px;background:var(--s1);border-radius:6px;font-size:12px;color:var(--t3)">Opening the authorisation window… allow popups for this site if nothing appears.</div>';
  }
  return '<div class="card"><div class="card-header" style="display:flex;align-items:center;justify-content:space-between"><h3>' + _socEsc(cfg.label) + '</h3>' + stateBadge + '</div><div class="card-body" style="padding:20px;max-width:560px">' + bodyHtml + '</div></div>';
};

window._svConnectPlatform = async function(platform){
  var btn = document.getElementById('sv-connect-' + platform), spinner = document.getElementById('sv-connecting-' + platform), state = document.getElementById('sv-state-' + platform);
  if (btn) btn.disabled = true; if (spinner) spinner.style.display = 'block'; if (state) state.textContent = 'Connecting…';
  var redirectUrl = null;
  try {
    var r = await _socApi('GET', '/social/oauth/' + platform + '/connect');
    if (r && r.redirect_url) redirectUrl = r.redirect_url; else throw new Error(r && r.error ? r.error : 'Could not get an authorisation URL');
  } catch (e) {
    if (btn) btn.disabled = false; if (spinner) spinner.style.display = 'none'; if (state) state.textContent = 'Not connected';
    showToast('Could not start the ' + _SOC_LABEL[platform] + ' connection: ' + (e.message || 'unknown'), 'error');
    return;
  }
  var w = 600, h = 700, left = Math.max(0, (screen.width/2) - (w/2)), top = Math.max(0, (screen.height/2) - (h/2));
  var popup = window.open(redirectUrl, 'lu_social_connect', 'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',scrollbars=yes,resizable=yes');
  if (!popup || popup.closed || typeof popup.closed === 'undefined') {
    if (btn) btn.disabled = false; if (spinner) spinner.style.display = 'none'; if (state) state.textContent = 'Not connected';
    showToast('Popup blocked. Allow popups for this site and try again.', 'warning'); return;
  }
  function handler(e){
    if (!e.data || !e.data.type || (e.data.type !== 'social_connected' && e.data.type !== 'social_error')) return;
    window.removeEventListener('message', handler); try { popup.close(); } catch (_) {}
    if (btn) btn.disabled = false; if (spinner) spinner.style.display = 'none';
    if (e.data.type === 'social_connected') { showToast('Connected — ' + (e.data.account_name || platform) + ' is ready.', 'success'); socialLoad(document.getElementById('social-root')); }
    else { if (state) state.textContent = 'Not connected'; showToast('Connection failed: ' + (e.data.message || 'unknown'), 'error'); }
  }
  window.addEventListener('message', handler);
  var pollClose = setInterval(function(){ if (!popup || popup.closed) { clearInterval(pollClose); window.removeEventListener('message', handler); if (btn && btn.disabled) { btn.disabled = false; if (spinner) spinner.style.display = 'none'; if (state) state.textContent = 'Not connected'; socialLoad(document.getElementById('social-root')); } } }, 500);
};

(function(){
  var params = new URLSearchParams(window.location.search);
  ['facebook','instagram','linkedin'].forEach(function(p){
    if (params.get(p + '_connected') === '1') {
      history.replaceState({}, '', window.location.pathname);
      // P0 (2026-08-30): a URL flag is not proof — confirm an active account for this platform exists before saying "connected".
      (function (plat) {
        _socApi('GET', '/social/accounts').then(function (res) {
          var list = Array.isArray(res) ? res : (res && res.accounts) || [];
          var hit = list.some(function (a) { return a && String(a.platform || '').toLowerCase() === plat && (a.status || 'active') === 'active'; });
          if (hit) showToast(_SOC_LABEL[plat] + ' connected.', 'success');
          else showToast(_SOC_LABEL[plat] + ' did not finish connecting — no active account was saved. Please try again.', 'warning');
        }).catch(function (e) { showToast('Could not confirm the ' + _SOC_LABEL[plat] + ' connection: ' + (e && e.message ? e.message : 'unknown'), 'warning'); });
      })(p);
    }
    if (params.get(p + '_error')) { showToast(_SOC_LABEL[p] + ' error: ' + params.get(p + '_error'), 'error'); history.replaceState({}, '', window.location.pathname); }
  });
})();

console.log('[LevelUp] social engine v3.0.0 loaded');
