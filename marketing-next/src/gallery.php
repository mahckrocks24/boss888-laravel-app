<?php
/**
 * Real sites, opened by a real visitor. Each card links to the published site; the brief that produced it is printed
 * underneath, because the brief is the product. @var array $sc showcase-data.php
 */
?>
<div class="gal">
  <?php foreach ($sc['sites'] as $i => $s): ?>
  <a class="gal-card<?= $i === 0 ? ' gal-lead' : '' ?>" href="https://<?= e($s['slug']) ?>.levelupgrowth.io/" target="_blank" rel="noopener">
    <span class="gal-shot">
      <picture>
        <source media="(max-width:760px)" srcset="/next/assets/show/m-<?= e($s['slug']) ?>.webp">
        <img src="/next/assets/show/<?= e($s['slug']) ?>.webp" alt="<?= e($s['name']) ?>, a <?= e(strtolower($s['what'])) ?> in <?= e($s['where']) ?>, built by Arthur." width="1440" height="900" <?= $i === 0 ? 'fetchpriority="high"' : '' ?> decoding="async">
      </picture>
      <span class="gal-open"><?= icon('arrow-right', 15) ?> Open it</span>
    </span>
    <span class="gal-meta">
      <b><?= e($s['name']) ?></b>
      <span class="gal-what"><?= e($s['what']) ?> · <?= e($s['where']) ?></span>
      <span class="gal-secs"><?= (int) $s['seconds'] ?>s</span>
    </span>
    <span class="gal-brief">“<?= e($s['brief']) ?>”</span>
  </a>
  <?php endforeach; ?>
</div>
