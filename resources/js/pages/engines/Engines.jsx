import { useEffect, useState } from 'react'
import { seo, write, creative, marketing, social, builder, calendar, sarah, approvals as approvalsApi, workspace as wsApi, team, tasks } from '../../services/api'
import { PageHeader, Card, Badge, Button, Input, Select, Modal, Loading, Empty, StatCard, Alert, statusBadge } from '../../components/ui/index.jsx'
import { useAuth } from '../../context/AuthContext'

// ── Shared hook ───────────────────────────────────────────────────────────────
function useEngine(loader) {
  const [data, setData]   = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  useEffect(() => {
    loader().then(res => {
      if (res?.ok) setData(res.data)
      else setError(res?.data?.error || 'Load failed')
      setLoading(false)
    })
  }, [])
  return { data, loading, error }
}

// ── SEO ───────────────────────────────────────────────────────────────────────
export function SEOPage() {
  const [keyword, setKeyword] = useState('')
  const [result, setResult]   = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState('')

  const runAnalysis = async () => {
    if (!keyword) return
    setLoading(true); setError('')
    const res = await seo.serpAnalysis({ keyword, url: '' })
    setLoading(false)
    if (res?.ok) setResult(res.data)
    else setError(res?.data?.error || 'Analysis failed — insufficient credits or plan')
  }

  return (
    <div>
      <PageHeader title="SEO Engine" subtitle="15 tools: SERP analysis, deep audits, link management, and more" />
      {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px', marginBottom: '20px' }}>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>SERP Analysis</div>
          <Input label="Keyword" value={keyword} onChange={e => setKeyword(e.target.value)} placeholder="best restaurant Dubai" />
          <Button variant="primary" onClick={runAnalysis} disabled={loading || !keyword}>
            {loading ? 'Analyzing… (5 credits)' : '🔍 Run SERP Analysis (5 credits)'}
          </Button>
        </Card>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Quick Tools</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
            {['Deep Site Audit (15 cr)', 'AI SEO Report (10 cr)', 'Link Suggestions (3 cr)', 'Outbound Links (2 cr)', 'Set Autonomous Goal (20 cr)'].map(tool => (
              <div key={tool} style={{ padding: '10px 12px', background: 'var(--s2)', borderRadius: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '13px' }}>
                {tool}<Button size="sm" variant="ghost">Run</Button>
              </div>
            ))}
          </div>
        </Card>
      </div>
      {result && (
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '10px' }}>Analysis Result</div>
          <pre style={{ fontSize: '12px', color: 'var(--muted)', whiteSpace: 'pre-wrap', maxHeight: '400px', overflow: 'auto' }}>{JSON.stringify(result, null, 2)}</pre>
        </Card>
      )}
    </div>
  )
}

