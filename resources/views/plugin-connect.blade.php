<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Connect LevelUp Growth SEO Plugin</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
           background: #0F1117; color: #E8EDF5; margin: 0;
           min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .card { background: #171A21; border: 1px solid #2a2d3e; border-radius: 16px;
            padding: 40px; max-width: 460px; width: 90%;
            box-shadow: 0 8px 32px rgba(0,0,0,.3); }
    h1 { margin: 0 0 8px; font-size: 22px; font-weight: 700; }
    .sub { color: #8B97B0; font-size: 14px; margin-bottom: 28px; }
    .row { display: flex; justify-content: space-between; padding: 12px 0;
           border-bottom: 1px solid #2a2d3e; font-size: 14px; }
    .row:last-of-type { border-bottom: none; }
    .row strong { color: #fff; }
    button, a.btn { display: block; width: 100%; padding: 14px;
             border-radius: 10px; border: none; box-sizing: border-box;
             background: linear-gradient(135deg, #7C3AED, #3B82F6); color: #fff;
             font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 24px;
             text-decoration: none; text-align: center; }
    button:hover, a.btn:hover { opacity: .9; }
    button:disabled { opacity: .5; cursor: not-allowed; }
    .err { color: #F87171; font-size: 13px; margin-top: 12px; }
    .icon { font-size: 32px; margin-bottom: 16px; text-align: center; }
    .hidden { display: none; }
    .muted { color: #8B97B0; font-size: 12px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">🔗</div>
    <h1>Authorize WP Plugin</h1>
    <p class="sub">Connect your WordPress site to your LevelUp Growth account.
       This grants the plugin permission to read your SEO data and run
       AI generations on your behalf.</p>

    <div id="not-logged-in" class="hidden">
      <p class="err">You're not logged into LevelUp Growth in this browser.</p>
      <a href="/app/?return_to={{ urlencode(request()->fullUrl()) }}" class="btn">
        Log in →
      </a>
    </div>

    <div id="logged-in" class="hidden">
      <div class="row"><span>Account</span><strong id="r-email">…</strong></div>
      <div class="row"><span>Workspace</span><strong id="r-workspace">…</strong></div>
      <div class="row"><span>Permission scope</span><strong>plugin</strong></div>
      <div class="row"><span>Token expires</span><strong>1 year</strong></div>
      <button id="authorize-btn" type="button">Authorize plugin →</button>
      <p class="err hidden" id="auth-err"></p>
    </div>

    <div id="busy" class="">
      <p class="muted">Checking your session…</p>
    </div>
  </div>

<script>
(function () {
  var redirectUri = @json($redirect_uri ?? '');
  var siteUrl     = @json($site_url ?? '');
  var token       = '';
  try { token = localStorage.getItem('lu_token') || localStorage.getItem('token') || ''; } catch (e) {}

  var $busy    = document.getElementById('busy');
  var $logged  = document.getElementById('logged-in');
  var $nolog   = document.getElementById('not-logged-in');
  var $email   = document.getElementById('r-email');
  var $ws      = document.getElementById('r-workspace');
  var $btn     = document.getElementById('authorize-btn');
  var $err     = document.getElementById('auth-err');

  if (!token) {
    $busy.classList.add('hidden');
    $nolog.classList.remove('hidden');
    return;
  }

  // Probe /api/auth/me to confirm token is valid + fetch profile.
  fetch('/api/auth/me', {
    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
  })
  .then(function (r) {
    if (!r.ok) { throw new Error('not_authed'); }
    return r.json();
  })
  .then(function (j) {
    var u = j.user || j.data || j;
    $email.textContent = u.email || '(unknown)';
    $ws.textContent    = (u.workspace_name || u.current_workspace_name || u.workspace || '(default)');
    $busy.classList.add('hidden');
    $logged.classList.remove('hidden');
  })
  .catch(function () {
    $busy.classList.add('hidden');
    $nolog.classList.remove('hidden');
  });

  $btn.addEventListener('click', function () {
    $btn.disabled = true; $btn.textContent = 'Authorizing…';
    $err.classList.add('hidden');
    fetch('/api/plugin/connect', {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type':  'application/json',
        'Accept':        'application/json'
      },
      body: JSON.stringify({ site_url: siteUrl })
    })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
    .then(function (res) {
      if (!res.ok || !res.j.success) {
        throw new Error(res.j.error || res.j.message || 'connect_failed');
      }
      var pluginToken = res.j.plugin_token;
      if (redirectUri) {
        var sep = redirectUri.indexOf('?') >= 0 ? '&' : '?';
        window.location.href = redirectUri + sep
          + 'lgsc_connected=1&lgsc_token=' + encodeURIComponent(pluginToken);
      } else {
        document.body.innerHTML = '<div class="card"><h1>✅ Connected</h1>'
          + '<p>Plugin token: <code>' + pluginToken + '</code></p>'
          + '<p>Copy this token into your WP plugin settings.</p></div>';
      }
    })
    .catch(function (e) {
      $btn.disabled = false; $btn.textContent = 'Authorize plugin →';
      $err.textContent = 'Authorization failed: ' + (e.message || 'unknown');
      $err.classList.remove('hidden');
    });
  });
})();
</script>
</body>
</html>
