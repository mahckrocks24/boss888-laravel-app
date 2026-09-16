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

<?php /* 02 — who is doing it, now that there is a reason to ask. */ ?>
<section class="sec sec-tight who-bg" id="sarah" style="--who-bg:url('<?= $mv('who-sarah.webp') ?>')">
  <div class="container row-7-5">
    <div class="who-copy">
      <p class="eyebrow">Who does it</p>
      <h2 class="display"><span class="grad">Sarah runs it.<br>You approve it.</span></h2>
      <p class="say">Tell her about the business once. She keeps the context — the site, the customers, the balance, what already ran — plans the month, prices each job, and brings the finished work to you before any of it goes live.</p>
      <p><a class="in" href="/next/product/ai-workforce/">How Sarah works <?= icon('arrow-right', 15) ?></a></p>
    </div>
    <div aria-hidden="true"></div>
  </div>
</section>

<?php /* 03 — the loop. The whole product in one screen. */ ?>
<section class="sec" id="loop">
  <div class="container">
    <div class="row-7-5 row-top">
      <div>
        <p class="eyebrow">Brief it once</p>
        <h2 class="display">You don't manage<br>the work.</h2>
      </div>
      <div>
        <p class="say">You say what you want. Sarah scopes it, prices it, hands it to the specialist who does that job, and brings it back for your yes. This is one that happened.</p>
      </div>
    </div>
    <?php if (! empty($j['article'])) { require dirname(__DIR__) . '/loop.php'; } ?>
  </div>
</section>

<?php /* 04 — the numbers, as one figure. */ ?>
<section class="sec" id="proof">
  <div class="container row-5-7 row-top">
    <div>
      <p class="eyebrow">Numbers you can check</p>
      <span class="big-n"><?= (int) $sr['score'] ?><small>/100</small></span>
      <span class="big-cap">A site she built, audited the day it went live. <?= (int) $sr['passed'] ?> checks passed, <?= (int) $sr['warnings'] ?> to fix, <?= (int) $sr['errors'] ?> problems.</span>
      <p class="say mt">The article from the loop above scores <?= (int) ($j['aeo']['score'] ?? 90) ?>/100 with answer engines. Where a number needs a connection you have not made, the screen says so instead of guessing.</p>
      <p><a class="in" href="/next/product/seo/#report">See the whole report <?= icon('arrow-right', 15) ?></a></p>
    </div>
    <div>
      <?= crop('42-reports-live', 'Work done in a workspace: tasks, articles live, enquiries and bookings, each marked measured.', ['l' => 0.20, 't' => 0.10, 'w' => 0.74, 'ratio' => '21']) ?>
    </div>
  </div>
</section>

<?php /* 05 — the team behind her, without the org chart. */ ?>
<section class="sec sec-tight night team-bg" id="team" style="--team-bg:url('<?= $mv('team-five.webp') ?>')">
  <div class="container">
    <div class="row-7-5 row-top team-copy">
      <div>
        <p class="eyebrow">She is not doing it alone</p>
        <h2 class="display-sm">Sarah brings in<br>the right specialist.</h2>
      </div>
      <div>
        <p class="say say-light">Search, content, social, creative, customers. <?= $specialists ?> of them, working from the same context and reporting into the same approval queue. You only ever talk to Sarah.</p>
        <p><a class="in" href="/next/product/ai-workforce/">Meet the team <?= icon('arrow-right', 15) ?></a></p>
      </div>
    </div>
  </div>
</section>

