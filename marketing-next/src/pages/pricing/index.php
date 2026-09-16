<?php
/** @var array $page */ /** @var array $data */
/*
 * Pricing — reworked 2026-09-07 (Owner directive, Part 3 §8). Rules: no annual price or "save %" until an annual price
 * exists; no money-back guarantee until the refund fact is set; the trial as the platform grants it; every number from
 * the plans table; the workforce line from the plan row (count + registry level) with the wording bug gone.
 */
$page['title'] = 'Pricing';
$plans = $data['plans'];
$page['description'] = count($plans) . ' plans from $0. Free and Starter run the site you own; AI Lite and above add Sarah and the specialist workforce. Monthly billing, cancel anytime.';
$featured = 'ai-lite';
$labels = [
    'website_builder' => 'Website builder', 'custom_domain' => 'Custom domain', 'crm' => 'CRM and lead capture', 'calendar' => 'Calendar and booking',
    'seo_suite' => 'SEO suite', 'content_writing' => 'Content writing', 'image_generation' => 'AI images and ad creatives', 'video_generation' => 'AI video (Studio, desktop)',
    'chatbot_included' => 'Website chatbot', 'social' => 'Social planning and drafting', 'automation' => 'Automations', 'ai_assistant' => 'Aria, the platform FAQ', 'meeting_room' => 'Strategy Room',
];
$counters = ['max_tracked_keywords' => 'Tracked keywords', 'chatbot_messages_per_month' => 'Chatbot messages per month', 'chatbot_kb_max_docs' => 'Chatbot knowledge documents'];
$blurbs = [
    'free' => 'Build and publish your site, capture leads and take bookings. No card, no time limit, no ongoing AI.',
    'starter' => 'The site you own on your own domain, with the CRM and calendar. No ongoing AI.',
    'ai-lite' => 'The full AI Growth OS at its smallest capacity: Sarah, specialists, SEO, content, creative and the chatbot.',
    'growth' => 'More capacity, more sites, more credits for the workforce to spend.',
    'pro' => 'Ten sites, priority processing and the companion app.',
    'agency' => 'White-label, unlimited team, twenty-five client sites under one roof.',
];
$fmtNum = fn ($n) => $n >= 999999 ? 'Unlimited' : number_format((int) $n);
$trial = trial_line($data);
$offers = [];
foreach ($plans as $p) { $offers[] = ['@type' => 'Offer', 'name' => $p['name'], 'price' => $p['price_monthly'], 'priceCurrency' => 'USD', 'url' => 'https://levelupgrowth.io/pricing/#' . $p['slug']]; }
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'LevelUpGrowth', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'offers' => $offers];
$faq = [
    ['What are AI credits?', 'Credits are the meter for the workforce\'s work: research, writing, images, video, and every change Arthur makes to a site (one credit per change; edits you make yourself in the editor are free). Conversation is metered lightly: one credit for every ten messages with Sarah, Arthur or your website chatbot. Every plan from AI Lite includes a monthly allowance that resets each month. Sarah states the cost before anything runs, and every credit, reserved, charged or released, is visible in Billing.'],
    ['Is there a free trial of the AI workforce?', $trial . '. It starts the moment you create the account, so you can meet Sarah before choosing a plan.'],
    ['Can I change plans?', 'Yes. Upgrade or downgrade at any time from Billing. Upgrades apply immediately; downgrades apply at the next renewal.'],
    ['Can I cancel anytime?', 'Yes. Monthly plans have no contract; cancel from your account and keep access until the end of the period you paid for.'],
    ['Do I own my website and data?', 'Yes. Your sites publish on your own subdomain or domain, your domain is registered to you, and your contacts and leads can be exported at any time. Nothing is held hostage to a subscription.'],
    ['What is your refund policy?', 'Refund terms are being finalised and will be published on the Refunds page before public launch. Until then, write to us about any billing question.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
?>
<section class="section-tight page-head">
  <div class="container">
    <p class="eyebrow">Pricing</p>
    <h1>Start free. <span class="grad">Add Sarah when you are ready.</span></h1>
    <p class="lede"><?= count($plans) ?> plans from <?= money($plans[0]['price_monthly'] ?? 0) ?>. Monthly billing, no contracts, cancel anytime. <?= e($trial) ?>.</p>
  </div>
</section>

<section class="section-tight">
  <div class="container plan-grid" id="plans" data-plans-version="<?= e($data['plans_version']) ?>">
    <?php foreach ($plans as $p): $f = $p['features']; ?>
    <article class="plan<?= $p['slug'] === $featured ? ' featured' : '' ?>" id="<?= e($p['slug']) ?>">
      <?php if ($p['slug'] === $featured): ?><span class="plan-tag">Where the AI Growth OS starts</span><?php endif; ?>
      <h2 class="plan-name"><?= e($p['name']) ?></h2>
      <p class="plan-desc"><?= e($blurbs[$p['slug']] ?? '') ?></p>
      <div class="plan-price"><span class="amount"><?= money($p['price_monthly']) ?></span><small>/month</small></div>
      <p class="plan-meta">
        <?= $p['credits_per_month'] > 0 ? $fmtNum($p['credits_per_month']) . ' credits/month · ' : '' ?><?= $fmtNum($p['max_websites']) ?> website<?= $p['max_websites'] === 1 ? '' : 's' ?> · <?= $p['unlimited_team'] ? 'unlimited team' : $fmtNum($p['max_team_members']) . ' team member' . ($p['max_team_members'] === 1 ? '' : 's') ?>
      </p>
      <ul class="feature-list plan-features">
        <li><?= icon('users', 18) ?><span><?= e(workforce_line($p)) ?></span></li>
        <?php foreach (['website_builder', 'custom_domain', 'crm', 'calendar', 'seo_suite', 'content_writing', 'image_generation', 'video_generation', 'chatbot_included'] as $flag): ?>
          <?php if (! isset($labels[$flag])) { continue; } ?>
          <li class="<?= ! empty($f[$flag]) ? '' : 'no' ?>"><?= icon('check', 18) ?><span><?= e($labels[$flag]) ?><?= $flag === 'chatbot_included' && ! empty($f[$flag]) && ! empty($f['chatbot_messages_per_month']) ? ', ' . $fmtNum($f['chatbot_messages_per_month']) . ' messages/month' : '' ?></span></li>
        <?php endforeach; ?>
        <?php if (! empty($p['agents']['includes_dmm'])): ?><li><?= icon('check', 18) ?><span>Companion app: <?= $p['slug'] === 'pro' || $p['slug'] === 'agency' ? 'included' : 'not included' ?></span></li><?php endif; ?>
        <?php if ($p['white_label']): ?><li><?= icon('check', 18) ?><span>White-label</span></li><?php endif; ?>
        <?php if ($p['priority_processing']): ?><li><?= icon('check', 18) ?><span>Priority processing</span></li><?php endif; ?>
      </ul>
      <a class="btn <?= $p['slug'] === $featured ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(signup_href($data, $p['slug'])) ?>"><?= e(cta_label($data)) ?></a>
      <?php if ($p['agents']['addon_price'] !== null): ?><p class="plan-addon">Add a specialist for <?= money($p['agents']['addon_price']) ?>/month</p><?php endif; ?>
    </article>
    <?php endforeach; ?>
    <article class="plan plan-enterprise">
      <h2 class="plan-name">Enterprise and Agency+</h2>
      <p class="plan-desc">More than twenty-five sites, a custom workforce, procurement and invoicing. We build the plan with you.</p>
      <div class="plan-price"><span class="amount">Custom</span></div>
      <p class="plan-meta">Volume pricing · dedicated onboarding · invoicing</p>
      <a class="btn btn-secondary" href="/next/contact/?topic=enterprise">Talk to us</a>
    </article>
  </div>
  <div class="container">
    <h2 class="mt">Which plan is for me?</h2>
    <div class="plan-pick">
      <div><b>I want a site, and I will run the marketing myself.</b>Free on our subdomain; Starter on your own domain. Both include the CRM and calendar, neither includes ongoing AI.</div>
      <div><b>I want Sarah and the workforce running my growth.</b>AI Lite is the full AI Growth OS at its smallest capacity. Growth and Pro add sites, credits and specialists.</div>
      <div><b>I run several businesses or clients.</b>Agency: twenty-five sites, unlimited team, white-label, ten senior specialists.</div>
    </div>
    <p class="fine">Specialist counts and levels (junior, specialist, senior) are read from the plans table; add more on any AI plan for the flat price shown on the card.</p>
  </div>
</section>

<section class="section-tight">
  <div class="container">
    <details class="matrix-wrap" open>
      <summary>Compare every plan</summary>
      <h2>Compare plans</h2>
      <p>Limits and features exactly as the platform enforces them, read from the plans table at build.</p>
      <div class="tbl"><table class="matrix">
        <thead><tr><th scope="col">Feature</th><?php foreach ($plans as $p): ?><th scope="col"><?= e($p['name']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <tr><th scope="row">Monthly price</th><?php foreach ($plans as $p): ?><td class="num"><?= money($p['price_monthly']) ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">Websites</th><?php foreach ($plans as $p): ?><td class="num"><?= $fmtNum($p['max_websites']) ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">Team members</th><?php foreach ($plans as $p): ?><td class="num"><?= $p['unlimited_team'] ? 'Unlimited' : $fmtNum($p['max_team_members']) ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">AI credits per month</th><?php foreach ($plans as $p): ?><td class="num"><?= $fmtNum($p['credits_per_month']) ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">AI workforce</th><?php foreach ($plans as $p): ?><td><?= e(workforce_line($p)) ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">Extra specialist</th><?php foreach ($plans as $p): ?><td class="num"><?= $p['agents']['addon_price'] !== null ? money($p['agents']['addon_price']) . '/mo' : '—' ?></td><?php endforeach; ?></tr>
          <?php foreach ($labels as $flag => $label): $any = false; foreach ($plans as $p) { if (! empty($p['features'][$flag])) { $any = true; } } if (! $any) { continue; } ?>
          <tr><th scope="row"><?= e($label) ?></th><?php foreach ($plans as $p): ?><td class="mark"><?= ! empty($p['features'][$flag]) ? icon('check', 18) . '<span class="sr">Included</span>' : '<span class="dash">—</span>' ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          <?php foreach ($counters as $flag => $label): ?>
          <tr><th scope="row"><?= e($label) ?></th><?php foreach ($plans as $p): ?><td class="num"><?= ! empty($p['features'][$flag]) ? $fmtNum($p['features'][$flag]) : '—' ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          <tr><th scope="row">Companion app</th><?php foreach ($plans as $p): ?><td class="mark"><?= in_array($p['slug'], ['pro', 'agency'], true) ? icon('check', 18) : '<span class="dash">—</span>' ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">White-label</th><?php foreach ($plans as $p): ?><td class="mark"><?= $p['white_label'] ? icon('check', 18) : '<span class="dash">—</span>' ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">Priority processing</th><?php foreach ($plans as $p): ?><td class="mark"><?= $p['priority_processing'] ? icon('check', 18) : '<span class="dash">—</span>' ?></td><?php endforeach; ?></tr>
        </tbody>
      </table></div>
    </details>
  </div>
</section>

<section class="section-tight">
  <div class="container">
    <h2>Questions people ask before they choose</h2>
    <div class="faq">
      <?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
    </div>
  </div>
</section>
