<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Engines\SEO\Services\SeoAssistantService;
use App\Engines\Write\Services\WriteService;
use App\Engines\SEO\Services\SeoService;

$WS = 1;
$PROMPT = "Write me a 400-word article about ergonomic office chairs in Dubai";

echo "============================================================\n";
echo "E2E SMOKE — workspace=$WS\n";
echo "PROMPT: $PROMPT\n";
echo "============================================================\n\n";

function snap(int $ws): array {
    return [
        'tasks'    => DB::table('tasks')->where('workspace_id', $ws)->count(),
        'articles' => DB::table('articles')->where('workspace_id', $ws)->count(),
        'sci'      => DB::table('seo_content_index')->where('workspace_id', $ws)->count(),
        'media'    => DB::table('media')->where('workspace_id', $ws)->count(),
        'seo_links' => DB::table('seo_links')->where('workspace_id', $ws)->count(),
        'engine_intel_global' => DB::table('engine_intelligence')->count(),
    ];
}
$before = snap($WS);
echo "STATE BEFORE:\n";
foreach ($before as $k => $v) echo "  $k = $v\n";
echo "\n";

echo "── STAGE 1: SeoAssistantService::handle() ──\n";
$svc = app(SeoAssistantService::class);
try {
    $result = $svc->handle($WS, $PROMPT, ['source' => 'e2e_smoke']);
    echo "  RETURNED keys: " . implode(',', array_keys($result)) . "\n";
    foreach (['response', 'executed', 'intent', 'action_proposed', 'requires_approval'] as $k) {
        if (isset($result[$k])) {
            $v = $result[$k];
            if (is_array($v)) $v = json_encode($v);
            elseif (is_bool($v)) $v = $v ? 'true' : 'false';
            echo "  $k = " . mb_substr((string)$v, 0, 300) . "\n";
        }
    }
    if (isset($result['result'])) {
        echo "  result: " . mb_substr(json_encode($result['result']), 0, 300) . "\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: ".$e->getMessage()."\n";
}
echo "\n";

$afterAssistant = snap($WS);
echo "── STAGE 2: state delta after assistant ──\n";
foreach ($afterAssistant as $k => $v) {
    $d = $v - $before[$k];
    echo sprintf("  %-22s %d (delta %s%d)\n", $k, $v, $d>=0?'+':'', $d);
}
echo "\n";

echo "── STAGE 3: WriteService::createArticle (simulate Approve) ──\n";
$newArticleId = 0;
try {
    $writeSvc = app(WriteService::class);
    $articleResult = $writeSvc->createArticle($WS, [
        'title'           => 'E2E Smoke: Ergonomic Office Chairs Dubai',
        'content'         => '<h1>E2E Smoke Test</h1><p>Test article body for end-to-end verification.</p><h2>Why ergonomic chairs matter</h2><p>Some content about chairs that meets the 100-char minimum for readability calculation. This is intentionally long enough to trigger the FK scorer in scoreContentExtended.</p><h2>Top picks</h2><p>More content here. ABC ergonomic chair, XYZ task chair, lumbar support models.</p>',
        'type'            => 'blog_post',
        'target_keyword'  => 'ergonomic office chairs Dubai',
        'seo_title'       => 'Best Ergonomic Office Chairs in Dubai — 2026 Guide',
        'seo_description' => 'Discover the top ergonomic office chairs available in Dubai. Real reviews, prices, and where to buy.',
        'category'        => 'guides',
        'tags'            => ['office', 'ergonomics', 'dubai'],
        'user_id'         => 1,
    ]);
    echo "  article_id = " . ($articleResult['article_id'] ?? '?') . "\n";
    echo "  status = " . ($articleResult['status'] ?? '?') . "\n";
    $newArticleId = (int) ($articleResult['article_id'] ?? 0);
} catch (\Throwable $e) {
    echo "  ERROR: ".$e->getMessage()."\n";
}
echo "\n";

echo "── STAGE 4: persisted article + SCI sync ──\n";
$siteUrl = DB::table('seo_settings')->where('workspace_id', $WS)->where('key', 'site_url')->value('value');
echo "  workspace site_url: " . ($siteUrl ?? 'NOT SET') . "\n";
if ($newArticleId > 0) {
    $a = DB::table('articles')->where('id', $newArticleId)->first();
    if ($a) {
        echo "  ARTICLE:\n";
        foreach (['id','title','slug','status','seo_score','readability_score','word_count','wp_post_id','scheduled_at','published_at','focus_keyword','featured_image_url'] as $k) {
            $v = $a->$k ?? null;
            echo sprintf("    %-22s %s\n", $k, $v === null ? 'NULL' : (string)$v);
        }
    }
    if ($siteUrl && $a) {
        $url = rtrim($siteUrl,'/').'/'.ltrim($a->slug,'/');
        $sci = DB::table('seo_content_index')->where('workspace_id', $WS)->where('url_hash', md5($url))->first();
        if ($sci) {
            echo "  SCI ROW (url $url):\n";
            foreach (['id','content_score','readability_score','h2_count','image_count','internal_link_count','has_schema','has_og','score_version'] as $k) {
                $v = $sci->$k ?? null;
                echo sprintf("    %-22s %s\n", $k, $v === null ? 'NULL' : (string)$v);
            }
        } else {
            echo "  SCI ROW: NOT FOUND for url $url\n";
        }
    }
}
echo "\n";

echo "── STAGE 5: engine_intelligence recent rows ──\n";
$intel = DB::table('engine_intelligence')->orderByDesc('id')->limit(5)->get();
foreach ($intel as $i) {
    echo "  id={$i->id} engine={$i->engine} key={$i->key} effectiveness={$i->effectiveness_score} usage={$i->usage_count} at={$i->updated_at}\n";
}
echo "\n";

echo "── STAGE 6-9: featured_image / scheduled / backlinks / wp_post_id ──\n";
if ($newArticleId > 0) {
    $a = DB::table('articles')->where('id', $newArticleId)->first();
    echo "  featured_image_url: " . ($a->featured_image_url ?? 'NULL — gen NOT auto-triggered') . "\n";
    echo "  scheduled_at:       " . ($a->scheduled_at ?? 'NULL — never scheduled') . "\n";
    echo "  wp_post_id:         " . ($a->wp_post_id ?? 'NULL — never published to WP') . "\n";
    $linksForThis = DB::table('seo_links')->where('workspace_id', $WS)->where('source_url', 'like', '%'.$a->slug.'%')->count();
    echo "  seo_links with this slug as source: $linksForThis\n";
}
echo "\n";

$after = snap($WS);
echo "── FINAL STATE DELTA ──\n";
foreach ($after as $k => $v) {
    $d = $v - $before[$k];
    echo sprintf("  %-22s %d (delta %s%d)\n", $k, $v, $d>=0?'+':'', $d);
}
echo "\n";

echo "── ARTIFACTS ──\n";
echo "  test article_id = $newArticleId  (delete with: php artisan tinker -> DB::table('articles')->where('id', $newArticleId)->delete())\n";