// LevelUp MARKETING Engine — v2.1.0
// Patches: sequences view, template CRUD, direct REST calls, proper error handling

window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['marketing'] = true;

// ═══════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════
var _mkt = {
  campaigns:       [],
  templates:       [],
  sequences:       [],
  currentCampaign: null,
  filters:         { type: '', status: '' },
  loading:         false,
  page:            'dashboard',
};

// ═══════════════════════════════════════════════════════════════════
// API HELPER — direct REST, proper error handling (Patches 4+5+6)
// ═══════════════════════════════════════════════════════════════════
async function _mktApi(method, path, body) {
  const nonce = window.LU_CFG?.nonce || '';
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization':'Bearer '+(localStorage.getItem('lu_token')||'') },
    cache: 'no-store',
  };
  if (body) opts.body = JSON.stringify(body);
  let r;
  try { r = await fetch(window.location.origin + '/api' + path, opts); }
  catch (e) { throw new Error('Network error: ' + e.message); }

  // Patch 5: status-code-specific errors
  if (r.status === 400) { const d = await r.json().catch(() => ({})); throw Object.assign(new Error(d.message || d.error || 'Validation error'), { code: 400 }); }
  if (r.status === 401) throw Object.assign(new Error('Session expired — please refresh.'), { code: 401 });
  if (r.status === 402) throw Object.assign(new Error('Insufficient credits.'), { code: 402 });
  if (r.status === 429) throw Object.assign(new Error('Rate limited — please wait a moment.'), { code: 429 });
  if (!r.ok) { const d = await r.json().catch(() => ({})); throw new Error(d.message || d.error || 'Error ' + r.status); }
  return r.json();
}

// ═══════════════════════════════════════════════════════════════════
// LOAD + RENDER
// ═══════════════════════════════════════════════════════════════════
async function mktLoad(el) {
  if (!el) return;
  el.innerHTML = loadingCard(300);
  try {
    const [campaigns, templates, sequences] = await Promise.all([
      _mktApi('GET', '/marketing/campaigns'),
      _mktApi('GET', '/marketing/templates'),
      _mktApi('GET', '/marketing/sequences'),
    ]);
    _mkt.campaigns  = Array.isArray(campaigns)  ? campaigns  : (campaigns?.campaigns  || []);
    _mkt.templates  = Array.isArray(templates)  ? templates  : (templates?.templates  || []);
    _mkt.sequences  = Array.isArray(sequences)  ? sequences  : (sequences?.sequences  || []);
    _mktRender(el);
  } catch(e) {
    console.error('[Marketing]', e);
    el.innerHTML = `<div style="padding:60px;text-align:center;color:var(--t2)">
      <div style="font-size:32px;margin-bottom:12px">${window.icon('warning',14)}</div>
      <div style="font-size:14px;font-weight:600;margin-bottom:6px">Marketing failed to load</div>
      <div style="font-size:12px;color:var(--t3)">${friendlyError(e)}</div>
      <button class="btn btn-outline btn-sm" style="margin-top:16px" onclick="mktLoad(document.getElementById('marketing-root'))">↺ Retry</button></div>`;
  }
}

