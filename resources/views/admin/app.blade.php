<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" onload="this.rel='stylesheet'">
  <noscript><link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"></noscript>
  <title>LevelUp Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:#0F1117;--s1:#171A21;--s2:#1E2230;--s3:#252A3A;
      --bd:rgba(255,255,255,.07);--bd2:rgba(255,255,255,.13);
      --p:#6C5CE7;--pg:rgba(108,92,231,.22);--ps:rgba(108,92,231,.1);
      --ac:#00E5A8;--ag:rgba(0,229,168,.18);--as:rgba(0,229,168,.08);
      --bl:#3B8BF5;--am:#F59E0B;--rd:#F87171;--pu:#A78BFA;
      --t1:#E8EDF5;--t2:#8B97B0;--t3:#4A566B;
      --fh:'Syne',sans-serif;--fb:'DM Sans',sans-serif;
      --r:10px;--rg:14px;--rx:20px;
      --text:var(--t1);--muted:var(--t2);--border:var(--bd);
    }
    body { background: var(--bg); color: var(--text); font-family: var(--fb, 'DM Sans', system-ui, sans-serif); display: flex; height: 100vh; overflow: hidden; }

    /* Sidebar */
    .sidebar { width: 220px; background: var(--s1); border-right: 1px solid var(--border); display: flex; flex-direction: column; flex-shrink: 0; }
    .sidebar-logo { padding: 20px 16px 16px; border-bottom: 1px solid var(--border); }
    .logo-text { font-family: var(--fh, 'Syne', system-ui, sans-serif); font-size: 18px; font-weight: 700; background: linear-gradient(135deg, var(--p), var(--ac)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .logo-sub { font-size: 10px; color: var(--muted); letter-spacing: 1.5px; text-transform: uppercase; }
    nav { flex: 1; overflow-y: auto; padding: 8px 0; }
    .nav-group { padding: 12px 12px 4px; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); }
    .nav-item { display: flex; align-items: center; gap: 10px; padding: 8px 16px; cursor: pointer; border-radius: 6px; margin: 1px 8px; font-size: 14px; color: var(--muted); transition: all .15s; }
    .nav-item:hover { background: var(--s2); color: var(--text); }
    .nav-item.active { background: rgba(108,92,231,.15); color: var(--p); }
    .nav-icon { width: 16px; text-align: center; }
    .sidebar-footer { padding: 12px 16px; border-top: 1px solid var(--border); }
    .admin-badge { font-size: 12px; color: var(--muted); }

    /* Main */
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
    .topbar { background: var(--s1); border-bottom: 1px solid var(--border); padding: 0 24px; height: 56px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .page-title { font-family: var(--fh, 'Syne', system-ui, sans-serif); font-size: 18px; font-weight: 600; }
    .topbar-right { display: flex; align-items: center; gap: 12px; }
    .btn { background: var(--p); color: #fff; border: none; border-radius: 6px; padding: 6px 14px; font-size: 13px; font-weight: 600; cursor: pointer; transition: opacity .2s; }
    .btn:hover { opacity: .85; }
    .btn-sm { padding: 4px 10px; font-size: 12px; }
    .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--muted); }
    .btn-danger { background: var(--rd); }

    /* Content */
    .content { flex: 1; overflow-y: auto; padding: 24px; }

    /* Cards */
    .card { background: var(--s1); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
    .card-title { font-size: 14px; font-weight: 600; margin-bottom: 16px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .stat-card { background: var(--s2); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
    .stat-value { font-size: 28px; font-weight: 700; font-family: var(--fh, 'Syne', system-ui, sans-serif); }
    .stat-label { font-size: 12px; color: var(--muted); margin-top: 4px; }

    /* Table */
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: left; padding: 8px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); border-bottom: 1px solid var(--border); }
    td { padding: 10px 12px; border-bottom: 1px solid rgba(45,55,72,.5); vertical-align: middle; }
    tr:hover td { background: rgba(255,255,255,.02); }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 100px; font-size: 11px; font-weight: 600; }
    .badge-green { background: rgba(0,229,168,.12); color: var(--ac); }
    .badge-red { background: rgba(248,113,113,.12); color: var(--rd); }
    .badge-blue { background: rgba(59,139,245,.12); color: var(--bl); }
    .badge-amber { background: rgba(245,158,11,.12); color: var(--am); }
    .badge-purple { background: rgba(108,92,231,.12); color: var(--p); }

    /* Form */
    .form-group { margin-bottom: 16px; }
    label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
    input, select, textarea { width: 100%; background: var(--s2); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; color: var(--text); font-size: 13px; outline: none; }
    input:focus, select:focus { border-color: var(--p); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .search-bar { background: var(--s2); border: 1px solid var(--border); border-radius: 8px; padding: 8px 14px; color: var(--text); font-size: 13px; outline: none; width: 240px; }
    .search-bar:focus { border-color: var(--p); }

    /* Loading / Error */
    .loading { text-align: center; padding: 40px; color: var(--muted); }
    .error-msg { background: rgba(248,113,113,.1); border: 1px solid var(--rd); border-radius: 8px; padding: 12px 16px; color: var(--rd); font-size: 13px; margin-bottom: 16px; }
    .success-msg { background: rgba(0,229,168,.1); border: 1px solid var(--ac); border-radius: 8px; padding: 12px 16px; color: var(--ac); font-size: 13px; margin-bottom: 16px; }

    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; z-index: 100; display: none; }
    .modal-overlay.open { display: flex; }
    .modal { background: var(--s1); border: 1px solid var(--border); border-radius: 16px; padding: 28px; width: 100%; max-width: 480px; }
    .modal h3 { font-size: 18px; font-weight: 600; margin-bottom: 20px; }
    .modal-footer { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

    /* ================================================================
       Bella AI Chat Panel
       ================================================================ */
    .bella-toggle {
      position: fixed; bottom: 24px; right: 24px; z-index: 198;
      height: 48px; border-radius: 24px; border: none; cursor: pointer;
      background: linear-gradient(135deg, #6C5CE7, #A78BFA);
      color: #fff; font-family: var(--fb, 'DM Sans', system-ui, sans-serif); font-size: 14px; font-weight: 600;
      display: flex; align-items: center; gap: 8px;
      padding: 0 20px;
      transition: transform .2s, box-shadow .2s;
      box-shadow: 0 4px 16px rgba(108,92,231,.4);
    }
    .bella-toggle:hover { transform: scale(1.05); box-shadow: 0 6px 24px rgba(108,92,231,.6); }
    .bella-toggle.active { box-shadow: 0 0 0 3px rgba(108,92,231,.3); }
    .bella-toggle-pulse {
      position: absolute; inset: -3px; border-radius: 24px;
      border: 2px solid var(--p); opacity: 0; animation: bellaPulse 2s infinite;
    }
    @keyframes bellaPulse { 0%{opacity:.6;transform:scale(1)} 100%{opacity:0;transform:scale(1.4)} }

    .bella-panel {
      position: fixed; bottom: 84px; right: 24px; width: 400px; height: 600px;
      background: var(--s1); border: 1px solid var(--border); border-radius: var(--rx, 20px);
      z-index: 200; display: flex; flex-direction: column;
      transform: translateY(20px) scale(.95); opacity: 0; pointer-events: none;
      transition: transform .25s cubic-bezier(.4,0,.2,1), opacity .25s;
      box-shadow: 0 12px 40px rgba(0,0,0,.5);
    }
    .bella-panel.open {
      transform: translateY(0) scale(1); opacity: 1; pointer-events: auto;
    }
    /* Overlay disabled — Bella chat is non-modal so the admin can browse while chatting */
    .bella-overlay { display: none; }
    .bella-overlay.open { display: none; }

    .bella-header {
      padding: 16px 20px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 12px; flex-shrink: 0;
    }
    .bella-avatar {
      width: 40px; height: 40px; border-radius: 50%;
      background: linear-gradient(135deg, #6C5CE7, #A78BFA);
      display: flex; align-items: center; justify-content: center;
      font-family: var(--fh, 'Syne', system-ui, sans-serif); font-size: 18px; font-weight: 700; color: #fff;
      flex-shrink: 0;
    }
    .bella-header-info { flex: 1; }
    .bella-header-name { font-size: 15px; font-weight: 600; color: var(--text); }
    .bella-header-role { font-size: 11px; color: var(--muted); letter-spacing: .5px; }
    .bella-close {
      width: 32px; height: 32px; border-radius: 8px; border: none;
      background: var(--s2); color: var(--muted); font-size: 18px; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: background .15s, color .15s;
    }
    .bella-close:hover { background: var(--s3); color: var(--text); }

    .bella-messages {
      flex: 1; overflow-y: auto; padding: 16px 20px;
      display: flex; flex-direction: column; gap: 12px;
    }
    .bella-messages::-webkit-scrollbar { width: 4px; }
    .bella-messages::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

    .bella-msg {
      max-width: 85%; padding: 10px 14px; border-radius: 12px;
      font-size: 13px; line-height: 1.5; word-wrap: break-word;
    }
    .bella-msg-user {
      align-self: flex-end; background: linear-gradient(135deg, #6C5CE7, #7C6CF7);
      color: #fff; border-bottom-right-radius: 4px;
    }
    .bella-msg-bella {
      align-self: flex-start; background: var(--s2); color: var(--text);
      border-bottom-left-radius: 4px; max-width: 90%;
    }
    .bella-msg-avatar-row {
      display: flex; align-items: flex-start; gap: 8px;
    }
    .bella-msg-mini-avatar {
      width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, #6C5CE7, #A78BFA);
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; color: #fff; margin-top: 1px;
    }
    .bella-msg-content { flex: 1; min-width: 0; }
    .bella-msg-content h3 { font-size: 14px; font-weight: 600; margin: 8px 0 4px; color: var(--text); }
    .bella-msg-content h3:first-child { margin-top: 0; }
    .bella-msg-content strong { color: var(--text); font-weight: 600; }
    .bella-msg-content ul, .bella-msg-content ol { margin: 4px 0; padding-left: 18px; }
    .bella-msg-content li { margin: 2px 0; }
    .bella-msg-content code {
      background: var(--s3); padding: 1px 6px; border-radius: 4px;
      font-family: 'JetBrains Mono', monospace; font-size: 12px;
    }
    .bella-msg-content pre {
      background: var(--bg); padding: 10px 12px; border-radius: 8px;
      overflow-x: auto; margin: 6px 0; font-size: 12px;
    }
    .bella-msg-content pre code { background: none; padding: 0; }

    .bella-typing {
      align-self: flex-start; display: flex; align-items: center; gap: 8px;
      padding: 12px 14px; background: var(--s2); border-radius: 12px;
      border-bottom-left-radius: 4px;
    }
    .bella-typing-dots { display: flex; gap: 4px; }
    .bella-typing-dot {
      width: 6px; height: 6px; border-radius: 50%; background: var(--muted);
      animation: bellaBounce .6s infinite alternate;
    }
    .bella-typing-dot:nth-child(2) { animation-delay: .2s; }
    .bella-typing-dot:nth-child(3) { animation-delay: .4s; }
    @keyframes bellaBounce { 0%{transform:translateY(0);opacity:.4} 100%{transform:translateY(-6px);opacity:1} }

    .bella-quick-actions {
      padding: 8px 20px; border-top: 1px solid var(--border);
      display: flex; gap: 6px; flex-wrap: wrap; flex-shrink: 0;
    }
    .bella-quick-btn {
      padding: 5px 12px; border-radius: 100px; border: 1px solid var(--border);
      background: var(--s2); color: var(--muted); font-size: 11px; cursor: pointer;
      transition: all .15s; white-space: nowrap;
    }
    .bella-quick-btn:hover { border-color: var(--p); color: var(--p); background: rgba(108,92,231,.08); }

    .bella-input-area {
      padding: 12px 20px 16px; border-top: 1px solid var(--border);
      display: flex; gap: 8px; align-items: flex-end; flex-shrink: 0;
    }
    .bella-input {
      flex: 1; background: var(--s2); border: 1px solid var(--border);
      border-radius: 10px; padding: 10px 14px; color: var(--text);
      font-size: 13px; outline: none; resize: none; min-height: 40px;
      max-height: 100px; font-family: inherit; line-height: 1.4;
    }
    .bella-input:focus { border-color: var(--p); }
    .bella-input::placeholder { color: var(--muted); }
    .bella-send {
      width: 40px; height: 40px; border-radius: 10px; border: none;
      background: linear-gradient(135deg, #6C5CE7, #A78BFA);
      color: #fff; cursor: pointer; display: flex; align-items: center;
      justify-content: center; transition: transform .15s, opacity .15s;
      flex-shrink: 0;
    }
    .bella-send:hover { transform: scale(1.05); }
    .bella-send:disabled { opacity: .5; cursor: not-allowed; transform: none; }
    .bella-send svg { width: 18px; height: 18px; }

    .bella-welcome { text-align: center; padding: 40px 20px; color: var(--muted); }
    .bella-welcome-avatar {
      width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 16px;
      background: linear-gradient(135deg, #6C5CE7, #A78BFA);
      display: flex; align-items: center; justify-content: center;
      font-family: var(--fh, 'Syne', system-ui, sans-serif); font-size: 28px; font-weight: 700; color: #fff;
    }
    .bella-welcome h3 { color: var(--text); font-size: 16px; margin-bottom: 8px; }
    .bella-welcome p { font-size: 13px; line-height: 1.6; }

    .bella-error {
      align-self: flex-start; background: rgba(248,113,113,.1); border: 1px solid var(--rd);
      border-radius: 12px; padding: 10px 14px; font-size: 12px; color: var(--rd);
      max-width: 85%;
    }

    /* Admin Toast */
    .admin-toast{position:fixed;bottom:24px;right:24px;z-index:99999;color:#fff;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,.4);transition:opacity .3s;max-width:420px;font-family:var(--fb)}
    .admin-toast.success{background:#00E5A8}
    .admin-toast.error{background:#F87171}
    .admin-toast.info{background:#3B8BF5}
    .admin-toast.warning{background:#F59E0B}
    /* Admin Modal Overlay */
    .admin-modal-overlay{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;font-family:var(--fb)}
    .admin-modal-box{background:var(--s1);border:1px solid var(--bd2);border-radius:16px;padding:28px;max-width:440px;width:90%;box-shadow:0 12px 40px rgba(0,0,0,.5)}
    .admin-modal-title{font-size:16px;font-weight:700;margin-bottom:12px;color:var(--t1);font-family:var(--fh)}
    .admin-modal-msg{font-size:13px;color:var(--t2);margin-bottom:20px;line-height:1.5;white-space:pre-line}
    .admin-modal-input{width:100%;padding:10px 14px;border-radius:8px;border:1px solid var(--bd2);background:var(--s2);color:var(--t1);font-size:13px;margin-bottom:20px;outline:none;font-family:var(--fb)}
    .admin-modal-input:focus{border-color:var(--p)}
    .admin-modal-actions{display:flex;gap:10px;justify-content:flex-end}
    .admin-modal-btn{padding:8px 18px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;border:none;transition:opacity .15s}
    .admin-modal-btn:hover{opacity:.85}
    .admin-modal-cancel{background:transparent;border:1px solid var(--bd2);color:var(--t2)}
    .admin-modal-ok{background:var(--p);color:#fff}
  </style>
</head>
<body>

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-logo" style="display:flex;align-items:center;gap:10px">
      <div style="width:32px;height:32px;background:linear-gradient(135deg,var(--p),#9B8DF8);border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:14px;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 4px 14px var(--pg)">L</div>
      <div><div class="logo-text" style="font-size:13px">LevelUp Growth</div><div class="logo-sub">Admin Console</div></div>
    </div>
    <nav>
      <div class="nav-group">Overview</div>
      <div class="nav-item active" onclick="nav('dashboard')"><span class="nav-icon">&#9673;</span> Dashboard</div>

      <div class="nav-group">Users &amp; Access</div>
      <div class="nav-item" onclick="nav('users')"><span class="nav-icon">&#128101;</span> Users</div>
      <div class="nav-item" onclick="nav('workspaces')"><span class="nav-icon">&#127970;</span> Workspaces</div>
      <div class="nav-item" onclick="nav('memberships')"><span class="nav-icon">&#128101;</span> Memberships</div>
      <div class="nav-item" onclick="nav('sessions')"><span class="nav-icon">&#128273;</span> Sessions</div>

      <div class="nav-group">Billing</div>
      <div class="nav-item" onclick="nav('plans')"><span class="nav-icon">&#128179;</span> Plans</div>
      <div class="nav-item" onclick="nav('subscriptions')"><span class="nav-icon">&#128179;</span> Subscriptions</div>
      <div class="nav-item" onclick="nav('credits')"><span class="nav-icon">&#128176;</span> Credits</div>

      <div class="nav-group">Agents &amp; Tasks</div>
      <div class="nav-item" onclick="nav('agents')"><span class="nav-icon">&#129302;</span> Agents</div>
      <div class="nav-item" onclick="nav('tasks')"><span class="nav-icon">&#9889;</span> Task Monitor</div>

      <div class="nav-group">AI Engines</div>
      <div class="nav-item" onclick="nav('engines')"><span class="nav-icon">&#129513;</span> Engine Registry</div>
      <div class="nav-item" onclick="nav('capabilities')"><span class="nav-icon">&#128506;</span> Capability Map</div>

      <div class="nav-group">Analytics</div>
      <div class="nav-item" onclick="nav('analytics')"><span class="nav-icon">&#128202;</span> Analytics</div>

      <div class="nav-group">Content &amp; Creative</div>
      <div class="nav-item" onclick="nav('websitesAdmin')"><span class="nav-icon">&#127760;</span> Websites</div>
      <div class="nav-item" onclick="nav('templatesAdmin')"><span class="nav-icon">&#128196;</span> Templates</div>
      <div class="nav-item" onclick="nav('emailTemplatesAdmin')"><span class="nav-icon">&#128231;</span> Email Templates</div>
      <div class="nav-item" onclick="nav('campaignsAdmin')"><span class="nav-icon">&#128640;</span> Campaigns</div>
      <div class="nav-item" onclick="nav('assets')"><span class="nav-icon">&#127912;</span> Media Library</div>
      <div class="nav-item" onclick="nav('articles')"><span class="nav-icon">&#128221;</span> Articles</div>
      <div class="nav-group">Business Data</div>
      <div class="nav-item" onclick="nav('crmAdmin')"><span class="nav-icon">&#128100;</span> CRM Overview</div>
      <div class="nav-item" onclick="nav('seoAdmin')"><span class="nav-icon">&#128269;</span> SEO Overview</div>
      <div class="nav-item" onclick="nav('revenue')"><span class="nav-icon">&#128176;</span> Revenue</div>
      <div class="nav-group">Intelligence</div>
      <div class="nav-item" onclick="nav('meetingsAdmin')"><span class="nav-icon">&#127963;</span> Meetings</div>
      <div class="nav-item" onclick="nav('proposals')"><span class="nav-icon">&#128161;</span> Proposals</div>
      <div class="nav-item" onclick="nav('knowledge')"><span class="nav-icon">&#129504;</span> Knowledge</div>
      <div class="nav-item" onclick="nav('memory')"><span class="nav-icon">&#128190;</span> Memory</div>
      <div class="nav-item" onclick="nav('experimentsAdmin')"><span class="nav-icon">&#129514;</span> Experiments</div>
      <div class="nav-item" onclick="nav('notificationsAdmin')"><span class="nav-icon">&#128276;</span> Notifications</div>
      <div class="nav-group">System</div>
      <div class="nav-item" onclick="nav('apiUsage')"><span class="nav-icon">&#128176;</span> API Usage</div>
      <div class="nav-item" onclick="nav('houseAccount')"><span class="nav-icon">&#127968;</span> House Account</div>
      <div class="nav-item" onclick="nav('health')"><span class="nav-icon">&#128154;</span> System Health</div>
      <div class="nav-item" onclick="nav('queue')"><span class="nav-icon">&#128230;</span> Queue</div>
      <div class="nav-item" onclick="nav('audit')"><span class="nav-icon">&#128203;</span> Audit Logs</div>
      <div class="nav-item" onclick="nav('settings')"><span class="nav-icon">&#9881;&#65039;</span> Settings</div>
    </nav>
    <div class="sidebar-footer">
      <div class="admin-badge" id="admin-name">Admin</div>
      <button class="btn btn-ghost btn-sm" style="margin-top:8px;width:100%" onclick="logout()">Sign out</button>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <div class="topbar">
      <div class="page-title" id="page-title">Dashboard</div>
      <div class="topbar-right">
        <span id="bella-topbar-placeholder" style="width:0;height:0;display:none"></span>
        <span id="topbar-status" style="font-size:12px;color:var(--muted)">Loading...</span>
      </div>
    </div>
    <div class="content" id="content">
      <div class="loading">Loading...</div>
    </div>
  </div>

  <script>
    // -- Admin Modal Utilities (replaces native alert/confirm/prompt) -----------
    function showAdminToast(msg, type) {
      type = type || 'info';
      var el = document.createElement('div');
      el.className = 'admin-toast ' + type;
      el.textContent = msg;
      document.body.appendChild(el);
      setTimeout(function(){ el.style.opacity = '0'; setTimeout(function(){ el.remove(); }, 300); }, 3000);
    }
    function adminConfirm(msg, title) {
      return new Promise(function(resolve) {
        var ov = document.createElement('div');
        ov.className = 'admin-modal-overlay';
        ov.innerHTML = '<div class="admin-modal-box"><div class="admin-modal-title">' + (title || 'Confirm') + '</div><div class="admin-modal-msg">' + msg + '</div><div class="admin-modal-actions"><button class="admin-modal-btn admin-modal-cancel" id="_amc">Cancel</button><button class="admin-modal-btn admin-modal-ok" id="_amo">Confirm</button></div></div>';
        document.body.appendChild(ov);
        ov.querySelector('#_amo').onclick = function(){ ov.remove(); resolve(true); };
        ov.querySelector('#_amc').onclick = function(){ ov.remove(); resolve(false); };
      });
    }
    function adminPrompt(msg, title, defaultVal) {
      return new Promise(function(resolve) {
        var ov = document.createElement('div');
        ov.className = 'admin-modal-overlay';
        ov.innerHTML = '<div class="admin-modal-box"><div class="admin-modal-title">' + (title || 'Input') + '</div><div class="admin-modal-msg">' + msg + '</div><input class="admin-modal-input" id="_ami" value="' + (defaultVal || '') + '"><div class="admin-modal-actions"><button class="admin-modal-btn admin-modal-cancel" id="_amc">Cancel</button><button class="admin-modal-btn admin-modal-ok" id="_amo">OK</button></div></div>';
        document.body.appendChild(ov);
        var inp = ov.querySelector('#_ami');
        setTimeout(function(){ inp.focus(); }, 50);
        ov.querySelector('#_amo').onclick = function(){ ov.remove(); resolve(inp.value); };
        ov.querySelector('#_amc').onclick = function(){ ov.remove(); resolve(null); };
        inp.addEventListener('keydown', function(e){ if(e.key==='Enter'){ ov.remove(); resolve(inp.value); } });
      });
    }
    // -- Auth guard ----------------------------------------------------------------
    const token = localStorage.getItem('lu_admin_token');
    const user  = JSON.parse(localStorage.getItem('lu_admin_user') || '{}');
    if (!token || !user.is_platform_admin) {
      window.location.href = '/admin/login';
    }
    document.getElementById('admin-name').textContent = user.name || 'Admin';

    // -- API helper ----------------------------------------------------------------
    async function api(path, method = 'GET', body = null) {
      const opts = {
        method,
        headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
      };
      if (body) opts.body = JSON.stringify(body);
      const res = await fetch('/api/admin' + path, opts);
      if (res.status === 401) { logout(); return null; }
      return res.json();
    }

    function logout() {
      localStorage.removeItem('lu_admin_token');
      localStorage.removeItem('lu_admin_user');
      window.location.href = '/admin/login';
    }

    // -- Navigation ----------------------------------------------------------------
    let currentPage = 'dashboard';
    function nav(page) {
      currentPage = page;
      document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
      event.currentTarget.classList.add('active');
      document.getElementById('page-title').textContent = {
        dashboard: 'Dashboard', users: 'Users', workspaces: 'Workspaces',
        plans: 'Plans', agents: 'Agents', tasks: 'Task Monitor',
        health: 'System Health',
        websitesAdmin: 'Websites', templatesAdmin: 'Template Library', emailTemplatesAdmin: 'Email Templates', campaignsAdmin: 'Campaigns', assets: 'Media Library', articles: 'Articles',
        crmAdmin: 'CRM Overview', seoAdmin: 'SEO Overview', revenue: 'Revenue',
        meetingsAdmin: 'Meetings', proposals: 'Strategy Proposals', knowledge: 'Global Knowledge',
        memory: 'Workspace Memory', experimentsAdmin: 'Experiments', notificationsAdmin: 'Notifications', audit: 'Audit Logs', settings: 'Settings',
        memberships: 'Memberships', subscriptions: 'Subscriptions',
        sessions: 'Sessions', credits: 'Credits & Transactions', queue: 'Queue Monitor', apiUsage: 'API Usage & Costs', houseAccount: 'House Account', media: 'Media Library',
        engines: 'Engine Registry', capabilities: 'Capability Map', analytics: 'Platform Analytics',
      }[page] || page;
      pages[page]?.();
    }

    // -- Content helpers -----------------------------------------------------------
    const el = id => document.getElementById(id);
    const content = () => el('content');
    function setContent(html) { content().innerHTML = html; }
    function badge(status) {
      const map = { active:'green', suspended:'red', trialing:'blue', free:'amber', growth:'purple', pro:'blue', agency:'green', completed:'green', failed:'red', pending:'amber', running:'blue', expired:'red', revoked:'amber' };
      return '<span class="badge badge-' + (map[status]||'blue') + '">' + status + '</span>';
    }
    function ts(str) { return str ? new Date(str).toLocaleDateString() : '\u2014'; }
    function tsTime(str) { return str ? new Date(str).toLocaleString() : '\u2014'; }
    function truncate(str, len) { if (!str) return '\u2014'; return str.length > len ? str.substring(0, len) + '...' : str; }

    // ── Admin Table Utilities: Search, Sort, Filter ─────────────────
    window._adminTables = {};
    window._adminTableTimers = {};

    function adminTable(config) {
      window._adminTables[config.id] = {
        ...config,
        _search: '',
        _sort: config.defaultSort || null,
        _filters: {},
        _originalData: config.data
      };
      return _adminTableBuild(config.id);
    }

    function adminTableSearch(tableId, query) {
      clearTimeout(window._adminTableTimers[tableId]);
      window._adminTableTimers[tableId] = setTimeout(function() {
        var t = window._adminTables[tableId];
        if (!t) return;
        t._search = query.toLowerCase();
        _adminTableRerender(tableId);
      }, 300);
    }

    function adminTableSort(tableId, column) {
      var t = window._adminTables[tableId];
      if (!t) return;
      if (t._sort && t._sort.key === column) {
        t._sort.dir = t._sort.dir === 'asc' ? 'desc' : 'asc';
      } else {
        t._sort = { key: column, dir: 'asc' };
      }
      _adminTableRerender(tableId);
    }

    function adminTableFilter(tableId, key, value) {
      var t = window._adminTables[tableId];
      if (!t) return;
      if (value === '' || value === undefined) {
        delete t._filters[key];
      } else {
        t._filters[key] = value;
      }
      _adminTableRerender(tableId);
    }

    function _adminTableGetVal(row, key) {
      if (key.indexOf('.') !== -1) {
        var parts = key.split('.');
        var v = row;
        for (var i = 0; i < parts.length; i++) {
          v = v ? v[parts[i]] : undefined;
        }
        return v;
      }
      return row[key];
    }

    function _adminTableGetFiltered(tableId) {
      var t = window._adminTables[tableId];
      if (!t) return [];
      var data = t._originalData.slice();

      // Apply search
      if (t._search && t.searchFields && t.searchFields.length > 0) {
        var q = t._search;
        data = data.filter(function(row) {
          return t.searchFields.some(function(f) {
            var val = _adminTableGetVal(row, f);
            return val && String(val).toLowerCase().indexOf(q) !== -1;
          });
        });
      }

      // Apply filters
      var filterKeys = Object.keys(t._filters);
      filterKeys.forEach(function(key) {
        var filterVal = t._filters[key];
        if (filterVal === '' || filterVal === undefined) return;
        data = data.filter(function(row) {
          if (key.indexOf('_text_') === 0) {
            var colKey = key.substring(6);
            var cellVal = String(_adminTableGetVal(row, colKey) || '').toLowerCase();
            return cellVal.indexOf(String(filterVal).toLowerCase()) !== -1;
          }
          var rowVal = _adminTableGetVal(row, key);
          return String(rowVal || '').toLowerCase() === String(filterVal).toLowerCase();
        });
      });

      // Apply sort
      if (t._sort) {
        var sortKey = t._sort.key;
        var dir = t._sort.dir === 'desc' ? -1 : 1;
        data.sort(function(a, b) {
          var va = _adminTableGetVal(a, sortKey);
          var vb = _adminTableGetVal(b, sortKey);
          if (sortKey.indexOf('_at') !== -1 || sortKey === 'date') {
            va = va ? new Date(va).getTime() : 0;
            vb = vb ? new Date(vb).getTime() : 0;
            return (va - vb) * dir;
          }
          if (typeof va === 'number' && typeof vb === 'number') {
            return (va - vb) * dir;
          }
          var na = parseFloat(va), nb = parseFloat(vb);
          if (!isNaN(na) && !isNaN(nb)) {
            return (na - nb) * dir;
          }
          va = String(va || '').toLowerCase();
          vb = String(vb || '').toLowerCase();
          return va < vb ? -dir : va > vb ? dir : 0;
        });
      }

      return data;
    }

    function _adminTableBuild(tableId) {
      var t = window._adminTables[tableId];
      if (!t) return '';
      var filtered = _adminTableGetFiltered(tableId);
      var total = t._originalData.length;
      var shown = filtered.length;

      // Build filter options map from data
      var filterOptions = {};
      if (t.filters) {
        t.filters.forEach(function(f) { filterOptions[f.key] = f.options; });
      }

      // Top toolbar: just search + result count
      var toolbar = '<div style="display:flex;gap:10px;margin-bottom:12px;align-items:center">';
      if (t.searchFields && t.searchFields.length > 0) {
        toolbar += '<input type="text" class="search-bar" style="flex:1;max-width:300px" placeholder="Search..." value="' + (t._search || '').replace(/"/g, '&quot;') + '" oninput="adminTableSearch(\'' + tableId + '\', this.value)">';
      }
      toolbar += '<span style="font-size:12px;color:var(--muted);margin-left:auto">Showing ' + shown + ' of ' + total + '</span>';
      toolbar += '</div>';

      // Table header row 1: sortable labels
      var thead = '<thead>';
      thead += '<tr>';
      t.columns.forEach(function(col) {
        var arrow = '';
        if (col.sortable && t._sort && t._sort.key === col.key) {
          arrow = t._sort.dir === 'asc' ? ' <span style="color:var(--ac)">&#9650;</span>' : ' <span style="color:var(--ac)">&#9660;</span>';
        }
        var clickAttr = col.sortable ? ' onclick="adminTableSort(\'' + tableId + '\',\'' + col.key + '\')" style="cursor:pointer;user-select:none;white-space:nowrap' + (col.width ? ';width:' + col.width : '') + '"' : (col.width ? ' style="width:' + col.width + '"' : '');
        thead += '<th' + clickAttr + '>' + col.label + arrow + '</th>';
      });
      thead += '</tr>';

      // Table header row 2: per-column filter inputs
      thead += '<tr style="background:var(--s2)">';
      t.columns.forEach(function(col) {
        thead += '<td style="padding:4px 6px">';
        if (filterOptions[col.key]) {
          // Dropdown filter
          var currentVal = t._filters[col.key] || '';
          thead += '<select style="width:100%;padding:4px 6px;background:var(--s3,#252A3A);border:1px solid var(--bd);border-radius:4px;color:var(--t1);font-size:11px" onchange="adminTableFilter(\'' + tableId + '\',\'' + col.key + '\',this.value)">';
          filterOptions[col.key].forEach(function(opt) {
            thead += '<option value="' + opt.value + '"' + (currentVal === String(opt.value) ? ' selected' : '') + '>' + opt.label + '</option>';
          });
          thead += '</select>';
        } else if (col.sortable && col.key !== '_actions') {
          // Text filter for sortable columns
          var currentTxt = t._filters['_text_' + col.key] || '';
          thead += '<input type="text" placeholder="Filter..." value="' + currentTxt.replace(/"/g, '&quot;') + '" style="width:100%;padding:4px 6px;background:var(--s3,#252A3A);border:1px solid var(--bd);border-radius:4px;color:var(--t1);font-size:11px;box-sizing:border-box" oninput="adminTableFilter(\'' + tableId + '\',\'_text_' + col.key + '\',this.value)">';
        } else {
          thead += '&nbsp;';
        }
        thead += '</td>';
      });
      thead += '</tr>';
      thead += '</thead>';

      // Table body
      var tbody = '<tbody id="at-body-' + tableId + '">';
      if (filtered.length === 0) {
        tbody += '<tr><td colspan="' + t.columns.length + '" style="text-align:center;color:var(--muted);padding:24px">No matching records</td></tr>';
      } else {
        filtered.forEach(function(row) {
          tbody += '<tr>';
          t.columns.forEach(function(col) {
            var raw = _adminTableGetVal(row, col.key);
            var display = col.render ? col.render(raw, row) : (raw !== null && raw !== undefined ? String(raw) : '\u2014');
            tbody += '<td>' + display + '</td>';
          });
          tbody += '</tr>';
        });
      }
      tbody += '</tbody>';

      var extra = t.extraHtml || '';
      return extra + '<div class="admin-table-wrap card" id="at-wrap-' + tableId + '">' + toolbar + '<table>' + thead + tbody + '</table></div>';
    }

    function _adminTableRerender(tableId) {
      var wrap = document.getElementById('at-wrap-' + tableId);
      if (!wrap) return;
      var t = window._adminTables[tableId];
      if (!t) return;
      var filtered = _adminTableGetFiltered(tableId);
      var total = t._originalData.length;
      var shown = filtered.length;

      var spans = wrap.querySelectorAll('span');
      for (var i = 0; i < spans.length; i++) {
        if (spans[i].textContent.indexOf('Showing') === 0) {
          spans[i].textContent = 'Showing ' + shown + ' of ' + total;
          break;
        }
      }

      var tbodyHtml = '';
      if (filtered.length === 0) {
        tbodyHtml = '<tr><td colspan="' + t.columns.length + '" style="text-align:center;color:var(--muted);padding:24px">No matching records</td></tr>';
      } else {
        filtered.forEach(function(row) {
          tbodyHtml += '<tr>';
          t.columns.forEach(function(col) {
            var raw = _adminTableGetVal(row, col.key);
            var display;
            if (col.render) {
              display = col.render(raw, row);
            } else {
              display = raw !== null && raw !== undefined ? String(raw) : '\u2014';
            }
            tbodyHtml += '<td>' + display + '</td>';
          });
          tbodyHtml += '</tr>';
        });
      }

      var tbodyEl = wrap.querySelector('tbody');
      if (tbodyEl) tbodyEl.innerHTML = tbodyHtml;

      var ths = wrap.querySelectorAll('thead th');
      t.columns.forEach(function(col, idx) {
        if (col.sortable && ths[idx]) {
          var arrow = '';
          if (t._sort && t._sort.key === col.key) {
            arrow = t._sort.dir === 'asc' ? ' \u2191' : ' \u2193';
          }
          ths[idx].textContent = col.label + arrow;
          ths[idx].onclick = function() { adminTableSort(tableId, col.key); };
        }
      });
    }



    // -- Pages ---------------------------------------------------------------------
    const pages = {
      apiUsage: async () => {
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading API usage...</div>';
        try {
          var d = await api('/api-usage');
          if (!d) return;
          var s = d.summary || {};
          var today = s.today || {}; var month = s.this_month || {}; var total = s.total || {};
          var providers = d.by_provider || [];
          var recent = d.recent || [];
          var h = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">';
          h += '<div class="stat-card"><div class="stat-value">' + (today.calls||0) + '</div><div class="stat-label">Calls Today</div></div>';
          h += '<div class="stat-card"><div class="stat-value">' + (today.tokens||0).toLocaleString() + '</div><div class="stat-label">Tokens Today</div></div>';
          h += '<div class="stat-card"><div class="stat-value">$' + parseFloat(today.cost_usd||0).toFixed(4) + '</div><div class="stat-label">Cost Today</div></div>';
          h += '<div class="stat-card"><div class="stat-value">$' + parseFloat(month.cost_usd||0).toFixed(4) + '</div><div class="stat-label">Cost This Month</div></div>';
          h += '</div>';
          h += '<h4 style="font-size:14px;font-weight:700;margin:0 0 12px">Provider Breakdown</h4>';
          h += '<table class="data-table" style="margin-bottom:24px"><thead><tr><th>Provider</th><th>Calls</th><th>Tokens</th><th>Cost</th><th>Avg Response</th></tr></thead><tbody>';
          providers.forEach(function(p) {
            h += '<tr><td style="font-weight:600">' + p.provider + '</td><td>' + p.calls + '</td><td>' + (p.tokens||0).toLocaleString() + '</td><td>$' + parseFloat(p.cost_usd||0).toFixed(4) + '</td><td>' + (p.avg_ms||0) + 'ms</td></tr>';
          });
          if (!providers.length) h += '<tr><td colspan="5" style="text-align:center;color:#6B7280;padding:20px">No API calls logged yet</td></tr>';
          h += '</tbody></table>';
          h += '<h4 style="font-size:14px;font-weight:700;margin:0 0 12px">Recent Calls (' + recent.length + ')</h4>';
          h += '<div style="max-height:400px;overflow-y:auto"><table class="data-table"><thead><tr><th>Time</th><th>Provider</th><th>Model</th><th>Tokens</th><th>Cost</th><th>Duration</th><th>Status</th></tr></thead><tbody>';
          recent.forEach(function(r) {
            var ok = r.status === 'success';
            h += '<tr><td>' + new Date(r.created_at).toLocaleString() + '</td><td>' + r.provider + '</td><td>' + (r.model||'—') + '</td><td>' + (r.total_tokens||0) + '</td><td>$' + parseFloat(r.cost_usd||0).toFixed(4) + '</td><td>' + (r.duration_ms||0) + 'ms</td><td>' + (ok?'✅':'❌') + '</td></tr>';
          });
          if (!recent.length) h += '<tr><td colspan="7" style="text-align:center;color:#6B7280;padding:20px">No calls yet</td></tr>';
          h += '</tbody></table></div>';
          h += '<div style="margin-top:16px;font-size:11px;color:#4A566B">Total lifetime: ' + (total.calls||0) + ' calls · ' + (total.tokens||0).toLocaleString() + ' tokens · $' + parseFloat(total.cost_usd||0).toFixed(4) + '</div>';
          content().innerHTML = h;
        } catch(e) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Failed to load: ' + e.message + '</div>'; }
      },

      async dashboard() {
        setContent('<div class="loading">Loading stats...</div>');
        const [stats, queue] = await Promise.all([api('/stats'), api('/queue')]);
        if (!stats) return;
        document.getElementById('topbar-status').textContent = 'Queue: ' + (queue?.pending || 0) + ' pending';
        setContent(
          '<div class="stats-grid" style="margin-bottom:24px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + (stats.total_users||0) + '</div><div class="stat-label">Active Users</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (stats.total_workspaces||0) + '</div><div class="stat-label">Workspaces</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + (stats.tasks_today||0) + '</div><div class="stat-label">Tasks Today</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + (stats.active_subscriptions||0) + '</div><div class="stat-label">Active Subs</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">$' + Math.round(stats.total_revenue||0) + '</div><div class="stat-label">MRR</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:' + (queue?.stale>0?'var(--rd)':'var(--ac)') + '">' + (queue?.stale||0) + '</div><div class="stat-label">Stale Tasks</div></div>' +
          '</div>' +
          '<div class="card">' +
            '<div class="card-title">Queue Status</div>' +
            '<div style="display:flex;gap:24px;font-size:13px">' +
              '<div><span style="color:var(--muted)">Pending:</span> <strong>' + (queue?.pending||0) + '</strong></div>' +
              '<div><span style="color:var(--muted)">Running:</span> <strong>' + (queue?.running||0) + '</strong></div>' +
              '<div><span style="color:var(--muted)">Failed today:</span> <strong style="color:var(--rd)">' + (queue?.failed_today||0) + '</strong></div>' +
              '<div><span style="color:var(--muted)">Driver:</span> <strong>' + (queue?.queue_driver||'\u2014') + '</strong></div>' +
            '</div>' +
          '</div>'
        );
      },

      async users() {
        setContent('<div class="loading">Loading users...</div>');
        const data = await api('/users?per_page=200');
        if (!data) return;
        const users = data.data || [];
        setContent(adminTable({
          id: 'users',
          data: users,
          columns: [
            {key: 'name', label: 'Name', sortable: true},
            {key: 'email', label: 'Email', sortable: true, render: function(v){ return '<span style="color:var(--muted)">' + (v||'\u2014') + '</span>'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return badge(v || 'active'); }},
            {key: 'is_platform_admin', label: 'Role', render: function(v){ return v ? '<span class="badge badge-purple">Admin</span>' : '\u2014'; }},
            {key: 'created_at', label: 'Joined', sortable: true, render: function(v){ return ts(v); }},
            {key: '_actions', label: 'Actions', render: function(v,row){ return '<button class="btn btn-ghost btn-sm" onclick="suspendUser('+row.id+',\''+((row.status||'active')).replace(/'/g,"\\'")+'\')">' + (row.status==='suspended'?'Unsuspend':'Suspend') + '</button>'; }}
          ],
          searchFields: ['name', 'email'],
          defaultSort: {key: 'created_at', dir: 'desc'},
          filters: [{key: 'status', label: 'Status', options: [{value:'', label:'All Statuses'}, {value:'active', label:'Active'}, {value:'suspended', label:'Suspended'}]}]
        }));
      },

      async workspaces() {
        setContent('<div class="loading">Loading workspaces...</div>');
        const data = await api('/workspaces?per_page=200');
        if (!data) return;
        const workspaces = (data.data || []).map(function(w){ w._status = w.onboarded ? 'onboarded' : 'setup'; return w; });
        setContent(adminTable({
          id: 'workspaces',
          data: workspaces,
          columns: [
            {key: 'name', label: 'Workspace', sortable: true, render: function(v,row){ return '<strong>' + v + '</strong>' + (row.business_name ? '<br><span style="font-size:11px;color:var(--muted)">' + row.business_name + '</span>' : ''); }},
            {key: 'industry', label: 'Industry', render: function(v){ return '<span style="color:var(--muted)">' + (v || '\u2014') + '</span>'; }},
            {key: 'users_count', label: 'Team', sortable: true, render: function(v){ return (v || 0) + ' members'; }},
            {key: '_status', label: 'Status', render: function(v){ return v === 'onboarded' ? '<span class="badge badge-green">Onboarded</span>' : '<span class="badge badge-amber">Setup</span>'; }},
            {key: 'created_at', label: 'Created', sortable: true, render: function(v){ return ts(v); }},
            {key: '_actions', label: '', render: function(v,row){ return '<button class="btn btn-ghost btn-sm" onclick="viewWorkspace(' + row.id + ')">View</button>'; }}
          ],
          searchFields: ['name', 'business_name', 'industry'],
          defaultSort: {key: 'created_at', dir: 'desc'},
          filters: [{key: '_status', label: 'Status', options: [{value:'', label:'All'}, {value:'onboarded', label:'Onboarded'}, {value:'setup', label:'Setup'}]}]
        }));
      },

      async plans() {
        setContent('<div class="loading">Loading plans...</div>');
        const data = await api('/plans');
        if (!data) return;
        const rows = (data.plans || []).map(p =>
          '<tr>' +
            '<td><strong>' + p.name + '</strong></td>' +
            '<td>$' + p.price + '/mo</td>' +
            '<td>' + (p.credit_limit || 0) + ' cr</td>' +
            '<td>' + badge(p.ai_access || 'none') + '</td>' +
            '<td>' + (p.agent_count || 0) + '</td>' +
            '<td>' + (p.max_websites || 1) + '</td>' +
            '<td>' + (p.companion_app ? '\u2705' : '\u2014') + '</td>' +
          '</tr>').join('');
        setContent(
          '<div class="card">' +
            '<div class="card-title">Plans</div>' +
            '<table><thead><tr><th>Plan</th><th>Price</th><th>Credits</th><th>AI Access</th><th>Agents</th><th>Sites</th><th>APP888</th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table>' +
          '</div>');
      },

      async agents() {
        setContent('<div class="loading">Loading agents...</div>');
        const data = await api('/agents');
        if (!data) return;
        const agents = data.agents || [];
        setContent(adminTable({
          id: 'agents',
          data: agents,
          columns: [
            {key: 'name', label: 'Name', sortable: true, render: function(v){ return '<strong>' + v + '</strong>'; }},
            {key: 'slug', label: 'Slug', sortable: true, render: function(v){ return '<span style="color:var(--muted)">' + v + '</span>'; }},
            {key: 'category', label: 'Domain', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return badge(v || 'active'); }},
            {key: 'is_dmm', label: 'Role', render: function(v){ return v ? '<span class="badge badge-amber">DMM</span>' : '\u2014'; }}
          ],
          searchFields: ['name', 'slug', 'category'],
          defaultSort: {key: 'name', dir: 'asc'},
          filters: [
            {key: 'status', label: 'Status', options: [{value:'', label:'All Statuses'}, {value:'active', label:'Active'}, {value:'inactive', label:'Inactive'}]},
            {key: 'category', label: 'Domain', options: [{value:'', label:'All Domains'}].concat(
              [...new Set(agents.map(function(a){ return a.category; }).filter(Boolean))].sort().map(function(c){ return {value:c, label:c}; })
            )}
          ]
        }));
      },

      async tasks() {
        setContent('<div class="loading">Loading tasks...</div>');
        const data = await api('/tasks?per_page=200');
        if (!data) return;
        const tasks = data.data || [];
        setContent(adminTable({
          id: 'tasks',
          data: tasks,
          extraHtml: '<div style="margin-bottom:12px;text-align:right"><button class="btn btn-ghost btn-sm" onclick="recoverStale()">Recover Stale</button></div>',
          columns: [
            {key: 'id', label: 'ID', sortable: true, render: function(v){ return '<span style="font-size:11px;color:var(--muted)">#' + v + '</span>'; }},
            {key: 'engine', label: 'Engine / Action', sortable: true, render: function(v,row){ return (row.engine || '\u2014') + ' / ' + (row.action || '\u2014'); }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return badge(v); }},
            {key: 'created_at', label: 'Created', sortable: true, render: function(v){ return '<span style="color:var(--muted)">' + ts(v) + '</span>'; }},
            {key: '_actions', label: '', render: function(v,row){ return row.status === 'failed' ? '<button class="btn btn-ghost btn-sm" onclick="retryTask(' + row.id + ')">Retry</button>' : ''; }}
          ],
          searchFields: ['engine', 'action'],
          defaultSort: {key: 'created_at', dir: 'desc'},
          filters: [{key: 'status', label: 'Status', options: [{value:'', label:'All Statuses'}, {value:'pending', label:'Pending'}, {value:'running', label:'Running'}, {value:'completed', label:'Completed'}, {value:'failed', label:'Failed'}]}]
        }));
      },

      async health() {
        setContent('<div class="loading">Loading health...</div>');
        const [health, queue] = await Promise.all([api('/health'), api('/queue')]);
        if (!health) return;
        const checks = Object.entries(health.checks || health || {}).map(([k, v]) =>
          '<tr><td>' + k + '</td><td>' + (typeof v === 'object' ? JSON.stringify(v) : v) + '</td></tr>').join('');
        setContent(
          '<div class="card">' +
            '<div class="card-title">System Health</div>' +
            '<table><thead><tr><th>Check</th><th>Value</th></tr></thead>' +
            '<tbody>' + (checks || '<tr><td colspan="2" style="color:var(--muted)">No health data</td></tr>') + '</tbody></table>' +
          '</div>' +
          '<div class="card">' +
            '<div class="card-title">Queue</div>' +
            '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px">' +
              ['pending','running','stale','failed_today'].map(k =>
                '<div class="stat-card"><div class="stat-value" style="font-size:24px">' + (queue?.[k]||0) + '</div><div class="stat-label">' + k.replace('_',' ') + '</div></div>').join('') +
            '</div>' +
          '</div>');
      },

      async audit() {
        setContent('<div class="loading">Loading audit logs...</div>');
        const data = await api('/audit-logs?per_page=200');
        if (!data) return;
        const logs = data.data || [];
        setContent(adminTable({
          id: 'audit',
          data: logs,
          columns: [
            {key: 'created_at', label: 'Time', sortable: true, render: function(v){ return '<span style="font-size:11px;color:var(--muted)">' + tsTime(v) + '</span>'; }},
            {key: 'action', label: 'Action', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'workspace_id', label: 'Workspace', render: function(v){ return v || '\u2014'; }},
            {key: 'user_id', label: 'User', render: function(v){ return v || '\u2014'; }}
          ],
          searchFields: ['action', 'user_id', 'workspace_id'],
          defaultSort: {key: 'created_at', dir: 'desc'}
        }));
      },

      async settings() {
        setContent('<div class="loading">Loading settings...</div>');
        const data = await api('/config');
        if (!data) return;
        const settings = data.settings || [];
        const groups = [...new Set(settings.map(s => s.group))];
        const sections = groups.map(g =>
          '<div class="card">' +
            '<div class="card-title">' + g + '</div>' +
            settings.filter(s => s.group === g).map(s =>
              '<div class="form-group">' +
                '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">' +
                  '<label style="margin:0">' + s.key + (s.is_sensitive ? ' \ud83d\udd12' : '') + '</label>' +
                  (s.is_set ? '<span style="font-size:10px;font-weight:700;color:var(--ac);background:rgba(0,229,168,.1);border:1px solid rgba(0,229,168,.25);padding:1px 8px;border-radius:4px">Set \u2713</span>' : '<span style="font-size:10px;font-weight:700;color:var(--rd);background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);padding:1px 8px;border-radius:4px">Not Set</span>') +
                '</div>' +
                '<input type="' + (s.is_sensitive ? 'password' : 'text') + '" id="setting_' + s.key + '" placeholder="' + (s.is_set ? 'Enter new value to replace' : 'Enter value') + '" value="' + (!s.is_sensitive && s.is_set && s.value ? s.value : '') + '">' +
                '<div style="font-size:11px;color:var(--muted);margin-top:4px">' + (s.description || '') + '</div>' +
              '</div>').join('') +
          '</div>').join('');
        setContent(
          (sections || '<div class="card"><div class="card-title">No settings configured</div></div>') +
          '<button class="btn" onclick="saveSettings()">Save Settings</button>' +
          '<div style="margin-top:16px;padding:12px 16px;background:var(--s2);border-radius:8px;font-size:12px;color:var(--muted)">' +
            '<strong>Environment:</strong> ' + (data.env?.app_env || '\u2014') + ' | ' +
            '<strong>Queue:</strong> ' + (data.env?.queue_driver || '\u2014') + ' | ' +
            '<strong>Cache:</strong> ' + (data.env?.cache_driver || '\u2014') + ' | ' +
            '<strong>DB:</strong> ' + (data.env?.db_driver || '\u2014') +
          '</div>');
      },

      async memberships() {
        setContent('<div class="loading">Loading memberships...</div>');
        const data = await api('/memberships?per_page=200');
        if (!data) return;
        const memberships = data.data || [];
        const roles = ['owner','admin','member','viewer'];
        setContent(adminTable({
          id: 'memberships',
          data: memberships,
          columns: [
            {key: 'user_name', label: 'User', sortable: true},
            {key: 'user_email', label: 'Email', render: function(v){ return '<span style="color:var(--muted)">' + v + '</span>'; }},
            {key: 'workspace_name', label: 'Workspace', sortable: true},
            {key: 'role', label: 'Role', render: function(v,row){ return '<select id="role_' + row.id + '" style="width:auto;padding:4px 8px">' + roles.map(function(r){ return '<option value="' + r + '"' + (row.role===r?' selected':'') + '>' + r + '</option>'; }).join('') + '</select>'; }},
            {key: 'created_at', label: 'Joined', sortable: true, render: function(v){ return ts(v); }},
            {key: '_actions', label: '', render: function(v,row){ return '<button class="btn btn-sm" onclick="saveMemberRole(' + row.id + ')">Save</button>'; }}
          ],
          searchFields: ['user_name', 'user_email', 'workspace_name'],
          defaultSort: {key: 'created_at', dir: 'desc'},
          filters: [{key: 'role', label: 'Role', options: [{value:'', label:'All Roles'}, {value:'owner', label:'Owner'}, {value:'admin', label:'Admin'}, {value:'member', label:'Member'}, {value:'viewer', label:'Viewer'}]}]
        }));
      },

      async subscriptions() {
        setContent('<div class="loading">Loading subscriptions...</div>');
        const data = await api('/subscriptions?per_page=200');
        if (!data) return;
        const subs = data.data || [];
        setContent(adminTable({
          id: 'subscriptions',
          data: subs,
          columns: [
            {key: 'workspace_name', label: 'Workspace', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'plan_name', label: 'Plan', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ var map = {active:'green', past_due:'red', cancelled:'amber', trialing:'blue', expired:'red', superseded:'purple'}; return '<span class="badge badge-' + (map[v]||'blue') + '">' + v + '</span>'; }},
            {key: 'stripe_customer_id', label: 'Stripe Customer', render: function(v){ return '<span style="color:var(--muted);font-size:11px">' + (v || '\u2014') + '</span>'; }},
            {key: 'starts_at', label: 'Period Start', sortable: true, render: function(v){ return ts(v); }},
            {key: 'ends_at', label: 'Period End', sortable: true, render: function(v){ return ts(v); }}
          ],
          searchFields: ['workspace_name', 'plan_name', 'stripe_customer_id'],
          defaultSort: {key: 'starts_at', dir: 'desc'},
          filters: [
            {key: 'status', label: 'Status', options: [{value:'', label:'All Statuses'}, {value:'active', label:'Active'}, {value:'trialing', label:'Trialing'}, {value:'past_due', label:'Past Due'}, {value:'cancelled', label:'Cancelled'}, {value:'expired', label:'Expired'}]},
            {key: 'plan_name', label: 'Plan', options: [{value:'', label:'All Plans'}].concat(
              [...new Set(subs.map(function(s){ return s.plan_name; }).filter(Boolean))].sort().map(function(p){ return {value:p, label:p}; })
            )}
          ]
        }));
      },


      // =====================================================================
      // NEW PAGE: Sessions (Phase 1 — Operational Visibility)
      // =====================================================================
      async sessions() {
        setContent('<div class="loading">Loading sessions...</div>');
        const data = await api('/sessions');
        if (!data) return;
        const summary = data.summary || {};
        const sessions = (data.sessions?.data || []).map(function(s) {
          if (s.revoked_at) s._status = 'revoked';
          else if (new Date(s.expires_at) < new Date()) s._status = 'expired';
          else s._status = 'active';
          return s;
        });
        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + (summary.total_active || 0) + '</div><div class="stat-label">Active Sessions</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--rd)">' + (summary.total_revoked || 0) + '</div><div class="stat-label">Revoked Sessions</div></div>' +
          '</div>';
        setContent(adminTable({
          id: 'sessions',
          data: sessions,
          extraHtml: statsHtml,
          columns: [
            {key: 'user_name', label: 'User', sortable: true, render: function(v,row){ return (v || '\u2014') + '<br><span style="font-size:11px;color:var(--muted)">' + (row.user_email || '') + '</span>'; }},
            {key: 'ip_address', label: 'IP', render: function(v){ return '<span style="color:var(--muted);font-size:12px">' + (v || '\u2014') + '</span>'; }},
            {key: 'user_agent', label: 'Device', render: function(v){ return '<span style="color:var(--muted);font-size:11px" title="' + (v || '').replace(/"/g, '&quot;') + '">' + truncate(v, 40) + '</span>'; }},
            {key: 'created_at', label: 'Created', sortable: true, render: function(v){ return '<span style="font-size:12px">' + tsTime(v) + '</span>'; }},
            {key: 'last_used_at', label: 'Last Active', sortable: true, render: function(v){ return '<span style="font-size:12px">' + tsTime(v) + '</span>'; }},
            {key: 'expires_at', label: 'Expires', render: function(v){ return '<span style="font-size:12px">' + tsTime(v) + '</span>'; }},
            {key: '_status', label: 'Status', sortable: true, render: function(v){ return badge(v); }},
            {key: '_actions', label: '', render: function(v,row){ return row._status === 'active' ? '<button class="btn btn-danger btn-sm" onclick="revokeSession(' + row.session_id + ')">Revoke</button>' : ''; }}
          ],
          searchFields: ['user_name', 'user_email', 'ip_address'],
          defaultSort: {key: 'last_used_at', dir: 'desc'},
          filters: [{key: '_status', label: 'Status', options: [{value:'', label:'All'}, {value:'active', label:'Active'}, {value:'expired', label:'Expired'}, {value:'revoked', label:'Revoked'}]}]
        }));
      },


      // =====================================================================
      // NEW PAGE: Credits & Transactions (Phase 1 — Operational Visibility)
      // =====================================================================
      async credits() {
        setContent('<div class="loading">Loading credits...</div>');
        const data = await api('/credits');
        if (!data) return;
        const summary = data.summary || {};
        const credits = data.credits || [];
        const transactions = data.transactions || [];
        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + (summary.total_balance || 0) + '</div><div class="stat-label">Total Credits (Platform)</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + (summary.total_reserved || 0) + '</div><div class="stat-label">Reserved Credits</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + (summary.transactions_today || 0) + '</div><div class="stat-label">Transactions Today</div></div>' +
          '</div>';
        const creditsTable = adminTable({
          id: 'credits',
          data: credits,
          columns: [
            {key: 'workspace_name', label: 'Workspace', sortable: true},
            {key: 'balance', label: 'Balance', sortable: true, render: function(v){ return '<span style="font-weight:600">' + v + '</span>'; }},
            {key: 'reserved_balance', label: 'Reserved', sortable: true, render: function(v){ return '<span style="color:var(--am)">' + v + '</span>'; }},
            {key: '_actions', label: 'Actions', render: function(v,row){ return '<button class="btn btn-ghost btn-sm" onclick="adjustWorkspaceCredits(' + row.workspace_id + ',\'' + (row.workspace_name||'').replace(/'/g, "\\'") + '\')">Adjust</button>'; }}
          ],
          searchFields: ['workspace_name'],
          defaultSort: {key: 'balance', dir: 'desc'}
        });
        const txTable = adminTable({
          id: 'transactions',
          data: transactions,
          columns: [
            {key: 'workspace_name', label: 'Workspace', sortable: true},
            {key: 'type', label: 'Type', render: function(v){ return badge(v || 'debit'); }},
            {key: 'amount', label: 'Amount', sortable: true, render: function(v){ return '<span style="font-weight:600;color:' + (v >= 0 ? 'var(--ac)' : 'var(--rd)') + '">' + (v >= 0 ? '+' : '') + v + '</span>'; }},
            {key: 'reference_type', label: 'Reference', render: function(v){ return '<span style="color:var(--muted)">' + (v || '\u2014') + '</span>'; }},
            {key: 'created_at', label: 'Date', sortable: true, render: function(v){ return '<span style="font-size:12px">' + tsTime(v) + '</span>'; }}
          ],
          searchFields: ['workspace_name', 'reference_type'],
          defaultSort: {key: 'created_at', dir: 'desc'}
        });
        setContent(statsHtml + creditsTable + txTable);
      },


      // =====================================================================
      // NEW PAGE: Queue Monitor (Phase 1 — Operational Visibility)
      // =====================================================================
      async queue() {
        setContent('<div class="loading">Loading queue...</div>');
        const [queueData, failedData] = await Promise.all([api('/queue'), api('/failed-jobs')]);
        if (!queueData) return;
        const jobs = failedData?.jobs || [];
        const jobRows = jobs.map(j =>
          '<tr>' +
            '<td style="font-size:11px;color:var(--muted)">#' + j.id + '</td>' +
            '<td>' + (j.queue || '\u2014') + '</td>' +
            '<td style="color:var(--muted);font-size:11px" title="' + (j.exception_preview || '').replace(/"/g, '&quot;') + '">' + truncate(j.exception_preview, 80) + '</td>' +
            '<td style="font-size:12px">' + tsTime(j.failed_at) + '</td>' +
            '<td style="white-space:nowrap">' +
              '<button class="btn btn-ghost btn-sm" onclick="retryFailedJob(' + j.id + ')">Retry</button> ' +
              '<button class="btn btn-danger btn-sm" onclick="deleteFailedJob(' + j.id + ')">Delete</button>' +
            '</td>' +
          '</tr>').join('');
        setContent(
          '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + (queueData.pending || 0) + '</div><div class="stat-label">Pending</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + (queueData.running || 0) + '</div><div class="stat-label">Running</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--rd)">' + (queueData.failed_today || 0) + '</div><div class="stat-label">Failed Today</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:' + (queueData.stale > 0 ? 'var(--rd)' : 'var(--ac)') + '">' + (queueData.stale || 0) + '</div><div class="stat-label">Stale</div></div>' +
          '</div>' +
          '<div class="card">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">' +
              '<div class="card-title" style="margin:0">Queue Health</div>' +
              '<div style="display:flex;gap:8px">' +
                '<button class="btn btn-ghost btn-sm" onclick="recoverStale()">Recover Stale</button>' +
                '<span style="color:var(--muted);font-size:12px;line-height:28px">Driver: <strong>' + (queueData.queue_driver || '\u2014') + '</strong></span>' +
              '</div>' +
            '</div>' +
          '</div>' +
          '<div class="card">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">' +
              '<div class="card-title" style="margin:0">Failed Jobs (' + jobs.length + ')</div>' +
              (jobs.length > 0 ? '<button class="btn btn-danger btn-sm" onclick="purgeFailedJobs()">Purge All Failed</button>' : '') +
            '</div>' +
            '<table><thead><tr><th>ID</th><th>Queue</th><th>Error</th><th>Failed At</th><th>Actions</th></tr></thead>' +
            '<tbody>' + (jobRows || '<tr><td colspan="5" style="color:var(--muted)">No failed jobs</td></tr>') + '</tbody></table>' +
          '</div>');
      },

      // =====================================================================
      // NEW PAGE: Engine Registry (Phase 2 — AI Engines)
      // =====================================================================
      async engines() {
        setContent('<div class="loading">Loading engine registry...</div>');
        const data = await api('/engines/registry');
        if (!data) return;
        const engines = data.engines || [];
        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + engines.length + '</div><div class="stat-label">Total Engines</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + engines.filter(function(e){ return e.status === 'active'; }).length + '</div><div class="stat-label">Active</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + engines.reduce(function(s,e){ return s + (e.route_count||0); }, 0) + '</div><div class="stat-label">Total Routes</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + engines.reduce(function(s,e){ return s + (e.total_tasks||0); }, 0) + '</div><div class="stat-label">Total Tasks</div></div>' +
          '</div>';
        setContent(adminTable({
          id: 'engines',
          data: engines,
          extraHtml: statsHtml,
          columns: [
            {key: 'name', label: 'Engine', sortable: true, render: function(v,row){ var c = row.status === 'active' ? 'var(--ac)' : 'var(--rd)'; return '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + c + ';margin-right:6px"></span><strong>' + v + '</strong>'; }},
            {key: 'version', label: 'Version', render: function(v){ return '<span class="badge badge-purple">v' + (v || '1.0') + '</span>'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return badge(v || 'active'); }},
            {key: 'route_count', label: 'Routes', sortable: true, render: function(v){ return '<strong>' + (v || 0) + '</strong>'; }},
            {key: 'total_tasks', label: 'Tasks', sortable: true, render: function(v){ return '<strong>' + (v || 0) + '</strong>'; }},
            {key: 'failed_tasks', label: 'Failed', sortable: true, render: function(v){ return '<strong style="color:' + ((v||0) > 0 ? 'var(--rd)' : 'var(--ac)') + '">' + (v || 0) + '</strong>'; }},
            {key: 'last_execution', label: 'Last Run', sortable: true, render: function(v){ return v ? ts(v) : '\u2014'; }}
          ],
          searchFields: ['name'],
          defaultSort: {key: 'name', dir: 'asc'},
          filters: [{key: 'status', label: 'Status', options: [{value:'', label:'All'}, {value:'active', label:'Active'}, {value:'inactive', label:'Inactive'}]}]
        }));
      },


      // =====================================================================
      // NEW PAGE: Capability Map (Phase 2 — AI Engines)
      // =====================================================================
      async capabilities() {
        setContent('<div class="loading">Loading capability map...</div>');
        const data = await api('/engines/capabilities');
        if (!data) return;
        const caps = data.capabilities || [];
        const enginesSet = [...new Set(caps.map(function(c){ return c.engine; }))];
        const autoCount = caps.filter(function(c){ return c.approval_mode === 'auto'; }).length;
        const reviewCount = caps.filter(function(c){ return c.approval_mode === 'review'; }).length;
        const protectedCount = caps.filter(function(c){ return c.approval_mode === 'protected'; }).length;
        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + caps.length + '</div><div class="stat-label">Total Capabilities</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + enginesSet.length + '</div><div class="stat-label">Engines</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + autoCount + '</div><div class="stat-label">Auto-Approved</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + reviewCount + '</div><div class="stat-label">Review Required</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + protectedCount + '</div><div class="stat-label">Protected</div></div>' +
          '</div>';
        setContent(adminTable({
          id: 'capabilities',
          data: caps,
          extraHtml: statsHtml,
          columns: [
            {key: 'action', label: 'Action', sortable: true, render: function(v){ return '<strong>' + (v || '\u2014') + '</strong>'; }},
            {key: 'engine', label: 'Engine', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'credit_cost', label: 'Credits', sortable: true, render: function(v){ var c = parseInt(v) || 0; if (c === 0) return '<span class="badge" style="background:rgba(148,163,184,.12);color:var(--muted)">0</span>'; if (c <= 5) return '<span class="badge badge-green">' + c + '</span>'; if (c <= 15) return '<span class="badge badge-blue">' + c + '</span>'; return '<span class="badge badge-purple">' + c + '</span>'; }},
            {key: 'approval_mode', label: 'Approval', sortable: true, render: function(v){ var map = {auto:'green', review:'blue', protected:'amber'}; return '<span class="badge badge-' + (map[v] || 'blue') + '">' + (v || 'auto') + '</span>'; }},
            {key: 'connector', label: 'Connector', render: function(v){ return '<span style="color:var(--muted)">' + (v || '\u2014') + '</span>'; }}
          ],
          searchFields: ['action', 'engine', 'connector'],
          defaultSort: {key: 'engine', dir: 'asc'},
          filters: [
            {key: 'engine', label: 'Engine', options: [{value:'', label:'All Engines'}].concat(enginesSet.sort().map(function(e){ return {value:e, label:e}; }))},
            {key: 'approval_mode', label: 'Approval', options: [{value:'', label:'All'}, {value:'auto', label:'Auto'}, {value:'review', label:'Review'}, {value:'protected', label:'Protected'}]}
          ]
        }));
      },



      // =====================================================================
      // NEW PAGE: Platform Analytics (Phase 2 — Analytics)
      // =====================================================================
      async analytics() {
        setContent('<div class="loading">Loading analytics...</div>');
        const data = await api('/analytics');
        if (!data) return;
        const ug = data.user_growth || {};
        const tv = data.task_volume || {};
        const cc = data.credit_consumption || {};
        const eu = data.engine_usage || [];
        const tw = data.top_workspaces || [];
        const rev = data.revenue || {};

        // Top stat cards
        let html = '<div class="stats-grid" style="margin-bottom:24px">' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + (ug.total_users || 0) + '</div><div class="stat-label">Total Users</div></div>' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + (ug.active_7d || 0) + '</div><div class="stat-label">Active (7d)</div></div>' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (tv.today || 0) + '</div><div class="stat-label">Tasks Today</div></div>' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + (cc.today || 0) + '</div><div class="stat-label">Credits Used Today</div></div>' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">$' + Math.round(rev.mrr || 0) + '</div><div class="stat-label">MRR</div></div>' +
        '</div>';

        // Engine usage bar chart
        if (eu.length > 0) {
          const maxTasks = Math.max(...eu.map(e => e.tasks || 0), 1);
          const bars = eu.map(e => {
            const pct = Math.round(((e.tasks || 0) / maxTasks) * 100);
            return '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">' +
              '<div style="width:120px;font-size:13px;text-align:right;color:var(--muted);flex-shrink:0">' + e.engine + '</div>' +
              '<div style="flex:1;background:var(--s3);border-radius:4px;height:24px;overflow:hidden">' +
                '<div style="width:' + pct + '%;height:100%;background:linear-gradient(90deg,var(--p),var(--bl));border-radius:4px;transition:width .3s"></div>' +
              '</div>' +
              '<div style="width:60px;font-size:13px;font-weight:600">' + (e.tasks || 0) + '</div>' +
            '</div>';
          }).join('');
          html += '<div class="card"><div class="card-title">Engine Usage</div>' + bars + '</div>';
        }

        // Task volume summary
        html += '<div class="card"><div class="card-title">Task Volume</div>' +
          '<div style="display:flex;gap:24px;font-size:13px;flex-wrap:wrap">' +
            '<div><span style="color:var(--muted)">Today:</span> <strong>' + (tv.today || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">This Week:</span> <strong>' + (tv.this_week || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">This Month:</span> <strong>' + (tv.this_month || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Completed:</span> <strong style="color:var(--ac)">' + (tv.completed || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Failed:</span> <strong style="color:var(--rd)">' + (tv.failed || 0) + '</strong></div>' +
          '</div></div>';

        // Credit consumption
        html += '<div class="card"><div class="card-title">Credit Consumption</div>' +
          '<div style="display:flex;gap:24px;font-size:13px;flex-wrap:wrap">' +
            '<div><span style="color:var(--muted)">Today:</span> <strong>' + (cc.today || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">This Week:</span> <strong>' + (cc.this_week || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">This Month:</span> <strong>' + (cc.this_month || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Platform Total:</span> <strong style="color:var(--am)">' + (cc.total || 0) + '</strong></div>' +
          '</div></div>';

        // User growth sparkline
        const daily = ug.daily || [];
        if (daily.length > 0) {
          const maxDaily = Math.max(...daily.map(d => d.count || 0), 1);
          const sparkBars = daily.map(d => {
            const h = Math.max(Math.round(((d.count || 0) / maxDaily) * 60), 2);
            return '<div style="display:flex;flex-direction:column;align-items:center;gap:4px">' +
              '<div style="font-size:11px;font-weight:600">' + (d.count || 0) + '</div>' +
              '<div style="width:20px;height:' + h + 'px;background:var(--ac);border-radius:3px"></div>' +
              '<div style="font-size:10px;color:var(--muted)">' + (d.date ? d.date.slice(5) : '') + '</div>' +
            '</div>';
          }).join('');
          html += '<div class="card"><div class="card-title">User Growth (Daily Signups)</div>' +
            '<div style="display:flex;align-items:flex-end;gap:8px;padding-top:8px">' + sparkBars + '</div></div>';
        }

        // Top workspaces table
        if (tw.length > 0) {
          const wsRows = tw.map(w =>
            '<tr>' +
              '<td><strong>' + (w.name || '\u2014') + '</strong></td>' +
              '<td>' + badge(w.plan || 'free') + '</td>' +
              '<td>' + (w.tasks || 0) + '</td>' +
              '<td>' + (w.credits_used || 0) + '</td>' +
            '</tr>').join('');
          html += '<div class="card"><div class="card-title">Top Workspaces</div>' +
            '<table><thead><tr><th>Workspace</th><th>Plan</th><th>Tasks</th><th>Credits Used</th></tr></thead>' +
            '<tbody>' + wsRows + '</tbody></table></div>';
        }

        // Revenue
        html += '<div class="card"><div class="card-title">Revenue</div>' +
          '<div style="display:flex;gap:24px;font-size:13px;flex-wrap:wrap">' +
            '<div><span style="color:var(--muted)">MRR:</span> <strong style="color:var(--ac)">$' + Math.round(rev.mrr || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Active Subs:</span> <strong>' + (rev.active_subscriptions || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Trialing:</span> <strong>' + (rev.trialing || 0) + '</strong></div>' +
          '</div></div>';

        setContent(html);
      },

      async websitesAdmin() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/websites-all');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id: 'websites', data: items,
          columns: [
            {key:'name',label:'Name',sortable:true,render:function(v){return '<strong>'+(v||'-')+'</strong>';}},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'domain',label:'Domain',render:function(v){return v||'-';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'draft');}},
            {key:'page_count',label:'Pages',sortable:true,render:function(v){return (v||0)+'';} },
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['name','workspace_name','domain'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'draft',label:'Draft'},{value:'published',label:'Published'},{value:'active',label:'Active'}]}]
        }));
      },

      // ── Template Library (admin CRUD for storage/templates/*) ─────
      async templatesAdmin() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/templates');
        if (!data || !data.success) { setContent('<div class="loading">Failed to load templates.</div>'); return; }
        const templates = data.templates || [];
        const stats = data.stats || {};
        const usage = data.usage || [];

        const statsHtml =
          '<div class="stats-grid" style="margin-bottom:16px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (stats.total_templates||0) + '</div><div class="stat-label">Total Templates</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:#00E5A8">' + (stats.active_templates||0) + '</div><div class="stat-label">Active Templates</div></div>' +
            '<div class="stat-card"><div class="stat-value">' + (stats.total_industries||0) + '</div><div class="stat-label">Total Industries</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p);font-size:22px">' + (stats.most_used||'\u2014') + '</div><div class="stat-label">Most Used (' + (stats.most_used_count||0) + ' sites)</div></div>' +
          '</div>';

        const actionBar =
          '<div style="display:flex;justify-content:flex-end;margin-bottom:12px">' +
            '<button onclick="_admTemplatesOpenUpload()" style="background:var(--p);color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">+ Add Template</button>' +
          '</div>';

        setContent(statsHtml + actionBar + '<div id="tpl-library"></div><div id="tpl-usage" style="margin-top:32px"></div><div id="tpl-modal-root"></div>');

        document.getElementById('tpl-library').innerHTML = adminTable({
          id: 'tpl-library', data: templates,
          columns: [
            { key:'industry', label:'Industry', sortable:true, render:function(v,row){
                return '<strong>' + (row.name||v||'-') + '</strong>' +
                       '<div style="font-size:11px;color:var(--muted);font-family:monospace">' + (v||'-') + '</div>';
              } },
            { key:'variation',     label:'Variation', sortable:true, render:function(v){return badge(v||'luxury');} },
            { key:'block_count',   label:'Blocks',    sortable:true, render:function(v){return (v||0)+'';} },
            { key:'element_count', label:'Fields',    sortable:true, render:function(v){return (v||0)+'';} },
            { key:'field_count',   label:'Vars',      sortable:true, render:function(v){return (v||0)+'';} },
            { key:'is_active',     label:'Status',    sortable:true, render:function(v){return v?'<span class="badge badge-green">active</span>':'<span class="badge badge-amber">inactive</span>';} },
            { key:'sites_built',   label:'Usage',     sortable:true, render:function(v){return (v||0)+' sites';} },
            { key:'industry',      label:'Actions',   render:function(v,row){
                var preview = '<a href="/templates/'+v+'/preview" target="_blank" style="color:var(--p);font-size:12px;margin-right:12px">Preview</a>';
                var toggleLabel = row.is_active ? 'Disable' : 'Enable';
                var toggle = '<a href="#" onclick="_admTemplateToggle(event,\''+v+'\');return false;" style="color:var(--muted);font-size:12px;margin-right:12px">'+toggleLabel+'</a>';
                var clone = '<a href="#" onclick="_admTemplateClone(event,\''+v+'\');return false;" style="color:var(--p);font-size:12px">Clone as V2</a>';
                return preview + toggle + clone;
              } }
          ],
          searchFields: ['industry','name','variation','category'],
          defaultSort: { key:'industry', dir:'asc' },
          filters: [
            { key:'is_active', label:'Status',    options:[{value:'',label:'All'},{value:true,label:'Active'},{value:false,label:'Inactive'}] },
            { key:'variation', label:'Variation', options:[{value:'',label:'All'},{value:'luxury',label:'Luxury'},{value:'commercial',label:'Commercial'}] }
          ]
        });

        var usageRows = (usage||[]).map(function(u){
          return '<tr><td style="padding:10px 16px;border-top:1px solid var(--border)"><strong>'+u.industry+'</strong></td>' +
                 '<td style="text-align:right;padding:10px 16px;border-top:1px solid var(--border);font-variant-numeric:tabular-nums">'+u.sites_built+'</td></tr>';
        }).join('');
        document.getElementById('tpl-usage').innerHTML =
          '<h3 style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:12px">Template Usage (sites built per industry)</h3>' +
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:8px;overflow:hidden">' +
            '<table style="width:100%;border-collapse:collapse">' +
              '<thead><tr style="background:var(--s2)"><th style="text-align:left;padding:10px 16px;font-size:11px;color:var(--muted);font-weight:600;letter-spacing:.06em">Industry</th><th style="text-align:right;padding:10px 16px;font-size:11px;color:var(--muted);font-weight:600;letter-spacing:.06em">Sites Built</th></tr></thead>' +
              '<tbody>' + (usageRows || '<tr><td colspan="2" style="padding:20px;text-align:center;color:var(--muted)">No sites built yet.</td></tr>') + '</tbody>' +
            '</table>' +
          '</div>';
      },

      async emailTemplatesAdmin() {
        setContent('<div class="loading">Loading email templates…</div>');
        const data = await api('/email-templates');
        if (!data || (!data.templates && !Array.isArray(data))) {
          setContent('<div class="loading">Failed to load email templates.</div>');
          return;
        }
        const all = data.templates || data || [];
        window._admEmailTpls = all;
        const sysCount  = all.filter(function(t){ return t.is_system == 1; }).length;
        const userCount = all.filter(function(t){ return t.is_system != 1; }).length;

        const stats =
          '<div class="stats-grid" style="margin-bottom:16px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + all.length + '</div><div class="stat-label">Total</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:#00E5A8">' + sysCount + '</div><div class="stat-label">System (Library)</div></div>' +
            '<div class="stat-card"><div class="stat-value">' + userCount + '</div><div class="stat-label">User-created</div></div>' +
          '</div>';

        const actions =
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;flex-wrap:wrap">' +
            '<div style="display:flex;gap:6px">' +
              '<button id="ema-f-all"  onclick="_admEmailFilter(\'all\')"  style="background:var(--p);color:#fff;border:none;padding:6px 12px;font-size:12px;font-weight:600;border-radius:6px;cursor:pointer">All <span style="opacity:.7">' + all.length + '</span></button>' +
              '<button id="ema-f-sys"  onclick="_admEmailFilter(\'sys\')"  style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:6px 12px;font-size:12px;font-weight:600;border-radius:6px;cursor:pointer">System <span style="opacity:.7">' + sysCount + '</span></button>' +
              '<button id="ema-f-user" onclick="_admEmailFilter(\'user\')" style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:6px 12px;font-size:12px;font-weight:600;border-radius:6px;cursor:pointer">User <span style="opacity:.7">' + userCount + '</span></button>' +
            '</div>' +
            '<button onclick="_admEmailNew()" style="background:var(--p);color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">+ New System Template</button>' +
          '</div>';

        setContent(stats + actions + '<div id="ema-grid"></div>');
        _admEmailRender('all');
      },

      async campaignsAdmin() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/campaigns-all');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'campaigns', data:items,
          columns:[
            {key:'name',label:'Name',sortable:true,render:function(v,row){return '<strong>'+(v||row.title||'-')+'</strong>';}},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'draft');}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['name','title','workspace_name'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'draft',label:'Draft'},{value:'active',label:'Active'},{value:'completed',label:'Completed'}]}]
        }));
      },

      // ── Unified Media Library (replaces old Creative Assets stub) ─
      async assets() {
        if (!window._adminMedia) {
          window._adminMedia = {
            tab: 'library', page: 1, per_page: 20,
            filters: { search:'', category:'', industry:'', type:'', source:'' },
            view: 'grid',
            selected: [],        // bulk-delete selection (ids)
            selectMode: false,   // selection UI on/off
          };
        }
        setContent('<div class="loading">Loading...</div>');
        await _mediaRender();
      },

      async articles() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/articles');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'articles', data:items,
          columns:[
            {key:'title',label:'Title',sortable:true,render:function(v){return '<strong>'+(v||'-')+'</strong>';}},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'draft');}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['title','workspace_name'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'draft',label:'Draft'},{value:'published',label:'Published'}]}]
        }));
      },

      async crmAdmin() { setContent('<div class="loading">Loading...</div>'); const data = await api('/crm-overview'); if(!data) return; setContent('<div class="stats-grid" style="margin-bottom:16px"><div class="stat-card"><div class="stat-value" style="color:var(--ac)">'+(data.total_contacts||0)+'</div><div class="stat-label">Contacts</div></div><div class="stat-card"><div class="stat-value" style="color:var(--bl)">'+(data.total_leads||0)+'</div><div class="stat-label">Leads</div></div><div class="stat-card"><div class="stat-value" style="color:var(--p)">'+(data.total_deals||0)+'</div><div class="stat-label">Deals</div></div></div><div class="card"><div class="card-title">Recent Contacts</div>'+((data.recent_contacts||[]).length===0?'<div style="padding:10px;color:var(--muted)">No contacts</div>':'<table><thead><tr><th>Name</th><th>Email</th><th>Created</th></tr></thead><tbody>'+(data.recent_contacts||[]).map(c=>'<tr><td>'+(c.name||'-')+'</td><td style="color:var(--muted)">'+(c.email||'-')+'</td><td>'+ts(c.created_at)+'</td></tr>').join('')+'</tbody></table>')+'</div>'); },

      async seoAdmin() { setContent('<div class="loading">Loading...</div>'); const data = await api('/seo-overview'); if(!data) return; setContent('<div class="stats-grid" style="margin-bottom:16px"><div class="stat-card"><div class="stat-value" style="color:var(--bl)">'+(data.total_keywords||0)+'</div><div class="stat-label">Keywords</div></div><div class="stat-card"><div class="stat-value" style="color:var(--am)">'+(data.total_audits||0)+'</div><div class="stat-label">Audits</div></div><div class="stat-card"><div class="stat-value" style="color:var(--ac)">'+(data.total_goals||0)+'</div><div class="stat-label">Goals</div></div><div class="stat-card"><div class="stat-value" style="color:var(--p)">'+(data.total_links||0)+'</div><div class="stat-label">Links</div></div></div><div class="card"><div class="card-title">Recent Audits</div>'+((data.recent_audits||[]).length===0?'<div style="padding:10px;color:var(--muted)">No audits</div>':'<table><thead><tr><th>URL</th><th>Score</th><th>Created</th></tr></thead><tbody>'+(data.recent_audits||[]).map(a=>'<tr><td>'+(a.url||'-')+'</td><td>'+(a.score||'-')+'</td><td>'+ts(a.created_at)+'</td></tr>').join('')+'</tbody></table>')+'</div>'); },

      async revenue() { setContent('<div class="loading">Loading...</div>'); const data = await api('/revenue'); if(!data) return; setContent('<div class="stats-grid" style="margin-bottom:16px"><div class="stat-card"><div class="stat-value" style="color:var(--ac)">$'+(data.mrr||0)+'</div><div class="stat-label">MRR</div></div><div class="stat-card"><div class="stat-value" style="color:var(--bl)">'+(data.active_subscriptions||0)+'</div><div class="stat-label">Active Subs</div></div><div class="stat-card"><div class="stat-value" style="color:var(--rd)">'+(data.churn_30d||0)+'</div><div class="stat-label">Churn (30d)</div></div></div><div class="card"><div class="card-title">Subscriptions by Plan</div>'+((data.subscriptions_by_plan||[]).length===0?'<div style="padding:10px;color:var(--muted)">No subscriptions</div>':'<table><thead><tr><th>Plan</th><th>Count</th><th>Price</th></tr></thead><tbody>'+(data.subscriptions_by_plan||[]).map(s=>'<tr><td>'+badge(s.plan||s.name||'-')+'</td><td>'+(s.count||0)+'</td><td>$'+(s.price||0)+'</td></tr>').join('')+'</tbody></table>')+'</div>'); },

      async meetingsAdmin() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/meetings-all');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'meetings', data:items,
          columns:[
            {key:'title',label:'Title',sortable:true,render:function(v,row){return '<strong>'+(v||row.topic||'-')+'</strong>';}},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'type',label:'Type',render:function(v){return v||'-';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'pending');}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['title','topic','workspace_name'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'pending',label:'Pending'},{value:'completed',label:'Completed'},{value:'cancelled',label:'Cancelled'}]}]
        }));
      },

      async proposals() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/proposals');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'proposals', data:items,
          columns:[
            {key:'title',label:'Title',sortable:true,render:function(v){return '<strong>'+(v||'-')+'</strong>';}},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'pending');}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['title','workspace_name'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'pending',label:'Pending'},{value:'approved',label:'Approved'},{value:'rejected',label:'Rejected'}]}]
        }));
      },

      async knowledge() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/global-knowledge');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'knowledge', data:items,
          columns:[
            {key:'key',label:'Key',sortable:true,render:function(v,row){return '<strong>'+(v||row.title||'-')+'</strong>';}},
            {key:'category',label:'Category',sortable:true,render:function(v,row){return badge(v||row.type||'-');}},
            {key:'value',label:'Value',render:function(v,row){var txt=v||row.content||'-'; return '<span style="color:var(--muted);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block">'+txt.substring(0,100)+'</span>';}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['key','title','category','type'],
          defaultSort:{key:'created_at',dir:'desc'}
        }));
      },

      async memory() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/workspace-memory');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'memory', data:items,
          columns:[
            {key:'workspace_name',label:'Workspace',sortable:true},
            {key:'key',label:'Key',sortable:true,render:function(v){return '<strong>'+(v||'-')+'</strong>';}},
            {key:'value',label:'Value',render:function(v){return '<span style="color:var(--muted);max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block">'+(v||'-').substring(0,80)+'</span>';}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['workspace_name','key','value'],
          defaultSort:{key:'created_at',dir:'desc'}
        }));
      },

      async experimentsAdmin() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/experiments');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'experiments', data:items,
          columns:[
            {key:'name',label:'Name',sortable:true,render:function(v){return '<strong>'+(v||'-')+'</strong>';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'draft');}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['name'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'draft',label:'Draft'},{value:'running',label:'Running'},{value:'completed',label:'Completed'}]}]
        }));
      },

      async notificationsAdmin() {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/notifications-all');
        if(!data) return;
        const items = (data.data||[]).map(function(n){ n._read = n.read_at ? 'read' : 'unread'; return n; });
        setContent(adminTable({
          id:'notifications', data:items,
          columns:[
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return v||'-';}},
            {key:'type',label:'Type',render:function(v){return badge(v||'-');}},
            {key:'title',label:'Title',sortable:true,render:function(v,row){return v||row.message||'-';}},
            {key:'_read',label:'Read',render:function(v){return v==='read'?'<span class="badge badge-green">Read</span>':'<span class="badge badge-amber">Unread</span>';}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['workspace_name','title','message','type'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'_read',label:'Status',options:[{value:'',label:'All'},{value:'read',label:'Read'},{value:'unread',label:'Unread'}]}]
        }));
      },

    };



    // -- Actions -------------------------------------------------------------------
    async function suspendUser(id, currentStatus) {
      const action = currentStatus === 'suspended' ? 'unsuspend' : 'suspend';
      var ok = await adminConfirm(action.charAt(0).toUpperCase() + action.slice(1) + ' this user?', 'Confirm Action'); if (!ok) return;
      await api('/users/' + id + '/' + action, 'POST');
      pages.users();
    }

    async function viewWorkspace(id) {
      const data = await api('/workspaces/' + id);
      if (!data) return;
      const ws = data.workspace;
      showAdminToast(ws.name + ' \u2014 Plan: ' + (data.subscription?.plan?.name || 'Free') + ', Credits: ' + (data.credit?.balance || 0) + ', Tasks: ' + (data.task_count || 0) + ' (' + (data.task_completed || 0) + ' completed)', 'info');
    }

    async function retryTask(id) {
      await api('/tasks/' + id + '/retry', 'POST');
      pages.tasks();
    }

    async function recoverStale() {
      const btn = event.currentTarget;
      btn.disabled = true;
      btn.textContent = 'Recovering...';
      await api('/recover-stale', 'POST');
      btn.disabled = false;
      btn.textContent = 'Recover Stale';
      if (currentPage === 'queue') pages.queue();
      else pages.tasks();
    }

    async function saveSettings() {
      const inputs = document.querySelectorAll('[id^="setting_"]');
      const payload = {};
      inputs.forEach(input => {
        if (input.value) {
          const key = input.id.replace('setting_', '');
          payload[key] = input.value;
        }
      });
      if (!Object.keys(payload).length) { showAdminToast('No changes to save', 'info'); return; }
      const res = await api('/config', 'POST', payload);
      showAdminToast(res?.success ? 'Saved ' + res.updated + ' setting(s)' : 'Save failed', res?.success ? 'success' : 'error');
    }

    async function saveMemberRole(id) {
      const role = document.getElementById('role_' + id).value;
      const res = await api('/memberships/' + id, 'PUT', { role });
      if (res && res.success) {
        showAdminToast('Role updated', 'success');
        pages.memberships();
      } else {
        showAdminToast('Failed to update role', 'error');
      }
    }

    // -- New Actions (Phase 1) -----------------------------------------------------
    async function revokeSession(id) {
      var ok = await adminConfirm('Revoke this session? The user will be logged out.', 'Revoke Session'); if (!ok) return;
      const res = await api('/sessions/' + id + '/revoke', 'POST');
      if (res?.success) pages.sessions();
      else showAdminToast('Failed to revoke session', 'error');
    }

    async function adjustWorkspaceCredits(wsId, wsName) {
      const amount = await adminPrompt('Enter positive number to add, negative to deduct:', 'Adjust Credits for "' + wsName + '"');
      if (amount === null || amount === '') return;
      const num = parseInt(amount, 10);
      if (isNaN(num)) { showAdminToast('Invalid number', 'error'); return; }
      const reason = await adminPrompt('Enter reason for adjustment:', 'Reason');
      if (!reason) { showAdminToast('Reason is required', 'warning'); return; }
      const res = await api('/workspaces/' + wsId + '/credits', 'POST', { amount: num, reason: reason });
      if (res?.balance !== undefined) {
        showAdminToast('Credits adjusted. New balance: ' + res.balance, 'success');
        pages.credits();
      } else {
        showAdminToast('Failed to adjust credits', 'error');
      }
    }

    async function retryFailedJob(id) {
      var ok = await adminConfirm('Remove this failed job from the queue? (Manual re-dispatch may be needed)', 'Retry Job'); if (!ok) return;
      const res = await api('/failed-jobs/' + id + '/retry', 'POST');
      if (res?.success) pages.queue();
      else showAdminToast('Failed to process', 'error');
    }

    async function deleteFailedJob(id) {
      var ok = await adminConfirm('Delete this failed job permanently?', 'Delete Job'); if (!ok) return;
      const res = await api('/failed-jobs/' + id, 'DELETE');
      if (res?.success) pages.queue();
      else showAdminToast('Failed to delete', 'error');
    }

    async function purgeFailedJobs() {
      var ok1 = await adminConfirm('PURGE ALL failed jobs? This cannot be undone.', 'Purge Jobs'); if (!ok1) return;
      var ok2 = await adminConfirm('Are you sure? This will delete ALL failed job records.', 'Final Confirmation'); if (!ok2) return;
      const res = await api('/failed-jobs/purge', 'POST');
      if (res?.success) {
        showAdminToast('Purged ' + (res.purged || 0) + ' failed jobs', 'success');
        pages.queue();
      } else {
        showAdminToast('Failed to purge', 'error');
      }
    }

    // -- New Actions (Phase 2 — AI Engines + Analytics) ----------------------------
    function showEngineDetail(el) {
      try {
        const e = JSON.parse(el.dataset.engine);
        const details = 'Engine: ' + e.name + '\n' +
          'Version: ' + (e.version || '1.0') + '\n' +
          'Status: ' + e.status + '\n' +
          'Routes: ' + (e.route_count || 0) + '\n' +
          'Total Tasks: ' + (e.total_tasks || 0) + '\n' +
          'Failed Tasks: ' + (e.failed_tasks || 0) + '\n' +
          'Last Execution: ' + (e.last_execution || 'Never') + '\n' +
          'Description: ' + (e.description || 'N/A');
        showAdminToast(details, 'info');
      } catch(err) { console.error('Engine detail error', err); }
    }

    // filterCapabilities replaced by adminTable system

    async function viewAnalyticsWorkspace(id) {
      const data = await api('/analytics/workspace/' + id);
      if (!data) return;
      const ws = data.workspace || {};
      showAdminToast('Workspace: ' + (ws.name || id) + ' \u2014 Plan: ' + (ws.plan || 'N/A') + ', Tasks: ' + (ws.total_tasks || 0) + ', Credits Used: ' + (ws.credits_used || 0) + ', Members: ' + (ws.member_count || 0), 'info');
    }


    // ================================================================
    // BELLA AI CHAT INTERFACE
    // ================================================================

    // Persist history across SPA navigation (window-level)
    if (!window.bellaHistory) window.bellaHistory = [];
    let bellaOpen = false;

    function toggleBella() {
      bellaOpen = !bellaOpen;
      document.getElementById('bella-panel').classList.toggle('open', bellaOpen);
      document.getElementById('bella-overlay').classList.toggle('open', bellaOpen);
      document.getElementById('bella-toggle-btn').classList.toggle('active', bellaOpen);
      if (bellaOpen) {
        setTimeout(() => document.getElementById('bella-input').focus(), 300);
      }
    }

    // Simple markdown renderer
    function bellaRenderMarkdown(text) {
      if (!text) return '';
      let html = text;

      // Code blocks (``` ... ```)
      html = html.replace(/```([\s\S]*?)```/g, function(m, code) {
        return '<pre><code>' + code.replace(/</g, '&lt;').replace(/>/g, '&gt;').trim() + '</code></pre>';
      });

      // Inline code
      html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

      // Bold
      html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

      // Headers (## and ###)
      html = html.replace(/^### (.+)$/gm, '<h3 style="font-size:13px;font-weight:600;margin:6px 0 2px;color:var(--text)">$1</h3>');
      html = html.replace(/^## (.+)$/gm, '<h3>$1</h3>');

      // Unordered lists
      html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
      html = html.replace(/(<li>.*<\/li>)/s, function(m) {
        // Wrap consecutive <li> in <ul>
        return '<ul>' + m + '</ul>';
      });
      // Fix multiple <ul> wraps — merge consecutive
      html = html.replace(/<\/ul>\s*<ul>/g, '');

      // Numbered lists
      html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');

      // Newlines to <br> (but not inside pre/code blocks)
      const parts = html.split(/(<pre>[\s\S]*?<\/pre>)/);
      html = parts.map((part, i) => {
        if (part.startsWith('<pre>')) return part;
        return part.replace(/\n/g, '<br>');
      }).join('');

      return html;
    }

    function bellaAddMessage(role, text) {
      const messages = document.getElementById('bella-messages');
      // Remove welcome message on first chat
      const welcome = document.getElementById('bella-welcome');
      if (welcome) welcome.remove();

      const div = document.createElement('div');

      if (role === 'user') {
        div.className = 'bella-msg bella-msg-user';
        div.textContent = text;
      } else if (role === 'bella') {
        div.className = 'bella-msg bella-msg-bella';
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' + bellaRenderMarkdown(text) + '</div>' +
          '</div>';
      } else if (role === 'image') {
        // Bella Session 3: render generated image inline
        div.className = 'bella-msg bella-msg-bella';
        const imgData = typeof text === 'object' ? text : { url: text, prompt: '' };
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' +
              '<div style="margin-bottom:6px;font-size:13px;color:var(--text)">' + bellaRenderMarkdown(imgData.reply || 'Here\u2019s the image:') + '</div>' +
              '<img src="' + (imgData.url||'') + '" style="max-width:100%;border-radius:8px;border:1px solid var(--border);margin:4px 0" loading="lazy">' +
              '<div style="display:flex;gap:8px;margin-top:6px">' +
                '<a href="' + (imgData.url||'') + '" target="_blank" download style="font-size:11px;color:var(--p);text-decoration:none">&#8681; Download</a>' +
                (imgData.asset_id ? '<span style="font-size:10px;color:var(--muted)">Asset #' + imgData.asset_id + '</span>' : '') +
              '</div>' +
            '</div>' +
          '</div>';
      } else if (role === 'document') {
        // Bella Session 4: render document card with Word + PDF download buttons
        div.className = 'bella-msg bella-msg-bella';
        const d = typeof text === 'object' ? text : { title: 'Document' };
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' +
              '<div style="margin-bottom:6px;font-size:13px;color:var(--text)">' + bellaRenderMarkdown(d.reply || 'Document ready:') + '</div>' +
              '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;align-items:center;gap:12px">' +
                '<div style="font-size:28px;flex-shrink:0">&#128196;</div>' +
                '<div style="flex:1;min-width:0">' +
                  '<div style="font-size:13px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (d.title||'Document').replace(/</g,'&lt;') + '</div>' +
                  '<div style="font-size:11px;color:var(--muted);margin-top:2px">' + (d.sections||'?') + ' sections</div>' +
                  '<div style="display:flex;gap:8px;margin-top:8px">' +
                    (d.docx_url ? '<a href="' + d.docx_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; Word</a>' : '') +
                    (d.pdf_url ? '<a href="' + d.pdf_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; PDF</a>' : '') +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +
          '</div>';
      } else if (role === 'presentation') {
        // Bella Session 5: render presentation card with PPT + PDF download
        div.className = 'bella-msg bella-msg-bella';
        const p = typeof text === 'object' ? text : { title: 'Presentation' };
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' +
              '<div style="margin-bottom:6px;font-size:13px;color:var(--text)">' + bellaRenderMarkdown(p.reply || 'Presentation ready:') + '</div>' +
              '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;align-items:center;gap:12px">' +
                '<div style="font-size:28px;flex-shrink:0">&#128202;</div>' +
                '<div style="flex:1;min-width:0">' +
                  '<div style="font-size:13px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (p.title||'Presentation').replace(/</g,'&lt;') + '</div>' +
                  '<div style="font-size:11px;color:var(--muted);margin-top:2px">' + (p.slide_count||'?') + ' slides</div>' +
                  '<div style="display:flex;gap:8px;margin-top:8px">' +
                    (p.pptx_url ? '<a href="' + p.pptx_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; PowerPoint</a>' : '') +
                    (p.pdf_url ? '<a href="' + p.pdf_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; PDF</a>' : '') +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +
          '</div>';
      } else if (role === 'video') {
        // Bella Session 6: render video card with player or progress indicator
        div.className = 'bella-msg bella-msg-bella';
        const v = typeof text === 'object' ? text : { prompt: 'Video' };
        const hasUrl = v.video_url && v.video_url !== '';
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' +
              '<div style="margin-bottom:6px;font-size:13px;color:var(--text)">' + bellaRenderMarkdown(v.reply || (hasUrl ? 'Video ready:' : 'Video is being generated...')) + '</div>' +
              '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:12px">' +
                (hasUrl
                  ? '<video src="' + v.video_url + '" controls style="width:100%;border-radius:6px;max-height:240px" preload="metadata"></video>'
                    + '<div style="display:flex;gap:8px;margin-top:8px">'
                    + '<a href="' + v.video_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; Download</a>'
                    + (v.asset_id ? '<span style="font-size:10px;color:var(--muted);align-self:center">Asset #' + v.asset_id + '</span>' : '')
                    + '</div>'
                  : '<div style="display:flex;align-items:center;gap:10px;padding:8px 0">'
                    + '<div style="font-size:24px">&#127909;</div>'
                    + '<div>'
                    + '<div style="font-size:13px;font-weight:600;color:var(--text)">' + (v.prompt||'Video').replace(/</g,'&lt;').slice(0,60) + '</div>'
                    + '<div style="font-size:11px;color:var(--muted)" id="bella-video-status-' + (v.asset_id||0) + '">'
                    + (v.status === 'in_progress' ? '&#9203; Generating... (polling every 5s)' : 'Status: ' + (v.status||'completed'))
                    + '</div></div></div>'
                ) +
              '</div>' +
            '</div>' +
          '</div>';
        // Start polling if still in progress
        if (!hasUrl && v.asset_id && v.status === 'in_progress') {
          bellaStartVideoPoll(v.asset_id);
        }
      } else if (role === 'error') {
        div.className = 'bella-error';
        div.textContent = text;
      }

      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
      return div;
    }

    function bellaShowTyping() {
      const messages = document.getElementById('bella-messages');
      const div = document.createElement('div');
      div.className = 'bella-typing';
      div.id = 'bella-typing';
      div.innerHTML =
        '<div class="bella-msg-mini-avatar">B</div>' +
        '<div class="bella-typing-dots">' +
          '<div class="bella-typing-dot"></div>' +
          '<div class="bella-typing-dot"></div>' +
          '<div class="bella-typing-dot"></div>' +
        '</div>';
      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
    }

    function bellaHideTyping() {
      const el = document.getElementById('bella-typing');
      if (el) el.remove();
    }

    async function bellaChat(message) {
      if (!message || !message.trim()) return;
      message = message.trim();

      // Add user message
      bellaAddMessage('user', message);

      // Add to history
      window.bellaHistory.push({ role: 'user', content: message });

      // Show typing
      bellaShowTyping();

      // Disable input while processing
      const input = document.getElementById('bella-input');
      const sendBtn = document.getElementById('bella-send-btn');
      input.disabled = true;
      sendBtn.disabled = true;

      try {
        const res = await fetch('/api/admin/bella', {
          method: 'POST',
          headers: {
            'Authorization': 'Bearer ' + token,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: JSON.stringify({
            message: message,
            history: window.bellaHistory.slice(-18), // Send last 18 turns (9 exchanges)
            context_request: _bellaContext || undefined, // Platform stats injected silently
          }),
        });

        bellaHideTyping();

        if (!res.ok) {
          const errData = await res.json().catch(() => ({}));
          throw new Error(errData.error || errData.message || 'Request failed (' + res.status + ')');
        }

        const data = await res.json();
        const reply = data.reply || data.message || data.response || 'No response received.';

        // Bella Session 3+4+5: detect image/document/presentation responses
        if (data.type === 'image' && data.image_url) {
          bellaAddMessage('image', { url: data.image_url, reply: reply, asset_id: data.asset_id, prompt: data.prompt });
        } else if (data.type === 'document') {
          bellaAddMessage('document', { title: data.title, reply: reply, docx_url: data.docx_url, pdf_url: data.pdf_url, sections: data.sections });
        } else if (data.type === 'presentation') {
          bellaAddMessage('presentation', { title: data.title, reply: reply, pptx_url: data.pptx_url, pdf_url: data.pdf_url, slide_count: data.slide_count });
        } else if (data.type === 'video') {
          bellaAddMessage('video', { video_url: data.video_url, reply: reply, asset_id: data.asset_id, status: data.status, prompt: data.prompt, scene_count: data.scene_count });
        } else {
          bellaAddMessage('bella', reply);
        }

        // Store assistant reply in history
        window.bellaHistory.push({ role: 'assistant', content: reply });

        // Trim history to max 20 turns (10 exchanges)
        if (window.bellaHistory.length > 20) {
          window.bellaHistory = window.bellaHistory.slice(-20);
        }

      } catch (err) {
        bellaHideTyping();
        bellaAddMessage('error', 'Error: ' + err.message);
        console.error('Bella chat error:', err);
      } finally {
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();
      }
    }

    function bellaSend() {
      const input = document.getElementById('bella-input');
      const msg = input.value;
      input.value = '';
      input.style.height = 'auto';
      bellaChat(msg);
    }

    function bellaQuickAction(action) {
      const prompts = {
        report: 'Generate a full system status report',
        users: 'Give me a summary of all users and their activity',
        credits: 'Show me credit balances and recent transactions across all workspaces',
        queue: 'What is the current queue status? Any failed jobs?',
      };
      const msg = prompts[action];
      if (msg) {
        // Open panel if not open
        if (!bellaOpen) toggleBella();
        bellaChat(msg);
      }
    }

    // Auto-resize textarea
    document.addEventListener('DOMContentLoaded', function() {
      const bellaInput = document.getElementById('bella-input');
      if (bellaInput) {
        bellaInput.addEventListener('input', function() {
          this.style.height = 'auto';
          this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
        bellaInput.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            bellaSend();
          }
        });
      }
    });

    // Keyboard shortcut: Ctrl+B to toggle Bella
    document.addEventListener('keydown', function(e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        toggleBella();
      }
      // Escape to close
      if (e.key === 'Escape' && bellaOpen) {
        toggleBella();
      }
    });

    // ── Admin Template Library — row actions + upload modal ─────
    // Exposed on window so the table's onclick handlers can find them.
    window._admTemplateToggle = function(e, industry) {
      if (e && e.preventDefault) e.preventDefault();
      api('/templates/' + industry + '/toggle', 'POST').then(function(r){
        if (r && r.success) { pages.templatesAdmin(); }
        else alert('Toggle failed: ' + ((r && r.error) || 'unknown error'));
      });
    };
    window._admTemplateClone = function(e, industry) {
      if (e && e.preventDefault) e.preventDefault();
      if (!confirm('Clone "' + industry + '" as a commercial variant? A new folder "' + industry + '_commercial" will be created in storage/templates/.')) return;
      api('/templates/' + industry + '/clone', 'POST').then(function(r){
        if (r && r.success) { alert('Cloned to ' + r.cloned_to); pages.templatesAdmin(); }
        else alert('Clone failed: ' + ((r && r.error) || 'unknown error'));
      });
    };
    window._admTemplatesOpenUpload = function() {
      var root = document.getElementById('tpl-modal-root');
      if (!root) return;
      root.innerHTML =
        '<div id="tpl-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px" onclick="if(event.target.id===\'tpl-modal\')_admTemplatesCloseUpload()">' +
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:12px;width:480px;max-width:100%;max-height:90vh;overflow-y:auto">' +
            '<div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">' +
              '<div style="font-weight:600;font-size:15px">Add Template</div>' +
              '<button type="button" onclick="_admTemplatesCloseUpload()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px;line-height:1">\u2715</button>' +
            '</div>' +
            '<form id="tpl-upload-form" onsubmit="_admTemplatesSubmit(event)" style="padding:22px;display:flex;flex-direction:column;gap:14px">' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Industry Key (lowercase letters/numbers/underscore)<input name="industry" required pattern="[a-z0-9_]+" placeholder="e.g. dental_clinic" style="display:block;width:100%;margin-top:6px;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Display Name<input name="name" required placeholder="e.g. Dental Clinic Premium" style="display:block;width:100%;margin-top:6px;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Variation<select name="variation" style="display:block;width:100%;margin-top:6px;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"><option value="luxury">Luxury</option><option value="commercial">Commercial</option></select></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">template.html (max 2 MB)<input name="template_html" type="file" accept=".html,text/html" required style="display:block;width:100%;margin-top:6px;padding:8px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">manifest.json (max 1 MB)<input name="manifest_json" type="file" accept=".json,application/json" required style="display:block;width:100%;margin-top:6px;padding:8px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px"></label>' +
              '<div id="tpl-upload-err" style="color:#F87171;font-size:12px;display:none"></div>' +
              '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">' +
                '<button type="button" onclick="_admTemplatesCloseUpload()" style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:10px 18px;border-radius:6px;cursor:pointer;font-size:13px">Cancel</button>' +
                '<button type="submit" style="background:var(--p);color:#fff;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Upload</button>' +
              '</div>' +
            '</form>' +
          '</div>' +
        '</div>';
    };
    window._admTemplatesCloseUpload = function() {
      var root = document.getElementById('tpl-modal-root');
      if (root) root.innerHTML = '';
    };
    window._admTemplatesSubmit = async function(e) {
      e.preventDefault();
      var form = e.target;
      var fd = new FormData(form);
      var errEl = document.getElementById('tpl-upload-err');
      if (errEl) errEl.style.display = 'none';
      try {
        var token = localStorage.getItem('admin_token') || localStorage.getItem('lu_token') || '';
        var resp = await fetch('/api/admin/templates/upload', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
          body: fd
        });
        var d = await resp.json().catch(function(){ return {}; });
        if (d && d.success) {
          _admTemplatesCloseUpload();
          pages.templatesAdmin();
        } else {
          if (errEl) { errEl.textContent = (d && d.error) || ('Upload failed (HTTP ' + resp.status + ')'); errEl.style.display = 'block'; }
        }
      } catch (err) {
        if (errEl) { errEl.textContent = 'Upload failed: ' + (err && err.message || err); errEl.style.display = 'block'; }
      }
    };

    // ── HTML escape polyfill ──
    // bld_escH comes from the main-app JS bundle (/app/js/*) and isn't
    // defined inside the admin blade context. Provide a local fallback so
    // both templatesAdmin() and the Media Library can escape user-supplied
    // strings safely when the main bundle isn't loaded.
    if (typeof window.bld_escH !== 'function') {
      window.bld_escH = function(s) {
        if (s === null || s === undefined) return '';
        return String(s)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      };
    }

    // ── Admin Media Library — grid/list view, stats, filters, upload, arthur-uploads tab ──
    // Single coordinator that drives the whole assets page. State lives on
    // window._adminMedia so switching tabs / pages / filters is cheap.
    async function _mediaRender() {
      var st = window._adminMedia;
      setContent(
        _mediaShellHtml() +
        '<div id="media-stats-row" class="stats-grid" style="margin-bottom:16px"></div>' +
        '<div id="media-tabs" style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:16px">' +
          _mediaTabBtn('library', '\u{1F4C1} Library') +
          _mediaTabBtn('videos', '\u{1F3AC} Videos') +
          _mediaTabBtn('uploads', '\u{1F464} Website Uploads') +
        '</div>' +
        '<div id="media-body"></div>' +
        '<div id="media-modal-root"></div>'
      );
      await _mediaLoadStats();
      if (st.tab === 'uploads')      { await _mediaLoadArthurUploads(); }
      else if (st.tab === 'videos')  { await _mediaLoadVideos(); }
      else                            { await _mediaLoadLibrary(); }
    }

    function _mediaShellHtml() {
      return ''
        + '<style>'
        +   '.mediaTabBtn{padding:10px 18px;background:transparent;border:none;color:var(--muted);font-size:13px;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;letter-spacing:.02em}'
        +   '.mediaTabBtn.active{color:var(--p);border-bottom-color:var(--p)}'
        +   '.mediaCard{background:var(--s1);border:1px solid var(--border);border-radius:10px;overflow:hidden;display:flex;flex-direction:column;transition:transform .2s,border-color .2s}'
        +   '.mediaCard:hover{border-color:var(--p);transform:translateY(-2px)}'
        +   '.mediaThumb{aspect-ratio:4/3;background-color:var(--s2);position:relative;overflow:hidden}'
        +   '.mediaThumb img,.mediaThumb video{width:100%;height:100%;object-fit:cover;display:block}'
        +   '.mediaThumbMissing::after{content:"Image unavailable";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px}'
        +   '.mediaBadge{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.65);color:#fff;padding:4px 10px;border-radius:4px;font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase}'
        +   '.mediaBadgePlatform{background:var(--p)}'
        +   '.mediaMeta{padding:12px 14px;font-size:12px;color:var(--muted);display:flex;flex-direction:column;gap:6px;flex:1}'
        +   '.mediaMetaRow{display:flex;justify-content:space-between;gap:8px;font-size:11px}'
        +   '.mediaActions{display:flex;gap:4px;padding:10px 14px;border-top:1px solid var(--border);background:var(--s2)}'
        +   '.mediaActionBtn{flex:1;font-size:11px;padding:6px 8px;border:1px solid var(--border);background:transparent;color:var(--text);border-radius:4px;cursor:pointer;letter-spacing:.04em}'
        +   '.mediaActionBtn:hover{border-color:var(--p);color:var(--p)}'
        +   '.mediaActionBtn.danger:hover{border-color:#F87171;color:#F87171}'
        +   '.mediaFilters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}'
        +   '.mediaFilters input,.mediaFilters select{background:var(--s2);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:6px;font-size:13px;font-family:inherit}'
        +   '.mediaFilters input{min-width:220px}'
        +   '.mediaPager{display:flex;gap:8px;align-items:center;justify-content:center;margin:20px 0;font-size:13px;color:var(--muted)}'
        +   '.mediaPager button{background:var(--s2);border:1px solid var(--border);color:var(--text);padding:7px 14px;border-radius:6px;cursor:pointer;font-size:13px}'
        +   '.mediaPager button:disabled{opacity:.4;cursor:not-allowed}'
        + '</style>';
    }

    function _mediaTabBtn(tab, label) {
      var active = window._adminMedia.tab === tab;
      return '<button class="mediaTabBtn' + (active?' active':'') + '" onclick="_mediaSwitchTab(\'' + tab + '\')">' + label + '</button>';
    }

    window._mediaSwitchTab = function(tab) {
      window._adminMedia.tab = tab;
      pages.assets();
    };

    async function _mediaLoadStats() {
      var res = await api('/media/stats');
      var row = document.getElementById('media-stats-row');
      if (!row) return;
      if (!res || !res.success) { row.innerHTML = '<div class="stat-card"><div class="stat-value">\u2014</div><div class="stat-label">Stats unavailable</div></div>'; return; }
      row.innerHTML =
        '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (res.total_files||0) + '</div><div class="stat-label">Total Files</div></div>' +
        '<div class="stat-card"><div class="stat-value" style="color:#00E5A8">' + (res.platform||0) + '</div><div class="stat-label">Platform Assets</div></div>' +
        '<div class="stat-card"><div class="stat-value">' + (res.workspace||0) + '</div><div class="stat-label">Workspace Uploads</div></div>' +
        '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (res.storage_human||'0 B') + '</div><div class="stat-label">Storage Used</div></div>';
    }

    async function _mediaLoadLibrary() {
      var st = window._adminMedia;
      var body = document.getElementById('media-body');
      if (!body) return;
      body.innerHTML = '<div class="loading">Loading media...</div>';

      var qs = new URLSearchParams({
        page: st.page,
        per_page: st.per_page,
        search: st.filters.search || '',
        category: st.filters.category || '',
        industry: st.filters.industry || '',
        type: st.filters.type || '',
        source: st.filters.source || '',
      }).toString();
      var res = await api('/media?' + qs);
      if (!res || !res.success) { body.innerHTML = '<div class="loading">Failed to load media</div>'; return; }

      var items = res.data || [];
      var filters = res.filters || { categories:[], industries:[], sources:[] };

      // Asset type sub-tabs (All / Images / Videos / Documents)
      var typeTabs = ['','image','video','document'].map(function(t){
        var label = t==='' ? 'All' : (t==='image'?'Images':(t==='video'?'Videos':'Documents'));
        var active = (st.filters.type||'') === t;
        return '<button onclick="_mediaSetFilter(\'type\',\''+t+'\')" style="padding:8px 16px;background:'+(active?'var(--p)':'transparent')+';color:'+(active?'#fff':'var(--muted)')+';border:1px solid '+(active?'var(--p)':'var(--border)')+';border-radius:6px;cursor:pointer;font-size:12px;font-weight:500;letter-spacing:.04em">'+label+'</button>';
      }).join('');

      // Bulk selection badge (only in select mode)
      var selCount = (st.selected||[]).length;
      var selBar = st.selectMode ?
        '<span style="color:var(--muted);font-size:12px;padding:8px 10px">' + selCount + ' selected</span>' +
        '<button onclick="_mediaBulkDelete()" ' + (selCount?'':'disabled') + ' style="padding:8px 14px;background:'+(selCount?'#DC2626':'var(--s2)')+';color:#fff;border:none;border-radius:6px;cursor:'+(selCount?'pointer':'not-allowed')+';font-size:12px;font-weight:600;opacity:'+(selCount?1:.4)+'">Delete ' + (selCount||'') + '</button>' +
        '<button onclick="_mediaToggleSelectMode()" style="padding:8px 14px;background:transparent;color:var(--muted);border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:12px">Cancel</button>'
        :
        '<button onclick="_mediaToggleSelectMode()" style="padding:8px 14px;background:transparent;color:var(--muted);border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:12px">\u2611 Select</button>';

      var filterHtml =
        '<div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">' + typeTabs + '</div>' +
        '<div class="mediaFilters">' +
          '<input type="search" id="media-search" placeholder="Search filename, description, tags\u2026" value="' + bld_escH(st.filters.search) + '" onkeydown="if(event.key===\'Enter\')_mediaApplySearch()">' +
          '<select id="media-category" onchange="_mediaSetFilter(\'category\',this.value)">' + _mediaOptions(['',...(filters.categories||[])], st.filters.category, function(v){return v===''?'All categories':_mediaCap(v);}) + '</select>' +
          '<select id="media-industry" onchange="_mediaSetFilter(\'industry\',this.value)">' + _mediaOptions(['',...(filters.industries||[])], st.filters.industry, function(v){return v===''?'All industries':_mediaCap(v);}) + '</select>' +
          '<select id="media-source" onchange="_mediaSetFilter(\'source\',this.value)">' + _mediaOptions(['',...(filters.sources||[])], st.filters.source, function(v){return v===''?'All sources':_mediaCap(v);}) + '</select>' +
          '<div style="margin-left:auto;display:flex;gap:6px;align-items:center">' +
            selBar +
            '<button onclick="_mediaSetView(\'grid\')" style="padding:8px 12px;background:' + (st.view==='grid'?'var(--p)':'var(--s2)') + ';color:' + (st.view==='grid'?'#fff':'var(--text)') + ';border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:13px">\u2B1A Grid</button>' +
            '<button onclick="_mediaSetView(\'list\')" style="padding:8px 12px;background:' + (st.view==='list'?'var(--p)':'var(--s2)') + ';color:' + (st.view==='list'?'#fff':'var(--text)') + ';border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:13px">\u2630 List</button>' +
            '<button onclick="_mediaOpenGenerate()" style="padding:8px 18px;background:var(--ac);color:#0F1117;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">\u2726 Generate</button>' +
            '<button onclick="_mediaOpenUpload()" style="padding:8px 18px;background:var(--p);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">+ Upload</button>' +
          '</div>' +
        '</div>';

      var gridOrList = st.view === 'list' ? _mediaListHtml(items) : _mediaGridHtml(items);
      var pager = _mediaPagerHtml(res);

      body.innerHTML = filterHtml + gridOrList + pager;
    }

    // Bulk selection + tag editor helpers
    window._mediaToggleSelectMode = function() {
      var st = window._adminMedia;
      st.selectMode = !st.selectMode;
      st.selected = [];
      _mediaLoadLibrary();
    };
    window._mediaToggleSelected = function(id) {
      var st = window._adminMedia;
      if (!st.selectMode) return;
      var idx = st.selected.indexOf(id);
      if (idx >= 0) st.selected.splice(idx, 1);
      else st.selected.push(id);
      _mediaLoadLibrary();
    };
    window._mediaBulkDelete = async function() {
      var st = window._adminMedia;
      if (!st.selected || !st.selected.length) return;
      if (!confirm('Delete ' + st.selected.length + ' media files? This removes the DB rows AND the files on disk.')) return;
      var token = localStorage.getItem('admin_token') || localStorage.getItem('lu_token') || '';
      var r = await fetch('/api/admin/media/bulk-delete', { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+token}, body: JSON.stringify({ ids: st.selected }) });
      var d = await r.json().catch(function(){return {};});
      if (d && d.success) {
        alert('Deleted ' + d.rows_deleted + ' rows, removed ' + d.files_removed + ' files from disk.');
        st.selected = []; st.selectMode = false;
        _mediaLoadStats(); _mediaLoadLibrary();
      } else alert('Bulk delete failed: ' + ((d && d.error) || 'unknown'));
    };

    window._mediaEditTags = async function(id, currentTagsJson) {
      var current = [];
      try { current = JSON.parse(currentTagsJson || '[]') || []; } catch(e){}
      var next = prompt('Tags (comma-separated):', current.join(', '));
      if (next === null) return; // cancelled
      var tags = next.split(',').map(function(t){return t.trim();}).filter(function(t){return t;});
      var token = localStorage.getItem('admin_token') || localStorage.getItem('lu_token') || '';
      var r = await fetch('/api/admin/media/' + id + '/tags', { method:'PATCH', headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+token}, body: JSON.stringify({ tags: tags }) });
      var d = await r.json().catch(function(){return {};});
      if (d && d.success) _mediaLoadLibrary();
      else alert('Tag update failed: ' + ((d && d.error) || 'unknown'));
    };

    window._mediaShowUsage = async function(id) {
      var token = localStorage.getItem('admin_token') || localStorage.getItem('lu_token') || '';
      var r = await fetch('/api/admin/media/' + id + '/usage', { headers:{'Accept':'application/json','Authorization':'Bearer '+token} });
      var d = await r.json().catch(function(){return {};});
      if (!d || !d.success) { alert('Usage lookup failed'); return; }
      var lines = ['Media #' + id + ' — ' + (d.url || '')];
      lines.push('Total usage: ' + (d.total_usage||0));
      if (d.websites && d.websites.length) {
        lines.push('\nWebsites (' + d.websites.length + '):');
        d.websites.forEach(function(w){ lines.push('  \u2022 #' + w.id + ' ' + (w.name||'') + ' [' + (w.template_industry||'?') + ', ' + (w.status||'?') + ']'); });
      }
      if (d.articles && d.articles.length) {
        lines.push('\nArticles (' + d.articles.length + '):');
        d.articles.forEach(function(a){ lines.push('  \u2022 #' + a.id + ' ' + (a.title||'') + ' [' + (a.status||'?') + ']'); });
      }
      if (!d.total_usage) lines.push('\nNot used in any site or article yet.');
      alert(lines.join('\n'));
    };

    function _mediaFormatDuration(s) {
      if (!s || s <= 0) return '';
      var m = Math.floor(s/60), ss = s % 60;
      return m + ':' + (ss<10?'0':'') + ss;
    }

    function _mediaOptions(values, current, labelFn) {
      return values.map(function(v){
        var sel = (String(v) === String(current || '')) ? ' selected' : '';
        return '<option value="' + bld_escH(v) + '"' + sel + '>' + bld_escH(labelFn(v)) + '</option>';
      }).join('');
    }

    function _mediaCap(v) {
      if (!v) return v;
      return String(v).replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();});
    }

    window._mediaSetFilter = function(key, val) {
      window._adminMedia.filters[key] = val;
      window._adminMedia.page = 1;
      _mediaLoadLibrary();
    };
    window._mediaApplySearch = function() {
      var el = document.getElementById('media-search');
      window._adminMedia.filters.search = el ? el.value : '';
      window._adminMedia.page = 1;
      _mediaLoadLibrary();
    };
    window._mediaSetView = function(view) {
      window._adminMedia.view = view;
      _mediaLoadLibrary();
    };

    function _mediaGridHtml(items) {
      if (!items.length) return '<div style="padding:48px;text-align:center;color:var(--muted)">No media matches the current filters.</div>';
      var st = window._adminMedia;
      var cards = items.map(function(m){
        var isImage = (m.asset_type === 'image') || (m.mime_type && m.mime_type.indexOf('image/') === 0);
        var isVideo = (m.asset_type === 'video') || (m.mime_type && m.mime_type.indexOf('video/') === 0);
        var isDoc   = (m.asset_type === 'document');
        var url = m.url || '';
        var thumbSrc = m.thumbnail_url || (isImage ? url : '');
        var mediaEl;
        if (isVideo && url) {
          // Prefer the generated ffmpeg thumbnail; fall back to <video> preload poster
          if (thumbSrc) {
            mediaEl = '<img src="' + bld_escH(thumbSrc) + '" alt="' + bld_escH(m.filename||'') + '" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block">';
          } else {
            mediaEl = '<video src="' + bld_escH(url) + '" preload="metadata" muted style="width:100%;height:100%;object-fit:cover;display:block" onmouseover="this.play()" onmouseout="this.pause();this.currentTime=0"></video>';
          }
        } else if (isImage && url) {
          mediaEl = '<img src="' + bld_escH(url) + '" alt="' + bld_escH(m.filename||'') + '" loading="lazy" onerror="this.style.display=\'none\';this.parentElement.classList.add(\'mediaThumbMissing\')" style="width:100%;height:100%;object-fit:cover;display:block">';
        } else if (isDoc) {
          mediaEl = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:32px">\u{1F4C4}</div>';
        } else {
          mediaEl = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px">File</div>';
        }
        var badge = m.category ? '<span class="mediaBadge">' + bld_escH(m.category) + '</span>' : '';
        var platformBadge = m.is_platform_asset ? '<span class="mediaBadge mediaBadgePlatform" style="top:auto;bottom:8px;left:8px">Platform</span>' : '';
        var typeBadge = isVideo ?
          '<div style="position:absolute;top:8px;right:8px;font-size:11px;color:#fff;background:rgba(0,0,0,.72);padding:4px 8px;border-radius:4px;letter-spacing:.08em;font-weight:600">\u25B6 ' + (_mediaFormatDuration(m.duration_seconds) || 'VIDEO') + '</div>' : '';
        var dims = (m.width && m.height) ? m.width + ' \u00D7 ' + m.height : (isVideo?'Video':'\u2014');
        var selected = (st.selected||[]).indexOf(m.id) >= 0;
        var selCheckbox = st.selectMode ?
          '<div onclick="event.stopPropagation();_mediaToggleSelected(' + m.id + ')" style="position:absolute;top:8px;left:8px;width:24px;height:24px;border-radius:50%;background:' + (selected?'var(--p)':'rgba(0,0,0,.6)') + ';border:2px solid #fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:700">' + (selected?'\u2713':'') + '</div>' : '';
        var tagsJson = m.tags ? (typeof m.tags === 'string' ? m.tags : JSON.stringify(m.tags)) : '[]';
        var tagsAttr = bld_escH(tagsJson);
        var clickCardAttr = st.selectMode ? ' onclick="_mediaToggleSelected(' + m.id + ')" style="cursor:pointer' + (selected?';outline:3px solid var(--p);outline-offset:-3px':'') + '"' : '';
        return '<div class="mediaCard"' + clickCardAttr + '>' +
                 '<div class="mediaThumb">' + mediaEl + badge + platformBadge + typeBadge + selCheckbox + '</div>' +
                 '<div class="mediaMeta">' +
                   '<div style="font-weight:500;color:var(--text);font-size:13px" title="' + bld_escH(m.filename||'') + '">' + bld_escH(truncate(m.filename,26)) + '</div>' +
                   '<div class="mediaMetaRow"><span>' + (m.industry ? bld_escH(_mediaCap(m.industry)) : '\u2014') + '</span><span>' + dims + '</span></div>' +
                   '<div class="mediaMetaRow"><span>' + _mediaHumanBytes(m.size_bytes||0) + '</span><span>Used ' + (m.use_count||0) + '\u00D7</span></div>' +
                 '</div>' +
                 '<div class="mediaActions">' +
                   '<button class="mediaActionBtn" onclick="event.stopPropagation();window.open(\'' + (m.url||'#') + '\',\'_blank\')">Preview</button>' +
                   '<button class="mediaActionBtn" onclick="event.stopPropagation();_mediaEditTags(' + m.id + ',\'' + tagsAttr + '\')">Tags</button>' +
                   '<button class="mediaActionBtn" onclick="event.stopPropagation();_mediaShowUsage(' + m.id + ')">Usage</button>' +
                   '<button class="mediaActionBtn danger" onclick="event.stopPropagation();_mediaDelete(' + m.id + ')">Delete</button>' +
                 '</div>' +
               '</div>';
      }).join('');
      return '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">' + cards + '</div>';
    }

    function _mediaListHtml(items) {
      if (!items.length) return '<div style="padding:48px;text-align:center;color:var(--muted)">No media matches the current filters.</div>';
      var rows = items.map(function(m){
        return '<tr>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border);font-size:12px;font-family:monospace"><a href="' + (m.url||'#') + '" target="_blank" style="color:var(--p)">' + bld_escH(truncate(m.filename,40)) + '</a></td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + bld_escH(m.category||'\u2014') + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + bld_escH(m.industry||'\u2014') + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + bld_escH(m.mime_type||'\u2014') + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border);text-align:right">' + ((m.width&&m.height)?(m.width+'\u00D7'+m.height):'\u2014') + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border);text-align:right">' + _mediaHumanBytes(m.size_bytes||0) + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border);text-align:right">' + (m.use_count||0) + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + (m.is_platform_asset?'<span class="badge badge-green">platform</span>':bld_escH(m.source||'\u2014')) + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + ts(m.created_at) + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' +
            '<button class="mediaActionBtn" onclick="_mediaCopyUrl(\'' + (m.url||'') + '\')">Copy</button> ' +
            '<button class="mediaActionBtn danger" onclick="_mediaDelete(' + m.id + ')">Delete</button>' +
          '</td></tr>';
      }).join('');
      return '<div style="background:var(--s1);border:1px solid var(--border);border-radius:8px;overflow-x:auto">' +
               '<table style="width:100%;border-collapse:collapse;font-size:13px">' +
                 '<thead><tr style="background:var(--s2)">' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">File</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Category</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Industry</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Mime</th>' +
                   '<th style="text-align:right;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Dims</th>' +
                   '<th style="text-align:right;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Size</th>' +
                   '<th style="text-align:right;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Used</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Source</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Created</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Actions</th>' +
                 '</tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function _mediaPagerHtml(res) {
      var total = res.total||0, tp = res.total_pages||1, p = res.page||1;
      if (tp <= 1) return '';
      return '<div class="mediaPager">' +
               '<button ' + (p<=1?'disabled':'') + ' onclick="_mediaGoPage(' + (p-1) + ')">\u2190 Prev</button>' +
               '<span>Page ' + p + ' / ' + tp + ' \u00B7 ' + total + ' total</span>' +
               '<button ' + (p>=tp?'disabled':'') + ' onclick="_mediaGoPage(' + (p+1) + ')">Next \u2192</button>' +
             '</div>';
    }
    window._mediaGoPage = function(p) {
      window._adminMedia.page = p;
      _mediaLoadLibrary();
    };

    function _mediaHumanBytes(b) {
      if (!b || b<=0) return '0 B';
      var u = ['B','KB','MB','GB'], i = Math.floor(Math.log(b)/Math.log(1024));
      i = Math.min(i, u.length-1);
      return (b/Math.pow(1024,i)).toFixed(i>1?2:0) + ' ' + u[i];
    }

    window._mediaCopyUrl = function(url) {
      if (!url) return;
      var abs = url.indexOf('http')===0 ? url : (location.origin + url);
      if (navigator.clipboard) navigator.clipboard.writeText(abs).then(function(){ alert('URL copied: ' + abs); });
      else { window.prompt('Copy URL:', abs); }
    };

    window._mediaDelete = async function(id) {
      if (!confirm('Delete media #' + id + '? This also removes the file from disk.')) return;
      var token = localStorage.getItem('admin_token') || localStorage.getItem('lu_token') || '';
      var r = await fetch('/api/admin/media/' + id, { method: 'DELETE', headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' } });
      var d = await r.json().catch(function(){return {};});
      if (d && d.success) { _mediaLoadStats(); _mediaLoadLibrary(); }
      else alert('Delete failed: ' + ((d && d.error) || 'unknown'));
    };

    async function _mediaLoadArthurUploads() {
      var body = document.getElementById('media-body');
      if (!body) return;
      body.innerHTML = '<div class="loading">Loading Arthur uploads...</div>';
      var res = await api('/media/arthur-uploads');
      if (!res || !res.success) { body.innerHTML = '<div class="loading">Failed to load Arthur uploads</div>'; return; }
      var items = res.data || [];
      var note = '<div style="background:var(--s1);border:1px solid var(--border);border-left:3px solid var(--p);padding:14px 18px;margin-bottom:16px;font-size:13px;color:var(--muted);border-radius:6px">These files were uploaded by users during the Arthur website-build flow. They are stored on disk under <code>storage/app/public/arthur-uploads/{workspace_id}/</code> and are not tracked in the <code>media</code> table.</div>';
      if (!items.length) { body.innerHTML = note + '<div style="padding:48px;text-align:center;color:var(--muted)">No Arthur uploads found.</div>'; return; }
      var rows = items.map(function(f){
        var isImage = f.mime_type && f.mime_type.indexOf('image/') === 0;
        var isVideo = f.mime_type && f.mime_type.indexOf('video/') === 0;
        var mediaEl;
        if (isImage) mediaEl = '<img src="' + bld_escH(f.url) + '" alt="' + bld_escH(f.filename) + '" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block">';
        else if (isVideo) mediaEl = '<video src="' + bld_escH(f.url) + '" preload="metadata" muted style="width:100%;height:100%;object-fit:cover;display:block"></video>';
        else mediaEl = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px">File</div>';
        return '<div class="mediaCard">' +
                 '<div class="mediaThumb">' + mediaEl + '</div>' +
                 '<div class="mediaMeta">' +
                   '<div style="font-weight:500;color:var(--text);font-size:13px" title="' + bld_escH(f.filename) + '">' + bld_escH(truncate(f.filename,28)) + '</div>' +
                   '<div class="mediaMetaRow"><span>Workspace ' + (f.workspace_id||'\u2014') + '</span><span>' + _mediaHumanBytes(f.size_bytes||0) + '</span></div>' +
                   '<div class="mediaMetaRow"><span>' + ts(f.modified_at) + '</span><span>' + bld_escH(f.mime_type||'') + '</span></div>' +
                 '</div>' +
                 '<div class="mediaActions">' +
                   '<button class="mediaActionBtn" onclick="window.open(\'' + f.url + '\',\'_blank\')">Preview</button>' +
                   '<button class="mediaActionBtn" onclick="_mediaCopyUrl(\'' + f.url + '\')">Copy URL</button>' +
                 '</div>' +
               '</div>';
      }).join('');
      body.innerHTML = note + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">' + rows + '</div>';
    }

    // ── Videos tab ──
    // Filters the media library to mime_type=video/*. The creative engine
    // dual-write in CreativeService::completeAsset() lands completed
    // MiniMax / Runway generations here automatically. Still useful when
    // empty — renders an explainer so admins know where new videos will
    // appear.
    async function _mediaLoadVideos() {
      var body = document.getElementById('media-body');
      if (!body) return;
      body.innerHTML = '<div class="loading">Loading videos...</div>';
      var res = await api('/media?type=video&per_page=60');
      if (!res || !res.success) { body.innerHTML = '<div class="loading">Failed to load videos</div>'; return; }
      var items = res.data || [];
      var note = '<div style="background:var(--s1);border:1px solid var(--border);border-left:3px solid var(--p);padding:14px 18px;margin-bottom:16px;font-size:13px;color:var(--muted);border-radius:6px">Videos from the Creative engine (MiniMax, Runway, and future providers) appear here once generation completes. Every completed asset is dual-written into the <code>media</code> table, so new videos surface automatically \u2014 no backfill required.</div>';
      if (!items.length) {
        body.innerHTML = note +
          '<div style="padding:56px 24px;text-align:center;color:var(--muted);background:var(--s1);border:1px dashed var(--border);border-radius:10px">' +
            '<div style="font-size:40px;margin-bottom:12px;opacity:.6">\u{1F3AC}</div>' +
            '<div style="font-weight:600;color:var(--text);font-size:15px;margin-bottom:8px">No videos yet</div>' +
            '<div style="max-width:500px;margin:0 auto;line-height:1.65">No completed video generations in the <code>media</code> table yet. Videos will appear here after the Creative engine produces them via MiniMax or Runway. The dual-write hook is live \u2014 the next successful generation will land here automatically.</div>' +
          '</div>';
        return;
      }
      body.innerHTML = note + _mediaGridHtml(items);
    }

    // ── Upload modal ──
    window._mediaOpenUpload = function() {
      var root = document.getElementById('media-modal-root');
      if (!root) return;
      root.innerHTML =
        '<div id="mu-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px" onclick="if(event.target.id===\'mu-modal\')_mediaCloseUpload()">' +
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:12px;width:480px;max-width:100%;max-height:90vh;overflow-y:auto">' +
            '<div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center"><div style="font-weight:600;font-size:15px">Upload Media</div><button type="button" onclick="_mediaCloseUpload()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px">\u2715</button></div>' +
            '<form id="mu-form" onsubmit="_mediaSubmitUpload(event)" style="padding:22px;display:flex;flex-direction:column;gap:14px">' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Files (images, video, documents — up to 100 MB each, drop multiple)<input name="files[]" type="file" accept="image/*,video/*,.pdf,.doc,.docx" multiple required style="display:block;width:100%;margin-top:6px;padding:8px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Category<select name="category" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"><option value="">\u2014 Select \u2014</option><option value="hero">Hero</option><option value="gallery">Gallery</option><option value="website">Website</option><option value="blog">Blog</option><option value="logo">Logo</option><option value="team">Team</option><option value="other">Other</option></select></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Industry (optional)<input name="industry" type="text" placeholder="e.g. restaurant, technology, marketing_agency" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Description (optional)<input name="description" type="text" placeholder="Short description" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Tags (comma-separated, optional)<input name="tags" type="text" placeholder="luxury, warm, editorial" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<div id="mu-err" style="color:#F87171;font-size:12px;display:none"></div>' +
              '<div style="display:flex;gap:10px;justify-content:flex-end"><button type="button" onclick="_mediaCloseUpload()" style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:10px 18px;border-radius:6px;cursor:pointer;font-size:13px">Cancel</button><button type="submit" style="background:var(--p);color:#fff;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Upload</button></div>' +
            '</form>' +
          '</div>' +
        '</div>';
    };
    window._mediaCloseUpload = function() {
      var root = document.getElementById('media-modal-root');
      if (root) root.innerHTML = '';
    };
    window._mediaSubmitUpload = async function(e) {
      e.preventDefault();
      var form = e.target;
      var filesInput = form.querySelector('input[type=file]');
      var files = filesInput ? filesInput.files : null;
      var err = document.getElementById('mu-err');
      if (err) err.style.display = 'none';
      if (!files || !files.length) { if (err) { err.textContent = 'Pick at least one file.'; err.style.display = 'block'; } return; }

      // Choose single vs bulk endpoint based on file count.
      var multi = files.length > 1;
      var fd = new FormData();
      if (multi) {
        for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
      } else {
        fd.append('file', files[0]);
      }
      ['category','industry','description','tags'].forEach(function(k){
        var el = form.querySelector('[name="'+k+'"]');
        if (el && el.value) fd.append(k, el.value);
      });

      try {
        var token = localStorage.getItem('admin_token') || localStorage.getItem('lu_token') || '';
        var endpoint = multi ? '/api/admin/media/bulk-upload' : '/api/admin/media/upload';
        var submitBtn = form.querySelector('button[type=submit]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = multi ? ('Uploading ' + files.length + '...') : 'Uploading...'; }
        var r = await fetch(endpoint, { method:'POST', headers:{'Authorization':'Bearer '+token,'Accept':'application/json'}, body: fd });
        var d = await r.json().catch(function(){return {};});
        if (d && d.success) {
          _mediaCloseUpload();
          _mediaLoadStats();
          _mediaLoadLibrary();
          if (multi && d.failed_count > 0) {
            var msg = 'Uploaded ' + d.uploaded_count + ' OK, ' + d.failed_count + ' failed:\n';
            (d.failed||[]).forEach(function(f){ msg += '\u2022 ' + f.filename + ': ' + f.error + '\n'; });
            alert(msg);
          }
        } else {
          if (err) { err.textContent = (d && d.error) || ('Upload failed (' + r.status + ')'); err.style.display = 'block'; }
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Upload'; }
        }
      } catch (ex) {
        if (err) { err.textContent = 'Upload failed: ' + (ex && ex.message || ex); err.style.display = 'block'; }
      }
    };

    // ── Generate modal (T3.8) — DALL-E 3 admin-side image generation ──
    // Shape mirrors _mediaOpenUpload but submits JSON to /api/admin/media/generate.
    // T3.1D backend already inlines thumbnail generation, so the new image
    // appears in the grid with thumbnail on the post-success refresh.
    var _MEDIA_COST_MATRIX = {
      '1024x1024|standard': 0.04,
      '1024x1024|hd':       0.08,
      '1792x1024|standard': 0.08,
      '1792x1024|hd':       0.12,
      '1024x1792|standard': 0.08,
      '1024x1792|hd':       0.12
    };

    window._mediaGenSlug = function(s) {
      return String(s||'').toLowerCase()
        .replace(/[^a-z0-9\s-]/g,'').trim()
        .split(/\s+/).slice(0,5).join('-')
        .replace(/-+/g,'-').replace(/^-|-$/g,'') || 'image';
    };

    window._mediaGenAutoFilename = function(prompt) {
      var slug = _mediaGenSlug(prompt);
      var ts = Math.floor(Date.now()/1000);
      return slug + '_' + ts;
    };

    window._mediaGenUpdateCost = function() {
      var sizeEl = document.querySelector('#mg-form [name=size]');
      var qualEl = document.querySelector('#mg-form [name=quality]');
      var costEl = document.getElementById('mg-cost');
      if (!sizeEl || !qualEl || !costEl) return;
      var key = sizeEl.value + '|' + qualEl.value;
      var cost = _MEDIA_COST_MATRIX[key] || 0;
      costEl.textContent = 'Estimated cost: $' + cost.toFixed(2);
    };

    window._mediaGenUpdatePromptUI = function() {
      var ta = document.querySelector('#mg-form [name=prompt]');
      var counter = document.getElementById('mg-counter');
      var fnameEl = document.querySelector('#mg-form [name=filename]');
      if (!ta || !counter) return;
      var n = (ta.value || '').length;
      counter.textContent = n + '/1000';
      counter.style.color = n > 900 ? '#F87171' : 'var(--muted)';
      if (fnameEl && !fnameEl.value) {
        fnameEl.placeholder = ta.value ? _mediaGenAutoFilename(ta.value) : 'auto-generated from prompt';
      }
    };

    window._mediaOpenGenerate = function() {
      var root = document.getElementById('media-modal-root');
      if (!root) return;
      root.innerHTML =
        '<div id="mg-modal" data-busy="0" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px" onclick="if(event.target.id===\'mg-modal\' && event.currentTarget.dataset.busy!==\'1\')_mediaCloseGenerate()">' +
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:12px;width:520px;max-width:100%;max-height:90vh;overflow-y:auto">' +
            '<div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">' +
              '<div style="font-weight:600;font-size:15px">✦ Generate Image (DALL-E 3)</div>' +
              '<button type="button" onclick="_mediaCloseGenerate()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px">✕</button>' +
            '</div>' +
            '<form id="mg-form" onsubmit="_mediaSubmitGenerate(event)" style="padding:22px;display:flex;flex-direction:column;gap:14px">' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500;display:flex;justify-content:space-between;align-items:center">' +
                '<span>Prompt</span><span id="mg-counter" style="font-size:11px">0/1000</span>' +
              '</label>' +
              '<textarea name="prompt" required maxlength="1000" rows="4" oninput="_mediaGenUpdatePromptUI()" placeholder="Describe the image. Be specific: subject, style, lighting, no text, no people if you want stock-style." style="width:100%;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px;font-family:inherit;resize:vertical;min-height:90px"></textarea>' +
              '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                '<label style="font-size:12px;color:var(--muted);font-weight:500">Size' +
                  '<select name="size" onchange="_mediaGenUpdateCost()" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px">' +
                    '<option value="1024x1024">1024×1024 (square)</option>' +
                    '<option value="1792x1024">1792×1024 (landscape)</option>' +
                    '<option value="1024x1792">1024×1792 (portrait)</option>' +
                  '</select>' +
                '</label>' +
                '<label style="font-size:12px;color:var(--muted);font-weight:500">Quality' +
                  '<select name="quality" onchange="_mediaGenUpdateCost()" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px">' +
                    '<option value="standard">Standard</option>' +
                    '<option value="hd">HD</option>' +
                  '</select>' +
                '</label>' +
              '</div>' +
              '<div id="mg-cost" style="font-size:13px;color:var(--ac);font-weight:600">Estimated cost: $0.04</div>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Category' +
                '<select name="category" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px">' +
                  '<option value="">— Select —</option>' +
                  '<option value="hero">Hero</option>' +
                  '<option value="gallery">Gallery</option>' +
                  '<option value="website">Website</option>' +
                  '<option value="blog">Blog</option>' +
                  '<option value="logo">Logo</option>' +
                  '<option value="team">Team</option>' +
                  '<option value="other">Other</option>' +
                '</select>' +
              '</label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Filename (optional)' +
                '<input name="filename" type="text" placeholder="auto-generated from prompt" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px">' +
              '</label>' +
              '<div id="mg-err" style="color:#F87171;font-size:12px;display:none"></div>' +
              '<div style="display:flex;gap:10px;justify-content:flex-end">' +
                '<button type="button" id="mg-cancel-btn" onclick="_mediaCloseGenerate()" style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:10px 18px;border-radius:6px;cursor:pointer;font-size:13px">Cancel</button>' +
                '<button type="submit" id="mg-submit-btn" style="background:var(--ac);color:#0F1117;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Generate</button>' +
              '</div>' +
            '</form>' +
          '</div>' +
        '</div>';
      _mediaGenUpdateCost();
    };

    window._mediaCloseGenerate = function() {
      var modal = document.getElementById('mg-modal');
      if (modal && modal.dataset.busy === '1') return;
      var root = document.getElementById('media-modal-root');
      if (root) root.innerHTML = '';
    };

    window._mediaShowToast = function(msg, ok) {
      var toast = document.createElement('div');
      toast.textContent = msg;
      toast.style.cssText = 'position:fixed;top:24px;right:24px;z-index:10000;background:' +
        (ok ? 'var(--ac)' : '#F87171') + ';color:' + (ok ? '#0F1117' : '#fff') +
        ';padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.4);transition:opacity .3s';
      document.body.appendChild(toast);
      setTimeout(function(){ toast.style.opacity = '0'; }, 2700);
      setTimeout(function(){ toast.remove(); }, 3000);
    };

    window._mediaSubmitGenerate = async function(e) {
      e.preventDefault();
      var form = e.target;
      var modal = document.getElementById('mg-modal');
      var err = document.getElementById('mg-err');
      var submitBtn = document.getElementById('mg-submit-btn');
      var cancelBtn = document.getElementById('mg-cancel-btn');
      if (err) err.style.display = 'none';

      var prompt   = (form.querySelector('[name=prompt]')   || {}).value || '';
      var size     = (form.querySelector('[name=size]')     || {}).value || '1024x1024';
      var quality  = (form.querySelector('[name=quality]')  || {}).value || 'standard';
      var category = (form.querySelector('[name=category]') || {}).value || '';
      var filename = (form.querySelector('[name=filename]') || {}).value || '';

      prompt = prompt.trim();
      if (!prompt) {
        if (err) { err.textContent = 'Prompt is required.'; err.style.display = 'block'; }
        return;
      }
      if (!filename) filename = _mediaGenAutoFilename(prompt);
      var costKey = size + '|' + quality;
      var cost    = _MEDIA_COST_MATRIX[costKey] || 0;

      // Lock UI during generation
      if (modal) modal.dataset.busy = '1';
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '⟳ Generating… (~10s)'; }
      if (cancelBtn) { cancelBtn.disabled = true; cancelBtn.style.opacity = '0.5'; cancelBtn.style.cursor = 'not-allowed'; }

      try {
        var token = localStorage.getItem('admin_token') || localStorage.getItem('lu_token') || '';
        var r = await fetch('/api/admin/media/generate', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + token
          },
          body: JSON.stringify({
            prompt: prompt,
            size: size,
            quality: quality,
            category: category,
            filename: filename
          })
        });
        var d = await r.json().catch(function(){ return {}; });
        if (d && d.success) {
          if (modal) modal.dataset.busy = '0';
          _mediaCloseGenerate();
          _mediaShowToast('✓ Image generated — $' + cost.toFixed(2) + ' charged', true);
          _mediaLoadStats();
          _mediaLoadLibrary();
        } else {
          if (modal) modal.dataset.busy = '0';
          if (err) { err.textContent = (d && d.error) || ('Generation failed (HTTP ' + r.status + ')'); err.style.display = 'block'; }
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Generate'; }
          if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.style.opacity = '1'; cancelBtn.style.cursor = 'pointer'; }
        }
      } catch (ex) {
        if (modal) modal.dataset.busy = '0';
        if (err) { err.textContent = 'Generation failed: ' + (ex && ex.message || ex); err.style.display = 'block'; }
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Generate'; }
        if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.style.opacity = '1'; cancelBtn.style.cursor = 'pointer'; }
      }
    };

    // -- Boot ----------------------------------------------------------------------
    pages.dashboard();
  </script>

  <!-- Bella AI Floating Button (Bella Session 2) -->
  <button class="bella-toggle" id="bella-toggle-btn" onclick="toggleBella()" title="Chat with Bella AI">
    <span class="bella-toggle-pulse"></span>&#10024; Bella
  </button>

  <!-- Bella overlay disabled — non-modal so admin can browse while chatting -->
  <div class="bella-overlay" id="bella-overlay" style="display:none"></div>

  <!-- Bella AI Chat Panel (Bella Session 2 — full widget) -->
  <div class="bella-panel" id="bella-panel">
    <div class="bella-header">
      <div class="bella-avatar">B</div>
      <div class="bella-header-info">
        <div class="bella-header-name">Bella</div>
        <div class="bella-header-role">EXECUTIVE ASSISTANT</div>
      </div>
      <div style="display:flex;gap:6px;margin-left:auto">
        <button class="bella-close" onclick="bellaToggleArtifacts()" title="Artifacts" style="font-size:14px">&#128193;</button>
        <button class="bella-close" onclick="toggleBella()" title="Close">&#215;</button>
      </div>
    </div>

    <!-- Artifacts slide-over (hidden by default) -->
    <div id="bella-artifacts-panel" style="display:none;flex:1;overflow-y:auto;padding:12px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <span style="font-size:13px;font-weight:600;color:var(--text)">Generated Artifacts</span>
        <button class="bella-close" onclick="bellaToggleArtifacts()" style="font-size:11px">Back to chat</button>
      </div>
      <div id="bella-artifacts-list" style="font-size:12px;color:var(--muted)">Loading...</div>
    </div>

    <!-- Chat area -->
    <div id="bella-chat-area" style="display:flex;flex-direction:column;flex:1;min-height:0">
      <div class="bella-messages" id="bella-messages">
        <div class="bella-welcome" id="bella-welcome">
          <div class="bella-welcome-avatar">B</div>
          <h3>Hi, I'm Bella</h3>
          <p>Your executive assistant.<br>I can analyze screenshots, generate reports, manage credits, and more.</p>
        </div>
      </div>
      <div class="bella-quick-actions">
        <button class="bella-quick-btn" onclick="bellaQuickAction('report')">&#128202; Report</button>
        <button class="bella-quick-btn" onclick="bellaQuickAction('users')">&#128101; Users</button>
        <button class="bella-quick-btn" onclick="bellaQuickAction('credits')">&#128176; Credits</button>
        <button class="bella-quick-btn" onclick="bellaQuickAction('queue')
      </div>
      <div id="admin-api-usage" class="admin-section" style="display:none">
        <h3 style="font-family:var(--fh);font-size:18px;margin:0 0 16px">💰 API Usage & Costs</h3>
        <div id="api-usage-summary" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px"></div>
        <h4 style="font-size:13px;font-weight:700;color:var(--t2);margin:0 0 10px">By Provider</h4>
        <div id="api-usage-providers" style="margin-bottom:20px"></div>
        <h4 style="font-size:13px;font-weight:700;color:var(--t2);margin:0 0 10px">Recent Calls</h4>
        <div id="api-usage-recent" style="max-height:300px;overflow-y:auto"></div>
      </div>
      <script>
      async function loadApiUsage(){
        try{
          var jwt=localStorage.getItem('bella_jwt');
          var r=await fetch('/api/admin/api-usage',{headers:{'Authorization':'Bearer '+jwt}});
          var d=await r.json();
          var s=d.summary||{};
          var today=s.today||{};var month=s.this_month||{};var total=s.total||{};
          document.getElementById('api-usage-summary').innerHTML=
            '<div style="background:#1a1d2e;border:1px solid #2a2f4a;border-radius:10px;padding:14px;text-align:center"><div style="font-size:22px;font-weight:700;color:#00E5A8">'+((today.calls||0))+'</div><div style="font-size:10px;color:#6B7280;margin-top:4px">Calls Today</div></div>'+
            '<div style="background:#1a1d2e;border:1px solid #2a2f4a;border-radius:10px;padding:14px;text-align:center"><div style="font-size:22px;font-weight:700;color:#3B82F6">'+(today.tokens||0).toLocaleString()+'</div><div style="font-size:10px;color:#6B7280;margin-top:4px">Tokens Today</div></div>'+
            '<div style="background:#1a1d2e;border:1px solid #2a2f4a;border-radius:10px;padding:14px;text-align:center"><div style="font-size:22px;font-weight:700;color:#F59E0B">$'+parseFloat(today.cost_usd||0).toFixed(4)+'</div><div style="font-size:10px;color:#6B7280;margin-top:4px">Cost Today</div></div>'+
            '<div style="background:#1a1d2e;border:1px solid #2a2f4a;border-radius:10px;padding:14px;text-align:center"><div style="font-size:22px;font-weight:700;color:#EC4899">$'+parseFloat(month.cost_usd||0).toFixed(4)+'</div><div style="font-size:10px;color:#6B7280;margin-top:4px">Cost This Month</div></div>';
          var providers=d.by_provider||[];
          document.getElementById('api-usage-providers').innerHTML='<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr style="border-bottom:1px solid #2a2f4a"><th style="text-align:left;padding:8px;color:#6B7280">Provider</th><th style="padding:8px;color:#6B7280">Calls</th><th style="padding:8px;color:#6B7280">Tokens</th><th style="padding:8px;color:#6B7280">Cost</th><th style="padding:8px;color:#6B7280">Avg ms</th></tr></thead><tbody>'+
            providers.map(function(p){return '<tr style="border-bottom:1px solid #1a1d2e"><td style="padding:8px;font-weight:600;color:#E8EDF5">'+p.provider+'</td><td style="padding:8px;text-align:center;color:#8B97B0">'+p.calls+'</td><td style="padding:8px;text-align:center;color:#8B97B0">'+(p.tokens||0).toLocaleString()+'</td><td style="padding:8px;text-align:center;color:#F59E0B">$'+parseFloat(p.cost_usd||0).toFixed(4)+'</td><td style="padding:8px;text-align:center;color:#8B97B0">'+(p.avg_ms||0)+'ms</td></tr>';}).join('')+
            '</tbody></table>';
          var recent=d.recent||[];
          document.getElementById('api-usage-recent').innerHTML=recent.slice(0,30).map(function(r){
            var ok=r.status==='success';
            return '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #1a1d2e;font-size:11px"><span>'+(ok?'✅':'❌')+'</span><span style="font-weight:600;color:#E8EDF5;min-width:70px">'+r.provider+'</span><span style="color:#6B7280;min-width:80px">'+(r.model||'—')+'</span><span style="color:#8B97B0;flex:1">'+(r.total_tokens||0)+' tok</span><span style="color:#F59E0B;min-width:60px">$'+parseFloat(r.cost_usd||0).toFixed(4)+'</span><span style="color:#6B7280;min-width:50px">'+(r.duration_ms||0)+'ms</span><span style="color:#4A566B">'+new Date(r.created_at).toLocaleTimeString()+'</span></div>';
          }).join('')||'<div style="color:#6B7280;text-align:center;padding:20px">No API calls logged yet.</div>';
        }catch(e){console.warn('API usage load failed:',e);}
      }
      // Add nav item for API Usage
      (function(){
        var nav=document.querySelector('.admin-nav');
        if(nav){
          var btn=document.createElement('button');
          btn.className='admin-nav-btn';
          btn.textContent='💰 API Usage';
          btn.onclick=function(){
            document.querySelectorAll('.admin-section').forEach(function(s){s.style.display='none';});
            document.getElementById('admin-api-usage').style.display='block';
            loadApiUsage();
          };
          nav.appendChild(btn);
        }
      })();
      </script>
      <div style="display:none">">&#9889; Queue</button>
      </div>
      <div id="admin-houseAccount" class="admin-section" style="display:none">
        <h3 style="font-family:var(--fh);font-size:18px;margin:0 0 16px">🏠 House Account Management</h3>
        <div id="house-accounts-list"></div>
        <script>
        (function(){
          var jwt=localStorage.getItem('bella_token')||'';
          var _hLoad=window._houseLoad=async function(){
            try{
              var r=await fetch('/api/admin/house-accounts',{headers:{'Authorization':'Bearer '+jwt}});
              var d=await r.json();
              var el=document.getElementById('house-accounts-list');
              if(!d.house_accounts||!d.house_accounts.length){
                el.innerHTML='<div style="text-align:center;padding:40px;color:var(--muted)">No house accounts found.</div>';
                return;
              }
              var h='';
              d.house_accounts.forEach(function(a){
                var pct=a.monthly_allowance>0?Math.round((a.balance/a.monthly_allowance)*100):0;
                var barColor=pct>50?'var(--p)':pct>20?'#F59E0B':'#EF4444';
                h+='<div style="background:var(--s1);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:16px">';
                h+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">';
                h+='<div><span style="font-size:20px;font-weight:700;color:var(--text)">'+a.name+'</span>';
                h+='<span style="margin-left:12px;font-size:12px;padding:2px 8px;background:rgba(108,92,231,.15);color:var(--p);border-radius:4px">'+a.plan+'</span>';
                h+=(a.auto_replenish?'<span style="margin-left:8px;font-size:11px;color:var(--green)">● Auto-replenish ON</span>':'')+'</div>';
                h+='<div style="font-size:12px;color:var(--muted)">Last replenish: '+(a.last_replenish?new Date(a.last_replenish).toLocaleDateString():'Never')+'</div></div>';

                // Credit bar
                h+='<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px">';
                h+='<div style="background:var(--s2);border-radius:8px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:700;color:var(--text)">'+a.balance+'</div><div style="font-size:11px;color:var(--muted)">Current Balance</div></div>';
                h+='<div style="background:var(--s2);border-radius:8px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:700;color:var(--text)">'+a.reserved+'</div><div style="font-size:11px;color:var(--muted)">Reserved</div></div>';
                h+='<div style="background:var(--s2);border-radius:8px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:700;color:var(--text)">'+a.monthly_allowance+'</div><div style="font-size:11px;color:var(--muted)">Monthly Allowance</div></div>';
                h+='</div>';

                // Progress bar
                h+='<div style="height:6px;background:rgba(255,255,255,.06);border-radius:3px;margin-bottom:16px;overflow:hidden"><div style="height:100%;width:'+Math.min(100,pct)+'%;background:'+barColor+';border-radius:3px;transition:width .3s"></div></div>';

                // Actions
                h+='<div style="display:flex;gap:12px;flex-wrap:wrap">';
                h+='<div style="display:flex;gap:8px;align-items:center"><input type="number" id="topup-'+a.id+'" value="100" min="1" style="width:80px;padding:6px 8px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"><button onclick="_houseTopUp('+a.id+')" style="padding:6px 16px;background:var(--p);color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer">+ Add Credits</button></div>';
                h+='<div style="display:flex;gap:8px;align-items:center"><input type="number" id="allowance-'+a.id+'" value="'+a.monthly_allowance+'" min="0" style="width:80px;padding:6px 8px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"><button onclick="_houseSetAllowance('+a.id+')" style="padding:6px 16px;background:var(--s2);border:1px solid var(--border);color:var(--text);border-radius:6px;font-size:13px;cursor:pointer">Set Allowance</button></div>';
                h+='<button onclick="_houseToggleReplenish('+a.id+','+(!a.auto_replenish)+')" style="padding:6px 16px;background:'+(a.auto_replenish?'rgba(239,68,68,.1)':'rgba(0,229,168,.1)')+';border:1px solid '+(a.auto_replenish?'rgba(239,68,68,.2)':'rgba(0,229,168,.2)')+';color:'+(a.auto_replenish?'#EF4444':'var(--green)')+';border-radius:6px;font-size:13px;cursor:pointer">'+(a.auto_replenish?'Disable Auto-Replenish':'Enable Auto-Replenish')+'</button>';
                h+='</div></div>';
              });
              el.innerHTML=h;
            }catch(e){document.getElementById('house-accounts-list').innerHTML='<div style="color:#EF4444">Error: '+e.message+'</div>';}
          };

          window._houseTopUp=async function(id){
            var amt=parseInt(document.getElementById('topup-'+id).value)||0;
            if(amt<=0){alert('Enter a positive amount');return;}
            try{
              var r=await fetch('/api/admin/house-accounts/'+id+'/top-up',{method:'POST',headers:{'Authorization':'Bearer '+jwt,'Content-Type':'application/json'},body:JSON.stringify({amount:amt})});
              var d=await r.json();
              if(d.success){alert('Credits added. New balance: '+d.new_balance);_hLoad();}else{alert('Error: '+(d.error||'Unknown'));}
            }catch(e){alert('Error: '+e.message);}
          };

          window._houseSetAllowance=async function(id){
            var amt=parseInt(document.getElementById('allowance-'+id).value)||0;
            try{
              var r=await fetch('/api/admin/house-accounts/'+id+'/settings',{method:'PUT',headers:{'Authorization':'Bearer '+jwt,'Content-Type':'application/json'},body:JSON.stringify({monthly_credit_allowance:amt})});
              var d=await r.json();
              if(d.success){alert('Allowance updated to '+amt);_hLoad();}
            }catch(e){alert('Error: '+e.message);}
          };

          window._houseToggleReplenish=async function(id,enable){
            try{
              var r=await fetch('/api/admin/house-accounts/'+id+'/settings',{method:'PUT',headers:{'Authorization':'Bearer '+jwt,'Content-Type':'application/json'},body:JSON.stringify({credits_auto_replenish:enable})});
              var d=await r.json();
              if(d.success){_hLoad();}
            }catch(e){alert('Error: '+e.message);}
          };

          // Auto-load when section becomes visible
          var obs=new MutationObserver(function(muts){
            muts.forEach(function(m){
              if(m.target.id==='admin-houseAccount'&&m.target.style.display!=='none'){_hLoad();}
            });
          });
          var sec=document.getElementById('admin-houseAccount');
          if(sec)obs.observe(sec,{attributes:true,attributeFilter:['style']});
        })();
        </script>
      </div>
      <div id="admin-media" class="admin-section" style="display:none">
        <h3 style="font-family:var(--fh);font-size:18px;margin:0 0 16px">📷 Media Library</h3>
        <div id="media-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px"></div>
        <div style="display:flex;gap:8px;margin-bottom:16px;align-items:center">
          <select id="media-filter-source" style="background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:6px 10px;font-size:12px">
            <option value="">All Sources</option>
            <option value="dalle">DALL-E</option>
            <option value="upload">Upload</option>
            <option value="import">Import</option>
          </select>
          <select id="media-filter-category" style="background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:6px 10px;font-size:12px">
            <option value="">All Categories</option>
            <option value="hero">Hero</option>
            <option value="gallery">Gallery</option>
            <option value="blog">Blog</option>
            <option value="website">Website</option>
          </select>
          <button onclick="_mediaLoad()" style="padding:6px 12px;background:var(--p);color:#fff;border:none;border-radius:6px;font-size:12px;cursor:pointer">Filter</button>
          <span id="media-count" style="font-size:12px;color:var(--muted);margin-left:auto"></span>
        </div>
        <div id="media-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px"></div>
        <div id="media-pagination" style="display:flex;justify-content:center;gap:8px;margin-top:16px"></div>
        <script>
        (function(){
          var jwt=localStorage.getItem('bella_token')||'';
          var offset=0, limit=50;

          window._mediaLoad=async function(){
            var src=document.getElementById('media-filter-source').value;
            var cat=document.getElementById('media-filter-category').value;
            var url='/api/admin/media?limit='+limit+'&offset='+offset;
            if(src)url+='&source='+src;
            if(cat)url+='&category='+cat;
            try{
              var r=await fetch(url,{headers:{'Authorization':'Bearer '+jwt}});
              var d=await r.json();
              document.getElementById('media-count').textContent=d.total+' items';
              var grid=document.getElementById('media-grid');
              if(!d.items||!d.items.length){
                grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted)">No media found.</div>';
                return;
              }
              grid.innerHTML=d.items.map(function(m){
                var imgUrl=m.url.startsWith('http')?m.url:(location.origin+m.url);
                var sizeKb=Math.round((m.size_bytes||0)/1024);
                var dims=m.width&&m.height?(m.width+'×'+m.height):'';
                var srcBadge='<span style="font-size:9px;padding:2px 6px;border-radius:4px;background:'+(m.source==='dalle'?'rgba(108,92,231,.15);color:#A78BFA':'rgba(0,229,168,.12);color:#00E5A8')+'">'+(m.source||'?')+'</span>';
                var catBadge=m.category?'<span style="font-size:9px;padding:2px 6px;border-radius:4px;background:rgba(59,130,246,.12);color:#3B82F6">'+m.category+'</span>':'';
                return'<div style="background:var(--s1);border:1px solid var(--border);border-radius:10px;overflow:hidden;transition:all .15s" onmouseover="this.style.borderColor=\'var(--p)\'" onmouseout="this.style.borderColor=\'var(--border)\'">'
                  +'<div style="height:120px;background:#0a0a0a;display:flex;align-items:center;justify-content:center;overflow:hidden"><img src="'+imgUrl+'" style="max-width:100%;max-height:100%;object-fit:cover" onerror="this.parentElement.innerHTML=\'<span style=color:var(--muted)>⚠️</span>\'"></div>'
                  +'<div style="padding:8px 10px">'
                  +'<div style="font-size:10px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px">'+m.filename+'</div>'
                  +'<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:4px">'+srcBadge+catBadge+'</div>'
                  +'<div style="font-size:9px;color:var(--muted)">'+sizeKb+'KB'+(dims?' · '+dims:'')+'</div>'
                  +'<div style="display:flex;gap:4px;margin-top:6px">'
                  +'<button onclick="navigator.clipboard.writeText(\''+imgUrl+'\');this.textContent=\'✓\'" style="font-size:10px;padding:2px 8px;background:var(--s2);border:1px solid var(--border);border-radius:4px;color:var(--muted);cursor:pointer">Copy URL</button>'
                  +'<button onclick="_mediaDelete('+m.id+')" style="font-size:10px;padding:2px 8px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);border-radius:4px;color:#F87171;cursor:pointer">✕</button>'
                  +'</div></div></div>';
              }).join('');
            }catch(e){document.getElementById('media-grid').innerHTML='<div style="color:#F87171">Error: '+e.message+'</div>';}

            // Stats
            try{
              var sr=await fetch('/api/admin/media/stats',{headers:{'Authorization':'Bearer '+jwt}});
              var sd=await sr.json();
              var mb=Math.round((sd.total_size||0)/1048576);
              document.getElementById('media-stats').innerHTML=
                '<div style="background:var(--s1);border:1px solid var(--border);border-radius:8px;padding:14px"><div style="font-size:24px;font-weight:700;color:var(--text)">'+sd.total+'</div><div style="font-size:11px;color:var(--muted)">Total Items</div></div>'
                +'<div style="background:var(--s1);border:1px solid var(--border);border-radius:8px;padding:14px"><div style="font-size:24px;font-weight:700;color:var(--text)">'+mb+'MB</div><div style="font-size:11px;color:var(--muted)">Storage Used</div></div>'
                +(sd.by_source||[]).map(function(s){return'<div style="background:var(--s1);border:1px solid var(--border);border-radius:8px;padding:14px"><div style="font-size:24px;font-weight:700;color:var(--text)">'+s.count+'</div><div style="font-size:11px;color:var(--muted)">'+s.source+'</div></div>';}).join('');
            }catch(e){}
          };

          window._mediaDelete=async function(id){
            if(!confirm('Delete this media item?'))return;
            try{
              await fetch('/api/admin/media/'+id,{method:'DELETE',headers:{'Authorization':'Bearer '+jwt}});
              _mediaLoad();
            }catch(e){}
          };

          var obs=new MutationObserver(function(m){m.forEach(function(mu){if(mu.target.id==='admin-media'&&mu.target.style.display!=='none')_mediaLoad();});});
          var sec=document.getElementById('admin-media');
          if(sec)obs.observe(sec,{attributes:true,attributeFilter:['style']});
        })();
        </script>
      </div>

      <!-- Image preview (shown when image attached) -->
      <div id="bella-image-preview" style="display:none;padding:4px 16px;background:var(--s2)">
        <div style="display:flex;align-items:center;gap:8px">
          <img id="bella-preview-thumb" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--border)" src="">
          <span id="bella-preview-name" style="font-size:11px;color:var(--muted);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
          <button onclick="bellaClearImage()" style="background:none;border:none;color:var(--rd);cursor:pointer;font-size:14px">&#215;</button>
        </div>
      </div>

      <div class="bella-input-area">
        <!-- Hidden file input for image upload -->
        <input type="file" id="bella-file-input" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="bellaFileSelected(this)">
        <button onclick="document.getElementById('bella-file-input').click()" title="Attach image" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:16px;padding:4px 6px;flex-shrink:0">&#128206;</button>
        <!-- Generate dropdown -->
        <div style="position:relative;flex-shrink:0">
          <button id="bella-gen-btn" onclick="bellaToggleGenMenu()" title="Generate" style="background:none;border:none;color:var(--am);cursor:pointer;font-size:14px;padding:4px 6px">&#9889;</button>
          <div id="bella-gen-menu" style="display:none;position:absolute;bottom:36px;left:0;background:var(--s2);border:1px solid var(--border);border-radius:8px;padding:4px;min-width:160px;z-index:10;box-shadow:0 4px 16px rgba(0,0,0,.4)">
            <button onclick="bellaGenerate('Image')" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px" onmouseover="this.style.background='var(--s3)'" onmouseout="this.style.background='none'">&#127912; Image</button>
            <button onclick="bellaGenerate('Document')" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px" onmouseover="this.style.background='var(--s3)'" onmouseout="this.style.background='none'">&#128196; Document</button>
            <button onclick="bellaGenerate('Presentation')" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px" onmouseover="this.style.background='var(--s3)'" onmouseout="this.style.background='none'">&#128202; Presentation</button>
            <button onclick="bellaGenerate('Video')" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px" onmouseover="this.style.background='var(--s3)'" onmouseout="this.style.background='none'">&#127909; Video</button>
          </div>
        </div>
        <textarea class="bella-input" id="bella-input" placeholder="Ask Bella anything..." rows="1" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();bellaSend();}"></textarea>
        <button class="bella-send" id="bella-send-btn" onclick="bellaSend()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        </button>
      </div>
    </div>
  </div>

  <script>
  // ── Bella Session 2: enhanced widget JS ──────────────────────────────────
  // Platform context injection, vision/image upload, generate menu, artifacts.
  // All existing bellaChat/bellaSend/bellaAddMessage functions are preserved
  // above (lines ~1607-1825). This block adds the new features only.

  // Platform context — fetched once when panel opens, injected into every message
  let _bellaContext = '';
  async function _bellaFetchContext() {
    try {
      const r = await fetch('/api/admin/stats', {
        headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
      });
      const s = await r.json();
      _bellaContext = `Current platform state: ${s.total_workspaces??s.workspaces??'?'} workspaces, `
        + `${s.total_users??s.users??'?'} users, ${s.tasks_today??0} tasks today, `
        + `MRR $${s.total_revenue??0}, active subs: ${s.active_subscriptions??0}.`;
    } catch(e) { _bellaContext = ''; }
  }

  // Patch toggleBella to fetch context on open
  const _origToggleBella = toggleBella;
  toggleBella = function() {
    _origToggleBella();
    if (bellaOpen && !_bellaContext) _bellaFetchContext();
    // Close gen menu + artifacts on toggle
    document.getElementById('bella-gen-menu').style.display = 'none';
  };

  // Patch bellaChat to inject platform context
  const _origBellaChat = bellaChat;
  bellaChat = async function(message) {
    // If there's an attached image, route through vision instead
    if (window._bellaImageBase64) {
      const img64 = window._bellaImageBase64;
      const imgName = window._bellaImageName || 'image';
      bellaClearImage();
      bellaAddMessage('user', '📎 ' + imgName + '\n' + message);
      window.bellaHistory.push({ role: 'user', content: message });
      bellaShowTyping();
      const input = document.getElementById('bella-input');
      const sendBtn = document.getElementById('bella-send-btn');
      input.disabled = true;
      sendBtn.disabled = true;
      try {
        const r = await fetch('/api/admin/bella/vision', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
          body: JSON.stringify({ prompt: message, image: img64 })
        });
        bellaHideTyping();
        const d = await r.json();
        const reply = d.analysis || d.error || 'No analysis returned.';
        bellaAddMessage('bella', reply);
        window.bellaHistory.push({ role: 'assistant', content: reply });
      } catch(e) {
        bellaHideTyping();
        bellaAddMessage('error', 'Vision error: ' + e.message);
      }
      input.disabled = false;
      sendBtn.disabled = false;
      input.focus();
      return;
    }

    // Inject platform context silently via context_request field (not visible in chat)
    return _origBellaChat(message);
  };

  // Image upload handling
  window._bellaImageBase64 = null;
  window._bellaImageName = null;

  function bellaFileSelected(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
      alert('Image must be under 10MB');
      input.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = function(e) {
      window._bellaImageBase64 = e.target.result.replace(/^data:image\/[a-z]+;base64,/i, '');
      window._bellaImageName = file.name;
      document.getElementById('bella-preview-thumb').src = e.target.result;
      document.getElementById('bella-preview-name').textContent = file.name + ' (' + (file.size/1024).toFixed(0) + 'KB)';
      document.getElementById('bella-image-preview').style.display = 'block';
    };
    reader.readAsDataURL(file);
    input.value = '';
  }

  function bellaClearImage() {
    window._bellaImageBase64 = null;
    window._bellaImageName = null;
    document.getElementById('bella-image-preview').style.display = 'none';
  }

  // Generate menu
  function bellaToggleGenMenu() {
    const m = document.getElementById('bella-gen-menu');
    m.style.display = m.style.display === 'none' ? 'block' : 'none';
  }
  function bellaGenerate(type) {
    document.getElementById('bella-gen-menu').style.display = 'none';
    const input = document.getElementById('bella-input');
    input.value = 'Generate a ' + type.toLowerCase() + ' about: ';
    input.focus();
    // Place cursor at end
    input.setSelectionRange(input.value.length, input.value.length);
  }

  // Artifacts panel
  function bellaToggleArtifacts() {
    const ap = document.getElementById('bella-artifacts-panel');
    const ca = document.getElementById('bella-chat-area');
    if (ap.style.display === 'none') {
      ap.style.display = 'flex';
      ap.style.flexDirection = 'column';
      ca.style.display = 'none';
      bellaLoadArtifacts();
    } else {
      ap.style.display = 'none';
      ca.style.display = 'flex';
    }
  }

  async function bellaLoadArtifacts() {
    const list = document.getElementById('bella-artifacts-list');
    list.innerHTML = '<span style="color:var(--muted)">Loading...</span>';
    try {
      const r = await fetch('/api/admin/bella/artifacts?per_page=30', {
        headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
      });
      const d = await r.json();
      const items = d.data || [];
      if (items.length === 0) {
        list.innerHTML = '<span style="color:var(--muted)">No artifacts yet.</span>';
        return;
      }
      list.innerHTML = items.map(a => {
        const url = a.url || '#';
        const name = a.title || a.prompt || 'Untitled';
        const type = a.type || '?';
        const date = a.created_at ? new Date(a.created_at).toLocaleDateString() : '';
        return '<div style="padding:8px 0;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px">'
          + '<span style="font-size:16px">' + (type==='image'?'&#127912;':type==='video'?'&#127909;':'&#128196;') + '</span>'
          + '<div style="flex:1;min-width:0"><div style="color:var(--text);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + name.replace(/</g,'&lt;').slice(0,60) + '</div>'
          + '<div style="font-size:10px;color:var(--muted)">' + type + ' &middot; ' + date + '</div></div>'
          + (url!=='#'?'<a href="'+url+'" target="_blank" style="color:var(--p);font-size:11px;flex-shrink:0">Open &#8599;</a>':'')
          + '</div>';
      }).join('');
    } catch(e) {
      list.innerHTML = '<span style="color:var(--rd)">Failed to load artifacts.</span>';
    }
  }

  // Close gen menu on outside click
  document.addEventListener('click', function(e) {
    const menu = document.getElementById('bella-gen-menu');
    const btn = document.getElementById('bella-gen-btn');
    if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
      menu.style.display = 'none';
    }
  });

  // Bella Session 6: video generation poll
  function bellaStartVideoPoll(assetId) {
    let attempts = 0;
    const maxAttempts = 24; // 2 minutes max (24 × 5s)
    const statusEl = document.getElementById('bella-video-status-' + assetId);
    const interval = setInterval(async () => {
      attempts++;
      if (attempts >= maxAttempts) {
        clearInterval(interval);
        if (statusEl) statusEl.textContent = 'Timed out — check Artifacts panel.';
        return;
      }
      try {
        const r = await fetch('/api/admin/bella/video-status/' + assetId, {
          headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        });
        const d = await r.json();
        if (d.status === 'completed' && d.url) {
          clearInterval(interval);
          if (statusEl) {
            statusEl.parentElement.parentElement.parentElement.innerHTML =
              '<video src="' + d.url + '" controls style="width:100%;border-radius:6px;max-height:240px" preload="metadata"></video>' +
              '<div style="display:flex;gap:8px;margin-top:8px">' +
              '<a href="' + d.url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; Download</a>' +
              '<span style="font-size:10px;color:var(--muted);align-self:center">Asset #' + assetId + '</span></div>';
          }
        } else if (d.status === 'failed' || d.status === 'timed_out') {
          clearInterval(interval);
          if (statusEl) statusEl.textContent = 'Generation failed (' + d.status + ')';
        } else if (statusEl) {
          statusEl.textContent = '\u23F3 Generating... (' + (attempts * 5) + 's)';
        }
      } catch(e) { /* ignore poll errors */ }
    }, 5000);
  }
  
    // ── Admin Email Templates helpers ─────────────────────
    function _admEmailFilter(which){
      ['all','sys','user'].forEach(function(k){
        var b = document.getElementById('ema-f-' + k);
        if (!b) return;
        if (k === which){ b.style.background = 'var(--p)'; b.style.color = '#fff'; b.style.borderColor = 'var(--p)'; }
        else { b.style.background = 'transparent'; b.style.color = 'var(--muted)'; b.style.borderColor = 'var(--border)'; }
      });
      _admEmailRender(which);
    }
    function _admEmailRender(which){
      var all = window._admEmailTpls || [];
      var rows = which === 'sys'  ? all.filter(function(t){ return t.is_system == 1; })
               : which === 'user' ? all.filter(function(t){ return t.is_system != 1; })
               : all;
      var grid = document.getElementById('ema-grid');
      if (!grid) return;
      if (!rows.length) { grid.innerHTML = '<div class="loading" style="padding:60px;text-align:center">No templates in this view.</div>'; return; }
      grid.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">' +
        rows.map(function(t){
          var sys = t.is_system == 1;
          var safeName = (t.name || '').replace(/'/g, '');
          var thumb = t.thumbnail_url
            ? '<img src="' + t.thumbnail_url + '" alt="" style="width:100%;height:100%;object-fit:cover;object-position:top" loading="lazy"/>'
            : '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:11px;text-transform:uppercase">' + (t.category||'preview') + '</div>';
          return '<div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--s2)">' +
                   '<div style="aspect-ratio:3/4;background:var(--s3);overflow:hidden;position:relative">' +
                     thumb +
                     (sys ? '<div style="position:absolute;top:8px;left:8px;background:rgba(108,92,231,.85);color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:.06em">SYSTEM</div>' : '') +
                   '</div>' +
                   '<div style="padding:10px 12px">' +
                     '<div style="font-weight:600;font-size:13px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + (t.name||'') + '">' + (t.name || 'Untitled') + '</div>' +
                     '<div style="font-size:10px;color:var(--muted);margin-bottom:10px;text-transform:capitalize">' + (t.category || 'general') + ' · #' + t.id + '</div>' +
                     '<div style="display:flex;gap:4px;flex-wrap:wrap">' +
                       '<button onclick="_admEmailEdit(' + t.id + ')" style="flex:1;background:transparent;border:1px solid var(--border);color:var(--text);padding:5px 8px;font-size:11px;border-radius:4px;cursor:pointer">Edit</button>' +
                       '<button onclick="_admEmailToggleSys(' + t.id + ',' + (sys ? 'false' : 'true') + ')" style="background:transparent;border:1px solid var(--border);color:var(--muted);padding:5px 8px;font-size:11px;border-radius:4px;cursor:pointer">' + (sys ? 'Unmark' : 'Mark sys') + '</button>' +
                       '<button onclick="_admEmailRegen(' + t.id + ')" title="Regenerate thumbnail" style="background:transparent;border:1px solid var(--border);color:var(--muted);padding:5px 8px;font-size:11px;border-radius:4px;cursor:pointer">↻</button>' +
                       '<button onclick="_admEmailDelete(' + t.id + ',\'' + safeName + '\')" style="background:transparent;border:1px solid var(--border);color:#EF4444;padding:5px 8px;font-size:11px;border-radius:4px;cursor:pointer">×</button>' +
                     '</div>' +
                   '</div>' +
                 '</div>';
        }).join('') +
        '</div>';
    }
    async function _admEmailEdit(id){
      var t = (window._admEmailTpls || []).find(function(x){ return x.id == id; });
      if (!t) return;
      var name     = prompt('Name', t.name || '');                                  if (name === null) return;
      var category = prompt('Category', t.category || 'general');                   if (category === null) return;
      var subject  = prompt('Subject', t.subject || '');                            if (subject === null) return;
      var brand    = prompt('Brand color (hex)', t.brand_color || '#5B5BD6');       if (brand === null) return;
      var r = await api('/email-templates/' + id, 'PUT', { name: name, category: category, subject: subject, brand_color: brand });
      if (r && r.success) { alert('Updated'); pages.emailTemplatesAdmin(); }
      else alert('Update failed: ' + (r && r.error || 'unknown'));
    }
    async function _admEmailToggleSys(id, makeSys){
      var r = await api('/email-templates/' + id, 'PUT', { is_system: makeSys ? 1 : 0 });
      if (r && r.success) pages.emailTemplatesAdmin();
      else alert('Toggle failed');
    }
    async function _admEmailRegen(id){
      var r = await api('/email-templates/' + id + '/regen-thumbnail', 'POST');
      if (r && r.thumbnail_url) { alert('Thumbnail regenerated'); pages.emailTemplatesAdmin(); }
      else alert('Regen failed: ' + (r && r.error || 'unknown'));
    }
    async function _admEmailDelete(id, name){
      if (!confirm('Hard-delete template "' + name + '" (id ' + id + ')? This removes the row, its blocks, and its thumbnail file.')) return;
      var r = await api('/email-templates/' + id, 'DELETE');
      if (r && r.deleted) pages.emailTemplatesAdmin();
      else alert('Delete failed: ' + (r && r.error || 'unknown'));
    }
    async function _admEmailNew(){
      var name     = prompt('Template name', 'New System Template'); if (!name) return;
      var category = prompt('Category', 'general'); if (!category) return;
      var source   = prompt('Source: blank or html', 'blank');       if (!source) return;
      var html     = '';
      if (source === 'html') {
        html = prompt('Paste raw HTML:', '');
        if (!html) return;
      }
      var r = await api('/email-templates', 'POST', { name: name, category: category, source: source, html_content: html });
      if (r && r.success) { alert('Created (id ' + r.template_id + ')'); pages.emailTemplatesAdmin(); }
      else alert('Create failed: ' + (r && r.error || 'unknown'));
    }

  </script>

</body>
</html>