<?php /* 06 — the range, as three groups rather than ten sections. */ ?>
<section class="sec" id="does">
  <div class="container">
    <p class="eyebrow">What gets done</p>
    <h2 class="display-sm">Build. Grow. Convert.</h2>
    <div class="triad mt">
      <?php foreach ($groups as [$name, $ico, $line, $links]): ?>
      <div class="triad-col">
        <span class="triad-ico"><?= icon($ico, 22) ?></span>
        <h3><?= e($name) ?></h3>
        <p><?= e($line) ?></p>
        <ul>
          <?php foreach ($links as [$label, $href]): ?>
          <li><a href="<?= e($href) ?>"><?= e($label) ?> <?= icon('arrow-right', 13) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="row-5-7 row-top mt-xl">
      <div>
        <p class="eyebrow">It sells while you sleep</p>
        <h2 class="display-sm">A visitor at 11pm<br>is a lead by morning.</h2>
        <p class="say">The chatbot is grounded in your own pages, so it answers about your business, asks where and when, and writes the lead into your CRM.</p>
        <?php if (! empty($j['chat']['lead'])): ?>
        <ul class="lead-list">
          <?php foreach ($j['chat']['lead'] as $k => $v): ?>
          <li><b><?= e($k) ?></b><span><?= e($v) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
      <div class="widget-stage">
        <figure class="frame frame-dark">
          <div class="frame-bar"><span class="frame-url">saltmarsh.levelupgrowth.io</span></div>
          <div class="frame-body"><img src="<?= $mv('77-widget.webp') ?>" alt="The Saltmarsh Travel website with its own chatbot open, answering a honeymoon enquiry from the agency's own Japan package and asking for an email so a consultant can send a quote." width="1440" height="900" loading="lazy" decoding="async" width="780" height="1688" loading="lazy" decoding="async"></div>
        </figure>
        <p class="crop-note center">On the customer's own site, in their own colours.</p>
      </div>
    </div>
  </div>
</section>

<?php /* 07 — control */ ?>
<section class="sec sec-tight" id="control">
  <div class="container row-7-5">
    <div>
      <p class="eyebrow">Nothing ships without you</p>
      <h2 class="display-sm">AI when you want it.<br>Control when you need it.</h2>
      <p class="say">Every finished piece of work waits in one queue with the specialist who did it and what it cost. Approve it, or do not. And if you would rather not see the machinery, the whole account collapses to five things that need you.</p>
    </div>
    <div><?= crop('40-queue-live', 'The approval queue: three pieces of work, each with its specialist, its cost, and approve or reject.', ['l' => 0.17, 't' => 0.28, 'w' => 0.78, 'ratio' => '16']) ?></div>
  </div>
</section>

<?php /* 08 — ownership */ ?>
<section class="sec sec-tight night" id="own">
  <div class="container">
    <div class="row-7-5 row-top">
      <div>
        <p class="eyebrow">It is yours, not rented</p>
        <h2 class="display">The business you own,<br>run by a workforce<br>you approve.</h2>
      </div>
      <div>
        <ul class="feature-list feature-list-light">
          <li><?= icon('check', 18) ?><span>Your website, and your domain registered to you</span></li>
          <li><?= icon('check', 18) ?><span>Your customers, exportable any day</span></li>
          <li><?= icon('check', 18) ?><span>Your content, on your site, not on ours</span></li>
          <li><?= icon('check', 18) ?><span>Several sites in one business, one shared context</span></li>
        </ul>
        <p><a class="in" href="/next/security/">How your data is held <?= icon('arrow-right', 15) ?></a></p>
      </div>
    </div>
  </div>
</section>

<?php /* 09 — price and close */ ?>
<section class="sec sec-tight" id="price">
  <div class="container center narrow">
    <p class="eyebrow">Pricing</p>
    <h2 class="display-sm">Start free. Add Sarah when you are ready.</h2>
    <div class="price-2 mt">
      <?php foreach (array_filter([$free, $lite]) as $p): ?>
      <a class="price-card<?= $p['slug'] === 'ai-lite' ? ' featured' : '' ?>" href="/next/pricing/#<?= e($p['slug']) ?>">
        <span class="price-name"><?= e($p['name']) ?></span>
        <span class="price-amount"><?= money($p['price_monthly']) ?><small>/mo</small></span>
        <span class="price-line"><?= e(plan_summary($p)) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="fine"><a href="/next/pricing/">Every plan and what is in it</a></p>
  </div>
</section>

<section class="sec night cta-final" id="start">
  <div class="container center">
    <h2 class="display">Give Sarah your business.</h2>
    <p class="say say-light center-x">She will tell you what she would do first.</p>
    <div class="hero-actions center">
      <a class="btn btn-primary btn-lg" href="<?= e(signup_href($data)) ?>"><?= e(cta_label($data)) ?> <?= icon('arrow-right', 18) ?></a>
      <a class="btn btn-ghost-light btn-lg" href="/next/product/ai-workforce/">How Sarah works</a>
    </div>
  </div>
</section>
