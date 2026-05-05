<?php
/**
 * Seed the premium template + smoke-test EmailBuilderService end to end.
 * Run: php tools/seed_and_smoke_email_builder.php
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$svc = app(\App\Engines\Marketing\Services\EmailBuilderService::class);
$WS  = 1;

echo "── Seeding Premium template ──\n";
$seeded = $svc->seedPremiumTemplate($WS);
echo "  template_id={$seeded['template_id']}"
   . (!empty($seeded['already_seeded']) ? " (already seeded)" : " (fresh)")
   . "\n";
$premiumId = $seeded['template_id'];

echo "── Smoke 1: listTemplates ──\n";
$list = $svc->listTemplates($WS);
echo "  " . count($list) . " templates visible to workspace $WS\n";

echo "── Smoke 2: getTemplate(premium) ──\n";
$got = $svc->getTemplate($premiumId);
echo "  name='{$got['template']['name']}' blocks=" . count($got['blocks']) . " variables=" . count($got['variables']) . "\n";
assert(count($got['blocks']) === 7);
echo "  ✓ 7 blocks (header, hero, features, body_text, secondary_cta, testimonial, footer)\n";

echo "── Smoke 3: createTemplate source=blank ──\n";
$blank = $svc->createTemplate($WS, ['name' => 'smoke-blank', 'source' => 'blank']);
$blankId = $blank['template']['id'];
echo "  blank id={$blankId} blocks=" . count($blank['blocks']) . "\n";
assert(count($blank['blocks']) === 3); // header + hero + footer

echo "── Smoke 4: addBlock (hero inserted at order 2) ──\n";
$added = $svc->addBlock($blankId, ['block_type' => 'features', 'block_order' => 2]);
echo "  added features block id={$added['block_id']}\n";
$reloaded = $svc->getBlocks($blankId);
echo "  order: " . implode(',', array_map(fn($b) => "{$b['block_type']}({$b['block_order']})", $reloaded)) . "\n";

echo "── Smoke 5: updateBlock content ──\n";
$svc->updateBlock($blankId, $added['block_id'], ['content_json' => [
    'section_label' => 'SMOKE-TEST',
    'feature_1_title' => 'UpdatedFeature', 'feature_1_body' => 'OK', 'feature_1_icon' => "\u{26A1}",
]]);
$after = $svc->getBlocks($blankId);
$features = array_values(array_filter($after, fn($b) => $b['block_type'] === 'features'))[0];
echo "  feature_1_title = '{$features['content_json']['feature_1_title']}'\n";
assert($features['content_json']['feature_1_title'] === 'UpdatedFeature');

echo "── Smoke 6: reorderBlocks ──\n";
$ids = array_column($after, 'id');
$reversed = array_reverse($ids);
$svc->reorderBlocks($blankId, $reversed);
$after2 = $svc->getBlocks($blankId);
echo "  new order: " . implode(',', array_column($after2, 'block_type')) . "\n";

echo "── Smoke 7: render(premium) ──\n";
$html = $svc->render($premiumId);
echo "  html length: " . strlen($html) . " chars\n";
assert(strlen($html) > 5000);
echo "  contains VML roundrect: " . (str_contains($html, 'v:roundrect') ? 'yes' : 'NO') . "\n";
echo "  contains dark-mode media: " . (str_contains($html, 'prefers-color-scheme') ? 'yes' : 'NO') . "\n";
echo "  contains preheader: " . (str_contains($html, 'class="preheader"') ? 'yes' : 'NO') . "\n";

echo "── Smoke 8: renderWithVariables ──\n";
$html2 = $svc->renderWithVariables($premiumId, [
    'brand_name' => 'LevelUp Growth',
    'headline'   => 'AI Growth Engine',
    'subheadline'=> 'Replace your marketing stack.',
    'cta_text'   => 'Start Free',
    'cta_url'    => 'https://levelupgrowth.io',
    'hero_image_url' => 'https://cdn.example.com/hero.png',
    'feature_1_icon' => "\u{26A1}", 'feature_1_title' => 'AI Strategy', 'feature_1_body' => 'Auto plans.',
    'feature_2_icon' => "\u{1F4CA}", 'feature_2_title' => 'Live Analytics', 'feature_2_body' => 'Real-time.',
    'feature_3_icon' => "\u{1F517}", 'feature_3_title' => 'Integrations', 'feature_3_body' => '200+ tools.',
    'body_text' => 'Hi Sarah, your metrics are at an inflection point.',
    'secondary_headline' => 'See it live in 15 minutes', 'secondary_body' => 'Book a demo.',
    'secondary_cta_text' => 'Book a Demo', 'secondary_cta_url' => 'https://levelupgrowth.io/demo',
    'testimonial_quote'  => 'LevelUp replaced 4 tools.', 'testimonial_name' => 'Sarah Mitchell', 'testimonial_role' => 'Head of Growth',
    'footer_text'        => 'LevelUp Growth · Dubai, UAE',
    'unsubscribe_url'    => 'https://example.com/u',
]);
$residual = preg_match_all('/\{\{[a-z_0-9]+\}\}/i', $html2, $m);
echo "  residual {{tokens}} after substitution: $residual\n";
assert($residual === 0);
echo "  ✓ all tokens substituted\n";

echo "── Smoke 9: exportHtml ──\n";
$exp = $svc->exportHtml($premiumId);
echo "  export length: " . strlen($exp) . " chars\n";

echo "── Smoke 10: aiSpamCheck ──\n";
$spam = $svc->aiSpamCheck($premiumId, 'AI Growth Engine — LevelUp Growth');
echo "  score={$spam['score']}/10  issues=" . count($spam['issues']) . "  rec: {$spam['recommendation']}\n";

echo "── Smoke 11: variable extraction ──\n";
$vars = $got['variables'];
echo "  premium has " . count($vars) . " variables: " . implode(', ', array_slice($vars, 0, 8)) . "...\n";

echo "── Smoke 12: deleteBlock (shift reorder) ──\n";
$svc->deleteBlock($blankId, $added['block_id']);
$after3 = $svc->getBlocks($blankId);
echo "  blocks remaining: " . count($after3) . " types=" . implode(',', array_column($after3, 'block_type')) . "\n";

echo "── Smoke 13: deleteTemplate (non-system) ──\n";
$ok = $svc->deleteTemplate($blankId);
echo "  delete ok=" . ($ok ? 'true' : 'false') . "\n";

echo "── Smoke 14: deleteTemplate (system — must refuse) ──\n";
$ok2 = $svc->deleteTemplate($premiumId);
echo "  system delete refused=" . ($ok2 ? 'NO (BUG)' : 'yes') . "\n";
assert($ok2 === false);

echo "\n✅ ALL SMOKE TESTS PASSED\n";
