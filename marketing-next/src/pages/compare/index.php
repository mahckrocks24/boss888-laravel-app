<?php
/** @var array $page */ /** @var array $data */
$cmp = require dirname(__DIR__, 2) . '/compare-data.php';
$page['title'] = 'Compare';
$page['description'] = 'LevelUpGrowth against website builders, CRM platforms, AI employee bundles and agency platforms, with competitor prices as published by each vendor, read ' . $cmp['verified'] . '.';
$plans = $data['plans'];
$growth = array_values(array_filter($plans, fn ($p) => $p['slug'] === 'growth'))[0] ?? null;
$agency = array_values(array_filter($plans, fn ($p) => $p['slug'] === 'agency'))[0] ?? null;
?>
<section class="section-tight page-head">
  <div class="container">
    <p class="eyebrow">Compare</p>
    <h1>Four kinds of tool. One account that is all of them.</h1>
    <p class="lede">Website builders sell a site. CRMs sell seats. AI employee bundles sell chat helpers. Agency platforms sell plumbing. Competitor prices below are as published by each vendor when we read them (<?= e($cmp['verified']) ?>); each comparison page lists where. They change, so check before you buy.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container">
    <div class="tbl"><table class="matrix cmp">
      <thead><tr><th>Product</th><th>Camp</th><th>Entry price</th><th>Mid and top</th><th>Where it is thin</th></tr></thead>
      <tbody>
        <tr class="us"><th scope="row">LevelUpGrowth</th><td>All four</td><td class="num"><?= money($plans[0]['price_monthly'] ?? 0) ?> · <?= $growth ? money($growth['price_monthly']) . '/mo with the workforce' : '' ?></td><td class="num"><?= $agency ? money($agency['price_monthly']) . '/mo agency, white-label, ' . (int) $agency['max_websites'] . ' sites' : '' ?></td><td>Customer stories are published only when they are real.</td></tr>
        <?php foreach ($cmp['competitors'] as $c): ?>
        <tr><th scope="row"><a href="/next/compare/<?= e($c['slug']) ?>/"><?= e($c['name']) ?></a></th><td><?= e($c['camp']) ?></td><td class="num"><?= e($c['entry']) ?></td><td class="num"><?= e($c['top']) ?></td><td><?= e($c['thin']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="fine">Competitor prices change; each competitor page lists its sources and the verification date. Our own prices are read from our plans table at build time.</p>
  </div>
</section>
<section class="section-tight">
  <div class="container grid-3">
    <div class="card"><h3>What only we say</h3><p>Your site is exportable, your domain and mailboxes are registered to you, your customer data is yours to take. Nobody else in the table can say all three.</p></div>
    <div class="card"><h3>What only we do</h3><p><?= count($data['agents']) ?> named specialists led by a manager who checks their work before you see it. Helpers advise; ours operate your assets and wait for approval.</p></div>
    <div class="card"><h3>What we will not do</h3><p>Promotional prices that renew at 60% more, setup fees, per-seat meters, or a comparison table without sources.</p></div>
  </div>
</section>
