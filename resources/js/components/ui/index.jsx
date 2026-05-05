// LevelUp Design System — Reusable UI Components

// ── Card ────────────────────────────────────────────────────────
export function Card({ children, className = '', onClick, style }) {
  return (
    <div
      className={`lu-card ${className}`}
      onClick={onClick}
      style={style}
    >
      {children}
    </div>
  )
}

// ── Badge ────────────────────────────────────────────────────────
const BADGE_COLORS = {
  green:  { bg: 'rgba(0,229,168,.12)',   color: '#00E5A8' },
  red:    { bg: 'rgba(248,113,113,.12)', color: '#F87171' },
  blue:   { bg: 'rgba(59,139,245,.12)',  color: '#3B8BF5' },
  amber:  { bg: 'rgba(245,158,11,.12)',  color: '#F59E0B' },
  purple: { bg: 'rgba(108,92,231,.12)',  color: '#A78BFA' },
  p:      { bg: 'rgba(108,92,231,.12)',  color: '#6C5CE7' },
  muted:  { bg: 'rgba(148,163,184,.1)',  color: '#94A3B8' },
}

export function Badge({ children, color = 'blue' }) {
  const c = BADGE_COLORS[color] || BADGE_COLORS.blue
  return (
    <span style={{
      display: 'inline-block',
      padding: '2px 10px',
      borderRadius: '100px',
      fontSize: '11px',
      fontWeight: 600,
      background: c.bg,
      color: c.color,
    }}>
      {children}
    </span>
  )
}

// Status → color mapping
export function statusBadge(status) {
  const map = {
    active: 'green', completed: 'green', accepted: 'green', enabled: 'green',
    failed: 'red', suspended: 'red', cancelled: 'red', expired: 'red',
    pending: 'amber', trialing: 'amber', scheduled: 'amber', draft: 'amber',
    in_progress: 'blue', queued: 'blue', running: 'blue', processing: 'blue',
    free: 'muted', growth: 'purple', pro: 'blue', agency: 'green',
  }
  return map[status] || 'blue'
}

// ── Button ───────────────────────────────────────────────────────
export function Button({ children, variant = 'primary', onClick, disabled, size = 'md', type = 'button', className = '', style }) {
  const styles = {
    primary: { background: 'var(--p)', color: '#fff', border: 'none' },
    outline: { background: 'transparent', color: 'var(--text)', border: '1px solid var(--border)' },
    ghost:   { background: 'transparent', color: 'var(--muted)', border: '1px solid var(--border)' },
    danger:  { background: 'var(--rd)', color: '#fff', border: 'none' },
    success: { background: 'var(--ac)', color: '#0F1117', border: 'none' },
  }
  const sizes = {
    sm: { padding: '4px 12px', fontSize: '12px', borderRadius: '6px' },
    md: { padding: '8px 16px', fontSize: '13px', borderRadius: '8px' },
    lg: { padding: '12px 24px', fontSize: '15px', borderRadius: '10px' },
  }
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={className}
      style={{
        fontFamily: 'inherit',
        fontWeight: 600,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1,
        transition: 'opacity .15s',
        ...styles[variant],
        ...sizes[size],
        ...style,
      }}
    >
      {children}
    </button>
  )
}

// ── Input ────────────────────────────────────────────────────────
export function Input({ label, value, onChange, placeholder, type = 'text', required, rows, style, id }) {
  const inputStyle = {
    width: '100%',
    background: 'var(--s2)',
    border: '1px solid var(--border)',
    borderRadius: '8px',
    padding: '9px 12px',
    color: 'var(--text)',
    fontSize: '14px',
    outline: 'none',
    fontFamily: 'inherit',
    ...style,
  }
  return (
    <div style={{ marginBottom: '14px' }}>
      {label && <label htmlFor={id} style={{ display: 'block', fontSize: '12px', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '.5px', marginBottom: '6px' }}>{label}</label>}
      {rows ? (
        <textarea id={id} value={value} onChange={onChange} placeholder={placeholder} rows={rows} style={{ ...inputStyle, resize: 'vertical' }} />
      ) : (
        <input id={id} type={type} value={value} onChange={onChange} placeholder={placeholder} required={required} style={inputStyle} />
      )}
    </div>
  )
}

// ── Select ───────────────────────────────────────────────────────
export function Select({ label, value, onChange, options, style }) {
  return (
    <div style={{ marginBottom: '14px' }}>
      {label && <label style={{ display: 'block', fontSize: '12px', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '.5px', marginBottom: '6px' }}>{label}</label>}
      <select
        value={value}
        onChange={onChange}
        style={{
          width: '100%',
          background: 'var(--s2)',
          border: '1px solid var(--border)',
          borderRadius: '8px',
          padding: '9px 12px',
          color: 'var(--text)',
          fontSize: '14px',
          outline: 'none',
          fontFamily: 'inherit',
          ...style,
        }}
      >
        {options.map(o => (
          <option key={o.value || o} value={o.value || o}>{o.label || o}</option>
        ))}
      </select>
    </div>
  )
}

