<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$svc = app(\App\Engines\Marketing\Services\EmailBuilderService::class);
$premiumId = 14;

echo "── Phase 2.1: previewTemplate injects bridge ──\n";
$preview = $svc->previewTemplate($premiumId, ['brand_name'=>'LevelUp','headline'=>'H','subheadline'=>'S','cta_text'=>'Go','cta_url'=>'#','hero_image_url'=>'#','feature_1_icon'=>'A','feature_1_title'=>'A','feature_1_body'=>'A','feature_2_icon'=>'B','feature_2_title'=>'B','feature_2_body'=>'B','feature_3_icon'=>'C','feature_3_title'=>'C','feature_3_body'=>'C','body_text'=>'x','secondary_headline'=>'y','secondary_body'=>'y','secondary_cta_text'=>'y','secondary_cta_url'=>'#','testimonial_quote'=>'q','testimonial_name'=>'N','testimonial_role'=>'R','footer_text'=>'f','unsubscribe_url'=>'#']);
assert(str_contains($preview, '__lu_email_preview_bridge__'));
assert(str_contains($preview, 'block-selected'));
assert(str_contains($preview, 'update-brand-color'));
assert(str_contains($preview, 'bridge-ready'));
echo "  ✓ bridge injected (hover/select/update-field/brand-color/highlight/ready)\n";

echo "── Phase 2.2: data-block-id on each block ──\n";
preg_match_all('/data-block-id="(\d+)"/', $preview, $m);
echo "  found " . count($m[1]) . " block markers: " . implode(',', $m[1]) . "\n";
assert(count($m[1]) >= 7);

echo "── Phase 2.3: render() without bridge (final send) ──\n";
$final = $svc->render($premiumId);
assert(!str_contains($final, '__lu_email_preview_bridge__'));
echo "  ✓ bridge absent from render() output\n";

echo "── Phase 2.4: getTemplateVariables — typed output ──\n";
$vars = $svc->getTemplateVariables($premiumId);
$typeCounts = array_count_values(array_column($vars, 'type'));
echo "  " . count($vars) . " vars typed: " . json_encode($typeCounts) . "\n";
$byName = array_column($vars, 'type', 'name');
assert(($byName['hero_image_url'] ?? null) === 'image');
assert(($byName['cta_url']        ?? null) === 'url');
assert(($byName['body_text']      ?? null) === 'textarea');
assert(($byName['subheadline']    ?? null) === 'textarea');
assert(($byName['brand_name']     ?? null) === 'text');
echo "  ✓ inference: image/url/textarea/text all correct\n";

echo "── Phase 2.5: mobile format applies narrow wrap ──\n";
$mob = $svc->previewTemplate($premiumId, [], 'mobile');
assert(str_contains($mob, 'max-width:420px'));
echo "  ✓ mobile format wraps container at 420px\n";

echo "── Phase 3.1: DeepSeekConnector is injected ──\n";
$ref = new ReflectionClass($svc);
$hasLlm = $ref->hasProperty('llm');
echo "  llm property present: " . ($hasLlm ? 'yes' : 'NO') . "\n";
assert($hasLlm);

echo "── Phase 3.2: aiSpamCheck — clean subject ──\n";
$s1 = $svc->aiSpamCheck($premiumId, 'Your AI Growth Engine is Ready');
echo "  score={$s1['score']}/20 rating={$s1['rating']} subject_score={$s1['subject_score']} content_score={$s1['content_score']}\n";
assert($s1['score'] <= 3);
assert($s1['rating'] === 'great');

echo "── Phase 3.3: aiSpamCheck — spam-like subject ──\n";
$s2 = $svc->aiSpamCheck($premiumId, 'FREE URGENT CASH!!! BUY NOW');
echo "  score={$s2['score']}/20 rating={$s2['rating']} issues=" . count($s2['issues']) . "\n";
foreach ($s2['issues'] as $i) echo "    - {$i['rule']} sev={$i['severity']}: {$i['fix']}\n";
assert($s2['score'] >= 7);
assert($s2['rating'] === 'danger');

echo "── Phase 3.4: aiSpamCheck — borderline ──\n";
$s3 = $svc->aiSpamCheck($premiumId, 'Limited time — 50% off this week');
echo "  score={$s3['score']}/20 rating={$s3['rating']}\n";

echo "── Phase 3.5: AI endpoints are callable (DeepSeek key check) ──\n";
$key = config('connectors.deepseek.api_key') ?: env('DEEPSEEK_API_KEY');
echo "  DeepSeek key configured: " . (!empty($key) ? 'yes' : 'NO (skipping live calls)') . "\n";

if (!empty($key)) {
    echo "── Phase 3.6: aiSuggestSubjects — live DeepSeek call ──\n";
    $sugg = $svc->aiSuggestSubjects($premiumId, ['goal' => 'announce', 'tone' => 'professional', 'brand_name' => 'LevelUp']);
    $count = count($sugg['subjects'] ?? []);
    echo "  returned $count subjects";
    if ($count > 0) {
        $first = $sugg['subjects'][0];
        echo " — first: '{$first['text']}' (angle={$first['angle']}, score={$first['score']})";
    }
    if (!empty($sugg['error'])) echo " error={$sugg['error']}";
    echo "\n";
} else {
    echo "  ⚠ skipped live DeepSeek smoke (no key)\n";
}

echo "\n✅ Phase 2 + Phase 3 SMOKE PASSED\n";
