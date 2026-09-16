<?php
/** @var array $page */ /** @var array $data */ /** @var array $post (from dynamic.php) */
$page['title'] = $post['meta_title'] ?: $post['title'];
$page['description'] = $post['meta_description'] ?: ($post['excerpt'] ?: $post['title'] . ' — from the LevelUpGrowth blog.');
$page['og_image'] = $post['featured_image_url'] ?: '';
$page['og_type'] = 'article';
if (! empty($post['jsonld'])) { $page['jsonld'][] = $post['jsonld']; }
$hasCat = ! empty($post['category']);
$crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Blog', 'item' => 'https://levelupgrowth.io/blog/']];
if ($hasCat) { $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $post['category_label'], 'item' => 'https://levelupgrowth.io/blog/category/' . $post['category'] . '/']; }
$crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $post['title'], 'item' => $post['url']];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs];
$date = fn ($d) => $d ? date('j F Y', strtotime((string) $d)) : '';
// Table of contents from H2s in the body; ids added where missing.
$content = (string) ($post['content'] ?? '');
// The article body sometimes repeats its title as an <h1>; the page has exactly one H1.
$content = preg_replace('/^\s*<h1\b[^>]*>.*?<\/h1>/is', '', $content, 1) ?? $content;
$toc = [];
$content = preg_replace_callback('/<h2([^>]*)>(.*?)<\/h2>/is', function ($m) use (&$toc) {
    $text = trim(strip_tags($m[2]));
    $id = preg_match('/id="([^"]+)"/', $m[1], $im) ? $im[1] : 'h-' . substr(md5($text), 0, 8);
    $toc[] = [$id, $text];
    $attrs = preg_match('/id="/', $m[1]) ? $m[1] : $m[1] . ' id="' . e($id) . '"';
    return "<h2$attrs>{$m[2]}</h2>";
}, $content);
?>
<article class="post">
  <header class="post-head">
    <div class="container narrow">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/next/blog/">Blog</a><?php if ($hasCat): ?><span>/</span><a href="/next/blog/category/<?= e($post['category']) ?>/"><?= e($post['category_label']) ?></a><?php endif; ?></nav>
      <h1><?= e($post['title']) ?></h1>
      <p class="lede"><?= e($post['excerpt']) ?></p>
      <p class="byline">
        <span class="badge badge-dmm" aria-hidden="true">S</span>
        <span><strong><?= e($post['author']) ?></strong>, <?= e($post['author_role']) ?> · <a href="/next/legal/ai-disclosure/">How we write</a></span>
        <span class="mono"><?= e($date($post['published_at'])) ?> · <?= (int) $post['read_time'] ?> min read</span>
      </p>
    </div>
    <?php if (! empty($post['featured_image_url'])): ?>
    <div class="container narrow"><figure class="post-image"><img src="<?= e($post['featured_image_url']) ?>" alt="<?= e($post['featured_image_alt'] ?: $post['title']) ?>" loading="eager" decoding="async"></figure></div>
    <?php endif; ?>
  </header>
  <div class="container narrow post-grid">
    <?php if (count($toc) > 1): ?>
    <aside class="toc" aria-label="On this page"><p class="eyebrow">On this page</p><ol><?php foreach ($toc as [$id, $text]): ?><li><a href="#<?= e($id) ?>"><?= e($text) ?></a></li><?php endforeach; ?></ol></aside>
    <?php endif; ?>
    <div class="prose"><?= $content ?></div>
  </div>
  <footer class="container narrow post-foot">
    <?php if (! empty($post['tags'])): ?><p class="tags"><?php foreach ($post['tags'] as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></p><?php endif; ?>
    <?php if (! empty($post['updated_at']) && substr((string) $post['updated_at'], 0, 10) !== substr((string) $post['published_at'], 0, 10)): ?><p class="fine">Updated <?= e($date($post['updated_at'])) ?></p><?php endif; ?>
    <?php if (! empty($post['related'])): ?>
    <h2>Related</h2>
    <div class="grid-3">
      <?php foreach ($post['related'] as $r): ?>
      <a class="card post-card" href="/next/blog/<?= e($r['slug']) ?>/"><span class="eyebrow"><?= e($r['category_label']) ?></span><h3><?= e($r['title']) ?></h3><p><?= e($r['excerpt']) ?></p></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </footer>
</article>
