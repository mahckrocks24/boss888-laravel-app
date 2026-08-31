// LevelUp PROJECTS Engine — v1.0.1
// Renders the Projects workspace: list view + single-project view with 8 tabs.
// Reads + mutations: dispose, milestone achieve, KPI measure, create new
// milestone/KPI all wire to live endpoints. Inline forms render in-place.

window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['projects'] = true;

// ═══════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════
var _pj = {
  mode: 'list',          // 'list' | 'single'
  list: null,            // last list response
  single: null,          // last single response
  activeId: null,        // current project_id in single view
  activeTab: 'overview', // overview|milestones|kpis|tasks|calendar|publish|discussion|outcomes
  tabCache: {},          // {milestones: ..., kpis: ..., timeline: ..., publish: ...}
  filters: {
    status: '',
    source_type: '',
    q: '',
    sort: 'created_at',
    dir: 'desc',
    page: 1,
    per_page: 20,
  },
};

// ═══════════════════════════════════════════════════════════════════
// API
// ═══════════════════════════════════════════════════════════════════
async function _pjApi(path, opts) {
  opts = opts || {};
  const init = {
    method: opts.method || 'GET',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
    },
    cache: 'no-store',
  };
  if (opts.body) init.body = typeof opts.body === 'string' ? opts.body : JSON.stringify(opts.body);

  let r;
  try { r = await fetch(window.location.origin + '/api' + path, init); }
  catch (e) { throw new Error('Network error: ' + e.message); }

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
// LOAD ENTRY (dispatched from core.js)
// ═══════════════════════════════════════════════════════════════════
async function projectsLoad(el) {
  if (!el) return;
  // Deep-link via #project=ID could land us in single mode
  const hashMatch = (location.hash || '').match(/[?&]project=(\d+)/);
  if (hashMatch) {
    _pj.mode = 'single';
    _pj.activeId = parseInt(hashMatch[1], 10);
  }
  if (_pj.mode === 'single' && _pj.activeId) {
    return _pjRenderSingle(el, _pj.activeId);
  }
  return _pjRenderList(el);
}

// ═══════════════════════════════════════════════════════════════════
// LIST VIEW
// ═══════════════════════════════════════════════════════════════════
async function _pjRenderList(el) {
  el.innerHTML = (typeof loadingCard === 'function' ? loadingCard(300)
    : '<div style="padding:60px;text-align:center;color:var(--t2)">Loading projects…</div>');
  _pj.mode = 'list';

  try {
    const qs = [];
    Object.keys(_pj.filters).forEach(k => {
      const v = _pj.filters[k];
      if (v !== '' && v !== null && v !== undefined) qs.push(k + '=' + encodeURIComponent(v));
    });
    const resp = await _pjApi('/projects' + (qs.length ? '?' + qs.join('&') : ''));
    _pj.list = resp;
    el.innerHTML = _pjListHtml(resp);
  } catch (e) {
    console.error('[Projects]', e);
    el.innerHTML = _pjErrorHtml('Projects list failed to load', e, "projectsLoad(document.getElementById('projects-root'))");
  }
}

function _pjListHtml(resp) {
  const rows = (resp && resp.data) || [];
  const paging = (resp && resp.paging) || {};
  const total = paging.total || 0;

  return `
    <div style="max-width:1200px;margin:0 auto;padding:24px">
      ${_pjListHeaderHtml(total)}
      ${_pjFiltersHtml()}
      ${rows.length === 0 ? _pjListEmptyHtml() : _pjListCardsHtml(rows)}
      ${rows.length > 0 ? _pjPagingHtml(paging) : ''}
      <div style="font-size:11px;color:var(--t3);text-align:center;margin-top:24px;padding:8px">
        v1.0.1 — Projects · sort: ${resp.sort || 'created_at'} ${resp.dir || 'desc'}
      </div>
    </div>
  `;
}

function _pjListHeaderHtml(total) {
  return `
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px">
      <div>
        <h1 style="font-size:24px;font-weight:700;margin:0;color:var(--t1)">Projects</h1>
        <div style="font-size:13px;color:var(--t3);margin-top:2px">${total} project${total === 1 ? '' : 's'} in this workspace</div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline btn-sm" onclick="nav('meeting')">+ From Strategy Room</button>
        <button class="btn btn-primary btn-sm" onclick="_pjNewProject()">+ New Project</button>
      </div>
    </div>
  `;
}

function _pjFiltersHtml() {
  const f = _pj.filters;
  return `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:12px;margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input id="pj-q" type="text" aria-label="Search projects by name or goal" value="${_pjEsc(f.q)}" placeholder="Search by name or goal…"
        style="flex:1;min-width:200px;padding:8px 12px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px"
        oninput="_pjFilterDebounce()">
      <select id="pj-status" onchange="_pjFilterApply('status', this.value)"
        style="padding:8px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px">
        <option value="">All statuses</option>
        ${['proposed','active','paused','completed','cancelled','archived']
          .map(s => `<option value="${s}" ${f.status===s?'selected':''}>${_pjCap(s)}</option>`).join('')}
      </select>
      <select id="pj-source" onchange="_pjFilterApply('source_type', this.value)"
        style="padding:8px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px">
        <option value="">All sources</option>
        ${['direct','strategy_room','sarah_campaign','content_pack']
          .map(s => `<option value="${s}" ${f.source_type===s?'selected':''}>${_pjCap(s.replace('_',' '))}</option>`).join('')}
      </select>
      <select onchange="_pjFilterApply('sort', this.value)"
        style="padding:8px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px">
        <option value="created_at" ${f.sort==='created_at'?'selected':''}>Newest</option>
        <option value="planned_end_at" ${f.sort==='planned_end_at'?'selected':''}>Deadline</option>
        <option value="name" ${f.sort==='name'?'selected':''}>Name</option>
        <option value="status" ${f.sort==='status'?'selected':''}>Status</option>
      </select>
    </div>
  `;
}

