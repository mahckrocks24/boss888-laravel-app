<?php
/** @var array $page */ /** @var array $data */
/*
 * Home — rebuilt 2026-09-08 on the Owner's structural review.
 *
 * What was wrong: the page had three competing identities in the first three lines (an OS, a website builder, a
 * 19-agent workforce), then became a product tour — twelve sections of equal weight, each a feature with a
 * screenshot, in build order rather than in the order a visitor asks questions. The agent count was doing the work
 * a benefit should do, the workforce roster made people read an org chart before they understood the product, and
 * two demo businesses fought each other across the page.
 *
 * What this is: one business (Saltmarsh Travel), one organising idea (Sarah is your growth manager), and nine
 * scenes in persuasion order — meet her, watch the loop, watch her build, check the numbers, meet the team behind
 * her, see the range, see the control, see what you own, then price and close.
 */
$sc = require dirname(__DIR__) . '/showcase-data.php';
$sr = require dirname(__DIR__) . '/seo-data.php';
$jf = dirname(__DIR__) . '/journey-data.php';
$j  = is_file($jf) ? require $jf : [];
$specialists = count(array_filter($data['agents'], fn ($a) => empty($a['is_dmm'])));
$page['title'] = '';
$page['description'] = 'Meet Sarah, the AI growth manager for your business. Tell her about it once. She keeps the context, plans the work, brings in the right specialist, shows you the cost, and waits for your approval before anything goes live.';
$plans = $data['plans'];
$byPlan = fn (string $slug) => array_values(array_filter($plans, fn ($p) => $p['slug'] === $slug))[0] ?? null;
$free = $byPlan('free'); $lite = $byPlan('ai-lite');
$lead = $sc['sites'][0];   // the fastest recorded build, used in the hero facts
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'LevelUpGrowth', 'url' => 'https://levelupgrowth.io'];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], home_faq($data))];
$groups = [
  ['Build', 'globe', 'Website, landing pages, images and video.', [['Website builder', '/next/product/website-builder/'], ['Creative studio', '/next/product/creative/'], ['Domains', '/next/product/domains/']]],
  ['Grow',  'search', 'Search, articles, social and the numbers behind them.', [['SEO', '/next/product/seo/'], ['Content', '/next/product/content/'], ['Social', '/next/product/social/']]],
  ['Convert', 'message', 'Answer the visitor, capture the lead, keep the customer.', [['Chatbot', '/next/product/chatbot/'], ['CRM', '/next/product/crm/'], ['Calendar', '/next/product/calendar/']]],
];
?>