// ── Modal ────────────────────────────────────────────────────────
export function Modal({ open, onClose, title, children, footer }) {
  if (!open) return null
  return (
    <div
      onClick={(e) => e.target === e.currentTarget && onClose()}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 200, padding: '24px',
      }}
    >
      <div style={{
        background: 'var(--s1)', border: '1px solid var(--border)',
        borderRadius: '16px', padding: '28px', width: '100%', maxWidth: '520px',
        maxHeight: '90vh', overflowY: 'auto',
      }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
          <h3 style={{ fontSize: '18px', fontWeight: 700, fontFamily: 'Syne, system-ui, sans-serif' }}>{title}</h3>
          <button onClick={onClose} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: '20px', lineHeight: 1 }}>×</button>
        </div>
        {children}
        {footer && <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end', marginTop: '20px' }}>{footer}</div>}
      </div>
    </div>
  )
}

// ── Table ────────────────────────────────────────────────────────
export function Table({ headers, rows, onRowClick, emptyText = 'No data' }) {
  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '13px' }}>
        <thead>
          <tr>
            {headers.map(h => (
              <th key={h} style={{ textAlign: 'left', padding: '8px 12px', fontSize: '11px', textTransform: 'uppercase', letterSpacing: '.5px', color: 'var(--muted)', borderBottom: '1px solid var(--border)', fontWeight: 600 }}>
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr><td colSpan={headers.length} style={{ padding: '32px', textAlign: 'center', color: 'var(--muted)' }}>{emptyText}</td></tr>
          ) : rows.map((row, i) => (
            <tr key={i} onClick={() => onRowClick?.(row)} style={{ cursor: onRowClick ? 'pointer' : 'default', borderBottom: '1px solid rgba(45,55,72,.4)' }}>
              {Object.values(row).map((cell, j) => (
                <td key={j} style={{ padding: '10px 12px', verticalAlign: 'middle' }}>{cell}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ── Loading ──────────────────────────────────────────────────────
export function Loading({ text = 'Loading…' }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '48px', color: 'var(--muted)', gap: '12px' }}>
      <div style={{ width: '20px', height: '20px', border: '2px solid rgba(108,92,231,.2)', borderTopColor: 'var(--p)', borderRadius: '50%', animation: 'spin .6s linear infinite' }} />
      {text}
    </div>
  )
}

// ── Empty State ──────────────────────────────────────────────────
export function Empty({ icon = '📭', title, description, action }) {
  return (
    <div style={{ textAlign: 'center', padding: '64px 24px', color: 'var(--muted)' }}>
      <div style={{ fontSize: '40px', marginBottom: '16px' }}>{icon}</div>
      {title && <div style={{ fontSize: '16px', fontWeight: 600, color: 'var(--text)', marginBottom: '8px' }}>{title}</div>}
      {description && <div style={{ fontSize: '14px', maxWidth: '360px', margin: '0 auto 20px' }}>{description}</div>}
      {action}
    </div>
  )
}

// ── Stat Card ────────────────────────────────────────────────────
export function StatCard({ value, label, color = 'var(--ac)', icon }) {
  return (
    <div style={{ background: 'var(--s2)', border: '1px solid var(--border)', borderRadius: '12px', padding: '20px' }}>
      {icon && <div style={{ fontSize: '20px', marginBottom: '8px' }}>{icon}</div>}
      <div style={{ fontFamily: 'Syne, system-ui, sans-serif', fontSize: '32px', fontWeight: 800, color }}>{value}</div>
      <div style={{ fontSize: '13px', color: 'var(--muted)', marginTop: '4px' }}>{label}</div>
    </div>
  )
}

// ── Page Header ──────────────────────────────────────────────────
export function PageHeader({ title, subtitle, actions }) {
  return (
    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: '24px', flexWrap: 'wrap', gap: '12px' }}>
      <div>
        <h1 style={{ fontFamily: 'Syne, system-ui, sans-serif', fontSize: '22px', fontWeight: 700, marginBottom: subtitle ? '4px' : 0 }}>{title}</h1>
        {subtitle && <div style={{ fontSize: '14px', color: 'var(--muted)' }}>{subtitle}</div>}
      </div>
      {actions && <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap' }}>{actions}</div>}
    </div>
  )
}

// ── Alert / Toast ─────────────────────────────────────────────────
export function Alert({ type = 'info', children, onClose }) {
  const styles = {
    info:    { bg: 'rgba(59,139,245,.1)',  border: 'rgba(59,139,245,.3)',  color: '#3B8BF5' },
    success: { bg: 'rgba(0,229,168,.1)',   border: 'rgba(0,229,168,.3)',   color: '#00E5A8' },
    warning: { bg: 'rgba(245,158,11,.1)',  border: 'rgba(245,158,11,.3)',  color: '#F59E0B' },
    error:   { bg: 'rgba(248,113,113,.1)', border: 'rgba(248,113,113,.3)', color: '#F87171' },
  }
  const s = styles[type] || styles.info
  return (
    <div style={{ background: s.bg, border: `1px solid ${s.border}`, borderRadius: '8px', padding: '12px 16px', color: s.color, fontSize: '13px', marginBottom: '16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
      <span>{children}</span>
      {onClose && <button onClick={onClose} style={{ background: 'none', border: 'none', color: 'inherit', cursor: 'pointer', marginLeft: '12px', opacity: .7 }}>×</button>}
    </div>
  )
}