// ── Write ─────────────────────────────────────────────────────────────────────
export function WritePage() {
  const [topic, setTopic]   = useState('')
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError]   = useState('')
  const [articles, setArticles] = useState([])

  useEffect(() => {
    write.listArticles({}).then(res => { if (res?.ok) setArticles(res.data?.articles || res.data?.data || []) })
  }, [])

  const generateArticle = async () => {
    if (!topic) return
    setLoading(true); setError('')
    const res = await write.writeArticle({ topic, type: 'blog_post', length: 1000 })
    setLoading(false)
    if (res?.ok) { setResult(res.data); setArticles(a => [res.data, ...a]) }
    else setError(res?.data?.error || 'Generation failed')
  }

  return (
    <div>
      <PageHeader title="Write Engine" subtitle="Articles, briefs, outlines, headlines, and SEO meta" />
      {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px', marginBottom: '20px' }}>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Generate Article</div>
          <Input label="Topic" value={topic} onChange={e => setTopic(e.target.value)} placeholder="5 Best Restaurants in Dubai Marina" />
          <Button variant="primary" onClick={generateArticle} disabled={loading || !topic} style={{ width: '100%' }}>
            {loading ? 'Writing… (10 credits)' : '✍️ Write Article (10 credits)'}
          </Button>
        </Card>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Quick Tools</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
            {['Generate Outline (3 cr)', 'Generate Headlines (2 cr)', 'SEO Meta (2 cr)', 'Improve Draft (5 cr)'].map(t => (
              <div key={t} style={{ padding: '10px 12px', background: 'var(--s2)', borderRadius: '8px', display: 'flex', justifyContent: 'space-between', fontSize: '13px' }}>
                {t}<Button size="sm" variant="ghost">Run</Button>
              </div>
            ))}
          </div>
        </Card>
      </div>
      <Card>
        <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Articles ({articles.length})</div>
        {articles.length === 0 ? <Empty icon="✍️" title="No articles yet" description="Generate your first article above." /> :
          articles.slice(0, 5).map(a => (
            <div key={a.id || Math.random()} style={{ padding: '12px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div><div style={{ fontWeight: 600, fontSize: '14px' }}>{a.title || 'Article'}</div><div style={{ fontSize: '12px', color: 'var(--muted)' }}>{a.type || 'blog_post'}</div></div>
              <Badge color={statusBadge(a.status || 'draft')}>{a.status || 'draft'}</Badge>
            </div>
          ))
        }
      </Card>
    </div>
  )
}

// ── Creative ──────────────────────────────────────────────────────────────────
export function CreativePage() {
  const [prompt, setPrompt] = useState('')
  const [assets, setAssets] = useState([])
  const [brand, setBrand]   = useState(null)
  const [loading, setLoading] = useState(true)
  const [generating, setGenerating] = useState(false)
  const [error, setError]   = useState('')

  useEffect(() => {
    Promise.all([creative.listAssets({}), creative.brand()]).then(([aRes, bRes]) => {
      if (aRes?.ok) setAssets(aRes.data?.assets || [])
      if (bRes?.ok) setBrand(bRes.data)
      setLoading(false)
    })
  }, [])

  const generateImage = async () => {
    if (!prompt) return
    setGenerating(true)
    const res = await creative.generateImage({ prompt })
    setGenerating(false)
    if (res?.ok) { setAssets(a => [res.data, ...a]); setPrompt('') }
    else setError(res?.data?.error || 'Generation failed — check your plan and credits')
  }

  if (loading) return <Loading />

  return (
    <div>
      <PageHeader title="Creative Engine" subtitle="AI images, videos, brand kit, and creative blueprints" />
      {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px', marginBottom: '20px' }}>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Generate Image</div>
          <Input label="Prompt" value={prompt} onChange={e => setPrompt(e.target.value)} placeholder="Modern restaurant interior, warm lighting, Dubai style..." />
          <Button variant="primary" onClick={generateImage} disabled={generating || !prompt} style={{ width: '100%' }}>
            {generating ? 'Generating… (10 credits)' : '🎨 Generate Image (10 credits)'}
          </Button>
        </Card>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Brand Kit</div>
          {brand ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', fontSize: '13px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Voice</span><strong>{brand.voice || '—'}</strong></div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Tone</span><strong>{brand.tone || '—'}</strong></div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Industry</span><strong>{brand.industry || '—'}</strong></div>
              <Button size="sm" variant="outline" style={{ marginTop: '8px' }}>Edit Brand Kit</Button>
            </div>
          ) : <Empty icon="🎨" title="No brand kit" description="Set up your brand to improve all outputs." />}
        </Card>
      </div>
      <Card>
        <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Assets ({assets.length})</div>
        {assets.length === 0 ? <Empty icon="🖼️" title="No assets yet" description="Generate your first image above." /> :
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: '12px' }}>
            {assets.slice(0, 12).map(a => (
              <div key={a.id} style={{ background: 'var(--s2)', borderRadius: '10px', overflow: 'hidden' }}>
                {a.url ? <img src={a.url} alt={a.title} style={{ width: '100%', aspectRatio: '1', objectFit: 'cover' }} /> :
                  <div style={{ width: '100%', aspectRatio: '1', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--muted)', fontSize: '24px' }}>{a.status === 'in_progress' ? '⏳' : '🖼️'}</div>}
                <div style={{ padding: '8px', fontSize: '11px', color: 'var(--muted)' }}>{a.type} · {a.status}</div>
              </div>
            ))}
          </div>
        }
      </Card>
    </div>
  )
}

// ── Marketing ─────────────────────────────────────────────────────────────────
export function MarketingPage() {
  const [campaigns, setCampaigns] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    marketing.listCampaigns().then(res => {
      if (res?.ok) setCampaigns(res.data?.campaigns || res.data?.data || [])
      setLoading(false)
    })
  }, [])

  if (loading) return <Loading />

  return (
    <div>
      <PageHeader title="Marketing Engine" subtitle="Email campaigns, templates, and marketing automation"
        actions={<Button variant="primary">+ New Campaign</Button>}
      />
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '14px', marginBottom: '20px' }}>
        <StatCard value={campaigns.length} label="Total campaigns" color="var(--am)" />
        <StatCard value={campaigns.filter(c => c.status === 'active').length} label="Active" color="var(--ac)" />
        <StatCard value={campaigns.filter(c => c.status === 'draft').length} label="Drafts" color="var(--muted)" />
        <StatCard value={campaigns.filter(c => c.status === 'sent').length} label="Sent" color="var(--bl)" />
      </div>
      <Card>
        <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Campaigns</div>
        {campaigns.length === 0 ? <Empty icon="📧" title="No campaigns" description="Create your first email campaign." action={<Button>+ New Campaign</Button>} /> :
          campaigns.map(c => (
            <div key={c.id} style={{ padding: '12px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div><div style={{ fontWeight: 600 }}>{c.name}</div><div style={{ fontSize: '12px', color: 'var(--muted)' }}>{c.type}</div></div>
              <Badge color={statusBadge(c.status)}>{c.status}</Badge>
            </div>
          ))
        }
      </Card>
    </div>
  )
}

