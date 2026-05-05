import { useEffect, useState } from 'react'
import { useAuth } from '../context/AuthContext'
import { sarah, approvals as approvalsApi, tasks as tasksApi } from '../services/api'
import { PageHeader, StatCard, Card, Badge, Button, Loading, Empty, Alert, statusBadge } from '../components/ui/index.jsx'

const AGENT_COLORS = {
  sarah: '#F59E0B', james: '#3B82F6', alex: '#06B6D4', diana: '#3B82F6',
  priya: '#7C3AED', leo: '#F97316', marcus: '#EC4899', elena: '#00E5A8',
  sam: '#F59E0B', kai: '#F59E0B', vera: '#00E5A8', max: '#F59E0B',
}

export function DashboardPage() {
  const { user, workspace, capabilities, hasFullAI } = useAuth()
  const [plans, setPlans]         = useState([])
  const [approvals, setApprovals] = useState([])
  const [recentTasks, setTasks]   = useState([])
  const [proposals, setProposals] = useState([])
  const [loading, setLoading]     = useState(true)
  const [goal, setGoal]           = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [msg, setMsg]             = useState(null)

  useEffect(() => {
    Promise.all([
      approvalsApi.list(),
      tasksApi.list({ limit: 5 }),
      sarah.listProposals(),
    ]).then(([aRes, tRes, pRes]) => {
      if (aRes?.ok) setApprovals(aRes.data?.data || aRes.data || [])
      if (tRes?.ok) setTasks(tRes.data?.data || [])
      if (pRes?.ok) setProposals(pRes.data || [])
      setLoading(false)
    })
  }, [])

  const submitGoal = async () => {
    if (!goal.trim()) return
    setSubmitting(true)
    const res = await sarah.createGoal({ goal })
    setSubmitting(false)
    if (res?.ok) {
      setMsg({ type: 'success', text: 'Sarah received your goal and is building a plan! Check the Strategy Room.' })
      setGoal('')
    } else {
      setMsg({ type: 'error', text: res?.data?.error || 'Failed to submit goal' })
    }
  }

  const handleApprove = async (id) => {
    await approvalsApi.approve(id)
    setApprovals(a => a.filter(x => x.id !== id))
  }

  const handleDecline = async (id) => {
    await approvalsApi.reject(id, 'Declined by user')
    setApprovals(a => a.filter(x => x.id !== id))
  }

  const credits = capabilities?.credits?.available ?? 0
  const creditLimit = capabilities?.credits?.monthly_allowance ?? 0

  if (loading) return <Loading />

  return (
    <div>
      <PageHeader
        title={`Welcome back${user?.name ? ', ' + user.name.split(' ')[0] : ''}! 👋`}
        subtitle={workspace?.business_name || workspace?.name || 'Your AI marketing OS'}
      />

      {msg && <Alert type={msg.type} onClose={() => setMsg(null)}>{msg.text}</Alert>}

      {/* Stats */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '14px', marginBottom: '24px' }}>
        <StatCard value={creditLimit > 0 ? `${credits}/${creditLimit}` : '—'} label="Credits available" color="var(--ac)" icon="💎" />
        <StatCard value={approvals.length} label="Pending approvals" color={approvals.length > 0 ? 'var(--am)' : 'var(--muted)'} icon="✅" />
        <StatCard value={recentTasks.filter(t => t.status === 'completed').length} label="Tasks completed" color="var(--ac)" icon="⚡" />
        <StatCard value={proposals.filter(p => p.status === 'pending_approval').length} label="Proposals to review" color="var(--p)" icon="📋" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px' }}>

        {/* Goal Input */}
        {hasFullAI && (
          <Card>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '14px' }}>
              <div style={{ width: '36px', height: '36px', borderRadius: '50%', background: 'linear-gradient(135deg, #6C5CE7, #F59E0B)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '18px' }}>👩‍💼</div>
              <div>
                <div style={{ fontWeight: 700, fontSize: '14px' }}>Tell Sarah your goal</div>
                <div style={{ fontSize: '12px', color: 'var(--muted)' }}>She'll research, plan, and present a strategy</div>
              </div>
            </div>
            <textarea
              value={goal}
              onChange={e => setGoal(e.target.value)}
              placeholder="e.g. Get more leads from Instagram, Rank #1 for 'best restaurant Dubai', Launch email campaign for new menu..."
              rows={3}
              style={{ width: '100%', background: 'var(--s2)', border: '1px solid var(--border)', borderRadius: '8px', padding: '10px 12px', color: 'var(--text)', fontSize: '14px', outline: 'none', resize: 'none', fontFamily: 'inherit', marginBottom: '12px' }}
            />
            <Button variant="primary" onClick={submitGoal} disabled={submitting || !goal.trim()} style={{ width: '100%' }}>
              {submitting ? 'Sending to Sarah…' : '🚀 Submit goal to Sarah'}
            </Button>
          </Card>
        )}

        {/* Pending Approvals */}
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px', display: 'flex', justifyContent: 'space-between' }}>
            <span>Pending Approvals</span>
            {approvals.length > 0 && <Badge color="amber">{approvals.length}</Badge>}
          </div>
          {approvals.length === 0 ? (
            <Empty icon="✅" title="All clear!" description="No pending approvals." />
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
              {approvals.slice(0, 4).map(a => (
                <div key={a.id} style={{ background: 'var(--s2)', borderRadius: '10px', padding: '12px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <div>
                    <div style={{ fontSize: '13px', fontWeight: 600 }}>{a.action || a.title || 'Action required'}</div>
                    <div style={{ fontSize: '11px', color: 'var(--muted)', marginTop: '2px' }}>{a.engine} · {a.credit_cost || 0} credits</div>
                  </div>
                  <div style={{ display: 'flex', gap: '6px' }}>
                    <Button size="sm" variant="success" onClick={() => handleApprove(a.id)}>✓</Button>
                    <Button size="sm" variant="ghost" onClick={() => handleDecline(a.id)}>✗</Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>

        {/* Proposals */}
        {proposals.filter(p => p.status === 'pending_approval').length > 0 && (
          <Card>
            <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Sarah's Proposals</div>
            {proposals.filter(p => p.status === 'pending_approval').slice(0, 3).map(p => (
              <div key={p.id} style={{ background: 'var(--s2)', borderRadius: '10px', padding: '14px', marginBottom: '10px' }}>
                <div style={{ fontSize: '14px', fontWeight: 600, marginBottom: '4px' }}>{p.title}</div>
                <div style={{ fontSize: '12px', color: 'var(--muted)', marginBottom: '10px' }}>{p.description}</div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span style={{ fontSize: '12px', color: 'var(--am)' }}>💎 {p.total_credits} credits</span>
                  <div style={{ display: 'flex', gap: '8px' }}>
                    <Button size="sm" onClick={() => sarah.approveProposal(p.id)}>Approve</Button>
                    <Button size="sm" variant="ghost" onClick={() => sarah.declineProposal(p.id)}>Decline</Button>
                  </div>
                </div>
              </div>
            ))}
          </Card>
        )}

        {/* Recent Tasks */}
        <Card>
          <div style={{ fontWeight: 700, fontSize: '14px', marginBottom: '14px' }}>Recent Tasks</div>
          {recentTasks.length === 0 ? (
            <Empty icon="⚡" title="No tasks yet" description="Submit a goal to Sarah to get started." />
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
              {recentTasks.map(t => (
                <div key={t.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 12px', background: 'var(--s2)', borderRadius: '8px' }}>
                  <div>
                    <div style={{ fontSize: '13px', fontWeight: 600 }}>{t.engine} / {t.action}</div>
                    <div style={{ fontSize: '11px', color: 'var(--muted)' }}>{new Date(t.created_at).toLocaleDateString()}</div>
                  </div>
                  <Badge color={statusBadge(t.status)}>{t.status}</Badge>
                </div>
              ))}
            </div>
          )}
        </Card>

      </div>
    </div>
  )
}
