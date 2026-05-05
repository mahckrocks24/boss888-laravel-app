/**
 * LevelUp Growth — Orb Avatar System v2
 * Color = Role (never changes with seniority)
 * Motion complexity = Seniority level
 * setOrbState(agentId, state) — global state control
 */
'use strict';

// ── Role colors (LOCKED — never change for seniority) ─────────────────────
const ORB_CONFIG = {
    dmm:       { color: '#F59E0B', label: 'Sarah',  role: 'Marketing Lead'      },
    seo:       { color: '#3B82F6', label: 'James',  role: 'SEO Strategist'      },
    content:   { color: '#7C3AED', label: 'Priya',  role: 'Content Manager'     },
    social:    { color: '#EC4899', label: 'Marcus', role: 'Social Media'        },
    ads:       { color: '#F97316', label: 'Elena',  role: 'CRM Manager'         },
    technical: { color: '#06B6D4', label: 'Alex',   role: 'Technical SEO'       },
    crm:       { color: '#00E5A8', label: 'Elena',  role: 'CRM Manager'         },
};

// ── Agent ID → orb type ───────────────────────────────────────────────────
const AGENT_ORB_MAP = {
    dmm:    'dmm',
    sarah:  'dmm',
    james:  'seo',
    priya:  'content',
    marcus: 'social',
    elena:  'crm',
    alex:   'technical',
    // 20 specialists
    diana:  'seo',      ryan:   'technical', sofia:  'seo',
    leo:    'content',  maya:   'content',   chris:  'ads',       nora:   'content',
    zara:   'social',   tyler:  'social',    aria:   'ads',       jordan: 'social',
    sam:    'crm',      kai:    'crm',       vera:   'ads',       max:    'crm',
};

// ── Core agent level assignments (spec-locked) ────────────────────────────
const AGENT_LEVELS = {
    sarah:  'senior',      // DMM — always senior
    dmm:    'senior',
    james:  'junior',      // SEO Strategist
    priya:  'specialist',  // Content Manager
    marcus: 'specialist',  // Social Media Manager
    elena:  'junior',      // CRM Manager
    alex:   'senior',      // Technical SEO
};

// ── Level → CSS class map ─────────────────────────────────────────────────
const LEVEL_CLASS = {
    specialist: 'orb--specialist',
    junior:     'orb--junior',
    senior:     'orb--senior',
};

// ── Level labels + colors ─────────────────────────────────────────────────
const LEVEL_LABELS = {
    specialist: 'Specialist',
    junior:     'Junior Specialist',
    senior:     'Senior Specialist',
};
const LEVEL_COLORS = {
    specialist: '#6B7280',
    junior:     '#3B82F6',
    senior:     '#F59E0B',
};

/**
 * Build orb HTML string.
 *
 * Supports two call signatures:
 *   buildOrb(type, size, state, label, level)       — legacy positional
 *   buildOrb({ role, size, state, label, level })    — object (spec style)
 *
 * @param {string|object} typeOrOpts
 * @param {string} [size]  - sm | md | lg
 * @param {string} [state] - idle | thinking | executing | success | error
 * @param {string} [label] - optional text below orb
 * @param {string} [level] - specialist | junior | senior
 */
function buildOrb(typeOrOpts, size, state, label, level) {
    // Object parameter support
    if (typeOrOpts && typeof typeOrOpts === 'object') {
        const o = typeOrOpts;
        return buildOrb(
            o.role  || o.type  || 'dmm',
            o.size  || 'md',
            o.state || 'idle',
            o.label || '',
            o.level || ''
        );
    }

    const type  = typeOrOpts || 'dmm';
    size  = size  || 'md';
    state = state || 'idle';
    label = label !== undefined ? label : '';
    level = level || '';

    // Build class string — apply BOTH legacy and new BEM class
    let classes = 'orb';
    if (level) {
        classes += ' orb-level-' + level;   // backward compat
        classes += ' ' + (LEVEL_CLASS[level] || '');  // new BEM system
    }

    const orbHtml = `<div class="${classes}" data-type="${type}" data-size="${size}" data-state="${state}" data-agent="${type}">
      <div class="orb-core"></div>
      <div class="orb-shine"></div>
      <div class="orb-ring"></div>
    </div>`;

    if (label) {
        return `<div class="orb-wrap">${orbHtml}<div class="orb-label">${label}</div></div>`;
    }
    return orbHtml;
}

/**
 * Get the correct level for a core agent by ID.
 * Falls back to 'specialist' if unknown.
 */
