<?php
/**
 * Phase 4 Part A — add "semantic" blocks to every studio template manifest.
 * Keyword-based heuristic per spec. Idempotent: only writes if "semantic"
 * is missing OR --force is passed.
 *
 * Run: php tools/enrich_semantic_profiles.php [--force]
 */

$ROOT = '/var/www/levelup-staging/storage/templates/studio';
$force = in_array('--force', $argv, true);

if (!is_dir($ROOT)) { fwrite(STDERR, "templates dir not found\n"); exit(1); }

// Keyword → partial semantic profile. First match wins.
$RULES = [
    ['/fitness|gym|neon|transformation|sport|performance|bold-fitness/i', [
        'mood' => ['energetic','bold'], 'color_temperature' => 'dark', 'composition' => 'person_center',
        'best_for' => ['motivation','promotion','fitness','transformation','performance'],
    ]],
    ['/beauty|rose|glow|editorial-beauty/i', [
        'mood' => ['elegant','luxury'], 'color_temperature' => 'warm', 'composition' => 'person_center',
        'best_for' => ['beauty','wellness','promotion','editorial'],
    ]],
    ['/restaurant|food|menu|catering|hospitality-luxury/i', [
        'mood' => ['playful','warm'], 'color_temperature' => 'vibrant', 'composition' => 'product_hero',
        'best_for' => ['food','promotion','menu','hospitality'],
    ]],
    ['/realestate|real-estate|property|interior-design|architecture/i', [
        'mood' => ['luxury','professional'], 'color_temperature' => 'dark', 'composition' => 'person_right',
        'best_for' => ['real estate','property','services','architecture'],
    ]],
    ['/corporate|agency|finance|glass-card|legal/i', [
        'mood' => ['professional','bold'], 'color_temperature' => 'dark', 'composition' => 'text_dominant',
        'best_for' => ['business','consulting','B2B','finance','legal'],
    ]],
    ['/social|creator|pop-profile|personal-brand/i', [
        'mood' => ['playful','energetic'], 'color_temperature' => 'vibrant', 'composition' => 'person_center',
        'best_for' => ['personal branding','social','engagement'],
    ]],
    ['/fashion|minimal|lookbook/i', [
        'mood' => ['minimal','elegant'], 'color_temperature' => 'light', 'composition' => 'no_person',
        'best_for' => ['fashion','retail','product'],
    ]],
    ['/tech|saas|premium|startup|edtech/i', [
        'mood' => ['professional','bold'], 'color_temperature' => 'dark', 'composition' => 'text_dominant',
        'best_for' => ['technology','SaaS','startup','B2B'],
    ]],
    ['/wellness|yoga|bento|healthcare|clean/i', [
        'mood' => ['calm','minimal'], 'color_temperature' => 'light', 'composition' => 'person_center',
        'best_for' => ['wellness','yoga','health','mindfulness'],
    ]],
    ['/event|poster|energy/i', [
        'mood' => ['energetic','bold'], 'color_temperature' => 'vibrant', 'composition' => 'text_dominant',
        'best_for' => ['event','announcement','promotion'],
    ]],
    ['/ecom|ecommerce|product|blast/i', [
        'mood' => ['bold','playful'], 'color_temperature' => 'vibrant', 'composition' => 'product_hero',
        'best_for' => ['product launch','sale','ecommerce','promotion'],
    ]],
    ['/construction|industrial|bold|automotive/i', [
        'mood' => ['bold','professional'], 'color_temperature' => 'dark', 'composition' => 'text_dominant',
        'best_for' => ['services','industrial','trade','automotive'],
    ]],
    ['/cleaning|fresh/i', [
        'mood' => ['minimal','calm'], 'color_temperature' => 'light', 'composition' => 'text_dominant',
        'best_for' => ['cleaning','services','home'],
    ]],
    ['/childcare|playful|kids/i', [
        'mood' => ['playful','energetic'], 'color_temperature' => 'warm', 'composition' => 'person_center',
        'best_for' => ['childcare','education','family'],
    ]],
];

