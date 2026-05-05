import React, { useEffect, useState } from 'react';
import * as api from '../../services/api';

export default function MarketingPage() {
  const [tab, setTab] = useState('campaigns');
  const [campaigns, setCampaigns] = useState([]);
  const [automations, setAutomations] = useState([]);

  useEffect(() => {
    if (tab === 'campaigns') api.marketing.campaigns().then(d => setCampaigns(d.campaigns || []));
    if (tab === 'automations') api.marketing.automations().then(d => setAutomations(d || []));
  }, [tab]);

  return (
    <div>
      <div className="mb-6"><h1 className="font-heading text-xl font-bold">📣 Marketing</h1></div>
      <div className="flex gap-1 mb-4 bg-s1 rounded-lg p-1 border border-border">
        {['campaigns', 'automations'].map(t => <button key={t} onClick={() => setTab(t)} className={`px-4 py-1.5 rounded-md text-sm font-medium capitalize ${tab === t ? 'bg-primary/15 text-primary' : 'text-gray-400'}`}>{t}</button>)}
      </div>
      {tab === 'campaigns' && <div className="bg-s1 border border-border rounded-xl p-4">{campaigns.length ? campaigns.map(c => <div key={c.id} className="flex justify-between py-2 border-b border-s2"><span>{c.name}</span><span className="text-[11px] px-2 py-0.5 rounded-full bg-blue/10 text-blue">{c.status}</span></div>) : <div className="text-gray-500 text-center py-8">No campaigns</div>}</div>}
      {tab === 'automations' && <div className="bg-s1 border border-border rounded-xl p-4">{automations.length ? automations.map(a => <div key={a.id} className="flex justify-between py-2 border-b border-s2"><span>{a.name}</span><span className="text-[11px] text-gray-500">{a.trigger_type}</span></div>) : <div className="text-gray-500 text-center py-8">No automations</div>}</div>}
    </div>
  );
}