function _pjListEmptyHtml() {
  return `
    <div style="background:var(--s1);border:1px dashed var(--bd);border-radius:var(--rg);padding:48px;text-align:center">
      <div style="font-size:14px;color:var(--t1);font-weight:600;margin-bottom:6px">No projects yet</div>
      <div style="font-size:12px;color:var(--t3);margin-bottom:16px">
        Projects come from Strategy Room meetings. Start a meeting and ratify it to create one.
      </div>
      <button class="btn btn-primary btn-sm" onclick="nav('meeting')">Start a Meeting</button>
    </div>
  `;
}

function _pjListCardsHtml(rows) {
  return `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px">
    ${rows.map(p => _pjCardHtml(p)).join('')}
  </div>`;
}

function _pjCardHtml(p) {
  const counts = p.counts || {};
  const outcome = p.outcome || null;
  const statusColor = _pjStatusColor(p.status);
  const sourceLabel = _pjSourceLabel(p.source_type);

  return `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:16px;cursor:pointer;transition:border-color .15s"
         onclick="_pjOpen(${p.id})"
         onmouseover="this.style.borderColor='var(--am)'"
         onmouseout="this.style.borderColor='var(--bd)'">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px">
        <div style="font-size:14px;font-weight:600;color:var(--t1);line-height:1.3">
          ${_pjEsc(p.name)}
        </div>
        <span style="font-size:10px;padding:2px 8px;border-radius:10px;background:${statusColor.bg};color:${statusColor.fg};font-weight:600;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap">
          ${p.status}
        </span>
      </div>
      <div style="font-size:12px;color:var(--t3);margin-bottom:12px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
        ${_pjEsc(p.goal || '(no goal set)')}
      </div>
      <div style="display:flex;gap:12px;font-size:11px;color:var(--t3);border-top:1px solid var(--bd);padding-top:8px">
        <span title="Milestones">◇ ${counts.milestones || 0}</span>
        <span title="KPIs">⌖ ${counts.kpis || 0}</span>
        <span title="Tasks">▤ ${counts.plan_tasks || 0}</span>
        <span title="Events">◷ ${counts.events || 0}</span>
        <span style="margin-left:auto;color:var(--t3)">${sourceLabel}</span>
      </div>
      ${outcome ? `
        <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;font-size:11px">
          <span style="color:var(--t2)">Outcome: <strong style="color:var(--t1)">${outcome.outcome}</strong></span>
          ${outcome.composite_score !== null ? `<span style="color:var(--t1);font-weight:600">${outcome.composite_score}%</span>` : ''}
        </div>` : ''}
    </div>
  `;
}

function _pjPagingHtml(paging) {
  const p = paging.page || 1;
  const last = paging.last_page || 1;
  if (last <= 1) return '';
  return `
    <div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:24px;font-size:13px;color:var(--t2)">
      <button class="btn btn-outline btn-sm" ${p<=1?'disabled':''} onclick="_pjFilterApply('page', ${p-1})">← Prev</button>
      <span>Page ${p} of ${last}</span>
      <button class="btn btn-outline btn-sm" ${p>=last?'disabled':''} onclick="_pjFilterApply('page', ${p+1})">Next →</button>
    </div>
  `;
}

// Filter actions
window._pjFilterApply = function(key, val) {
  _pj.filters[key] = val;
  if (key !== 'page') _pj.filters.page = 1; // reset paging on filter change
  projectsLoad(document.getElementById('projects-root'));
};
var _pjQDebounce = null;
window._pjFilterDebounce = function() {
  clearTimeout(_pjQDebounce);
  _pjQDebounce = setTimeout(function() {
    _pj.filters.q = (document.getElementById('pj-q') || {}).value || '';
    _pj.filters.page = 1;
    projectsLoad(document.getElementById('projects-root'));
  }, 300);
};

// ═══════════════════════════════════════════════════════════════════
// SINGLE VIEW (8 tabs)
// ═══════════════════════════════════════════════════════════════════
window._pjOpen = function(id) {
  _pj.activeId = id;
  _pj.mode = 'single';
  _pj.activeTab = 'overview';
  _pj.tabCache = {};
  // soft URL update for back-button friendliness
  try { history.replaceState({}, '', '#project=' + id); } catch(e) {}
  _pjRenderSingle(document.getElementById('projects-root'), id);
};

window._pjBack = function() {
  _pj.mode = 'list';
  _pj.activeId = null;
  try { history.replaceState({}, '', location.pathname); } catch(e) {}
  projectsLoad(document.getElementById('projects-root'));
};

window._pjTab = function(tab) {
  _pj.activeTab = tab;
  _pjRenderSingle(document.getElementById('projects-root'), _pj.activeId, /*useCache*/ true);
};