function infer_layout_type(string $slug): string
{
    if (str_contains($slug, 'split'))      return 'split';
    if (str_contains($slug, 'grid'))       return 'bento';
    if (str_contains($slug, 'bento'))      return 'bento';
    if (str_contains($slug, 'poster'))     return 'full-bleed';
    if (str_contains($slug, 'editorial'))  return 'editorial';
    if (str_contains($slug, 'card'))       return 'card';
    if (str_contains($slug, 'overlay'))    return 'overlay';
    return 'centered';
}

// Cross-industry hints for the most-reused patterns.
function cross_industry_for(string $slug, array $profile): array
{
    $out = [];
    if (in_array('text_dominant', [$profile['composition']], true) || str_contains($slug, 'bold')) {
        $out[] = ['industry' => 'SaaS', 'how' => 'Swap headline with product claim. Stats become uptime / users / ROI.', 'field_mapping' => ['eyebrow' => 'SAAS PLATFORM']];
        $out[] = ['industry' => 'Real estate', 'how' => 'Headline = property address. Stats become Beds / Baths / SqFt.', 'field_mapping' => ['stat_1_label' => 'Beds', 'stat_2_label' => 'Baths', 'stat_3_label' => 'SqFt']];
    }
    if (in_array('person_center', [$profile['composition']], true)) {
        $out[] = ['industry' => 'Coaching', 'how' => 'Person photo works for coaches, speakers, consultants as-is.', 'field_mapping' => []];
        $out[] = ['industry' => 'Agency', 'how' => 'Replace athlete / model with founder headshot.', 'field_mapping' => ['eyebrow' => 'FOUNDERS']];
    }
    if (in_array('product_hero', [$profile['composition']], true)) {
        $out[] = ['industry' => 'DTC', 'how' => 'Swap food / product shot with any hero product photo.', 'field_mapping' => []];
    }
    return $out;
}

function arthur_instructions_for(string $slug, array $profile): string
{
    $mood = implode(' / ', $profile['mood'] ?? []);
    $bestFor = implode(', ', $profile['best_for'] ?? []);
    $composition = $profile['composition'] ?? 'centered';
    return "Use when user wants a {$mood} design ({$composition} composition). "
         . "Works for: {$bestFor}. Primary image can be swapped to any hero visual; "
         . "stats slots work for any numbers that tell a story.";
}

$processed = 0; $skipped = 0; $updated = 0;

foreach (scandir($ROOT) as $slug) {
    if ($slug === '.' || $slug === '..') continue;
    $mf = $ROOT . '/' . $slug . '/manifest.json';
    if (!is_file($mf)) continue;
    $processed++;

    $data = json_decode(file_get_contents($mf), true);
    if (!is_array($data)) { echo "  skip (bad json): $slug\n"; continue; }

    if (!$force && isset($data['semantic'])) { $skipped++; continue; }

    // Figure out profile from slug
    $profile = [
        'mood' => ['professional'],
        'color_temperature' => 'dark',
        'composition' => 'centered',
        'best_for' => ['general'],
    ];
    foreach ($RULES as [$pat, $prof]) {
        if (preg_match($pat, $slug)) { $profile = array_merge($profile, $prof); break; }
    }
    $profile['layout_type'] = infer_layout_type($slug);
    $profile['cross_industry'] = cross_industry_for($slug, $profile);
    $profile['arthur_instructions'] = arthur_instructions_for($slug, $profile);

    $data['semantic'] = $profile;

    file_put_contents($mf, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $updated++;
    echo "  ✓ $slug — " . implode('/', $profile['mood']) . " · " . $profile['color_temperature'] . " · " . $profile['layout_type'] . "\n";
}

echo "\nPROCESSED: $processed  UPDATED: $updated  SKIPPED: $skipped\n";
