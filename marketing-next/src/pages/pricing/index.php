<?php
/** @var array $page */ /** @var array $data */
/*
 * Pricing - PRICING-2 (Owner 2026-10-03: "The pricing page is not enterprise"; "chatbot is not even mentioned ... group them
 * into 4, 0 to AI Lite, then another group for 99 and 199, then ... 1 row for agency and last row for custom"; "the custom
 * is dedicated to big corporations exactly what we have for MR Systems"). Four groups: Free, Starter and AI Lite; Growth and
 * Pro (each card says only what it adds); Agency on its own row selling the idea to agencies; Custom on the last row selling
 * what we build for large organisations. The website chatbot is a capacity row on every card. Rules kept from 2026-09-07:
 * no annual price or "save %" until an annual price exists; no money-back guarantee until the refund fact is set; the trial
 * as the platform grants it; every number from the plans table.
 */
$page['title'] = 'Pricing';
$plans = $data['plans'];
$bySlug = []; foreach ($plans as $p) $bySlug[$p['slug']] = $p;
$page['description'] = count($plans) . ' plans from $0. Free and Starter run the site you own; from AI Lite every plan adds Sarah, five specialists and the website chatbot. Monthly billing, cancel anytime.';
$featured = 'ai-lite';
$fmtNum = fn ($n) => ((int) $n === -1 || $n >= 999999) ? 'Unlimited' : number_format((int) $n);
$users = fn ($p) => ! empty($p['unlimited_team']) || (int) $p['max_team_members'] >= 999 ? 'Unlimited' : $fmtNum($p['max_team_members']);
$sites = function ($p) use ($fmtNum) {
    $w = (int) $p['max_websites']; $b = (int) (($p['features']['max_businesses'] ?? null) ?: $w);
    return $fmtNum($w) . ($b !== $w ? ' / ' . $fmtNum($b) : '');
};
$chat = fn ($p) => empty($p['features']['chatbot_included']) ? 'Not included' : (($m = $fmtNum($p['features']['chatbot_messages_per_month'] ?? 0)) === 'Unlimited' ? 'Unlimited' : $m . ' messages/mo');
$credits = fn ($p) => (int) $p['credits_per_month'] > 0 ? $fmtNum($p['credits_per_month']) : 'None';
$companion = fn ($p) => in_array($p['slug'], ['growth', 'pro', 'agency'], true);
$isAi = fn ($p) => ! empty($p['agents']['includes_dmm']);
$team = 'Sarah + ' . (int) (($bySlug[$featured]['agents']['count'] ?? 5)) . ' specialists';
$blurbs = [
    'free' => 'Build and publish your site on a free address, capture leads and take bookings. Your site carries our ads.',
    'starter' => 'Everything in Free, on your own domain and with no ads on your site.',
    'ai-lite' => 'Sarah, five specialists and the website chatbot working for one business.',
    'growth' => 'For a business with more than one site: three websites and more credits.',
    'pro' => 'For a business that runs on its marketing: ten websites, three users and priority processing.',
];
// what a plan adds over the one before it (credits, websites, users and the chatbot sit in the capacity rows)
$flagLabels = [
    'website_builder' => 'Website builder', 'crm' => 'CRM and lead capture', 'calendar' => 'Calendar and booking',
    'custom_domain' => 'Your own domain', 'social' => 'Social planning and drafting', 'automation' => 'Automations',
    'seo_suite' => 'SEO suite with keyword tracking', 'content_writing' => 'Content writing', 'image_generation' => 'AI images and ad creatives',
    'video_generation' => 'AI video', 'meeting_room' => 'Strategy Room with Sarah',
];
// Owner 2026-10-03: "on growth, pro and agency, add Multi-business Profile Management" - every plan with more than one business
$multiBiz = fn ($p) => (int) (($p['features']['max_businesses'] ?? null) ?: $p['max_websites']) > 1;
$adds = function ($p, $prev) use ($flagLabels, $fmtNum, $companion, $isAi, $team, $multiBiz) {
    $out = [];
    if ($multiBiz($p)) $out[] = 'Multi-business profile management';
    if ($isAi($p) && (! $prev || ! $isAi($prev))) { $out[] = $team; $out[] = 'Website chatbot that answers visitors and captures leads'; }
    foreach ($flagLabels as $k => $label) if (! empty($p['features'][$k]) && (! $prev || empty($prev['features'][$k]))) $out[] = $label;
    if (! $prev) $out[] = 'Publish on a free address, with LevelUpGrowth ads';
    if ($prev && $prev['slug'] === 'free') $out[] = 'No ads on your website';   // Owner 2026-10-03: Starter = Free + own domain + no ads
    if ($prev) foreach (['max_tracked_keywords' => '%s tracked keywords', 'chatbot_kb_max_docs' => '%s chatbot knowledge documents'] as $k => $fmt) {
        $a = (int) ($p['features'][$k] ?? 0); $b = (int) ($prev['features'][$k] ?? 0);
        if ($b > 0 && ($a > $b || ($a === -1 && $b !== -1))) $out[] = sprintf($fmt, $a === -1 ? 'Unlimited' : $fmtNum($a));
    }
    if ($companion($p) && (! $prev || ! $companion($prev))) $out[] = 'The companion app for your phone';
    if ($p['priority_processing'] && (! $prev || ! $prev['priority_processing'])) $out[] = 'Priority processing';
    return $out;
};
$card = function ($p, $prev, string $variant) use ($featured, $blurbs, $credits, $sites, $users, $chat, $adds, $data) {
    $isF = $p['slug'] === $featured;
    ob_start(); ?>
      <article class="plan pr-card pr-<?= $variant ?><?= $isF ? ' featured' : '' ?>" id="<?= e($p['slug']) ?>">
        <div class="pr-card-main">
          <?php if ($isF): ?><span class="pr-tag">Where the AI Growth starts</span><?php endif; ?>
          <h3 class="pr-name"><?= e($p['name']) ?></h3>
          <p class="pr-desc"><?= e($blurbs[$p['slug']] ?? '') ?></p>
          <div class="pr-price"><span class="amount"><?= money($p['price_monthly']) ?></span><small>/month</small></div>
          <a class="btn <?= $isF ? 'btn-primary' : 'btn-secondary' ?> pr-cta" href="<?= e(signup_href($data, $p['slug'])) ?>"><?= e(cta_label($data)) ?></a>
        </div>
        <div class="pr-card-detail">
          <dl class="pr-specs">
            <div><dt>AI credits a month</dt><dd><?= e($credits($p)) ?></dd></div>
            <div><dt>Websites and businesses</dt><dd><?= e($sites($p)) ?></dd></div>
            <div><dt>Users</dt><dd><?= e($users($p)) ?></dd></div>
            <div class="<?= empty($p['features']['chatbot_included']) ? 'is-off' : 'is-chat' ?>"><dt>Website chatbot</dt><dd><?= e($chat($p)) ?></dd></div>
          </dl>
          <p class="pr-list-head"><?= $prev ? 'Everything in ' . e($prev['name']) . ', plus' : 'Includes' ?></p>
          <ul class="pr-list">
            <?php foreach ($adds($p, $prev) as $item): ?><li><?= icon('check', 16) ?><span><?= e($item) ?></span></li><?php endforeach; ?>
          </ul>
          <?php if ($p['agents']['addon_price'] !== null): ?><p class="pr-addon">Extra specialist <?= money($p['agents']['addon_price']) ?>/month</p><?php endif; ?>
        </div>
      </article>
    <?php return ob_get_clean();
};
$g1 = array_values(array_filter([$bySlug['free'] ?? null, $bySlug['starter'] ?? null, $bySlug['ai-lite'] ?? null]));
$g2 = array_values(array_filter([$bySlug['growth'] ?? null, $bySlug['pro'] ?? null]));
$ag = $bySlug['agency'] ?? null;
$trial = trial_line($data);
$offers = [];
foreach ($plans as $p) { $offers[] = ['@type' => 'Offer', 'name' => $p['name'], 'price' => $p['price_monthly'], 'priceCurrency' => 'USD', 'url' => 'https://levelupgrowth.io/pricing/#' . $p['slug']]; }
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'LevelUpGrowth', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'offers' => $offers];
$seatLine = implode(', ', array_map(fn ($p) => $p['name'] . ' ' . strtolower($users($p)), array_filter($plans, fn ($p) => (int) $p['max_team_members'] > 1)));
$faq = [
    ['What are AI credits?', 'Credits are the meter for the team\'s work: research, writing, images, video, and every change Arthur makes to a site (one credit per change; edits you make yourself in the editor are free). Conversation is metered lightly: one credit for every five messages with Sarah or Arthur. Every AI plan includes a monthly allowance that resets each month. Sarah states the cost before anything runs, and every credit is visible in Billing.'],
    ['What does the website chatbot do?', 'It sits on your website, answers visitors from what it knows about your business, and turns conversations into leads in your CRM. It is included from AI Lite, with a monthly message allowance on each plan.'],
    ['Is there a free trial?', $trial . '. It starts the moment you create the account, so you can meet Sarah and her team before choosing a plan.'],
    ['What counts as a user?', 'A user is a person you invite into your account, with their own login and a role (admin or member). Free, Starter, AI Lite and Growth are for one user, the owner. More users start on Pro: ' . $seatLine . '. Extra team members are $20 a month each on Pro and Agency.'],
    ['Is the AI team different on bigger plans?', 'No. Every AI plan has the same team, ' . strtolower($team) . '. Bigger plans add credits, websites and users. You can add a specialist on any AI plan for ' . money($bySlug[$featured]['agents']['addon_price'] ?? 20) . ' a month.'],
    ['Can I change plans or cancel?', 'Yes. Upgrade or downgrade at any time from Billing; upgrades apply immediately and downgrades at the next renewal. Monthly plans have no contract: cancel from your account and keep access until the end of the period you paid for.'],
    ['Do I own my website and data?', 'Yes. Your sites publish on your own subdomain or domain, your domain is registered to you, and your contacts and leads can be exported at any time.'],
    ['What is your refund policy?', 'Refund terms are being finalised and will be published on the Refunds page before public launch. Until then, write to us about any billing question.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
$mark = fn (bool $on) => $on ? icon('check', 18) . '<span class="sr">Included</span>' : '<span class="pr-dash" aria-label="Not included">&ndash;</span>';
$groups = [
    'Capacity' => [
        ['Monthly price', fn ($p) => money($p['price_monthly'])],
        ['AI credits a month', fn ($p) => $fmtNum($p['credits_per_month'])],
        ['Websites and businesses', $sites],
        ['Users', $users],
    ],
    'Your AI team' => [
        ['Sarah, your Digital Marketing Manager', fn ($p) => $mark($isAi($p))],
        ['Specialists included', fn ($p) => $isAi($p) ? (string) (int) $p['agents']['count'] : $mark(false)],
        ['Extra specialist', fn ($p) => $p['agents']['addon_price'] !== null ? money($p['agents']['addon_price']) . ' a month' : $mark(false)],
        ['Strategy Room', fn ($p) => $mark(! empty($p['features']['meeting_room']))],
        ['In-app help assistant', fn ($p) => $mark(! empty($p['features']['ai_assistant']))],
    ],
    'Website chatbot' => [
        ['Chatbot on your website', fn ($p) => $mark(! empty($p['features']['chatbot_included']))],
        ['Messages a month', fn ($p) => ! empty($p['features']['chatbot_included']) ? $fmtNum($p['features']['chatbot_messages_per_month'] ?? 0) : $mark(false)],
        ['Knowledge documents', fn ($p) => ! empty($p['features']['chatbot_kb_max_docs']) ? $fmtNum($p['features']['chatbot_kb_max_docs']) : $mark(false)],
    ],
    'Marketing' => [
        ['SEO suite', fn ($p) => $mark(! empty($p['features']['seo_suite']))],
        ['Tracked keywords', fn ($p) => ! empty($p['features']['max_tracked_keywords']) ? $fmtNum($p['features']['max_tracked_keywords']) : $mark(false)],
        ['Content writing', fn ($p) => $mark(! empty($p['features']['content_writing']))],
        ['Social planning and drafting', fn ($p) => $mark(! empty($p['features']['social']))],
        ['AI images and ad creatives', fn ($p) => $mark(! empty($p['features']['image_generation']))],
        ['AI video', fn ($p) => $mark(! empty($p['features']['video_generation']))],
        ['Automations', fn ($p) => $mark(! empty($p['features']['automation']))],
    ],
    'Website and customers' => [
        ['Multi-business profile management', fn ($p) => $mark($multiBiz($p))],
        ['Website builder', fn ($p) => $mark(! empty($p['features']['website_builder']))],
        ['Your own domain', fn ($p) => $mark(! empty($p['features']['custom_domain']))],
        ['No ads on your website', fn ($p) => $mark($p['slug'] !== 'free')],
        ['CRM and lead capture', fn ($p) => $mark(! empty($p['features']['crm']))],
        ['Calendar and booking', fn ($p) => $mark(! empty($p['features']['calendar']))],
    ],
    'Scale' => [
        ['Companion app', fn ($p) => $mark($companion($p))],
        ['Priority processing', fn ($p) => $mark((bool) $p['priority_processing'])],
    ],
];
?>
<section class="pr-hero">
  <div class="container">
    <p class="eyebrow">Pricing</p>
    <h1>Start free. <span class="grad">Add Sarah when you are ready.</span></h1>
    <p class="lede">From your first website to fifty client businesses. Every AI plan has the same team; you choose the capacity.</p>
    <ul class="pr-facts">
      <li><?= icon('check', 16) ?>Three-day trial, 50 credits, no card</li>
      <li><?= icon('check', 16) ?>Monthly billing, cancel anytime</li>
      <li><?= icon('check', 16) ?>Nothing goes live without your approval</li>
    </ul>
  </div>
</section>

<section class="pr-group" id="plans" data-plans-version="<?= e($data['plans_version']) ?>">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">Start</p>
      <h2>From your first website to your AI team</h2>
      <p>Free and Starter run the site you own. AI Lite adds Sarah, five specialists and a chatbot that answers your visitors.</p>
    </div>
    <div class="pr-grid pr-grid-3">
      <?php $prev = null; foreach ($g1 as $p) { echo $card($p, $prev, 'std'); $prev = $p; } ?>
    </div>
  </div>
</section>

<?php if ($g2): ?>
<section class="pr-group">
  <div class="container">
    <div class="pr-group-head">
      <p class="eyebrow">Grow</p>
      <h2>More capacity for a growing business</h2>
      <p>The same team as AI Lite, with more credits, more websites and room for your staff.</p>
    </div>
    <div class="pr-grid pr-grid-2">
      <?php $prev = $bySlug['ai-lite'] ?? null; foreach ($g2 as $p) { echo $card($p, $prev, 'wide'); $prev = $p; } ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($ag): ?>
<section class="pr-group" id="agency">
  <div class="container">
    <div class="pr-feature pr-agency">
      <div class="pr-feature-copy">
        <p class="eyebrow">For agencies</p>
        <h2>Run fifty client businesses from one account</h2>
        <p class="pr-feature-lede">Give every client a website, a chatbot and a marketing team, without hiring one. Sarah and her specialists work on each client business in its own brand, and you approve the work before the client sees it.</p>
        <ul class="pr-benefits">
          <li><?= icon('layers', 20) ?><span><b>Multi-business profile management</b><?= e($sites($ag)) ?> websites and businesses; each client keeps its own brand profile, website, contacts and calendar.</span></li>
          <li><?= icon('shield', 20) ?><span><b>One login, every client</b>Switch between client businesses in one account; nothing reaches a client without your approval.</span></li>
          <li><?= icon('users', 20) ?><span><b><?= e($users($ag)) ?> users</b>Bring in your team and your clients, each with their own login and role.</span></li>
          <li><?= icon('message', 20) ?><span><b>A chatbot for every client</b><?= $chat($ag) === 'Unlimited' ? 'Unlimited messages' : e($chat($ag)) ?> across your client websites.</span></li>
          <li><?= icon('zap', 20) ?><span><b><?= $fmtNum($ag['credits_per_month']) ?> credits a month</b>One pool for all your clients, with priority processing.</span></li>
          <li><?= icon('bot', 20) ?><span><b>The companion app</b>Approve client work and answer leads from your phone.</span></li>
        </ul>
      </div>
      <div class="pr-feature-side">
        <article class="plan pr-card pr-side featured" id="<?= e($ag['slug']) ?>">
          <h3 class="pr-name"><?= e($ag['name']) ?></h3>
          <div class="pr-price"><span class="amount"><?= money($ag['price_monthly']) ?></span><small>/month</small></div>
          <dl class="pr-specs">
            <div><dt>AI credits a month</dt><dd><?= e($credits($ag)) ?></dd></div>
            <div><dt>Websites and businesses</dt><dd><?= e($sites($ag)) ?></dd></div>
            <div><dt>Users</dt><dd><?= e($users($ag)) ?></dd></div>
            <div class="is-chat"><dt>Website chatbot</dt><dd><?= e($chat($ag)) ?></dd></div>
          </dl>
          <a class="btn btn-primary pr-cta" href="<?= e(signup_href($data, $ag['slug'])) ?>"><?= e(cta_label($data)) ?></a>
          <a class="pr-more" href="/next/agencies/">How agencies use LevelUpGrowth <?= icon('arrow-right', 16) ?></a>
          <?php if ($ag['agents']['addon_price'] !== null): ?><p class="pr-addon"><?= e($team) ?> on every client. Extra specialist <?= money($ag['agents']['addon_price']) ?>/month</p><?php endif; ?>
        </article>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="pr-group" id="enterprise">
  <div class="container">
    <div class="pr-feature pr-custom pr-ent-wrap">
      <div class="pr-ent-top">
        <div class="pr-feature-copy">
          <p class="eyebrow">Enterprise</p>
          <h2>Enterprise AI, architected around your strategy</h2>
          <p class="pr-feature-lede">For the organisations that set the pace in their industry. We begin with your strategy, not a product: the goals, systems and data of every department, mapped and joined into one intelligent operating layer. AI runs end to end, from the boardroom dashboard to the front desk, governed to the standard your board and your regulators expect.</p>
          <ul class="pr-ent-proof">
            <li><b>Strategy first</b>Every build traces back to an objective your leadership has signed.</li>
            <li><b>End to end</b>One architecture across departments, not a tool per team.</li>
            <li><b>Yours</b>The systems, the data and the intellectual property belong to you.</li>
          </ul>
        </div>
        <div class="pr-feature-side">
          <div class="plan pr-card pr-side pr-ent">
            <h3 class="pr-name">Enterprise</h3>
            <div class="pr-price pr-price-word"><span class="amount">By engagement</span></div>
            <p class="pr-desc">A dedicated engagement team with one accountable lead, from strategy to steady state.</p>
            <ol class="pr-steps">
              <li><b>Strategy and discovery</b>Executive workshops and department interviews map goals, processes, systems and data.</li>
              <li><b>Architecture and roadmap</b>One blueprint, sequenced by business value and signed off by your leadership.</li>
              <li><b>Build and integrate</b>Delivered in stages and connected to the systems you run today.</li>
              <li><b>Operate and evolve</b>Monitored, measured and refined, with reporting to your executive team.</li>
            </ol>
            <a class="btn btn-primary pr-cta" href="/next/contact/?topic=enterprise">Request a private consultation</a>
            <p class="pr-addon">Every engagement is scoped individually and held in confidence.</p>
          </div>
        </div>
      </div>
      <div class="pr-depts-head">
        <p class="eyebrow">Department by department</p>
        <h3>One intelligent operating layer across the whole organisation</h3>
      </div>
      <ul class="pr-depts">
        <li><?= icon('chart', 20) ?><b>Executive office</b><span>Live KPIs across brands and regions, board-ready briefings written by AI, and decisions backed by one source of truth.</span></li>
        <li><?= icon('users', 20) ?><b>Sales</b><span>A CRM built around your process, pipelines and lead scoring, and proposals and RFP responses drafted in hours, not weeks.</span></li>
        <li><?= icon('share', 20) ?><b>Marketing and communications</b><span>Brand, content, search and AI-search visibility, press relations and media intelligence, run by an AI team to your voice.</span></li>
        <li><?= icon('message', 20) ?><b>Customer experience</b><span>An AI concierge on every channel, trained on your knowledge and handing over to your people at the right moment.</span></li>
        <li><?= icon('server', 20) ?><b>Operations</b><span>ERP, orders, inventory and procurement in one system, with the work between teams automated end to end.</span></li>
        <li><?= icon('layers', 20) ?><b>Finance</b><span>P&amp;L, budgets and forecasts by entity, brand or branch, with AI that explains every variance before the meeting.</span></li>
        <li><?= icon('building', 20) ?><b>People</b><span>Careers portals, applications captured and routed, onboarding and internal knowledge your teams can ask.</span></li>
        <li><?= icon('shield', 20) ?><b>Technology and governance</b><span>Single sign-on, role-based access, encrypted offsite backups, and AI with approval chains, budgets, kill switches and a full audit trail.</span></li>
      </ul>
    </div>
  </div>
</section>

<section class="pr-compare" id="compare">
  <div class="container">
    <h2>Compare every plan</h2>
    <p class="pr-swipe">Swipe the table to see every plan.</p>
    <div class="pr-tbl"><table class="pr-matrix">
      <thead><tr><th scope="col" class="pr-corner"><span class="sr">Feature</span></th><?php foreach ($plans as $p): ?><th scope="col"<?= $p['slug'] === $featured ? ' class="is-f"' : '' ?>><span class="pr-th-name"><?= e($p['name']) ?></span><span class="pr-th-price"><?= money($p['price_monthly']) ?>/mo</span></th><?php endforeach; ?></tr></thead>
      <?php foreach ($groups as $g => $rows): ?>
      <tbody>
        <tr class="pr-group-row"><th scope="rowgroup" colspan="<?= count($plans) + 1 ?>"><?= e($g) ?></th></tr>
        <?php foreach ($rows as [$label, $cell]): ?>
        <tr><th scope="row"><?= e($label) ?></th><?php foreach ($plans as $p): $v = (string) $cell($p); ?><td<?= $p['slug'] === $featured ? ' class="is-f"' : '' ?>><?= str_starts_with($v, '<') ? $v : e($v) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; ?>
    </table></div>
  </div>
</section>

<section class="pr-faq">
  <div class="container pr-faq-in">
    <div class="pr-faq-side">
      <h2>Questions before you choose</h2>
      <p>Anything else about plans or billing? <a href="/next/contact/?topic=billing">Write to us</a> and a person answers.</p>
    </div>
    <div class="faq">
      <?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pr-close">
  <div class="container">
    <div class="pr-close-in">
      <h2>Meet Sarah and her team, free for three days</h2>
      <p>50 credits, every AI feature including the website chatbot, no card. Choose a plan when you are ready.</p>
      <p class="pr-close-cta"><a class="btn btn-primary btn-lg" href="<?= e(signup_href($data)) ?>"><?= e(cta_label($data)) ?></a><a class="btn btn-secondary btn-lg" href="/next/free-trial/">How the free trial works</a></p>
    </div>
  </div>
</section>