async function _pjRenderSingle(el, id, useCache) {
  if (!el) return;
  if (!useCache) {
    el.innerHTML = (typeof loadingCard === 'function' ? loadingCard(300)
      : '<div style="padding:60px;text-align:center;color:var(--t2)">Loading project…</div>');
  }
  try {
    if (!useCache) {
      const resp = await _pjApi('/projects/' + id);
      _pj.single = resp;
    }
    const data = (_pj.single && _pj.single.data) || null;
    if (!data) {
      el.innerHTML = _pjErrorHtml('Project not found', new Error('No data'), '_pjBack()');
      return;
    }
    // Render frame
    el.innerHTML = `
      <div style="max-width:1200px;margin:0 auto;padding:24px">
        ${_pjSingleHeaderHtml(data)}
        ${_pjTabsBarHtml(data)}
        <div id="pj-tab-body" style="margin-top:16px">${_pjTabLoadingHtml()}</div>
      </div>
    `;
    await _pjRenderActiveTab(data);
  } catch (e) {
    console.error('[Projects single]', e);
    el.innerHTML = _pjErrorHtml('Failed to load project', e, "projectsLoad(document.getElementById('projects-root'))");
  }
}

function _pjSingleHeaderHtml(d) {
  const status = _pjStatusColor(d.status);
  const composite = d.scores && d.scores.composite !== null ? d.scores.composite + '%' : '—';
  return `
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;gap:16px;flex-wrap:wrap">
      <div style="flex:1;min-width:300px">
        <div style="font-size:12px;color:var(--t3);margin-bottom:4px">
          <a href="#" onclick="_pjBack();return false" style="color:var(--t3);text-decoration:none">← Projects</a>
          <span style="margin:0 6px">·</span>
          <span>${_pjSourceLabel(d.source_type)}</span>
        </div>
        <h1 style="font-size:22px;font-weight:700;margin:0 0 6px;color:var(--t1)">${_pjEsc(d.name)}</h1>
        <div style="font-size:13px;color:var(--t2);line-height:1.4">${_pjEsc(d.goal || '(no goal set)')}</div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:10px;padding:3px 10px;border-radius:10px;background:${status.bg};color:${status.fg};font-weight:600;text-transform:uppercase;letter-spacing:.5px">${d.status}</span>
        <div style="text-align:right">
          <div style="font-size:22px;font-weight:700;color:var(--t1);line-height:1">${composite}</div>
          <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px">Composite</div>
        </div>
      </div>
    </div>
  `;
}

function _pjTabsBarHtml(d) {
  const tabs = [
    { key:'overview',  label:'Overview'  },
    { key:'milestones',label:'Milestones', n:(d.counts && d.counts.milestones) || 0 },
    { key:'kpis',      label:'KPIs',       n:(d.counts && d.counts.kpis) || 0 },
    { key:'tasks',     label:'Tasks',      n:(d.counts && d.counts.plan_tasks) || 0 },
    { key:'calendar',  label:'Calendar'  },
    { key:'publish',   label:'Publish Queue' },
    { key:'discussion',label:'Discussion' },
    { key:'outcomes',  label:'Outcomes'  },
  ];
  return `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:4px;display:flex;gap:2px;overflow-x:auto;flex-wrap:nowrap">
      ${tabs.map(t => {
        const active = _pj.activeTab === t.key;
        return `<button onclick="_pjTab('${t.key}')"
          style="padding:8px 12px;background:${active?'var(--am)':'transparent'};color:${active?'#fff':'var(--t2)'};border:none;border-radius:6px;font-size:12px;font-weight:${active?'600':'500'};cursor:pointer;white-space:nowrap;transition:background .12s">
          ${t.label}${typeof t.n === 'number' ? ` <span style="opacity:.7;font-weight:400">${t.n}</span>` : ''}
        </button>`;
      }).join('')}
    </div>
  `;
}

function _pjTabLoadingHtml() {
  return '<div style="padding:40px;text-align:center;color:var(--t3);font-size:13px">Loading…</div>';
}

async function _pjRenderActiveTab(d) {
  const body = document.getElementById('pj-tab-body');
  if (!body) return;
  const tab = _pj.activeTab;
  try {
    let html = '';
    if (tab === 'overview')   html = _pjOverviewHtml(d);
    else if (tab === 'milestones') html = await _pjMilestonesTab(d);
    else if (tab === 'kpis')  html = await _pjKpisTab(d);
    else if (tab === 'tasks') html = await _pjTasksTab(d);
    else if (tab === 'calendar') html = await _pjCalendarTab(d);
    else if (tab === 'publish')  html = await _pjPublishTab(d);
    else if (tab === 'discussion') html = _pjDiscussionTab(d);
    else if (tab === 'outcomes') html = _pjOutcomesTab(d);
    body.innerHTML = html;
  } catch (e) {
    console.error('[Projects tab]', e);
    body.innerHTML = `<div style="padding:24px;text-align:center;color:var(--rd);font-size:13px">Failed to load: ${_pjEsc(e.message || String(e))}</div>`;
  }
}

