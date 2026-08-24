<?php
/**
 * EXPERIENCE888 suite 1 â€” confidence, event/outcome capture, pattern formation,
 * contradiction, decay, workspace isolation.
 *
 * Isolated workspaces 999851/999852. Chef Red (ws2) is never touched.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Experience888\{ExperienceConfidence as C, ExperienceRecorder as R, PatternEngine};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; }
    else { $F++; echo "  FAIL: {$what}" . ($extra ? " â€” {$extra}" : '') . "\n"; }
}

$A = 999851; $B = 999852;
foreach ([$A, $B] as $w) {
    DB::table('workspaces')->updateOrInsert(['id' => $w], [
        'name' => "EXP888 ws{$w}", 'slug' => "exp888-{$w}", 'timezone' => 'UTC',
        'created_by' => 2, 'created_at' => now(), 'updated_at' => now()]);
}
$clean = function () use ($A, $B) {
    foreach (['experience_pattern_evidence','experience_patterns','experience_outcomes',
              'experience_events','experience_owner_feedback','experience_playbook_runs',
              'experience_playbooks'] as $t)
        DB::table($t)->whereIn('workspace_id', [$A, $B])->delete();
};
$clean();

$rec = new R();
$pat = new PatternEngine();

// â”€â”€ CONFIDENCE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "-- ExperienceConfidenceTest --\n";
ok('single observation is never a pattern', C::evaluate(1, 0)['status'] === C::STATUS_HYPOTHESIS);
ok('two observations still a hypothesis', C::evaluate(2, 0)['status'] === C::STATUS_HYPOTHESIS);
ok('3 consistent = MEDIUM pattern', C::evaluate(3, 0)['level'] === C::MEDIUM,
   json_encode(C::evaluate(3, 0)));
ok('6 consistent = HIGH experience', C::evaluate(6, 0)['level'] === C::HIGH);
ok('6 consistent status EXPERIENCE', C::evaluate(6, 0)['status'] === C::STATUS_EXPERIENCE);
ok('mixed 7/5 is not HIGH', C::evaluate(7, 5)['level'] !== C::HIGH);
ok('contradictions >= support = CONTRADICTED', C::evaluate(4, 0, 4)['status'] === C::STATUS_CONTRADICTED);
ok('age beyond dead limit = STALE', C::evaluate(8, 0, 0, 200)['status'] === C::STATUS_STALE);
ok('age past stale limit demotes one level', C::evaluate(8, 0, 0, 90)['level'] === C::MEDIUM,
   json_encode(C::evaluate(8, 0, 0, 90)));
ok('no evidence = LOW', C::evaluate(0, 0)['level'] === C::LOW);
ok('reason is always populated', C::evaluate(6, 0)['reason'] !== '');
ok('correlated attribution caps confidence', C::capForAttribution(C::HIGH, 'CORRELATED') === C::LOW);
ok('direct attribution preserves confidence', C::capForAttribution(C::HIGH, 'DIRECTLY_ATTRIBUTABLE') === C::HIGH);
ok('likely contributor demotes once', C::capForAttribution(C::HIGH, 'LIKELY_CONTRIBUTOR') === C::MEDIUM);
ok('deterministic: same input same output',
   C::evaluate(5, 2, 1, 10) === C::evaluate(5, 2, 1, 10));

// â”€â”€ EVENT CAPTURE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "-- ExperienceEventTest --\n";
$e1 = $rec->recordEvent($A, R::TASK_COMPLETED, [
    'capability' => 'write', 'action' => 'write_article',
    'evidence_type' => 'tasks', 'evidence_id' => '1001']);
ok('event recorded', $e1 > 0);

$e1again = $rec->recordEvent($A, R::TASK_COMPLETED, [
    'capability' => 'write', 'action' => 'write_article',
    'evidence_type' => 'tasks', 'evidence_id' => '1001']);
ok('idempotent: same evidence returns same row', $e1 === $e1again);
ok('idempotency did not duplicate', DB::table('experience_events')->where('workspace_id',$A)->count() === 1);

$threw = false;
try { $rec->recordEvent($A, 'NOT_A_REAL_TYPE', ['evidence_type'=>'tasks','evidence_id'=>'2']); }
catch (\InvalidArgumentException) { $threw = true; }
ok('unknown event type refused', $threw);

$threw = false;
try { $rec->recordEvent($A, R::TASK_COMPLETED, []); }
catch (\InvalidArgumentException) { $threw = true; }
ok('event without provenance refused', $threw);

$threw = false;
try { $rec->recordEvent(0, R::TASK_COMPLETED, ['evidence_type'=>'tasks','evidence_id'=>'3']); }
catch (\InvalidArgumentException) { $threw = true; }
ok('event without workspace refused', $threw);

// â”€â”€ OUTCOME + ATTRIBUTION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "-- ExperienceOutcomeTest --\n";
$o1 = $rec->recordOutcome($A, $e1, 'succeeded', [
    'attribution_level' => R::ATTR_DIRECT,
    'attribution_basis' => 'the task row itself reached status=completed',
    'evidence_type' => 'tasks', 'evidence_id' => '1001']);
ok('outcome recorded', $o1 > 0);

$threw = false;
try { $rec->recordOutcome($A, $e1, 'succeeded', ['attribution_level' => 'CAUSED_IT']); }
catch (\InvalidArgumentException) { $threw = true; }
ok('unknown attribution level refused', $threw);

$threw = false;
try { $rec->recordOutcome($A, $e1, 'traffic_rose', ['attribution_level' => R::ATTR_LIKELY]); }
catch (\InvalidArgumentException) { $threw = true; }
ok('attribution above UNKNOWN requires a basis', $threw);

$threw = false;
try { $rec->recordOutcome($B, $e1, 'succeeded', ['attribution_level' => R::ATTR_UNKNOWN]); }
catch (\RuntimeException) { $threw = true; }
ok('cross-workspace outcome refused', $threw);

// â”€â”€ PATTERN FORMATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "-- ExperiencePatternTest --\n";
$pid = null;
for ($i = 1; $i <= 2; $i++) {
    $ev = $rec->recordEvent($A, R::TASK_FAILED, [
        'capability'=>'write','action'=>'write_article',
        'evidence_type'=>'tasks','evidence_id'=>"retry-{$i}"]);
    // The statement is "retrying rarely recovers", so a failed retry SUPPORTS it.
    $pid = $pat->observe($A, PatternEngine::TYPE_ACTION_RECOVERY, 'retry:write_article', 'supports', [
        'event_id'=>$ev, 'statement'=>'Retrying write_article rarely recovers']);
}
$p = DB::table('experience_patterns')->where('id',$pid)->first();
ok('2 observations stays a hypothesis', $p->status === C::STATUS_HYPOTHESIS, $p->status);

for ($i = 3; $i <= 8; $i++) {
    $ev = $rec->recordEvent($A, R::TASK_FAILED, [
        'capability'=>'write','action'=>'write_article',
        'evidence_type'=>'tasks','evidence_id'=>"retry-{$i}"]);
    $pat->observe($A, PatternEngine::TYPE_ACTION_RECOVERY, 'retry:write_article', 'supports', ['event_id'=>$ev]);
}
$p = DB::table('experience_patterns')->where('id',$pid)->first();
ok('8 consistent observations form a pattern', in_array($p->status,[C::STATUS_PATTERN,C::STATUS_EXPERIENCE],true), $p->status);
ok('sample size derived from evidence', (int)$p->sample_size === 8, "got {$p->sample_size}");

$dupe = $pat->observe($A, PatternEngine::TYPE_ACTION_RECOVERY, 'retry:write_article', 'supports',
    ['event_id' => DB::table('experience_events')->where('workspace_id',$A)->where('evidence_id','retry-3')->value('id')]);
$p2 = DB::table('experience_patterns')->where('id',$pid)->first();
ok('re-observing the same evidence does not inflate the sample', (int)$p2->sample_size === 8, "got {$p2->sample_size}");

// â”€â”€ CONTRADICTION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "-- ExperienceContradictionTest --\n";
$sid = null;
for ($i=1;$i<=6;$i++) {
    $ev = $rec->recordEvent($A, R::CONTENT_PUBLISHED, ['capability'=>'write','action'=>'publish_article',
        'evidence_type'=>'articles','evidence_id'=>"pub-{$i}"]);
    $sid = $pat->observe($A, PatternEngine::TYPE_PUBLISH_OUTCOME, 'publish:tuesday', 'supports', ['event_id'=>$ev]);
}
$before = DB::table('experience_patterns')->where('id',$sid)->first();
ok('6 supports reaches HIGH', $before->confidence_level === C::HIGH, $before->confidence_level);

for ($i=7;$i<=12;$i++) {
    $ev = $rec->recordEvent($A, R::CONTENT_PUBLISHED, ['capability'=>'write','action'=>'publish_article',
        'evidence_type'=>'articles','evidence_id'=>"pub-{$i}"]);
    $pat->observe($A, PatternEngine::TYPE_PUBLISH_OUTCOME, 'publish:tuesday', 'contradicts', ['event_id'=>$ev]);
}
$after = DB::table('experience_patterns')->where('id',$sid)->first();
ok('contradicting evidence lowers confidence',
   C::rank($after->confidence_level) < C::rank($before->confidence_level),
   "{$before->confidence_level} -> {$after->confidence_level}");
ok('equal contradiction marks CONTRADICTED', $after->status === C::STATUS_CONTRADICTED, $after->status);

// â”€â”€ DECAY â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "-- ExperienceDecayTest --\n";
$old = $pat->observe($A, PatternEngine::TYPE_FAILURE_CLASS, 'stale:thing', 'supports',
    ['observed_at' => now()->subDays(200), 'statement' => 'an old pattern']);
for ($i=2;$i<=6;$i++) {
    $ev = $rec->recordEvent($A, R::TASK_FAILED, ['evidence_type'=>'tasks','evidence_id'=>"old-{$i}"]);
    $pat->observe($A, PatternEngine::TYPE_FAILURE_CLASS, 'stale:thing', 'supports',
        ['event_id'=>$ev, 'observed_at'=>now()->subDays(200)]);
}
$o = DB::table('experience_patterns')->where('id',$old)->first();
ok('long-unobserved pattern goes STALE', $o->status === C::STATUS_STALE, $o->status);
ok('stale pattern is not offered to Sarah',
   !in_array($old, array_map(fn($x)=>$x->id, $pat->usable($A, PatternEngine::TYPE_FAILURE_CLASS)), true));

// â”€â”€ WORKSPACE ISOLATION (hard gate) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "-- ExperienceWorkspaceIsolationTest --\n";
$evB = $rec->recordEvent($B, R::TASK_FAILED, ['capability'=>'write','action'=>'write_article',
    'evidence_type'=>'tasks','evidence_id'=>'b-1']);
for ($i=1;$i<=8;$i++) {
    $e = $rec->recordEvent($B, R::TASK_FAILED, ['capability'=>'write','action'=>'write_article',
        'evidence_type'=>'tasks','evidence_id'=>"b-retry-{$i}"]);
    $pat->observe($B, PatternEngine::TYPE_ACTION_RECOVERY, 'retry:write_article', 'supports', ['event_id'=>$e]);
}
$aPat = $pat->usable($A, PatternEngine::TYPE_ACTION_RECOVERY, 20);
$bPat = $pat->usable($B, PatternEngine::TYPE_ACTION_RECOVERY, 20);
ok('A and B hold same-named subject independently', count($aPat) >= 0 && count($bPat) === 1);
foreach ($bPat as $x) ok('B pattern is owned by B', (int)$x->workspace_id === $B);
foreach ($aPat as $x) ok('A pattern is owned by A', (int)$x->workspace_id === $A);

$aIds = array_map(fn($x)=>(int)$x->id, $aPat);
$bIds = array_map(fn($x)=>(int)$x->id, $bPat);
ok('no shared pattern rows between workspaces', array_intersect($aIds,$bIds) === []);
ok('explain() refuses a foreign pattern', $pat->explain($A, $bIds[0] ?? 0) === []);

$threw = false;
try { $pat->reevaluate($A, $bIds[0] ?? 0); } catch (\RuntimeException) { $threw = true; }
ok('reevaluate refuses a foreign pattern', $threw);

ok('B sees only its own events',
   DB::table('experience_events')->where('workspace_id',$B)->count() === 9,
   (string) DB::table('experience_events')->where('workspace_id',$B)->count());

// â”€â”€ EXPLAINABILITY â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "-- ExperienceExplainabilityTest --\n";
$ex = $pat->explain($A, $pid);
ok('explain returns the statement', !empty($ex['statement']));
ok('explain reports sample size', ($ex['sample_size'] ?? 0) === 8);
ok('explain gives a deterministic reason', !empty($ex['why']));
ok('explain lists its evidence rows', count($ex['evidence'] ?? []) === 8);
ok('explain says what would change its mind', !empty($ex['would_change_my_mind']));
ok('explain carries first/last observed', !empty($ex['first_observed']) && !empty($ex['last_observed']));

echo "\n----------------------------------------\n";
printf("  passed: %d   failed: %d\n", $P, $F);
exit($F > 0 ? 1 : 0);

