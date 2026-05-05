import React, { useEffect, useState, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import * as api from '../../services/api';

export default function ContentEditor() {
  const { id } = useParams();
  const nav = useNavigate();
  const editorRef = useRef(null);
  const [article, setArticle] = useState({ title: '', content: '', type: 'blog_post', status: 'draft' });
  const [seo, setSeo] = useState({ title: '', description: '', keywords: '' });
  const [saving, setSaving] = useState(false);
  const [wordCount, setWordCount] = useState(0);
  const [showSeo, setShowSeo] = useState(false);

  useEffect(() => {
    if (id && id !== 'new') {
      api.write.getArticle(id).then(a => {
        if (a) {
          setArticle({ title: a.title || '', content: a.content || '', type: a.type || 'blog_post', status: a.status || 'draft' });
          if (a.seo_json) setSeo(typeof a.seo_json === 'string' ? JSON.parse(a.seo_json) : a.seo_json);
        }
      });
    }
  }, [id]);

  useEffect(() => {
    setWordCount(article.content ? article.content.split(/\s+/).filter(Boolean).length : 0);
  }, [article.content]);

  const save = async () => {
    setSaving(true);
    try {
      if (id && id !== 'new') {
        await api.write.updateArticle(id, { ...article, seo_json: seo });
      } else {
        const res = await api.write.createArticle({ ...article, seo_json: seo });
        if (res.article_id) nav(`/editor/content/${res.article_id}`, { replace: true });
      }
      alert('Saved!');
    } catch (e) { alert('Error: ' + e.message); }
    setSaving(false);
  };

  const TOOLBAR_ACTIONS = [
    { label: 'B', cmd: 'bold', style: 'font-bold' },
    { label: 'I', cmd: 'italic', style: 'italic' },
    { label: 'U', cmd: 'underline', style: 'underline' },
    { label: 'H1', cmd: () => document.execCommand('formatBlock', false, 'h1') },
    { label: 'H2', cmd: () => document.execCommand('formatBlock', false, 'h2') },
    { label: 'H3', cmd: () => document.execCommand('formatBlock', false, 'h3') },
    { label: '• List', cmd: 'insertUnorderedList' },
    { label: '1. List', cmd: 'insertOrderedList' },
    { label: '""', cmd: () => document.execCommand('formatBlock', false, 'blockquote') },
    { label: '🔗', cmd: () => { const url = prompt('Link URL:'); if (url) document.execCommand('createLink', false, url); } },
  ];

  const execCmd = (action) => {
    if (typeof action.cmd === 'function') action.cmd();
    else document.execCommand(action.cmd, false, null);
    // Sync content
    if (editorRef.current) setArticle(prev => ({ ...prev, content: editorRef.current.innerHTML }));
  };

  return (
    <div className="h-screen bg-bg flex flex-col">
      {/* Top bar */}
      <div className="h-12 bg-s1 border-b border-border flex items-center justify-between px-4 flex-shrink-0">
        <div className="flex items-center gap-3">
          <button onClick={() => nav('/write')} className="text-gray-400 hover:text-gray-200 text-sm">← Back</button>
          <input value={article.title} onChange={e => setArticle({ ...article, title: e.target.value })} placeholder="Article title..." className="bg-transparent font-heading font-bold text-sm text-gray-200 outline-none w-64" />
        </div>
        <div className="flex items-center gap-3">
          <span className="text-[11px] text-gray-500">{wordCount} words</span>
          <select value={article.status} onChange={e => setArticle({ ...article, status: e.target.value })} className="bg-s2 border border-border rounded text-[11px] px-2 py-1 text-gray-400">
            <option value="draft">Draft</option><option value="review">Review</option><option value="approved">Approved</option><option value="published">Published</option>
          </select>
          <button onClick={() => setShowSeo(!showSeo)} className="text-gray-400 hover:text-gray-200 text-[11px] border border-border rounded px-2 py-1">SEO</button>
          <button onClick={save} disabled={saving} className="bg-primary text-white text-xs font-bold px-3 py-1.5 rounded-lg">{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </div>

      {/* Formatting toolbar */}
      <div className="h-10 bg-s1 border-b border-border flex items-center gap-1 px-4 flex-shrink-0">
        {TOOLBAR_ACTIONS.map(a => (
          <button key={a.label} onClick={() => execCmd(a)} className={`px-2 py-1 rounded text-[12px] text-gray-400 hover:bg-s2 hover:text-gray-200 ${a.style || ''}`}>{a.label}</button>
        ))}
      </div>

      <div className="flex-1 flex overflow-hidden">
        {/* Editor */}
        <div className="flex-1 overflow-y-auto flex justify-center py-8">
          <div className="w-full max-w-3xl">
            <div ref={editorRef} contentEditable suppressContentEditableWarning
              onInput={() => { if (editorRef.current) setArticle(prev => ({ ...prev, content: editorRef.current.innerHTML })); }}
              dangerouslySetInnerHTML={{ __html: article.content }}
              className="min-h-[500px] bg-s1 border border-border rounded-xl p-8 text-gray-200 text-[15px] leading-relaxed outline-none prose prose-invert prose-headings:font-heading"
              style={{ fontFamily: "'Inter', sans-serif" }}
            />
          </div>
        </div>

        {/* SEO Panel */}
        {showSeo && (
          <div className="w-72 bg-s1 border-l border-border p-4 overflow-y-auto flex-shrink-0">
            <h3 className="font-heading font-bold text-sm mb-4">SEO Settings</h3>
            <div className="space-y-3">
              <div><label className="text-[11px] text-gray-500 block mb-1">SEO Title</label><input value={seo.title} onChange={e => setSeo({ ...seo, title: e.target.value })} className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm" /></div>
              <div><label className="text-[11px] text-gray-500 block mb-1">Meta Description</label><textarea value={seo.description} onChange={e => setSeo({ ...seo, description: e.target.value })} rows={3} className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm resize-none" /></div>
              <div><label className="text-[11px] text-gray-500 block mb-1">Keywords</label><input value={seo.keywords} onChange={e => setSeo({ ...seo, keywords: e.target.value })} placeholder="keyword1, keyword2" className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-sm" /></div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
