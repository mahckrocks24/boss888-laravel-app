<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Agencies';
$agency = array_values(array_filter($data['plans'], fn ($p) => $p['slug'] === 'agency'))[0] ?? null;
$pro = array_values(array_filter($data['plans'], fn ($p) => $p['slug'] === 'pro'))[0] ?? null;
$cmp = require dirname(__DIR__, 2) . '/compare-data.php';
$rivals = array_values(array_filter($cmp['competitors'], fn ($c) => in_array($c['slug'], ['gohighlevel', 'markty'], true)));
$page['description'] = 'Run every client from one account: white-label, ' . ($agency ? (int) $agency['max_websites'] . ' sites, unlimited team, ' : '') . 'an AI workforce per workspace, and a price that does not charge you per client.';
$specialists = count(array_filter($data['agents'], fn ($a) => empty($a['is_dmm'])));
?>
<section class="hero">
  <div class="container hero-grid">
    <div>
      <p class="eyebrow">Agencies</p>
      <h1>Every client in one account. Your brand on all of it.</h1>
      <p class="lede">Client workspaces, white-label, an AI workforce that does the production work, and an approval flow you can run with a small team. Priced as one plan, not per client.</p>
      <div class="hero-actions">
        <a class="btn btn-primary btn-lg" href="<?= e(signup_href($data, $agency['slug'] ?? null)) ?>">Start with <?= e($agency['name'] ?? 'Agency') ?> <?= icon('arrow-right', 18) ?></a>
        <a class="btn btn-secondary btn-lg" href="/next/contact/?topic=enterprise">Talk to us about more</a>
      </div>
      <?php if ($agency): ?><p class="fine"><?= e($agency['name']) ?>: <?= money($agency['price_monthly']) ?>/month, <?= (int) $agency['max_websites'] ?> sites, <?= $agency['unlimited_team'] ? 'unlimited team' : (int) $agency['max_team_members'] . ' team members' ?>, <?= number_format((int) $agency['credits_per_month']) ?> credits, white-label. Read from the plans table.</p><?php endif; ?>
    </div>
    <div><?= shot('12-websites', 'Two published sites in one workspace. An agency runs one workspace per client, each with its own sites, CRM, calendar and credits.', 'Websites', true) ?></div>
  </div>
</section>

<section class="section">
  <div class="container grid-3">
    <div class="card"><?= icon('layers', 22) ?><h3>Client workspaces</h3><p>Each client gets a workspace with its own site, CRM, calendar, content and chatbot. Your team switches between them; nothing leaks across.</p></div>
    <div class="card"><?= icon('shield', 22) ?><h3>White-label</h3><p>Your brand on the sites and the client-facing surfaces. The platform stays in the background.</p></div>
    <div class="card"><?= icon('users', 22) ?><h3>The workforce does production</h3><p>Sarah plus <?= $agency ? (int) $agency['agents']['count'] . ' specialists' : 'specialists' ?> on the Agency plan; your account managers approve instead of producing.</p></div>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <h2>Against the agency platforms</h2>
    <p>Competitor prices as published when we read them (<?= e($cmp['verified']) ?>); each comparison page lists where.</p>
    <div class="tbl"><table class="matrix cmp">
      <thead><tr><th>Platform</th><th>Entry</th><th>Top</th><th>What you get</th><th>Where it is thin</th></tr></thead>
      <tbody>
        <tr class="us"><th scope="row">LevelUpGrowth <?= e($agency['name'] ?? '') ?></th><td class="num"><?= $pro ? 'Pro ' . money($pro['price_monthly']) . '/mo' : '' ?></td><td class="num"><?= $agency ? 'Agency ' . money($agency['price_monthly']) . '/mo' : '' ?></td><td>White-label, <?= $agency ? (int) $agency['max_websites'] : '' ?> client sites, unlimited team, an AI workforce per workspace, owned assets for every client.</td><td>Customer stories only when real.</td></tr>
        <?php foreach ($rivals as $c): ?>
        <tr><th scope="row"><a href="/next/compare/<?= e($c['slug']) ?>/"><?= e($c['name']) ?></a></th><td class="num"><?= e($c['entry']) ?></td><td class="num"><?= e($c['top']) ?></td><td><?= e($c['sells']) ?></td><td><?= e($c['thin']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>How an agency month runs</h2>
    <div class="grid-4 weeks">
      <div class="week"><span class="step-n">1</span><h3>Onboard the client</h3><p>Brief, site built by Arthur, approved by you, published on the client's subdomain.</p></div>
      <div class="week"><span class="step-n">2</span><h3>Set the workforce</h3><p>Sarah drafts the plan per workspace; you pick the specialists and the cadence.</p></div>
      <div class="week"><span class="step-n">3</span><h3>Approve, do not produce</h3><p>Content, social and creatives arrive reviewed; your team approves in one click per item.</p></div>
      <div class="week"><span class="step-n">4</span><h3>Report from the platform</h3><p>SEO movement, leads and bookings per workspace, read from the same data the client sees.</p></div>
    </div>
    <p class="mt"><a class="btn btn-primary" href="<?= e(signup_href($data, $agency['slug'] ?? null)) ?>">Start with <?= e($agency['name'] ?? 'Agency') ?> <?= icon('arrow-right', 18) ?></a> <a class="btn btn-secondary" href="/next/pricing/">Compare plans</a></p>
  </div>
</section>
