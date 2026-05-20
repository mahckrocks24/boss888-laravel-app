<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Backfill 1: seo_audit_items.score (pass=100/warning=50/error=0) ===\n";
$updated = DB::table('seo_audit_items')
    ->whereNull('score')
    ->where('status', 'pass')
    ->update(['score' => 100]);
echo "  pass rows scored: $updated\n";
$updated = DB::table('seo_audit_items')
    ->whereNull('score')
    ->where('status', 'warning')
    ->update(['score' => 50]);
echo "  warning rows scored: $updated\n";
$updated = DB::table('seo_audit_items')
    ->whereNull('score')
    ->whereIn('status', ['error', 'fail'])
    ->update(['score' => 0]);
echo "  error rows scored: $updated\n";
echo "  remaining null: " . DB::table('seo_audit_items')->whereNull('score')->count() . "\n";

echo "\n=== Backfill 2: rewrite seo_audits.results_json with per-category scores ===\n";
$audits = DB::table('seo_audits')->where('status', 'completed')->get();
$rewritten = 0;
foreach ($audits as $a) {
    $items = DB::table('seo_audit_items')->where('audit_id', $a->id)->get();
    if ($items->count() === 0) {
        echo "  skip audit {$a->id} (no items)\n";
        continue;
    }
    $byCat = [];
    foreach ($items as $it) {
        $cat = $it->category ?: 'general';
        if (!isset($byCat[$cat])) $byCat[$cat] = ['pass'=>0,'warn'=>0,'fail'=>0,'checks'=>[]];
        if ($it->status === 'pass') $byCat[$cat]['pass']++;
        elseif ($it->status === 'warning') $byCat[$cat]['warn']++;
        else $byCat[$cat]['fail']++;
        $byCat[$cat]['checks'][] = [
            'check' => $it->check_name,
            'status' => $it->status,
            'details' => $it->details,
            'category' => $cat,
        ];
    }
    $categories = [];
    foreach ($byCat as $cat => $b) {
        $n = $b['pass'] + $b['warn'] + $b['fail'];
        $score = $n > 0 ? (int) round((($b['pass'] + 0.5 * $b['warn']) / $n) * 100) : null;
        $categories[$cat] = ['score' => $score, 'checks' => $b['checks']];
    }
    if (isset($categories['meta'])) $categories['meta_tags'] = $categories['meta'];

    $pick = fn($k) => isset($categories[$k]) ? $categories[$k]['score'] : null;
    $avgOf = function($arr) {
        $v = array_values(array_filter($arr, fn($x) => $x !== null));
        return count($v) === 0 ? null : (int) round(array_sum($v) / count($v));
    };
    $techScore    = $avgOf([$pick('technical'), $pick('security'), $pick('performance'), $pick('mobile')]);
    $contentScore = $avgOf([$pick('content'), $pick('meta'), $pick('schema')]);

    $existing = [];
    try { $existing = json_decode((string) $a->results_json, true) ?: []; } catch (\Throwable $e) {}
    $existing['categories']     = $categories;
    $existing['tech_score']     = $techScore;
    $existing['content_score']  = $contentScore;
    $existing['internal_score'] = $existing['internal_score'] ?? null;
    $existing['serp_score']     = $existing['serp_score'] ?? null;
    $existing['technical']      = ['score' => $techScore];
    $existing['content']        = ['score' => $contentScore];
    $existing['internal']       = ['score' => $existing['internal_score']];
    $existing['serp']           = ['score' => $existing['serp_score']];

    DB::table('seo_audits')->where('id', $a->id)->update([
        'results_json' => json_encode($existing),
        'updated_at'   => now(),
    ]);
    $rewritten++;
}
echo "  audits rewritten: $rewritten / " . $audits->count() . "\n";

echo "\n=== Verify: latest audit results_json (ws 7) ===\n";
$latest = DB::table('seo_audits')->where('workspace_id', 7)->where('status', 'completed')->orderByDesc('id')->first();
if ($latest) {
    $r = json_decode($latest->results_json, true);
    echo "  audit id={$latest->id} url={$latest->url}\n";
    echo "  overall score={$latest->score}\n";
    echo "  tech_score=".($r['tech_score'] ?? 'null')."\n";
    echo "  content_score=".($r['content_score'] ?? 'null')."\n";
    echo "  internal_score=".($r['internal_score'] ?? 'null')."\n";
    echo "  serp_score=".($r['serp_score'] ?? 'null')."\n";
    echo "  categories:\n";
    foreach (($r['categories'] ?? []) as $cat => $cd) {
        if ($cat === 'meta_tags') continue;
        $n = count($cd['checks'] ?? []);
        echo "    $cat: score=".($cd['score'] ?? 'null')." checks=$n\n";
    }
}

echo "\n=== audit_items.score distribution ===\n";
$rows = DB::select("SELECT status, COUNT(*) AS n, SUM(score IS NULL) AS nulls, MIN(score) AS mn, MAX(score) AS mx, AVG(score) AS avg FROM seo_audit_items GROUP BY status");
foreach ($rows as $r) {
    echo "  status={$r->status} n={$r->n} null={$r->nulls} min={$r->mn} max={$r->mx} avg=".round($r->avg, 1)."\n";
}

echo "\nDONE\n";