<?php
declare(strict_types=1);
/**
 * Build-time data. Everything a page prints as a number or a name comes from here.
 * Plans: the public plans endpoint first (the same source the live page reads), the table as fallback.
 * Agents and template count: the platform directly.
 */
$appRoot = dirname(__DIR__, 2);   // P0-1: src now lives at <app>/marketing-next/src, outside public/
$env = @file_get_contents($appRoot . '/.env') ?: '';
$get = fn (string $k) => (preg_match('/^' . $k . '=(.*)$/m', $env, $m) ? trim($m[1], " \"'") : '');
$pdo = null;
try {
    $pdo = new PDO('mysql:host=' . ($get('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $get('DB_DATABASE') . ';charset=utf8mb4', $get('DB_USERNAME'), $get('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    fwrite(STDERR, "data.php: no database (" . $e->getMessage() . ")\n");
}

$plans = []; $annualDiscount = 0.17; $plansVersion = null; $plansSource = 'none'; $infraLive = false;
$endpoint = rtrim($get('APP_URL') ?: 'https://staging.levelupgrowth.io', '/') . '/api/public/plans';
$ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
$raw = @file_get_contents($endpoint, false, $ctx);
$api = $raw ? (json_decode($raw, true) ?: null) : null;
if ($api && ! empty($api['plans'])) {
    $plans = $api['plans']; $annualDiscount = (float) ($api['annual_discount'] ?? 0.17); $plansVersion = $api['version'] ?? null; $plansSource = 'endpoint'; $infraLive = (bool) ($api['infrastructure_live'] ?? false);
} elseif ($pdo) {
    foreach ($pdo->query("SELECT name, slug, price, credit_limit, max_websites, max_team_members, agent_count, agent_level, agent_addon_price, includes_dmm, white_label, priority_processing, features_json FROM plans WHERE is_public = 1 ORDER BY price ASC") as $r) {
        $f = json_decode((string) $r['features_json'], true) ?: [];
        foreach (['custom_domain', 'hosting_access', 'hosting_sites_limit', 'infrastructure', 'infrastructure_access'] as $flag) { unset($f[$flag]); }
        $price = (float) $r['price'];
        $plans[] = ['slug' => $r['slug'], 'name' => $r['name'], 'price_monthly' => $price, 'price_annual_monthly' => $price > 0 ? round($price * (1 - $annualDiscount)) : 0.0,
            'credits_per_month' => (int) $r['credit_limit'], 'max_websites' => (int) $r['max_websites'], 'max_team_members' => (int) $r['max_team_members'], 'unlimited_team' => (int) $r['max_team_members'] >= 999,
            'agents' => ['includes_dmm' => (bool) $r['includes_dmm'], 'count' => (int) $r['agent_count'], 'level' => $r['agent_level'], 'addon_price' => $r['agent_addon_price'] !== null ? (float) $r['agent_addon_price'] : null],
            'white_label' => (bool) $r['white_label'], 'priority_processing' => (bool) $r['priority_processing'], 'features' => $f];
    }
    $plansSource = 'table';
}

$agents = [];
if ($pdo) {
    foreach ($pdo->query("SELECT id, slug, name, title, category, level, is_dmm FROM agents WHERE status = 'active' ORDER BY is_dmm DESC, category, id") as $r) { $agents[] = $r; }
}

// templates: every manifest under storage/templates, plus a curated demo site per industry (verified published, read-only)
$templates = [];
$demoAllow = ['cafe' => 'aurora-cafe', 'gym' => 'ironforge-gym', 'dental' => 'harbourdental', 'news_channel' => 'kabayan'];
$demoLive = [];
if ($pdo) { foreach ($pdo->query("SELECT template_industry, subdomain FROM websites WHERE status = 'published' AND deleted_at IS NULL AND subdomain IS NOT NULL") as $r) { $demoLive[$r['subdomain']] = $r['template_industry']; } }
foreach (glob($appRoot . '/storage/templates/*/manifest.json') ?: [] as $mf) {
    $m = json_decode((string) file_get_contents($mf), true) ?: [];
    $slug = basename(dirname($mf));
    $demo = null;
    if (isset($demoAllow[$slug])) { $sub = $demoAllow[$slug] . '.levelupgrowth.io'; if (($demoLive[$sub] ?? null) === $slug) { $demo = 'https://' . $sub . '/'; } }
    $templates[] = ['slug' => $slug, 'name' => (string) ($m['name'] ?? $slug), 'description' => (string) ($m['description'] ?? ''), 'variation' => (string) ($m['variation'] ?? ''), 'industry' => (string) ($m['industry'] ?? $slug), 'blocks' => array_values(array_map(fn ($b) => (string) ($b['name'] ?? $b['id'] ?? ''), $m['blocks'] ?? [])), 'variables' => count($m['variables'] ?? []), 'demo' => $demo];
}
usort($templates, fn ($a, $b) => strcmp($a['name'], $b['name']));

fwrite(STDERR, "data.php: plans from $plansSource (" . count($plans) . "), agents " . count($agents) . "\n");

return [
    'site'            => ['name' => 'LevelUpGrowth', 'url' => 'https://levelupgrowth.io', 'email' => 'hello@levelupgrowth.io'],
    'plans'           => $plans,
    'plans_version'   => $plansVersion ?: substr(sha1(json_encode($plans)), 0, 10),
    'plans_source'    => $plansSource,
    'annual_discount' => $annualDiscount,
    'agents'          => $agents,
    'template_count'  => count(glob($appRoot . '/storage/templates/*/template.html') ?: []),
    'templates'       => $templates,
    'infrastructure_live' => $infraLive,
    // CUTOVER (2026-09-10): preview mode bakes <meta robots=noindex> into every page (layout.php:31).
    // It is inferred from APP_URL containing 'staging.', which is ALWAYS true on this box because staging
    // IS production here — so an apex build would have shipped noindex to the live marketing site and made
    // the SEO product invisible to search. The build tool sets \['MN_PREVIEW']=false for --live.
    'preview'         => array_key_exists('MN_PREVIEW', $GLOBALS)
        ? (bool) $GLOBALS['MN_PREVIEW']
        : str_contains((string) ($get('APP_URL') ?: ''), 'staging.'),
    'launched'        => filter_var($get('PLATFORM_PUBLIC_LAUNCHED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
];
