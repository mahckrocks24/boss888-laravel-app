<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Launch checklist';
$page['description'] = 'Internal go/no-go view for the marketing site cut-over. Not linked, not indexed.';
$page['noindex'] = true;
$snap = null; $raw = @file_get_contents(rtrim($data['api_base'] ?? 'https://staging.levelupgrowth.io', '/') . '/api/public/status', false, stream_context_create(['http' => ['timeout' => 10]])); if ($raw) { $snap = json_decode($raw, true) ?: null; }
$launched = ! empty($data['launched']);
$infra = ! empty($data['infrastructure_live']);
$gates = [
    ['Payments', 'Stripe live keys and one real checkout per plan', 'boss', 'Stripe is in test mode'],
    ['Signup', 'PLATFORM_PUBLIC_LAUNCHED=true, launch-gate script removed, signup to first site under five minutes', $launched ? 'ok' : 'boss', $launched ? 'launched' : 'buttons go to /start/ (waitlist)'],
    ['Pricing truth', 'Page equals endpoint equals table equals enforcement', 'ok', 'drift check aligned at build ' . $data['plans_version']],
    ['Copy truth', 'Every product claim maps to a certified engine', 'ok', '12 products, 3 infrastructure pages held' . ($infra ? ' (live)' : '')],
    ['Imagery', 'Real screenshots from the seeded demo workspace; three recordings', 'boss', 'placeholders on every product frame'],
    ['Social', 'Connector accounts and Meta review; one real post from a demo workspace', 'boss', 'live publishing blocked'],
    ['Infrastructure', 'Domains, business email and hosting proven once, or their pages held', $infra ? 'boss' : 'ok', $infra ? 'pages published, gates to prove' : 'pages held behind the flag'],
    ['Blog', 'Archive artefacts, re-file into the taxonomy, twelve accepted posts by Sarah', 'boss', '13 posts today, archive list pending'],
    ['Legal facts', 'Twenty-one business facts confirmed in the policies and the about page', 'boss', 'shown as to-confirm marks'],
    ['Mailbox', 'hello@levelupgrowth.io receives mail; Postmark inactive flag cleared', 'boss', 'hard-bounced on 7 September'],
    ['Quality', 'Sweep clean at four widths, no type under 13px, zero console errors, links and budgets green', 'ok', 'nightly script covers it'],
    ['Status', 'Platform components operational', $snap && $snap['overall'] === 'operational' ? 'ok' : 'warn', $snap ? $snap['overall'] : 'unknown'],
];
$labels = ['ok' => 'Ready', 'boss' => 'Decision', 'warn' => 'Check'];
?>
<section class="section-tight page-head">
  <div class="container">
    <p class="eyebrow">Internal</p>
    <h1>Launch checklist</h1>
    <p class="lede">Go/no-go for the cut-over from the old site to this one. Rebuilt with every build; not linked from the site, not indexed.</p>
    <p class="mono">launched=<?= $launched ? 'true' : 'false' ?> · infrastructure_live=<?= $infra ? 'true' : 'false' ?> · plans <?= e($data['plans_version']) ?> · built <?= date('Y-m-d H:i') ?> UTC</p>
  </div>
</section>
<section class="section-tight">
  <div class="container">
    <div class="tbl"><table class="matrix">
      <thead><tr><th>Gate</th><th>Check</th><th>State</th><th>Today</th></tr></thead>
      <tbody>
        <?php foreach ($gates as [$g, $c, $s, $t]): ?>
        <tr><th scope="row"><?= e($g) ?></th><td><?= e($c) ?></td><td><span class="gate gate-<?= e($s) ?>"><?= e($labels[$s]) ?></span></td><td><?= e($t) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <h2>Cut-over, when every gate is green</h2>
    <ol>
      <li>Set <span class="mono">PLATFORM_PUBLIC_LAUNCHED=true</span> and rebuild; confirm every Start button points into the app.</li>
      <li>Point the marketing routes at <span class="mono">public/marketing-next/dist</span> and move the preview from <span class="mono">/next/</span> to <span class="mono">/</span> (site URL already levelupgrowth.io in canonicals and sitemap).</li>
      <li>Remove the old launch-gate script from the served pages; keep <span class="mono">public/marketing</span> untouched as the rollback.</li>
      <li>Run the nightly script once by hand: drift, links, budget, sweep.</li>
      <li>Submit the sitemap; watch the delivery ledger for the first real waitlist and contact mail.</li>
    </ol>
    <p class="fine">Rollback: the old site is intact in <span class="mono">public/marketing</span> and in two backups dated 7 September; restoring the route is one line.</p>
  </div>
</section>