// ── Social ────────────────────────────────────────────────────────────────────
export function SocialPage() {
  const [posts, setPosts]     = useState([])
  const [accounts, setAccounts] = useState([])
  const [loading, setLoading] = useState(true)
  const [topic, setTopic]     = useState('')
  const [platform, setPlatform] = useState('instagram')
  const [generating, setGen]  = useState(false)
  const [error, setError]     = useState('')

  useEffect(() => {
    Promise.all([social.listPosts({}), social.listAccounts()]).then(([pRes, aRes]) => {
      if (pRes?.ok) setPosts(pRes.data?.posts || pRes.data?.data || [])
      if (aRes?.ok) setAccounts(aRes.data?.accounts || [])
      setLoading(false)
    })
  }, [])

  const generatePost = async () => {
    if (!topic) return
    setGen(true)
    const res = await social.generatePost({ topic, platform })
    setGen(false)
    if (res?.ok) { setPosts(p => [res.data, ...p]); setTopic('') }
    else setError(res?.data?.error || 'Generation failed')
  }

  if (loading) return <Loading />

  const platforms = ['instagram', 'linkedin', 'tiktok', 'twitter', 'facebook']

  return (
    <div>
      <PageHeader title="Social Engine" subtitle="Multi-platform posting, scheduling, and AI post generation"
        actions={<Button variant="primary">+ Create Post</Button>}
      />
      {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px', marginBottom: '20px' }}>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Generate Post</div>
          <div style={{ display: 'flex', gap: '8px', marginBottom: '12px' }}>
            {platforms.map(p => (
              <button key={p} onClick={() => setPlatform(p)} style={{ padding: '4px 12px', borderRadius: '100px', border: '1px solid', borderColor: platform === p ? 'var(--p)' : 'var(--border)', background: platform === p ? 'rgba(108,92,231,.1)' : 'transparent', color: platform === p ? 'var(--p)' : 'var(--muted)', fontSize: '12px', cursor: 'pointer', fontFamily: 'inherit', textTransform: 'capitalize' }}>{p}</button>
            ))}
          </div>
          <Input label="Topic" value={topic} onChange={e => setTopic(e.target.value)} placeholder="New menu item launch, Dubai foodie..." />
          <Button variant="primary" onClick={generatePost} disabled={generating || !topic} style={{ width: '100%' }}>
            {generating ? 'Generating… (1 credit)' : `📱 Generate ${platform} post (1 credit)`}
          </Button>
        </Card>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Connected Accounts ({accounts.length})</div>
          {accounts.length === 0 ? (
            <Empty icon="🔗" title="No accounts connected" description="Connect your social media accounts." action={<Button size="sm">Connect account</Button>} />
          ) : accounts.map(a => (
            <div key={a.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '6px' }}>
              <span style={{ fontSize: '13px', textTransform: 'capitalize' }}>{a.platform}</span>
              <Badge color="green">Connected</Badge>
            </div>
          ))}
        </Card>
      </div>
      <Card>
        <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Posts ({posts.length})</div>
        {posts.length === 0 ? <Empty icon="📱" title="No posts yet" description="Generate or create your first social post." /> :
          posts.slice(0, 5).map(p => (
            <div key={p.id} style={{ padding: '12px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div style={{ flex: 1, paddingRight: '12px' }}>
                <div style={{ fontSize: '13px', marginBottom: '4px' }}>{p.content?.slice(0, 120)}…</div>
                <div style={{ fontSize: '11px', color: 'var(--muted)', textTransform: 'capitalize' }}>{p.platform}</div>
              </div>
              <div style={{ display: 'flex', gap: '6px', flexShrink: 0 }}>
                <Badge color={statusBadge(p.status || 'draft')}>{p.status || 'draft'}</Badge>
              </div>
            </div>
          ))
        }
      </Card>
    </div>
  )
}

// ── Builder ───────────────────────────────────────────────────────────────────
export function BuilderPage() {
  const [websites, setWebsites] = useState([])
  const [loading, setLoading]   = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]         = useState({ name: '', industry: '', goal: 'leads' })
  const [error, setError]       = useState('')

  useEffect(() => {
    builder.listWebsites().then(res => {
      if (res?.ok) setWebsites(res.data?.websites || res.data || [])
      setLoading(false)
    })
  }, [])

  const createWebsite = async () => {
    const res = await builder.createWebsite({ name: form.name, industry: form.industry, goal: form.goal })
    if (res?.ok) { setWebsites(w => [res.data, ...w]); setShowModal(false) }
    else setError(res?.data?.error || 'Failed to create website')
  }
  if (loading) return <Loading />

  return (
    <div>
      <PageHeader title="Builder Engine" subtitle="Website builder, landing pages, and website wizard"
        actions={<Button variant="primary" onClick={() => setShowModal(true)}>+ New Website</Button>}
      />
      {websites.length === 0 ? (
        <Empty icon="🏗️" title="No websites yet" description="Create your first website — this also activates your free trial!" action={<Button onClick={() => setShowModal(true)}>Create Website</Button>} />
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: '16px' }}>
          {websites.map(w => (
            <Card key={w.id}>
              <div style={{ fontWeight: 700, fontSize: '16px', marginBottom: '6px' }}>{w.name}</div>
              <div style={{ fontSize: '12px', color: 'var(--muted)', marginBottom: '12px' }}>{w.domain || w.subdomain}</div>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <Badge color={statusBadge(w.status || 'draft')}>{w.status || 'draft'}</Badge>
                <Button size="sm" variant="ghost">Edit Pages</Button>
              </div>
            </Card>
          ))}
        </div>
      )}
      <Modal open={showModal} onClose={() => setShowModal(false)} title="Create Website"
        footer={<><Button variant="ghost" onClick={() => setShowModal(false)}>Cancel</Button><Button variant="primary" onClick={createWebsite}>Create</Button></>}
      >
        {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
        <Input label="Website Name" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="My Restaurant Dubai" />
        <Input label="Industry" value={form.industry} onChange={e => setForm(f => ({ ...f, industry: e.target.value }))} placeholder="Restaurant & Food" />
      </Modal>
    </div>
  )
}

