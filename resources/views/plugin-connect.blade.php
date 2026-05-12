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
    button { width: 100%; padding: 14px; border-radius: 10px; border: none;
             background: linear-gradient(135deg, #7C3AED, #3B82F6); color: #fff;
             font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 24px; }
    button:hover { opacity: .9; }
    .errors { color: #F87171; font-size: 13px; margin-bottom: 12px; }
    .icon { font-size: 32px; margin-bottom: 16px; text-align: center; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">🔗</div>
    <h1>Authorize WP Plugin</h1>
    <p class="sub">Connect your WordPress site to your LevelUp Growth account.
       This grants the plugin permission to read your SEO data and run
       AI generations on your behalf.</p>

    @if ($errors->any())
      <div class="errors">{{ $errors->first() }}</div>
    @endif

    <div class="row"><span>Account</span><strong>{{ $user->email }}</strong></div>
    <div class="row"><span>Workspace</span><strong>{{ $workspace_name }}</strong></div>
    <div class="row"><span>Permission scope</span><strong>plugin</strong></div>
    <div class="row"><span>Token expires</span><strong>1 year</strong></div>

    <form method="POST" action="{{ route('plugin.connect.authorize') }}">
      @csrf
      <input type="hidden" name="redirect_uri" value="{{ $redirect_uri }}">
      <input type="hidden" name="site_url"
             value="{{ request()->query('site_url', '') }}">
      <button type="submit">Authorize plugin →</button>
    </form>
  </div>
</body>
</html>
