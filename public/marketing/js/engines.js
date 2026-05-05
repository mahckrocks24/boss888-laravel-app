/**
 * LevelUpGrowth — Engine Panels
 * All engines route to internal SPA views.
 * All engines use internal SPA routing only.
 */
'use strict';

// Internal SPA routing — all engines open inside the SaaS app
function _goEngine(view) {
    Router.go(view);
}

const Engines = {

    async renderHub(container) {
        container.innerHTML = `
        <div class="view-header">
          <div><h1 class="view-title">Engine Hub</h1><p class="view-sub">AI-powered marketing engines</p></div>
        </div>
        <div class="engines-grid" id="eng-grid">
          ${[0,1,2,3,4,5,6,7].map(() => '<div class="eng-card eng-skeleton"></div>').join('')}
        </div>`;

        const [tasksRes] = await Promise.allSettled([
            API.TasksAPI.list('?limit=100'),
        ]);
        const tasks = tasksRes.status === 'fulfilled' ? (tasksRes.value.data.tasks || []) : [];

        const writeCount  = tasks.filter(t => t.tools && t.tools.includes('write')).length;
        const seoCount    = tasks.filter(t => t.agent_id === 'james' || t.agent_id === 'alex').length;
        const socialCount = tasks.filter(t => t.agent_id === 'marcus').length;
        const crmCount    = tasks.filter(t => t.agent_id === 'elena').length;

        document.getElementById('eng-grid').innerHTML = [
            {
                icon:'🏗️', name:'Website Builder', color:'#3B82F6',
                desc:'Generate, edit, and publish AI-built websites.',
                stats:[{label:'Pages built', value:'—'}],
                route:'builder', cta:'Open Builder',
            },
            {
                icon:'📈', name:'SEO Engine', color:'#7C3AED',
                desc:'Keyword research, content optimisation, and technical SEO.',
                stats:[{label:'SEO tasks run', value:seoCount}],
                route:'seo', cta:'Open SEO',
            },
            {
                icon:'✍️', name:'Write Engine', color:'#A78BFA',
                desc:'AI-generated blog articles, landing pages, and marketing copy.',
                stats:[{label:'Articles created', value:writeCount}],
                route:'write', cta:'Open Write',
            },
            {
                icon:'🎨', name:'Creative Engine', color:'#F87171',
                desc:'AI image and video generation for your campaigns.',
                stats:[{label:'Assets generated', value:'—'}],
                route:'creative', cta:'Open Creative',
            },
            {
                icon:'🤝', name:'CRM', color:'#00E5A8',
                desc:'Lead management, pipeline tracking, and contact organisation.',
                stats:[{label:'CRM tasks', value:crmCount}],
                route:'crm', cta:'Open CRM',
            },
            {
                icon:'📣', name:'Marketing Engine', color:'#F59E0B',
                desc:'Email campaigns, sequences, and marketing automation.',
                stats:[{label:'Campaigns', value:'—'}],
                route:'marketing', cta:'Open Marketing',
            },
            {
                icon:'📱', name:'Social Media', color:'#F59E0B',
                desc:'AI content creation and scheduling across social platforms.',
                stats:[{label:'Posts', value:socialCount}],
                route:'social', cta:'Open Social',
            },
            {
                icon:'📅', name:'Calendar', color:'#3B82F6',
                desc:'Content calendar and marketing schedule management.',
                stats:[{label:'Events', value:'—'}],
                route:'calendar', cta:'Open Calendar',
            },
        ].map(e => `
          <div class="eng-card" onclick="Router.go('${e.route}')">
            <div class="eng-icon" style="background:${e.color}18;color:${e.color}">${e.icon}</div>
            <div class="eng-name" style="color:${e.color}">${e.name}</div>
            <div class="eng-desc">${e.desc}</div>
            <div class="eng-stats">
              ${e.stats.map(s => `<div class="eng-stat"><span class="eng-stat-val" style="color:${e.color}">${s.value}</span><span class="eng-stat-label">${s.label}</span></div>`).join('')}
            </div>
            <button class="eng-cta btn btn-ghost btn-sm">${e.cta} →</button>
          </div>`).join('');
    },

    // Generic "coming soon" panel for engine views not yet built
    renderEnginePlaceholder(container, name, icon, color, desc) {
        container.innerHTML = `
        <div class="view-header">
          <div><h1 class="view-title">${icon} ${name}</h1><p class="view-sub">${desc}</p></div>
          <button class="btn btn-ghost btn-sm" onclick="Router.go('engines')">← Engine Hub</button>
        </div>
        <div class="engine-placeholder-wrap">
          <div class="engine-placeholder-card" style="border-color:${color}30">
            <div style="font-size:52px;margin-bottom:16px">${icon}</div>
            <h2 style="font-family:var(--ff-h);font-size:20px;font-weight:800;margin-bottom:10px;color:${color}">${name}</h2>
            <p style="color:var(--muted);font-size:14.5px;line-height:1.65;max-width:440px;margin:0 auto 24px">${desc}</p>
            <div class="engine-status-badge" style="background:${color}12;border:1px solid ${color}35;color:${color}">
              ✓ Engine loaded · Connected to AI backend
            </div>
            <div style="margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
              <button class="btn btn-primary" onclick="Router.go('strategy')">Launch via Strategy Room →</button>
              <button class="btn btn-ghost btn-sm" onclick="Router.go('tasks')">View Tasks</button>
            </div>
          </div>
        </div>`;
    },

    renderStrategyRoom(container) {
        container.innerHTML = `
        <div class="view-header">
          <div><h1 class="view-title">Strategy Room</h1><p class="view-sub">Multi-agent AI meetings and task execution</p></div>
        </div>
        <div class="strategy-cta-wrap">
          <div class="strategy-cta-card">
            <div style="font-size:48px;margin-bottom:16px">🎯</div>
            <h2 style="font-family:var(--ff-h);font-size:22px;font-weight:800;margin-bottom:8px">Start a Strategy Meeting</h2>
            <p style="color:var(--muted);font-size:14.5px;line-height:1.65;margin-bottom:28px;max-width:460px">Describe your goal and your full AI agent team will collaborate in real time — planning strategy, creating content, managing SEO, and launching campaigns.</p>
            <div style="background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.25);border-radius:12px;padding:16px 20px;margin-bottom:24px;text-align:left;max-width:440px;margin-left:auto;margin-right:auto">
              <div style="font-family:var(--ff-h);font-weight:700;font-size:13px;margin-bottom:10px;color:var(--violet)">What your team will do:</div>
              <div style="display:flex;flex-direction:column;gap:7px;font-size:13px;color:var(--muted)">
                <div>🎯 <strong style="color:#D1D5DB">Sarah</strong> — Plans the overall strategy</div>
                <div>🔍 <strong style="color:#D1D5DB">James</strong> — Runs SEO analysis</div>
                <div>✍️ <strong style="color:#D1D5DB">Priya</strong> — Creates content</div>
                <div>📱 <strong style="color:#D1D5DB">Marcus</strong> — Manages social media</div>
                <div>🤝 <strong style="color:#D1D5DB">Elena</strong> — Handles leads & CRM</div>
                <div>⚙️ <strong style="color:#D1D5DB">Alex</strong> — Technical SEO & fixes</div>
              </div>
            </div>
            <div id="strategy-meeting-area">
              <button class="btn btn-primary btn-lg" onclick="StrategyRoom.startNew()">+ Start New Meeting</button>
            </div>
          </div>
          <div class="strategy-agents-grid">
            ${[
              {n:'Sarah',  r:'Marketing Lead',      icon:'🎯', c:'#6C5CE7'},
              {n:'James',  r:'SEO Strategist',       icon:'🔍', c:'#3B8BF5'},
              {n:'Priya',  r:'Content Manager',      icon:'✍️', c:'#A78BFA'},
              {n:'Marcus', r:'Social Media Mgr',     icon:'📱', c:'#F59E0B'},
              {n:'Elena',  r:'Lead & CRM Manager',   icon:'🤝', c:'#F87171'},
              {n:'Alex',   r:'Technical SEO',        icon:'⚙️', c:'#00E5A8'},
            ].map(a => `
              <div class="strategy-agent-chip" style="border-color:${a.c}35">
                <div class="strategy-agent-icon" style="background:${a.c}18;color:${a.c}">${a.icon}</div>
                <div>
                  <div style="font-family:var(--ff-h);font-weight:700;font-size:13px;color:${a.c}">${a.n}</div>
                  <div style="font-size:11px;color:var(--faint)">${a.r}</div>
                </div>
              </div>`).join('')}
          </div>
        </div>`;
    },
};

