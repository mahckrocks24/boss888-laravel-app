<?php
    $payload = [
        'type'           => $success ? 'social_connected' : 'social_error',
        'platform'       => $platform ?? 'facebook',
        'account_name'   => $account_name ?? null,
        'accounts_count' => (int) ($accounts_count ?? 0),
        'message'        => $error_message ?? null,
    ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $success ? 'Connected' : 'Connection failed' }}</title>
<style>
 body{margin:0;background:#0F1117;color:#E8EDF5;font-family:'DM Sans',system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;text-align:center}
 .card{background:#171A21;border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:32px 28px;max-width:400px;width:100%}
 .icon{font-size:42px;margin-bottom:12px}
 .icon.ok{color:#00E5A8}
 .icon.err{color:#F87171}
 h1{font-family:'Syne',sans-serif;font-size:18px;margin:0 0 8px;color:#E8EDF5;letter-spacing:-0.01em}
 p{color:#8B97B0;font-size:13px;line-height:1.5;margin:0}
 .hint{color:#4A566B;font-size:11px;margin-top:16px}
 .err-detail{color:#F87171;font-size:12px;margin-top:10px;padding:8px 10px;background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);border-radius:6px;word-break:break-word}
</style>
</head>
<body>
<div class="card">
@if($success)
  <div class="icon ok">✓</div>
  <h1>Connected to {{ $account_name ?? 'Facebook' }}</h1>
  <p>{{ (int) ($accounts_count ?? 0) }} account{{ ((int)($accounts_count ?? 0)) === 1 ? '' : 's' }} linked. This window will close automatically.</p>
@else
  <div class="icon err">✕</div>
  <h1>Connection failed</h1>
  <p>We could not complete the connection to {{ ucfirst($platform ?? 'Facebook') }}.</p>
  @if(!empty($error_message))
    <div class="err-detail">{{ $error_message }}</div>
  @endif
@endif
<p class="hint">If this window does not close, you can close it manually.</p>
</div>
<script>
(function(){
  try {
    var payload = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage(payload, '*');
    }
  } catch (e) { /* noop */ }
  setTimeout(function(){ try { window.close(); } catch(e) {} }, 1600);
})();
</script>
</body>
</html>
