<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Status';
$page['description'] = 'Live checks of the LevelUpGrowth platform: application, database, cache, queue and mail provider. No invented uptime figures.';
$snap = null;
$raw = @file_get_contents(rtrim($data['api_base'] ?? 'https://staging.levelupgrowth.io', '/') . '/api/public/status', false, stream_context_create(['http' => ['timeout' => 10]]));
if ($raw) { $snap = json_decode($raw, true) ?: null; }
$labels = ['application' => 'Application', 'database' => 'Database', 'cache' => 'Cache', 'queue' => 'Work queue', 'mail' => 'Mail provider'];
?>
<section class="section-tight page-head">
  <div class="container narrow">
    <p class="eyebrow">Status</p>
    <h1 id="status-overall"><?= $snap ? e(ucfirst(str_replace('_', ' ', $snap['overall']))) : 'Checking' ?></h1>
    <p class="lede">Live checks, refreshed every minute. This page shows what is true now; it does not keep history and does not print an uptime percentage we have not measured.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container narrow">
    <ul class="status-list" id="status-list" data-api="/api/public/status">
      <?php foreach ($labels as $k => $label): $c = $snap['components'][$k] ?? ['state' => 'checking']; ?>
      <li class="status-item" data-component="<?= e($k) ?>"><span class="status-dot <?= e($c['state']) ?>" aria-hidden="true"></span><span class="status-name"><?= e($label) ?></span><span class="status-state mono"><?= e(str_replace('_', ' ', $c['state'])) ?><?= isset($c['latency_ms']) ? ' · ' . (int) $c['latency_ms'] . ' ms' : '' ?><?= isset($c['depth']) ? ' · ' . (int) $c['depth'] . ' queued' : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="fine" id="status-checked">Last checked <?= $snap ? e(date('j F Y, H:i', strtotime($snap['checked_at']))) . ' UTC' : 'at page load' ?>.</p>
  </div>
</section>
