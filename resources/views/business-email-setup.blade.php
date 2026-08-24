{{--
  INFRA888 · E7.3 — the customer's mailbox setup page.

  LEVELUP GROWTH ONLY. No provider name, no provider hostname, no provider
  asset, and deliberately no third-party script of any kind: no analytics, no
  session replay, no font CDN. A page that receives a password loads nothing it
  does not control.
--}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  {{-- No referrer: the token must not travel to anything the customer clicks next. --}}
  <meta name="referrer" content="no-referrer">
  <title>Set up your Business Email — LevelUp Growth</title>
  <style>
    :root { --bg:#0B0F17; --card:#141A24; --bd:#232C3A; --t1:#E8EDF5; --t2:#9AA7BC; --p:#6366F1; --ok:#34D399; --bad:#F87171; }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--bg); color:var(--t1);
           font:400 15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
           display:flex; align-items:center; justify-content:center; min-height:100vh; padding:24px; }
    .card { background:var(--card); border:1px solid var(--bd); border-radius:12px;
            padding:32px; width:100%; max-width:440px; }
    .brand { font:600 15px/1 inherit; letter-spacing:-.01em; margin-bottom:28px; color:var(--t1); }
    .brand span { color:var(--p); }
    h1 { font-size:20px; font-weight:600; margin:0 0 8px; letter-spacing:-.01em; }
    p  { color:var(--t2); margin:0 0 20px; }
    .addr { display:inline-block; background:#1B2331; border:1px solid var(--bd); border-radius:6px;
            padding:4px 10px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; color:var(--t1); }
    label { display:block; font-size:13px; font-weight:600; margin:16px 0 6px; }
    input[type=password] { width:100%; padding:11px 12px; background:#0E141D; color:var(--t1);
            border:1px solid var(--bd); border-radius:8px; font-size:15px; }
    input:focus { outline:none; border-color:var(--p); }
    .hint { font-size:12px; color:var(--t2); margin-top:6px; }
    button { width:100%; margin-top:22px; padding:12px; border:none; border-radius:8px;
             background:var(--p); color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
    button:disabled { opacity:.55; cursor:not-allowed; }
    .msg { margin-top:18px; padding:12px 14px; border-radius:8px; font-size:14px; display:none; }
    .msg.ok  { display:block; background:#0F2A20; border:1px solid #1F5B44; color:var(--ok); }
    .msg.bad { display:block; background:#2A1416; border:1px solid #5B2226; color:var(--bad); }
    .foot { margin-top:24px; font-size:12px; color:var(--t2); }
  </style>
</head>
<body>
  <div class="card">
    <div class="brand">LevelUp<span>Growth</span></div>

    @if (! $ok)
      <h1>This link has expired</h1>
      <p>{{ $message }}</p>
      <div class="foot">Need help? Contact LevelUp Growth support.</div>
    @else
      <h1>Set up your mailbox</h1>
      <p>Choose a password for <span class="addr">{{ $address }}</span></p>

      <form id="f" autocomplete="off">
        <label for="pw">New password</label>
        <input id="pw" type="password" autocomplete="new-password" minlength="12" required>
        <div class="hint">At least 12 characters. Use something you don't use anywhere else.</div>

        <label for="pw2">Confirm password</label>
        <input id="pw2" type="password" autocomplete="new-password" minlength="12" required>

        <button id="go" type="submit">Set password</button>
      </form>

      <div id="m" class="msg"></div>

      <div class="foot">
        This link works once and expires 72 hours after it was sent.
      </div>
    @endif
  </div>

  @if ($ok)
  <script>
  (function () {
    var f = document.getElementById('f'), m = document.getElementById('m'), go = document.getElementById('go');

    function show(kind, text) { m.className = 'msg ' + kind; m.textContent = text; }

    f.addEventListener('submit', async function (e) {
      e.preventDefault();

      var pw = document.getElementById('pw').value, pw2 = document.getElementById('pw2').value;

      if (pw !== pw2)   { return show('bad', 'The two passwords do not match.'); }
      if (pw.length < 12) { return show('bad', 'Please use at least 12 characters.'); }

      go.disabled = true;
      show('ok', 'Setting up your mailbox…');

      try {
        var r = await fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ password: pw, password_confirmation: pw2 })
        });

        var d = await r.json();

        // The password is cleared from the DOM the moment it has been sent, so
        // it does not sit in a form field for the rest of the session.
        document.getElementById('pw').value = '';
        document.getElementById('pw2').value = '';
        pw = pw2 = null;

        if (d.success) { show('ok', d.message); f.style.display = 'none'; }
        else { show('bad', d.message || 'Something went wrong. Please try again.'); go.disabled = false; }
      } catch (err) {
        show('bad', 'We could not reach LevelUp Growth just now. Please try again.');
        go.disabled = false;
      }
    });
  })();
  </script>
  @endif
</body>
</html>
