<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$svc = app(\App\Engines\SEO\Services\SeoService::class);

echo "=== BACKFILL: rescore every seo_content_index row ===\n";
$rows = DB::table('seo_content_index')->select('id','workspace_id','url','content_score AS old_score')->get();
$total = count($rows);
$changed = 0; $upper = 0; $lower = 0; $same = 0; $errors = 0;
foreach ($rows as $r) {
    try {
        $res = $svc->rescoreAfterMetaEdit((int)$r->workspace_id, (int)$r->id);
        $new = (int)($res['score'] ?? 0);
        $old = $r->old_score === null ? null : (int)$r->old_score;
        if ($old === null || $new !== $old) {
            $changed++;
            if ($old !== null && $new > $old) $upper++;
            if ($old !== null && $new < $old) $lower++;
        } else {
            $same++;
        }
    } catch (\Throwable $e) {
        $errors++;
        echo "  ERR id={$r->id}: ".$e->getMessage()."\n";
    }
}
echo "  total={$total} changed={$changed} (up={$upper} down={$lower}) same={$same} errors={$errors}\n";

echo "\n=== BACKFILL: articles.seo_score from SCI.content_score ===\n";
$arts = DB::table('articles')->select('id','workspace_id','slug')->whereNotNull('slug')->get();
$updated = 0; $missed = 0;
foreach ($arts as $a) {
    $siteUrl = DB::table('seo_settings')->where('workspace_id',$a->workspace_id)->where('key','site_url')->value('value');
    if (!$siteUrl) { $missed++; continue; }
    $url = rtrim($siteUrl,'/').'/'.ltrim($a->slug,'/');
    $sci = DB::table('seo_content_index')
        ->where('workspace_id',$a->workspace_id)
        ->where('url_hash', md5($url))
        ->first();
    if ($sci && $sci->content_score !== null) {
        DB::table('articles')->where('id',$a->id)->update([
            'seo_score' => $sci->content_score,
            'updated_at' => now(),
        ]);
        $updated++;
    } else {
        $missed++;
    }
}
echo "  articles_updated={$updated} missed={$missed}\n";

echo "\n=== SCORE DISTRIBUTION AFTER BACKFILL ===\n";
$rows = DB::select("
SELECT 'sci' AS tbl, COUNT(*) AS n,
       SUM(content_score IS NULL) AS null_score,
       SUM(content_score > 100)   AS over_100,
       SUM(content_score = 0)     AS zero,
       MIN(content_score) AS mn, MAX(content_score) AS mx, ROUND(AVG(content_score),1) AS avg
FROM seo_content_index
UNION ALL
SELECT 'articles', COUNT(*),
       SUM(seo_score IS NULL),
       SUM(seo_score > 100),
       SUM(seo_score = 0),
       MIN(seo_score), MAX(seo_score), ROUND(AVG(seo_score),1)
FROM articles
");
foreach ($rows as $r) {
    echo sprintf("  %s n=%d null=%d over100=%d zero=%d min=%s max=%s avg=%s\n",
        $r->tbl, $r->n, $r->null_score, $r->over_100, $r->zero, $r->mn, $r->mx, $r->avg);
}

echo "\n=== readability_score backfill (was 100% NULL) ===\n";
$row = DB::selectOne("SELECT COUNT(*) AS n, SUM(readability_score IS NULL) AS nulls FROM seo_content_index");
echo "  total={$row->n} null_readability={$row->nulls}\n";

echo "\n=== score_version distribution ===\n";
$rows = DB::select("SELECT score_version, COUNT(*) AS n FROM seo_content_index GROUP BY score_version");
foreach ($rows as $r) { echo "  v={$r->score_version} n={$r->n}\n"; }

echo "\nDONE\n";