<?php /* 01 — Arthur, in the hero. The visitor describes the business, then signs up, then presses build. */ ?>
<section class="hero-mr" id="top">
  <div class="container">
    <div class="hero-top">
    <span class="pill">An AI Growth Team working for You 24/7</span>
    <h1>Get&nbsp;found. Get&nbsp;booked.<br> <span class="grad">Level&nbsp;Up Your Business Today.</span></h1>
    <p class="hero-sub">It's time every business gets an agency-level marketing without spending thousands of dollars. Describe your business below and Arthur will build and launch your website today. Sarah will lead your SEO, Social Media, Emails and more.</p>
    <div class="hero-cta"><a class="btn-glow" href="<?= e(signup_href($data)) ?>" data-lu-signup><span class="lbl-out">Level Up Now <?= icon('arrow-right', 16) ?></span><span class="lbl-in">Dashboard <?= icon('arrow-right', 16) ?></span></a></div>
    <button type="button" class="scroll-cue" aria-label="Scroll down" data-scroll-cue><span class="lbl">Scroll</span><span class="bar" aria-hidden="true"></span></button>
    </div>

    <?php // media carries a content hash so a re-encode can never be served from a stale edge
    $mv = function (string $file): string {
        $p = dirname(__DIR__) . '/assets/product/' . $file;
        return '/next/assets/product/' . $file . (is_file($p) ? '?v=' . substr(md5_file($p), 0, 8) : '');
    }; ?>

    <div class="panel hero-panel ax" id="ax">
      <noscript><p class="ax-fallback">Arthur needs JavaScript. <a href="/app/">Open the builder</a> instead.</p></noscript>
    </div>

    <?php
    // TEMPLATE GALLERY (2026-09-11): every distinct live design's hero, shot from the platform's own preview.
    $tplManifest = dirname(__DIR__) . '/assets/product/templates/templates.json';
    $tplItems = is_file($tplManifest) ? (json_decode((string) file_get_contents($tplManifest), true)['items'] ?? []) : [];
    $tplItems = array_values(array_filter($tplItems, fn ($t) => is_file(dirname(__DIR__) . '/assets/product/' . ($t['file'] ?? ''))));
    if ($tplItems !== []): ?>
    <div class="panel hero-panel tg-gallery" data-tg-gallery aria-roledescription="carousel" aria-label="Website designs Arthur builds from">
      <div class="tg-stage">
        <div class="tg-track" tabindex="0" aria-live="off">
          <?php foreach ($tplItems as $i => $t):
              $src = $mv('templates/' . basename($t['file']));
              $eager = $i < 2; ?>
          <figure class="tg-card" data-i="<?= $i ?>" data-slug="<?= htmlspecialchars($t['slug'], ENT_QUOTES) ?>"<?= ($t['slug'] ?? '') === 'it_services' ? ' data-featured="1"' : '' ?> aria-label="<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>">
            <div class="tg-frame">
              <img src="<?= $src ?>" alt="" width="1440" height="900" <?= $eager ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"' ?> decoding="async" draggable="false">
            </div>
            <figcaption><span class="tg-name"><?= htmlspecialchars($t['name'], ENT_QUOTES) ?></span><span class="tg-ind"><?= htmlspecialchars($t['industry_label'] ?? '', ENT_QUOTES) ?></span></figcaption>
          </figure>
          <?php endforeach; ?>
        </div>
        <button type="button" class="tg-nav tg-prev" aria-label="Previous design"><?= icon('arrow-left', 16) ?></button>
        <button type="button" class="tg-nav tg-next" aria-label="Next design"><?= icon('arrow-right', 16) ?></button>
      </div>
    </div>
    <?php else: ?>
    <figure class="panel hero-panel hero-result">
      <div class="panel-in">
        <img src="<?= $mv('82-plum-site.webp') ?>" alt="Saltmarsh Travel, the finished website, on the Royal Plum theme." width="1280" height="800" loading="lazy" decoding="async">
      </div>
      <figcaption>One Arthur built earlier, from four sentences. <a href="https://saltmarsh.levelupgrowth.io/" target="_blank" rel="noopener">Open it <?= icon('arrow-right', 13) ?></a></figcaption>
    </figure>
    <?php endif; ?>

  </div>
</section>

<?php
/*
 * GLASS-HOME-1 (Owner 2026-09-29: "push it to live on website's home page. make sure to route properly").
 * Below the site's own hero (Arthur's real intake + the design gallery, unchanged) the page continues as the Liquid Glass
 * home from the Motion mockup v19 (claude.ai/artifact/DYHZ3BZcM2imQ3AtuVwxBS): self-playing panels drawn as glass, never
 * screenshots. The mockup's own chrome (header, footer, legend, sticky pill, placeholder orb) is not carried over: the
 * site's header, footer and chatbot888 do those jobs. Every link routes to a real page; every number comes from $data.
 * The home follows the device's colour scheme (head script below); the other pages stay as they are until they are rebuilt.
 */
$mk = function (string $f): string { $p = dirname(__DIR__) . '/assets/mk/' . $f; return '/next/assets/mk/' . $f . (is_file($p) ? '?v=' . substr(md5_file($p), 0, 8) : ''); };
$agentCount = count($data['agents']);
$page['head'] = '<script>/* GLASS-HOME-1: the home follows the device (Owner 2026-09-29) */try{var t=localStorage.getItem("lug_theme");if(!t&&window.matchMedia&&matchMedia("(prefers-color-scheme: light)").matches)t="light";if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}</script>' . "\n"
  . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">' . "\n"
  . '<link rel="stylesheet" href="' . e($mk('lug-glass.css')) . '">' . "\n"
  . '<link rel="stylesheet" href="' . e($mk('home.css')) . '">';
$page['scripts'] = '<script src="' . e($mk('motion.js')) . '" defer></script>' . "\n" . '<script src="' . e($mk('home.js')) . '" defer></script>';
$signup = e(signup_href($data));
$ctaOut = e(cta_label($data));
$agentImg = fn (string $slug) => '/img/agents/' . $slug . '.webp';
?>

