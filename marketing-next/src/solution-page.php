<?php
/** @var array $page */ /** @var array $data */ /** @var array $s */
/*
 * SOLUTIONS-2 (Owner 2026-10-03: "Solutions ... scored 2/10, make it enterprise level per industry"). One industry, sold the
 * way an enterprise vendor sells it: the outcome, what holds the industry back and how the platform answers, the system in
 * four parts, the team we recommend beside Sarah (every AI plan is Sarah + 5 you choose), the first thirty days, plan fit
 * and questions. The dashboard is an example and says so. Styles: site-glass.css section 20 (sol-*), pricing's pr-* reused.
 */
$page['title'] = $s['name'];
$page['description'] = $s['promise'] . ' ' . mb_substr($s['lede'], 0, 140);
// SOL-SITES-1: an old combined slug (dental-and-medical, beauty-and-barbers, hotels-and-rentals) still answers, pointing search at the real page
if (! empty($alias_of)) { $page['route'] = $alias_of; $page['noindex'] = true; }
$pd = require __DIR__ . '/product-data.php';
$productIndex = []; foreach ($pd['launched'] as $p) { $productIndex[$p['slug']] = $p; }
$agentBy = []; foreach ($data['agents'] as $a) { $agentBy[$a['slug']] = $a; }
$planBy = []; foreach ($data['plans'] as $p) { $planBy[$p['slug']] = $p; }
$avatar = fn (string $slug) => '/img/agents/' . rawurlencode($slug) . '.webp';
$sarah = $agentBy['sarah'] ?? ['name' => 'Sarah', 'title' => 'Digital Marketing Manager', 'slug' => 'sarah'];
$team = array_values(array_filter(array_map(fn ($t) => isset($agentBy[$t[0]]) ? $agentBy[$t[0]] + ['job' => $t[1]] : null, $s['team'])));
$fitPlans = [
    [$s['fit'][0], $planBy['ai-lite'] ?? null, 'Sarah, five specialists and the website chatbot for one business.', '/next/pricing/#ai-lite'],
    [$s['fit'][1], $planBy['growth'] ?? null, 'Three websites and businesses on Growth, ten on Pro, each with its own brand.', '/next/pricing/#growth'],
    [$s['fit'][2], null, 'An engagement built around your strategy and every department.', '/next/pricing/#enterprise'],
];
$statusClass = fn (string $st) => ['Waiting for you' => 'is-wait', 'Approved' => 'is-ok', 'Live' => 'is-live'][$st] ?? '';
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Solutions', 'item' => 'https://levelupgrowth.io/solutions/'], ['@type' => 'ListItem', 'position' => 2, 'name' => $s['name'], 'item' => 'https://levelupgrowth.io/solutions/' . $s['slug'] . '/']]];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $s['faq'])];
?>
<section class="sol-hero">
  <div class="container sol-hero-grid">
    <div class="sol-hero-copy">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/next/solutions/">Solutions</a><span>/</span><span><?= e($s['name']) ?></span></nav>
      <p class="eyebrow"><?= icon($s['icon'], 16) ?> <?= e($s['name']) ?></p>
      <h1><?= e($s['promise']) ?></h1>
      <p class="lede"><?= e($s['lede']) ?></p>
      <div class="sol-actions">
        <a class="btn btn-primary btn-lg" href="<?= e(signup_href($data)) ?>"><?= e(cta_label($data)) ?> <?= icon('arrow-right', 18) ?></a>
        <a class="btn btn-secondary btn-lg" href="/next/pricing/">See plans</a>
      </div>
      <ul class="pr-facts sol-facts">
        <?php foreach ($s['outcomes'] as $o): ?><li><?= icon('check', 16) ?><?= e($o) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <div class="sol-dash" aria-label="Example dashboard">
      <div class="sol-dash-head">
        <span class="sol-dash-who"><img src="<?= e($avatar('sarah')) ?>" alt="" width="32" height="32" loading="eager"><span><b>Sarah</b><small><?= e($s['short']) ?> dashboard</small></span></span>
        <span class="sol-chip">Example</span>
      </div>
      <div class="sol-kpis">
        <?php foreach ($s['dash'] as [$k, $v]): ?><div><small><?= e($k) ?></small><b><?= e($v) ?></b></div><?php endforeach; ?>
      </div>
      <p class="sol-dash-sub">This week's work</p>
      <ul class="sol-tasks">
        <?php foreach ($s['tasks'] as [$who, $what, $st]): $a = $agentBy[$who] ?? null; if (! $a) continue; ?>
        <li><img src="<?= e($avatar($who)) ?>" alt="" width="30" height="30" loading="lazy"><span><b><?= e($what) ?></b><small><?= e($a['name']) ?> · <?= e($a['title']) ?></small></span><em class="<?= $statusClass($st) ?>"><?= e($st) ?></em></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>