function _mktRender(el) {
  const C = _mkt.campaigns, T = _mkt.templates, S = _mkt.sequences;
  const sCls = {active:'badge-green',draft:'badge-amber',paused:'badge-grey',sent:'badge-blue',scheduled:'badge-purple',archived:'badge-grey'};
  const sClsDash = {active:'db-pub',draft:'db-draft',paused:'db-draft',sent:'db-sched',scheduled:'db-sched',archived:'db-draft'};
  const sent   = C.reduce((s,c) => s + (c.sent_count||0), 0);
  const active = C.filter(c => c.status==='active'||c.status==='scheduled').length;
  const avgOpen = C.filter(c=>c.open_rate).length
    ? (C.filter(c=>c.open_rate).reduce((s,c)=>s+parseFloat(c.open_rate||0),0)/C.filter(c=>c.open_rate).length).toFixed(1) : null;

  el.innerHTML = `
  <div style="max-width:1400px;padding-bottom:32px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div><h1 style="margin:0 0 3px;font-size:22px">Marketing</h1>
        <div style="font-size:13px;color:var(--t3)">${C.length} campaign${C.length!==1?'s':''} · ${T.length} template${T.length!==1?'s':''} · ${S.length} sequence${S.length!==1?'s':''}</div></div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline btn-sm" onclick="mktLoad(document.getElementById('marketing-root'))">↺ Refresh</button>
        <button class="btn btn-outline btn-sm" onclick="mktSetView('settings')">${window.icon('edit',14)} Settings</button>
        <button class="btn btn-primary btn-sm" onclick="mktNewCampaign()">+ New Campaign</button>
      </div>
    </div>

    <!-- NAV TABS -->
    <div style="display:flex;gap:0;margin-bottom:20px;border-bottom:1px solid var(--bd)">
      ${['dashboard','campaigns','templates','sequences','settings'].map(v => `
        <button class="dash-view-tab" data-mv="${v}" onclick="mktSetView('${v}',this)"
          style="color:${v==='dashboard'?'var(--da)':'var(--t3)'};font-weight:${v==='dashboard'?'600':'400'};
          border-bottom:2px solid ${v==='dashboard'?'var(--da)':'transparent'};
          padding:7px 14px;border-top:none;border-left:none;border-right:none;background:none;font-size:13px;cursor:pointer">
          ${{dashboard:'Dashboard',campaigns:'Campaigns',templates:'Templates',sequences:'Sequences',settings:'Settings'}[v]}
        </button>`).join('')}
    </div>

    <!-- DASHBOARD -->
    <div id="mkt-view-dashboard">
      <div class="dash-grid dash-stats" style="margin-bottom:20px">
        <div class="dash-stat"><div class="dash-stat-val">${sent>0?sent.toLocaleString():'—'}</div><div class="dash-stat-lbl">Emails Sent</div><div class="dash-stat-sub">total delivered</div></div>
        <div class="dash-stat"><div class="dash-stat-val">${avgOpen?avgOpen+'%':'—'}</div><div class="dash-stat-lbl">Open Rate</div><div class="dash-stat-sub">avg across campaigns</div></div>
        <div class="dash-stat"><div class="dash-stat-val">${active}</div><div class="dash-stat-lbl">Active</div><div class="dash-stat-sub">running or scheduled</div></div>
        <div class="dash-stat"><div class="dash-stat-val">${S.length}</div><div class="dash-stat-lbl">Sequences</div><div class="dash-stat-sub">automation</div></div>
      </div>
      <div class="dash-grid dash-body" style="margin-bottom:20px">
        <div>
          <div class="dash-card" style="margin-bottom:14px">
            <div class="dash-card-hdr">Quick Actions</div>
            <div class="dash-card-body">
              <button class="dash-qa-btn" onclick="mktNewCampaign()"><span class="qa-ico">${window.icon('rocket',14)}</span>Create Campaign</button>
              <button class="dash-qa-btn" onclick="mktNewTemplate()"><span class="qa-ico">${window.icon('more',14)}</span>New Template</button>
              <button class="dash-qa-btn" onclick="mktNewSequence()"><span class="qa-ico">${window.icon('ai',14)}</span>New Sequence</button>
            </div>
          </div>
          <div class="dash-card">
            <div class="dash-card-hdr">By Status</div>
            <div class="dash-card-body" style="padding:10px 14px">
              ${['draft','active','scheduled','sent','paused'].map(s=>{
                const count=C.filter(c=>(c.status||'draft')===s).length;
                return count>0?`<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--bd)"><span style="font-size:12px;color:var(--t2)">${s.charAt(0).toUpperCase()+s.slice(1)}</span><span class="dash-badge ${sClsDash[s]||'db-draft'}">${count}</span></div>`:'';
              }).filter(Boolean).join('')||`<div style="text-align:center;padding:16px;color:var(--t3);font-size:12px">No campaigns yet</div>`}
            </div>
          </div>
        </div>
        <div>
          <div class="dash-card">
            <div class="dash-card-hdr">Recent Campaigns <span>${C.length}</span></div>
            ${C.length===0
              ? `<div style="text-align:center;padding:40px 20px"><div style="font-size:36px;margin-bottom:12px">${window.icon('rocket',14)}</div><p style="color:var(--t3);margin:0 0 16px;font-size:13px">No campaigns yet.</p><button class="btn btn-primary btn-sm" onclick="mktNewCampaign()">+ New Campaign</button></div>`
              : `<div style="overflow:hidden"><table style="width:100%;border-collapse:collapse">
                  <thead><tr style="border-bottom:1px solid var(--bd)">
                    <th style="padding:8px 12px;text-align:left;font-size:11px;color:var(--t3);text-transform:uppercase">Campaign</th>
                    <th style="padding:8px 12px;text-align:left;font-size:11px;color:var(--t3);text-transform:uppercase">Status</th>
                    <th style="padding:8px 12px;text-align:right;font-size:11px;color:var(--t3);text-transform:uppercase">Sent</th>
                    <th style="padding:8px 12px"></th>
                  </tr></thead>
                  <tbody>${C.slice(0,8).map(c=>`<tr style="border-bottom:1px solid var(--bd)">
                    <td style="padding:8px 12px"><div style="font-size:13px;font-weight:600">${c.name||c.subject||'Untitled'}</div><div style="font-size:10px;color:var(--t3)">${c.type||'Email'}</div></td>
                    <td style="padding:8px 12px"><span class="dash-badge ${sClsDash[c.status||'draft']||'db-draft'}">${c.status||'draft'}</span></td>
                    <td style="padding:8px 12px;text-align:right;font-size:12px">${c.sent_count?c.sent_count.toLocaleString():'—'}</td>
                    <td style="padding:8px 12px"><div style="display:flex;gap:4px">
                      <button class="btn btn-primary btn-sm" onclick="mktSendCampaign(${c.id},'${(c.subject||c.name||'').replace(/'/g,'')}')">Send</button>
                      <button class="btn btn-outline btn-sm" onclick="mktEditCampaign(${c.id})">${window.icon('edit',14)}</button><button class="btn btn-outline btn-sm" onclick="mktCampaignAnalytics(${c.id}, ${JSON.stringify(c.name||'')})" title="Analytics">📊</button>
                      <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="mktDeleteCampaign(${c.id},'${(c.name||c.subject||'').replace(/'/g,'')}')">${window.icon('delete',14)}</button>
                    </div></td>
                  </tr>`).join('')}</tbody>
                </table></div>`}
          </div>
        </div>
        <div>
          <div class="dash-card">
            <div class="dash-card-hdr">Sequences <span>${S.length}</span></div>
            <div class="dash-card-body" style="max-height:300px;overflow-y:auto;padding:8px 14px">
              ${S.length===0
                ? `<div style="text-align:center;padding:20px;color:var(--t3);font-size:12px">No sequences yet.<br><a href="#" onclick="mktNewSequence();return false" style="color:var(--da)">Create one →</a></div>`
                : S.map(s=>`<div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--bd)">
                    <span style="font-size:18px">${window.icon('ai',14)}</span>
                    <div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${s.name||'Sequence'}</div>
                    <div style="font-size:10px;color:var(--t3)">${s.trigger_event||'manual'} · ${s.step_count||0} step${(s.step_count||0)!==1?'s':''}</div></div>
                    <button class="btn btn-ghost btn-sm" style="color:var(--rd);font-size:11px" onclick="mktDeleteSequence(${s.id},'${(s.name||'').replace(/'/g,'')}')">${window.icon('delete',14)}</button>
                  </div>`).join('')}
              <button class="btn btn-outline btn-sm" style="width:100%;margin-top:10px" onclick="mktNewSequence()">+ New Sequence</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- CAMPAIGNS VIEW -->
    <div id="mkt-view-campaigns" style="display:none">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div style="display:flex;gap:8px">
          <button class="tab active" data-mkt-tab="all"   onclick="mktSetTab(this,'all')">All</button>
          <button class="tab"        data-mkt-tab="email" onclick="mktSetTab(this,'email')">${window.icon('message',14)} Email</button>
          <button class="tab"        data-mkt-tab="ad"    onclick="mktSetTab(this,'ad')">${window.icon('rocket',14)} Ads</button>
        </div>
        <button class="btn btn-primary btn-sm" onclick="mktNewCampaign()">+ New Campaign</button>
      </div>
      ${C.length===0
        ? `<div class="card card-body" style="text-align:center;padding:60px 20px"><div style="font-size:40px;margin-bottom:14px">${window.icon('rocket',14)}</div><h3 style="margin:0 0 8px">No campaigns yet</h3><button class="btn btn-primary" onclick="mktNewCampaign()">+ New Campaign</button></div>`
        : `<div class="card"><div class="table-wrap"><table id="mkt-cmp-table"><thead><tr>
            <th>Campaign</th><th>Type</th><th>Status</th><th>Sent</th><th>Open Rate</th><th></th>
          </tr></thead><tbody>${C.map(c=>`<tr data-type="${c.type||'email'}">
            <td><strong>${c.name||c.subject||'Untitled'}</strong><div style="font-size:11px;color:var(--t3)">${c.created_at?new Date(c.created_at).toLocaleDateString():''}</div></td>
            <td><span class="badge badge-blue">${c.type||'Email'}</span></td>
            <td><span class="badge ${sCls[c.status||'draft']||'badge-grey'}">${c.status||'draft'}</span></td>
            <td>${c.sent_count?c.sent_count.toLocaleString():'—'}</td>
            <td>${c.open_rate?c.open_rate+'%':'—'}</td>
            <td><div style="display:flex;gap:4px">
              <button class="btn btn-primary btn-sm" onclick="mktSendCampaign(${c.id},'${(c.subject||c.name||'').replace(/'/g,'')}')">${window.icon('message',14)} Send</button>
              <button class="btn btn-outline btn-sm" onclick="mktEditCampaign(${c.id})">${window.icon('edit',14)}</button><button class="btn btn-outline btn-sm" onclick="mktCampaignAnalytics(${c.id}, ${JSON.stringify(c.name||'')})" title="Analytics">📊</button>
              <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="mktDeleteCampaign(${c.id},'${(c.name||c.subject||'').replace(/'/g,'')}')">${window.icon('delete',14)}</button>
            </div></td>
          </tr>`).join('')}</tbody></table></div></div>`}
    </div>

    <!-- TEMPLATES VIEW -->
    <div id="mkt-view-templates" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap">
        <div style="display:flex;gap:4px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:3px">
          <button id="mkt-tpl-tab-mine" class="mkt-tpl-tab active" onclick="mktSwitchTplTab('mine')" style="background:var(--p);color:#fff;border:none;padding:6px 14px;font-size:12px;font-weight:600;border-radius:5px;cursor:pointer;font-family:inherit">My Templates <span id="mkt-tpl-cnt-mine" style="opacity:.7;margin-left:4px"></span></button>
          <button id="mkt-tpl-tab-lib"  class="mkt-tpl-tab" onclick="mktSwitchTplTab('lib')" style="background:transparent;color:var(--t3);border:none;padding:6px 14px;font-size:12px;font-weight:600;border-radius:5px;cursor:pointer;font-family:inherit">Browse Library <span id="mkt-tpl-cnt-lib" style="opacity:.7;margin-left:4px"></span></button>
        </div>
        <button id="mkt-tpl-newbtn" class="btn btn-primary btn-sm" onclick="mktNewTemplate()">+ New Template</button>
      </div>

      <div id="mkt-tpl-mine">
      ${(T.filter(t => !t.is_system)).length===0
        ? `<div class="card card-body" style="text-align:center;padding:60px 20px"><div style="font-size:40px;margin-bottom:14px">${window.icon('more',14)}</div><h3 style="margin:0 0 8px">No templates yet</h3><p style="color:var(--t3);margin:0 0 20px;font-size:13px">Start from a library template, or create one from scratch.</p><button class="btn btn-outline" onclick="mktSwitchTplTab('lib')">Browse Library</button> <button class="btn btn-primary" onclick="mktNewTemplate()">+ New</button></div>`
        : `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">
            ${T.filter(t => !t.is_system).map(t=>`<div class="mkt-tpl-card" style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;background:var(--s2);transition:transform .15s,border-color .15s">
              <div class="mkt-tpl-thumb" style="aspect-ratio:3/4;background:var(--s3);overflow:hidden;position:relative;cursor:pointer" onclick="mktEditTemplate(${t.id})">
                ${t.thumbnail_url
                    ? `<img src="${_esc(t.thumbnail_url)}" alt="${_esc(t.name||'')}" style="width:100%;height:100%;object-fit:cover;object-position:top" loading="lazy"/>`
                    : `<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--t3);font-size:11px;text-transform:uppercase;letter-spacing:.08em">${_esc(t.category||'preview')}</div>`}
              </div>
              <div style="padding:12px 14px">
                <div style="font-weight:600;font-size:13px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${_esc(t.name||'')}">${_esc(t.name||'Template')}</div>
                <div style="font-size:10px;color:var(--t3);margin-bottom:10px;text-transform:capitalize">${_esc(t.category||'general')}</div>
                <div style="display:flex;gap:6px">
                  <button class="btn btn-outline btn-sm" style="flex:1" onclick="mktEditTemplate(${t.id})">${window.icon('edit',14)} Edit</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="mktDeleteTemplate(${t.id},'${(t.name||'').replace(/'/g,'')}')">${window.icon('delete',14)}</button>
                </div>
              </div>
            </div>`).join('')}
          </div>`}
      </div>

      <div id="mkt-tpl-lib" style="display:none">
      ${(T.filter(t => t.is_system)).length===0
        ? `<div class="card card-body" style="text-align:center;padding:60px 20px"><div style="font-size:40px;margin-bottom:14px">${window.icon('more',14)}</div><h3 style="margin:0 0 8px">Library is empty</h3><p style="color:var(--t3);margin:0;font-size:13px">No system templates have been published yet.</p></div>`
        : `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">
            ${T.filter(t => t.is_system).map(t=>`<div class="mkt-tpl-card" style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;background:var(--s2);transition:transform .15s,border-color .15s">
              <div class="mkt-tpl-thumb" style="aspect-ratio:3/4;background:var(--s3);overflow:hidden;position:relative;cursor:pointer" onclick="mktUseTemplate(${t.id},'${(t.name||'').replace(/'/g,"\\'")}')">
                ${t.thumbnail_url
                    ? `<img src="${_esc(t.thumbnail_url)}" alt="${_esc(t.name||'')}" style="width:100%;height:100%;object-fit:cover;object-position:top" loading="lazy"/>`
                    : `<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--t3);font-size:11px;text-transform:uppercase;letter-spacing:.08em">${_esc(t.category||'preview')}</div>`}
                <div style="position:absolute;top:8px;left:8px;background:rgba(108,92,231,.85);color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:.06em">SYSTEM</div>
              </div>
              <div style="padding:12px 14px">
                <div style="font-weight:600;font-size:13px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${_esc(t.name||'')}">${_esc(t.name||'Template')}</div>
                <div style="font-size:10px;color:var(--t3);margin-bottom:10px;text-transform:capitalize">${_esc(t.category||'general')}</div>
                <button class="btn btn-primary btn-sm" style="width:100%" onclick="mktUseTemplate(${t.id},'${(t.name||'').replace(/'/g,"\\'")}')">${window.icon('rocket',14)} Use Template</button>
              </div>
            </div>`).join('')}
          </div>`}
      </div>
    </div>

    <!-- SEQUENCES VIEW -->
    <div id="mkt-view-sequences" style="display:none">
      <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <button class="btn btn-primary btn-sm" onclick="mktNewSequence()">+ New Sequence</button>
      </div>
      ${S.length===0
        ? `<div class="card card-body" style="text-align:center;padding:60px 20px">
            <div style="font-size:40px;margin-bottom:14px">${window.icon('ai',14)}</div>
            <h3 style="margin:0 0 8px">No sequences yet</h3>
            <p style="color:var(--t3);margin:0 0 20px;font-size:13px">Automation sequences send emails automatically when a trigger fires.</p>
            <button class="btn btn-primary" onclick="mktNewSequence()">+ New Sequence</button>
          </div>`
        : `<div class="card"><div class="table-wrap"><table><thead><tr>
              <th>Sequence</th><th>Trigger</th><th>Steps</th><th>Status</th><th></th>
            </tr></thead><tbody>${S.map(s=>`<tr>
              <td><strong>${s.name||'Untitled'}</strong><div style="font-size:11px;color:var(--t3)">${s.description||''}</div></td>
              <td><span class="badge badge-blue">${s.trigger_event||'manual'}</span></td>
              <td style="font-size:12px">${s.step_count||0} step${(s.step_count||0)!==1?'s':''}</td>
              <td><span class="badge ${(s.status||'active')==='active'?'badge-green':'badge-grey'}">${s.status||'active'}</span></td>
              <td><div style="display:flex;gap:4px">
                <button class="btn btn-outline btn-sm" onclick="mktAddStep(${s.id},'${(s.name||'').replace(/'/g,'')}')">+ Step</button>
                <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="mktDeleteSequence(${s.id},'${(s.name||'').replace(/'/g,'')}')">${window.icon('delete',14)}</button>
              </div></td>
            </tr>`).join('')}</tbody></table></div></div>`}
    </div>

    <!-- SETTINGS VIEW -->
    <div id="mkt-view-settings" style="display:none">
      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
          <h3>Email Configuration</h3><span id="mkt-email-status-badge"></span>
        </div>
        <div class="card-body" style="padding:24px;max-width:520px">
          <p style="color:var(--t2);font-size:13px;margin:0 0 20px">Configure Postmark for reliable delivery. Without it, emails use wp_mail (may go to spam).</p>
          <div class="form-group" style="margin-bottom:16px"><label class="form-label">Postmark API Key</label><input class="form-input" id="mkt-pm-key" type="password" placeholder="Postmark Server API token"></div>
          <div class="form-group" style="margin-bottom:16px"><label class="form-label">Sender Email *</label><input class="form-input" id="mkt-sender-email" type="email" placeholder="hello@yourdomain.com"></div>
          <div class="form-group" style="margin-bottom:24px"><label class="form-label">Sender Name</label><input class="form-input" id="mkt-sender-name" placeholder="Your Company Name"></div>
          <div style="display:flex;gap:10px"><button class="btn btn-primary" id="mkt-settings-save">Save Settings</button><button class="btn btn-outline" id="mkt-test-email-btn">Send Test</button></div>
          <div id="mkt-settings-msg" style="margin-top:14px;font-size:13px;display:none"></div>
        </div>
      </div>
    </div>
  </div>`;

  // View switcher
  window.mktSetView = (view, btn) => {
    ['dashboard','campaigns','templates','sequences','settings'].forEach(v => {
      const e = document.getElementById('mkt-view-'+v);
      if (e) e.style.display = v===view ? '' : 'none';
    });
    document.querySelectorAll('[data-mv]').forEach(b => {
      const active = b.dataset.mv === view;
      b.style.borderBottomColor = active ? 'var(--da)' : 'transparent';
      b.style.color     = active ? 'var(--da)' : 'var(--t3)';
      b.style.fontWeight = active ? '600' : '400';
    });
    if (view === 'settings') window.mktLoadSettings();
  };

  // Tab filter
  window.mktSetTab = (btn, tab) => {
    document.querySelectorAll('[data-mkt-tab]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#mkt-cmp-table tbody tr').forEach(r => {
      r.style.display = (tab==='all' || r.dataset.type===tab) ? '' : 'none';
    });
  };
}

// ═══════════════════════════════════════════════════════════════════
// CAMPAIGNS
// ═══════════════════════════════════════════════════════════════════
window.mktNewCampaign = function() {
  const bd = _mktModal('New Campaign', `
    <div class="form-group"><label class="form-label">Campaign Name *</label><input class="form-input" id="mc-n" placeholder="e.g. Summer Promo"></div>
    <div class="form-group"><label class="form-label">Type</label>
      <select class="form-select" id="mc-t"><option value="email">Email Campaign</option><option value="ad">Ad Campaign</option><option value="social">Social Campaign</option></select></div>
    <div class="form-group"><label class="form-label">Subject Line</label><input class="form-input" id="mc-s" placeholder="Your email subject…"></div>
    <div class="form-group">
      <label class="form-label" style="display:flex;align-items:center;justify-content:space-between">
        <span>Body / Description</span>
        <button type="button" onclick="mktInsertImage('mc-b')" style="background:var(--s2,#1e2030);border:1px solid var(--bd,#2a2d3e);color:var(--t1,#e0e0e0);padding:4px 10px;border-radius:4px;cursor:pointer;font-size:11px">\uD83D\uDCF7 Insert Image</button>
      </label>
      <textarea class="form-input" id="mc-b" style="min-height:120px;resize:vertical" placeholder="Campaign body or brief&hellip;"></textarea>
    </div>`,
    [{label:'Cancel',cls:'btn-outline',close:true},{label:'+ Create',cls:'btn-primary',id:'mc-save'}]
  );
  bd.querySelector('#mc-save').onclick = async () => {
    const name = bd.querySelector('#mc-n').value.trim();
    if (!name) { showToast('Enter a campaign name.','error'); return; }
    _mktSetLoading(bd, '#mc-save', true, 'Creating…');
    try {
      const c = await _mktApi('POST', '/marketing/campaigns', {
        name, type: bd.querySelector('#mc-t').value,
        subject: bd.querySelector('#mc-s').value.trim(),
        body: bd.querySelector('#mc-b').value.trim(),
        status: 'draft',
      });
      _mkt.campaigns.unshift(c.campaign || c);
      bd.remove(); showToast('Campaign created!','success');
      mktLoad(document.getElementById('marketing-root'));
    } catch(e) { showToast(e.message,'error'); _mktSetLoading(bd,'#mc-save',false,'+ Create'); }
  };
};

window.mktEditCampaign = async function(id) {
  if (!id) return;
  let cmp = _mkt.campaigns.find(c => c.id === id) || {};
  if (!cmp.id) {
    try { cmp = await _mktApi('GET', '/marketing/campaigns/' + id); }
    catch(e) { showToast('Load failed: ' + e.message,'error'); return; }
  }
  const bd = _mktModal('Edit Campaign', `
    <div class="form-group"><label class="form-label">Name *</label><input class="form-input" id="me-n" value="${_esc(cmp.name||cmp.subject||'')}"></div>
    <div class="form-group"><label class="form-label">Subject</label><input class="form-input" id="me-s" value="${_esc(cmp.subject||'')}"></div>
    <div class="form-group">
      <label class="form-label" style="display:flex;align-items:center;justify-content:space-between">
        <span>Body</span>
        <button type="button" onclick="mktInsertImage('me-b')" style="background:var(--s2,#1e2030);border:1px solid var(--bd,#2a2d3e);color:var(--t1,#e0e0e0);padding:4px 10px;border-radius:4px;cursor:pointer;font-size:11px">\uD83D\uDCF7 Insert Image</button>
      </label>
      <textarea class="form-input" id="me-b" style="min-height:140px;resize:vertical;font-family:monospace;font-size:12px">${_esc(cmp.body_html||cmp.body||'')}</textarea>
    </div>
    <div class="form-group"><label class="form-label">Status</label>
      <select class="form-select" id="me-st">
        ${['draft','active','scheduled','paused','sent'].map(s=>`<option value="${s}"${(cmp.status||'draft')===s?' selected':''}>${s.charAt(0).toUpperCase()+s.slice(1)}</option>`).join('')}
      </select></div>`,
    [{label:'Cancel',cls:'btn-outline',close:true},{label:''+window.icon("save",14)+' Save',cls:'btn-primary',id:'me-save'}]
  );
  bd.querySelector('#me-save').onclick = async () => {
    const name = bd.querySelector('#me-n').value.trim();
    if (!name) { showToast('Enter a name.','error'); return; }
    _mktSetLoading(bd,'#me-save',true,'Saving…');
    try {
      const updated = await _mktApi('PUT', '/marketing/campaigns/' + id, {
        name, subject: bd.querySelector('#me-s').value.trim(),
        body_html: bd.querySelector('#me-b').value,
        status: bd.querySelector('#me-st').value,
      });
      const idx = _mkt.campaigns.findIndex(c=>c.id===id);
      if (idx>=0) _mkt.campaigns[idx] = Object.assign(_mkt.campaigns[idx], updated.campaign||updated);
      bd.remove(); showToast('Campaign updated!','success');
      mktLoad(document.getElementById('marketing-root'));
    } catch(e) { showToast(e.message,'error'); _mktSetLoading(bd,'#me-save',false,''+window.icon("save",14)+' Save'); }
  };
};

window.mktDeleteCampaign = async function(id, name) {
  if (!id) return;
  const ok = await luConfirm('Archive campaign "' + (name||'this campaign') + '"?', 'Archive Campaign','Archive','Keep');
  if (!ok) return;
  showToast('Archiving…','info');
  try {
    await _mktApi('DELETE', '/marketing/campaigns/' + id);
    _mkt.campaigns = _mkt.campaigns.filter(c=>c.id!==id);
    showToast('Campaign archived.','success');
    mktLoad(document.getElementById('marketing-root'));
  } catch(e) { showToast(e.message,'error'); }
};

window.mktSendCampaign = async function(id, name) {
  if (!id) return;
  // Check email config
  try {
    const cfg = await _mktApi('GET', '/marketing/email/settings');
    if (!cfg.configured) {
      const proceed = await luConfirm('Email is not fully configured. Emails may go to spam. Continue?','Email Not Configured','Send Anyway','Configure');
      if (!proceed) { mktSetView('settings'); return; }
    }
  } catch(e) { /* non-fatal */ }
  const bd = _mktModal('Send Campaign', `
    <p style="font-size:14px;margin:0 0 16px">Send <strong>${_esc(name||'this campaign')}</strong>?</p>
    <div class="form-group"><label class="form-label">Recipients (comma-separated emails or list name)</label>
      <input class="form-input" id="sc-to" placeholder="all-subscribers, or email@example.com"></div>
    <div class="form-group"><label class="form-label">Schedule (leave blank to send now)</label>
      <input type="datetime-local" class="form-input" id="sc-sched"></div>`,
    [{label:'Cancel',cls:'btn-outline',close:true},{label:''+window.icon("message",14)+' Send Now',cls:'btn-primary',id:'sc-btn'}]
  );
  bd.querySelector('#sc-btn').onclick = async () => {
    const recipients = bd.querySelector('#sc-to').value.trim();
    const scheduled_at = bd.querySelector('#sc-sched').value || null;
    _mktSetLoading(bd,'#sc-btn',true,'Sending…');
    try {
      await _mktApi('POST', '/marketing/campaign/send', { campaign_id: id, recipients, scheduled_at });
      bd.remove(); showToast('Campaign sent!','success');
      mktLoad(document.getElementById('marketing-root'));
    } catch(e) { showToast(e.message,'error'); _mktSetLoading(bd,'#sc-btn',false,''+window.icon("message",14)+' Send Now'); }
  };
};

// ═══════════════════════════════════════════════════════════════════
// TEMPLATES
// ═══════════════════════════════════════════════════════════════════
window.mktNewTemplate = function() {
  if (typeof window.openNewEmailTemplate === 'function') { window.openNewEmailTemplate(); }
  else { showToast('Email Builder not loaded','error'); }
};

window.mktEditTemplate = async function(id) {
  if (typeof window.openEmailBuilder === 'function') { window.openEmailBuilder(id); }
  else { showToast('Email Builder not loaded','error'); }
};

window.mktDeleteTemplate = async function(id, name) {
  const ok = await luConfirm('Delete template "' + (name||'this template') + '"?','Delete Template','Delete','Keep');
  if (!ok) return;
  showToast('Deleting…','info');
  try {
    await _mktApi('DELETE', '/marketing/templates/' + id);
    _mkt.templates = _mkt.templates.filter(t=>t.id!==id);
    showToast('Template deleted.','success');
    mktLoad(document.getElementById('marketing-root'));
  } catch(e) { showToast(e.message,'error'); }
};

// ═══════════════════════════════════════════════════════════════════
// SEQUENCES
// ═══════════════════════════════════════════════════════════════════
window.mktNewSequence = function() {
  const bd = _mktModal('New Sequence', `
    <div class="form-group"><label class="form-label">Sequence Name *</label><input class="form-input" id="ms-n" placeholder="e.g. Onboarding Flow"></div>
    <div class="form-group"><label class="form-label">Trigger Event</label>
      <select class="form-select" id="ms-tr">
        <option value="manual">Manual</option>
        <option value="lead_created">Lead Created</option>
        <option value="form_submit">Form Submit</option>
        <option value="tag_added">Tag Added</option>
      </select></div>
    <div class="form-group"><label class="form-label">Description</label><input class="form-input" id="ms-d" placeholder="What does this sequence do?"></div>`,
    [{label:'Cancel',cls:'btn-outline',close:true},{label:'+ Create',cls:'btn-primary',id:'msq-save'}]
  );
  bd.querySelector('#msq-save').onclick = async () => {
    const name = bd.querySelector('#ms-n').value.trim();
    if (!name) { showToast('Enter a sequence name.','error'); return; }
    _mktSetLoading(bd,'#msq-save',true,'Creating…');
    try {
      const s = await _mktApi('POST', '/marketing/sequences', {
        name, trigger_event: bd.querySelector('#ms-tr').value,
        description: bd.querySelector('#ms-d').value.trim(),
        status: 'active',
      });
      _mkt.sequences.unshift(s.sequence || s);
      bd.remove(); showToast('Sequence created!','success');
      mktLoad(document.getElementById('marketing-root'));
    } catch(e) { showToast(e.message,'error'); _mktSetLoading(bd,'#msq-save',false,'+ Create'); }
  };
};

window.mktDeleteSequence = async function(id, name) {
  const ok = await luConfirm('Delete sequence "' + (name||'this sequence') + '"?','Delete Sequence','Delete','Keep');
  if (!ok) return;
  showToast('Deleting…','info');
  try {
    await _mktApi('DELETE', '/marketing/sequences/' + id);
    _mkt.sequences = _mkt.sequences.filter(s=>s.id!==id);
    showToast('Sequence deleted.','success');
    mktLoad(document.getElementById('marketing-root'));
  } catch(e) { showToast(e.message,'error'); }
};

window.mktAddStep = function(seqId, seqName) {
  const bd = _mktModal('Add Step to "' + (seqName||'Sequence') + '"', `
    <div class="form-group"><label class="form-label">Step Type</label>
      <select class="form-select" id="mss-ty">
        <option value="email">${window.icon('message',14)} Send Email</option>
        <option value="delay">⏰ Wait / Delay</option>
        <option value="condition">🔀 Condition Check</option>
        <option value="tag">${window.icon('tag',14)} Add/Remove Tag</option>
      </select></div>
    <div class="form-group"><label class="form-label">Delay (days before this step)</label>
      <input type="number" class="form-input" id="mss-delay" value="0" min="0"></div>
    <div class="form-group"><label class="form-label">Subject (for email steps)</label>
      <input class="form-input" id="mss-subj" placeholder="Email subject…"></div>
    <div class="form-group"><label class="form-label">Content / Notes</label>
      <textarea class="form-input" id="mss-body" style="min-height:80px;resize:vertical" placeholder="Email body or step notes…"></textarea></div>`,
    [{label:'Cancel',cls:'btn-outline',close:true},{label:'+ Add Step',cls:'btn-primary',id:'mss-save'}]
  );
  bd.querySelector('#mss-save').onclick = async () => {
    _mktSetLoading(bd,'#mss-save',true,'Adding…');
    try {
      await _mktApi('POST', '/marketing/sequences/' + seqId + '/steps', {
        step_type:   bd.querySelector('#mss-ty').value,
        delay_days:  parseInt(bd.querySelector('#mss-delay').value,10)||0,
        subject:     bd.querySelector('#mss-subj').value.trim(),
        body:        bd.querySelector('#mss-body').value,
      });
      bd.remove(); showToast('Step added!','success');
      mktLoad(document.getElementById('marketing-root'));
    } catch(e) { showToast(e.message,'error'); _mktSetLoading(bd,'#mss-save',false,'+ Add Step'); }
  };
};

// ═══════════════════════════════════════════════════════════════════
// SETTINGS
// ═══════════════════════════════════════════════════════════════════
window.mktLoadSettings = async function() {
  const badge   = document.getElementById('mkt-email-status-badge');
  const nonce   = window.LU_CFG?.nonce || '';
  const headers = { 'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||'') };
  try {
    const r = await fetch(window.location.origin+'/api/marketing/email/settings', { headers, cache:'no-store' });
    const d = await r.json();
    if (document.getElementById('mkt-sender-email')) document.getElementById('mkt-sender-email').value = d.sender_email||'';
    if (document.getElementById('mkt-sender-name'))  document.getElementById('mkt-sender-name').value  = d.sender_name ||'';
    if (badge) badge.innerHTML = d.configured
      ? '<span style="color:#00b894;font-size:13px;font-weight:600">'+window.icon("check",14)+' Configured</span>'
      : '<span style="color:#fdcb6e;font-size:13px;font-weight:600">'+window.icon("warning",14)+' Not configured</span>';
  } catch(e) { if(badge) badge.innerHTML='<span style="color:var(--t3);font-size:12px">Could not load</span>'; }

  const saveBtn = document.getElementById('mkt-settings-save');
  if (saveBtn && !saveBtn.dataset.bound) {
    saveBtn.dataset.bound = '1';
    saveBtn.onclick = async function() {
      const api_key      = document.getElementById('mkt-pm-key')?.value.trim();
      const sender_email = document.getElementById('mkt-sender-email')?.value.trim();
      const sender_name  = document.getElementById('mkt-sender-name')?.value.trim();
      if (!sender_email) { showToast('Enter a sender email.','error'); return; }
      saveBtn.disabled=true; saveBtn.textContent='Saving…';
      try {
        const payload = { sender_email, sender_name };
        if (api_key) payload.postmark_api_key = api_key;
        const res = await _mktApi('POST', '/marketing/email/settings', payload);
        if (res.success) { showToast('Settings saved.','success'); if(document.getElementById('mkt-pm-key'))document.getElementById('mkt-pm-key').value=''; window.mktLoadSettings(); }
        else showToast(res.error||'Save failed.','error');
      } catch(e) { showToast(e.message,'error'); }
      finally { saveBtn.disabled=false; saveBtn.textContent='Save Settings'; }
    };
  }

  const testBtn = document.getElementById('mkt-test-email-btn');
  if (testBtn && !testBtn.dataset.bound) {
    testBtn.dataset.bound = '1';
    testBtn.onclick = async function() {
      const email = await luPrompt('Enter email address to test:','','Send Test Email');
      if (!email?.trim()) return;
      testBtn.disabled=true; testBtn.textContent='Sending…';
      const msg = document.getElementById('mkt-settings-msg');
      try {
        const res = await _mktApi('POST', '/marketing/email/test', { email: email.trim() });
        if (res.success) { showToast('Test email sent!','success'); if(msg){msg.style.display='block';msg.style.color='#00b894';msg.textContent='✓ Test sent to '+email.trim()+'.';} }
        else showToast(res.error||'Send failed.','error');
      } catch(e) { showToast(e.message,'error'); }
      finally { testBtn.disabled=false; testBtn.textContent='Send Test'; }
    };
  }
};

// ═══════════════════════════════════════════════════════════════════
// UTILITIES (Patches 6+7+9)
// ═══════════════════════════════════════════════════════════════════
function _mktModal(title, bodyHtml, buttons) {
  const bd = document.createElement('div');
  bd.className = 'modal-backdrop';
  bd.onclick = e => { if (e.target===bd) bd.remove(); };
  bd.innerHTML = `<div class="modal" style="max-width:480px">
    <div class="modal-header"><h3>${title}</h3><button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button></div>
    <div class="modal-body">${bodyHtml}</div>
    <div class="modal-footer">${buttons.map(b=>`<button class="btn ${b.cls}"${b.id?' id="'+b.id+'"':''}${b.close?' onclick="this.closest(\'.modal-backdrop\').remove()"':''}>${b.label}</button>`).join('')}</div>
  </div>`;
  document.body.appendChild(bd);
  bd.style.opacity='1'; bd.style.pointerEvents='all';
  return bd;
}

function _mktSetLoading(bd, selector, loading, label) {
  const btn = bd.querySelector(selector);
  if (btn) { btn.disabled=loading; btn.textContent=label; }
}

// HTML escape helper (Patch 7: normalize/sanitize display)
function _esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Email image insertion (T3 2026-04-20) ─────────────────────────
// Opens the media picker for an email campaign body textarea, validates
// size/type, tracks usage, and inserts an <img> tag at the cursor.
window.mktInsertImage = function(textareaId) {
  if (typeof window.openMediaPicker !== 'function') {
    showToast('Media picker unavailable', 'error');
    return;
  }
  var ta = document.getElementById(textareaId);
  if (!ta) return;

  window.openMediaPicker({ type: 'image', context: 'email', multiple: false }, function(file) {
    if (!file) return;

    // ── Video guard ─────────────────────────────────────────────
    var assetType = (file.asset_type || '').toLowerCase();
    var mime = (file.mime_type || '').toLowerCase();
    if (assetType === 'video' || mime.indexOf('video/') === 0) {
      if (typeof luConfirm === 'function') {
        luConfirm(
          'Videos don\u2019t work in email clients. Please choose an image (or a video thumbnail) instead.',
          'Video not supported in email',
          'Choose image',
          'Cancel'
        ).then(function(choose){ if (choose) mktInsertImage(textareaId); });
      } else {
        if (confirm('Videos don\u2019t work in email clients. Pick an image instead?')) mktInsertImage(textareaId);
      }
      return;
    }

    var url = file.file_url || file.url || file.src || '';
    if (!url) return;
    var w = parseInt(file.width, 10) || 0;
    var h = parseInt(file.height, 10) || 0;
    var alt = (file.alt_text || file.filename || 'image').replace(/"/g, '&quot;');

    var doInsert = function(finalWidth) {
      var widthAttr = finalWidth ? ' width="' + finalWidth + '"' : '';
      var imgTag = '<img src="' + url + '" alt="' + alt + '"' + widthAttr + ' style="max-width:100%;height:auto;display:block">';
      var start = ta.selectionStart || 0;
      var end   = ta.selectionEnd   || 0;
      var v = ta.value || '';
      ta.value = v.slice(0, start) + imgTag + v.slice(end);
      ta.focus();
      try { ta.setSelectionRange(start + imgTag.length, start + imgTag.length); } catch(_){}
      // Track usage (best-effort, non-blocking)
      if (file.id) {
        _mktApi('POST', '/media/use', { media_id: file.id, context: 'email' })
          .catch(function(e){ console.warn('[mkt] media use tracking failed:', e && e.message); });
      }
      showToast('Image inserted', 'success');
    };

    // ── Resize prompt for wide images ───────────────────────────
    if (w > 600) {
      var ask = function() {
        if (typeof luConfirm === 'function') {
          return luConfirm(
            'This image is ' + w + 'px wide. Resize to 600px for email compatibility? (Keeps original file; only sets display width.)',
            'Resize for email?',
            'Yes, resize to 600',
            'Keep original'
          );
        }
        return Promise.resolve(confirm('Image is ' + w + 'px. Resize to 600px for email? (OK = resize, Cancel = keep original)'));
      };
      ask().then(function(resize){ doInsert(resize ? 600 : null); });
    } else {
      doInsert(null);
    }
  });
};

console.log('[LevelUp] marketing engine v2.1.0 loaded');

// ═══════════════════════════════════════════════════════════════════
// EMAIL BUILDER — Phase 4 (v4.7.3)
// Full-screen visual editor for email templates.
// Mounted via: window.openEmailBuilder(templateId, {campaignId})
// ═══════════════════════════════════════════════════════════════════
(function() {
  'use strict';

  // ── STATE ───────────────────────────────────────────────────────
  const EB = {
    template:      null,
    blocks:        [],
    selectedBlock: null,
    history:       [],   // stack of {blocks:[...serialized]}
    historyIndex:  -1,
    dirty:         false,
    iframeReady:   false,
    viewMode:      'desktop',
    campaignId:    null,
    iframe:        null,
    root:          null,
    autoSaveTimer: null,
    previewDebounce: null,
    darkPreview:   false,
    cssInjected:   false,
  };

  const BLOCK_GROUPS = [
    ['Structure',  ['header','divider','spacer','footer']],
    ['Content',    ['hero','features','body_text','testimonial','stats']],
    ['Media',      ['image','product']],
    ['Conversion', ['secondary_cta','countdown']],
    ['Advanced',   ['custom_html']],
  ];
  const BLOCK_LABEL = {
    header:'Header', hero:'Hero', features:'Features', body_text:'Body text',
    testimonial:'Testimonial', secondary_cta:'Secondary CTA', image:'Image',
    stats:'Stats', countdown:'Countdown', product:'Product', divider:'Divider',
    spacer:'Spacer', footer:'Footer', custom_html:'Custom HTML',
  };
  const COLOR_PRESETS = ['#6C5CE7','#3B82F6','#10B981','#F59E0B','#EF4444','#EC4899','#0F172A','#5B5BD6'];

  // ── CSS ─────────────────────────────────────────────────────────
  function _ebInjectCSS(){
    if (EB.cssInjected) return;
    EB.cssInjected = true;
    const style = document.createElement('style');
    style.id = 'eb-styles';
    style.textContent = `
