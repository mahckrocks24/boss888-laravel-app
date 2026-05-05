import React, { useEffect, useState } from 'react';
import * as api from '../../services/api';

export default function SocialPage() {
  const [posts, setPosts] = useState([]);
  useEffect(() => { api.social.posts().then(d => setPosts(d.posts || [])); }, []);
  return (
    <div>
      <div className="flex justify-between items-start mb-6"><div><h1 className="font-heading text-xl font-bold">📱 Social Media</h1></div>
        <button className="bg-primary text-white text-sm font-bold px-4 py-2 rounded-lg">+ New Post</button></div>
      <div className="bg-s1 border border-border rounded-xl overflow-hidden">
        <table className="w-full text-sm"><thead><tr className="text-[11px] text-gray-500 uppercase border-b border-border"><th className="text-left px-4 py-3">Content</th><th className="text-left px-4 py-3">Platform</th><th className="text-left px-4 py-3">Status</th></tr></thead>
        <tbody>{posts.map(p => <tr key={p.id} className="border-b border-s2"><td className="px-4 py-3 truncate max-w-xs">{p.content}</td><td className="px-4 py-3 text-gray-400">{p.platform}</td><td className="px-4 py-3"><span className="text-[11px] px-2 py-0.5 rounded-full bg-blue/10 text-blue">{p.status}</span></td></tr>)}</tbody></table>
        {!posts.length && <div className="text-center text-gray-500 py-8">No posts yet</div>}
      </div>
    </div>
  );
}
