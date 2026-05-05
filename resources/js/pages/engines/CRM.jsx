import { useEffect, useState } from 'react'
import { crm as crmApi } from '../../services/api'
import { PageHeader, Card, Badge, Button, Input, Modal, Loading, Empty, StatCard, Alert, statusBadge, Table } from '../../components/ui/index.jsx'

export function CRMPage() {
  const [leads, setLeads]       = useState([])
  const [pipeline, setPipeline] = useState([])
  const [loading, setLoading]   = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]         = useState({ name: '', email: '', phone: '', company: '', status: 'new', source: 'manual' })
  const [error, setError]       = useState('')
  const [success, setSuccess]   = useState('')
  const [tab, setTab]           = useState('leads')
  const [outreach, setOutreach] = useState('')

  const set = k => e => setForm(f => ({ ...f, [k]: e.target.value }))

  useEffect(() => {
    Promise.all([crmApi.listLeads({}), crmApi.pipeline()])
      .then(([lRes, pRes]) => {
        if (lRes?.ok) setLeads(lRes.data?.leads || lRes.data?.data || [])
        if (pRes?.ok) setPipeline(pRes.data?.stages || pRes.data || [])
        setLoading(false)
      })
  }, [])

  const createLead = async () => {
    if (!form.name) { setError('Name is required'); return }
    const res = await crmApi.createLead(form)
    if (res?.ok) {
      setLeads(l => [res.data, ...l])
      setShowModal(false)
      setSuccess('Lead created!')
      setForm({ name: '', email: '', phone: '', company: '', status: 'new', source: 'manual' })
    } else {
      setError(res?.data?.message || 'Failed to create lead')
    }
  }

  const scoreLead = async (id) => {
    await crmApi.scoreLead(id)
    setSuccess('Lead scored!')
  }

  const generateOutreach = async (leadId) => {
    const res = await crmApi.outreach({ lead_id: leadId, goal: 'Book a discovery call' })
    if (res?.ok) setOutreach(JSON.stringify(res.data, null, 2))
  }

  if (loading) return <Loading />

  const tabs = ['leads', 'pipeline', 'contacts']

  return (
    <div>
      <PageHeader
        title="CRM"
        subtitle="Manage your leads, deals, and customer relationships"
        actions={
          <>
            <Button variant="outline" onClick={() => {}}>Import</Button>
            <Button variant="primary" onClick={() => setShowModal(true)}>+ New Lead</Button>
          </>
        }
      />

      {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
      {success && <Alert type="success" onClose={() => setSuccess('')}>{success}</Alert>}

      {/* Stats */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '14px', marginBottom: '24px' }}>
        <StatCard value={leads.length} label="Total leads" color="var(--ac)" />
        <StatCard value={leads.filter(l => l.status === 'new').length} label="New leads" color="var(--bl)" />
        <StatCard value={leads.filter(l => l.status === 'qualified').length} label="Qualified" color="var(--pu)" />
        <StatCard value={leads.filter(l => l.status === 'converted').length} label="Converted" color="var(--am)" />
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: '4px', marginBottom: '20px' }}>
        {tabs.map(t => (
          <button key={t} onClick={() => setTab(t)} style={{
            padding: '7px 16px', borderRadius: '8px', border: 'none', cursor: 'pointer',
            background: tab === t ? 'var(--p)' : 'var(--s2)',
            color: tab === t ? '#fff' : 'var(--muted)',
            fontSize: '13px', fontWeight: 600, fontFamily: 'inherit', textTransform: 'capitalize'
          }}>{t}</button>
        ))}
      </div>

      {tab === 'leads' && (
        <Card>
          {leads.length === 0 ? (
            <Empty icon="👥" title="No leads yet" description="Add your first lead to get started." action={<Button onClick={() => setShowModal(true)}>+ Add Lead</Button>} />
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
              {leads.map(lead => (
                <div key={lead.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 14px', background: 'var(--s2)', borderRadius: '10px' }}>
                  <div>
                    <div style={{ fontWeight: 600, fontSize: '14px' }}>{lead.name}</div>
                    <div style={{ fontSize: '12px', color: 'var(--muted)' }}>{lead.company} · {lead.email}</div>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                    {lead.score !== undefined && <span style={{ fontSize: '12px', color: 'var(--ac)', fontWeight: 700 }}>Score: {lead.score}</span>}
                    <Badge color={statusBadge(lead.status)}>{lead.status}</Badge>
                    <Button size="sm" variant="ghost" onClick={() => scoreLead(lead.id)}>Score</Button>
                    <Button size="sm" variant="ghost" onClick={() => generateOutreach(lead.id)}>Outreach</Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {tab === 'pipeline' && (
        <div style={{ display: 'flex', gap: '14px', overflowX: 'auto', paddingBottom: '12px' }}>
          {pipeline.length === 0 ? (
            <Empty icon="📊" title="No pipeline stages" description="Create pipeline stages to manage your deals." />
          ) : pipeline.map(stage => (
            <div key={stage.id} style={{ minWidth: '240px', background: 'var(--s1)', border: '1px solid var(--border)', borderRadius: '12px', padding: '16px' }}>
              <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '12px' }}>{stage.name}</div>
              <div style={{ fontSize: '12px', color: 'var(--muted)' }}>0 deals</div>
            </div>
          ))}
        </div>
      )}

      {tab === 'contacts' && (
        <Card>
          <Empty icon="📇" title="Contacts" description="Contact management coming here — linked to your leads and deals." />
        </Card>
      )}

      {outreach && (
        <Card style={{ marginTop: '16px' }}>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '10px' }}>AI-Generated Outreach</div>
          <pre style={{ fontSize: '12px', color: 'var(--muted)', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{outreach}</pre>
          <Button size="sm" variant="ghost" onClick={() => setOutreach('')} style={{ marginTop: '8px' }}>Clear</Button>
        </Card>
      )}

      {/* Create Lead Modal */}
      <Modal open={showModal} onClose={() => setShowModal(false)} title="New Lead"
        footer={
          <>
            <Button variant="ghost" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button variant="primary" onClick={createLead}>Create Lead</Button>
          </>
        }
      >
        {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
        <Input label="Name *" value={form.name} onChange={set('name')} placeholder="John Smith" />
        <Input label="Email" type="email" value={form.email} onChange={set('email')} placeholder="john@company.com" />
        <Input label="Company" value={form.company} onChange={set('company')} placeholder="Acme Corp" />
        <Input label="Phone" value={form.phone} onChange={set('phone')} placeholder="+971 50 000 0000" />
      </Modal>
    </div>
  )
}
