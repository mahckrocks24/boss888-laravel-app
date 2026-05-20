<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$svc = app(\App\Engines\SEO\Services\SeoService::class);

echo "=== Find articles + run syncFromArticle ===\n";
$arts = DB::table('articles')->select('id','workspace_id','slug','title','seo_score AS old_score')
    ->whereNotNull('slug')->get();
$ran = 0; $failed = 0;
foreach ($arts as $a) {
    try {
        $full = DB::table('articles')->find($a->id);
        if (!$full) { $failed++; continue; }
        $svc->syncFromArticle((int)$a->workspace_id, $full);
        $ran++;
    } catch (\Throwable $e) {
        $failed++;
        echo "  ERR art_id={$a->id}: ".$e->getMessage()."\n";
    }
}
echo "  ran={$ran} failed={$failed}\n";

echo "\n=== articles.seo_score AFTER syncFromArticle backfill ===\n";
$row = DB::selectOne("
SELECT COUNT(*) AS n,
       SUM(seo_score IS NULL) AS nulls,
       MIN(seo_score) AS mn, MAX(seo_score) AS mx,
       ROUND(AVG(seo_score),1) AS avg
FROM articles");
echo "  total={$row->n} null={$row->nulls} min={$row->mn} max={$row->mx} avg={$row->avg}\n";

echo "\n=== sample 10 articles ===\n";
$rows = DB::select("SELECT id, workspace_id, LEFT(title,40) AS title, seo_score FROM articles ORDER BY id DESC LIMIT 10");
foreach ($rows as $r) {
    printf("  id=%-3d ws=%d  score=%-6s  %s\n", $r->id, $r->workspace_id, $r->seo_score === null ? 'NULL' : $r->seo_score, $r->title);
}

echo "\n=== SCI readability_score distribution AFTER ===\n";
$row = DB::selectOne("
SELECT COUNT(*) AS n,
       SUM(readability_score IS NULL) AS nulls,
       MIN(readability_score) AS mn,
       MAX(readability_score) AS mx,
       ROUND(AVG(readability_score),1) AS avg
FROM seo_content_index");
echo "  total={$row->n} null_readability={$row->nulls} min={$row->mn} max={$row->mx} avg={$row->avg}\n";

echo "\n=== content_score distribution AFTER full sync ===\n";
$row = DB::selectOne("
SELECT COUNT(*) AS n,
       SUM(content_score IS NULL) AS nulls,
       SUM(content_score > 100)   AS over100,
       MIN(content_score) AS mn, MAX(content_score) AS mx,
       ROUND(AVG(content_score),1) AS avg
FROM seo_content_index");
echo "  total={$row->n} null={$row->nulls} over100={$row->over100} min={$row->mn} max={$row->mx} avg={$row->avg}\n";

echo "\n=== Sample SCI rows with full breakdown ===\n";
$rows = DB::select("
SELECT id, LEFT(url,55) AS url, content_score, readability_score,
       has_schema, has_og, h2_count, image_count, internal_link_count,
       score_version
FROM seo_content_index
WHERE workspace_id=1
ORDER BY updated_at DESC LIMIT 6");
foreach ($rows as $r) {
    printf("  id=%-3d s=%-3d  read=%-5s schema=%d og=%d h2=%d img=%d il=%-2d v=%d  %s\n",
        $r->id, $r->content_score,
        $r->readability_score === null ? 'NULL' : $r->readability_score,
        $r->has_schema, $r->has_og, $r->h2_count, $r->image_count, $r->internal_link_count,
        $r->score_version, $r->url);
}

echo "\nDONE\n";