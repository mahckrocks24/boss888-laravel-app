<?php
/** @var array $page */ /** @var array $data */
/*
 * Partners (RFC-0026, Owner 2026-10-05: "Goal is to encourage vloggers and affiliate marketers to promote us"). Every
 * number is the programme's rule as the platform enforces it (app/Core/Partners/PartnerProgram.php) and every price is
 * read from the plans table at build time. No supplier is named (DEC-0047).
 */
$page['title'] = 'Partner program';
$page['description'] = 'Share LevelUpGrowth and earn: up to 20% of every monthly plan you bring for 6 payments, 15% of yearly plans, 10% of new domains. Split it with your audience as a discount, your choice.';
$byslug = []; foreach ($data['plans'] as $p) { $byslug[$p['slug']] = $p; }
$ex = array_values(array_filter([$byslug['growth'] ?? null, $byslug['pro'] ?? null, $byslug['agency'] ?? null]));
$m2 = fn ($n) => '$' . number_format($n, 2);
$faq = [
    ['Who can join?', 'Creators, vloggers, bloggers, newsletter writers, consultants and agencies whose audience runs a small business. We read every application by hand, usually within 2 working days.'],
    ['How do I choose the split?', 'In your partner portal you create a code and drag one bar per product. Left keeps more for you; right gives your audience a bigger discount. Discount plus your commission always equals that product\'s share: 20% on monthly plans, 15% on yearly plans, 10% on new domains.'],
    ['What if someone uses my link and no code?', 'You earn the full share: 20% of their monthly plan for their first 6 payments, 15% of a yearly plan, 10% of new domain registrations in their first 6 months.'],
    ['How long does my link count?', 'Anyone who signs up within 60 days of clicking your link is yours. A code they type in beats any link, so the person who gave them the code is credited.'],
    ['When do I get paid?', 'Each commission is held for 30 days, in case of a refund, then becomes ready to pay. Payouts go out on the 15th of each month to your bank account once you have at least $50 ready. Smaller amounts roll over to the next month.'],
    ['Do free trials earn?', 'No. You earn on payments. A trial that becomes a paid plan earns from its first payment.'],
    ['What does not earn?', 'Credit top-ups, domain renewals and our $1 first-year domain offer. Your own account and your own business do not count either.'],
    ['What can I see about the people I refer?', 'When they joined, through which link or code, their plan, how many payments have earned you money, and what you earned, with their email partly hidden. Never their work, their customers or their website.'],
    ['Do I need a LevelUpGrowth plan to be a partner?', 'No. Partners have their own portal. If you also run your business on LevelUpGrowth, the same login opens both.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
?>
<section class="hero">
  <div class="container hero-grid">
    <div>
      <p class="eyebrow">Partner program</p>
      <h1>Share LevelUpGrowth. Keep the share you choose.</h1>
      <p class="lede">For every business you bring, you get a share of what they pay: 20% of their monthly plan for 6 payments, 15% of a yearly plan, 10% of new domains. Keep all of it, or give part of it to your audience as a discount. It is your split.</p>
      <div class="hero-actions">
        <a class="btn btn-primary btn-lg" href="/partners/portal?apply=1">Apply to become a partner <?= icon('arrow-right', 18) ?></a>
        <a class="btn btn-secondary btn-lg" href="/partners/portal">Partner sign-in</a>
      </div>
      <p class="fine">Free to join. Reviewed by a person, usually within 2 working days.</p>
    </div>
    <div class="card">
      <h3 style="margin-top:0">One business on <?= e($byslug['pro']['name'] ?? 'Pro') ?>, three ways</h3>
      <?php $pp = (float) ($byslug['pro']['price_monthly'] ?? 199); ?>
      <div class="tbl"><table class="matrix" style="min-width:0">
        <thead><tr><th>You share</th><th class="num">They pay</th><th class="num">You earn</th></tr></thead>
        <tbody>
          <tr><th scope="row">Your link</th><td class="num"><?= $m2($pp) ?>/mo</td><td class="num"><?= $m2($pp * .20) ?>/mo</td></tr>
          <tr><th scope="row">A 5% code</th><td class="num"><?= $m2($pp * .95) ?>/mo</td><td class="num"><?= $m2($pp * .15) ?>/mo</td></tr>
          <tr><th scope="row">A 10% code</th><td class="num"><?= $m2($pp * .90) ?>/mo</td><td class="num"><?= $m2($pp * .10) ?>/mo</td></tr>
        </tbody>
      </table></div>
      <p class="fine" style="margin-bottom:0">For their first 6 monthly payments. On the link alone that is <?= $m2($pp * .20 * 6) ?> from one business.</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>How it works</h2>
    <div class="grid-4 weeks">
      <div class="week"><span class="step-n">1</span><h3>Apply</h3><p>Tell us where you publish. Once approved you get your link, levelupgrowth.io/r/yourname.</p></div>
      <div class="week"><span class="step-n">2</span><h3>Choose your split</h3><p>Make codes in your portal with one bar per product: more for you, or a bigger discount for your audience.</p></div>
      <div class="week"><span class="step-n">3</span><h3>Share</h3><p>Give each video or post its own link to see which one brings sign-ups, and which ones pay.</p></div>
      <div class="week"><span class="step-n">4</span><h3>Get paid</h3><p>Commissions clear after 30 days and are paid to your bank on the 15th of each month.</p></div>
    </div>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <h2>The share on each product</h2>
    <p>Discount plus your commission always adds up to the share. You can change a code at any time; people who already joined keep the terms they joined on.</p>
    <div class="tbl"><table class="matrix">
      <thead><tr><th>Product</th><th class="num">Share</th><th>For how long</th><th>Standard code</th></tr></thead>
      <tbody>
        <tr><th scope="row">Monthly plans</th><td class="num">20%</td><td>The first 6 monthly payments</td><td>5% off for them, 15% for you</td></tr>
        <tr><th scope="row">Yearly plans</th><td class="num">15%</td><td>The yearly payment, once</td><td>5% off for them, 10% for you</td></tr>
        <tr><th scope="row">New domains</th><td class="num">10%</td><td>Registrations in their first 6 months</td><td>5% off for them, 5% for you</td></tr>
      </tbody>
    </table></div>
    <?php if ($ex): ?>
    <h3 class="mt">On the link alone, per business</h3>
    <div class="tbl"><table class="matrix">
      <thead><tr><th>Plan</th><th class="num">Price</th><th class="num">You earn a month</th><th class="num">Over 6 payments</th></tr></thead>
      <tbody>
        <?php foreach ($ex as $p): $pr = (float) $p['price_monthly']; ?>
        <tr><th scope="row"><?= e($p['name']) ?></th><td class="num"><?= money($pr) ?>/mo</td><td class="num"><?= $m2($pr * .20) ?></td><td class="num"><?= $m2($pr * .20 * 6) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container grid-3">
    <div class="card"><?= icon('chart', 22) ?><h3>Your numbers, live</h3><p>Clicks, sign-ups, paying businesses and earnings in your portal, by link, by video, by period.</p></div>
    <div class="card"><?= icon('shield', 22) ?><h3>Fair by design</h3><p>A typed code beats a link, every payment is counted once, and refunds are taken back the same way they were earned.</p></div>
    <div class="card"><?= icon('users', 22) ?><h3>People, not bots</h3><p>Every application is read by a person, and every partner can reach us. Say clearly when a link is a partner link.</p></div>
  </div>
</section>

<section class="section section-alt">
  <div class="container narrow">
    <h2>Questions</h2>
    <div class="faq">
      <?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
    </div>
    <p class="mt"><a class="btn btn-primary" href="/partners/portal?apply=1">Apply now <?= icon('arrow-right', 18) ?></a> <a class="btn btn-secondary" href="/next/legal/partners/">Partner terms</a></p>
  </div>
</section>
