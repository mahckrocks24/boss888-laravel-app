<?php
/**
 * The website-builder page's evidence: the conversation that built a site, then five sites you can open.
 * Reuses the home components so there is one implementation, not two. @var array $data
 */
$sc = require dirname(__DIR__) . '/showcase-data.php';
$sr = require dirname(__DIR__) . '/seo-data.php';
$fastest = min(array_map(fn ($s) => (int) $s['seconds'], $sc['sites']));
?>
<section class="section" id="build">
  <div class="container">
    <p class="eyebrow">The whole thing, start to finish</p>
    <h2>You talk. Arthur builds. <?= (int) $sc['build_seconds'] ?> seconds later it is live.</h2>
    <p class="lede">This is the actual conversation that produced the site on the right.</p>
    <?php require dirname(__DIR__) . '/build-sim.php'; ?>
  </div>
</section>

<section class="section section-alt" id="sites">
  <div class="container">
    <p class="eyebrow">Five briefs, five sites</p>
    <h2>Open any of them. They are on the web right now.</h2>
    <p class="lede">The sentence under each one is the whole brief it was built from.</p>
    <?php require dirname(__DIR__) . '/gallery.php'; ?>
    <p class="swipe-hint"><?= icon('arrow-right', 14) ?> swipe for more</p>
    <p class="fine mt">Fastest of the five: <?= $fastest ?> seconds. And they are not only quick — <?= e($sr['site_name']) ?> scores <b><?= (int) $sr['score'] ?>/100</b> on our own <?= (int) $sr['total'] ?>-point SEO audit before anyone touches it. <a href="/next/product/seo/#report">See the report</a>.</p>
  </div>
</section>