// ── Calendar ──────────────────────────────────────────────────────────────────
export function CalendarPage() {
  const [events, setEvents] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    calendar.listEvents({ from: new Date().toISOString() }).then(res => {
      if (res?.ok) setEvents(res.data?.events || res.data || [])
      setLoading(false)
    })
  }, [])

  if (loading) return <Loading />

  return (
    <div>
      <PageHeader title="Calendar Engine" subtitle="Events, appointments, and scheduling"
        actions={<Button variant="primary">+ New Event</Button>}
      />
      {events.length === 0 ? <Empty icon="📅" title="No upcoming events" description="Add events to your calendar." /> :
        <Card>
          {events.map(e => (
            <div key={e.id} style={{ padding: '12px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '8px', display: 'flex', justifyContent: 'space-between' }}>
              <div><div style={{ fontWeight: 600 }}>{e.title}</div><div style={{ fontSize: '12px', color: 'var(--muted)' }}>{e.start_at}</div></div>
              <Badge color={statusBadge(e.type || 'event')}>{e.type || 'event'}</Badge>
            </div>
          ))}
        </Card>
      }
    </div>
  )
}

// ── Strategy Room ─────────────────────────────────────────────────────────────
export function StrategyPage() {
  const [plans, setPlans]     = useState([])
  const [proposals, setProposals] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([sarah.listPlans(), sarah.listProposals()]).then(([pRes, propRes]) => {
      if (pRes?.ok) setPlans(pRes.data?.plans || pRes.data || [])
      if (propRes?.ok) setProposals(propRes.data || [])
      setLoading(false)
    })
  }, [])

  if (loading) return <Loading />

  return (
    <div>
      <PageHeader title="Strategy Room" subtitle="Sarah's plans, strategy meetings, and proposals" />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px' }}>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Active Plans ({plans.length})</div>
          {plans.length === 0 ? <Empty icon="🧭" title="No active plans" description="Submit a goal to Sarah from the dashboard to get a strategic plan." /> :
            plans.slice(0, 5).map(p => (
              <div key={p.id} style={{ padding: '12px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '8px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px' }}>
                  <span style={{ fontWeight: 600, fontSize: '14px' }}>{p.goal?.slice(0, 60)}…</span>
                  <Badge color={statusBadge(p.status)}>{p.status}</Badge>
                </div>
                {p.status === 'draft' && <Button size="sm" onClick={() => sarah.approvePlan(p.id)}>Approve Plan</Button>}
              </div>
            ))
          }
        </Card>
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Proposals ({proposals.length})</div>
          {proposals.length === 0 ? <Empty icon="📋" title="No proposals" description="Sarah will send proposals when she identifies opportunities." /> :
            proposals.slice(0, 5).map(p => (
              <div key={p.id} style={{ padding: '12px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '8px' }}>
                <div style={{ fontWeight: 600, fontSize: '14px', marginBottom: '4px' }}>{p.title}</div>
                <div style={{ fontSize: '12px', color: 'var(--muted)', marginBottom: '8px' }}>{p.description?.slice(0, 80)}…</div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span style={{ fontSize: '12px', color: 'var(--am)' }}>💎 {p.total_credits} credits</span>
                  {p.status === 'pending_approval' && (
                    <div style={{ display: 'flex', gap: '8px' }}>
                      <Button size="sm" onClick={() => sarah.approveProposal(p.id)}>Approve</Button>
                      <Button size="sm" variant="ghost" onClick={() => sarah.declineProposal(p.id)}>Decline</Button>
                    </div>
                  )}
                </div>
              </div>
            ))
          }
        </Card>
      </div>
    </div>
  )
}

// ── Approvals ─────────────────────────────────────────────────────────────────
export function ApprovalsPage() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    approvalsApi.list().then(res => {
      if (res?.ok) setItems(res.data?.data || res.data || [])
      setLoading(false)
    })
  }, [])

  const approve = async (id) => { await approvalsApi.approve(id); setItems(a => a.filter(x => x.id !== id)) }
  const reject  = async (id) => { await approvalsApi.reject(id, 'Declined'); setItems(a => a.filter(x => x.id !== id)) }

  if (loading) return <Loading />

  return (
    <div>
      <PageHeader title="Approvals" subtitle="Review and approve AI-generated actions before they execute" />
      {items.length === 0 ? (
        <Empty icon="✅" title="All clear!" description="No pending approvals. Your AI team is waiting for new goals." />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
          {items.map(a => (
            <Card key={a.id}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                <div>
                  <div style={{ fontWeight: 700, fontSize: '15px', marginBottom: '4px' }}>{a.action || a.title}</div>
                  <div style={{ fontSize: '13px', color: 'var(--muted)', marginBottom: '8px' }}>{a.engine} engine · {a.credit_cost || 0} credits</div>
                  {a.params_json && <pre style={{ fontSize: '11px', color: 'var(--muted)', background: 'var(--s2)', padding: '8px', borderRadius: '6px', whiteSpace: 'pre-wrap', maxHeight: '100px', overflow: 'auto' }}>{typeof a.params_json === 'string' ? a.params_json : JSON.stringify(a.params_json, null, 2)}</pre>}
                </div>
                <div style={{ display: 'flex', gap: '8px', flexShrink: 0, marginLeft: '16px' }}>
                  <Button variant="success" onClick={() => approve(a.id)}>✓ Approve</Button>
                  <Button variant="ghost" onClick={() => reject(a.id)}>✗ Decline</Button>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Settings ──────────────────────────────────────────────────────────────────
export function SettingsPage() {
  const { user, workspace, capabilities, planSlug, refreshCaps } = useAuth()
  const [billing, setBilling]     = useState(null)
  const [plans, setPlans]         = useState([])
  const [members, setMembers]     = useState([])
  const [loading, setLoading]     = useState(true)
  const [tab, setTab]             = useState('account')
  const [inviteEmail, setInvite]  = useState('')
  const [inviteRole, setRole]     = useState('member')
  const [inviting, setInviting]   = useState(false)
  const [msg, setMsg]             = useState(null)

  useEffect(() => {
    Promise.all([wsApi.billing(), wsApi.plans(), team.getMembers()]).then(([bRes, pRes, mRes]) => {
      if (bRes?.ok) setBilling(bRes.data)
      if (pRes?.ok) setPlans(pRes.data?.plans || [])
      if (mRes?.ok) setMembers(mRes.data?.members || [])
      setLoading(false)
    })
  }, [])

  const sendInvite = async () => {
    if (!inviteEmail) return
    setInviting(true)
    const res = await team.invite(inviteEmail, inviteRole)
    setInviting(false)
    if (res?.ok) { setMsg({ type: 'success', text: `Invite sent to ${inviteEmail} as ${inviteRole}` }); setInvite('') }
    else setMsg({ type: 'error', text: res?.data?.error || 'Failed to send invite' })
  }

  const openPortal = async () => {
    const res = await wsApi.portal()
    if (res?.ok && res.data?.portal_url) window.open(res.data.portal_url, '_blank')
    else setMsg({ type: 'error', text: res?.data?.error || 'Portal not available' })
  }

  if (loading) return <Loading />

  const tabs = ['account', 'billing', 'team', 'workspace']

  return (
    <div>
      <PageHeader title="Settings" subtitle="Account, billing, team, and workspace configuration" />
      {msg && <Alert type={msg.type} onClose={() => setMsg(null)}>{msg.text}</Alert>}

      <div style={{ display: 'flex', gap: '4px', marginBottom: '20px' }}>
        {tabs.map(t => (
          <button key={t} onClick={() => setTab(t)} style={{ padding: '7px 16px', borderRadius: '8px', border: 'none', cursor: 'pointer', background: tab === t ? 'var(--p)' : 'var(--s2)', color: tab === t ? '#fff' : 'var(--muted)', fontSize: '13px', fontWeight: 600, fontFamily: 'inherit', textTransform: 'capitalize' }}>{t}</button>
        ))}
      </div>

      {tab === 'account' && (
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '16px' }}>Account Details</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', fontSize: '14px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Name</span><strong>{user?.name}</strong></div>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Email</span><strong>{user?.email}</strong></div>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Plan</span><Badge color={statusBadge(planSlug)}>{planSlug}</Badge></div>
          </div>
        </Card>
      )}

      {tab === 'billing' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
          <Card>
            <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '16px' }}>Current Plan</div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div>
                <div style={{ fontSize: '20px', fontWeight: 800, fontFamily: 'Syne, system-ui, sans-serif' }}>{billing?.plan || 'Free'}</div>
                <div style={{ fontSize: '13px', color: 'var(--muted)' }}>{billing?.credit_balance || 0} / {billing?.monthly_credit_limit || 0} credits</div>
              </div>
              {billing?.stripe_connected && <Button variant="outline" onClick={openPortal}>Manage billing →</Button>}
            </div>
          </Card>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '12px' }}>
            {plans.filter(p => p.slug !== planSlug).slice(0, 3).map(p => (
              <Card key={p.id}>
                <div style={{ fontWeight: 700, fontSize: '16px', marginBottom: '4px' }}>{p.name}</div>
                <div style={{ fontSize: '22px', fontWeight: 800, color: 'var(--p)', marginBottom: '8px', fontFamily: 'Syne, system-ui, sans-serif' }}>${p.price}<span style={{ fontSize: '13px', fontWeight: 400, color: 'var(--muted)' }}>/mo</span></div>
                <div style={{ fontSize: '12px', color: 'var(--muted)', marginBottom: '12px' }}>{p.credit_limit} credits/mo</div>
                <Button variant="primary" size="sm" style={{ width: '100%' }} onClick={async () => {
                  const res = await wsApi.checkout(p.id)
                  if (res?.ok) {
                    if (res.data?.checkout_url) window.location.href = res.data.checkout_url
                    else if (res.data?.activated) { setMsg({ type: 'success', text: `Activated ${p.name} plan!` }); refreshCaps() }
                  }
                }}>
                  Upgrade to {p.name}
                </Button>
              </Card>
            ))}
          </div>
        </div>
      )}

      {tab === 'team' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
          <Card>
            <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Team Members ({members.length})</div>
            {members.map(m => (
              <div key={m.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 12px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '8px' }}>
                <div><div style={{ fontWeight: 600, fontSize: '14px' }}>{m.name}</div><div style={{ fontSize: '12px', color: 'var(--muted)' }}>{m.email}</div></div>
                <Badge color={m.role === 'owner' ? 'amber' : m.role === 'admin' ? 'blue' : 'muted'}>{m.role}</Badge>
              </div>
            ))}
          </Card>
          <Card>
            <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Invite Team Member</div>
            <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap' }}>
              <input value={inviteEmail} onChange={e => setInvite(e.target.value)} placeholder="colleague@company.com" style={{ flex: '1 1 200px', background: 'var(--s2)', border: '1px solid var(--border)', borderRadius: '8px', padding: '9px 12px', color: 'var(--text)', fontSize: '14px', outline: 'none', fontFamily: 'inherit' }} />
              <select value={inviteRole} onChange={e => setRole(e.target.value)} style={{ background: 'var(--s2)', border: '1px solid var(--border)', borderRadius: '8px', padding: '9px 12px', color: 'var(--text)', fontSize: '14px', fontFamily: 'inherit' }}>
                <option value="member">Member</option>
                <option value="admin">Admin</option>
                <option value="viewer">Viewer</option>
              </select>
              <Button variant="primary" onClick={sendInvite} disabled={inviting || !inviteEmail}>{inviting ? 'Sending…' : 'Send Invite'}</Button>
            </div>
            <div style={{ fontSize: '12px', color: 'var(--muted)', marginTop: '8px' }}>
              Admin: full access to features, no billing changes. Member: engine access only. Viewer: read-only.
            </div>
          </Card>
        </div>
      )}

      {tab === 'workspace' && (
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '16px' }}>Workspace Details</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', fontSize: '14px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Name</span><strong>{workspace?.name}</strong></div>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Business</span><strong>{workspace?.business_name || '—'}</strong></div>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Industry</span><strong>{workspace?.industry || '—'}</strong></div>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: 'var(--muted)' }}>Goal</span><strong>{workspace?.goal || '—'}</strong></div>
          </div>
        </Card>
      )}
    </div>
  )
}

