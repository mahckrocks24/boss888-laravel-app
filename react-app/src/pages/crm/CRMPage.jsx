import React, { useEffect, useState, useCallback, useRef } from 'react';
import { Routes, Route, useNavigate, useParams } from 'react-router-dom';
import * as api from '../../services/api';

// ═══════════════════════════════════════════════════════════════
// CRM Router
// ═══════════════════════════════════════════════════════════════
export default function CRMPage() {
  return (
    <Routes>
      <Route index element={<CRMMain />} />
      <Route path="lead/:id" element={<LeadDetail />} />
      <Route path="contact/:id" element={<ContactDetail />} />
      <Route path="deal/:id" element={<DealDetail />} />
    </Routes>
  );
}

// ═══════════════════════════════════════════════════════════════
// MAIN CRM VIEW WITH TABS
// ═══════════════════════════════════════════════════════════════
const TABS = ['leads', 'pipeline', 'contacts', 'deals', 'today', 'revenue', 'reporting'];

function CRMMain() {
  const [tab, setTab] = useState('leads');
  return (
    <div>
      <div className="flex justify-between items-start mb-4">
        <div><h1 className="font-heading text-xl font-bold">🤝 CRM</h1><p className="text-gray-500 text-sm">Lead management, pipeline & revenue</p></div>
      </div>
      <div className="flex gap-0.5 mb-5 bg-s1 rounded-lg p-1 border border-border overflow-x-auto">
        {TABS.map(t => <button key={t} onClick={() => setTab(t)} className={`px-3 py-1.5 rounded-md text-[13px] font-medium capitalize whitespace-nowrap transition-colors ${tab === t ? 'bg-primary/15 text-primary' : 'text-gray-500 hover:text-gray-300'}`}>{t}</button>)}
      </div>
      {tab === 'leads' && <LeadsView />}
      {tab === 'pipeline' && <PipelineView />}
      {tab === 'contacts' && <ContactsView />}
      {tab === 'deals' && <DealsView />}
      {tab === 'today' && <TodayView />}
      {tab === 'revenue' && <RevenueView />}
      {tab === 'reporting' && <ReportingView />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// LEADS VIEW — full filters, search, bulk, import/export
// ═══════════════════════════════════════════════════════════════
function LeadsView() {
  const nav = useNavigate();
  const [leads, setLeads] = useState([]);
  const [total, setTotal] = useState(0);
  const [sources, setSources] = useState({});
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const [showFilters, setShowFilters] = useState(false);
  const [filters, setFilters] = useState({ search: '', status: '', source: '', sort_by: 'created_at', sort_dir: 'desc' });
  const [form, setForm] = useState({ name: '', email: '', phone: '', company: '', source: '', website: '', city: '', country: '', deal_value: '' });

  const load = useCallback(() => {
    setLoading(true);
    const params = Object.entries(filters).filter(([,v]) => v).map(([k,v]) => `${k}=${encodeURIComponent(v)}`).join('&');
    api.crm.leads(params ? `?${params}` : '').then(d => {
      setLeads(d.leads || []); setTotal(d.total || 0); setSources(d.sources || {});
    }).finally(() => setLoading(false));
  }, [filters]);

  useEffect(() => { load(); }, [load]);

  const saveLead = async () => {
    if (!form.name.trim()) return;
    await api.crm.createLead(form);
    setShowForm(false); setForm({ name: '', email: '', phone: '', company: '', source: '', website: '', city: '', country: '', deal_value: '' });
    load();
  };

  const handleImport = async (text) => {
    const lines = text.trim().split('\n');
    const headers = lines[0].split(',').map(h => h.trim().toLowerCase());
    const rows = lines.slice(1).map(line => {
      const vals = line.split(',');
      const row = {};
      headers.forEach((h, i) => { row[h] = vals[i]?.trim() || ''; });
      return row;
    });
    const result = await api.crm.importLeads(rows);
    alert(`Imported: ${result.imported}, Skipped: ${result.skipped}`);
    setShowImport(false); load();
  };

  const handleExport = () => {
    api.crm.exportLeads(filters).then(csv => {
      if (typeof csv === 'string') {
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = `leads-${new Date().toISOString().split('T')[0]}.csv`; a.click();
      }
    });
  };

  const SC = { new: 'bg-blue/10 text-blue', contacted: 'bg-amber/10 text-amber', qualified: 'bg-purple/10 text-purple', converted: 'bg-accent/10 text-accent', lost: 'bg-red/10 text-red' };

  return (
    <div>
      {/* Action bar */}
      <div className="flex flex-wrap gap-2 mb-4">
        <button onClick={() => setShowForm(true)} className="bg-primary text-white text-[13px] font-bold px-4 py-2 rounded-lg">+ Add Lead</button>
        <button onClick={() => setShowImport(true)} className="bg-s1 border border-border text-gray-300 text-[13px] px-3 py-2 rounded-lg">Import CSV</button>
        <button onClick={handleExport} className="bg-s1 border border-border text-gray-300 text-[13px] px-3 py-2 rounded-lg">Export</button>
        <button onClick={() => setShowFilters(!showFilters)} className="bg-s1 border border-border text-gray-300 text-[13px] px-3 py-2 rounded-lg">{showFilters ? '✕ Filters' : '⚙ Filters'}</button>
        <div className="flex-1" />
        <input value={filters.search} onChange={e => setFilters({...filters, search: e.target.value})} placeholder="Search leads..." className="bg-s2 border border-border rounded-lg px-3 py-2 text-[13px] w-56" />
        <span className="text-[12px] text-gray-500 self-center">{total} leads</span>
      </div>

      {/* Filters panel */}
      {showFilters && (
        <div className="bg-s1 border border-border rounded-xl p-4 mb-4 grid grid-cols-2 md:grid-cols-4 gap-3">
          <div><label className="text-[10px] text-gray-500 block mb-1">Status</label><select value={filters.status} onChange={e => setFilters({...filters, status: e.target.value})} className="w-full bg-s2 border border-border rounded px-2 py-1.5 text-[12px]"><option value="">All</option>{['new','contacted','qualified','converted','lost'].map(s=><option key={s} value={s}>{s}</option>)}</select></div>
          <div><label className="text-[10px] text-gray-500 block mb-1">Source</label><select value={filters.source} onChange={e => setFilters({...filters, source: e.target.value})} className="w-full bg-s2 border border-border rounded px-2 py-1.5 text-[12px]"><option value="">All</option>{Object.keys(sources).map(s=><option key={s} value={s}>{s} ({sources[s]})</option>)}</select></div>
          <div><label className="text-[10px] text-gray-500 block mb-1">Sort By</label><select value={filters.sort_by} onChange={e => setFilters({...filters, sort_by: e.target.value})} className="w-full bg-s2 border border-border rounded px-2 py-1.5 text-[12px]"><option value="created_at">Created</option><option value="name">Name</option><option value="score">Score</option><option value="deal_value">Value</option></select></div>
          <div><label className="text-[10px] text-gray-500 block mb-1">Direction</label><select value={filters.sort_dir} onChange={e => setFilters({...filters, sort_dir: e.target.value})} className="w-full bg-s2 border border-border rounded px-2 py-1.5 text-[12px]"><option value="desc">Newest</option><option value="asc">Oldest</option></select></div>
        </div>
      )}

      {/* Import modal */}
      {showImport && <ImportModal onImport={handleImport} onClose={() => setShowImport(false)} />}

      {/* Add lead form */}
      {showForm && (
        <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
          <h3 className="font-heading font-bold text-sm mb-3">New Lead</h3>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
            {[['name','Name *'],['email','Email'],['phone','Phone'],['company','Company'],['source','Source'],['website','Website'],['city','City'],['country','Country'],['deal_value','Deal Value (AED)']].map(([k,l])=>
              <div key={k}><label className="text-[10px] text-gray-500 block mb-1">{l}</label><input value={form[k]} onChange={e=>setForm({...form,[k]:e.target.value})} className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-[13px]" /></div>
            )}
          </div>
          <div className="flex gap-2 mt-4"><button onClick={saveLead} className="bg-primary text-white text-[13px] font-bold px-4 py-2 rounded-lg">Save Lead</button><button onClick={() => setShowForm(false)} className="text-gray-400 text-[13px]">Cancel</button></div>
        </div>
      )}

      {/* Leads table */}
      <div className="bg-s1 border border-border rounded-xl overflow-hidden">
        <table className="w-full text-[13px]">
          <thead><tr className="text-[10px] text-gray-500 uppercase border-b border-border">
            <th className="text-left px-4 py-3">Name</th><th className="text-left px-4 py-3">Email</th><th className="text-left px-4 py-3">Company</th><th className="text-left px-4 py-3">Status</th><th className="text-left px-4 py-3">Score</th><th className="text-left px-4 py-3">Value</th><th className="text-left px-4 py-3">Source</th>
          </tr></thead>
          <tbody>{leads.map(l => (
            <tr key={l.id} onClick={() => nav(`/crm/lead/${l.id}`)} className="border-b border-s2 hover:bg-s2 cursor-pointer">
              <td className="px-4 py-3 font-semibold">{l.name}</td>
              <td className="px-4 py-3 text-gray-400">{l.email || '—'}</td>
              <td className="px-4 py-3 text-gray-400">{l.company || '—'}</td>
              <td className="px-4 py-3"><span className={`text-[11px] px-2 py-0.5 rounded-full ${SC[l.status] || 'bg-gray-500/10 text-gray-400'}`}>{l.status}</span></td>
              <td className="px-4 py-3"><ScoreBar score={l.score} /></td>
              <td className="px-4 py-3 text-accent">{l.deal_value > 0 ? `AED ${parseFloat(l.deal_value).toLocaleString()}` : '—'}</td>
              <td className="px-4 py-3 text-gray-500">{l.source || '—'}</td>
            </tr>
          ))}</tbody>
        </table>
        {!leads.length && !loading && <div className="text-center text-gray-500 py-12">No leads found</div>}
        {loading && <div className="text-center text-gray-500 py-12">Loading...</div>}
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// LEAD DETAIL — full timeline, activities, notes, deals
// ═══════════════════════════════════════════════════════════════
function LeadDetail() {
  const { id } = useParams();
  const nav = useNavigate();
  const [data, setData] = useState(null);
  const [detailTab, setDetailTab] = useState('timeline');
  const [noteText, setNoteText] = useState('');
  const [actForm, setActForm] = useState({ type: 'call', subject: '', description: '', scheduled_at: '' });
  const [showActForm, setShowActForm] = useState(false);
  const [editing, setEditing] = useState(false);
  const [editForm, setEditForm] = useState({});

  const load = useCallback(() => { api.crm.getLead(id).then(setData); }, [id]);
  useEffect(() => { load(); }, [load]);

  if (!data) return <div className="text-gray-500 py-8">Loading...</div>;
  const { lead, activities, notes, deals, timeline } = data;

  const saveNote = async () => {
    if (!noteText.trim()) return;
    await api.crm.addNote({ entity_type: 'Lead', entity_id: lead.id, body: noteText });
    setNoteText(''); load();
  };

  const saveActivity = async () => {
    await api.crm.logActivity({ ...actForm, entity_type: 'Lead', entity_id: lead.id });
    setShowActForm(false); setActForm({ type: 'call', subject: '', description: '', scheduled_at: '' }); load();
  };

  const saveEdit = async () => {
    await api.crm.updateLead(lead.id, editForm);
    setEditing(false); load();
  };

  const startEdit = () => { setEditForm({ name: lead.name, email: lead.email, phone: lead.phone, company: lead.company, source: lead.source, website: lead.website, city: lead.city, country: lead.country, status: lead.status }); setEditing(true); };

  const SC = { new: 'bg-blue/10 text-blue', contacted: 'bg-amber/10 text-amber', qualified: 'bg-purple/10 text-purple', converted: 'bg-accent/10 text-accent', lost: 'bg-red/10 text-red' };
  const DTABS = ['timeline', 'activities', 'notes', 'deals'];

  return (
    <div>
      <button onClick={() => nav('/crm')} className="text-gray-400 hover:text-gray-200 text-[13px] mb-4 inline-block">← Back to Leads</button>

      {/* Header */}
      <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
        <div className="flex justify-between items-start">
          <div>
            <h2 className="font-heading text-lg font-bold">{lead.name}</h2>
            <div className="flex items-center gap-3 mt-1 text-[13px] text-gray-400">
              {lead.email && <span>📧 {lead.email}</span>}
              {lead.phone && <span>📱 {lead.phone}</span>}
              {lead.company && <span>🏢 {lead.company}</span>}
            </div>
          </div>
          <div className="flex items-center gap-2">
            <span className={`text-[11px] px-3 py-1 rounded-full font-semibold ${SC[lead.status] || ''}`}>{lead.status}</span>
            <ScoreBar score={lead.score} size="lg" />
            <button onClick={startEdit} className="bg-s2 border border-border text-gray-300 text-[12px] px-3 py-1.5 rounded-lg">Edit</button>
            <button onClick={async () => { if (confirm('Delete?')) { await api.crm.deleteLead(lead.id); nav('/crm'); }}} className="bg-red/10 text-red text-[12px] px-3 py-1.5 rounded-lg">Delete</button>
          </div>
        </div>
        {/* Info grid */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
          {[['Source', lead.source], ['Website', lead.website], ['City', lead.city], ['Country', lead.country], ['Value', lead.deal_value > 0 ? `AED ${parseFloat(lead.deal_value).toLocaleString()}` : '—'], ['Created', lead.created_at ? new Date(lead.created_at).toLocaleDateString() : '—'], ['Last Contact', lead.last_contacted_at ? new Date(lead.last_contacted_at).toLocaleDateString() : 'Never'], ['Converted', lead.converted_at ? new Date(lead.converted_at).toLocaleDateString() : '—']].map(([l,v]) =>
            <div key={l}><div className="text-[10px] text-gray-500">{l}</div><div className="text-[13px] text-gray-300">{v || '—'}</div></div>
          )}
        </div>
      </div>

      {/* Edit form */}
      {editing && (
        <div className="bg-s1 border border-primary/30 rounded-xl p-5 mb-4">
          <h3 className="font-heading font-bold text-sm mb-3">Edit Lead</h3>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
            {Object.entries(editForm).map(([k, v]) => (
              <div key={k}><label className="text-[10px] text-gray-500 block mb-1 capitalize">{k.replace('_',' ')}</label>
                {k === 'status' ? <select value={v||''} onChange={e => setEditForm({...editForm, [k]: e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]">{['new','contacted','qualified','converted','lost'].map(s=><option key={s} value={s}>{s}</option>)}</select>
                : <input value={v||''} onChange={e => setEditForm({...editForm, [k]: e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]" />}
              </div>
            ))}
          </div>
          <div className="flex gap-2 mt-3"><button onClick={saveEdit} className="bg-primary text-white text-[13px] px-4 py-2 rounded-lg">Save</button><button onClick={() => setEditing(false)} className="text-gray-400 text-[13px]">Cancel</button></div>
        </div>
      )}

      {/* Detail tabs */}
      <div className="flex gap-0.5 mb-4 bg-s1 rounded-lg p-1 border border-border">
        {DTABS.map(t => <button key={t} onClick={() => setDetailTab(t)} className={`px-3 py-1.5 rounded-md text-[13px] font-medium capitalize ${detailTab === t ? 'bg-primary/15 text-primary' : 'text-gray-500'}`}>{t} {t === 'activities' ? `(${activities?.length || 0})` : t === 'notes' ? `(${notes?.length || 0})` : t === 'deals' ? `(${deals?.length || 0})` : ''}</button>)}
      </div>

      {/* Timeline */}
      {detailTab === 'timeline' && (
        <div className="space-y-2">
          {(timeline || []).map((t, i) => (
            <div key={i} className="flex gap-3 bg-s1 border border-border rounded-lg p-3">
              <div className="text-lg">{t.type === 'activity' ? '⚡' : t.type === 'note' ? '📝' : '💰'}</div>
              <div className="flex-1">
                <div className="text-[13px]">{t.description}</div>
                <div className="text-[11px] text-gray-500 mt-1">{t.type}{t.subtype ? ` · ${t.subtype}` : ''} · {t.date ? new Date(t.date).toLocaleString() : ''}</div>
              </div>
            </div>
          ))}
          {!(timeline?.length) && <div className="text-gray-500 text-center py-8">No timeline entries yet</div>}
        </div>
      )}

      {/* Activities */}
      {detailTab === 'activities' && (
        <div>
          <button onClick={() => setShowActForm(!showActForm)} className="bg-primary text-white text-[13px] px-3 py-1.5 rounded-lg mb-3">+ Log Activity</button>
          {showActForm && (
            <div className="bg-s1 border border-border rounded-xl p-4 mb-3">
              <div className="grid grid-cols-2 gap-3">
                <div><label className="text-[10px] text-gray-500 block mb-1">Type</label><select value={actForm.type} onChange={e=>setActForm({...actForm,type:e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]"><option value="call">Call</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="task">Task</option><option value="note">Note</option></select></div>
                <div><label className="text-[10px] text-gray-500 block mb-1">Subject</label><input value={actForm.subject} onChange={e=>setActForm({...actForm,subject:e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]" /></div>
                <div className="col-span-2"><label className="text-[10px] text-gray-500 block mb-1">Description</label><textarea value={actForm.description} onChange={e=>setActForm({...actForm,description:e.target.value})} rows={2} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px] resize-none" /></div>
                <div><label className="text-[10px] text-gray-500 block mb-1">Schedule for</label><input type="datetime-local" value={actForm.scheduled_at} onChange={e=>setActForm({...actForm,scheduled_at:e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]" /></div>
              </div>
              <div className="flex gap-2 mt-3"><button onClick={saveActivity} className="bg-primary text-white text-[13px] px-4 py-2 rounded-lg">Save</button><button onClick={()=>setShowActForm(false)} className="text-gray-400 text-[13px]">Cancel</button></div>
            </div>
          )}
          <div className="space-y-2">{(activities||[]).map(a => (
            <div key={a.id} className="bg-s1 border border-border rounded-lg p-3 flex justify-between items-center">
              <div><div className="text-[13px] font-medium">{a.subject || a.type}</div><div className="text-[12px] text-gray-500">{a.description}</div></div>
              <div className="text-[11px] text-gray-500">{a.created_at ? new Date(a.created_at).toLocaleDateString() : ''}</div>
            </div>
          ))}</div>
        </div>
      )}

      {/* Notes */}
      {detailTab === 'notes' && (
        <div>
          <div className="flex gap-2 mb-3">
            <textarea value={noteText} onChange={e => setNoteText(e.target.value)} placeholder="Add a note..." rows={2} className="flex-1 bg-s2 border border-border rounded-lg px-3 py-2 text-[13px] resize-none" />
            <button onClick={saveNote} className="bg-primary text-white text-[13px] px-4 py-2 rounded-lg self-end">Add</button>
          </div>
          <div className="space-y-2">{(notes||[]).map(n => (
            <div key={n.id} className="bg-s1 border border-border rounded-lg p-3 flex justify-between">
              <div className="text-[13px]">{n.body}</div>
              <div className="flex items-center gap-2">
                <span className="text-[11px] text-gray-500">{n.created_at ? new Date(n.created_at).toLocaleDateString() : ''}</span>
                <button onClick={async () => { await api.crm.deleteNote(n.id); load(); }} className="text-red text-[11px]">✕</button>
              </div>
            </div>
          ))}</div>
        </div>
      )}

      {/* Deals */}
      {detailTab === 'deals' && (
        <div>
          {(deals||[]).map(d => (
            <div key={d.id} onClick={() => nav(`/crm/deal/${d.id}`)} className="bg-s1 border border-border rounded-lg p-3 mb-2 flex justify-between items-center cursor-pointer hover:border-primary/30">
              <div><div className="text-[13px] font-semibold">{d.title}</div><div className="text-[12px] text-gray-500">Stage: {d.stage}</div></div>
              <div className="text-accent font-heading font-bold">{d.currency} {parseFloat(d.value||0).toLocaleString()}</div>
            </div>
          ))}
          {!(deals?.length) && <div className="text-gray-500 text-center py-8">No deals linked</div>}
        </div>
      )}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// PIPELINE KANBAN
// ═══════════════════════════════════════════════════════════════
function PipelineView() {
  const nav = useNavigate();
  const [pipeline, setPipeline] = useState([]);
  const [dragDeal, setDragDeal] = useState(null);

  useEffect(() => { api.crm.pipeline().then(d => setPipeline(d.pipeline || [])); }, []);

  const handleDrop = async (stageSlug) => {
    if (!dragDeal) return;
    await api.crm.updateDealStage(dragDeal.id, stageSlug);
    api.crm.pipeline().then(d => setPipeline(d.pipeline || []));
    setDragDeal(null);
  };

  return (
    <div className="flex gap-3 overflow-x-auto pb-4" style={{ minHeight: 400 }}>
      {pipeline.map(stage => (
        <div key={stage.slug} className="min-w-[280px] max-w-[280px] bg-s1 border border-border rounded-xl flex flex-col flex-shrink-0"
          onDragOver={e => e.preventDefault()} onDrop={() => handleDrop(stage.slug)}>
          <div className="px-4 py-3 flex justify-between items-center" style={{ borderBottom: `3px solid ${stage.color}` }}>
            <span className="font-heading font-bold text-[13px]">{stage.name}</span>
            <div className="flex items-center gap-2">
              <span className="text-[11px] text-gray-500 bg-s2 rounded-full px-2 py-0.5">{stage.deal_count}</span>
              {stage.probability > 0 && <span className="text-[10px] text-gray-500">{stage.probability}%</span>}
            </div>
          </div>
          <div className="flex-1 p-2 space-y-2 min-h-[150px]">
            {(stage.deals || []).map(deal => (
              <div key={deal.id} draggable onDragStart={() => setDragDeal(deal)}
                onClick={() => nav(`/crm/deal/${deal.id}`)}
                className="bg-s2 rounded-lg p-3 cursor-grab active:cursor-grabbing hover:bg-s3 transition-colors border border-transparent hover:border-primary/20">
                <div className="text-[13px] font-medium mb-1">{deal.title}</div>
                <div className="flex justify-between items-center">
                  <span className="text-[12px] text-accent font-heading font-bold">{deal.currency || 'AED'} {parseFloat(deal.value || 0).toLocaleString()}</span>
                  {deal.expected_close && <span className="text-[10px] text-gray-500">{new Date(deal.expected_close).toLocaleDateString()}</span>}
                </div>
              </div>
            ))}
          </div>
          <div className="px-4 py-2 border-t border-border text-[11px] text-gray-500">
            Total: AED {parseFloat(stage.total_value || 0).toLocaleString()}
          </div>
        </div>
      ))}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// CONTACTS VIEW — search, merge, duplicates
// ═══════════════════════════════════════════════════════════════
function ContactsView() {
  const nav = useNavigate();
  const [contacts, setContacts] = useState([]);
  const [search, setSearch] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [showDupes, setShowDupes] = useState(false);
  const [dupes, setDupes] = useState(null);
  const [form, setForm] = useState({ name: '', email: '', phone: '', company: '', position: '' });

  const load = () => { api.crm.contacts(search ? `?search=${encodeURIComponent(search)}` : '').then(d => setContacts(d.contacts || [])); };
  useEffect(() => { load(); }, [search]);

  const saveContact = async () => { await api.crm.createContact(form); setShowForm(false); setForm({ name: '', email: '', phone: '', company: '', position: '' }); load(); };
  const findDupes = async () => { const d = await api.crm.findDuplicates(); setDupes(d); setShowDupes(true); };
  const handleMerge = async (keepId, mergeId) => { await api.crm.mergeContacts(keepId, mergeId); findDupes(); load(); };

  return (
    <div>
      <div className="flex gap-2 mb-4">
        <button onClick={() => setShowForm(true)} className="bg-primary text-white text-[13px] font-bold px-4 py-2 rounded-lg">+ Add Contact</button>
        <button onClick={findDupes} className="bg-s1 border border-border text-gray-300 text-[13px] px-3 py-2 rounded-lg">Find Duplicates</button>
        <div className="flex-1" />
        <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search..." className="bg-s2 border border-border rounded-lg px-3 py-2 text-[13px] w-56" />
      </div>

      {showDupes && dupes && (
        <div className="bg-amber/5 border border-amber/30 rounded-xl p-4 mb-4">
          <h3 className="font-heading font-bold text-sm text-amber mb-2">Duplicates Found</h3>
          {[...(dupes.email_duplicates||[]), ...(dupes.phone_duplicates||[])].map((d, i) => (
            <div key={i} className="flex justify-between items-center py-2 border-b border-border last:border-0">
              <span className="text-[13px]">{d.email || d.phone} — {d.count} contacts (IDs: {d.ids.join(', ')})</span>
              {d.ids.length === 2 && <button onClick={() => handleMerge(d.ids[0], d.ids[1])} className="bg-primary text-white text-[11px] px-2 py-1 rounded">Merge</button>}
            </div>
          ))}
          {!(dupes.email_duplicates?.length || dupes.phone_duplicates?.length) && <div className="text-gray-500 text-[13px]">No duplicates found</div>}
          <button onClick={() => setShowDupes(false)} className="text-gray-400 text-[12px] mt-2">Close</button>
        </div>
      )}

      {showForm && (
        <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
            {Object.keys(form).map(k => <div key={k}><label className="text-[10px] text-gray-500 block mb-1 capitalize">{k}</label><input value={form[k]} onChange={e=>setForm({...form,[k]:e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]" /></div>)}
          </div>
          <div className="flex gap-2 mt-3"><button onClick={saveContact} className="bg-primary text-white text-[13px] px-4 py-2 rounded-lg">Save</button><button onClick={() => setShowForm(false)} className="text-gray-400 text-[13px]">Cancel</button></div>
        </div>
      )}

      <div className="bg-s1 border border-border rounded-xl overflow-hidden">
        <table className="w-full text-[13px]"><thead><tr className="text-[10px] text-gray-500 uppercase border-b border-border"><th className="text-left px-4 py-3">Name</th><th className="text-left px-4 py-3">Email</th><th className="text-left px-4 py-3">Phone</th><th className="text-left px-4 py-3">Company</th><th className="text-left px-4 py-3">Position</th></tr></thead>
        <tbody>{contacts.map(c => <tr key={c.id} onClick={() => nav(`/crm/contact/${c.id}`)} className="border-b border-s2 hover:bg-s2 cursor-pointer"><td className="px-4 py-3 font-semibold">{c.name}</td><td className="px-4 py-3 text-gray-400">{c.email||'—'}</td><td className="px-4 py-3 text-gray-400">{c.phone||'—'}</td><td className="px-4 py-3 text-gray-400">{c.company||'—'}</td><td className="px-4 py-3 text-gray-500">{c.position||'—'}</td></tr>)}</tbody></table>
        {!contacts.length && <div className="text-center text-gray-500 py-8">No contacts</div>}
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// CONTACT + DEAL DETAIL
// ═══════════════════════════════════════════════════════════════
function ContactDetail() {
  const { id } = useParams(); const nav = useNavigate();
  const [data, setData] = useState(null);
  useEffect(() => { api.crm.getContact(id).then(setData); }, [id]);
  if (!data) return <div className="text-gray-500 py-8">Loading...</div>;
  const { contact, deals, activities } = data;
  return (
    <div>
      <button onClick={() => nav('/crm')} className="text-gray-400 text-[13px] mb-4 inline-block">← Back</button>
      <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
        <h2 className="font-heading text-lg font-bold">{contact.name}</h2>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3 text-[13px]">
          {[['Email',contact.email],['Phone',contact.phone],['Company',contact.company],['Position',contact.position]].map(([l,v]) => <div key={l}><div className="text-[10px] text-gray-500">{l}</div><div className="text-gray-300">{v||'—'}</div></div>)}
        </div>
      </div>
      <h3 className="font-heading font-bold text-sm mb-2">Deals ({deals?.length || 0})</h3>
      {(deals||[]).map(d => <div key={d.id} onClick={() => nav(`/crm/deal/${d.id}`)} className="bg-s1 border border-border rounded-lg p-3 mb-2 cursor-pointer hover:bg-s2"><div className="font-semibold text-[13px]">{d.title}</div><div className="text-accent text-[12px]">{d.currency} {parseFloat(d.value).toLocaleString()} · {d.stage}</div></div>)}
      <h3 className="font-heading font-bold text-sm mb-2 mt-4">Activities ({activities?.length || 0})</h3>
      {(activities||[]).map(a => <div key={a.id} className="bg-s1 border border-border rounded-lg p-3 mb-2"><div className="text-[13px]">{a.subject || a.type}: {a.description}</div></div>)}
    </div>
  );
}

function DealDetail() {
  const { id } = useParams(); const nav = useNavigate();
  const [data, setData] = useState(null);
  useEffect(() => { api.crm.getDeal(id).then(setData); }, [id]);
  if (!data) return <div className="text-gray-500 py-8">Loading...</div>;
  const { deal, lead, contact, activities, notes } = data;
  return (
    <div>
      <button onClick={() => nav('/crm')} className="text-gray-400 text-[13px] mb-4 inline-block">← Back</button>
      <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
        <div className="flex justify-between items-start">
          <div><h2 className="font-heading text-lg font-bold">{deal.title}</h2><div className="text-accent font-heading text-xl font-bold mt-1">{deal.currency} {parseFloat(deal.value).toLocaleString()}</div></div>
          <span className="text-[11px] px-3 py-1 rounded-full bg-blue/10 text-blue font-semibold">{deal.stage}</span>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 text-[13px]">
          {[['Probability',`${deal.probability}%`],['Expected Close',deal.expected_close||'—'],['Lead',lead?.name||'—'],['Contact',contact?.name||'—']].map(([l,v]) => <div key={l}><div className="text-[10px] text-gray-500">{l}</div><div className="text-gray-300">{v}</div></div>)}
        </div>
      </div>
      <h3 className="font-heading font-bold text-sm mb-2">Activities</h3>
      {(activities||[]).map(a => <div key={a.id} className="bg-s1 border border-border rounded-lg p-3 mb-2"><div className="text-[13px]">{a.subject||a.type}: {a.description}</div></div>)}
      {!(activities?.length) && <div className="text-gray-500 text-[13px]">No activities</div>}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// DEALS VIEW
// ═══════════════════════════════════════════════════════════════
function DealsView() {
  const nav = useNavigate();
  const [deals, setDeals] = useState([]);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ title: '', value: '', stage: 'discovery', expected_close: '' });

  useEffect(() => { api.crm.deals().then(d => setDeals(d.deals || [])); }, []);

  const saveDeal = async () => { await api.crm.createDeal(form); setShowForm(false); setForm({ title: '', value: '', stage: 'discovery', expected_close: '' }); api.crm.deals().then(d => setDeals(d.deals||[])); };

  return (
    <div>
      <button onClick={() => setShowForm(true)} className="bg-primary text-white text-[13px] font-bold px-4 py-2 rounded-lg mb-4">+ New Deal</button>
      {showForm && (
        <div className="bg-s1 border border-border rounded-xl p-5 mb-4 grid grid-cols-2 gap-3">
          <div><label className="text-[10px] text-gray-500 block mb-1">Title *</label><input value={form.title} onChange={e=>setForm({...form,title:e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]" /></div>
          <div><label className="text-[10px] text-gray-500 block mb-1">Value (AED) *</label><input type="number" value={form.value} onChange={e=>setForm({...form,value:e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]" /></div>
          <div><label className="text-[10px] text-gray-500 block mb-1">Stage</label><select value={form.stage} onChange={e=>setForm({...form,stage:e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]"><option value="discovery">Discovery</option><option value="proposal">Proposal</option><option value="negotiation">Negotiation</option></select></div>
          <div><label className="text-[10px] text-gray-500 block mb-1">Expected Close</label><input type="date" value={form.expected_close} onChange={e=>setForm({...form,expected_close:e.target.value})} className="w-full bg-s2 border border-border rounded px-3 py-2 text-[13px]" /></div>
          <div className="col-span-2 flex gap-2"><button onClick={saveDeal} className="bg-primary text-white text-[13px] px-4 py-2 rounded-lg">Save</button><button onClick={() => setShowForm(false)} className="text-gray-400 text-[13px]">Cancel</button></div>
        </div>
      )}
      <div className="bg-s1 border border-border rounded-xl overflow-hidden">
        <table className="w-full text-[13px]"><thead><tr className="text-[10px] text-gray-500 uppercase border-b border-border"><th className="text-left px-4 py-3">Title</th><th className="text-left px-4 py-3">Value</th><th className="text-left px-4 py-3">Stage</th><th className="text-left px-4 py-3">Probability</th><th className="text-left px-4 py-3">Close Date</th></tr></thead>
        <tbody>{deals.map(d => <tr key={d.id} onClick={() => nav(`/crm/deal/${d.id}`)} className="border-b border-s2 hover:bg-s2 cursor-pointer"><td className="px-4 py-3 font-semibold">{d.title}</td><td className="px-4 py-3 text-accent">AED {parseFloat(d.value||0).toLocaleString()}</td><td className="px-4 py-3"><span className="text-[11px] px-2 py-0.5 rounded-full bg-blue/10 text-blue">{d.stage}</span></td><td className="px-4 py-3">{d.probability}%</td><td className="px-4 py-3 text-gray-500">{d.expected_close||'—'}</td></tr>)}</tbody></table>
        {!deals.length && <div className="text-center text-gray-500 py-8">No deals</div>}
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// TODAY VIEW
// ═══════════════════════════════════════════════════════════════
function TodayView() {
  const [data, setData] = useState(null);
  useEffect(() => { api.crm.today().then(setData); }, []);
  if (!data) return <div className="text-gray-500 py-8">Loading...</div>;

  const Section = ({ title, items, color, emptyText }) => (
    <div className="mb-6">
      <h3 className="font-heading font-bold text-sm mb-2" style={{ color }}>{title} ({items?.length || 0})</h3>
      {(items||[]).map(a => (
        <div key={a.id} className="bg-s1 border border-border rounded-lg p-3 mb-2 flex justify-between items-center">
          <div><div className="text-[13px] font-medium">{a.subject || a.type}</div><div className="text-[12px] text-gray-500">{a.description}</div></div>
          <div className="flex items-center gap-2">
            {a.scheduled_at && <span className="text-[11px] text-gray-500">{new Date(a.scheduled_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})}</span>}
            {!a.completed && <button onClick={async () => { await api.crm.completeActivity(a.id); api.crm.today().then(setData); }} className="bg-accent/10 text-accent text-[11px] px-2 py-1 rounded">✓</button>}
          </div>
        </div>
      ))}
      {!(items?.length) && <div className="text-gray-500 text-[13px] bg-s1 border border-border rounded-lg p-4 text-center">{emptyText}</div>}
    </div>
  );

  return (
    <div>
      <div className="grid grid-cols-3 gap-4 mb-6">
        {[['Due Today', data.summary?.due_count, '#F59E0B'],['Overdue', data.summary?.overdue_count, '#F87171'],['Completed', data.summary?.completed_count, '#00E5A8']].map(([l,v,c]) => (
          <div key={l} className="bg-s1 border border-border rounded-xl p-4 text-center"><div className="text-[10px] text-gray-500 uppercase">{l}</div><div className="font-heading text-2xl font-bold" style={{color:c}}>{v||0}</div></div>
        ))}
      </div>
      <Section title="Overdue" items={data.overdue} color="#F87171" emptyText="Nothing overdue" />
      <Section title="Due Today" items={data.due_today} color="#F59E0B" emptyText="Nothing due today" />
      <Section title="Completed Today" items={data.completed_today} color="#00E5A8" emptyText="Nothing completed yet" />
      <Section title="Upcoming" items={data.upcoming} color="#3B82F6" emptyText="Nothing scheduled" />
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// REVENUE DASHBOARD
// ═══════════════════════════════════════════════════════════════
function RevenueView() {
  const [data, setData] = useState(null);
  useEffect(() => { api.crm.revenue().then(setData); }, []);
  if (!data) return <div className="text-gray-500 py-8">Loading...</div>;

  return (
    <div>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        {[['Pipeline', data.total_pipeline, '#3B82F6'],['Weighted', data.weighted_pipeline, '#7C3AED'],['Won', data.won, '#00E5A8'],['Lost', data.lost, '#F87171'],['Avg Deal', data.avg_deal_value, '#F59E0B'],['Conversion', data.conversion_rate+'%', '#7C3AED', true],['Win Rate', data.win_rate+'%', '#00E5A8', true],['Deals', data.deal_count, '#3B82F6', true]].map(([l,v,c,raw]) => (
          <div key={l} className="bg-s1 border border-border rounded-xl p-4"><div className="text-[10px] text-gray-500 uppercase mb-1">{l}</div><div className="font-heading text-xl font-bold" style={{color:c}}>{raw ? v : `AED ${parseFloat(v||0).toLocaleString()}`}</div></div>
        ))}
      </div>

      {/* 90-day forecast */}
      <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
        <h3 className="font-heading font-bold text-sm mb-3">90-Day Forecast</h3>
        <div className="grid grid-cols-3 gap-4">
          <div><div className="text-[10px] text-gray-500">Weighted Forecast</div><div className="font-heading text-lg font-bold text-primary">AED {parseFloat(data.forecast_90d?.weighted||0).toLocaleString()}</div></div>
          <div><div className="text-[10px] text-gray-500">Total Pipeline</div><div className="font-heading text-lg font-bold text-blue">AED {parseFloat(data.forecast_90d?.total||0).toLocaleString()}</div></div>
          <div><div className="text-[10px] text-gray-500">Expected Deals</div><div className="font-heading text-lg font-bold text-amber">{data.forecast_90d?.count||0}</div></div>
        </div>
      </div>

      {/* Monthly won */}
      {data.monthly_won?.length > 0 && (
        <div className="bg-s1 border border-border rounded-xl p-5">
          <h3 className="font-heading font-bold text-sm mb-3">Monthly Revenue (Won)</h3>
          <div className="space-y-2">{data.monthly_won.map(m => (
            <div key={m.month} className="flex items-center gap-3">
              <span className="text-[13px] text-gray-400 w-20">{m.month}</span>
              <div className="flex-1 bg-s2 rounded-full h-6 overflow-hidden">
                <div className="h-full bg-accent/30 rounded-full flex items-center px-2" style={{ width: `${Math.min(100, (m.total / Math.max(...data.monthly_won.map(x => x.total), 1)) * 100)}%` }}>
                  <span className="text-[11px] text-accent font-bold whitespace-nowrap">AED {parseFloat(m.total).toLocaleString()}</span>
                </div>
              </div>
              <span className="text-[11px] text-gray-500">{m.count} deals</span>
            </div>
          ))}</div>
        </div>
      )}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// REPORTING
// ═══════════════════════════════════════════════════════════════
function ReportingView() {
  const [data, setData] = useState(null);
  useEffect(() => { api.crm.reporting().then(setData); }, []);
  if (!data) return <div className="text-gray-500 py-8">Loading...</div>;

  return (
    <div className="space-y-6">
      {/* Totals */}
      <div className="grid grid-cols-4 gap-4">
        {[['Leads', data.total_leads, '#3B82F6'],['Contacts', data.total_contacts, '#7C3AED'],['Deals', data.total_deals, '#00E5A8'],['Activities (30d)', data.total_activities_30d, '#F59E0B']].map(([l,v,c]) => (
          <div key={l} className="bg-s1 border border-border rounded-xl p-4"><div className="text-[10px] text-gray-500 uppercase">{l}</div><div className="font-heading text-2xl font-bold" style={{color:c}}>{v||0}</div></div>
        ))}
      </div>

      {/* Conversion funnel */}
      <div className="bg-s1 border border-border rounded-xl p-5">
        <h3 className="font-heading font-bold text-sm mb-3">Conversion Funnel</h3>
        <div className="space-y-2">{(data.conversion_funnel||[]).map(f => {
          const max = Math.max(...(data.conversion_funnel||[]).map(x=>x.count), 1);
          const colors = { new: '#3B82F6', contacted: '#F59E0B', qualified: '#7C3AED', converted: '#00E5A8', lost: '#F87171' };
          return (
            <div key={f.stage} className="flex items-center gap-3">
              <span className="text-[13px] text-gray-400 w-24 capitalize">{f.stage}</span>
              <div className="flex-1 bg-s2 rounded-full h-7 overflow-hidden">
                <div className="h-full rounded-full flex items-center px-3 transition-all" style={{ width: `${(f.count/max)*100}%`, background: `${colors[f.stage]||'#3B82F6'}30` }}>
                  <span className="text-[12px] font-bold" style={{ color: colors[f.stage] }}>{f.count}</span>
                </div>
              </div>
            </div>
          );
        })}</div>
      </div>

      {/* Lead sources */}
      <div className="bg-s1 border border-border rounded-xl p-5">
        <h3 className="font-heading font-bold text-sm mb-3">Lead Sources</h3>
        <table className="w-full text-[13px]"><thead><tr className="text-[10px] text-gray-500 uppercase border-b border-border"><th className="text-left px-3 py-2">Source</th><th className="text-left px-3 py-2">Count</th><th className="text-left px-3 py-2">Converted</th><th className="text-left px-3 py-2">Conv. Rate</th></tr></thead>
        <tbody>{(data.lead_sources||[]).map(s => <tr key={s.source} className="border-b border-s2"><td className="px-3 py-2 capitalize">{s.source||'Unknown'}</td><td className="px-3 py-2">{s.count}</td><td className="px-3 py-2 text-accent">{s.converted}</td><td className="px-3 py-2"><span className="text-accent">{s.conversion_rate}%</span></td></tr>)}</tbody></table>
      </div>

      {/* Activity breakdown */}
      <div className="bg-s1 border border-border rounded-xl p-5">
        <h3 className="font-heading font-bold text-sm mb-3">Activity Breakdown (30 days)</h3>
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
          {Object.entries(data.activity_stats||{}).map(([type, count]) => (
            <div key={type} className="bg-s2 rounded-lg p-3 text-center"><div className="text-[10px] text-gray-500 capitalize">{type}</div><div className="font-heading text-lg font-bold text-primary">{count}</div></div>
          ))}
        </div>
      </div>

      {/* Avg conversion time */}
      <div className="bg-s1 border border-border rounded-xl p-5">
        <h3 className="font-heading font-bold text-sm mb-2">Avg Time to Conversion</h3>
        <div className="font-heading text-3xl font-bold text-primary">{data.avg_days_to_conversion} days</div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// SHARED COMPONENTS
// ═══════════════════════════════════════════════════════════════
function ScoreBar({ score, size = 'sm' }) {
  const w = size === 'lg' ? 'w-16' : 'w-10';
  const color = score >= 70 ? '#00E5A8' : score >= 40 ? '#F59E0B' : '#F87171';
  return (
    <div className="flex items-center gap-1">
      <div className={`${w} h-1.5 bg-s2 rounded-full overflow-hidden`}>
        <div className="h-full rounded-full" style={{ width: `${score}%`, background: color }} />
      </div>
      <span className="text-[11px] font-bold" style={{ color }}>{score}</span>
    </div>
  );
}

function ImportModal({ onImport, onClose }) {
  const [text, setText] = useState('');
  return (
    <div className="bg-s1 border border-border rounded-xl p-5 mb-4">
      <h3 className="font-heading font-bold text-sm mb-2">Import Leads from CSV</h3>
      <p className="text-[12px] text-gray-500 mb-3">Paste CSV with headers: name, email, phone, company, source</p>
      <textarea value={text} onChange={e => setText(e.target.value)} rows={6} placeholder={"name,email,phone,company,source\nJohn Doe,john@example.com,+971555,Acme Corp,website"} className="w-full bg-s2 border border-border rounded-lg px-3 py-2 text-[12px] font-mono resize-none mb-3" />
      <div className="flex gap-2"><button onClick={() => onImport(text)} className="bg-primary text-white text-[13px] px-4 py-2 rounded-lg">Import</button><button onClick={onClose} className="text-gray-400 text-[13px]">Cancel</button></div>
    </div>
  );
}
