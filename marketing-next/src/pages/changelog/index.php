<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Changelog';
$page['description'] = 'What changed in LevelUpGrowth, dated. Certified engines, fixes and new pages.';
$entries = require dirname(__DIR__, 2) . '/changelog-data.php';
?>
<section class="section-tight page-head">
  <div class="container narrow">
    <p class="eyebrow">Changelog</p>
    <h1>What changed, and when.</h1>
    <p class="lede">Each entry names the part of the platform it touched. Nothing here is a promise; it is a record.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container narrow">
    <?php foreach ($entries as $e): ?>
    <article class="log">
      <div class="log-head"><span class="mono"><?= e($e['date']) ?></span><span class="chip"><?= e($e['area']) ?></span></div>
      <h2><?= e($e['title']) ?></h2>
      <ul><?php foreach ($e['items'] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
    </article>
    <?php endforeach; ?>
  </div>
</section>