// ── Campaigns ─────────────────────────────────────────────────────────────────
export function CampaignsPage() {
  return (
    <div>
      <PageHeader title="Campaigns" subtitle="Active campaigns across all marketing channels" />
      <Empty icon="🚀" title="Campaigns view" description="Campaign overview across SEO, email, social, and paid media. Sarah creates campaigns through the Strategy Room." />
    </div>
  )
}

// ── History ───────────────────────────────────────────────────────────────────
export function HistoryPage() {
  // LOW-04 FIX: was using dynamic import() for tasks — the module is already
  // statically bundled. Use the statically-imported tasks service directly.
  const [taskList, setTaskList] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    tasks.list({ limit: 20 }).then(res => {
      if (res?.ok) setTaskList(res.data?.data || [])
      setLoading(false)
    })
  }, [])

  if (loading) return <Loading />

  return (
    <div>
      <PageHeader title="History & Reports" subtitle="Task history and generated content log" />
      <Card>
        {taskList.length === 0 ? <Empty icon="📋" title="No history yet" description="Completed tasks and reports will appear here." /> :
          taskList.map(t => (
            <div key={t.id} style={{ padding: '12px', background: 'var(--s2)', borderRadius: '8px', marginBottom: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <div style={{ fontWeight: 600, fontSize: '14px' }}>{t.engine} / {t.action}</div>
                <div style={{ fontSize: '12px', color: 'var(--muted)' }}>{new Date(t.created_at).toLocaleString()}</div>
              </div>
              <Badge color={statusBadge(t.status)}>{t.status}</Badge>
            </div>
          ))
        }
      </Card>
    </div>
  )
}
