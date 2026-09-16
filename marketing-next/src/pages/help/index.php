<?php
/** @var array $page */ /** @var array $data */
$sections = require dirname(__DIR__, 2) . '/help-data.php';
$page['title'] = 'Help centre';
$page['description'] = 'Answers about getting started, the AI workforce, your website and hosting, plans and billing, with every number read from the plans table.';
$plans = $data['plans'];
$free = array_values(array_filter($plans, fn ($p) => (float) $p['price_monthly'] === 0.0))[0] ?? null;
$aiFirst = array_values(array_filter($plans, fn ($p) => ! empty($p['features']['ai_agents']) || ! empty($p['features']['ai_assistant'])))[0] ?? null;
$fmt = fn ($n) => $n >= 999999 ? 'unlimited' : number_format((int) $n);
$agentsByCat = []; foreach ($data['agents'] as $a) { if (! empty($a['is_dmm'])) { continue; } $agentsByCat[$a['category']][] = $a['name'] . ' (' . $a['title'] . ')'; }
$agentsText = 'Sarah, the Digital Marketing Manager, leads ' . count(array_filter($data['agents'], fn ($a) => empty($a['is_dmm']))) . ' specialists. ' . implode(' ', array_map(fn ($cat, $names) => ucfirst($cat === 'crm' ? 'customers' : $cat) . ': ' . implode(', ', $names) . '.', array_keys($agentsByCat), $agentsByCat)) . ' Plans include Sarah plus a number of specialists; add more for a flat monthly price.';
$plansText = count($plans) . ' plans: ' . implode('; ', array_map(fn ($p) => $p['name'] . ' at ' . money($p['price_monthly']) . '/month with ' . $fmt($p['max_websites']) . ' site' . ((int) $p['max_websites'] === 1 ? '' : 's') . ($p['credits_per_month'] > 0 ? ' and ' . $fmt($p['credits_per_month']) . ' credits' : ', no workforce'), $plans)) . '. Details and the feature matrix are on the pricing page.';
$creditsText = implode(' ', array_map(fn ($p) => $p['name'] . ' includes ' . $fmt($p['credits_per_month']) . ' credits a month.', array_filter($plans, fn ($p) => $p['credits_per_month'] > 0)));
$freeText = $free ? 'Yes. ' . $free['name'] . ' is ' . money($free['price_monthly']) . ' forever with ' . $fmt($free['max_websites']) . ' site on a subdomain, the builder, CRM and calendar, and no workforce.' . ($aiFirst ? ' The workforce starts on ' . $aiFirst['name'] . ' at ' . money($aiFirst['price_monthly']) . '/month. Every new account gets a three-day AI trial with 50 free credits at signup, no card required.' : '') : 'See the pricing page.';
$domainsText = ! empty($data['infrastructure_live']) ? 'Yes. Buy a domain from your account or connect one you already own; SSL is provisioned automatically and the registration is in your name.' : 'Every site publishes on a LevelUpGrowth subdomain with SSL, which is permanent and shareable. Domains, business email and managed hosting are separate infrastructure products; they appear on this site when they are available on your plan.';
$fill = fn (string $s) => str_replace(['{free_plan}', '{agents}', '{plans}', '{credits}', '{domains}', '{annual}'], [$freeText, $agentsText, $plansText, $creditsText, $domainsText, (string) (int) round(($data['annual_discount'] ?? 0.17) * 100)], $s);
$all = [];
foreach ($sections as $s) { foreach ($s['items'] as [$q, $a]) { $all[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $fill($a)]]; } }
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $all];
?>
<section class="section-tight page-head">
  <div class="container narrow">
    <p class="eyebrow">Help centre</p>
    <h1>Answers, with the numbers read from the plans table.</h1>
    <p class="lede">Sixteen questions across four topics. Anything else: <a href="/next/contact/">write to us</a>.</p>
    <nav class="chips" aria-label="Topics"><?php foreach ($sections as $s): ?><a class="chip" href="#<?= e($s['slug']) ?>"><?= e($s['title']) ?></a><?php endforeach; ?></nav>
  </div>
</section>
<?php foreach ($sections as $s): ?>
<section class="section-tight" id="<?= e($s['slug']) ?>">
  <div class="container narrow">
    <h2><?= e($s['title']) ?></h2>
    <div class="faq"><?php foreach ($s['items'] as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($fill($a)) ?></p></details><?php endforeach; ?></div>
  </div>
</section>
<?php endforeach; ?>
