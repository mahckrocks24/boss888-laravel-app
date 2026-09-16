<?php
/** @var array $page */ /** @var array $data */ /** @var array $c */ /** @var string $verified */
$page['title'] = 'LevelUpGrowth vs ' . $c['name'];
$page['description'] = $c['name'] . ' sells ' . lcfirst($c['sells']) . ' LevelUpGrowth adds the owned assets and the approval-first workforce. Prices verified ' . $verified . '.';
$plans = $data['plans'];
$growth = array_values(array_filter($plans, fn ($p) => $p['slug'] === 'growth'))[0] ?? null;
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Compare', 'item' => 'https://levelupgrowth.io/compare/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => $c['name'], 'item' => 'https://levelupgrowth.io/compare/' . $c['slug'] . '/'],
]];
?>
<section class="section-tight page-head">
  <div class="container">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/next/compare/">Compare</a><span>/</span><span><?= e($c['name']) ?></span></nav>
    <p class="eyebrow"><?= e($c['camp']) ?></p>
    <h1>LevelUpGrowth vs <?= e($c['name']) ?></h1>
    <p class="lede"><?= e($c['name']) ?> is a good <?= e(strtolower($c['camp'])) ?>. The question is what happens to the business after you buy it.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container grid-2">
    <div class="card">
      <h3><?= e($c['name']) ?></h3>
      <ul class="feature-list">
        <li><?= icon('check', 18) ?><span>Sells: <?= e($c['sells']) ?></span></li>
        <li><?= icon('check', 18) ?><span>Entry price: <?= e($c['entry']) ?></span></li>
        <li><?= icon('check', 18) ?><span>Mid and top: <?= e($c['top']) ?></span></li>
        <li class="no"><?= icon('check', 18) ?><span>Thin: <?= e($c['thin']) ?></span></li>
      </ul>
    </div>
    <div class="card">
      <h3>LevelUpGrowth</h3>
      <ul class="feature-list">
        <li><?= icon('check', 18) ?><span>Sells: the site, the CRM, the calendar, SEO, content, social planning, chatbot, creative and video in one account, run by <?= count($data['agents']) ?> named specialists you approve.</span></li>
        <li><?= icon('check', 18) ?><span>Entry price: <?= money($plans[0]['price_monthly'] ?? 0) ?>, no card, no time limit.</span></li>
        <li><?= icon('check', 18) ?><span>With the workforce: <?= $growth ? money($growth['price_monthly']) . '/mo, ' . number_format((int) $growth['credits_per_month']) . ' credits, ' . (int) $growth['max_websites'] . ' sites' : '' ?>.</span></li>
        <li><?= icon('check', 18) ?><span>Ownership: site export, domain and mailboxes registered to you, data export at any time.</span></li>
      </ul>
    </div>
  </div>
</section>
<section class="section-tight">
  <div class="container">
    <h2>Sources</h2>
    <p>Prices read <?= e($verified) ?> from the pages below; nothing here is measured by us. If you find one out of date, write to hello@levelupgrowth.io and we will correct it and print the correction date.</p>
    <ul>
      <?php foreach ($c['sources'] as $s): ?><li><a href="<?= e($s) ?>" rel="nofollow noopener" target="_blank"><?= e($s) ?></a></li><?php endforeach; ?>
    </ul>
    <p class="mt"><a class="btn btn-primary" href="<?= e(signup_href($data)) ?>">Start free <?= icon('arrow-right', 18) ?></a> <a class="btn btn-secondary" href="/next/pricing/">See pricing</a></p>
  </div>
</section>
