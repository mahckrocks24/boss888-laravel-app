<?php
/** @var array $page */ /** @var array $data */
$sols = require dirname(__DIR__, 2) . '/solutions-data.php';
$page['title'] = 'Solutions';
$page['description'] = 'How LevelUpGrowth runs a restaurant, a gym, a clinic, a brokerage, a salon, a consultancy, a shop or a hotel: the site Arthur builds, the specialists and the first thirty days.';
?>
<section class="section-tight page-head">
  <div class="container">
    <p class="eyebrow">Solutions</p>
    <h1>Built for the business you actually run.</h1>
    <p class="lede">Eight industries where the site, the specialists and the first thirty days are already worked out. Arthur builds for every other industry from your brief.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container grid-4">
    <?php foreach ($sols as $s): ?>
    <a class="card product" href="/next/solutions/<?= e($s['slug']) ?>/"><?= icon($s['icon'], 22) ?><h3><?= e($s['name']) ?></h3><p><?= e($s['promise']) ?></p></a>
    <?php endforeach; ?>
  </div>
</section>
