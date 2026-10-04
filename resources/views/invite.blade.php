<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Join your team · LevelUpGrowth</title>
  <link rel="icon" href="/img/logo-icon-40.png">
  <style>
    /* TEAM-1 (2026-10-04): was a script error (APP_URL declared twice) that left the page on "Loading invitation…" */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --bg: #0F1117; --s1: #171A21; --s2: #1E2230; --p: #6C5CE7; --ac: #00E5A8; --rd: #F87171; --text: #E2E8F0; --muted: #94A3B8; --border: #2D3748; }
    body { background: radial-gradient(1200px 600px at 50% -10%, rgba(108,92,231,.18), transparent 60%), var(--bg); color: var(--text); font-family: 'DM Sans', system-ui, -apple-system, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
    .card { background: var(--s1); border: 1px solid var(--border); border-radius: 18px; padding: 36px 32px; width: 100%; max-width: 440px; box-shadow: 0 24px 60px rgba(0,0,0,.35); }
    .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 26px; font: 700 20px 'Syne', system-ui, sans-serif; letter-spacing: -.01em; }
    .brand img { width: 30px; height: 30px; object-fit: contain; }
    .eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--p); text-align: center; }
    h1 { font: 700 24px 'Syne', system-ui, sans-serif; text-align: center; margin: 8px 0 6px; line-height: 1.2; }
    .lead { font-size: 14px; color: var(--muted); text-align: center; line-height: 1.55; margin-bottom: 22px; }
    .who { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-bottom: 22px; }
    .pill { font-size: 11.5px; font-weight: 700; padding: 5px 12px; border-radius: 99px; border: 1px solid var(--border); color: var(--text); background: var(--s2); }
    label { display: block; font-size: 11px; font-weight: 700; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .06em; }
    input { width: 100%; background: var(--s2); border: 1px solid var(--border); border-radius: 10px; padding: 11px 14px; color: var(--text); font-size: 15px; outline: none; margin-bottom: 14px; }
    input:focus { border-color: var(--p); box-shadow: 0 0 0 3px rgba(108,92,231,.2); }
    input[readonly] { color: var(--muted); }
    .btn { width: 100%; background: var(--p); color: #fff; border: none; border-radius: 10px; padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; transition: filter .15s; margin-top: 4px; }
    .btn:hover { filter: brightness(1.08); }
    .btn:disabled { opacity: .55; cursor: wait; }
    .note { font-size: 12.5px; color: var(--muted); text-align: center; margin-top: 14px; line-height: 1.5; }
    .msg { border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 14px; display: none; line-height: 1.5; }
    .msg.err { background: rgba(248,113,113,.1); border: 1px solid var(--rd); color: var(--rd); }
    .msg.ok { background: rgba(0,229,168,.1); border: 1px solid var(--ac); color: var(--ac); }
    .loading { text-align: center; color: var(--muted); padding: 28px 0; font-size: 14px; }
    a { color: var(--p); text-decoration: none; font-weight: 600; }
  </style>
</head>
<body>
  <main class="card">
    <div class="brand"><img src="/img/logo-icon-40.png" alt=""><span>LevelUpGrowth</span></div>
    <div id="body"><div class="loading">Opening your invitation…</div></div>
  </main>

  <script>
    const TOKEN = @json($token);
    const API_BASE = @json(rtrim(config('app.url'), '/') . '/api');
    const APP_URL = @json(rtrim(config('app.url'), '/'));
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const roleWords = r => r === 'admin' ? 'Admin' : r === 'viewer' ? 'Viewer' : 'Team member';
    let invite = null;

    function show(kind, text) { const el = document.getElementById('msg'); if (!el) return; el.className = 'msg ' + kind; el.textContent = text; el.style.display = 'block'; }

    async function load() {
      const body = document.getElementById('body');
      try {
        const res = await fetch(`${API_BASE}/invite/${encodeURIComponent(TOKEN)}`);
        const data = await res.json();
        if (!data.valid) {
          body.innerHTML = `<div class="eyebrow">Invitation</div><h1>This link no longer works</h1><p class="lead">${esc(data.error === 'Invite has expired' ? 'The invitation has expired.' : 'The invitation was already used or cancelled.')} Ask the person who invited you to send a new one.</p><a class="btn" style="display:block;text-align:center" href="${APP_URL}/login/">Sign in</a>`;
          return;
        }
        invite = data;
        const head = `<div class="eyebrow">You are invited</div><h1>Join ${esc(data.workspace)}</h1>
          <p class="lead">${esc(data.invited_by || 'The owner')} invited you to work on ${esc(data.workspace)} with the team and Sarah, its AI marketing manager.</p>
          <div class="who"><span class="pill">${esc(roleWords(data.role))}</span><span class="pill">${esc(data.email)}</span></div><div id="msg" class="msg"></div>`;
        body.innerHTML = data.user_exists
          ? head + `<button class="btn" id="go" onclick="accept(false)">Accept and join</button><p class="note">You already have a LevelUpGrowth login with this email. After joining, sign in as usual and pick ${esc(data.workspace)}.</p>`
          : head + `<label for="name">Your full name</label><input id="name" type="text" autocomplete="name" placeholder="First and last name">
             <label for="pass">Choose a password</label><input id="pass" type="password" autocomplete="new-password" placeholder="At least 8 characters">
             <button class="btn" id="go" onclick="accept(true)">Create my login and join</button>
             <p class="note">Already use LevelUpGrowth with another email? Ask for the invitation to be sent to that address instead.</p>`;
      } catch (e) {
        body.innerHTML = '<div class="loading">We could not open the invitation. Please refresh the page.</div>';
      }
    }

    async function accept(isNew) {
      const payload = {};
      if (isNew) {
        payload.name = (document.getElementById('name').value || '').trim();
        payload.password = document.getElementById('pass').value || '';
        if (!payload.name) return show('err', 'Enter your name.');
        if (payload.password.length < 8) return show('err', 'Choose a password of at least 8 characters.');
      }
      const btn = document.getElementById('go'); btn.disabled = true; btn.textContent = 'Joining…';
      try {
        const res = await fetch(`${API_BASE}/invite/${encodeURIComponent(TOKEN)}/accept`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'The invitation could not be accepted.');
        show('ok', `You have joined ${data.workspace || invite.workspace}. Taking you to sign in…`);
        btn.style.display = 'none';
        setTimeout(() => { window.location.href = `${APP_URL}/login/?email=${encodeURIComponent(invite.email)}`; }, 1800);
      } catch (e) {
        show('err', e.message);
        btn.disabled = false; btn.textContent = isNew ? 'Create my login and join' : 'Accept and join';
      }
    }
    load();
  </script>
  <script src="/app/js/lu-keyboard.js?v=kb3"></script>
</body>
</html>