<?php require __DIR__ . '/sol-sites.php'; ?>

<section class="pr-group">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">The challenge</p>
      <h2>What holds a <?= e(strtolower($s['unit'])) ?> back, and how we answer it</h2>
    </div>
    <div class="sol-pains">
      <?php foreach ($s['pains'] as $i => [$t, $problem, $answer]): ?>
      <article class="plan sol-pain">
        <span class="sol-num">0<?= $i + 1 ?></span>
        <h3><?= e($t) ?></h3>
        <p class="sol-problem"><?= e($problem) ?></p>
        <p class="sol-answer"><?= icon('check', 16) ?><span><?= e($answer) ?></span></p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pr-group">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">The system</p>
      <h2>One platform, built around how a <?= e(strtolower($s['unit'])) ?> grows</h2>
    </div>
    <div class="sol-pillars">
      <?php foreach ($s['pillars'] as [$ic, $t, $txt, $prods]): ?>
      <div class="sol-pillar">
        <?= icon($ic, 22) ?>
        <h3><?= e($t) ?></h3>
        <p><?= e($txt) ?></p>
        <p class="sol-links"><?php foreach ($prods as $slug): if (! isset($productIndex[$slug])) continue; ?><a href="/next/product/<?= e($slug) ?>/"><?= e($productIndex[$slug]['name']) ?></a><?php endforeach; ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pr-group">
  <div class="container">
    <div class="sol-team">
      <div class="sol-team-lead">
        <p class="eyebrow">Your team</p>
        <h2>Sarah leads. Five specialists do the work.</h2>
        <p>Every AI plan includes Sarah and five specialists you choose. For a <?= e(strtolower($s['unit'])) ?>, this is the team we recommend.</p>
        <div class="sol-sarah">
          <img src="<?= e($avatar('sarah')) ?>" alt="" width="64" height="64" loading="lazy">
          <span><b><?= e($sarah['name']) ?></b><small><?= e($sarah['title']) ?></small><em>Plans the month, briefs the team and brings the work back for your yes.</em></span>
        </div>
      </div>
      <ul class="sol-roster">
        <?php foreach ($team as $a): ?>
        <li><img src="<?= e($avatar($a['slug'])) ?>" alt="" width="48" height="48" loading="lazy"><span><b><?= e($a['name']) ?></b><small><?= e($a['title']) ?></small><em><?= e($a['job']) ?></em></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>

<section class="pr-group">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">The first thirty days</p>
      <h2>What happens, week by week</h2>
      <p>Every step runs on an engine that exists today, and nothing goes live until you approve it.</p>
    </div>
    <ol class="sol-weeks">
      <?php foreach ($s['weeks'] as $i => [$h, $t]): ?>
      <li><span class="sol-week">Week <?= $i + 1 ?></span><h3><?= e($h) ?></h3><p><?= e($t) ?></p></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section class="pr-group">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">Plan fit</p>
      <h2>The right plan for the size of your business</h2>
    </div>
    <div class="sol-fit">
      <?php foreach ($fitPlans as $i => [$who, $plan, $txt, $href]): ?>
      <a class="plan sol-fit-card<?= $i === 2 ? ' is-ent' : '' ?>" href="<?= e($href) ?>">
        <small><?= e($who) ?></small>
        <b><?= $plan ? e($plan['name']) . ' <span>' . money($plan['price_monthly']) . '/month</span>' : 'Enterprise <span>By engagement</span>' ?></b>
        <p><?= e($txt) ?></p>
        <span class="sol-fit-go"><?= $i === 2 ? 'Explore Enterprise' : 'See the plan' ?> <?= icon('arrow-right', 16) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pr-faq">
  <div class="container pr-faq-in">
    <div class="pr-faq-side">
      <h2>Questions from <?= e(strtolower($s['name'])) ?></h2>
      <p>Something specific to your business? <a href="/next/contact/">Write to us</a> and a person answers.</p>
    </div>
    <div class="faq"><?php foreach ($s['faq'] as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
  </div>
</section>

<section class="pr-close">
  <div class="container">
    <div class="pr-close-in">
      <h2>See it working for your <?= e(strtolower($s['unit'])) ?>, free for three days</h2>
      <p>Arthur builds your site, Sarah and her team start on the first month, and you approve every step. No card.</p>
      <p class="pr-close-cta"><a class="btn btn-primary btn-lg" href="<?= e(signup_href($data)) ?>"><?= e(cta_label($data)) ?></a><a class="btn btn-secondary btn-lg" href="/next/solutions/">All industries</a></p>
    </div>
  </div>
</section>
