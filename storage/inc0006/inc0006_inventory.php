<?php
/**
 * INC-0006 forensic step 1 — inventory every workspace-scoped table.
 *
 * Read-only. Produces the backbone of the 165-table classification:
 *   table | has website_id | rows in the stranded workspaces | rows in the destination | unique indexes
 *
 * The unique-index column matters because a merge collides wherever a unique key does NOT include workspace_id
 * (or includes it and would produce a duplicate once the workspace value changes).
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$SCHEMA  = DB::getDatabaseName();
$SOURCES = [990100, 999909, 999924, 999925, 999926, 999993, 999997];
$DEST    = 2;

$tables = DB::table('information_schema.COLUMNS')
    ->where('TABLE_SCHEMA', $SCHEMA)->where('COLUMN_NAME', 'workspace_id')
    ->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->unique()->values()->all();

$withWebsite = DB::table('information_schema.COLUMNS')
    ->where('TABLE_SCHEMA', $SCHEMA)->where('COLUMN_NAME', 'website_id')
    ->pluck('TABLE_NAME')->unique()->flip();

$uniques = [];
foreach (DB::select("SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
                     FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'
                     GROUP BY TABLE_NAME, INDEX_NAME", [$SCHEMA]) as $r) {
    $uniques[$r->TABLE_NAME][] = $r->cols;
}

$in = implode(',', $SOURCES);
printf("%-42s %-8s %-9s %-9s %s\n", 'TABLE', 'website', 'stranded', 'dest', 'UNIQUE KEYS (excl PK)');
echo str_repeat('-', 130), "\n";

$totStranded = 0; $tablesWithData = 0;
foreach ($tables as $t) {
    try {
        $s = (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN ({$in})")->c;
        $d = (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id = ?", [$DEST])->c;
    } catch (\Throwable $e) { $s = -1; $d = -1; }
    $totStranded += max(0, $s);
    if ($s > 0) $tablesWithData++;
    $u = isset($uniques[$t]) ? implode(' | ', array_slice($uniques[$t], 0, 3)) : '';
    // only print tables that actually carry stranded rows, plus any with risky unique keys
    if ($s > 0) {
        printf("%-42s %-8s %-9d %-9d %s\n", $t, isset($withWebsite[$t]) ? 'yes' : '-', $s, $d, $u);
    }
}

echo "\n";
printf("workspace-scoped tables      : %d\n", count($tables));
printf("tables holding stranded rows : %d\n", $tablesWithData);
printf("total stranded rows          : %d\n", $totStranded);
printf("tables that also have website_id: %d\n", count($withWebsite));
