import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { Badge, statusBadge } from '../ui/index.jsx'

const NAV = [
  { group: 'Workspace', items: [
    { path: '/dashboard',     icon: '◉', label: 'Workspace' },
    { path: '/strategy',      icon: '🧭', label: 'Strategy Room' },
    { path: '/campaigns',     icon: '🚀', label: 'Campaigns' },
    { path: '/history',       icon: '📋', label: 'History & Reports' },
  ]},
  { group: 'Tools', items: [
    { path: '/crm',           icon: '📊', label: 'CRM' },
    { path: '/seo',           icon: '🔍', label: 'SEO' },
    { path: '/write',         icon: '✍️', label: 'Write' },
    { path: '/creative',      icon: '🎨', label: 'Creative' },
    { path: '/marketing',     icon: '📧', label: 'Marketing' },
    { path: '/social',        icon: '📱', label: 'Social' },
    { path: '/builder',       icon: '🏗️', label: 'Builder' },
    { path: '/calendar',      icon: '📅', label: 'Calendar' },
  ]},
  { group: 'Account', items: [
    { path: '/approvals',     icon: '✅', label: 'Approvals' },
    { path: '/settings',      icon: '⚙️', label: 'Settings' },
  ]},
]

export function AppLayout({ children }) {
  const { user, workspace, workspaces, capabilities, planSlug, logout, switchWorkspace } = useAuth()
  const location = useLocation()
  const [collapsed, setCollapsed] = useState(false)
  const [switching, setSwitching] = useState(false)

  const credits = capabilities?.credits?.available ?? 0
  const creditLimit = capabilities?.credits?.monthly_allowance ?? 0

  const handleSwitch = async (wsId) => {
    if (wsId === workspace?.id) return
    setSwitching(true)
    await switchWorkspace(wsId)
    setSwitching(false)
  }

  return (
    <div style={{ display: 'flex', height: '100vh', overflow: 'hidden' }}>
      {/* Sidebar */}
      <aside style={{
        width: collapsed ? '60px' : '220px',
        background: 'var(--s1)',
        borderRight: '1px solid var(--border)',
        display: 'flex',
        flexDirection: 'column',
        flexShrink: 0,
        transition: 'width .2s',
        overflowX: 'hidden',
      }}>
        {/* Logo */}
        <div style={{ padding: '18px 16px 14px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', gap: '10px', justifyContent: collapsed ? 'center' : 'space-between' }}>
          {!collapsed && (
            <div>
              <div style={{ fontFamily: 'Syne, system-ui, sans-serif', fontSize: '18px', fontWeight: 800, background: 'linear-gradient(135deg, #6C5CE7, #00E5A8)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>LevelUp</div>
              <div style={{ fontSize: '10px', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '1px' }}>{workspace?.name || 'Platform'}</div>
            </div>
          )}
          <button onClick={() => setCollapsed(!collapsed)} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: '16px', padding: '2px' }}>
            {collapsed ? '→' : '←'}
          </button>
        </div>

        {/* Navigation */}
        <nav style={{ flex: 1, overflow: 'auto', padding: '8px 0' }}>
          {NAV.map(({ group, items }) => (
            <div key={group}>
              {!collapsed && <div style={{ padding: '10px 14px 4px', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '1px', color: 'var(--muted)' }}>{group}</div>}
              {items.map(({ path, icon, label }) => {
                const active = location.pathname === path || (path !== '/dashboard' && location.pathname.startsWith(path))
                return (
                  <Link
                    key={path}
                    to={path}
                    title={label}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: collapsed ? 0 : '10px',
                      justifyContent: collapsed ? 'center' : 'flex-start',
                      padding: '8px 14px',
                      margin: '1px 8px',
                      borderRadius: '8px',
                      fontSize: '13px',
                      color: active ? 'var(--p)' : 'var(--muted)',
                      background: active ? 'rgba(108,92,231,.1)' : 'transparent',
                      transition: 'all .15s',
                      textDecoration: 'none',
                    }}
                  >
                    <span style={{ fontSize: '15px' }}>{icon}</span>
                    {!collapsed && <span>{label}</span>}
                  </Link>
                )
              })}
            </div>
          ))}
        </nav>

        {/* Footer */}
        {!collapsed && (
          <div style={{ padding: '12px 14px', borderTop: '1px solid var(--border)' }}>
            {/* Credits bar */}
            {creditLimit > 0 && (
              <div style={{ marginBottom: '10px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '11px', color: 'var(--muted)', marginBottom: '4px' }}>
                  <span>Credits</span><span>{credits} / {creditLimit}</span>
                </div>
                <div style={{ height: '3px', background: 'var(--s3)', borderRadius: '100px' }}>
                  <div style={{ height: '100%', width: `${Math.min(100, creditLimit > 0 ? (credits / creditLimit) * 100 : 0)}%`, background: 'var(--ac)', borderRadius: '100px' }} />
                </div>
              </div>
            )}
            {/* MEDIUM-02: Workspace switcher — only shown when user belongs to multiple workspaces */}
            {workspaces.length > 1 && (
              <div style={{ marginBottom: '8px' }}>
                <select
                  value={workspace?.id || ''}
                  onChange={e => handleSwitch(Number(e.target.value))}
                  disabled={switching}
                  style={{ width: '100%', background: 'var(--s2)', border: '1px solid var(--border)', borderRadius: '6px', padding: '4px 8px', color: 'var(--text)', fontSize: '11px', fontFamily: 'inherit', cursor: 'pointer' }}
                >
                  {workspaces.map(ws => (
                    <option key={ws.id} value={ws.id}>{ws.name}</option>
                  ))}
                </select>
              </div>
            )}
            <div style={{ fontSize: '12px', color: 'var(--muted)', marginBottom: '6px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '120px' }}>{user?.name}</span>
              <Badge color={statusBadge(planSlug)}>{planSlug}</Badge>
            </div>
            <button onClick={logout} style={{ width: '100%', background: 'transparent', border: '1px solid var(--border)', borderRadius: '6px', padding: '5px 10px', color: 'var(--muted)', cursor: 'pointer', fontSize: '12px', fontFamily: 'inherit' }}>
              Sign out
            </button>
          </div>
        )}
      </aside>

      {/* Main content */}
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
        <main style={{ flex: 1, overflowY: 'auto', padding: '24px' }}>
          {children}
        </main>
      </div>
    </div>
  )
}
