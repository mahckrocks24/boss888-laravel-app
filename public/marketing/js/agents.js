/**
 * LevelUp Growth — Agent Roster System
 * 20 Specialists · 3 levels · plan-based allocation
 * Persists selection to localStorage as lu_team
 */
'use strict';

// ── Plan rules ────────────────────────────────────────────────────────────
const PLAN_AGENT_RULES = {
    'free':    { ai: false,  dmm: false, count: 0, level: null,         addon_price: null },
    'starter': { ai: false,  dmm: false, count: 0, level: null,         addon_price: null },
    'ai lite': { ai: 'lite', dmm: false, count: 0, level: null,         addon_price: null },
    'growth':  { ai: true,   dmm: true,  count: 2, level: 'specialist', label: 'Specialist', addon_price: 20   },
    'pro':     { ai: true,   dmm: true,  count: 5, level: 'junior',     label: 'Junior',     addon_price: 20   },
    'agency':  { ai: true,   dmm: true,  count: 10, level: 'senior',    label: 'Senior',     addon_price: 10   },
};

function getPlanRules(planName) {
    const key = (planName || '').toLowerCase().trim();
    return PLAN_AGENT_RULES[key] || PLAN_AGENT_RULES['free'];
}

// ── 20 Specialists roster ─────────────────────────────────────────────────
const SPECIALISTS = [
    // ── SEO category ─────────────────────────────────────────────────────
    {
        id: 'james', name: 'James', level: 'junior',     orbType: 'seo', color: '#3B82F6',
        role: 'SEO Strategist', category: 'seo',
        desc: 'Keyword research, SERP analysis, and organic growth strategy.',
        skills: ['Keyword Research', 'SERP Analysis', 'Competitor Research'],
    },
    {
        id: 'alex', name: 'Alex', level: 'senior',     orbType: 'technical', color: '#06B6D4',
        role: 'Technical SEO Engineer', category: 'seo',
        desc: 'Site audits, Core Web Vitals, schema markup, and indexing.',
        skills: ['Site Audits', 'Core Web Vitals', 'Schema Markup'],
    },
    {
        id: 'diana', name: 'Diana', level: 'junior', orbType: 'seo', color: '#3B82F6',
        role: 'Local SEO Specialist', category: 'seo',
        desc: 'Google Business, local citations, and map pack rankings.',
        skills: ['Google Business', 'Local Citations', 'Map Pack'],
    },
    {
        id: 'ryan', name: 'Ryan', level: 'junior', orbType: 'technical', color: '#06B6D4',
        role: 'Link Building Specialist', category: 'seo',
        desc: 'Backlink acquisition, digital PR, and authority building.',
        skills: ['Link Outreach', 'Digital PR', 'Authority Building'],
    },
    {
        id: 'sofia', name: 'Sofia', level: 'senior', orbType: 'seo', color: '#3B82F6',
        role: 'International SEO Director', category: 'seo',
        desc: 'Multi-market SEO strategy, hreflang, and international search presence.',
        skills: ['Multi-market SEO', 'Hreflang', 'International SEO'],
    },
    // ── Content category ──────────────────────────────────────────────────
    {
        id: 'priya', name: 'Priya', level: 'specialist', orbType: 'content', color: '#7C3AED',
        role: 'Content Manager', category: 'content',
        desc: 'Blog articles, website copy, and marketing content creation.',
        skills: ['Blog Writing', 'Landing Pages', 'SEO Copy'],
    },
    {
        id: 'leo', name: 'Leo', level: 'specialist', orbType: 'content', color: '#7C3AED',
        role: 'Brand Copywriter', category: 'content',
        desc: 'Conversion-focused copy, brand messaging, and ad headlines.',
        skills: ['Ad Copy', 'Brand Voice', 'Conversion Copy'],
    },
    {
        id: 'maya', name: 'Maya', level: 'junior', orbType: 'content', color: '#7C3AED',
        role: 'Social Content Writer', category: 'content',
        desc: 'Captions, hashtag strategies, and platform-specific content.',
        skills: ['Captions', 'Hashtags', 'Platform Formats'],
    },
    {
        id: 'chris', name: 'Chris', level: 'junior', orbType: 'ads', color: '#F97316',
        role: 'Video Script Writer', category: 'content',
        desc: 'Short-form video scripts, reels hooks, and ad video copy.',
        skills: ['Video Scripts', 'Reel Hooks', 'TikTok Copy'],
    },
    {
        id: 'nora', name: 'Nora', level: 'senior', orbType: 'content', color: '#7C3AED',
        role: 'Content Strategy Director', category: 'content',
        desc: 'Full content calendars, thought leadership, and editorial strategy.',
        skills: ['Editorial Strategy', 'Content Calendars', 'Thought Leadership'],
    },
    // ── Social Media category ─────────────────────────────────────────────
    {
        id: 'marcus', name: 'Marcus', level: 'specialist', orbType: 'social', color: '#EC4899',
        role: 'Social Media Manager', category: 'social',
        desc: 'Multi-platform content creation and posting schedule management.',
        skills: ['Content Creation', 'Scheduling', 'Engagement'],
    },
    {
        id: 'zara', name: 'Zara', level: 'junior', orbType: 'social', color: '#EC4899',
        role: 'Instagram Growth Specialist', category: 'social',
        desc: 'Instagram Reels, Stories, and follower growth strategies.',
        skills: ['Instagram Reels', 'Stories', 'Follower Growth'],
    },
    {
        id: 'tyler', name: 'Tyler', level: 'junior', orbType: 'social', color: '#EC4899',
        role: 'LinkedIn Marketing Expert', category: 'social',
        desc: 'B2B LinkedIn strategy, thought leadership posts, and lead gen.',
        skills: ['LinkedIn Posts', 'B2B Strategy', 'Lead Gen'],
    },
    {
        id: 'zoe',  name: 'Zoe',  level: 'junior', orbType: 'ads', color: '#EC4899',
        role: 'TikTok & Reels Creator', category: 'social',
        desc: 'Short-form video strategy, trending formats, and viral hooks.',
        skills: ['TikTok Strategy', 'Trending Formats', 'Viral Hooks'],
    },
    {
        id: 'jordan', name: 'Jordan', level: 'senior', orbType: 'social', color: '#EC4899',
        role: 'Social Analytics Director', category: 'social',
        desc: 'Cross-platform analytics, ROI reporting, and audience insights.',
        skills: ['Analytics', 'ROI Reporting', 'Audience Insights'],
    },
    // ── CRM / Growth category ─────────────────────────────────────────────
    {
        id: 'elena', name: 'Elena', level: 'junior',     orbType: 'crm', color: '#00E5A8',
        role: 'Lead & CRM Manager', category: 'crm',
        desc: 'Lead capture, pipeline management, and automated follow-ups.',
        skills: ['Lead Capture', 'Pipeline', 'Follow-up Sequences'],
    },
    {
        id: 'sam', name: 'Sam', level: 'specialist', orbType: 'crm', color: '#00E5A8',
        role: 'Email Marketing Specialist', category: 'crm',
        desc: 'Campaign creation, segmentation, and email automation flows.',
        skills: ['Email Campaigns', 'Automation', 'Segmentation'],
    },
    {
        id: 'kai', name: 'Kai', level: 'junior', orbType: 'crm', color: '#00E5A8',
        role: 'Lead Nurturing Specialist', category: 'crm',
        desc: 'Drip sequences, lead scoring, and conversion optimization.',
        skills: ['Drip Sequences', 'Lead Scoring', 'Nurture Flows'],
    },
    {
        id: 'vera', name: 'Vera', level: 'junior', orbType: 'ads', color: '#F97316',
        role: 'Marketing Automation Expert', category: 'crm',
        desc: 'Workflow automation, trigger logic, and multi-channel sequences.',
        skills: ['Workflow Builder', 'Trigger Logic', 'Multi-channel'],
    },
    {
        id: 'max', name: 'Max', level: 'senior', orbType: 'crm', color: '#00E5A8',
        role: 'Growth & CRO Director', category: 'crm',
        desc: 'Conversion rate optimization, funnel analysis, and A/B testing.',
        skills: ['CRO', 'Funnel Analysis', 'A/B Testing'],
    },
];

