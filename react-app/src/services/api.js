const BASE = '/api';

let _token = localStorage.getItem('lu_token');
let _refresh = localStorage.getItem('lu_refresh');
let _onUnauth = null;

export const setTokens = (a, r) => { _token = a; _refresh = r; localStorage.setItem('lu_token', a); if (r) localStorage.setItem('lu_refresh', r); };
export const getToken = () => _token;
export const clearAuth = () => { _token = null; _refresh = null; localStorage.removeItem('lu_token'); localStorage.removeItem('lu_refresh'); };
export const onUnauthorized = (fn) => { _onUnauth = fn; };

async function tryRefresh() {
  if (!_refresh) return false;
  try {
    const r = await fetch(`${BASE}/auth/refresh`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ refresh_token: _refresh }) });
    if (!r.ok) return false;
    const d = await r.json();
    if (d.access_token) { setTokens(d.access_token, d.refresh_token || _refresh); return true; }
  } catch (_) {}
  return false;
}

export async function api(method, path, body = null, opts = {}) {
  const h = { 'Content-Type': 'application/json' };
  if (_token) h['Authorization'] = `Bearer ${_token}`;
  const c = { method, headers: h, cache: 'no-store' };
  if (body) c.body = JSON.stringify(body);

  let res = await fetch(`${BASE}${path}`, c);

  if (res.status === 401 && !opts.noRetry) {
    const ok = await tryRefresh();
    if (ok) { h['Authorization'] = `Bearer ${_token}`; res = await fetch(`${BASE}${path}`, { ...c, headers: h }); }
    else { if (_onUnauth) _onUnauth(); throw new Error('Session expired'); }
  }

  const data = await res.json().catch(() => ({}));
  if (!res.ok && !opts.allowErrors) throw new Error(data.message || data.error || `HTTP ${res.status}`);
  return data;
}

// ── Auth ────────────────────────────────────────────
export const auth = {
  login: (email, password) => api('POST', '/auth/login', { email, password }, { allowErrors: true, noRetry: true }),
  register: (name, email, password) => api('POST', '/auth/register', { name, email, password }, { allowErrors: true, noRetry: true }),
  logout: () => api('POST', '/auth/logout', { refresh_token: _refresh }).catch(() => {}),
  me: () => api('GET', '/auth/me'),
};

// ── Workspace ───────────────────────────────────────
export const workspace = {
  status: () => api('GET', '/workspace/status'),
  credits: () => api('GET', '/workspace/credits'),
  billing: () => api('GET', '/billing/status'),
  plans: () => api('GET', '/billing/plans'),
  checkout: (planId) => api('POST', '/billing/checkout', { plan_id: planId }),
  cancel: () => api('POST', '/billing/cancel'),
  onboarding: (d) => api('POST', '/workspace/onboarding', d),
  completeOnboarding: () => api('POST', '/workspace/complete-onboarding'),
  insights: () => api('GET', '/insights/summary'),
};

// ── Tasks ───────────────────────────────────────────
export const tasks = {
  list: (p = '') => api('GET', `/tasks${p}`),
  get: (id) => api('GET', `/tasks/${id}`),
  status: (id) => api('GET', `/tasks/${id}/status`),
  events: (id) => api('GET', `/tasks/${id}/events`),
  cancel: (id) => api('POST', `/tasks/${id}/cancel`),
};

// ── Agents ──────────────────────────────────────────
export const agents = {
  list: () => api('GET', '/agents'),
  dispatch: (d) => api('POST', '/agent/dispatch', d),
  conversations: () => api('GET', '/agent/conversations'),
  strategyMeeting: (goal) => api('POST', '/agent/strategy-meeting', { goal }),
  plan: (goal) => api('POST', '/agent/plan', { goal }),
  executePlan: (plan) => api('POST', '/agent/execute-plan', { plan }),
};

