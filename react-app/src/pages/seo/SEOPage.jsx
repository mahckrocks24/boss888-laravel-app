import React, { useEffect, useState } from 'react';
import * as api from '../../services/api';

const TOOLS = [
  { name: 'SERP Analysis', icon: '🔍', fn: 'serpAnalysis' }, { name: 'AI Report', icon: '📊', fn: 'aiReport' },
  { name: 'Deep Audit', icon: '🔬', fn: 'deepAudit' }, { name: 'Link Suggestions', icon: '🔗', fn: 'links' },
];

export default function SEOPage() {
  const [tab, setTab] = useState('tools');
  const [keywords, setKeywords] = useState([]);
  const [audits, setAudits] = useState([]);
  const [goals, setGoals] = useState([]);

  useEffect(() => {
    if (tab === 'keywords') api.seo.keywords().then(d => setKeywords(d || []));
    if (tab === 'audits') api.seo.audits().then(d => setAudits(d || []));
    if (tab === 'goals') api.seo.goals().then(d => setGoals(d || []));
  }, [tab]);

  const runTool = async (fn) => {
    const url = prompt('Enter URL:'); if (!url) return;
    await api.seo[fn]({ url, keyword: url }); alert('Analysis started — check Audits');
  };

  return (
    <div>
      <div className="mb-6"><h1 className="font-heading text-xl font-bold">📈 SEO Engine</h1><p className="text-gray-500 text-sm">15 tools for search optimization</p></div>
      <div className="flex gap-1 mb-4 bg-s1 rounded-lg p-1 border border-border">
        {['tools', 'keywords', 'audits', 'goals'].map(t => <button key={t} onClick={() => setTab(t)} className={`px-4 py-1.5 rounded-md text-sm font-medium capitalize ${tab === t ? 'bg-primary/15 text-primary' : 'text-gray-400'}`}>{t}</button>)}
      </div>
      {tab === 'tools' && <div className="grid grid-cols-2 md:grid-cols-4 gap-4">{TOOLS.map(t => <button key={t.fn} onClick={() => runTool(t.fn)} className="bg-s1 border border-border rounded-xl p-5 text-left hover:border-primary/50"><div className="text-2xl mb-2">{t.icon}</div><div className="font-heading font-bold text-sm">{t.name}</div></button>)}</div>}
      {tab === 'keywords' && <div className="bg-s1 border border-border rounded-xl p-4"><button onClick={() => { const k = prompt('Keyword:'); if (k) api.seo.addKeyword({ keyword: k }).then(() => api.seo.keywords().then(d => setKeywords(d || []))); }} className="bg-primary text-white text-sm px-3 py-1.5 rounded-lg mb-3">+ Track</button>{keywords.map(k => <div key={k.id} className="flex justify-between py-2 border-b border-s2"><span>{k.keyword}</span><span className="text-gray-500">Vol: {k.volume || '—'}</span></div>)}</div>}
      {tab === 'audits' && <div className="bg-s1 border border-border rounded-xl p-4">{audits.length ? audits.map(a => <div key={a.id} className="flex justify-between py-2 border-b border-s2"><span className="text-sm">{a.url}</span><span className="text-[11px] px-2 py-0.5 rounded-full bg-blue/10 text-blue">{a.status}</span></div>) : <div className="text-gray-500 text-center py-8">No audits yet</div>}</div>}
      {tab === 'goals' && <div className="bg-s1 border border-border rounded-xl p-4"><button onClick={() => { const t = prompt('Goal:'); if (t) api.seo.createGoal({ title: t }).then(() => api.seo.goals().then(d => setGoals(d || []))); }} className="bg-primary text-white text-sm px-3 py-1.5 rounded-lg mb-3">+ Goal</button>{goals.map(g => <div key={g.id} className="flex justify-between py-2 border-b border-s2"><span>{g.title}</span><span className="text-[11px] px-2 py-0.5 rounded-full bg-accent/10 text-accent">{g.status}</span></div>)}</div>}
    </div>
  );
}
