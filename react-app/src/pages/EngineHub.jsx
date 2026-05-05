import React from 'react';
import { useNavigate } from 'react-router-dom';

const ENGINES = [
  { icon: '🏗️', name: 'Website Builder', color: '#3B82F6', route: '/builder', desc: 'Generate, edit, and publish AI-built websites.' },
  { icon: '📈', name: 'SEO Engine', color: '#7C3AED', route: '/seo', desc: 'Keyword research and technical SEO.' },
  { icon: '✍️', name: 'Write Engine', color: '#A78BFA', route: '/write', desc: 'AI-generated articles and marketing copy.' },
  { icon: '🎨', name: 'Creative Engine', color: '#F87171', route: '/creative', desc: 'AI image and video generation.' },
  { icon: '🤝', name: 'CRM', color: '#00E5A8', route: '/crm', desc: 'Lead management and pipeline tracking.' },
  { icon: '📣', name: 'Marketing', color: '#F59E0B', route: '/marketing', desc: 'Email campaigns and automation.' },
  { icon: '📱', name: 'Social Media', color: '#EC4899', route: '/social', desc: 'Content creation and scheduling.' },
  { icon: '📅', name: 'Calendar', color: '#3B82F6', route: '/calendar', desc: 'Unified marketing schedule.' },
];

export default function EngineHub() {
  const nav = useNavigate();
  return (
    <div>
      <div className="mb-6"><h1 className="font-heading text-xl font-bold">Engine Hub</h1><p className="text-gray-500 text-sm">AI-powered marketing engines</p></div>
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {ENGINES.map(e => (
          <button key={e.route} onClick={() => nav(e.route)} className="bg-s1 border border-border rounded-xl p-5 text-left hover:border-gray-600 transition-colors group">
            <div className="text-3xl mb-3">{e.icon}</div>
            <div className="font-heading font-bold text-sm mb-1" style={{ color: e.color }}>{e.name}</div>
            <div className="text-[12px] text-gray-500 leading-relaxed">{e.desc}</div>
            <div className="mt-3 text-[11px] font-semibold text-gray-500 group-hover:text-primary transition-colors">Open →</div>
          </button>
        ))}
      </div>
    </div>
  );
}
