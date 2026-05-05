import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as api from '../../services/api';

export default function BuilderPage() {
  const [websites, setWebsites] = useState([]);
  const [showWizard, setShowWizard] = useState(false);
  const [wiz, setWiz] = useState({ business_name: '', industry: '', goal: 'leads' });
  const nav = useNavigate();

  useEffect(() => { api.builder.websites().then(d => setWebsites(d || [])); }, []);

  const runWizard = async () => { const r = await api.builder.wizard(wiz); alert(`Website created with ${r.pages_created} pages!`); setShowWizard(false); api.builder.websites().then(d => setWebsites(d || [])); };

  return (
    <div>
      <div className="flex justify-between items-start mb-6">
        <div><h1 className="font-heading text-xl font-bold">🏗️ Website Builder</h1></div>
        <button onClick={() => setShowWizard(true)} className="bg-primary text-white text-sm font-bold px-4 py-2 rounded-lg">+ New Website</button>
      </div>
      {showWizard && <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
        <h3 className="font-heading font-bold mb-3">🏗️ Arthur — Website Wizard</h3>
        <input value={wiz.business_name} onChange={e => setWiz({...wiz, business_name: e.target.value})} placeholder="Business name" className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm mb-3" />
        <input value={wiz.industry} onChange={e => setWiz({...wiz, industry: e.target.value})} placeholder="Industry" className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm mb-3" />
        <select value={wiz.goal} onChange={e => setWiz({...wiz, goal: e.target.value})} className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm mb-3"><option value="leads">Generate Leads</option><option value="brand">Build Brand</option><option value="ecommerce">Sell Products</option><option value="portfolio">Showcase Work</option></select>
        <div className="flex gap-2"><button onClick={runWizard} className="bg-primary text-white text-sm px-4 py-2 rounded-lg">Generate Website ✨</button><button onClick={() => setShowWizard(false)} className="text-gray-400 text-sm">Cancel</button></div>
      </div>}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {websites.map(w => <div key={w.id} onClick={() => nav(`/editor/builder/${w.id}`)} className="bg-s1 border border-border rounded-xl p-5 cursor-pointer hover:border-primary/50">
          <div className="font-heading font-bold mb-1">{w.name}</div><div className="text-[12px] text-gray-500">{w.domain || w.subdomain || 'No domain'}</div>
          <div className="mt-2"><span className="text-[11px] px-2 py-0.5 rounded-full bg-blue/10 text-blue">{w.status}</span></div>
        </div>)}
      </div>
      {!websites.length && <div className="text-center text-gray-500 py-8">No websites yet</div>}
    </div>
  );
}
