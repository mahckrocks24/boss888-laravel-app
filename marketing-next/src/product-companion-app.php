<?php
/** @var array $page */ /** @var array $data */ /** @var array $p (product) */
/*
 * COMPANION-PAGE-1 (Owner 2026-10-05: "add a dedicated page for the mobile companion app in the marketing website listed as one of
 * our products"). The app is a communication tool with exactly three tabs - Sarah, Review, Account (app888 1.0.22). The page claims
 * only what those tabs do: the same Sarah thread as the web (text, previews, approval buttons, reply chips, photos/camera/documents),
 * Review (approve or decline with a reason, the cost shown, open the work on the web), Account (plan, credits, websites,
 * notifications, app lock with the phone's biometrics, open the web app, sign out). Plans: Growth, Pro and Agency (pricing page).
 */
$page['title'] = 'Companion app';
$page['description'] = 'Sarah in your pocket. Chat with your AI growth team, approve their work and get notified when something needs you, from the LevelUpGrowth companion app for your phone.';
$mk = function (string $f): string { $q = __DIR__ . '/assets/mk/' . $f; return '/next/assets/mk/' . $f . (is_file($q) ? '?v=' . substr(md5_file($q), 0, 8) : ''); };
$agentImg = fn (string $slug) => '/img/agents/' . $slug . '.webp';
$page['head'] = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">' . "\n"
  . '<link rel="stylesheet" href="' . e($mk('lug-glass.css')) . '">' . "\n" . '<link rel="stylesheet" href="' . e($mk('home.css')) . '">';
