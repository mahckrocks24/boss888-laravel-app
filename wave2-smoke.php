<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "============================================================\n";
echo "WAVE 2 SMOKE\n";
echo "============================================================\n\n";

$svc = app(\App\Engines\SEO\Services\SeoAssistantService::class);

// Test 1: runPreflightSweep returns sensible data
echo "── TEST 1: runPreflightSweep(ws=7) returns aggregated state ──\n";
$ref = new ReflectionMethod($svc, 'runPreflightSweep');
$ref->setAccessible(true);
$sweep = $ref->invoke($svc, 7);
foreach ($sweep as $k => $v) {
    if (is_array($v)) {
        echo "  $k = [array, " . count($v) . " items]\n";
    } else {
        echo "  $k = " . var_export($v, true) . "\n";
    }
}
echo "\n";

// Test 2: buildProposal for generate_article triggers audit-first gate
echo "── TEST 2: buildProposal(generate_article) for ws 1 (audit fresh?) ──\n";
$ref2 = new ReflectionMethod($svc, 'buildProposal');
$ref2->setAccessible(true);
$memory = ['business_type' => 'AI marketing platform', 'services' => ['SEO', 'AI writing']];
$proposal = $ref2->invoke($svc, 1, 'generate_article', 'write an article', $memory);
echo "  action: {$proposal['action']}\n";
echo "  cost: {$proposal['cost']}\n";
echo "  requires_audit_first: " . var_export($proposal['requires_audit_first'], true) . "\n";
echo "  keyword chosen: " . ($proposal['params']['keyword'] ?? '(none)') . "\n";
echo "  target_rationale: " . ($proposal['params']['target_rationale'] ?? '(none)') . "\n";
echo "  days_since_audit (from preflight): " . ($proposal['preflight']['days_since_audit'] ?? 'null') . "\n";
echo "\n";

// Test 3: narrateProposal — see what the user actually reads
echo "── TEST 3: narrateProposal output for generate_article ──\n";
$ref3 = new ReflectionMethod($svc, 'narrateProposal');
$ref3->setAccessible(true);
$narration = $ref3->invoke($svc, $proposal, $memory);
echo "  ─── BEGIN NARRATION ───\n";
foreach (explode("\n", $narration) as $line) echo "    | $line\n";
echo "  ─── END NARRATION ───\n";
echo "  contains 'Shall I proceed?': " . (strpos($narration, 'Shall I proceed?') !== false ? 'YES (UI buttons will render)' : 'NO (UI buttons will NOT render — BUG)') . "\n";
echo "\n";

// Test 4: extractKeywordFromReply — pulls LLM-recommended topic
echo "── TEST 4: extractKeywordFromReply — alternative topic detection ──\n";
$ref4 = new ReflectionMethod($svc, 'extractKeywordFromReply');
$ref4->setAccessible(true);
$cases = [
    "I can't write that, but I could write about 'AI marketing Dubai' instead." => "AI marketing Dubai",
    "Strategic Recommendation: Write an article targeting your top tracked keyword 'SaaS growth strategies' — currently unaddressed." => "SaaS growth strategies",
    "How about an article on smart home automation for Dubai?" => "smart home automation for Dubai",
    "Just a chat message with no clear topic." => "",
];
foreach ($cases as $reply => $expected) {
    $extracted = $ref4->invoke($svc, $reply);
    $ok = $extracted === $expected ? "✓" : "✗";
    echo "  $ok in: " . mb_substr($reply, 0, 60) . "...\n";
    echo "    expected: '$expected'\n";
    echo "    got:      '$extracted'\n";
}
echo "\n";

// Test 5: Reset admin disclaimer, simulate scope-mismatch → check pending saved
echo "── TEST 5: scope-mismatch returns pending proposal with alternative keyword ──\n";
DB::table('users')->where('id', 1)->update(['seo_assistant_disclaimer_accepted_at' => now()]);
$resp = $svc->handle(1, "Write me an article about ergonomic office chairs in Dubai", ['user_id' => 1, 'source' => 'wave2_smoke']);
echo "  response (first 400 chars):\n";
echo "    " . mb_substr(str_replace("\n", "\n    ", $resp['response']), 0, 700) . "...\n";
echo "  response length: " . strlen($resp['response']) . "\n";
echo "  contains 'Shall I proceed?': " . (strpos($resp['response'], 'Shall I proceed?') !== false ? 'YES' : 'NO') . "\n";
$pending = json_decode(\Illuminate\Support\Facades\Redis::get("seo_pending_ws_1") ?? 'null', true);
if ($pending) {
    echo "  pending saved:\n";
    echo "    action: {$pending['action']}\n";
    echo "    keyword: " . ($pending['params']['keyword'] ?? '(none)') . "\n";
    echo "    rationale: " . ($pending['params']['target_rationale'] ?? '(none)') . "\n";
    echo "    cost: {$pending['cost']}\n";
} else {
    echo "  pending: NONE (R3 not firing)\n";
}
echo "\n";

echo "DONE\n";