<!doctype html>
<html lang="en" class="desk-html">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#0F1117">
<title>{{ $desk['site_name'] }} — Publisher Desk</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='{{ urlencode($desk['primary']) }}'/%3E%3Ctext x='16' y='22' text-anchor='middle' font-family='Georgia,serif' font-weight='700' font-size='18' fill='%23fff'%3ED%3C/text%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,600;12..96,700&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="/desk/desk.css?v={{ $v }}">
<script nonce="{{ $nonce }}">
window.DESK = @json($desk);
document.documentElement.style.setProperty('--site', window.DESK.primary || '#0038A8');
document.documentElement.style.setProperty('--site-accent', window.DESK.accent || '#FCD116');
try { var t = localStorage.getItem('desk_theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t); } catch (e) {}
</script>
</head>
<body class="desk-body">
<a class="desk-skip" href="#desk-main">Skip to content</a>
<div id="desk" class="desk" data-state="boot">
  <noscript><p style="padding:24px;font-family:system-ui">The Publisher Desk needs JavaScript.</p></noscript>
</div>
<script src="/desk/desk-ui.js?v={{ $v }}" nonce="{{ $nonce }}"></script>
<script src="/desk/desk.js?v={{ $v }}" nonce="{{ $nonce }}"></script>
</body>
</html>
