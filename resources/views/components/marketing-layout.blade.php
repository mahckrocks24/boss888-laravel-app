<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $title ?? 'LevelUp Growth Platform' }} — AI Marketing OS for SMBs</title>
  <meta name="description" content="{{ $description ?? 'Hire AI agents instead of a marketing agency. Sarah and your team research, strategize, and execute — you stay in control.' }}">
  <meta property="og:title" content="{{ $title ?? 'LevelUp Growth Platform' }}">
  <meta property="og:description" content="{{ $description ?? 'AI marketing OS for SMBs in MENA, DACH and SEA.' }}">
  <meta property="og:url" content="https://levelupgrowth.io">
  <meta name="theme-color" content="#6C5CE7">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0F1117; --s1: #171A21; --s2: #1E2230; --s3: #252A3A;
      --p: #6C5CE7; --ac: #00E5A8; --bl: #3B8BF5; --am: #F59E0B; --rd: #F87171; --pu: #A78BFA;
      --text: #E2E8F0; --muted: #94A3B8; --border: #2D3748;
    }
    html { scroll-behavior: smooth; }
    body { background: var(--bg); color: var(--text); font-family: 'DM Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; }
    a { color: inherit; text-decoration: none; }
    img { max-width: 100%; }

    /* Typography */
    h1, h2, h3, h4 { font-family: 'Syne', system-ui, sans-serif; line-height: 1.2; }

    /* Nav */
    nav { position: sticky; top: 0; z-index: 50; background: rgba(15,17,23,.92); backdrop-filter: blur(16px); border-bottom: 1px solid var(--border); }
    .nav-inner { max-width: 1200px; margin: 0 auto; padding: 0 24px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
    .nav-logo { font-family: 'Syne', system-ui, sans-serif; font-size: 22px; font-weight: 800; background: linear-gradient(135deg, var(--p), var(--ac)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .nav-links { display: flex; align-items: center; gap: 32px; }
    .nav-links a { font-size: 14px; color: var(--muted); transition: color .2s; }
    .nav-links a:hover { color: var(--text); }
    .nav-cta { background: var(--p); color: #fff !important; padding: 8px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; transition: opacity .2s; }
    .nav-cta:hover { opacity: .85; }

    /* Sections */
    .section { max-width: 1200px; margin: 0 auto; padding: 80px 24px; }
    .section-sm { max-width: 1200px; margin: 0 auto; padding: 48px 24px; }
    .section-center { text-align: center; }

    /* Buttons */
    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; border-radius: 10px; font-weight: 600; font-size: 15px; cursor: pointer; border: none; transition: all .2s; text-decoration: none; }
    .btn-primary { background: var(--p); color: #fff; }
    .btn-primary:hover { opacity: .85; transform: translateY(-1px); }
    .btn-outline { background: transparent; color: var(--text); border: 1px solid var(--border); }
    .btn-outline:hover { border-color: var(--p); color: var(--p); }
    .btn-lg { padding: 16px 36px; font-size: 17px; border-radius: 12px; }

    /* Badge */
    .badge { display: inline-block; padding: 4px 12px; border-radius: 100px; font-size: 12px; font-weight: 600; letter-spacing: .5px; }
    .badge-p { background: rgba(108,92,231,.15); color: var(--pu); border: 1px solid rgba(108,92,231,.25); }
    .badge-ac { background: rgba(0,229,168,.1); color: var(--ac); border: 1px solid rgba(0,229,168,.2); }

    /* Cards */
    .card { background: var(--s1); border: 1px solid var(--border); border-radius: 16px; padding: 28px; }
    .card:hover { border-color: rgba(108,92,231,.4); }

    /* Grid */
    .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
    .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }
    .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }

    /* Footer */
    footer { border-top: 1px solid var(--border); background: var(--s1); }
    .footer-inner { max-width: 1200px; margin: 0 auto; padding: 48px 24px 32px; }
    .footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 48px; margin-bottom: 40px; }
    .footer-logo { font-family: 'Syne', system-ui, sans-serif; font-size: 20px; font-weight: 800; background: linear-gradient(135deg, var(--p), var(--ac)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 12px; }
    .footer-desc { font-size: 14px; color: var(--muted); line-height: 1.7; }
    .footer-col h4 { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 16px; color: var(--muted); }
    .footer-col a { display: block; font-size: 14px; color: var(--muted); padding: 4px 0; transition: color .2s; }
    .footer-col a:hover { color: var(--text); }
    .footer-bottom { border-top: 1px solid var(--border); padding-top: 24px; display: flex; justify-content: space-between; font-size: 13px; color: var(--muted); }

    /* Gradient text */
    .gradient-text { background: linear-gradient(135deg, var(--p), var(--ac)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .gradient-text-warm { background: linear-gradient(135deg, var(--am), var(--p)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

    /* Responsive */
    @media (max-width: 768px) {
      .grid-3, .grid-4 { grid-template-columns: 1fr; }
      .grid-2 { grid-template-columns: 1fr; }
      .nav-links { display: none; }
      .footer-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 480px) {
      .footer-grid { grid-template-columns: 1fr; }
    }
  </style>
  @stack('styles')
</head>
<body>

<nav>
  <div class="nav-inner">
    <a href="/" class="nav-logo">LevelUp</a>
    <div class="nav-links">
      <a href="/features">Features</a>
      <a href="/specialists">AI Team</a>
      <a href="/pricing">Pricing</a>
      <a href="/faq">FAQ</a>
      <a href="/app" style="color:var(--muted)">Sign in</a>
      <a href="/sign-up" class="nav-cta">Start free trial</a>
    </div>
  </div>
</nav>

{{ $slot }}

<footer>
  <div class="footer-inner">
    <div class="footer-grid">
      <div>
        <div class="footer-logo">LevelUp</div>
        <p class="footer-desc">AI marketing OS for SMBs in MENA, DACH, and SEA. Your dedicated AI marketing team, always on, always executing — with your approval.</p>
      </div>
      <div class="footer-col">
        <h4>Product</h4>
        <a href="/features">Features</a>
        <a href="/specialists">AI Specialists</a>
        <a href="/pricing">Pricing</a>
        <a href="/faq">FAQ</a>
      </div>
      <div class="footer-col">
        <h4>Engines</h4>
        <a href="/features#seo">SEO</a>
        <a href="/features#content">Content</a>
        <a href="/features#social">Social</a>
        <a href="/features#crm">CRM</a>
        <a href="/features#creative">Creative</a>
      </div>
      <div class="footer-col">
        <h4>Company</h4>
        <a href="/sign-up">Get started</a>
        <a href="mailto:hello@levelupgrowth.io">Contact</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© {{ date('Y') }} LevelUp Growth Platform. All rights reserved.</span>
      <span>Dubai · Frankfurt · Manila</span>
    </div>
  </div>
</footer>

@stack('scripts')
</body>
</html>