// ── CRM (100% — 32 endpoints) ───────────────────────
export const crm = {
  // Leads
  leads: (p = '') => api('GET', `/crm/leads${p}`),
  createLead: (d) => api('POST', '/crm/leads', d),
  getLead: (id) => api('GET', `/crm/leads/${id}`),
  updateLead: (id, d) => api('PUT', `/crm/leads/${id}`, d),
  deleteLead: (id) => api('DELETE', `/crm/leads/${id}`),
  restoreLead: (id) => api('POST', `/crm/leads/${id}/restore`),
  scoreLead: (id, s) => api('POST', `/crm/leads/${id}/score`, s !== undefined ? { score: s } : {}),
  assignLead: (id, userId) => api('POST', `/crm/leads/${id}/assign`, { assigned_to: userId }),
  importLeads: (rows) => api('POST', '/crm/leads/import', { rows }),
  exportLeads: (filters) => api('GET', `/crm/leads/export${filters ? '?' + new URLSearchParams(filters) : ''}`),
  // Contacts
  contacts: (p = '') => api('GET', `/crm/contacts${p}`),
  createContact: (d) => api('POST', '/crm/contacts', d),
  getContact: (id) => api('GET', `/crm/contacts/${id}`),
  updateContact: (id, d) => api('PUT', `/crm/contacts/${id}`, d),
  deleteContact: (id) => api('DELETE', `/crm/contacts/${id}`),
  mergeContacts: (keepId, mergeId) => api('POST', '/crm/contacts/merge', { keep_id: keepId, merge_id: mergeId }),
  findDuplicates: () => api('GET', '/crm/contacts/duplicates'),
  // Deals
  deals: (p = '') => api('GET', `/crm/deals${p}`),
  createDeal: (d) => api('POST', '/crm/deals', d),
  getDeal: (id) => api('GET', `/crm/deals/${id}`),
  updateDeal: (id, d) => api('PUT', `/crm/deals/${id}`, d),
  updateDealStage: (id, s) => api('PUT', `/crm/deals/${id}/stage`, { stage: s }),
  // Pipeline
  pipeline: () => api('GET', '/crm/pipeline'),
  stages: () => api('GET', '/crm/stages'),
  createStage: (d) => api('POST', '/crm/stages', d),
  updateStage: (id, d) => api('PUT', `/crm/stages/${id}`, d),
  deleteStage: (id) => api('DELETE', `/crm/stages/${id}`),
  reorderStages: (ids) => api('POST', '/crm/stages/reorder', { stage_ids: ids }),
  // Activities
  activities: (type, id) => api('GET', `/crm/activities?entity_type=${type}&entity_id=${id}`),
  logActivity: (d) => api('POST', '/crm/activities', d),
  completeActivity: (id) => api('POST', `/crm/activities/${id}/complete`),
  today: () => api('GET', '/crm/today'),
  // Notes
  notes: (type, id) => api('GET', `/crm/notes?entity_type=${type}&entity_id=${id}`),
  addNote: (d) => api('POST', '/crm/notes', d),
  deleteNote: (id) => api('DELETE', `/crm/notes/${id}`),
  // Revenue & Reporting
  revenue: () => api('GET', '/crm/revenue'),
  reporting: () => api('GET', '/crm/reporting'),
};

// ── SEO ─────────────────────────────────────────────
export const seo = {
  serpAnalysis: (d) => api('POST', '/seo/serp-analysis', d),
  aiReport: (d) => api('POST', '/seo/ai-report', d),
  deepAudit: (d) => api('POST', '/seo/deep-audit', d),
  audits: () => api('GET', '/seo/audits'),
  keywords: () => api('GET', '/seo/keywords'),
  addKeyword: (d) => api('POST', '/seo/keywords', d),
  links: () => api('GET', '/seo/links'),
  insertLink: (id) => api('POST', `/seo/links/${id}/insert`),
  dismissLink: (id) => api('POST', `/seo/links/${id}/dismiss`),
  goals: () => api('GET', '/seo/goals'),
  createGoal: (d) => api('POST', '/seo/goals', d),
  pauseGoal: (id) => api('POST', `/seo/goals/${id}/pause`),
  agentStatus: () => api('GET', '/seo/agent-status'),
};

// ── Write ───────────────────────────────────────────
export const write = {
  articles: (p = '') => api('GET', `/write/articles${p}`),
  createArticle: (d) => api('POST', '/write/articles', d),
  getArticle: (id) => api('GET', `/write/articles/${id}`),
  updateArticle: (id, d) => api('PUT', `/write/articles/${id}`, d),
  versions: (id) => api('GET', `/write/articles/${id}/versions`),
};

// ── Creative ────────────────────────────────────────
export const creative = {
  assets: (p = '') => api('GET', `/creative/assets${p}`),
  createAsset: (d) => api('POST', '/creative/assets', d),
  getAsset: (id) => api('GET', `/creative/assets/${id}`),
  deleteAsset: (id) => api('DELETE', `/creative/assets/${id}`),
};

