<?php
/** @var array $page */ /** @var array $data */ /** @var array $category */ /** @var array $posts */
$page['title'] = $category['label'] . ' · Blog';
$page['description'] = 'Posts about ' . strtolower($category['label']) . ' from LevelUpGrowth, written by Sarah and checked before they are published.';
$date = fn ($d) => $d ? date('j F Y', strtotime((string) $d)) : '';
?>
<section class="section-tight page-head">
  <div class="container">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/next/blog/">Blog</a><span>/</span><span><?= e($category['label']) ?></span></nav>
    <h1><?= e($category['label']) ?></h1>
    <p class="lede"><?= count($posts) ?> post<?= count($posts) === 1 ? '' : 's' ?>.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container grid-3">
    <?php foreach ($posts as $p): ?>
    <a class="card post-card" href="/next/blog/<?= e($p['slug']) ?>/">
      <?php if (! empty($p['featured_image_url'])): ?><img class="post-card-img" src="<?= e($p['featured_image_url']) ?>" alt="<?= e($p['featured_image_alt'] ?: '') ?>" loading="lazy"><?php endif; ?>
      <span class="eyebrow"><?= e($p['category_label']) ?></span>
      <h3><?= e($p['title']) ?></h3>
      <p><?= e($p['excerpt']) ?></p>
      <span class="mono post-card-meta"><?= e($date($p['published_at'])) ?> · <?= (int) $p['read_time'] ?> min</span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
