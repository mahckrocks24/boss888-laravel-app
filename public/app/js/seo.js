// LevelUp SEO Engine — Laravel native (replaces WP iframe)
// Maps to 25 routes under /api/seo/*
/* ==========================================================================
   DEAD CODE WARNING — legacy shells, lines ~1–3150.
   Runtime entry point is `window.seoLoad`, defined at ~line 4184 (canonical
   block 3151–4193). The earlier blocks redefine `_seoRenderShell` /
   `_seoSwitchTab` repeatedly; only the last load wins, and the dashboard
   actually invokes `seoLoad`. Editing anything above line 3151 has no
   runtime effect and only adds bundle weight.
   Do NOT make functional edits above line 3151.
   See: storage/audits/boss888-phase2-20260425-073526/
        seo-button-function-audit.md (2026-04-28)
   ========================================================================== */

var _seoTab = 'dashboard';
var _seoEl = () => document.getElementById('seo-root');
var _seoApi = async (method, path, body) => {
  var token = localStorage.getItem('lu_token') || '';
  var opts = { method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + token }, cache: 'no-store' };
  if (body) opts.body = JSON.stringify(body);
  var r = await fetch(window.location.origin + '/api/seo' + path, opts);
  return r.json();
};

function seoLoad(el) {
  if (!el) return;
  el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%';
  _seoRenderShell(el);
  _seoSwitchTab('dashboard');
}

function _seoRenderShell(el) {
  var tabs = [
    { id: 'dashboard', label: 'Command Center', icon: ''+window.icon("info",14)+'' },
    { id: 'audits', label: 'Audit Center', icon: ''+window.icon("info",14)+'' },
    { id: 'links', label: 'Link Opportunities', icon: ''+window.icon("link",14)+'' },
    { id: 'workspace', label: 'Optimization', icon: ''+window.icon("chart",14)+'' },
    { id: 'insights', label: 'Insights', icon: ''+window.icon("info",14)+'' },
    { id: 'reports', label: 'Reports', icon: ''+window.icon("info",14)+'' },
    { id: 'redirects', label: 'Redirects', icon: '↪' },
    { id: 'outbound', label: 'Outbound Links', icon: ''+window.icon("globe",14)+'' },
    { id: 'images', label: 'Images', icon: ''+window.icon("image",14)+'' },
    { id: 'settings', label: 'Settings', icon: ''+window.icon("edit",14)+'' },
    { id: 'score', label: 'Scores', icon: ''+window.icon("more",14)+'' },
    { id: 'integrations', label: 'Integrations', icon: ''+window.icon("link",14)+'' },
    { id: 'content', label: 'Content AI', icon: ''+window.icon("edit",14)+'' },
    { id: 'goals', label: 'Goals', icon: ''+window.icon("more",14)+'' },
        { id: 'gsc', label: 'Search Console', icon: ''+window.icon("chart",14)+'' },
{ id: 'pages', label: 'Pages', icon: ''+window.icon("more",14)+'' },
    { id: 'ctr', label: 'CTR Optimizer', icon: ''+window.icon("chart",14)+'' },
    { id: 'wins', label: 'Quick Wins', icon: ''+window.icon("check",14)+'' },
  ];
  var tabHtml = tabs.map(t =>
    '<div class="seo-tab" id="seo-tab-' + t.id + '" onclick="_seoSwitchTab(\'' + t.id + '\')" ' +
    'style="padding:10px 16px;cursor:pointer;font-size:13px;font-weight:500;color:var(--t2);border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap">' +
    t.icon + ' ' + t.label + '</div>'
  ).join('');

  el.innerHTML =
    '<div style="display:flex;border-bottom:1px solid var(--bd);background:var(--s1);flex-shrink:0;overflow-x:auto">' + tabHtml + '</div>' +
    '<div id="seo-content" style="flex:1;overflow-y:auto;padding:24px"></div>';
}

function _seoSwitchTab(tab) {
  _seoTab = tab;
  document.querySelectorAll('.seo-tab').forEach(t => {
    t.style.color = 'var(--t2)';
    t.style.borderBottomColor = 'transparent';
  });
  var active = document.getElementById('seo-tab-' + tab);
  if (active) {
    active.style.color = 'var(--p,#6C5CE7)';
    active.style.borderBottomColor = 'var(--p,#6C5CE7)';
  }
  var content = document.getElementById('seo-content');
  if (!content) return;
  content.innerHTML = loadingCard(300);

  if (tab === 'dashboard') _seoDashboard(content);
  else if (tab === 'audits') _seoAudits(content);
  else if (tab === 'links') _seoLinks(content);
  else if (tab === 'workspace') _seoWorkspace(content);
  else if (tab === 'insights') _seoInsights(content);
  else if (tab === 'reports') _seoReports(content);
  else if (tab === 'redirects') _seoRedirects(content);
  else if (tab === 'outbound') _seoOutbound(content);
  else if (tab === 'images') _seoImages(content);
  else if (tab === 'settings') _seoSettings(content);
  else if (tab === 'score') _seoScoreSettings(content);
  else if (tab === 'integrations') _seoIntegrations(content);
  else if (tab === 'content') _seoContent(content);
  else if (tab === 'goals') _seoGoals(content);
  else if (tab === 'gsc') _seoGsc(content);
  else if (tab === 'pages') _seoPages(content);
  else if (tab === 'ctr') _seoCtr(content);
  else if (tab === 'wins') _seoWins(content);
}

// ── Dashboard ──────────────────────────────────────────────────────────
async function _seoDashboard(el) {
  try {
    var data = await _seoApi('GET', '/dashboard');
    var h = '<div style="margin-bottom:24px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">SEO Dashboard</h2>' +
      '<p style="font-size:13px;color:var(--t2)">Overview of your SEO performance</p></div>';
    h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px">';
    h += _seoStat('Keywords Tracked', data.keyword_count || 0, 'var(--p)');
    h += _seoStat('Audits Run', data.audit_count || 0, 'var(--bl)');
    h += _seoStat('Active Goals', data.active_goals || 0, 'var(--ac)');
    h += _seoStat('Link Suggestions', data.link_count || 0, 'var(--am)');
    h += _seoStat('AI Reports', data.ai_report_count || 0, 'var(--pu)');
    h += _seoStat('Health Score', (data.health_score || '—') + '%', 'var(--ac)');
    h += '</div>';
    if (data.recent_audits && data.recent_audits.length) {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:20px"><div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:12px">Recent Audits</div>';
      data.recent_audits.forEach(a => {
        h += '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--bd);font-size:13px"><span style="color:var(--t1)">' + (a.url || a.domain || 'Audit #' + a.id) + '</span><span style="color:var(--t2)">' + (a.score || '—') + '/100</span></div>';
      });
      h += '</div>';
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

// ── Site Audit ─────────────────────────────────────────────────────────
async function _seoAudits(el) {
  try {
    var data = await _seoApi('GET', '/audits');
    var audits = data.audits || data || [];
    var h = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
      '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0">Site Audits</h2>' +
      '<button class="btn btn-primary" onclick="_seoRunAudit()">'+window.icon("more",14)+' Run New Audit</button></div>';
    if (audits.length === 0) {
      h += '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">'+window.icon("more",14)+'</div><p>No audits yet. Run your first site audit.</p></div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px">';
      h += '<thead><tr><th style="text-align:left;padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">URL</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Score</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Date</th></tr></thead><tbody>';
      audits.forEach(a => {
        h += '<tr style="cursor:pointer" onclick="_seoViewAudit(' + a.id + ')"><td style="padding:10px 12px;color:var(--t1);border-bottom:1px solid rgba(255,255,255,.04)">' + (a.url || a.domain || '—') + '</td><td style="padding:10px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.04)"><span style="color:' + (a.score >= 80 ? 'var(--ac)' : a.score >= 50 ? 'var(--am)' : 'var(--rd)') + ';font-weight:600">' + (a.score || '—') + '</span></td><td style="padding:10px 12px;color:var(--t2);text-align:center;border-bottom:1px solid rgba(255,255,255,.04)">' + (a.created_at ? new Date(a.created_at).toLocaleDateString() : '—') + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

async function _seoRunAudit() {
  var url = await luPrompt('Enter URL to audit:', 'Run Audit', window.location.origin);
  if (!url) return;
  var el = document.getElementById('seo-content');
  if (el) el.innerHTML = loadingCard(300);
  try {
    var data = await _seoApi('POST', '/deep-audit', { url: url });
    showToast('Audit started!', 'success');
    setTimeout(() => _seoAudits(el), 1000);
  } catch(e) { showToast('Audit failed: ' + e.message, 'error'); _seoAudits(el); }
}

async function _seoViewAudit(id) {
  var el = document.getElementById('seo-content');
  if (!el) return;
  el.innerHTML = loadingCard(300);
  try {
    var data = await _seoApi('GET', '/audits/' + id);
    var a = data.audit || data;
    var h = '<div style="margin-bottom:16px"><button class="btn btn-outline btn-sm" onclick="_seoAudits(document.getElementById(\'seo-content\'))">← Back</button></div>';
    h += '<h2 style="font-family:var(--fh);font-size:20px;color:var(--t1);margin:0 0 16px">Audit: ' + (a.url || a.domain || '#' + a.id) + '</h2>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px"><pre style="white-space:pre-wrap;font-size:12px;color:var(--t2);max-height:600px;overflow-y:auto">' + JSON.stringify(a, null, 2) + '</pre></div>';
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

// ── Keywords ───────────────────────────────────────────────────────────
async function _seoKeywords(el) {
  try {
    var data = await _seoApi('GET', '/keywords');
    var kws = data.keywords || data || [];
    var h = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
      '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0">Tracked Keywords</h2>' +
      '<button class="btn btn-primary" onclick="_seoAddKeyword()">+ Add Keyword</button></div>';
    if (kws.length === 0) {
      h += '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">'+window.icon("lock",14)+'</div><p>No keywords tracked yet.</p></div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px">';
      h += '<thead><tr><th style="text-align:left;padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Keyword</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Position</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Volume</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Actions</th></tr></thead><tbody>';
      kws.forEach(k => {
        h += '<tr><td style="padding:10px 12px;color:var(--t1);border-bottom:1px solid rgba(255,255,255,.04)">' + (k.keyword || k.term || '—') + '</td><td style="padding:10px 12px;text-align:center;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (k.position || '—') + '</td><td style="padding:10px 12px;text-align:center;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (k.volume || '—') + '</td><td style="padding:10px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.04)"><button class="btn btn-outline btn-sm" onclick="_seoDeleteKeyword(' + k.id + ')" style="font-size:11px;color:var(--rd)">Delete</button></td></tr>';
      });
      h += '</tbody></table></div>';
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

async function _seoAddKeyword() {
  var kw = await luPrompt('Enter keyword to track:', 'Add Keyword');
  if (!kw) return;
  try {
    await _seoApi('POST', '/keywords', { keyword: kw });
    showToast('Keyword added!', 'success');
    _seoKeywords(document.getElementById('seo-content'));
  } catch(e) { showToast('Failed: ' + e.message, 'error'); }
}

async function _seoDeleteKeyword(id) {
  var ok = await luConfirm('Delete this keyword?', 'Delete Keyword', 'Delete', 'Cancel'); if (!ok) return;
  try {
    await _seoApi('DELETE', '/keywords/' + id);
    showToast('Keyword removed.', 'success');
    _seoKeywords(document.getElementById('seo-content'));
  } catch(e) { showToast('Failed: ' + e.message, 'error'); }
}

// ── SERP Analysis ──────────────────────────────────────────────────────
async function _seoSerp(el) {
  el.innerHTML = '<div style="max-width:600px">' +
    '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 16px">SERP Analysis</h2>' +
    '<p style="font-size:13px;color:var(--t2);margin-bottom:20px">Analyze search engine results for any keyword to understand the competitive landscape.</p>' +
    '<div style="display:flex;gap:8px"><input type="text" id="seo-serp-kw" class="form-input" placeholder="Enter keyword…" style="flex:1">' +
    '<button class="btn btn-primary" onclick="_seoRunSerp()">Analyze</button></div>' +
    '<div id="seo-serp-result" style="margin-top:20px"></div></div>';
}

async function _seoRunSerp() {
  var kw = document.getElementById('seo-serp-kw')?.value;
  if (!kw) { showToast('Enter a keyword.', 'error'); return; }
  var result = document.getElementById('seo-serp-result');
  if (result) result.innerHTML = loadingCard(200);
  try {
    var data = await _seoApi('POST', '/serp-analysis', { keyword: kw });
    if (result) result.innerHTML = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:20px"><pre style="white-space:pre-wrap;font-size:12px;color:var(--t2);max-height:500px;overflow-y:auto">' + JSON.stringify(data, null, 2) + '</pre></div>';
  } catch(e) { if (result) result.innerHTML = _seoError(e); }
}

// ── Internal Links ─────────────────────────────────────────────────────
async function _seoLinks(el) {
  try {
    var data = await _seoApi('GET', '/links');
    var links = data.suggestions || data.links || data || [];
    var h = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
      '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0">Internal Link Suggestions</h2>' +
      '<button class="btn btn-primary" onclick="_seoGenerateLinks()">'+window.icon("link",14)+' Generate Suggestions</button></div>';
    if (!Array.isArray(links) || links.length === 0) {
      h += '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">'+window.icon("link",14)+'</div><p>No link suggestions yet. Click Generate to scan your content.</p></div>';
    } else {
      links.forEach(l => {
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;margin-bottom:10px;font-size:13px">' +
          '<div style="color:var(--t1);font-weight:500;margin-bottom:6px">' + (l.anchor_text || l.keyword || '—') + '</div>' +
          '<div style="color:var(--t2);font-size:12px;margin-bottom:8px">From: ' + (l.source_url || '—') + ' → ' + (l.target_url || '—') + '</div>' +
          '<div style="display:flex;gap:6px"><button class="btn btn-primary btn-sm" onclick="_seoInsertLink(' + l.id + ')">Insert</button><button class="btn btn-outline btn-sm" onclick="_seoDismissLink(' + l.id + ')">Dismiss</button></div></div>';
      });
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

async function _seoGenerateLinks() {
  var el = document.getElementById('seo-content');
  if (el) el.innerHTML = loadingCard(300);
  try {
    await _seoApi('POST', '/links/generate', {});
    showToast('Link suggestions generated!', 'success');
    _seoLinks(el);
  } catch(e) { showToast('Failed: ' + e.message, 'error'); _seoLinks(el); }
}

async function _seoInsertLink(id) {
  try { await _seoApi('POST', '/links/' + id + '/insert', {}); showToast('Link inserted!', 'success'); _seoLinks(document.getElementById('seo-content')); } catch(e) { showToast('Failed: ' + e.message, 'error'); }
}

async function _seoDismissLink(id) {
  try { await _seoApi('POST', '/links/' + id + '/dismiss', {}); showToast('Dismissed.', 'info'); _seoLinks(document.getElementById('seo-content')); } catch(e) { showToast('Failed: ' + e.message, 'error'); }
}

// ── Content AI ─────────────────────────────────────────────────────────
async function _seoContent(el) {
  el.innerHTML = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 16px">Content AI Tools</h2>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">' +
    '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px">' +
      '<div style="font-size:28px;margin-bottom:12px">🤖</div>' +
      '<div style="font-family:var(--fh);font-size:15px;font-weight:600;color:var(--t1);margin-bottom:6px">AI SEO Report</div>' +
      '<div style="font-size:12px;color:var(--t2);margin-bottom:16px">Get AI-powered SEO analysis and recommendations for any URL.</div>' +
      '<input type="text" class="form-input" id="seo-ai-url" placeholder="https://yoursite.com/page" style="margin-bottom:8px">' +
      '<button class="btn btn-primary" style="width:100%" onclick="_seoAiReport()">Generate Report</button></div>' +
    '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px">' +
      '<div style="font-size:28px;margin-bottom:12px">'+window.icon("edit",14)+'</div>' +
      '<div style="font-family:var(--fh);font-size:15px;font-weight:600;color:var(--t1);margin-bottom:6px">Write SEO Article</div>' +
      '<div style="font-size:12px;color:var(--t2);margin-bottom:16px">AI writes an SEO-optimized article for your target keyword.</div>' +
      '<input type="text" class="form-input" id="seo-write-kw" placeholder="Target keyword" style="margin-bottom:8px">' +
      '<button class="btn btn-primary" style="width:100%" onclick="_seoWriteArticle()">Write Article</button></div>' +
    '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px">' +
      '<div style="font-size:28px;margin-bottom:12px">'+window.icon("edit",14)+'</div>' +
      '<div style="font-family:var(--fh);font-size:15px;font-weight:600;color:var(--t1);margin-bottom:6px">Improve Draft</div>' +
      '<div style="font-size:12px;color:var(--t2);margin-bottom:16px">Paste your draft and get SEO improvement suggestions.</div>' +
      '<textarea class="form-input" id="seo-draft" placeholder="Paste your draft here..." rows="4" style="margin-bottom:8px;resize:vertical"></textarea>' +
      '<button class="btn btn-primary" style="width:100%" onclick="_seoImproveDraft()">Improve</button></div>' +
    '</div><div id="seo-ai-result" style="margin-top:24px"></div>';
}

async function _seoAiReport() {
  var url = document.getElementById('seo-ai-url')?.value;
  if (!url) { showToast('Enter a URL.', 'error'); return; }
  var r = document.getElementById('seo-ai-result'); if (r) r.innerHTML = loadingCard(200);
  try { var d = await _seoApi('POST', '/ai-report', { url: url }); if (r) r.innerHTML = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:20px"><pre style="white-space:pre-wrap;font-size:12px;color:var(--t2);max-height:500px;overflow-y:auto">' + JSON.stringify(d, null, 2) + '</pre></div>'; } catch(e) { if (r) r.innerHTML = _seoError(e); }
}

async function _seoWriteArticle() {
  var kw = document.getElementById('seo-write-kw')?.value;
  if (!kw) { showToast('Enter a keyword.', 'error'); return; }
  var r = document.getElementById('seo-ai-result'); if (r) r.innerHTML = loadingCard(200);
  try { var d = await _seoApi('POST', '/write-article', { keyword: kw }); if (r) r.innerHTML = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:20px"><pre style="white-space:pre-wrap;font-size:12px;color:var(--t2);max-height:500px;overflow-y:auto">' + JSON.stringify(d, null, 2) + '</pre></div>'; } catch(e) { if (r) r.innerHTML = _seoError(e); }
}

async function _seoImproveDraft() {
  var draft = document.getElementById('seo-draft')?.value;
  if (!draft) { showToast('Paste a draft first.', 'error'); return; }
  var r = document.getElementById('seo-ai-result'); if (r) r.innerHTML = loadingCard(200);
  try { var d = await _seoApi('POST', '/improve-draft', { content: draft }); if (r) r.innerHTML = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:20px"><pre style="white-space:pre-wrap;font-size:12px;color:var(--t2);max-height:500px;overflow-y:auto">' + JSON.stringify(d, null, 2) + '</pre></div>'; } catch(e) { if (r) r.innerHTML = _seoError(e); }
}

// ── Goals ──────────────────────────────────────────────────────────────
async function _seoGoals(el) {
  try {
    var data = await _seoApi('GET', '/goals');
    var goals = data.goals || data || [];
    var h = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
      '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0">SEO Goals</h2>' +
      '<button class="btn btn-primary" onclick="_seoCreateGoal()">+ New Goal</button></div>';
    if (goals.length === 0) {
      h += '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">'+window.icon("more",14)+'</div><p>No SEO goals set. Create your first goal.</p></div>';
    } else {
      goals.forEach(g => {
        var status = g.status || 'active';
        var color = status === 'active' ? 'var(--ac)' : status === 'paused' ? 'var(--am)' : 'var(--t3)';
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center">' +
          '<div><div style="font-size:14px;font-weight:500;color:var(--t1)">' + (g.title || g.name || 'Goal #' + g.id) + '</div>' +
          '<div style="font-size:12px;color:var(--t2);margin-top:4px">' + (g.description || g.target || '') + '</div></div>' +
          '<div style="display:flex;align-items:center;gap:8px"><span style="font-size:11px;color:' + color + ';font-weight:600;text-transform:uppercase">' + status + '</span>' +
          (status === 'active' ? '<button class="btn btn-outline btn-sm" onclick="_seoPauseGoal(' + g.id + ')">Pause</button>' : '<button class="btn btn-outline btn-sm" onclick="_seoResumeGoal(' + g.id + ')">Resume</button>') +
          '</div></div>';
      });
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

async function _seoCreateGoal() {
  var title = await luPrompt('Goal title (e.g., "Rank #1 for AI marketing"):', 'Create Goal');
  if (!title) return;
  try { await _seoApi('POST', '/goals', { title: title }); showToast('Goal created!', 'success'); _seoGoals(document.getElementById('seo-content')); } catch(e) { showToast('Failed: ' + e.message, 'error'); }
}
async function _seoPauseGoal(id) { try { await _seoApi('POST', '/goals/' + id + '/pause', {}); showToast('Goal paused.', 'info'); _seoGoals(document.getElementById('seo-content')); } catch(e) { showToast('Failed.', 'error'); } }
async function _seoResumeGoal(id) { try { await _seoApi('POST', '/goals/' + id + '/resume', {}); showToast('Goal resumed.', 'success'); _seoGoals(document.getElementById('seo-content')); } catch(e) { showToast('Failed.', 'error'); } }


// ── Optimization Workspace ──────────────────────────────────────
async function _seoWorkspace(el) {
  try {
    var data = await _seoApi('GET', '/keywords');
    var kws = data.keywords || data || [];
    var h = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Optimization Workspace</h2>' +
      '<p style="font-size:13px;color:var(--t2);margin-bottom:20px">Track and optimize your indexed content</p>';
    if (kws.length === 0) {
      h += '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">'+window.icon("chart",14)+'</div><p>Add keywords to start tracking optimization.</p><button class="btn btn-primary" onclick="_seoAddKeyword()">+ Track Keyword</button></div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr><th style="text-align:left;padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Keyword</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Position</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Volume</th></tr></thead><tbody>';
      kws.forEach(function(k) { h += '<tr><td style="padding:10px 12px;color:var(--t1);border-bottom:1px solid rgba(255,255,255,.04)">' + (k.keyword || k.term || '—') + '</td><td style="padding:10px 12px;text-align:center;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (k.position || '—') + '</td><td style="padding:10px 12px;text-align:center;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (k.volume || '—') + '</td></tr>'; });
      h += '</tbody></table></div>';
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

// ── Insights ────────────────────────────────────────────────────
async function _seoInsights(el) {
  try {
    var data = await _seoApi('GET', '/ai-status');
    el.innerHTML = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 16px">SEO Insights</h2>' +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">' +
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px"><div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:12px">AI Agent Status</div><pre style="white-space:pre-wrap;font-size:12px;color:var(--t2)">' + JSON.stringify(data, null, 2) + '</pre></div>' +
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px"><div style="font-size:28px;margin-bottom:12px">🤖</div><div style="font-size:14px;font-weight:600;color:var(--t1)">AI Report</div><div style="font-size:12px;color:var(--t2);margin-top:4px">Get AI-powered SEO analysis</div></div>' +
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px"><div style="font-size:28px;margin-bottom:12px">'+window.icon("search",14)+'</div><div style="font-size:14px;font-weight:600;color:var(--t1)">SERP Analysis</div><div style="font-size:12px;color:var(--t2);margin-top:4px">Competitive landscape analysis</div></div></div>';
  } catch(e) { el.innerHTML = _seoError(e); }
}

// ── Reports ─────────────────────────────────────────────────────
async function _seoReports(el) {
  try {
    var data = await _seoApi('GET', '/report');
    el.innerHTML = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 16px">SEO Reports</h2>' +
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px"><pre style="white-space:pre-wrap;font-size:12px;color:var(--t2);max-height:600px;overflow-y:auto">' + JSON.stringify(data, null, 2) + '</pre></div>';
  } catch(e) { el.innerHTML = _seoError(e); }
}

// ── Outbound Links ──────────────────────────────────────────────
async function _seoOutbound(el) {
  try {
    var data = await _seoApi('GET', '/outbound');
    var links = data.links || data || [];
    var h = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px"><div><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0">Outbound Links</h2><p style="font-size:13px;color:var(--t2);margin-top:4px">Monitor external links on your site</p></div><button class="btn btn-primary" onclick="_seoCheckOutbound()">Check Links</button></div>';
    if (!Array.isArray(links) || links.length === 0) {
      h += '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">'+window.icon("globe",14)+'</div><p>No outbound links scanned yet.</p></div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr><th style="text-align:left;padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">URL</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Status</th></tr></thead><tbody>';
      links.forEach(function(l) { h += '<tr><td style="padding:10px 12px;color:var(--bl);border-bottom:1px solid rgba(255,255,255,.04);word-break:break-all">' + (l.url || '—') + '</td><td style="padding:10px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.04)">' + (l.status_code || '—') + '</td></tr>'; });
      h += '</tbody></table></div>';
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}
async function _seoCheckOutbound() { var el = document.getElementById('seo-content'); if(el) el.innerHTML = loadingCard(300); try { await _seoApi('POST', '/outbound/check', {}); showToast('Scan started!', 'success'); setTimeout(function(){ _seoOutbound(el); }, 2000); } catch(e) { showToast('Failed', 'error'); _seoOutbound(el); } }

// ── Integrations ────────────────────────────────────────────────
async function _seoIntegrations(el) {
  try {
    var data = await _seoApi('GET', '/agent-status');
    el.innerHTML = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 16px">Integrations</h2>' +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">' +
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px"><div style="font-size:28px;margin-bottom:12px">'+window.icon("globe",14)+'</div><div style="font-size:15px;font-weight:600;color:var(--t1);margin-bottom:6px">Google Search Console</div><div style="font-size:12px;color:var(--t2)">Import keyword rankings and impressions</div></div>' +
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px"><div style="font-size:28px;margin-bottom:12px">🤖</div><div style="font-size:15px;font-weight:600;color:var(--t1);margin-bottom:6px">AI SEO Agent</div><pre style="font-size:11px;color:var(--t2);white-space:pre-wrap;background:var(--s2);padding:10px;border-radius:8px;margin-top:8px">' + JSON.stringify(data, null, 2) + '</pre></div>' +
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px"><div style="font-size:28px;margin-bottom:12px">'+window.icon("chart",14)+'</div><div style="font-size:15px;font-weight:600;color:var(--t1);margin-bottom:6px">Google Analytics</div><div style="font-size:12px;color:var(--t2)">Traffic data integration — coming soon</div></div></div>';
  } catch(e) { el.innerHTML = _seoError(e); }
}

// ── Stub page (for tabs migrating from WP) ──────────────────────
// ── Redirects ─────────────────────────────────────────────────────
async function _seoRedirects(el) {
  try {
    var data = await _seoApi('GET', '/redirects');
    var redirects = data.redirects || [];
    var logData = await _seoApi('GET', '/404-log');
    var entries = logData.entries || [];
    var h = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
      '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0">Redirects</h2>' +
      '<button class="btn btn-primary" onclick="_seoAddRedirect()">+ Add Redirect</button></div>';
    if (redirects.length === 0) {
      h += '<div style="text-align:center;padding:40px;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:12px;margin-bottom:24px"><div style="font-size:40px;margin-bottom:14px">&#8618;</div><p>No redirects configured yet. Add your first redirect rule.</p></div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden;margin-bottom:24px"><table style="width:100%;border-collapse:collapse;font-size:13px">';
      h += '<thead><tr><th style="text-align:left;padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">From</th><th style="text-align:left;padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">To</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Type</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Actions</th></tr></thead><tbody>';
      redirects.forEach(function(r) {
        h += '<tr><td style="padding:10px 12px;color:var(--t1);border-bottom:1px solid rgba(255,255,255,.04);word-break:break-all">' + (r.from || '—') + '</td>' +
          '<td style="padding:10px 12px;color:var(--bl);border-bottom:1px solid rgba(255,255,255,.04);word-break:break-all">' + (r.to || '—') + '</td>' +
          '<td style="padding:10px 12px;text-align:center;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (r.type || 301) + '</td>' +
          '<td style="padding:10px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.04)"><button class="btn btn-outline btn-sm" onclick="_seoDeleteRedirect(' + r.id + ')" style="font-size:11px;color:var(--rd)">Delete</button></td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '<h3 style="font-family:var(--fh);font-size:16px;font-weight:600;color:var(--t1);margin:0 0 12px">404 Error Log</h3>';
    if (entries.length === 0) {
      h += '<div style="text-align:center;padding:30px;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:12px"><p>No 404 errors logged yet. This is a good sign!</p></div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px">';
      h += '<thead><tr><th style="text-align:left;padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">URL</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Hits</th><th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Last Seen</th></tr></thead><tbody>';
      entries.forEach(function(e) {
        h += '<tr><td style="padding:10px 12px;color:var(--t1);border-bottom:1px solid rgba(255,255,255,.04);word-break:break-all">' + (e.url || '—') + '</td>' +
          '<td style="padding:10px 12px;text-align:center;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (e.hits || 0) + '</td>' +
          '<td style="padding:10px 12px;text-align:center;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (e.last_seen || '—') + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

async function _seoAddRedirect() {
  var from = await luPrompt('From path (e.g. /old-page):', 'Add Redirect');
  if (!from) return;
  var to = await luPrompt('To URL (e.g. /new-page or https://...):', 'Destination');
  if (!to) return;
  var type = await luPrompt('Redirect type (301 or 302):', 'Redirect Type', '301');
  if (!type) type = '301';
  try {
    await _seoApi('POST', '/redirects', { from: from, to: to, type: parseInt(type) });
    showToast('Redirect created!', 'success');
    _seoRedirects(document.getElementById('seo-content'));
  } catch(e) { showToast('Failed: ' + e.message, 'error'); }
}

async function _seoDeleteRedirect(id) {
  var ok = await luConfirm('Delete this redirect?', 'Delete Redirect', 'Delete', 'Cancel'); if (!ok) return;
  try {
    await _seoApi('DELETE', '/redirects/' + id);
    showToast('Redirect deleted.', 'success');
    _seoRedirects(document.getElementById('seo-content'));
  } catch(e) { showToast('Failed: ' + e.message, 'error'); }
}

// ── Images ────────────────────────────────────────────────────────
function _seoImages(el) {
  el.innerHTML = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Image Optimization</h2>' +
    '<p style="font-size:13px;color:var(--t2);margin-bottom:20px">Optimize images for faster page load and better SEO</p>' +
    '<div style="text-align:center;padding:60px;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:12px">' +
    '<div style="font-size:48px;margin-bottom:16px">&#128444;</div>' +
    '<p style="font-size:14px;margin-bottom:8px">Image optimization requires website content.</p>' +
    '<p style="font-size:13px">Add pages to your site first, then return here to scan and optimize images.</p></div>';
}

// ── SEO Settings ──────────────────────────────────────────────────
async function _seoSettings(el) {
  try {
    var data = await _seoApi('GET', '/settings');
    var h = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">SEO Settings</h2>' +
      '<p style="font-size:13px;color:var(--t2);margin-bottom:20px">Configure title templates, meta descriptions, and sitemap settings</p>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px;max-width:600px">';
    h += '<div style="margin-bottom:20px"><label style="display:block;font-size:13px;font-weight:600;color:var(--t1);margin-bottom:6px">Title Template</label>' +
      '<input type="text" class="form-input" id="seo-set-title" value="' + (data.title_template || '').replace(/"/g, '&amp;quot;') + '" placeholder="{page_title} | {site_name}" style="width:100%">' +
      '<div style="font-size:11px;color:var(--t3);margin-top:4px">Variables: {page_title}, {site_name}, {separator}</div></div>';
    h += '<div style="margin-bottom:20px"><label style="display:block;font-size:13px;font-weight:600;color:var(--t1);margin-bottom:6px">Meta Description Fallback</label>' +
      '<textarea class="form-input" id="seo-set-meta" rows="3" placeholder="Default meta description when none is set..." style="width:100%;resize:vertical">' + (data.meta_description_fallback || '') + '</textarea></div>';
    h += '<div style="margin-bottom:24px"><label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600;color:var(--t1);cursor:pointer">' +
      '<input type="checkbox" id="seo-set-sitemap"' + (data.sitemap_enabled ? ' checked' : '') + ' style="width:18px;height:18px"> Enable XML Sitemap</label>' +
      '<div style="font-size:11px;color:var(--t3);margin-top:4px;margin-left:28px">Automatically generate and update your sitemap.xml</div></div>';
    h += '<button class="btn btn-primary" onclick="_seoSaveSettings()">Save Settings</button></div>';
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

async function _seoSaveSettings() {
  var title = document.getElementById('seo-set-title');
  var meta = document.getElementById('seo-set-meta');
  var sitemap = document.getElementById('seo-set-sitemap');
  var payload = {
    title_template: title ? title.value : '',
    meta_description_fallback: meta ? meta.value : '',
    sitemap_enabled: sitemap ? sitemap.checked : true
  };
  try {
    await _seoApi('POST', '/settings', payload);
    showToast('SEO settings saved!', 'success');
  } catch(e) { showToast('Failed to save settings: ' + e.message, 'error'); }
}

// ── Score Settings ────────────────────────────────────────────────
async function _seoScoreSettings(el) {
  try {
    var data = await _seoApi('GET', '/score-weights');
    var factors = [
      { key: 'title', label: 'Title Tag', desc: 'Presence and quality of the page title' },
      { key: 'meta_description', label: 'Meta Description', desc: 'Presence and length of meta description' },
      { key: 'content_length', label: 'Content Length', desc: 'Minimum word count for quality content' },
      { key: 'internal_links', label: 'Internal Links', desc: 'Number of internal links on the page' },
      { key: 'image_alt', label: 'Image Alt Text', desc: 'Alt attributes on images' },
      { key: 'readability', label: 'Readability', desc: 'Flesch-Kincaid readability score' }
    ];
    var h = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Score Settings</h2>' +
      '<p style="font-size:13px;color:var(--t2);margin-bottom:20px">Configure how SEO scores are calculated for your content. Total must equal 100.</p>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:24px;max-width:600px">';
    var total = 0;
    factors.forEach(function(f) {
      var val = data[f.key] || 0;
      total += val;
      h += '<div style="margin-bottom:20px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">' +
        '<label style="font-size:13px;font-weight:600;color:var(--t1)">' + f.label + '</label>' +
        '<span id="seo-sw-val-' + f.key + '" style="font-size:13px;font-weight:700;color:var(--p,#6C5CE7);min-width:36px;text-align:right">' + val + '%</span></div>' +
        '<input type="range" id="seo-sw-' + f.key + '" min="0" max="50" value="' + val + '" ' +
        'oninput="document.getElementById(&quot;seo-sw-val-' + f.key + '&quot;).textContent=this.value+&quot;%&quot;;_seoUpdateWeightTotal()" ' +
        'style="width:100%;accent-color:var(--p,#6C5CE7)">' +
        '<div style="font-size:11px;color:var(--t3);margin-top:2px">' + f.desc + '</div></div>';
    });
    h += '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-top:1px solid var(--bd);margin-bottom:16px">' +
      '<span style="font-size:14px;font-weight:600;color:var(--t1)">Total</span>' +
      '<span id="seo-sw-total" style="font-size:14px;font-weight:700;color:' + (total === 100 ? 'var(--ac)' : 'var(--rd)') + '">' + total + '%</span></div>';
    h += '<button class="btn btn-primary" onclick="_seoSaveScoreWeights()">Save Score Weights</button></div>';
    el.innerHTML = h;
  } catch(e) { el.innerHTML = _seoError(e); }
}

function _seoUpdateWeightTotal() {
  var keys = ['title', 'meta_description', 'content_length', 'internal_links', 'image_alt', 'readability'];
  var total = 0;
  keys.forEach(function(k) {
    var el = document.getElementById('seo-sw-' + k);
    if (el) total += parseInt(el.value) || 0;
  });
  var totalEl = document.getElementById('seo-sw-total');
  if (totalEl) {
    totalEl.textContent = total + '%';
    totalEl.style.color = total === 100 ? 'var(--ac)' : 'var(--rd)';
  }
}

async function _seoSaveScoreWeights() {
  var keys = ['title', 'meta_description', 'content_length', 'internal_links', 'image_alt', 'readability'];
  var payload = {};
  var total = 0;
  keys.forEach(function(k) {
    var el = document.getElementById('seo-sw-' + k);
    var val = el ? parseInt(el.value) || 0 : 0;
    payload[k] = val;
    total += val;
  });
  if (total !== 100) {
    showToast('Weights must total 100%. Currently: ' + total + '%', 'error');
    return;
  }
  try {
    await _seoApi('POST', '/score-weights', payload);
    showToast('Score weights saved!', 'success');
  } catch(e) { showToast('Failed to save: ' + e.message, 'error'); }
}
function _seoStubPage(el, title, desc, icon) {
  el.innerHTML = '<h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">' + title + '</h2>' +
    '<p style="font-size:13px;color:var(--t2);margin-bottom:20px">' + desc + '</p>' +
    '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">' + icon + '</div><p>Migrating from WordPress module. Full functionality coming soon.</p></div>';
}

// ── Helpers ────────────────────────────────────────────────────────────
function _seoStat(label, value, color) {
  return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px"><div style="font-size:24px;font-weight:700;font-family:var(--fh);color:' + color + '">' + value + '</div><div style="font-size:12px;color:var(--t2);margin-top:4px">' + label + '</div></div>';
}

function _seoError(e) {
  return '<div style="background:rgba(248,113,113,.08);border:1px solid var(--rd,#F87171);border-radius:12px;padding:20px;color:var(--rd)"><strong>Error:</strong> ' + (e.message || e) + '</div>';
}

window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['seo'] = true;
console.log('[LevelUp] SEO engine loaded (Laravel native — 14 tabs, 33 routes)');


// ═══════════════════════════════════════════════════════════════
// KEYWORD USAGE + SCAN INFO PATCH — injected 2026-04-16
// Replaces the keyword display override with one that handles
// the new {keywords, usage, scan} response format + usage badge + scan info
// ═══════════════════════════════════════════════════════════════

(function() {
    window._seoKeywords = async function(el) {
        if (!el) return;
        try {
            var data = await _seoApi('GET', '/keywords');

            // Handle both old (array) and new ({keywords, usage, scan}) formats
            var kws, usage, scan;
            if (Array.isArray(data)) {
                kws = data; usage = null; scan = null;
            } else if (data.keywords) {
                kws = data.keywords || [];
                usage = data.usage || null;
                scan = data.scan || null;
            } else {
                kws = data || [];
                usage = null; scan = null;
            }

            // Header with usage badge
            var usageHtml = '';
            if (usage) {
                var atLimit = usage.limit > 0 && usage.count >= usage.limit;
                var barPct = usage.limit > 0 ? Math.min(100, Math.round((usage.count / usage.limit) * 100)) : 0;
                var barColor = atLimit ? '#EF4444' : '#6C5CE7';
                usageHtml = '<span style="display:inline-flex;align-items:center;gap:8px;margin-left:12px;font-size:13px;color:#9CA3AF">'
                    + '<span style="font-weight:600;color:' + (atLimit ? '#EF4444' : '#D1D5DB') + '">' + usage.count + '/' + usage.limit + ' keywords</span>'
                    + '<span style="width:50px;height:5px;background:rgba(255,255,255,.08);border-radius:3px;display:inline-block;overflow:hidden">'
                    + '<span style="display:block;height:100%;width:' + barPct + '%;background:' + barColor + ';border-radius:3px"></span></span>'
                    + (atLimit ? '<a href="/app/#upgrade" style="color:#A78BFA;font-size:11px;font-weight:600;text-decoration:none">Upgrade</a>' : '')
                    + '</span>';
            }

            var canAdd = !usage || usage.can_add;
            var addBtnStyle = canAdd ? '' : 'opacity:0.5;pointer-events:none;';
            var addBtnTitle = canAdd ? '' : ' title="Keyword limit reached"';

            var h = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">'
                + '<div style="display:flex;align-items:center"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0">Tracked Keywords</h2>' + usageHtml + '</div>'
                + '<button class="btn btn-primary" onclick="_seoAddKeyword()" style="' + addBtnStyle + '"' + addBtnTitle + '>+ Add Keyword</button></div>';

            if (kws.length === 0) {
                if (usage && usage.limit === 0) {
                    h += '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">'+window.icon("lock",14)+'</div><p>Keyword tracking requires AI Lite plan or higher.</p><a href="/app/#upgrade" style="color:#A78BFA">Upgrade your plan →</a></div>';
                } else {
                    h += '<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:14px">'+window.icon("lock",14)+'</div><p>No keywords tracked yet.</p></div>';
                }
            } else {
                h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px">';
                h += '<thead><tr>'
                    + '<th style="text-align:left;padding:12px;color:var(--t3);border-bottom:1px solid var(--bd)">Keyword</th>'
                    + '<th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd);text-align:center">Rank</th>'
                    + '<th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd);text-align:center">Change</th>'
                    + '<th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd);text-align:center">Volume</th>'
                    + '<th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd);text-align:center">Last Checked</th>'
                    + '<th style="padding:12px;color:var(--t3);border-bottom:1px solid var(--bd);text-align:center">Actions</th>'
                    + '</tr></thead><tbody>';

                kws.forEach(function(k) {
                    var rank = k.current_rank || k.position;
                    var rankDisplay = rank ? '#' + rank : '<span style="color:var(--t3)">—</span>';
                    var rankColor = rank ? (rank <= 3 ? 'var(--gn)' : (rank <= 10 ? '#F59E0B' : 'var(--t2)')) : 'var(--t3)';

                    var change = k.rank_change;
                    var changeDisplay = '<span style="color:var(--t3)">—</span>';
                    if (change !== null && change !== undefined && change !== 0) {
                        changeDisplay = change > 0
                            ? '<span style="color:var(--gn);font-weight:600">↑' + change + '</span>'
                            : '<span style="color:var(--rd);font-weight:600">↓' + Math.abs(change) + '</span>';
                    }

                    var lastCheck = k.last_rank_check;
                    var lastCheckDisplay = '<span style="font-size:11px;color:var(--t3)">Never</span>';
                    if (lastCheck) {
                        var d = new Date(lastCheck);
                        var diffH = Math.round((new Date() - d) / 3600000);
                        if (diffH < 1) lastCheckDisplay = '<span style="font-size:11px;color:var(--t3)">Just now</span>';
                        else if (diffH < 24) lastCheckDisplay = '<span style="font-size:11px;color:var(--t3)">' + diffH + 'h ago</span>';
                        else lastCheckDisplay = '<span style="font-size:11px;color:var(--t3)">' + Math.round(diffH/24) + 'd ago</span>';
                    }

                    var rs = 'padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.04)';
                    h += '<tr>'
                        + '<td style="' + rs + ';color:var(--t1)">' + (k.keyword || '—') + '</td>'
                        + '<td style="' + rs + ';text-align:center;color:' + rankColor + ';font-weight:600">' + rankDisplay + '</td>'
                        + '<td style="' + rs + ';text-align:center">' + changeDisplay + '</td>'
                        + '<td style="' + rs + ';text-align:center;color:var(--t2)">' + (k.volume || '—') + '</td>'
                        + '<td style="' + rs + ';text-align:center">' + lastCheckDisplay + '</td>'
                        + '<td style="' + rs + ';text-align:center"><button class="btn btn-outline btn-sm" onclick="_seoDeleteKeyword(' + k.id + ')" style="font-size:11px;color:var(--rd)">Delete</button></td>'
                        + '</tr>';
                });
                h += '</tbody></table></div>';
            }

            // Scan schedule info
            if (scan) {
                h += '<div style="margin-top:16px;padding:12px 16px;background:var(--s1);border:1px solid var(--bd);border-radius:8px;font-size:12px;color:var(--t3)">';
                if (scan.frequency === 'never') {
                    h += ''+window.icon("lock",14)+' Keyword rank scanning requires AI Lite plan or higher.';
                } else if (scan.has_scanned) {
                    h += ''+window.icon("check",14)+' Last scan: ' + scan.last_scan_date_formatted + ' · Next scan: ' + scan.next_scan_date_formatted;
                } else {
                    h += ''+window.icon("search",14)+' Your first keyword scan will run on ' + (scan.next_scan_date_formatted || 'the next Monday') + '.';
                }
                h += '</div>';
            }

            el.innerHTML = h;
        } catch(e) {
            if (typeof _seoError === 'function') el.innerHTML = _seoError(e);
            else el.innerHTML = '<div style="color:var(--rd)">Error: ' + e.message + '</div>';
        }
    };
})();


// ───────────────────────────────────────────────────────────────────────────
// SEO Gap Build Week 1 (LB-SEO-Gap-Week1, 2026-04-27)
// ───────────────────────────────────────────────────────────────────────────

// Build 5 — Pages tab (indexed content table with inline editing)
window._seoPagesState = { page: 1, filter: '', sort: 'score', q: '' };

async function _seoPages(el) {
  try {
    var st = window._seoPagesState;
    var qs = '?page=' + st.page + '&per_page=25&sort=' + encodeURIComponent(st.sort) + '&filter=' + encodeURIComponent(st.filter) + '&q=' + encodeURIComponent(st.q);
    var d = await _seoApi('GET', '/indexed-content' + qs);
    var items = d.items || [];

    var h = '<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    h += '<div><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Pages</h2>';
    h += '<p style="font-size:13px;color:var(--t2);margin:0">' + (d.total || 0) + ' indexed pages — click any cell to edit</p></div>';
    h += '<div style="display:flex;gap:6px;flex-wrap:wrap">';
    h += '<input type="text" placeholder="Search url/title…" value="' + (st.q||'') + '" oninput="window._seoPagesState.q=this.value" onkeydown="if(event.key===\'Enter\'){window._seoPagesState.page=1;_seoSwitchTab(\'pages\')}" style="background:var(--s1);border:1px solid var(--bd);color:var(--t1);border-radius:6px;padding:6px 10px;font-size:12px;width:160px">';
    var filterOpts = [['','All'],['low_score','Low score (<50)'],['missing_meta','Missing meta'],['thin_content','Thin content'],['no_h1','No H1']];
    h += '<select onchange="window._seoPagesState.filter=this.value;window._seoPagesState.page=1;_seoSwitchTab(\'pages\')" style="background:var(--s1);border:1px solid var(--bd);color:var(--t1);border-radius:6px;padding:6px 10px;font-size:12px">';
    filterOpts.forEach(function(o){ h += '<option value="'+o[0]+'"'+(st.filter===o[0]?' selected':'')+'>'+o[1]+'</option>'; });
    h += '</select>';
    h += '<button onclick="_seoSwitchTab(\'pages\')" style="background:none;border:1px solid var(--bd);color:var(--t3);border-radius:6px;padding:6px 12px;font-size:11px;cursor:pointer">↻ Refresh</button>';
    h += '</div></div>';

    if (items.length === 0) {
      h += '<div style="padding:40px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px">No pages indexed yet. Run a deep audit to populate this list.</div>';
      el.innerHTML = h; return;
    }

    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:12px">';
    h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
    ['URL','Score','Title','Meta description','H1','Words','Issues',''].forEach(function(c){
      h += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">'+c+'</th>';
    });
    h += '</tr></thead><tbody>';
    items.forEach(function(p){
      var sc = p.content_score || 0;
      var scColor = sc >= 70 ? '#10B981' : sc >= 50 ? '#F59E0B' : '#F87171';
      h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
      h += '<td style="padding:9px 12px;color:var(--t2);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+p.url+'"><a href="'+p.url+'" target="_blank" style="color:var(--t2);text-decoration:none">'+p.url+'</a></td>';
      h += '<td style="padding:9px 12px"><span style="background:'+scColor+'20;color:'+scColor+';padding:2px 8px;border-radius:8px;font-weight:700">'+sc+'</span></td>';
      h += '<td style="padding:9px 12px"><input value="'+(p.meta_title||p.title||'').replace(/"/g,'&quot;')+'" onblur="_seoPageSave('+p.id+',\'meta_title\',this.value,this)" style="background:transparent;border:none;color:var(--t1);font-size:12px;width:100%;padding:2px 0"></td>';
      h += '<td style="padding:9px 12px"><input value="'+(p.meta_description||'').replace(/"/g,'&quot;')+'" onblur="_seoPageSave('+p.id+',\'meta_description\',this.value,this)" style="background:transparent;border:none;color:var(--t1);font-size:12px;width:100%;padding:2px 0" placeholder="(empty)"></td>';
      h += '<td style="padding:9px 12px"><input value="'+(p.h1||'').replace(/"/g,'&quot;')+'" onblur="_seoPageSave('+p.id+',\'h1\',this.value,this)" style="background:transparent;border:none;color:var(--t1);font-size:12px;width:100%;padding:2px 0" placeholder="(empty)"></td>';
      h += '<td style="padding:9px 12px;color:var(--t2)">'+p.word_count+'</td>';
      h += '<td style="padding:9px 12px"><span style="color:'+(p.issues_count>0?'var(--am)':'var(--t3)')+'">'+p.issues_count+'</span></td>';
      h += '<td style="padding:9px 12px"></td>';
      h += '</tr>';
    });
    h += '</tbody></table></div>';

    if ((d.pages||1) > 1) {
      h += '<div style="margin-top:14px;display:flex;gap:6px;justify-content:center">';
      for (var i=1; i<=d.pages; i++) {
        var ac = (i===d.page);
        h += '<button onclick="window._seoPagesState.page='+i+';_seoSwitchTab(\'pages\')" style="background:'+(ac?'var(--p)':'var(--s1)')+';color:'+(ac?'#fff':'var(--t3)')+';border:1px solid var(--bd);border-radius:6px;padding:5px 10px;font-size:11px;cursor:pointer">'+i+'</button>';
      }
      h += '</div>';
    }

    el.innerHTML = h;
  } catch(e) {
    el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load pages: '+(e.message||e)+'</div>';
  }
}

window._seoPageSave = async function(id, field, value, inputEl) {
  try {
    var body = {}; body[field] = value;
    await _seoApi('PATCH', '/indexed-content/' + id, body);
    if (inputEl) {
      inputEl.style.background = 'rgba(16,185,129,.12)';
      setTimeout(function(){ inputEl.style.background = 'transparent'; }, 800);
    }
  } catch(e) {
    if (inputEl) inputEl.style.background = 'rgba(248,113,113,.12)';
    if (typeof showToast === 'function') showToast('Save failed: '+(e.message||e), 'error');
  }
};

// Build 2 — CTR Optimizer tab
async function _seoCtr(el) {
  try {
    var d = await _seoApi('GET', '/ctr-analysis');
    var h = '<div style="margin-bottom:16px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">CTR Optimizer</h2>';
    h += '<p style="font-size:13px;color:var(--t2);margin:0">Score 0–100 per page based on title/meta/intent alignment. Lower scores = bigger CTR opportunity.</p></div>';

    h += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px">';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px;text-align:center"><div style="font-size:22px;font-weight:700;color:var(--t1)">'+(d.total_pages||0)+'</div><div style="font-size:11px;color:var(--t3)">Pages analyzed</div></div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px;text-align:center"><div style="font-size:22px;font-weight:700;color:var(--p)">'+(d.avg_score||0)+'</div><div style="font-size:11px;color:var(--t3)">Average CTR score</div></div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px;text-align:center"><div style="font-size:22px;font-weight:700;color:#F87171">'+(d.low_performers||0)+'</div><div style="font-size:11px;color:var(--t3)">Low performers (≤49)</div></div>';
    h += '</div>';

    var worst = d.worst_performers || [];
    if (worst.length === 0) {
      h += '<div style="padding:40px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px">All pages scoring well! 🎉</div>';
    } else {
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Worst performers</div>';
      h += '<div style="display:flex;flex-direction:column;gap:8px">';
      worst.forEach(function(p){
        var sc = p.ctr_score, lbl = p.ctr_label;
        var col = sc >= 75 ? '#10B981' : sc >= 50 ? '#F59E0B' : sc >= 25 ? '#F87171' : '#EF4444';
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px">';
        h += '<div style="display:flex;align-items:center;gap:12px;margin-bottom:6px"><span style="background:'+col+'20;color:'+col+';padding:2px 10px;border-radius:8px;font-weight:700;font-size:12px">'+sc+' · '+lbl+'</span><div style="flex:1;font-size:12px;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+(p.title||p.url)+'</div></div>';
        if (p.ctr_reasons && p.ctr_reasons.length) {
          h += '<div style="font-size:11px;color:var(--t3);margin-bottom:4px">' + p.ctr_reasons.map(function(r){ return '• '+r; }).join('  ') + '</div>';
        }
        if (p.ctr_suggestions && p.ctr_suggestions.length) {
          h += '<div style="font-size:11px;color:var(--am);margin-top:6px"><strong>Suggestions:</strong> ' + p.ctr_suggestions.join(' · ') + '</div>';
        }
        h += '</div>';
      });
      h += '</div>';
    }

    el.innerHTML = h;
  } catch(e) {
    el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load CTR analysis: '+(e.message||e)+'</div>';
  }
}

// Build 3 — Quick Wins tab
async function _seoWins(el) {
  try {
    var d = await _seoApi('GET', '/quick-wins?limit=20');
    var wins = d.quick_wins || [];

    var h = '<div style="margin-bottom:16px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Quick Wins</h2>';
    h += '<p style="font-size:13px;color:var(--t2);margin:0">High-impact fixes you can ship in under 30 minutes each.</p></div>';

    if (wins.length === 0) {
      h += '<div style="padding:40px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px">No quick wins found — your pages look healthy! 🎉</div>';
      el.innerHTML = h; return;
    }

    h += '<div style="display:flex;flex-direction:column;gap:8px">';
    wins.forEach(function(w){
      var imp = w.impact, mins = w.fix_minutes;
      var impCol = imp >= 25 ? '#10B981' : imp >= 15 ? '#F59E0B' : 'var(--t3)';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px;display:flex;align-items:center;gap:14px">';
      h += '<div style="text-align:center;min-width:60px"><div style="background:'+impCol+'20;color:'+impCol+';font-size:18px;font-weight:700;padding:6px 10px;border-radius:8px">+' + imp + '</div><div style="font-size:9px;color:var(--t3);margin-top:3px">IMPACT</div></div>';
      h += '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:3px">'+w.issue+'</div>';
      h += '<div style="font-size:11px;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+(w.title||w.url)+'</div>';
      h += '<div style="font-size:11px;color:var(--am);margin-top:5px">→ '+w.fix+'</div></div>';
      h += '<div style="text-align:center;min-width:80px"><div style="font-size:14px;font-weight:600;color:var(--t1)">'+mins+'m</div><div style="font-size:10px;color:var(--t3)">FIX TIME</div><button onclick="_seoSwitchTab(\'pages\')" style="background:var(--p);border:none;color:#fff;padding:4px 10px;border-radius:5px;font-size:11px;cursor:pointer;margin-top:6px">Fix</button></div>';
      h += '</div>';
    });
    h += '</div>';

    el.innerHTML = h;
  } catch(e) {
    el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load quick wins: '+(e.message||e)+'</div>';
  }
}


// ───────────────────────────────────────────────────────────────────────────
// SEO Week 2 Session 1 (LB-SEO-W2, 2026-04-27)
// ───────────────────────────────────────────────────────────────────────────

// Build 1 — Google Search Console tab
async function _seoGsc(el) {
  try {
    var status = await _seoApi('GET', '/gsc/status');
    var h = '<div style="margin-bottom:16px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Search Console</h2>';
    h += '<p style="font-size:13px;color:var(--t2);margin:0">Real CTR + impressions + position from Google.</p></div>';

    if (!status.connected) {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:24px;text-align:center">';
      if (!status.configured) {
        h += '<div style="font-size:14px;font-weight:600;color:var(--am);margin-bottom:8px">Configuration required</div>';
        h += '<div style="font-size:12px;color:var(--t2);max-width:480px;margin:0 auto">Set <code>GSC_CLIENT_ID</code> + <code>GSC_CLIENT_SECRET</code> in the platform <code>.env</code>, restart, then click Connect.</div>';
      } else {
        h += '<div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:8px">Not connected</div>';
        h += '<div style="font-size:12px;color:var(--t2);margin-bottom:16px">Connect Google Search Console to pull real CTR + impression data per page and query.</div>';
        h += '<button onclick="_seoGscConnect()" style="background:var(--p);border:none;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">Connect Search Console →</button>';
      }
      h += '</div>';
      el.innerHTML = h;
      return;
    }

    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    h += '<div><div style="font-size:13px;font-weight:600;color:#10B981">✓ Connected</div>';
    h += '<div style="font-size:11px;color:var(--t3);margin-top:2px">Property: ' + (status.site_url||'(unknown)') + '</div>';
    h += '<div style="font-size:11px;color:var(--t3)">Last synced: ' + (status.last_synced_at ? new Date(status.last_synced_at).toLocaleString() : 'never') + '</div></div>';
    h += '<div style="display:flex;gap:6px">';
    h += '<button onclick="_seoGscSync()" style="background:var(--p);border:none;color:#fff;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer">Sync now</button>';
    h += '<button onclick="_seoGscDisconnect()" style="background:none;border:1px solid var(--bd);color:var(--rd);border-radius:6px;padding:7px 14px;font-size:12px;cursor:pointer">Disconnect</button>';
    h += '</div></div>';

    var qs = await _seoApi('GET', '/gsc/queries?limit=20');
    var queries = (qs && qs.queries) || [];
    if (queries.length === 0) {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:32px;text-align:center;color:var(--t3);font-size:13px">No data yet. Click <strong>Sync now</strong> to pull last 30 days from Search Console.</div>';
    } else {
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Top queries</div>';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      ['Query','Clicks','Impressions','CTR','Position'].forEach(function(c){
        h += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">'+c+'</th>';
      });
      h += '</tr></thead><tbody>';
      queries.forEach(function(q){
        h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:9px 12px;color:var(--t1)">'+_luEsc(q.query||'')+'</td>';
        h += '<td style="padding:9px 12px;color:var(--t1);font-weight:600">'+(q.clicks||0)+'</td>';
        h += '<td style="padding:9px 12px;color:var(--t2)">'+(q.impressions||0)+'</td>';
        h += '<td style="padding:9px 12px;color:var(--t2)">'+((q.ctr*100).toFixed(2))+'%</td>';
        h += '<td style="padding:9px 12px;color:var(--t2)">'+(q.position||0).toFixed(1)+'</td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';
    }

    el.innerHTML = h;
  } catch(e) {
    el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load Search Console: '+(e.message||e)+'</div>';
  }
}

window._seoGscConnect = async function() {
  try {
    var d = await _seoApi('GET', '/gsc/auth-url');
    if (d && d.url) window.location.href = d.url;
  } catch(e) { if (typeof showToast==='function') showToast('Could not generate auth URL', 'error'); }
};

window._seoGscSync = async function() {
  if (typeof showToast==='function') showToast('Syncing… this can take 10-20 seconds', 'info');
  try {
    var d = await _seoApi('POST', '/gsc/sync');
    if (d && d.success) {
      if (typeof showToast==='function') showToast('Synced '+(d.synced_rows||0)+' rows from GSC', 'success');
      _seoSwitchTab('gsc');
    } else {
      if (typeof showToast==='function') showToast('Sync failed: '+(d.error||'unknown'), 'error');
    }
  } catch(e) { if (typeof showToast==='function') showToast('Sync error', 'error'); }
};

window._seoGscDisconnect = async function() {
  if (!confirm('Disconnect Search Console? You can reconnect anytime.')) return;
  try {
    await _seoApi('POST', '/gsc/disconnect');
    _seoSwitchTab('gsc');
  } catch(e) {}
};

// Build 2 — Image issues renderer (override existing _seoImages if present)
window._seoImages = async function(el) {
  try {
    var summary = await _seoApi('GET', '/image-summary');
    var issuesData = await _seoApi('GET', '/image-issues?limit=200');
    var issues = Array.isArray(issuesData) ? issuesData : (issuesData.issues || []);

    var h = '<div style="margin-bottom:16px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Images</h2>';
    h += '<p style="font-size:13px;color:var(--t2);margin:0">Audit images on indexed pages — alt text, format, dimensions.</p></div>';

    h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px">';
    var s = summary.summary || summary;
    [['Missing alt', s.missing_alt||0, '#F87171'],
     ['Empty alt',   s.empty_alt||0,   '#F59E0B'],
     ['Bad filename',s.filename_unfriendly||0, '#F59E0B'],
     ['Wrong format',s.wrong_format||0,'#F87171']].forEach(function(c){
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:12px;text-align:center"><div style="font-size:20px;font-weight:700;color:'+c[2]+'">'+c[1]+'</div><div style="font-size:10px;color:var(--t3);margin-top:2px;text-transform:uppercase">'+c[0]+'</div></div>';
    });
    h += '</div>';

    if (issues.length === 0) {
      h += '<div style="padding:40px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px">No image issues found across indexed pages. 🎉</div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      ['Page','Image','Issue','Current alt','Action'].forEach(function(c){ h += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">'+c+'</th>'; });
      h += '</tr></thead><tbody>';
      issues.slice(0,80).forEach(function(i, idx){
        h += '<tr id="img-row-'+idx+'" style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:9px 12px;color:var(--t2);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+_luEsc(i.page_url||'')+'">'+_luEsc(i.page_url||'')+'</td>';
        h += '<td style="padding:9px 12px;color:var(--t2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+_luEsc(i.image_src||'')+'"><a href="'+_luEsc(i.image_src||'#')+'" target="_blank" style="color:var(--t2);text-decoration:none">'+_luEsc(i.filename||'')+'</a></td>';
        h += '<td style="padding:9px 12px"><span style="background:rgba(245,158,11,.12);color:var(--am);padding:2px 8px;border-radius:8px;font-size:10px">'+_luEsc(i.issue_label||i.issue_type)+'</span></td>';
        h += '<td style="padding:9px 12px;color:var(--t2)">'+(_luEsc(i.current_alt||'(none)'))+'</td>';
        h += '<td style="padding:9px 12px"><button onclick="_seoImgSuggest(\''+_luEsc(i.image_src).replace(/\'/g,"\\'")+'\',\''+_luEsc(i.page_context||'').replace(/\'/g,"\\'")+'\','+idx+')" style="background:none;border:1px solid var(--p);color:var(--p);padding:3px 10px;border-radius:5px;font-size:10px;cursor:pointer">Suggest alt</button></td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';
    }

    el.innerHTML = h;
  } catch(e) { el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load images: '+(e.message||e)+'</div>'; }
};

window._seoImgSuggest = async function(imageSrc, pageContext, idx) {
  var row = document.getElementById('img-row-'+idx);
  if (!row) return;
  try {
    var d = await _seoApi('POST', '/image-issues/suggest-alt', { image_src: imageSrc, page_context: pageContext });
    var alt = d.suggested_alt || '(no suggestion)';
    var altCell = row.cells[3];
    altCell.innerHTML = '<input value="'+_luEsc(alt).replace(/"/g,'&quot;')+'" style="background:var(--s2);border:1px solid var(--p);color:var(--t1);font-size:12px;padding:3px 6px;border-radius:4px;width:100%" readonly onclick="this.select()">';
    if (typeof showToast==='function') showToast('Suggested: '+alt, 'success');
  } catch(e) { if (typeof showToast==='function') showToast('Could not suggest alt', 'error'); }
};

// Build 3 — Anchor analysis renderer (extends existing 'links' tab)
// Override _seoLinks if it exists, otherwise add it.
var _seoLinksOriginal = window._seoLinks;
window._seoLinks = async function(el) {
  try {
    var opps  = await _seoApi('GET', '/link-opportunities');
    var anchorR = await _seoApi('GET', '/links/anchor-analysis');
    var anchors = (anchorR && anchorR.issues) || [];

    var h = '<div style="margin-bottom:16px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Internal Links</h2>';
    h += '<p style="font-size:13px;color:var(--t2);margin:0">Find linking opportunities + audit existing anchor text.</p></div>';

    // Anchor analysis section
    h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px"><div style="font-size:13px;font-weight:600;color:var(--t1)">Anchor analysis</div></div>';
    if (anchors.length === 0) {
      h += '<div style="padding:24px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px;margin-bottom:18px">No anchor issues found in current articles.</div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden;margin-bottom:18px"><table style="width:100%;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      ['Article','Anchor','Issue','Fix'].forEach(function(c){ h += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">'+c+'</th>'; });
      h += '</tr></thead><tbody>';
      anchors.slice(0,30).forEach(function(a){
        var typeColor = a.issue_type === 'over_optimised' ? '#F87171' : (a.issue_type === 'too_long' ? '#F59E0B' : 'var(--am)');
        h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:9px 12px;color:var(--t2);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+_luEsc(a.article_title||'')+'</td>';
        h += '<td style="padding:9px 12px;color:var(--t1);font-weight:600">"'+_luEsc(a.anchor_text||'')+'"</td>';
        h += '<td style="padding:9px 12px"><span style="background:'+typeColor+'20;color:'+typeColor+';padding:2px 8px;border-radius:8px;font-size:10px">'+_luEsc(a.issue_label||'')+'</span></td>';
        h += '<td style="padding:9px 12px;color:var(--t2);font-size:11px">'+_luEsc(a.fix||'')+'</td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';
    }

    // Link opportunities section
    var oppsArr = (opps && opps.opportunities) || [];
    var orphArr = (opps && opps.orphans) || [];
    h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Link opportunities</div>';
    if (oppsArr.length === 0) {
      h += '<div style="padding:24px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px">Need at least 2 indexed pages with overlapping topics to detect opportunities.</div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      ['Source','Target','Anchor','Score','Action'].forEach(function(c){ h += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">'+c+'</th>'; });
      h += '</tr></thead><tbody>';
      oppsArr.slice(0,30).forEach(function(o){
        h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:9px 12px;color:var(--t2);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+_luEsc(o.source_title||o.source_url||'')+'</td>';
        h += '<td style="padding:9px 12px;color:var(--t2);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+_luEsc(o.target_title||o.target_url||'')+'</td>';
        h += '<td style="padding:9px 12px;color:var(--t1)">"'+_luEsc(o.suggested_anchor||'')+'"</td>';
        h += '<td style="padding:9px 12px"><span style="background:rgba(108,92,231,.12);color:var(--p);padding:2px 8px;border-radius:8px;font-weight:700">'+(o.score||0)+'</span></td>';
        h += '<td style="padding:9px 12px"><button onclick="_seoApplyLink(' + o.source_id + ',\'' + _luEsc(o.suggested_anchor||'').replace(/\'/g, "\\'") + '\',\'' + _luEsc(o.target_url||'').replace(/\'/g, "\\'") + '\')" style="background:none;border:1px solid var(--p);color:var(--p);padding:3px 10px;border-radius:5px;font-size:10px;cursor:pointer">Apply →</button></td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';
    }

    if (orphArr.length > 0) {
      h += '<div style="font-size:13px;font-weight:600;color:var(--am);margin-top:18px;margin-bottom:8px">Orphan pages (no internal links pointing to them)</div>';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:10px"><ul style="margin:0;padding-left:20px;font-size:12px;color:var(--t2)">';
      orphArr.slice(0,10).forEach(function(o){ h += '<li><a href="'+_luEsc(o.url||'')+'" target="_blank" style="color:var(--t2)">'+_luEsc(o.title||o.url)+'</a> · '+(o.word_count||0)+' words · score '+(o.content_score||0)+'</li>'; });
      h += '</ul></div>';
    }

    el.innerHTML = h;
  } catch(e) {
    el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load: '+(e.message||e)+'</div>';
  }
};

window._seoApplyLink = async function(sourceId, anchor, targetUrl) {
  // sourceId is an article id from seo_content_index? Actually opportunity returns source_id which is the indexed page id, not the article id.
  // For staging-MVP: prompt user for article id (most pages map 1:1 to an article).
  var artIdRaw = null; // P0-AUD-FIX1: native prompt() removed. This function lives in the dead W2S1 block (see banner at top of file); the canonical lgse path is the only live runtime entry.
  if (!artIdRaw) return;
  var artId = parseInt(artIdRaw, 10);
  if (!artId) return;
  try {
    var r = await _seoApi('POST', '/links/insert', { article_id: artId, anchor_text: anchor, target_url: targetUrl });
    if (r && r.pending_approval) {
      if (typeof showToast==='function') showToast('Link insertion sent for approval', 'success');
    } else {
      if (typeof showToast==='function') showToast('Insert response: '+JSON.stringify(r).slice(0,80), 'info');
    }
  } catch(e) { if (typeof showToast==='function') showToast('Insert failed', 'error'); }
};

// ═══════════════════════════════════════════════════════════════════════════
// SEO Week 2 Session 2 (LB-SEO-W2S2, 2026-04-27) — Frontend extension
// Adds: Anchor Intelligence (FULL), Real Link Graph, Equity Flow, Semantic Clusters.
// Append-only — overrides _seoLinks, _seoRenderShell, _seoSwitchTab via wrappers.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  // Inject Topics tab into the tab strip after Links.
  var _origRender = window._seoRenderShell || _seoRenderShell;
  window._seoRenderShell = function (el) {
    _origRender(el);
    var linksTab = document.getElementById('seo-tab-links');
    if (linksTab && !document.getElementById('seo-tab-topics')) {
      var t = document.createElement('div');
      t.className = 'seo-tab';
      t.id = 'seo-tab-topics';
      t.style.cssText = 'padding:10px 16px;cursor:pointer;font-size:13px;font-weight:500;color:var(--t2);border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap';
      var ico = (typeof window.icon === 'function') ? window.icon('chart', 14) : '';
      t.innerHTML = ico + ' Topics';
      t.onclick = function () { _seoSwitchTab('topics'); };
      linksTab.parentNode.insertBefore(t, linksTab.nextSibling);
    }
  };

  // Hook _seoSwitchTab to dispatch 'topics'.
  var _origSwitch = window._seoSwitchTab || _seoSwitchTab;
  window._seoSwitchTab = function (tab) {
    if (tab === 'topics') {
      window._seoTab = tab;
      document.querySelectorAll('.seo-tab').forEach(function (t) {
        t.style.color = 'var(--t2)';
        t.style.borderBottomColor = 'transparent';
      });
      var active = document.getElementById('seo-tab-topics');
      if (active) {
        active.style.color = 'var(--p,#6C5CE7)';
        active.style.borderBottomColor = 'var(--p,#6C5CE7)';
      }
      var content = document.getElementById('seo-content');
      if (content) {
        content.innerHTML = (typeof loadingCard === 'function') ? loadingCard(300) : 'Loading…';
        _seoTopics(content);
      }
      return;
    }
    return _origSwitch(tab);
  };

  // Local helpers (do not collide with core).
  function esc(s) { return (typeof window._luEsc === 'function') ? window._luEsc(s || '') : String(s || '').replace(/[<>"]/g, ''); }
  function toast(msg, kind) { if (typeof window.showToast === 'function') window.showToast(msg, kind || 'info'); }
  function bar(pct, color) {
    pct = Math.max(0, Math.min(100, pct));
    return '<div style="background:rgba(255,255,255,.06);height:6px;border-radius:3px;overflow:hidden">' +
      '<div style="background:' + color + ';height:6px;width:' + pct + '%;transition:width .3s"></div></div>';
  }
  function scoreColor(s) { return s >= 70 ? '#10B981' : (s >= 40 ? '#F59E0B' : '#EF4444'); }
  function chip(text, color) {
    return '<span style="background:' + color + '20;color:' + color + ';padding:2px 8px;border-radius:8px;font-size:10px;font-weight:600">' + esc(text) + '</span>';
  }

  // ── Override _seoLinks again (W2S2): extends with Link Graph + Equity sections.
  var _seoLinksW2S1 = window._seoLinks;
  window._seoLinks = async function (el) {
    try {
      var anchorR = await _seoApi('GET', '/links/anchor-analysis');
      var anchors = (anchorR && anchorR.issues) || [];
      var bulkR = await _seoApi('GET', '/anchors/bulk-analysis').catch(function () { return null; });
      var distribution = (bulkR && bulkR.distribution) || [];
      var bulkSummary = (bulkR && bulkR.summary) || null;
      var opps = await _seoApi('GET', '/link-opportunities');
      var oppsArr = (opps && opps.opportunities) || [];
      var orphArr = (opps && opps.orphans) || [];
      var graphR = await _seoApi('GET', '/link-graph').catch(function () { return null; });
      var graphNodes = (graphR && graphR.nodes) || [];

      var h = '';
      h += '<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">';
      h += '<div><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Link Intelligence</h2>';
      h += '<p style="font-size:13px;color:var(--t2);margin:0">Real link graph + anchor analysis + equity flow.</p></div>';
      h += '<div style="display:flex;gap:8px">';
      h += '<button onclick="_seoBuildLinkGraph()" style="background:var(--p);color:#fff;border:0;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">Build Link Graph</button>';
      h += '<button onclick="_seoCalcEquity()" style="background:none;border:1px solid var(--p);color:var(--p);padding:7px 13px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">Calculate Equity</button>';
      h += '</div></div>';

      // ── Anchor distribution summary ───────────────────────────────
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Anchor distribution</div>';
      if (bulkSummary) {
        var totalIssues = bulkSummary.total_issues || 0;
        var generic = bulkSummary.generic_issues || 0;
        var overOpt = bulkSummary.over_optimised || 0;
        var ok = Math.max(0, totalIssues - generic - overOpt);
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px;margin-bottom:18px;display:grid;grid-template-columns:repeat(3,1fr);gap:14px">';
        h += '<div><div style="font-size:11px;color:var(--t3);margin-bottom:6px">Generic</div><div style="font-size:18px;font-weight:700;color:#EF4444">' + generic + '</div>' + bar(totalIssues > 0 ? generic / totalIssues * 100 : 0, '#EF4444') + '</div>';
        h += '<div><div style="font-size:11px;color:var(--t3);margin-bottom:6px">Over-optimised</div><div style="font-size:18px;font-weight:700;color:#F59E0B">' + overOpt + '</div>' + bar(totalIssues > 0 ? overOpt / totalIssues * 100 : 0, '#F59E0B') + '</div>';
        h += '<div><div style="font-size:11px;color:var(--t3);margin-bottom:6px">Other</div><div style="font-size:18px;font-weight:700;color:#10B981">' + ok + '</div>' + bar(totalIssues > 0 ? ok / totalIssues * 100 : 0, '#10B981') + '</div>';
        h += '</div>';
      } else {
        h += '<div style="padding:18px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px;margin-bottom:18px">Run anchor bulk analysis to see distribution.</div>';
      }

      // ── Anchor issues table ───────────────────────────────────────
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Anchor issues</div>';
      if (anchors.length === 0) {
        h += '<div style="padding:18px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px;margin-bottom:18px">No anchor issues found.</div>';
      } else {
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden;margin-bottom:18px"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
        ['Article', 'Anchor', 'Issue', 'Fix'].forEach(function (c) { h += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">' + c + '</th>'; });
        h += '</tr></thead><tbody>';
        anchors.slice(0, 20).forEach(function (a) {
          var col = (a.issue_type === 'over_optimised' || a.issue_type === 'over_optimised_exact') ? '#F87171' : (a.issue_type === 'too_long' ? '#F59E0B' : '#FBBF24');
          h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
          h += '<td style="padding:9px 12px;color:var(--t2);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(a.article_title) + '</td>';
          h += '<td style="padding:9px 12px;color:var(--t1);font-weight:600">"' + esc(a.anchor_text) + '"</td>';
          h += '<td style="padding:9px 12px">' + chip(a.issue_label || '', col) + '</td>';
          h += '<td style="padding:9px 12px;color:var(--t2);font-size:11px">' + esc(a.fix) + '</td></tr>';
        });
        h += '</tbody></table></div>';
      }

      // ── Link opportunities (existing) ─────────────────────────────
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Link opportunities</div>';
      if (oppsArr.length === 0) {
        h += '<div style="padding:18px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px;margin-bottom:18px">Need ≥2 indexed pages with overlapping topics.</div>';
      } else {
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden;margin-bottom:18px"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
        ['Source', 'Target', 'Anchor', 'Score', 'Action'].forEach(function (c) { h += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">' + c + '</th>'; });
        h += '</tr></thead><tbody>';
        oppsArr.slice(0, 20).forEach(function (o) {
          h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
          h += '<td style="padding:9px 12px;color:var(--t2);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(o.source_title || o.source_url) + '</td>';
          h += '<td style="padding:9px 12px;color:var(--t2);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(o.target_title || o.target_url) + '</td>';
          h += '<td style="padding:9px 12px;color:var(--t1)">"' + esc(o.suggested_anchor) + '"</td>';
          h += '<td style="padding:9px 12px">' + chip(String(o.score || 0), scoreColor(o.score || 0)) + '</td>';
          h += '<td style="padding:9px 12px"><button onclick="_seoApplyLink(' + (o.source_id || 0) + ',\'' + esc(o.suggested_anchor).replace(/\'/g, "\\'") + '\',\'' + esc(o.target_url).replace(/\'/g, "\\'") + '\')" style="background:none;border:1px solid var(--p);color:var(--p);padding:3px 10px;border-radius:5px;font-size:10px;cursor:pointer">Apply →</button></td></tr>';
        });
        h += '</tbody></table></div>';
      }

      // ── Equity leaders (top 5 by equity_score) ────────────────────
      var equityLeaders = (graphNodes || []).slice().sort(function (a, b) { return (b.equity_score || 0) - (a.equity_score || 0); }).slice(0, 5);
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-top:8px;margin-bottom:10px">Equity leaders (top 5)</div>';
      if (equityLeaders.length === 0 || !equityLeaders[0].equity_score) {
        h += '<div style="padding:14px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px;margin-bottom:18px">Click "Calculate Equity" to compute PageRank-style scores.</div>';
      } else {
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:8px;margin-bottom:18px">';
        equityLeaders.forEach(function (n) {
          h += '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 6px;border-bottom:1px solid rgba(255,255,255,.03)">';
          h += '<span style="font-size:12px;color:var(--t2);max-width:60%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(n.title || n.url) + '</span>';
          h += '<span style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:var(--t3)">in:' + (n.inbound || 0) + '</span>' + chip('Equity ' + (n.equity_score || 0), scoreColor(n.equity_score || 0)) + '</span>';
          h += '</div>';
        });
        h += '</div>';
      }

      // ── Orphan pages (existing) ──────────────────────────────────
      if (orphArr.length > 0) {
        h += '<div style="font-size:13px;font-weight:600;color:var(--am);margin-top:6px;margin-bottom:8px">Orphan pages (no inbound internal links)</div>';
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:10px"><ul style="margin:0;padding-left:20px;font-size:12px;color:var(--t2)">';
        orphArr.slice(0, 10).forEach(function (o) {
          h += '<li><a href="' + esc(o.url) + '" target="_blank" style="color:var(--t2)">' + esc(o.title || o.url) + '</a> · ' + (o.word_count || 0) + ' words · score ' + (o.content_score || 0) + '</li>';
        });
        h += '</ul></div>';
      }

      // ── Equity redistribution suggestions ────────────────────────
      h += '<div id="seo-equity-suggestions" style="margin-top:18px"></div>';

      el.innerHTML = h;

      // Lazy-load equity suggestions (only useful after equity calc).
      _seoApi('GET', '/equity/suggestions').then(function (sugg) {
        var arr = Array.isArray(sugg) ? sugg : ((sugg && sugg.data) || []);
        if (!arr || arr.length === 0) return;
        var container = document.getElementById('seo-equity-suggestions');
        if (!container) return;
        var hh = '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Redistribution suggestions</div>';
        hh += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        hh += '<thead><tr style="border-bottom:1px solid var(--bd)">';
        ['From', 'To', 'Reason', 'Gain', 'Action'].forEach(function (c) { hh += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">' + c + '</th>'; });
        hh += '</tr></thead><tbody>';
        arr.slice(0, 8).forEach(function (s) {
          hh += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
          hh += '<td style="padding:9px 12px;color:var(--t2);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.from_title || s.from_url) + '</td>';
          hh += '<td style="padding:9px 12px;color:var(--t2);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.to_title || s.to_url) + '</td>';
          hh += '<td style="padding:9px 12px;color:var(--t2);font-size:11px">' + esc(s.reason || '') + '</td>';
          hh += '<td style="padding:9px 12px">' + chip('+' + (s.estimated_equity_gain || 0), '#10B981') + '</td>';
          hh += '<td style="padding:9px 12px"><button onclick="_seoApplyLink(0,\'\',\'' + esc(s.to_url).replace(/\'/g, "\\'") + '\')" style="background:none;border:1px solid var(--p);color:var(--p);padding:3px 10px;border-radius:5px;font-size:10px;cursor:pointer">Apply</button></td></tr>';
        });
        hh += '</tbody></table></div>';
        container.innerHTML = hh;
      }).catch(function () { /* silent — endpoint exists, just empty */ });
    } catch (e) {
      el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load: ' + (e.message || e) + '</div>';
    }
  };

  // ── Build link graph button ──────────────────────────────────────────
  window._seoBuildLinkGraph = async function () {
    toast('Building link graph — fetching pages…', 'info');
    try {
      var r = await _seoApi('POST', '/link-graph/build', {});
      var msg = 'Graph built: ' + (r.pages_processed || 0) + ' pages → ' + (r.total_links || 0) + ' links (' + (r.internal_links || 0) + ' int / ' + (r.external_links || 0) + ' ext)';
      toast(msg, 'success');
      _seoLinks(document.getElementById('seo-content'));
    } catch (e) { toast('Build failed: ' + (e.message || ''), 'error'); }
  };

  // ── Calculate equity button ──────────────────────────────────────────
  window._seoCalcEquity = async function () {
    toast('Running PageRank-style equity flow…', 'info');
    try {
      var r = await _seoApi('POST', '/equity/calculate', {});
      toast('Equity calculated: ' + (r.pages_scored || 0) + ' pages over ' + (r.iterations || 0) + ' iterations.', 'success');
      _seoLinks(document.getElementById('seo-content'));
    } catch (e) { toast('Equity calc failed: ' + (e.message || ''), 'error'); }
  };

  // ─────────────────────────────────────────────────────────────────────
  // Topics tab — Semantic clusters
  // ─────────────────────────────────────────────────────────────────────
  window._seoTopics = async function (el) {
    try {
      var [authR, gapsR] = await Promise.all([
        _seoApi('GET', '/topics/authority').catch(function () { return null; }),
        _seoApi('GET', '/clusters/gaps').catch(function () { return null; }),
      ]);
      var topics = (authR && authR.topics) || [];
      var gaps = (gapsR && gapsR.gaps) || [];

      var h = '';
      h += '<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">';
      h += '<div><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Topic Authority</h2>';
      h += '<p style="font-size:13px;color:var(--t2);margin:0">Semantic clusters built from your indexed content (TF-IDF + cosine similarity).</p></div>';
      h += '<button onclick="_seoBuildClusters()" style="background:var(--p);color:#fff;border:0;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">Build Clusters</button>';
      h += '</div>';

      if (topics.length === 0) {
        h += '<div style="padding:40px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px;margin-bottom:18px">';
        h += '<div style="font-size:36px;margin-bottom:12px">📚</div>';
        h += '<div style="font-size:14px;color:var(--t2);margin-bottom:8px">No topic clusters yet.</div>';
        h += '<div style="font-size:12px;color:var(--t3)">Click <b>Build Clusters</b> to group your indexed pages by topic. Needs ≥2 pages with overlapping content.</div>';
        h += '</div>';
        el.innerHTML = h;
        return;
      }

      // Topic table
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden;margin-bottom:18px"><table style="width:100%;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      ['Topic', 'Authority', 'Pages', 'Avg score', 'Completeness', 'Pillar', 'Action'].forEach(function (c) { h += '<th style="text-align:left;padding:10px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">' + c + '</th>'; });
      h += '</tr></thead><tbody>';
      topics.slice(0, 30).forEach(function (t) {
        h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:9px 12px;color:var(--t1);font-weight:600">' + esc(t.topic) + '</td>';
        h += '<td style="padding:9px 12px">' + chip(String(t.authority || 0), scoreColor(t.authority || 0)) + '</td>';
        h += '<td style="padding:9px 12px;color:var(--t2)">' + (t.member_count || 0) + '</td>';
        h += '<td style="padding:9px 12px;color:var(--t2)">' + (t.avg_content_score || 0) + '</td>';
        h += '<td style="padding:9px 12px;width:120px">' + bar(t.completeness_score || 0, scoreColor(t.completeness_score || 0)) + '<div style="font-size:10px;color:var(--t3);margin-top:2px">' + (t.completeness_score || 0) + '/100</div></td>';
        h += '<td style="padding:9px 12px">' + (t.has_pillar ? chip('✓ pillar', '#10B981') : chip('no pillar', '#F87171')) + '</td>';
        h += '<td style="padding:9px 12px"><button onclick="_seoViewCluster(' + (t.cluster_id || 0) + ')" style="background:none;border:1px solid var(--p);color:var(--p);padding:3px 10px;border-radius:5px;font-size:10px;cursor:pointer">View →</button></td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';

      // Cluster gaps
      if (gaps.length > 0) {
        h += '<div style="font-size:13px;font-weight:600;color:var(--am);margin-bottom:10px">Cluster gaps (' + gaps.length + ')</div>';
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:8px">';
        gaps.slice(0, 8).forEach(function (g) {
          h += '<div style="padding:10px;border-bottom:1px solid rgba(255,255,255,.03)">';
          h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">';
          h += '<span style="font-size:13px;color:var(--t1);font-weight:600">' + esc(g.topic) + '</span>';
          h += '<span style="display:flex;gap:6px">';
          (g.gaps || []).forEach(function (gg) { h += chip(gg.replace(/_/g, ' '), '#F59E0B'); });
          h += '</span></div>';
          h += '<div style="font-size:11px;color:var(--t2)">' + esc(g.recommendation || '') + '</div>';
          h += '</div>';
        });
        h += '</div>';
      }

      // Detail panel mount point
      h += '<div id="seo-cluster-detail" style="margin-top:18px"></div>';
      el.innerHTML = h;
    } catch (e) {
      el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load: ' + (e.message || e) + '</div>';
    }
  };

  window._seoBuildClusters = async function () {
    toast('Building semantic clusters…', 'info');
    try {
      var r = await _seoApi('POST', '/clusters/build', {});
      var n = Array.isArray(r) ? r.length : (r && r.length) || 0;
      if (n === 0) {
        toast('No clusters formed — need ≥2 indexed pages with overlapping topics.', 'info');
      } else {
        toast('Built ' + n + ' cluster' + (n === 1 ? '' : 's') + '.', 'success');
      }
      _seoTopics(document.getElementById('seo-content'));
    } catch (e) { toast('Cluster build failed: ' + (e.message || ''), 'error'); }
  };

  window._seoViewCluster = async function (clusterId) {
    if (!clusterId) return;
    var detail = document.getElementById('seo-cluster-detail');
    if (!detail) return;
    detail.innerHTML = (typeof loadingCard === 'function') ? loadingCard(120) : 'Loading…';
    try {
      var r = await _seoApi('GET', '/clusters/' + clusterId + '/members');
      var members = (r && r.members) || [];
      var hh = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px">';
      hh += '<div style="display:flex;justify-content:space-between;margin-bottom:10px"><div style="font-size:13px;font-weight:600;color:var(--t1)">Cluster #' + clusterId + ' — ' + members.length + ' member' + (members.length === 1 ? '' : 's') + '</div>';
      hh += '<button onclick="document.getElementById(\'seo-cluster-detail\').innerHTML=\'\'" style="background:none;border:1px solid var(--bd);color:var(--t3);padding:3px 10px;border-radius:5px;font-size:10px;cursor:pointer">Close</button></div>';
      if (members.length === 0) {
        hh += '<div style="padding:14px;color:var(--t3);text-align:center">No members.</div>';
      } else {
        hh += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        hh += '<thead><tr style="border-bottom:1px solid var(--bd)"><th style="text-align:left;padding:8px 12px;font-size:10px;color:var(--t3);text-transform:uppercase">Page</th><th style="padding:8px 12px;font-size:10px;color:var(--t3);text-transform:uppercase">Content</th><th style="padding:8px 12px;font-size:10px;color:var(--t3);text-transform:uppercase">Words</th><th style="padding:8px 12px;font-size:10px;color:var(--t3);text-transform:uppercase">Pillar</th></tr></thead><tbody>';
        members.forEach(function (m) {
          hh += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
          hh += '<td style="padding:8px 12px;color:var(--t2);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="' + esc(m.page_url) + '" target="_blank" style="color:var(--t2)">' + esc(m.page_title || m.page_url) + '</a></td>';
          hh += '<td style="padding:8px 12px;text-align:center">' + chip(String(m.content_score || 0), scoreColor(m.content_score || 0)) + '</td>';
          hh += '<td style="padding:8px 12px;text-align:center;color:var(--t2)">' + (m.word_count || 0) + '</td>';
          hh += '<td style="padding:8px 12px;text-align:center">' + (m.is_pillar ? chip('★', '#10B981') : '<span style="color:var(--t3)">—</span>') + '</td>';
          hh += '</tr>';
        });
        hh += '</tbody></table>';
      }
      hh += '</div>';
      detail.innerHTML = hh;
    } catch (e) { detail.innerHTML = '<div style="padding:14px;color:var(--rd)">Failed: ' + (e.message || e) + '</div>'; }
  };

  // If shell already rendered (user navigated to SEO before this script ran),
  // inject the Topics tab immediately.
  if (document.readyState !== 'loading') {
    var existingTabBar = document.getElementById('seo-tab-links');
    if (existingTabBar && !document.getElementById('seo-tab-topics')) {
      var t = document.createElement('div');
      t.className = 'seo-tab';
      t.id = 'seo-tab-topics';
      t.style.cssText = 'padding:10px 16px;cursor:pointer;font-size:13px;font-weight:500;color:var(--t2);border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap';
      var ico2 = (typeof window.icon === 'function') ? window.icon('chart', 14) : '';
      t.innerHTML = ico2 + ' Topics';
      t.onclick = function () { _seoSwitchTab('topics'); };
      existingTabBar.parentNode.insertBefore(t, existingTabBar.nextSibling);
    }
  }
})();

// ═══════════════════════════════════════════════════════════════════════════
// SEO Week 3 Session 1 (LB-SEO-W3, 2026-04-28) — Frontend extension
// Adds: Competitors tab. Rebuilds: Insights + Reports tabs (replaces stubs).
// Append-only — patches tab strip via _seoRenderShell wrapper.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  function escW3(s) { return (typeof window._luEsc === 'function') ? window._luEsc(s || '') : String(s || '').replace(/[<>"]/g, ''); }
  function toastW3(msg, kind) { if (typeof window.showToast === 'function') window.showToast(msg, kind || 'info'); }
  function loadingW3() { return (typeof loadingCard === 'function') ? loadingCard(220) : 'Loading…'; }
  function scoreColorW3(s) { return s >= 70 ? '#10B981' : (s >= 40 ? '#F59E0B' : '#EF4444'); }
  function chipW3(text, color) {
    return '<span style="background:' + color + '20;color:' + color + ';padding:2px 8px;border-radius:8px;font-size:10px;font-weight:600">' + escW3(text) + '</span>';
  }

  // Inject Competitors tab between Topics and Optimization (Workspace).
  var _origRenderW3 = window._seoRenderShell || _seoRenderShell;
  window._seoRenderShell = function (el) {
    _origRenderW3(el);
    var topicsTab = document.getElementById('seo-tab-topics');
    var anchor = topicsTab || document.getElementById('seo-tab-links');
    if (anchor && !document.getElementById('seo-tab-competitors')) {
      var t = document.createElement('div');
      t.className = 'seo-tab';
      t.id = 'seo-tab-competitors';
      t.style.cssText = 'padding:10px 16px;cursor:pointer;font-size:13px;font-weight:500;color:var(--t2);border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap';
      var ico = (typeof window.icon === 'function') ? window.icon('globe', 14) : '';
      t.innerHTML = ico + ' Competitors';
      t.onclick = function () { _seoSwitchTab('competitors'); };
      anchor.parentNode.insertBefore(t, anchor.nextSibling);
    }
  };

  // Hook _seoSwitchTab for the new 'competitors' tab id.
  var _origSwitchW3 = window._seoSwitchTab;
  window._seoSwitchTab = function (tab) {
    if (tab === 'competitors') {
      window._seoTab = tab;
      document.querySelectorAll('.seo-tab').forEach(function (t) {
        t.style.color = 'var(--t2)';
        t.style.borderBottomColor = 'transparent';
      });
      var active = document.getElementById('seo-tab-competitors');
      if (active) {
        active.style.color = 'var(--p,#6C5CE7)';
        active.style.borderBottomColor = 'var(--p,#6C5CE7)';
      }
      var content = document.getElementById('seo-content');
      if (content) {
        content.innerHTML = loadingW3();
        _seoCompetitors(content);
      }
      return;
    }
    return _origSwitchW3(tab);
  };

  // ── Competitors tab ──────────────────────────────────────────────────────
  window._seoCompetitors = async function (el) {
    try {
      var trackedR = await _seoApi('GET', '/competitors/tracked').catch(function () { return null; });
      var tracked = (trackedR && trackedR.tracked) || [];

      var h = '';
      h += '<div style="margin-bottom:16px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Competitor Analysis</h2>';
      h += '<p style="font-size:13px;color:var(--t2);margin:0">Top 10 SERP competitors per keyword + content-gap analysis.</p></div>';

      // ── Keyword analysis panel ─────────────────────────────────────
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:18px;margin-bottom:18px">';
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Analyze a keyword</div>';
      h += '<div style="display:flex;gap:8px;flex-wrap:wrap">';
      h += '<input id="cmp-kw" type="text" class="form-input" placeholder="e.g. digital marketing dubai" style="flex:1;min-width:240px;padding:9px 11px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:6px;font-size:13px">';
      h += '<input id="cmp-loc" type="text" class="form-input" placeholder="Location" value="United Arab Emirates" style="width:200px;padding:9px 11px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:6px;font-size:13px">';
      h += '<button onclick="_seoCmpAnalyze()" style="background:var(--p);color:#fff;border:0;padding:9px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">Analyze</button>';
      h += '</div>';
      h += '<div id="cmp-results" style="margin-top:14px"></div>';
      h += '</div>';

      // ── Comparison panel ───────────────────────────────────────────
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:18px;margin-bottom:18px">';
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Compare your page vs SERP</div>';
      h += '<div style="display:flex;gap:8px;flex-wrap:wrap">';
      h += '<input id="cmp-your-url" type="text" class="form-input" placeholder="Your URL (must be in indexed content)" style="flex:1;min-width:240px;padding:9px 11px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:6px;font-size:13px">';
      h += '<input id="cmp-cmp-kw" type="text" class="form-input" placeholder="Target keyword" style="width:240px;padding:9px 11px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:6px;font-size:13px">';
      h += '<button onclick="_seoCmpCompare()" style="background:none;border:1px solid var(--p);color:var(--p);padding:8px 15px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">Compare</button>';
      h += '</div>';
      h += '<div id="cmp-compare-results" style="margin-top:14px"></div>';
      h += '</div>';

      // ── Tracked competitors ────────────────────────────────────────
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:18px">';
      h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">';
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1)">Tracked competitor domains</div></div>';
      h += '<div style="display:flex;gap:8px;margin-bottom:12px">';
      h += '<input id="cmp-track-dom" type="text" class="form-input" placeholder="competitor.com" style="flex:1;padding:9px 11px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);border-radius:6px;font-size:13px">';
      h += '<button onclick="_seoCmpTrack()" style="background:var(--p);color:#fff;border:0;padding:9px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">Track</button>';
      h += '</div>';
      if (tracked.length === 0) {
        h += '<div style="padding:14px;text-align:center;color:var(--t3);font-size:12px">No competitors tracked yet.</div>';
      } else {
        h += '<div>';
        tracked.forEach(function (c) {
          h += '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px;border-bottom:1px solid rgba(255,255,255,.03)">';
          h += '<span style="font-size:13px;color:var(--t2)">' + escW3(c.competitor_domain) + '</span>';
          h += '<span style="font-size:11px;color:var(--t3)">' + (c.last_analyzed_at ? 'analyzed ' + escW3(c.last_analyzed_at) : 'not yet analyzed') + '</span>';
          h += '</div>';
        });
        h += '</div>';
      }
      h += '</div>';

      el.innerHTML = h;
    } catch (e) {
      el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load: ' + (e.message || e) + '</div>';
    }
  };

  window._seoCmpAnalyze = async function () {
    var kw = document.getElementById('cmp-kw');
    var loc = document.getElementById('cmp-loc');
    var box = document.getElementById('cmp-results');
    if (!kw || !box) return;
    if (!kw.value.trim()) { toastW3('Enter a keyword first.', 'error'); return; }
    box.innerHTML = loadingW3();
    try {
      var r = await _seoApi('POST', '/competitors/analyze', { keyword: kw.value.trim(), location: (loc && loc.value) || 'United Arab Emirates' });
      var arr = (r && r.competitors) || [];
      if (arr.length === 0) {
        box.innerHTML = '<div style="padding:14px;text-align:center;color:var(--t3);background:var(--s2);border-radius:6px;font-size:12px">No SERP data — DataForSEO may not be configured.</div>';
        return;
      }
      var h = '<div style="background:var(--s2);border-radius:6px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      ['Rank', 'Title', 'Domain', 'Words', 'URL'].forEach(function (c) { h += '<th style="text-align:left;padding:9px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">' + c + '</th>'; });
      h += '</tr></thead><tbody>';
      arr.forEach(function (c) {
        h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:8px 12px;color:var(--t2);font-weight:600">#' + (c.rank || 0) + '</td>';
        h += '<td style="padding:8px 12px;color:var(--t1);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escW3(c.title) + '</td>';
        h += '<td style="padding:8px 12px;color:var(--t2)">' + escW3(c.domain) + '</td>';
        h += '<td style="padding:8px 12px;color:var(--t2)">' + (c.est_word_count || 0) + '</td>';
        h += '<td style="padding:8px 12px"><a href="' + escW3(c.url) + '" target="_blank" style="color:var(--p);font-size:11px">↗</a></td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';
      // Trigger gap analysis lazily.
      h += '<div id="cmp-gaps-mount" style="margin-top:14px"><button onclick="_seoCmpGaps()" style="background:none;border:1px solid var(--p);color:var(--p);padding:7px 13px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">Find content gaps with AI</button></div>';
      box.innerHTML = h;
    } catch (e) { box.innerHTML = '<div style="color:var(--rd);padding:14px">Failed: ' + (e.message || e) + '</div>'; }
  };

  window._seoCmpGaps = async function () {
    var kw = document.getElementById('cmp-kw');
    var mount = document.getElementById('cmp-gaps-mount');
    if (!kw || !mount) return;
    mount.innerHTML = loadingW3();
    try {
      var r = await _seoApi('POST', '/competitors/gaps', { keyword: kw.value.trim() });
      var gaps = (r && r.gaps) || [];
      if (gaps.length === 0) { mount.innerHTML = '<div style="padding:10px;color:var(--t3);font-size:12px">No gaps detected (or AI runtime unavailable).</div>'; return; }
      var h = '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:8px">AI-detected content gaps</div>';
      h += '<div style="background:var(--s2);border-radius:6px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      ['Topic', 'Found in', 'Priority', 'Suggested heading'].forEach(function (c) { h += '<th style="text-align:left;padding:9px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">' + c + '</th>'; });
      h += '</tr></thead><tbody>';
      gaps.forEach(function (g) {
        var pcol = g.priority === 'high' ? '#EF4444' : (g.priority === 'medium' ? '#F59E0B' : '#10B981');
        h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:8px 12px;color:var(--t1);font-weight:600">' + escW3(g.topic) + '</td>';
        h += '<td style="padding:8px 12px;color:var(--t2)">' + (g.found_in_n_competitors || 0) + ' / 10</td>';
        h += '<td style="padding:8px 12px">' + chipW3(g.priority || '', pcol) + '</td>';
        h += '<td style="padding:8px 12px;color:var(--t2);font-size:11px">' + escW3(g.suggested_heading || '') + '</td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';
      mount.innerHTML = h;
    } catch (e) { mount.innerHTML = '<div style="color:var(--rd);padding:10px">Failed: ' + (e.message || e) + '</div>'; }
  };

  window._seoCmpCompare = async function () {
    var url = document.getElementById('cmp-your-url');
    var kw = document.getElementById('cmp-cmp-kw');
    var box = document.getElementById('cmp-compare-results');
    if (!url || !kw || !box) return;
    if (!url.value.trim() || !kw.value.trim()) { toastW3('Enter both URL and keyword.', 'error'); return; }
    box.innerHTML = loadingW3();
    try {
      var r = await _seoApi('POST', '/competitors/compare', { your_url: url.value.trim(), keyword: kw.value.trim() });
      if (r && r.error) { box.innerHTML = '<div style="padding:14px;color:var(--am);background:var(--s2);border-radius:6px;font-size:12px">Your URL is not in the indexed content table. Run "outbound scan" or "indexed content" first.</div>'; return; }
      var you = r.your_page || {};
      var avg = r.competitor_avg || {};
      var gaps = r.gaps || [];
      var recs = r.recommendations || [];
      var h = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:12px">';
      h += '<div style="background:var(--s2);padding:12px;border-radius:6px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase">Your words</div><div style="font-size:20px;font-weight:700;color:var(--t1)">' + (you.word_count || 0) + '</div></div>';
      h += '<div style="background:var(--s2);padding:12px;border-radius:6px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase">Avg competitor words</div><div style="font-size:20px;font-weight:700;color:var(--t1)">' + (avg.word_count || 0) + '</div></div>';
      h += '<div style="background:var(--s2);padding:12px;border-radius:6px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase">Your content score</div><div style="font-size:20px;font-weight:700;">' + chipW3((you.content_score || 0), scoreColorW3(you.content_score || 0)) + '</div></div>';
      h += '<div style="background:var(--s2);padding:12px;border-radius:6px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase">Word delta</div><div style="font-size:20px;font-weight:700;color:' + ((r.word_count_delta || 0) >= 0 ? '#10B981' : '#EF4444') + '">' + (r.word_count_delta > 0 ? '+' : '') + (r.word_count_delta || 0) + '</div></div>';
      h += '</div>';
      if (gaps.length > 0) {
        h += '<div style="font-size:13px;font-weight:600;color:var(--am);margin-bottom:8px">Gaps</div>';
        h += '<ul style="margin:0;padding-left:18px;font-size:12px;color:var(--t2)">';
        gaps.forEach(function (g) { var pcol = g.priority === 'high' ? '#EF4444' : (g.priority === 'medium' ? '#F59E0B' : '#10B981'); h += '<li style="margin-bottom:4px">' + chipW3(g.priority || '', pcol) + ' ' + escW3(g.description || '') + '</li>'; });
        h += '</ul>';
      }
      if (recs.length > 0) {
        h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-top:12px;margin-bottom:8px">Recommendations</div>';
        h += '<ul style="margin:0;padding-left:18px;font-size:12px;color:var(--t2)">';
        recs.forEach(function (rc) { h += '<li style="margin-bottom:4px">' + escW3(rc.action || '') + '</li>'; });
        h += '</ul>';
      }
      box.innerHTML = h;
    } catch (e) { box.innerHTML = '<div style="color:var(--rd);padding:14px">Failed: ' + (e.message || e) + '</div>'; }
  };

  window._seoCmpTrack = async function () {
    var input = document.getElementById('cmp-track-dom');
    if (!input) return;
    if (!input.value.trim()) { toastW3('Enter a domain first.', 'error'); return; }
    try {
      await _seoApi('POST', '/competitors/track', { competitor_domain: input.value.trim() });
      toastW3('Competitor tracked.', 'success');
      input.value = '';
      _seoCompetitors(document.getElementById('seo-content'));
    } catch (e) { toastW3('Failed: ' + (e.message || ''), 'error'); }
  };

  // ── Replace _seoInsights stub ────────────────────────────────────────────
  window._seoInsights = async function (el) {
    try {
      var [traffic, top, perf, summ] = await Promise.all([
        _seoApi('GET', '/insights/traffic?days=28').catch(function () { return null; }),
        _seoApi('GET', '/insights/top-pages?days=28&limit=10').catch(function () { return null; }),
        _seoApi('GET', '/insights/content-performance').catch(function () { return null; }),
        _seoApi('GET', '/insights/summary').catch(function () { return null; }),
      ]);

      var t = traffic || {};
      var pages = (top && top.pages) || [];
      var p = perf || {};
      var s = summ || {};

      var h = '';
      h += '<div style="margin-bottom:16px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">Traffic Insights</h2>';
      h += '<p style="font-size:13px;color:var(--t2);margin:0">28-day GSC data + content score correlation.</p></div>';

      // Headline cards
      var dirIcon = t.direction === 'up' ? '↑' : (t.direction === 'down' ? '↓' : '→');
      var dirCol = t.direction === 'up' ? '#10B981' : (t.direction === 'down' ? '#EF4444' : '#9ca3af');
      h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px">';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);padding:14px;border-radius:8px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase">Total clicks (28d)</div><div style="font-size:22px;font-weight:700;color:var(--t1)">' + (t.total_clicks || 0).toLocaleString() + '</div></div>';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);padding:14px;border-radius:8px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase">Total impressions</div><div style="font-size:22px;font-weight:700;color:var(--t1)">' + (t.total_impressions || 0).toLocaleString() + '</div></div>';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);padding:14px;border-radius:8px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase">Trend</div><div style="font-size:22px;font-weight:700;color:' + dirCol + '">' + dirIcon + ' ' + (t.change_pct || 0) + '%</div></div>';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);padding:14px;border-radius:8px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase">Content correlation</div><div style="font-size:22px;font-weight:700;color:var(--t1)">' + escW3((p.correlation || 'weak')) + '</div></div>';
      h += '</div>';

      // AI insights / recommendations
      h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px">';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);padding:14px;border-radius:8px"><div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:8px">Key insights</div>';
      if ((s.insights || []).length === 0) {
        h += '<div style="font-size:12px;color:var(--t3)">Not enough data yet — sync GSC and re-run.</div>';
      } else {
        h += '<ul style="margin:0;padding-left:18px;font-size:12px;color:var(--t2)">';
        s.insights.forEach(function (i) { h += '<li style="margin-bottom:6px">' + escW3(i) + '</li>'; });
        h += '</ul>';
      }
      h += '</div>';
      h += '<div style="background:var(--s1);border:1px solid var(--bd);padding:14px;border-radius:8px"><div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:8px">Recommendations</div>';
      if ((s.recommendations || []).length === 0) {
        h += '<div style="font-size:12px;color:var(--t3)">No recommendations yet.</div>';
      } else {
        h += '<ul style="margin:0;padding-left:18px;font-size:12px;color:var(--t2)">';
        s.recommendations.forEach(function (i) { h += '<li style="margin-bottom:6px">' + escW3(i) + '</li>'; });
        h += '</ul>';
      }
      h += '</div></div>';

      // Top pages table
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Top pages by traffic</div>';
      if (pages.length === 0) {
        h += '<div style="padding:14px;text-align:center;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:8px;margin-bottom:18px">No GSC data yet. Connect Search Console and run a sync.</div>';
      } else {
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden;margin-bottom:18px"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
        ['URL', 'Title', 'Clicks', 'Impr', 'CTR', 'Pos', 'Score'].forEach(function (c) { h += '<th style="text-align:left;padding:9px 12px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">' + c + '</th>'; });
        h += '</tr></thead><tbody>';
        pages.forEach(function (p) {
          h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
          h += '<td style="padding:8px 12px;color:var(--t2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="' + escW3(p.url) + '" target="_blank" style="color:var(--t2);font-size:11px">' + escW3(p.url) + '</a></td>';
          h += '<td style="padding:8px 12px;color:var(--t1);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escW3(p.title) + '</td>';
          h += '<td style="padding:8px 12px;color:var(--t1);font-weight:600">' + (p.clicks || 0) + '</td>';
          h += '<td style="padding:8px 12px;color:var(--t2)">' + (p.impressions || 0) + '</td>';
          h += '<td style="padding:8px 12px;color:var(--t2)">' + Math.round((p.ctr || 0) * 100 * 100) / 100 + '%</td>';
          h += '<td style="padding:8px 12px;color:var(--t2)">' + (p.position || 0) + '</td>';
          h += '<td style="padding:8px 12px">' + chipW3((p.content_score || 0), scoreColorW3(p.content_score || 0)) + '</td>';
          h += '</tr>';
        });
        h += '</tbody></table></div>';
      }

      // Opportunity pages
      var opps = p.opportunity_pages || [];
      if (opps.length > 0) {
        h += '<div style="font-size:13px;font-weight:600;color:var(--am);margin-bottom:8px">Lift-potential pages (high impressions, weak content)</div>';
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:8px">';
        opps.slice(0, 5).forEach(function (o) {
          h += '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px;border-bottom:1px solid rgba(255,255,255,.03)">';
          h += '<span style="font-size:12px;color:var(--t2);max-width:60%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escW3(o.title || o.url) + '</span>';
          h += '<span style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:var(--t3)">' + (o.clicks || 0) + ' clicks · score ' + (o.score || 0) + '</span>' + chipW3('+' + (o.potential_gain || 0) + ' lift', '#10B981') + '</span>';
          h += '</div>';
        });
        h += '</div>';
      }

      el.innerHTML = h;
    } catch (e) {
      el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load: ' + (e.message || e) + '</div>';
    }
  };

  // ── Replace _seoReports stub ─────────────────────────────────────────────
  window._seoReports = async function (el) {
    try {
      var report = await _seoApi('GET', '/reports/audit');

      var token = localStorage.getItem('lu_token') || '';
      // PDF/HTML/CSV downloads must be GET with Authorization header → use fetch + Blob.
      var dlBase = window.location.origin + '/api/seo';

      var h = '';
      h += '<div style="margin-bottom:16px"><h2 style="font-family:var(--fh);font-size:20px;font-weight:700;color:var(--t1);margin:0 0 4px">SEO Reports</h2>';
      h += '<p style="font-size:13px;color:var(--t2);margin:0">Generate audit report (PDF / HTML) + raw data CSV exports.</p></div>';

      // Audit summary card
      var score = report.health_score || 0;
      h += '<div style="background:var(--s1);border:1px solid var(--bd);padding:18px;border-radius:10px;margin-bottom:18px;display:grid;grid-template-columns:auto 1fr auto;gap:16px;align-items:center">';
      h += '<div><div style="font-size:11px;color:var(--t3);text-transform:uppercase;margin-bottom:4px">Health</div><div style="font-size:32px;font-weight:700;color:' + scoreColorW3(score) + '">' + score + '/100</div></div>';
      h += '<div><div style="font-size:13px;color:var(--t1);font-weight:600">' + escW3((report.workspace || {}).name || 'Workspace') + '</div><div style="font-size:11px;color:var(--t3);margin-top:2px">Pages: ' + ((report.content_summary || {}).total_pages || 0) + ' · Keywords: ' + ((report.keyword_summary || {}).tracking || 0) + ' · Internal links: ' + ((report.link_summary || {}).internal || 0) + '</div></div>';
      h += '<div style="display:flex;gap:8px">';
      h += '<button onclick="_seoDownloadReport(\'pdf\')" style="background:var(--p);color:#fff;border:0;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">Download PDF</button>';
      h += '<button onclick="_seoDownloadReport(\'html\')" style="background:none;border:1px solid var(--p);color:var(--p);padding:7px 13px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">Download HTML</button>';
      h += '</div></div>';

      // CSV exports
      h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Raw data exports</div>';
      h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:18px">';
      ['keywords', 'pages', 'links', 'images', 'anchors'].forEach(function (t) {
        h += '<button onclick="_seoExportCsv(\'' + t + '\')" style="background:var(--s1);border:1px solid var(--bd);color:var(--t1);padding:14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;text-align:left">';
        h += '<div style="margin-bottom:4px">Export ' + t.charAt(0).toUpperCase() + t.slice(1) + '</div>';
        h += '<div style="font-size:10px;color:var(--t3);font-weight:400">CSV download</div>';
        h += '</button>';
      });
      h += '</div>';

      // Quick wins preview
      var quickWins = report.quick_wins || [];
      if (quickWins.length > 0) {
        h += '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:10px">Quick wins</div>';
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:8px">';
        quickWins.slice(0, 5).forEach(function (w) {
          h += '<div style="padding:8px;border-bottom:1px solid rgba(255,255,255,.03);font-size:12px;color:var(--t2)">' + escW3(w.url || w.page || '') + ' — ' + escW3(w.issue || w.gap || '') + ' (impact ' + (w.impact || w.priority || 0) + ')</div>';
        });
        h += '</div>';
      }

      el.innerHTML = h;
    } catch (e) {
      el.innerHTML = '<div style="padding:24px;color:var(--rd)">Failed to load: ' + (e.message || e) + '</div>';
    }
  };

  window._seoDownloadReport = async function (kind) {
    try {
      var token = localStorage.getItem('lu_token') || '';
      var path = kind === 'pdf' ? '/reports/audit/pdf' : '/reports/audit/html';
      toastW3('Generating ' + kind.toUpperCase() + '...', 'info');
      var resp = await fetch(window.location.origin + '/api/seo' + path, {
        headers: { 'Authorization': 'Bearer ' + token, 'Accept': '*/*' },
      });
      if (!resp.ok) { toastW3('Download failed: HTTP ' + resp.status, 'error'); return; }
      var blob = await resp.blob();
      var ext = (resp.headers.get('Content-Type') || '').includes('pdf') ? 'pdf' : 'html';
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'seo-audit-' + Date.now() + '.' + ext;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      toastW3('Downloaded.', 'success');
    } catch (e) { toastW3('Download error: ' + (e.message || e), 'error'); }
  };

  window._seoExportCsv = async function (type) {
    try {
      var token = localStorage.getItem('lu_token') || '';
      toastW3('Generating ' + type + '.csv...', 'info');
      var resp = await fetch(window.location.origin + '/api/seo/reports/export/' + type, {
        headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'text/csv' },
      });
      if (!resp.ok) { toastW3('Export failed: HTTP ' + resp.status, 'error'); return; }
      var blob = await resp.blob();
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'seo-' + type + '-' + Date.now() + '.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      toastW3(type + ' exported (' + Math.round(blob.size / 1024) + ' KB).', 'success');
    } catch (e) { toastW3('Export error: ' + (e.message || e), 'error'); }
  };

  // Inject Competitors tab if shell already rendered.
  if (document.readyState !== 'loading') {
    var anchorReady = document.getElementById('seo-tab-topics') || document.getElementById('seo-tab-links');
    if (anchorReady && !document.getElementById('seo-tab-competitors')) {
      var t = document.createElement('div');
      t.className = 'seo-tab';
      t.id = 'seo-tab-competitors';
      t.style.cssText = 'padding:10px 16px;cursor:pointer;font-size:13px;font-weight:500;color:var(--t2);border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap';
      var ico2 = (typeof window.icon === 'function') ? window.icon('globe', 14) : '';
      t.innerHTML = ico2 + ' Competitors';
      t.onclick = function () { _seoSwitchTab('competitors'); };
      anchorReady.parentNode.insertBefore(t, anchorReady.nextSibling);
    }
  }
})();

// ═══════════════════════════════════════════════════════════════════════════
// SEO Engine UI/UX Redesign (LB-SEO-UI, 2026-04-28)
// "Mission Control" — data-forward, monospace numbers, sticky tables.
// Append-only progressive enhancement. No API changes. No rewrites.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  // ── Style sheet (injected once into <head>) ────────────────────────────
  var LGSE_STYLES = [
    ".lgse-mono{font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace}",

    // Score color helpers
    ".lgse-score-90{color:#00E5A8}",
    ".lgse-score-70{color:#3B8BF5}",
    ".lgse-score-50{color:var(--am)}",
    ".lgse-score-low{color:var(--rd)}",

    // Tables
    ".lgse-table{width:100%;border-collapse:collapse}",
    ".lgse-table thead th{background:var(--s1);color:var(--t3);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;padding:10px 16px;text-align:left;position:sticky;top:0;z-index:10;border-bottom:1px solid var(--bd)}",
    ".lgse-table thead th.num{text-align:right}",
    ".lgse-table tbody tr{border-bottom:1px solid var(--bd);transition:background .1s}",
    ".lgse-table tbody tr:hover{background:var(--s2)}",
    ".lgse-table tbody td{padding:12px 16px;font-size:13px;color:var(--t1)}",
    ".lgse-table tbody td.num{text-align:right;font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace}",
    ".lgse-table-wrap{background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden;max-height:560px;overflow-y:auto}",

    // Badges
    ".lgse-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace;line-height:1.4}",
    ".lgse-badge-success{background:rgba(0,229,168,.12);color:#00E5A8}",
    ".lgse-badge-info{background:rgba(59,139,245,.12);color:#3B8BF5}",
    ".lgse-badge-warning{background:rgba(245,158,11,.12);color:var(--am)}",
    ".lgse-badge-danger{background:rgba(248,113,113,.12);color:var(--rd)}",
    ".lgse-badge-muted{background:rgba(139,151,176,.1);color:var(--t2)}",
    ".lgse-badge-primary{background:rgba(108,92,231,.12);color:var(--p)}",

    // KPI cards
    ".lgse-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px}",
    ".lgse-kpi-card{background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:16px;transition:border-color .15s}",
    ".lgse-kpi-card:hover{border-color:var(--bd2)}",
    ".lgse-kpi-label{font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;font-weight:600}",
    ".lgse-kpi-value{font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace;font-size:26px;font-weight:700;color:var(--t1);line-height:1}",
    ".lgse-kpi-change{font-size:11px;margin-top:6px;font-family:'JetBrains Mono',ui-monospace,monospace}",
    ".lgse-kpi-change.up{color:#00E5A8}",
    ".lgse-kpi-change.down{color:var(--rd)}",
    ".lgse-kpi-change.same{color:var(--t3)}",

    // Issue chips
    ".lgse-issues-strip{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:24px}",
    ".lgse-issue-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:var(--s1);border:1px solid var(--bd);border-radius:20px;font-size:12px;color:var(--t2);cursor:pointer;transition:all .15s}",
    ".lgse-issue-chip:hover{border-color:var(--p);color:var(--t1)}",
    ".lgse-issue-chip .dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}",

    // Section header
    ".lgse-section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--bd)}",
    ".lgse-section-title{font-size:11px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:.08em}",
    ".lgse-section-actions{display:flex;align-items:center;gap:8px}",

    // Tab bar
    ".lgse-tab-bar{display:flex;gap:0;border-bottom:1px solid var(--bd);margin-bottom:24px;overflow-x:auto;scrollbar-width:none;flex-shrink:0;background:transparent}",
    ".lgse-tab-bar::-webkit-scrollbar{display:none}",
    ".lgse-tab-bar .seo-tab{padding:10px 16px;font-size:12px;font-weight:500;color:var(--t3);cursor:pointer;border-bottom:2px solid transparent;transition:color .15s,border-color .15s;white-space:nowrap;display:flex;align-items:center;gap:6px;background:transparent}",
    ".lgse-tab-bar .seo-tab:hover{color:var(--t1)}",
    ".lgse-tab-bar .seo-tab.lgse-active{color:var(--p);border-bottom-color:var(--p)}",
    ".lgse-tab-bar .seo-tab .lgse-count{background:var(--s2);color:var(--t2);font-family:'JetBrains Mono',ui-monospace,monospace;font-size:10px;padding:1px 5px;border-radius:10px;min-width:18px;text-align:center;line-height:1.5;font-weight:600}",
    ".lgse-tab-bar .seo-tab.lgse-active .lgse-count{background:rgba(108,92,231,.18);color:var(--p)}",

    // Score gauge
    ".lgse-gauge-wrap{position:relative;display:inline-flex;align-items:center;justify-content:center}",
    ".lgse-gauge-text{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none}",
    ".lgse-gauge-text .v{font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace;font-weight:700;line-height:1}",
    ".lgse-gauge-text .l{font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.12em;margin-top:4px;font-weight:600}",

    // Empty states
    ".lgse-empty-state{text-align:center;padding:40px 24px;color:var(--t3);background:var(--s1);border:1px dashed var(--bd);border-radius:10px}",
    ".lgse-empty-state .icon{font-size:28px;margin-bottom:10px;opacity:.6}",
    ".lgse-empty-state h3{font-size:14px;font-weight:600;color:var(--t2);margin:0 0 6px}",
    ".lgse-empty-state p{font-size:12px;max-width:340px;margin:0 auto 14px}",

    // Position change indicators
    ".lgse-pos-change{font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace;font-size:11px;font-weight:600}",
    ".lgse-pos-change.up{color:#00E5A8}",
    ".lgse-pos-change.down{color:var(--rd)}",
    ".lgse-pos-change.same{color:var(--t3)}",

    // Sparkline
    ".lgse-sparkline{display:inline-block;vertical-align:middle}",

    // Buttons
    ".lgse-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;border:1px solid transparent;font-family:inherit}",
    ".lgse-btn-primary{background:var(--p);color:#fff;border-color:var(--p)}",
    ".lgse-btn-primary:hover{filter:brightness(1.08)}",
    ".lgse-btn-secondary{background:transparent;color:var(--t1);border-color:var(--bd)}",
    ".lgse-btn-secondary:hover{border-color:var(--p);color:var(--p)}",
    ".lgse-btn-ghost{background:transparent;color:var(--t2);border-color:transparent}",
    ".lgse-btn-ghost:hover{color:var(--t1);background:var(--s2)}",

    // Connection pill
    ".lgse-conn-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:500;background:var(--s1);border:1px solid var(--bd)}",
    ".lgse-conn-pill .dot{width:8px;height:8px;border-radius:50%}",
    ".lgse-conn-pill.connected .dot{background:#00E5A8;box-shadow:0 0 8px rgba(0,229,168,.6)}",
    ".lgse-conn-pill.disconnected .dot{background:var(--rd)}",

    // Anchor distribution bar (stacked horizontal)
    ".lgse-stack-bar{display:flex;width:100%;height:8px;border-radius:4px;overflow:hidden;background:var(--s2)}",
    ".lgse-stack-seg{height:100%;transition:width .3s}",
    ".lgse-stack-legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:10px;font-size:11px;color:var(--t2)}",
    ".lgse-stack-legend .lg{display:inline-flex;align-items:center;gap:6px}",
    ".lgse-stack-legend .sw{width:10px;height:10px;border-radius:2px}",

    // Animations
    "@keyframes lgse-fadein{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}",
    ".lgse-animate{animation:lgse-fadein .2s ease-out}",
  ].join('');

  function lgseInjectStyles() {
    if (document.getElementById('lgse-styles')) return;
    var s = document.createElement('style');
    s.id = 'lgse-styles';
    s.textContent = LGSE_STYLES;
    document.head.appendChild(s);
  }

  // ── Helpers ─────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function scoreColor(score) {
    score = parseInt(score, 10) || 0;
    if (score >= 90) return '#00E5A8';
    if (score >= 70) return '#3B8BF5';
    if (score >= 50) return '#F59E0B';
    return '#F87171';
  }
  function scoreClass(score) {
    score = parseInt(score, 10) || 0;
    if (score >= 90) return 'lgse-score-90';
    if (score >= 70) return 'lgse-score-70';
    if (score >= 50) return 'lgse-score-50';
    return 'lgse-score-low';
  }
  function scoreBadgeKind(score) {
    score = parseInt(score, 10) || 0;
    if (score >= 90) return 'lgse-badge-success';
    if (score >= 70) return 'lgse-badge-info';
    if (score >= 50) return 'lgse-badge-warning';
    return 'lgse-badge-danger';
  }

  // ── Score gauge component (SVG, animated) ──────────────────────────────
  window.renderScoreGauge = function (container, score, size) {
    if (!container) return;
    size = size || 120;
    score = Math.max(0, Math.min(100, parseInt(score, 10) || 0));
    var r = (size / 2) - 8;
    var circ = 2 * Math.PI * r;
    var offset = circ - (score / 100) * circ;
    var color = scoreColor(score);
    var fontSize = Math.round(size * 0.22);

    container.style.width = size + 'px';
    container.style.height = size + 'px';
    container.classList.add('lgse-gauge-wrap');
    container.innerHTML =
      '<svg width="' + size + '" height="' + size + '" style="transform:rotate(-90deg)">' +
        '<circle cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" fill="none" stroke="var(--s2)" stroke-width="8"/>' +
        '<circle cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="8" stroke-dasharray="' + circ + '" stroke-dashoffset="' + circ + '" stroke-linecap="round" style="transition:stroke-dashoffset 1s cubic-bezier(.4,0,.2,1)" data-target-offset="' + offset + '"/>' +
      '</svg>' +
      '<div class="lgse-gauge-text">' +
        '<div class="v" style="font-size:' + fontSize + 'px;color:' + color + '">' + score + '</div>' +
        '<div class="l">score</div>' +
      '</div>';
    requestAnimationFrame(function () {
      var c = container.querySelector('[data-target-offset]');
      if (c) c.style.strokeDashoffset = c.getAttribute('data-target-offset');
    });
  };

  // ── Sparkline (SVG polyline) ───────────────────────────────────────────
  window.renderSparkline = function (positions, width, height) {
    width = width || 60;
    height = height || 20;
    if (!positions || positions.length < 2) return '';
    var vals = positions.map(function (p) { return p.pos != null ? p.pos : (p.position != null ? p.position : 100); });
    var min = Math.min.apply(null, vals);
    var max = Math.max.apply(null, vals);
    var range = max - min || 1;
    var pts = vals.map(function (v, i) {
      var x = (i / (vals.length - 1)) * width;
      var y = height - ((v - min) / range) * height;
      return x + ',' + y;
    }).join(' ');
    var trend = vals[0] > vals[vals.length - 1] ? '#00E5A8' : '#F87171';
    return '<svg class="lgse-sparkline" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '">' +
      '<polyline points="' + pts + '" fill="none" stroke="' + trend + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" opacity=".85"/></svg>';
  };

  // Position-change pill: "↑3" green / "↓2" red / "—" muted.
  window.renderPosChange = function (change) {
    var n = parseInt(change, 10) || 0;
    if (n === 0) return '<span class="lgse-pos-change same">—</span>';
    if (n < 0) return '<span class="lgse-pos-change up">↑' + Math.abs(n) + '</span>';
    return '<span class="lgse-pos-change down">↓' + n + '</span>';
  };

  // ── Tab strip rebuild — restyle existing tabs + insert Overview first ──
  var _origRenderShellUI = window._seoRenderShell || _seoRenderShell;
  window._seoRenderShell = function (el) {
    lgseInjectStyles();
    _origRenderShellUI(el);
    var bar = el.querySelector('.seo-tab') ? el.querySelector('.seo-tab').parentNode : null;
    if (bar) {
      bar.classList.add('lgse-tab-bar');
      // Inject Overview tab as the FIRST tab if not already present.
      if (!document.getElementById('seo-tab-overview')) {
        var ov = document.createElement('div');
        ov.className = 'seo-tab';
        ov.id = 'seo-tab-overview';
        ov.style.cssText = 'padding:10px 16px;cursor:pointer;font-size:12px;font-weight:500;color:var(--t3);border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap;display:flex;align-items:center;gap:6px';
        var ico = (typeof window.icon === 'function') ? window.icon('home', 14) : '';
        ov.innerHTML = ico + ' Overview';
        ov.onclick = function () { _seoSwitchTab('overview'); };
        bar.insertBefore(ov, bar.firstChild);
      }
    }
  };

  // Hook _seoSwitchTab — handle 'overview' + apply lgse-active class.
  var _origSwitchUI = window._seoSwitchTab;
  window._seoSwitchTab = function (tab) {
    if (tab === 'overview') {
      window._seoTab = 'overview';
      // Reset all tabs.
      var tabs = document.querySelectorAll('.seo-tab');
      Array.prototype.forEach.call(tabs, function (t) {
        t.style.color = 'var(--t3)';
        t.style.borderBottomColor = 'transparent';
        t.classList.remove('lgse-active');
      });
      var active = document.getElementById('seo-tab-overview');
      if (active) {
        active.style.color = 'var(--p)';
        active.style.borderBottomColor = 'var(--p)';
        active.classList.add('lgse-active');
      }
      var content = document.getElementById('seo-content');
      if (content) {
        content.innerHTML = (typeof loadingCard === 'function') ? loadingCard(220) : 'Loading…';
        _seoOverview(content);
      }
      return;
    }
    var ret = _origSwitchUI(tab);
    // After existing handler runs, ensure active class is in sync (some renderers replace innerHTML and lose classes).
    requestAnimationFrame(function () {
      var tabs2 = document.querySelectorAll('.seo-tab');
      Array.prototype.forEach.call(tabs2, function (t) { t.classList.remove('lgse-active'); });
      var active2 = document.getElementById('seo-tab-' + tab);
      if (active2) active2.classList.add('lgse-active');
    });
    return ret;
  };

  // Make Overview the default landing tab when seoLoad runs.
  var _origSeoLoad = window.seoLoad || seoLoad;
  window.seoLoad = function (el) {
    if (!el) return;
    el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%';
    lgseInjectStyles();
    _seoRenderShell(el);
    _seoSwitchTab('overview');
  };

  // ── Overview tab ────────────────────────────────────────────────────────
  window._seoOverview = async function (el) {
    try {
      var [knowledge, wins] = await Promise.all([
        _seoApi('GET', '/knowledge').catch(function () { return null; }),
        _seoApi('GET', '/wins?limit=5').catch(function () { return null; }),
      ]);

      // Knowledge endpoint returns the structured context object built by SeoKnowledgeService.
      var k = knowledge || {};
      var quickWins = [];
      if (Array.isArray(wins)) quickWins = wins;
      else if (wins && Array.isArray(wins.quick_wins)) quickWins = wins.quick_wins;
      else if (wins && Array.isArray(wins.wins)) quickWins = wins.wins;
      else if (k.quick_wins && Array.isArray(k.quick_wins)) quickWins = k.quick_wins;

      var health = parseInt(k.health_score || 0, 10);
      var content = k.content_health || {};
      var topicAuth = k.topic_authority || {};
      var linkHealth = k.link_health || {};
      var topIssues = Array.isArray(k.top_issues) ? k.top_issues : [];
      var keywordRanks = Array.isArray(k.keyword_rankings) ? k.keyword_rankings : [];
      var summary = k.summary || '';

      var pagesCount = parseInt(content.avg_score != null ? (content.below_50_count + (content.above_50_count || 0)) : 0, 10);
      // Use direct count when available.
      pagesCount = pagesCount || 0;

      var keywordCount = keywordRanks.length;

      var h = '<div class="lgse-animate">';

      // Hero row: gauge + KPIs
      h += '<div style="display:flex;align-items:flex-start;gap:32px;margin-bottom:32px;flex-wrap:wrap">';
      h += '<div id="lgse-overview-gauge" class="lgse-gauge-wrap" style="width:140px;height:140px;flex-shrink:0">Loading…</div>';
      h += '<div style="flex:1;min-width:280px">';
      h += '<div class="lgse-kpi-grid">';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Pages indexed</div><div class="lgse-kpi-value">' + (content.below_50_count != null ? '—' : '0') + '</div><div class="lgse-kpi-change same lgse-kpi-pages-change"></div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Keywords tracked</div><div class="lgse-kpi-value">' + keywordCount + '</div><div class="lgse-kpi-change same"></div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Quick wins</div><div class="lgse-kpi-value">' + quickWins.length + '</div><div class="lgse-kpi-change same"></div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Internal links</div><div class="lgse-kpi-value">' + (linkHealth.internal_link_count || 0) + '</div><div class="lgse-kpi-change same">orphans: ' + (linkHealth.orphan_count || 0) + '</div></div>';
      h += '</div></div></div>';

      // AI summary callout
      if (summary) {
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-left:3px solid var(--p);border-radius:8px;padding:14px 18px;margin-bottom:24px">';
        h += '<div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;font-weight:600">AI Summary</div>';
        h += '<div style="font-size:13px;color:var(--t1);line-height:1.55">' + escHtml(summary) + '</div>';
        h += '</div>';
      }

      // Issues strip
      h += '<div class="lgse-section-header"><span class="lgse-section-title">Active Issues</span></div>';
      h += '<div class="lgse-issues-strip">';
      if (topIssues.length === 0 && (content.below_50_count || 0) === 0 && (content.missing_meta_count || 0) === 0) {
        h += '<span style="color:var(--t3);font-size:12px">No active issues — site looks healthy.</span>';
      } else {
        topIssues.slice(0, 6).forEach(function (i) {
          var dotCol = i.type === 'critical' ? '#F87171' : i.type === 'errors' ? '#F87171' : '#F59E0B';
          h += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'audits\')"><span class="dot" style="background:' + dotCol + '"></span>' + escHtml(i.count + ' ' + i.type) + '</span>';
        });
        if ((content.missing_meta_count || 0) > 0) {
          h += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'pages\')"><span class="dot" style="background:#F87171"></span>' + (content.missing_meta_count || 0) + ' missing meta</span>';
        }
        if ((content.below_50_count || 0) > 0) {
          h += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'pages\')"><span class="dot" style="background:#F59E0B"></span>' + (content.below_50_count || 0) + ' pages &lt; 50</span>';
        }
        if ((linkHealth.orphan_count || 0) > 0) {
          h += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'links\')"><span class="dot" style="background:#F59E0B"></span>' + linkHealth.orphan_count + ' orphan pages</span>';
        }
        if (topicAuth.weakest_topic && topicAuth.weakest_topic.topic) {
          h += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'topics\')"><span class="dot" style="background:#3B8BF5"></span>weak topic: ' + escHtml(topicAuth.weakest_topic.topic) + '</span>';
        }
      }
      h += '</div>';

      // Quick wins
      h += '<div class="lgse-section-header"><span class="lgse-section-title">Quick Wins</span><span class="lgse-badge lgse-badge-info">Top 3</span></div>';
      if (quickWins.length === 0) {
        h += '<div class="lgse-empty-state"><div class="icon">✓</div><h3>No quick wins right now</h3><p>Run an audit or sync GSC to surface high-impact opportunities.</p></div>';
      } else {
        h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin-bottom:24px">';
        quickWins.slice(0, 3).forEach(function (w) {
          var label = w.issue_label || w.issue || w.gap || w.description || w.title || 'Optimization opportunity';
          var fix = w.fix || w.recommendation || w.action || '';
          var imp = parseInt(w.impact || w.priority || 0, 10);
          var badgeKind = imp >= 18 ? 'lgse-badge-danger' : imp >= 10 ? 'lgse-badge-warning' : 'lgse-badge-success';
          var badgeLabel = imp >= 18 ? 'HIGH' : imp >= 10 ? 'MED' : 'LOW';
          h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px">';
          h += '<div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:8px">';
          h += '<div style="font-size:13px;color:var(--t1);font-weight:600;line-height:1.4">' + escHtml(label) + '</div>';
          h += '<span class="lgse-badge ' + badgeKind + '">' + badgeLabel + '</span>';
          h += '</div>';
          if (fix) h += '<div style="font-size:12px;color:var(--t2);line-height:1.5">' + escHtml(fix) + '</div>';
          h += '</div>';
        });
        h += '</div>';
      }

      // Top keywords mini-table (if any)
      if (keywordRanks.length > 0) {
        h += '<div class="lgse-section-header"><span class="lgse-section-title">Tracked Keywords</span><span class="lgse-section-actions"><button class="lgse-btn lgse-btn-ghost" onclick="_seoSwitchTab(\'workspace\')">View all →</button></span></div>';
        h += '<div class="lgse-table-wrap" style="margin-bottom:24px"><table class="lgse-table">';
        h += '<thead><tr><th>Keyword</th><th class="num">Position</th><th class="num">Δ</th></tr></thead><tbody>';
        keywordRanks.slice(0, 5).forEach(function (kw) {
          var pos = parseInt(kw.pos || kw.position || 0, 10);
          var posBadge = pos <= 3 ? 'lgse-badge-success' : pos <= 10 ? 'lgse-badge-info' : pos <= 30 ? 'lgse-badge-warning' : 'lgse-badge-muted';
          h += '<tr>';
          h += '<td>' + escHtml(kw.kw || kw.keyword || '—') + '</td>';
          h += '<td class="num"><span class="lgse-badge ' + posBadge + '">' + pos + '</span></td>';
          h += '<td class="num">' + window.renderPosChange(kw.change || 0) + '</td>';
          h += '</tr>';
        });
        h += '</tbody></table></div>';
      }

      h += '</div>';
      el.innerHTML = h;

      // Render gauge after DOM is in place.
      var gaugeEl = document.getElementById('lgse-overview-gauge');
      if (gaugeEl) window.renderScoreGauge(gaugeEl, health, 140);

      // Pages-indexed count fix-up — knowledge service doesn't return a single
      // total but we can derive it from below_50_count + content_health.
      // Easier: hit /api/seo/indexed-content head for a real count.
      _seoApi('GET', '/indexed-content?limit=1').then(function (resp) {
        var total = (resp && (resp.total || resp.count)) || 0;
        if (!total && resp && Array.isArray(resp.items)) total = resp.items.length;
        var pagesValEl = el.querySelector('.lgse-kpi-card:nth-child(1) .lgse-kpi-value');
        if (pagesValEl) pagesValEl.textContent = total;
      }).catch(function () { /* silent */ });
    } catch (e) {
      el.innerHTML = '<div class="lgse-empty-state"><div class="icon">📊</div><h3>Could not load overview</h3><p>' + escHtml(e && e.message ? e.message : 'Try refreshing the page.') + '</p></div>';
    }
  };

  // ── Reskin: _seoAudits — wrap table with lgse-table classes ────────────
  var _origAudits = window._seoAudits;
  window._seoAudits = async function (el) {
    try {
      var data = await _seoApi('GET', '/audits').catch(function () { return null; });
      var rows = (data && (data.audits || data.data || data)) || [];
      if (!Array.isArray(rows)) rows = [];

      var h = '<div class="lgse-animate">';
      h += '<div class="lgse-section-header"><span class="lgse-section-title">Audit Center</span><span class="lgse-section-actions"><button class="lgse-btn lgse-btn-primary" onclick="_seoRunAudit()">Run new audit</button></span></div>';

      if (rows.length === 0) {
        h += '<div class="lgse-empty-state"><div class="icon">🔍</div><h3>No audits yet</h3><p>Run your first audit to see issues, scores, and a full health snapshot.</p><button class="lgse-btn lgse-btn-primary" onclick="_seoRunAudit()">Run audit</button></div>';
      } else {
        h += '<div class="lgse-table-wrap"><table class="lgse-table">';
        h += '<thead><tr><th>URL</th><th class="num">Score</th><th>Type</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>';
        rows.forEach(function (a) {
          var sc = parseInt(a.score || 0, 10);
          h += '<tr>';
          h += '<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escHtml(a.url || '—') + '</td>';
          h += '<td class="num">' + (sc > 0 ? '<span class="lgse-badge ' + scoreBadgeKind(sc) + '">' + sc + '</span>' : '<span style="color:var(--t3)">—</span>') + '</td>';
          h += '<td><span class="lgse-badge lgse-badge-muted">' + escHtml(a.type || '—') + '</span></td>';
          h += '<td><span class="lgse-badge ' + (a.status === 'completed' ? 'lgse-badge-success' : a.status === 'failed' ? 'lgse-badge-danger' : 'lgse-badge-info') + '">' + escHtml(a.status || '—') + '</span></td>';
          h += '<td style="color:var(--t2);font-size:12px">' + escHtml(a.created_at || '—') + '</td>';
          h += '<td><button class="lgse-btn lgse-btn-ghost" onclick="_seoViewAudit(' + (a.id || 0) + ')">View →</button></td>';
          h += '</tr>';
        });
        h += '</tbody></table></div></div>';
      }
      el.innerHTML = h;
    } catch (e) {
      if (typeof _origAudits === 'function') return _origAudits(el);
      el.innerHTML = '<div class="lgse-empty-state"><div class="icon">⚠</div><h3>Could not load audits</h3></div>';
    }
  };

  // ── Reskin: _seoKeywords — sparklines + position bands ─────────────────
  var _origKeywords = window._seoKeywords;
  window._seoKeywords = async function (el) {
    try {
      var data = await _seoApi('GET', '/keywords').catch(function () { return null; });
      var rows = (data && (data.keywords || data.data || data)) || [];
      if (!Array.isArray(rows)) rows = [];

      var h = '<div class="lgse-animate">';
      h += '<div class="lgse-section-header"><span class="lgse-section-title">Keyword Tracking</span><span class="lgse-section-actions"><button class="lgse-btn lgse-btn-primary" onclick="_seoAddKeyword()">+ Add keyword</button></span></div>';

      if (rows.length === 0) {
        h += '<div class="lgse-empty-state"><div class="icon">🎯</div><h3>No keywords tracked</h3><p>Add keywords to monitor their position in search results over time.</p></div>';
      } else {
        h += '<div class="lgse-table-wrap"><table class="lgse-table">';
        h += '<thead><tr><th>Keyword</th><th class="num">Position</th><th class="num">Δ</th><th>Trend</th><th class="num">Volume</th><th></th></tr></thead><tbody>';
        rows.forEach(function (kw) {
          var pos = parseInt(kw.current_rank || kw.position || kw.pos || 0, 10);
          var prev = parseInt(kw.previous_rank || 0, 10);
          var change = parseInt(kw.rank_change != null ? kw.rank_change : (prev > 0 ? prev - pos : 0), 10);
          var posBadge = pos === 0 ? 'lgse-badge-muted' : pos <= 3 ? 'lgse-badge-success' : pos <= 10 ? 'lgse-badge-info' : pos <= 30 ? 'lgse-badge-warning' : 'lgse-badge-muted';
          var vol = parseInt(kw.volume || 0, 10);
          var volTier = vol >= 10000 ? 'HIGH' : vol >= 1000 ? 'MED' : 'LOW';
          var volKind = vol >= 10000 ? 'lgse-badge-success' : vol >= 1000 ? 'lgse-badge-info' : 'lgse-badge-muted';
          // Build positions array from rank history if available; otherwise fall back to two-point change.
          var positions = Array.isArray(kw.positions) && kw.positions.length > 0 ? kw.positions : (prev > 0 && pos > 0 ? [{pos: prev}, {pos: pos}] : []);
          h += '<tr>';
          h += '<td>' + escHtml(kw.keyword || kw.kw || '—') + '</td>';
          h += '<td class="num">' + (pos > 0 ? '<span class="lgse-badge ' + posBadge + '">' + pos + '</span>' : '<span style="color:var(--t3)">—</span>') + '</td>';
          h += '<td class="num">' + window.renderPosChange(change) + '</td>';
          h += '<td>' + (positions.length > 1 ? window.renderSparkline(positions, 60, 20) : '<span style="color:var(--t3);font-size:11px">—</span>') + '</td>';
          h += '<td class="num">' + (vol > 0 ? '<span class="lgse-badge ' + volKind + '">' + volTier + '</span> ' + vol.toLocaleString() : '<span style="color:var(--t3)">—</span>') + '</td>';
          h += '<td><button class="lgse-btn lgse-btn-ghost" onclick="_seoDeleteKeyword(' + (kw.id || 0) + ')">×</button></td>';
          h += '</tr>';
        });
        h += '</tbody></table></div></div>';
      }
      el.innerHTML = h;
    } catch (e) {
      if (typeof _origKeywords === 'function') return _origKeywords(el);
      el.innerHTML = '<div class="lgse-empty-state"><div class="icon">⚠</div><h3>Could not load keywords</h3></div>';
    }
  };

  // ── Reskin: _seoGsc — connection pill at top ───────────────────────────
  var _origGsc = window._seoGsc;
  window._seoGsc = async function (el) {
    try {
      var status = await _seoApi('GET', '/gsc/status').catch(function () { return null; });
      var connected = !!(status && (status.connected || status.is_connected || status.configured));
      var lastSync = (status && (status.last_synced_at || status.synced_at)) || null;
      var siteUrl = (status && status.site_url) || '';

      var h = '<div class="lgse-animate">';
      h += '<div class="lgse-section-header">';
      h += '<span class="lgse-section-title">Search Console</span>';
      h += '<span class="lgse-section-actions">';
      if (connected) {
        h += '<span class="lgse-conn-pill connected"><span class="dot"></span>Connected' + (lastSync ? ' — synced ' + escHtml(lastSync) : '') + '</span>';
        h += '<button class="lgse-btn lgse-btn-secondary" onclick="_seoApi(\'POST\',\'/gsc/sync\',{}).then(function(){ _seoGsc(document.getElementById(\'seo-content\')); })">Sync now</button>';
      } else {
        h += '<span class="lgse-conn-pill disconnected"><span class="dot"></span>Not connected</span>';
        h += '<button class="lgse-btn lgse-btn-primary" onclick="_seoConnectGsc()">Connect →</button>';
      }
      h += '</span></div>';

      if (!connected) {
        h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:24px;text-align:center">';
        h += '<div style="font-size:32px;margin-bottom:12px;opacity:.6">🔗</div>';
        h += '<h3 style="font-size:14px;color:var(--t1);margin:0 0 8px">Connect Google Search Console</h3>';
        h += '<p style="font-size:12px;color:var(--t2);max-width:420px;margin:0 auto 16px">Sync clicks, impressions, CTR, and position data into your LevelUp workspace. Required for traffic insights and lift-potential pages.</p>';
        h += '<button class="lgse-btn lgse-btn-primary" onclick="_seoConnectGsc()">Connect with Google →</button>';
        h += '</div>';
        el.innerHTML = h + '</div>';
        return;
      }

      // Connected — fetch top queries.
      var qResp = await _seoApi('GET', '/gsc/queries').catch(function () { return null; });
      var queries = (qResp && (qResp.queries || qResp.data)) || [];
      h += '<div style="margin-top:16px">';
      h += '<div class="lgse-section-header"><span class="lgse-section-title">Top Queries' + (siteUrl ? ' — ' + escHtml(siteUrl) : '') + '</span></div>';
      if (!Array.isArray(queries) || queries.length === 0) {
        h += '<div class="lgse-empty-state"><div class="icon">📡</div><h3>No GSC data yet</h3><p>Click "Sync now" to fetch the latest 28 days of Search Console data.</p></div>';
      } else {
        // Find max ctr for heatmap.
        var maxCtr = 0;
        queries.forEach(function (q) { if ((q.ctr || 0) > maxCtr) maxCtr = q.ctr || 0; });
        h += '<div class="lgse-table-wrap"><table class="lgse-table">';
        h += '<thead><tr><th>Query</th><th class="num">Clicks</th><th class="num">Impressions</th><th class="num">CTR</th><th class="num">Position</th></tr></thead><tbody>';
        queries.slice(0, 30).forEach(function (q) {
          var ctr = parseFloat(q.ctr || 0);
          var pos = parseFloat(q.position || 0);
          var ctrPct = (ctr * 100).toFixed(2) + '%';
          var heat = maxCtr > 0 ? Math.min(0.32, (ctr / maxCtr) * 0.32) : 0;
          var posBadge = pos <= 3 ? 'lgse-badge-success' : pos <= 10 ? 'lgse-badge-info' : pos <= 30 ? 'lgse-badge-warning' : 'lgse-badge-muted';
          h += '<tr>';
          h += '<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escHtml(q.query || '—') + '</td>';
          h += '<td class="num">' + (q.clicks || 0) + '</td>';
          h += '<td class="num">' + (q.impressions || 0) + '</td>';
          h += '<td class="num" style="background:rgba(108,92,231,' + heat + ')">' + ctrPct + '</td>';
          h += '<td class="num"><span class="lgse-badge ' + posBadge + '">' + (pos > 0 ? pos.toFixed(1) : '—') + '</span></td>';
          h += '</tr>';
        });
        h += '</tbody></table></div>';
      }
      h += '</div></div>';
      el.innerHTML = h;
    } catch (e) {
      if (typeof _origGsc === 'function') return _origGsc(el);
      el.innerHTML = '<div class="lgse-empty-state"><div class="icon">⚠</div><h3>GSC unavailable</h3></div>';
    }
  };

  window._seoConnectGsc = async function () {
    try {
      var r = await _seoApi('GET', '/gsc/auth-url');
      if (r && r.url) window.open(r.url, '_blank');
      else if (typeof showToast === 'function') showToast('Auth URL unavailable', 'error');
    } catch (e) { if (typeof showToast === 'function') showToast('Failed: ' + (e.message || ''), 'error'); }
  };

  // ── Reskin: ensure Reports / Insights / Topics / Competitors get the
  //   tab-bar styling. Their renderers were already redesigned in W2/W3.
  //   No further changes needed — CSS variable-based styling carries through.
})();

// ═══════════════════════════════════════════════════════════════════════════
// SEO Engine — Critical Fixes (LB-SEO-FIX, 2026-04-28)
// 1. Audit detail: structured render (was raw JSON)
// 2. Integrations: KPI summary (was raw JSON)
// 3. Tab consolidation: 20+ → 9
// 4. Overview KPIs: real counts (were zeros)
// 5. Polish: page titles, empty states, button consistency
// Append-only progressive enhancement. No backend changes.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function scoreColor(s) {
    s = parseInt(s, 10) || 0;
    if (s >= 90) return '#00E5A8';
    if (s >= 70) return '#3B8BF5';
    if (s >= 50) return '#F59E0B';
    return '#F87171';
  }
  function scoreBadgeKind(s) {
    s = parseInt(s, 10) || 0;
    if (s >= 90) return 'lgse-badge-success';
    if (s >= 70) return 'lgse-badge-info';
    if (s >= 50) return 'lgse-badge-warning';
    return 'lgse-badge-danger';
  }
  function pageHeader(title, desc) {
    return '<div style="margin-bottom:24px">' +
      '<h2 style="font-family:var(--fh,inherit);font-size:20px;font-weight:600;color:var(--t1);margin:0 0 4px">' + escHtml(title) + '</h2>' +
      '<p style="font-size:13px;color:var(--t3);margin:0">' + escHtml(desc) + '</p>' +
      '</div>';
  }

  // ── FIX 3 — Tab consolidation: 20+ → 9 ─────────────────────────────────
  // Override _seoRenderShell to render exactly 9 top-level tabs.
  // Old tab IDs are mapped onto the 9 in _seoSwitchTab so deep links keep working.

  var TABS = [
    { id: 'overview',    label: 'Overview',     icon: 'home' },
    { id: 'audits',      label: 'Audit',        icon: 'info' },
    { id: 'workspace',   label: 'Keywords',     icon: 'chart' },
    { id: 'pages',       label: 'Pages',        icon: 'edit' },
    { id: 'links',       label: 'Links',        icon: 'link' },
    { id: 'topics',      label: 'Topics',       icon: 'chart' },
    { id: 'competitors', label: 'Competitors',  icon: 'globe' },
    { id: 'insights',    label: 'Insights',     icon: 'info' },
    { id: 'reports',     label: 'Reports',      icon: 'check' },
    { id: 'pipeline',    label: 'Pipeline',     icon: 'edit' },
  ];

  // FIX 2026-05-11 (sprint): Tabs with unimplemented backend — hidden until ready.
  // Backed by the Phase 1 audit which confirmed these endpoints return 404:
  // /clusters, /anchors/bulk-analysis, /competitors/tracked, /gsc/*,
  // /insights/content-performance, /insights/top-pages, /equity/*, /quick-wins,
  // /image-issues, /ctr-analysis, /link-graph, /indexed-content.
  var _SEO_ORPHAN_TABS = [
    'clusters','anchors','competitors','gsc',
    'insights','equity','quick-wins','image-issues',
    'ctr-analysis','link-graph','indexed-content',
    'workspace','integrations','images','ctr','wins'
  ];
  TABS = TABS.filter(function (t) {
    var id = typeof t === 'string' ? t : (t.id || t.key || t);
    return _SEO_ORPHAN_TABS.indexOf(id) === -1;
  });

  // Map legacy tab IDs to the consolidated tab ID. Used by _seoSwitchTab so
  // any code calling _seoSwitchTab('outbound') still ends up on Links, etc.
  var TAB_ALIAS = {
    dashboard:     'overview',
    content:       'overview',  // Content AI moved to Write engine — surface its absence via Overview
    goals:         'overview',  // Goals belong to Sarah; Overview shows quick wins
    wins:          'overview',
    score:         'pages',
    settings:      'reports',   // Settings becomes a header action; for now route to Reports
    integrations:  'reports',
    images:        'pages',
    ctr:           'pages',
    outbound:      'links',
    redirects:     'links',
    gsc:           'insights',
  };

  // Override the shell. Inject our own tab strip; preserve seo-content area.
  window._seoRenderShell = function (el) {
    if (typeof window.lgseInjectStyles === 'function') window.lgseInjectStyles();

    var tabHtml = TABS.map(function (t) {
      var iconHtml = (typeof window.icon === 'function') ? window.icon(t.icon, 14) : '';
      return '<div class="seo-tab lgse-tab" id="seo-tab-' + t.id + '" ' +
        'onclick="_seoSwitchTab(\'' + t.id + '\')" ' +
        'style="padding:10px 16px;cursor:pointer;font-size:12px;font-weight:500;color:var(--t3);border-bottom:2px solid transparent;transition:color .15s,border-color .15s;white-space:nowrap;display:flex;align-items:center;gap:6px">' +
        iconHtml + ' ' + t.label + '</div>';
    }).join('');

    el.innerHTML =
      '<div class="lgse-tab-bar" style="display:flex;border-bottom:1px solid var(--bd);background:var(--s1);flex-shrink:0;overflow-x:auto">' + tabHtml + '</div>' +
      '<div id="seo-content" style="flex:1;overflow-y:auto;padding:24px"></div>';
  };

  // Override _seoSwitchTab: alias old IDs, set active class, dispatch to renderer.
  window._seoSwitchTab = function (tab) {
    // FIX 2026-05-11 (sprint): coming-soon safety net for any orphan tab id
    // that still slips through (e.g. deep-link). The orphan list mirrors
    // _SEO_ORPHAN_TABS above.
    var _SEO_COMING_SOON = [
      'clusters','anchors','competitors','gsc',
      'insights','equity','quick-wins','image-issues',
      'ctr-analysis','link-graph','indexed-content'
    ];
    if (_SEO_COMING_SOON.indexOf(tab) > -1) {
      var _csEl = document.getElementById('seo-content');
      if (_csEl) _csEl.innerHTML =
        '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60dvh;gap:16px;text-align:center;padding:40px">' +
          '<h3 style="font:700 20px/1.3 Manrope,sans-serif;color:#F0F0F0;margin:0">Coming Soon</h3>' +
          '<p style="font:400 15px/1.6 Inter,sans-serif;color:#9CA3AF;max-width:300px;margin:0">This feature is being integrated.</p>' +
        '</div>';
      return;
    }

    // Resolve legacy tab IDs to new 9-tab structure.
    if (TAB_ALIAS[tab]) tab = TAB_ALIAS[tab];
    window._seoTab = tab;

    // Reset all tab styles, set active.
    var tabs = document.querySelectorAll('.seo-tab');
    Array.prototype.forEach.call(tabs, function (t) {
      t.style.color = 'var(--t3)';
      t.style.borderBottomColor = 'transparent';
      t.classList.remove('lgse-active');
    });
    var active = document.getElementById('seo-tab-' + tab);
    if (active) {
      active.style.color = 'var(--p)';
      active.style.borderBottomColor = 'var(--p)';
      active.classList.add('lgse-active');
    }

    var content = document.getElementById('seo-content');
    if (!content) return;
    content.innerHTML = (typeof loadingCard === 'function') ? loadingCard(220) : 'Loading…';

    // Dispatch.
    if      (tab === 'overview')    _seoOverview(content);
    else if (tab === 'audits')      _seoAudits(content);
    else if (tab === 'workspace')   _seoWorkspace(content);
    else if (tab === 'pages')       _seoPagesAll(content);
    else if (tab === 'links')       _seoLinksAll(content);
    else if (tab === 'topics')      _seoTopics(content);
    else if (tab === 'competitors') _seoCompetitors(content);
    else if (tab === 'insights')    _seoInsightsAll(content);
    else if (tab === 'reports')     _seoReports(content);
    else if (tab === 'pipeline')    _seoPipeline(content);
    else _seoOverview(content);
  };

  // Make Overview the default tab on first load.
  window.seoLoad = function (el) {
    if (!el) return;
    el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%';
    if (typeof window.lgseInjectStyles === 'function') window.lgseInjectStyles();
    _seoRenderShell(el);
    _seoSwitchTab('overview');
  };

  // ── Compose: Pages tab (pages + images + CTR + quick wins) ─────────────
  window._seoPagesAll = async function (el) {
    el.innerHTML = pageHeader('Pages', 'On-page optimization across content, images, and CTR. Click any sub-section to focus.') +
      '<div class="lgse-tab-bar" style="margin-bottom:18px;border-bottom:1px solid var(--bd);background:transparent">' +
        '<div class="lgse-subtab seo-tab lgse-active" data-section="indexed" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--p);border-bottom:2px solid var(--p);white-space:nowrap">Indexed Content</div>' +
        '<div class="lgse-subtab seo-tab" data-section="images" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--t3);border-bottom:2px solid transparent;white-space:nowrap">Images</div>' +
        '<div class="lgse-subtab seo-tab" data-section="ctr" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--t3);border-bottom:2px solid transparent;white-space:nowrap">CTR</div>' +
        '<div class="lgse-subtab seo-tab" data-section="wins" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--t3);border-bottom:2px solid transparent;white-space:nowrap">Quick Wins</div>' +
      '</div>' +
      '<div id="lgse-pages-section" class="lgse-animate"></div>';

    var section = el.querySelector('#lgse-pages-section');

    function loadSection(name) {
      // Active sub-tab styling.
      Array.prototype.forEach.call(el.querySelectorAll('.lgse-subtab'), function (t) {
        var isActive = t.getAttribute('data-section') === name;
        t.style.color = isActive ? 'var(--p)' : 'var(--t3)';
        t.style.borderBottomColor = isActive ? 'var(--p)' : 'transparent';
      });
      section.innerHTML = (typeof loadingCard === 'function') ? loadingCard(180) : 'Loading…';
      if (name === 'indexed' && typeof _seoPages === 'function') return _seoPages(section);
      if (name === 'images' && typeof _seoImages === 'function') return _seoImages(section);
      if (name === 'ctr' && typeof _seoCtr === 'function')      return _seoCtr(section);
      if (name === 'wins' && typeof _seoWins === 'function')    return _seoWins(section);
      section.innerHTML = '<div class="lgse-empty-state"><div class="icon">·</div><h3>Section unavailable</h3></div>';
    }

    Array.prototype.forEach.call(el.querySelectorAll('.lgse-subtab'), function (t) {
      t.addEventListener('click', function () { loadSection(t.getAttribute('data-section')); });
    });
    loadSection('indexed');
  };


  // ── Compose: Pipeline tab — Queue + Calendar sub-tabs ─────────────────
  // 2026-05-12 — moved from standalone sidebar engine into the SEO tab
  // strip per spec. Queue (task list, auto-poll 8s) + Calendar (month grid).
  var _lgsePipe = { sub: 'queue', month: null, pipeline: null, calendar: null, pollT: null };

  function _lgsePipeEsc(s) {
    return (s == null ? '' : String(s))
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function _lgsePipeBadge(action) {
    var map = {
      generate_article:'#7C3AED', write_article:'#7C3AED', optimize_article:'#8B5CF6',
      improve_draft:'#8B5CF6', deep_audit:'#3B82F6', serp_analysis:'#06B6D4',
      keyword_research:'#06B6D4', bulk_generate_meta:'#F59E0B', generate_meta:'#F59E0B',
      generate_image:'#EC4899', autonomous_goal:'#00E5A8', agent_goal:'#F59E0B',
      link_suggestions:'#10B981',
    };
    return map[action] || '#6B7280';
  }
  function _lgsePipeStatusCol(s) {
    if (s === 'completed') return '#10B981';
    if (s === 'running' || s === 'verifying') return '#3B82F6';
    if (s === 'failed' || s === 'degraded' || s === 'blocked') return '#EF4444';
    if (s === 'cancelled') return '#6B7280';
    return '#F59E0B';
  }
  function _lgsePipeFmtAction(a) {
    return String(a || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }
  function _lgsePipeFmtDate(iso) {
    if (!iso) return '';
    try { return new Date(String(iso).replace(' ', 'T')).toLocaleString(); }
    catch (_) { return String(iso); }
  }

  async function _lgsePipeFetchQueue() {
    try {
      if (typeof window._luFetch === 'function') {
        var r = await window._luFetch('GET', '/connector/content/pipeline', null);
        return await r.json();
      }
      var r2 = await fetch('/api/connector/content/pipeline', {
        headers: { 'Accept':'application/json',
                   'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') },
      });
      return await r2.json();
    } catch (e) { return { success: false, error: 'fetch_failed' }; }
  }
  async function _lgsePipeFetchCal(month) {
    try {
      if (typeof window._luFetch === 'function') {
        var r = await window._luFetch('GET', '/connector/content/calendar?month=' + encodeURIComponent(month), null);
        return await r.json();
      }
      var r2 = await fetch('/api/connector/content/calendar?month=' + encodeURIComponent(month), {
        headers: { 'Accept':'application/json',
                   'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') },
      });
      return await r2.json();
    } catch (e) { return { success: false, error: 'fetch_failed' }; }
  }

  function _lgsePipeRenderQueue() {
    var p = _lgsePipe.pipeline;
    if (!p) { return '<div style="padding:32px;text-align:center;color:var(--t3)">Loading queue…</div>'; }
    if (!p.success) { return '<div style="padding:32px;text-align:center;color:#EF4444">Failed to load pipeline.</div>'; }
    var c = p.counts || {};
    var h = '';
    h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px">';
    [{l:'Queued',v:c.queued||0,c:'#F59E0B'},{l:'Running',v:c.running||0,c:'#3B82F6'},
     {l:'Completed',v:c.completed||0,c:'#10B981'},{l:'Failed',v:c.failed||0,c:'#EF4444'}].forEach(function(x){
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:14px 16px">'
         +   '<div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px">'+x.l+'</div>'
         +   '<div style="font-size:24px;font-weight:700;color:'+x.c+';margin-top:4px">'+x.v+'</div>'
         + '</div>';
    });
    h += '</div>';

    var rows = [];
    ['running','queued','completed','failed','cancelled'].forEach(function(b){ rows = rows.concat(p.pipeline[b] || []); });
    if (rows.length === 0) {
      h += '<div style="padding:48px;text-align:center;color:var(--t3);background:var(--s1);border:1px dashed var(--bd);border-radius:10px">'
         + 'No tasks yet.<br><span style="font-size:12px">When you trigger article generation or audits, they appear here.</span></div>';
      return h;
    }
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden">';
    h += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    h += '<thead style="background:var(--s2)"><tr>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Type</th>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Task</th>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Status</th>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Progress</th>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Created</th>'
       + '</tr></thead><tbody>';
    rows.forEach(function(t){
      var bc = _lgsePipeBadge(t.task_type);
      var sc = _lgsePipeStatusCol(t.status);
      var pr = (t.progress >= 0 ? t.progress : 0);
      h += '<tr style="border-top:1px solid var(--bd)">'
         +   '<td style="padding:10px 14px"><span style="background:'+bc+'15;color:'+bc+';padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600">'+_lgsePipeFmtAction(t.task_type)+'</span></td>'
         +   '<td style="padding:10px 14px;color:var(--t1)">'+_lgsePipeEsc(t.result_summary || _lgsePipeFmtAction(t.task_type))+'</td>'
         +   '<td style="padding:10px 14px"><span style="color:'+sc+';font-weight:600">'+_lgsePipeEsc(t.status)+'</span></td>'
         +   '<td style="padding:10px 14px"><div style="background:var(--s2);border-radius:3px;height:6px;width:100px;overflow:hidden"><div style="background:'+sc+';height:100%;width:'+pr+'%"></div></div></td>'
         +   '<td style="padding:10px 14px;color:var(--t3);font-size:11px">'+_lgsePipeEsc(_lgsePipeFmtDate(t.created_at))+'</td>'
         + '</tr>';
    });
    h += '</tbody></table></div>';
    return h;
  }

  function _lgsePipeRenderCal() {
    var cal = _lgsePipe.calendar;
    if (!cal) { return '<div style="padding:32px;text-align:center;color:var(--t3)">Loading calendar…</div>'; }
    if (!cal.success) { return '<div style="padding:32px;text-align:center;color:#EF4444">Failed to load calendar.</div>'; }
    var month = cal.month || _lgsePipe.month;
    var parts = month.split('-');
    var year = parseInt(parts[0], 10);
    var mo = parseInt(parts[1], 10);
    var label = new Date(year, mo-1, 1).toLocaleString('default', { month:'long', year:'numeric' });
    var firstDow = new Date(year, mo-1, 1).getDay();
    var lastDay = new Date(year, mo, 0).getDate();
    var prevM=mo-1,prevY=year; if(prevM<1){prevM=12;prevY--;}
    var nextM=mo+1,nextY=year; if(nextM>12){nextM=1;nextY++;}
    var pM = prevY+'-'+String(prevM).padStart(2,'0');
    var nM = nextY+'-'+String(nextM).padStart(2,'0');
    var h = '';
    h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">';
    h += '<button onclick="window._lgsePipeSetMonth(\''+pM+'\')" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px">← Prev</button>';
    h += '<h2 style="margin:0;font-size:18px;font-weight:700">'+_lgsePipeEsc(label)+'</h2>';
    h += '<button onclick="window._lgsePipeSetMonth(\''+nM+'\')" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px">Next →</button>';
    h += '</div>';
    h += '<div style="display:flex;gap:14px;margin-bottom:14px;font-size:11px;color:var(--t3)">'
       + '<span><span style="background:#10B981;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Completed</span>'
       + '<span><span style="background:#3B82F6;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Running</span>'
       + '<span><span style="background:#F59E0B;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Queued</span>'
       + '<span><span style="background:#7C3AED;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Article</span>'
       + '</div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden">';
    h += '<div style="display:grid;grid-template-columns:repeat(7,1fr);background:var(--s2);font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.5px">';
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(d){
      h += '<div style="padding:8px 10px;border-right:1px solid var(--bd);text-align:center">'+d+'</div>';
    });
    h += '</div>';
    h += '<div style="display:grid;grid-template-columns:repeat(7,1fr)">';
    for (var i=0;i<firstDow;i++) {
      h += '<div style="min-height:80px;background:var(--s1);border-right:1px solid var(--bd);border-top:1px solid var(--bd)"></div>';
    }
    var days = cal.days || {};
    for (var d=1; d<=lastDay; d++) {
      var key = year+'-'+String(mo).padStart(2,'0')+'-'+String(d).padStart(2,'0');
      var items = days[key] || [];
      h += '<div style="min-height:80px;background:var(--s1);border-right:1px solid var(--bd);border-top:1px solid var(--bd);padding:6px 8px;font-size:11px">';
      h += '<div style="color:var(--t3);font-weight:600;margin-bottom:4px">'+d+'</div>';
      var maxShow=3;
      for (var j=0; j<Math.min(items.length, maxShow); j++) {
        var it = items[j];
        var col = it.type === 'article' ? '#7C3AED' : _lgsePipeStatusCol(it.status);
        var tt = String(it.title || '').slice(0,22)+(String(it.title||'').length>22?'…':'');
        h += '<div style="background:'+col+'20;color:'+col+';padding:2px 5px;border-radius:3px;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+_lgsePipeEsc(it.title||'')+'">'+_lgsePipeEsc(tt)+'</div>';
      }
      if (items.length > maxShow) {
        h += '<div style="color:var(--t3);font-size:10px">+'+(items.length-maxShow)+' more</div>';
      }
      h += '</div>';
    }
    h += '</div></div>';
    return h;
  }

  function _lgsePipeRender() {
    var el = document.getElementById('seo-content');
    if (!el) return;
    var sub = _lgsePipe.sub;
    var html = '';
    html += '<div class="lgse-tab-bar" style="margin-bottom:18px;border-bottom:1px solid var(--bd);background:transparent">'
         + '<div class="lgse-subtab seo-tab' + (sub==='queue'?' lgse-active':'') + '" onclick="window._lgsePipeSetSub(\'queue\')" '
         + 'style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:'+(sub==='queue'?'var(--p)':'var(--t3)')+';border-bottom:2px solid '+(sub==='queue'?'var(--p)':'transparent')+';white-space:nowrap">Queue</div>'
         + '<div class="lgse-subtab seo-tab' + (sub==='calendar'?' lgse-active':'') + '" onclick="window._lgsePipeSetSub(\'calendar\')" '
         + 'style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:'+(sub==='calendar'?'var(--p)':'var(--t3)')+';border-bottom:2px solid '+(sub==='calendar'?'var(--p)':'transparent')+';white-space:nowrap">Calendar</div>'
         + '</div>';
    html += sub === 'calendar' ? _lgsePipeRenderCal() : _lgsePipeRenderQueue();
    el.innerHTML = html;
  }

  window._lgsePipeSetSub = function (s) {
    _lgsePipe.sub = s;
    _lgsePipeRender();
    if (s === 'calendar' && !_lgsePipe.calendar) {
      _lgsePipeFetchCal(_lgsePipe.month).then(function (d) {
        _lgsePipe.calendar = d; _lgsePipeRender();
      });
    }
  };
  window._lgsePipeSetMonth = function (m) {
    _lgsePipe.month = m; _lgsePipe.calendar = null; _lgsePipeRender();
    _lgsePipeFetchCal(m).then(function (d) { _lgsePipe.calendar = d; _lgsePipeRender(); });
  };

  function _lgsePipeStartPoll() {
    if (_lgsePipe.pollT) return;
    _lgsePipe.pollT = setInterval(function () {
      if (_lgsePipe.sub !== 'queue') return;
      _lgsePipeFetchQueue().then(function (d) {
        _lgsePipe.pipeline = d; _lgsePipeRender();
        if (d.success && (!d.counts || d.counts.running === 0)) {
          clearInterval(_lgsePipe.pollT); _lgsePipe.pollT = null;
        }
      });
    }, 8000);
  }

  window._seoPipeline = async function (el) {
    if (!_lgsePipe.month) {
      var dt = new Date();
      _lgsePipe.month = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0');
    }
    _lgsePipeRender();
    var d = await _lgsePipeFetchQueue();
    _lgsePipe.pipeline = d;
    _lgsePipeRender();
    if (d.success && d.counts && d.counts.running > 0) _lgsePipeStartPoll();
  };

  // ── Compose: Links tab (link intel + outbound + redirects) ─────────────
  window._seoLinksAll = async function (el) {
    el.innerHTML = pageHeader('Links', 'Internal link graph, outbound link health, and 301/302 redirects in one place.') +
      '<div class="lgse-tab-bar" style="margin-bottom:18px;border-bottom:1px solid var(--bd);background:transparent">' +
        '<div class="lgse-subtab seo-tab lgse-active" data-section="internal" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--p);border-bottom:2px solid var(--p);white-space:nowrap">Link Intelligence</div>' +
        '<div class="lgse-subtab seo-tab" data-section="outbound" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--t3);border-bottom:2px solid transparent;white-space:nowrap">Outbound</div>' +
        '<div class="lgse-subtab seo-tab" data-section="redirects" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--t3);border-bottom:2px solid transparent;white-space:nowrap">Redirects</div>' +
      '</div>' +
      '<div id="lgse-links-section" class="lgse-animate"></div>';

    var section = el.querySelector('#lgse-links-section');
    function loadSection(name) {
      Array.prototype.forEach.call(el.querySelectorAll('.lgse-subtab'), function (t) {
        var isActive = t.getAttribute('data-section') === name;
        t.style.color = isActive ? 'var(--p)' : 'var(--t3)';
        t.style.borderBottomColor = isActive ? 'var(--p)' : 'transparent';
      });
      section.innerHTML = (typeof loadingCard === 'function') ? loadingCard(180) : 'Loading…';
      if (name === 'internal' && typeof _seoLinks === 'function')      return _seoLinks(section);
      if (name === 'outbound' && typeof _seoOutbound === 'function')   return _seoOutbound(section);
      if (name === 'redirects' && typeof _seoRedirects === 'function') return _seoRedirects(section);
      section.innerHTML = '<div class="lgse-empty-state"><div class="icon">·</div><h3>Section unavailable</h3></div>';
    }

    Array.prototype.forEach.call(el.querySelectorAll('.lgse-subtab'), function (t) {
      t.addEventListener('click', function () { loadSection(t.getAttribute('data-section')); });
    });
    loadSection('internal');
  };

  // ── Compose: Insights tab (GSC connection + traffic insights) ──────────
  window._seoInsightsAll = async function (el) {
    el.innerHTML = pageHeader('Insights', 'Search Console performance + AI-driven traffic insights and content correlations.') +
      '<div class="lgse-tab-bar" style="margin-bottom:18px;border-bottom:1px solid var(--bd);background:transparent">' +
        '<div class="lgse-subtab seo-tab lgse-active" data-section="traffic" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--p);border-bottom:2px solid var(--p);white-space:nowrap">Traffic Insights</div>' +
        '<div class="lgse-subtab seo-tab" data-section="gsc" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--t3);border-bottom:2px solid transparent;white-space:nowrap">Search Console</div>' +
      '</div>' +
      '<div id="lgse-insights-section" class="lgse-animate"></div>';

    var section = el.querySelector('#lgse-insights-section');
    function loadSection(name) {
      Array.prototype.forEach.call(el.querySelectorAll('.lgse-subtab'), function (t) {
        var isActive = t.getAttribute('data-section') === name;
        t.style.color = isActive ? 'var(--p)' : 'var(--t3)';
        t.style.borderBottomColor = isActive ? 'var(--p)' : 'transparent';
      });
      section.innerHTML = (typeof loadingCard === 'function') ? loadingCard(180) : 'Loading…';
      if (name === 'traffic' && typeof _seoInsights === 'function') return _seoInsights(section);
      if (name === 'gsc' && typeof _seoGsc === 'function')           return _seoGsc(section);
      section.innerHTML = '<div class="lgse-empty-state"><div class="icon">·</div><h3>Section unavailable</h3></div>';
    }

    Array.prototype.forEach.call(el.querySelectorAll('.lgse-subtab'), function (t) {
      t.addEventListener('click', function () { loadSection(t.getAttribute('data-section')); });
    });
    loadSection('traffic');
  };

  // ── FIX 1 — Audit detail: structured render (was raw JSON) ─────────────
  window._seoViewAudit = async function (id) {
    var content = document.getElementById('seo-content');
    if (!content) return;
    content.innerHTML = (typeof loadingCard === 'function') ? loadingCard(180) : 'Loading…';
    try {
      var a = await _seoApi('GET', '/audits/' + id);
      // Some API shapes wrap the audit in {audit: {...}} or {data: {...}}.
      if (a && (a.audit || a.data)) a = a.audit || a.data;

      var results = {};
      try {
        if (typeof a.results_json === 'string') results = JSON.parse(a.results_json);
        else if (a.results_json && typeof a.results_json === 'object') results = a.results_json;
        else if (a.results) results = a.results;
      } catch (e) { results = {}; }

      var checks = Array.isArray(results.checks) ? results.checks
        : (Array.isArray(results.issues) ? results.issues
        : (Array.isArray(a.issues_json) ? a.issues_json : []));

      // Some audit types have keyed sections instead of a flat checks[] list.
      // Normalise into {category, check, status, details}.
      var normalised = [];
      checks.forEach(function (c) {
        if (!c || typeof c !== 'object') return;
        normalised.push({
          category: c.category || c.section || c.type || 'general',
          check:    c.check || c.title || c.label || c.name || '—',
          status:   (c.status || (c.severity === 'error' ? 'error' : c.severity === 'warning' ? 'warning' : 'pass')),
          details:  c.details || c.message || c.description || c.fix || '',
          impact:   c.impact || c.priority || 0,
        });
      });

      var errors = normalised.filter(function (c) { return c.status === 'error' || c.status === 'fail'; });
      var warnings = normalised.filter(function (c) { return c.status === 'warning'; });
      var passed = normalised.filter(function (c) { return c.status === 'pass' || c.status === 'success'; });

      var categories = {};
      normalised.forEach(function (c) {
        if (!categories[c.category]) categories[c.category] = [];
        categories[c.category].push(c);
      });

      var score = parseInt(a.score || results.score || 0, 10);
      var url = a.url || results.url || '';
      var type = a.type || 'audit';
      var when = a.created_at ? new Date(a.created_at).toLocaleDateString() : '';

      var h = '<div class="lgse-animate" style="padding:0 0 32px">';

      // Header: gauge + title + back link.
      h += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:18px"><button class="lgse-btn lgse-btn-ghost" onclick="_seoSwitchTab(\'audits\')">← Back to audits</button></div>';

      h += '<div style="display:flex;align-items:center;gap:24px;margin-bottom:24px;flex-wrap:wrap">';
      h += '<div id="audit-detail-gauge" class="lgse-gauge-wrap" style="width:96px;height:96px;flex-shrink:0">Loading…</div>';
      h += '<div style="flex:1;min-width:240px">';
      h += '<div style="font-family:var(--fh,inherit);font-size:18px;font-weight:600;color:var(--t1);margin-bottom:4px;word-break:break-all">' + escHtml(url || 'Audit Report') + '</div>';
      h += '<div style="font-size:12px;color:var(--t3);margin-bottom:10px">' + escHtml(type) + ' audit' + (when ? ' · ' + escHtml(when) : '') + '</div>';
      h += '<div style="display:flex;gap:6px;flex-wrap:wrap">';
      h += '<span class="lgse-badge lgse-badge-danger">' + errors.length + ' errors</span>';
      h += '<span class="lgse-badge lgse-badge-warning">' + warnings.length + ' warnings</span>';
      h += '<span class="lgse-badge lgse-badge-success">' + passed.length + ' passed</span>';
      h += '</div></div></div>';

      // Categories.
      if (Object.keys(categories).length === 0) {
        // Fall back to a key/value table of the raw results for unknown audit types.
        h += '<div class="lgse-section-header"><span class="lgse-section-title">Audit Details</span></div>';
        h += '<div class="lgse-table-wrap"><table class="lgse-table"><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>';
        var emitted = 0;
        Object.keys(results).forEach(function (k) {
          if (emitted >= 30) return;
          var v = results[k];
          if (v == null) return;
          if (typeof v === 'object') v = JSON.stringify(v).slice(0, 200);
          h += '<tr><td style="font-weight:600;color:var(--t1)">' + escHtml(k) + '</td><td style="color:var(--t2);font-size:12px">' + escHtml(String(v)) + '</td></tr>';
          emitted++;
        });
        h += '</tbody></table></div>';
      } else {
        Object.keys(categories).forEach(function (cat) {
          var items = categories[cat];
          h += '<div style="margin-bottom:24px">';
          h += '<div class="lgse-section-header"><span class="lgse-section-title">' + escHtml(cat) + '</span><span class="lgse-badge lgse-badge-muted">' + items.length + ' checks</span></div>';
          h += '<div class="lgse-table-wrap"><table class="lgse-table">';
          h += '<thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>';
          items.forEach(function (c) {
            var badge = c.status === 'error' || c.status === 'fail' ? 'lgse-badge-danger'
                      : c.status === 'warning' ? 'lgse-badge-warning'
                      : 'lgse-badge-success';
            h += '<tr>';
            h += '<td style="font-weight:600;color:var(--t1)">' + escHtml(c.check) + '</td>';
            h += '<td><span class="lgse-badge ' + badge + '">' + escHtml(c.status) + '</span></td>';
            h += '<td style="color:var(--t2);font-size:12px">' + escHtml(c.details) + '</td>';
            h += '</tr>';
          });
          h += '</tbody></table></div></div>';
        });
      }

      h += '</div>';
      content.innerHTML = h;

      var gaugeEl = document.getElementById('audit-detail-gauge');
      if (gaugeEl && typeof window.renderScoreGauge === 'function') {
        window.renderScoreGauge(gaugeEl, score, 96);
      }
    } catch (e) {
      content.innerHTML = '<div class="lgse-empty-state"><div class="icon">⚠</div><h3>Could not load audit</h3><p>' + escHtml(e && e.message ? e.message : 'Unknown error') + '</p><button class="lgse-btn lgse-btn-secondary" onclick="_seoSwitchTab(\'audits\')">← Back to audits</button></div>';
    }
  };

  // ── FIX 2 — Integrations: KPI summary (was raw JSON) ───────────────────
  // We're consolidating Integrations away (Fix 3 maps it to Reports), but
  // keep _seoIntegrations functional in case anything still calls it.
  window._seoIntegrations = async function (el) {
    try {
      var [agentResp, auditsResp] = await Promise.all([
        _seoApi('GET', '/agent-status').catch(function () { return null; }),
        _seoApi('GET', '/audits').catch(function () { return null; }),
      ]);
      var goalsResp = await _seoApi('GET', '/goals').catch(function () { return null; });

      var activeGoals = 0;
      if (goalsResp && Array.isArray(goalsResp)) activeGoals = goalsResp.filter(function (g) { return g.status === 'active'; }).length;
      else if (goalsResp && Array.isArray(goalsResp.goals)) activeGoals = goalsResp.goals.filter(function (g) { return g.status === 'active'; }).length;

      var auditsArr = (auditsResp && (auditsResp.audits || auditsResp.data || auditsResp)) || [];
      if (!Array.isArray(auditsArr)) auditsArr = [];
      var recentAudits = auditsArr.length;

      var lastActivity = '—';
      if (auditsArr[0] && auditsArr[0].created_at) {
        lastActivity = new Date(auditsArr[0].created_at).toLocaleDateString();
      }

      var connected = !!(agentResp && (agentResp.connected || agentResp.success));
      var statusBadge = connected
        ? '<span class="lgse-badge lgse-badge-success">● ACTIVE</span>'
        : '<span class="lgse-badge lgse-badge-muted">○ IDLE</span>';

      var h = '<div class="lgse-animate">';
      h += pageHeader('Integrations', 'External services and AI agents connected to your SEO workspace.');
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:20px">';
      h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">';
      h += '<div style="display:flex;align-items:center;gap:12px"><div style="font-size:28px">🤖</div><div><div style="font-size:14px;font-weight:600;color:var(--t1)">AI SEO Agent</div><div style="font-size:11px;color:var(--t3)">Autonomous SEO agent · powered by your AI platform</div></div></div>';
      h += statusBadge;
      h += '</div>';
      h += '<div class="lgse-kpi-grid">';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Active Goals</div><div class="lgse-kpi-value">' + activeGoals + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Audits Run</div><div class="lgse-kpi-value">' + recentAudits + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Last Activity</div><div class="lgse-kpi-value" style="font-size:14px">' + escHtml(lastActivity) + '</div></div>';
      h += '</div>';
      h += '</div></div>';
      el.innerHTML = h;
    } catch (e) {
      el.innerHTML = '<div class="lgse-empty-state"><div class="icon">⚠</div><h3>Integrations unavailable</h3></div>';
    }
  };

  // ── FIX 4 — Overview KPIs: real counts (were zeros) ────────────────────
  // Override _seoOverview so each KPI hits its own dedicated endpoint instead
  // of trying to derive everything from /knowledge.
  window._seoOverview = async function (el) {
    try {
      // Render the shell first so KPIs hydrate progressively.
      el.innerHTML =
        '<div class="lgse-animate">' +
        pageHeader('Overview', 'SEO health snapshot — score, top issues, quick wins, and tracked keywords.') +
        '<div style="display:flex;align-items:flex-start;gap:32px;margin-bottom:32px;flex-wrap:wrap">' +
          '<div id="lgse-overview-gauge" class="lgse-gauge-wrap" style="width:140px;height:140px;flex-shrink:0">Loading…</div>' +
          '<div style="flex:1;min-width:280px"><div class="lgse-kpi-grid" id="lgse-overview-kpis">' +
            '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Pages indexed</div><div class="lgse-kpi-value" data-kpi="pages">—</div></div>' +
            '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Keywords tracked</div><div class="lgse-kpi-value" data-kpi="keywords">—</div></div>' +
            '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Quick wins</div><div class="lgse-kpi-value" data-kpi="wins">—</div></div>' +
            '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Internal links</div><div class="lgse-kpi-value" data-kpi="links">—</div></div>' +
          '</div></div>' +
        '</div>' +
        '<div id="lgse-overview-summary"></div>' +
        '<div class="lgse-section-header"><span class="lgse-section-title">Active Issues</span></div>' +
        '<div class="lgse-issues-strip" id="lgse-overview-issues"><span style="color:var(--t3);font-size:12px">Loading…</span></div>' +
        '<div class="lgse-section-header"><span class="lgse-section-title">Quick Wins</span><span class="lgse-badge lgse-badge-info">Top 3</span></div>' +
        '<div id="lgse-overview-wins"></div>' +
        '<div id="lgse-overview-keywords"></div>' +
        '</div>';

      function setKpi(name, value) {
        var node = el.querySelector('[data-kpi="' + name + '"]');
        if (node) node.textContent = value;
      }

      // Parallel data fetches against dedicated endpoints. Each fails soft.
      var promises = {
        knowledge:  _seoApi('GET', '/knowledge').catch(function () { return null; }),
        audits:     _seoApi('GET', '/audits?limit=1').catch(function () { return null; }),
        keywords:   _seoApi('GET', '/keywords').catch(function () { return null; }),
        wins:       _seoApi('GET', '/wins?limit=10').catch(function () { return null; }),
        indexed:    _seoApi('GET', '/indexed-content?limit=1').catch(function () { return null; }),
      };

      // Helper to count items in a varied response shape.
      function countItems(resp, listKey) {
        if (!resp) return 0;
        if (Array.isArray(resp)) return resp.length;
        if (resp.total != null) return parseInt(resp.total, 10) || 0;
        if (resp.count != null) return parseInt(resp.count, 10) || 0;
        if (listKey && Array.isArray(resp[listKey])) return resp[listKey].length;
        if (Array.isArray(resp.data)) return resp.data.length;
        if (Array.isArray(resp.items)) return resp.items.length;
        return 0;
      }

      // Resolve in order — gauge + KPIs first, then issues / wins / keywords.
      var auditsResp = await promises.audits;
      var auditsArr = auditsResp && (auditsResp.audits || auditsResp.data || auditsResp);
      if (!Array.isArray(auditsArr)) auditsArr = [];
      var latestScore = 0;
      if (auditsArr[0] && auditsArr[0].score != null) latestScore = parseInt(auditsArr[0].score, 10) || 0;

      // If no audit yet, fall back to knowledge.health_score.
      var knowledge = await promises.knowledge;
      if (!latestScore && knowledge && knowledge.health_score != null) {
        latestScore = parseInt(knowledge.health_score, 10) || 0;
      }
      var gaugeEl = document.getElementById('lgse-overview-gauge');
      if (gaugeEl && typeof window.renderScoreGauge === 'function') window.renderScoreGauge(gaugeEl, latestScore, 140);

      // KPIs — each hits its dedicated endpoint.
      var indexedResp = await promises.indexed;
      var pagesCount = countItems(indexedResp, 'items');
      // Some indexed-content endpoints return rows[] without a total — try a fuller fetch as fallback.
      if (pagesCount === 0) {
        try {
          var allIndexed = await _seoApi('GET', '/indexed-content');
          pagesCount = countItems(allIndexed, 'items');
        } catch (e) { /* keep 0 */ }
      }
      setKpi('pages', pagesCount.toLocaleString());

      var kwResp = await promises.keywords;
      setKpi('keywords', countItems(kwResp, 'keywords').toLocaleString());

      var winsResp = await promises.wins;
      var winsCount = 0;
      var winsArr = [];
      if (winsResp && Array.isArray(winsResp.quick_wins)) { winsArr = winsResp.quick_wins; winsCount = winsArr.length; }
      else if (Array.isArray(winsResp)) { winsArr = winsResp; winsCount = winsResp.length; }
      else if (winsResp && Array.isArray(winsResp.wins)) { winsArr = winsResp.wins; winsCount = winsArr.length; }
      setKpi('wins', winsCount);

      var linksCount = (knowledge && knowledge.link_health && knowledge.link_health.internal_link_count) || 0;
      setKpi('links', linksCount.toLocaleString());

      // AI summary callout.
      var summaryWrap = el.querySelector('#lgse-overview-summary');
      if (summaryWrap && knowledge && knowledge.summary) {
        summaryWrap.innerHTML = '<div style="background:var(--s1);border:1px solid var(--bd);border-left:3px solid var(--p);border-radius:8px;padding:14px 18px;margin-bottom:24px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;font-weight:600">AI Summary</div><div style="font-size:13px;color:var(--t1);line-height:1.55">' + escHtml(knowledge.summary) + '</div></div>';
      }

      // Issues strip.
      var issuesWrap = el.querySelector('#lgse-overview-issues');
      if (issuesWrap) {
        var topIssues = (knowledge && Array.isArray(knowledge.top_issues)) ? knowledge.top_issues : [];
        var contentH = (knowledge && knowledge.content_health) || {};
        var linkH = (knowledge && knowledge.link_health) || {};
        var topicA = (knowledge && knowledge.topic_authority) || {};
        var anyIssue = topIssues.length > 0 || (contentH.missing_meta_count || 0) > 0 || (contentH.below_50_count || 0) > 0 || (linkH.orphan_count || 0) > 0;

        if (!anyIssue) {
          issuesWrap.innerHTML = '<span style="color:var(--t3);font-size:12px">No active issues — site looks healthy.</span>';
        } else {
          var ih = '';
          topIssues.slice(0, 4).forEach(function (i) {
            var col = i.type === 'critical' || i.type === 'errors' ? '#F87171' : '#F59E0B';
            ih += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'audits\')"><span class="dot" style="background:' + col + '"></span>' + escHtml(i.count + ' ' + i.type) + '</span>';
          });
          if ((contentH.missing_meta_count || 0) > 0) {
            ih += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'pages\')"><span class="dot" style="background:#F87171"></span>' + contentH.missing_meta_count + ' missing meta</span>';
          }
          if ((contentH.below_50_count || 0) > 0) {
            ih += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'pages\')"><span class="dot" style="background:#F59E0B"></span>' + contentH.below_50_count + ' pages &lt; 50</span>';
          }
          if ((linkH.orphan_count || 0) > 0) {
            ih += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'links\')"><span class="dot" style="background:#F59E0B"></span>' + linkH.orphan_count + ' orphan pages</span>';
          }
          if (topicA.weakest_topic && topicA.weakest_topic.topic) {
            ih += '<span class="lgse-issue-chip" onclick="_seoSwitchTab(\'topics\')"><span class="dot" style="background:#3B8BF5"></span>weak topic: ' + escHtml(topicA.weakest_topic.topic) + '</span>';
          }
          issuesWrap.innerHTML = ih;
        }
      }

      // Quick wins (top 3).
      var winsWrap = el.querySelector('#lgse-overview-wins');
      if (winsWrap) {
        if (winsArr.length === 0) {
          winsWrap.innerHTML = '<div class="lgse-empty-state"><div class="icon">✓</div><h3>No quick wins right now</h3><p>Run an audit or sync GSC to surface high-impact opportunities.</p><button class="lgse-btn lgse-btn-primary" onclick="_seoSwitchTab(\'audits\')">Go to Audit</button></div>';
        } else {
          var wh = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin-bottom:24px">';
          winsArr.slice(0, 3).forEach(function (w) {
            var label = w.issue_label || w.issue || w.gap || w.description || w.title || 'Optimization opportunity';
            var fix = w.fix || w.recommendation || w.action || '';
            var imp = parseInt(w.impact || w.priority || 0, 10);
            var bk = imp >= 18 ? 'lgse-badge-danger' : imp >= 10 ? 'lgse-badge-warning' : 'lgse-badge-success';
            var bl = imp >= 18 ? 'HIGH' : imp >= 10 ? 'MED' : 'LOW';
            wh += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:14px">';
            wh += '<div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:8px">';
            wh += '<div style="font-size:13px;color:var(--t1);font-weight:600;line-height:1.4">' + escHtml(label) + '</div>';
            wh += '<span class="lgse-badge ' + bk + '">' + bl + '</span></div>';
            if (fix) wh += '<div style="font-size:12px;color:var(--t2);line-height:1.5">' + escHtml(fix) + '</div>';
            wh += '</div>';
          });
          wh += '</div>';
          winsWrap.innerHTML = wh;
        }
      }

      // Tracked keywords mini-table.
      var kwWrap = el.querySelector('#lgse-overview-keywords');
      var keywordRanks = (knowledge && Array.isArray(knowledge.keyword_rankings)) ? knowledge.keyword_rankings : [];
      if (kwWrap && keywordRanks.length > 0) {
        var kh = '<div class="lgse-section-header"><span class="lgse-section-title">Tracked Keywords</span><span class="lgse-section-actions"><button class="lgse-btn lgse-btn-ghost" onclick="_seoSwitchTab(\'workspace\')">View all →</button></span></div>';
        kh += '<div class="lgse-table-wrap" style="margin-bottom:24px"><table class="lgse-table"><thead><tr><th>Keyword</th><th class="num">Position</th><th class="num">Δ</th></tr></thead><tbody>';
        keywordRanks.slice(0, 5).forEach(function (kw) {
          var pos = parseInt(kw.pos || kw.position || 0, 10);
          var posBadge = pos === 0 ? 'lgse-badge-muted' : pos <= 3 ? 'lgse-badge-success' : pos <= 10 ? 'lgse-badge-info' : pos <= 30 ? 'lgse-badge-warning' : 'lgse-badge-muted';
          kh += '<tr>';
          kh += '<td>' + escHtml(kw.kw || kw.keyword || '—') + '</td>';
          kh += '<td class="num">' + (pos > 0 ? '<span class="lgse-badge ' + posBadge + '">' + pos + '</span>' : '<span style="color:var(--t3)">—</span>') + '</td>';
          kh += '<td class="num">' + (typeof window.renderPosChange === 'function' ? window.renderPosChange(kw.change || 0) : '—') + '</td>';
          kh += '</tr>';
        });
        kh += '</tbody></table></div>';
        kwWrap.innerHTML = kh;
      }
    } catch (e) {
      el.innerHTML = '<div class="lgse-empty-state"><div class="icon">📊</div><h3>Could not load overview</h3><p>' + escHtml(e && e.message ? e.message : 'Try refreshing the page.') + '</p></div>';
    }
  };
})();

// ═══════════════════════════════════════════════════════════════════════════
// SEO ENGINE — COMPLETE UI REBUILD (LB-SEO-FINAL, 2026-04-28)
// Production-grade. Self-contained module that takes over the SEO engine.
// Wraps existing API endpoints — no backend changes.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  // ── Design system CSS (injected once) ───────────────────────────────────
  var LGSE_CSS = ":root{--lgse-bg0:#0d0f14;--lgse-bg1:#13161e;--lgse-bg2:#1a1d27;--lgse-bg3:#222635;--lgse-border:#2a2f42;--lgse-border2:#343a52;--lgse-t1:#f0f2ff;--lgse-t2:#8b90a7;--lgse-t3:#555a72;--lgse-purple:#6C5CE7;--lgse-teal:#00E5A8;--lgse-blue:#3B82F6;--lgse-amber:#F59E0B;--lgse-red:#EF4444;--lgse-mono:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace}\n"
    + ".lgse-shell{background:var(--lgse-bg1);border-radius:16px;overflow:hidden;border:1px solid var(--lgse-border);min-height:400px}\n"
    + ".lgse-topbar{background:var(--lgse-bg0);border-bottom:1px solid var(--lgse-border);padding:0 20px;display:flex;align-items:center;overflow-x:auto;scrollbar-width:none}\n"
    + ".lgse-topbar::-webkit-scrollbar{display:none}\n"
    + ".lgse-nav-tab{padding:14px 15px;font-size:11.5px;font-weight:500;color:var(--lgse-t3);cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;display:flex;align-items:center;gap:5px;transition:color .15s;user-select:none}\n"
    + ".lgse-nav-tab:hover{color:var(--lgse-t2)}\n"
    + ".lgse-nav-tab.active{color:var(--lgse-purple);border-bottom-color:var(--lgse-purple)}\n"
    + ".lgse-pane{padding:20px;animation:lgse-in .18s ease-out}\n"
    + "@keyframes lgse-in{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}\n"
    + ".lgse-gauge-wrap{position:relative}\n"
    + ".lgse-gauge-wrap svg{transform:rotate(-90deg)}\n"
    + ".lgse-gauge-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none}\n"
    + ".lgse-gauge-num{font-family:var(--lgse-mono);font-weight:700;line-height:1}\n"
    + ".lgse-gauge-label{font-size:9px;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.1em;margin-top:2px}\n"
    + ".lgse-gauge-ring{fill:none;stroke-linecap:round}\n"
    + ".lgse-gauge-track{fill:none;stroke:#1e2235}\n"
    + ".lgse-score{display:inline-flex;align-items:center;font-family:var(--lgse-mono);font-size:11px;font-weight:700;padding:2px 8px;border-radius:5px}\n"
    + ".lgse-s90{background:rgba(0,229,168,.12);color:#00c49a}\n"
    + ".lgse-s70{background:rgba(59,130,246,.12);color:#3b82f6}\n"
    + ".lgse-s50{background:rgba(245,158,11,.12);color:#d97706}\n"
    + ".lgse-slow{background:rgba(239,68,68,.12);color:#ef4444}\n"
    + ".lgse-badge{display:inline-flex;align-items:center;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px}\n"
    + ".lgse-b-teal{background:rgba(0,229,168,.1);color:#00c49a}\n"
    + ".lgse-b-blue{background:rgba(59,130,246,.1);color:#3b82f6}\n"
    + ".lgse-b-amber{background:rgba(245,158,11,.1);color:#d97706}\n"
    + ".lgse-b-red{background:rgba(239,68,68,.1);color:#ef4444}\n"
    + ".lgse-b-purple{background:rgba(108,92,231,.1);color:#6C5CE7}\n"
    + ".lgse-b-muted{background:rgba(148,163,184,.1);color:#94a3b8}\n"
    + ".lgse-kpi-grid{display:grid;gap:10px;margin-bottom:16px}\n"
    + ".lgse-kpi-card{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:14px 16px}\n"
    + ".lgse-kpi-label{font-size:9.5px;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}\n"
    + ".lgse-kpi-val{font-family:var(--lgse-mono);font-size:28px;font-weight:700;color:var(--lgse-t1);line-height:1}\n"
    + ".lgse-kpi-change{font-size:10.5px;margin-top:4px;font-family:var(--lgse-mono)}\n"
    + ".lgse-up{color:var(--lgse-teal)}\n"
    + ".lgse-dn{color:var(--lgse-red)}\n"
    + ".lgse-flat{color:var(--lgse-t3)}\n"
    + ".lgse-table{width:100%;border-collapse:collapse}\n"
    + ".lgse-table thead th{background:var(--lgse-bg0);color:var(--lgse-t3);font-size:9.5px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;padding:9px 12px;text-align:left;border-bottom:1px solid var(--lgse-border);position:sticky;top:0;z-index:5}\n"
    + ".lgse-table thead th.r{text-align:right}\n"
    + ".lgse-table tbody tr{border-bottom:1px solid rgba(42,47,66,.6);transition:background .1s;cursor:pointer}\n"
    + ".lgse-table tbody tr:hover{background:var(--lgse-bg2)}\n"
    + ".lgse-table tbody td{padding:11px 12px;font-size:11.5px;color:var(--lgse-t2);vertical-align:middle}\n"
    + ".lgse-table tbody td.mono{font-family:var(--lgse-mono);text-align:right;font-size:11px}\n"
    + ".lgse-url{font-family:var(--lgse-mono);font-size:10.5px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-purple)}\n"
    + ".lgse-issue-chip{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:8px;font-size:11px;color:var(--lgse-t2)}\n"
    + ".lgse-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}\n"
    + ".lgse-ai-bar{display:flex;align-items:flex-start;gap:10px;background:linear-gradient(90deg,rgba(108,92,231,.1) 0%,transparent 100%);border-left:2px solid var(--lgse-purple);border-radius:0 10px 10px 0;padding:12px 14px;margin-bottom:16px}\n"
    + ".lgse-ai-name{font-size:9px;font-weight:700;color:var(--lgse-purple);text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px}\n"
    + ".lgse-ai-text{font-size:11.5px;color:var(--lgse-t2);line-height:1.65}\n"
    + ".lgse-win-tile{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px;cursor:pointer;transition:all .15s}\n"
    + ".lgse-win-tile:hover{border-color:var(--lgse-border2);background:var(--lgse-bg3);transform:translateY(-1px)}\n"
    + ".lgse-win-count{font-family:var(--lgse-mono);font-size:28px;font-weight:700;line-height:1;margin-bottom:4px}\n"
    + ".lgse-win-desc{font-size:10.5px;color:var(--lgse-t2);margin-bottom:8px;line-height:1.4}\n"
    + ".lgse-win-cta{font-size:10px;font-weight:600}\n"
    + ".lgse-btn-primary{background:var(--lgse-purple);color:white;border:none;border-radius:7px;padding:8px 16px;font-size:11.5px;font-weight:600;cursor:pointer;transition:opacity .15s}\n"
    + ".lgse-btn-primary:hover{opacity:.9}\n"
    + ".lgse-btn-secondary{background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border2);border-radius:7px;padding:7px 14px;font-size:11px;cursor:pointer;transition:all .15s}\n"
    + ".lgse-btn-secondary:hover{color:var(--lgse-t1);border-color:var(--lgse-t3)}\n"
    + ".lgse-section-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}\n"
    + ".lgse-section-title{font-size:10px;font-weight:700;color:var(--lgse-t2);text-transform:uppercase;letter-spacing:.1em}\n"
    + ".lgse-page-title{font-size:17px;font-weight:700;color:var(--lgse-t1);margin-bottom:3px}\n"
    + ".lgse-page-desc{font-size:11.5px;color:var(--lgse-t3);margin-bottom:18px}\n"
    + ".lgse-pos-up{font-family:var(--lgse-mono);font-size:10px;font-weight:700;color:var(--lgse-teal)}\n"
    + ".lgse-pos-dn{font-family:var(--lgse-mono);font-size:10px;font-weight:700;color:var(--lgse-red)}\n"
    + ".lgse-filter-row{display:flex;align-items:center;gap:6px;margin-bottom:12px;flex-wrap:wrap}\n"
    + ".lgse-filter-pill{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:20px;padding:4px 12px;font-size:10.5px;color:var(--lgse-t3);cursor:pointer;transition:all .12s;user-select:none}\n"
    + ".lgse-filter-pill.active,.lgse-filter-pill:hover{border-color:var(--lgse-purple);color:var(--lgse-purple);background:rgba(108,92,231,.08)}\n"
    + ".lgse-subtabs{display:flex;gap:0;border-bottom:1px solid var(--lgse-border);margin-bottom:14px}\n"
    + ".lgse-subtab{padding:7px 14px;font-size:11px;color:var(--lgse-t3);cursor:pointer;border-bottom:2px solid transparent;transition:color .12s;white-space:nowrap}\n"
    + ".lgse-subtab:hover{color:var(--lgse-t2)}\n"
    + ".lgse-subtab.active{color:var(--lgse-purple);border-bottom-color:var(--lgse-purple)}\n"
    + ".lgse-empty{text-align:center;padding:40px 20px;color:var(--lgse-t3)}\n"
    + ".lgse-empty-icon{font-size:28px;margin-bottom:10px;opacity:.4}\n"
    + ".lgse-empty h3{font-size:13px;font-weight:600;color:var(--lgse-t2);margin-bottom:5px}\n"
    + ".lgse-empty p{font-size:11px;line-height:1.5;max-width:240px;margin:0 auto 14px}\n"
    + ".lgse-sparkline{display:inline-block;vertical-align:middle}\n"
    + ".lgse-vol-h{background:rgba(239,68,68,.1);color:#ef4444}\n"
    + ".lgse-vol-m{background:rgba(245,158,11,.1);color:#d97706}\n"
    + ".lgse-vol-l{background:rgba(148,163,184,.1);color:#94a3b8}\n"
    + ".lgse-cat-item{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:9px 12px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:all .12s}\n"
    + ".lgse-cat-item:hover{border-color:var(--lgse-border2)}\n"
    + ".lgse-cat-icon{width:8px;height:8px;border-radius:2px;flex-shrink:0}\n"
    + ".lgse-stack-bar{display:flex;width:100%;height:8px;border-radius:4px;overflow:hidden;background:var(--lgse-bg3)}\n"
    + ".lgse-trend{width:100%;height:100%}\n"
    + "@media(prefers-reduced-motion:reduce){.lgse-pane{animation:none}.lgse-win-tile:hover{transform:none}}";

  function lgseInjectStyles() {
    if (document.getElementById('lgse-styles')) return;
    var s = document.createElement('style');
    s.id = 'lgse-styles';
    s.textContent = LGSE_CSS;
    document.head.appendChild(s);
  }

  // ── Helpers ─────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function scoreClass(n) { n = parseInt(n, 10) || 0; return n >= 90 ? 'lgse-s90' : n >= 70 ? 'lgse-s70' : n >= 50 ? 'lgse-s50' : 'lgse-slow'; }
  function scoreColor(n) { n = parseInt(n, 10) || 0; return n >= 90 ? '#00c49a' : n >= 70 ? '#3b82f6' : n >= 50 ? '#d97706' : '#ef4444'; }
  function scorePill(n) { return '<span class="lgse-score ' + scoreClass(n) + '">' + (parseInt(n, 10) || 0) + '</span>'; }
  function badge(t, kind) { return '<span class="lgse-badge lgse-b-' + kind + '">' + esc(t) + '</span>'; }

  function gauge(score, size, showLabel) {
    size = size || 120;
    score = Math.max(0, Math.min(100, parseInt(score, 10) || 0));
    var r = (size / 2) - 9;
    var circ = 2 * Math.PI * r;
    var color = scoreColor(score);
    var fs = Math.round(size * 0.23);
    return '<div class="lgse-gauge-wrap" style="width:' + size + 'px;height:' + size + 'px">'
      + '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">'
      + '<circle class="lgse-gauge-track" cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" stroke-width="9"/>'
      + '<circle class="lgse-gauge-ring" cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" stroke="' + color + '" stroke-width="9" stroke-dasharray="' + circ + '" stroke-dashoffset="' + circ + '" data-circ="' + circ + '" data-offset="' + (circ - (score / 100) * circ) + '"/>'
      + '</svg>'
      + '<div class="lgse-gauge-center">'
      + '<div class="lgse-gauge-num" style="font-size:' + fs + 'px;color:' + color + '" data-target="' + score + '">0</div>'
      + (showLabel ? '<div class="lgse-gauge-label">score</div>' : '')
      + '</div></div>';
  }

  function animateGauge(container, delay) {
    if (!container) return;
    delay = delay || 0;
    var ring = container.querySelector('.lgse-gauge-ring');
    var num = container.querySelector('.lgse-gauge-num');
    if (!ring || !num) return;
    var target = parseInt(num.getAttribute('data-target'), 10) || 0;
    var offset = parseFloat(ring.getAttribute('data-offset'));
    var circ = parseFloat(ring.getAttribute('data-circ'));
    ring.style.strokeDasharray = circ;
    ring.style.strokeDashoffset = circ;
    setTimeout(function () {
      ring.style.transition = 'stroke-dashoffset 1.1s cubic-bezier(.4,0,.2,1)';
      ring.style.strokeDashoffset = offset;
      countUp(num, target, 900);
    }, delay);
  }

  function countUp(el, target, dur) {
    if (!el) return;
    var start = performance.now();
    function step(now) {
      var t = Math.min((now - start) / dur, 1);
      var ease = 1 - Math.pow(1 - t, 3);
      el.textContent = Math.round(ease * target);
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function sparkline(vals, w, h) {
    w = w || 60; h = h || 20;
    if (!vals || vals.length < 2) return '<svg width="' + w + '" height="' + h + '"></svg>';
    var min = Math.min.apply(null, vals);
    var max = Math.max.apply(null, vals);
    var range = max - min || 1;
    var pts = vals.map(function (v, i) {
      var x = (i / (vals.length - 1)) * w;
      var y = h - ((v - min) / range) * (h - 2) - 1;
      return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');
    var color = vals[vals.length - 1] < vals[0] ? '#00E5A8' : '#EF4444';
    return '<svg class="lgse-sparkline" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '"><polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  function trendChart(vals, w, h) {
    w = w || 720; h = h || 110;
    if (!vals || vals.length < 2) return '<svg viewBox="0 0 ' + w + ' ' + h + '"></svg>';
    var min = Math.max(0, Math.min.apply(null, vals) - 10);
    var max = Math.min(100, Math.max.apply(null, vals) + 10);
    var range = max - min || 1;
    var pad = 8;
    var pts = vals.map(function (v, i) {
      var x = pad + (i / (vals.length - 1)) * (w - pad * 2);
      var y = h - pad - ((v - min) / range) * (h - pad * 2);
      return [x, y];
    });
    var line = pts.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
    var area = 'M' + pts[0][0].toFixed(1) + ',' + h + ' L' + line.split(' ').join(' L') + ' L' + pts[pts.length - 1][0].toFixed(1) + ',' + h + ' Z';
    return '<svg class="lgse-trend" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">'
      + '<path d="' + area + '" fill="rgba(59,130,246,.07)"/>'
      + '<polyline points="' + line + '" fill="none" stroke="#3B82F6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
      + pts.map(function (p) { return '<circle cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="3" fill="#3B82F6"/>'; }).join('')
      + '</svg>';
  }

  function posChange(change) {
    var n = parseInt(change, 10) || 0;
    if (n > 0) return '<span class="lgse-pos-up">↑' + n + '</span>';
    if (n < 0) return '<span class="lgse-pos-dn">↓' + Math.abs(n) + '</span>';
    return '<span style="color:var(--lgse-t3);font-family:var(--lgse-mono);font-size:10px">—</span>';
  }

  function emptyState(icon, title, descText, btnText, btnFn) {
    return '<div class="lgse-empty"><div class="lgse-empty-icon">' + esc(icon) + '</div><h3>' + esc(title) + '</h3><p>' + esc(descText) + '</p>'
      + (btnText ? '<button class="lgse-btn-primary" onclick="' + btnFn + '">' + esc(btnText) + '</button>' : '') + '</div>';
  }

  function pageTitle(title, descText) {
    return '<div class="lgse-page-title">' + esc(title) + '</div><div class="lgse-page-desc">' + esc(descText) + '</div>';
  }

  // Bridge to existing API helper. _seoApi handles auth + workspace scope.
  function api(method, path, body) {
    if (typeof window._seoApi === 'function') return window._seoApi(method, path, body);
    // Fallback if _seoApi missing.
    var token = localStorage.getItem('lu_token') || '';
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', Accept: 'application/json' }, cache: 'no-store' };
    if (body) opts.body = JSON.stringify(body);
    return fetch(window.location.origin + '/api/seo' + path, opts).then(function (r) {
      // 2026-05-12: surface backend errors (402 NO_CREDITS, 403 PLAN_GATED,
      // 422 validation, 5xx) instead of silently resolving with the error JSON.
      return r.json().then(function (d) {
        if (!r.ok || (d && d.success === false)) {
          var err = new Error((d && (d.error || d.message)) || ('HTTP ' + r.status));
          err.code   = (d && d.code) || null;
          err.status = r.status;
          err.body   = d;
          throw err;
        }
        return d;
      });
    });
  }
  function countItems(resp, key) {
    if (!resp) return 0;
    if (Array.isArray(resp)) return resp.length;
    if (resp.total != null) return parseInt(resp.total, 10) || 0;
    if (resp.count != null) return parseInt(resp.count, 10) || 0;
    if (key && Array.isArray(resp[key])) return resp[key].length;
    if (Array.isArray(resp.data)) return resp.data.length;
    if (Array.isArray(resp.items)) return resp.items.length;
    return 0;
  }

  // ── Tab definitions ────────────────────────────────────────────────────
  var TABS = [
    { id: 'overview',    label: 'Overview' },
    { id: 'audit',       label: 'Audit' },
    { id: 'keywords',    label: 'Keywords' },
    { id: 'pages',       label: 'Pages' },
    { id: 'links',       label: 'Links' },
    { id: 'topics',      label: 'Topics' },
    { id: 'competitors', label: 'Competitors' },
    { id: 'insights',    label: 'Insights' },
    { id: 'reports',     label: 'Reports' },
    { id: 'write',       label: 'Write' },
    { id: 'pipeline',    label: 'Pipeline' },
  ];

  // Legacy ID aliases — preserve deep links.
  var ALIAS = {
    audits: 'audit', workspace: 'keywords', dashboard: 'overview',
    content: 'overview', goals: 'overview', wins: 'overview',
    score: 'pages', images: 'pages', ctr: 'pages',
    outbound: 'links', redirects: 'links',
    gsc: 'insights', settings: 'reports', integrations: 'reports',
  };

  function buildShell(container) {
    lgseInjectStyles();
    container.innerHTML = '<div class="lgse-shell">'
      + '<div class="lgse-topbar" id="lgse-topbar"></div>'
      + '<div class="lgse-pane" id="lgse-content"></div>'
      + '</div>';
    var bar = document.getElementById('lgse-topbar');
    // 2026-05-13 — Write tab is embed-only. Direct SPA users have the
    // Write engine section in their main nav; the in-iframe shortcut is
    // for WP-bundle users who don't see the sidebar.
    var _writeEmbedOnly = (window._LGSC_EMBED && window._LGSC_EMBED.api_key)
      || new URLSearchParams(window.location.search).has('lgsc_key');
    TABS.forEach(function (t) {
      if (t.id === 'write' && !_writeEmbedOnly) { return; }
      var d = document.createElement('div');
      d.className = 'lgse-nav-tab';
      d.setAttribute('data-tab-id', t.id);
      d.textContent = t.label;
      d.onclick = function () { switchTab(t.id); };
      bar.appendChild(d);
    });
    switchTab('overview');

    // 2026-05-13 — Gate the SEO Assistant FAB to embed mode only.
    // Direct SPA users have Sarah + agents for AI assistance; the
    // floating drawer is meant for the WP plugin iframe context where
    // there's no other AI surface. _LGSC_EMBED is set at the top of
    // core.js when ?lgsc_key= + embed=1 are in the URL.
    var _seoIsEmbed = (window._LGSC_EMBED && window._LGSC_EMBED.api_key)
      || new URLSearchParams(window.location.search).has('lgsc_key');
    if (_seoIsEmbed) {
      _lgseInjectAiDrawer();
      var fab = document.getElementById('lgse-ai-fab');
      if (fab) { fab.style.display = 'flex'; }
    }
  }

  function _lgseInjectAiDrawer() {
    if (document.getElementById('lgse-ai-fab')) { return; }
    var qs = [
      'What are my biggest SEO issues?',
      'Which pages need work?',
      'Summarize my latest audit.',
      'How can I improve my score?',
    ];
    var chips = qs.map(function (q) {
      var safe = q.replace(/'/g, "\\'");
      return '<button data-q="' + q.replace(/"/g, '&quot;') + '" onclick="window._lgseDrawerSuggest(\'' + safe + '\')" '
        + 'style="background:rgba(255,255,255,0.05);color:#9CA3AF;'
        + 'border:1px solid rgba(255,255,255,0.08);border-radius:16px;'
        + 'padding:5px 10px;font-size:11px;cursor:pointer">'
        + q + '</button>';
    }).join('');

    var wrap = document.createElement('div');
    wrap.innerHTML =
      // FAB — bottom-right, offset 88px right of edge so it doesn't
      // overlap the global ai-fab in standalone SPA mode.
      '<button id="lgse-ai-fab" onclick="window._lgseDrawerOpen()"'
        + ' style="position:fixed;bottom:24px;right:88px;z-index:10000;'
        + 'width:52px;height:52px;border-radius:50%;border:none;cursor:pointer;'
        + 'background:linear-gradient(135deg,#7C3AED,#3B82F6);'
        + 'box-shadow:0 4px 20px rgba(124,58,237,0.4);'
        + 'display:none;align-items:center;justify-content:center;'
        + 'font-size:22px;color:#fff;transition:transform 0.2s"'
        + ' onmouseover="this.style.transform=\'scale(1.1)\'"'
        + ' onmouseout="this.style.transform=\'scale(1)\'"'
        + ' title="LevelUp SEO Assistant">&#128172;</button>'
      // Overlay
      + '<div id="lgse-ai-overlay" onclick="window._lgseDrawerClose()"'
        + ' style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,0.5)"></div>'
      // Drawer
      + '<div id="lgse-ai-drawer"'
        + ' style="display:none;position:fixed;top:0;right:0;bottom:0;width:420px;max-width:95vw;'
        + 'z-index:9999;background:#121826;border-left:1px solid rgba(255,255,255,0.08);'
        + 'flex-direction:column;box-shadow:-8px 0 32px rgba(0,0,0,0.4)">'
        // Header
        + '<div style="display:flex;align-items:center;gap:10px;padding:20px 20px 16px;'
        + 'border-bottom:1px solid rgba(255,255,255,0.06)">'
          + '<span style="font-size:20px;color:#A78BFA">&#128172;</span>'
          + '<div style="flex:1">'
            + '<div style="font-size:16px;font-weight:700;color:#fff">LevelUp SEO Assistant</div>'
            + '<div style="font-size:12px;color:#6B7280">Powered by LevelUp Growth</div>'
          + '</div>'
          + '<button onclick="window._lgseDrawerClose()"'
            + ' style="background:none;border:none;color:#6B7280;font-size:18px;cursor:pointer;padding:4px">&times;</button>'
        + '</div>'
        // Thread
        + '<div id="lgse-drawer-thread"'
          + ' style="flex:1;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:12px">'
          + '<div style="background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);'
          + 'border-radius:12px;padding:14px 16px;max-width:90%">'
            + '<div style="font-size:12px;font-weight:600;color:#A78BFA;margin-bottom:6px">LevelUp SEO Assistant</div>'
            + '<div style="font-size:14px;line-height:1.6;color:#E5E7EB">'
              + 'Hi &mdash; I can answer SEO questions about your workspace using your real audit data, '
              + 'indexed pages, and tracked keywords. Ask me anything.'
            + '</div>'
          + '</div>'
        + '</div>'
        // Suggestion chips
        + '<div id="lgse-drawer-suggestions"'
          + ' style="padding:0 20px 12px;display:flex;gap:6px;flex-wrap:wrap">'
          + chips
        + '</div>'
        // Input
        + '<div style="padding:12px 20px 20px;border-top:1px solid rgba(255,255,255,0.06)">'
          + '<div style="display:flex;gap:8px">'
            + '<textarea id="lgse-drawer-input" rows="2" maxlength="2000"'
              + ' placeholder="Ask anything about your SEO\u2026"'
              + ' style="flex:1;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);'
              + 'border-radius:10px;padding:10px 14px;color:#fff;'
              + 'font-size:13px;resize:none;outline:none;font-family:inherit"></textarea>'
            + '<button onclick="window._lgseDrawerSend()"'
              + ' style="background:linear-gradient(135deg,#7C3AED,#3B82F6);color:#fff;'
              + 'border:none;border-radius:10px;padding:10px 16px;'
              + 'font-size:13px;font-weight:600;cursor:pointer;align-self:flex-end">Send</button>'
          + '</div>'
          + '<div style="font-size:11px;color:#4B5563;margin-top:8px;text-align:center">'
            + 'Responses based on your real workspace data'
          + '</div>'
        + '</div>'
      + '</div>';
    document.body.appendChild(wrap);

    setTimeout(function () {
      var inp = document.getElementById('lgse-drawer-input');
      if (inp) {
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (typeof window._lgseDrawerSend === 'function') {
              window._lgseDrawerSend();
            }
          }
        });
      }
    }, 200);
  }

  function switchTab(id) {
    if (ALIAS[id]) id = ALIAS[id];
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-nav-tab'), function (t) {
      t.classList.toggle('active', t.getAttribute('data-tab-id') === id);
    });
    var content = document.getElementById('lgse-content');
    if (!content) return;
    content.innerHTML = '<div style="padding:40px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading…</div>';

    var renderers = {
      overview: renderOverview, audit: renderAudit, keywords: renderKeywords,
      pages: renderPages, links: renderLinks, topics: renderTopics,
      competitors: renderCompetitors, insights: renderInsights, reports: renderReports,
      pipeline: renderPipeline, write: renderWrite,
    };
    (renderers[id] || renderOverview)(content);
  }
  window.lgseSwitchTab = switchTab;

  // ── Tab — AI Assistant (James — SEO-focused chat) ────────────────────
  function renderAssistant(el) {
    el.innerHTML =
      '<div style="display:flex;flex-direction:column;height:100%;padding:0">'
        + '<div style="padding:20px 24px 0">'
          + '<h3 style="font-family:var(--fh,Manrope);font-size:18px;font-weight:700;color:var(--lgse-t1);margin:0 0 4px">SEO AI Assistant</h3>'
          + '<p style="font-size:13px;color:var(--lgse-t3);margin:0 0 16px">'
            + 'SEO-focused assistant powered by James. Answers based on your workspace data.'
          + '</p>'
        + '</div>'
        + '<div id="lgse-chat-thread" style="flex:1;overflow-y:auto;padding:0 24px;display:flex;flex-direction:column;gap:12px;min-height:300px;max-height:60vh">'
          + '<div style="background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);border-radius:12px;padding:14px 16px;max-width:80%">'
            + '<span style="font-size:12px;font-weight:600;color:#A78BFA">James</span>'
            + '<p style="font-size:14px;line-height:1.6;color:var(--lgse-t1);margin:4px 0 0">'
              + 'Hi — I can answer SEO questions about your workspace using your real audit data, indexed pages, and tracked keywords. Ask me something.'
            + '</p>'
          + '</div>'
        + '</div>'
        + '<div style="padding:16px 24px">'
          + '<div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
            + '<button onclick="_lgseAssistantSuggest(this)" data-q="What are my biggest SEO issues right now?" '
              + 'style="background:rgba(255,255,255,0.06);color:var(--lgse-t3);border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:6px 12px;font-size:12px;cursor:pointer">'
              + 'Biggest SEO issues?'
            + '</button>'
            + '<button onclick="_lgseAssistantSuggest(this)" data-q="Which pages should I optimize first?" '
              + 'style="background:rgba(255,255,255,0.06);color:var(--lgse-t3);border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:6px 12px;font-size:12px;cursor:pointer">'
              + 'Pages to optimize first?'
            + '</button>'
            + '<button onclick="_lgseAssistantSuggest(this)" data-q="Summarize my latest audit." '
              + 'style="background:rgba(255,255,255,0.06);color:var(--lgse-t3);border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:6px 12px;font-size:12px;cursor:pointer">'
              + 'Summarize latest audit'
            + '</button>'
          + '</div>'
          + '<div style="display:flex;gap:8px">'
            + '<textarea id="lgse-chat-input" rows="2" '
              + 'style="flex:1;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:10px 14px;color:var(--lgse-t1);font-size:14px;resize:none;outline:none;font-family:inherit" '
              + 'placeholder="Ask anything about your SEO…" maxlength="2000"></textarea>'
            + '<button onclick="_lgseAssistantSend()" '
              + 'style="background:linear-gradient(135deg,#7C3AED,#3B82F6);color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap">'
              + 'Send'
            + '</button>'
          + '</div>'
        + '</div>'
      + '</div>';
    setTimeout(function () {
      var inp = document.getElementById('lgse-chat-input');
      if (inp) {
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (typeof window._lgseAssistantSend === 'function') {
              window._lgseAssistantSend();
            }
          }
        });
        inp.focus();
      }
    }, 100);
  }

  // ── Tab — Website Chatbot status ─────────────────────────────────────
  function renderChatbot(el) {
    el.innerHTML = '<div style="padding:24px;color:var(--lgse-t3)">Loading…</div>';
    api('GET', '/chatbot/status').then(function (d) {
      var status = (d && d.chatbot) ? d.chatbot : null;
      var enabled = status && status.enabled;
      el.innerHTML =
        '<div style="padding:24px">'
          + '<h3 style="font-family:var(--fh,Manrope);font-size:18px;font-weight:700;color:var(--lgse-t1);margin:0 0 4px">Website Chatbot</h3>'
          + '<p style="font-size:13px;color:var(--lgse-t3);margin:0 0 24px">'
            + 'AI chatbot powered by your business knowledge base. Handles visitor questions 24/7.'
          + '</p>'
          + (status
            ? '<div style="background:' + (enabled ? 'rgba(0,229,168,0.08);border:1px solid rgba(0,229,168,0.2)' : 'rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2)') + ';border-radius:12px;padding:20px;margin-bottom:16px">'
                + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">'
                  + '<span style="width:8px;height:8px;border-radius:50%;background:' + (enabled ? '#00E5A8' : '#F59E0B') + ';display:inline-block"></span>'
                  + '<span style="font-size:14px;font-weight:600;color:' + (enabled ? '#00E5A8' : '#F59E0B') + '">Chatbot ' + (enabled ? 'Active' : 'Configured (Inactive)') + '</span>'
                + '</div>'
                + '<p style="font-size:13px;color:var(--lgse-t3);margin:0 0 12px">'
                  + (enabled ? 'Your chatbot is live and ready to handle visitor questions.' : 'Chatbot exists but is not yet active. Enable it from the chatbot settings.')
                + '</p>'
                + '<div style="font-size:11px;color:var(--lgse-t3);font-family:var(--lgse-mono,monospace)">'
                  + 'Theme: ' + (status.theme || 'auto') + ' · Timezone: ' + (status.timezone || 'UTC')
                + '</div>'
              + '</div>'
            : '<div style="background:rgba(255,255,255,0.03);border:1px dashed rgba(255,255,255,0.12);border-radius:12px;padding:32px;text-align:center">'
                + '<div style="font-size:32px;margin-bottom:12px">&#129302;</div>'
                + '<div style="font-size:15px;font-weight:600;color:var(--lgse-t1);margin-bottom:8px">No chatbot configured yet</div>'
                + '<div style="font-size:13px;color:var(--lgse-t3);max-width:320px;margin:0 auto 20px">'
                  + 'Set up your AI chatbot to handle visitor questions automatically.'
                + '</div>'
                + '<a href="/app/#chatbot" target="_parent" '
                  + 'style="background:linear-gradient(135deg,#7C3AED,#3B82F6);color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;display:inline-block">'
                  + 'Set Up Chatbot →'
                + '</a>'
              + '</div>')
        + '</div>';
    }).catch(function () {
      el.innerHTML = '<div style="padding:24px;color:var(--lgse-t3)">Could not load chatbot status.</div>';
    });
  }

  // ── Tab 1 — Overview ───────────────────────────────────────────────────
  function renderOverview(el) {
    el.innerHTML =
      '<div style="display:grid;grid-template-columns:190px 1fr;gap:14px;margin-bottom:14px">'
        + '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:14px;padding:18px;display:flex;flex-direction:column;align-items:center;justify-content:center">'
          + '<div id="lgse-main-gauge">' + gauge(0, 120, true) + '</div>'
          + '<div id="lgse-tier" style="font-size:10px;font-weight:600;padding:3px 10px;border-radius:10px;background:rgba(59,130,246,.12);color:#3b82f6;margin-top:10px">Loading…</div>'
        + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
          + dimCard('tech',  'Technical',     'HTTPS, speed, crawl',           '30%', '#EF4444')
          + dimCard('con',   'Content',       'Quality, structure, meta',      '30%', '#3B82F6')
          + dimCard('links', 'Internal links', 'Graph, equity, anchors',       '20%', '#F59E0B')
          + dimCard('serp',  'SERP & CTR',     'Clicks, positions, CTR',       '20%', '#00E5A8')
        + '</div>'
      + '</div>'

      + '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr)">'
        + kpiCard('pages', 'Pages indexed')
        + kpiCard('kw',    'Keywords tracked')
        + kpiCard('wins',  'Quick wins')
        + kpiCard('orph',  'Orphan pages', 'lgse-dn')
      + '</div>'

      + '<div class="lgse-ai-bar" id="lgse-ai-bar" style="display:none">'
        + '<div style="width:30px;height:30px;border-radius:50%;background:rgba(108,92,231,.12);border:1px solid rgba(108,92,231,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px">·</div>'
        + '<div><div class="lgse-ai-name">James · SEO Agent</div><div class="lgse-ai-text" id="lgse-ai-text"></div></div>'
      + '</div>'

      + '<div class="lgse-section-hdr"><span class="lgse-section-title">Quick wins — click to fix</span><span id="lgse-wins-count" style="font-size:10px;color:var(--lgse-t3)"></span></div>'
      + '<div id="lgse-wins-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px"></div>'

      + '<div class="lgse-section-hdr"><span class="lgse-section-title">Active issues</span><span id="lgse-iss-count"></span></div>'
      + '<div id="lgse-iss-strip" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px"></div>'

      + '<div class="lgse-section-hdr"><span class="lgse-section-title">Score trend</span><span style="font-size:10px;color:var(--lgse-t3)">Last 30 days</span></div>'
      + '<div id="lgse-trend" style="height:110px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:8px;color:var(--lgse-t3);font-size:11px;display:flex;align-items:center;justify-content:center">Loading trend…</div>';

    setTimeout(function () { animateGauge(document.getElementById('lgse-main-gauge'), 80); }, 50);
    loadOverviewData();

    // 2026-05-12: cold-workspace fast-path. If we know the site URL but have
    // no audit history, auto-trigger the first audit so the dashboard fills
    // in without the user having to find the modal. Waits 2s for
    // loadOverviewData / loadSiteList to settle.
    setTimeout(function () {
      var siteUrl = window._lgseActiveSite || window._LGSC_SITE_URL;
      var sites   = window._lgseSites || [];
      if (siteUrl && sites.length === 0 && !window._lgseAutoAuditFired
          && typeof window.lgseDoRunAudit === 'function') {
        window._lgseAutoAuditFired = true;
        if (typeof window.showToast === 'function') {
          window.showToast('Starting first audit for ' + siteUrl + '…', 'info');
        }
        try { console.log('[LGSE] Cold workspace — auto-running first audit: ' + siteUrl); } catch (_) {}
        window.lgseDoRunAudit(siteUrl);
      }
    }, 2000);
  }

  function dimCard(id, label, hint, weight, color) {
    return '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:12px;display:flex;align-items:center;gap:10px">'
      + '<div style="position:relative;width:48px;height:48px;flex-shrink:0">'
        + '<svg width="48" height="48" viewBox="0 0 48 48" style="transform:rotate(-90deg)">'
          + '<circle fill="none" stroke="#1e2235" stroke-width="5" cx="24" cy="24" r="18"/>'
          + '<circle fill="none" stroke="' + color + '" stroke-width="5" stroke-linecap="round" cx="24" cy="24" r="18" stroke-dasharray="113.1" stroke-dashoffset="113.1" id="dim-r-' + id + '"/>'
        + '</svg>'
        + '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-family:var(--lgse-mono);font-size:12px;font-weight:700;color:' + color + '" id="dim-n-' + id + '">0</div>'
      + '</div>'
      + '<div>'
        + '<div style="font-size:11px;font-weight:600;color:var(--lgse-t1);margin-bottom:2px">' + esc(label) + '</div>'
        + '<div style="font-size:9px;color:var(--lgse-t3);margin-bottom:4px">' + esc(hint) + '</div>'
        + '<span style="font-size:9px;font-weight:600;padding:1px 6px;border-radius:4px;background:var(--lgse-bg3);color:var(--lgse-t3);font-family:var(--lgse-mono)">' + weight + '</span>'
      + '</div></div>';
  }

  function kpiCard(id, label, valClass) {
    return '<div class="lgse-kpi-card"><div class="lgse-kpi-label">' + esc(label) + '</div>'
      + '<div class="lgse-kpi-val ' + (valClass || '') + '" id="kpi-' + id + '">—</div></div>';
  }

  function animateDim(id, val) {
    var ring = document.getElementById('dim-r-' + id);
    var num = document.getElementById('dim-n-' + id);
    if (!ring || !num) return;
    var circ = 2 * Math.PI * 18;
    setTimeout(function () {
      ring.style.transition = 'stroke-dashoffset .9s ease-out';
      ring.style.strokeDashoffset = circ - (val / 100) * circ;
      countUp(num, val, 700);
    }, 200);
  }

  function loadOverviewData() {
    Promise.all([
      api('GET', '/audits?limit=7').catch(function () { return null; }),
      api('GET', '/knowledge').catch(function () { return null; }),
      api('GET', '/quick-wins?limit=10').catch(function () { return null; }),
      api('GET', '/indexed-content').catch(function () { return null; }),
      api('GET', '/keywords').catch(function () { return null; }),
      api('GET', '/link-graph/orphans').catch(function () { return null; }),
    ]).then(function (results) {
      var audits = results[0]; var knowledge = results[1]; var wins = results[2];
      var indexed = results[3]; var keywords = results[4]; var orphans = results[5];

      var auditsArr = (audits && (audits.audits || audits.data)) || (Array.isArray(audits) ? audits : []);
      var latest = auditsArr[0] || {};
      var score = parseInt(latest.score || (knowledge && knowledge.health_score) || 0, 10);

      // Main gauge fresh paint.
      var mg = document.getElementById('lgse-main-gauge');
      if (mg) { mg.innerHTML = gauge(score, 120, true); animateGauge(mg, 60); }

      // Tier label.
      var tier = document.getElementById('lgse-tier');
      if (tier) {
        var tierName = score >= 90 ? 'Excellent' : score >= 70 ? 'Good' : score >= 50 ? 'Needs work' : 'Critical';
        tier.textContent = tierName;
        tier.style.color = scoreColor(score);
      }

      // Dimension scores — derive from audit results_json or knowledge.
      var results_json = {};
      try { results_json = typeof latest.results_json === 'string' ? JSON.parse(latest.results_json) : (latest.results_json || {}); } catch (e) {}
      var dims = {
        tech: parseInt((results_json.technical && results_json.technical.score) || results_json.tech_score || score, 10),
        con: parseInt((results_json.content && results_json.content.score) || results_json.content_score || (knowledge && knowledge.content_health && knowledge.content_health.avg_score) || 0, 10),
        links: parseInt((results_json.internal && results_json.internal.score) || results_json.internal_score || 0, 10),
        serp: parseInt((results_json.serp && results_json.serp.score) || results_json.serp_score || score, 10),
      };
      // Fallback: split overall score evenly if no sub-scores.
      Object.keys(dims).forEach(function (k) { if (!dims[k]) dims[k] = score; });
      animateDim('tech', dims.tech);
      animateDim('con', dims.con);
      animateDim('links', dims.links);
      animateDim('serp', dims.serp);

      // KPIs.
      var pCount = countItems(indexed, 'items');
      var kCount = countItems(keywords, 'keywords');
      var winsArr = (wins && (wins.quick_wins || wins.wins)) || (Array.isArray(wins) ? wins : []);
      var oCount = (orphans && (orphans.orphans || orphans.data)) ? (orphans.orphans || orphans.data).length : 0;

      var kpiPages = document.getElementById('kpi-pages'); if (kpiPages) { kpiPages.textContent = '0'; countUp(kpiPages, pCount, 700); }
      var kpiKw = document.getElementById('kpi-kw'); if (kpiKw) { kpiKw.textContent = '0'; countUp(kpiKw, kCount, 600); }
      var kpiWins = document.getElementById('kpi-wins'); if (kpiWins) { kpiWins.textContent = '0'; countUp(kpiWins, winsArr.length, 600); }
      var kpiOrph = document.getElementById('kpi-orph'); if (kpiOrph) { kpiOrph.textContent = '0'; countUp(kpiOrph, oCount, 600); }

      // AI summary.
      if (knowledge && knowledge.summary) {
        var bar = document.getElementById('lgse-ai-bar');
        var txt = document.getElementById('lgse-ai-text');
        if (bar && txt) { txt.textContent = knowledge.summary; bar.style.display = 'flex'; }
      }

      // Issues strip — top 3.
      var checks = (results_json.checks || results_json.issues || []);
      var errors = checks.filter(function (c) { return c && (c.status === 'error' || c.severity === 'error'); });
      var warnings = checks.filter(function (c) { return c && (c.status === 'warning' || c.severity === 'warning'); });
      var topIssues = (knowledge && Array.isArray(knowledge.top_issues)) ? knowledge.top_issues : [];

      var issStrip = document.getElementById('lgse-iss-strip');
      var issCount = document.getElementById('lgse-iss-count');
      var combined = [];
      errors.slice(0, 3).forEach(function (e) { combined.push({ label: e.check || e.label || 'Critical issue', level: 'error' }); });
      if (combined.length < 3) warnings.slice(0, 3 - combined.length).forEach(function (w) { combined.push({ label: w.check || w.label || 'Warning', level: 'warn' }); });
      if (combined.length === 0) topIssues.slice(0, 3).forEach(function (i) { combined.push({ label: i.count + ' ' + i.type, level: i.type === 'critical' || i.type === 'errors' ? 'error' : 'warn' }); });

      if (issCount) {
        var total = errors.length + warnings.length || topIssues.reduce(function (a, b) { return a + (b.count || 0); }, 0);
        issCount.innerHTML = badge(total + ' total', total > 5 ? 'red' : total > 0 ? 'amber' : 'teal');
      }
      if (issStrip) {
        if (combined.length === 0) {
          issStrip.innerHTML = '<div style="grid-column:1/-1">' + emptyState('✓', 'No issues found', 'Your site looks healthy.') + '</div>';
        } else {
          issStrip.innerHTML = combined.map(function (i) {
            var col = i.level === 'error' ? '#EF4444' : '#F59E0B';
            return '<div class="lgse-issue-chip"><div class="lgse-dot" style="background:' + col + '"></div><div style="flex:1;font-size:11px;color:var(--lgse-t2)">' + esc(i.label) + '</div>' + badge(i.level === 'error' ? 'CRITICAL' : 'WARN', i.level === 'error' ? 'red' : 'amber') + '</div>';
          }).join('');
        }
      }

      // P0-5: priority tiles (replaces generic wins-grid). Uses real audit
      // check counts + orphan count already in scope. No extra network call.
      window.lgseRenderPriorityTiles(checks, oCount, latest);

      // Trend chart — pure SVG.
      var trendEl = document.getElementById('lgse-trend');
      if (trendEl) {
        var snaps = auditsArr.slice().reverse();
        if (snaps.length < 2) {
          trendEl.innerHTML = '<span style="color:var(--lgse-t3);font-size:11px">Not enough audits yet — run a few to see trend.</span>';
        } else {
          var scores = snaps.map(function (s) { return parseInt(s.score || 0, 10); });
          trendEl.innerHTML = trendChart(scores);
        }
      }
    }).catch(function () {
      var mg = document.getElementById('lgse-main-gauge');
      if (mg) mg.innerHTML = '<div style="color:var(--lgse-t3);font-size:11px">Could not load</div>';
    });
  }

  // P0-5: priority tiles for the Overview tab. Replaces the generic quick-wins
  // grid with concrete, actionable counts pulled from the latest audit's
  // results_json + the orphan-pages query. Each tile navigates to the matching
  // tab (Pages, Audit, Links). IIFE-private functions are scoped above; the
  // entry point is exposed on `window` so the inline-handler call from
  // loadOverviewData can reach it.
  window.lgseRenderPriorityTiles = function (checks, orphanCount, audit) {
    var el = document.getElementById('lgse-wins-grid');
    var countEl = document.getElementById('lgse-wins-count');
    if (!el) return;

    checks = Array.isArray(checks) ? checks : [];
    orphanCount = parseInt(orphanCount, 10) || 0;

    function isErr(c) { return c && (c.status === 'error' || c.severity === 'error'); }
    function isWarn(c) { return c && (c.status === 'warning' || c.severity === 'warning'); }
    function nameContains(c, needle) {
      var n = (c && (c.check || c.label || c.title || '')) + '';
      return n.toLowerCase().indexOf(needle) !== -1;
    }

    var tiles = [];

    var missingTitle = checks.filter(function (c) { return isErr(c) && nameContains(c, 'title'); }).length;
    if (missingTitle > 0) tiles.push({
      count: missingTitle, label: 'Missing SEO Titles', cta: 'Fix titles',
      color: 'var(--lgse-amber)', tab: 'pages',
    });

    var missingDesc = checks.filter(function (c) { return isErr(c) && nameContains(c, 'description'); }).length;
    if (missingDesc > 0) tiles.push({
      count: missingDesc, label: 'Missing Meta Descriptions', cta: 'Fix descriptions',
      color: 'var(--lgse-amber)', tab: 'pages',
    });

    var httpsErr = checks.filter(function (c) { return isErr(c) && nameContains(c, 'https'); }).length;
    if (httpsErr > 0) tiles.push({
      count: httpsErr, label: 'HTTPS Not Enabled', cta: 'Fix now',
      color: 'var(--lgse-red)', tab: 'audit',
    });

    var imageIssues = checks.filter(function (c) { return c && c.status !== 'pass' && nameContains(c, 'image'); }).length;
    if (imageIssues > 0) tiles.push({
      count: imageIssues, label: 'Image Issues', cta: 'Optimize images',
      color: 'var(--lgse-amber)', tab: 'pages',
    });

    if (orphanCount > 0) tiles.push({
      count: orphanCount, label: 'Orphan Pages', cta: 'Add internal links',
      color: 'var(--lgse-red)', tab: 'links',
    });

    var warnTotal = checks.filter(isWarn).length;
    if (warnTotal > 0) tiles.push({
      count: warnTotal, label: 'SEO Warnings', cta: 'Review warnings',
      color: 'var(--lgse-amber)', tab: 'audit',
    });

    if (countEl) {
      countEl.textContent = tiles.length === 0
        ? 'all clear'
        : tiles.length + (tiles.length === 1 ? ' priority' : ' priorities');
    }

    if (tiles.length === 0) {
      el.innerHTML = '<div style="grid-column:1/-1">'
        + emptyState('✓', 'No critical issues found', 'Your site is in good shape — keep monitoring with weekly audits.')
        + '</div>';
      return;
    }

    var cols = Math.min(tiles.length, 3);
    el.style.gridTemplateColumns = 'repeat(' + cols + ',1fr)';

    el.innerHTML = tiles.slice(0, 6).map(function (t) {
      var safeTab = String(t.tab).replace(/[^a-z]/g, '');
      return '<div class="lgse-win-tile" onclick="lgseSwitchTab(\'' + safeTab + '\')">'
        + '<div class="lgse-win-count" style="color:' + t.color + '">' + t.count + '</div>'
        + '<div class="lgse-win-desc">' + esc(t.label) + '</div>'
        + '<div class="lgse-win-cta" style="color:' + t.color + '">' + esc(t.cta) + ' →</div>'
        + '</div>';
    }).join('');
  };

  // ── Tab 2 — Audit ──────────────────────────────────────────────────────

  // P0-AUD-FIX2: Workspace site list. Boot-time fetch from audit history
  // (audit-history fallback only — /api/seo/workspaces/sites does not exist).
  function loadSiteList() {
    // 2026-05-12: pre-arm _lgseActiveSite from workspace seo_settings before
    // falling back to audit-history-derived sites. Fires once per page load.
    if (!window._lgseActiveSite && !window._lgseSettingsFetched) {
      window._lgseSettingsFetched = true;
      api('GET', '/settings').then(function (s) {
        if (s && s.site_url && !window._lgseActiveSite) {
          window._lgseActiveSite = s.site_url;
          try { console.log('[LGSE] Active site from /seo/settings: ' + s.site_url); } catch (_) {}
        }
      }).catch(function () {});
    }
    if (window._lgseSites && window._lgseSites.length) return;
    api('GET', '/audits?limit=50').then(function (d) {
      var rows = (d && (d.audits || d.data)) || (Array.isArray(d) ? d : []);
      var seen = {};
      var sites = [];
      rows.forEach(function (a) {
        var u = a && a.url;
        if (u && !seen[u]) { seen[u] = 1; sites.push({ url: u, name: u }); }
      });
      window._lgseSites = sites;
      if (!window._lgseActiveSite && sites.length) window._lgseActiveSite = sites[0].url;
    }).catch(function () { window._lgseSites = window._lgseSites || []; });
  }

  // P0-AUD-FIX3: site-selector strip rendered above the audit list.
  function renderSiteSelector(selectedUrl) {
    var sites = window._lgseSites || [];
    if (sites.length === 0) return '';
    if (sites.length === 1) {
      return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">'
        + '<div style="font-size:9.5px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em">Showing results for</div>'
        + '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:20px;padding:4px 12px;font-size:11px;color:var(--lgse-t2);font-family:var(--lgse-mono)">' + esc(sites[0].url) + '</div>'
        + '</div>';
    }
    return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">'
      + '<div style="font-size:9.5px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap">Website</div>'
      + '<select id="lgse-site-selector" onchange="lgseChangeSite(this.value)" style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:6px 12px;font-size:11.5px;color:var(--lgse-t1);cursor:pointer;max-width:300px">'
      + sites.map(function (s) {
          var sel = s.url === selectedUrl ? ' selected' : '';
          return '<option value="' + esc(s.url) + '"' + sel + '>' + esc(s.name || s.url) + '</option>';
        }).join('')
      + '</select></div>';
  }

  function renderAudit(el) {
    el.innerHTML =
      renderSiteSelector(window._lgseActiveSite)
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">'
        + '<div>' + pageTitle('Audit Center', 'Technical, content, link, and SERP checks. Click any audit row for the full report.') + '</div>'
        + '<div style="display:flex;gap:8px;flex-shrink:0">'
          + '<button class="lgse-btn-secondary" id="lgse-deepscan-btn" onclick="lgseRunDeepScan()">⟳ Scan Website</button>'
          + '<button class="lgse-btn-primary" onclick="lgseRunAudit()">+ Run new audit</button>'
        + '</div>'
      + '</div>'
      + '<div id="lgse-audit-body">Loading…</div>';
    loadAuditList();
  }

  // P0-AUD-FIX1: themed modal replaces the legacy native dialog.
  window.lgseRunAudit = function () {
    var sites = window._lgseSites || [];
    var siteOptions;
    if (sites.length > 1) {
      siteOptions = '<select id="lgse-audit-url-select" style="width:100%;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:8px 12px;font-size:11.5px;color:var(--lgse-t1);margin-bottom:8px;cursor:pointer">'
        + sites.map(function (s) { return '<option value="' + esc(s.url) + '">' + esc(s.name || s.url) + '</option>'; }).join('')
        + '</select>';
    } else if (sites.length === 1) {
      siteOptions = '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:8px 12px;font-size:11.5px;color:var(--lgse-t2);margin-bottom:8px;font-family:var(--lgse-mono)">' + esc(sites[0].url)
        + '<input type="hidden" id="lgse-audit-url-select" value="' + esc(sites[0].url) + '"></div>';
    } else {
      // 2026-05-12: pre-fill from _lgseActiveSite or _LGSC_SITE_URL (embed)
      var _prefill = window._lgseActiveSite || window._LGSC_SITE_URL || '';
      var _prefillEsc = _prefill.replace(/"/g, '&quot;');
      siteOptions = '<input type="text" id="lgse-audit-url-select" placeholder="https://yoursite.com" value="' + _prefillEsc + '" style="width:100%;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:8px 12px;font-size:11.5px;color:var(--lgse-t1);outline:none;margin-bottom:8px">';
    }

    var overlay = document.createElement('div');
    overlay.id = 'lgse-audit-modal';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = '<div style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:14px;padding:24px;min-width:380px;max-width:460px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.5)">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">'
        + '<div>'
          + '<div style="font-size:15px;font-weight:600;color:var(--lgse-t1);margin-bottom:3px">Run SEO Audit</div>'
          + '<div style="font-size:11px;color:var(--lgse-t3)">Runs 20+ technical, content, and SERP checks</div>'
        + '</div>'
        + '<button onclick="(function(){var m=document.getElementById(\'lgse-audit-modal\');if(m)m.remove();})()" style="background:transparent;border:none;color:var(--lgse-t3);font-size:20px;cursor:pointer;line-height:1;padding:4px">×</button>'
      + '</div>'
      + '<div style="font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">'
        + (sites.length > 1 ? 'Select website' : 'Website')
      + '</div>'
      + siteOptions
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid var(--lgse-border)">'
        + '<button onclick="(function(){var m=document.getElementById(\'lgse-audit-modal\');if(m)m.remove();})()" class="lgse-btn-secondary" style="flex:1">Cancel</button>'
        + '<button onclick="lgseSubmitAudit()" class="lgse-btn-primary" style="flex:2">Run audit</button>'
      + '</div>'
      + '</div>';
    document.body.appendChild(overlay);
    var inp = document.getElementById('lgse-audit-url-select');
    if (inp && inp.tagName === 'INPUT' && inp.type === 'text') inp.focus();
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
  };

  window.lgseSubmitAudit = function () {
    var el = document.getElementById('lgse-audit-url-select');
    if (!el) return;
    var url = (el.value || el.textContent || '').trim();
    if (!url) { el.style.borderColor = 'var(--lgse-red)'; return; }
    var modal = document.getElementById('lgse-audit-modal');
    if (modal) modal.remove();
    window.lgseDoRunAudit(url);
  };

  window.lgseDoRunAudit = function (url) {
    var btn = document.querySelector('[onclick*="lgseRunAudit"]');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Running...'; }
    api('POST', '/deep-audit', { url: url }).then(function () {
      if (typeof window.showToast === 'function') window.showToast('Audit started.', 'success');
      if (btn) { btn.disabled = false; btn.textContent = '+ Run new audit'; }
      // Add new URL to known sites if absent.
      var sites = window._lgseSites || [];
      var seen = false;
      for (var i = 0; i < sites.length; i++) { if (sites[i].url === url) { seen = true; break; } }
      if (!seen) {
        sites.unshift({ url: url, name: url });
        window._lgseSites = sites;
        window._lgseActiveSite = url;
      }
      loadAuditList();
    }).catch(function (e) {
      // 2026-05-12: branch on e.code so the user sees the actual reason
      // (out of credits, plan-gated, validation error) instead of a generic
      // 'Failed:' toast that flashes by.
      var code = e && e.code;
      var msg  = (e && e.message) || 'Unknown error';
      if (code === 'NO_CREDITS' && typeof showPlanGate === 'function') {
        showPlanGate(msg);
      } else if (code === 'PLAN_GATED' && typeof showPlanGate === 'function') {
        showPlanGate(msg);
      } else if (typeof window.showToast === 'function') {
        window.showToast('Audit failed: ' + msg, 'error');
      }
      try { console.warn('[LGSE] Audit failed', { code: code, status: e && e.status, body: e && e.body }); } catch (_) {}
      if (btn) { btn.disabled = false; btn.textContent = '+ Run new audit'; }
    });
  };

  window.lgseChangeSite = function (url) {
    window._lgseActiveSite = url;
    loadAuditList();
  };

  // P0-AUD-FIX4: Always-visible "Scan Website" button + simulated progress bar.
  window.lgseRunDeepScan = function () {
    var sites = window._lgseSites || [];
    var siteUrl = window._lgseActiveSite || (sites[0] && sites[0].url) || '';
    // 2026-05-12: fall back to the embed-passed site URL before prompting.
    if (!siteUrl && window._LGSC_SITE_URL) {
      siteUrl = window._LGSC_SITE_URL;
      window._lgseActiveSite = siteUrl;
    }
    if (!siteUrl) { window.lgseRunAudit(); return; }
    var btn = document.getElementById('lgse-deepscan-btn');
    var progEl = document.getElementById('lgse-deepscan-progress');
    if (!progEl) {
      progEl = document.createElement('div');
      progEl.id = 'lgse-deepscan-progress';
      progEl.style.cssText = 'background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px;margin-bottom:14px';
      progEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">'
        + '<span id="lgse-scan-msg" style="font-size:11.5px;color:var(--lgse-t2)">Starting deep scan…</span>'
        + '<span style="font-family:var(--lgse-mono);font-size:10px;color:var(--lgse-t3)" id="lgse-scan-pct">0%</span>'
        + '</div>'
        + '<div style="height:6px;background:var(--lgse-bg3);border-radius:3px;overflow:hidden">'
        + '<div id="lgse-scan-bar" style="height:100%;width:0%;background:var(--lgse-purple);border-radius:3px;transition:width .4s ease"></div>'
        + '</div>';
      var auditBody = document.getElementById('lgse-audit-body');
      if (auditBody && auditBody.parentNode) auditBody.parentNode.insertBefore(progEl, auditBody);
    }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Scanning...'; }
    var pct = 0;
    var ticker = setInterval(function () {
      pct = Math.min(pct + 2, 88);
      var bar = document.getElementById('lgse-scan-bar');
      var pctEl = document.getElementById('lgse-scan-pct');
      var msg = document.getElementById('lgse-scan-msg');
      if (bar) bar.style.width = pct + '%';
      if (pctEl) pctEl.textContent = pct + '%';
      if (msg && pct > 60) msg.textContent = 'Indexing content…';
      else if (msg && pct > 20) msg.textContent = 'Scanning pages…';
    }, 400);
    api('POST', '/deep-audit', { url: siteUrl }).then(function (d) {
      clearInterval(ticker);
      var bar = document.getElementById('lgse-scan-bar');
      var pctEl = document.getElementById('lgse-scan-pct');
      var msg = document.getElementById('lgse-scan-msg');
      if (bar) bar.style.width = '100%';
      if (pctEl) pctEl.textContent = '100%';
      var n = (d && (d.pages_indexed || d.pages || d.total_urls)) || '';
      if (msg) msg.textContent = 'Scan complete' + (n ? (' — ' + n + ' pages indexed') : '');
      setTimeout(function () {
        var prog = document.getElementById('lgse-deepscan-progress');
        if (prog) prog.remove();
        if (btn) { btn.disabled = false; btn.textContent = '⟳ Scan Website'; }
        loadAuditList();
      }, 2500);
    }).catch(function () {
      clearInterval(ticker);
      var msg = document.getElementById('lgse-scan-msg');
      if (msg) { msg.textContent = 'Scan failed — try again'; msg.style.color = 'var(--lgse-red)'; }
      if (btn) { btn.disabled = false; btn.textContent = '⟳ Scan Website'; }
      setTimeout(function () {
        var prog = document.getElementById('lgse-deepscan-progress');
        if (prog) prog.remove();
      }, 3000);
    });
  };

  function loadAuditList() {
    var body = document.getElementById('lgse-audit-body');
    if (!body) return;
    api('GET', '/audits').then(function (d) {
      var rows = (d && (d.audits || d.data)) || (Array.isArray(d) ? d : []);
      // P0-AUD-FIX3: client-side filter by active site (backend filter unconfirmed).
      var activeSite = window._lgseActiveSite;
      if (activeSite) rows = rows.filter(function (r) { return r && r.url === activeSite; });
      if (rows.length === 0) {
        body.innerHTML = emptyState('◎', 'No audits yet', 'Run your first audit to see issues, scores, and a full health snapshot.', 'Run audit', 'lgseRunAudit()');
        return;
      }
      // P0-AUD-AUTOLOAD: auto-show the most recent audit detail.
      // Trust backend ordering (rows[0] = latest); the back link in the
      // detail view re-runs lgseSwitchTab('audit') which re-enters this
      // function, effectively a refresh-to-latest.
      var latest = rows[0];
      if (latest && latest.id) {
        window.lgseShowAuditDetail(latest.id);
      } else {
        body.innerHTML = emptyState('⚠', 'Could not load latest audit', 'No audit ID returned from server.');
      }
    }).catch(function () {
      body.innerHTML = emptyState('⚠', 'Could not load audits', 'Check your connection and try again.');
    });
  }
  window.lgseShowAuditDetail = function (id) {
    var body = document.getElementById('lgse-audit-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:40px;text-align:center;color:var(--lgse-t3);font-size:11px">Loading audit…</div>';
    api('GET', '/audits/' + id).then(function (a) {
      if (a && (a.audit || a.data)) a = a.audit || a.data;
      var results = {};
      try { results = typeof a.results_json === 'string' ? JSON.parse(a.results_json) : (a.results_json || {}); } catch (e) { results = {}; }
      var checks = Array.isArray(results.checks) ? results.checks : (Array.isArray(results.issues) ? results.issues : []);
      var errors = checks.filter(function (c) { return c.status === 'error' || c.severity === 'error'; });
      var warnings = checks.filter(function (c) { return c.status === 'warning' || c.severity === 'warning'; });
      var passed = checks.filter(function (c) { return c.status === 'pass' || c.status === 'success'; });
      var cats = {};
      checks.forEach(function (c) { var k = c.category || c.section || 'general'; (cats[k] = cats[k] || []).push(c); });

      var h = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">'
        + '<div style="flex:1"><div style="font-size:13px;font-weight:600;color:var(--lgse-t1);margin-bottom:6px;word-break:break-all">' + esc(a.url || '') + '</div>'
        + '<div style="display:flex;gap:6px;flex-wrap:wrap">' + badge(errors.length + ' errors', 'red') + badge(warnings.length + ' warnings', 'amber') + badge(passed.length + ' passed', 'teal') + '</div></div>'
        + gauge(a.score || 0, 72, false) + '</div>';
      h += '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:16px">';
      [
        { v: checks.length, l: 'Checks run', c: 'var(--lgse-t1)' },
        { v: errors.length, l: 'Critical', c: 'var(--lgse-red)' },
        { v: warnings.length, l: 'Warnings', c: 'var(--lgse-amber)' },
        { v: passed.length, l: 'Passed', c: 'var(--lgse-teal)' },
        { v: a.score || 0, l: 'Score', c: 'var(--lgse-blue)' },
      ].forEach(function (k) {
        h += '<div class="lgse-kpi-card" style="text-align:center;padding:10px 8px"><div class="lgse-kpi-val" style="font-size:20px;color:' + k.c + '">' + k.v + '</div><div class="lgse-kpi-label">' + k.l + '</div></div>';
      });
      h += '</div>';

      if (Object.keys(cats).length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Issue categories</span><span style="font-size:10px;color:var(--lgse-t3)">Click a category to filter checks</span></div>';
        h += '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-bottom:16px">';
        Object.keys(cats).forEach(function (k) {
          var items = cats[k];
          var ec = items.filter(function (i) { return i.status === 'error'; }).length;
          var wc = items.filter(function (i) { return i.status === 'warning'; }).length;
          var col = ec > 0 ? '#EF4444' : wc > 0 ? '#F59E0B' : '#00E5A8';
          var safeCat = String(k).replace(/['"<>\\]/g, '');
          h += '<div class="lgse-cat-item" data-category="' + esc(k) + '" style="cursor:pointer" onclick="lgseFilterAuditByCategory(\'' + safeCat + '\', this)"><div class="lgse-cat-icon" style="background:' + col + '"></div><div style="flex:1;font-size:11px;color:var(--lgse-t1)">' + esc(k) + '</div><span style="font-family:var(--lgse-mono);font-size:11px;font-weight:700;color:' + col + '">' + items.length + '</span>' + badge(ec > 0 ? 'CRITICAL' : wc > 0 ? 'WARN' : 'OK', ec > 0 ? 'red' : wc > 0 ? 'amber' : 'teal') + '</div>';
        });
        h += '</div>';
      }

      h += '<div class="lgse-section-hdr"><span class="lgse-section-title">All checks</span><span style="display:flex;align-items:center;gap:10px"><span id="lgse-cat-filter-label" style="font-size:10px;color:var(--lgse-t3)">All checks</span><span id="lgse-cat-filter-clear" onclick="lgseFilterAuditByCategory(\'all\', null)" style="font-size:10px;color:var(--lgse-purple);cursor:pointer;display:none">Clear filter</span></span></div>';
      if (checks.length === 0) {
        h += emptyState('·', 'No checks recorded', 'This audit type may not have produced detailed checks. Audit type: ' + esc(a.type || 'unknown') + '.');
      } else {
        h += '<table class="lgse-table"><thead><tr><th>Check</th><th>Category</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        checks.forEach(function (c) {
          var st = c.status || (c.severity === 'error' ? 'error' : c.severity === 'warning' ? 'warning' : 'pass');
          var rowCat = c.category || c.section || 'general';
          h += '<tr class="lgse-check-row" data-category="' + esc(rowCat) + '"><td style="font-weight:500;color:var(--lgse-t1)">' + esc(c.check || c.label || c.title || '—') + '</td>';
          h += '<td style="font-size:10.5px;color:var(--lgse-t3)">' + esc(c.category || '—') + '</td>';
          h += '<td>' + badge(st, st === 'error' ? 'red' : st === 'warning' ? 'amber' : 'teal') + '</td>';
          h += '<td style="font-size:10.5px;color:var(--lgse-t3)">' + esc(c.details || c.message || c.description || '') + '</td></tr>';
        });
        h += '</tbody></table>';
      }
      body.innerHTML = h;
      var g = body.querySelector('.lgse-gauge-wrap');
      if (g) animateGauge(g, 50);
    }).catch(function () {
      body.innerHTML = emptyState('⚠', 'Could not load audit', 'Try refreshing the page.', '← Back', 'lgseSwitchTab(\'audit\')');
    });
  };

  // P0-4: clickable category filter for the All-checks table.
  window.lgseFilterAuditByCategory = function (cat, el) {
    var chips = document.querySelectorAll('.lgse-cat-item');
    Array.prototype.forEach.call(chips, function (c) {
      c.style.borderColor = '';
      c.style.background = '';
    });
    if (el && cat !== 'all') {
      el.style.borderColor = 'var(--lgse-purple)';
      el.style.background = 'rgba(108,92,231,0.08)';
    }
    var rows = document.querySelectorAll('.lgse-check-row');
    var shown = 0;
    Array.prototype.forEach.call(rows, function (row) {
      var rc = row.getAttribute('data-category') || '';
      var show = cat === 'all' || rc === cat;
      row.style.display = show ? '' : 'none';
      if (show) shown++;
    });
    var lbl = document.getElementById('lgse-cat-filter-label');
    var clr = document.getElementById('lgse-cat-filter-clear');
    if (lbl) {
      lbl.textContent = cat === 'all'
        ? 'All checks'
        : (cat + ' · ' + shown + (shown === 1 ? ' check' : ' checks'));
    }
    if (clr) clr.style.display = cat === 'all' ? 'none' : 'inline';
  };

  // ── Tab 3 — Keywords ───────────────────────────────────────────────────
  // SMOKE-2.3 — Country selector (persisted in localStorage). The current
  // backend (GET /keywords) doesn't yet accept a location filter, but the
  // selection is forwarded as a query param so the data layer can adopt it
  // without a frontend change. SERP fetch + research panel require a
  // backend that doesn't exist on staging yet — surfaced honestly below.
  var LGSE_KW_COUNTRIES = [
    { v: 'AE', l: 'UAE' },     { v: 'SA', l: 'Saudi Arabia' },
    { v: 'EG', l: 'Egypt' },   { v: 'DE', l: 'Germany' },
    { v: 'AT', l: 'Austria' }, { v: 'CH', l: 'Switzerland' },
    { v: 'IN', l: 'India' },   { v: 'SG', l: 'Singapore' },
    { v: 'GB', l: 'UK' },      { v: 'US', l: 'USA' }
  ];

  function lgseGetKwCountry() {
    return localStorage.getItem('lgse_kw_country') || 'AE';
  }

  window.lgseSetKwCountry = function (v) {
    if (!v) return;
    localStorage.setItem('lgse_kw_country', v);
    loadKeywords();
  };

  function renderKeywords(el) {
    var country = lgseGetKwCountry();
    var countryOpts = LGSE_KW_COUNTRIES.map(function (c) {
      return '<option value="' + c.v + '"' + (c.v === country ? ' selected' : '') + '>' + esc(c.l) + '</option>';
    }).join('');
    el.innerHTML = pageTitle('Keywords', 'Track positions, discover keywords from your content, and research new opportunities.')
      + '<div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-bottom:12px;flex-wrap:wrap">'
        + '<span style="font-size:11px;color:var(--lgse-t3)">Country:</span>'
        + '<select onchange="lgseSetKwCountry(this.value)" style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:6px 10px;border-radius:7px;font-size:11.5px;cursor:pointer" title="Search country">' + countryOpts + '</select>'
      + '</div>'
      + '<div class="lgse-kw-subtabs" style="display:flex;gap:0;border-bottom:1px solid var(--lgse-border);margin-bottom:16px">'
        + '<div class="lgse-kw-subtab lgse-active" data-subtab="tracked" onclick="lgseKwSubTab(\'tracked\',this)" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--p);border-bottom:2px solid var(--p);white-space:nowrap;margin-bottom:-1px">Tracked</div>'
        + '<div class="lgse-kw-subtab" data-subtab="suggestions" onclick="lgseKwSubTab(\'suggestions\',this)" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--lgse-t3);border-bottom:2px solid transparent;white-space:nowrap;margin-bottom:-1px">Suggestions</div>'
        + '<div class="lgse-kw-subtab" data-subtab="research" onclick="lgseKwSubTab(\'research\',this)" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--lgse-t3);border-bottom:2px solid transparent;white-space:nowrap;margin-bottom:-1px">Research</div>'
      + '</div>'
      + '<div id="lgse-kw-content"></div>';
    window.lgseKwSubTab('tracked', el.querySelector('.lgse-kw-subtab.lgse-active'));
  }

  window.lgseKwSubTab = function (tab, clickedEl) {
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-kw-subtab'), function (t) {
      t.classList.remove('lgse-active');
      t.style.color = 'var(--lgse-t3)';
      t.style.borderBottom = '2px solid transparent';
    });
    if (clickedEl) {
      clickedEl.classList.add('lgse-active');
      clickedEl.style.color = 'var(--p)';
      clickedEl.style.borderBottom = '2px solid var(--p)';
    }
    if (tab === 'tracked')          window.lgseRenderTrackedKeywords();
    else if (tab === 'suggestions') window.lgseRenderSuggestionsTab();
    else if (tab === 'research')    window.lgseRenderResearchTab();
  };

  window.lgseRenderTrackedKeywords = function () {
    var content = document.getElementById('lgse-kw-content');
    if (!content) return;
    content.innerHTML =
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">'
      +   '<input id="lgse-add-kw" type="text" placeholder="Enter a keyword to track…" style="flex:1;min-width:200px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px">'
      +   '<button class="lgse-btn-primary" onclick="lgseAddKw()">+ Track</button>'
      +   '<button class="lgse-btn-secondary" onclick="lgseCheckAllKeywords(this)">Check all</button>'
      + '</div>'
      + '<div id="lgse-kw-body">Loading…</div>';
    loadKeywords();
  };

  window.lgseCheckAllKeywords = function (btn) {
    var orig = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Checking all…'; }
    var country = lgseGetKwCountry();
    api('GET', '/keywords?location=' + encodeURIComponent(country)).then(function (d) {
      var rows = (d && (d.keywords || d.data)) || (Array.isArray(d) ? d : []);
      if (!rows.length) {
        if (btn) { btn.disabled = false; btn.textContent = orig || 'Check all'; }
        return;
      }
      return Promise.all(rows.map(function (kw) {
        return api('POST', '/keywords/' + (kw.id || 0) + '/check', { country: country }).catch(function () {});
      }));
    }).then(function () {
      if (btn) { btn.disabled = false; btn.textContent = orig || 'Check all'; }
      if (typeof loadKeywords === 'function') loadKeywords();
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = orig || 'Check all'; }
    });
  };

  window.lgseRenderSuggestionsTab = function () {
    var content = document.getElementById('lgse-kw-content');
    if (!content) return;
    content.innerHTML =
        '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid #6C5CE7;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:16px">'
      +   '<div style="font-size:9px;font-weight:600;color:#6C5CE7;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">How this works</div>'
      +   '<div style="font-size:11.5px;color:var(--lgse-t2);line-height:1.65">We analyze your indexed pages and extract the most important words and phrases. Then we check their search volume using DataForSEO. The result is a list of keywords your content is already targeting — ranked by how many people search for them each month.</div>'
      + '</div>'
      + '<div style="text-align:center;padding:16px 0">'
      +   '<button id="lgse-suggest-btn" class="lgse-btn-primary" onclick="lgseFetchSuggestions()" style="padding:10px 24px;font-size:12px">✨ Analyze my content</button>'
      + '</div>'
      + '<div id="lgse-suggestions-list"></div>';
  };

  window.lgseFetchSuggestions = function () {
    var btn = document.getElementById('lgse-suggest-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Analyzing…'; }
    var country = lgseGetKwCountry();
    api('GET', '/keywords/suggestions?location=' + encodeURIComponent(country)).then(function (d) {
      if (btn) { btn.disabled = false; btn.textContent = '✨ Analyze my content'; }
      var suggestions = (d && (d.data || d.suggestions)) || [];
      var list = document.getElementById('lgse-suggestions-list');
      if (!list) return;
      if (!suggestions.length) {
        list.innerHTML = emptyState('⟡', 'No suggestions yet', 'Index more pages first (Pages → Scan now) to get keyword suggestions from your content.');
        return;
      }
      var h = '<table class="lgse-table"><thead><tr><th>Keyword</th><th class="r">Volume</th><th>Competition</th><th>Status</th><th></th></tr></thead><tbody>';
      suggestions.forEach(function (s) {
        var vol = parseInt(s.volume || 0, 10);
        var volTier = vol >= 10000 ? 'HIGH' : vol >= 1000 ? 'MED' : vol > 0 ? 'LOW' : '—';
        var volCls  = vol >= 10000 ? 'lgse-vol-h' : vol >= 1000 ? 'lgse-vol-m' : 'lgse-vol-l';
        var compRaw = (s.competition || '').toString().toUpperCase();
        var compIdx = (s.competition_index != null) ? parseInt(s.competition_index, 10) : -1;
        var compLabel = compRaw === 'HIGH' ? 'Hard'
          : compRaw === 'LOW' ? 'Easy'
          : compRaw === 'MEDIUM' ? 'Medium'
          : (compIdx >= 70 ? 'Hard' : compIdx >= 30 ? 'Medium' : compIdx >= 0 ? 'Easy' : '—');
        var compCls = compLabel === 'Hard' ? 'lgse-b-red' : compLabel === 'Medium' ? 'lgse-b-amber' : compLabel === 'Easy' ? 'lgse-b-teal' : 'lgse-b-muted';
        var safeKw = (s.keyword || '').replace(/'/g, "\\'");
        h += '<tr>'
          + '<td style="color:var(--lgse-t1);font-weight:500">' + esc(s.keyword || '') + '</td>'
          + '<td class="mono">' + (vol > 0 ? '<span class="lgse-badge ' + volCls + '" style="margin-right:6px">' + volTier + '</span>' + vol.toLocaleString() : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td>' + (compLabel === '—' ? '<span style="color:var(--lgse-t3)">—</span>' : '<span class="lgse-badge ' + compCls + '">' + compLabel + '</span>') + '</td>'
          + '<td>' + (s.already_tracked ? '<span class="lgse-badge lgse-b-teal">Tracked</span>' : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td style="text-align:right">' + (s.already_tracked ? '' : '<button class="lgse-btn-primary" style="padding:3px 10px;font-size:10px" onclick="lgseTrackSuggestion(\'' + safeKw + '\')">+ Track</button>') + '</td>'
          + '</tr>';
      });
      h += '</tbody></table>';
      list.innerHTML = h;
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = '✨ Analyze my content'; }
      var list = document.getElementById('lgse-suggestions-list');
      if (list) list.innerHTML = emptyState('⚠', 'Analysis failed', 'DataForSEO may be unavailable. Try again in a moment.');
    });
  };

  window.lgseTrackSuggestion = function (kw) {
    if (!kw) return;
    var country = lgseGetKwCountry();
    api('POST', '/keywords', { keyword: kw, country: country }).then(function () {
      var firstTab = document.querySelector('.lgse-kw-subtab[data-subtab="tracked"]');
      if (firstTab) window.lgseKwSubTab('tracked', firstTab);
    }).catch(function () {});
  };

  window.lgseRenderResearchTab = function () {
    var content = document.getElementById('lgse-kw-content');
    if (!content) return;
    content.innerHTML =
        '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid #6C5CE7;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:16px">'
      +   '<div style="font-size:9px;font-weight:600;color:#6C5CE7;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">Keyword Research Tool</div>'
      +   '<div style="font-size:11.5px;color:var(--lgse-t2);line-height:1.65">Enter any keyword to discover related search terms, their monthly search volume, and competition level. Use this to find new content opportunities before you write.</div>'
      + '</div>'
      + '<div style="display:flex;gap:8px;margin-bottom:16px">'
      +   '<input id="lgse-research-input" placeholder="e.g. private chef dubai" style="flex:1;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:10px 14px;font-size:12px;color:var(--lgse-t1)">'
      +   '<button class="lgse-btn-primary" onclick="lgseRunResearch()" style="padding:10px 20px;font-size:12px">Research →</button>'
      + '</div>'
      + '<div id="lgse-research-results"><div style="text-align:center;padding:40px;color:var(--lgse-t3);font-size:11.5px">Enter a keyword above to discover related search terms</div></div>';
    setTimeout(function () {
      var inp = document.getElementById('lgse-research-input');
      if (inp) inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') window.lgseRunResearch(); });
    }, 50);
  };

  window.lgseRunResearch = function () {
    var inp = document.getElementById('lgse-research-input');
    var kw  = inp ? inp.value.trim() : '';
    if (!kw) return;
    var results = document.getElementById('lgse-research-results');
    if (results) results.innerHTML = '<div style="text-align:center;padding:20px;color:var(--lgse-t3);font-size:11.5px">⏳ Researching "' + esc(kw) + '"…</div>';
    var country = lgseGetKwCountry();
    api('POST', '/keywords/research', { keyword: kw, location: country }).then(function (d) {
      var items = (d && (d.data || d.ideas)) || [];
      if (!results) return;
      if (!items.length) {
        results.innerHTML = emptyState('⟡', 'No related keywords found', 'Try a broader search term.');
        return;
      }
      var h = '<div style="font-size:10px;color:var(--lgse-t3);margin-bottom:8px">' + items.length + ' related keywords for "' + esc(kw) + '"</div>'
        + '<table class="lgse-table"><thead><tr><th>Keyword</th><th class="r">Volume</th><th>Difficulty</th><th class="r">CPC</th><th></th></tr></thead><tbody>';
      items.forEach(function (item) {
        var vol = parseInt(item.volume || 0, 10);
        var volTier = vol >= 10000 ? 'HIGH' : vol >= 1000 ? 'MED' : vol > 0 ? 'LOW' : '—';
        var volCls  = vol >= 10000 ? 'lgse-vol-h' : vol >= 1000 ? 'lgse-vol-m' : 'lgse-vol-l';
        var diff      = (item.difficulty || '').toString();
        var diffCls   = diff === 'hard' ? 'lgse-b-red' : diff === 'medium' ? 'lgse-b-amber' : diff === 'easy' ? 'lgse-b-teal' : 'lgse-b-muted';
        var diffLabel = diff ? (diff.charAt(0).toUpperCase() + diff.slice(1)) : '—';
        var safeKw = (item.keyword || '').replace(/'/g, "\\'");
        h += '<tr>'
          + '<td style="color:var(--lgse-t1);font-weight:500">' + esc(item.keyword || '') + '</td>'
          + '<td class="mono">' + (vol > 0 ? '<span class="lgse-badge ' + volCls + '" style="margin-right:6px">' + volTier + '</span>' + vol.toLocaleString() : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td>' + (diff ? '<span class="lgse-badge ' + diffCls + '">' + diffLabel + '</span>' : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td class="mono">' + (item.cpc != null ? '$' + parseFloat(item.cpc).toFixed(2) : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td style="text-align:right"><button class="lgse-btn-primary" style="padding:3px 10px;font-size:10px" onclick="lgseTrackSuggestion(\'' + safeKw + '\')">+ Track</button></td>'
          + '</tr>';
      });
      h += '</tbody></table>';
      results.innerHTML = h;
    }).catch(function () {
      if (results) results.innerHTML = emptyState('⚠', 'Research failed', 'DataForSEO may be unavailable. Try again in a moment.');
    });
  };
  window.lgseAddKw = function () {
    var inp = document.getElementById('lgse-add-kw');
    if (!inp || !inp.value.trim()) return;
    api('POST', '/keywords', { keyword: inp.value.trim(), country: lgseGetKwCountry() }).then(function () { inp.value = ''; loadKeywords(); }).catch(function () {});
  };
  function loadKeywords() {
    var body = document.getElementById('lgse-kw-body');
    if (!body) return;
    var country = lgseGetKwCountry();
    api('GET', '/keywords?location=' + encodeURIComponent(country)).then(function (d) {
      var rows = (d && (d.keywords || d.data)) || (Array.isArray(d) ? d : []);
      if (rows.length === 0) {
        body.innerHTML = emptyState('↗', 'No keywords tracked', 'Add keywords above to monitor their position over time.');
        return;
      }
      // Detect whether ANY row has live position/volume data.
      var hasLiveData = rows.some(function (kw) {
        return parseInt(kw.current_rank || kw.position || 0, 10) > 0 || parseInt(kw.volume || 0, 10) > 0;
      });
      var h = '';
      if (!hasLiveData) {
        h += '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid var(--lgse-purple);border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:14px;font-size:11.5px;color:var(--lgse-t2);line-height:1.55">'
          + '<strong style="color:var(--lgse-t1)">Position + volume data not yet available.</strong> '
          + 'These columns populate once a SERP/GSC sync runs for this workspace. '
          + 'Connect Google Search Console (Insights → Search Console) to start syncing.'
          + '</div>';
      }
      h += '<table class="lgse-table"><thead><tr><th>Keyword</th><th class="r">Position</th><th class="r">Δ</th><th>Trend</th><th class="r">Volume</th><th></th></tr></thead><tbody>';
      rows.forEach(function (kw) {
        var pos = parseInt(kw.current_rank || kw.position || 0, 10);
        var prev = parseInt(kw.previous_rank || 0, 10);
        var change = parseInt(kw.rank_change != null ? kw.rank_change : (prev > 0 && pos > 0 ? prev - pos : 0), 10);
        var positions = Array.isArray(kw.positions) && kw.positions.length > 0 ? kw.positions.map(function (p) { return p.pos != null ? p.pos : p.position || 100; }) : (prev > 0 && pos > 0 ? [prev, pos] : []);
        var posBadge = pos === 0 ? 'lgse-b-muted' : pos <= 3 ? 'lgse-b-teal' : pos <= 10 ? 'lgse-b-blue' : pos <= 30 ? 'lgse-b-amber' : 'lgse-b-muted';
        var vol = parseInt(kw.volume || 0, 10);
        var volTier = vol >= 10000 ? 'HIGH' : vol >= 1000 ? 'MED' : vol > 0 ? 'LOW' : '—';
        var volCls = vol >= 10000 ? 'lgse-vol-h' : vol >= 1000 ? 'lgse-vol-m' : 'lgse-vol-l';
        h += '<tr><td style="color:var(--lgse-t1);font-weight:500">' + esc(kw.keyword || kw.kw || '—') + '</td>';
        h += '<td class="mono">' + (pos > 0 ? '<span class="lgse-badge ' + posBadge + '">#' + pos + '</span>' : '<span style="color:var(--lgse-t3)">—</span>') + '</td>';
        h += '<td class="mono">' + posChange(change) + '</td>';
        h += '<td>' + (positions.length > 1 ? sparkline(positions, 60, 20) : '<span style="color:var(--lgse-t3);font-size:10px">—</span>') + '</td>';
        h += '<td class="mono">' + (vol > 0 ? '<span class="lgse-badge ' + volCls + '" style="margin-right:6px">' + volTier + '</span>' + vol.toLocaleString() : '<span style="color:var(--lgse-t3)">—</span>') + '</td>';
        h += '<td style="text-align:right;white-space:nowrap">'
          + '<button class="lgse-btn-secondary" style="padding:3px 9px;font-size:10px;margin-right:4px" onclick="event.stopPropagation();lgseCheckKeyword(' + (kw.id || 0) + ',this)">Check</button>'
          + '<button class="lgse-btn-secondary" style="padding:3px 9px;font-size:10px" onclick="event.stopPropagation();lgseDelKw(' + (kw.id || 0) + ')">Remove</button>'
        + '</td></tr>';
      });
      h += '</tbody></table>';
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load keywords', 'Try refreshing.'); });
  }

  // ITEM-4 — fetch fresh SERP position for one keyword via DataForSeoConnector.
  window.lgseCheckKeyword = function (id, btn) {
    if (!id) return;
    var orig = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
    var country = (typeof lgseGetKwCountry === 'function') ? lgseGetKwCountry() : 'AE';
    api('POST', '/keywords/' + id + '/check', { country: country }).then(function (r) {
      if (r && r.success) {
        if (btn) { btn.disabled = false; btn.textContent = orig || 'Check'; }
        loadKeywords();
      } else if (btn) {
        btn.disabled = false;
        btn.textContent = orig || 'Check';
        if (typeof window.showToast === 'function') {
          window.showToast('Position check failed: ' + ((r && r.error) || 'unknown'), 'error');
        }
      }
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = orig || 'Check'; }
      if (typeof window.showToast === 'function') window.showToast('Position check failed', 'error');
    });
  };

  window.lgseDelKw = function (id) {
    if (!id) return;
    api('DELETE', '/keywords/' + id).then(function () { loadKeywords(); }).catch(function () {});
  };

  // ── Tab 4 — Pages (sub-tabs) ───────────────────────────────────────────

  // P0-PAGES-FIX (2026-04-28): server-side scan to populate seo_content_index.
  // POSTs to /api/seo/index-pages, which wraps SeoService::fetchAndIndexUrl.
  // If no active site URL is known (cold workspace), opens the audit modal so
  // the user can supply one — same modal as Run Audit, reused.
  window.lgseScanAndIndexPages = function () {
    var sites = window._lgseSites || [];
    var url = window._lgseActiveSite || (sites[0] && sites[0].url) || '';
    if (!url) {
      if (typeof window.lgseRunAudit === 'function') window.lgseRunAudit();
      return;
    }
    var btn = document.getElementById('lgse-scanpages-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Scanning…'; }
    api('POST', '/index-pages', { url: url }).then(function (r) {
      if (btn) { btn.disabled = false; btn.textContent = '⟳ Scan Pages'; }
      if (typeof window.showToast === 'function') {
        if (r && r.success) window.showToast('Indexed: ' + url + (r.score != null ? ' (score ' + r.score + ')' : ''), 'success');
        else window.showToast('Scan failed: ' + (r && r.error || 'unknown'), 'error');
      }
      // Re-render the Pages tab so the indexed sub-tab re-fetches.
      if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('pages');
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = '⟳ Scan Pages'; }
      if (typeof window.showToast === 'function') window.showToast('Scan failed.', 'error');
    });
  };

  function renderPages(el) {
    el.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px">'
        + '<div>' + pageTitle('Pages', 'On-page optimization across content, images, and CTR opportunities.') + '</div>'
        + '<button class="lgse-btn-secondary" id="lgse-scanpages-btn" onclick="lgseScanAndIndexPages()" style="flex-shrink:0">⟳ Scan Pages</button>'
      + '</div>'
      + '<div class="lgse-subtabs" id="lgse-pages-subtabs">'
        + '<div class="lgse-subtab active" data-sec="indexed">Indexed Content</div>'
        + '<div class="lgse-subtab" data-sec="images">Images</div>'
        + '<div class="lgse-subtab" data-sec="ctr">CTR Optimizer</div>'
        + '<div class="lgse-subtab" data-sec="wins">Quick Wins</div>'
      + '</div>'
      + '<div id="lgse-pages-body"></div>';
    var body = document.getElementById('lgse-pages-body');
    var subtabs = document.getElementById('lgse-pages-subtabs');
    function load(sec) {
      Array.prototype.forEach.call(subtabs.children, function (t) { t.classList.toggle('active', t.getAttribute('data-sec') === sec); });
      body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading…</div>';
      if (sec === 'indexed') return loadIndexed(body);
      if (sec === 'images') return loadImages(body);
      if (sec === 'ctr') return loadCtr(body);
      if (sec === 'wins') return loadWins(body);
    }
    Array.prototype.forEach.call(subtabs.children, function (t) {
      t.addEventListener('click', function () { load(t.getAttribute('data-sec')); });
    });
    load('indexed');
  }

  // P0-1: inline meta-edit for the Pages > Indexed Content table.
  // Click any "SEO Title" or "Description" cell → editable popup → save.
  // The save POSTs both meta fields together (backend always writes both,
  // so we ship the un-edited cell's current value too — see lgseIleSave).

  window.lgseIleOpen = function (el) {
    if (!el) return;
    var display = el.querySelector('.ile-display');
    var editor  = el.querySelector('.ile-editor');
    var input   = el.querySelector('.ile-input');
    var counter = el.querySelector('.ile-counter');
    if (!display || !editor || !input) return;

    // Close other open editors.
    var allEditors = document.querySelectorAll('.ile-editor');
    Array.prototype.forEach.call(allEditors, function (e) { if (e !== editor) e.style.display = 'none'; });
    var allDisplays = document.querySelectorAll('.ile-display');
    Array.prototype.forEach.call(allDisplays, function (d) { if (d !== display) d.style.display = 'block'; });

    display.style.display = 'none';
    editor.style.display = 'block';
    input.focus();
    input.select();

    var max  = parseInt(el.dataset.max  || '60', 10);
    var warn = parseInt(el.dataset.warn || '50', 10);
    function updateCounter() {
      var n = (input.value || '').length;
      if (!counter) return;
      counter.textContent = n + '/' + max;
      counter.style.color = n > max ? 'var(--lgse-red)'
                          : n > warn ? 'var(--lgse-amber)'
                                     : 'var(--lgse-teal)';
    }
    updateCounter();
    input.oninput = updateCounter;
    input.onkeydown = function (ev) {
      if (ev.key === 'Enter')  { ev.preventDefault(); window.lgseIleSave(el); }
      if (ev.key === 'Escape') { window.lgseIleClose(el); }
    };
  };

  window.lgseIleClose = function (el) {
    if (!el) return;
    var display = el.querySelector('.ile-display');
    var editor  = el.querySelector('.ile-editor');
    if (display) display.style.display = 'block';
    if (editor)  editor.style.display = 'none';
  };

  window.lgseIleSave = function (el) {
    if (!el) return;
    var url = el.dataset.url;
    if (!url) return;

    // Backend ALWAYS writes both meta_title + meta_description on every call,
    // so a partial body (just one field) would wipe the other. Walk every
    // .lgse-ile sibling in the same row and ship all current values together.
    var tr = el.closest ? el.closest('tr') : null;
    var siblings = tr ? tr.querySelectorAll('.lgse-ile') : [el];
    var body = { url: url };
    Array.prototype.forEach.call(siblings, function (s) {
      var f = s.dataset.field;
      if (!f) return;
      var inp = s.querySelector('.ile-input');
      body[f] = inp ? (inp.value || '').trim() : '';
    });

    // Optimistic UI update on the focused cell.
    var input = el.querySelector('.ile-input');
    var display = el.querySelector('.ile-display');
    var newVal = input ? (input.value || '').trim() : '';
    if (display) {
      display.textContent = newVal || 'Click to add…';
      display.style.color = newVal ? '' : 'var(--lgse-red)';
      display.title = newVal;
    }
    el.dataset.savedValue = newVal;
    window.lgseIleClose(el);

    api('PATCH', '/connector/save-meta', body).then(function (resp) {
      if (!resp || resp.success === false) {
        if (display) {
          display.style.color = 'var(--lgse-red)';
          display.title = 'Save failed: ' + ((resp && resp.error) || 'unknown');
        }
      }
    }).catch(function () {
      if (display) {
        display.style.color = 'var(--lgse-red)';
        display.title = 'Save failed';
      }
    });
  };

  window.lgseIleCell = function (val, field, url, max, warn) {
    val = (val == null) ? '' : String(val);
    var maxLen  = parseInt(max  || 60, 10);
    var warnLen = parseInt(warn || 50, 10);
    var safeUrl = esc(url || '');
    var safeVal = esc(val);
    var displayText = val || 'Click to add…';
    var emptyColor = val ? '' : 'color:var(--lgse-red);';

    return '<div class="lgse-ile" data-url="' + safeUrl + '" data-field="' + esc(field) + '" data-max="' + maxLen + '" data-warn="' + warnLen + '" style="position:relative;display:inline-block;min-width:120px;max-width:200px">'
      // Display mode
      + '<div class="ile-display" onclick="lgseIleOpen(this.parentElement)" '
      + 'style="cursor:pointer;font-size:10.5px;' + emptyColor + 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:3px 6px;border-radius:4px;border:1px solid transparent;transition:border-color .12s" '
      + 'onmouseover="this.style.borderColor=\'var(--lgse-border2)\'" '
      + 'onmouseout="this.style.borderColor=\'transparent\'" '
      + 'title="' + safeVal + '">' + esc(displayText) + '</div>'
      // Editor mode (absolutely positioned over the cell, popping out beyond table column width)
      + '<div class="ile-editor" style="display:none;position:absolute;top:0;left:0;z-index:100;min-width:280px;background:var(--lgse-bg1);border:1px solid var(--lgse-purple);border-radius:8px;padding:8px;box-shadow:0 8px 24px rgba(0,0,0,.5)">'
      +   '<input type="text" class="ile-input" value="' + safeVal + '" '
      +     'style="width:100%;background:transparent;border:none;color:var(--lgse-t1);font-size:11.5px;outline:none;padding:2px 0">'
      +   '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;gap:6px">'
      +     '<span class="ile-counter" style="font-family:var(--lgse-mono);font-size:9px;color:var(--lgse-t3)">0/' + maxLen + '</span>'
      +     '<div style="display:flex;gap:4px">'
      +       '<button onclick="event.stopPropagation();lgseIleSave(this.closest(\'.lgse-ile\'))" style="background:var(--lgse-purple);color:white;border:none;border-radius:4px;padding:2px 10px;font-size:10px;cursor:pointer">Save</button>'
      +       '<button onclick="event.stopPropagation();lgseIleClose(this.closest(\'.lgse-ile\'))" style="background:transparent;color:var(--lgse-t3);border:none;font-size:11px;cursor:pointer">✕</button>'
      +     '</div>'
      +   '</div>'
      + '</div>'
      + '</div>';
  };

  // ─────────────────────────────────────────────────────────────────────
  // P0-2 — Pages full controls: filters / sort / search / pagination /
  // column visibility. Module-level state lives below; UI handlers are
  // window-exposed so inline onclick= strings can reach them.
  // Backend support (verified): filter ∈ {low_score, missing_meta,
  // thin_content, no_h1}; sort ∈ {score, words, issues, indexed_at};
  // q for search; page + per_page for pagination. The runbook listed
  // 16 columns — only Score / Words / SEO Title / Description / Inbound /
  // Outbound / Orphan are backed by current data; the rest render '—'
  // until those fields are added to the response.
  // ─────────────────────────────────────────────────────────────────────

  var _pgFilter         = 'all';
  var _pgSort           = 'score';
  var _pgOrder          = 'asc';   // UI state only; backend doesn't take 'order'
  var _pgPage           = 1;
  var _pgTotal          = 0;
  var _pgSize           = 25;
  var _pgSearch         = '';
  var _pgDebounceTimer  = null;
  var _pgColPanelClickInstalled = false;

  // Filter pill → backend `filter=` param (or null for client-side / no-op).
  var _pgFilterMap = {
    all:        null,
    low:        'low_score',
    no_title:   'missing_meta',  // backend's missing_meta covers title OR desc
    no_desc:    'missing_meta',
    thin:       'thin_content',
    no_h1:      'no_h1',
  };
  // Backend-supported sort keys.
  var _pgSortable = { score: 1, words: 1, issues: 1, indexed_at: 1 };

  // Default-hidden columns for the toggle panel.
  var _pgDefaultHidden = {
    'Robots': true, 'Outbound': true, 'Authority': true, 'Equity': true,
    'CTR Score': true, 'CTR Potential': true, 'Anchor Quality': true,
    'GSC Clicks': true, 'GSC Impressions': true, 'GSC Position': true,
  };

  window.lgseColVisible = function (col) {
    var key = 'lgse_pg_cols';
    var stored = {};
    try { stored = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) {}
    if (stored.hasOwnProperty(col)) return !!stored[col];
    return !_pgDefaultHidden[col];
  };
  window.lgseToggleCol = function (col) {
    var key = 'lgse_pg_cols';
    var stored = {};
    try { stored = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) {}
    stored[col] = !window.lgseColVisible(col);
    localStorage.setItem(key, JSON.stringify(stored));
    window.lgseLoadPages();
  };
  window.lgseToggleColPanel = function () {
    var panel = document.getElementById('lgse-col-panel');
    if (!panel) return;
    panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
  };

  window.lgseSetPageFilter = function (f, el) {
    _pgFilter = f;
    _pgPage = 1;
    // Re-render filter pills so active state updates.
    var wrap = document.getElementById('lgse-pg-filters');
    if (wrap) wrap.innerHTML = lgsePagesFiltersHtml();
    window.lgseLoadPages();
  };

  window.lgsePageNav = function (dir) {
    var totalPages = Math.max(1, Math.ceil(_pgTotal / _pgSize));
    _pgPage = Math.max(1, Math.min(_pgPage + dir, totalPages));
    window.lgseLoadPages();
  };

  window.lgseSortPages = function (col) {
    if (!_pgSortable[col]) return; // unsupported sort key — ignore click
    if (_pgSort === col) {
      _pgOrder = _pgOrder === 'asc' ? 'desc' : 'asc';
    } else {
      _pgSort = col;
      _pgOrder = 'asc';
    }
    _pgPage = 1;
    window.lgseLoadPages();
  };

  window.lgseSearchPages = function (val) {
    if (_pgDebounceTimer) clearTimeout(_pgDebounceTimer);
    _pgSearch = val || '';
    _pgDebounceTimer = setTimeout(function () {
      _pgPage = 1;
      window.lgseLoadPages();
    }, 300);
  };

  window.lgseExportPagesCsv = function () {
    var token = localStorage.getItem('lu_token') || '';
    fetch(window.location.origin + '/api/seo/reports/export/pages', {
      headers: { 'Authorization': 'Bearer ' + token }
    }).then(function (r) {
      if (!r.ok) { if (typeof window.showToast === 'function') window.showToast('Export failed', 'error'); return null; }
      return r.blob();
    }).then(function (blob) {
      if (!blob) return;
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = 'pages-' + Date.now() + '.csv';
      document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    });
  };

  window.lgseLoadPages = function () {
    var body = document.getElementById('lgse-pg-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading pages…</div>';

    var params = ['per_page=' + _pgSize, 'page=' + _pgPage];
    if (_pgSortable[_pgSort]) params.push('sort=' + encodeURIComponent(_pgSort));
    var fParam = _pgFilterMap[_pgFilter];
    if (fParam) params.push('filter=' + encodeURIComponent(fParam));
    if (_pgSearch) params.push('q=' + encodeURIComponent(_pgSearch));

    api('GET', '/indexed-content?' + params.join('&')).then(function (d) {
      var pages = (d && (d.items || d.data)) || (Array.isArray(d) ? d : []);
      _pgTotal = parseInt((d && d.total) || pages.length, 10) || 0;
      window._lgsePages = pages; // P1-F — cache for row-expand detail panel.
      window.lgseRenderPagesTable(pages, _pgTotal);
    }).catch(function () {
      body.innerHTML = emptyState('⚠', 'Could not load pages', 'Check your connection and try again.');
    });
  };

  // Helpers (private — only called from within the IIFE).
  function lgsePagesFiltersHtml() {
    var pills = [
      { f: 'all',      l: 'All' },
      { f: 'low',      l: 'Low score' },
      { f: 'no_title', l: 'Missing title' },
      { f: 'no_desc',  l: 'Missing desc' },
      { f: 'thin',     l: 'Thin content' },
      { f: 'no_h1',    l: 'No H1' },
    ];
    return pills.map(function (p) {
      var active = p.f === _pgFilter;
      var bg = active ? 'rgba(108,92,231,0.08)' : 'var(--lgse-bg2)';
      var bd = active ? 'var(--lgse-purple)' : 'var(--lgse-border)';
      var c  = active ? 'var(--lgse-purple)' : 'var(--lgse-t3)';
      return '<span class="lgse-fp' + (active ? ' active' : '') + '" onclick="lgseSetPageFilter(\'' + p.f + '\', this)" '
        + 'style="background:' + bg + ';border:1px solid ' + bd + ';border-radius:20px;padding:4px 12px;font-size:10.5px;color:' + c + ';cursor:pointer;transition:all .12s">'
        + esc(p.l) + '</span>';
    }).join('');
  }

  function lgsePagesColPanelHtml() {
    var cols = ['Robots', 'Outbound', 'Authority', 'Equity', 'CTR Score', 'CTR Potential', 'Anchor Quality', 'GSC Clicks', 'GSC Impressions', 'GSC Position'];
    return cols.map(function (col) {
      var checked = window.lgseColVisible(col) ? ' checked' : '';
      return '<label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:11px;color:var(--lgse-t2);cursor:pointer">'
        + '<input type="checkbox"' + checked + ' onchange="lgseToggleCol(\'' + col + '\')" style="accent-color:var(--lgse-purple)">'
        + esc(col)
        + '</label>';
    }).join('');
  }

  window.lgseRenderPagesTable = function (pages, total) {
    var body = document.getElementById('lgse-pg-body');
    if (!body) return;

    if (!pages || !pages.length) {
      body.innerHTML = emptyState('□', 'No pages match this view',
        _pgSearch || _pgFilter !== 'all'
          ? 'Try clearing the search or filter.'
          : 'Click "Scan Pages" to crawl your site and index it for optimization.',
        'Scan Pages', 'lgseScanAndIndexPages()');
      return;
    }

    function sortInd(col) {
      if (_pgSort !== col) return '';
      return _pgOrder === 'asc' ? ' ↑' : ' ↓';
    }
    function th(label, col, opts) {
      opts = opts || {};
      var rightStyle = opts.right ? 'text-align:right;' : '';
      var sortable = _pgSortable[col];
      var cursor = sortable ? 'pointer' : 'default';
      var click = sortable ? ' onclick="lgseSortPages(\'' + col + '\')"' : '';
      return '<th' + click + ' style="cursor:' + cursor + ';' + rightStyle + 'user-select:none">'
        + esc(label) + (sortable ? sortInd(col) : '') + '</th>';
    }

    // Always-visible columns first.
    var thead = '<thead><tr>'
      + '<th style="width:26px;text-align:center"><input type="checkbox" id="lgse-pg-selectall" onchange="lgseTogglePageSelectAll(this.checked)" style="cursor:pointer"></th>' /* P1-G master checkbox */
      + '<th style="width:22px"></th>' /* P1-F chevron column */
      + th('URL', 'url')
      + th('Score', 'score', { right: true })
      + th('Words', 'words', { right: true })
      + '<th style="width:52px">Image</th>'
      + '<th>SEO Title</th>'
      + '<th>Description</th>';

    // Toggleable columns (rendered when visible).
    var toggleCols = [
      { label: 'Robots',          col: 'robots' },
      { label: 'Inbound',         col: 'il_in',           right: true },
      { label: 'Outbound',        col: 'il_out',          right: true },
      { label: 'Orphan',          col: 'orphan' },
      { label: 'Authority',       col: 'il_authority',    right: true },
      { label: 'Equity',          col: 'equity',          right: true },
      { label: 'CTR Score',       col: 'ctr_score',       right: true },
      { label: 'CTR Potential',   col: 'ctr_potential' },
      { label: 'Anchor Quality',  col: 'anchor_quality',  right: true },
      { label: 'GSC Clicks',      col: 'gsc_clicks',      right: true },
      { label: 'GSC Impressions', col: 'gsc_impressions', right: true },
      { label: 'GSC Position',    col: 'gsc_position',    right: true },
    ];
    toggleCols.forEach(function (c) {
      if (window.lgseColVisible(c.label)) thead += th(c.label, c.col, { right: !!c.right });
    });
    thead += '</tr></thead>';

    var dash = '<span style="color:var(--lgse-t3)">—</span>';

    var rowsHtml = pages.map(function (p) {
      var url = p.url || '';
      var score = parseInt(p.content_score || p.score || 0, 10) || 0;
      var words = parseInt(p.word_count || 0, 10) || 0;
      var ilIn  = parseInt(p.internal_link_count || 0, 10) || 0;
      var ilOut = parseInt(p.external_link_count || 0, 10) || 0;
      var orphan = ilIn === 0;
      var pid = parseInt(p.id, 10) || 0;
      // Bare hostnames (e.g. "chef-red.levelupgrowth.io") get treated as
      // relative paths by the browser if used in href. Force absolute.
      var externalUrl = url ? (/^https?:\/\//i.test(url) ? url : 'https://' + url) : '';

      var tr = '<tr data-page-id="' + pid + '">'
        + '<td style="text-align:center"><input type="checkbox" class="lgse-pg-check" data-pid="' + pid + '" onchange="lgseUpdateBulkBar()" style="cursor:pointer"></td>'
        + '<td onclick="lgseExpandPageRow(' + pid + ')" style="cursor:pointer;text-align:center;color:var(--lgse-t3);font-size:10px" title="Show details"><span class="lgse-pg-chev" data-pid="' + pid + '" style="display:inline-block;transition:transform .15s">▶</span></td>'
        + '<td class="lgse-url" title="' + esc(url) + '">'
          + (url
              ? '<a href="' + esc(externalUrl) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="color:var(--lgse-purple);text-decoration:none" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">' + esc(url) + '</a>'
              : '<span style="color:var(--lgse-t3)">—</span>')
        + '</td>'
        + '<td class="mono">' + (score ? scorePill(score) : dash) + '</td>'
        + '<td class="mono">' + (words || dash) + '</td>'
        + '<td>' + (function () {
            var titleAttr = esc(p.title || url || '');
            var safeUrl = encodeURIComponent(url || '');
            var safeTitle = encodeURIComponent(p.title || url || '');
            if (p.featured_image_url) {
              return '<img src="' + esc(p.featured_image_url) + '" '
                + 'style="width:44px;height:36px;object-fit:cover;border-radius:4px;cursor:pointer" '
                + 'title="Regenerate featured image (1 credit)" '
                + 'onclick="window._lgseRegenImage(' + pid + ',decodeURIComponent(\'' + safeUrl + '\'),decodeURIComponent(\'' + safeTitle + '\'),this)">';
            }
            return '<div onclick="window._lgseRegenImage(' + pid + ',decodeURIComponent(\'' + safeUrl + '\'),decodeURIComponent(\'' + safeTitle + '\'),this)" '
              + 'style="width:44px;height:36px;background:#1e293b;border:1px dashed #334155;border-radius:4px;cursor:pointer;display:flex;align-items:center;justify-content:center" '
              + 'title="Generate featured image (1 credit)">'
              + '<span style="color:#475569;font-size:16px">📷</span></div>';
          })() + '</td>'
        + '<td style="position:relative;max-width:220px">' + window.lgseIleCell(p.meta_title || p.title || '', 'meta_title', url, 60, 50) + '</td>'
        + '<td style="position:relative;max-width:240px">' + window.lgseIleCell(p.meta_description || '', 'meta_description', url, 160, 140) + '</td>';

      if (window.lgseColVisible('Robots'))          tr += '<td>' + dash + '</td>';
      if (window.lgseColVisible('Inbound'))         tr += '<td class="mono" style="color:' + (ilIn === 0 ? 'var(--lgse-red)' : 'inherit') + '">' + ilIn + '</td>';
      if (window.lgseColVisible('Outbound'))        tr += '<td class="mono">' + (ilOut || dash) + '</td>';
      if (window.lgseColVisible('Orphan'))          tr += '<td>' + (orphan ? badge('orphan', 'red') : badge('linked', 'teal')) + '</td>';
      if (window.lgseColVisible('Authority'))       tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('Equity'))          tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('CTR Score'))       tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('CTR Potential'))   tr += '<td>' + dash + '</td>';
      if (window.lgseColVisible('Anchor Quality'))  tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('GSC Clicks'))      tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('GSC Impressions')) tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('GSC Position'))    tr += '<td class="mono">' + dash + '</td>';

      tr += '</tr>';
      return tr;
    }).join('');

    var totalPages = Math.max(1, Math.ceil(total / _pgSize));
    var pagination = '';
    if (total > _pgSize) {
      pagination = '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 4px;margin-top:8px;border-top:1px solid var(--lgse-border);font-size:11px">'
        + '<button onclick="lgsePageNav(-1)"' + (_pgPage <= 1 ? ' disabled' : '') + ' style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:6px;padding:5px 12px;font-size:11px;' + (_pgPage <= 1 ? 'opacity:.4;cursor:not-allowed' : 'cursor:pointer') + '">← Prev</button>'
        + '<span style="font-family:var(--lgse-mono);font-size:11px;color:var(--lgse-t3)">Page ' + _pgPage + ' of ' + totalPages + ' · ' + total + ' pages</span>'
        + '<button onclick="lgsePageNav(1)"' + (_pgPage >= totalPages ? ' disabled' : '') + ' style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:6px;padding:5px 12px;font-size:11px;' + (_pgPage >= totalPages ? 'opacity:.4;cursor:not-allowed' : 'cursor:pointer') + '">Next →</button>'
        + '</div>';
    }

    body.innerHTML = '<div style="overflow-x:auto"><table class="lgse-table">' + thead + '<tbody>' + rowsHtml + '</tbody></table>' + pagination + '</div>';
  };

  function loadIndexed(body) {
    // Reset state when entering the sub-tab fresh.
    _pgPage = 1;
    body.innerHTML = ''
      // Toolbar: search · Columns ▾ · Export · Scan Pages.
      + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
      +   '<input id="lgse-pg-search" placeholder="Search URL or title…" oninput="lgseSearchPages(this.value)" '
      +     'style="flex:1;min-width:180px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 12px;font-size:11.5px;color:var(--lgse-t1)">'
      +   '<div style="position:relative">'
      +     '<button onclick="event.stopPropagation();lgseToggleColPanel()" style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 12px;font-size:11px;cursor:pointer">Columns ▾</button>'
      +     '<div id="lgse-col-panel" style="display:none;position:absolute;right:0;top:100%;margin-top:4px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:10px;padding:12px;min-width:200px;z-index:20;box-shadow:0 8px 24px rgba(0,0,0,.4)">'
      +       lgsePagesColPanelHtml()
      +     '</div>'
      +   '</div>'
      +   '<button onclick="lgseExportPagesCsv()" style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 12px;font-size:11px;cursor:pointer">Export CSV</button>'
      +   '<button class="lgse-btn-secondary" id="lgse-scanpages-btn" onclick="lgseScanAndIndexPages()" style="font-size:11px">⟳ Scan Pages</button>'
      + '</div>'
      // Filter pills.
      + '<div id="lgse-pg-filters" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">' + lgsePagesFiltersHtml() + '</div>'
      // Table body.
      + '<div id="lgse-pg-body"></div>';

    // Outside-click closes the col panel — install once.
    if (!_pgColPanelClickInstalled) {
      _pgColPanelClickInstalled = true;
      document.addEventListener('click', function (e) {
        var panel = document.getElementById('lgse-col-panel');
        if (!panel || panel.style.display !== 'block') return;
        if (e.target.closest && (e.target.closest('#lgse-col-panel') || (e.target.closest('button') && /lgseToggleColPanel/.test(e.target.closest('button').getAttribute('onclick') || '')))) return;
        panel.style.display = 'none';
      });
    }

    window.lgseLoadPages();
  }
  // P1-D — Images sub-tab is rendered by window.lgseRenderImages (defined below).
  // loadImages stays as a thin delegator so renderPages' dispatch keeps working.
  function loadImages(body) { window.lgseRenderImages(body); }
  function loadCtr(body) {
    api('GET', '/ctr-analysis?worst=20').then(function (d) {
      var rows = (d && (d.pages || d.data)) || (Array.isArray(d) ? d : []);
      if (rows.length === 0) {
        body.innerHTML = emptyState(
          '↗',
          'No CTR data yet',
          'CTR optimization needs Google Search Console connected. Once connected, this tab surfaces pages with high impressions but low click-through — your biggest optimization opportunity.',
          'Connect GSC',
          'lgseSwitchTab(\'insights\');setTimeout(function(){var s=document.querySelector(\'.lgse-subtab[data-sec="gsc"]\');if(s)s.click();},20)'
        );
        return;
      }
      var h = '<table class="lgse-table"><thead><tr><th>URL</th><th class="r">Clicks</th><th class="r">Impressions</th><th class="r">CTR</th><th class="r">Position</th></tr></thead><tbody>';
      rows.slice(0, 50).forEach(function (p) {
        var ctr = parseFloat(p.ctr || 0);
        h += '<tr><td class="lgse-url">' + esc(p.url || p.page_url || '—') + '</td>';
        h += '<td class="mono">' + (parseInt(p.clicks || 0, 10) || 0).toLocaleString() + '</td>';
        h += '<td class="mono">' + (parseInt(p.impressions || 0, 10) || 0).toLocaleString() + '</td>';
        h += '<td class="mono">' + (ctr * 100).toFixed(2) + '%</td>';
        h += '<td class="mono">' + (parseFloat(p.position || 0)).toFixed(1) + '</td></tr>';
      });
      h += '</tbody></table>';
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'CTR analysis unavailable', 'Connect Google Search Console to enable.'); });
  }
  function loadWins(body) {
    api('GET', '/quick-wins').then(function (d) {
      var rows = (d && (d.quick_wins || d.wins || d.data)) || (Array.isArray(d) ? d : []);
      if (rows.length === 0) { body.innerHTML = emptyState('✓', 'No quick wins right now', 'Run audits and sync GSC to surface lift opportunities.'); return; }
      var h = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px">';
      rows.slice(0, 30).forEach(function (w) {
        var label = w.issue_label || w.issue || w.gap || w.description || w.title || 'Optimization opportunity';
        var fix = w.fix || w.recommendation || w.action || '';
        var imp = parseInt(w.impact || w.priority || 0, 10);
        var bk = imp >= 18 ? 'red' : imp >= 10 ? 'amber' : 'teal';
        var bl = imp >= 18 ? 'HIGH' : imp >= 10 ? 'MED' : 'LOW';
        h += '<div class="lgse-win-tile"><div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:8px"><div style="font-size:13px;color:var(--lgse-t1);font-weight:600;line-height:1.4">' + esc(label) + '</div>' + badge(bl, bk) + '</div>';
        if (fix) h += '<div style="font-size:11px;color:var(--lgse-t2);line-height:1.5">' + esc(fix) + '</div>';
        h += '</div>';
      });
      h += '</div>';
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load quick wins', 'Try refreshing.'); });
  }

  // ── Tab 5 — Links (sub-tabs) ───────────────────────────────────────────
  function renderLinks(el) {
    el.innerHTML = pageTitle('Links', 'Internal link graph, outbound checks, and 301/302 redirects in one place.')
      + '<div class="lgse-subtabs" id="lgse-links-subtabs">'
        + '<div class="lgse-subtab active" data-sec="internal">Link Intelligence</div>'
        + '<div class="lgse-subtab" data-sec="anchors">Anchors</div>'
        + '<div class="lgse-subtab" data-sec="gaps">Gaps</div>'
        + '<div class="lgse-subtab" data-sec="outbound">Outbound</div>'
        + '<div class="lgse-subtab" data-sec="redirects">Redirects</div>'
      + '</div>'
      + '<div id="lgse-links-body"></div>';
    var body = document.getElementById('lgse-links-body');
    var subtabs = document.getElementById('lgse-links-subtabs');
    function load(sec) {
      Array.prototype.forEach.call(subtabs.children, function (t) { t.classList.toggle('active', t.getAttribute('data-sec') === sec); });
      body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading…</div>';
      if (sec === 'internal')  return loadInternalLinks(body);
      if (sec === 'anchors')   return loadAnchorsSec(body);
      if (sec === 'gaps')      return loadGapsSec(body);
      if (sec === 'outbound')  return loadOutbound(body);
      if (sec === 'redirects') return loadRedirects(body);
    }
    Array.prototype.forEach.call(subtabs.children, function (t) { t.addEventListener('click', function () { load(t.getAttribute('data-sec')); }); });
    load('internal');
  }
  function loadInternalLinks(body) {
    Promise.all([
      api('GET', '/link-graph').catch(function () { return null; }),
      api('GET', '/link-graph/orphans').catch(function () { return null; }),
      api('GET', '/links/anchor-analysis').catch(function () { return null; }),
    ]).then(function (r) {
      var graph = r[0]; var orphans = (r[1] && (r[1].orphans || r[1].data)) || []; var anchors = (r[2] && (r[2].issues || r[2].data)) || [];
      var nodes = (graph && graph.nodes) || []; var edges = (graph && graph.edges) || [];
      var h = '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Pages in graph</div><div class="lgse-kpi-val">' + nodes.length + '</div></div>'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Internal links</div><div class="lgse-kpi-val">' + edges.length + '</div></div>'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Orphan pages</div><div class="lgse-kpi-val lgse-dn">' + orphans.length + '</div></div>'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Anchor issues</div><div class="lgse-kpi-val">' + anchors.length + '</div></div>'
      + '</div>'
      + '<div style="display:flex;gap:8px;margin-bottom:6px">'
        + '<button class="lgse-btn-primary"   id="lgse-rebuild-graph-btn"   onclick="lgseRebuildGraph(this)">Rebuild graph</button>'
        + '<button class="lgse-btn-secondary" id="lgse-recalc-equity-btn"   onclick="lgseRecalcEquity(this)">Recalculate equity</button>'
      + '</div>'
      + '<div style="font-size:10px;color:var(--lgse-t3);margin-bottom:14px">Rebuilds the internal link map from your indexed pages. Run after adding new pages or changing links.</div>';

      if (orphans.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Orphan pages</span></div>';
        h += '<table class="lgse-table" style="margin-bottom:16px"><thead><tr><th>URL</th><th>Title</th><th class="r">Words</th><th class="r">Score</th></tr></thead><tbody>';
        orphans.slice(0, 20).forEach(function (o) {
          h += '<tr><td class="lgse-url">' + esc(o.url || '—') + '</td><td style="color:var(--lgse-t1);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(o.title || '—') + '</td><td class="mono">' + (parseInt(o.word_count || 0, 10) || 0) + '</td><td class="mono">' + (o.content_score ? scorePill(o.content_score) : '—') + '</td></tr>';
        });
        h += '</tbody></table>';
      }
      if (anchors.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Anchor issues</span></div>';
        h += '<table class="lgse-table"><thead><tr><th>Article</th><th>Anchor</th><th>Issue</th><th>Fix</th></tr></thead><tbody>';
        anchors.slice(0, 20).forEach(function (a) {
          var col = a.issue_type === 'over_optimised' || a.issue_type === 'over_optimised_exact' ? 'red' : a.issue_type === 'too_long' ? 'amber' : 'amber';
          h += '<tr><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(a.article_title || '') + '</td><td style="color:var(--lgse-t1);font-weight:500">"' + esc(a.anchor_text || '') + '"</td><td>' + badge(a.issue_label || '', col) + '</td><td style="color:var(--lgse-t3);font-size:11px">' + esc(a.fix || '') + '</td></tr>';
        });
        h += '</tbody></table>';
      }
      if (orphans.length === 0 && anchors.length === 0 && nodes.length === 0) {
        h += emptyState('⟡', 'No link graph yet', 'Click "Rebuild graph" to walk your indexed pages and extract internal links.', 'Build graph', 'api(\'POST\',\'/link-graph/build\',{}).then(function(){lgseSwitchTab(\'links\')})');
      }
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load link graph', 'Try refreshing.'); });
  }
  // ─────────────────────────────────────────────────────────────────────
  // P0-9 / P0-10 / P0-11 — Outbound · Redirects · 404 Log
  // The private loadOutbound / loadRedirects functions are kept as thin
  // delegators so renderLinks's existing dispatch keeps working.
  // ─────────────────────────────────────────────────────────────────────
  function loadOutbound(body)  { window.lgseLoadOutbound(); }
  function loadRedirects(body) { window.lgseLoadRedirects(); }
  function loadAnchorsSec(body){ window.lgseLoadAnchors(); }

  // ─────────────────────────────────────────────────────────────────────
  // P1-B — Anchors sub-tab. Distribution bar + filter pills + table.
  // Backend: GET /anchors -> { anchors:[...], distribution:{...}, total }
  //          POST /anchors/suggest-intent -> { suggestions:[...] }
  // No native dialogs; uses lgseShowModal for the suggest UI.
  // ─────────────────────────────────────────────────────────────────────
  window._lgseAnchorState = window._lgseAnchorState || { anchors: [], distribution: null, filter: 'all' };

  window.lgseLoadAnchors = function () {
    var bodyEl = document.getElementById('lgse-links-body');
    if (!bodyEl) return;
    bodyEl.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading anchors…</div>';
    api('GET', '/anchors').then(function (r) {
      window._lgseAnchorState = {
        anchors:      (r && r.anchors) || [],
        distribution: (r && r.distribution) || { generic:0, exact:0, natural:0, total:0, generic_pct:0, exact_pct:0, natural_pct:0 },
        filter:       'all'
      };
      window.lgseLoadAnchorData();
    }).catch(function () {
      bodyEl.innerHTML = emptyState('⚠', 'Could not load anchors', 'Click Run analysis to rebuild from your articles.', 'Run analysis', 'lgseRunAnchorAnalysis()');
    });
  };

  window.lgseLoadAnchorData = function () {
    var bodyEl = document.getElementById('lgse-links-body');
    if (!bodyEl) return;
    var st = window._lgseAnchorState || { anchors: [], distribution: { generic:0, exact:0, natural:0, total:0, generic_pct:0, exact_pct:0, natural_pct:0 }, filter: 'all' };
    var d  = st.distribution || { generic:0, exact:0, natural:0, total:0, generic_pct:0, exact_pct:0, natural_pct:0 };
    var anchors = st.anchors.slice();
    var f = st.filter || 'all';
    if (f === 'generic')  anchors = anchors.filter(function (a) { return a.classification === 'generic'; });
    else if (f === 'exact')   anchors = anchors.filter(function (a) { return a.classification === 'exact_match'; });
    else if (f === 'natural') anchors = anchors.filter(function (a) { return ['partial_match','descriptive','long_phrase'].indexOf(a.classification) !== -1; });
    else if (f === 'issues')  anchors = anchors.filter(function (a) { return !!a.issue_type; });

    var h = ''
      + '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid #6C5CE7;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:16px">'
        + '<div style="font-size:9px;font-weight:600;color:#6C5CE7;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">What is Anchor Analysis?</div>'
        + '<div style="font-size:11.5px;color:#8b90a7;line-height:1.65">Anchor text is the clickable text in a hyperlink. Google uses it to understand what the linked page is about. <strong style="color:#f0f2ff">Generic anchors</strong> ("click here", "read more") waste SEO value. <strong style="color:#f0f2ff">Exact match anchors</strong> (your exact keyword) can look spammy if overused. <strong style="color:#f0f2ff">Natural anchors</strong> (descriptive, varied) are best. Click "Run analysis" to scan all internal links on your indexed pages.</div>'
      + '</div>'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">'
        + '<div style="font-size:12px;color:var(--lgse-t2)">Anchor distribution across <strong style="color:var(--lgse-t1)">' + (d.total || 0) + '</strong> internal links</div>'
        + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="lgseRunAnchorAnalysis()">Run analysis</button>'
      + '</div>';

    // KPI cards.
    h += '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Total anchors</div><div class="lgse-kpi-val">' + (d.total || 0) + '</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Generic</div><div class="lgse-kpi-val" style="color:var(--lgse-red)">' + (d.generic_pct || 0) + '%</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Exact match</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (d.exact_pct || 0) + '%</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Natural</div><div class="lgse-kpi-val" style="color:var(--lgse-teal)">' + (d.natural_pct || 0) + '%</div></div>'
      + '</div>';

    // Distribution stack bar.
    var totalForBar = (d.generic || 0) + (d.exact || 0) + (d.natural || 0);
    if (totalForBar > 0) {
      var gW = ((d.generic || 0) / totalForBar) * 100;
      var eW = ((d.exact   || 0) / totalForBar) * 100;
      var nW = ((d.natural || 0) / totalForBar) * 100;
      h += '<div style="margin-bottom:14px">'
        + '<div class="lgse-stack-bar" style="height:14px">'
          + '<div title="Generic ' + (d.generic_pct || 0) + '%" style="width:' + gW + '%;background:var(--lgse-red)"></div>'
          + '<div title="Exact ' + (d.exact_pct || 0) + '%" style="width:' + eW + '%;background:var(--lgse-amber)"></div>'
          + '<div title="Natural ' + (d.natural_pct || 0) + '%" style="width:' + nW + '%;background:var(--lgse-teal)"></div>'
        + '</div>'
        + '<div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--lgse-t3);margin-top:5px">'
          + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-red);border-radius:2px;margin-right:5px"></span>Generic ' + (d.generic || 0) + '</span>'
          + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-amber);border-radius:2px;margin-right:5px"></span>Exact ' + (d.exact || 0) + '</span>'
          + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-teal);border-radius:2px;margin-right:5px"></span>Natural ' + (d.natural || 0) + '</span>'
        + '</div>'
      + '</div>';
    }

    // Filter pills.
    function pill(value, label, count) {
      var cls = 'lgse-filter-pill' + (f === value ? ' active' : '');
      var suffix = (count != null) ? ' <span style="opacity:.7;font-size:10px">(' + count + ')</span>' : '';
      return '<div class="' + cls + '" onclick="lgseAnchorFilter(\'' + value + '\')">' + esc(label) + suffix + '</div>';
    }
    var issuesCount = st.anchors.filter(function (a) { return !!a.issue_type; }).length;
    h += '<div class="lgse-filter-row" style="margin-bottom:10px">'
      + pill('all',     'All',     d.total)
      + pill('generic', 'Generic', d.generic)
      + pill('exact',   'Exact',   d.exact)
      + pill('natural', 'Natural', d.natural)
      + pill('issues',  'Issues',  issuesCount)
      + '</div>';

    if (anchors.length === 0) {
      if ((d.total || 0) === 0) {
        h += emptyState('⟡', 'No anchors yet', 'Click "Run analysis" to scan your articles for internal-link anchor text.', 'Run analysis', 'lgseRunAnchorAnalysis()');
      } else {
        h += '<div style="padding:24px;text-align:center;color:var(--lgse-t3);background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;font-size:11.5px">No anchors match the current filter.</div>';
      }
      bodyEl.innerHTML = h;
      return;
    }

    // Anchor table — capped at 200 rows for render perf.
    var rows = anchors.slice(0, 200);
    h += '<table class="lgse-table">'
      + '<thead><tr>'
        + '<th>Article</th>'
        + '<th>Anchor</th>'
        + '<th>Target</th>'
        + '<th>Type</th>'
        + '<th class="r">Score</th>'
        + '<th>Issue</th>'
        + '<th class="r">Action</th>'
      + '</tr></thead><tbody>';
    rows.forEach(function (a, idx) {
      var origIdx = st.anchors.indexOf(a);
      var clsLabel, clsKind;
      if (a.classification === 'generic')              { clsLabel = 'Generic';   clsKind = 'red'; }
      else if (a.classification === 'exact_match')     { clsLabel = 'Exact';     clsKind = 'amber'; }
      else if (a.classification === 'partial_match')   { clsLabel = 'Partial';   clsKind = 'teal'; }
      else if (a.classification === 'descriptive')     { clsLabel = 'Descriptive'; clsKind = 'teal'; }
      else if (a.classification === 'long_phrase')     { clsLabel = 'Long';      clsKind = 'teal'; }
      else if (a.classification === 'url')             { clsLabel = 'URL';       clsKind = 'amber'; }
      else                                             { clsLabel = a.classification || '—'; clsKind = 'amber'; }
      var issueCell = a.issue_type
        ? '<span style="color:var(--lgse-amber);font-size:11px">' + esc(a.issue_label || a.issue_type) + '</span>'
        : '<span style="color:var(--lgse-t3);font-size:11px">—</span>';
      h += '<tr>'
        + '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(a.article_title || '—') + '</td>'
        + '<td style="color:var(--lgse-t1);font-weight:500">"' + esc(a.anchor_text || '') + '"</td>'
        + '<td class="lgse-url" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(a.target_url || '') + '</td>'
        + '<td>' + badge(clsLabel, clsKind) + '</td>'
        + '<td class="r mono">' + (a.score != null ? scorePill(a.score) : '—') + '</td>'
        + '<td>' + issueCell + '</td>'
        + '<td class="r"><button class="lgse-btn-secondary" style="font-size:10px;padding:4px 9px" onclick="lgseSuggestAnchor(' + origIdx + ')">Suggest</button></td>'
      + '</tr>';
    });
    h += '</tbody></table>';
    if (anchors.length > rows.length) {
      h += '<div style="text-align:center;color:var(--lgse-t3);font-size:11px;margin-top:8px">Showing first ' + rows.length + ' of ' + anchors.length + ' anchors</div>';
    }
    bodyEl.innerHTML = h;
  };

  window.lgseRunAnchorAnalysis = function () {
    // Route is GET /api/seo/anchors/bulk-analysis (routes/api.php:1617) — InternalLinkService::bulkAnalyzeAnchors rebuilds classifications.
    var btns = document.querySelectorAll('[onclick*="lgseRunAnchorAnalysis"]');
    for (var i = 0; i < btns.length; i++) { btns[i].disabled = true; btns[i].textContent = '⏳ Analyzing…'; }
    api('GET', '/anchors/bulk-analysis').then(function () {
      window.lgseLoadAnchors();
    }).catch(function () {
      var rb = document.querySelectorAll('[onclick*="lgseRunAnchorAnalysis"]');
      for (var j = 0; j < rb.length; j++) { rb[j].disabled = false; rb[j].textContent = 'Run analysis'; }
      var bodyEl = document.getElementById('lgse-links-body');
      if (bodyEl) bodyEl.innerHTML = emptyState('⚠', 'Anchor analysis failed', 'The bulk-analysis call did not complete. Try again.', 'Retry', 'lgseRunAnchorAnalysis()');
    });
  };

  window.lgseAnchorFilter = function (which) {
    if (!window._lgseAnchorState) return;
    window._lgseAnchorState.filter = which || 'all';
    window.lgseLoadAnchorData();
  };

  window.lgseSuggestAnchor = function (idx) {
    var st = window._lgseAnchorState; if (!st || !st.anchors[idx]) return;
    var a = st.anchors[idx];
    var html = ''
      + '<div style="font-size:12px;color:var(--lgse-t2);margin-bottom:10px">Replacing: <span style="color:var(--lgse-t1);font-weight:500">"' + esc(a.anchor_text || '') + '"</span></div>'
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:10px">Target: ' + esc(a.target_url || '') + '</div>'
      + '<div id="lgse-anchor-suggest-list" style="display:flex;flex-direction:column;gap:6px;max-height:260px;overflow-y:auto">'
        + '<div style="padding:18px;text-align:center;color:var(--lgse-t3);font-size:11px">Generating suggestions…</div>'
      + '</div>';
    window.lgseShowModal('Suggest a better anchor', html, null, { hideSave: true, cancelLabel: 'Close' });

    api('POST', '/anchors/suggest-intent', {
      target_url:   a.target_url || '',
      target_title: a.target_title || '',
      target_keyword: a.target_title || '',
      source_content: '',
      intent: ''
    }).then(function (r) {
      var list = (r && r.suggestions) || [];
      var listEl = document.getElementById('lgse-anchor-suggest-list');
      if (!listEl) return;
      if (list.length === 0) {
        listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-t3);font-size:11px">No suggestions returned. Try again or refine the article context.</div>';
        return;
      }
      var inner = '';
      list.slice(0, 8).forEach(function (s) {
        var anchorTxt = s.anchor || '';
        var ctx       = s.context_sentence || '';
        var sc        = s.score != null ? s.score : '';
        inner += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px">'
          + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:' + (ctx ? '5px' : '0') + '">'
            + '<div style="color:var(--lgse-t1);font-weight:500;font-size:12px">"' + esc(anchorTxt) + '"</div>'
            + (sc !== '' ? '<div style="font-size:10.5px;color:var(--lgse-t3)">score ' + esc(sc) + '</div>' : '')
          + '</div>'
          + (ctx ? '<div style="color:var(--lgse-t3);font-size:11px;line-height:1.4">' + esc(ctx) + '</div>' : '')
        + '</div>';
      });
      listEl.innerHTML = inner;
    }).catch(function () {
      var listEl = document.getElementById('lgse-anchor-suggest-list');
      if (listEl) listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-red);font-size:11px">Suggest service unavailable. Try again later.</div>';
    });
  };
  // ── End P1-B ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-C — Gaps sub-tab. Orphan + weak (1-2 inbound) pages.
  // Backend: GET /links/gaps -> { orphans:[], weak:[], summary:{...} }
  //          GET /link-graph/unlinked-mentions?keyword=… -> [...]
  // ─────────────────────────────────────────────────────────────────────
  function loadGapsSec(body) { window.lgseLoadGaps(); }

  window._lgseGapsState = window._lgseGapsState || { orphans: [], weak: [], summary: { orphan_count: 0, weak_count: 0 } };

  window.lgseLoadGaps = function () {
    var bodyEl = document.getElementById('lgse-links-body');
    if (!bodyEl) return;
    bodyEl.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading link gaps…</div>';
    api('GET', '/links/gaps').then(function (r) {
      window._lgseGapsState = {
        orphans: (r && r.orphans) || [],
        weak:    (r && r.weak)    || [],
        summary: (r && r.summary) || { orphan_count: 0, weak_count: 0 }
      };
      window.lgseLoadGapsData();
    }).catch(function () {
      bodyEl.innerHTML = emptyState('⚠', 'Could not load gaps', 'Build the link graph first, then refresh.', 'Build graph', 'api(\'POST\',\'/link-graph/build\',{}).then(function(){lgseRefreshGaps()})');
    });
  };

  window.lgseLoadGapsData = function () {
    var bodyEl = document.getElementById('lgse-links-body');
    if (!bodyEl) return;
    var st = window._lgseGapsState || { orphans: [], weak: [], summary: { orphan_count: 0, weak_count: 0 } };
    var orphans = st.orphans || []; var weak = st.weak || [];
    var s = st.summary || { orphan_count: 0, weak_count: 0 };

    var h = ''
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">'
        + '<div style="font-size:12px;color:var(--lgse-t2)">Pages that are missing internal links — fix these to spread page authority.</div>'
        + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="lgseRefreshGaps()">Refresh</button>'
      + '</div>'
      + '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(2,1fr);margin-bottom:14px">'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Orphan pages</div><div class="lgse-kpi-val" style="color:var(--lgse-red)">' + (s.orphan_count || 0) + '</div></div>'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Weak pages (1–2 inbound)</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (s.weak_count || 0) + '</div></div>'
      + '</div>';

    if (orphans.length === 0 && weak.length === 0) {
      h += emptyState('✓', 'No link gaps found', 'Every indexed page has at least 3 inbound internal links. Run "Build graph" if you just published new content.', 'Build graph', 'api(\'POST\',\'/link-graph/build\',{}).then(function(){lgseRefreshGaps()})');
      bodyEl.innerHTML = h;
      return;
    }

    function renderTable(rows, title, kind) {
      if (rows.length === 0) return '';
      var out = '<div class="lgse-section-hdr"><span class="lgse-section-title">' + esc(title) + '</span></div>';
      out += '<table class="lgse-table" style="margin-bottom:18px">'
        + '<thead><tr>'
          + '<th>URL</th>'
          + '<th>Title</th>'
          + '<th class="r">Words</th>'
          + '<th class="r">Score</th>'
          + (kind === 'weak' ? '<th class="r">Inbound</th>' : '')
          + '<th class="r">Action</th>'
        + '</tr></thead><tbody>';
      rows.slice(0, 30).forEach(function (p, idx) {
        var origIdx = (kind === 'weak' ? rows.indexOf(p) : rows.indexOf(p));
        out += '<tr>'
          + '<td class="lgse-url" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(p.url || '—') + '</td>'
          + '<td style="color:var(--lgse-t1);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(p.title || '—') + '</td>'
          + '<td class="r mono">' + (parseInt(p.word_count, 10) || 0) + '</td>'
          + '<td class="r mono">' + (p.content_score != null ? scorePill(p.content_score) : '—') + '</td>'
          + (kind === 'weak' ? '<td class="r mono">' + (parseInt(p.inbound_count, 10) || 0) + '</td>' : '')
          + '<td class="r"><button class="lgse-btn-secondary" style="font-size:10px;padding:4px 9px" onclick="lgseGenerateLinksFor(\'' + kind + '\',' + origIdx + ')">Generate</button></td>'
        + '</tr>';
      });
      out += '</tbody></table>';
      if (rows.length > 30) out += '<div style="text-align:center;color:var(--lgse-t3);font-size:11px;margin:-10px 0 18px">Showing first 30 of ' + rows.length + '</div>';
      return out;
    }

    h += renderTable(orphans, 'Orphan pages', 'orphan');
    h += renderTable(weak,    'Weak pages',   'weak');

    bodyEl.innerHTML = h;
  };

  window.lgseRefreshGaps = function () { window.lgseLoadGaps(); };

  window.lgseGenerateLinksFor = function (kind, idx) {
    var st = window._lgseGapsState; if (!st) return;
    var pool = (kind === 'weak') ? (st.weak || []) : (st.orphans || []);
    var p = pool[idx]; if (!p) return;

    var keyword = (p.title || '').replace(/\s+\|.*$/, '').trim();
    if (keyword === '') {
      window.lgseShowModal('Generate internal links', '<div style="padding:6px 0;font-size:12px;color:var(--lgse-t3)">This page has no title to use as the keyword. Add a title or H1 first.</div>', null, { hideSave: true, cancelLabel: 'Close' });
      return;
    }

    var html = ''
      + '<div style="font-size:12px;color:var(--lgse-t2);margin-bottom:6px">Target: <span style="color:var(--lgse-t1)">' + esc(p.url || '') + '</span></div>'
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:10px">Searching pages that mention <strong>"' + esc(keyword) + '"</strong> but don\'t link here.</div>'
      + '<div id="lgse-gap-mentions-list" style="display:flex;flex-direction:column;gap:6px;max-height:300px;overflow-y:auto">'
        + '<div style="padding:18px;text-align:center;color:var(--lgse-t3);font-size:11px">Searching…</div>'
      + '</div>';
    window.lgseShowModal('Generate internal links', html, null, { hideSave: true, cancelLabel: 'Close' });

    api('GET', '/link-graph/unlinked-mentions?keyword=' + encodeURIComponent(keyword)).then(function (r) {
      var list = (r && (r.mentions || r.data || r)) || [];
      if (!Array.isArray(list)) list = [];
      var listEl = document.getElementById('lgse-gap-mentions-list');
      if (!listEl) return;
      if (list.length === 0) {
        listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-t3);font-size:11px">No source pages mention this keyword without linking. Try writing new content.</div>';
        return;
      }
      var inner = '';
      list.slice(0, 10).forEach(function (m) {
        inner += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px">'
          + '<div style="color:var(--lgse-t1);font-weight:500;font-size:12px;margin-bottom:3px">' + esc(m.title || m.source_title || '—') + '</div>'
          + '<div class="lgse-url" style="font-size:11px">' + esc(m.url || m.source_url || '') + '</div>'
        + '</div>';
      });
      listEl.innerHTML = inner;
    }).catch(function () {
      var listEl = document.getElementById('lgse-gap-mentions-list');
      if (listEl) listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-red);font-size:11px">Could not search. Build the link graph first.</div>';
    });
  };
  // ── End P1-C ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-D — Images sub-tab. Stats KPIs + progress bar + bulk button + table.
  // Backend: GET /image-summary, GET /image-issues, POST /image-issues/suggest-alt,
  //          POST /image-issues/bulk-analyze (re-scan).
  // No native dialogs. All CSS via existing vars.
  // ─────────────────────────────────────────────────────────────────────
  window._lgseImagesState = window._lgseImagesState || { summary: null, issues: [], optimizing: false, optProgress: 0, optTotal: 0, optResults: [] };

  window.lgseRenderImages = function (bodyEl) {
    bodyEl = bodyEl || document.getElementById('lgse-pages-body');
    if (!bodyEl) return;
    bodyEl.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading images…</div>';
    window.lgseLoadImagesData(bodyEl);
  };

  window.lgseLoadImagesData = function (bodyEl) {
    bodyEl = bodyEl || document.getElementById('lgse-pages-body');
    if (!bodyEl) return;
    Promise.all([
      api('GET', '/image-summary').catch(function () { return null; }),
      api('GET', '/image-issues?limit=200').catch(function () { return null; })
    ]).then(function (rs) {
      // 2026-05-13 — /image-summary returns {success:true, summary:{...}}.
      // Unwrap the .summary key first; falling through to the raw response keeps
      // any older flat-shape responses working.
      var sumResp = rs[0] || {};
      var summary = (sumResp && sumResp.summary) || sumResp || { missing_alt:0, empty_alt:0, filename_unfriendly:0, wrong_format:0, total_images:0, issues_found:0 };
      var issuesResp = rs[1] || [];
      var issues = Array.isArray(issuesResp) ? issuesResp : ((issuesResp && (issuesResp.issues || issuesResp.data)) || []);
      window._lgseImagesState.summary = summary;
      window._lgseImagesState.issues  = issues;
      window.lgseRenderImagesBody(bodyEl);
    }).catch(function () {
      bodyEl.innerHTML = emptyState('🖼', 'No image data', 'Run a scan to populate this section.', 'Re-scan images', 'api(\'POST\',\'/image-issues/bulk-analyze\',{}).then(function(){lgseRenderImages()})');
    });
  };

  window.lgseRenderImagesBody = function (bodyEl) {
    bodyEl = bodyEl || document.getElementById('lgse-pages-body');
    if (!bodyEl) return;
    var st = window._lgseImagesState;
    var s  = st.summary || {};
    var issues = st.issues || [];

    var totalImg   = parseInt(s.total_images, 10) || 0;
    var issuesCnt  = parseInt(s.issues_found,  10) || issues.length;

    var h = ''
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:8px;flex-wrap:wrap">'
        + '<div style="font-size:12px;color:var(--lgse-t2)">Image health across <strong style="color:var(--lgse-t1)">' + totalImg + '</strong> images on <strong style="color:var(--lgse-t1)">' + (parseInt(s.pages_scanned, 10) || 0) + '</strong> pages.</div>'
        + '<div style="display:flex;gap:8px">'
          + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="api(\'POST\',\'/image-issues/bulk-analyze\',{}).then(function(){lgseRenderImages()})">Re-scan</button>'
          + (st.optimizing
              ? '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px;color:var(--lgse-red);border-color:var(--lgse-red)" onclick="lgseStopOptimize()">Stop</button>'
              : '<button class="lgse-btn-primary" style="font-size:11px;padding:6px 12px" onclick="lgseBulkOptimize()">Scan images</button>'
            )
        + '</div>'
      + '</div>';

    h += '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Missing alt</div><div class="lgse-kpi-val" style="color:var(--lgse-red)">' + (s.missing_alt || 0) + '</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Empty alt</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (s.empty_alt || 0) + '</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Bad filename</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (s.filename_unfriendly || 0) + '</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Wrong format</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (s.wrong_format || 0) + '</div></div>'
      + '</div>';

    if (st.optimizing || (st.optTotal > 0 && st.optProgress < st.optTotal)) {
      var pct = st.optTotal > 0 ? Math.round((st.optProgress / st.optTotal) * 100) : 0;
      h += '<div style="margin-bottom:14px;padding:12px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">'
          + '<div style="font-size:11.5px;color:var(--lgse-t2)">Generating alt text — ' + st.optProgress + ' / ' + st.optTotal + ' (' + pct + '%)</div>'
        + '</div>'
        + '<div class="lgse-stack-bar" style="height:8px"><div style="width:' + pct + '%;background:var(--lgse-purple);transition:width .2s"></div></div>'
      + '</div>';
    } else if (st.optTotal > 0 && st.optProgress >= st.optTotal && st.optResults.length > 0) {
      h += '<div style="margin-bottom:14px;padding:10px 12px;background:rgba(20,184,166,.08);border:1px solid var(--lgse-teal);border-radius:8px;font-size:11.5px;color:var(--lgse-teal)">'
        + 'Image scan complete — ' + st.optResults.length + ' alt-text suggestions generated. Click Suggest on a row to view and copy each one.'
      + '</div>';
    }

    if (issues.length === 0) {
      if (issuesCnt === 0 && totalImg === 0) {
        h += emptyState('🖼', 'No images indexed yet', 'Click Re-scan to walk your pages and surface image issues.', 'Re-scan', 'api(\'POST\',\'/image-issues/bulk-analyze\',{}).then(function(){lgseRenderImages()})');
      } else {
        h += '<div style="padding:24px;text-align:center;color:var(--lgse-t3);background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;font-size:11.5px">No image issues found.</div>';
      }
      bodyEl.innerHTML = h;
      return;
    }

    var rows = issues.slice(0, 200);
    h += '<table class="lgse-table">'
      + '<thead><tr>'
        + '<th>Image</th>'
        + '<th>Page</th>'
        + '<th>Issue</th>'
        + '<th>Current alt</th>'
        + '<th class="r">Action</th>'
      + '</tr></thead><tbody>';
    rows.forEach(function (it, idx) {
      var origIdx = issues.indexOf(it);
      var src = it.image_src || it.src || '';
      var fn  = src ? src.split('/').pop().split('?')[0] : '—';
      var label = it.issue_label || it.issue || it.issue_type || '—';
      var kind = (it.issue_type === 'missing_alt') ? 'red' : 'amber';
      var alt = it.alt || '';
      var stored = (st.optResults || []).filter(function (x) { return x.idx === origIdx; })[0];
      var hasSuggested = !!(stored && stored.suggested);
      h += '<tr>'
        + '<td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1);font-family:var(--lgse-mono);font-size:11px" title="' + esc(src) + '">' + esc(fn) + '</td>'
        + '<td class="lgse-url" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(it.page_url || '—') + '</td>'
        + '<td>' + badge(label, kind) + '</td>'
        + '<td style="color:var(--lgse-t2);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px">' + (alt ? '"' + esc(alt) + '"' : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
        + '<td class="r"><button class="lgse-btn-secondary" style="font-size:10px;padding:4px 9px' + (hasSuggested ? ';border-color:var(--lgse-teal);color:var(--lgse-teal)' : '') + '" onclick="lgseSuggestAlt(' + origIdx + ')">' + (hasSuggested ? 'View' : 'Suggest') + '</button></td>'
      + '</tr>';
    });
    h += '</tbody></table>';
    if (issues.length > rows.length) {
      h += '<div style="text-align:center;color:var(--lgse-t3);font-size:11px;margin-top:8px">Showing first ' + rows.length + ' of ' + issues.length + ' issues</div>';
    }
    bodyEl.innerHTML = h;
  };

  window.lgseBulkOptimize = function () {
    var st = window._lgseImagesState; if (!st) return;
    var queue = (st.issues || []).filter(function (it, i) {
      if (it.issue_type !== 'missing_alt' && it.issue_type !== 'empty_alt') return false;
      it.__idx = i; return true;
    });
    if (queue.length === 0) {
      window.lgseShowModal('Scan images', '<div style="padding:6px 0;font-size:12px;color:var(--lgse-teal)">All images have alt text — no action needed ✓</div>', null, { hideSave: true, cancelLabel: 'Close' });
      return;
    }
    st.optimizing = true; st.optProgress = 0; st.optTotal = queue.length; st.optResults = [];
    window.lgseRenderImagesBody();

    function next(i) {
      st = window._lgseImagesState;
      if (!st.optimizing) {
        // Stop pressed.
        window.lgseRenderImagesBody();
        return;
      }
      if (i >= queue.length) {
        st.optimizing = false;
        window.lgseRenderImagesBody();
        return;
      }
      var it = queue[i];
      api('POST', '/image-issues/suggest-alt', {
        image_src:    it.image_src || it.src || '',
        page_context: it.page_title || it.page_url || ''
      }).then(function (r) {
        var s = (r && r.suggested_alt) || '';
        st.optResults.push({ idx: it.__idx, suggested: s, image_src: it.image_src || it.src });
      }).catch(function () { /* swallow */ }).then(function () {
        st.optProgress = i + 1;
        window.lgseRenderImagesBody();
        // Yield to keep UI responsive.
        setTimeout(function () { next(i + 1); }, 80);
      });
    }
    next(0);
  };

  window.lgseStopOptimize = function () {
    var st = window._lgseImagesState; if (!st) return;
    st.optimizing = false;
    window.lgseRenderImagesBody();
  };

  window.lgseSuggestAlt = function (idx) {
    var st = window._lgseImagesState; if (!st) return;
    var it = (st.issues || [])[idx]; if (!it) return;
    var src = it.image_src || it.src || '';
    var existing = (st.optResults || []).filter(function (x) { return x.idx === idx; })[0];
    var html = ''
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:8px">Image: <span style="color:var(--lgse-t1);font-family:var(--lgse-mono)">' + esc(src) + '</span></div>'
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:8px">Page: ' + esc(it.page_url || '') + '</div>'
      + '<div id="lgse-alt-suggest-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px">'
        + (existing ? '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px"><div style="color:var(--lgse-t1);font-size:12px;line-height:1.45">"' + esc(existing.suggested) + '"</div></div>' : '<div style="padding:14px;text-align:center;color:var(--lgse-t3);font-size:11px">Generating…</div>')
      + '</div>'
      + '<div style="font-size:11px;color:var(--lgse-t3);line-height:1.5">Copy this into your CMS\'s image alt-text field. Apply-in-place isn\'t wired yet.</div>';
    window.lgseShowModal('Suggested alt text', html, null, { hideSave: true, cancelLabel: 'Close' });
    if (existing) return;

    api('POST', '/image-issues/suggest-alt', {
      image_src:    src,
      page_context: it.page_title || it.page_url || ''
    }).then(function (r) {
      var s = (r && r.suggested_alt) || '';
      var listEl = document.getElementById('lgse-alt-suggest-list'); if (!listEl) return;
      if (!s) {
        listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-t3);font-size:11px">No suggestion returned.</div>';
        return;
      }
      st.optResults = st.optResults || [];
      if (!st.optResults.some(function (x) { return x.idx === idx; })) {
        st.optResults.push({ idx: idx, suggested: s, image_src: src });
      }
      listEl.innerHTML = '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px"><div style="color:var(--lgse-t1);font-size:12px;line-height:1.45">"' + esc(s) + '"</div></div>';
      window.lgseRenderImagesBody();
    }).catch(function () {
      var listEl = document.getElementById('lgse-alt-suggest-list');
      if (listEl) listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-red);font-size:11px">Suggest service unavailable.</div>';
    });
  };
  // ── End P1-D ──────────────────────────────────────────────────────────

  // Generic themed modal helper. Re-used by P0-10 redirect Add/Edit/Delete
  // and P0-11 Purge-all-404s. Replaces native alert/confirm/prompt.
  window.lgseShowModal = function (title, html, onSave, opts) {
    opts = opts || {};
    var existing = document.getElementById('lgse-modal-overlay');
    if (existing) existing.remove();

    var saveLabel = esc(opts.saveLabel || 'Save');
    var cancelLabel = esc(opts.cancelLabel || 'Cancel');
    var hideSave = !!opts.hideSave;

    var overlay = document.createElement('div');
    overlay.id = 'lgse-modal-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML =
      '<div style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:14px;padding:22px;min-width:380px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.5)">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">'
      +   '<div style="font-size:15px;font-weight:600;color:var(--lgse-t1)">' + esc(title) + '</div>'
      +   '<button onclick="(function(){var m=document.getElementById(\'lgse-modal-overlay\');if(m)m.remove();})()" style="background:transparent;border:none;color:var(--lgse-t3);font-size:20px;cursor:pointer;line-height:1;padding:4px">×</button>'
      + '</div>'
      + '<div id="lgse-modal-body">' + html + '</div>'
      + '<div id="lgse-modal-error" style="display:none;margin-top:10px;padding:8px 12px;background:rgba(239,68,68,.08);border:1px solid var(--lgse-red);border-radius:6px;color:var(--lgse-red);font-size:11.5px"></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;padding-top:14px;border-top:1px solid var(--lgse-border)">'
      +   '<button onclick="(function(){var m=document.getElementById(\'lgse-modal-overlay\');if(m)m.remove();})()" class="lgse-btn-secondary" style="font-size:12px;padding:8px 16px">' + cancelLabel + '</button>'
      +   (hideSave ? '' : '<button id="lgse-modal-save" class="lgse-btn-primary" style="font-size:12px;padding:8px 16px">' + saveLabel + '</button>')
      + '</div>'
      + '</div>';
    document.body.appendChild(overlay);
    if (!hideSave) {
      document.getElementById('lgse-modal-save').onclick = function () { onSave(overlay); };
    }
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
  };

  // Inline-error helper for the open modal. Replaces native alert().
  function lgseModalError(msg) {
    var el = document.getElementById('lgse-modal-error');
    if (!el) return;
    el.textContent = msg;
    el.style.display = 'block';
  }
  function lgseClearModalError() {
    var el = document.getElementById('lgse-modal-error');
    if (el) { el.textContent = ''; el.style.display = 'none'; }
  }

  // ── P0-9: Outbound links ─────────────────────────────────────────────

  window.lgseLoadOutbound = function () {
    var body = document.getElementById('lgse-links-body');
    if (!body) return;
    body.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:8px">'
      +   '<div style="display:flex;gap:8px">'
      +     '<button class="lgse-btn-primary" id="lgse-ob-scan-btn" onclick="lgseScanOutbound(this)" style="font-size:11.5px;padding:7px 14px">Scan outbound links</button>'
      +     '<button class="lgse-btn-secondary" onclick="lgseCheckBroken(this)" style="font-size:11px;padding:7px 14px">Check broken</button>'
      +   '</div>'
      + '</div>'
      + '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">'
      +   '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Total</div><div class="lgse-kpi-val" id="lgse-ob-total">—</div></div>'
      +   '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Broken</div><div class="lgse-kpi-val lgse-dn" id="lgse-ob-broken">—</div></div>'
      +   '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Unchecked</div><div class="lgse-kpi-val" id="lgse-ob-unchecked">—</div></div>'
      +   '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Healthy</div><div class="lgse-kpi-val lgse-up" id="lgse-ob-healthy">—</div></div>'
      + '</div>'
      + '<div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
      +   '<input id="lgse-ob-search" placeholder="Search domain…" oninput="lgseFilterOBTable(this.value)" style="flex:1;min-width:180px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 12px;font-size:11.5px;color:var(--lgse-t1)">'
      +   ['all','broken','unchecked'].map(function (f) {
            var lbl = f.charAt(0).toUpperCase() + f.slice(1);
            var active = f === 'all';
            var bg = active ? 'rgba(108,92,231,0.08)' : 'var(--lgse-bg2)';
            var bd = active ? 'var(--lgse-purple)' : 'var(--lgse-border)';
            var c  = active ? 'var(--lgse-purple)' : 'var(--lgse-t3)';
            return '<span class="lgse-fp" onclick="lgseOBFilter(\'' + f + '\', this)" style="background:' + bg + ';border:1px solid ' + bd + ';border-radius:20px;padding:4px 12px;font-size:10.5px;color:' + c + ';cursor:pointer;transition:all .12s">' + lbl + '</span>';
          }).join('')
      + '</div>'
      + '<div id="lgse-ob-table">Loading…</div>';
    window.lgseLoadOutboundData();
  };

  window.lgseLoadOutboundData = function () {
    api('GET', '/outbound').then(function (d) {
      var links = (d && (d.links || d.data)) || (Array.isArray(d) ? d : []);
      var broken = 0, unchecked = 0;
      links.forEach(function (l) {
        if (l.is_broken || l.http_status === 404 || l.status === 'broken') broken++;
        if (!l.last_checked && l.status !== 'ok' && l.status !== 'broken') unchecked++;
      });
      var healthy = links.length - broken - unchecked;

      var t  = document.getElementById('lgse-ob-total');
      var b  = document.getElementById('lgse-ob-broken');
      var u  = document.getElementById('lgse-ob-unchecked');
      var hl = document.getElementById('lgse-ob-healthy');
      if (t)  t.textContent  = links.length;
      if (b)  b.textContent  = broken;
      if (u)  u.textContent  = unchecked;
      if (hl) hl.textContent = healthy;

      var table = document.getElementById('lgse-ob-table');
      if (!table) return;
      if (!links.length) {
        table.innerHTML = emptyState('⟡', 'No outbound links scanned', 'Click "Scan outbound links" to find every external link on your site.', 'Scan now', 'lgseScanOutbound(null)');
        return;
      }

      var headers = ['Domain', 'URL', 'Status', 'Source page', 'Last checked', ''];
      var thead = '<thead><tr>' + headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead>';

      var rows = links.map(function (l) {
        var st = l.http_status || 0;
        var stColor = st === 200 ? 'teal' : st === 404 ? 'red' : (st > 300 && st < 400) ? 'blue' : 'muted';
        var stLabel = st ? String(st) : (l.status || 'unchecked');
        var isBroken = l.is_broken || st === 404 || l.status === 'broken';
        var notChecked = !l.last_checked && l.status !== 'ok' && l.status !== 'broken';
        var rowStatus = isBroken ? 'broken' : notChecked ? 'unchecked' : 'ok';
        var domain = l.domain || (l.target_url || l.url || '').replace(/^https?:\/\//, '').split('/')[0];
        var url = l.target_url || l.url || '';
        var sourceUrl = l.source_url || '';
        return '<tr class="lgse-ob-row" data-domain="' + esc(domain) + '" data-status="' + rowStatus + '">'
          + '<td class="mono" style="color:var(--lgse-t2)">' + esc(domain) + '</td>'
          + '<td class="lgse-url" title="' + esc(url) + '">' + esc(url.replace(/^https?:\/\//, '').slice(0, 50)) + '</td>'
          + '<td>' + badge(stLabel, stColor) + '</td>'
          + '<td class="lgse-url" title="' + esc(sourceUrl) + '">' + esc(sourceUrl.replace(/^https?:\/\//, '').slice(0, 40)) + '</td>'
          + '<td style="font-size:10px;color:var(--lgse-t3)">' + (l.last_checked ? new Date(l.last_checked).toLocaleDateString() : 'Never') + '</td>'
          + '<td><div style="display:flex;gap:4px">'
          +   '<button onclick="lgseRecheckLink(' + (l.id || 0) + ', this)" style="background:transparent;color:var(--lgse-purple);border:1px solid rgba(108,92,231,.3);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Recheck</button>'
          +   (isBroken ? '<button onclick="lgseMarkLinkOK(' + (l.id || 0) + ', this)" style="background:transparent;color:var(--lgse-t3);border:1px solid var(--lgse-border);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Mark OK</button>' : '')
          + '</div></td>'
          + '</tr>';
      }).join('');

      table.innerHTML = '<table class="lgse-table">' + thead + '<tbody>' + rows + '</tbody></table>';
    }).catch(function () {
      var t = document.getElementById('lgse-ob-table');
      if (t) t.innerHTML = emptyState('⟡', 'No outbound data', 'Try a fresh scan.');
    });
  };

  window.lgseScanOutbound = function (btn) {
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Scanning…'; }
    api('POST', '/scan-outbound', {}).then(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Scan outbound links'; }
      window.lgseLoadOutboundData();
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Scan outbound links'; }
    });
  };

  window.lgseCheckBroken = function (btn) {
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Checking…'; }
    api('POST', '/outbound-check', {}).then(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Check broken'; }
      window.lgseLoadOutboundData();
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Check broken'; }
    });
  };

  window.lgseRecheckLink = function (id, btn) {
    if (!id) return;
    btn.disabled = true; btn.textContent = '⏳';
    api('POST', '/scan-outbound/' + id, {}).then(function () {
      btn.disabled = false; btn.textContent = 'Recheck';
      window.lgseLoadOutboundData();
    }).catch(function () { btn.disabled = false; btn.textContent = 'Recheck'; });
  };

  // Mark OK — backend route NOT implemented yet (PATCH /outbound/{id}).
  // Catches the 404 gracefully so the button doesn't appear broken.
  window.lgseMarkLinkOK = function (id, btn) {
    if (!id) return;
    btn.disabled = true; btn.textContent = '⏳';
    api('PATCH', '/outbound/' + id, { status: 'ok' }).then(function (resp) {
      btn.disabled = false; btn.textContent = 'Mark OK';
      if (typeof window.showToast === 'function') {
        if (resp && resp.success === false) window.showToast('Mark-OK endpoint not yet wired on the backend.', 'info');
      }
      window.lgseLoadOutboundData();
    }).catch(function () {
      btn.disabled = false; btn.textContent = 'Mark OK';
      if (typeof window.showToast === 'function') window.showToast('Mark-OK endpoint not yet wired on the backend.', 'info');
    });
  };

  window.lgseFilterOBTable = function (q) {
    q = (q || '').toLowerCase();
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-ob-row'), function (row) {
      var domain = (row.getAttribute('data-domain') || '').toLowerCase();
      row.style.display = !q || domain.indexOf(q) > -1 ? '' : 'none';
    });
  };

  window.lgseOBFilter = function (status, el) {
    Array.prototype.forEach.call(document.querySelectorAll('#lgse-links-body span[onclick*="lgseOBFilter"]'), function (p) {
      p.style.background = 'var(--lgse-bg2)';
      p.style.borderColor = 'var(--lgse-border)';
      p.style.color = 'var(--lgse-t3)';
    });
    if (el) {
      el.style.background = 'rgba(108,92,231,0.08)';
      el.style.borderColor = 'var(--lgse-purple)';
      el.style.color = 'var(--lgse-purple)';
    }
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-ob-row'), function (row) {
      var rs = row.getAttribute('data-status') || 'ok';
      row.style.display = (status === 'all' || rs === status) ? '' : 'none';
    });
  };

  // ── P0-10: Redirects + P0-11: 404 Log ────────────────────────────────

  window.lgseLoadRedirects = function () {
    var body = document.getElementById('lgse-links-body');
    if (!body) return;
    body.innerHTML =
      '<div style="display:flex;gap:0;border-bottom:1px solid var(--lgse-border);margin-bottom:14px">'
      +   '<div onclick="lgseRedirectTab(0,this)" style="padding:7px 14px;font-size:11px;cursor:pointer;border-bottom:2px solid var(--lgse-purple);color:var(--lgse-purple);transition:color .12s">Redirects</div>'
      +   '<div onclick="lgseRedirectTab(1,this)" style="padding:7px 14px;font-size:11px;cursor:pointer;border-bottom:2px solid transparent;color:var(--lgse-t3);transition:color .12s">404 Log</div>'
      + '</div>'
      + '<div id="lgse-redir-content"></div>';
    window.lgseLoadRedirectsList();
  };

  window.lgseRedirectTab = function (idx, el) {
    var tabs = document.querySelectorAll('#lgse-links-body [onclick*="lgseRedirectTab"]');
    Array.prototype.forEach.call(tabs, function (t, i) {
      t.style.borderBottomColor = (i === idx) ? 'var(--lgse-purple)' : 'transparent';
      t.style.color = (i === idx) ? 'var(--lgse-purple)' : 'var(--lgse-t3)';
    });
    if (idx === 0) window.lgseLoadRedirectsList();
    else window.lgseLoad404Log();
  };

  window.lgseLoadRedirectsList = function () {
    var content = document.getElementById('lgse-redir-content');
    if (!content) return;
    content.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">'
      +   '<div style="font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em">Redirect rules</div>'
      +   '<button class="lgse-btn-primary" onclick="lgseAddRedirect()" style="font-size:11.5px;padding:7px 14px">+ Add redirect</button>'
      + '</div>'
      + '<div id="lgse-redir-table">Loading…</div>';

    api('GET', '/redirects').then(function (d) {
      var rows = (d && (d.redirects || d.data)) || (Array.isArray(d) ? d : []);
      var el = document.getElementById('lgse-redir-table');
      if (!el) return;
      if (!rows.length) {
        el.innerHTML = emptyState('↪', 'No redirects yet', 'Add redirect rules to manage URL changes.', '+ Add redirect', 'lgseAddRedirect()');
        return;
      }
      var headers = ['From URL', 'To URL', 'Type', 'Hits', 'Status', ''];
      var thead = '<thead><tr>' + headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead>';
      var rowHtml = rows.map(function (r) {
        var isActive = (r.is_active === undefined ? true : !!r.is_active) && r.status !== 'inactive';
        // Backend stores `source_url`/`target_url`; legacy fallbacks kept.
        var srcRaw = r.source_url || r.from_url || r.from || r.source || '';
        var tgtRaw = r.target_url || r.to_url   || r.to   || r.target || '';
        var fromUrl = srcRaw.replace(/^https?:\/\//, '');
        var toUrl   = tgtRaw.replace(/^https?:\/\//, '');
        var type    = String(r.type || r.code || 301);
        var rPayload = encodeURIComponent(JSON.stringify({ id: r.id, from_url: srcRaw, to_url: tgtRaw, type: type }));
        return '<tr>'
          + '<td class="lgse-url" title="' + esc(srcRaw) + '">' + esc(fromUrl) + '</td>'
          + '<td class="lgse-url" title="' + esc(tgtRaw) + '">' + esc(toUrl) + '</td>'
          + '<td>' + badge(type, 'blue') + '</td>'
          + '<td class="mono">' + (r.hit_count || r.hits || 0) + '</td>'
          + '<td>' + badge(isActive ? 'active' : 'inactive', isActive ? 'teal' : 'muted') + '</td>'
          + '<td><div style="display:flex;gap:4px">'
          +   '<button onclick="lgseEditRedirect(\'' + rPayload + '\')" style="background:transparent;color:var(--lgse-purple);border:1px solid rgba(108,92,231,.3);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Edit</button>'
          +   '<button onclick="lgseDeleteRedirect(' + (r.id || 0) + ')" style="background:transparent;color:var(--lgse-t3);border:1px solid var(--lgse-border);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Delete</button>'
          + '</div></td>'
          + '</tr>';
      }).join('');
      el.innerHTML = '<table class="lgse-table">' + thead + '<tbody>' + rowHtml + '</tbody></table>';
    }).catch(function () {
      var el = document.getElementById('lgse-redir-table');
      if (el) el.innerHTML = emptyState('⟡', 'Could not load redirects', 'Try refreshing.');
    });
  };

  function lgseRedirectFormHtml(r) {
    r = r || {};
    var label = 'display:block;font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px';
    var input = 'width:100%;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:8px 12px;font-size:11.5px;color:var(--lgse-t1);outline:none;margin-bottom:12px;box-sizing:border-box';
    return '<label style="' + label + '">From URL</label>'
      + '<input id="lgse-redir-from" type="text" value="' + esc(r.from_url || '') + '" placeholder="https://yoursite.com/old-url" style="' + input + '">'
      + '<label style="' + label + '">To URL</label>'
      + '<input id="lgse-redir-to" type="text" value="' + esc(r.to_url || '') + '" placeholder="https://yoursite.com/new-url" style="' + input + '">'
      + '<label style="' + label + '">Type</label>'
      + '<select id="lgse-redir-type" style="' + input + '">'
      +   '<option value="301"' + ((r.type || '301') === '301' ? ' selected' : '') + '>301 — Permanent</option>'
      +   '<option value="302"' + (r.type === '302' ? ' selected' : '') + '>302 — Temporary</option>'
      + '</select>';
  }

  window.lgseAddRedirect = function (preFrom) {
    var r = preFrom ? { from_url: preFrom } : {};
    window.lgseShowModal('Add redirect', lgseRedirectFormHtml(r), function (overlay) {
      lgseClearModalError();
      var from = (document.getElementById('lgse-redir-from').value || '').trim();
      var to   = (document.getElementById('lgse-redir-to').value || '').trim();
      var type = document.getElementById('lgse-redir-type').value || '301';
      if (!from || !to)  { lgseModalError('Both URLs are required.'); return; }
      if (from === to)   { lgseModalError('From and To URLs must differ.'); return; }
      api('POST', '/redirects', { from_url: from, to_url: to, type: type }).then(function () {
        overlay.remove();
        window.lgseLoadRedirectsList();
      }).catch(function () { lgseModalError('Failed to save redirect. Please try again.'); });
    });
  };

  // Edit redirect — backend PATCH /redirects/{id} not implemented yet.
  // We attempt the PATCH; on failure we delete + recreate as a fallback so the user-visible behaviour still works.
  window.lgseEditRedirect = function (payload) {
    var r = {};
    try { r = JSON.parse(decodeURIComponent(payload)); } catch (e) { r = {}; }
    window.lgseShowModal('Edit redirect', lgseRedirectFormHtml(r), function (overlay) {
      lgseClearModalError();
      var from = (document.getElementById('lgse-redir-from').value || '').trim();
      var to   = (document.getElementById('lgse-redir-to').value || '').trim();
      var type = document.getElementById('lgse-redir-type').value || '301';
      if (!from || !to)  { lgseModalError('Both URLs are required.'); return; }
      if (from === to)   { lgseModalError('From and To URLs must differ.'); return; }
      // Try PATCH first (will 404 today — backend route not yet shipped).
      api('PATCH', '/redirects/' + (r.id || 0), { from_url: from, to_url: to, type: type }).then(function (resp) {
        if (resp && resp.success !== false) {
          overlay.remove(); window.lgseLoadRedirectsList(); return;
        }
        // Fallback: delete + recreate.
        return api('DELETE', '/redirects/' + (r.id || 0), {}).then(function () {
          return api('POST', '/redirects', { from_url: from, to_url: to, type: type });
        }).then(function () { overlay.remove(); window.lgseLoadRedirectsList(); });
      }).catch(function () {
        // Same delete + recreate fallback if PATCH errored entirely.
        api('DELETE', '/redirects/' + (r.id || 0), {}).then(function () {
          return api('POST', '/redirects', { from_url: from, to_url: to, type: type });
        }).then(function () {
          overlay.remove(); window.lgseLoadRedirectsList();
        }).catch(function () { lgseModalError('Failed to update redirect. Please try again.'); });
      });
    });
  };

  window.lgseDeleteRedirect = function (id) {
    if (!id) return;
    window.lgseShowModal('Delete redirect',
      '<p style="color:var(--lgse-t2);font-size:12.5px;line-height:1.6">Delete this redirect rule? This cannot be undone.</p>',
      function (overlay) {
        api('DELETE', '/redirects/' + id, {}).then(function () {
          overlay.remove(); window.lgseLoadRedirectsList();
        }).catch(function () { overlay.remove(); window.lgseLoadRedirectsList(); });
      },
      { saveLabel: 'Delete' }
    );
  };

  // ── P0-11: 404 log ──

  window.lgseLoad404Log = function () {
    var content = document.getElementById('lgse-redir-content');
    if (!content) return;
    content.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">'
      +   '<div style="font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em">404 error log</div>'
      +   '<button onclick="lgsePurge404s()" style="background:transparent;color:var(--lgse-red);border:1px solid rgba(239,68,68,.3);border-radius:7px;padding:6px 14px;font-size:11px;cursor:pointer">Purge all</button>'
      + '</div>'
      + '<div id="lgse-404-table">Loading…</div>';

    api('GET', '/404-log').then(function (d) {
      var rows = (d && (d.entries || d.data || d.log)) || (Array.isArray(d) ? d : []);
      var el = document.getElementById('lgse-404-table');
      if (!el) return;
      if (!rows.length) {
        el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--lgse-t3);font-size:11px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px">✓ No 404 errors logged — that\'s a good sign.</div>';
        return;
      }
      var headers = ['URL', 'Hits', 'Last seen', 'Referrer', ''];
      var thead = '<thead><tr>' + headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead>';
      var rowHtml = rows.map(function (r) {
        var url = r.url || r.path || '';
        var safeUrl = url.replace(/'/g, "\\'");
        return '<tr>'
          + '<td class="lgse-url" title="' + esc(url) + '">' + esc(url) + '</td>'
          + '<td class="mono" style="color:var(--lgse-red)">' + (r.hit_count || r.hits || 1) + '</td>'
          + '<td style="font-size:10px;color:var(--lgse-t3)">' + (r.last_seen || r.updated_at ? new Date(r.last_seen || r.updated_at).toLocaleDateString() : '—') + '</td>'
          + '<td class="lgse-url" style="font-size:10px;color:var(--lgse-t3)">' + esc(r.referrer || '—') + '</td>'
          + '<td><div style="display:flex;gap:4px">'
          +   '<button onclick="lgseConvert404(\'' + safeUrl + '\')" style="background:var(--lgse-purple);color:white;border:none;border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Fix →</button>'
          +   '<button onclick="lgseDelete404(' + (r.id || 0) + ')" style="background:transparent;color:var(--lgse-t3);border:1px solid var(--lgse-border);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">✕</button>'
          + '</div></td>'
          + '</tr>';
      }).join('');
      el.innerHTML = '<table class="lgse-table">' + thead + '<tbody>' + rowHtml + '</tbody></table>';
    }).catch(function () {
      var el = document.getElementById('lgse-404-table');
      if (el) el.innerHTML = emptyState('⟡', 'Could not load 404 log', 'Try refreshing.');
    });
  };

  window.lgseConvert404 = function (url) { window.lgseAddRedirect(url); };

  // DELETE /404-log/{id} — backend route NOT implemented yet.
  // Fires the request anyway and shows a graceful toast on 404.
  window.lgseDelete404 = function (id) {
    if (!id) return;
    api('DELETE', '/404-log/' + id, {}).then(function () {
      window.lgseLoad404Log();
    }).catch(function () {
      if (typeof window.showToast === 'function') window.showToast('Delete-404 endpoint not yet wired on the backend.', 'info');
    });
  };

  window.lgsePurge404s = function () {
    window.lgseShowModal('Purge all 404 logs',
      '<p style="color:var(--lgse-t2);font-size:12.5px;line-height:1.6">Delete every 404 error log entry? This cannot be undone.</p>',
      function (overlay) {
        api('DELETE', '/404-log', {}).then(function () {
          overlay.remove(); window.lgseLoad404Log();
        }).catch(function () {
          overlay.remove();
          if (typeof window.showToast === 'function') window.showToast('Purge endpoint not yet wired on the backend.', 'info');
        });
      },
      { saveLabel: 'Purge all' }
    );
  };

  // ── Tab 6 — Topics ─────────────────────────────────────────────────────
  function renderTopics(el) {
    el.innerHTML = pageTitle('Topics', 'Semantic clusters built from your indexed content. Track topic authority and gaps.')
      + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">'
        + '<button class="lgse-btn-primary" id="lgse-rebuild-clusters-btn" onclick="lgseRunClusters(this)">Rebuild clusters</button>'
      + '</div>'
      + '<div style="font-size:10px;color:var(--lgse-t3);margin-bottom:14px">Groups your indexed pages by topic using semantic similarity. Run after adding new content.</div>'
      + '<div id="lgse-topics-body">Loading…</div>';
    window.lgseLoadTopics();
  }

  window.lgseLoadTopics = function () {
    var body = document.getElementById('lgse-topics-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading topics…</div>';
    Promise.all([
      api('GET', '/topics/authority').catch(function () { return null; }),
      api('GET', '/clusters/gaps').catch(function () { return null; }),
    ]).then(function (r) {
      var topics = (r[0] && (r[0].topics || r[0].data)) || []; var gaps = (r[1] && (r[1].gaps || r[1].data)) || [];
      if (topics.length === 0) {
        body.innerHTML = emptyState('⬡', 'Not enough pages to cluster', 'Topics clustering needs at least 3 indexed pages with overlapping content. Scan more pages first.', 'Scan Pages', 'lgseSwitchTab(\'pages\')');
        return;
      }
      var h = '<table class="lgse-table" style="margin-bottom:16px"><thead><tr><th>Topic</th><th class="r">Authority</th><th class="r">Pages</th><th class="r">Avg score</th><th>Completeness</th><th>Pillar</th></tr></thead><tbody>';
      topics.slice(0, 30).forEach(function (t) {
        var auth = parseInt(t.authority || 0, 10);
        var comp = parseInt(t.completeness_score || 0, 10);
        h += '<tr><td style="font-weight:500;color:var(--lgse-t1)">' + esc(t.topic || '—') + '</td>';
        h += '<td class="mono">' + scorePill(auth) + '</td>';
        h += '<td class="mono">' + (parseInt(t.member_count || 0, 10) || 0) + '</td>';
        h += '<td class="mono">' + (parseInt(t.avg_content_score || 0, 10) || 0) + '</td>';
        h += '<td style="width:120px"><div class="lgse-stack-bar"><div style="height:100%;background:' + scoreColor(comp) + ';width:' + comp + '%"></div></div><div style="font-size:9px;color:var(--lgse-t3);margin-top:2px;font-family:var(--lgse-mono)">' + comp + '/100</div></td>';
        h += '<td>' + (t.has_pillar ? badge('✓ pillar', 'teal') : badge('no pillar', 'red')) + '</td></tr>';
      });
      h += '</tbody></table>';
      if (gaps.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Cluster gaps</span><span style="font-size:10px;color:var(--lgse-t3)">' + gaps.length + ' total</span></div>';
        h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:8px">';
        gaps.slice(0, 8).forEach(function (g) {
          h += '<div class="lgse-cat-item" style="display:block"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px"><span style="font-size:12px;color:var(--lgse-t1);font-weight:600">' + esc(g.topic || '') + '</span><span style="display:flex;gap:4px">';
          (g.gaps || []).forEach(function (gg) { h += badge(String(gg).replace(/_/g, ' '), 'amber'); });
          h += '</span></div><div style="font-size:10.5px;color:var(--lgse-t2)">' + esc(g.recommendation || '') + '</div></div>';
        });
        h += '</div>';
      }
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load topics', 'Try refreshing.'); });
  };

  window.lgseRunClusters = function (btn) {
    var b = btn || document.getElementById('lgse-rebuild-clusters-btn');
    var orig = b ? b.textContent : '';
    if (b) { b.disabled = true; b.textContent = '⏳ Building…'; }
    api('POST', '/clusters/build', {}).then(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Rebuild clusters'; }
      window.lgseLoadTopics();
    }).catch(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Rebuild clusters'; }
      var body = document.getElementById('lgse-topics-body');
      if (body) body.innerHTML = emptyState('⬡', 'Not enough pages to cluster', 'Topics clustering needs at least 3 indexed pages with overlapping content. Scan more pages first.', 'Scan Pages', 'lgseSwitchTab(\'pages\')');
    });
  };

  // ── Tab 7 — Competitors ────────────────────────────────────────────────
  // Country list for the location selector — DataForSEO accepts these names.
  var LGSE_CMP_COUNTRIES = [
    { v: 'United Arab Emirates', l: '🇦🇪 UAE' },
    { v: 'Saudi Arabia',          l: '🇸🇦 Saudi Arabia' },
    { v: 'Qatar',                 l: '🇶🇦 Qatar' },
    { v: 'Kuwait',                l: '🇰🇼 Kuwait' },
    { v: 'Bahrain',               l: '🇧🇭 Bahrain' },
    { v: 'Oman',                  l: '🇴🇲 Oman' },
    { v: 'Egypt',                 l: '🇪🇬 Egypt' },
    { v: 'Jordan',                l: '🇯🇴 Jordan' },
    { v: 'Lebanon',               l: '🇱🇧 Lebanon' },
    { v: 'United Kingdom',        l: '🇬🇧 UK' },
    { v: 'Germany',               l: '🇩🇪 Germany' },
    { v: 'France',                l: '🇫🇷 France' },
    { v: 'Spain',                 l: '🇪🇸 Spain' },
    { v: 'Italy',                 l: '🇮🇹 Italy' },
    { v: 'Netherlands',           l: '🇳🇱 Netherlands' },
    { v: 'Switzerland',           l: '🇨🇭 Switzerland' },
    { v: 'Austria',               l: '🇦🇹 Austria' },
    { v: 'United States',         l: '🇺🇸 United States' },
    { v: 'Canada',                l: '🇨🇦 Canada' },
    { v: 'Australia',             l: '🇦🇺 Australia' },
    { v: 'Singapore',             l: '🇸🇬 Singapore' },
    { v: 'India',                 l: '🇮🇳 India' },
    { v: 'Pakistan',              l: '🇵🇰 Pakistan' }
  ];

  // Module-level state for competitors tab — sort + last results + last keyword.
  // Lives on window so the sort/render handlers can reach it without closure.
  window._lgseCmpState = window._lgseCmpState || { results: [], sortCol: 'rank', sortDir: 'asc', keyword: '' };

  function renderCompetitors(el) {
    var helpHtml = ''
      + '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid var(--lgse-purple);border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:16px">'
        + '<div style="font-size:9px;font-weight:600;color:var(--lgse-purple);text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">How to use Competitors</div>'
        + '<div style="font-size:11.5px;color:var(--lgse-t2);line-height:1.65">'
          + '<strong style="color:var(--lgse-t1)">Analyze a keyword</strong> to see the top 10 pages currently ranking for it. Compare their word count and domain to understand what it takes to rank. '
          + '<strong style="color:var(--lgse-t1)">Compare your page</strong> to see how your content stacks up against the competition. '
          + '<strong style="color:var(--lgse-t1)">Find content gaps</strong> to discover topics your competitors cover that you don\'t.'
        + '</div>'
      + '</div>';

    var savedCountry = localStorage.getItem('lgse_cmp_country') || 'United Arab Emirates';
    var locOpts = LGSE_CMP_COUNTRIES.map(function (c) {
      return '<option value="' + c.v + '"' + (c.v === savedCountry ? ' selected' : '') + '>' + c.l + '</option>';
    }).join('');

    el.innerHTML = pageTitle('Competitors', 'Top 10 SERP competitors per keyword + AI-driven content gap analysis.')
      + helpHtml
      + '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:14px;margin-bottom:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:2px">Analyze a keyword</div>'
        + '<div style="font-size:10.5px;color:var(--lgse-t3);margin-bottom:10px">See who ranks in the top 10 for any search term in your target market.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap">'
          + '<input id="lgse-cmp-kw" type="text" placeholder="e.g. digital marketing dubai" style="flex:1;min-width:240px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px">'
          + '<select id="lgse-cmp-loc" onchange="localStorage.setItem(\'lgse_cmp_country\', this.value)" style="min-width:200px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px;cursor:pointer">' + locOpts + '</select>'
          + '<button class="lgse-btn-primary" onclick="lgseCmpAnalyze()">Analyze</button>'
        + '</div>'
        + '<div id="lgse-cmp-results" style="margin-top:14px"></div>'
      + '</div>'
      + '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:2px">Compare your page vs SERP</div>'
        + '<div style="font-size:10.5px;color:var(--lgse-t3);margin-bottom:10px">Check if your page has enough content to compete for a keyword.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap">'
          + '<input id="lgse-cmp-url" type="text" placeholder="Your URL" style="flex:1;min-width:240px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px">'
          + '<input id="lgse-cmp-kw2" type="text" placeholder="Target keyword" style="width:240px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px">'
          + '<button class="lgse-btn-secondary" onclick="lgseCmpCompare()">Compare</button>'
        + '</div>'
        + '<div id="lgse-cmp-cmp" style="margin-top:14px"></div>'
      + '</div>';
  }
  window.lgseCmpAnalyze = function () {
    var kw = document.getElementById('lgse-cmp-kw');
    var loc = document.getElementById('lgse-cmp-loc');
    var box = document.getElementById('lgse-cmp-results');
    if (!kw || !box || !kw.value.trim()) return;
    var keyword = kw.value.trim();
    var location = (loc && loc.value) || 'United Arab Emirates';
    if (loc && loc.value) localStorage.setItem('lgse_cmp_country', loc.value);
    box.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);font-size:11px">Analyzing…</div>';
    api('POST', '/competitors/analyze', { keyword: keyword, location: location }).then(function (d) {
      var arr = (d && d.competitors) || [];
      if (arr.length === 0) { box.innerHTML = emptyState('·', 'No SERP data', 'DataForSEO may not be configured for this workspace.'); return; }
      // Persist results + keyword so the gap → Sarah flow can reference them.
      window._lgseCmpState.results = arr;
      window._lgseCmpState.keyword = keyword;
      window._lgseCmpState.sortCol = 'rank';
      window._lgseCmpState.sortDir = 'asc';
      box.innerHTML = '<div id="lgse-cmp-table"></div>'
        + '<div style="margin-top:10px"><button class="lgse-btn-secondary" onclick="lgseCmpGaps()">Find content gaps with AI →</button></div>'
        + '<div id="lgse-cmp-gaps"></div>';
      window.lgseCmpRenderTable(arr);
    }).catch(function () { box.innerHTML = emptyState('⚠', 'Analyze failed', 'Try again.'); });
  };

  window.lgseCmpRenderTable = function (rows) {
    var st = window._lgseCmpState;
    var sorted = (rows || []).slice().sort(function (a, b) {
      var av, bv;
      if (st.sortCol === 'rank') {
        av = parseInt(a.rank || a.position || 99, 10);
        bv = parseInt(b.rank || b.position || 99, 10);
        return st.sortDir === 'asc' ? av - bv : bv - av;
      }
      if (st.sortCol === 'words') {
        av = parseInt(a.est_word_count || a.word_count || 0, 10);
        bv = parseInt(b.est_word_count || b.word_count || 0, 10);
        return st.sortDir === 'asc' ? av - bv : bv - av;
      }
      av = (a[st.sortCol] || '').toString().toLowerCase();
      bv = (b[st.sortCol] || '').toString().toLowerCase();
      return st.sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
    });

    function sortHdr(col, label, extra) {
      var ind = st.sortCol === col ? (st.sortDir === 'asc' ? ' ↑' : ' ↓') : '';
      var align = extra && extra.right ? ' class="r"' : '';
      return '<th' + align + ' onclick="lgseCmpSort(\'' + col + '\')" style="cursor:pointer;user-select:none">' + label + ind + '</th>';
    }

    var h = '<table class="lgse-table"><thead><tr>'
      + sortHdr('rank',   'Rank')
      + sortHdr('title',  'Title')
      + sortHdr('domain', 'Domain')
      + sortHdr('words',  'Words', { right: true })
      + '</tr></thead><tbody>';
    sorted.forEach(function (c) {
      var rank = parseInt(c.rank || c.position || 0, 10);
      var rankCol = rank > 0 && rank <= 3 ? 'var(--lgse-amber)' : rank > 0 && rank <= 10 ? 'var(--lgse-teal)' : 'var(--lgse-t3)';
      var wc = parseInt(c.est_word_count || c.word_count || 0, 10);
      var wcCol = wc > 2000 ? 'var(--lgse-teal)' : wc > 800 ? 'var(--lgse-t1)' : wc > 0 ? 'var(--lgse-amber)' : 'var(--lgse-t3)';
      h += '<tr>'
        + '<td class="mono" style="color:' + rankCol + ';font-weight:700">#' + (rank || '?') + '</td>'
        + '<td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(c.title || '—') + '</td>'
        + '<td style="color:var(--lgse-t2);font-size:10.5px">' + esc(c.domain || '—') + '</td>'
        + '<td class="mono r" style="color:' + wcCol + '">' + (wc > 0 ? wc.toLocaleString() : '—') + '</td>'
        + '</tr>';
    });
    h += '</tbody></table>';
    var tableEl = document.getElementById('lgse-cmp-table');
    if (tableEl) tableEl.innerHTML = h;
  };

  window.lgseCmpSort = function (col) {
    var st = window._lgseCmpState;
    if (st.sortCol === col) {
      st.sortDir = st.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      st.sortCol = col;
      st.sortDir = (col === 'rank') ? 'asc' : 'desc';
    }
    window.lgseCmpRenderTable(st.results);
  };
  window.lgseCmpGaps = function () {
    var kw = document.getElementById('lgse-cmp-kw');
    var mount = document.getElementById('lgse-cmp-gaps');
    if (!kw || !mount) return;
    var keyword = kw.value.trim();
    window._lgseCmpState.keyword = keyword;
    mount.innerHTML = '<div style="padding:10px;color:var(--lgse-t3);font-size:11px">Asking AI…</div>';
    api('POST', '/competitors/gaps', { keyword: keyword }).then(function (r) {
      var gaps = (r && r.gaps) || [];
      if (gaps.length === 0) { mount.innerHTML = '<div style="padding:10px;color:var(--lgse-t3);font-size:11px">No gaps detected.</div>'; return; }
      var h = '<div class="lgse-section-hdr" style="margin-top:10px"><span class="lgse-section-title">AI-detected content gaps</span></div>';
      h += '<table class="lgse-table"><thead><tr><th>Topic</th><th>Found in</th><th>Priority</th><th>Suggested heading</th><th></th></tr></thead><tbody>';
      gaps.forEach(function (g) {
        var pk = g.priority === 'high' ? 'red' : g.priority === 'medium' ? 'amber' : 'teal';
        var heading = g.suggested_heading || g.topic || '';
        var payload = encodeURIComponent(JSON.stringify({
          topic:    g.topic || '',
          heading:  heading,
          keyword:  keyword,
          priority: g.priority || 'medium'
        }));
        h += '<tr>'
          + '<td style="color:var(--lgse-t1);font-weight:500">' + esc(g.topic || '') + '</td>'
          + '<td class="mono">' + (g.found_in_n_competitors || 0) + ' / 10</td>'
          + '<td>' + badge(g.priority || '', pk) + '</td>'
          + '<td style="color:var(--lgse-t2);font-size:11px">' + esc(heading) + '</td>'
          + '<td style="text-align:right"><button onclick="lgseGenerateFromGap(\'' + payload + '\')" style="background:var(--lgse-purple);color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:10px;font-weight:600;cursor:pointer;white-space:nowrap">✨ Generate</button></td>'
          + '</tr>';
      });
      h += '</tbody></table>';
      mount.innerHTML = h;
    }).catch(function () { mount.innerHTML = '<div style="padding:10px;color:var(--lgse-red);font-size:11px">AI runtime unavailable.</div>'; });
  };

  window.lgseGenerateFromGap = function (payloadStr) {
    var data = {};
    try { data = JSON.parse(decodeURIComponent(payloadStr)); } catch (e) {}
    var topic    = data.topic    || '';
    var heading  = data.heading  || topic;
    var keyword  = data.keyword  || '';
    var priority = data.priority || 'medium';

    // Sarah's goal text — sent verbatim to POST /api/sarah/receive { goal, context }.
    var goal = 'I need to create content for the topic: "' + topic + '". '
      + 'Suggested title: "' + heading + '". '
      + 'Target keyword: "' + keyword + '". '
      + 'Priority: ' + priority + '. '
      + 'Please write a comprehensive SEO-optimized article.';
    var goalEnc = encodeURIComponent(goal);

    function safeAttr(s) { return String(s || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    var overlay = document.createElement('div');
    overlay.id = 'lgse-sarah-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML =
        '<div style="background:#13161e;border:1px solid #2a2f42;border-radius:16px;padding:24px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">'
      +   '<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">'
      +     '<div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#F59E0B,#EF4444);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">🤖</div>'
      +     '<div>'
      +       '<div style="font-size:14px;font-weight:700;color:#f0f2ff">Sarah</div>'
      +       '<div style="font-size:11px;color:#F59E0B">Digital Marketing Manager · AI OS</div>'
      +     '</div>'
      +   '</div>'
      +   '<div style="background:#1a1d27;border:1px solid #2a2f42;border-radius:10px;padding:14px;margin-bottom:16px">'
      +     '<div style="font-size:9px;font-weight:600;color:#F59E0B;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">Sarah already knows what you need:</div>'
      +     '<div style="font-size:12.5px;color:#e2e8f0;line-height:1.7">'
      +       'I\'ve identified a content gap: <strong style="color:#f0f2ff">"' + safeAttr(topic) + '"</strong>. '
      +       'Your competitors cover this topic but you don\'t. I\'ll write a '
      +       '<strong style="color:#f0f2ff">' + safeAttr(priority) + '-priority</strong> article targeting '
      +       '"' + safeAttr(keyword) + '" with the title:<br><br>'
      +       '<em style="color:#6C5CE7">"' + safeAttr(heading) + '"</em>'
      +     '</div>'
      +   '</div>'
      +   '<div style="font-size:11px;color:#555a72;margin-bottom:16px;line-height:1.6">'
      +     '<strong style="color:#8b90a7">Sarah will:</strong><br>'
      +     '1. Research the topic and competitors<br>'
      +     '2. Write a full SEO-optimized draft<br>'
      +     '3. Submit for your review before publishing'
      +   '</div>'
      +   '<div style="display:flex;gap:8px">'
      +     '<button onclick="document.getElementById(\'lgse-sarah-overlay\').remove()" style="flex:1;background:transparent;color:#8b90a7;border:1px solid #343a52;border-radius:8px;padding:10px;font-size:12px;cursor:pointer">Not now</button>'
      +     '<button id="lgse-sarah-confirm-btn" onclick="lgseConfirmSarahContent(\'' + goalEnc + '\',this)" style="flex:2;background:#F59E0B;color:#0d0f14;border:none;border-radius:8px;padding:10px;font-size:12px;font-weight:700;cursor:pointer">✓ Yes, create this content</button>'
      +   '</div>'
      + '</div>';

    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) overlay.remove();
    });
  };

  window.lgseConfirmSarahContent = function (goalEnc, btn) {
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = '⏳ Sending to Sarah…';
    var goal = decodeURIComponent(goalEnc);
    var token = localStorage.getItem('lu_token') || '';

    // Sarah ingress is /api/sarah/receive — payload requires `goal` (validated `required|string`).
    // The seo `api()` helper hardcodes /api/seo, so we use bare fetch here.
    fetch(window.location.origin + '/api/sarah/receive', {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        goal: goal,
        context: { source: 'seo.competitor_gap', type: 'content_generation', origin: 'competitors_tab' }
      })
    }).then(function (r) {
      if (!r.ok) throw new Error('http_' + r.status);
      return r.json();
    }).then(function () {
      var overlay = document.getElementById('lgse-sarah-overlay');
      if (overlay) overlay.remove();

      var toast = document.createElement('div');
      toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#13161e;border:1px solid #F59E0B;border-radius:10px;padding:14px 18px;font-size:12px;color:#f0f2ff;z-index:9999;max-width:300px;box-shadow:0 8px 24px rgba(0,0,0,.4)';
      toast.innerHTML = '<div style="font-weight:600;color:#F59E0B;margin-bottom:4px">✓ Sarah is on it</div><div style="color:#8b90a7;font-size:11px">Content request sent. Check Strategy Room for updates.</div>';
      document.body.appendChild(toast);
      setTimeout(function () { toast.remove(); }, 5000);
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = '✓ Yes, create this content';
      var errEl = document.createElement('div');
      errEl.style.cssText = 'color:var(--lgse-red);font-size:11px;margin-top:8px;text-align:center';
      errEl.textContent = 'Could not reach Sarah. Try again.';
      btn.parentElement.appendChild(errEl);
    });
  };
  window.lgseCmpCompare = function () {
    var url = document.getElementById('lgse-cmp-url');
    var kw = document.getElementById('lgse-cmp-kw2');
    var box = document.getElementById('lgse-cmp-cmp');
    if (!url || !kw || !box || !url.value.trim() || !kw.value.trim()) return;
    box.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);font-size:11px">Comparing…</div>';
    api('POST', '/competitors/compare', { your_url: url.value.trim(), keyword: kw.value.trim() }).then(function (r) {
      if (r && r.error) { box.innerHTML = emptyState('·', 'Page not indexed', 'Add your URL to the index first via the connector or a manual scan.'); return; }
      var you = r.your_page || {}; var avg = r.competitor_avg || {}; var gaps = r.gaps || []; var recs = r.recommendations || [];
      var h = '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:12px">';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Your words</div><div class="lgse-kpi-val">' + (you.word_count || 0) + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Competitor avg</div><div class="lgse-kpi-val">' + (avg.word_count || 0) + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Your score</div><div class="lgse-kpi-val">' + scorePill(you.content_score || 0) + '</div></div>';
      var delta = parseInt(r.word_count_delta || 0, 10);
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Word delta</div><div class="lgse-kpi-val ' + (delta >= 0 ? 'lgse-up' : 'lgse-dn') + '">' + (delta > 0 ? '+' : '') + delta + '</div></div>';
      h += '</div>';
      if (gaps.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Gaps</span></div><ul style="margin:0 0 12px;padding-left:18px;font-size:11.5px;color:var(--lgse-t2)">';
        gaps.forEach(function (g) { var pk = g.priority === 'high' ? 'red' : g.priority === 'medium' ? 'amber' : 'teal'; h += '<li style="margin-bottom:4px">' + badge(g.priority || '', pk) + ' ' + esc(g.description || '') + '</li>'; });
        h += '</ul>';
      }
      if (recs.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Recommendations</span></div><ul style="margin:0;padding-left:18px;font-size:11.5px;color:var(--lgse-t2)">';
        recs.forEach(function (rc) { h += '<li style="margin-bottom:4px">' + esc(rc.action || '') + '</li>'; });
        h += '</ul>';
      }
      box.innerHTML = h;
    }).catch(function () { box.innerHTML = emptyState('⚠', 'Compare failed', 'Try again.'); });
  };

  // ── Tab 8 — Insights (sub-tabs) ────────────────────────────────────────
  function renderInsights(el) {
    el.innerHTML = pageTitle('Insights', 'Search Console performance + AI-driven traffic correlations.')
      + '<div class="lgse-subtabs" id="lgse-ins-subtabs">'
        + '<div class="lgse-subtab active" data-sec="traffic">Traffic Insights</div>'
        + '<div class="lgse-subtab" data-sec="gsc">Search Console</div>'
      + '</div>'
      + '<div id="lgse-ins-body"></div>';
    var body = document.getElementById('lgse-ins-body');
    var subtabs = document.getElementById('lgse-ins-subtabs');
    function load(sec) {
      Array.prototype.forEach.call(subtabs.children, function (t) { t.classList.toggle('active', t.getAttribute('data-sec') === sec); });
      body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading…</div>';
      if (sec === 'traffic') return loadTraffic(body);
      if (sec === 'gsc') return loadGsc(body);
    }
    Array.prototype.forEach.call(subtabs.children, function (t) { t.addEventListener('click', function () { load(t.getAttribute('data-sec')); }); });
    load('traffic');
  }
  function loadTraffic(body) {
    Promise.all([
      api('GET', '/insights/traffic?days=28').catch(function () { return null; }),
      api('GET', '/insights/top-pages?days=28&limit=10').catch(function () { return null; }),
      api('GET', '/insights/content-performance').catch(function () { return null; }),
      api('GET', '/insights/summary').catch(function () { return null; }),
    ]).then(function (r) {
      var t = r[0] || {}; var pages = (r[1] && r[1].pages) || []; var p = r[2] || {}; var s = r[3] || {};
      var dirCol = t.direction === 'up' ? '#00E5A8' : t.direction === 'down' ? '#EF4444' : '#8b90a7';
      var dirIcon = t.direction === 'up' ? '↑' : t.direction === 'down' ? '↓' : '→';
      var h = '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Clicks (28d)</div><div class="lgse-kpi-val">' + (t.total_clicks || 0).toLocaleString() + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Impressions</div><div class="lgse-kpi-val">' + (t.total_impressions || 0).toLocaleString() + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Trend</div><div class="lgse-kpi-val" style="color:' + dirCol + '">' + dirIcon + ' ' + (t.change_pct || 0) + '%</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Correlation</div><div class="lgse-kpi-val" style="font-size:18px">' + esc(p.correlation || 'weak') + '</div></div>';
      h += '</div>';

      h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px"><div class="lgse-section-title" style="margin-bottom:8px">Key insights</div>';
      if ((s.insights || []).length === 0) h += '<div style="color:var(--lgse-t3);font-size:11px">Not enough data yet.</div>';
      else h += '<ul style="margin:0;padding-left:18px;font-size:11.5px;color:var(--lgse-t2)">' + s.insights.map(function (i) { return '<li style="margin-bottom:6px">' + esc(i) + '</li>'; }).join('') + '</ul>';
      h += '</div>';
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px"><div class="lgse-section-title" style="margin-bottom:8px">Recommendations</div>';
      if ((s.recommendations || []).length === 0) h += '<div style="color:var(--lgse-t3);font-size:11px">No recommendations yet.</div>';
      else h += '<ul style="margin:0;padding-left:18px;font-size:11.5px;color:var(--lgse-t2)">' + s.recommendations.map(function (rc) { return '<li style="margin-bottom:6px">' + esc(rc) + '</li>'; }).join('') + '</ul>';
      h += '</div></div>';

      h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Top pages by traffic</span></div>';
      if (pages.length === 0) {
        h += emptyState('∿', 'No GSC data yet', 'Connect Search Console and run a sync.', 'Open GSC', 'lgseSwitchTab(\'insights\');setTimeout(function(){var s=document.querySelector(\'.lgse-subtab[data-sec="gsc"]\');if(s)s.click();},20)');
      } else {
        h += '<table class="lgse-table"><thead><tr><th>URL</th><th>Title</th><th class="r">Clicks</th><th class="r">Impr</th><th class="r">CTR</th><th class="r">Pos</th><th class="r">Score</th></tr></thead><tbody>';
        pages.forEach(function (pg) {
          h += '<tr><td class="lgse-url">' + esc(pg.url || '—') + '</td><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(pg.title || '—') + '</td><td class="mono">' + (pg.clicks || 0) + '</td><td class="mono">' + (pg.impressions || 0) + '</td><td class="mono">' + ((pg.ctr || 0) * 100).toFixed(2) + '%</td><td class="mono">' + (pg.position || 0) + '</td><td class="mono">' + (pg.content_score ? scorePill(pg.content_score) : '—') + '</td></tr>';
        });
        h += '</tbody></table>';
      }
      h += '<div id="lgse-ins-extra" style="margin-top:18px"></div>';
      body.innerHTML = h;
      lgseLoadInsightsExtras();
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Insights unavailable', 'Try refreshing.'); });
  }

  // ─────────────────────────────────────────────────────────────────────
  // P1-E — Extra Insights sections rendered into #lgse-ins-extra:
  //        Content quality, Top authority pages, Anchor quality, Link opps.
  // No new backend endpoints — aggregates from /indexed-content, /anchors,
  // /links/gaps which all exist after P1-A/B/C.
  // ─────────────────────────────────────────────────────────────────────
  function lgseLoadInsightsExtras() {
    var holder = document.getElementById('lgse-ins-extra');
    if (!holder) return;
    holder.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading site-health insights…</div>';
    Promise.all([
      api('GET', '/indexed-content?per_page=100&sort=score&order=asc').catch(function () { return null; }),
      api('GET', '/indexed-content?per_page=10&sort=equity&order=desc').catch(function () { return null; }),
      api('GET', '/anchors').catch(function () { return null; }),
      api('GET', '/links/gaps').catch(function () { return null; })
    ]).then(function (rs) {
      var pagesAsc  = (rs[0] && rs[0].items) || [];
      var pagesByEq = (rs[1] && rs[1].items) || [];
      var anchors   = rs[2] || null;
      var gaps      = rs[3] || null;

      var h = '<div class="lgse-section-hdr" style="margin-top:8px"><span class="lgse-section-title">Site health</span></div>';
      h += '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">';

      // ── Content quality ──
      var bands = { 'Strong (≥90)': 0, 'Healthy (70–89)': 0, 'Needs work (50–69)': 0, 'At risk (<50)': 0 };
      var sum = 0; var count = 0;
      pagesAsc.forEach(function (p) {
        var s = parseInt(p.content_score, 10); if (isNaN(s)) return;
        sum += s; count++;
        if (s >= 90)      bands['Strong (≥90)']++;
        else if (s >= 70) bands['Healthy (70–89)']++;
        else if (s >= 50) bands['Needs work (50–69)']++;
        else              bands['At risk (<50)']++;
      });
      var avg = count > 0 ? Math.round(sum / count) : 0;
      var maxBand = Math.max(1, bands['Strong (≥90)'], bands['Healthy (70–89)'], bands['Needs work (50–69)'], bands['At risk (<50)']);
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
          + '<div class="lgse-section-title">Content quality</div>'
          + '<div style="font-size:11px;color:var(--lgse-t3)">avg ' + scorePill(avg) + ' across ' + count + ' pages</div>'
        + '</div>';
      Object.keys(bands).forEach(function (label) {
        var pct = (bands[label] / maxBand) * 100;
        var col = label.indexOf('Strong') === 0 ? 'var(--lgse-teal)'
                : label.indexOf('Healthy') === 0 ? 'var(--lgse-purple)'
                : label.indexOf('Needs') === 0 ? 'var(--lgse-amber)'
                : 'var(--lgse-red)';
        h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:11px">'
          + '<div style="width:120px;color:var(--lgse-t2);flex-shrink:0">' + esc(label) + '</div>'
          + '<div style="flex:1;height:8px;background:var(--lgse-bg3);border-radius:4px;overflow:hidden"><div style="width:' + pct + '%;height:100%;background:' + col + '"></div></div>'
          + '<div class="mono" style="width:36px;text-align:right;color:var(--lgse-t1)">' + bands[label] + '</div>'
        + '</div>';
      });
      if (count === 0) h += '<div style="color:var(--lgse-t3);font-size:11px;padding:6px 0">No indexed pages yet.</div>';
      h += '</div>';

      // ── Top authority pages ──
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:10px">Top authority pages</div>';
      var topAuth = pagesByEq.slice(0, 5);
      if (topAuth.length === 0) {
        h += '<div style="color:var(--lgse-t3);font-size:11px">Build the link graph and run equity calc to populate this view.</div>';
      } else {
        h += '<table class="lgse-table" style="margin:0"><tbody>';
        topAuth.forEach(function (p) {
          var eq = (p.equity_score != null) ? p.equity_score : (p.content_score || 0);
          h += '<tr>'
            + '<td class="lgse-url" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:6px 0">' + esc(p.url || p.title || '—') + '</td>'
            + '<td class="r mono" style="padding:6px 0">' + scorePill(eq) + '</td>'
          + '</tr>';
        });
        h += '</tbody></table>';
      }
      h += '</div>';

      // ── Anchor quality ──
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:10px">Anchor quality</div>';
      if (!anchors || !anchors.distribution) {
        h += '<div style="color:var(--lgse-t3);font-size:11px">No anchor data yet — open Links → Anchors and run analysis.</div>';
      } else {
        var d = anchors.distribution;
        h += '<div style="font-size:11.5px;color:var(--lgse-t2);margin-bottom:10px">' + (d.total || 0) + ' internal anchors analysed</div>'
          + '<div class="lgse-stack-bar" style="height:10px;margin-bottom:8px">'
            + '<div style="width:' + (d.generic_pct || 0) + '%;background:var(--lgse-red)"></div>'
            + '<div style="width:' + (d.exact_pct   || 0) + '%;background:var(--lgse-amber)"></div>'
            + '<div style="width:' + (d.natural_pct || 0) + '%;background:var(--lgse-teal)"></div>'
          + '</div>'
          + '<div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--lgse-t3)">'
            + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-red);border-radius:2px;margin-right:5px"></span>Generic ' + (d.generic_pct || 0) + '%</span>'
            + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-amber);border-radius:2px;margin-right:5px"></span>Exact ' + (d.exact_pct || 0) + '%</span>'
            + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-teal);border-radius:2px;margin-right:5px"></span>Natural ' + (d.natural_pct || 0) + '%</span>'
          + '</div>';
      }
      h += '</div>';

      // ── Link opportunities ──
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:10px">Link opportunities</div>';
      if (!gaps) {
        h += '<div style="color:var(--lgse-t3);font-size:11px">Build the link graph to surface internal-link opportunities.</div>';
      } else {
        var sg = gaps.summary || { orphan_count: 0, weak_count: 0 };
        var oc = parseInt(sg.orphan_count, 10) || 0;
        var wc = parseInt(sg.weak_count, 10) || 0;
        h += '<div style="display:flex;gap:14px;margin-bottom:10px">'
          + '<div><div style="font-size:22px;color:var(--lgse-red);font-weight:600">' + oc + '</div><div style="font-size:10.5px;color:var(--lgse-t3)">orphan</div></div>'
          + '<div><div style="font-size:22px;color:var(--lgse-amber);font-weight:600">' + wc + '</div><div style="font-size:10.5px;color:var(--lgse-t3)">weak (1–2 inbound)</div></div>'
        + '</div>';
        if (oc + wc > 0) {
          h += '<button class="lgse-btn-secondary" style="font-size:10.5px;padding:6px 11px" onclick="lgseSwitchTab(\'links\');setTimeout(function(){var s=document.querySelector(\'.lgse-subtab[data-sec=\\\'gaps\\\']\');if(s)s.click();},30)">Open Gaps →</button>';
        } else {
          h += '<div style="color:var(--lgse-teal);font-size:11px">All pages have enough inbound links.</div>';
        }
      }
      h += '</div>';

      h += '</div>'; // grid end
      holder.innerHTML = h;
    }).catch(function () {
      holder.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);text-align:center;font-size:11px">Site-health insights unavailable.</div>';
    });
  }
  // ── End P1-E ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-F — Pages row click-to-expand. Google preview + score breakdown +
  //        quick edit (meta_title, meta_description, h1).
  // Toggling: clicking the chevron inserts/removes a sibling <tr>.
  // Data: read from window._lgsePages cache (no extra fetch).
  // Save: PATCH /indexed-content/{id} (existing route).
  // ─────────────────────────────────────────────────────────────────────
  window.lgseExpandPageRow = function (id) {
    if (!id) return;
    var tbody = document.querySelector('#lgse-pg-body table tbody'); if (!tbody) return;
    var row = tbody.querySelector('tr[data-page-id="' + id + '"]:not(.lgse-pg-detail)'); if (!row) return;
    var detail = tbody.querySelector('tr.lgse-pg-detail[data-pg-detail-for="' + id + '"]');
    var chev = row.querySelector('.lgse-pg-chev');

    if (detail) {
      detail.parentNode.removeChild(detail);
      if (chev) chev.style.transform = '';
      return;
    }

    // Find the page in the cached list — set in lgseLoadPages.
    var p = null;
    var pool = window._lgsePages || [];
    for (var i = 0; i < pool.length; i++) { if (parseInt(pool[i].id, 10) === id) { p = pool[i]; break; } }
    if (!p) return;

    if (chev) chev.style.transform = 'rotate(90deg)';

    var colCount = (row.children || []).length;
    var detailTr = document.createElement('tr');
    detailTr.className = 'lgse-pg-detail';
    detailTr.setAttribute('data-pg-detail-for', id);
    var url = p.url || '';
    var title = p.meta_title || p.title || '';
    var meta = p.meta_description || '';
    var h1 = p.h1 || '';

    // Google search preview.
    var domain = '';
    try { domain = new URL(url).hostname; } catch (_) { domain = url; }
    var previewTitle = title ? title : (p.title || url);
    var previewBody  = meta ? meta : 'Add a meta description to control how this page looks in Google.';

    // Score breakdown.
    var bk = (p.score_breakdown && p.score_breakdown.length) ? p.score_breakdown : [];
    var bkHtml = '';
    if (bk.length === 0) {
      bkHtml = '<div style="color:var(--lgse-t3);font-size:11px">No score breakdown stored. Run audit to refresh.</div>';
    } else {
      bk.forEach(function (f) {
        var s = parseInt(f.score, 10) || 0;
        var w = parseInt(f.weight, 10) || 0;
        var pct = w > 0 ? Math.round((s / w) * 100) : 0;
        var col = pct >= 90 ? 'var(--lgse-teal)' : pct >= 60 ? 'var(--lgse-purple)' : pct >= 30 ? 'var(--lgse-amber)' : 'var(--lgse-red)';
        bkHtml += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:11px">'
          + '<div style="width:130px;color:var(--lgse-t2);flex-shrink:0">' + esc(f.factor || '—') + '</div>'
          + '<div style="flex:1;height:6px;background:var(--lgse-bg3);border-radius:3px;overflow:hidden"><div style="width:' + pct + '%;height:100%;background:' + col + '"></div></div>'
          + '<div class="mono" style="width:60px;text-align:right;color:var(--lgse-t1)">' + s + '/' + w + '</div>'
        + '</div>';
        if (f.details) bkHtml += '<div style="font-size:10.5px;color:var(--lgse-t3);margin:-3px 0 7px 138px">' + esc(f.details) + '</div>';
      });
    }

    var inner = ''
      + '<td colspan="' + colCount + '" style="background:var(--lgse-bg2);padding:14px 18px;border-bottom:1px solid var(--lgse-border)">'
        + '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px">'

          // ── Google preview ──
          + '<div>'
            + '<div class="lgse-section-title" style="margin-bottom:8px">Google preview</div>'
            + '<div style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:8px;padding:12px">'
              + '<div style="font-size:11px;color:var(--lgse-t3);margin-bottom:3px">' + esc(domain) + '</div>'
              + '<div style="color:var(--lgse-purple);font-size:14px;line-height:1.35;margin-bottom:4px;cursor:pointer">' + esc(previewTitle) + '</div>'
              + '<div style="font-size:11.5px;color:var(--lgse-t2);line-height:1.45">' + esc(previewBody) + '</div>'
            + '</div>'
          + '</div>'

          // ── Score breakdown ──
          + '<div>'
            + '<div class="lgse-section-title" style="margin-bottom:8px">Score breakdown</div>'
            + '<div style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:8px;padding:12px">' + bkHtml + '</div>'
          + '</div>'

        + '</div>'

        // ── Quick edit form ──
        + '<div style="margin-top:14px">'
          + '<div class="lgse-section-title" style="margin-bottom:8px">Quick edit</div>'
          + '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1fr);gap:10px;align-items:end">'
            + '<div><label style="display:block;font-size:10.5px;color:var(--lgse-t3);margin-bottom:4px">Meta title</label><input id="lgse-edit-title-' + id + '" value="' + esc(title) + '" maxlength="70" style="width:100%;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;padding:7px 10px;font-size:11.5px;color:var(--lgse-t1)"></div>'
            + '<div><label style="display:block;font-size:10.5px;color:var(--lgse-t3);margin-bottom:4px">Meta description</label><input id="lgse-edit-meta-' + id + '" value="' + esc(meta) + '" maxlength="170" style="width:100%;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;padding:7px 10px;font-size:11.5px;color:var(--lgse-t1)"></div>'
            + '<div><label style="display:block;font-size:10.5px;color:var(--lgse-t3);margin-bottom:4px">H1</label><input id="lgse-edit-h1-' + id + '" value="' + esc(h1) + '" maxlength="200" style="width:100%;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;padding:7px 10px;font-size:11.5px;color:var(--lgse-t1)"></div>'
          + '</div>'
          + '<div style="display:flex;gap:8px;margin-top:10px;align-items:center">'
            + '<button class="lgse-btn-primary" style="font-size:11px;padding:7px 14px" onclick="lgseSavePageRow(' + id + ')">Save</button>'
            + '<button class="lgse-btn-secondary" style="font-size:11px;padding:7px 14px" onclick="lgseExpandPageRow(' + id + ')">Cancel</button>'
            + '<span id="lgse-edit-status-' + id + '" style="font-size:11px;color:var(--lgse-t3);margin-left:6px"></span>'
          + '</div>'
        + '</div>'

        // ── P1-H — Featured image section ──
        + '<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--lgse-border)">'
          + '<div style="font-size:9px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">Featured image</div>'
          + '<div style="display:flex;align-items:flex-start;gap:16px">'

            // Current image preview
            + '<div id="lgse-fi-preview-' + id + '" style="width:120px;height:80px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">'
              + (p.featured_image_url
                ? '<img src="' + esc(p.featured_image_url) + '" style="width:100%;height:100%;object-fit:cover">'
                : '<span style="font-size:11px;color:var(--lgse-t3)">No image</span>')
            + '</div>'

            // Action column
            + '<div style="display:flex;flex-direction:column;gap:8px">'
              + '<button onclick="lgsePickMedia(' + id + ')" style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 14px;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:6px">🖼 Pick from media library</button>'
              + '<button onclick="lgseGenerateFeaturedImage(' + id + ')" style="background:var(--lgse-purple);color:#fff;border:none;border-radius:7px;padding:7px 14px;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:6px">✨ Generate with AI</button>'
              + '<div id="lgse-fi-status-' + id + '" style="font-size:10px;color:var(--lgse-t3)"></div>'
            + '</div>'

          + '</div>'
        + '</div>'

      + '</td>';

    detailTr.innerHTML = inner;
    row.parentNode.insertBefore(detailTr, row.nextSibling);
  };

  window.lgseSavePageRow = function (id) {
    if (!id) return;
    var t = document.getElementById('lgse-edit-title-' + id);
    var m = document.getElementById('lgse-edit-meta-'  + id);
    var h = document.getElementById('lgse-edit-h1-'    + id);
    var s = document.getElementById('lgse-edit-status-' + id);
    if (!t || !m || !h) return;
    if (s) { s.textContent = 'Saving…'; s.style.color = 'var(--lgse-t3)'; }
    api('PATCH', '/indexed-content/' + id, {
      meta_title:       t.value,
      meta_description: m.value,
      h1:               h.value
    }).then(function (r) {
      if (r && r.success) {
        if (s) { s.textContent = 'Saved ✓'; s.style.color = 'var(--lgse-teal)'; }
        // Refresh cached row from response so a re-expand reflects the save.
        if (r.row && window._lgsePages) {
          for (var i = 0; i < window._lgsePages.length; i++) {
            if (parseInt(window._lgsePages[i].id, 10) === id) {
              window._lgsePages[i].meta_title       = r.row.meta_title;
              window._lgsePages[i].meta_description = r.row.meta_description;
              window._lgsePages[i].h1               = r.row.h1;
              break;
            }
          }
        }
      } else if (s) {
        s.textContent = 'Save failed: ' + ((r && r.error) || 'unknown');
        s.style.color = 'var(--lgse-red)';
      }
    }).catch(function () {
      if (s) { s.textContent = 'Save failed (network)'; s.style.color = 'var(--lgse-red)'; }
    });
  };
  // ── End P1-F ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-H — Featured image picker + AI-generate hook for Pages row expand.
  // Save: PATCH /indexed-content/{id} with { featured_image_url }
  // List: GET /media/library (workspace-scoped MediaController)
  // Generate: no /studio/generate route on staging — falls back to a modal
  //           that takes the user to Studio (or similar) with a prefilled
  //           prompt. UI is honest about this.
  // ─────────────────────────────────────────────────────────────────────
  window.lgsePickMedia = function (id) {
    var listEl;
    var html = '<div id="lgse-media-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;max-height:400px;overflow-y:auto">'
      + '<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--lgse-t3);font-size:11px">Loading media…</div>'
      + '</div>';
    window.lgseShowModal('Media library', html, null, { hideSave: true, cancelLabel: 'Close' });

    api('GET', '/media/library?per_page=60&type=image').then(function (d) {
      // Accept several response shapes from MediaController.
      var items = (d && (d.items || d.data || d.media)) || (Array.isArray(d) ? d : []);
      // Filter to images only — defence in depth.
      items = items.filter(function (it) {
        var t = (it.asset_type || it.type || '').toLowerCase();
        if (t && t !== 'image') return false;
        return !!(it.url || it.thumbnail_url || it.path || it.src);
      });
      var grid = document.getElementById('lgse-media-grid'); if (!grid) return;
      if (items.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:32px;color:var(--lgse-t3);font-size:11px">No media found. Upload images in Studio first.</div>';
        return;
      }
      var inner = '';
      items.forEach(function (it) {
        var src = it.thumbnail_url || it.url || it.file_url || it.path || it.src || '';
        var fullSrc = it.url || it.file_url || it.path || it.src || src;
        if (!src) return;
        var safeFull = String(fullSrc).replace(/'/g, '\\\'').replace(/"/g, '&quot;');
        inner += '<div onclick="lgseSelectMediaItem(' + id + ', \'' + safeFull + '\')" '
          + 'style="cursor:pointer;border-radius:6px;overflow:hidden;aspect-ratio:1;border:2px solid transparent;transition:border-color .12s" '
          + 'onmouseover="this.style.borderColor=\'var(--lgse-purple)\'" '
          + 'onmouseout="this.style.borderColor=\'transparent\'">'
          + '<img src="' + esc(src) + '" style="width:100%;height:100%;object-fit:cover">'
          + '</div>';
      });
      grid.innerHTML = inner;
    }).catch(function () {
      var grid = document.getElementById('lgse-media-grid');
      if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--lgse-red);font-size:11px">Could not load media library.</div>';
    });
  };

  window.lgseSelectMediaItem = function (id, src) {
    var overlay = document.getElementById('lgse-modal-overlay');
    if (overlay) overlay.remove();
    window.lgseSetFeaturedImage(id, src);
  };

  window.lgseSetFeaturedImage = function (id, imageUrl) {
    if (!id || !imageUrl) return;
    var preview = document.getElementById('lgse-fi-preview-' + id);
    if (preview) preview.innerHTML = '<img src="' + esc(imageUrl) + '" style="width:100%;height:100%;object-fit:cover">';
    var status  = document.getElementById('lgse-fi-status-'  + id);
    if (status) { status.textContent = 'Saving…'; status.style.color = 'var(--lgse-t3)'; }

    api('PATCH', '/indexed-content/' + id, { featured_image_url: imageUrl }).then(function (r) {
      if (r && r.success) {
        if (status) {
          status.textContent = '✓ Saved';
          status.style.color = 'var(--lgse-teal)';
          setTimeout(function () { if (status) status.textContent = ''; }, 3000);
        }
        // Update cache so reopening the row reflects the save.
        if (window._lgsePages) {
          for (var i = 0; i < window._lgsePages.length; i++) {
            if (parseInt(window._lgsePages[i].id, 10) === id) {
              window._lgsePages[i].featured_image_url = imageUrl;
              break;
            }
          }
        }
      } else if (status) {
        status.textContent = 'Save failed: ' + ((r && r.error) || 'unknown');
        status.style.color = 'var(--lgse-red)';
      }
    }).catch(function () {
      if (status) { status.textContent = 'Save failed (network)'; status.style.color = 'var(--lgse-red)'; }
    });
  };

  window.lgseGenerateFeaturedImage = function (id) {
    var pool = window._lgsePages || [];
    var p = null;
    for (var i = 0; i < pool.length; i++) { if (parseInt(pool[i].id, 10) === id) { p = pool[i]; break; } }
    if (!p) return;
    var pageUrl   = p.url || '';
    var pageTitle = p.meta_title || p.title || pageUrl;

    var status = document.getElementById('lgse-fi-status-' + id);
    if (status) { status.textContent = 'Generating…'; status.style.color = 'var(--lgse-t3)'; }

    // 2026-05-13 — call the verified-working connector route directly.
    // /api/connector/pages/regenerate-image handles credit reserve + commit,
    // routes through CreativeConnector → RuntimeClient → gpt-image-1, and
    // writes the PNG to public storage. It returns { success, image_url }.
    // _luFetch carries X-API-KEY in embed mode and Authorization Bearer in
    // direct SPA mode — works for both.
    if (typeof window._luFetch !== 'function') {
      if (status) { status.textContent = 'Auth helper unavailable — refresh the page.'; status.style.color = 'var(--lgse-red)'; }
      return;
    }

    window._luFetch('POST', '/connector/pages/regenerate-image', {
      page_id: id,
      url:     pageUrl,
      title:   pageTitle,
      force:   true,
    })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: 'bad_json' }; }); })
      .then(function (d) {
        if (d && d.success && d.image_url) {
          // lgseSetFeaturedImage handles the PATCH + preview update + status badge.
          window.lgseSetFeaturedImage(id, d.image_url);
          return;
        }
        if (d && d.error === 'insufficient_credits') {
          if (status) {
            status.textContent = 'Not enough credits (need 1).';
            status.style.color = 'var(--lgse-red)';
          }
          return;
        }
        if (status) {
          status.textContent = 'Generation failed: ' + (d && (d.error || d.message) || 'unknown');
          status.style.color = 'var(--lgse-red)';
        }
      })
      .catch(function (e) {
        if (status) {
          status.textContent = 'Network error — try again.';
          status.style.color = 'var(--lgse-red)';
        }
      });
  };
  // ── End P1-H ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // SMOKE-1.3 — Rebuild graph / Recalculate equity (proper feedback).
  // Replaces the inline onclick handlers that fired-and-forgot with no UI
  // signal. Both routes already exist on staging:
  //   POST /api/seo/link-graph/build
  //   POST /api/seo/equity/calculate
  // ─────────────────────────────────────────────────────────────────────
  window.lgseRebuildGraph = function (btn) {
    var b = btn || document.getElementById('lgse-rebuild-graph-btn');
    var orig = b ? b.textContent : '';
    if (b) { b.disabled = true; b.textContent = '⏳ Rebuilding…'; }
    api('POST', '/link-graph/build', {}).then(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Rebuild graph'; }
      // Re-render to show the fresh graph counts.
      if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('links');
    }).catch(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Rebuild graph'; }
    });
  };

  window.lgseRecalcEquity = function (btn) {
    var b = btn || document.getElementById('lgse-recalc-equity-btn');
    var orig = b ? b.textContent : '';
    if (b) { b.disabled = true; b.textContent = '⏳ Calculating…'; }
    api('POST', '/equity/calculate', {}).then(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Recalculate equity'; }
      if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('links');
    }).catch(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Recalculate equity'; }
    });
  };
  // ── End SMOKE-1.3 ─────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-G — Pages bulk action toolbar.
  // Sticky bottom bar appears when ≥1 row checkbox is selected.
  // Actions: Select none · Export selected as CSV.
  // ─────────────────────────────────────────────────────────────────────
  function lgseGetCheckedPageIds() {
    var ids = [];
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-pg-check'), function (cb) {
      if (cb.checked) {
        var pid = parseInt(cb.getAttribute('data-pid'), 10);
        if (pid) ids.push(pid);
      }
    });
    return ids;
  }

  window.lgseTogglePageSelectAll = function (checked) {
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-pg-check'), function (cb) { cb.checked = !!checked; });
    window.lgseUpdateBulkBar();
  };

  window.lgseUpdateBulkBar = function () {
    var ids = lgseGetCheckedPageIds();
    var bar = document.getElementById('lgse-pg-bulkbar');
    var allCb = document.getElementById('lgse-pg-selectall');
    var totalCbs = document.querySelectorAll('.lgse-pg-check').length;
    if (allCb) {
      allCb.checked = totalCbs > 0 && ids.length === totalCbs;
      allCb.indeterminate = ids.length > 0 && ids.length < totalCbs;
    }

    if (ids.length === 0) {
      if (bar) bar.style.display = 'none';
      return;
    }
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'lgse-pg-bulkbar';
      bar.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);background:var(--lgse-bg1);border:1px solid var(--lgse-purple);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.45);padding:10px 16px;display:flex;align-items:center;gap:14px;z-index:200;font-size:11.5px';
      document.body.appendChild(bar);
    }
    bar.innerHTML = ''
      + '<div style="color:var(--lgse-t1);font-weight:500"><span style="color:var(--lgse-purple)">' + ids.length + '</span> selected</div>'
      + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="lgseSelectNone()">Select none</button>'
      + '<button class="lgse-btn-primary" style="font-size:11px;padding:6px 12px" onclick="lgseExportSelected()">Export selected ↓</button>';
    bar.style.display = 'flex';
  };

  window.lgseSelectNone = function () {
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-pg-check'), function (cb) { cb.checked = false; });
    var allCb = document.getElementById('lgse-pg-selectall');
    if (allCb) { allCb.checked = false; allCb.indeterminate = false; }
    window.lgseUpdateBulkBar();
  };

  window.lgseExportSelected = function () {
    var ids = lgseGetCheckedPageIds();
    if (ids.length === 0) return;
    var pool = window._lgsePages || [];
    var selected = pool.filter(function (p) { return ids.indexOf(parseInt(p.id, 10)) !== -1; });
    if (selected.length === 0) return;

    var cols = ['id', 'url', 'title', 'meta_title', 'meta_description', 'h1', 'word_count', 'image_count', 'internal_link_count', 'external_link_count', 'content_score', 'intent', 'http_status', 'issues_count', 'indexed_at'];
    function csvCell(v) {
      if (v == null) return '';
      var s = String(v);
      if (/[",\n\r]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
      return s;
    }
    var lines = [cols.join(',')];
    selected.forEach(function (p) {
      lines.push(cols.map(function (c) { return csvCell(p[c]); }).join(','));
    });
    var csv = lines.join('\n');
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'seo-pages-selected-' + Date.now() + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };
  // ── End P1-G ──────────────────────────────────────────────────────────
  function loadGsc(body) {
    api('GET', '/gsc/status').then(function (status) {
      var connected = !!(status && (status.connected || status.is_connected));
      if (!connected) {
        body.innerHTML = emptyState('🔗', 'Connect Google Search Console', 'Sync clicks, impressions, CTR, and position data into your workspace.', 'Connect with Google', 'api(\'GET\',\'/gsc/auth-url\').then(function(r){if(r&&r.url)window.open(r.url,\'_blank\')})');
        return;
      }
      api('GET', '/gsc/queries').then(function (qResp) {
        var queries = (qResp && (qResp.queries || qResp.data)) || [];
        var maxCtr = 0;
        queries.forEach(function (q) { if ((q.ctr || 0) > maxCtr) maxCtr = q.ctr || 0; });
        var lastSync = (status && (status.last_synced_at || status.synced_at)) || '';
        var h = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">';
        h += '<span class="lgse-badge lgse-b-teal">● Connected' + (lastSync ? ' — synced ' + esc(lastSync) : '') + '</span>';
        h += '<button class="lgse-btn-secondary" onclick="api(\'POST\',\'/gsc/sync\',{}).then(function(){lgseSwitchTab(\'insights\')})">Sync now</button>';
        h += '</div>';
        if (queries.length === 0) {
          h += emptyState('📡', 'No GSC data yet', 'Click "Sync now" to fetch the latest 28 days.');
        } else {
          h += '<table class="lgse-table"><thead><tr><th>Query</th><th class="r">Clicks</th><th class="r">Impressions</th><th class="r">CTR</th><th class="r">Position</th></tr></thead><tbody>';
          queries.slice(0, 30).forEach(function (q) {
            var ctr = parseFloat(q.ctr || 0);
            var heat = maxCtr > 0 ? Math.min(0.32, (ctr / maxCtr) * 0.32) : 0;
            h += '<tr><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(q.query || '—') + '</td><td class="mono">' + (q.clicks || 0) + '</td><td class="mono">' + (q.impressions || 0) + '</td><td class="mono" style="background:rgba(108,92,231,' + heat + ')">' + (ctr * 100).toFixed(2) + '%</td><td class="mono">' + (parseFloat(q.position || 0)).toFixed(1) + '</td></tr>';
          });
          h += '</tbody></table>';
        }
        body.innerHTML = h;
      }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load queries', 'Try refreshing.'); });
    }).catch(function () { body.innerHTML = emptyState('⚠', 'GSC status unavailable', 'Try refreshing.'); });
  }

  // ── Tab 9 — Reports (P0-12 + P0-13) ───────────────────────────────────
  // History table built from /audits (most recent first), KPI strip, score
  // trend SVG, CSV export grid, per-row "View →" → drills into the Audit
  // tab's detail view via window.lgseShowAuditDetail(id).

  function renderReports(el) {
    el.innerHTML = pageTitle('Reports', 'Audit history, score trend, and CSV exports.')
      + '<div id="lgse-reports-body">Loading…</div>';
    window.lgseLoadReports();
  }

  window.lgseLoadReports = function () {
    var body = document.getElementById('lgse-reports-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:24px;text-align:center;color:var(--lgse-t3);font-size:11px">Loading report…</div>';

    api('GET', '/audits?limit=20').then(function (d) {
      var audits = (d && (d.audits || d.data)) || (Array.isArray(d) ? d : []);
      if (!audits.length) {
        body.innerHTML = emptyState('◎', 'No audit history', 'Run your first audit to start tracking SEO improvements.', 'Run audit', 'lgseRunAudit()');
        return;
      }

      // Sort defensively — we want descending by created_at so audits[0] is latest.
      audits.sort(function (a, b) {
        return (new Date(b.created_at || 0)).getTime() - (new Date(a.created_at || 0)).getTime();
      });

      var latest = audits[0];
      var oldest = audits[audits.length - 1];
      var avgScore = Math.round(audits.reduce(function (s, a) { return s + (parseInt(a.score, 10) || 0); }, 0) / audits.length);
      var scoreChange = (parseInt(latest.score, 10) || 0) - (parseInt(oldest.score, 10) || 0);

      // Coverage: derive from latest audit's checks.
      var latestResults = {};
      try { latestResults = typeof latest.results_json === 'string' ? JSON.parse(latest.results_json) : (latest.results_json || {}); } catch (e) {}
      var checks = Array.isArray(latestResults.checks) ? latestResults.checks : (Array.isArray(latestResults.issues) ? latestResults.issues : []);

      function coverage(needle) {
        var matched = checks.filter(function (c) { return ((c.check || c.label || '') + '').toLowerCase().indexOf(needle) !== -1; });
        if (!matched.length) return null;
        var passed = matched.filter(function (c) { return c.status === 'pass' || c.status === 'success'; }).length;
        return Math.round(passed / matched.length * 100);
      }
      var titlePct = coverage('title');
      var descPct  = coverage('description');

      var html = '';

      // Header: title + audit count + Download buttons
      html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap">'
        +   '<div>'
        +     '<div style="font-size:16px;font-weight:600;color:var(--lgse-t1);margin-bottom:3px">SEO report</div>'
        +     '<div style="font-size:11px;color:var(--lgse-t3)">' + audits.length + ' audit' + (audits.length === 1 ? '' : 's') + ' · last run ' + (latest.created_at ? new Date(latest.created_at).toLocaleDateString() : '—') + '</div>'
        +   '</div>'
        +   '<div style="display:flex;gap:8px">'
        +     '<button class="lgse-btn-secondary" onclick="lgseDownloadReport(\'html\')" style="font-size:11px;padding:7px 14px">Export HTML</button>'
        +     '<button class="lgse-btn-primary"   onclick="lgseDownloadReport(\'pdf\')"  style="font-size:11.5px;padding:7px 14px">Print → PDF</button>'
        +   '</div>'
        + '</div>';

      // Date-range pills (visual only — backend doesn't yet take a date filter on /audits).
      html += '<div style="display:flex;gap:6px;margin-bottom:14px">'
        + [7, 30, 60, 90].map(function (days) {
            var active = days === 30; // default emphasis
            var bg = active ? 'rgba(108,92,231,0.08)' : 'var(--lgse-bg2)';
            var bd = active ? 'var(--lgse-purple)' : 'var(--lgse-border)';
            var c  = active ? 'var(--lgse-purple)' : 'var(--lgse-t3)';
            return '<span class="lgse-fp" onclick="lgseReportsRange(' + days + ', this)" style="background:' + bg + ';border:1px solid ' + bd + ';border-radius:20px;padding:4px 12px;font-size:10.5px;color:' + c + ';cursor:pointer;transition:all .12s">' + days + 'd</span>';
          }).join('')
        + '</div>';

      // KPI cards — 5 across (uses existing lgse-kpi-card class).
      html += '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:14px">';
      var kpis = [
        { l: 'Avg score',     v: avgScore },
        { l: 'Title coverage', v: titlePct == null ? '—' : titlePct + '%', cls: titlePct != null && titlePct >= 80 ? 'lgse-up' : (titlePct != null && titlePct < 50 ? 'lgse-dn' : '') },
        { l: 'Desc coverage',  v: descPct  == null ? '—' : descPct  + '%', cls: descPct  != null && descPct  >= 80 ? 'lgse-up' : (descPct  != null && descPct  < 50 ? 'lgse-dn' : '') },
        { l: 'Audits run',    v: audits.length },
        { l: 'Score change',  v: (scoreChange > 0 ? '+' : '') + scoreChange, cls: scoreChange > 0 ? 'lgse-up' : (scoreChange < 0 ? 'lgse-dn' : '') },
      ];
      kpis.forEach(function (k) {
        html += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">' + esc(k.l) + '</div><div class="lgse-kpi-val ' + (k.cls || '') + '">' + esc(String(k.v)) + '</div></div>';
      });
      html += '</div>';

      // SMOKE-1.4 — Score trend as CSS bar chart (was: brittle SVG polyline).
      var chartAudits = audits.slice(0, 8).reverse();
      if (chartAudits.length > 1) {
        var chartHtml = '<div style="display:flex;align-items:flex-end;gap:6px;height:96px;padding:6px 0">';
        chartAudits.forEach(function (a) {
          var sc  = parseInt(a.score, 10) || 0;
          var pct = Math.round((sc / 100) * 80); // 0–80 px tall
          var col = sc >= 75 ? 'var(--lgse-teal)' : sc >= 50 ? 'var(--lgse-purple)' : 'var(--lgse-amber)';
          var d   = a.created_at ? new Date(a.created_at) : null;
          var dateLbl = d ? d.toLocaleDateString('en', { month: 'short', day: 'numeric' }) : '—';
          chartHtml += '<div style="display:flex;flex-direction:column;align-items:center;flex:1;gap:4px">'
            + '<div style="font-family:var(--lgse-mono);font-size:9px;color:var(--lgse-t3)">' + sc + '</div>'
            + '<div style="background:' + col + ';width:100%;height:' + pct + 'px;border-radius:3px 3px 0 0;min-height:4px"></div>'
            + '<div style="font-size:9px;color:var(--lgse-t3);text-align:center;white-space:nowrap">' + esc(dateLbl) + '</div>'
          + '</div>';
        });
        chartHtml += '</div>';
        html += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px;margin-bottom:16px">'
          +   '<div style="font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Score trend</div>'
          +   chartHtml
          + '</div>';
      }

      // CSV exports — reuses existing window.lgseExportCsv (auth-aware).
      html += '<div class="lgse-section-hdr"><span class="lgse-section-title">Data exports</span></div>';
      html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-bottom:18px">';
      ['keywords', 'pages', 'links', 'images', 'anchors'].forEach(function (t) {
        var label = t.charAt(0).toUpperCase() + t.slice(1);
        html += '<button class="lgse-btn-secondary" style="text-align:left;padding:14px;display:block;cursor:pointer" onclick="lgseExportCsv(\'' + t + '\')">'
          +   '<div style="font-weight:600;color:var(--lgse-t1);margin-bottom:3px">⬇ ' + label + '</div>'
          +   '<div style="font-size:10px;color:var(--lgse-t3)">CSV download</div>'
          + '</button>';
      });
      html += '</div>';

      // P0-13 — audit history table with per-row drill-down.
      html += '<div class="lgse-section-hdr"><span class="lgse-section-title">Audit history</span><span style="font-size:10px;color:var(--lgse-t3)">' + audits.length + ' audit' + (audits.length === 1 ? '' : 's') + '</span></div>';
      html += '<table class="lgse-table"><thead><tr><th>Date</th><th>URL</th><th class="r">Score</th><th class="r">Issues</th><th>Status</th><th></th></tr></thead><tbody>';
      audits.forEach(function (a) {
        var when = a.created_at ? new Date(a.created_at).toLocaleString() : '—';
        var totalIssues = parseInt(a.total_issues || a.errors || 0, 10) || 0;
        // Try to count issues from results_json if column missing.
        if (!totalIssues) {
          try {
            var parsed = typeof a.results_json === 'string' ? JSON.parse(a.results_json) : (a.results_json || {});
            var c = Array.isArray(parsed.checks) ? parsed.checks : (Array.isArray(parsed.issues) ? parsed.issues : []);
            totalIssues = c.filter(function (x) { return x && (x.status === 'error' || x.status === 'warning'); }).length;
          } catch (e) {}
        }
        var statusBadge = badge(a.status || '—', a.status === 'completed' ? 'teal' : a.status === 'failed' ? 'red' : 'amber');
        html += '<tr style="cursor:pointer" onclick="lgseShowRunDetail(' + (a.id || 0) + ')">'
          +   '<td style="font-size:10.5px;color:var(--lgse-t2)">' + esc(when) + '</td>'
          +   '<td class="lgse-url" title="' + esc(a.url || '') + '">' + esc(a.url || '—') + '</td>'
          +   '<td>' + scorePill(parseInt(a.score, 10) || 0) + '</td>'
          +   '<td class="mono">' + totalIssues + '</td>'
          +   '<td>' + statusBadge + '</td>'
          +   '<td style="color:var(--lgse-purple);font-size:10px;text-align:right">View →</td>'
          + '</tr>';
      });
      html += '</tbody></table>';

      body.innerHTML = html;
    }).catch(function () {
      body.innerHTML = emptyState('⚠', 'Could not load reports', 'Check your connection and try again.');
    });
  };

  // P0-13: open the matching audit's detail view in the Audit tab.
  // Uses the existing window.lgseShowAuditDetail (passes id, not the full row).
  window.lgseShowRunDetail = function (id) {
    if (!id) return;
    if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('audit');
    setTimeout(function () {
      if (typeof window.lgseShowAuditDetail === 'function') window.lgseShowAuditDetail(id);
    }, 80);
  };

  window.lgseReportsRange = function (days, el) {
    // Visual-only filter for now — backend /audits doesn't take a date range.
    Array.prototype.forEach.call(document.querySelectorAll('[onclick*="lgseReportsRange"]'), function (p) {
      p.style.background = 'var(--lgse-bg2)';
      p.style.borderColor = 'var(--lgse-border)';
      p.style.color = 'var(--lgse-t3)';
    });
    if (el) {
      el.style.background = 'rgba(108,92,231,0.08)';
      el.style.borderColor = 'var(--lgse-purple)';
      el.style.color = 'var(--lgse-purple)';
    }
    // No-op refetch for now; future: pass since=… to /audits when supported.
  };
  window.lgseDownloadReport = async function (kind) {
    // ITEM-3 — backend's /reports/audit/pdf returns HTML wrapped as text/html
    // (no real PDF rendering library wired). For 'pdf' we open the HTML report
    // in a new tab + toast the user to use browser Print → Save as PDF.
    // 'html' keeps its original direct-download behaviour.
    try {
      var token = localStorage.getItem('lu_token') || '';
      if (kind === 'pdf') {
        var resp = await fetch(window.location.origin + '/api/seo/reports/audit/html', { headers: { 'Authorization': 'Bearer ' + token } });
        if (!resp.ok) { if (typeof window.showToast === 'function') window.showToast('Could not open report', 'error'); return; }
        var html = await resp.text();
        var blob = new Blob([html], { type: 'text/html' });
        var url  = URL.createObjectURL(blob);
        var w = window.open(url, '_blank', 'noopener');
        if (!w) {
          // Popup blocked — fall through to download.
          var a = document.createElement('a');
          a.href = url; a.download = 'seo-audit-' + Date.now() + '.html';
          document.body.appendChild(a); a.click(); document.body.removeChild(a);
        }
        if (typeof window.showToast === 'function') {
          window.showToast('Use browser File → Print → Save as PDF', 'info');
        }
        // Revoke after a delay so the new tab has time to fetch the blob.
        setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
        return;
      }
      // HTML direct-download path.
      var r = await fetch(window.location.origin + '/api/seo/reports/audit/html', { headers: { 'Authorization': 'Bearer ' + token } });
      if (!r.ok) { if (typeof window.showToast === 'function') window.showToast('Download failed', 'error'); return; }
      var b = await r.blob();
      var u = URL.createObjectURL(b);
      var ah = document.createElement('a');
      ah.href = u; ah.download = 'seo-audit-' + Date.now() + '.html';
      document.body.appendChild(ah); ah.click(); document.body.removeChild(ah);
      URL.revokeObjectURL(u);
    } catch (e) { /* silent */ }
  };
  window.lgseExportCsv = async function (type) {
    try {
      var token = localStorage.getItem('lu_token') || '';
      var resp = await fetch(window.location.origin + '/api/seo/reports/export/' + type, { headers: { 'Authorization': 'Bearer ' + token } });
      if (!resp.ok) { if (typeof window.showToast === 'function') window.showToast('Export failed', 'error'); return; }
      var blob = await resp.blob();
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'seo-' + type + '-' + Date.now() + '.csv';
      document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
      if (typeof window.showToast === 'function') window.showToast(type + ' exported (' + Math.round(blob.size / 1024) + ' KB)', 'success');
    } catch (e) { /* silent */ }
  };

  // ── New Goal modal (Pipeline tab header CTA) ──────────────────────────
  // 2026-05-13 — submits to POST /api/connector/pipeline/submit-goal which
  // inserts a tasks row with engine='seo', action='agent_goal'. The
  // orchestrator picks it up and dispatches to the agent team. Re-uses the
  // existing lgseShowModal helper for theme + close-on-backdrop + inline
  // error display.
  window._seoNewGoalModal = function () {
    if (typeof window.lgseShowModal !== 'function') {
      console.warn('[SEO] lgseShowModal not available');
      return;
    }
    var bodyHtml =
        '<div style="margin-bottom:10px;color:var(--lgse-t2,#94a3b8);font-size:12.5px;line-height:1.5">'
      +   'Describe what you want done. The SEO team picks it up, plans, '
      +   'and quotes credit costs before executing anything paid.'
      + '</div>'
      + '<textarea id="lgse-goal-text" rows="5" '
      +   'placeholder="e.g. Improve the homepage SEO score by fixing thin content and broken outbound links" '
      +   'style="width:100%;padding:10px 12px;background:var(--lgse-bg2,#0f172a);'
      +   'border:1px solid var(--lgse-border,#1e293b);border-radius:8px;color:var(--lgse-t1,#fff);'
      +   'font-size:13px;line-height:1.5;font-family:inherit;resize:vertical;min-height:120px;'
      +   'box-sizing:border-box"></textarea>'
      + '<div style="margin-top:6px;font-size:11px;color:var(--lgse-t3,#64748b)">'
      +   'Minimum 5 characters. Submitting uses no credits.'
      + '</div>';

    window.lgseShowModal('New SEO goal', bodyHtml, function (overlay) {
      if (typeof lgseClearModalError === 'function') lgseClearModalError();
      var ta = document.getElementById('lgse-goal-text');
      var goal = ta ? String(ta.value || '').trim() : '';
      if (goal.length < 5) {
        if (typeof lgseModalError === 'function') {
          lgseModalError('Goal must be at least 5 characters.');
        }
        return;
      }
      var btn = document.getElementById('lgse-modal-save');
      if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }

      if (typeof window._luFetch !== 'function') {
        if (typeof lgseModalError === 'function') {
          lgseModalError('Cannot reach the API (auth helper missing).');
        }
        if (btn) { btn.disabled = false; btn.textContent = 'Submit goal'; }
        return;
      }

      window._luFetch('POST', '/connector/pipeline/submit-goal', { goal_text: goal })
        .then(function (r) {
          return r.json().catch(function () { return { success: false, error: 'bad_json' }; });
        })
        .then(function (j) {
          if (!j || !j.success) {
            var msg = (j && (j.error || j.message)) || 'Submit failed.';
            if (typeof lgseModalError === 'function') lgseModalError(String(msg));
            if (btn) { btn.disabled = false; btn.textContent = 'Submit goal'; }
            return;
          }
          if (overlay && overlay.remove) overlay.remove();
          // Refresh the Pipeline tab Queue sub-tab so the new task appears.
          window._lgsePipeTab = 'queue';
          if (typeof window.lgseSwitchTab === 'function') {
            window.lgseSwitchTab('pipeline');
          }
          if (typeof window.showToast === 'function') {
            window.showToast('Goal submitted (#' + (j.task_id || '?') + ').', 'success');
          }
        })
        .catch(function (e) {
          if (typeof lgseModalError === 'function') {
            lgseModalError('Submit failed: ' + (e && e.message ? e.message : 'network error'));
          }
          if (btn) { btn.disabled = false; btn.textContent = 'Submit goal'; }
        });
    }, { saveLabel: 'Submit goal' });
  };

  // ── Tab — Pipeline (Queue + Calendar sub-tabs) ────────────────────────
  // 2026-05-13 — canonical-block implementation. Hits /api/connector/*
  // via _luFetch (embed X-API-KEY routing); _seoApi only handles /api/seo/*.
  function renderPipeline(el) {
    if (!window._lgsePipeTab)   window._lgsePipeTab   = 'queue';
    if (!window._lgsePipeMonth) {
      var __now = new Date();
      window._lgsePipeMonth = __now.getFullYear() + '-' + String(__now.getMonth() + 1).padStart(2, '0');
    }

    function pipFetch(path) {
      if (typeof window._luFetch === 'function') {
        return window._luFetch('GET', path, null).then(function (r) { return r.json(); });
      }
      var t = localStorage.getItem('lu_token') || '';
      return fetch(window.location.origin + '/api' + path, {
        headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + t },
        cache: 'no-store',
      }).then(function (r) { return r.json(); });
    }

    function fmtDate(s) {
      if (!s) return '';
      return String(s).slice(0, 10);
    }

    function shiftMonth(ym, delta) {
      var p = ym.split('-'); var yr = parseInt(p[0], 10); var mo = parseInt(p[1], 10) + delta;
      if (mo < 1)  { mo = 12; yr--; }
      if (mo > 12) { mo = 1;  yr++; }
      return yr + '-' + String(mo).padStart(2, '0');
    }

    var subQ = window._lgsePipeTab === 'queue';
    var subC = window._lgsePipeTab === 'calendar';
    el.innerHTML =
        '<div style="display:flex;gap:8px;align-items:center;margin-bottom:18px;border-bottom:1px solid var(--bd,#1e293b)">' +
          '<button onclick="window._lgsePipeNav(\'queue\')" '
            + 'style="padding:10px 16px;cursor:pointer;background:transparent;border:none;border-bottom:2px solid '
            + (subQ ? 'var(--p,#7C3AED)' : 'transparent') + ';color:' + (subQ ? 'var(--p,#7C3AED)' : 'var(--lgse-t3,#94a3b8)')
            + ';font-size:13px;font-weight:600">Queue</button>' +
          '<button onclick="window._lgsePipeNav(\'calendar\')" '
            + 'style="padding:10px 16px;cursor:pointer;background:transparent;border:none;border-bottom:2px solid '
            + (subC ? 'var(--p,#7C3AED)' : 'transparent') + ';color:' + (subC ? 'var(--p,#7C3AED)' : 'var(--lgse-t3,#94a3b8)')
            + ';font-size:13px;font-weight:600">Calendar</button>' +
          // 2026-05-13 — New Goal CTA, right-aligned via margin-left:auto.
          '<button onclick="window._seoNewGoalModal()" class="lgse-btn-primary" '
            + 'style="margin-left:auto;margin-bottom:6px;font-size:12px;padding:7px 14px">+ New Goal</button>' +
        '</div>' +
        '<div id="lgse-pipe-body" style="color:var(--lgse-t3,#94a3b8);font-size:13px">Loading…</div>';

    var body = document.getElementById('lgse-pipe-body');
    if (!body) return;

    if (subQ) {
      pipFetch('/connector/content/pipeline').then(function (r) {
        if (!r || !r.success) { body.innerHTML = '<p>Could not load pipeline.</p>'; return; }
        var counts = r.counts || {};
        var all = (r.pipeline.queued || [])
          .concat(r.pipeline.running || [])
          .concat(r.pipeline.completed || [])
          .concat(r.pipeline.failed || []);
        var typeC = {
          generate_article: '#7C3AED', write_article: '#7C3AED', optimize_article: '#8B5CF6',
          improve_draft: '#8B5CF6',    deep_audit: '#3B82F6', serp_analysis: '#06B6D4',
          keyword_research: '#06B6D4', bulk_generate_meta: '#F59E0B', generate_meta: '#F59E0B',
          generate_image: '#EC4899',   autonomous_goal: '#00E5A8', agent_goal: '#F59E0B',
          link_suggestions: '#10B981',
        };
        var html = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">';
        ['queued', 'running', 'completed', 'failed'].forEach(function (s) {
          html += '<div style="background:#1e293b;border-radius:8px;padding:12px;text-align:center">'
                +   '<div style="font-size:22px;font-weight:700;color:#fff">' + (counts[s] || 0) + '</div>'
                +   '<div style="font-size:12px;color:#94a3b8;text-transform:capitalize">' + s + '</div>'
                + '</div>';
        });
        html += '</div>';
        if (!all.length) {
          html += '<p style="color:#94a3b8;padding:18px;background:#0f172a;border-radius:8px">No tasks yet. Generate an article or run an audit to populate.</p>';
        } else {
          html += '<table style="width:100%;border-collapse:collapse;font-size:13px">'
                +   '<tr style="color:#64748b;border-bottom:1px solid #1e293b">'
                +     '<th style="text-align:left;padding:8px">Type</th>'
                +     '<th style="text-align:left;padding:8px">Status</th>'
                +     '<th style="text-align:left;padding:8px">Created</th>'
                +   '</tr>';
          all.forEach(function (t) {
            var c = typeC[t.task_type] || '#64748b';
            html += '<tr style="border-bottom:1px solid #1e293b">'
                  +   '<td style="padding:8px"><span style="background:' + c + '22;color:' + c
                  +     ';padding:2px 8px;border-radius:4px;font-size:11px">' + (t.task_type || 'task') + '</span></td>'
                  +   '<td style="padding:8px;color:#94a3b8">' + (t.status || '') + '</td>'
                  +   '<td style="padding:8px;color:#64748b">' + fmtDate(t.created_at) + '</td>'
                  + '</tr>';
          });
          html += '</table>';
        }
        body.innerHTML = html;
        if ((counts.running || 0) > 0) {
          setTimeout(function () {
            if (window._lgsePipeTab === 'queue') {
              var c = document.getElementById('lgse-content');
              if (c) renderPipeline(c);
            }
          }, 8000);
        }
      }).catch(function () {
        body.innerHTML = '<p style="color:#ef4444">Pipeline fetch failed.</p>';
      });
    } else {
      pipFetch('/connector/content/calendar?month=' + window._lgsePipeMonth).then(function (r) {
        if (!r || !r.success) { body.innerHTML = '<p>Could not load calendar.</p>'; return; }
        var days = r.days || {};
        var parts = window._lgsePipeMonth.split('-');
        var yr = parseInt(parts[0], 10);
        var mo = parseInt(parts[1], 10) - 1;
        var firstDay = new Date(yr, mo, 1).getDay();
        var daysInMonth = new Date(yr, mo + 1, 0).getDate();
        var monthNames = ['January','February','March','April','May','June',
                          'July','August','September','October','November','December'];
        var html = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">'
                 +   '<button onclick="window._lgsePipeNav(null, \'' + shiftMonth(window._lgsePipeMonth, -1) + '\')" '
                 +     'style="background:#1e293b;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer">←</button>'
                 +   '<strong style="color:#fff">' + monthNames[mo] + ' ' + yr + '</strong>'
                 +   '<button onclick="window._lgsePipeNav(null, \'' + shiftMonth(window._lgsePipeMonth, +1) + '\')" '
                 +     'style="background:#1e293b;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer">→</button>'
                 + '</div>';
        html += '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px">';
        ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(function (d) {
          html += '<div style="text-align:center;font-size:11px;color:#64748b;padding:4px">' + d + '</div>';
        });
        var startOffset = (firstDay + 6) % 7;
        for (var i = 0; i < startOffset; i++) {
          html += '<div style="min-height:60px"></div>';
        }
        for (var d = 1; d <= daysInMonth; d++) {
          var key = yr + '-' + String(mo + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
          var items = days[key] || [];
          var cellHtml = '<div style="background:#1e293b;border-radius:6px;padding:6px;min-height:60px">'
                       +   '<div style="font-size:11px;color:#64748b;margin-bottom:4px">' + d + '</div>';
          items.slice(0, 3).forEach(function (item) {
            var col = item.status === 'completed' ? '#10b981'
                    : item.type === 'task'        ? '#3B82F6'
                    :                                '#F59E0B';
            var title = (item.title || 'Task');
            var safeTitle = String(title).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            cellHtml += '<div style="background:' + col + '22;color:' + col
                      + ';font-size:10px;padding:2px 4px;border-radius:3px;margin-bottom:2px;'
                      + 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + safeTitle + '">'
                      + safeTitle.slice(0, 20) + '</div>';
          });
          if (items.length > 3) {
            cellHtml += '<div style="font-size:10px;color:#64748b">+' + (items.length - 3) + ' more</div>';
          }
          cellHtml += '</div>';
          html += cellHtml;
        }
        html += '</div>';
        body.innerHTML = html;
      }).catch(function () {
        body.innerHTML = '<p style="color:#ef4444">Calendar fetch failed.</p>';
      });
    }
  }

  // ── Tab — Write Article (embed mode only) ────────────────────────────
  // 2026-05-13 — Simple form that calls /connector/generate-article (2cr,
  // 1 text + 1 mini featured image). Goes via _luFetch so X-API-KEY routes
  // correctly in embed context.
  function renderWrite(el) {
    el.innerHTML =
        '<div style="max-width:680px;margin:0 auto;padding:8px 0">'
      +   '<h2 style="color:var(--lgse-t1,#fff);font-size:18px;font-weight:600;margin:0 0 4px">Write Article</h2>'
      +   '<p style="color:var(--lgse-t3,#94a3b8);font-size:13px;margin:0 0 24px">'
      +     'Generate an SEO-optimised article with a featured image. Uses <strong style="color:#7C3AED">2 credits</strong>.'
      +   '</p>'
      +   '<div style="display:grid;gap:14px">'
      +     '<div>'
      +       '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Focus Keyword <span style="color:#EF4444">*</span></label>'
      +       '<input id="lgse-w-keyword" type="text" placeholder="e.g. business setup in Dubai" '
      +         'style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:10px 12px;color:#fff;font-size:13px;box-sizing:border-box">'
      +     '</div>'
      +     '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
      +       '<div>'
      +         '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Tone</label>'
      +         '<select id="lgse-w-tone" style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:10px 12px;color:#fff;font-size:13px">'
      +           '<option value="professional">Professional</option>'
      +           '<option value="informative">Informative</option>'
      +           '<option value="authoritative">Authoritative</option>'
      +         '</select>'
      +       '</div>'
      +       '<div>'
      +         '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Language</label>'
      +         '<select id="lgse-w-lang" style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:10px 12px;color:#fff;font-size:13px">'
      +           '<option value="English">English</option>'
      +           '<option value="Arabic">Arabic</option>'
      +         '</select>'
      +       '</div>'
      +     '</div>'
      +     '<div>'
      +       '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Word Count</label>'
      +       '<div style="display:flex;gap:8px;align-items:center">'
      +         '<input id="lgse-w-min" type="number" value="800" min="300" max="3000" '
      +           'style="width:90px;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:8px 10px;color:#fff;font-size:13px">'
      +         '<span style="color:#64748b">to</span>'
      +         '<input id="lgse-w-max" type="number" value="1500" min="500" max="5000" '
      +           'style="width:90px;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:8px 10px;color:#fff;font-size:13px">'
      +       '</div>'
      +     '</div>'
      +     '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
      +       '<div>'
      +         '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">FAQs to include</label>'
      +         '<input id="lgse-w-faq" type="number" value="3" min="0" max="10" '
      +           'style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:8px 12px;color:#fff;font-size:13px;box-sizing:border-box">'
      +       '</div>'
      +       '<div style="display:flex;align-items:center;gap:8px;padding-top:20px">'
      +         '<input type="checkbox" id="lgse-w-cta" checked style="width:16px;height:16px;accent-color:#7C3AED">'
      +         '<label for="lgse-w-cta" style="color:#94a3b8;font-size:13px;cursor:pointer">Include CTA section</label>'
      +       '</div>'
      +     '</div>'
      +     '<div>'
      +       '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Extra context (optional)</label>'
      +       '<textarea id="lgse-w-context" rows="2" placeholder="Target audience, specific points to cover, location..." '
      +         'style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:10px 12px;color:#fff;font-size:13px;box-sizing:border-box;resize:vertical"></textarea>'
      +     '</div>'
      +     '<p style="color:#64748b;font-size:12px;margin:0">💳 This will use <strong style="color:#7C3AED">2 credits</strong> (1 text + 1 featured image).</p>'
      +     '<button id="lgse-w-btn" onclick="window._lgseWriteGenerate()" '
      +       'style="background:linear-gradient(135deg,#7C3AED,#3B82F6);color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:14px;font-weight:600;cursor:pointer;width:100%">Generate Article</button>'
      +     '<div id="lgse-w-result" style="display:none;background:#1e293b;border-radius:8px;padding:16px;margin-top:8px">'
      +       '<div id="lgse-w-result-inner"></div>'
      +     '</div>'
      +   '</div>'
      + '</div>';
  }

  window._lgseWriteGenerate = function () {
    var $ = function (id) { return document.getElementById(id); };
    var kwEl = $('lgse-w-keyword');
    var keyword = kwEl ? (kwEl.value || '').trim() : '';
    if (!keyword) { alert('Please enter a focus keyword.'); return; }

    var btn    = $('lgse-w-btn');
    var result = $('lgse-w-result');
    var inner  = $('lgse-w-result-inner');
    if (!btn || !result || !inner) return;

    btn.disabled = true;
    btn.textContent = 'Generating… (this can take 60-90s)';
    result.style.display = 'none';

    var siteUrl = '';
    try {
      var sp = new URLSearchParams(window.location.search);
      siteUrl = sp.get('lgsc_site') || (window._LGSC_EMBED && window._LGSC_EMBED.site_url) || '';
    } catch (_) {}

    var payload = {
      keyword:        keyword,
      tone:           ($('lgse-w-tone') || {}).value || 'professional',
      language:       ($('lgse-w-lang') || {}).value || 'English',
      word_count_min: parseInt(($('lgse-w-min') || {}).value || 800, 10),
      word_count_max: parseInt(($('lgse-w-max') || {}).value || 1500, 10),
      faq_count:      parseInt(($('lgse-w-faq') || {}).value || 3, 10),
      include_cta:    ($('lgse-w-cta') || {}).checked !== false,
      extra_context:  ($('lgse-w-context') || {}).value || '',
      embed_context:  'seo_plugin',
      scope:          'seo_optimised',
      site_url:       siteUrl,
    };

    var fetcher = (typeof window._luFetch === 'function')
      ? window._luFetch('POST', '/connector/generate-article', payload).then(function (r) { return r.json(); })
      : fetch(window.location.origin + '/api/connector/generate-article', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
          },
          body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); });

    fetcher.then(function (d) {
      btn.disabled = false;
      btn.textContent = 'Generate Article';
      if (!d || !d.success) {
        inner.innerHTML = '<p style="color:#f87171;margin:0">' + esc((d && (d.error || d.message)) || 'Generation failed. Please try again.') + '</p>';
        result.style.display = 'block';
        return;
      }
      var imgHtml = d.image_url
        ? '<img src="' + esc(d.image_url) + '" alt="" style="width:80px;height:60px;object-fit:cover;border-radius:6px;flex-shrink:0;margin-left:12px">'
        : '';
      var imgFailedHtml = d.image_failed
        ? ' · <span style="color:#f59e0b">Image generation failed (refunded)</span>'
        : '';
      inner.innerHTML =
          '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">'
        +   '<div style="min-width:0">'
        +     '<div style="color:#fff;font-size:15px;font-weight:600;margin-bottom:4px">' + esc(d.title || keyword) + '</div>'
        +     '<div style="color:#64748b;font-size:12px">'
        +       (d.word_count || 0) + ' words · ' + (d.credits_used || 2) + ' credits used'
        +       imgFailedHtml
        +     '</div>'
        +   '</div>'
        +   imgHtml
        + '</div>'
        + '<div style="color:#94a3b8;font-size:12px;margin-bottom:12px">' + esc(d.meta_description || '') + '</div>'
        + '<p style="color:#00E5A8;font-size:12px;margin:0">✓ Article saved.</p>'
        + '<p style="color:#64748b;font-size:11px;margin:4px 0 0">Go to WordPress Posts to review, edit, and publish.</p>';
      result.style.display = 'block';
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = 'Generate Article';
      inner.innerHTML = '<p style="color:#f87171;margin:0">Request failed. Please try again.</p>';
      result.style.display = 'block';
    });
  };

  // Global trampoline for the Pages-tab image regenerate cell.
  window._lgseRegenImage = function (pageId, pageUrl, pageTitle, el) {
    if (!confirm('Generate featured image for this page? Uses 1 credit.')) { return; }
    var cell = el;
    if (cell && cell.tagName === 'IMG') { cell = cell.parentElement; }
    if (cell) { cell.style.opacity = '0.4'; }

    var payload = {
      page_id: pageId,
      url:     pageUrl,
      title:   pageTitle,
      force:   true,
    };
    var fetcher = (typeof window._luFetch === 'function')
      ? window._luFetch('POST', '/connector/pages/regenerate-image', payload).then(function (r) { return r.json(); })
      : fetch(window.location.origin + '/api/connector/pages/regenerate-image', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
          },
          body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); });

    fetcher.then(function (d) {
      if (cell) { cell.style.opacity = '1'; }
      if (!d || !d.success || !d.image_url) {
        alert('Image generation failed: ' + ((d && (d.error || d.message)) || 'unknown error'));
        return;
      }
      // Replace cell contents with the new image.
      if (cell) {
        var safeUrl = encodeURIComponent(pageUrl || '');
        var safeTitle = encodeURIComponent(pageTitle || '');
        cell.outerHTML = '<td><img src="' + d.image_url + '" '
          + 'style="width:44px;height:36px;object-fit:cover;border-radius:4px;cursor:pointer" '
          + 'title="Regenerate featured image (1 credit)" '
          + 'onclick="window._lgseRegenImage(' + pageId + ',decodeURIComponent(\'' + safeUrl + '\'),decodeURIComponent(\'' + safeTitle + '\'),this)"></td>';
      }
    }).catch(function () {
      if (cell) { cell.style.opacity = '1'; }
      alert('Request failed. Please try again.');
    });
  };

  // Global trampoline so the injected onclick handlers (global scope) can
  // re-enter the IIFE-private renderPipeline.
  window._lgsePipeNav = function (tab, month) {
    if (tab)   window._lgsePipeTab   = tab;
    if (month) window._lgsePipeMonth = month;
    var c = document.getElementById('lgse-content');
    if (c) renderPipeline(c);
  };

  // ── Override entry point ───────────────────────────────────────────────
  window.seoLoad = function (el) {
    if (!el) return;
    el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%;background:var(--bg, #0F1117)';
    var inner = document.createElement('div');
    inner.style.cssText = 'flex:1;overflow-y:auto;padding:20px';
    el.innerHTML = '';
    el.appendChild(inner);
    loadSiteList(); // P0-AUD-FIX2 — populate window._lgseSites from audit history.
    buildShell(inner);
  };
})();


// 2026-05-15 — AI Assistant drawer global helpers.
// Window-attached so the inline onclick handlers injected by
// _lgseInjectAiDrawer can reach them. Also: hide FAB when leaving SEO.

window._lgseDrawerOpen = function () {
  var d = document.getElementById('lgse-ai-drawer');
  var o = document.getElementById('lgse-ai-overlay');
  if (d) { d.style.display = 'flex'; }
  if (o) { o.style.display = 'block'; }
  setTimeout(function () {
    var inp = document.getElementById('lgse-drawer-input');
    if (inp) { inp.focus(); }
  }, 100);
};

window._lgseDrawerClose = function () {
  var d = document.getElementById('lgse-ai-drawer');
  var o = document.getElementById('lgse-ai-overlay');
  if (d) { d.style.display = 'none'; }
  if (o) { o.style.display = 'none'; }
};

window._lgseDrawerSuggest = function (q) {
  var inp = document.getElementById('lgse-drawer-input');
  if (inp && q) { inp.value = q; inp.focus(); }
};

// Called by core.js nav() when leaving the SEO engine.
window._lgseHideFab = function () {
  var fab = document.getElementById('lgse-ai-fab');
  if (fab) { fab.style.display = 'none'; }
  if (typeof window._lgseDrawerClose === 'function') { window._lgseDrawerClose(); }
};

window._lgseDrawerSend = function () {
  var inp = document.getElementById('lgse-drawer-input');
  var thread = document.getElementById('lgse-drawer-thread');
  var suggs = document.getElementById('lgse-drawer-suggestions');
  if (!inp || !thread) { return; }
  var msg = (inp.value || '').trim();
  if (!msg) { return; }

  function escMsg(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  if (suggs) { suggs.style.display = 'none'; }

  var userBubble = document.createElement('div');
  userBubble.style.cssText = 'align-self:flex-end;background:rgba(59,130,246,0.12);'
    + 'border:1px solid rgba(59,130,246,0.2);border-radius:12px;padding:12px 16px;max-width:90%';
  userBubble.innerHTML = '<div style="font-size:14px;line-height:1.6;color:#E5E7EB">'
    + escMsg(msg).replace(/\n/g, '<br>') + '</div>';
  thread.appendChild(userBubble);

  var typing = document.createElement('div');
  typing.id = 'lgse-drawer-typing';
  typing.style.cssText = 'background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.15);'
    + 'border-radius:12px;padding:14px 16px;max-width:90%';
  typing.innerHTML = '<div style="font-size:12px;font-weight:600;color:#A78BFA;margin-bottom:4px">LevelUp SEO</div>'
    + '<div style="color:#6B7280;font-size:13px">Thinking&hellip;</div>';
  thread.appendChild(typing);
  thread.scrollTop = thread.scrollHeight;
  inp.value = '';

  function lgseMarkdown(text) {
    return text
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/^### (.+)\n?/gm, '<h3 style="margin:8px 0 4px;font-size:14px;font-weight:600">$1</h3>')
      .replace(/^## (.+)\n?/gm, '<h2 style="margin:10px 0 6px;font-size:15px;font-weight:700">$1</h2>')
      .replace(/^- (.+)$/gm, '<li>$1</li>')
      .replace(/(<li>[\s\S]*?<\/li>(?:\n<li>[\s\S]*?<\/li>)*)/g, '<ul>$1</ul>')
      .replace(/\n\n/g, '</p><p>')
      .replace(/\n/g, '<br>');
  }
  function appendBot(text) {
    var t = document.getElementById('lgse-drawer-typing');
    if (t) { t.remove(); }
    var b = document.createElement('div');
    b.style.cssText = 'background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);'
      + 'border-radius:12px;padding:14px 16px;max-width:90%';
    b.innerHTML = '<div style="font-size:12px;font-weight:600;color:#A78BFA;margin-bottom:6px">LevelUp SEO</div>'
      + '<div style="font-size:14px;line-height:1.6;color:#E5E7EB"><p style="margin:0">'
      + lgseMarkdown(escMsg(text)) + '</p></div>';
    thread.appendChild(b);
    // 2026-05-12 chat-scroll fix: scroll to TOP of the new assistant
    // message so the user sees the START, not the end (long replies
    // were jumping past the opening line).
    b.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function appendErr(text) {
    var t = document.getElementById('lgse-drawer-typing');
    if (t) { t.remove(); }
    var e = document.createElement('div');
    e.style.cssText = 'background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.15);'
      + 'border-radius:12px;padding:12px 16px;max-width:90%';
    e.innerHTML = '<div style="color:#FCA5A5;font-size:13px">' + escMsg(text) + '</div>';
    thread.appendChild(e);
    e.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // 2026-05-12 fix — _luFetch returns the raw Response object; .json() it
  // explicitly so the downstream parser sees the actual body. Without this,
  // the chat shows "I could not get a response" because d.data is undefined
  // on a Response.
  var fetcher = typeof window._luFetch === 'function'
    ? window._luFetch('POST', '/connector/assistant/message', { message: msg }).then(function (r) { return r.json(); })
    : fetch(window.location.origin + '/api/connector/assistant/message', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
        },
        body: JSON.stringify({ message: msg }),
      }).then(function (r) { return r.json(); });

  fetcher.then(function (d) {
    var text = (d && d.data && d.data.response) ? d.data.response
             : (d && d.response) ? d.response
             : (d && d.reply) ? d.reply
             : 'I could not get a response. Please try again.';
    appendBot(text);
  }).catch(function () { appendErr('Connection error. Please try again.'); });
};

// Legacy tab-mode helpers — kept as no-ops so dead-code renderAssistant/Chatbot
// (still in the file but no longer in the renderers map) doesn't throw if
// someone calls them by URL hash.
window._lgseAssistantSuggest = function (btn) {
  var q = btn && btn.getAttribute ? btn.getAttribute('data-q') : null;
  if (q) { window._lgseDrawerSuggest(q); window._lgseDrawerOpen(); }
};

window._lgseAssistantSend = function () {
  var inp = document.getElementById('lgse-chat-input');
  var thread = document.getElementById('lgse-chat-thread');
  if (!inp || !thread) return;
  var msg = (inp.value || '').trim();
  if (!msg) return;

  function escMsg(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  var userBubble = document.createElement('div');
  userBubble.style.cssText = 'align-self:flex-end;background:rgba(59,130,246,0.15);border:1px solid rgba(59,130,246,0.2);border-radius:12px;padding:12px 16px;max-width:80%';
  userBubble.innerHTML = '<p style="font-size:14px;line-height:1.6;color:#E5E7EB;margin:0">' + escMsg(msg) + '</p>';
  thread.appendChild(userBubble);

  var typing = document.createElement('div');
  typing.id = 'lgse-typing';
  typing.style.cssText = 'background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);border-radius:12px;padding:14px 16px;max-width:80%';
  typing.innerHTML = '<span style="font-size:12px;font-weight:600;color:#A78BFA">James</span>' +
    '<p style="color:#6B7280;margin:4px 0 0;font-size:14px">Thinking…</p>';
  thread.appendChild(typing);
  thread.scrollTop = thread.scrollHeight;
  inp.value = '';

  function lgseMarkdown(text) {
    return text
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/^### (.+)\n?/gm, '<h3 style="margin:8px 0 4px;font-size:14px;font-weight:600">$1</h3>')
      .replace(/^## (.+)\n?/gm, '<h2 style="margin:10px 0 6px;font-size:15px;font-weight:700">$1</h2>')
      .replace(/^- (.+)$/gm, '<li>$1</li>')
      .replace(/(<li>[\s\S]*?<\/li>(?:\n<li>[\s\S]*?<\/li>)*)/g, '<ul>$1</ul>')
      .replace(/\n\n/g, '</p><p>')
      .replace(/\n/g, '<br>');
  }
  function appendBot(text) {
    var t = document.getElementById('lgse-typing');
    if (t) t.remove();
    var bot = document.createElement('div');
    bot.style.cssText = 'background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);border-radius:12px;padding:14px 16px;max-width:80%';
    bot.innerHTML =
      '<span style="font-size:12px;font-weight:600;color:#A78BFA">James</span>' +
      '<p style="font-size:14px;line-height:1.6;color:#E5E7EB;margin:4px 0 0">' +
        lgseMarkdown(escMsg(text)) +
      '</p>';
    thread.appendChild(bot);
    bot.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function appendErr(text) {
    var t = document.getElementById('lgse-typing');
    if (t) t.remove();
    var err = document.createElement('div');
    err.style.cssText = 'background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:12px;padding:12px 16px;max-width:80%';
    err.innerHTML = '<p style="color:#FCA5A5;margin:0;font-size:13px">' + escMsg(text) + '</p>';
    thread.appendChild(err);
    err.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Prefer _luFetch (core.js global) so embed-mode X-API-KEY routing works.
  // 2026-05-12 fix — _luFetch returns the raw Response object; .json() it
  // explicitly so the downstream parser sees the actual body. Without this,
  // the chat shows "I could not get a response" because d.data is undefined
  // on a Response.
  var fetcher = typeof window._luFetch === 'function'
    ? window._luFetch('POST', '/connector/assistant/message', { message: msg }).then(function (r) { return r.json(); })
    : fetch(window.location.origin + '/api/connector/assistant/message', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
        },
        body: JSON.stringify({ message: msg }),
      }).then(function (r) { return r.json(); });

  fetcher
    .then(function (d) {
      var response = (d && d.data && d.data.response) ? d.data.response
                   : (d && d.response) ? d.response
                   : (d && d.reply) ? d.reply
                   : 'I could not get a response. Please try again.';
      appendBot(response);
    })
    .catch(function () { appendErr('Connection failed. Please try again.'); });
};