.eb-root{position:fixed;inset:0;z-index:1000;background:var(--bg,#0B0C11);display:flex;flex-direction:column;color:var(--t1,#F1F5F9);font-family:'DM Sans','Inter',Arial,sans-serif}
.eb-topbar{height:52px;border-bottom:1px solid var(--bd,#1f2330);display:flex;align-items:center;padding:0 16px;gap:12px;flex-shrink:0;background:var(--s1,#0F1218)}
.eb-back{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px}
.eb-back:hover{background:var(--s2,#171b23)}
.eb-title{flex:1;background:transparent;border:none;font-size:15px;font-weight:600;color:var(--t1,#F1F5F9);outline:none}
.eb-title:focus{background:var(--s2,#171b23);padding:4px 8px;border-radius:4px}
.eb-topbar-actions{display:flex;gap:8px;align-items:center}
.eb-btn-ghost,.eb-btn-primary,.eb-btn-outline{padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:inherit}
.eb-btn-ghost{background:transparent;color:var(--t2,#CBD5E1);border:1px solid var(--bd,#2a2f3a)}
.eb-btn-ghost:hover{background:var(--s2,#171b23);color:var(--t1,#F1F5F9)}
.eb-btn-primary{background:var(--p,#6C5CE7);color:#fff}
.eb-btn-primary:hover{background:var(--p-dark,#5849d3)}
.eb-btn-outline{background:transparent;color:var(--p,#6C5CE7);border:1px solid var(--p,#6C5CE7)}
.eb-btn-outline:hover{background:rgba(108,92,231,0.08)}
.eb-btn-outline.full-width{width:100%}
.eb-subject-bar{height:48px;border-bottom:1px solid var(--bd,#1f2330);display:flex;align-items:center;padding:0 16px;gap:24px;background:var(--s1,#0F1218);flex-shrink:0}
.eb-subject-field{display:flex;align-items:center;gap:8px;flex:1}
.eb-subject-field label{font-size:10px;font-weight:700;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap}
.eb-subject-field input{flex:1;background:transparent;border:none;font-size:13px;color:var(--t1,#F1F5F9);padding:4px 8px;border-radius:4px;outline:none}
.eb-subject-field input:focus{background:var(--s2,#171b23)}
.eb-subject-field button{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--ac,#A78BFA);padding:3px 10px;border-radius:4px;cursor:pointer;font-size:11px;white-space:nowrap}
.eb-subject-field button:hover{border-color:var(--ac,#A78BFA);background:rgba(167,139,250,.08)}
.eb-shell{flex:1;display:grid;grid-template-columns:260px 1fr 320px;overflow:hidden;min-height:0}
.eb-left{border-right:1px solid var(--bd,#1f2330);overflow-y:auto;display:flex;flex-direction:column;background:var(--s1,#0F1218)}
.eb-tabs{display:flex;border-bottom:1px solid var(--bd,#1f2330);flex-shrink:0}
.eb-tab{flex:1;background:transparent;border:none;color:var(--t3,#64748B);padding:12px 8px;font-size:12px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit}
.eb-tab.active{color:var(--t1,#F1F5F9);border-bottom-color:var(--p,#6C5CE7)}
.eb-tab:hover:not(.active){color:var(--t2,#CBD5E1)}
.eb-tab-content{padding:12px;flex:1}
.eb-tab-content.hidden{display:none}
.eb-section-label{font-size:10px;font-weight:700;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;margin:12px 0 8px}
.eb-section-label:first-child{margin-top:0}
.eb-block-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.eb-block-card{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 8px;border:1px solid var(--bd,#2a2f3a);border-radius:6px;cursor:grab;font-size:11px;font-weight:600;color:var(--t2,#CBD5E1);background:var(--s2,#171b23);transition:all .15s;text-align:center}
.eb-block-card:hover{border-color:var(--p,#6C5CE7);color:var(--p,#6C5CE7);background:rgba(108,92,231,.08)}
.eb-block-card.dragging{opacity:.5}
.eb-field-group{margin-bottom:14px}
.eb-field-group label{display:block;font-size:11px;font-weight:600;color:var(--t2,#CBD5E1);margin-bottom:6px}
.eb-field-group input,.eb-field-group select,.eb-field-group textarea{width:100%;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:6px;color:var(--t1,#F1F5F9);font-size:13px;padding:8px 10px;font-family:inherit;box-sizing:border-box;outline:none}
.eb-field-group input:focus,.eb-field-group select:focus,.eb-field-group textarea:focus{border-color:var(--p,#6C5CE7)}
.eb-field-group textarea{resize:vertical;min-height:72px}
.eb-color-row{display:flex;gap:6px}
.eb-color-row input[type=color]{width:40px;height:34px;padding:2px;border-radius:6px;cursor:pointer}
.eb-color-row input[type=text]{flex:1}
.eb-color-presets{display:flex;gap:4px;margin-top:8px;flex-wrap:wrap}
.eb-color-preset{width:22px;height:22px;border-radius:5px;cursor:pointer;border:2px solid var(--bd,#2a2f3a);transition:transform .12s}
.eb-color-preset:hover{transform:scale(1.1)}
.eb-canvas-wrap{display:flex;flex-direction:column;background:#1a1a2e;overflow:hidden;min-width:0}
.eb-canvas-toolbar{height:40px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;padding:0 12px;gap:8px;flex-shrink:0;background:#0f0f1e}
.eb-view-toggle{display:flex;background:rgba(255,255,255,.06);border-radius:6px;padding:2px}
.eb-view-btn{background:transparent;border:none;color:rgba(255,255,255,.55);padding:4px 14px;font-size:11px;font-weight:600;cursor:pointer;border-radius:4px;font-family:inherit}
.eb-view-btn.active{background:rgba(255,255,255,.12);color:#fff}
.eb-toolbar-btn{background:transparent;border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.65);padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;font-family:inherit}
.eb-toolbar-btn:hover:not(:disabled){background:rgba(255,255,255,.06);color:#fff}
.eb-toolbar-btn:disabled{opacity:.35;cursor:default}
.eb-toolbar-spacer{flex:1}
.eb-iframe-wrap{flex:1;overflow-y:auto;display:flex;justify-content:center;padding:24px;position:relative}
.eb-iframe-wrap.drag-over{background:rgba(108,92,231,.06)}
#eb-iframe{width:600px;min-height:800px;background:#fff;border:none;border-radius:4px;box-shadow:0 4px 40px rgba(0,0,0,.4);transition:width .3s ease;max-width:100%}
#eb-iframe.mobile{width:375px}
.eb-drop-indicator{position:absolute;left:20%;right:20%;height:3px;background:var(--p,#6C5CE7);border-radius:2px;box-shadow:0 0 12px rgba(108,92,231,.6);pointer-events:none;z-index:10;display:none}
.eb-block-overlay{position:absolute;pointer-events:none;border:2px solid #6C5CE7;border-radius:2px;transition:all .08s ease;z-index:5}
.eb-block-overlay.hidden{display:none}
.eb-block-toolbar{position:absolute;top:-32px;right:0;background:#6C5CE7;border-radius:6px;display:flex;align-items:center;gap:2px;padding:3px 6px;pointer-events:all;box-shadow:0 4px 14px rgba(0,0,0,.3)}
.eb-block-action{background:transparent;border:none;color:#fff;padding:3px 8px;font-size:11px;cursor:pointer;border-radius:3px;font-family:inherit;font-weight:600}
.eb-block-action:hover{background:rgba(255,255,255,.15)}
.eb-block-action.danger:hover{background:rgba(239,68,68,.4)}
.eb-toolbar-sep{width:1px;height:14px;background:rgba(255,255,255,.25);margin:0 2px}
.eb-drag-handle{color:#fff;font-size:14px;cursor:grab;padding:0 4px}
.eb-right{border-left:1px solid var(--bd,#1f2330);display:flex;flex-direction:column;overflow:hidden;background:var(--s1,#0F1218)}
.eb-right-header{display:flex;border-bottom:1px solid var(--bd,#1f2330);flex-shrink:0}
.eb-right-tab{flex:1;background:transparent;border:none;color:var(--t3,#64748B);padding:12px 8px;font-size:12px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit}
.eb-right-tab.active{color:var(--t1,#F1F5F9);border-bottom-color:var(--p,#6C5CE7)}
.eb-panel-content{display:flex;flex-direction:column;flex:1;overflow:hidden;min-height:0}
.eb-panel-content.hidden{display:none}
.eb-subject-gen{padding:12px;border-bottom:1px solid var(--bd,#1f2330);flex-shrink:0}
.eb-suggestions{display:flex;flex-direction:column;gap:4px;margin-bottom:8px}
.eb-suggestion{background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);padding:8px 10px;border-radius:6px;font-size:12px;cursor:pointer;color:var(--t2,#CBD5E1);transition:all .15s}
.eb-suggestion:hover{border-color:var(--p,#6C5CE7);background:rgba(108,92,231,.08)}
.eb-suggestion .eb-sug-meta{font-size:10px;color:var(--t3,#64748B);margin-top:3px}
.eb-quick-actions{display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px;border-bottom:1px solid var(--bd,#1f2330);flex-shrink:0}
.eb-quick{font-size:11px;padding:5px 11px;border-radius:20px;border:1px solid var(--bd,#2a2f3a);background:transparent;color:var(--t3,#64748B);cursor:pointer;white-space:nowrap;transition:all .15s;font-family:inherit}
.eb-quick:hover{border-color:var(--p,#6C5CE7);color:var(--p,#6C5CE7);background:rgba(108,92,231,.08)}
.eb-chat-history{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;min-height:0}
.eb-chat-welcome{color:var(--t3,#64748B);font-size:12px;line-height:1.6;padding:12px;text-align:center}
.eb-chat-welcome p{margin:0 0 8px 0}
.eb-chat-bubble{padding:10px 12px;border-radius:10px;font-size:12px;line-height:1.55;max-width:90%}
.eb-chat-bubble.user{background:var(--p,#6C5CE7);color:#fff;margin-left:auto;border-bottom-right-radius:3px}
.eb-chat-bubble.ai{background:var(--s2,#171b23);color:var(--t1,#F1F5F9);border:1px solid var(--bd,#2a2f3a);border-bottom-left-radius:3px}
.eb-chat-bubble.ai.loading{color:var(--t3,#64748B);font-style:italic}
.eb-apply-btn{display:inline-block;margin-top:6px;font-size:11px;padding:4px 10px;background:var(--p,#6C5CE7);color:#fff;border:none;border-radius:4px;cursor:pointer;font-family:inherit;font-weight:600;margin-right:4px}
.eb-apply-btn.secondary{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1)}
.eb-chat-input-wrap{border-top:1px solid var(--bd,#1f2330);padding:10px;display:flex;gap:6px;flex-shrink:0;background:var(--s1,#0F1218)}
.eb-chat-input-wrap textarea{flex:1;resize:none;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:6px;color:var(--t1,#F1F5F9);font-size:12px;padding:8px 10px;font-family:inherit;outline:none}
.eb-chat-input-wrap textarea:focus{border-color:var(--p,#6C5CE7)}
.eb-chat-input-wrap button{background:var(--p,#6C5CE7);color:#fff;border:none;padding:0 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit}
.eb-edit-header{display:flex;align-items:center;justify-content:space-between;padding:12px;border-bottom:1px solid var(--bd,#1f2330);flex-shrink:0}
#eb-edit-block-type{font-size:12px;font-weight:700;color:var(--t1,#F1F5F9);text-transform:capitalize}
#eb-edit-done{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:3px 10px;border-radius:4px;cursor:pointer;font-size:11px;font-family:inherit}
#eb-edit-fields{flex:1;overflow-y:auto;padding:12px;min-height:0}
.eb-edit-actions{padding:10px 12px;border-top:1px solid var(--bd,#1f2330);flex-shrink:0}
.eb-toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#10B981;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;z-index:1001;box-shadow:0 8px 24px rgba(0,0,0,.3);animation:eb-toast-in .3s ease}
.eb-toast.error{background:#EF4444}
.eb-toast.warning{background:#F59E0B}
@keyframes eb-toast-in{from{opacity:0;transform:translate(-50%,10px)}to{opacity:1;transform:translate(-50%,0)}}
.eb-spam-modal-body{padding:20px}
.eb-spam-score{font-size:44px;font-weight:800;text-align:center;margin:12px 0}
.eb-spam-score.great{color:#10B981}
.eb-spam-score.warning{color:#F59E0B}
.eb-spam-score.danger{color:#EF4444}
.eb-spam-rating{text-align:center;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px}
.eb-spam-rating.great{color:#10B981}
.eb-spam-rating.warning{color:#F59E0B}
.eb-spam-rating.danger{color:#EF4444}
.eb-spam-issues{display:flex;flex-direction:column;gap:6px;max-height:280px;overflow-y:auto}
.eb-spam-issue{padding:8px 10px;border-radius:6px;background:var(--s2,#171b23);font-size:12px}
.eb-spam-issue .eb-issue-rule{font-weight:700;color:var(--t1,#F1F5F9);margin-bottom:2px}
.eb-spam-issue .eb-issue-fix{color:var(--t3,#64748B)}
.eb-dim{color:var(--t3,#64748B)}
.eb-loading-dots{display:inline-block}
.eb-loading-dots::after{content:'';animation:eb-dots 1.2s steps(4) infinite}
@keyframes eb-dots{0%{content:''}25%{content:'.'}50%{content:'..'}75%{content:'...'}}
    `;
    document.head.appendChild(style);
  }

  // ── TOAST ───────────────────────────────────────────────────────
  function _ebToast(msg, type){
    const t = document.createElement('div');
    t.className = 'eb-toast ' + (type || '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 2600);
  }

  // ── API HELPERS ─────────────────────────────────────────────────
  async function _ebApi(method, path, body){
    return _mktApi(method, path, body);
  }

  // ── MOUNT ───────────────────────────────────────────────────────
  async function mountEmailBuilder(templateId, opts){
    opts = opts || {};
    EB.campaignId = opts.campaignId || null;
    _ebInjectCSS();

    let data;
    try {
      data = await _ebApi('GET', '/marketing/email-builder/templates/' + templateId);
    } catch (e) {
      _ebToast('Failed to load template: ' + e.message, 'error'); return;
    }
    if (!data || !data.template) { _ebToast('Template not found', 'error'); return; }

    EB.template      = data.template;
    EB.blocks        = data.blocks || [];
    EB.selectedBlock = null;
    EB.history       = [_ebSnapshot()];
    EB.historyIndex  = 0;
    EB.dirty         = false;

    // Remove any prior mount
    const old = document.getElementById('email-builder');
    if (old) old.remove();

    const root = document.createElement('div');
    root.id = 'email-builder';
    root.className = 'eb-root';
    root.innerHTML = _ebRootHtml();
    document.body.appendChild(root);
    EB.root = root;

    _ebWireTopbar();
    _ebWireSubjectBar();
    _ebWireLeftPanel();
    _ebWireRightPanel();
    _ebWireCanvas();
    _ebWireWindowEvents();
    _ebRenderBlockPalette();

    EB.iframe = root.querySelector('#eb-iframe');
    window.addEventListener('message', _ebHandleMessage);
    await _ebLoadPreview();

    // Auto-save every 30s if dirty
    if (EB.autoSaveTimer) clearInterval(EB.autoSaveTimer);
    EB.autoSaveTimer = setInterval(() => { if (EB.dirty) _ebSave(true); }, 30000);
  }

  // ── DOM SCAFFOLD ────────────────────────────────────────────────
  function _ebRootHtml(){
    const t = EB.template;
    const title = _esc(t.name || 'Untitled Email');
    const subject = _esc(t.subject || '');
    const previewText = _esc(t.preview_text || '');
    const brand = t.brand_color || '#5B5BD6';
    return `
      <div class="eb-topbar">
        <button class="eb-back" id="eb-back">← Back</button>
        <input class="eb-title" id="eb-title" value="${title}" placeholder="Template name..."/>
        <div class="eb-topbar-actions">
          <button class="eb-btn-ghost" id="eb-spam-check">Spam Check</button>
          <button class="eb-btn-ghost" id="eb-preview-btn">Preview</button>
          <button class="eb-btn-ghost" id="eb-send-test">Send Test</button>
          <button class="eb-btn-primary" id="eb-save">Save</button>
        </div>
      </div>
      <div class="eb-subject-bar">
        <div class="eb-subject-field">
          <label>Subject</label>
          <input id="eb-subject" value="${subject}" placeholder="Email subject line..."/>
          <button id="eb-subject-ai" title="Generate 5 subject options">✦ Generate</button>
        </div>
        <div class="eb-subject-field">
          <label>Preview text</label>
          <input id="eb-preview-text" value="${previewText}" placeholder="Preview text (shown in inbox)..."/>
        </div>
      </div>
      <div class="eb-shell">
        <div class="eb-left" id="eb-left-panel">
          <div class="eb-tabs">
            <button class="eb-tab active" data-tab="blocks">Blocks</button>
            <button class="eb-tab" data-tab="styles">Styles</button>
            <button class="eb-tab" data-tab="settings">Settings</button>
          </div>
          <div class="eb-tab-content" id="eb-tab-blocks"></div>
          <div class="eb-tab-content hidden" id="eb-tab-styles">
            <div class="eb-field-group">
              <label>Brand color</label>
              <div class="eb-color-row">
                <input type="color" id="eb-brand-color" value="${brand}"/>
                <input type="text" id="eb-brand-color-hex" value="${brand}" maxlength="7"/>
              </div>
              <div class="eb-color-presets" id="eb-color-presets"></div>
            </div>
            <div class="eb-field-group">
              <label>Font family</label>
              <select id="eb-font-family">
                <option value="Inter, Arial, Helvetica">Inter (default)</option>
                <option value="Georgia, serif">Georgia (serif)</option>
                <option value="Trebuchet MS, sans-serif">Trebuchet MS</option>
                <option value="Verdana, sans-serif">Verdana</option>
              </select>
            </div>
            <div class="eb-field-group">
              <label>Canvas background</label>
              <input type="color" id="eb-bg-color" value="#F2F4F8"/>
            </div>
          </div>
          <div class="eb-tab-content hidden" id="eb-tab-settings">
            <div class="eb-field-group">
              <label>Template category</label>
              <select id="eb-category">
                <option value="promotional">promotional</option>
                <option value="newsletter">newsletter</option>
                <option value="onboarding">onboarding</option>
                <option value="transactional">transactional</option>
                <option value="announcement">announcement</option>
                <option value="reactivation">reactivation</option>
                <option value="ai_generated">ai_generated</option>
              </select>
            </div>
            <div class="eb-field-group">
              <label>From name</label>
              <input type="text" id="eb-from-name" placeholder="e.g. LevelUp Growth"/>
            </div>
            <div class="eb-field-group">
              <label>Reply-to email</label>
              <input type="email" id="eb-reply-to" placeholder="hello@yourdomain.com"/>
            </div>
          </div>
        </div>

        <div class="eb-canvas-wrap" id="eb-canvas-wrap">
          <div class="eb-canvas-toolbar">
            <div class="eb-view-toggle">
              <button class="eb-view-btn active" data-view="desktop">Desktop</button>
              <button class="eb-view-btn" data-view="mobile">Mobile</button>
            </div>
            <button class="eb-toolbar-btn" id="eb-undo" title="Undo (Ctrl+Z)">↩ Undo</button>
            <button class="eb-toolbar-btn" id="eb-redo" title="Redo (Ctrl+Shift+Z)">↪ Redo</button>
            <div class="eb-toolbar-spacer"></div>
            <button class="eb-toolbar-btn" id="eb-dark-preview">Dark mode</button>
          </div>
          <div class="eb-iframe-wrap" id="eb-iframe-wrap">
            <iframe id="eb-iframe" frameborder="0" scrolling="auto"></iframe>
            <div id="eb-block-overlay" class="eb-block-overlay hidden">
              <div id="eb-block-toolbar" class="eb-block-toolbar">
                <span class="eb-drag-handle" title="Drag">⠿</span>
                <span class="eb-toolbar-sep"></span>
                <button class="eb-block-action" id="eb-block-edit">Edit</button>
                <button class="eb-block-action" id="eb-block-dup">Dupe</button>
                <button class="eb-block-action" id="eb-block-up">↑</button>
                <button class="eb-block-action" id="eb-block-dn">↓</button>
                <button class="eb-block-action danger" id="eb-block-del">✕</button>
              </div>
            </div>
            <div class="eb-drop-indicator" id="eb-drop-indicator"></div>
          </div>
        </div>

        <div class="eb-right" id="eb-right-panel">
          <div class="eb-right-header">
            <button class="eb-right-tab active" data-panel="ai">AI Assistant</button>
            <button class="eb-right-tab" data-panel="edit">Edit Block</button>
          </div>
          <div id="eb-ai-panel" class="eb-panel-content">
            <div class="eb-subject-gen">
              <div class="eb-section-label">Subject lines</div>
              <div id="eb-subject-suggestions" class="eb-suggestions"></div>
              <button class="eb-btn-outline full-width" id="eb-gen-subjects">✦ Generate 5 options</button>
            </div>
            <div class="eb-quick-actions" id="eb-quick-actions">
              ${[
                ['Rewrite the entire email','Rewrite email'],
                ['Make the email shorter and more concise','Shorter'],
                ['Add more urgency and FOMO','Urgency'],
                ['Make the tone more professional and formal','More formal'],
                ['Make the tone more casual and friendly','More casual'],
                ['Add a testimonial section','+ Testimonial'],
                ['Improve the CTA button text','Better CTA'],
                ['Add a features section','+ Features'],
              ].map(([p,l])=>`<button class="eb-quick" data-prompt="${_esc(p)}">${_esc(l)}</button>`).join('')}
            </div>
            <div class="eb-chat-history" id="eb-chat-history">
              <div class="eb-chat-welcome">
                <p>Ask me to write, rewrite, or improve any part of your email.</p>
                <p>Click a block on the canvas to edit it — or ask me to add new sections.</p>
              </div>
            </div>
            <div class="eb-chat-input-wrap">
              <textarea id="eb-chat-input" rows="2" placeholder="Ask AI to edit your email..."></textarea>
              <button id="eb-chat-send">Send</button>
            </div>
          </div>
          <div id="eb-edit-panel" class="eb-panel-content hidden">
            <div class="eb-edit-header">
              <span id="eb-edit-block-type">Edit Block</span>
              <button id="eb-edit-done">Done</button>
            </div>
            <div id="eb-edit-fields"></div>
            <div class="eb-edit-actions">
              <button class="eb-btn-outline full-width" id="eb-edit-ai-rewrite">✦ AI Rewrite this block</button>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function _ebRenderBlockPalette(){
    const holder = EB.root.querySelector('#eb-tab-blocks');
    holder.innerHTML = BLOCK_GROUPS.map(([label, types]) => `
      <div class="eb-section-label">${_esc(label)}</div>
      <div class="eb-block-grid">
        ${types.map(t => `<div class="eb-block-card" draggable="true" data-block-type="${t}">+ ${_esc(BLOCK_LABEL[t]||t)}</div>`).join('')}
      </div>
    `).join('');
    // Wire drag + click-to-append
    holder.querySelectorAll('.eb-block-card').forEach(card => {
      card.addEventListener('dragstart', e => {
        card.classList.add('dragging');
        e.dataTransfer.setData('text/eb-block', card.getAttribute('data-block-type'));
        e.dataTransfer.effectAllowed = 'copy';
      });
      card.addEventListener('dragend', () => card.classList.remove('dragging'));
      card.addEventListener('click', () => _ebAddBlock(card.getAttribute('data-block-type'), EB.blocks.length + 1));
    });
  }

  // ── EVENT WIRING ────────────────────────────────────────────────
  function _ebWireTopbar(){
    EB.root.querySelector('#eb-back').onclick = _ebRequestExit;
    EB.root.querySelector('#eb-title').oninput = (e) => { EB.template.name = e.target.value; _ebMarkDirty(); };
    EB.root.querySelector('#eb-save').onclick = () => _ebSave(false);
    EB.root.querySelector('#eb-preview-btn').onclick = _ebShowPreviewModal;
    EB.root.querySelector('#eb-send-test').onclick = _ebShowSendTestModal;
    EB.root.querySelector('#eb-spam-check').onclick = _ebSpamCheck;
  }

  function _ebWireSubjectBar(){
    EB.root.querySelector('#eb-subject').oninput = (e) => { EB.template.subject = e.target.value; _ebMarkDirty(); };
    EB.root.querySelector('#eb-preview-text').oninput = (e) => { EB.template.preview_text = e.target.value; _ebMarkDirty(); };
    EB.root.querySelector('#eb-subject-ai').onclick = _ebGenerateSubjects;
  }

  function _ebWireLeftPanel(){
    EB.root.querySelectorAll('.eb-tab').forEach(tab => {
      tab.onclick = () => {
        EB.root.querySelectorAll('.eb-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        ['blocks','styles','settings'].forEach(id => EB.root.querySelector('#eb-tab-'+id).classList.add('hidden'));
        EB.root.querySelector('#eb-tab-' + tab.getAttribute('data-tab')).classList.remove('hidden');
      };
    });
    // Brand color — presets + pickers
    const presetsHolder = EB.root.querySelector('#eb-color-presets');
    COLOR_PRESETS.forEach(hex => {
      const el = document.createElement('div');
      el.className = 'eb-color-preset';
      el.style.background = hex;
      el.title = hex;
      el.onclick = () => _ebSetBrandColor(hex);
      presetsHolder.appendChild(el);
    });
    EB.root.querySelector('#eb-brand-color').onchange = (e) => _ebSetBrandColor(e.target.value);
    const hexInput = EB.root.querySelector('#eb-brand-color-hex');
    hexInput.onchange = (e) => {
      const v = e.target.value.trim();
      if (/^#[0-9A-Fa-f]{6}$/.test(v)) _ebSetBrandColor(v);
      else e.target.value = EB.template.brand_color;
    };
    EB.root.querySelector('#eb-category').value = EB.template.category || 'promotional';
    EB.root.querySelector('#eb-category').onchange = (e) => { EB.template.category = e.target.value; _ebMarkDirty(); };
  }

  function _ebWireRightPanel(){
    EB.root.querySelectorAll('.eb-right-tab').forEach(tab => {
      tab.onclick = () => _ebSwitchRightPanel(tab.getAttribute('data-panel'));
    });
    EB.root.querySelector('#eb-gen-subjects').onclick = _ebGenerateSubjects;
    EB.root.querySelector('#eb-chat-send').onclick = () => {
      const ta = EB.root.querySelector('#eb-chat-input');
      const msg = ta.value.trim();
      if (!msg) return;
      ta.value = '';
      _ebSendChat(msg);
    };
    EB.root.querySelector('#eb-chat-input').addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); EB.root.querySelector('#eb-chat-send').click(); }
    });
    EB.root.querySelectorAll('.eb-quick').forEach(b => {
      b.onclick = () => _ebSendChat(b.getAttribute('data-prompt'));
    });
    EB.root.querySelector('#eb-edit-done').onclick = () => { EB.selectedBlock = null; _ebSwitchRightPanel('ai'); _ebHideBlockOverlay(); };
    EB.root.querySelector('#eb-edit-ai-rewrite').onclick = _ebShowRewriteBlockPrompt;
  }

  function _ebSwitchRightPanel(which){
    EB.root.querySelectorAll('.eb-right-tab').forEach(t => t.classList.toggle('active', t.getAttribute('data-panel') === which));
    EB.root.querySelector('#eb-ai-panel').classList.toggle('hidden', which !== 'ai');
    EB.root.querySelector('#eb-edit-panel').classList.toggle('hidden', which !== 'edit');
  }

  function _ebWireCanvas(){
    EB.root.querySelectorAll('.eb-view-btn').forEach(btn => {
      btn.onclick = () => {
        EB.root.querySelectorAll('.eb-view-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        EB.viewMode = btn.getAttribute('data-view');
        EB.iframe.classList.toggle('mobile', EB.viewMode === 'mobile');
      };
    });
    EB.root.querySelector('#eb-undo').onclick = _ebUndo;
    EB.root.querySelector('#eb-redo').onclick = _ebRedo;
    EB.root.querySelector('#eb-dark-preview').onclick = () => { EB.darkPreview = !EB.darkPreview; _ebSchedulePreviewReload(true); };

    // Block overlay toolbar actions
    EB.root.querySelector('#eb-block-edit').onclick = () => { if (EB.selectedBlock) _ebOpenBlockEditor(EB.selectedBlock); };
    EB.root.querySelector('#eb-block-dup').onclick  = () => { if (EB.selectedBlock) _ebDuplicateBlock(EB.selectedBlock.id); };
    EB.root.querySelector('#eb-block-up').onclick   = () => { if (EB.selectedBlock) _ebMoveBlock(EB.selectedBlock.id, -1); };
    EB.root.querySelector('#eb-block-dn').onclick   = () => { if (EB.selectedBlock) _ebMoveBlock(EB.selectedBlock.id, +1); };
    EB.root.querySelector('#eb-block-del').onclick  = () => { if (EB.selectedBlock) _ebDeleteBlock(EB.selectedBlock.id); };

    // Drop-target wiring on iframe wrap
    const wrap = EB.root.querySelector('#eb-iframe-wrap');
    wrap.addEventListener('dragover', e => {
      if (!e.dataTransfer.types.includes('text/eb-block')) return;
      e.preventDefault();
      wrap.classList.add('drag-over');
      _ebShowDropIndicator(e);
    });
    wrap.addEventListener('dragleave', e => {
      if (e.target === wrap) { wrap.classList.remove('drag-over'); _ebHideDropIndicator(); }
    });
    wrap.addEventListener('drop', e => {
      e.preventDefault();
      wrap.classList.remove('drag-over');
      _ebHideDropIndicator();
      const type = e.dataTransfer.getData('text/eb-block');
      if (!type) return;
      const order = _ebCalcDropOrder(e);
      _ebAddBlock(type, order);
    });
  }

  function _ebShowDropIndicator(e){
    const ind = EB.root.querySelector('#eb-drop-indicator');
    const wrap = EB.root.querySelector('#eb-iframe-wrap');
    const r = wrap.getBoundingClientRect();
    ind.style.top = (e.clientY - r.top + wrap.scrollTop - 1) + 'px';
    ind.style.display = 'block';
  }
  function _ebHideDropIndicator(){
    EB.root.querySelector('#eb-drop-indicator').style.display = 'none';
  }
  function _ebCalcDropOrder(e){
    const wrap = EB.root.querySelector('#eb-iframe-wrap');
    const y = e.clientY - wrap.getBoundingClientRect().top + wrap.scrollTop;
    const iframeRect = EB.iframe.getBoundingClientRect();
    const yInFrame = y - (iframeRect.top - wrap.getBoundingClientRect().top + wrap.scrollTop);
    try {
      const doc = EB.iframe.contentDocument;
      const rows = Array.from(doc.querySelectorAll('[data-block-id]'));
      for (let i = 0; i < rows.length; i++){
        const rr = rows[i].getBoundingClientRect();
        if (yInFrame < rr.top + rr.height/2) return i + 1;
      }
      return rows.length + 1;
    } catch(_){ return EB.blocks.length + 1; }
  }

  function _ebWireWindowEvents(){
    window.addEventListener('beforeunload', (e) => {
      if (EB.dirty) { e.preventDefault(); e.returnValue = ''; }
    });
    document.addEventListener('keydown', _ebKeyHandler);
  }

  function _ebKeyHandler(e){
    if (!document.getElementById('email-builder')) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !e.shiftKey){ e.preventDefault(); _ebUndo(); }
    if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))){ e.preventDefault(); _ebRedo(); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's'){ e.preventDefault(); _ebSave(false); }
    if (e.key === 'Escape'){
      if (EB.selectedBlock){ EB.selectedBlock = null; _ebHideBlockOverlay(); _ebSwitchRightPanel('ai'); }
    }
  }

  // ── IFRAME / PREVIEW ────────────────────────────────────────────
  async function _ebLoadPreview(){
    EB.iframeReady = false;
    let html;
    try {
      const res = await _ebApi('POST', '/marketing/email-builder/templates/' + EB.template.id + '/preview', {
        variables: _ebPreviewVars(),
        format: EB.viewMode,
      });
      html = res.html || '';
    } catch (e) {
      _ebToast('Preview failed: ' + e.message, 'error'); return;
    }
    if (EB.darkPreview){
      // Inject unconditional dark-mode styles — @media prefers-color-scheme
      // can't be simulated from JS, so we manually apply the rules.
      const darkCss = '<style>body,.wrap{background-color:#111827!important}.card-w{background-color:#1F2937!important}.card-l{background-color:#1A2234!important}.card-d{background-color:#0B1220!important}.txt-h{color:#F1F5F9!important}.txt-b{color:#CBD5E1!important}.txt-m{color:#64748B!important}.hr{border-color:#374151!important}</style>';
      html = html.replace('</head>', darkCss + '</head>');
    }
    EB.iframe.srcdoc = html;
  }
  function _ebSchedulePreviewReload(force){
    if (EB.previewDebounce) clearTimeout(EB.previewDebounce);
    EB.previewDebounce = setTimeout(() => _ebLoadPreview(), force ? 0 : 200);
  }
  function _ebPreviewVars(){
    // Sensible defaults so placeholders resolve in preview
    return {
      brand_name: EB.template.name || 'Your Brand',
      headline: 'Your headline here', subheadline: 'A compelling subheadline that explains the value.',
      body_text: 'This is placeholder body copy.',
      cta_text: 'Get Started', cta_url: '#',
      hero_image_url: 'https://via.placeholder.com/1200x520/5B5BD6/FFFFFF?text=Hero+Image',
      feature_1_icon: '⚡', feature_1_title: 'Fast', feature_1_body: 'Ship in minutes.',
      feature_2_icon: '📊', feature_2_title: 'Smart', feature_2_body: 'Data-driven.',
      feature_3_icon: '🔗', feature_3_title: 'Connected', feature_3_body: '200+ integrations.',
      testimonial_quote: 'This product changed how we work.', testimonial_name: 'Sarah Mitchell', testimonial_role: 'Head of Growth',
      secondary_headline: 'See it live in 15 minutes', secondary_body: 'Book a walkthrough.',
      secondary_cta_text: 'Book Demo', secondary_cta_url: '#',
      footer_text: 'Your Company · City, Country', unsubscribe_url: '#',
      current_year: String(new Date().getFullYear()),
    };
  }

  function _ebHandleMessage(evt){
    const d = evt.data; if (!d || !d.type) return;
    if (d.type === 'bridge-ready'){ EB.iframeReady = true; return; }
    if (d.type === 'block-hover'){ _ebShowBlockOverlay(d); return; }
    if (d.type === 'block-hover-end'){ _ebHideBlockOverlay(); return; }
    if (d.type === 'block-selected'){ _ebSelectBlockById(Number(d.blockId)); return; }
  }

  function _ebShowBlockOverlay(data){
    const ov = EB.root.querySelector('#eb-block-overlay');
    const wrap = EB.root.querySelector('#eb-iframe-wrap');
    const r = data.rect || {};
    const iframeRect = EB.iframe.getBoundingClientRect();
    const wrapRect = wrap.getBoundingClientRect();
    const top  = (iframeRect.top - wrapRect.top) + wrap.scrollTop + (r.top || 0);
    const left = (iframeRect.left - wrapRect.left) + wrap.scrollLeft + (r.left || 0);
    ov.style.top = top + 'px';
    ov.style.left = left + 'px';
    ov.style.width = (r.width || 0) + 'px';
    ov.style.height = (r.height || 0) + 'px';
    ov.classList.remove('hidden');
    // Remember which block the overlay is over
    EB.selectedBlock = EB.blocks.find(b => Number(b.id) === Number(data.blockId)) || null;
  }
  function _ebHideBlockOverlay(){
    EB.root.querySelector('#eb-block-overlay').classList.add('hidden');
  }

  function _ebSelectBlockById(id){
    const b = EB.blocks.find(x => Number(x.id) === Number(id));
    if (!b) return;
    EB.selectedBlock = b;
    _ebOpenBlockEditor(b);
    try {
      EB.iframe.contentWindow.postMessage({ type: 'highlight-block', blockId: id }, '*');
    } catch(_){}
  }

  // ── BLOCK EDITOR (right panel — Edit Block) ─────────────────────
  function _ebOpenBlockEditor(block){
    _ebSwitchRightPanel('edit');
    EB.root.querySelector('#eb-edit-block-type').textContent = BLOCK_LABEL[block.block_type] || block.block_type;
    const holder = EB.root.querySelector('#eb-edit-fields');
    holder.innerHTML = '';
    const content = block.content_json || {};
    Object.keys(content).forEach(k => {
      const type = _ebInferFieldType(k, content[k]);
      holder.appendChild(_ebBuildFieldRow(block, k, content[k], type));
    });
  }

  function _ebInferFieldType(key, value){
    const k = (key || '').toLowerCase();
    if (typeof value === 'boolean') return 'bool';
    if (k.endsWith('_color') || k === 'color' || k === 'bg_color' || k === 'text_color' || k === 'avatar_bg') return 'color';
    if (k === 'email' || k.endsWith('_email')) return 'email';
    if (k.includes('image') || k === 'logo_url' || k.endsWith('_image_url') || k === 'avatar_url') return 'image';
    if (k.endsWith('_url') || k === 'url' || k === 'unsubscribe_url') return 'url';
    if (['body_text','body_html','description','product_description','quote','testimonial_quote','subheadline','secondary_body','footer_text','raw_html'].includes(k)) return 'textarea';
    if (typeof value === 'number') return 'number';
    return 'text';
  }

  function _ebBuildFieldRow(block, key, value, type){
    const row = document.createElement('div');
    row.className = 'eb-field-group';
    const label = document.createElement('label');
    label.textContent = key.replace(/_/g, ' ');
    row.appendChild(label);

    let input;
    if (type === 'textarea') {
      input = document.createElement('textarea');
      input.rows = 4;
      input.value = (value == null ? '' : String(value));
    } else if (type === 'color') {
      const cr = document.createElement('div'); cr.className = 'eb-color-row';
      const ci = document.createElement('input'); ci.type = 'color'; ci.value = /^#[0-9A-Fa-f]{6}$/.test(value) ? value : '#000000';
      const ti = document.createElement('input'); ti.type = 'text'; ti.maxLength = 7; ti.value = (value == null ? '' : String(value));
      ci.oninput = () => { ti.value = ci.value; _ebFieldChange(block, key, ci.value); };
      ti.oninput = () => { if (/^#[0-9A-Fa-f]{6}$/.test(ti.value)) { ci.value = ti.value; _ebFieldChange(block, key, ti.value); } };
      cr.appendChild(ci); cr.appendChild(ti);
      row.appendChild(cr);
      return row;
    } else if (type === 'bool') {
      input = document.createElement('select');
      input.innerHTML = '<option value="true">On</option><option value="false">Off</option>';
      input.value = value ? 'true' : 'false';
      input.onchange = () => _ebFieldChange(block, key, input.value === 'true');
      row.appendChild(input); return row;
    } else if (type === 'image') {
      const group = document.createElement('div');
      group.style.cssText = 'display:flex;gap:6px';
      input = document.createElement('input'); input.type = 'url';
      input.value = (value == null ? '' : String(value));
      input.placeholder = 'Image URL';
      group.appendChild(input);
      const btn = document.createElement('button');
      btn.type = 'button'; btn.textContent = 'Browse';
      btn.className = 'eb-btn-ghost'; btn.style.cssText = 'padding:6px 10px;font-size:11px';
      btn.onclick = () => {
        if (typeof window.openMediaPicker !== 'function') { _ebToast('Media picker unavailable', 'error'); return; }
        window.openMediaPicker({ onSelect: (file) => { input.value = file.url; _ebFieldChange(block, key, file.url); } });
      };
      group.appendChild(btn);
      row.appendChild(group);
    } else if (type === 'number') {
      input = document.createElement('input'); input.type = 'number';
      input.value = (value == null ? '' : String(value));
    } else {
      input = document.createElement('input'); input.type = (type === 'email' ? 'email' : (type === 'url' ? 'url' : 'text'));
      input.value = (value == null ? '' : String(value));
    }

    let deb;
    input.oninput = () => {
      clearTimeout(deb);
      deb = setTimeout(() => {
        const v = (type === 'number') ? Number(input.value) : input.value;
        _ebFieldChange(block, key, v);
      }, 300);
    };
    row.appendChild(input);
    return row;
  }

  function _ebFieldChange(block, key, value){
    block.content_json = block.content_json || {};
    block.content_json[key] = value;
    _ebMarkDirty();
    // Live update via postMessage — no iframe reload
    try {
      EB.iframe.contentWindow.postMessage({
        type: 'update-field', blockId: String(block.id), field: key, value: String(value),
      }, '*');
    } catch(_){}
    // Persist the block to server (debounced via _ebMarkDirty + autoSaveTimer)
    // Also immediate PUT so iframe reload (on structural changes) shows latest
    clearTimeout(block.__saveDeb);
    block.__saveDeb = setTimeout(async () => {
      try {
        await _ebApi('PUT', '/marketing/email-builder/templates/' + EB.template.id + '/blocks/' + block.id, {
          content_json: block.content_json,
        });
      } catch(_){}
    }, 600);
  }

  // ── BLOCK OPS ───────────────────────────────────────────────────
  async function _ebAddBlock(blockType, order){
    _ebPushHistory();
    try {
      const res = await _ebApi('POST', '/marketing/email-builder/templates/' + EB.template.id + '/blocks', {
        block_type: blockType, block_order: order,
      });
      const fresh = await _ebApi('GET', '/marketing/email-builder/templates/' + EB.template.id + '/blocks');
      EB.blocks = fresh.blocks || [];
      await _ebLoadPreview();
      _ebMarkDirty();
      _ebToast('Block added');
    } catch (e) { _ebToast('Add failed: ' + e.message, 'error'); }
  }

  async function _ebDeleteBlock(id){
    _ebPushHistory();
    try {
      await _ebApi('DELETE', '/marketing/email-builder/templates/' + EB.template.id + '/blocks/' + id);
      EB.blocks = EB.blocks.filter(b => Number(b.id) !== Number(id));
      EB.selectedBlock = null; _ebHideBlockOverlay(); _ebSwitchRightPanel('ai');
      await _ebLoadPreview();
      _ebMarkDirty();
      _ebToast('Block deleted');
    } catch(e){ _ebToast('Delete failed: ' + e.message, 'error'); }
  }

  async function _ebDuplicateBlock(id){
    _ebPushHistory();
    const src = EB.blocks.find(b => Number(b.id) === Number(id));
    if (!src) return;
    try {
      await _ebApi('POST', '/marketing/email-builder/templates/' + EB.template.id + '/blocks', {
        block_type: src.block_type, block_order: src.block_order + 1,
        content_json: src.content_json, styles_json: src.styles_json || {},
      });
      const fresh = await _ebApi('GET', '/marketing/email-builder/templates/' + EB.template.id + '/blocks');
      EB.blocks = fresh.blocks || [];
      await _ebLoadPreview();
      _ebMarkDirty();
      _ebToast('Block duplicated');
    } catch(e){ _ebToast('Duplicate failed: ' + e.message, 'error'); }
  }

  async function _ebMoveBlock(id, delta){
    const idx = EB.blocks.findIndex(b => Number(b.id) === Number(id));
    if (idx < 0) return;
    const target = idx + delta;
    if (target < 0 || target >= EB.blocks.length) return;
    _ebPushHistory();
    const reordered = EB.blocks.slice();
    const [moved] = reordered.splice(idx, 1);
    reordered.splice(target, 0, moved);
    try {
      await _ebApi('POST', '/marketing/email-builder/templates/' + EB.template.id + '/blocks/reorder', {
        block_ids: reordered.map(b => b.id),
      });
      const fresh = await _ebApi('GET', '/marketing/email-builder/templates/' + EB.template.id + '/blocks');
      EB.blocks = fresh.blocks || [];
      await _ebLoadPreview();
      _ebMarkDirty();
    } catch(e){ _ebToast('Move failed: ' + e.message, 'error'); }
  }

  // ── BRAND COLOR ─────────────────────────────────────────────────
  async function _ebSetBrandColor(hex){
    if (!/^#[0-9A-Fa-f]{6}$/.test(hex)) return;
    EB.template.brand_color = hex;
    EB.root.querySelector('#eb-brand-color').value = hex;
    EB.root.querySelector('#eb-brand-color-hex').value = hex;
    try { EB.iframe.contentWindow.postMessage({ type: 'update-brand-color', color: hex }, '*'); } catch(_){}
    _ebMarkDirty();
    // Persist to template so next preview uses it
    try {
      await _ebApi('PUT', '/marketing/email-builder/templates/' + EB.template.id, { brand_color: hex });
    } catch(_){}
    // Schedule a full reload so the whole template recompiles with new tokens
    _ebSchedulePreviewReload(false);
  }

  // ── UNDO / REDO ─────────────────────────────────────────────────
  function _ebSnapshot(){
    return { blocks: JSON.parse(JSON.stringify(EB.blocks)), template: JSON.parse(JSON.stringify(EB.template)) };
  }
  function _ebPushHistory(){
    // Drop anything after current index, then push snapshot
    EB.history = EB.history.slice(0, EB.historyIndex + 1);
    EB.history.push(_ebSnapshot());
    if (EB.history.length > 50) EB.history.shift();
    EB.historyIndex = EB.history.length - 1;
    _ebSyncUndoRedoBtns();
  }
  function _ebSyncUndoRedoBtns(){
    EB.root.querySelector('#eb-undo').disabled = EB.historyIndex <= 0;
    EB.root.querySelector('#eb-redo').disabled = EB.historyIndex >= EB.history.length - 1;
  }
  async function _ebUndo(){
    if (EB.historyIndex <= 0) return;
    EB.historyIndex--;
    await _ebApplySnapshot(EB.history[EB.historyIndex]);
    _ebSyncUndoRedoBtns();
  }
  async function _ebRedo(){
    if (EB.historyIndex >= EB.history.length - 1) return;
    EB.historyIndex++;
    await _ebApplySnapshot(EB.history[EB.historyIndex]);
    _ebSyncUndoRedoBtns();
  }
  async function _ebApplySnapshot(snap){
    // Push full block state to server via reorder + individual updates
    EB.blocks = JSON.parse(JSON.stringify(snap.blocks));
    EB.template = Object.assign({}, EB.template, snap.template);
    // Server reconciliation: easiest path is to save each block then reorder.
    // For undo fidelity, we persist content + order; dropped-block restore is
    // out of scope here (would need recreate). We warn user if history step
    // would have required a recreate — still show the local state.
    try {
      for (const b of EB.blocks) {
        await _ebApi('PUT', '/marketing/email-builder/templates/' + EB.template.id + '/blocks/' + b.id, {
          content_json: b.content_json, is_visible: b.is_visible,
        });
      }
      await _ebApi('POST', '/marketing/email-builder/templates/' + EB.template.id + '/blocks/reorder', {
        block_ids: EB.blocks.map(b => b.id),
      });
    } catch (_) {}
    await _ebLoadPreview();
  }

  // ── DIRTY + SAVE ───────────────────────────────────────────────
  function _ebMarkDirty(){
    EB.dirty = true;
    const btn = EB.root && EB.root.querySelector('#eb-save');
    if (btn && !btn.dataset.dirty) { btn.dataset.dirty = '1'; btn.textContent = 'Save *'; }
  }
  async function _ebSave(silent){
    try {
      await _ebApi('PUT', '/marketing/email-builder/templates/' + EB.template.id, {
        name: EB.template.name, subject: EB.template.subject,
        preview_text: EB.template.preview_text, brand_color: EB.template.brand_color,
        category: EB.template.category,
      });
      EB.dirty = false;
      const btn = EB.root.querySelector('#eb-save'); btn.dataset.dirty = ''; btn.textContent = 'Save';
      if (!silent) _ebToast('Saved ✓', 'success');
    } catch(e){ if (!silent) _ebToast('Save failed: ' + e.message, 'error'); }
  }

  // ── EXIT ────────────────────────────────────────────────────────
  async function _ebRequestExit(){
    if (EB.dirty){
      const ok = await (window.luConfirm ? window.luConfirm('You have unsaved changes. Leave anyway?', 'Unsaved changes', 'Leave', 'Stay') : Promise.resolve(confirm('Unsaved changes. Leave anyway?')));
      if (!ok) return;
    }
    _ebTeardown();
  }
  function _ebTeardown(){
    window.removeEventListener('message', _ebHandleMessage);
    document.removeEventListener('keydown', _ebKeyHandler);
    if (EB.autoSaveTimer) clearInterval(EB.autoSaveTimer);
    if (EB.root) EB.root.remove();
    EB.root = null;
    // Refresh marketing list
    const el = document.getElementById('marketing-root');
    if (el) mktLoad(el);
  }

  // ── AI CHAT ─────────────────────────────────────────────────────
  async function _ebSendChat(message){
    _ebAddChatBubble(message, 'user');
    const loading = _ebAddChatBubble('Thinking', 'ai-loading');
    try {
      let result, applied = false;
      if (EB.selectedBlock){
        // Block-scoped rewrite
        result = await _ebApi('POST', '/marketing/email-builder/ai/rewrite-block', {
          template_id: EB.template.id, block_id: EB.selectedBlock.id, instruction: message,
        });
        if (result && result.success && result.content_json){
          EB.selectedBlock.content_json = result.content_json;
          _ebOpenBlockEditor(EB.selectedBlock);
          await _ebLoadPreview();
          applied = true;
        }
      } else {
        // Email-wide generate — creates a NEW template with the result.
        // For in-place edits of the current template, we don't have a server
        // endpoint yet — so this rewrites the current template in a new draft
        // and informs the user.
        result = await _ebApi('POST', '/marketing/email-builder/ai/generate', {
          prompt: message, goal: EB.template.category || 'announce', tone: 'professional',
          brand_name: EB.template.name || '', brand_color: EB.template.brand_color,
          name: 'Draft: ' + (EB.template.name || 'Untitled'),
        });
        if (result && result.success && result.template_id){
          loading.remove();
          const bubble = _ebAddChatBubble('', 'ai');
          bubble.innerHTML = 'I drafted a new template based on your request. <br><br>Subject A: <strong>' + _esc(result.subject_a || '') + '</strong><br>Subject B: <strong>' + _esc(result.subject_b || '') + '</strong>';
          const openBtn = document.createElement('button'); openBtn.className = 'eb-apply-btn'; openBtn.textContent = 'Open new draft';
          openBtn.onclick = () => { _ebTeardown(); setTimeout(() => mountEmailBuilder(result.template_id), 100); };
          bubble.appendChild(openBtn);
          return;
        }
      }
      loading.remove();
      if (applied) {
        _ebAddChatBubble('Done. The block was updated.', 'ai');
      } else {
        _ebAddChatBubble('AI failed: ' + (result && (result.error || result.message) || 'no response'), 'ai');
      }
    } catch(e){
      loading.remove();
      _ebAddChatBubble('Error: ' + e.message, 'ai');
    }
  }

  function _ebAddChatBubble(text, kind){
    const host = EB.root.querySelector('#eb-chat-history');
    const welcome = host.querySelector('.eb-chat-welcome'); if (welcome) welcome.remove();
    const b = document.createElement('div');
    b.className = 'eb-chat-bubble ' + (kind === 'user' ? 'user' : 'ai');
    if (kind === 'ai-loading'){ b.classList.add('loading'); b.innerHTML = _esc(text) + '<span class="eb-loading-dots"></span>'; }
    else b.textContent = text;
    host.appendChild(b);
    host.scrollTop = host.scrollHeight;
    return b;
  }

  function _ebShowRewriteBlockPrompt(){
    if (!EB.selectedBlock) return;
    const bd = _mktModal('AI Rewrite Block', `
      <div class="form-group">
        <label class="form-label">Instruction</label>
        <input class="form-input" id="eb-rw-instr" placeholder="e.g. make it shorter with more urgency">
      </div>`, [
      { label:'Cancel', cls:'btn-outline', close:true },
      { label:'✦ Rewrite', cls:'btn-primary', id:'eb-rw-go' }
    ]);
    bd.querySelector('#eb-rw-go').onclick = async () => {
      const instr = bd.querySelector('#eb-rw-instr').value.trim();
      if (!instr) return;
      _mktSetLoading(bd, '#eb-rw-go', true, 'Rewriting…');
      try {
        const res = await _ebApi('POST', '/marketing/email-builder/ai/rewrite-block', {
          template_id: EB.template.id, block_id: EB.selectedBlock.id, instruction: instr,
        });
        if (res && res.success && res.content_json){
          EB.selectedBlock.content_json = res.content_json;
          _ebOpenBlockEditor(EB.selectedBlock);
          await _ebLoadPreview();
          bd.remove(); _ebToast('Block rewritten', 'success');
        } else {
          _ebToast('Rewrite failed: ' + (res && res.error || 'no response'), 'error');
          _mktSetLoading(bd, '#eb-rw-go', false, '✦ Rewrite');
        }
      } catch(e){ _ebToast(e.message, 'error'); _mktSetLoading(bd, '#eb-rw-go', false, '✦ Rewrite'); }
    };
  }

  // ── SUBJECT GENERATOR ───────────────────────────────────────────
  async function _ebGenerateSubjects(){
    const btn = EB.root.querySelector('#eb-gen-subjects');
    btn.disabled = true; const prev = btn.textContent; btn.textContent = 'Generating…';
    try {
      const res = await _ebApi('POST', '/marketing/email-builder/ai/suggest-subject', {
        template_id: EB.template.id, goal: EB.template.category || 'announce', tone: 'professional', brand_name: EB.template.name || '',
      });
      const holder = EB.root.querySelector('#eb-subject-suggestions');
      holder.innerHTML = '';
      (res.subjects || []).forEach(s => {
        const el = document.createElement('div');
        el.className = 'eb-suggestion';
        el.innerHTML = `<div>${_esc(s.text)}</div><div class="eb-sug-meta">${_esc(s.angle || '')} · score ${_esc(String(s.score || ''))} · ${_esc(s.reason || '')}</div>`;
        el.onclick = () => {
          EB.template.subject = s.text;
          EB.root.querySelector('#eb-subject').value = s.text;
          _ebMarkDirty();
          _ebToast('Subject applied');
        };
        holder.appendChild(el);
      });
      if (!res.subjects || res.subjects.length === 0) _ebToast('AI returned no options (check DeepSeek key)', 'warning');
    } catch(e){ _ebToast('Subject generate failed: ' + e.message, 'error'); }
    btn.disabled = false; btn.textContent = prev;
  }

  // ── SPAM CHECK ─────────────────────────────────────────────────
  async function _ebSpamCheck(){
    try {
      const res = await _ebApi('POST', '/marketing/email-builder/ai/spam-check', {
        template_id: EB.template.id, subject: EB.template.subject || '',
      });
      const bd = _mktModal('Spam Check', `
        <div class="eb-spam-modal-body">
          <div class="eb-spam-score ${_esc(res.rating)}">${res.score}/${res.max_score}</div>
          <div class="eb-spam-rating ${_esc(res.rating)}">${_esc(res.rating)}</div>
          <div style="text-align:center;color:var(--t2,#CBD5E1);font-size:13px;margin-bottom:16px">${_esc(res.recommendation || '')}</div>
          <div style="display:flex;gap:16px;font-size:11px;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">
            <span>Subject score: ${res.subject_score}</span>
            <span>Content score: ${res.content_score}</span>
          </div>
          <div class="eb-spam-issues">
            ${(res.issues || []).map(i => `
              <div class="eb-spam-issue">
                <div class="eb-issue-rule">${_esc(i.rule)} <span style="opacity:.6;font-weight:400">(+${_esc(String(i.severity))})</span></div>
                <div class="eb-issue-fix">${_esc(i.fix)}</div>
              </div>
            `).join('') || '<div style="color:var(--t3,#64748B);text-align:center;padding:20px">No issues found.</div>'}
          </div>
        </div>`, [{ label:'Close', cls:'btn-primary', close:true }]);
    } catch(e){ _ebToast('Spam check failed: ' + e.message, 'error'); }
  }

  // ── SEND TEST ──────────────────────────────────────────────────
  function _ebShowSendTestModal(){
    if (!EB.campaignId){
      _ebToast('Send Test requires a campaign — open this template from a campaign first.', 'warning');
      return;
    }
    const bd = _mktModal('Send Test Email', `
      <div class="form-group">
        <label class="form-label">Send test to</label>
        <input class="form-input" id="eb-test-to" type="email" placeholder="you@yourdomain.com">
        <div style="font-size:11px;color:var(--t3);margin-top:6px">Subject will be prefixed with [TEST].</div>
      </div>`, [
      { label:'Cancel', cls:'btn-outline', close:true },
      { label:'Send Test', cls:'btn-primary', id:'eb-test-go' }
    ]);
    bd.querySelector('#eb-test-go').onclick = async () => {
      const to = bd.querySelector('#eb-test-to').value.trim();
      if (!to) return;
      _mktSetLoading(bd, '#eb-test-go', true, 'Sending…');
      try {
        const res = await _ebApi('POST', '/marketing/email-builder/campaigns/' + EB.campaignId + '/send-test', {
          to_email: to, variables: _ebPreviewVars(),
        });
        if (res.success){ bd.remove(); _ebToast('Test sent ✓', 'success'); }
        else { _ebToast('Send failed: ' + (res.message || 'unknown'), 'error'); _mktSetLoading(bd, '#eb-test-go', false, 'Send Test'); }
      } catch(e){ _ebToast(e.message, 'error'); _mktSetLoading(bd, '#eb-test-go', false, 'Send Test'); }
    };
  }

  // ── PREVIEW MODAL ──────────────────────────────────────────────
  async function _ebShowPreviewModal(){
    let html = '';
    try {
      const res = await _ebApi('POST', '/marketing/email-builder/templates/' + EB.template.id + '/preview', {
        variables: _ebPreviewVars(), format: 'desktop',
      });
      html = res.html || '';
    } catch(e){ _ebToast('Preview failed: ' + e.message, 'error'); return; }
    const bd = document.createElement('div');
    bd.className = 'modal-backdrop';
    bd.onclick = (e) => { if (e.target === bd) bd.remove(); };
    bd.innerHTML = `
      <div class="modal" style="max-width:720px;width:95%;max-height:90vh;display:flex;flex-direction:column">
        <div class="modal-header">
          <h3>Preview</h3>
          <div style="display:flex;gap:8px;align-items:center">
            <button class="btn btn-outline btn-sm" id="eb-pv-download">Download HTML</button>
            <button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button>
          </div>
        </div>
        <div class="modal-body" style="padding:0;overflow:auto;background:#1a1a2e;flex:1">
          <iframe id="eb-pv-iframe" style="width:100%;height:70vh;border:none;background:#fff"></iframe>
        </div>
      </div>`;
    document.body.appendChild(bd);
    bd.querySelector('#eb-pv-iframe').srcdoc = html;
    bd.querySelector('#eb-pv-download').onclick = async () => {
      const blob = new Blob([html], { type: 'text/html' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url;
      a.download = (EB.template.name || 'email') + '.html';
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
    };
  }

  // ═══════════════════════════════════════════════════════════════
  // ENTRY-POINT MODALS
  // ═══════════════════════════════════════════════════════════════

  async function openNewTemplateModal(){
    _ebInjectCSS();
    const bd = _mktModal('New Email Template', `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <button class="eb-btn-outline" style="padding:18px 12px" id="eb-nt-blank">
          <div style="font-weight:700;font-size:13px;margin-bottom:4px">Blank</div>
          <div style="font-size:11px;color:var(--t3,#64748B)">Start with header + hero + footer</div>
        </button>
        <button class="eb-btn-outline" style="padding:18px 12px" id="eb-nt-ai">
          <div style="font-weight:700;font-size:13px;margin-bottom:4px">✦ Generate with AI</div>
          <div style="font-size:11px;color:var(--t3,#64748B)">Describe what you need</div>
        </button>
        <button class="eb-btn-outline" style="padding:18px 12px" id="eb-nt-html">
          <div style="font-weight:700;font-size:13px;margin-bottom:4px">Upload HTML</div>
          <div style="font-size:11px;color:var(--t3,#64748B)">Paste your own HTML</div>
        </button>
        <button class="eb-btn-outline" style="padding:18px 12px" id="eb-nt-clone">
          <div style="font-weight:700;font-size:13px;margin-bottom:4px">Clone existing</div>
          <div style="font-size:11px;color:var(--t3,#64748B)">Start from a saved template</div>
        </button>
      </div>
      <div class="form-group">
        <label class="form-label">Template name</label>
        <input class="form-input" id="eb-nt-name" placeholder="e.g. Spring Promo">
      </div>
    `, [{ label:'Cancel', cls:'btn-outline', close:true }]);
    const pickName = () => bd.querySelector('#eb-nt-name').value.trim() || 'Untitled Email';

    bd.querySelector('#eb-nt-blank').onclick = async () => {
      try {
        const res = await _ebApi('POST', '/marketing/email-builder/templates', { name: pickName(), source: 'blank' });
        bd.remove();
        mountEmailBuilder(res.template.id);
      } catch(e){ _ebToast(e.message, 'error'); }
    };
    bd.querySelector('#eb-nt-ai').onclick = () => { bd.remove(); openAIGenerateModal(pickName()); };
    bd.querySelector('#eb-nt-html').onclick = () => { bd.remove(); openUploadHtmlModal(pickName()); };
    bd.querySelector('#eb-nt-clone').onclick = () => { bd.remove(); openCloneModal(pickName()); };
  }

  async function openUploadHtmlModal(prefillName){
    const bd = _mktModal('Upload HTML', `
      <div class="form-group"><label class="form-label">Name</label><input class="form-input" id="eb-up-name" value="${_esc(prefillName||'')}"></div>
      <div class="form-group">
        <label class="form-label">HTML content</label>
        <textarea class="form-input" id="eb-up-html" style="min-height:200px;font-family:monospace;font-size:11px" placeholder="Paste your email HTML here..."></textarea>
      </div>`, [
      { label:'Cancel', cls:'btn-outline', close:true },
      { label:'Create', cls:'btn-primary', id:'eb-up-go' }
    ]);
    bd.querySelector('#eb-up-go').onclick = async () => {
      const html = bd.querySelector('#eb-up-html').value;
      if (!html.trim()) { _ebToast('Paste HTML first', 'warning'); return; }
      _mktSetLoading(bd, '#eb-up-go', true, 'Creating…');
      try {
        const res = await _ebApi('POST', '/marketing/email-builder/templates', {
          name: bd.querySelector('#eb-up-name').value.trim() || 'Uploaded', source: 'html', html_content: html,
        });
        bd.remove();
        mountEmailBuilder(res.template.id);
      } catch(e){ _ebToast(e.message, 'error'); _mktSetLoading(bd, '#eb-up-go', false, 'Create'); }
    };
  }

  async function openCloneModal(prefillName){
    try {
      const list = await _ebApi('GET', '/marketing/email-builder/templates');
      const templates = list.templates || [];
      const options = templates.map(t => `<option value="${t.id}">${_esc(t.name)} ${t.is_system ? '(system)' : ''}</option>`).join('');
      const bd = _mktModal('Clone Template', `
        <div class="form-group"><label class="form-label">Source</label><select class="form-input" id="eb-cl-src">${options}</select></div>
        <div class="form-group"><label class="form-label">New name</label><input class="form-input" id="eb-cl-name" value="${_esc(prefillName||'')}"></div>
      `, [
        { label:'Cancel', cls:'btn-outline', close:true },
        { label:'Clone', cls:'btn-primary', id:'eb-cl-go' }
      ]);
      bd.querySelector('#eb-cl-go').onclick = async () => {
        _mktSetLoading(bd, '#eb-cl-go', true, 'Cloning…');
        try {
          const res = await _ebApi('POST', '/marketing/email-builder/templates', {
            name: bd.querySelector('#eb-cl-name').value.trim() || 'Clone',
            source: 'clone',
            clone_from_id: Number(bd.querySelector('#eb-cl-src').value),
          });
          bd.remove();
          mountEmailBuilder(res.template.id);
        } catch(e){ _ebToast(e.message, 'error'); _mktSetLoading(bd, '#eb-cl-go', false, 'Clone'); }
      };
    } catch(e){ _ebToast('Load templates failed: ' + e.message, 'error'); }
  }

  async function openAIGenerateModal(prefillName){
    const bd = _mktModal('Generate Email with AI', `
      <div class="form-group"><label class="form-label">Template name</label><input class="form-input" id="eb-ai-name" value="${_esc(prefillName||'')}"></div>
      <div class="form-group"><label class="form-label">Describe your email</label><textarea class="form-input" id="eb-ai-prompt" rows="3" placeholder="e.g. Promo email for our 20% off summer sale, targeting returning customers."></textarea></div>
      <div class="form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div>
          <label class="form-label">Goal</label>
          <select class="form-input" id="eb-ai-goal">
            <option value="sell">Sell</option>
            <option value="nurture">Nurture</option>
            <option value="announce" selected>Announce</option>
            <option value="onboard">Onboard</option>
            <option value="reactivate">Reactivate</option>
          </select>
        </div>
        <div>
          <label class="form-label">Tone</label>
          <select class="form-input" id="eb-ai-tone">
            <option value="professional" selected>Professional</option>
            <option value="casual">Casual</option>
            <option value="urgent">Urgent</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Brand name</label><input class="form-input" id="eb-ai-brand" placeholder="Your Brand"></div>
      <div class="form-group"><label class="form-label">Industry (optional)</label><input class="form-input" id="eb-ai-industry" placeholder="SaaS, ecommerce, etc."></div>
    `, [
      { label:'Cancel', cls:'btn-outline', close:true },
      { label:'✦ Generate Email', cls:'btn-primary', id:'eb-ai-go' }
    ]);
    bd.querySelector('#eb-ai-go').onclick = async () => {
      _mktSetLoading(bd, '#eb-ai-go', true, '✦ AI is writing your email…');
      try {
        const res = await _ebApi('POST', '/marketing/email-builder/ai/generate', {
          name:       bd.querySelector('#eb-ai-name').value.trim() || 'AI Draft',
          prompt:     bd.querySelector('#eb-ai-prompt').value.trim(),
          goal:       bd.querySelector('#eb-ai-goal').value,
          tone:       bd.querySelector('#eb-ai-tone').value,
          brand_name: bd.querySelector('#eb-ai-brand').value.trim(),
          industry:   bd.querySelector('#eb-ai-industry').value.trim(),
        });
        if (res.success && res.template_id){
          bd.remove();
          _ebToast('Generated. Subject A: ' + (res.subject_a || ''), 'success');
          mountEmailBuilder(res.template_id);
        } else {
          _ebToast('AI failed: ' + (res.error || 'no response'), 'error');
          _mktSetLoading(bd, '#eb-ai-go', false, '✦ Generate Email');
        }
      } catch(e){ _ebToast(e.message, 'error'); _mktSetLoading(bd, '#eb-ai-go', false, '✦ Generate Email'); }
    };
  }

  // ═══════════════════════════════════════════════════════════════
  // EXPOSURE
  // ═══════════════════════════════════════════════════════════════
  window.openEmailBuilder    = mountEmailBuilder;
  window.openAIGenerateEmail = openAIGenerateModal;
  window.openNewEmailTemplate = openNewTemplateModal;
})();

// ═══════════════════════════════════════════════════════════════════
// MARKETING PHASE 5 ADDON — send progress + analytics + settings
// v4.7.4
// ═══════════════════════════════════════════════════════════════════
(function(){
  'use strict';

  // ── CSS ────────────────────────────────────────────────────────
  if (!document.getElementById('mkt-p5-styles')) {
    const s = document.createElement('style');
    s.id = 'mkt-p5-styles';
    s.textContent = `
.mp5-progress-wrap{background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:10px;padding:16px;margin:12px 0}
.mp5-progress-bar{height:10px;background:rgba(255,255,255,.06);border-radius:5px;overflow:hidden}
.mp5-progress-fill{height:100%;background:linear-gradient(90deg,var(--p,#6C5CE7),var(--ac,#A78BFA));transition:width .4s ease;border-radius:5px}
.mp5-progress-meta{display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--t2,#CBD5E1)}
.mp5-analytics{padding:18px;background:var(--s1,#0F1218);border-radius:12px;margin-top:14px}
.mp5-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px}
.mp5-kpi{background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:10px;padding:14px 16px}
.mp5-kpi-label{font-size:10px;font-weight:700;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.mp5-kpi-value{font-size:22px;font-weight:800;color:var(--t1,#F1F5F9);letter-spacing:-0.5px}
.mp5-kpi-sub{font-size:11px;color:var(--t3,#64748B);margin-top:2px}
.mp5-section{margin-bottom:20px}
.mp5-section-title{font-size:11px;font-weight:700;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}
.mp5-funnel{display:flex;flex-direction:column;gap:4px}
.mp5-funnel-row{display:flex;align-items:center;gap:10px;font-size:12px}
.mp5-funnel-label{width:84px;color:var(--t2,#CBD5E1);font-weight:600}
.mp5-funnel-bar{flex:1;height:22px;background:rgba(255,255,255,.05);border-radius:4px;overflow:hidden;position:relative}
.mp5-funnel-fill{height:100%;background:var(--p,#6C5CE7)}
.mp5-funnel-count{position:absolute;left:8px;top:50%;transform:translateY(-50%);color:#fff;font-size:11px;font-weight:700;mix-blend-mode:difference}
.mp5-devices{display:flex;gap:8px}
.mp5-device-pill{flex:1;padding:10px 12px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;text-align:center}
.mp5-device-pill strong{display:block;font-size:18px;color:var(--t1,#F1F5F9);font-weight:800}
.mp5-device-pill span{font-size:11px;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.mp5-hour-chart{display:flex;align-items:flex-end;gap:2px;height:80px;padding:8px 0}
.mp5-hour-bar{flex:1;background:rgba(108,92,231,.3);border-radius:2px 2px 0 0;position:relative;min-height:2px;transition:background .2s}
.mp5-hour-bar.peak{background:var(--p,#6C5CE7)}
.mp5-hour-bar:hover::after{content:attr(data-hour) ':00 — ' attr(data-count);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#0b0c11;color:#fff;font-size:10px;padding:3px 6px;border-radius:3px;white-space:nowrap;margin-bottom:4px}
.mp5-hour-labels{display:flex;justify-content:space-between;font-size:10px;color:var(--t3,#64748B);margin-top:4px}
.mp5-links-table{width:100%;border-collapse:collapse;font-size:12px}
.mp5-links-table th,.mp5-links-table td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--bd,#2a2f3a)}
.mp5-links-table th{font-size:10px;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em}
.mp5-links-table .url{color:var(--t1,#F1F5F9);max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mp5-insight{background:linear-gradient(135deg,rgba(108,92,231,.15),rgba(167,139,250,.15));border:1px solid rgba(108,92,231,.4);border-radius:10px;padding:14px 16px;font-size:13px;line-height:1.6;color:var(--t1,#F1F5F9)}
.mp5-insight::before{content:'✦ ';color:var(--ac,#A78BFA);font-weight:700}
.mp5-empty{text-align:center;padding:32px;color:var(--t3,#64748B);font-size:13px}
    `;
    document.head.appendChild(s);
  }

  // ── Send flow with progress polling ─────────────────────────────
  async function sendCampaignWithProgress(campaignId, campaignName){
    const bd = _mktModal('Sending ' + (campaignName || 'campaign'), `
      <div class="mp5-progress-wrap">
        <div id="mp5-send-status" style="font-size:13px;color:var(--t1,#F1F5F9);margin-bottom:10px">Starting…</div>
        <div class="mp5-progress-bar"><div class="mp5-progress-fill" id="mp5-send-fill" style="width:0%"></div></div>
        <div class="mp5-progress-meta"><span id="mp5-send-count">0 / 0</span><span id="mp5-send-pct">0%</span></div>
      </div>
      <div id="mp5-send-errors" style="color:#EF4444;font-size:12px;margin-top:8px"></div>
    `, [
      { label:'Close', cls:'btn-outline', close:true, id:'mp5-close' }
    ]);

    let res;
    try {
      res = await _mktApi('POST', '/marketing/email-builder/campaigns/' + campaignId + '/send');
    } catch (e) {
      bd.querySelector('#mp5-send-status').textContent = 'Failed: ' + e.message;
      bd.querySelector('#mp5-send-status').style.color = '#EF4444';
      return;
    }
    if (!res.queued){
      const errs = res.errors || [res.error || 'validation failed'];
      bd.querySelector('#mp5-send-status').textContent = 'Can\'t send';
      bd.querySelector('#mp5-send-errors').innerHTML = errs.map(e => '• ' + _esc(String(e))).join('<br>');
      return;
    }
    bd.querySelector('#mp5-send-status').textContent = 'Queued. Sending now…';

    let lastStatus = '';
    const poll = async () => {
      try {
        const s = await _mktApi('GET', '/marketing/email-builder/campaigns/' + campaignId + '/send-status');
        lastStatus = s.status;
        const fill = bd.querySelector('#mp5-send-fill');
        const count = bd.querySelector('#mp5-send-count');
        const pct = bd.querySelector('#mp5-send-pct');
        const statusTxt = bd.querySelector('#mp5-send-status');
        if (fill) fill.style.width = (s.progress_pct || 0) + '%';
        if (count) count.textContent = (s.sent_count + s.failed_count) + ' / ' + s.total_count + (s.failed_count ? ' (' + s.failed_count + ' failed)' : '');
        if (pct) pct.textContent = (s.progress_pct || 0) + '%';
        if (statusTxt) statusTxt.textContent = s.status === 'sent' ? 'Done' : ('Sending… ' + (s.sent_count + s.failed_count) + '/' + s.total_count);
        if (s.status === 'sent' || s.status === 'failed') {
          const final = `Sent to ${s.sent_count} recipients` + (s.failed_count ? ` (${s.failed_count} bounced)` : '');
          if (statusTxt) { statusTxt.textContent = final; statusTxt.style.color = s.failed_count ? '#F59E0B' : '#10B981'; }
          return;
        }
      } catch(_){}
      setTimeout(poll, 2000);
    };
    setTimeout(poll, 1000);
  }

  // ── Campaign detail with Analytics tab ──────────────────────────
  async function openCampaignAnalytics(campaignId, campaignName){
    const bd = document.createElement('div');
    bd.className = 'modal-backdrop';
    bd.onclick = (e) => { if (e.target === bd) bd.remove(); };
    bd.innerHTML = `
      <div class="modal" style="max-width:820px;width:95%;max-height:92vh;overflow-y:auto">
        <div class="modal-header">
          <h3>Analytics — ${_esc(campaignName || 'Campaign #' + campaignId)}</h3>
          <button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button>
        </div>
        <div class="modal-body" style="padding:0">
          <div class="mp5-analytics" id="mp5-an-body">
            <div class="mp5-empty">Loading analytics…</div>
          </div>
        </div>
      </div>`;
    document.body.appendChild(bd);

    try {
      const a = await _mktApi('GET', '/marketing/email-builder/campaigns/' + campaignId + '/analytics');
      bd.querySelector('#mp5-an-body').innerHTML = _buildAnalyticsHtml(a);
    } catch(e) {
      bd.querySelector('#mp5-an-body').innerHTML = `<div class="mp5-empty" style="color:#EF4444">Failed to load analytics: ${_esc(e.message)}</div>`;
    }
  }

  function _buildAnalyticsHtml(a){
    if (!a || typeof a !== 'object') return '<div class="mp5-empty">No analytics yet — send the campaign first.</div>';
    if ((a.sent || 0) === 0) return '<div class="mp5-empty">No sends recorded yet for this campaign.</div>';

    const kpis = `
      <div class="mp5-kpis">
        <div class="mp5-kpi">
          <div class="mp5-kpi-label">Sent</div>
          <div class="mp5-kpi-value">${_fmt(a.sent)}</div>
          <div class="mp5-kpi-sub">${_fmt(a.delivered)} delivered</div>
        </div>
        <div class="mp5-kpi">
          <div class="mp5-kpi-label">Opens</div>
          <div class="mp5-kpi-value">${a.open_rate}%</div>
          <div class="mp5-kpi-sub">${_fmt(a.opened)} opens</div>
        </div>
        <div class="mp5-kpi">
          <div class="mp5-kpi-label">Clicks</div>
          <div class="mp5-kpi-value">${a.click_rate}%</div>
          <div class="mp5-kpi-sub">${_fmt(a.clicked)} clicks</div>
        </div>
        <div class="mp5-kpi">
          <div class="mp5-kpi-label">Bounced</div>
          <div class="mp5-kpi-value">${a.bounce_rate}%</div>
          <div class="mp5-kpi-sub">${_fmt(a.bounced)} bounces</div>
        </div>
      </div>
    `;

    // Funnel — each step is a bar proportional to the total sent
    const total = Math.max(1, a.sent || 1);
    const steps = [
      ['Sent',      a.sent,      100,                                ''],
      ['Delivered', a.delivered, Math.round(a.delivered/total*100), a.delivered ? ((a.delivered/total*100).toFixed(1)+'%') : ''],
      ['Opened',    a.opened,    Math.round(a.opened/total*100),    a.open_rate + '%'],
      ['Clicked',   a.clicked,   Math.round(a.clicked/total*100),   a.click_rate + '%'],
    ];
    const funnel = `
      <div class="mp5-section">
        <div class="mp5-section-title">Funnel</div>
        <div class="mp5-funnel">
          ${steps.map(([label, count, pct, rate]) => `
            <div class="mp5-funnel-row">
              <div class="mp5-funnel-label">${label}</div>
              <div class="mp5-funnel-bar">
                <div class="mp5-funnel-fill" style="width:${Math.max(pct,2)}%"></div>
                <div class="mp5-funnel-count">${_fmt(count)}${rate ? ' · ' + rate : ''}</div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>
    `;

    // Device split
    const dev = a.opens_by_device || {desktop:0, mobile:0, tablet:0};
    const devTotal = Math.max(1, (dev.desktop||0) + (dev.mobile||0) + (dev.tablet||0));
    const deviceHtml = `
      <div class="mp5-section">
        <div class="mp5-section-title">Opens by device</div>
        <div class="mp5-devices">
          <div class="mp5-device-pill"><strong>${Math.round((dev.desktop||0)*100/devTotal)}%</strong><span>Desktop · ${dev.desktop||0}</span></div>
          <div class="mp5-device-pill"><strong>${Math.round((dev.mobile||0)*100/devTotal)}%</strong><span>Mobile · ${dev.mobile||0}</span></div>
          <div class="mp5-device-pill"><strong>${Math.round((dev.tablet||0)*100/devTotal)}%</strong><span>Tablet · ${dev.tablet||0}</span></div>
        </div>
      </div>
    `;

    // Opens by hour
    const hours = a.opens_by_hour || Array(24).fill(0);
    const peak = Math.max(...hours);
    const peakIdx = hours.indexOf(peak);
    const hourHtml = `
      <div class="mp5-section">
        <div class="mp5-section-title">Opens by hour (server local)</div>
        <div class="mp5-hour-chart">
          ${hours.map((count, h) => {
            const pct = peak > 0 ? (count / peak) * 100 : 0;
            return `<div class="mp5-hour-bar${h === peakIdx && peak > 0 ? ' peak' : ''}" data-hour="${h}" data-count="${count}" style="height:${Math.max(pct,2)}%"></div>`;
          }).join('')}
        </div>
        <div class="mp5-hour-labels"><span>12am</span><span>6am</span><span>12pm</span><span>6pm</span><span>11pm</span></div>
      </div>
    `;

    // Top links
    const links = a.top_links || [];
    const linksHtml = links.length ? `
      <div class="mp5-section">
        <div class="mp5-section-title">Top clicked links</div>
        <table class="mp5-links-table">
          <thead><tr><th>URL</th><th style="text-align:right">Clicks</th><th style="text-align:right">%</th></tr></thead>
          <tbody>
            ${links.map(l => `<tr>
              <td class="url" title="${_esc(l.url)}">${_esc(l.url)}</td>
              <td style="text-align:right">${l.clicks}</td>
              <td style="text-align:right;color:var(--t3,#64748B)">${l.percentage}%</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    ` : '';

    // A/B subject variants
    const ab = (a.subject_a_opens > 0 || a.subject_b_opens > 0) ? `
      <div class="mp5-section">
        <div class="mp5-section-title">A/B subject line</div>
        <div class="mp5-devices">
          <div class="mp5-device-pill"><strong>${a.subject_a_opens}</strong><span>Subject A opens</span></div>
          <div class="mp5-device-pill"><strong>${a.subject_b_opens}</strong><span>Subject B opens</span></div>
        </div>
      </div>
    ` : '';

    // AI insight
    const insight = a.ai_insight ? `
      <div class="mp5-section">
        <div class="mp5-insight">${_esc(a.ai_insight)}</div>
      </div>
    ` : '';

    return kpis + funnel + deviceHtml + hourHtml + linksHtml + ab + insight;
  }

  function _fmt(n){
    n = Number(n || 0);
    return n >= 1000 ? (n/1000).toFixed(1).replace(/\.0$/,'') + 'K' : String(n);
  }

  // ── Wire up — replace the sendCampaign path, add [Analytics] button ────
  // mktSendCampaign already exists. We replace the post-confirm send path with
  // our progress-polling version.
  if (typeof window.mktSendCampaign === 'function') {
    const _origSend = window.mktSendCampaign;
    window.mktSendCampaign = async function(id, name) {
      // Reuse the existing confirm prompt from the original implementation:
      const confirmed = await (window.luConfirm
        ? window.luConfirm('Send "' + (name || 'this campaign') + '" to all recipients now?', 'Send Campaign', 'Send', 'Cancel')
        : Promise.resolve(confirm('Send "' + (name || 'this campaign') + '" to all recipients now?')));
      if (!confirmed) return;
      sendCampaignWithProgress(id, name);
    };
  }

  // Expose for the campaign row [Analytics] button
  window.mktCampaignAnalytics = openCampaignAnalytics;
  window.mktSendWithProgress  = sendCampaignWithProgress;
})();

// ─── Templates sub-tab switcher (added 2026-04-24) ───
window.mktSwitchTplTab = function(which){
  var mine  = document.getElementById('mkt-tpl-tab-mine');
  var lib   = document.getElementById('mkt-tpl-tab-lib');
  var mineV = document.getElementById('mkt-tpl-mine');
  var libV  = document.getElementById('mkt-tpl-lib');
  var newBtn= document.getElementById('mkt-tpl-newbtn');
  if (!mine || !lib) return;
  if (which === 'lib'){
    mine.style.background = 'transparent'; mine.style.color = 'var(--t3)';
    lib.style.background  = 'var(--p)';    lib.style.color  = '#fff';
    if (mineV) mineV.style.display = 'none';
    if (libV)  libV.style.display  = '';
    if (newBtn) newBtn.style.display = 'none';
  } else {
    mine.style.background = 'var(--p)';    mine.style.color = '#fff';
    lib.style.background  = 'transparent'; lib.style.color  = 'var(--t3)';
    if (mineV) mineV.style.display = '';
    if (libV)  libV.style.display  = 'none';
    if (newBtn) newBtn.style.display = '';
  }
};

window.mktUseTemplate = async function(id, name){
  try {
    var r = await _mktApi('POST', '/marketing/email-builder/templates/' + id + '/use');
    if (!r.success) throw new Error(r.error || 'use_failed');
    showToast('"' + (name || 'Template') + '" copied to your templates', 'success');
    if (typeof window.openEmailBuilder === 'function') window.openEmailBuilder(r.template_id);
    else mktLoad(document.getElementById('marketing-root'));
  } catch(e){ showToast('Failed: ' + e.message, 'error'); }
};
