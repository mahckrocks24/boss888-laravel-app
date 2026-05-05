<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>{{ !empty($resubscribed) ? 'Resubscribed' : 'Unsubscribed' }} · {{ $brand_name ?? 'LevelUp Growth' }}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body{margin:0;font-family:'Inter',Arial,sans-serif;background:#F2F4F8;color:#0F172A;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{max-width:480px;background:#fff;padding:44px 36px;border-radius:16px;box-shadow:0 10px 40px rgba(15,23,42,.08);text-align:center}
    .brand{display:inline-block;padding:6px 14px;border-radius:50px;background:#EEF2FF;color:#5B5BD6;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:24px}
    h1{margin:0 0 12px;font-size:26px;font-weight:700;letter-spacing:-.3px;color:#0F172A}
    p{margin:0 0 16px;color:#475569;font-size:15px;line-height:1.65}
    .email{font-weight:700;color:#0F172A;background:#F8FAFC;padding:10px 18px;border-radius:8px;display:inline-block;font-size:14px;margin:4px 0 20px}
    .btn{display:inline-block;padding:13px 28px;border-radius:8px;background:#5B5BD6;color:#fff;text-decoration:none;font-weight:600;font-size:14px;border:none;cursor:pointer;font-family:inherit}
    .btn:hover{background:#4747C2}
    .btn.secondary{background:transparent;color:#5B5BD6;border:1px solid #5B5BD6}
    .btn.secondary:hover{background:#EEF2FF}
    .muted{color:#94A3B8;font-size:12px;margin-top:28px;line-height:1.6}
    .icon{font-size:40px;margin-bottom:10px;line-height:1}
    form{display:inline}
    @media (prefers-color-scheme:dark){
      body{background:#0B1220;color:#F1F5F9}
      .card{background:#1F2937}
      h1{color:#F1F5F9}
      p{color:#CBD5E1}
      .email{background:#0B1220;color:#F1F5F9}
      .brand{background:#1A2234;color:#A78BFA}
      .muted{color:#64748B}
    }
  </style>
</head>
<body>
<div class="card">
  <span class="brand">{{ $brand_name ?? 'LevelUp Growth' }}</span>

  @if(!empty($resubscribed))
    <div class="icon">👋</div>
    <h1>You're back! We're glad.</h1>
    <p>You're subscribed again and will receive our emails.</p>
    <p class="muted">Change your mind? You can unsubscribe any time using the link at the bottom of any email we send you.</p>

  @elseif(!empty($ok))
    <div class="icon">✓</div>
    <h1>You've been unsubscribed</h1>
    @if(!empty($first_name))
      <p>Thanks, {{ $first_name }}. You won't receive further emails from {{ $brand_name ?? 'us' }}.</p>
    @else
      <p>You won't receive further emails from {{ $brand_name ?? 'us' }}.</p>
    @endif

    @if(!empty($email))
      <span class="email">{{ $email }}</span><br>
    @endif

    <form method="POST" action="{{ $resubscribe_url }}">
      @csrf
      <button type="submit" class="btn secondary">Resubscribe</button>
    </form>

    <p class="muted">Transactional emails (receipts, security, account updates) will still be delivered where required.</p>

  @else
    <div class="icon">✕</div>
    <h1>Link expired or invalid</h1>
    <p>This unsubscribe link is no longer valid. If you'd like to stop receiving emails, please reply to our most recent email and we'll update your preferences.</p>
  @endif
</div>
</body>
</html>
