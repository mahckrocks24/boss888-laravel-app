import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Button, Input, Alert } from '../components/ui/index.jsx'

const cardStyle = {
  background: 'var(--s1)',
  border: '1px solid var(--border)',
  borderRadius: '16px',
  padding: '40px',
  width: '100%',
  maxWidth: '420px',
}

const logoStyle = {
  fontFamily: 'Syne, system-ui, sans-serif',
  fontSize: '24px',
  fontWeight: 800,
  background: 'linear-gradient(135deg, #6C5CE7, #00E5A8)',
  WebkitBackgroundClip: 'text',
  WebkitTextFillColor: 'transparent',
  textAlign: 'center',
  marginBottom: '28px',
}

export function LoginPage() {
  const { login } = useAuth()
  const navigate   = useNavigate()
  const [email, setEmail]       = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading]   = useState(false)
  const [error, setError]       = useState('')

  const handleLogin = async () => {
    if (!email || !password) { setError('Please fill in all fields'); return }
    setLoading(true); setError('')
    const res = await login(email, password)
    setLoading(false)
    if (res.success) navigate('/dashboard')
    else setError(res.error)
  }

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '24px' }}>
      <div style={cardStyle}>
        <div style={logoStyle}>LevelUp</div>
        <h2 style={{ fontSize: '20px', fontWeight: 700, textAlign: 'center', marginBottom: '24px', fontFamily: 'Syne, system-ui, sans-serif' }}>Sign in</h2>
        {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
        <Input label="Email" type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="you@company.com" />
        <Input label="Password" type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="••••••••" />
        <Button variant="primary" size="lg" style={{ width: '100%', marginTop: '8px' }} onClick={handleLogin} disabled={loading}>
          {loading ? 'Signing in…' : 'Sign in'}
        </Button>
        <div style={{ textAlign: 'center', marginTop: '16px', fontSize: '13px', color: 'var(--muted)' }}>
          Don't have an account?{' '}
          <Link to="/register" style={{ color: 'var(--p)' }}>Start free trial</Link>
        </div>
      </div>
    </div>
  )
}

export function RegisterPage() {
  const { register } = useAuth()
  const navigate      = useNavigate()
  const [form, setForm]   = useState({ name: '', email: '', password: '', business_name: '', industry: '', goal: 'leads' })
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState('')

  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

  const handleRegister = async () => {
    if (!form.name || !form.email || !form.password || !form.business_name) { setError('Please fill in all required fields'); return }
    if (form.password.length < 8) { setError('Password must be at least 8 characters'); return }
    setLoading(true); setError('')
    const res = await register(form)
    setLoading(false)
    if (res.success) navigate('/dashboard')
    else setError(res.error)
  }

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '24px' }}>
      <div style={{ ...cardStyle, maxWidth: '480px' }}>
        <div style={logoStyle}>LevelUp</div>
        <h2 style={{ fontSize: '20px', fontWeight: 700, textAlign: 'center', marginBottom: '6px', fontFamily: 'Syne, system-ui, sans-serif' }}>Create your account</h2>
        <div style={{ textAlign: 'center', color: 'var(--muted)', fontSize: '13px', marginBottom: '24px' }}>Free 3-day trial · No credit card needed</div>
        {error && <Alert type="error" onClose={() => setError('')}>{error}</Alert>}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
          <Input label="Full Name" value={form.name} onChange={set('name')} placeholder="Your name" />
          <Input label="Business Name" value={form.business_name} onChange={set('business_name')} placeholder="Acme Co" />
        </div>
        <Input label="Email" type="email" value={form.email} onChange={set('email')} placeholder="you@company.com" />
        <Input label="Password" type="password" value={form.password} onChange={set('password')} placeholder="Min 8 characters" />
        <div style={{ marginBottom: '14px' }}>
          <label style={{ display: 'block', fontSize: '12px', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '.5px', marginBottom: '6px' }}>Industry</label>
          <select value={form.industry} onChange={set('industry')} style={{ width: '100%', background: 'var(--s2)', border: '1px solid var(--border)', borderRadius: '8px', padding: '9px 12px', color: 'var(--text)', fontSize: '14px', fontFamily: 'inherit' }}>
            <option value="">Select industry</option>
            {['Restaurant & Food','Retail','Real Estate','Healthcare','Professional Services','Technology','Education','Beauty & Fashion','Construction','Other'].map(i => <option key={i}>{i}</option>)}
          </select>
        </div>
        <Button variant="primary" size="lg" style={{ width: '100%', marginTop: '8px' }} onClick={handleRegister} disabled={loading}>
          {loading ? 'Creating account…' : 'Create account — start free trial'}
        </Button>
        <div style={{ textAlign: 'center', marginTop: '14px', fontSize: '13px', color: 'var(--muted)' }}>
          Already have an account?{' '}
          <Link to="/login" style={{ color: 'var(--p)' }}>Sign in</Link>
        </div>
      </div>
    </div>
  )
}
