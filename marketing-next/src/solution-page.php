<?php
/** @var array $page */ /** @var array $data */ /** @var array $s */
$page['title'] = $s['name'];
$page['description'] = $s['promise'] . ' ' . mb_substr($s['lede'], 0, 120);
$pd = require __DIR__ . '/product-data.php';
$productIndex = []; foreach ($pd['launched'] as $p) { $productIndex[$p['slug']] = $p; }
$templates = []; foreach ($data['templates'] ?? [] as $t) { $templates[$t['slug']] = $t; }
$tpl = $templates[$s['template']] ?? null;
$agents = array_values(array_filter($data['agents'], fn ($a) => in_array($a['category'], $s['agents'], true) && empty($a['is_dmm'])));
$growth = array_values(array_filter($data['plans'], fn ($p) => $p['slug'] === 'growth'))[0] ?? null;
$initials = fn (string $n) => mb_strtoupper(mb_substr($n, 0, 1));
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Solutions', 'item' => 'https://levelupgrowth.io/solutions/'], ['@type' => 'ListItem', 'position' => 2, 'name' => $s['name'], 'item' => 'https://levelupgrowth.io/solutions/' . $s['slug'] . '/']]];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $s['faq'])];
?>
<section class="hero">
  <div class="container hero-grid">
    <div>
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/next/solutions/">Solutions</a><span>/</span><span><?= e($s['name']) ?></span></nav>
      <p class="eyebrow"><?= icon($s['icon'], 16) ?> <?= e($s['name']) ?></p>
      <h1><?= e($s['promise']) ?></h1>
      <p class="lede"><?= e($s['lede']) ?></p>
      <div class="hero-actions">
        <a class="btn btn-primary btn-lg" href="<?= e(signup_href($data)) ?>">Start free <?= icon('arrow-right', 18) ?></a>
        
      </div>
    </div>
    <div><?= shot('01-dashboard', 'Sarah\'s home screen in a demo workspace: the same manager, plan and approval flow every industry gets.', 'app.levelupgrowth.io/app', true) ?></div>
  </div>
</section>

<section class="section">
  <div class="container">
    <p class="eyebrow">The first thirty days</p>
    <h2>What happens, week by week.</h2>
    <div class="grid-4 weeks">
      <?php foreach ($s['weeks'] as $i => [$h, $t]): ?>
      <div class="week"><span class="step-n"><?= $i + 1 ?></span><h3><?= e($h) ?></h3><p><?= e($t) ?></p></div>
      <?php endforeach; ?>
    </div>
    <p class="fine mt">Every step above runs on an engine that exists today and waits for your approval before anything goes live.</p>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <p class="eyebrow">The specialists who do the work</p>
    <h2><?= count($agents) ?> specialists, led by Sarah.</h2>
    <div class="team-grid">
      <?php foreach ($agents as $a): ?>
      <div class="agent"><span class="badge badge-<?= e($a['category']) ?>" aria-hidden="true"><?= e($initials($a['name'])) ?></span><div><strong><?= e($a['name']) ?></strong><span class="agent-title"><?= e($a['title']) ?></span></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <p class="eyebrow">Products used</p>
    <h2>Everything in one account.</h2>
    <div class="grid-3">
      <?php foreach ($s['products'] as $slug): if (! isset($productIndex[$slug])) { continue; } $p = $productIndex[$slug]; ?>
      <a class="card product" href="/next/product/<?= e($slug) ?>/"><?= icon($p['icon'], 22) ?><h3><?= e($p['name']) ?></h3><p><?= e(str_replace(['{industries}', '{specialists}'], [(string) $data['template_count'], (string) count(array_filter($data['agents'], fn ($x) => empty($x['is_dmm'])))], $p['promise'])) ?></p></a>
      <?php endforeach; ?>
    </div>
    <?php if ($growth): ?><p class="mt">Most owners in this industry start on <strong><?= e($growth['name']) ?></strong>, <?= money($growth['price_monthly']) ?>/month with <?= number_format((int) $growth['credits_per_month']) ?> credits and <?= (int) $growth['max_websites'] ?> sites. <a href="/next/pricing/">Compare plans</a>.</p><?php endif; ?>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <h2>Questions</h2>
    <div class="faq"><?php foreach ($s['faq'] as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
  </div>
</section>
