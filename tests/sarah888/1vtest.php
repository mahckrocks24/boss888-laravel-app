<?php
/**
 * SARAH888 Slice 1U/1V â€” derived knowledge, temporal grounding, compaction.
 * Covers the 20 proofs required by the derived-knowledge ruling.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use App\Core\Sarah888\{TemporalAnchor, DerivedState, CognitiveFrame, CommitmentStore};

$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  PASS  %s\n", $l); }
    else     { $fail++; printf("  FAIL  %s   %s\n", $l, $d); }
}

$time = app(TemporalAnchor::class);
$ds   = app(DerivedState::class);

// â”€â”€ isolated fixtures â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$WS_A = 990501;   // Europe/London
$WS_B = 990502;   // Pacific/Kiritimati  (UTC+14)
$WS_C = 990503;   // Pacific/Midway      (UTC-11)

function wipe(array $ids): void {
    DB::table('sarah_commitments')->whereIn('workspace_id', $ids)->delete();
    DB::table('approvals')->whereIn('workspace_id', $ids)->delete();
    DB::table('workspaces')->whereIn('id', $ids)->delete();
}
wipe([$WS_A, $WS_B, $WS_C]);

foreach ([[$WS_A,'Europe/London'], [$WS_B,'Pacific/Kiritimati'], [$WS_C,'Pacific/Midway']] as [$id,$tz]) {
    DB::table('workspaces')->insert([
        'id' => $id, 'name' => "TZ test $id", 'slug' => "tz-test-$id", 'timezone' => $tz, 'created_by' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

$today = CarbonImmutable::now('Europe/London')->startOfDay();
$mk = function (array $o) use ($WS_A): int {
    return (int) DB::table('sarah_commitments')->insertGetId(array_merge([
        'workspace_id' => $WS_A, 'title' => 'x', 'status' => 'active',
        'actor' => 'user', 'confidence' => 1.0, 'extraction_method' => 'seed',
        'verification_state' => 'unverified',
        'created_at' => now(), 'updated_at' => now(),
    ], $o));
};

$idOverdue  = $mk(['title' => 'Overdue kiln repair',   'deadline' => $today->subDays(10)->toDateString(), 'owner' => 'Bronwen', 'priority' => 'high']);
$idSoon     = $mk(['title' => 'Soon glaze order',      'deadline' => $today->addDays(3)->toDateString(),  'owner' => 'Aled']);
$idLater    = $mk(['title' => 'Later trade show',      'deadline' => $today->addDays(60)->toDateString(), 'owner' => 'Bronwen']);
$idNoDate   = $mk(['title' => 'Unscheduled audit',     'owner' => 'Bronwen']);
$idCancel   = $mk(['title' => 'Cancelled podcast',     'status' => 'cancelled']);
$idOld      = $mk(['title' => 'Superseded old name',   'status' => 'superseded', 'owner' => 'Meredith']);
$idNew      = $mk(['title' => 'Superseded old name',   'owner' => 'Aled', 'supersedes_id' => $idOld]);
// a commitment in ANOTHER workspace, to prove scoping
$otherId = (int) DB::table('sarah_commitments')->insertGetId([
    'workspace_id' => $WS_B, 'title' => 'Foreign commitment', 'status' => 'active',
    'actor' => 'user', 'confidence' => 1.0, 'extraction_method' => 'seed',
    'verification_state' => 'unverified', 'owner' => 'Stranger',
    'deadline' => $today->subDays(99)->toDateString(),
    'created_at' => now(), 'updated_at' => now(),
]);
DB::table('approvals')->insert([
    ['workspace_id' => $WS_A, 'engine' => 'write', 'action' => 'a', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
    ['workspace_id' => $WS_A, 'engine' => 'write', 'action' => 'b', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
    ['workspace_id' => $WS_A, 'engine' => 'write', 'action' => 'c', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
]);

echo "=== TEMPORAL GROUNDING ===\n";
$n = $time->now($WS_A);
chk('1  workspace local date is correct',
    $n['local']->toDateString() === CarbonImmutable::now('Europe/London')->toDateString(),
    $n['local']->toDateString());
chk('2  UTC date is correct',
    $n['utc']->toDateString() === CarbonImmutable::now('UTC')->toDateString(), $n['utc']->toDateString());

$b = $time->now($WS_B); $c = $time->now($WS_C);
chk('3a timezone resolved per workspace',
    $b['tz'] === 'Pacific/Kiritimati' && $c['tz'] === 'Pacific/Midway', "{$b['tz']} / {$c['tz']}");
$gap = $b['local']->format('Y-m-d H') !== $c['local']->format('Y-m-d H');
chk('3b UTC+14 and UTC-11 disagree about local time (midnight boundary)', $gap,
    $b['local']->toDateTimeString() . ' vs ' . $c['local']->toDateTimeString());
chk('3c the two are 25 hours apart',
    abs($b['local']->utcOffset() - $c['local']->utcOffset()) === 25 * 60,
    (string) abs($b['local']->utcOffset() - $c['local']->utcOffset()));
chk('3d unknown timezone falls back to UTC, does not throw',
    $time->timezone(99999999) === 'UTC');

echo "\n=== DETERMINISTIC DATE ARITHMETIC ===\n";
$r = $time->resolveExpression('24 days before 17 September', $WS_A);
$expect = CarbonImmutable::parse('17 September ' . $today->year, 'Europe/London');
if ($expect->lt($today)) $expect = $expect->addYear();
chk('4  17 September minus 24 days', $r && $r['value'] === $expect->subDays(24)->toDateString(),
    $r['value'] ?? 'null');
chk('5a leap year: 1 day after 28 Feb 2028 is 29 Feb',
    ($x = $time->resolveExpression('1 day after 28 February 2028', $WS_A)) && $x['value'] === '2028-02-29',
    $x['value'] ?? 'null');
chk('5b non-leap: 1 day after 28 Feb 2027 is 1 Mar',
    ($x = $time->resolveExpression('1 day after 28 February 2027', $WS_A)) && $x['value'] === '2027-03-01',
    $x['value'] ?? 'null');
chk('5c 1 year after 29 Feb 2028 lands in 2029',
    ($x = $time->resolveExpression('1 year after 29 February 2028', $WS_A)) && str_starts_with($x['value'], '2029-'),
    $x['value'] ?? 'null');
chk('5d 90 days after 1 Oct',
    ($x = $time->resolveExpression('90 days after 1 Oct', $WS_A)) !== null, $x['value'] ?? 'null');
chk('5e nonsense expression returns null, does not guess',
    $time->resolveExpression('sometime after the thing', $WS_A) === null);

echo "\n=== COUNTS OVER AUTHORITATIVE RECORDS ===\n";
$act = $ds->query('active_commitment_count', $WS_A);
chk('6  active commitment count', $act['value'] === 5, (string) $act['value']);   // overdue, soon, later, no-date, superseding successor
chk('7  cancelled excluded from active', !in_array($idCancel, $act['source_ids'], true));
chk('8  superseded excluded from active', !in_array($idOld, $act['source_ids'], true));
chk('7b cancelled counted in its own query',
    $ds->query('cancelled_commitment_count', $WS_A)['value'] === 1);
chk('8b superseded counted in its own query',
    $ds->query('superseded_commitment_count', $WS_A)['value'] === 1);

$early = $ds->query('earliest_deadline', $WS_A);
chk('9  earliest deadline is the overdue one', $early['source_ids'] === [$idOverdue], json_encode($early['source_ids']));
$next = $ds->query('next_deadline', $WS_A);
chk('9b next deadline is the soonest FUTURE one', $next['source_ids'] === [$idSoon], json_encode($next['source_ids']));
$over = $ds->query('overdue_commitment_count', $WS_A);
chk('10 overdue detected', $over['value'] === 1 && $over['items'][0]['days_overdue'] === 10,
    json_encode([$over['value'], $over['items'][0]['days_overdue'] ?? null]));

echo "\n=== OWNERSHIP ===\n";
chk('11 current owner read from the live row',
    $ds->query('current_owner', $WS_A, ['subject' => 'Superseded old name'])['value'] === 'Aled');
chk('12 previous owner walked from the supersession chain',
    ($p = $ds->query('previous_owner', $WS_A, ['subject' => 'Superseded old name']))['value'] === 'Meredith',
    json_encode($p['value']));
chk('12b previous owner is null when the chain proves nothing',
    $ds->query('previous_owner', $WS_A, ['subject' => 'Overdue kiln repair'])['value'] === null);
$by = $ds->query('commitments_by_owner', $WS_A);
chk('13 grouped by owner, ranked', ($by['value']['Bronwen'] ?? 0) === 3 && $by['top'] === 'Bronwen',
    json_encode($by['value']));

echo "\n=== OTHER DOMAINS ===\n";
chk('14 pending approvals counted, approved excluded',
    $ds->query('pending_approvals', $WS_A)['value'] === 2);

echo "\n=== TENANCY + CONTRACT ===\n";
chk('15a no cross-workspace commitments leak in',
    !in_array($otherId, $act['source_ids'], true));
chk('15b foreign workspace sees only its own',
    $ds->query('active_commitment_count', $WS_B)['value'] === 1);
chk('15c foreign overdue row does not become our earliest',
    $early['source_ids'] !== [$otherId]);
chk('15d date anchors resolve only within the workspace',
    $time->resolveExpression('2 days before Foreign commitment', $WS_A) === null);

$need = ['key','value','source_domain','source_ids','computed_at','rule','workspace_id','confidence','stale_after','calc_version'];
$missAny = [];
foreach (DerivedState::QUERIES as $q) {
    $f = $ds->query($q, $WS_A, ['subject' => 'Overdue kiln repair']);
    if ($d = array_diff($need, array_keys($f))) $missAny[$q] = $d;
    if ($f['workspace_id'] !== $WS_A) $missAny[$q . ':ws'] = true;
    if ($f['confidence'] !== 1.0) $missAny[$q . ':conf'] = true;
}
chk('16 every query returns the full derived-fact contract', !$missAny, json_encode($missAny));
chk('16b source ids are present for a counting query', count($act['source_ids']) === $act['value']);

$a1 = $ds->query('active_commitment_count', $WS_A);
$a2 = $ds->query('active_commitment_count', $WS_A);
chk('17 repeated query is deterministic',
    $a1['value'] === $a2['value'] && $a1['source_ids'] === $a2['source_ids'] && $a1['rule'] === $a2['rule']);

echo "\n=== FRAME BUDGET + COMPACTION ===\n";
$cf = app(CognitiveFrame::class);
$fr = $cf->build(990100, 'sarah');
chk('18a frame stays within its budget', $fr['chars'] <= CognitiveFrame::BUDGET_CHARS,
    "{$fr['chars']} / " . CognitiveFrame::BUDGET_CHARS);
chk('18b truncation is disclosed in the text, not just the return value',
    !$fr['truncated'] || preg_match('/TRUNCATED|OMITTED THIS TURN/', $fr['frame']) === 1);
chk('18c omission counts are reported', isset($fr['omitted']['live_total']));
chk('18d temporal block is never truncated', !isset($fr['truncated']['temporal']));
chk('18e derived block is never truncated', !isset($fr['truncated']['derived']));
chk('18f the anchor is actually in the text', str_contains($fr['frame'], date('Y-m-d')));

// high-priority / critical survive compaction on a large workspace
$store = app(CommitmentStore::class);
$rendered = $store->renderForPrompt(990100, 60, null);
$stats = $store->lastRenderStats;
chk('19a detail is rationed below the record count',
    $stats['live_detailed'] <= CommitmentStore::DETAIL_CAP && $stats['live_total'] > $stats['live_detailed'],
    json_encode($stats));
$overdueTitle = $ds->query('overdue_commitment_count', 990100)['items'][0]['title'] ?? null;
chk('19b the overdue commitment survives compaction',
    $overdueTitle && str_contains($rendered, mb_substr($overdueTitle, 0, 30)), (string) $overdueTitle);
chk('20a every live record is still accounted for (detail + summary)',
    $stats['live_detailed'] + $stats['live_summary'] === $stats['live_total'], json_encode($stats));
chk('20b the abridged remainder is disclosed as existing',
    str_contains($rendered, 'listed by name only') || str_contains($rendered, 'cannot see'));
$namedRender = $store->renderForPrompt(990100, 60, 'what about the studio rebuild?');
chk('20c a commitment named in the turn is pulled into detail',
    $store->lastRenderStats['named_in_turn'] > 0, json_encode($store->lastRenderStats));

echo "\n=== RECALL PROBES (production-shaped) ===\n";
chk('P1 derived date probe',    ($x = $time->resolveExpression('24 days before 17 September', $WS_A)) && $x['confidence'] === 1.0);
chk('P2 current count probe',   is_int($ds->query('active_commitment_count', 990100)['value']));
chk('P3 earliest deadline probe', $ds->query('earliest_deadline', 990100)['value'] !== null);
chk('P4 ownership aggregate probe', is_array($ds->query('commitments_by_owner', 990100)['value']));

wipe([$WS_A, $WS_B, $WS_C]);
echo "\n  cleanup: fixture workspaces removed\n";
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