// ── Builder ─────────────────────────────────────────
export const builder = {
  websites: () => api('GET', '/builder/websites'),
  createWebsite: (d) => api('POST', '/builder/websites', d),
  getWebsite: (id) => api('GET', `/builder/websites/${id}`),
  publish: (id) => api('POST', `/builder/websites/${id}/publish`),
  pages: (wid) => api('GET', `/builder/websites/${wid}/pages`),
  createPage: (wid, d) => api('POST', `/builder/websites/${wid}/pages`, d),
  getPage: (id) => api('GET', `/builder/pages/${id}`),
  updatePage: (id, d) => api('PUT', `/builder/pages/${id}`, d),
  wizard: (d) => api('POST', '/builder/wizard', d),
};

// ── Marketing ───────────────────────────────────────
export const marketing = {
  campaigns: (p = '') => api('GET', `/marketing/campaigns${p}`),
  createCampaign: (d) => api('POST', '/marketing/campaigns', d),
  getCampaign: (id) => api('GET', `/marketing/campaigns/${id}`),
  updateCampaign: (id, d) => api('PUT', `/marketing/campaigns/${id}`, d),
  scheduleCampaign: (id, at) => api('POST', `/marketing/campaigns/${id}/schedule`, { scheduled_at: at }),
  templates: () => api('GET', '/marketing/templates'),
  createTemplate: (d) => api('POST', '/marketing/templates', d),
  automations: () => api('GET', '/marketing/automations'),
  createAutomation: (d) => api('POST', '/marketing/automations', d),
  toggleAutomation: (id, s) => api('POST', `/marketing/automations/${id}/toggle`, { status: s }),
};

// ── Social ──────────────────────────────────────────
export const social = {
  posts: (p = '') => api('GET', `/social/posts${p}`),
  createPost: (d) => api('POST', '/social/posts', d),
  schedulePost: (id, at) => api('POST', `/social/posts/${id}/schedule`, { scheduled_at: at }),
  publishPost: (id) => api('POST', `/social/posts/${id}/publish`),
  accounts: () => api('GET', '/social/accounts'),
  calendar: (from, to) => api('GET', `/social/calendar?from=${from}&to=${to}`),
};

// ── Calendar ────────────────────────────────────────
export const calendar = {
  events: (from, to, cat) => api('GET', `/calendar/events?from=${from}&to=${to}${cat ? '&category=' + cat : ''}`),
  createEvent: (d) => api('POST', '/calendar/events', d),
  updateEvent: (id, d) => api('PUT', `/calendar/events/${id}`, d),
  deleteEvent: (id) => api('DELETE', `/calendar/events/${id}`),
};

// ── BeforeAfter ─────────────────────────────────────
export const beforeafter = {
  designs: () => api('GET', '/beforeafter/designs'),
  createDesign: (d) => api('POST', '/beforeafter/designs', d),
  getDesign: (id) => api('GET', `/beforeafter/designs/${id}`),
  roomTypes: () => api('GET', '/beforeafter/room-types'),
  styles: () => api('GET', '/beforeafter/styles'),
};

// ── ManualEdit ──────────────────────────────────────
export const manualedit = {
  canvases: () => api('GET', '/manualedit/canvases'),
  createCanvas: (d) => api('POST', '/manualedit/canvases', d),
  getCanvas: (id) => api('GET', `/manualedit/canvases/${id}`),
  saveCanvas: (id, state, ops) => api('PUT', `/manualedit/canvases/${id}`, { state, operations: ops }),
  deleteCanvas: (id) => api('DELETE', `/manualedit/canvases/${id}`),
};

// ── Approvals ───────────────────────────────────────
export const approvals = {
  list: () => api('GET', '/approvals'),
  approve: (id, note) => api('POST', `/approvals/${id}/approve`, { note }),
  reject: (id, note) => api('POST', `/approvals/${id}/reject`, { note }),
};

// ── System ──────────────────────────────────────────
export const system = { health: () => api('GET', '/system/health') };
export const notifications = {
  list: (unread) => api('GET', `/notifications${unread ? '?unread_only=1' : ''}`),
  read: (id) => api('POST', `/notifications/${id}/read`),
};

// ── Unified Execution Engine ─────────────────────────
// ALL write actions should flow through here for credits, approvals, agents, automation
export const execute = {
  manual: (engine, action, params = {}, priority = 'normal') =>
    api('POST', '/execute', { engine, action, params, priority }),
  async: (engine, action, params = {}, agentId = 'sarah', priority = 'normal') =>
    api('POST', '/execute/async', { engine, action, params, agent_id: agentId, priority }),
};
