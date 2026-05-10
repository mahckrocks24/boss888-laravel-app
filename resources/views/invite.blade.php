<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Join LevelUp — Team Invitation</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --bg: #0F1117; --s1: #171A21; --s2: #1E2230; --p: #6C5CE7; --ac: #00E5A8; --rd: #F87171; --text: #E2E8F0; --muted: #94A3B8; --border: #2D3748; }
    body { background: var(--bg); color: var(--text); font-family: 'DM Sans', system-ui, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .card { background: var(--s1); border: 1px solid var(--border); border-radius: 16px; padding: 40px; width: 100%; max-width: 420px; }
    .logo { font-family: 'Syne', system-ui, sans-serif; font-size: 22px; font-weight: 700; background: linear-gradient(135deg, var(--p), var(--ac)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-align: center; margin-bottom: 24px; }
    .invite-info { background: rgba(108,92,231,.08); border: 1px solid rgba(108,92,231,.2); border-radius: 10px; padding: 16px; margin-bottom: 24px; }
    label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
    input { width: 100%; background: var(--s2); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; color: var(--text); font-size: 14px; outline: none; margin-bottom: 14px; }
    input:focus { border-color: var(--p); }
    .btn { width: 100%; background: var(--p); color: #fff; border: none; border-radius: 8px; padding: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: opacity .2s; margin-top: 4px; }
    .btn:hover { opacity: .85; }
    .btn:disabled { opacity: .5; }
    .error { background: rgba(248,113,113,.1); border: 1px solid var(--rd); border-radius: 8px; padding: 10px 14px; color: var(--rd); font-size: 13px; margin-bottom: 12px; display: none; }
    .success { background: rgba(0,229,168,.1); border: 1px solid var(--ac); border-radius: 8px; padding: 10px 14px; color: var(--ac); font-size: 13px; margin-bottom: 12px; display: none; }
    .loading { text-align: center; color: var(--muted); padding: 20px; }
    a { color: var(--p); text-decoration: none; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo" style="display:flex;align-items:center;justify-content:center;gap:8px"><img src="/img/logo-icon-40.png" alt="" style="width:32px;height:32px;object-fit:contain"><span>LevelUp</span></div>
    <div id="invite-preview" class="loading">Loading invitation…</div>
    <div id="error" class="error"></div>
    <div id="success" class="success"></div>
    <div id="form-container" style="display:none"></div>
  </div>

  <script>
    const token = '{{ $token }}';
    // MEDIUM-06 FIX: Use absolute API URL so this page works when served from
    // levelupgrowth.io (marketing domain) while the API lives at app.levelupgrowth.io
    const API_BASE = '{{ rtrim(config("app.url"), "/") }}/api';
    const APP_URL  = '{{ config("app.url") }}';

    async function loadInvite() {
      try {
        const res  = await fetch(`${API_BASE}/invite/${token}`);
        const data = await res.json();

        if (!data.valid) {
          document.getElementById('invite-preview').innerHTML =
            `<div style="color:var(--rd);text-align:center">${data.error || 'Invalid invitation'}</div>`;
          return;
        }

        document.getElementById('invite-preview').innerHTML = `
          <div class="invite-info">
            <div style="font-size:18px;font-weight:600">📌 ${data.workspace}</div>
            <div style="font-size:13px;color:var(--muted);margin-top:4px">You're invited as <strong>${data.role}</strong></div>
            <div style="font-size:12px;color:var(--muted);margin-top:8px">Invited by ${data.invited_by}</div>
          </div>`;

        const fc = document.getElementById('form-container');
        fc.style.display = 'block';
        fc.innerHTML = data.user_exists
          ? `<p style="font-size:14px;color:var(--muted);margin-bottom:16px">
               Your account (<strong>${data.email}</strong>) already exists. Accept to join.
             </p>
             <button class="btn" onclick="accept(false)">Accept Invitation</button>`
          : `<p style="font-size:13px;color:var(--muted);margin-bottom:16px">
               Create your account to join <strong>${data.workspace}</strong>.
             </p>
             <label>Full Name</label>
             <input type="text" id="name" placeholder="Your full name">
             <label>Password</label>
             <input type="password" id="pass" placeholder="Min 8 characters">
             <button class="btn" onclick="accept(true)">Create Account & Join</button>
             <div style="font-size:13px;color:var(--muted);text-align:center;margin-top:12px">
               Already have an account? <a href="${APP_URL}/app">Sign in</a>
             </div>`;
      } catch(e) {
        document.getElementById('invite-preview').innerHTML =
          '<div style="color:var(--rd)">Failed to load invitation. Please try again.</div>';
      }
    }

    async function accept(isNew) {
      const errEl = document.getElementById('error');
      const sucEl = document.getElementById('success');
      errEl.style.display = 'none';

      const body = {};
      if (isNew) {
        body.name     = document.getElementById('name')?.value?.trim();
        body.password = document.getElementById('pass')?.value;
        if (!body.name || !body.password || body.password.length < 8) {
          errEl.textContent = 'Please enter your name and a password (min 8 characters)';
          errEl.style.display = 'block';
          return;
        }
      }

      const btn = document.querySelector('.btn');
      btn.disabled = true;
      btn.textContent = 'Joining…';

      try {
        const res  = await fetch(`${API_BASE}/invite/${token}/accept`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Failed to accept invitation');

        sucEl.textContent = `Welcome to ${data.workspace}! Redirecting…`;
        sucEl.style.display = 'block';
        document.getElementById('form-container').style.display = 'none';
        setTimeout(() => window.location.href = `${APP_URL}/app`, 2000);
      } catch(e) {
        errEl.textContent = e.message;
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = isNew ? 'Create Account & Join' : 'Accept Invitation';
      }
    }

    loadInvite();
  </script>
</body>
</html>
