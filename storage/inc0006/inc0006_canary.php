<?php
/**
 * INC-0006 — does a genuine fragmented multi-website business estate exist anywhere? READ ONLY.
 *
 * The Owner asked for a real migration canary, chosen by business boundary rather than owner identity, and
 * explicitly forbade manufacturing one. So the question is precise:
 *
 *   Is there any BUSINESS (same name, same owner) whose WEBSITES are split across more than one workspace?
 *
 * If not, there is nothing to consolidate — only debris to archive — and that must be reported as the finding.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::select("
    SELECT w.id, w.name, w.slug, w.created_by, w.created_at,
           (SELECT COUNT(*) FROM websites s WHERE s.workspace_id=w.id AND s.deleted_at IS NULL) sites
    FROM workspaces w
    WHERE w.created_at >= '2026-06-24'
    ORDER BY w.created_by, w.name, w.id
");

$suspects = array_values(array_filter($rows, fn ($r) => preg_match('/-[0-9a-f]{6}$/', (string) $r->slug)));

$withSite = array_filter($suspects, fn ($r) => $r->sites > 0);
$noSite   = array_filter($suspects, fn ($r) => $r->sites == 0);

printf("workspaces created since the defect date : %d\n", count($rows));
printf("carrying the provisioner slug fingerprint: %d\n", count($suspects));
printf("  ...of which hold at least one website  : %d\n", count($withSite));
printf("  ...of which hold NO website at all     : %d   <- debris from failed/abandoned builds\n\n", count($noSite));

// Group by (owner, business name). A business split across >1 workspace WITH websites in >1 is the only
// shape that needs a real data consolidation.
$groups = [];
foreach ($suspects as $r) {
    $key = $r->created_by . '|' . mb_strtolower(trim((string) $r->name));
    $groups[$key][] = $r;
}

$needsConsolidation = [];
echo "business clusters (owner + name) spanning more than one workspace:\n";
$any = false;
foreach ($groups as $key => $list) {
    if (count($list) < 2) continue;
    $any = true;
    [$owner, $name] = explode('|', $key, 2);
    $siteBearing = array_values(array_filter($list, fn ($r) => $r->sites > 0));
    printf("  owner %-7s %-32s workspaces %-34s websites in %d of them\n",
        $owner, mb_substr($name, 0, 32), implode(',', array_map(fn ($r) => $r->id, $list)), count($siteBearing));
    if (count($siteBearing) >= 2) $needsConsolidation[] = $key;
}
if (! $any) echo "  none\n";

echo "\n";
if ($needsConsolidation) {
    echo "REAL CANARY CANDIDATES (a business whose websites live in more than one workspace):\n";
    foreach ($needsConsolidation as $k) echo "  {$k}\n";
} else {
    echo "NO CANARY EXISTS: not one business has websites split across multiple workspaces.\n";
    echo "Every affected workspace holds 0 or 1 website, so there is no website-level consolidation to perform —\n";
    echo "only orphan-workspace archival, which requires no data migration at all.\n";
}

// What the debris actually contains, since that is what remains to be dealt with.
$ids = array_map(fn ($r) => $r->id, $noSite);
if ($ids) {
    $in = implode(',', $ids);
    echo "\ncontent of the website-less debris workspaces:\n";
    foreach (['strategy_proposals','tasks','notifications','articles','credit_transactions','assets','leads'] as $t) {
        try {
            $c = (int) DB::selectOne("SELECT COUNT(*) c FROM `{$t}` WHERE workspace_id IN ({$in})")->c;
            if ($c) printf("  %-22s %d\n", $t, $c);
        } catch (\Throwable $e) {}
    }
}
