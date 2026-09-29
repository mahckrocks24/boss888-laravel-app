<?php
/** @var array $page */ /** @var array $data */
/*
 * Home — GLASS-HOME-2 (2026-09-30). The Liquid Glass home exactly as the Motion mockup v28
 * (claude.ai/artifact/DYHZ3BZcM2imQ3AtuVwxBS). Owner 2026-09-30: "build the website's homepage now using that exact design.
 * route accordingly", for staging.levelupgrowth.io and levelupgrowth.io.
 *
 * Desktop (from 901px): the glass hero — the brand headline (three fixed lines), the one glowing CTA, the SCROLL cue, and
 * on the right Arthur building a site while the team speaks up in a group thread — then the template strip, the loop,
 * Command Center, chatbot to lead, review queue and team, Build/Grow/Convert, pricing from the plans table, the close.
 * Phones (to 900px): the site's own hero-top as served (the Owner's brand layout), then the same panels; the chatbot
 * section shows only chatbot888. The site's header (floating as a glass bar on this page), footer and chatbot888 stay.
 *
 * Not carried over from the mockup: its header, footer, legend, sticky pill and placeholder orb. Not on this page any
 * more (the mockup is the exact design): Arthur's intake panel and the design gallery of the previous hero — the CTA routes
 * to the signup door, where Arthur's intake lives. Every link routes to a real page; every number comes from $data.
 */
$specialists = count(array_filter($data['agents'], fn ($a) => empty($a['is_dmm'])));
$page['title'] = '';
$page['description'] = 'Meet Sarah, the AI growth manager for your business. Tell her about it once. She keeps the context, plans the work, brings in the right specialist, shows you the cost, and waits for your approval before anything goes live.';
$plans = $data['plans'];
$byPlan = fn (string $slug) => array_values(array_filter($plans, fn ($p) => $p['slug'] === $slug))[0] ?? null;
$free = $byPlan('free'); $lite = $byPlan('ai-lite');
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'LevelUpGrowth', 'url' => 'https://levelupgrowth.io'];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], home_faq($data))];
$groups = [
  ['Build', 'globe', 'Website, landing pages, images and video.', [['Website builder', '/next/product/website-builder/'], ['Creative studio', '/next/product/creative/'], ['Domains', '/next/product/domains/']]],
  ['Grow',  'search', 'Search, articles, social and the numbers behind them.', [['SEO', '/next/product/seo/'], ['Content', '/next/product/content/'], ['Social', '/next/product/social/']]],
  ['Convert', 'message', 'Answer the visitor, capture the lead, keep the customer.', [['Chatbot', '/next/product/chatbot/'], ['CRM', '/next/product/crm/'], ['Calendar', '/next/product/calendar/']]],
];
// media carries a content hash so a re-encode can never be served from a stale edge
$mv = function (string $file): string { $p = dirname(__DIR__) . '/assets/product/' . $file; return '/next/assets/product/' . $file . (is_file($p) ? '?v=' . substr(md5_file($p), 0, 8) : ''); };
$mk = function (string $f): string { $p = dirname(__DIR__) . '/assets/mk/' . $f; return '/next/assets/mk/' . $f . (is_file($p) ? '?v=' . substr(md5_file($p), 0, 8) : ''); };
// the strip of sites Arthur built: one of each hero family in turn (light copy, form card, dark) so no two alike sit side by side (Owner 09-29)
$tpls = [['restaurant', 'Restaurant'], ['hotel', 'Hotel'], ['aesthetic_clinic', 'Aesthetic clinic'], ['gym', 'Gym'], ['dental', 'Dental'], ['cafe', 'Café'], ['resort', 'Resort'], ['interior_design', 'Interior design'], ['ecommerce', 'Shop'], ['architecture', 'Architecture'], ['catering', 'Catering'], ['consulting', 'Consulting']];
$tpls = array_values(array_filter($tpls, fn ($t) => is_file(dirname(__DIR__) . '/assets/product/templates/' . $t[0] . '.webp')));
$page['head'] = '<script>/* GLASS-HOME: the home follows the device (Owner 2026-09-29); the header floats as glass here */try{document.documentElement.classList.add("mk-home");var t=(window.matchMedia&&matchMedia("(prefers-color-scheme: light)").matches)?"light":"dark";/* device only (Owner 09-30: a stored lug_theme from another surface forced light on a dark phone) */if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}</script>' . "\n"
  . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">' . "\n"
  . '<link rel="stylesheet" href="' . e($mk('lug-glass.css')) . '">' . "\n"
  . '<link rel="stylesheet" href="' . e($mk('home.css')) . '">';
$page['scripts'] = '<script src="' . e($mk('motion.js')) . '" defer></script>' . "\n" . '<script src="' . e($mk('home.js')) . '" defer></script>';
$signup = e(signup_href($data));
$agentImg = fn (string $slug) => '/img/agents/' . $slug . '.webp';
$arrow = '<svg class="ic ic--sm" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
?>

