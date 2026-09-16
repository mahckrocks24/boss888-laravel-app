<?php
/** @var array $page */ /** @var array $data */
$pd = require dirname(__DIR__, 2) . '/product-data.php';
$page['title'] = 'Products';
$page['description'] = 'The engines behind the AI Growth OS: website builder, the AI workforce, SEO, content, social planning, CRM, calendar, chatbot, creative and video, plus domains, in one account you own.';
$specialists = count(array_filter($data['agents'], fn ($a) => empty($a['is_dmm'])));
$fill = fn (string $s) => str_replace(['{industries}', '{specialists}'], [(string) $data['template_count'], (string) $specialists], $s);
$products = $pd['launched'];
if (! empty($data['infrastructure_live'])) { $products = array_merge($products, $pd['infrastructure']); }
?>
<section class="section-tight page-head">
  <div class="container">
    <p class="eyebrow">Products</p>
    <h1>One account. Every engine Sarah runs your growth on.</h1>
    <p class="lede">Each one is a real engine in the platform, run by the workforce and approved by you. Automation and Aria are capabilities of the account rather than products you buy. Availability per plan is read from the plans table on every page.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container grid-3">
    <?php foreach ($products as $p): ?>
    <a class="card product" href="/next/product/<?= e($p['slug']) ?>/">
      <?= icon($p['icon'], 24) ?>
      <h3><?= e($p['name']) ?></h3>
      <p><?= e($fill($p['promise'])) ?></p>
    </a>
    <?php endforeach; ?>
  </div>
</section>
