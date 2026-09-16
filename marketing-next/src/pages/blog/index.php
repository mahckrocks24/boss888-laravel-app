<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Blog';
$page['description'] = 'Playbooks, product notes and industry guides from LevelUpGrowth, written by Sarah, our AI Digital Marketing Manager, and checked before they are published.';
$posts = $data['blog']['posts'] ?? [];
$categories = $data['blog']['categories'] ?? [];
$date = fn ($d) => $d ? date('j F Y', strtotime((string) $d)) : '';
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => 'LevelUpGrowth Blog', 'url' => 'https://levelupgrowth.io/blog/', 'publisher' => ['@type' => 'Organization', 'name' => 'LevelUpGrowth']];
$lead = $posts[0] ?? null;
?>
<section class="section-tight page-head">
  <div class="container">
    <p class="eyebrow">Blog</p>
    <h1>What we learn running businesses with an AI workforce.</h1>
    <p class="lede">Written by Sarah, our Digital Marketing Manager, on the same rails our customers use: briefed from real keywords, checked by the QA gate, published on schedule. <a href="/next/legal/ai-disclosure/">How we write</a>.</p>
    <?php if ($categories): ?>
    <nav class="chips" aria-label="Categories">
      <?php foreach ($categories as $c): ?><a class="chip" href="<?= $c['slug'] === 'all' ? '/next/blog/' : '/next/blog/category/' . e($c['slug']) . '/' ?>"><?= e($c['label']) ?> <span class="mono"><?= (int) $c['count'] ?></span></a><?php endforeach; ?>
    </nav>
    <?php endif; ?>
  </div>
</section>
<?php if ($lead): ?>
<section class="section-tight">
  <div class="container">
    <a class="card post-lead" href="/next/blog/<?= e($lead['slug']) ?>/">
      <?php if (! empty($lead['featured_image_url'])): ?><img class="post-lead-img" src="<?= e($lead['featured_image_url']) ?>" alt="<?= e($lead['featured_image_alt'] ?: '') ?>" loading="eager"><?php endif; ?>
      <div>
        <span class="eyebrow"><?= e($lead['category_label']) ?> · Latest</span>
        <h2><?= e($lead['title']) ?></h2>
        <p><?= e($lead['excerpt']) ?></p>
        <span class="mono post-card-meta"><?= e($date($lead['published_at'])) ?> · <?= (int) $lead['read_time'] ?> min read</span>
      </div>
    </a>
  </div>
</section>
<?php endif; ?>
<section class="section-tight">
  <div class="container grid-3">
    <?php foreach (array_slice($posts, 1) as $p): ?>
    <a class="card post-card" href="/next/blog/<?= e($p['slug']) ?>/">
      <?php if (! empty($p['featured_image_url'])): ?><img class="post-card-img" src="<?= e($p['featured_image_url']) ?>" alt="<?= e($p['featured_image_alt'] ?: '') ?>" loading="lazy"><?php endif; ?>
      <span class="eyebrow"><?= e($p['category_label']) ?></span>
      <h3><?= e($p['title']) ?></h3>
      <p><?= e($p['excerpt']) ?></p>
      <span class="mono post-card-meta"><?= e($date($p['published_at'])) ?> · <?= (int) $p['read_time'] ?> min</span>
    </a>
    <?php endforeach; ?>
    <?php if (! $posts): ?><p>No posts yet.</p><?php endif; ?>
  </div>
  <div class="container mt">
    <div class="card narrow">
      <p class="eyebrow">How this blog is written</p>
      <p>Every post starts as a brief from a keyword we track, is written by Sarah's content team, carries its title tag, description, image alt text, tags and schema, and passes the same review gate our customers use before it is published on schedule. Posts are marked with Sarah's byline and link to our <a href="/next/legal/ai-disclosure/">AI disclosure</a>. Subscribe by <a href="/next/blog/feed.xml">RSS</a>.</p>
    </div>
  </div>
</section>
