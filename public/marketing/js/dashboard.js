/**
 * LevelUpGrowth — Command Center Dashboard
 * Fetches real data: credits, subscription, tasks, agents, insights
 */
'use strict';

const Dashboard = {

    async render(container) {
        container.innerHTML = `
        <div class="view-header">
          <div>
            <h1 class="view-title">Command Center</h1>
            <p class="view-sub">Your AI marketing team — live overview</p>
          </div>
          <button class="btn btn-ghost btn-sm" onclick="Dashboard.render(document.getElementById('app-view'))">↻ Refresh</button>
        </div>

        <div id="dash-kpis" class="kpi-row">
          ${[0,1,2,3].map(() => '<div class="kpi-card kpi-skeleton"></div>').join('')}
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px">
          <div class="panel" id="dash-agents-panel">
            <div class="panel-header"><span class="panel-title">AI Agents</span><span class="panel-badge" id="dash-agents-badge">Loading…</span></div>
            <div id="dash-agents-body" class="panel-body-scroll"></div>
          </div>
          <div class="panel" id="dash-tasks-panel">
            <div class="panel-header"><span class="panel-title">Recent Tasks</span><a class="panel-link" onclick="Router.go('tasks')">View all →</a></div>
            <div id="dash-tasks-body" class="panel-body-scroll"></div>
          </div>
        </div>

        <div class="panel" id="dash-insights-panel">
          <div class="panel-header"><span class="panel-title">AI Insights</span><span style="font-size:11px;color:var(--faint)">Updates hourly</span></div>
          <div id="dash-insights-body" class="panel-body-scroll"></div>
        </div>

        <div class="panel" id="dash-team-panel" style="margin-top:20px">
          <div class="panel-header">
            <span class="panel-title">Your AI Team</span>
            <a class="panel-link" onclick="Router.go('strategy')">Strategy Room →</a>
          </div>
          <div id="dash-team-body" style="padding:14px"></div>
        </div>`;

        await this.loadAll();
        Dashboard.renderTeamPanel();
    },

    async loadAll() {
        const [credits, tasks, agents, insights] = await Promise.allSettled([
            API.CreditsAPI.balance(),
            API.TasksAPI.list('?limit=20'),
            API.AgentsAPI.list(),
            API.AnalyticsAPI.summary(),
        ]);

        this.renderKPIs(
            credits.status === 'fulfilled' ? credits.value.data : null,
            tasks.status   === 'fulfilled' ? tasks.value.data   : null,
        );
        this.renderAgents(
            agents.status === 'fulfilled' ? agents.value.data : null,
            tasks.status  === 'fulfilled' ? tasks.value.data  : null,
        );
        this.renderTasks(tasks.status === 'fulfilled' ? tasks.value.data : null);
        this.renderInsights(insights.status === 'fulfilled' ? insights.value.data : null);
    },

    renderKPIs(credits, tasksData) {
        const kpiRow = document.getElementById('dash-kpis');
        if (!kpiRow) return;

        const bal   = credits ? parseFloat(credits.credit_balance || 0).toFixed(0) : '—';
        const limit = credits ? (credits.monthly_limit || credits.monthly_credit_limit || 50) : '—';
        const plan  = credits ? (credits.plan_name || 'Free') : '—';

        const taskList  = tasksData?.tasks || [];
        const active    = taskList.filter(t => ['in_progress','queued'].includes(t.status)).length;
        const completed = taskList.filter(t => t.status === 'completed').length;

        kpiRow.innerHTML = [
            { label: 'Credits Remaining',  value: bal,       sub: `of ${limit} credits this month`,         color: '#7C3AED' },
            { label: 'Current Plan',        value: plan,      sub: 'Active subscription',               color: '#3B82F6' },
            { label: 'Active Tasks',        value: active,    sub: `${completed} completed`,            color: '#F59E0B' },
            { label: 'Credits Used',        value: credits ? (credits.lifetime_used || 0) : '—',
              sub: 'Total since account creation', color: '#00E5A8' },
        ].map(k => `
          <div class="kpi-card">
            <div class="kpi-label">${k.label}</div>
            <div class="kpi-value" style="color:${k.color}">${k.value}</div>
            <div class="kpi-sub">${k.sub}</div>
          </div>`).join('');
    },

    renderAgents(agData, tasksData) {
        const body  = document.getElementById('dash-agents-body');
        const badge = document.getElementById('dash-agents-badge');
        if (!body) return;

        const agents    = agData?.agents || [];
        const taskList  = tasksData?.tasks || [];

        if (badge) badge.textContent = agents.length + ' agents';

        if (!agents.length) {
            body.innerHTML = '<div class="empty-state">No agent data available.</div>';
            return;
        }

        const AGENT_COLORS = { dmm:'#F59E0B', sarah:'#F59E0B', james:'#3B82F6', priya:'#7C3AED', marcus:'#EC4899', elena:'#00E5A8', alex:'#06B6D4' };
        const AGENT_ORB    = { dmm:'dmm', sarah:'dmm', james:'seo', priya:'content', marcus:'social', elena:'crm', alex:'technical' };

        body.innerHTML = agents.map(ag => {
            const id      = ag.agent_id || ag.id || '';
            const color   = AGENT_COLORS[id] || '#8892a4';
            const orbType = AGENT_ORB[id] || 'dmm';
            const active  = taskList.filter(t => (t.agent_id || t.assignee) === id && ['in_progress','queued'].includes(t.status)).length;
            const done    = taskList.filter(t => (t.agent_id || t.assignee) === id && t.status === 'completed').length;
            const orbHtml = (typeof OrbAvatar !== 'undefined') ? OrbAvatar.buildAgentOrb(id, 'sm', active ? 'executing' : 'idle') : '';
            return `
              <div class="agent-row" onmouseover="if(typeof setOrbState!=='undefined')setOrbState('${orbType}','thinking')" onmouseout="if(typeof setOrbState!=='undefined')setOrbState('${orbType}',${active ? "'executing'" : "'idle'"})">
                <div class="agent-row-avatar" style="background:${color}18;border:1px solid ${color}35;display:flex;align-items:center;justify-content:center">${orbHtml}</div>
                <div style="flex:1;min-width:0">
                  <div class="agent-row-name" style="color:${color}">${_esc(ag.name || id)}</div>
                  <div class="agent-row-role">${_esc(ag.title || '')}</div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                  ${active ? `<div style="font-size:11px;font-weight:700;color:var(--amber)">${active} active</div>` : ''}
                  <div style="font-size:11px;color:var(--faint)">${done} done</div>
                </div>
              </div>`;
        }).join('');
    },

    renderTasks(tasksData) {
        const body = document.getElementById('dash-tasks-body');
        if (!body) return;

        const tasks = (tasksData?.tasks || []).slice(0, 8);
        if (!tasks.length) {
            body.innerHTML = '<div class="empty-state">No tasks yet. Start a meeting to create your first task.</div>';
            return;
        }

        const STATUS_COLOR = { completed:'var(--green)', in_progress:'var(--amber)', queued:'var(--blue)', failed:'var(--red)', pending:'var(--faint)' };

        body.innerHTML = tasks.map(t => {
            const col = STATUS_COLOR[t.status] || 'var(--faint)';
            return `
              <div class="task-row" onclick="Router.go('tasks','${t.task_id}')">
                <div style="flex:1;min-width:0">
                  <div class="task-row-title">${_esc((t.title || '').slice(0, 52))}</div>
                  <div class="task-row-meta">${_esc(t.agent_id || '')} · ${_relTime(t.created_at)}</div>
                </div>
                <div class="status-chip" style="background:${col}18;color:${col};border-color:${col}35">${t.status}</div>
              </div>`;
        }).join('');
    },

    renderInsights(data) {
        const body = document.getElementById('dash-insights-body');
        if (!body) return;

        if (!data) {
            body.innerHTML = '<div class="empty-state">Insights data loading — check back after the first daily snapshot.</div>';
            return;
        }

        const metrics = [
            { label: 'Tasks completed today', value: data.tasks_completed_today || 0,            color: '#7C3AED' },
            { label: 'Credits used today',     value: parseFloat(data.credits_used_today || 0).toFixed(1), color: '#3B82F6' },
            { label: 'Campaigns sent (7d)',    value: data.campaigns_sent_week || 0,              color: '#F59E0B' },
            { label: 'Posts published (7d)',   value: data.posts_published_week || 0,            color: '#00E5A8' },
            { label: 'Write items total',      value: data.write_items_total || 0,               color: '#A78BFA' },
            { label: 'Avg SEO score',          value: data.avg_seo_score ? parseFloat(data.avg_seo_score).toFixed(1) : '—', color: '#F87171' },
        ];

        body.innerHTML = `<div class="insights-grid">${metrics.map(m => `
          <div class="insight-card">
            <div class="insight-label">${m.label}</div>
            <div class="insight-value" style="color:${m.color}">${m.value}</div>
          </div>`).join('')}</div>`;
    },

    renderTeamPanel() {
        const body = document.getElementById('dash-team-body');
        if (!body) return;

        const plan     = (localStorage.getItem('lu_selected_plan') || '').toLowerCase();
        const rules    = (typeof getPlanRules !== 'undefined') ? getPlanRules(plan) : { ai: false, dmm: false };
        const team     = (typeof AgentTeam !== 'undefined') ? AgentTeam.load() : {};
        const selIds   = Array.isArray(team.specialists) ? team.specialists : [];
        const level    = team.level || 'specialist';

        // DMM is always Sarah on AI plans
        const allAgents = [];
        if (rules.dmm) {
            allAgents.push({ id:'sarah', name:'Sarah', orbType:'dmm', color:'#F59E0B', role:'Digital Marketing Manager', level:'senior' });
        }
        if (typeof SPECIALISTS !== 'undefined' && selIds.length) {
            selIds.forEach(id => {
                const sp = SPECIALISTS.find(s => s.id === id);
                if (sp) allAgents.push({ ...sp, level });
            });
        }

        if (!allAgents.length) {
            const noAiPlans = ['free','starter','ai lite'];
            if (noAiPlans.includes(plan)) {
                body.innerHTML = `<div class="empty-state" style="padding:20px">
                    AI agents are available from the <strong style="color:var(--violet)">Growth plan</strong> ($99/month).
                    <br><a href="/pages/pricing/" style="color:var(--violet);font-weight:600;margin-top:6px;display:inline-block">View Pricing →</a>
                </div>`;
            } else {
                body.innerHTML = `<div class="empty-state" style="padding:20px">No team selected. <a href="#" onclick="localStorage.removeItem('lu_team_selected');App.showOnboarding()" style="color:var(--violet);font-weight:600">Select your team →</a></div>`;
            }
            return;
        }

        const LEVEL_C = { specialist:'#6B7280', junior:'#3B82F6', senior:'#F59E0B' };
        const LEVEL_L = { specialist:'Specialist', junior:'Junior Specialist', senior:'Senior Specialist' };

        body.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">` +
            allAgents.map(ag => {
                const lc = LEVEL_C[ag.level] || '#6B7280';
                const ll = LEVEL_L[ag.level] || 'Specialist';
                const orbHtml = (typeof OrbAvatar !== 'undefined') ? (ag.id && OrbAvatar.AGENT_LEVELS[ag.id] ? OrbAvatar.buildAgentOrb(ag.id, 'md', 'idle') : OrbAvatar.buildOrb(ag.orbType, 'md', 'idle', '', ag.level || 'specialist')) : '';
                return `<div class="agent-card-comp" style="border-color:${ag.color}20"
                    onmouseover="if(typeof setOrbState!=='undefined')setOrbState('${ag.orbType}','thinking')"
                    onmouseout="if(typeof setOrbState!=='undefined')setOrbState('${ag.orbType}','idle')">
                  ${orbHtml}
                  <div class="agent-card-name" style="color:${ag.color}">${_esc(ag.name)}</div>
                  <div class="agent-card-role">${_esc(ag.role)}</div>
                  <div class="agent-level-badge" style="background:${lc}12;color:${lc};border-color:${lc}28">${ll}</div>
                </div>`;
            }).join('') + `</div>`;

        // Animate orbs after render
        setTimeout(() => {
            if (typeof setOrbState !== 'undefined') {
                allAgents.forEach((ag, i) => {
                    setTimeout(() => setOrbState(ag.orbType, 'executing'), i * 300);
                    setTimeout(() => setOrbState(ag.orbType, 'idle'), i * 300 + 1500);
                });
            }
        }, 400);
    },
};

window.Dashboard = Dashboard;
