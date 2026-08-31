<?php
/**
 * INC-0006 §10 + §5 — provenance completeness. READ ONLY.
 *
 * Two questions the review demands answers to before any merge:
 *
 *  §10  For history left in source workspaces: how much of it can actually be attributed to a website?
 *       TOTAL / HAS website_id / NULL but RESOLVABLE / UNRESOLVABLE.
 *
 *  §5   For populated tables WITHOUT website_id: is the record truly business-wide, or is it website-specific with
 *       the website implied by the workspace? The test used here is whether the source workspace contained exactly
 *       one website at the time — if so the record is *derivably* website-specific; if the workspace held several
 *       (or none), it is ambiguous and must not be merged blind.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$SOURCES = [990100, 999909, 999924, 999925, 999926, 999993, 999997];
$in = implode(',', $SOURCES);

// how many websites each source workspace holds — the basis for "derivable"
$siteCount = [];
foreach (DB::select("SELECT workspace_id, COUNT(*) c FROM websites WHERE workspace_id IN ({$in}) AND deleted_at IS NULL GROUP BY workspace_id") as $r) {
    $siteCount[(int) $r->workspace_id] = (int) $r->c;
}
echo "source workspace → websites it holds\n";
foreach ($SOURCES as $ws) printf("  %-8d %d\n", $ws, $siteCount[$ws] ?? 0);

$derivable = array_keys(array_filter($siteCount, fn ($c) => $c === 1));
$ambiguous = array_keys(array_filter($siteCount, fn ($c) => $c > 1));
printf("\nderivable (exactly one website): %s\n", $derivable ? implode(', ', $derivable) : 'none');
printf("ambiguous (more than one)      : %s\n", $ambiguous ? implode(', ', $ambiguous) : 'none');
printf("no website at all              : %s\n\n", implode(', ', array_values(array_diff($SOURCES, array_keys($siteCount)))));

// §10 — tables that CAN express a website
$withSite = DB::table('information_schema.COLUMNS')
    ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('COLUMN_NAME', 'website_id')
    ->pluck('TABLE_NAME')->unique()->all();

printf("%-30s %-8s %-9s %-11s %s\n", 'TABLE (has website_id)', 'TOTAL', 'HAS SITE', 'RESOLVABLE', 'UNRESOLVABLE');
echo str_repeat('-', 78), "\n";
foreach ($withSite as $t) {
    try {
        $hasWs = DB::selectOne("SELECT COUNT(*) c FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='workspace_id'",
                               [DB::getDatabaseName(), $t])->c;
        if (! $hasWs) continue;
        $tot = (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN ({$in})")->c;
        if ($tot === 0) continue;
        $has = (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN ({$in}) AND website_id IS NOT NULL")->c;
        $res = $derivable
            ? (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN (" . implode(',', $derivable) . ") AND website_id IS NULL")->c
            : 0;
        printf("%-30s %-8d %-9d %-11d %d\n", $t, $tot, $has, $res, $tot - $has - $res);
    } catch (\Throwable $e) { /* skip */ }
}

// §5 — populated tables WITHOUT website_id: how much sits in a derivable vs ambiguous workspace
echo "\n\npopulated tables with NO website_id — can the website be derived from the workspace?\n";
printf("%-34s %-8s %-12s %-11s %s\n", 'TABLE', 'ROWS', 'DERIVABLE', 'AMBIGUOUS', 'NO SITE AT ALL');
echo str_repeat('-', 84), "\n";

$all = DB::table('information_schema.COLUMNS')
    ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('COLUMN_NAME', 'workspace_id')
    ->pluck('TABLE_NAME')->unique()->all();
$noSiteWs = array_values(array_diff($SOURCES, array_keys($siteCount)));

$totDeriv = 0; $totAmb = 0; $totNone = 0;
foreach ($all as $t) {
    if (in_array($t, $withSite, true)) continue;
    try {
        $tot = (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN ({$in})")->c;
        if ($tot === 0) continue;
        $d = $derivable ? (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN (" . implode(',', $derivable) . ")")->c : 0;
        $a = $ambiguous ? (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN (" . implode(',', $ambiguous) . ")")->c : 0;
        $n = $noSiteWs ? (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN (" . implode(',', $noSiteWs) . ")")->c : 0;
        $totDeriv += $d; $totAmb += $a; $totNone += $n;
        if ($tot >= 20) printf("%-34s %-8d %-12d %-11d %d\n", $t, $tot, $d, $a, $n);
    } catch (\Throwable $e) { /* skip */ }
}
printf("\nacross ALL such tables — derivable %d | ambiguous %d | workspace has no website %d\n", $totDeriv, $totAmb, $totNone);
