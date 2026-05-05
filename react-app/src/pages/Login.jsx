import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';

export default function Login() {
  const [mode, setMode] = useState('login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const { login, register } = useAuthStore();
  const navigate = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault(); setError(''); setLoading(true);
    try {
      if (mode === 'login') { await login(email, password); }
      else { await register(name, email, password); }
      navigate('/');
    } catch (err) { setError(err.message || 'Failed'); }
    finally { setLoading(false); }
  };

  return (
    <div className="min-h-screen bg-bg flex items-center justify-center p-4">
      <div className="w-full max-w-sm bg-s1 border border-border rounded-2xl p-8">
        <div className="text-center mb-8">
          <div className="text-4xl mb-2">⚡</div>
          <h1 className="font-heading text-xl font-bold">{mode === 'login' ? 'Welcome Back' : 'Create Account'}</h1>
          <p className="text-gray-500 text-sm mt-1">{mode === 'login' ? 'Sign in to your dashboard' : 'Start free. No credit card.'}</p>
        </div>
        <form onSubmit={handleSubmit} className="space-y-4">
          {mode === 'signup' && <input value={name} onChange={e => setName(e.target.value)} placeholder="Full Name" className="w-full bg-s2 border border-border rounded-lg px-4 py-2.5 text-sm text-gray-200 focus:border-primary outline-none" />}
          <input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="Email" className="w-full bg-s2 border border-border rounded-lg px-4 py-2.5 text-sm text-gray-200 focus:border-primary outline-none" />
          <input type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="Password" className="w-full bg-s2 border border-border rounded-lg px-4 py-2.5 text-sm text-gray-200 focus:border-primary outline-none" />
          {error && <div className="text-red text-xs">{error}</div>}
          <button disabled={loading} className="w-full bg-primary text-white font-heading font-bold text-sm rounded-lg py-2.5 hover:bg-primary/90 disabled:opacity-50">{loading ? 'Loading…' : mode === 'login' ? 'Sign In' : 'Create Account'}</button>
        </form>
        <div className="text-center mt-4 text-xs text-gray-500">
          {mode === 'login' ? <span>No account? <button onClick={() => setMode('signup')} className="text-primary font-semibold">Sign Up</button></span>
            : <span>Have an account? <button onClick={() => setMode('login')} className="text-primary font-semibold">Sign In</button></span>}
        </div>
      </div>
    </div>
  );
}
