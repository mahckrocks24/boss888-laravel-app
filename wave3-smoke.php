<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Engines\SEO\Services\SeoService;
use App\Connectors\RuntimeClient;

echo "============================================================\n";
echo "WAVE 3 SMOKE\n";
echo "============================================================\n\n";

// R9: RuntimeClient enrichContextWithWorkspace
echo "── R9: RuntimeClient::enrichContextWithWorkspace ──\n";
$rc = app(RuntimeClient::class);
$ref = new ReflectionMethod($rc, 'enrichContextWithWorkspace');
$ref->setAccessible(true);
$enriched = $ref->invoke($rc, ['workspace_id' => 7]);
echo "  context keys after enrich: " . implode(',', array_keys($enriched)) . "\n";
foreach (['workspace_id','business_name','brand_voice','services','tracked_keywords','prior_article_titles','workspace_knowledge','workspace_kb_loaded'] as $k) {
    if (isset($enriched[$k])) {
        $v = $enriched[$k];
        if (is_array($v)) $v = '[array '.count($v).']';
        elseif (is_string($v) && strlen($v) > 80) $v = substr($v, 0, 80) . '…';
        echo "    $k = " . var_export($v, true) . "\n";
    }
}
echo "\n";

// R7: aiPreviewLinkInsertion + aiApplyLinkInsertion
echo "── R7: aiPreviewLinkInsertion / aiApplyLinkInsertion ──\n";
$seo = app(SeoService::class);

// Pick an article from ws 1 (we have control of it)
$article = DB::table('articles')->where('workspace_id', 1)->orderByDesc('id')->first();
echo "  test article: id={$article->id} title={$article->title}\n";
echo "  current body length: " . strlen($article->content ?? '') . "\n";
echo "  body has <a>? " . (strpos($article->content ?? '', '<a ') !== false ? 'YES' : 'NO') . "\n";

// Create a seo_links suggestion that targets our test article
$siteUrl = DB::table('seo_settings')->where('workspace_id', 1)->where('key', 'site_url')->value('value');
$sourceUrl = rtrim($siteUrl,'/') . '/' . $article->slug;
$linkId = DB::table('seo_links')->insertGetId([
    'workspace_id'  => 1,
    'source_url'    => $sourceUrl,
    'target_url'    => rtrim($siteUrl, '/') . '/test-target-page',
    'anchor_text'   => 'Smoke Test', // will be in the body since we wrote it earlier
    'type'          => 'internal',
    'status'        => 'suggested',
    'priority_score'=> 50,
    'created_at'    => now(),
    'updated_at'    => now(),
]);
echo "  created test seo_links row id=$linkId\n";
echo "  source_url=$sourceUrl\n";

// Preview
$preview = $seo->aiPreviewLinkInsertion(1, $linkId);
echo "\n  PREVIEW result:\n";
foreach ($preview as $k => $v) {
    if ($k === '_modified_body') {
        echo "    $k = [hidden, " . strlen($v) . " chars]\n";
    } elseif (is_array($v)) {
        echo "    $k = " . json_encode($v) . "\n";
    } else {
        $s = (string) $v;
        if (strlen($s) > 120) $s = substr($s, 0, 120) . '…';
        echo "    $k = $s\n";
    }
}

// Apply if preview succeeded
if (! empty($preview['success'])) {
    echo "\n  APPLY result:\n";
    $apply = $seo->aiApplyLinkInsertion(1, $linkId);
    foreach ($apply as $k => $v) {
        if (is_array($v)) $v = json_encode($v);
        echo "    $k = " . (string)$v . "\n";
    }

    // Verify article body actually modified
    $after = DB::table('articles')->where('id', $article->id)->first();
    $beforeLen = strlen($article->content);
    $afterLen = strlen($after->content);
    $hasLink = strpos($after->content, '/test-target-page') !== false;
    echo "\n  article body delta: $beforeLen -> $afterLen chars\n";
    echo "  body contains new link target: " . ($hasLink ? 'YES ✓' : 'NO ✗') . "\n";

    $linkRow = DB::table('seo_links')->where('id', $linkId)->first();
    echo "  seo_links status: {$linkRow->status} (expected: inserted)\n";

    // Cleanup: restore article + delete test link
    DB::table('articles')->where('id', $article->id)->update(['content' => $article->content]);
    DB::table('seo_links')->where('id', $linkId)->delete();
    echo "  cleanup: article restored + test link deleted\n";
}
echo "\n";

// R6: branchConfirm follow-up proposal — simulate by directly calling buildProposal + checking next_proposal shape
echo "── R6: next_proposal mechanism (without firing the full chain) ──\n";
// Skip the full LLM chain — just verify that execGenerateArticle's return shape includes next_proposal
// by inspecting the code path
$assistantRef = new ReflectionClass(\App\Engines\SEO\Services\SeoAssistantService::class);
$execMethod = $assistantRef->getMethod('execGenerateArticle');
echo "  execGenerateArticle method exists: YES\n";
$src = file_get_contents($assistantRef->getFileName());
if (strpos($src, "'next_proposal' => \$nextProposal,") !== false) {
    echo "  return shape includes next_proposal: YES ✓\n";
} else {
    echo "  return shape includes next_proposal: NO ✗\n";
}
if (strpos($src, "branchConfirm") !== false && strpos($src, "savePending(\$wsId, \$exec['next_proposal'])") !== false) {
    echo "  branchConfirm handles next_proposal: YES ✓\n";
} else {
    echo "  branchConfirm handles next_proposal: NO ✗\n";
}

echo "\nDONE\n";