<div class="lg mk" id="glass-home">
  <div class="lg-aurora" aria-hidden="true"><i></i><i></i><i></i></div>
  <div id="mk-progress" aria-hidden="true"></div>
  <svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><linearGradient id="mkgrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8C25D2"></stop><stop offset=".55" stop-color="#4C86DE"></stop><stop offset="1" stop-color="#3FDFDF"></stop></linearGradient></defs></svg>

  <?php /* 02 — what happens after you describe the business: Arthur builds, the team speaks up */ ?>
  <section class="mk-sec" id="hx-sec" style="padding-top:56px;padding-bottom:40px">
    <div class="mk-wrap mk-hero">
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
            <img src="<?= e($mk('tpl-restaurant.webp')) ?>" id="hx-img" alt="Black Door restaurant website" width="1440" height="900" style="height:275px;width:100%;object-fit:cover;object-position:top" loading="lazy" decoding="async">
            <div class="mk-skel" id="hx-skel" hidden><div class="lg-skel" style="height:14px;width:40%"></div><div class="lg-skel" style="height:90px"></div><div class="lg-skel" style="height:14px;width:60%"></div><div class="lg-skel" style="height:14px;width:50%"></div></div>
          </div>
          <div style="padding:10px 12px;border-top:1px solid var(--hairline)" class="lg-stack gap-2">
            <div class="lg-row lg-between"><span class="t-caption c-2" id="hx-stage">Published</span><span class="t-caption t-num c-3"><span id="hx-pct">100</span>%</span></div>
            <div class="lg-meter"><i id="hx-meter" style="width:100%;transform-origin:left"></i></div>
          </div>
        </div>

        <div class="hx-frost" id="hx-frost" aria-hidden="true"></div>
        <div class="hx-tag" id="hx-tag" aria-hidden="true"><span class="hx-tag__line" id="hx-line">Your AI growth team working for you <b class="t-grad">24/7</b>.</span></div>
        <div class="hx-agents" id="hx-agents">
          <div class="hx-say" data-agent="sarah"><span class="hx-say__av lg-presence"><img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="" width="36" height="36"></span><div class="hx-say__body"><div class="hx-say__bubble"><span class="hx-say__who"><b>Sarah</b> · Digital marketing manager</span>Hi Boss, I'm Sarah, your Digital Marketing Manager. Let's start your growth!</div></div></div>
          <div class="hx-say" data-agent="james"><span class="hx-say__av lg-presence"><img class="lg-avatar" src="<?= e($agentImg('james')) ?>" alt="" width="36" height="36"></span><div class="hx-say__body"><div class="hx-say__bubble"><span class="hx-say__who"><b>James</b> · SEO strategist</span>I'll run an SEO audit on the new site now.</div></div></div>
          <div class="hx-say" data-agent="priya"><span class="hx-say__av lg-presence"><img class="lg-avatar" src="<?= e($agentImg('priya')) ?>" alt="" width="36" height="36"></span><div class="hx-say__body"><div class="hx-say__bubble"><span class="hx-say__who"><b>Priya</b> · Content manager</span>Boss, I'll write one article for you targeting your desired keywords.</div></div></div>
          <div class="hx-say" data-agent="marcus"><span class="hx-say__av lg-presence"><img class="lg-avatar" src="<?= e($agentImg('marcus')) ?>" alt="" width="36" height="36"></span><div class="hx-say__body"><div class="hx-say__bubble"><span class="hx-say__who"><b>Marcus</b> · Social media manager</span>Boss, I will prepare a 1 week social media campaign for you.</div></div></div>
        </div>
      </div>
    </div>
  </section>

  <?php /* 03 — THE LOOP */ ?>
  <section class="mk-sec" id="loop" style="padding-top:40px">
    <div class="mk-wrap lg-stack" style="gap:28px">
      <div class="lg-row lg-between" style="align-items:flex-end;gap:32px;flex-wrap:wrap">
        <div class="lg-stack gap-3" style="max-width:640px">
          <span class="t-eyebrow" data-rv>Who does it</span>
          <h2 class="mk-h2" data-rv>Sarah runs it. You approve it.</h2>
          <p class="mk-lead" data-rv>Tell her about the business once. She plans, prices each job, hands it to the right specialist and brings the finished work to you before any of it goes live. Watch one go round.</p>
        </div>
        <a class="lg-row gap-3" data-rv href="/next/product/ai-workforce/" style="color:inherit"><span class="lg-presence" style="display:inline-flex"><img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="Sarah" width="64" height="64" style="width:64px;height:64px"></span><div class="lg-stack"><span class="t-callout t-strong">Sarah</span><span class="t-footnote c-3">Digital marketing manager</span></div></a>
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
          <div class="lg-row lg-between" style="gap:12px;flex-wrap:wrap"><div class="lg-stack"><span class="t-title2">Good morning, Owner.</span><span class="t-footnote c-3"><?= e(date('l j F')) ?> · 7 of <?= $agentCount ?> agents on this workspace</span></div><span class="lg-badge lg-badge--success" style="height:30px;padding:0 12px"><span class="lg-dot"></span>LIVE</span></div>
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
              <div class="lg-glass lg-glass--thin lg-stack gap-2" style="padding:12px;border-radius:16px"><div class="lg-row gap-2"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg('priya')) ?>" alt="" width="32" height="32"><div class="lg-stack"><span class="t-subhead t-strong">Private hire page · write</span><span class="t-caption c-3">Priya · 2 credits</span></div></div><div class="lg-row gap-2"><span class="lg-btn lg-btn--primary lg-btn--sm lg-grow">Approve</span><span class="lg-btn lg-btn--glass lg-btn--sm lg-grow">Not now</span></div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php /* 05 — CHATBOT */ ?>
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
        <a class="t-footnote t-strong c-accent" data-rv href="/next/product/chatbot/">How the chatbot works <?= icon('arrow-right', 13) ?></a>
      </div>
      <div class="mk-frame lg-grow mk-frame--travel" data-rv style="min-width:0;height:470px">
        <div class="mk-frame__bar"><i></i><i></i><i></i><span class="mk-frame__url">saltmarsh.levelupgrowth.io</span></div>
        <img src="<?= e($mk('tpl-resort.webp')) ?>" alt="Saltmarsh Travel website" width="1440" height="900" style="height:436px;object-fit:cover;object-position:top" loading="lazy" decoding="async">
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
        <p class="t-body c-2" style="margin:0"><?= $specialists ?> specialists, one context, one approval queue. You only ever talk to Sarah.</p>
        <div class="lg-stack gap-2" id="tm" style="margin-top:6px">
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">Sarah</span><span class="t-caption c-3">Digital marketing manager</span></div><span class="mk-status" data-status="Planning October|Pricing a campaign|Reviewing James's audit|Briefing Priya"><i></i><span>Planning October</span></span></div>
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('priya')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">Priya</span><span class="t-caption c-3">Content manager</span></div><span class="mk-status" data-status="Writing: cherry blossom guide|Fixing 3 meta descriptions|Drafting the menu page|Idle"><i></i><span>Writing: cherry blossom guide</span></span></div>
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('james')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">James</span><span class="t-caption c-3">SEO strategist</span></div><span class="mk-status" data-status="Auditing 12 pages|Tracking 8 keywords|Checking answer engines|Idle"><i></i><span>Auditing 12 pages</span></span></div>
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('marcus')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">Marcus</span><span class="t-caption c-3">Social media manager</span></div><span class="mk-status" data-status="Scheduling Friday 9:00|Drafting a carousel|Idle|Reading the week's results"><i></i><span>Scheduling Friday 9:00</span></span></div>
          <div class="lg-row gap-3" style="min-height:40px"><img class="lg-avatar" src="<?= e($agentImg('elena')) ?>" alt="" width="34" height="34" style="width:34px;height:34px"><div class="lg-stack" style="width:150px;flex:none"><span class="t-subhead t-strong">Elena</span><span class="t-caption c-3">Lead and CRM manager</span></div><span class="mk-status" data-status="Logging Emma's enquiry|Idle|Reminder: call Priya Raman|Cleaning duplicates"><i></i><span>Logging Emma's enquiry</span></span></div>
        </div>
        <a class="t-footnote t-strong c-accent" href="/next/product/ai-workforce/">Meet all <?= $agentCount ?> <?= icon('arrow-right', 13) ?></a>
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
      <div class="lg-row gap-3" style="flex-wrap:wrap;justify-content:center"><a class="lg-btn lg-btn--primary lg-btn--lg mk-btn" href="<?= $signup ?>" data-lu-signup id="cta-main" style="position:relative;overflow:hidden"><span class="lbl-out"><?= $ctaOut ?></span><span class="lbl-in">Dashboard</span><span id="cta-shine" aria-hidden="true" style="position:absolute;inset:0;background:linear-gradient(105deg,transparent 30%,rgba(255,255,255,.45) 50%,transparent 70%);transform:translateX(-120%);pointer-events:none"></span></a><a class="lg-btn lg-btn--glass lg-btn--lg mk-btn" href="/next/pricing/">See pricing</a></div>
    </div></div>
  </section>
</div>