const LEVEL_LABELS = { specialist: 'Specialist', junior: 'Junior Specialist', senior: 'Senior Specialist' };
const LEVEL_COLORS = { specialist: '#6B7280', junior: '#3B82F6', senior: '#F59E0B' };

// ── Team storage ──────────────────────────────────────────────────────────
const AgentTeam = {
    load() {
        try { return JSON.parse(localStorage.getItem('lu_team') || '{}'); } catch(_) { return {}; }
    },
    save(team) {
        try { localStorage.setItem('lu_team', JSON.stringify(team)); } catch(_) {}
    },
    getSelectedIds() {
        const t = this.load();
        return Array.isArray(t.specialists) ? t.specialists : [];
    },
    setSpecialists(ids, planName) {
        const t = this.load();
        t.specialists = ids;
        t.plan = planName;
        t.dmm = 'sarah';
        t.level = getPlanRules(planName).level;
        this.save(t);
    },
    getById(id) {
        return SPECIALISTS.find(s => s.id === id) || null;
    },
};

// ── Build agent card HTML ─────────────────────────────────────────────────
function buildAgentCard(agent, opts = {}) {
    const { selected = false, showActions = false, plan = '' } = opts;
    const orbHtml = (typeof OrbAvatar !== 'undefined')
        ? OrbAvatar.buildOrb(agent.orbType, opts.size || 'md', 'idle', '', opts.levelOverride || agent.level)
        : '';
    const levelColor  = LEVEL_COLORS[agent.level]  || '#6B7280';
    const levelLabel  = LEVEL_LABELS[agent.level]  || 'Specialist';
    const selectedCls = selected ? ' agent-card-selected' : '';

    return `<div class="agent-card-comp${selectedCls}" data-id="${agent.id}"
        style="border-color:${selected ? agent.color + '60' : agent.color + '18'};background:${selected ? agent.color + '08' : ''}">
      <div class="agent-card-orb-wrap">${orbHtml}</div>
      <div class="agent-card-body">
        <div class="agent-card-name" style="color:${agent.color}">${agent.name}</div>
        <div class="agent-card-role">${agent.role}</div>
        <div class="agent-level-badge" style="background:${levelColor}12;color:${levelColor};border-color:${levelColor}28">${levelLabel}</div>
        <div class="agent-card-desc">${agent.desc}</div>
        ${showActions ? `<div class="agent-card-actions">
          <button class="btn btn-ghost btn-sm" onclick="AgentMgr.replace('${agent.id}')">Replace</button>
          ${plan === 'growth' || plan === 'pro' ? `<button class="btn btn-ghost btn-sm" onclick="AgentMgr.addAddon()">+ Add ($20/mo)</button>` : ''}
          ${plan === 'agency' ? `<button class="btn btn-ghost btn-sm" onclick="AgentMgr.addAddon()">+ Add ($10/mo)</button>` : ''}
        </div>` : ''}
      </div>
    </div>`;
}

// ── Agent management UI (dashboard) ──────────────────────────────────────
const AgentMgr = {
    replace(agentId) { _showToast('Agent replacement — coming in Phase 3', 'info'); },
    addAddon()       { _showToast('Additional agents — upgrade billing coming soon', 'info'); },
};

window.SPECIALISTS   = SPECIALISTS;
window.PLAN_AGENT_RULES = PLAN_AGENT_RULES;
window.getPlanRules  = getPlanRules;
window.AgentTeam     = AgentTeam;
window.buildAgentCard = buildAgentCard;
window.AgentMgr      = AgentMgr;
window.LEVEL_LABELS  = LEVEL_LABELS;
window.LEVEL_COLORS  = LEVEL_COLORS;