function getAgentLevel(agentId) {
    return AGENT_LEVELS[agentId] || AGENT_LEVELS[agentId.toLowerCase()] || 'specialist';
}

/**
 * Build orb for a core agent using their spec-locked level.
 * @param {string} agentId - sarah|james|priya|marcus|elena|alex|dmm|seo|...
 * @param {string} size    - sm | md | lg
 * @param {string} state   - idle | thinking | executing | success | error
 * @param {string} label   - optional
 */
function buildAgentOrb(agentId, size, state, label) {
    const type  = AGENT_ORB_MAP[agentId] || agentId;
    const level = getAgentLevel(agentId);
    return buildOrb(type, size || 'md', state || 'idle', label || '', level);
}

/**
 * Set state of all orbs for a given agentId.
 * @param {string} agentId
 * @param {string} state
 * @param {number} [autoresetMs]
 */
function setOrbState(agentId, state, autoresetMs) {
    autoresetMs = autoresetMs || 0;
    const type = AGENT_ORB_MAP[agentId] || agentId;
    const orbs = document.querySelectorAll(
        '.orb[data-agent="' + type + '"], .orb[data-type="' + type + '"]'
    );
    orbs.forEach(function(orb) { orb.dataset.state = state; });
    if (autoresetMs > 0) {
        setTimeout(function() { setOrbState(agentId, 'idle'); }, autoresetMs);
    }
}

/**
 * Replace container content with orb row (orb + name + role).
 */
function renderOrbRow(container, agentId, size, state) {
    const type  = AGENT_ORB_MAP[agentId] || agentId;
    const level = getAgentLevel(agentId);
    const cfg   = ORB_CONFIG[type] || ORB_CONFIG.dmm;
    container.innerHTML =
        '<div class="orb-row">' +
            buildOrb(type, size || 'md', state || 'idle', '', level) +
            '<div class="orb-row-info">' +
                '<div class="orb-row-name" style="color:' + cfg.color + '">' + cfg.label + '</div>' +
                '<div class="orb-row-role">' + cfg.role + '</div>' +
                '<div class="orb-level-tag" style="color:' + (LEVEL_COLORS[level]||'#6B7280') + '">' + (LEVEL_LABELS[level]||'') + '</div>' +
            '</div>' +
        '</div>';
}

/**
 * Auto-mount orbs into elements with data-orb-agent attribute.
 * <div data-orb-agent="james" data-orb-size="md" data-orb-state="idle"></div>
 * Automatically uses spec-locked level for core agents.
 */
function initOrbMounts() {
    document.querySelectorAll('[data-orb-agent]').forEach(function(el) {
        const agentId = el.dataset.orbAgent;
        const type    = AGENT_ORB_MAP[agentId] || agentId;
        const size    = el.dataset.orbSize  || 'md';
        const state   = el.dataset.orbState || 'idle';
        const label   = el.dataset.orbLabel !== undefined ? el.dataset.orbLabel : '';
        const level   = el.dataset.orbLevel || getAgentLevel(agentId);
        el.innerHTML  = buildOrb(type, size, state, label, level);
    });
}

function replaceEmojiAvatars() {
    document.querySelectorAll('.agent-emoji-replace').forEach(function(el) {
        const agentId = el.dataset.agent || 'dmm';
        const type    = AGENT_ORB_MAP[agentId] || agentId;
        const size    = el.dataset.size  || 'sm';
        const state   = el.dataset.state || 'idle';
        const level   = getAgentLevel(agentId);
        el.innerHTML  = buildOrb(type, size, state, '', level);
        el.classList.remove('agent-emoji-replace');
    });
}

function startOrbDemoLoop(orbEl, interval) {
    interval = interval || 3000;
    var states = ['idle', 'thinking', 'executing', 'success', 'idle'];
    var i = 0;
    return setInterval(function() {
        i = (i + 1) % states.length;
        orbEl.dataset.state = states[i];
    }, interval);
}

document.addEventListener('DOMContentLoaded', function() {
    initOrbMounts();
    replaceEmojiAvatars();
});

// ── Exports ───────────────────────────────────────────────────────────────
window.OrbAvatar = {
    buildOrb, buildAgentOrb, setOrbState, renderOrbRow,
    initOrbMounts, replaceEmojiAvatars, startOrbDemoLoop,
    AGENT_ORB_MAP, AGENT_LEVELS, ORB_CONFIG, LEVEL_LABELS, LEVEL_COLORS, LEVEL_CLASS,
    getAgentLevel,
};
window.setOrbState   = setOrbState;
window.buildOrb      = buildOrb;
window.buildAgentOrb = buildAgentOrb;
