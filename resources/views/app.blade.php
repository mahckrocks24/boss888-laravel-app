<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LevelUp Growth Platform</title>
  <meta name="description" content="AI marketing OS for SMBs — your dedicated AI marketing team.">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
  @php
    // MEDIUM-07 FIX: glob() finds the hashed bundle dynamically so rebuilds
    // need no manual edits. If the build is missing, $reactBundle = null and
    // we show a clear maintenance message instead of a silent infinite spinner.
    $reactAssets = glob(public_path('app-react/assets/*.js'));
    $reactBundle = !empty($reactAssets) ? '/app-react/assets/' . basename($reactAssets[0]) : null;
    $buildMissing = $reactBundle === null;
  @endphp
  @if($reactBundle)
    <script type="module" crossorigin src="{{ $reactBundle }}"></script>
  @endif
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --bg: #0F1117; --p: #6C5CE7; --ac: #00E5A8; --text: #E2E8F0; --muted: #94A3B8; }
    body { background: var(--bg); color: var(--text); font-family: 'DM Sans', system-ui, sans-serif; min-height: 100vh; }
    #root { min-height: 100vh; }
    .lu-preloader { display: flex; align-items: center; justify-content: center; min-height: 100vh; flex-direction: column; gap: 16px; }
    .lu-logo { font-family: 'Syne', system-ui, sans-serif; font-size: 28px; font-weight: 700; background: linear-gradient(135deg, var(--p), var(--ac)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .lu-spinner { width: 32px; height: 32px; border: 3px solid rgba(108,92,231,.2); border-top-color: var(--p); border-radius: 50%; animation: lu-spin .8s linear infinite; }
    @keyframes lu-spin { to { transform: rotate(360deg); } }
    /* MEDIUM-07: maintenance state */
    .lu-maintenance { text-align: center; padding: 24px; }
    .lu-maintenance h2 { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
    .lu-maintenance p { font-size: 14px; color: var(--muted); }
    .lu-maintenance code { font-size: 12px; background: rgba(255,255,255,.05); padding: 4px 10px; border-radius: 6px; display: inline-block; margin-top: 10px; }
  </style>
</head>
<body>
  <div id="root">
    @if($buildMissing)
      {{-- MEDIUM-07 FIX: clear message instead of silent spinner when build is absent --}}
      <div class="lu-preloader">
        <div class="lu-logo">LevelUp</div>
        <div class="lu-maintenance">
          @if(config('app.env') === 'local' || config('app.debug'))
            <h2>React build not found</h2>
            <p>Run the build command to generate the frontend assets.</p>
            <code>npm install && npm run build</code>
          @else
            <h2>Maintenance in progress</h2>
            <p>The platform will be back shortly. Thank you for your patience.</p>
          @endif
        </div>
      </div>
    @else
      <div class="lu-preloader">
        <div class="lu-logo">LevelUp</div>
        <div class="lu-spinner"></div>
      </div>
    @endif
  </div>
</body>
</html>