<?php /* PHONES — the site's own hero-top, as served (Owner: "exactly the same as this"); hidden from 901px up */ ?>
<section class="hero-mr mk-phone-hero" id="top">
  <div class="container">
    <div class="hero-top">
    <span class="pill">An AI Growth Team working for You 24/7</span>
    <h1>Get&nbsp;found. Get&nbsp;booked.<br> <span class="grad">Level&nbsp;Up Your Business Today.</span></h1>
    <p class="hero-sub">It's time every business gets an agency-level marketing without spending thousands of dollars. Describe your business below and Arthur will build and launch your website today. Sarah will lead your SEO, Social Media, Emails and more.</p>
    <div class="hero-cta"><a class="btn-glow" href="<?= $signup ?>" data-lu-signup><span class="lbl-out">Level Up Now <?= icon('arrow-right', 16) ?></span><span class="lbl-in">Dashboard <?= icon('arrow-right', 16) ?></span></a></div>
    <button type="button" class="scroll-cue" aria-label="Scroll down" data-scroll-cue><span class="lbl">Scroll</span><span class="bar" aria-hidden="true"></span></button>
    </div>
  </div>
</section>

<div class="lg mk" id="glass-home">
  <div class="lg-aurora" aria-hidden="true"><i></i><i></i><i></i></div>
  <div id="mk-progress" aria-hidden="true"></div>
  <svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><linearGradient id="mkgrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8C25D2"></stop><stop offset=".55" stop-color="#4C86DE"></stop><stop offset="1" stop-color="#3FDFDF"></stop></linearGradient></defs></svg>

  <?php /* 01 — HERO: the brand headline and the one CTA; Arthur builds a site while the team speaks up (loops) */ ?>
  <section class="mk-sec" id="hero-glass" style="padding-top:150px;padding-bottom:56px">
    <div class="mk-wrap mk-hero">
      <div class="lg-stack gap-5 mk-hero__copy">
        <span class="lg-badge lg-badge--brand mk-pill" data-hero="1" style="align-self:flex-start;height:30px;padding:0 12px;border-radius:999px"><span class="lg-dot"></span>An AI Growth Team working for You 24/7</span>
        <h1 class="mk-h1" id="hero-h1">Get&nbsp;found.<br>Get&nbsp;booked. <span class="t-grad">Level&nbsp;Up<br>Your Business Today.</span></h1>
        <p class="mk-lead" data-hero="3">It's time every business gets an agency-level marketing without spending thousands of dollars. Describe your business below and Arthur will build and launch your website today. Sarah will lead your SEO, Social Media, Emails and more.</p>
        <div class="lg-row gap-3" data-hero="4" style="flex-wrap:wrap">
          <span class="mk-glow"><a class="lg-btn lg-btn--primary lg-btn--lg mk-btn mk-cta" href="<?= $signup ?>" data-lu-signup><span class="lbl-out">Level Up Now<?= $arrow ?></span><span class="lbl-in">Dashboard<?= $arrow ?></span></a></span>
        </div>
      </div>

      <div class="mk-hero__visual" id="hero-visual">
        <div class="lg-glass lg-glass--thick lg-stack mk-chat" id="hx" data-hero-v="1">
          <div class="lg-row gap-3" style="padding:14px 16px;border-bottom:1px solid var(--hairline)">
            <span class="lg-avatar lg-presence" style="width:36px;height:36px;background:var(--fill-hover)"><img src="/img/logo-icon-40.png" alt="" width="22" height="22" style="width:22px;height:22px"></span>
            <div class="lg-stack"><span class="t-subhead t-strong">Arthur</span><span class="t-caption c-3">AI website builder</span></div>
          </div>
          <div class="lg-stack gap-2" style="padding:14px;min-height:250px">
            <div class="lg-bubble" style="align-self:flex-start;max-width:92%;font-size:14px;line-height:20px">Hi! Tell me about your business and I'll build your website today.</div>
            <div class="lg-bubble lg-bubble--me" id="hx-ask" style="align-self:flex-end;max-width:92%;font-size:14px;line-height:20px">A neighbourhood restaurant in Norwich. Twelve dishes, seasonal menu, private hire.</div>
            <div class="lg-typing lg-glass lg-glass--thin" id="hx-typing" hidden style="align-self:flex-start"><i></i><i></i><i></i></div>
            <div class="lg-bubble" id="hx-reply" style="align-self:flex-start;max-width:92%;font-size:14px;line-height:20px">On it. Black Door: candlelit, the menu and booking up front. Building now.</div>
            <span class="lg-event lg-glass lg-glass--thin" id="hx-live"><svg class="ic ic--sm" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg><span class="t-caption">Live at blackdoor.levelupgrowth.io</span></span>
          </div>
          <div class="lg-row gap-2" style="padding:10px 12px;border-top:1px solid var(--hairline)">
            <div class="lg-composer__field lg-glass lg-glass--thick" style="min-height:40px"><span class="lg-composer__input" style="font-size:14px"><span id="hx-typed"></span><span class="mk-caret" id="hx-caret" hidden></span></span><span class="lg-btn lg-btn--primary lg-btn--icon lg-btn--sm" aria-hidden="true"><svg class="ic ic--sm" viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg></span></div>
          </div>
          <span class="t-caption c-3" style="text-align:center;padding-bottom:8px">Arthur is AI and can make mistakes.</span>
        </div>

        <div class="mk-frame mk-site" data-hero-v="2">
          <div class="mk-frame__bar"><i></i><i></i><i></i><span class="mk-frame__url">blackdoor.levelupgrowth.io</span></div>
          <div class="mk-reveal" style="height:275px;background:var(--fill-hover)">
            <img src="<?= e($mv('templates/restaurant.webp')) ?>" id="hx-img" alt="Black Door restaurant website" width="1440" height="900" style="height:275px;width:100%;object-fit:cover;object-position:top" decoding="async">
            <div class="mk-skel" id="hx-skel" hidden><div class="lg-skel" style="height:14px;width:40%"></div><div class="lg-skel" style="height:90px"></div><div class="lg-skel" style="height:14px;width:60%"></div><div class="lg-skel" style="height:14px;width:50%"></div></div>
          </div>
          <div style="padding:10px 12px;border-top:1px solid var(--hairline)" class="lg-stack gap-2">
            <div class="lg-row lg-between"><span class="t-caption c-2" id="hx-stage">Published</span><span class="t-caption t-num c-3"><span id="hx-pct">100</span>%</span></div>
            <div class="lg-meter"><i id="hx-meter" style="width:100%;transform-origin:left"></i></div>
          </div>
        </div>

        <div class="hx-frost" id="hx-frost" aria-hidden="true"></div>
        <div class="hx-agents" id="hx-agents">
          <div class="hx-say" data-agent="sarah"><span class="hx-say__av lg-presence"><img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="" width="36" height="36"></span><div class="hx-say__body"><div class="hx-say__bubble"><span class="hx-say__who"><b>Sarah</b> · Digital marketing manager</span>Hi Boss, I'm Sarah, your Digital Marketing Manager. Let's start your growth!</div></div></div>
          <div class="hx-say" data-agent="james"><span class="hx-say__av lg-presence"><img class="lg-avatar" src="<?= e($agentImg('james')) ?>" alt="" width="36" height="36"></span><div class="hx-say__body"><div class="hx-say__bubble"><span class="hx-say__who"><b>James</b> · SEO strategist</span>I'll run an SEO audit on the new site now.</div></div></div>
          <div class="hx-say" data-agent="priya"><span class="hx-say__av lg-presence"><img class="lg-avatar" src="<?= e($agentImg('priya')) ?>" alt="" width="36" height="36"></span><div class="hx-say__body"><div class="hx-say__bubble"><span class="hx-say__who"><b>Priya</b> · Content manager</span>Boss, I'll write one article for you targeting your desired keywords.</div></div></div>
          <div class="hx-say" data-agent="marcus"><span class="hx-say__av lg-presence"><img class="lg-avatar" src="<?= e($agentImg('marcus')) ?>" alt="" width="36" height="36"></span><div class="hx-say__body"><div class="hx-say__bubble"><span class="hx-say__who"><b>Marcus</b> · Social media manager</span>Boss, I will prepare a 1 week social media campaign for you.</div></div></div>
        </div>
      </div>
    </div>
    <button class="mk-scroll" data-hero="6" type="button" aria-label="Scroll down"><span>Scroll</span><i></i></button>
  </section>

  <?php /* 02 — SITES ARTHUR BUILT glide past. Owner rules: never the word "template" (Arthur builds from scratch, we are just fast), no count, no link. */ ?>
  <section class="mk-tpls" id="tpls">
    <div class="mk-wrap lg-row lg-between" style="margin-bottom:18px;gap:12px;flex-wrap:wrap"><span class="t-eyebrow" data-rv>Websites Arthur builds, for every kind of business</span></div>
    <div class="mk-glide-wrap">
      <div class="mk-glide" id="mk-glide">
        <?php foreach ([false, true] as $copy): foreach ($tpls as [$slug, $name]): ?>
        <figure class="lg-glass mk-tpl"<?= $copy ? ' aria-hidden="true"' : '' ?>><img src="<?= e($mv('templates/' . $slug . '.webp')) ?>" alt="<?= $copy ? '' : e($name) ?>" width="1440" height="900" loading="lazy" decoding="async"><figcaption class="t-caption c-2" style="padding:10px 12px"><?= e($name) ?></figcaption></figure>
        <?php endforeach; endforeach; ?>
      </div>
    </div>
  </section>

  <?php /* 03 — THE LOOP */ ?>
  <section class="mk-sec" id="loop" style="padding-top:56px">
    <div class="mk-wrap lg-stack" style="gap:28px">
      <div class="lg-row lg-between" style="align-items:flex-end;gap:32px;flex-wrap:wrap">
        <div class="lg-stack gap-3" style="max-width:640px">
          <span class="t-eyebrow" data-rv>Who does it</span>
          <h2 class="mk-h2" data-rv>Sarah runs it. You approve it.</h2>
          <p class="mk-lead" data-rv>Tell her about the business once. She plans, prices each job, hands it to the right specialist and brings the finished work to you before any of it goes live. Watch one go round.</p>
        </div>
        <div class="lg-row gap-3" data-rv><span class="lg-presence" style="display:inline-flex"><img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="Sarah" width="64" height="64" style="width:64px;height:64px"></span><div class="lg-stack"><span class="t-callout t-strong">Sarah</span><span class="t-footnote c-3">Digital marketing manager</span></div></div>
      </div>
      <div class="mk-steps" id="lp">
        <div class="lg-glass mk-step lg-stack gap-1" data-rv data-step="0"><span class="mk-num">01 · YOU</span><span class="t-title3">Ask</span><div class="mk-mini"><span class="t-caption c-3">In Sarah's chat</span><div style="margin-top:6px">"<span id="lp-typed">Can we put something on the site about cherry blossom season in Japan?</span><span class="mk-caret" id="lp-caret" hidden style="height:13px"></span></div></div></div>
        <div class="lg-glass mk-step lg-stack gap-1" data-rv data-step="1"><span class="mk-num">02 · SARAH</span><span class="t-title3">Plans and prices it</span><div class="mk-mini"><div class="lg-stack gap-2" id="lp-s2"><div class="lg-row lg-between"><span class="t-subhead t-strong">Article · cherry blossom</span><span class="lg-badge lg-badge--info">Queued</span></div><div class="lg-row gap-2"><img class="lg-avatar" src="<?= e($agentImg('priya')) ?>" alt="" width="22" height="22" style="width:22px;height:22px"><span class="t-caption c-2">Priya · 2 credits</span></div></div><span class="t-caption c-3" id="lp-s2-off" hidden>Waiting for the ask</span></div></div>
        <div class="lg-glass mk-step lg-stack gap-1" data-rv data-step="2"><span class="mk-num">03 · PRIYA</span><span class="t-title3">Produces it</span><div class="mk-mini"><div class="lg-stack gap-1" id="lp-s3"><div class="lg-row lg-between"><span class="t-caption c-3">Words</span><span class="t-subhead t-strong t-num" id="lp-words">1,016</span></div><div class="lg-meter"><i id="lp-meter" style="width:100%;transform-origin:left"></i></div><span class="t-caption c-2">Meta description · featured image · answer-engine markup</span></div><span class="t-caption c-3" id="lp-s3-off" hidden>Not started</span></div></div>
        <div class="lg-glass mk-step lg-stack gap-1" data-rv data-step="3"><span class="mk-num">04 · YOU</span><span class="t-title3">Approve</span><div class="mk-mini"><div class="lg-stack gap-2" id="lp-s4"><div class="lg-row lg-between"><span class="t-subhead t-strong">Write article</span><span class="t-caption c-3">2 credits</span></div><div class="lg-row gap-2"><span id="lp-s4-pending" hidden style="display:inline-flex;gap:8px"><span style="position:relative;display:inline-flex"><span class="mk-pulse"></span><span class="lg-btn lg-btn--primary lg-btn--sm" style="height:30px">Approve</span></span><span class="lg-btn lg-btn--glass lg-btn--sm" style="height:30px">Not now</span></span><span class="lg-badge lg-badge--success" id="lp-s4-ok"><svg class="ic ic--sm" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>Approved</span></div></div><span class="t-caption c-3" id="lp-s4-off" hidden>Nothing to approve yet</span></div></div>
        <div class="lg-glass mk-step lg-stack gap-1" data-rv data-step="4"><span class="mk-num">05 · LIVE</span><span class="t-title3">Published</span><div class="mk-mini"><div class="lg-row gap-3" id="lp-s5"><svg class="mk-ring" width="58" height="58" viewBox="0 0 58 58"><circle class="bg" cx="29" cy="29" r="25"></circle><circle class="fg" id="lp-ring" cx="29" cy="29" r="25" stroke-dasharray="157" stroke-dashoffset="15.7"></circle></svg><div class="lg-stack"><span class="t-title3 t-num" style="margin:0"><span id="lp-score">90</span><span class="t-caption c-3">/100</span></span><span class="t-caption c-2">answer engines</span></div></div><span class="t-caption c-3" id="lp-s5-off" hidden>Goes live after your yes</span></div></div>
      </div>
      <div class="mk-rail" data-rv><i id="lp-rail"></i></div>
    </div>
  </section>

  <?php /* 04 — COMMAND CENTER */ ?>
  <section class="mk-sec" id="cc" style="padding-top:40px">
    <div class="mk-wrap lg-stack" style="gap:28px">
      <div class="lg-stack gap-3" style="max-width:720px"><span class="t-eyebrow" data-rv>Numbers you can check</span><h2 class="mk-h2" data-rv>Every result is measured, never guessed.</h2><p class="mk-lead" data-rv>This is the Command Center, the screen you land on. Where a number needs a connection you have not made, it says so instead of guessing.</p></div>
      <div class="lg-glass lg-glass--thick mk-app" data-rv>
        <aside class="mk-side">
          <div class="lg-row gap-2" style="padding:4px 6px 12px"><img src="/img/logo-icon-40.png" alt="" width="22" height="22" style="width:22px;height:22px"><span class="t-subhead t-strong">LevelUpGrowth</span></div>
          <div class="lg-seg lg-seg--block" style="margin-bottom:8px"><span class="lg-seg__item" style="height:28px;font-size:12px">Basic</span><span class="lg-seg__item is-on" style="height:28px;font-size:12px">Advanced</span></div>
          <div class="mk-nav"><img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="" width="22" height="22" style="width:22px;height:22px">Sarah</div>
          <span class="t-eyebrow" style="padding:10px 12px 4px">Workspace</span>
          <div class="mk-nav is-on">Command Center</div><div class="mk-nav">Strategy Room</div><div class="mk-nav">Campaigns</div><div class="mk-nav">Review Queue<span class="lg-count" style="margin-left:auto">2</span></div>
          <span class="t-eyebrow" style="padding:10px 12px 4px">Engines</span>
          <div class="mk-nav">Clients</div><div class="mk-nav">SEO</div><div class="mk-nav">Write</div><div class="mk-nav">Social</div>
        </aside>
        <div class="mk-main">
          <div class="lg-row lg-between" style="gap:12px;flex-wrap:wrap"><div class="lg-stack"><span class="t-title2">Good morning, Owner.</span><span class="t-footnote c-3">Tuesday 29 September · 7 of <?= (int) count($data['agents']) ?> agents on this workspace</span></div><span class="lg-badge lg-badge--success" style="height:30px;padding:0 12px"><span class="lg-dot"></span>LIVE</span></div>
          <div class="lg-grid-4" style="gap:12px">
            <div class="lg-glass lg-card lg-stat lg-card--lift" style="padding:14px 16px"><span class="t-eyebrow">Tasks done</span><span class="lg-stat__value" data-count="51">51</span><span class="t-caption c-2">+10 this week</span></div>
            <div class="lg-glass lg-card lg-stat lg-card--lift" style="padding:14px 16px"><span class="t-eyebrow">Content published</span><span class="lg-stat__value" data-count="2">2</span><span class="t-caption c-2">1 in draft</span></div>
            <div class="lg-glass lg-card lg-stat lg-card--lift" style="padding:14px 16px"><span class="t-eyebrow">Leads captured</span><span class="lg-stat__value" data-count="34">34</span><span class="t-caption"><span class="t-strong" style="color:var(--success)">+19</span><span class="c-2"> this week</span></span></div>
            <div class="lg-glass lg-card lg-stat lg-card--lift" style="padding:14px 16px"><span class="t-eyebrow">Keywords tracked</span><span class="lg-stat__value" data-count="8">8</span><span class="t-caption c-2">James checks them daily</span></div>
          </div>
          <div class="lg-row" data-cc-row style="gap:12px;align-items:stretch">
            <div class="lg-glass lg-card lg-stack" style="flex:1.3;min-width:0;padding:14px 16px 6px;gap:0">
              <div class="lg-row lg-between" style="margin-bottom:6px"><span class="t-eyebrow">Your team right now</span><span class="t-caption c-3"><span id="cc-count">6</span> events</span></div>
              <div id="cc-feed">
                <div class="lg-row-item cc-evt" style="padding-inline:0;min-height:50px"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('elena')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead">Elena captured a new lead</span><span class="t-caption c-3">just now</span></div></div>
                <div class="lg-row-item cc-evt" style="padding-inline:0;min-height:50px"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('marcus')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead">Marcus scheduled Friday's post</span><span class="t-caption c-3">2 minutes ago</span></div></div>
                <div class="lg-row-item cc-evt" style="padding-inline:0;min-height:50px"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('priya')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead">Priya finished: Autumn menu article</span><span class="t-caption c-3">18 minutes ago</span></div></div>
                <div class="lg-row-item cc-evt" style="padding-inline:0;min-height:50px"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('james')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead">James audited 12 pages</span><span class="t-caption c-3">1 hour ago</span></div></div>
                <div class="lg-row-item cc-evt" style="padding-inline:0;min-height:50px"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('sarah')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead">Sarah queued: Private hire landing page</span><span class="t-caption c-3">2 hours ago</span></div></div>
                <div class="lg-row-item cc-evt" style="padding-inline:0;min-height:50px"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('maya')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead">Maya drafted three captions</span><span class="t-caption c-3">3 hours ago</span></div></div>
              </div>
            </div>
            <div class="lg-glass lg-card lg-stack gap-3" style="flex:1;min-width:0;padding:14px 16px">
              <div class="lg-row lg-between"><span class="t-eyebrow">Needs your approval</span><span class="lg-count" id="cc-pending">2</span></div>
              <div class="lg-glass lg-glass--thin lg-stack gap-2" id="cc-approval" style="padding:12px;border-radius:16px"><div class="lg-row gap-2"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('marcus')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead t-strong">Friday's post · social</span><span class="t-caption c-3">Marcus · 4 credits</span></div></div><div class="lg-row gap-2"><span class="lg-btn lg-btn--primary lg-btn--sm lg-grow">Approve</span><span class="lg-btn lg-btn--glass lg-btn--sm lg-grow">Not now</span></div></div>
              <div class="lg-glass lg-glass--thin lg-stack gap-2" id="cc-approval-2" style="padding:12px;border-radius:16px"><div class="lg-row gap-2"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('priya')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead t-strong">Private hire page · write</span><span class="t-caption c-3">Priya · 2 credits</span></div></div><div class="lg-row gap-2"><span class="lg-btn lg-btn--primary lg-btn--sm lg-grow">Approve</span><span class="lg-btn lg-btn--glass lg-btn--sm lg-grow">Not now</span></div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php /* 05 — CHATBOT to lead (phones: only the chatbot888 interface, Owner 09-29) */ ?>
  <section class="mk-sec" id="bot" style="padding-top:40px">
    <div class="mk-wrap mk-two">
      <div class="lg-stack gap-4 mk-two__copy">
        <span class="t-eyebrow" data-rv>It sells while you sleep</span>
        <h2 class="mk-h2" data-rv>A visitor at 11pm is a lead by morning.</h2>
        <p class="mk-lead" data-rv style="font-size:17px;line-height:27px">The chatbot is grounded in your own pages, so it answers about your business, asks where and when, and Elena writes the lead into your CRM.</p>
        <div class="lg-glass lg-glass--thick lg-stack gap-2" data-rv style="padding:14px 16px;border-radius:18px;min-height:96px">
          <div class="lg-row lg-between"><span class="t-eyebrow">Clients</span><span class="t-caption c-3">11:04 pm</span></div>
          <div class="lg-row gap-3" id="bt-lead"><span class="lg-avatar" style="width:36px;height:36px;background:var(--success-soft);color:var(--success);font:700 14px/1 var(--font)">E</span><div class="lg-stack lg-grow"><span class="t-subhead t-strong">Emma · 07700 900412</span><span class="t-caption c-2">Japan honeymoon, two weeks in April</span></div><span class="lg-badge lg-badge--success">New lead</span></div>
          <span class="t-caption c-3" id="bt-nolead" hidden>Listening on saltmarsh.levelupgrowth.io…</span>
        </div>
      </div>
      <div class="mk-frame lg-grow mk-frame--travel" data-rv style="min-width:0;height:470px">
        <div class="mk-frame__bar"><i></i><i></i><i></i><span class="mk-frame__url">saltmarsh.levelupgrowth.io</span></div>
        <img src="<?= e($mv('templates/resort.webp')) ?>" alt="Saltmarsh Travel website" width="1440" height="900" style="height:436px;object-fit:cover;object-position:top" loading="lazy" decoding="async">
        <div class="lg-glass lg-glass--thick lg-stack mk-bot" id="bt">
          <div class="lg-row lg-between" style="padding:10px 14px;background:var(--brand-grad);color:#fff"><span class="t-subhead t-strong" style="color:#fff">Chat with us</span><span class="t-caption" style="color:#fff;opacity:.85">Saltmarsh Travel</span></div>
          <div class="lg-stack gap-2" style="padding:12px;min-height:190px">
            <div class="lg-bubble" style="align-self:flex-start;max-width:92%;font-size:13px;line-height:18px;padding:8px 12px">Hi! Tell me where you fancy going and I'll help you plan it.</div>
            <div class="lg-bubble lg-bubble--me bt-msg" style="align-self:flex-end;max-width:92%;font-size:13px;line-height:18px;padding:8px 12px">Looking for a honeymoon in Japan. Any ideas?</div>
            <div class="lg-typing lg-glass lg-glass--thin" id="bt-typing" hidden style="align-self:flex-start;padding:11px 12px"><i></i><i></i><i></i></div>
            <div class="lg-bubble bt-msg" style="align-self:flex-start;max-width:92%;font-size:13px;line-height:18px;padding:8px 12px">Lovely choice. Our Tokyo to Kyoto honeymoon is built around ryokans and cherry blossom. Shall a consultant send you a quote? A name and number is all I need.</div>
            <div class="lg-bubble lg-bubble--me bt-msg" style="align-self:flex-end;max-width:92%;font-size:13px;line-height:18px;padding:8px 12px">Yes please. Emma, 07700 900412.</div>
            <div class="lg-bubble bt-msg" style="align-self:flex-start;max-width:92%;font-size:13px;line-height:18px;padding:8px 12px">Thanks Emma. Aiko will call you tomorrow morning.</div>
          </div>
          <div class="lg-row gap-2" style="padding:8px 10px;border-top:1px solid var(--hairline)"><div class="lg-composer__field lg-glass lg-glass--thick" style="min-height:34px"><span class="lg-composer__input" style="font-size:13px"><span id="bt-typed"></span><span class="mk-caret" id="bt-caret" hidden style="height:13px"></span></span></div></div>
        </div>
      </div>
    </div>
  </section>

  <?php /* 06 — REVIEW QUEUE + TEAM */ ?>
  <section class="mk-sec" id="team" style="padding-top:40px">
    <div class="mk-wrap mk-cards2">
      <div class="lg-glass lg-card lg-stack gap-3" data-rv style="padding:28px">
        <span class="t-eyebrow">Nothing ships without you</span>
        <h3 class="t-title1" style="margin:0">AI when you want it. Control when you need it.</h3>
        <p class="t-body c-2" style="margin:0">Every finished piece waits in one queue with who did it and what it cost. Approve it, or don't.</p>
        <div class="lg-glass lg-glass--thick lg-stack" id="rq" style="margin-top:6px;border-radius:18px;padding:14px;gap:10px;min-height:280px">
          <div class="lg-row lg-between"><span class="t-subhead t-strong">Review queue</span><span class="lg-badge lg-badge--info"><span id="rq-pending">3</span> pending</span></div>
          <div class="lg-glass lg-glass--thin lg-row gap-3 rq-card" style="padding:10px 12px;border-radius:14px;overflow:hidden"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('priya')) ?>" alt="" width="32" height="32"><div class="lg-stack lg-grow"><span class="t-subhead t-strong">Write article · cherry blossom</span><span class="t-caption c-3">Priya · 2 credits</span></div><span style="position:relative;display:inline-flex"><span class="mk-pulse rq-pulse" hidden></span><span class="lg-btn lg-btn--primary lg-btn--sm rq-approve" style="height:30px">Approve</span></span></div>
          <div class="lg-glass lg-glass--thin lg-row gap-3 rq-card" style="padding:10px 12px;border-radius:14px;overflow:hidden"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('marcus')) ?>" alt="" width="32" height="32"><div class="lg-stack lg-grow"><span class="t-subhead t-strong">Friday's post · social</span><span class="t-caption c-3">Marcus · 4 credits</span></div><span style="position:relative;display:inline-flex"><span class="mk-pulse rq-pulse" hidden></span><span class="lg-btn lg-btn--primary lg-btn--sm rq-approve" style="height:30px">Approve</span></span></div>
          <div class="lg-glass lg-glass--thin lg-row gap-3 rq-card" style="padding:10px 12px;border-radius:14px;overflow:hidden"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('sofia')) ?>" alt="" width="32" height="32"><div class="lg-stack lg-grow"><span class="t-subhead t-strong">Spanish landing page · SEO</span><span class="t-caption c-3">Sofia · 2 credits</span></div><span style="position:relative;display:inline-flex"><span class="mk-pulse rq-pulse" hidden></span><span class="lg-btn lg-btn--primary lg-btn--sm rq-approve" style="height:30px">Approve</span></span></div>
          <div class="lg-toast lg-glass lg-glass--thick" id="rq-clear" hidden style="align-self:center"><span class="lg-toast__icon"><svg class="ic ic--sm" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></span><span class="t-subhead t-strong">Nothing waiting on you</span></div>
        </div>
      </div>
      <div class="lg-glass lg-card lg-stack gap-3" data-rv style="padding:28px">
        <span class="t-eyebrow">She is not doing it alone</span>
        <h3 class="t-title1" style="margin:0">Sarah brings in the right specialist.</h3>
        <p class="t-body c-2" style="margin:0"><?= (int) $specialists ?> specialists, one context, one approval queue. You only ever talk to Sarah.</p>
        <div class="lg-stack gap-2" id="tm" style="margin-top:6px">
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">Sarah</span><span class="t-caption c-3">Digital marketing manager</span></div><span class="mk-status" data-status="Planning October|Pricing a campaign|Reviewing James's audit|Briefing Priya"><i></i><span>Planning October</span></span></div>
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('priya')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">Priya</span><span class="t-caption c-3">Content manager</span></div><span class="mk-status" data-status="Writing: cherry blossom guide|Fixing 3 meta descriptions|Drafting the menu page|Idle"><i></i><span>Writing: cherry blossom guide</span></span></div>
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('james')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">James</span><span class="t-caption c-3">SEO strategist</span></div><span class="mk-status" data-status="Auditing 12 pages|Tracking 8 keywords|Checking answer engines|Idle"><i></i><span>Auditing 12 pages</span></span></div>
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('marcus')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">Marcus</span><span class="t-caption c-3">Social media manager</span></div><span class="mk-status" data-status="Scheduling Friday 9:00|Drafting a carousel|Idle|Reading the week's results"><i></i><span>Scheduling Friday 9:00</span></span></div>
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('elena')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">Elena</span><span class="t-caption c-3">Lead and CRM manager</span></div><span class="mk-status" data-status="Logging Emma's enquiry|Idle|Reminder: call Priya Raman|Cleaning duplicates"><i></i><span>Logging Emma's enquiry</span></span></div>
        </div>
        <a class="t-footnote t-strong c-accent" href="/next/product/ai-workforce/" style="text-decoration:none">Meet the team</a>
      </div>
    </div>
  </section>

  <?php /* 07 — BUILD GROW CONVERT, from the same $groups the old page used */ ?>
  <section class="mk-sec" id="does" style="padding-top:40px">
    <div class="mk-wrap lg-stack" style="gap:24px">
      <div class="lg-stack gap-3"><span class="t-eyebrow" data-rv>What gets done</span><h2 class="mk-h2" data-rv>Build. Grow. Convert.</h2></div>
      <div class="lg-grid-4" style="gap:16px">
        <?php foreach ($groups as [$name, $ico, $line, $links]): ?>
        <div class="lg-glass lg-card lg-stack gap-2 lg-card--lift" data-rv style="padding:22px"><span class="t-title2"><?= e($name) ?></span><span class="t-footnote c-2"><?= e($line) ?></span><span class="t-footnote t-strong" style="display:flex;flex-wrap:wrap;gap:4px 10px"><?php foreach ($links as [$label, $href]): ?><a href="<?= e($href) ?>" style="color:inherit"><?= e($label) ?></a><?php endforeach; ?></span></div>
        <?php endforeach; ?>
        <div class="lg-glass lg-glass--thick lg-card lg-stack gap-2 lg-card--lift" data-rv style="padding:22px"><span class="t-title2">Yours</span><span class="t-footnote c-2">Your domain registered to you, your customers exportable any day, your content on your site.</span><a class="t-footnote t-strong c-accent" href="/next/security/">How your data is held</a></div>
      </div>
    </div>
  </section>

  <?php /* 08 — PRICING, from the plans table */ ?>
  <section class="mk-sec" id="price" style="padding-top:40px">
    <div class="mk-wrap mk-price">
      <div class="lg-stack gap-3" style="flex:1 1 0;min-width:0"><span class="t-eyebrow" data-rv>Pricing</span><h2 class="mk-h2" data-rv>Start free. Add Sarah when you are ready.</h2><a class="t-footnote t-strong c-accent" href="/next/pricing/" data-rv>Every plan and what is in it</a></div>
      <div class="mk-price__cards">
        <?php foreach (array_filter([$free, $lite]) as $p): $featured = $p['slug'] === 'ai-lite'; ?>
        <div class="lg-glass<?= $featured ? ' lg-glass--thick' : '' ?> lg-card lg-stack gap-3 lg-card--lift" data-rv<?= $featured ? ' id="price-featured"' : '' ?> style="padding:24px<?= $featured ? ';box-shadow:inset 0 0 0 1.5px var(--accent-text),0 12px 32px rgba(40,20,120,.14)' : '' ?>">
          <div class="lg-row lg-between"><span class="t-title3"><?= e($p['name']) ?></span><?php if ($featured): ?><span class="lg-badge lg-badge--brand">Adds Sarah</span><?php endif; ?></div>
          <span><span class="mk-h2" style="font-size:40px"><?= money($p['price_monthly']) ?></span><span class="t-subhead c-3"> /month</span></span>
          <span class="t-subhead c-2" style="font-weight:400"><?= e(plan_summary($p)) ?></span>
          <a class="lg-btn <?= $featured ? 'lg-btn--primary' : 'lg-btn--glass' ?> lg-btn--block mk-btn" href="/next/pricing/#<?= e($p['slug']) ?>" style="margin-top:auto">See the <?= e($p['name']) ?> plan</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <?php /* 09 — CLOSING */ ?>
  <section class="mk-sec" id="start" style="padding-top:40px;padding-bottom:64px">
    <div class="mk-wrap"><div class="lg-glass lg-glass--thick lg-stack gap-4" data-rv style="border-radius:32px;padding:clamp(28px,5vw,56px);align-items:center;text-align:center;position:relative;overflow:hidden">
      <img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="" width="72" height="72" style="width:72px;height:72px">
      <h2 class="mk-h2">Give Sarah your business.</h2>
      <p class="mk-lead" style="text-align:center">She will tell you what she would do first.</p>
      <div class="lg-row gap-3" style="flex-wrap:wrap;justify-content:center"><a class="lg-btn lg-btn--primary lg-btn--lg mk-btn" href="<?= $signup ?>" data-lu-signup id="cta-main" style="position:relative;overflow:hidden"><span class="lbl-out"><?= e(cta_label($data)) ?></span><span class="lbl-in">Dashboard</span><span id="cta-shine" aria-hidden="true" style="position:absolute;inset:0;background:linear-gradient(105deg,transparent 30%,rgba(255,255,255,.45) 50%,transparent 70%);transform:translateX(-120%);pointer-events:none"></span></a><a class="lg-btn lg-btn--glass lg-btn--lg mk-btn" href="/next/pricing/">See pricing</a></div>
    </div></div>
  </section>
</div>
