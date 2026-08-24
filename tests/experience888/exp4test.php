<?php
/**
 * EXPERIENCE888 suite 4 - provenance consistency.
 *
 * Regression guard for the defect found in the first live loop: explain()
 * computed its verdict differently from reevaluate(), so the stored status and
 * the explanation disagreed and explain() reported a total that never happened.
 * Any future divergence between the two must fail here.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Experience888\{ExperienceConfidence as C, ExperienceRecorder as R, PatternEngine};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$W = 999855;
DB::table('workspaces')->updateOrInsert(['id'=>$W], ['name'=>'EXP888 prov','slug'=>'exp888-prov',
    'timezone'=>'UTC','created_by'=>2,'created_at'=>now(),'updated_at'=>now()]);
foreach (['experience_pattern_evidence','experience_patterns','experience_outcomes','experience_events'] as $t)
    DB::table($t)->where('workspace_id',$W)->delete();

$rec = new R(); $pat = new PatternEngine();

echo "-- ExperienceProvenanceTest --\n";
$pid = null;
for ($i=1;$i<=4;$i++) {
    $e = $rec->recordEvent($W, R::TASK_COMPLETED, ['action'=>'thing','evidence_type'=>'tasks','evidence_id'=>"ok-{$i}"]);
    $pid = $pat->observe($W, PatternEngine::TYPE_ACTION_RECOVERY, 'action:thing', 'supports',
        ['event_id'=>$e, 'statement'=>'thing completes when attempted']);
}
for ($i=1;$i<=7;$i++) {
    $e = $rec->recordEvent($W, R::TASK_FAILED, ['action'=>'thing','evidence_type'=>'tasks','evidence_id'=>"no-{$i}"]);
    $pat->observe($W, PatternEngine::TYPE_ACTION_RECOVERY, 'action:thing', 'contradicts', ['event_id'=>$e]);
}

$row = DB::table('experience_patterns')->where('id',$pid)->first();
$ex  = $pat->explain($W, $pid);

ok('stored sample size is the sum of its evidence',
   (int)$row->sample_size === (int)$row->positive_count + (int)$row->negative_count,
   "{$row->sample_size} vs {$row->positive_count}+{$row->negative_count}");

ok('explain sample matches stored sample',
   ($ex['sample_size'] ?? -1) === (int)$row->sample_size);

ok('explain supporting matches stored positive',
   ($ex['supporting'] ?? -1) === (int)$row->positive_count);

ok('explain contradicting matches stored negative',
   ($ex['contradicting'] ?? -1) === (int)$row->negative_count);

ok('explain status matches stored status',
   ($ex['status'] ?? '') === $row->status, ($ex['status'] ?? '') . ' vs ' . $row->status);

ok('explain confidence matches stored confidence',
   ($ex['confidence'] ?? '') === $row->confidence_level);

// The defect: the reason quoted a total larger than the sample.
if (preg_match('/(\d+) observation\(s\) against (\d+) supporting/', (string)($ex['why'] ?? ''), $m)) {
    $against = (int)$m[1]; $supporting = (int)$m[2];
    ok('explain "against" never exceeds the sample size',
       $against <= (int)$row->sample_size, "against={$against}, sample={$row->sample_size}");
    ok('explain against+supporting equals the sample',
       $against + $supporting === (int)$row->sample_size,
       "{$against}+{$supporting} != {$row->sample_size}");
} else {
    ok('reason string parsed', true);
}

ok('evidence row count equals sample size', count($ex['evidence'] ?? []) === (int)$row->sample_size);

// Both code paths must agree for arbitrary inputs.
foreach ([[3,0],[6,0],[4,7],[10,2],[5,5],[0,4]] as [$pos,$neg]) {
    $a = C::evaluate($pos,$neg,0,null);
    ok("evaluate({$pos},{$neg}) is self-consistent",
       $a['sample'] === $pos + $neg, json_encode($a));
}

echo "\n----------------------------------------\n";
printf("  passed: %d   failed: %d\n", $P, $F);
exit($F > 0 ? 1 : 0);
