// LevelUp SEO Engine — Laravel native (replaces WP iframe)
// Maps to 25 routes under /api/seo/*

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
