<?php
/** @var array $page */ /** @var array $data */
/*
 * Solutions - SOLUTIONS-2 (Owner 2026-10-03: "Solutions page ... scored 2/10, make it enterprise level per industry"). The
 * industries as an enterprise vendor presents them: each with its promise, three outcomes and its recommended team; what every
 * industry gets; Enterprise for groups; a closing band. Styles: site-glass.css section 20 (sol-*), pricing's pr-* reused.
 */
$sols = require dirname(__DIR__, 2) . '/solutions-data.php';
$page['title'] = 'Solutions';
$page['description'] = 'Industry solutions for restaurants, gyms, clinics, real estate, salons, professional services, retail and hospitality: the site Arthur builds, the AI team Sarah leads and the first thirty days, worked out for your industry.';
$avatar = fn (string $slug) => '/img/agents/' . rawurlencode($slug) . '.webp';
?>
<section class="pr-hero">
  <div class="container">
    <p class="eyebrow">Solutions</p>
    <h1>Growth, engineered <span class="grad">for your industry.</span></h1>
    <p class="lede">The site, the AI team and the first thirty days, already worked out for the business you run. Choose your industry to see the system built for it.</p>
    <ul class="pr-facts">
      <li><?= icon('check', 16) ?>Built for how your industry wins customers</li>
      <li><?= icon('check', 16) ?>Sarah and five specialists matched to your work</li>
      <li><?= icon('check', 16) ?>Nothing goes live without your approval</li>
    </ul>
  </div>
</section>

<section class="pr-group">
  <div class="container">
    <?php /* SOL-SITES-1: 33 industries grouped by sector */ $sectors = []; foreach ($sols as $s) { $sectors[$s['sector'] ?? 'Industries'][] = $s; } $anchor = fn (string $n) => preg_replace('/[^a-z0-9]+/', '-', strtolower($n)); ?>
    <?php if (count($sectors) > 1): ?><nav class="sol-sector-nav" aria-label="Sectors"><?php foreach (array_keys($sectors) as $n): ?><a href="#<?= e($anchor($n)) ?>"><?= e($n) ?></a><?php endforeach; ?></nav><?php endif; ?>
    <div class="sol-sectors">
    <?php foreach ($sectors as $sectorName => $group): ?>
    <div class="sol-sector" id="<?= e($anchor($sectorName)) ?>">
    <?php if (count($sectors) > 1): ?><h2><?= e($sectorName) ?></h2><?php endif; ?>
    <div class="sol-grid">
      <?php foreach ($group as $s): ?>
      <a class="plan sol-card" href="/next/solutions/<?= e($s['slug']) ?>/">
        <span class="sol-card-top"><span class="sol-card-icon"><?= icon($s['icon'], 22) ?></span><span class="sol-faces"><?php foreach (array_merge(['sarah'], array_column(array_slice($s['team'], 0, 3), 0)) as $f): ?><img src="<?= e($avatar($f)) ?>" alt="" width="28" height="28" loading="lazy"><?php endforeach; ?></span></span>
        <h2><?= e($s['name']) ?></h2>
        <p><?= e($s['promise']) ?></p>
        <ul><?php foreach ($s['outcomes'] as $o): ?><li><?= icon('check', 14) ?><?= e($o) ?></li><?php endforeach; ?></ul>
        <span class="sol-fit-go">Explore the solution <?= icon('arrow-right', 16) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pr-group">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">In every industry</p>
      <h2>One platform under every solution</h2>
    </div>
    <ul class="sol-pillars sol-common">
      <li class="sol-pillar"><?= icon('users', 22) ?><h3>Sarah and her team</h3><p>A Digital Marketing Manager and five specialists you choose, working on your business every week.</p></li>
      <li class="sol-pillar"><?= icon('globe', 22) ?><h3>A site built for you</h3><p>Arthur builds and edits your website from a conversation, on your own domain.</p></li>
      <li class="sol-pillar"><?= icon('message', 22) ?><h3>Chatbot and CRM</h3><p>Visitors answered around the clock, and every lead and booking in one place.</p></li>
      <li class="sol-pillar"><?= icon('shield', 22) ?><h3>Approval first</h3><p>Nothing is published, posted or sent until you say yes.</p></li>
    </ul>
  </div>
</section>

<section class="pr-group">
  <div class="container">
    <div class="pr-feature pr-custom sol-ent">
      <div class="pr-feature-copy">
        <p class="eyebrow">Not on the list, or running a group?</p>
        <h2>Every industry is served. Enterprises get an engagement.</h2>
        <p class="pr-feature-lede">Arthur builds for any business from your brief, and Sarah's team adapts to the way your industry wins customers. For corporations and groups, we architect end-to-end AI around your strategy and every department.</p>
      </div>
      <div class="sol-ent-cta">
        <a class="btn btn-primary btn-lg" href="/next/pricing/#enterprise">Explore Enterprise</a>
        <a class="btn btn-secondary btn-lg" href="<?= e(signup_href($data)) ?>"><?= e(cta_label($data)) ?></a>
      </div>
    </div>
  </div>
</section>

<section class="pr-close">
  <div class="container">
    <div class="pr-close-in">
      <h2>Your industry, your team, free for three days</h2>
      <p>50 credits, every AI feature, no card. Choose a plan when you are ready.</p>
      <p class="pr-close-cta"><a class="btn btn-primary btn-lg" href="<?= e(signup_href($data)) ?>"><?= e(cta_label($data)) ?></a><a class="btn btn-secondary btn-lg" href="/next/pricing/">See plans</a></p>
    </div>
  </div>
</section>
