import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as api from '../../services/api';

export default function WritePage() {
  const [articles, setArticles] = useState([]);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ title: '', type: 'blog_post', content: '' });
  const nav = useNavigate();

  useEffect(() => { api.write.articles().then(d => setArticles(d.articles || [])); }, []);

  const save = async () => { await api.write.createArticle(form); setShowForm(false); api.write.articles().then(d => setArticles(d.articles || [])); };

  return (
    <div>
      <div className="flex justify-between items-start mb-6">
        <div><h1 className="font-heading text-xl font-bold">✍️ Write Engine</h1></div>
        <button onClick={() => setShowForm(true)} className="bg-primary text-white text-sm font-bold px-4 py-2 rounded-lg">+ New Article</button>
      </div>
      {showForm && <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
        <input value={form.title} onChange={e => setForm({...form, title: e.target.value})} placeholder="Article title" className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm mb-3" />
        <textarea value={form.content} onChange={e => setForm({...form, content: e.target.value})} rows={6} placeholder="Write content..." className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm mb-3 resize-none" />
        <div className="flex gap-2"><button onClick={save} className="bg-primary text-white text-sm px-4 py-2 rounded-lg">Save Draft</button><button onClick={() => nav(`/editor/content/new`)} className="bg-accent/10 text-accent text-sm px-4 py-2 rounded-lg">Open Editor</button><button onClick={() => setShowForm(false)} className="text-gray-400 text-sm">Cancel</button></div>
      </div>}
      <div className="bg-s1 border border-border rounded-xl overflow-hidden">
        <table className="w-full text-sm"><thead><tr className="text-[11px] text-gray-500 uppercase border-b border-border"><th className="text-left px-4 py-3">Title</th><th className="text-left px-4 py-3">Type</th><th className="text-left px-4 py-3">Words</th><th className="text-left px-4 py-3">Status</th></tr></thead>
        <tbody>{articles.map(a => <tr key={a.id} className="border-b border-s2 hover:bg-s2 cursor-pointer" onClick={() => nav(`/editor/content/${a.id}`)}><td className="px-4 py-3 font-medium">{a.title}</td><td className="px-4 py-3 text-gray-400">{a.type}</td><td className="px-4 py-3">{a.word_count}</td><td className="px-4 py-3"><span className="text-[11px] px-2 py-0.5 rounded-full bg-blue/10 text-blue">{a.status}</span></td></tr>)}</tbody></table>
        {!articles.length && <div className="text-center text-gray-500 py-8">No articles yet</div>}
      </div>
    </div>
  );
}
