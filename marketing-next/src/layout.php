<?php
/** @var array $page  title, description, route, og_image, jsonld[] */
/** @var string $content */
/** @var array $data */
$site = $data['site'];
$title = $page['title'] !== '' ? $page['title'] . ' · ' . $site['name'] : $site['name'];
$canonical = rtrim($site['url'], '/') . $page['route'];
$og = $page['og_image'] ?: $site['url'] . '/next/assets/og/home-sarah.jpg';
$navModel = (require __DIR__ . '/nav-data.php')($data);
$nav = $navModel['primary'];
$chrome = $page['chrome'] ?? '';   // '' | 'no-signup' (start: no Start-free CTA) | 'minimal' (login: no header links, one-line footer) - RISK-0181 port of the 2026-09-15 edits
$jsonld = array_merge([[
    '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $site['name'], 'url' => $site['url'],
    'logo' => $site['url'] . '/img/logo-icon-40.png', 'contactPoint' => ['@type' => 'ContactPoint', 'email' => $site['email'], 'contactType' => 'customer support'],
]], $page['jsonld']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<script>/* build id + session hint before first paint (2026-09-11/12) */document.documentElement.setAttribute('data-build','<?= substr(md5_file(__DIR__ . '/site.js'), 0, 8) ?>');try{if(localStorage.getItem('lu_token'))document.documentElement.setAttribute('data-lu-session','likely')}catch(e){}</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, interactive-widget=resizes-content"><!-- KB-1 (Owner 2026-09-22): the on-screen keyboard shrinks the page instead of covering the Arthur chat box -->
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($page['description']) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="<?= e($page['og_type'] ?? 'website') ?>">
<meta property="og:site_name" content="<?= e($site['name']) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($page['description']) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($og) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php if (!empty($page['noindex']) || !empty($data['preview'])): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
<link rel="icon" href="/img/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="/img/apple-touch-icon.png">
<link rel="alternate" type="application/rss+xml" title="LevelUpGrowth Blog" href="/next/blog/feed.xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="/next/site.css?v=<?= substr(md5_file(__DIR__ . '/site.css'), 0, 8) ?>">
<?php foreach ($jsonld as $block): ?>
<script type="application/ld+json"><?= json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endforeach; ?>
<?php if (! empty($page['head'])) { echo $page['head'], "\n"; } ?>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site-header">
  <div class="container nav-row">
    <a class="brand" href="/next/" aria-label="<?= e($site['name']) ?> home">
      <img src="/img/logo-icon-40.png" alt="" width="28" height="28"><span>LevelUpGrowth</span>
    </a>
    <nav class="nav-primary" aria-label="Primary">
      <div class="nav-dd-wrap">
        <button class="nav-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="mega-products">Products <span class="caret" aria-hidden="true"></span></button>
        <div class="mega" id="mega-products" aria-label="Products" hidden>
          <div class="mega-cols">
            <?php foreach ($navModel['groups'] as $g): ?>
            <div class="mega-col"><div class="mega-col-title"><?= e($g['title']) ?></div>
              <?php foreach ($g['items'] as [$ico, $name, $desc, $href]): ?>
              <a class="mega-item" href="<?= e($href) ?>"><span class="mega-ico"><?= icon($ico, 18) ?></span><span class="mega-body"><span class="mega-name"><?= e($name) ?></span><span class="mega-desc"><?= e($desc) ?></span></span></a>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="mega-foot">
            <?php if (! empty($navModel['journey'])): ?><div class="mega-journey"><?php foreach ($navModel['journey'] as [$step, $val]): ?><div class="mega-j"><span class="mega-j-step"><?= e($step) ?></span><span class="mega-j-val"><?= e($val) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            <a class="btn btn-secondary" href="<?= e($navModel['featured']['href']) ?>"><?= e($navModel['featured']['label']) ?> <?= icon('arrow-right', 16) ?></a>
          </div>
        </div>
      </div>
      <?php foreach ($nav as [$label, $href]): ?><a href="/next<?= e($href) ?>"<?= $page['route'] === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
      <div class="nav-dd-wrap">
        <button class="nav-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="dd-resources">Resources <span class="caret" aria-hidden="true"></span></button>
        <div class="nav-dd" id="dd-resources" aria-label="Resources" hidden>
          <?php foreach ($navModel['resources'] as [$ico, $name, $href]): ?><a class="nav-dd-item" href="<?= e($href) ?>"><?= icon($ico, 16) ?><?= e($name) ?></a><?php endforeach; ?>
        </div>
      </div>
    </nav>
    <div class="nav-actions">
<?php if ($chrome !== 'minimal'): ?>
      <a class="btn btn-ghost lu-login" href="/next/login/">Log in</a>
<?php endif; ?>
<?php if ($chrome === ''): ?>
      <a class="btn btn-primary" href="<?= e(signup_href($data)) ?>" data-lu-signup><span class="lbl-out"><?= e(cta_label($data)) ?></span><span class="lbl-in">Dashboard</span></a>
<?php endif; ?>
      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="mobile-nav" aria-label="Open menu"><?= icon('menu', 22) ?></button>
    </div>
  </div>
  <nav id="mobile-nav" class="nav-mobile" aria-label="Mobile" hidden>
    <details class="m-group"><summary>Products <span class="caret" aria-hidden="true"></span></summary>
      <?php foreach ($navModel['groups'] as $g): ?><div class="m-group-title"><?= e($g['title']) ?></div><?php foreach ($g['items'] as [$ico, $name, $desc, $href]): ?><a class="m-item" href="<?= e($href) ?>"><?= icon($ico, 16) ?><?= e($name) ?></a><?php endforeach; ?><?php endforeach; ?>
    </details>
    <?php foreach ($nav as [$label, $href]): ?><a class="m-link" href="/next<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
    <details class="m-group"><summary>Resources <span class="caret" aria-hidden="true"></span></summary>
      <?php foreach ($navModel['resources'] as [$ico, $name, $href]): ?><a class="m-item" href="<?= e($href) ?>"><?= icon($ico, 16) ?><?= e($name) ?></a><?php endforeach; ?>
    </details>
    <div class="m-actions"><?php if ($chrome !== 'minimal'): ?><a class="btn btn-secondary lu-login" href="/next/login/">Log in</a><?php endif; ?><?php if ($chrome === ''): ?><a class="btn btn-primary" href="<?= e(signup_href($data)) ?>" data-lu-signup><span class="lbl-out"><?= e(cta_label($data)) ?></span><span class="lbl-in">Dashboard</span></a><?php endif; ?></div>
  </nav>
</header>
<div class="nav-backdrop" id="nav-backdrop" hidden></div>

<main id="main">
<?= $content ?>
</main>

<?php if ($chrome === 'minimal'): ?>
<footer class="site-footer login-minimal" style="padding:28px 0"><div class="container"><p class="fine" style="text-align:center;margin:0;display:flex;flex-wrap:wrap;justify-content:center;gap:6px 10px"><span>&copy; <?= date('Y') ?> LevelUpGrowth</span><a href="/next/legal/privacy/" style="display:inline">Privacy</a><a href="/next/legal/terms/" style="display:inline">Terms</a><a href="/next/help/" style="display:inline">Help</a></p></div></footer>
<?php else: ?>
<footer class="site-footer">
  <div class="container footer-grid">
    <div class="footer-brand">
      <a class="brand" href="/next/"><img src="/img/logo-icon-40.png" alt="" width="24" height="24"><span>LevelUpGrowth</span></a>
      <p>The business you own, run by an AI workforce you approve.</p>
      <p class="footer-fine">Plans and limits on this site are read from the platform at build time. Build <?= e($data['plans_version']) ?>.</p>
    </div>
    <div>
      <h3 class="footer-h">Products</h3>
      <a href="/next/product/website-builder/">Website builder</a><a href="/next/product/ai-workforce/">AI workforce</a><a href="/next/product/seo/">SEO</a><a href="/next/product/content/">Content</a><a href="/next/product/social/">Social</a><a href="/next/product/crm/">CRM</a><a href="/next/product/calendar/">Calendar</a><a href="/next/product/chatbot/">Chatbot</a><a href="/next/product/creative/">Creative</a><a href="/next/product/video/">Video</a><a href="/next/product/automation/">Automation</a>
    </div>
    <div>
      <h3 class="footer-h">Company</h3>
      <a href="/next/about/">About</a><a href="/next/security/">Security</a><a href="/next/contact/">Contact</a><a href="/next/status/">Status</a><a href="/next/agencies/">Agencies</a>
    </div>
    <div>
      <h3 class="footer-h">Resources</h3>
      <a href="/next/blog/">Blog</a><a href="/next/help/">Help centre</a><a href="/next/changelog/">Changelog</a><a href="/next/compare/">Compare</a><a href="/next/pricing/">Pricing</a>
    </div>
    <div>
      <h3 class="footer-h">Legal</h3>
      <a href="/next/legal/terms/">Terms</a><a href="/next/legal/privacy/">Privacy</a><a href="/next/legal/cookies/">Cookies</a><a href="/next/legal/refunds/">Refunds</a><a href="/next/legal/ai-disclosure/">AI disclosure</a>
    </div>
  </div>
  <div class="container footer-bottom">
    <span>© <?= date('Y') ?> LevelUpGrowth. All rights reserved.</span>
    <span class="mono">hello@levelupgrowth.io</span>
  </div>
</footer>
<?php endif; ?>
<script src="/next/site.js?v=<?= substr(md5_file(__DIR__ . '/site.js'), 0, 8) ?>" defer></script>
<?php /* Chatbot888, run by the house workspace (Owner, 2026-09-12). Token is a public, domain-restricted widget key. */ ?>
<script src="/chatbot-widget.js?v=176b6ad1" data-token="cwt_019cba3b517c458b30eae508ba28fdf055d9b2c5317157d0" data-color="#6D4AFF" data-theme="dark" data-position="bottom-right" data-icon="/img/logo-icon-48.png" data-bubble="#0B0B14" data-gradient="linear-gradient(135deg,#6D4AFF,#2FE0C8)" data-panel="#10141F" data-backdrop="3" defer></script>
<?php if (! empty($page['scripts'])) { echo $page['scripts'], "\n"; } ?>
</body>
</html>