// Stub for Strategy Room inline meeting (Phase 2)
const StrategyRoom = {
    startNew() {
        const area = document.getElementById('strategy-meeting-area');
        if (!area) return;
        area.innerHTML = `
          <div style="max-width:480px;margin:0 auto">
            <div class="form-group" style="margin-bottom:14px">
              <label class="form-label">What do you want your team to work on?</label>
              <textarea class="form-input" id="sr-goal" rows="3" placeholder="e.g. Create a social media campaign for our new product launch" style="resize:vertical"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:center">
              <button class="btn btn-primary" id="sr-launch-btn" onclick="StrategyRoom.launch()">Launch Meeting →</button>
              <button class="btn btn-ghost btn-sm" onclick="Router.go('strategy')">Cancel</button>
            </div>
            <div id="sr-status" style="margin-top:16px"></div>
          </div>`;
    },

    async launch() {
        const goal = document.getElementById('sr-goal')?.value?.trim();
        if (!goal) { _showToast('Enter your goal first.', 'error'); return; }
        const btn = document.getElementById('sr-launch-btn');
        const status = document.getElementById('sr-status');
        if (btn) { btn.disabled = true; btn.textContent = 'Launching…'; }
        try {
            const { data } = await API.AgentsAPI.dispatch({
                agent_id: 'dmm',
                task: { title: goal, params: { goal } },
                origin: 'user',
            });
            if (status) {
                status.innerHTML = `<div style="background:rgba(0,229,168,.08);border:1px solid rgba(0,229,168,.25);border-radius:10px;padding:14px 16px;font-size:13.5px;color:var(--green)">✓ Meeting launched — task ID: <code style="color:#D1D5DB">${data.task_id || 'queued'}</code><br><a href="#" onclick="Router.go('tasks');return false" style="color:var(--violet);font-weight:600">View in Tasks →</a></div>`;
            }
        } catch(e) {
            if (status) status.innerHTML = `<div style="color:var(--red);font-size:13px">Error: ${_esc(e.message)}</div>`;
            if (btn) { btn.disabled = false; btn.textContent = 'Launch Meeting →'; }
        }
    },
};

window.Engines = Engines;
window.StrategyRoom = StrategyRoom;
