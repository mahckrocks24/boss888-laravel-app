import React, { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import * as api from '../../services/api';

// Element types available in the builder
const ELEMENT_TYPES = [
  { type: 'hero', icon: '🎯', label: 'Hero Section' },
  { type: 'text', icon: '📝', label: 'Text Block' },
  { type: 'image', icon: '🖼️', label: 'Image' },
  { type: 'features', icon: '⭐', label: 'Features Grid' },
  { type: 'cta', icon: '📣', label: 'Call to Action' },
  { type: 'testimonials', icon: '💬', label: 'Testimonials' },
  { type: 'pricing', icon: '💎', label: 'Pricing Table' },
  { type: 'faq', icon: '❓', label: 'FAQ' },
  { type: 'gallery', icon: '🖼️', label: 'Gallery' },
  { type: 'contact_form', icon: '📧', label: 'Contact Form' },
  { type: 'map', icon: '📍', label: 'Map' },
  { type: 'spacer', icon: '↕️', label: 'Spacer' },
  { type: 'video', icon: '🎬', label: 'Video Embed' },
  { type: 'button', icon: '🔘', label: 'Button' },
  { type: 'html', icon: '🔧', label: 'Custom HTML' },
];

// Viewport options
const VIEWPORTS = [
  { key: 'desktop', icon: '🖥️', width: '100%' },
  { key: 'tablet', icon: '📱', width: '768px' },
  { key: 'mobile', icon: '📲', width: '375px' },
];

export default function BuilderEditor() {
  const { id } = useParams();
  const nav = useNavigate();
  const [website, setWebsite] = useState(null);
  const [pages, setPages] = useState([]);
  const [activePage, setActivePage] = useState(null);
  const [sections, setSections] = useState([]);
  const [viewport, setViewport] = useState('desktop');
  const [selectedSection, setSelectedSection] = useState(null);
  const [showPanel, setShowPanel] = useState('elements'); // elements | settings | seo
  const [saving, setSaving] = useState(false);
  const [dragOver, setDragOver] = useState(null);

  useEffect(() => {
    if (id) {
      api.builder.getWebsite(id).then(w => { if (w) setWebsite(w); });
      api.builder.pages(id).then(p => {
        setPages(p || []);
        if (p?.length) {
          setActivePage(p[0]);
          setSections(p[0].sections_json ? (typeof p[0].sections_json === 'string' ? JSON.parse(p[0].sections_json) : p[0].sections_json) : []);
        }
      });
    }
  }, [id]);

  const addSection = (type) => {
    const newSection = {
      id: 'sec_' + Date.now(),
      type,
      content: getDefaultContent(type),
      settings: { padding: '60px 0', background: 'transparent' },
    };
    setSections(prev => [...prev, newSection]);
  };

  const moveSection = (fromIndex, toIndex) => {
    setSections(prev => {
      const next = [...prev];
      const [moved] = next.splice(fromIndex, 1);
      next.splice(toIndex, 0, moved);
      return next;
    });
  };

  const deleteSection = (index) => {
    setSections(prev => prev.filter((_, i) => i !== index));
    setSelectedSection(null);
  };

  const updateSectionContent = (index, content) => {
    setSections(prev => prev.map((s, i) => i === index ? { ...s, content: { ...s.content, ...content } } : s));
  };

  const save = async () => {
    if (!activePage) return;
    setSaving(true);
    try {
      await api.builder.updatePage(activePage.id, { sections: sections });
      alert('Page saved!');
    } catch (e) { alert('Error: ' + e.message); }
    setSaving(false);
  };

  const switchPage = (page) => {
    setActivePage(page);
    setSections(page.sections_json ? (typeof page.sections_json === 'string' ? JSON.parse(page.sections_json) : page.sections_json) : []);
    setSelectedSection(null);
  };

  const vp = VIEWPORTS.find(v => v.key === viewport) || VIEWPORTS[0];

  return (
    <div className="h-screen bg-bg flex flex-col">
      {/* Top bar */}
      <div className="h-12 bg-s1 border-b border-border flex items-center justify-between px-4 flex-shrink-0">
        <div className="flex items-center gap-3">
          <button onClick={() => nav('/builder')} className="text-gray-400 hover:text-gray-200 text-sm">← Back</button>
          <span className="font-heading font-bold text-sm text-primary">{website?.name || 'Builder'}</span>
          {/* Page tabs */}
          <div className="flex gap-1 ml-4">
            {pages.map(p => (
              <button key={p.id} onClick={() => switchPage(p)}
                className={`px-3 py-1 rounded text-[11px] ${activePage?.id === p.id ? 'bg-primary/15 text-primary font-semibold' : 'text-gray-500 hover:text-gray-300'}`}>
                {p.title}
              </button>
            ))}
          </div>
        </div>
        <div className="flex items-center gap-2">
          {/* Viewport switcher */}
          <div className="flex gap-1 bg-s2 rounded-lg p-0.5">
            {VIEWPORTS.map(v => (
              <button key={v.key} onClick={() => setViewport(v.key)}
                className={`w-8 h-7 flex items-center justify-center rounded text-sm ${viewport === v.key ? 'bg-primary/20 text-primary' : 'text-gray-500'}`}>
                {v.icon}
              </button>
            ))}
          </div>
          <button onClick={save} disabled={saving} className="bg-primary text-white text-xs font-bold px-3 py-1.5 rounded-lg">{saving ? 'Saving…' : 'Save'}</button>
          <button onClick={() => api.builder.publish(id).then(() => alert('Published!'))} className="bg-accent/10 text-accent text-xs font-bold px-3 py-1.5 rounded-lg">Publish</button>
        </div>
      </div>

      <div className="flex-1 flex overflow-hidden">
        {/* Left panel — elements / settings */}
        <div className="w-56 bg-s1 border-r border-border flex flex-col flex-shrink-0">
          <div className="flex border-b border-border">
            <button onClick={() => setShowPanel('elements')} className={`flex-1 py-2 text-[11px] font-semibold ${showPanel === 'elements' ? 'text-primary border-b-2 border-primary' : 'text-gray-500'}`}>Elements</button>
            <button onClick={() => setShowPanel('settings')} className={`flex-1 py-2 text-[11px] font-semibold ${showPanel === 'settings' ? 'text-primary border-b-2 border-primary' : 'text-gray-500'}`}>Settings</button>
          </div>
          <div className="flex-1 overflow-y-auto p-3 space-y-1">
            {showPanel === 'elements' && ELEMENT_TYPES.map(el => (
              <button key={el.type} onClick={() => addSection(el.type)}
                className="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-[12px] text-gray-400 hover:bg-s2 hover:text-gray-200 transition-colors">
                <span>{el.icon}</span>{el.label}
              </button>
            ))}
            {showPanel === 'settings' && selectedSection !== null && (
              <div className="space-y-3">
                <h4 className="font-heading font-bold text-xs text-gray-300">Section Settings</h4>
                <div><label className="text-[10px] text-gray-500 block mb-1">Type</label><div className="text-sm text-gray-300 capitalize">{sections[selectedSection]?.type}</div></div>
                <div><label className="text-[10px] text-gray-500 block mb-1">Heading</label><input value={sections[selectedSection]?.content?.heading || ''} onChange={e => updateSectionContent(selectedSection, { heading: e.target.value })} className="w-full bg-s2 border border-border rounded px-2 py-1.5 text-[12px]" /></div>
                <div><label className="text-[10px] text-gray-500 block mb-1">Text</label><textarea value={sections[selectedSection]?.content?.text || ''} onChange={e => updateSectionContent(selectedSection, { text: e.target.value })} rows={3} className="w-full bg-s2 border border-border rounded px-2 py-1.5 text-[12px] resize-none" /></div>
                <button onClick={() => deleteSection(selectedSection)} className="w-full text-red text-[11px] bg-red/10 rounded py-1.5">Delete Section</button>
              </div>
            )}
          </div>
        </div>

        {/* Canvas area */}
        <div className="flex-1 bg-s2 overflow-y-auto flex justify-center py-8">
          <div style={{ width: vp.width, maxWidth: '100%', transition: 'width 0.3s' }} className="bg-white rounded-lg shadow-2xl overflow-hidden min-h-[600px]">
            {sections.length === 0 && (
              <div className="flex flex-col items-center justify-center h-96 text-gray-400">
                <div className="text-4xl mb-4">🏗️</div>
                <div className="text-lg font-heading font-bold mb-2" style={{ color: '#333' }}>Start Building</div>
                <div className="text-sm" style={{ color: '#666' }}>Click elements in the left panel to add sections</div>
              </div>
            )}
            {sections.map((section, idx) => (
              <div key={section.id} onClick={() => { setSelectedSection(idx); setShowPanel('settings'); }}
                onDragOver={(e) => { e.preventDefault(); setDragOver(idx); }}
                onDrop={() => { if (dragOver !== null && dragOver !== idx) moveSection(dragOver, idx); setDragOver(null); }}
                draggable onDragStart={() => setDragOver(idx)}
                className={`relative group cursor-pointer transition-all ${selectedSection === idx ? 'ring-2 ring-primary' : 'hover:ring-1 hover:ring-blue/30'}`}
                style={{ padding: section.settings?.padding || '40px 20px' }}>
                {/* Section preview */}
                <div style={{ color: '#333', textAlign: 'center' }}>
                  <div className="text-2xl mb-2">{ELEMENT_TYPES.find(e => e.type === section.type)?.icon || '📦'}</div>
                  <div className="font-bold text-lg" style={{ fontFamily: 'Manrope' }}>{section.content?.heading || section.type.toUpperCase()}</div>
                  {section.content?.text && <div className="text-sm mt-2" style={{ color: '#666' }}>{section.content.text}</div>}
                </div>
                {/* Section controls (visible on hover) */}
                <div className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 flex gap-1">
                  <button onClick={(e) => { e.stopPropagation(); if (idx > 0) moveSection(idx, idx - 1); }} className="w-6 h-6 bg-gray-800 rounded text-white text-xs flex items-center justify-center">↑</button>
                  <button onClick={(e) => { e.stopPropagation(); if (idx < sections.length - 1) moveSection(idx, idx + 1); }} className="w-6 h-6 bg-gray-800 rounded text-white text-xs flex items-center justify-center">↓</button>
                  <button onClick={(e) => { e.stopPropagation(); deleteSection(idx); }} className="w-6 h-6 bg-red rounded text-white text-xs flex items-center justify-center">✕</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

function getDefaultContent(type) {
  const defaults = {
    hero: { heading: 'Welcome to Your Business', text: 'Professional services you can trust.', buttonText: 'Get Started', buttonUrl: '#' },
    text: { heading: 'About Us', text: 'Tell your story here. What makes your business special?' },
    features: { heading: 'Our Services', items: [{ title: 'Service 1', desc: 'Description' }, { title: 'Service 2', desc: 'Description' }, { title: 'Service 3', desc: 'Description' }] },
    cta: { heading: 'Ready to Get Started?', text: 'Contact us today.', buttonText: 'Contact Us', buttonUrl: '#contact' },
    testimonials: { heading: 'What Our Clients Say', items: [{ name: 'Client', quote: 'Great service!' }] },
    pricing: { heading: 'Pricing', items: [{ name: 'Basic', price: '$99', features: ['Feature 1', 'Feature 2'] }] },
    faq: { heading: 'FAQ', items: [{ q: 'Question?', a: 'Answer.' }] },
    contact_form: { heading: 'Contact Us', fields: ['name', 'email', 'message'] },
    image: { src: '', alt: 'Image' },
    gallery: { heading: 'Gallery', images: [] },
    spacer: { height: '60px' },
    video: { url: '', heading: '' },
    button: { text: 'Click Here', url: '#', style: 'primary' },
    html: { code: '<div>Custom HTML</div>' },
    map: { heading: 'Find Us', address: '' },
  };
  return defaults[type] || { heading: type };
}