// ── Overview tab ───────────────────────────────────────────────────
function _pjOverviewHtml(d) {
  const sc = d.scores || {};
  const ms = sc.milestone || {};
  const kp = sc.kpi || {};
  const outcome = d.outcome || null;
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px">
      ${_pjScorePanelHtml('Milestone Score', ms.score, ms.achieved !== undefined ? `${ms.achieved}/${ms.total} achieved` : '')}
      ${_pjScorePanelHtml('KPI Score', kp.score, kp.count !== undefined ? `${kp.count} tracked` : '')}
      ${_pjScorePanelHtml('Composite', sc.composite, 'milestone & kpi blended')}
    </div>
    ${outcome && outcome.narrative ? `
      <div style="background:var(--s1);border:1px solid var(--bd);border-left:3px solid var(--am);border-radius:var(--rg);padding:16px;margin-bottom:16px">
        <div style="font-size:11px;color:var(--am);text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:6px">Sarah's Closing Note</div>
        <div style="font-size:13px;color:var(--t1);line-height:1.5">${_pjEsc(outcome.narrative)}</div>
      </div>` : ''}
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:16px">
      <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:8px">Project Details</div>
      ${_pjMetaRow('Description', d.description || '(none)')}
      ${_pjMetaRow('Source', _pjSourceLabel(d.source_type))}
      ${d.source_meeting_id ? _pjMetaRow('Source meeting', `#${d.source_meeting_id}`) : ''}
      ${_pjMetaRow('Planned start', d.planned_start_at ? _pjFmtDate(d.planned_start_at) : 'not set')}
      ${_pjMetaRow('Planned end',   d.planned_end_at   ? _pjFmtDate(d.planned_end_at)   : 'not set')}
      ${d.budget_credits ? _pjMetaRow('Budget', `${d.budget_spent || 0} / ${d.budget_credits} credits`) : ''}
      ${_pjMetaRow('Created', _pjFmtDate(d.created_at))}
    </div>
  `;
}

function _pjScorePanelHtml(label, value, subtitle) {
  const v = (value === null || value === undefined) ? '—' : (value + '%');
  return `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:16px">
      <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:6px">${label}</div>
      <div style="font-size:28px;font-weight:700;color:var(--t1);line-height:1">${v}</div>
      <div style="font-size:11px;color:var(--t3);margin-top:4px">${_pjEsc(subtitle || '')}</div>
    </div>
  `;
}

function _pjMetaRow(label, val) {
  return `<div style="display:flex;gap:12px;padding:6px 0;border-bottom:1px solid var(--bd);font-size:13px">
    <span style="color:var(--t3);min-width:120px">${_pjEsc(label)}</span>
    <span style="color:var(--t1);flex:1">${_pjEsc(val)}</span>
  </div>`;
}

// ── Milestones tab ─────────────────────────────────────────────────
async function _pjMilestonesTab(d) {
  if (!_pj.tabCache.milestones) {
    _pj.tabCache.milestones = await _pjApi('/projects/' + d.id + '/milestones');
  }
  const data = _pj.tabCache.milestones;
  const rows = data.data || [];
  const addBtn = `<button class="btn btn-primary btn-sm" onclick="_pjMilestoneCreateForm()" style="margin-bottom:12px">+ Add Milestone</button>`;
  if (rows.length === 0) {
    return addBtn + _pjEmptyTabHtml('No milestones yet', 'Add one manually, or ratify a Strategy Room meeting to get Sarah\'s proposed set.');
  }
  return `
    ${addBtn}
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);overflow:hidden">
      ${rows.map(m => _pjMilestoneRowHtml(m)).join('')}
    </div>
    <div id="pj-inline-form" style="margin-top:12px"></div>
  `;
}

function _pjMilestoneRowHtml(m) {
  const statusColor = _pjMilestoneColor(m.status);
  return `
    <div style="padding:14px 16px;border-bottom:1px solid var(--bd);display:flex;gap:12px;align-items:flex-start">
      <div style="margin-top:2px;width:8px;height:8px;border-radius:50%;background:${statusColor.dot}"></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:2px">${_pjEsc(m.title)}</div>
        ${m.description ? `<div style="font-size:12px;color:var(--t2);margin-bottom:4px">${_pjEsc(m.description)}</div>` : ''}
        <div style="font-size:11px;color:var(--t3)">
          ${m.target_date ? `Target: ${_pjFmtDate(m.target_date)}` : 'No date set'}
          ${m.completed_at ? ` · Closed: ${_pjFmtDate(m.completed_at)}` : ''}
        </div>
        ${m.success_criteria && m.success_criteria.description ? `<div style="font-size:11px;color:var(--t3);margin-top:4px">Criteria: ${_pjEsc(m.success_criteria.description)}</div>` : ''}
      </div>
      <span style="font-size:10px;padding:2px 8px;border-radius:10px;background:${statusColor.bg};color:${statusColor.fg};font-weight:600;text-transform:uppercase;letter-spacing:.5px">${m.status}</span>
      ${m.status === 'pending' ? `
        <button class="btn btn-outline btn-sm" onclick="_pjMilestoneAchieve(${m.id})">Achieve</button>` : ''}
    </div>
  `;
}

// ── KPIs tab ───────────────────────────────────────────────────────
async function _pjKpisTab(d) {
  if (!_pj.tabCache.kpis) {
    _pj.tabCache.kpis = await _pjApi('/projects/' + d.id + '/kpis');
  }
  const data = _pj.tabCache.kpis;
  const rows = data.data || [];
  const addBtn = `<button class="btn btn-primary btn-sm" onclick="_pjKpiCreateForm()" style="margin-bottom:12px">+ Add KPI</button>`;
  if (rows.length === 0) {
    return addBtn + _pjEmptyTabHtml('No KPIs yet', 'Add one manually, or ratify a Strategy Room meeting to get Sarah\'s proposed set.');
  }
  return `
    ${addBtn}
    <div style="display:grid;gap:12px">
      ${rows.map(k => _pjKpiCardHtml(k)).join('')}
    </div>
    <div id="pj-inline-form" style="margin-top:12px"></div>
  `;
}

function _pjKpiCardHtml(k) {
  const tgt = parseFloat(k.target_value || 0);
  const cur = parseFloat(k.current_value || 0);
  let pct = 0;
  if (k.direction === 'higher_is_better') {
    pct = tgt > 0 ? Math.min(100, (cur / tgt) * 100) : 0;
  } else {
    pct = cur > 0 ? Math.min(100, (tgt / cur) * 100) : 100;
  }
  const dirLabel = k.direction === 'higher_is_better' ? '↑' : '↓';
  return `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:16px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px">
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:600;color:var(--t1)">${_pjEsc(k.name)} <span style="color:var(--t3);font-size:11px;margin-left:6px">${dirLabel}</span></div>
          ${k.description ? `<div style="font-size:12px;color:var(--t2);margin-top:2px">${_pjEsc(k.description)}</div>` : ''}
          ${k.metadata && k.metadata.rationale ? `<div style="font-size:11px;color:var(--t3);font-style:italic;margin-top:4px">Sarah: ${_pjEsc(k.metadata.rationale)}</div>` : ''}
        </div>
        <button class="btn btn-outline btn-sm" onclick="_pjKpiMeasure(${k.id}, ${k.target_value})">Measure</button>
      </div>
      <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:6px">
        <span style="font-size:20px;font-weight:700;color:var(--t1)">${cur}</span>
        <span style="font-size:12px;color:var(--t3)">/ ${tgt} ${_pjEsc(k.unit || '')}</span>
        <span style="margin-left:auto;font-size:13px;font-weight:600;color:var(--am)">${Math.round(pct)}%</span>
      </div>
      <div style="height:6px;background:var(--bd);border-radius:3px;overflow:hidden">
        <div style="height:100%;width:${pct}%;background:var(--am);transition:width .3s"></div>
      </div>
      ${k.last_measured_at ? `<div style="font-size:10px;color:var(--t3);margin-top:6px">Last measured: ${_pjFmtDate(k.last_measured_at)}</div>` : ''}
    </div>
  `;
}

// ── Tasks tab ──────────────────────────────────────────────────────
async function _pjTasksTab(d) {
  // execution_plans linked to project — read from timeline filter
  if (!_pj.tabCache.timeline) {
    _pj.tabCache.timeline = await _pjApi('/projects/' + d.id + '/timeline');
  }
  const events = (_pj.tabCache.timeline.data || []).filter(e => e.kind === 'execution_plan');
  if (events.length === 0) {
    return _pjEmptyTabHtml('No tasks yet', 'When agents run work tied to this project, you\'ll see plans here.');
  }
  return `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);overflow:hidden">
      ${events.map(e => `
        <div style="padding:12px 16px;border-bottom:1px solid var(--bd);display:flex;gap:12px;align-items:center">
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--t1)">${_pjEsc(e.title || '(untitled plan)')}</div>
            <div style="font-size:11px;color:var(--t3);margin-top:2px">${_pjFmtDate(e.at)} · ${_pjEsc(e.reference)}</div>
          </div>
          <span style="font-size:10px;padding:2px 8px;border-radius:10px;background:var(--bd);color:var(--t2);font-weight:600;text-transform:uppercase;letter-spacing:.5px">${e.status}</span>
        </div>
      `).join('')}
    </div>
  `;
}

// ── Calendar tab ───────────────────────────────────────────────────
async function _pjCalendarTab(d) {
  if (!_pj.tabCache.timeline) {
    _pj.tabCache.timeline = await _pjApi('/projects/' + d.id + '/timeline');
  }
  const events = _pj.tabCache.timeline.data || [];
  if (events.length === 0) {
    return _pjEmptyTabHtml('Timeline empty', 'Events appear here as the project moves forward.');
  }
  return `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);overflow:hidden">
      ${events.map(e => `
        <div style="padding:12px 16px;border-bottom:1px solid var(--bd);display:flex;gap:12px;align-items:flex-start">
          <div style="width:110px;flex-shrink:0;font-size:11px;color:var(--t3);text-align:right;padding-top:2px">${_pjFmtDate(e.at)}</div>
          <div style="width:2px;background:var(--bd);align-self:stretch;flex-shrink:0"></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;color:var(--t1);font-weight:500">${_pjEsc(e.title || '(no title)')}</div>
            <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px">${e.kind} · ${e.status || ''}</div>
            ${e.description ? `<div style="font-size:12px;color:var(--t2);margin-top:4px">${_pjEsc(e.description)}</div>` : ''}
          </div>
        </div>
      `).join('')}
    </div>
  `;
}

// ── Publish Queue tab ──────────────────────────────────────────────
async function _pjPublishTab(d) {
  if (!_pj.tabCache.publish) {
    _pj.tabCache.publish = await _pjApi('/projects/' + d.id + '/publish-queue');
  }
  const rows = _pj.tabCache.publish.data || [];
  if (rows.length === 0) {
    return _pjEmptyTabHtml('Nothing in the queue', 'Content packs linked to this project will appear here.');
  }
  return `
    <div style="display:grid;gap:8px">
      ${rows.map(p => `
        <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:12px 16px;display:flex;align-items:center;gap:12px">
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--t1)">${_pjEsc(p.name)}</div>
            ${p.theme ? `<div style="font-size:11px;color:var(--t3);margin-top:2px">Theme: ${_pjEsc(p.theme)}</div>` : ''}
          </div>
          <span style="font-size:10px;padding:2px 8px;border-radius:10px;background:var(--bd);color:var(--t2);font-weight:600;text-transform:uppercase">${p.status}</span>
        </div>
      `).join('')}
    </div>
  `;
}

// ── Discussion tab ─────────────────────────────────────────────────
function _pjDiscussionTab(d) {
  if (d.source_type === 'strategy_room' && d.source_meeting_id) {
    return `
      <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:24px;text-align:center">
        <div style="font-size:13px;color:var(--t1);font-weight:600;margin-bottom:6px">From Strategy Room meeting #${d.source_meeting_id}</div>
        <div style="font-size:12px;color:var(--t3);margin-bottom:16px">Continue the conversation that led to this project.</div>
        <button class="btn btn-primary btn-sm" onclick="nav('meeting')">Open Meeting</button>
      </div>
    `;
  }
  return _pjEmptyTabHtml('No discussion linked', 'This project was created directly, not from a Strategy Room meeting.');
}

// ── Outcomes tab ───────────────────────────────────────────────────
function _pjOutcomesTab(d) {
  const o = d.outcome;
  if (!o) {
    return `
      <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:24px">
        <div style="font-size:13px;color:var(--t1);font-weight:600;margin-bottom:6px">Project not yet closed</div>
        <div style="font-size:12px;color:var(--t3);margin-bottom:16px">When you're done, dispose the project so Sarah can learn from the outcome.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-primary btn-sm" onclick="_pjDispose('succeeded')">Mark Succeeded</button>
          <button class="btn btn-outline btn-sm" onclick="_pjDispose('partially_succeeded')">Partially Succeeded</button>
          <button class="btn btn-outline btn-sm" onclick="_pjDispose('failed')">Mark Failed</button>
          <button class="btn btn-outline btn-sm" onclick="_pjDispose('abandoned')">Abandon</button>
        </div>
      </div>
    `;
  }
  return `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:16px;margin-bottom:12px">
      <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:6px">Verdict</div>
      <div style="font-size:18px;font-weight:700;color:var(--t1);text-transform:capitalize">${o.outcome.replace(/_/g, ' ')}</div>
      <div style="font-size:12px;color:var(--t3);margin-top:2px">Disposed ${_pjFmtDate(o.disposed_at)}</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:12px">
      ${_pjScorePanelHtml('Composite', o.composite_score, '')}
      ${_pjScorePanelHtml('Milestones', o.milestone_score, '')}
      ${_pjScorePanelHtml('KPIs', o.kpi_score, '')}
    </div>
    ${o.narrative ? `
      <div style="background:var(--s1);border:1px solid var(--bd);border-left:3px solid var(--am);border-radius:var(--rg);padding:16px">
        <div style="font-size:11px;color:var(--am);text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:6px">Sarah's Closing Note</div>
        <div style="font-size:13px;color:var(--t1);line-height:1.5">${_pjEsc(o.narrative)}</div>
      </div>` : ''}
  `;
}

window._pjDispose = async function(outcome) {
  if (!_pj.activeId) return;
  const labels = {succeeded:'Succeeded', partially_succeeded:'Partial', failed:'Failed', abandoned:'Abandoned'};
  const ok = await luConfirm('Mark this project as ' + (labels[outcome] || outcome) + '?', 'Sarah will write a closing note and record the outcome for future learning.', { okLabel: 'Mark as ' + (labels[outcome] || outcome), danger: outcome === 'failed' || outcome === 'abandoned' });
  if (!ok) return;
  try {
    const resp = await _pjApi('/projects/' + _pj.activeId + '/dispose', {
      method: 'POST',
      body: { outcome },
    });
    if (resp && resp.success) {
      // Bust cache and reload
      _pj.tabCache = {};
      _pj.single = null;
      _pjRenderSingle(document.getElementById('projects-root'), _pj.activeId);
    } else {
      showToast((resp && (resp.error || resp.message)) || 'Failed to dispose project', 'error');
    }
  } catch (e) {
    showToast('Error: ' + e.message, 'error');
  }
};

// ── Mutations ─────────────────────────────────────────────────────
async function _pjPostRefresh(path, body, scope) {
  // POST helper that, on success, busts the relevant tab cache + re-renders
  try {
    const resp = await _pjApi(path, { method: 'POST', body });
    if (resp && resp.success) {
      if (scope === 'milestones') delete _pj.tabCache.milestones;
      if (scope === 'kpis')       delete _pj.tabCache.kpis;
      if (scope === 'single')     _pj.single = null;
      // Outer project counts also change → reload single view shell
      _pj.single = null;
      _pjRenderSingle(document.getElementById('projects-root'), _pj.activeId);
      return resp;
    }
    showToast((resp && (resp.error || resp.message)) || 'Request failed — please try again', 'error');
    return null;
  } catch (e) {
    showToast('Error: ' + e.message, 'error');
    return null;
  }
}

window._pjMilestoneAchieve = async function(mid) {
  const notes = await luPrompt('Milestone achieved', '', { placeholder: 'What evidence supports this? (optional)', okLabel: 'Mark achieved' });
  if (notes === null) return; // user cancelled
  await _pjPostRefresh(
    '/projects/' + _pj.activeId + '/milestones/' + mid + '/achieve',
    { evidence: { notes: notes || '' } },
    'milestones'
  );
};

window._pjMilestoneCreateForm = function() {
  const host = document.getElementById('pj-inline-form');
  if (!host) return;
  host.innerHTML = `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:16px">
      <div style="font-size:12px;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:10px">New Milestone</div>
      <input id="pjnm-title" type="text" placeholder="Title (required)"
        style="width:100%;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:8px">
      <textarea id="pjnm-desc" placeholder="Description (optional)" rows="2"
        style="width:100%;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:8px;resize:vertical"></textarea>
      <input id="pjnm-target" type="date"
        style="padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:8px">
      <input id="pjnm-crit" type="text" placeholder="Success criteria (optional)"
        style="width:100%;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:10px">
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('pj-inline-form').innerHTML=''">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="_pjMilestoneCreate()">Create</button>
      </div>
    </div>
  `;
  setTimeout(() => { const t = document.getElementById('pjnm-title'); if (t) t.focus(); }, 50);
};

window._pjMilestoneCreate = async function() {
  const title = (document.getElementById('pjnm-title') || {}).value || '';
  if (!title.trim()) { luAlert('Check the form', 'Title is required.'); return; }
  const desc   = (document.getElementById('pjnm-desc') || {}).value || '';
  const target = (document.getElementById('pjnm-target') || {}).value || '';
  const crit   = (document.getElementById('pjnm-crit') || {}).value || '';
  const body = { title: title.trim() };
  if (desc.trim())   body.description = desc.trim();
  if (target)        body.target_date = target + ' 23:59:59';
  if (crit.trim())   body.success_criteria = { type: 'manual', description: crit.trim() };
  await _pjPostRefresh('/projects/' + _pj.activeId + '/milestones', body, 'milestones');
};

window._pjKpiMeasure = async function(kid, target) {
  const raw = await luPrompt('Record a new measurement', '', { placeholder: 'Value (target: ' + target + ')', okLabel: 'Record' });
  if (raw === null) return;
  const v = parseFloat(raw);
  if (isNaN(v)) { luAlert('Check the form', 'Value must be numeric.'); return; }
  await _pjPostRefresh(
    '/projects/' + _pj.activeId + '/kpis/' + kid + '/measure',
    { value: v },
    'kpis'
  );
};

window._pjKpiCreateForm = function() {
  const host = document.getElementById('pj-inline-form');
  if (!host) return;
  host.innerHTML = `
    <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:16px">
      <div style="font-size:12px;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:10px">New KPI</div>
      <input id="pjnk-name" type="text" placeholder="Name (required) — e.g. Monthly leads"
        style="width:100%;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:8px">
      <textarea id="pjnk-desc" placeholder="Description (optional)" rows="2"
        style="width:100%;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:8px;resize:vertical"></textarea>
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <input id="pjnk-target" type="number" step="any" placeholder="Target value (required)"
          style="flex:1;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px">
        <input id="pjnk-unit" type="text" placeholder="Unit — leads, %, $…"
          style="flex:1;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px">
      </div>
      <select id="pjnk-dir" style="width:100%;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:10px">
        <option value="higher_is_better">↑ Higher is better</option>
        <option value="lower_is_better">↓ Lower is better</option>
      </select>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('pj-inline-form').innerHTML=''">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="_pjKpiCreate()">Create</button>
      </div>
    </div>
  `;
  setTimeout(() => { const t = document.getElementById('pjnk-name'); if (t) t.focus(); }, 50);
};

window._pjKpiCreate = async function() {
  const name = (document.getElementById('pjnk-name') || {}).value || '';
  if (!name.trim()) { luAlert('Check the form', 'Name is required.'); return; }
  const target = parseFloat((document.getElementById('pjnk-target') || {}).value);
  if (isNaN(target)) { luAlert('Check the form', 'Target value must be numeric.'); return; }
  const body = {
    name: name.trim(),
    target_value: target,
    direction: (document.getElementById('pjnk-dir') || {}).value || 'higher_is_better',
  };
  const desc = (document.getElementById('pjnk-desc') || {}).value || '';
  const unit = (document.getElementById('pjnk-unit') || {}).value || '';
  if (desc.trim()) body.description = desc.trim();
  if (unit.trim()) body.unit = unit.trim();
  await _pjPostRefresh('/projects/' + _pj.activeId + '/kpis', body, 'kpis');
};

// ── New Project (list-view top button) ────────────────────────────
window._pjNewProject = function() {
  const root = document.getElementById('projects-root');
  if (!root) return;
  root.innerHTML = `
    <div style="max-width:560px;margin:48px auto;padding:0 24px">
      <div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:20px">
        <div style="font-size:11px;color:var(--t3);margin-bottom:6px"><a href="#" onclick="projectsLoad(document.getElementById('projects-root'));return false" style="color:var(--t3);text-decoration:none">← Cancel</a></div>
        <h2 style="font-size:18px;font-weight:700;color:var(--t1);margin:0 0 16px">New project</h2>
        <input id="pjnp-name" type="text" placeholder="Project name (required)"
          style="width:100%;padding:10px 12px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:14px;margin-bottom:10px">
        <textarea id="pjnp-goal" placeholder="Goal — what does success look like? (required)" rows="3"
          style="width:100%;padding:10px 12px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:10px;resize:vertical"></textarea>
        <textarea id="pjnp-desc" placeholder="Description (optional)" rows="2"
          style="width:100%;padding:10px 12px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px;margin-bottom:10px;resize:vertical"></textarea>
        <div style="display:flex;gap:8px;margin-bottom:14px">
          <div style="flex:1">
            <div style="font-size:11px;color:var(--t3);margin-bottom:4px">Planned end</div>
            <input id="pjnp-end" type="date"
              style="width:100%;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px">
          </div>
          <div style="flex:1">
            <div style="font-size:11px;color:var(--t3);margin-bottom:4px">Budget (credits)</div>
            <input id="pjnp-budget" type="number" min="0" step="1" placeholder="Optional"
              style="width:100%;padding:8px 10px;background:var(--s1);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-size:13px">
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button class="btn btn-outline btn-sm" onclick="projectsLoad(document.getElementById('projects-root'))">Cancel</button>
          <button class="btn btn-primary btn-sm" onclick="_pjProjectCreate()">Create Project</button>
        </div>
      </div>
    </div>
  `;
  setTimeout(() => { const t = document.getElementById('pjnp-name'); if (t) t.focus(); }, 50);
};

window._pjProjectCreate = async function() {
  const name = (document.getElementById('pjnp-name') || {}).value || '';
  const goal = (document.getElementById('pjnp-goal') || {}).value || '';
  if (!name.trim()) { luAlert('Check the form', 'Project name is required.'); return; }
  if (!goal.trim()) { luAlert('Check the form', 'Goal is required.'); return; }
  const body = { name: name.trim(), goal: goal.trim(), source_type: 'direct' };
  const desc = (document.getElementById('pjnp-desc') || {}).value || '';
  if (desc.trim()) body.description = desc.trim();
  const end = (document.getElementById('pjnp-end') || {}).value || '';
  if (end) body.planned_end_at = end + ' 23:59:59';
  const budget = parseInt((document.getElementById('pjnp-budget') || {}).value || '', 10);
  if (!isNaN(budget) && budget > 0) body.budget_credits = budget;

  try {
    const resp = await _pjApi('/projects', { method: 'POST', body });
    if (resp && resp.success && resp.project_id) {
      _pjOpen(resp.project_id);
    } else {
      showToast((resp && (resp.error || resp.message)) || 'Failed to create project', 'error');
    }
  } catch (e) {
    showToast('Error: ' + e.message, 'error');
  }
};

// ═══════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════
function _pjEmptyTabHtml(headline, sub) {
  return `<div style="background:var(--s1);border:1px dashed var(--bd);border-radius:var(--rg);padding:36px;text-align:center">
    <div style="font-size:14px;color:var(--t1);font-weight:600;margin-bottom:6px">${_pjEsc(headline)}</div>
    <div style="font-size:12px;color:var(--t3);max-width:380px;margin:0 auto">${_pjEsc(sub)}</div>
  </div>`;
}

function _pjErrorHtml(headline, e, retryExpr) {
  const msg = (typeof friendlyError === 'function') ? friendlyError(e) : (e.message || 'Unknown error');
  return `<div style="padding:60px;text-align:center;color:var(--t2)">
    <div style="font-size:14px;font-weight:600;margin-bottom:6px">${_pjEsc(headline)}</div>
    <div style="font-size:12px;color:var(--t3)">${_pjEsc(msg)}</div>
    ${retryExpr ? `<button class="btn btn-outline btn-sm" style="margin-top:16px" onclick="${retryExpr}">↺ Retry</button>` : ''}
  </div>`;
}

function _pjStatusColor(status) {
  // role-locked palette mirrors the rest of the app
  const map = {
    proposed:  {bg:'rgba(148,163,184,.15)', fg:'#64748b', dot:'#94a3b8'},
    active:    {bg:'rgba(34,197,94,.15)',   fg:'#16a34a', dot:'#22c55e'},
    paused:    {bg:'rgba(245,158,11,.15)',  fg:'#d97706', dot:'#f59e0b'},
    completed: {bg:'rgba(59,130,246,.15)',  fg:'#2563eb', dot:'#3b82f6'},
    cancelled: {bg:'rgba(239,68,68,.15)',   fg:'#dc2626', dot:'#ef4444'},
    archived:  {bg:'rgba(148,163,184,.1)',  fg:'#94a3b8', dot:'#94a3b8'},
  };
  return map[status] || map.proposed;
}

function _pjMilestoneColor(status) {
  const map = {
    pending:   {bg:'rgba(148,163,184,.15)', fg:'#64748b', dot:'#94a3b8'},
    achieved:  {bg:'rgba(34,197,94,.15)',   fg:'#16a34a', dot:'#22c55e'},
    missed:    {bg:'rgba(239,68,68,.15)',   fg:'#dc2626', dot:'#ef4444'},
    cancelled: {bg:'rgba(148,163,184,.1)',  fg:'#94a3b8', dot:'#94a3b8'},
  };
  return map[status] || map.pending;
}

function _pjSourceLabel(s) {
  const map = {
    direct: 'Direct',
    strategy_room: 'Strategy Room',
    sarah_campaign: 'Sarah Campaign',
    content_pack: 'Content Pack',
  };
  return map[s] || s || '—';
}

function _pjCap(s) { return (s || '').charAt(0).toUpperCase() + (s || '').slice(1); }

function _pjEsc(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, function(c) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
  });
}

function _pjFmtDate(s) {
  if (!s) return '';
  try {
    const d = new Date(s);
    if (isNaN(d.getTime())) return s;
    return d.toLocaleString(undefined, {year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
  } catch(e) { return s; }
}
