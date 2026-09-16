<?php
/**
 * The SEO product page's evidence: a real report, not a picture of one.
 *
 * A visitor's real question is "what does it actually tell me?" — so we answer it with the engine's own output on a
 * site it built. @var array $data
 */
$sr = require dirname(__DIR__) . '/seo-data.php';
$statusWord = ['pass' => 'Pass', 'warning' => 'Fix this', 'error' => 'Problem'];
$byCat = [];
foreach ($sr['checks'] as $c) { $byCat[$c[0]][] = $c; }
$catLabel = [];
foreach ($sr['categories'] as [$label, $key, $score]) { $catLabel[$key] = [$label, $score]; }
$dash = (int) round(2 * M_PI * 52 * $sr['score'] / 100);
?>
<section class="section" id="report">
  <div class="container">
    <p class="eyebrow">What you actually get</p>
    <h2>This is a real report, on a real site.</h2>
    <p class="lede">Run on <?= e($sr['site_name']) ?> — <?= e($sr['what']) ?> — on <?= e(date('j F Y', strtotime($sr['captured']))) ?>. Every line below is the engine's own wording. <a href="https://<?= e($sr['site']) ?>/" target="_blank" rel="noopener">Open the site it graded <?= icon('arrow-right', 13) ?></a></p>

    <div class="rep">
      <div class="rep-head">
        <div class="rep-score">
          <svg viewBox="0 0 120 120" class="rep-ring" role="img" aria-label="Score <?= (int) $sr['score'] ?> out of 100">
            <circle cx="60" cy="60" r="52" class="rep-ring-bg"></circle>
            <circle cx="60" cy="60" r="52" class="rep-ring-fg" stroke-dasharray="<?= $dash ?> 999"></circle>
          </svg>
          <span class="rep-num"><?= (int) $sr['score'] ?></span>
          <span class="rep-out">/ 100</span>
        </div>
        <div class="rep-tally">
          <h3><?= e($sr['site']) ?></h3>
          <p class="rep-sub"><?= (int) $sr['total'] ?> checks, run against the live page.</p>
          <ul class="rep-counts">
            <li class="ok"><b><?= (int) $sr['passed'] ?></b> passed</li>
            <li class="warn"><b><?= (int) $sr['warnings'] ?></b> to fix</li>
            <li class="bad"><b><?= (int) $sr['errors'] ?></b> problems</li>
          </ul>
        </div>
        <div class="rep-bars">
          <?php foreach ($sr['categories'] as [$label, $key, $score]): ?>
          <div class="rep-bar">
            <span class="rep-bar-l"><?= e($label) ?></span>
            <span class="rep-bar-t"><i style="width:<?= (int) $score ?>%"></i></span>
            <span class="rep-bar-n"><?= (int) $score ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="rep-body">
        <?php foreach ($byCat as $key => $rows): $meta = $catLabel[$key] ?? [ucfirst($key), null]; ?>
        <div class="rep-group">
          <div class="rep-group-h"><?= e($meta[0]) ?><?php if ($meta[1] !== null): ?> <span class="mono"><?= (int) $meta[1] ?></span><?php endif; ?></div>
          <ul class="rep-checks">
            <?php foreach ($rows as [$cat, $name, $status, $detail]): ?>
            <li class="rep-<?= e($status) ?>">
              <span class="rep-mark" aria-hidden="true"><?= icon($status === 'pass' ? 'check' : 'alert', 14) ?></span>
              <span class="rep-check"><?= e($name) ?></span>
              <span class="rep-detail"><?= e($detail) ?></span>
              <span class="rep-tag"><?= e($statusWord[$status] ?? $status) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="rep-foot">
        <p>The same audit, the same day, on two more sites it built:</p>
        <ul class="rep-others">
          <?php foreach ($sr['others'] as [$name, $slug, $score, $p, $w, $er]): ?>
          <li><a href="https://<?= e($slug) ?>.levelupgrowth.io/" target="_blank" rel="noopener"><b><?= e($name) ?></b> <span class="rep-o-score"><?= (int) $score ?></span> <span class="rep-o-sub"><?= (int) $p ?> passed · <?= (int) $w ?> to fix</span></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <p class="fine mt">Two warnings on a brand-new site is normal, and we show them rather than hide them. Sarah puts the fixable ones on next month's plan; you approve them before anything changes.</p>
  </div>
</section>
