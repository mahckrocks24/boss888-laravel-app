<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $notification->title }}</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; margin: 0; padding: 40px 20px; }
  .card { background: white; border-radius: 8px; max-width: 560px; margin: 0 auto; padding: 40px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
  .brand { font-size: 20px; font-weight: 800; color: #F59E0B; margin-bottom: 32px; }
  h2 { font-size: 22px; color: #111; margin: 0 0 12px; line-height: 1.3; }
  p { font-size: 15px; color: #444; line-height: 1.6; margin: 0 0 24px; }
  .btn { display: inline-block; background: #F59E0B; color: white !important;
         text-decoration: none; padding: 12px 24px; border-radius: 6px;
         font-weight: 600; font-size: 15px; }
  .footer { font-size: 12px; color: #999; margin-top: 32px; padding-top: 20px;
            border-top: 1px solid #eee; line-height: 1.6; }
</style>
</head>
<body>
<div class="card">
  <div class="brand">LevelUp Growth</div>
  <h2>{{ $notification->title }}</h2>
  @if($notification->body)
  <p>{{ $notification->body }}</p>
  @endif
  @if($notification->action_url)
  <p style="margin-bottom:32px">
    <a href="{{ rtrim(config('app.url'), '/') . $notification->action_url }}" class="btn">View in Dashboard</a>
  </p>
  @endif
  <div class="footer">
    You're receiving this because you have notifications enabled.<br>
    Manage your preferences in your dashboard settings.
  </div>
</div>
</body>
</html>
