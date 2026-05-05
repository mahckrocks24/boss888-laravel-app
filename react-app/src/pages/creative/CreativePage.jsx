import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as api from '../../services/api';

export default function CreativePage() {
  const [assets, setAssets] = useState([]);
  const [showGen, setShowGen] = useState(false);
  const [prompt, setPrompt] = useState('');
  const [type, setType] = useState('image');
  const nav = useNavigate();

  useEffect(() => { api.creative.assets().then(d => setAssets(d.assets || [])); }, []);

  const generate = async () => { await api.creative.createAsset({ type, prompt }); setShowGen(false); api.creative.assets().then(d => setAssets(d.assets || [])); };

  return (
    <div>
      <div className="flex justify-between items-start mb-6">
        <div><h1 className="font-heading text-xl font-bold">🎨 Creative Engine</h1></div>
        <button onClick={() => setShowGen(true)} className="bg-primary text-white text-sm font-bold px-4 py-2 rounded-lg">+ Generate</button>
      </div>
      {showGen && <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
        <select value={type} onChange={e => setType(e.target.value)} className="bg-s2 border border-border rounded-lg px-3 py-2 text-sm mb-3 w-full"><option value="image">Image</option><option value="video">Video</option></select>
        <textarea value={prompt} onChange={e => setPrompt(e.target.value)} rows={3} placeholder="Describe what to create..." className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm mb-3 resize-none" />
        <div className="flex gap-2"><button onClick={generate} className="bg-primary text-white text-sm px-4 py-2 rounded-lg">Generate ✨</button><button onClick={() => setShowGen(false)} className="text-gray-400 text-sm">Cancel</button></div>
      </div>}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {assets.map(a => <div key={a.id} onClick={() => nav(`/editor/canvas/${a.id}`)} className="bg-s1 border border-border rounded-xl overflow-hidden cursor-pointer hover:border-primary/50">
          <div className="h-32 bg-s2 flex items-center justify-center text-3xl">{a.type === 'video' ? '🎬' : '🖼️'}</div>
          <div className="p-3"><div className="text-sm font-medium truncate">{a.title || 'Untitled'}</div><div className="text-[11px] text-gray-500">{a.type} · {a.status}</div></div>
        </div>)}
      </div>
      {!assets.length && <div className="text-center text-gray-500 py-8">No assets yet. Generate your first.</div>}
    </div>
  );
}