$incl = array_values(array_filter($data['plans'], fn ($pl) => in_array($pl['slug'], ['growth', 'pro', 'agency'], true)));
$first = $incl[0] ?? null;
$signup = e(signup_href($data, $first['slug'] ?? null));
$check = '<svg viewBox="0 0 24 24" class="ic" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';
$arrow = '<svg class="ic ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$faq = [
  ['What can I do in the app?', 'Talk to Sarah, approve or decline the work your team has ready, and keep an eye on your plan and credits. Everything else, like editing your website or your client list, stays in the web app, one tap away.'],
  ['Is it the same Sarah as on the web?', 'Yes, the same conversation. Start on your laptop, carry on from your phone, and everything is in one thread on both.'],
  ['Can I approve work from my phone?', 'Yes. Every piece of work that waits for you is in the Review tab with what it is and what it costs. Approve it, or decline it with a reason so the team can do better next time.'],
  ['Will it notify me?', 'Yes. You are told when work is ready for your approval, when Sarah has something for you and when a new lead comes in. You can switch notifications off in the app.'],
  ['Is my account safe on my phone?', 'You sign in with your LevelUpGrowth login, and you can turn on App lock so the app opens only with your phone\'s face or fingerprint unlock.'],
  ['Which plans include it?', 'Growth, Pro and Agency.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'MobileApplication', 'name' => 'LevelUpGrowth', 'applicationCategory' => 'BusinessApplication', 'description' => $page['description'], 'url' => 'https://levelupgrowth.io/product/companion-app/'];
$tab = fn (string $on) => '<div class="ca-tabs">' . implode('', array_map(fn ($t) => '<span class="' . ($t[0] === $on ? 'on' : '') . '"><svg viewBox="0 0 24 24">' . $t[1] . '</svg>' . $t[0] . '</span>', [
  ['Sarah', '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
  ['Review', '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>'],
  ['Account', '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>'],
])) . '</div>';
?>
<style>
  .ca .ca-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:clamp(32px,5vw,64px);align-items:center;padding-top:clamp(72px,7vw,96px)}
  .ca .ca-h1{font-size:clamp(38px,5.2vw,62px);line-height:1.04;font-weight:800;letter-spacing:-.035em;margin:0;color:var(--ink);text-wrap:balance}
  .ca .ca-crumbs{display:flex;gap:8px;font-size:13px;color:var(--ink-3)}.ca .ca-crumbs a{color:var(--ink-2);text-decoration:none}
  .ca .ca-cta{display:flex;gap:12px;flex-wrap:wrap}
  .ca .ca-proof{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;max-width:560px}
  .ca .ca-proof div{border-radius:16px;padding:12px 14px;display:flex;flex-direction:column;gap:2px}.ca .ca-proof b{font:800 20px/1.15 var(--font);color:var(--ink)}.ca .ca-proof span{font-size:12.5px;line-height:17px;color:var(--ink-2)}
  /* three phones, sized by the stage so they scale as one */
  .ca .cs{position:relative;width:100%;max-width:620px;margin-left:auto;aspect-ratio:1/.98;container-type:inline-size}
  .ca .cs-glow{position:absolute;inset:6% 4%;border-radius:50%;background:radial-gradient(closest-side,rgba(124,58,237,.38),rgba(37,99,235,.18) 55%,transparent);filter:blur(10px)}
  .ca .ph{position:absolute;width:38cqw;aspect-ratio:9/19.2;border-radius:6.4cqw;background:#0b0d12;padding:1.3cqw;box-shadow:0 3cqw 8cqw rgba(10,8,40,.45),inset 0 0 0 .3cqw #2a2d36}
  .ca .ph--l{left:0;top:9cqw;transform:rotate(-7deg);z-index:1}.ca .ph--r{right:0;top:9cqw;transform:rotate(7deg);z-index:1}.ca .ph--c{left:31cqw;top:0;width:40cqw;z-index:3}
  .ca .scr{width:100%;height:100%;border-radius:5.2cqw;overflow:hidden;display:flex;flex-direction:column;background:linear-gradient(170deg,#f6f3ff,#eef4ff 55%,#f4f6fb);color:#14161c;font:400 1.8cqw/1.38 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  .ca .scr-bar{display:flex;justify-content:space-between;align-items:center;padding:2cqw 3cqw .4cqw;font-size:1.55cqw;font-weight:700}
  .ca .scr-notch{width:10cqw;height:2.4cqw;border-radius:2cqw;background:#0b0d12}
  .ca .scr-hd{display:flex;align-items:center;gap:1.4cqw;padding:1.2cqw 2.4cqw 1.4cqw}
  .ca .scr-hd img{width:4.8cqw;height:4.8cqw;border-radius:50%}.ca .scr-hd b{font-size:2.05cqw;display:block}.ca .scr-hd small{font-size:1.5cqw;color:#6b7080}
  .ca .scr-title{padding:1cqw 2.6cqw 1.4cqw;font:800 3cqw/1.1 var(--font);letter-spacing:-.02em}
  .ca .scr-body{flex:1;padding:0 2cqw;display:flex;flex-direction:column;gap:1.2cqw;overflow:hidden}
  .ca .bub{max-width:88%;padding:1.2cqw 1.6cqw;border-radius:2.4cqw;background:rgba(255,255,255,.85);box-shadow:0 .3cqw 1cqw rgba(20,16,60,.08);font-size:1.75cqw}
  .ca .bub--me{align-self:flex-end;background:linear-gradient(135deg,#7c3aed,#2563eb);color:#fff}
  .ca .bub b{font-weight:700}
  .ca .chips{display:flex;gap:1cqw;flex-wrap:wrap}.ca .chips span{padding:.8cqw 1.4cqw;border-radius:2cqw;background:#fff;border:.15cqw solid #ddd6fe;color:#5b21b6;font-weight:700;font-size:1.55cqw}
  .ca .pv{border-radius:2cqw;overflow:hidden;background:#fff;box-shadow:0 .3cqw 1cqw rgba(20,16,60,.1)}.ca .pv img{display:block;width:100%;aspect-ratio:1.25/1;object-fit:cover}
  .ca .pv-m{padding:1cqw 1.4cqw;font-size:1.55cqw;color:#5b6070}
  .ca .btns{display:flex;gap:1cqw}.ca .btn{flex:1;text-align:center;padding:1cqw;border-radius:1.8cqw;font-weight:700;font-size:1.6cqw;background:#fff;border:.15cqw solid #e2e4ec}.ca .btn--p{background:linear-gradient(135deg,#7c3aed,#2563eb);color:#fff;border:0}
  .ca .comp{margin:1cqw 2cqw 1.2cqw;display:flex;align-items:center;gap:1cqw;padding:1cqw 1.4cqw;border-radius:3cqw;background:rgba(255,255,255,.9);font-size:1.6cqw;color:#8a8f9e}
  .ca .comp i{margin-left:auto;width:3.6cqw;height:3.6cqw;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#2563eb)}
  .ca .rq{border-radius:2.2cqw;background:rgba(255,255,255,.9);padding:1.4cqw;display:flex;flex-direction:column;gap:1cqw;box-shadow:0 .3cqw 1cqw rgba(20,16,60,.08)}
  .ca .rq-row{display:flex;gap:1.2cqw;align-items:center}.ca .rq-row img{width:4cqw;height:4cqw;border-radius:50%}.ca .rq-row b{font-size:1.75cqw;display:block}.ca .rq-row small{font-size:1.45cqw;color:#6b7080}
  .ca .acc{border-radius:2.2cqw;background:rgba(255,255,255,.9);padding:.4cqw 1.6cqw}
  .ca .acc div{display:flex;justify-content:space-between;align-items:center;padding:1.2cqw 0;border-top:.15cqw solid #eceef4;font-size:1.7cqw}.ca .acc div:first-child{border-top:0}.ca .acc b{font-weight:700}
  .ca .tog{width:4.6cqw;height:2.6cqw;border-radius:2cqw;background:#7c3aed;position:relative}.ca .tog:after{content:"";position:absolute;right:.3cqw;top:.3cqw;width:2cqw;height:2cqw;border-radius:50%;background:#fff}
  .ca .ca-tabs{display:flex;justify-content:space-around;padding:1.2cqw 1cqw 2.2cqw;background:rgba(255,255,255,.75);border-top:.15cqw solid #e6e8f0}
  .ca .ca-tabs span{display:flex;flex-direction:column;align-items:center;gap:.4cqw;font-size:1.4cqw;font-weight:700;color:#9095a3}.ca .ca-tabs span.on{color:#6d28d9}
  .ca .ca-tabs svg{width:3.2cqw;height:3.2cqw;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .ca .cs-note{position:absolute;z-index:4;border-radius:3cqw;padding:1.8cqw 2.2cqw;display:flex;gap:1.6cqw;align-items:center;font-size:2cqw;line-height:1.35;color:var(--ink)}
  .ca .cs-note b{display:block;font-size:2.05cqw}.ca .cs-note small{display:block;color:var(--ink-3);font-size:1.7cqw}
  .ca .cs-note .ico{width:5cqw;height:5cqw;border-radius:1.6cqw;flex:none;display:grid;place-items:center;background:linear-gradient(135deg,#7c3aed,#2563eb);color:#fff}.ca .cs-note .ico svg{width:2.8cqw;height:2.8cqw;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .ca .cs-note--a{left:-2cqw;top:76cqw;width:40cqw}.ca .cs-note--b{right:-2cqw;top:2cqw;width:36cqw}
  .ca .ca-head{display:flex;flex-direction:column;gap:14px;max-width:760px;margin-bottom:28px}.ca .ca-head--c{margin-inline:auto;text-align:center;align-items:center}
  .ca .ca-grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
  .ca .ca-card{border-radius:22px;padding:22px;display:flex;flex-direction:column;gap:12px}
  .ca .ca-card h3{margin:0;font-size:20px;letter-spacing:-.01em;color:var(--ink)}.ca .ca-card p{margin:0;font-size:14.5px;line-height:22px;color:var(--ink-2)}
  .ca .ca-num{width:44px;height:44px;border-radius:14px;display:grid;place-items:center;color:#fff;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb))}.ca .ca-num svg{width:22px;height:22px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .ca .ca-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:9px}.ca .ca-list li{display:flex;gap:10px;align-items:flex-start;font-size:14.5px;line-height:21px;color:var(--ink-2)}.ca .ca-list svg{flex:none;width:18px;height:18px;margin-top:2px;color:var(--success)}
  .ca .ca-split{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .ca .ca-notes{display:flex;flex-direction:column;gap:10px}
  .ca .ca-n{display:flex;gap:12px;align-items:center;padding:12px 14px;border-radius:16px}
  .ca .ca-n img{width:36px;height:36px;border-radius:50%;flex:none}.ca .ca-n b{display:block;font-size:14.5px;color:var(--ink)}.ca .ca-n span{font-size:13px;color:var(--ink-2)}.ca .ca-n time{margin-left:auto;font-size:12px;color:var(--ink-3);white-space:nowrap}
  .ca .ca-plans{display:flex;gap:10px;flex-wrap:wrap}.ca .ca-planchip{display:inline-flex;gap:8px;align-items:center;padding:10px 14px;border-radius:14px;font-size:14px;color:var(--ink);text-decoration:none}.ca .ca-planchip span{color:var(--ink-3)}
  .ca .ca-faq{max-width:820px;margin:0 auto}
  .ca .ca-final{border-radius:28px;padding:clamp(28px,5vw,56px);text-align:center;display:flex;flex-direction:column;align-items:center;gap:16px}
  @media (max-width:900px){.ca .ca-hero,.ca .ca-grid3,.ca .ca-split{grid-template-columns:1fr}.ca .cs{margin:8px auto 0}}
  @media (max-width:600px){.ca .cs{aspect-ratio:1/1.12}.ca .ph--l,.ca .ph--r{width:34cqw;top:14cqw}.ca .ph--c{left:27cqw;width:46cqw}.ca .cs-note{font-size:12px;padding:9px 11px;gap:8px;border-radius:14px}.ca .cs-note b{font-size:12.5px}.ca .cs-note small{font-size:10.5px}.ca .cs-note .ico{width:26px;height:26px;border-radius:8px}.ca .cs-note .ico svg{width:15px;height:15px}.ca .cs-note--a{left:0;top:auto;bottom:0;width:60cqw}.ca .cs-note--b{right:0;top:0;width:52cqw}.ca .ca-proof b{font-size:17px}}
</style>
<div class="lg mk ca">

  <section class="mk-sec" style="padding-top:0;padding-bottom:40px">
    <div class="mk-wrap ca-hero">
      <div class="lg-stack gap-4">
        <nav class="ca-crumbs" aria-label="Breadcrumb"><a href="/next/product/">Products</a><span>/</span><span>Companion app</span></nav>
        <span class="lg-badge lg-badge--brand" style="align-self:flex-start;height:30px;padding:0 12px;border-radius:999px"><span class="lg-dot"></span>The companion app</span>
        <h1 class="ca-h1">Your growth team, <span class="t-grad">in your pocket.</span></h1>
        <p class="mk-lead">Talk to Sarah, approve your team's work and hear the moment something needs you, wherever you are. Three tabs, nothing to learn.</p>
        <div class="ca-cta"><a class="lg-btn lg-btn--primary lg-btn--lg" href="<?= $signup ?>" data-lu-signup><?= e(cta_label($data)) ?> <?= $arrow ?></a><a class="lg-btn lg-btn--glass lg-btn--lg" href="#tabs">See the three tabs</a></div>
        <div class="ca-proof">
          <div class="lg-glass lg-glass--thin"><b>One thread</b><span>the same Sarah as on the web</span></div>
          <div class="lg-glass lg-glass--thin"><b>One tap</b><span>to approve or decline work</span></div>
          <div class="lg-glass lg-glass--thin"><b>Locked</b><span>with your face or fingerprint</span></div>
        </div>
      </div>
      <div class="cs" aria-label="The companion app: the Review, Sarah and Account tabs">
        <div class="cs-glow" aria-hidden="true"></div>
        <div class="ph ph--l" aria-hidden="true"><div class="scr">
          <div class="scr-bar"><span>9:41</span><span class="scr-notch"></span><span>5G</span></div>
          <div class="scr-title">Review</div>
          <div class="scr-body">
            <?php foreach ([['marcus', 'Friday\'s post · Instagram', 'Marcus · 4 credits'], ['priya', 'Article · Spring honeymoons', 'Priya · 2 credits'], ['james', 'Fix 3 page titles', 'James · uses no credits']] as [$av, $t, $s]): ?>
            <div class="rq"><div class="rq-row"><img src="<?= e($agentImg($av)) ?>" alt=""><div><b><?= e($t) ?></b><small><?= e($s) ?></small></div></div><div class="btns"><span class="btn">Decline</span><span class="btn btn--p">Approve</span></div></div>
            <?php endforeach; ?>
          </div>
          <?= $tab('Review') ?>
        </div></div>
        <div class="ph ph--r" aria-hidden="true"><div class="scr">
          <div class="scr-bar"><span>9:41</span><span class="scr-notch"></span><span>5G</span></div>
          <div class="scr-title">Account</div>
          <div class="scr-body">
            <div class="acc"><div><span>Plan</span><b>Pro</b></div><div><span>Credits available</span><b>2,140</b></div><div><span>Websites</span><b>3</b></div></div>
            <div class="acc"><div><span>Notifications</span><span class="tog"></span></div><div><span>App lock</span><span class="tog"></span></div></div>
            <div class="acc"><div><span>Open LevelUpGrowth on the web</span><b>›</b></div><div><span>Manage plan and billing</span><b>›</b></div></div>
          </div>
          <?= $tab('Account') ?>
        </div></div>
        <div class="ph ph--c" aria-hidden="true"><div class="scr">
          <div class="scr-bar"><span>9:41</span><span class="scr-notch"></span><span>5G</span></div>
          <div class="scr-hd"><img src="<?= e($agentImg('sarah')) ?>" alt=""><div><b>Sarah</b><small>Your marketing manager</small></div></div>
          <div class="scr-body">
            <div class="bub">Morning, Boss. <b>Friday's post</b> is ready, and two people asked about April dates overnight. Both are in your clients.</div>
            <div class="pv"><img src="<?= e($mk('social/travel-agency-08.webp')) ?>" alt="" loading="eager"><div class="pv-m">Instagram · Friday 9:00 · 4 credits</div></div>
            <div class="btns"><span class="btn">Not now</span><span class="btn btn--p">Approve</span></div>
            <div class="bub bub--me">Approve it. Can we do one for honeymoons next week?</div>
            <div class="chips"><span>Yes, plan it</span><span>Show me ideas</span></div>
          </div>
          <div class="comp">Message Sarah<i></i></div>
          <?= $tab('Sarah') ?>
        </div></div>
        <div class="cs-note cs-note--b lg-glass lg-glass--thick"><span class="ico"><svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 20a2 2 0 0 0 4 0"/></svg></span><div><b>Work ready for you</b><small>Friday's post is waiting for approval</small></div></div>
        <div class="cs-note cs-note--a lg-glass lg-glass--thick"><span class="ico"><svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></span><div><b>Approved from your phone</b><small>It goes out Friday at 9:00</small></div></div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="tabs" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ca-head"><span class="t-eyebrow">Three tabs</span><h2 class="mk-h2">Everything a busy owner needs. Nothing they don't.</h2><p class="mk-lead">The web app is where the work is built. The companion app is where you stay in charge of it, in a few seconds at a time.</p></div>
      <div class="ca-grid3">
        <div class="ca-card lg-glass lg-glass--thick"><span class="ca-num"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span><h3>Sarah</h3><p>The same conversation as on the web, so you can start at your desk and finish on the move.</p>
          <ul class="ca-list"><li><?= $check ?>See posts, campaigns and reports as previews.</li><li><?= $check ?>Approve right in the chat, or tap a suggested reply.</li><li><?= $check ?>Send a photo, take one, or attach a document.</li></ul></div>
        <div class="ca-card lg-glass lg-glass--thick"><span class="ca-num"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span><h3>Review</h3><p>Every piece of work waiting for you, with who made it and what it costs.</p>
          <ul class="ca-list"><li><?= $check ?>Approve in one tap.</li><li><?= $check ?>Decline with a reason, so the team learns what you want.</li><li><?= $check ?>Read the article or open the work on the web.</li></ul></div>
        <div class="ca-card lg-glass lg-glass--thick"><span class="ca-num"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg></span><h3>Account</h3><p>Your plan, credits and websites at a glance, and the settings that matter on a phone.</p>
          <ul class="ca-list"><li><?= $check ?>Credits available and your plan, always current.</li><li><?= $check ?>Notifications on or off, and App lock.</li><li><?= $check ?>Open the web app or manage billing in one tap.</li></ul></div>
      </div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px">
    <div class="mk-wrap ca-split">
      <div class="ca-card lg-glass lg-glass--thick">
        <span class="t-eyebrow">Notifications</span><h3>Know the moment something needs you</h3>
        <p>Your team works around the clock. The app tells you only when it matters, so you can step in, approve and get back to your day.</p>
        <div class="ca-notes">
          <div class="ca-n lg-glass lg-glass--thin"><img src="<?= e($agentImg('marcus')) ?>" alt="" width="36" height="36"><div><b>Friday's post is ready</b><span>Waiting for your approval</span></div><time>8:02</time></div>
          <div class="ca-n lg-glass lg-glass--thin"><img src="<?= e($agentImg('elena')) ?>" alt="" width="36" height="36"><div><b>New lead: Maya</b><span>Asked about April dates</span></div><time>7:41</time></div>
          <div class="ca-n lg-glass lg-glass--thin"><img src="<?= e($agentImg('sarah')) ?>" alt="" width="36" height="36"><div><b>Your Monday report</b><span>Reach, leads and what changes this week</span></div><time>Mon</time></div>
        </div>
      </div>
      <div class="ca-card lg-glass lg-glass--thick">
        <span class="t-eyebrow">Security</span><h3>Your business, locked to you</h3>
        <p>The app uses your LevelUpGrowth login and keeps to the same approval rules as the web: nothing goes out without your yes.</p>
        <ul class="ca-list">
          <li><?= $check ?>App lock: the app opens only with your phone's face or fingerprint unlock.</li>
          <li><?= $check ?>Every approval and decline is on the record, the same as on the web.</li>
          <li><?= $check ?>Sign out from the Account tab at any time.</li>
          <li><?= $check ?>Turn notifications off in one tap, or in your phone's settings.</li>
        </ul>
      </div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ca-head"><span class="t-eyebrow">Plans</span><h2 class="mk-h2">Included in</h2><p class="mk-lead">The companion app comes with these plans. Sign in with the same login you use on the web.</p></div>
      <div class="ca-plans"><?php foreach ($incl as $pl): ?><a class="ca-planchip lg-glass lg-glass--thin" href="/next/pricing/#<?= e($pl['slug']) ?>"><?= $check ?><?= e($pl['name']) ?> <span><?= money($pl['price_monthly']) ?>/mo</span></a><?php endforeach; ?></div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px">
    <div class="mk-wrap ca-faq">
      <div class="ca-head ca-head--c"><span class="t-eyebrow">Questions</span><h2 class="mk-h2">About the companion app.</h2></div>
      <div class="faq"><?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px;padding-bottom:72px">
    <div class="mk-wrap"><div class="ca-final lg-glass lg-glass--thick">
      <span class="t-eyebrow">Stay in charge from anywhere</span>
      <h2 class="mk-h2" style="max-width:760px">Run your growth from your phone. <span class="t-grad">In seconds a day.</span></h2>
      <div class="ca-cta" style="justify-content:center"><a class="lg-btn lg-btn--primary lg-btn--lg" href="<?= $signup ?>" data-lu-signup><?= e(cta_label($data)) ?> <?= $arrow ?></a><a class="lg-btn lg-btn--glass lg-btn--lg" href="/next/pricing/">See pricing</a></div>
    </div></div>
  </section>
</div>
