import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as api from '../../services/api';

const AGENT_COLORS = { sarah: '#6C5CE7', james: '#3B82F6', priya: '#A78BFA', marcus: '#F59E0B', elena: '#F87171', alex: '#00E5A8' };

export default function Dashboard() {
  const nav = useNavigate();
  const [workspace, setWorkspace] = useState(null);
  const [credits, setCredits] = useState(null);
  const [insights, setInsights] = useState(null);
  const [plans, setPlans] = useState([]);
  const [knowledge, setKnowledge] = useState([]);
  const [quickGoal, setQuickGoal] = useState('');

  useEffect(() => {
    Promise.allSettled([
      api.workspace.status(),
      api.workspace.credits(),
      api.workspace.insights(),
      api.api('GET', '/sarah/plans?limit=5'),
      api.api('GET', '/intelligence/knowledge?limit=5'),
    ]).then(([ws, cr, ins, pl, kn]) => {
      if (ws.status === 'fulfilled') setWorkspace(ws.value);
      if (cr.status === 'fulfilled') setCredits(cr.value);
      if (ins.status === 'fulfilled') setInsights(ins.value);
      if (pl.status === 'fulfilled') setPlans(pl.value.plans || []);
      if (kn.status === 'fulfilled') setKnowledge(kn.value.insights || []);
    });
  }, []);

  const quickSubmit = async () => {
    if (!quickGoal.trim()) return;
    nav('/strategy');
  };

  return (
    <div>
      {/* Header with quick action */}
      <div className="flex justify-between items-start mb-6">
        <div>
          <h1 className="font-heading text-xl font-bold">Command Center</h1>
          <p className="text-gray-500 text-sm">{workspace?.business_name || 'Your AI Marketing Team'}</p>
        </div>
        <div className="flex items-center gap-2">
          <div className="bg-s1 border border-border rounded-xl px-3 py-2 flex items-center gap-2">
            <span className="text-primary text-sm">⚡</span>
            <span className="font-heading font-bold text-sm text-primary">{credits?.credit_balance ?? 0}</span>
            <span className="text-[11px] text-gray-500">credits</span>
          </div>
        </div>
      </div>

      {/* Quick ask Sarah */}
      <div className="bg-s1 border border-primary/20 rounded-xl p-4 mb-6 flex gap-3">
        <div className="w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold text-white flex-shrink-0" style={{ background: AGENT_COLORS.sarah }}>S</div>
        <input value={quickGoal} onChange={e => setQuickGoal(e.target.value)} onKeyDown={e => e.key === 'Enter' && quickSubmit()} placeholder="Ask Sarah anything... e.g. 'Run SEO audit for our website'" className="flex-1 bg-transparent text-[14px] text-gray-200 outline-none placeholder-gray-600" />
        <button onClick={quickSubmit} className="bg-primary text-white text-[12px] font-bold px-4 py-1.5 rounded-lg">Go →</button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Left column — plans + activity */}
        <div className="lg:col-span-2 space-y-4">

          {/* Active Plans */}
          <div className="bg-s1 border border-border rounded-xl p-5">
            <div className="flex justify-between items-center mb-4">
              <h3 className="font-heading font-bold text-sm">Active Plans</h3>
              <button onClick={() => nav('/strategy')} className="text-primary text-[12px] font-semibold">Strategy Room →</button>
            </div>
            {plans.length > 0 ? plans.slice(0, 3).map(plan => (
              <div key={plan.id} onClick={() => nav('/strategy')} className="flex items-center gap-3 py-3 border-b border-s2 last:border-0 cursor-pointer hover:bg-s2 -mx-2 px-2 rounded-lg">
                <StatusDot status={plan.status} />
                <div className="flex-1 min-w-0">
                  <div className="text-[13px] font-medium truncate">{plan.title || plan.goal}</div>
                  <div className="text-[11px] text-gray-500">{plan.completed_tasks}/{plan.total_tasks} tasks · {new Date(plan.created_at).toLocaleDateString()}</div>
                </div>
                {plan.total_tasks > 0 && (
                  <div className="w-16 h-1.5 bg-s2 rounded-full overflow-hidden">
                    <div className="h-full rounded-full bg-accent" style={{ width: `${(plan.completed_tasks / plan.total_tasks) * 100}%` }} />
                  </div>
                )}
              </div>
            )) : (
              <div className="text-center py-6">
                <div className="text-3xl mb-2">🎯</div>
                <p className="text-gray-500 text-[13px]">No active plans. Ask Sarah to create one.</p>
              </div>
            )}
          </div>

          {/* Performance overview */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <KPI label="Tasks Today" value={insights?.tasks_completed_today ?? 0} color="#00E5A8" />
            <KPI label="Credits Used" value={credits?.lifetime_used ?? 0} color="#7C3AED" />
            <KPI label="Campaigns (7d)" value={insights?.campaigns_sent_week ?? 0} color="#F59E0B" />
            <KPI label="Articles" value={insights?.write_items_total ?? 0} color="#A78BFA" />
          </div>

          {/* Engine quick access */}
          <div className="bg-s1 border border-border rounded-xl p-5">
            <h3 className="font-heading font-bold text-sm mb-3">Quick Actions</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
              {[
                { label: 'SEO Audit', icon: '🔍', route: '/seo', color: '#7C3AED' },
                { label: 'Write Article', icon: '✍️', route: '/write', color: '#A78BFA' },
                { label: 'Create Post', icon: '📱', route: '/social', color: '#EC4899' },
                { label: 'View Pipeline', icon: '💰', route: '/crm', color: '#00E5A8' },
              ].map(a => (
                <button key={a.route} onClick={() => nav(a.route)} className="bg-s2 rounded-lg p-3 text-left hover:bg-s3 transition-colors">
                  <div className="text-lg mb-1">{a.icon}</div>
                  <div className="text-[12px] font-semibold" style={{ color: a.color }}>{a.label}</div>
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* Right column — intelligence + agents */}
        <div className="space-y-4">

          {/* AI Team Status */}
          <div className="bg-s1 border border-border rounded-xl p-5">
            <h3 className="font-heading font-bold text-sm mb-3">Your AI Team</h3>
            {[
              { slug: 'sarah', name: 'Sarah', role: 'DMM Director', status: 'coordinating' },
              { slug: 'james', name: 'James', role: 'SEO Strategist', status: 'available' },
              { slug: 'priya', name: 'Priya', role: 'Content Writer', status: 'available' },
              { slug: 'marcus', name: 'Marcus', role: 'Social Manager', status: 'available' },
              { slug: 'elena', name: 'Elena', role: 'CRM Specialist', status: 'available' },
              { slug: 'alex', name: 'Alex', role: 'Technical SEO', status: 'available' },
            ].map(agent => (
              <div key={agent.slug} className="flex items-center gap-3 py-2">
                <div className="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white" style={{ background: AGENT_COLORS[agent.slug] }}>{agent.name[0]}</div>
                <div className="flex-1 min-w-0">
                  <div className="text-[12px] font-semibold">{agent.name}</div>
                  <div className="text-[10px] text-gray-500">{agent.role}</div>
                </div>
                <span className={`text-[9px] px-1.5 py-0.5 rounded-full ${agent.slug === 'sarah' ? 'bg-primary/10 text-primary' : 'bg-accent/10 text-accent'}`}>{agent.status}</span>
              </div>
            ))}
          </div>

          {/* Intelligence Insights */}
          <div className="bg-s1 border border-border rounded-xl p-5">
            <h3 className="font-heading font-bold text-sm mb-3">🧠 Intelligence</h3>
            {knowledge.length > 0 ? knowledge.map((k, i) => (
              <div key={i} className="py-2 border-b border-s2 last:border-0">
                <div className="text-[12px] text-gray-300">{k.insight}</div>
                <div className="flex items-center gap-2 mt-1">
                  <span className="text-[9px] text-gray-500 bg-s2 rounded px-1.5">{k.category}</span>
                  <span className="text-[9px] text-gray-500">{Math.round((k.confidence || 0) * 100)}% confidence</span>
                </div>
              </div>
            )) : (
              <p className="text-gray-500 text-[12px]">Intelligence grows as campaigns run. Start a plan to build knowledge.</p>
            )}
          </div>

          {/* Plan info */}
          <div className="bg-s1 border border-border rounded-xl p-5">
            <h3 className="font-heading font-bold text-sm mb-2">Plan</h3>
            <div className="text-2xl font-heading font-bold text-primary mb-1">{credits?.plan_name || 'Free'}</div>
            <div className="text-[12px] text-gray-500">{credits?.monthly_limit || 0} credits/month</div>
            <button onClick={() => nav('/settings')} className="text-primary text-[12px] font-semibold mt-2 block">Upgrade →</button>
          </div>
        </div>
      </div>
    </div>
  );
}

function KPI({ label, value, color }) {
  return (
    <div className="bg-s1 border border-border rounded-xl p-3">
      <div className="text-[10px] text-gray-500 uppercase tracking-wide">{label}</div>
      <div className="font-heading text-xl font-bold" style={{ color }}>{value}</div>
    </div>
  );
}

function StatusDot({ status }) {
  const colors = { executing: '#F59E0B', completed: '#00E5A8', failed: '#F87171', draft: '#3B82F6', cancelled: '#6B7280' };
  return <div className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ background: colors[status] || colors.draft }} />;
}
