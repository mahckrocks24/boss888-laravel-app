<?php
/** @var array $page */ /** @var array $data */
/*
 * Free trial (Owner 2026-10-03: "we do not have a page anywhere that talks about FREE trial, plan"). Every fact is the
 * platform's: the trial as the platform grants it (Aria 05-plans-credits), every plan number from the plans table, the
 * price list from the capability map as published in the help centre. No invented guarantees.
 */
$page['title'] = 'Free trial';
$plans = $data['plans'];
$page['description'] = 'Three days and 50 free credits with Sarah and her five specialists, no card. What the trial includes, what 50 credits buy, what happens when it ends, and what each plan adds.';
$fmtNum = fn ($n) => $n >= 999999 ? 'Unlimited' : number_format((int) $n);
$ai = array_values(array_filter($plans, fn ($p) => ! empty($p['agents']['includes_dmm'])));
$lite = $ai[0] ?? null;
$faq = [
    ['Do I need a card for the trial?', 'No. You create the account with your name, email, a password, your business name and industry, and the trial starts at once. No card is asked for.'],
    ['How long does the trial last?', 'Three days or 50 credits, whichever comes first. You can see the credits left in the app at any time.'],
    ['What happens when the trial ends?', 'Nothing is charged. Your account continues on the Free plan: your website stays published on your subdomain, and your contacts, leads and calendar stay where they are. Sarah and the specialists return when you choose a plan.'],
    ['Is the trial the full product?', 'It is the ' . ($lite ? $lite['name'] : 'AI Lite') . ' plan itself: Sarah and her five specialists, the website chatbot, SEO, content, images and video. Nothing is held back for later plans; later plans add credits, websites and team seats.'],
    ['Can I choose a plan during the trial?', 'Yes, from Billing at any time. Upgrades apply immediately.'],
    ['Do I own what gets built?', 'Yes. Your site, your contacts and your leads are yours and can be exported at any time.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
?>
<section class="section-tight page-head">
  <div class="container narrow">
    <p class="eyebrow">Free trial</p>
    <h1>Three days with your whole AI team, free.</h1>
    <p class="lede"><?= e(trial_line($data)) ?>. Meet Sarah, let her and her five specialists work on your business, then choose the plan that fits.</p>
    <p class="mt"><a class="btn btn-primary" href="<?= e(signup_href($data)) ?>"><?= e(cta_label($data)) ?></a> <a class="btn btn-secondary" href="/next/pricing/">See every plan</a></p>
  </div>
</section>

<section class="section-tight">
  <div class="container grid-2">
    <div class="card"><?= icon('users', 22) ?><h3>The whole team, from day one</h3><p>Sarah, your Digital Marketing Manager, and her five specialists for SEO, content, social and customers. Every AI plan has the same team; the trial is no smaller.</p></div>
    <div class="card"><?= icon('layers', 22) ?><h3>Every AI feature</h3><p>Arthur builds and edits your website, the website chatbot answers visitors, and the team writes, designs images and makes short videos for you to approve.</p></div>
    <div class="card"><?= icon('shield', 22) ?><h3>Nothing goes out without you</h3><p>The team proposes and you approve. Nothing is published, posted or sent on its own, during the trial or after it.</p></div>
    <div class="card"><?= icon('globe', 22) ?><h3>Yours to keep</h3><p>When the trial ends your account continues on Free: your website stays live on your subdomain and your contacts, leads and bookings stay with you. No card means no surprise charge.</p></div>
  </div>
</section>

<section class="section-tight">
  <div class="container narrow prose">
    <h2>What 50 credits buy</h2>
    <p>Credits are the meter for the team's work. A first website draft is 10 credits, a new page 5, a new section 2 and a change to text, style or media 1; edits you make yourself in the editor are free. Images are 1, 2 or 4 credits by quality, and a short video is 28 credits for 6 seconds. Talking with Sarah costs 1 credit for every five messages. Fifty credits is enough to build your site, meet the team and see real work come back for your approval.</p>
    <h2>How the trial ends</h2>
    <p>The trial ends when the 50 credits are spent or three days pass, whichever comes first. Your account then continues on the Free plan until you choose another one. You can choose a plan at any time, before or after the trial ends.</p>
  </div>
</section>

<section class="section-tight">
  <div class="container">
    <h2>After the trial: plans differ by capacity, not by team</h2>
    <p class="lede">Every AI plan includes Sarah and the same five specialists. What changes is how many credits, websites, businesses and team seats you get.</p>
    <div class="tbl"><table class="matrix">
      <thead><tr><th scope="col">Plan</th><th scope="col" class="num">Price</th><th scope="col" class="num">Credits a month</th><th scope="col" class="num">Websites and businesses</th><th scope="col" class="num">Team seats</th><th scope="col">AI team</th></tr></thead>
      <tbody>
      <?php foreach ($plans as $p): $biz = (int) (($p['features']['max_businesses'] ?? null) ?: $p['max_websites']); ?>
        <tr><th scope="row"><a href="/next/pricing/#<?= e($p['slug']) ?>"><?= e($p['name']) ?></a></th>
          <td class="num"><?= money($p['price_monthly']) ?></td>
          <td class="num"><?= $fmtNum($p['credits_per_month']) ?></td>
          <td class="num"><?= $fmtNum($p['max_websites']) ?><?= $biz !== (int) $p['max_websites'] ? ' / ' . $fmtNum($biz) : '' ?></td>
          <td class="num"><?= ! empty($p['unlimited_team']) || (int) $p['max_team_members'] >= 999 ? 'Unlimited' : $fmtNum($p['max_team_members']) ?></td>
          <td><?= ! empty($p['agents']['includes_dmm']) ? 'Sarah + ' . (int) $p['agents']['count'] . ' specialists' : 'No AI team' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="fine">Team seats are the people you invite into your account, each with their own login. Plans and limits on this page are read from the platform at build time.</p>
  </div>
</section>

<section class="section-tight">
  <div class="container narrow">
    <h2>Questions</h2>
    <div class="faq">
      <?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
    </div>
    <p class="mt"><a class="btn btn-primary" href="<?= e(signup_href($data)) ?>"><?= e(cta_label($data)) ?></a></p>
  </div>
</section>
