<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LevelUp — Admin Login</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0F1117; --s1: #171A21; --s2: #1E2230; --s3: #252A3A;
      --p: #6C5CE7; --ac: #00E5A8; --rd: #F87171; --am: #F59E0B;
      --text: #E2E8F0; --muted: #94A3B8; --border: #2D3748;
    }
    body { background: var(--bg); color: var(--text); font-family: 'DM Sans', system-ui, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .card { background: var(--s1); border: 1px solid var(--border); border-radius: 16px; padding: 40px; width: 100%; max-width: 400px; }
    .logo { text-align: center; margin-bottom: 32px; }
    .logo-text { font-family: 'Syne', system-ui, sans-serif; font-size: 24px; font-weight: 700; background: linear-gradient(135deg, var(--p), var(--ac)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .logo-sub { font-size: 12px; color: var(--muted); letter-spacing: 2px; text-transform: uppercase; margin-top: 4px; }
    h1 { font-size: 20px; font-weight: 600; margin-bottom: 24px; text-align: center; }
    label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
    input { width: 100%; background: var(--s2); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; color: var(--text); font-size: 14px; outline: none; transition: border-color .2s; margin-bottom: 16px; }
    input:focus { border-color: var(--p); }
    button { width: 100%; background: var(--p); color: #fff; border: none; border-radius: 8px; padding: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: opacity .2s; margin-top: 8px; }
    button:hover { opacity: .85; }
    button:disabled { opacity: .5; cursor: not-allowed; }
    .error { background: rgba(248,113,113,.12); border: 1px solid var(--rd); border-radius: 8px; padding: 10px 14px; font-size: 13px; color: var(--rd); margin-bottom: 16px; display: none; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">
      <img src="/img/logo-icon-40.png" alt="" style="width:48px;height:48px;object-fit:contain;display:block;margin:0 auto 12px">
      <div class="logo-text">LevelUp</div>
      <div class="logo-sub">Platform Admin</div>
    </div>
    <h1>Sign in</h1>
    <div class="error" id="err"></div>
    <label>Email</label>
    <input type="email" id="email" placeholder="admin@levelupgrowth.io" autocomplete="email">
    <label>Password</label>
    <input type="password" id="pass" placeholder="••••••••" autocomplete="current-password">
    <button id="btn" onclick="login()">Sign in to Admin</button>
  </div>

  <script>
    async function login() {
      const btn = document.getElementById('btn');
      const err = document.getElementById('err');
      const email = document.getElementById('email').value.trim();
      const pass = document.getElementById('pass').value;

      err.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Signing in…';

      try {
        const res = await fetch('/api/auth/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ email, password: pass })
        });

        const data = await res.json();

        if (!res.ok || !data.access_token) {
          throw new Error(data.message || data.error || 'Invalid credentials');
        }

        // Check platform admin flag
        if (!data.user?.is_platform_admin) {
          throw new Error('This account does not have admin access');
        }

        localStorage.setItem('lu_admin_token', data.access_token);
        localStorage.setItem('lu_admin_user', JSON.stringify(data.user));
        window.location.href = '/admin';

      } catch (e) {
        err.textContent = e.message;
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Sign in to Admin';
      }
    }

    // Enter key
    document.addEventListener('keydown', e => { if (e.key === 'Enter') login(); });

    // Redirect if already logged in
    const token = localStorage.getItem('lu_admin_token');
    if (token && window.location.pathname === '/admin/login') {
      window.location.href = '/admin';
    }
  </script>
</body>
</html>
