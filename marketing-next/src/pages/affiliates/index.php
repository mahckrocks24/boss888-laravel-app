<?php
/** @var array $page */ /** @var array $data */
/*
 * Affiliate Program (RFC-0026; Owner 2026-10-05: "It should say Affiliate Program" and "remove the custom URL and just do
 * vouchers"). Codes only - no tracking links, no cookies. Every number is the programme's rule as the platform enforces it
 * (app/Core/Partners/PartnerProgram.php); every price is read from the plans table at build time. No supplier is named.
 */
$page['title'] = 'Affiliate Program';
$page['description'] = 'Share your own LevelUpGrowth code and earn: up to 20% of every monthly plan for 6 payments, 15% of yearly plans, 10% of new domains. Split it with your audience as a discount, your choice.';
$byslug = []; foreach ($data['plans'] as $p) { $byslug[$p['slug']] = $p; }
$ex = array_values(array_filter([$byslug['growth'] ?? null, $byslug['pro'] ?? null, $byslug['agency'] ?? null]));
$m2 = fn ($n) => '$' . number_format($n, 2);
$faq = [
    ['Who can join?', 'Creators, vloggers, bloggers, newsletter writers, consultants and agencies whose audience runs a small business. We read every application by hand, usually within 2 working days.'],
    ['How does a business count as mine?', 'When it signs up with one of your codes. There are no tracking links and no cookies: your code is how we know the business came from you.'],
    ['How do I choose the split?', 'In your affiliate portal you create a code and drag one bar per product. Left keeps more for you; right gives your audience a bigger discount. Discount plus your commission always equals that product\'s share: 20% on monthly plans, 15% on yearly plans, 10% on new domains.'],
    ['Can I keep the whole share?', 'Yes. Make a code with no discount and you earn the full 20% of their monthly plan for their first 6 payments, 15% of a yearly plan, 10% of new domain registrations in their first 6 months.'],
    ['Can I see which video works best?', 'Yes. Make a different code for each video or post, and your portal shows sign-ups, paying businesses and earnings for each code.'],
    ['When do I get paid?', 'Each commission is held for 30 days, in case of a refund, then becomes ready to pay. Payouts go out on the 15th of each month, by PayPal or Wise, once you have at least $50 ready. Smaller amounts roll over to the next month.'],
    ['Do free trials earn?', 'No. You earn on payments. A trial that becomes a paid plan earns from its first payment.'],
    ['What does not earn?', 'Credit top-ups, domain renewals and our $1 first-year domain offer. Your own account and your own business do not count either.'],
    ['What can I see about the people I refer?', 'When they joined, which code they used, their plan, how many payments have earned you money, and what you earned, with their email partly hidden. Never their work, their customers or their website.'],
    ['Do I need a LevelUpGrowth plan to join?', 'No. Affiliates have their own portal. If you also run your business on LevelUpGrowth, the same login opens both.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
?>
<section class="hero">
  <div class="container hero-grid">
    <div>
      <p class="eyebrow">Affiliate Program</p>
      <h1>Share your code. Keep the share you choose.</h1>
      <p class="lede">For every business that signs up with your code, you get a share of what they pay: 20% of their monthly plan for 6 payments, 15% of a yearly plan, 10% of new domains. Keep all of it, or give part of it to your audience as a discount. It is your split.</p>
      <div class="hero-actions">
        <a class="btn btn-primary btn-lg" href="/affiliates/portal?apply=1">Apply to become an affiliate <?= icon('arrow-right', 18) ?></a>
        <a class="btn btn-secondary btn-lg" href="/affiliates/portal">Affiliate sign-in</a>
      </div>
      <p class="fine">Free to join. Reviewed by a person, usually within 2 working days. Codes only: no tracking links, no cookies.</p>
    </div>
    <div class="card">
      <h3 style="margin-top:0">One business on <?= e($byslug['pro']['name'] ?? 'Pro') ?>, three codes</h3>
      <?php $pp = (float) ($byslug['pro']['price_monthly'] ?? 199); ?>
      <div class="tbl"><table class="matrix" style="min-width:0">
        <thead><tr><th>Your code</th><th class="num">They pay</th><th class="num">You earn</th></tr></thead>
        <tbody>
          <tr><th scope="row">No discount</th><td class="num"><?= $m2($pp) ?>/mo</td><td class="num"><?= $m2($pp * .20) ?>/mo</td></tr>
          <tr><th scope="row">5% off</th><td class="num"><?= $m2($pp * .95) ?>/mo</td><td class="num"><?= $m2($pp * .15) ?>/mo</td></tr>
          <tr><th scope="row">10% off</th><td class="num"><?= $m2($pp * .90) ?>/mo</td><td class="num"><?= $m2($pp * .10) ?>/mo</td></tr>
        </tbody>
      </table></div>
      <p class="fine" style="margin-bottom:0">For their first 6 monthly payments. With no discount that is <?= $m2($pp * .20 * 6) ?> from one business.</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>How it works</h2>
    <div class="grid-4 weeks">
      <div class="week"><span class="step-n">1</span><h3>Apply</h3><p>Tell us where you publish. A person reads it and replies, usually within 2 working days.</p></div>
      <div class="week"><span class="step-n">2</span><h3>Create your code</h3><p>Pick a code your audience will remember and drag the split: more for you, or a bigger discount for them.</p></div>
      <div class="week"><span class="step-n">3</span><h3>Share it</h3><p>Say it in your video, put it in your description or post. A code per video shows you which one works.</p></div>
      <div class="week"><span class="step-n">4</span><h3>Get paid</h3><p>Commissions clear after 30 days and are paid on the 15th of each month by PayPal or Wise.</p></div>
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
    <h3 class="mt">With a no-discount code, per business</h3>
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
    <div class="card"><?= icon('chart', 22) ?><h3>Your numbers, live</h3><p>Sign-ups, paying businesses and earnings in your portal, by code and by period.</p></div>
    <div class="card"><?= icon('shield', 22) ?><h3>Fair by design</h3><p>A business counts when it uses your code, every payment is counted once, and refunds are taken back the same way they were earned.</p></div>
    <div class="card"><?= icon('users', 22) ?><h3>People, not bots</h3><p>Every application is read by a person, and every affiliate can reach us. Say clearly that your code is an affiliate code.</p></div>
  </div>
</section>

<section class="section section-alt">
  <div class="container narrow">
    <h2>Questions</h2>
    <div class="faq">
      <?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
    </div>
    <p class="mt"><a class="btn btn-primary" href="/affiliates/portal?apply=1">Apply now <?= icon('arrow-right', 18) ?></a> <a class="btn btn-secondary" href="/next/legal/affiliates/">Affiliate terms</a></p>
  </div>
</section>
