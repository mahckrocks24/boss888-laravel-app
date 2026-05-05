// LevelUp OS — API Service (AUDIT-REMEDIATED v1.1.0)
//
// CRITICAL-01 FIX: All engine paths corrected. Backend serves /crm/*, /seo/*, /write/*,
//   /creative/*, /social/*, /marketing/*, /website/*, /calendar/* — NOT /engine/{engine}/*
// CRITICAL-02 FIX: sarah.createGoal() now calls /sarah/receive (correct backend endpoint)
// CRITICAL-03 FIX: tasks.cancel() now uses user-accessible route (user cancel added to api.php)
// LOW-05 FIX:      team.invite() now accepts role param (was hardcoded 'member' in UI)

const BASE = '/api'

function getToken() {
  return localStorage.getItem('lu_token') || ''
}

async function request(method, path, body = null) {
  const opts = {
    method,
    headers: {
      Authorization: `Bearer ${getToken()}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  }
  if (body) opts.body = JSON.stringify(body)

  const res = await fetch(`${BASE}${path}`, opts)

  if (res.status === 401) {
    localStorage.removeItem('lu_token')
    localStorage.removeItem('lu_user')
    window.location.href = '/app'
    return null
  }

  const data = await res.json().catch(() => ({}))
  return { ok: res.ok, status: res.status, data }
}

export const api = {
  get:    (path)       => request('GET',    path),
  post:   (path, body) => request('POST',   path, body),
  put:    (path, body) => request('PUT',    path, body),
  delete: (path)       => request('DELETE', path),
  patch:  (path, body) => request('PATCH',  path, body),
}

// ── Auth ─────────────────────────────────────────────────────────────────────
export const auth = {
  login:    (email, password) => api.post('/auth/login',             { email, password }),
  register: (data)            => api.post('/auth/register',           data),
  me:       ()                => api.get('/auth/me'),
  logout:   ()                => api.post('/auth/logout',             {}),
  switch:   (wsId)            => api.post('/auth/switch-workspace',   { workspace_id: wsId }),
}

// ── Workspace ─────────────────────────────────────────────────────────────────
export const workspace = {
  list:         ()       => api.get('/workspaces'),
  capabilities: ()       => api.get('/workspace/capabilities'),
  trialStatus:  ()       => api.get('/workspace/trial-status'),
  billing:      ()       => api.get('/billing/status'),
  plans:        ()       => api.get('/billing/plans'),
  checkout:     (planId) => api.post('/billing/checkout', { plan_id: planId }),
  portal:       ()       => api.get('/billing/portal'),
  upgrade:      (planId) => api.post('/billing/upgrade',  { plan_id: planId }),
}

// ── CRM — /crm/* (AUDIT FIX: was /engine/crm/*) ──────────────────────────────
export const crm = {
  listLeads:    (p)     => api.get(`/crm/leads?${new URLSearchParams(p)}`),
  createLead:   (d)     => api.post('/crm/leads',               d),
  getLead:      (id)    => api.get(`/crm/leads/${id}`),
  updateLead:   (id, d) => api.put(`/crm/leads/${id}`,          d),
  deleteLead:   (id)    => api.delete(`/crm/leads/${id}`),
  scoreLead:    (id)    => api.post(`/crm/leads/${id}/score`,   {}),
  assignLead:   (id, d) => api.post(`/crm/leads/${id}/assign`,  d),
  pipeline:     ()      => api.get('/crm/pipeline'),
  stages:       ()      => api.get('/crm/stages'),
  listDeals:    ()      => api.get('/crm/deals'),
  createDeal:   (d)     => api.post('/crm/deals',               d),
  updateDeal:   (id, d) => api.put(`/crm/deals/${id}`,          d),
  listContacts: ()      => api.get('/crm/contacts'),
  todayView:    ()      => api.get('/crm/today'),
  outreach:     (d)     => api.post('/crm/outreach/generate',   d),
  followUp:     (d)     => api.post('/crm/outreach/follow-up',  d),
  revenue:      ()      => api.get('/crm/revenue'),
}

// ── SEO — /seo/* (AUDIT FIX: was /engine/seo/*) ──────────────────────────────
export const seo = {
  serpAnalysis:    (d)  => api.post('/seo/serp-analysis',      d),
  aiReport:        (d)  => api.post('/seo/ai-report',          d),
  deepAudit:       (d)  => api.post('/seo/deep-audit',         d),
  linkSuggestions: (d)  => api.post('/seo/link-suggestions',   d),
  insertLink:      (d)  => api.post('/seo/links/insert',       d),
  dismissLink:     (id) => api.post(`/seo/links/${id}/dismiss`, {}),
  checkOutbound:   (d)  => api.post('/seo/outbound/check',     d),
  listGoals:       ()   => api.get('/seo/goals'),
  createGoal:      (d)  => api.post('/seo/goals',              d),
  pauseGoal:       (id) => api.post(`/seo/goals/${id}/pause`,  {}),
  resumeGoal:      (id) => api.post(`/seo/goals/${id}/resume`, {}),
  agentStatus:     ()   => api.get('/seo/agent-status'),
}

// ── Write — /write/* ──────────────────────────────────────────────────────────
// AUDIT FIX v2: write AI routes are /write/ai/write, /write/ai/improve, etc.
// NOT /write/write-article or /write/improve-draft (those are SEO delegation routes)
export const write = {
  listArticles:  (p)     => api.get(`/write/articles?${new URLSearchParams(p)}`),
  getArticle:    (id)    => api.get(`/write/articles/${id}`),
  writeArticle:  (d)     => api.post('/write/ai/write',    d),
  improveDraft:  (d)     => api.post('/write/ai/improve',  d),
  getOutline:    (d)     => api.post('/write/ai/outline',  d),
  getHeadlines:  (d)     => api.post('/write/ai/headlines',d),
  getMeta:       (d)     => api.post('/write/ai/meta',     d),
  updateArticle: (id, d) => api.put(`/write/articles/${id}`, d),
  deleteArticle: (id)    => api.delete(`/write/articles/${id}`),
  dashboard:     ()      => api.get('/write/dashboard'),
}

// ── Creative — /creative/* (AUDIT FIX: was /engine/creative/*) ───────────────
export const creative = {
  dashboard:     ()      => api.get('/creative/dashboard'),
  listAssets:    (p)     => api.get(`/creative/assets?${new URLSearchParams(p)}`),
  getAsset:      (id)    => api.get(`/creative/assets/${id}`),
  generateImage: (d)     => api.post('/creative/generate/image',  d),
  generateVideo: (d)     => api.post('/creative/generate/video',  d),
  pollVideo:     (id)    => api.get(`/creative/assets/${id}/poll`),
  brand:         ()      => api.get('/creative/brand'),
  updateBrand:   (d)     => api.put('/creative/brand',            d),
  memory:        ()      => api.get('/creative/memory'),
  memoryStats:   ()      => api.get('/creative/memory/stats'),
}

// ── Marketing — /marketing/* ──────────────────────────────────────────────────
// AUDIT FIX v2: No /marketing/generate route exists. AI campaign generation
// goes through POST /marketing/campaigns with ai_generate:true in the payload,
// routed through the EngineExecutionService pipeline.
export const marketing = {
  getDashboard:    ()      => api.get('/marketing/dashboard'),
  listCampaigns:   ()      => api.get('/marketing/campaigns'),
  getCampaign:     (id)    => api.get(`/marketing/campaigns/${id}`),
  createCampaign:  (d)     => api.post('/marketing/campaigns',    d),
  generateCampaign:(d)     => api.post('/marketing/campaigns',    { ...d, ai_generate: true }),
  updateCampaign:  (id, d) => api.put(`/marketing/campaigns/${id}`, d),
  scheduleCampaign:(id, d) => api.post(`/marketing/campaigns/${id}/schedule`, d),
  listTemplates:   ()      => api.get('/marketing/templates'),
  createTemplate:  (d)     => api.post('/marketing/templates',    d),
  listAutomations: ()      => api.get('/marketing/automations'),
}

// ── Social — /social/* ────────────────────────────────────────────────────────
// AUDIT FIX v2: No /social/generate route. AI generation uses POST /social/posts
// with ai_generate:true flag passed through to the engine execution pipeline.
export const social = {
  getDashboard:   ()      => api.get('/social/dashboard'),
  listPosts:      (p)     => api.get(`/social/posts?${new URLSearchParams(p)}`),
  getPost:        (id)    => api.get(`/social/posts/${id}`),
  createPost:     (d)     => api.post('/social/posts',               d),
  generatePost:   (d)     => api.post('/social/posts',               { ...d, ai_generate: true }),
  publishPost:    (id)    => api.post(`/social/posts/${id}/publish`, {}),
  schedulePost:   (id, d) => api.post(`/social/posts/${id}/schedule`,d),
  listAccounts:   ()      => api.get('/social/accounts'),
  connectAccount: (d)     => api.post('/social/accounts',            d),
  socialCalendar: (p)     => api.get(`/social/calendar?${new URLSearchParams(p)}`),
}

// ── Builder — /builder/* ──────────────────────────────────────────────────────
// AUDIT FIX v2: Builder routes are under /builder/websites/*, not bare /websites/*
export const builder = {
  listWebsites:  ()       => api.get('/builder/websites'),
  createWebsite: (d)      => api.post('/builder/websites',                d),
  getWebsite:    (id)     => api.get(`/builder/websites/${id}`),
  publishSite:   (id)     => api.post(`/builder/websites/${id}/publish`,  {}),
  listPages:     (wid)    => api.get(`/builder/websites/${wid}/pages`),
  createPage:    (wid, d) => api.post(`/builder/websites/${wid}/pages`,   d),
  updatePage:    (id, d)  => api.put(`/builder/pages/${id}`,             d),
  getDashboard:  ()       => api.get('/builder/dashboard'),
  wizard:        (d)      => api.post('/builder/wizard',                  d),
}

// ── Calendar — /calendar/* ────────────────────────────────────────────────────
// AUDIT FIX v2: Calendar routes are /calendar/events, not /calendar or /events
export const calendar = {
  listEvents:  (p)      => api.get(`/calendar/events?${new URLSearchParams(p)}`),
  createEvent: (d)      => api.post('/calendar/events',       d),
  updateEvent: (id, d)  => api.put(`/calendar/events/${id}`, d),
  deleteEvent: (id)     => api.delete(`/calendar/events/${id}`),
}

// ── Sarah / Orchestration ─────────────────────────────────────────────────────
// CRITICAL-02 FIX: createGoal was calling /sarah/goal — correct endpoint is /sarah/receive
export const sarah = {
  receive:         (d)  => api.post('/sarah/receive',               d),
  createGoal:      (d)  => api.post('/sarah/receive',               d),  // alias
  listPlans:       ()   => api.get('/sarah/plans'),
  getPlan:         (id) => api.get(`/sarah/plans/${id}`),
  approvePlan:     (id) => api.post(`/sarah/plans/${id}/approve`,   {}),
  cancelPlan:      (id) => api.post(`/sarah/plans/${id}/cancel`,    {}),
  listProposals:   ()   => api.get('/sarah/proposals'),
  approveProposal: (id) => api.post(`/sarah/proposals/${id}/approve`, {}),
  declineProposal: (id) => api.post(`/sarah/proposals/${id}/decline`, {}),
  startMeeting:    (d)  => api.post('/sarah/meeting/start',         d),
  fullMeeting:     (d)  => api.post('/sarah/meeting/full',          d),
}

// ── Tasks — /tasks/* ──────────────────────────────────────────────────────────
// CRITICAL-03 FIX: tasks.cancel now hits user-accessible route added to api.php
export const tasks = {
  list:    (p)  => api.get(`/tasks?${new URLSearchParams(p)}`),
  get:     (id) => api.get(`/tasks/${id}`),
  status:  (id) => api.get(`/tasks/${id}/status`),
  events:  (id) => api.get(`/tasks/${id}/events`),
  cancel:  (id) => api.post(`/tasks/${id}/cancel`, {}),
}

// ── Approvals — /approvals/* ──────────────────────────────────────────────────
export const approvals = {
  list:    ()           => api.get('/approvals'),
  approve: (id)         => api.post(`/approvals/${id}/approve`, {}),
  reject:  (id, reason) => api.post(`/approvals/${id}/reject`,  { reason }),
  revise:  (id, notes)  => api.post(`/approvals/${id}/revise`,  { notes }),
}

// ── Agent / Meetings ──────────────────────────────────────────────────────────
export const meetings = {
  list:          ()       => api.get('/meetings'),
  get:           (id)     => api.get(`/meetings/${id}`),
  strategyMeet:  (d)      => api.post('/agent/strategy-meeting', d),
  dispatch:      (d)      => api.post('/agent/dispatch',         d),
  conversations: ()       => api.get('/agent/conversations'),
  conversation:  (id)     => api.get(`/agent/conversation/${id}`),
  events:        (cursor) => api.get(`/agent/events${cursor ? '?cursor='+cursor : ''}`),
}

// ── Notifications ─────────────────────────────────────────────────────────────
export const notifications = {
  list:     ()   => api.get('/notifications'),
  markRead: (id) => api.post(`/notifications/${id}/read`, {}),
}

// ── Team ──────────────────────────────────────────────────────────────────────
// LOW-05 FIX: invite() now accepts role parameter (was hardcoded 'member' in Settings UI)
export const team = {
  getMembers:  ()                => api.get('/team/members'),
  invite:      (email, role = 'member') => api.post('/team/invite', { email, role }),
  cancelInvite:(id)              => api.delete(`/team/invites/${id}`),
  listInvites: ()                => api.get('/team/invites'),
  updateRole:  (uid, role)       => api.put(`/team/members/${uid}/role`, { role }),
  remove:      (uid)             => api.delete(`/team/members/${uid}`),
  seats:       ()                => api.get('/team/seats'),
}
