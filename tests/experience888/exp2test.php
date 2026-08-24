<?php
/**
 * EXPERIENCE888 suite 2 - owner feedback classification, preference supersession,
 * playbook promotion / application / demotion, and the governance firewall.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Experience888\{ExperienceConfidence as C, ExperienceRecorder as R,
    PatternEngine, OwnerFeedbackClassifier as OF, PlaybookGovernor as PG};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$A = 999851; $B = 999852;
foreach (['experience_playbook_runs','experience_playbooks','experience_owner_feedback'] as $t)
    DB::table($t)->whereIn('workspace_id', [$A, $B])->delete();

$of = new OF(); $pg = new PG(); $rec = new R(); $pat = new PatternEngine();

// -- CLASSIFICATION -------------------------------------------------------
echo "-- ExperienceOwnerFeedbackTest --\n";
$c = $of->classify('I prefer short captions, keep them short and sharp.');
ok('preference detected', $c['feedback_type'] === OF::PREFERENCE, $c['feedback_type']);
ok('preference is durable', $c['durable'] === true);

$c = $of->classify('For this campaign, long-form is fine.');
ok('one-off detected', $c['feedback_type'] === OF::ONE_OFF_INSTRUCTION, $c['feedback_type']);
ok('one-off is NOT durable', $c['durable'] === false);
ok('one-off marked scope limited', $c['scope_limited'] === true);

$c = $of->classify('From now on never publish without my approval.');
ok('standing policy detected', $c['feedback_type'] === OF::POLICY, $c['feedback_type']);
ok('policy is STRONG', $c['strength'] === 'STRONG');
ok('policy is durable', $c['durable'] === true);

$c = $of->classify('That number is wrong, we published 15 not 50.');
ok('fact correction detected', $c['feedback_type'] === OF::FACT_CORRECTION, $c['feedback_type']);
ok('fact correction is NOT durable policy', $c['durable'] === false);

$c = $of->classify('That worked well, traffic held up.');
ok('outcome feedback detected', $c['feedback_type'] === OF::OUTCOME_FEEDBACK, $c['feedback_type']);
ok('outcome feedback is not a rule', $c['durable'] === false);

$c = $of->classify("We're dropping the wholesale market for now.");
ok('strategic decision detected', $c['feedback_type'] === OF::STRATEGIC_DECISION, $c['feedback_type']);

// -- PREFERENCE LIFECYCLE (Boss's Phase 14 scenario) ----------------------
echo "-- ExperiencePreferenceTest --\n";
$f1 = $of->record($A, 'caption_length', "I don't want long captions for this brand. Keep them short and sharp.");
$cur = $of->current($A, 'caption_length');
ok('durable preference is in force', $cur && (int)$cur->id === $f1);

$f2 = $of->record($A, 'caption_length', 'For this campaign, long-form is fine.');
$cur = $of->current($A, 'caption_length');
ok('one-off does NOT supersede the standing preference', $cur && (int)$cur->id === $f1,
   'in force: ' . ($cur->id ?? 'none'));
ok('one-off still recorded for provenance',
   DB::table('experience_owner_feedback')->where('id',$f2)->exists());

$f3 = $of->record($A, 'caption_length', 'Change the standing rule. Long-form is now preferred from now on.');
$cur = $of->current($A, 'caption_length');
ok('explicit durable change DOES supersede', $cur && (int)$cur->id === $f3,
   'in force: ' . ($cur->id ?? 'none'));
ok('superseded row is stamped',
   DB::table('experience_owner_feedback')->where('id',$f1)->whereNotNull('superseded_at')->exists());
ok('supersedes_id records the chain',
   (int) DB::table('experience_owner_feedback')->where('id',$f3)->value('supersedes_id') === $f1);
ok('only one standing preference per subject', count($of->standing($A)) === 1);

// -- PLAYBOOK PROMOTION ---------------------------------------------------
echo "-- ExperiencePlaybookTest --\n";
DB::table('experience_pattern_evidence')->where('workspace_id',$A)->where('pattern_id','>',0)->whereIn('pattern_id',
    DB::table('experience_patterns')->where('workspace_id',$A)->where('subject','publish:drafts')->pluck('id'))->delete();
DB::table('experience_patterns')->where('workspace_id',$A)->where('subject','publish:drafts')->delete();

$weak = null;
for ($i=1;$i<=2;$i++) {
    $ev = $rec->recordEvent($A, R::CONTENT_PUBLISHED, ['evidence_type'=>'articles','evidence_id'=>"pbweak-{$i}"]);
    $weak = $pat->observe($A, PatternEngine::TYPE_PUBLISH_OUTCOME, 'publish:drafts', 'supports',
        ['event_id'=>$ev, 'statement'=>'Publishing finished drafts restores output']);
}
$r = $pg->promoteFromPattern($A, $weak, ['name'=>'Publish the draft backlog','trigger_type'=>'output_collapse']);
ok('weak pattern refuses promotion', $r['promoted'] === false, $r['reason'] ?? '');

for ($i=3;$i<=8;$i++) {
    $ev = $rec->recordEvent($A, R::CONTENT_PUBLISHED, ['evidence_type'=>'articles','evidence_id'=>"pbweak-{$i}"]);
    $pat->observe($A, PatternEngine::TYPE_PUBLISH_OUTCOME, 'publish:drafts', 'supports', ['event_id'=>$ev]);
}
$r = $pg->promoteFromPattern($A, $weak, [
    'name'=>'Publish the draft backlog','trigger_type'=>'output_collapse',
    'objective'=>'Restore customer-visible output without new spend',
    'steps'=>['clear approvals','publish drafts'],
    'reversal_conditions'=>['output does not recover after publishing']]);
ok('evidenced pattern promotes', $r['promoted'] === true, $r['reason'] ?? '');
$pbId = $r['playbook_id'] ?? 0;
ok('HIGH confidence promotes straight to ACTIVE', ($r['status'] ?? '') === PG::ACTIVE, $r['status'] ?? '');
ok('promotion reason cites evidence', str_contains($r['reason'] ?? '', 'observations'));

// -- APPLICATION + DEMOTION ----------------------------------------------
echo "-- ExperiencePlaybookApplicationTest --\n";
$run1 = $pg->apply($A, $pbId, ['conversation_id'=>'conv-x']);
ok('playbook application recorded', $run1 > 0);
$res = $pg->resolveRun($A, $run1, 'success');
ok('successful run keeps it ACTIVE', $res['status'] === PG::ACTIVE, $res['status']);

echo "-- ExperiencePlaybookDemotionTest --\n";
// Demotion happens mid-sequence, so a run can only be applied while the
// playbook is still applicable. That refusal IS the demotion working.
$failures = 0; $refused = false;
for ($n = 1; $n <= 3; $n++) {
    try { $rid = $pg->apply($A, $pbId); }
    catch (\RuntimeException) { $refused = true; break; }
    $pg->resolveRun($A, $rid, 'failure');
    $failures++;
}
$pb = DB::table('experience_playbooks')->where('id',$pbId)->first();
ok('repeated failure demotes the playbook',
   in_array($pb->status,[PG::DEGRADED,PG::DEPRECATED],true), $pb->status);
ok('failure count comes from run rows', (int)$pb->failure_count === $failures,
   "counted {$pb->failure_count}, recorded {$failures}");
ok('a deprecated playbook stops accepting applications',
   $pb->status !== PG::DEPRECATED || $refused,
   'status ' . $pb->status . ', refused=' . var_export($refused, true));

$threw = false;
try { $pg->apply($A, $pbId); } catch (\RuntimeException) { $threw = true; }
ok('deprecated playbook cannot be applied again',
   $pb->status === PG::DEPRECATED ? $threw : true);

// -- WORKSPACE ISOLATION --------------------------------------------------
echo "-- ExperiencePlaybook/Preference isolation --\n";
$of->record($B, 'caption_length', 'From now on always use long captions.');
$aPref = $of->current($A, 'caption_length');
$bPref = $of->current($B, 'caption_length');
ok('same subject, different workspaces, independent preferences',
   $aPref && $bPref && (int)$aPref->id !== (int)$bPref->id);
ok('A preference belongs to A', (int)$aPref->workspace_id === $A);
ok('B preference belongs to B', (int)$bPref->workspace_id === $B);
ok('B cannot see A playbooks', count($pg->usable($B)) === 0);

$threw = false;
try { $pg->apply($B, $pbId); } catch (\RuntimeException) { $threw = true; }
ok('applying another workspace playbook is refused', $threw);

$threw = false;
try { $pg->promoteFromPattern($B, $weak, ['name'=>'stolen']); } catch (\RuntimeException) { $threw = true; }
ok('promoting from a foreign pattern is refused', $threw);

// -- GOVERNANCE FIREWALL --------------------------------------------------
echo "-- ExperienceAuthorizationIsolationTest --\n";
$cols = array_map(fn($c)=>$c->Field, DB::select('SHOW COLUMNS FROM experience_playbooks'));
$forbidden = ['authorization_id','authorized','approval_id','permission','role','credit','entitlement'];
$leak = array_filter($cols, function ($c) use ($forbidden) {
    foreach ($forbidden as $f) if (str_contains($c, $f)) return true;
    return false;
});
ok('playbooks carry no authorization column', $leak === [], implode(',', $leak));

foreach (['experience_events','experience_outcomes','experience_patterns','experience_owner_feedback'] as $t) {
    $cc = array_map(fn($c)=>$c->Field, DB::select("SHOW COLUMNS FROM {$t}"));
    $bad = array_filter($cc, function ($c) use ($forbidden) {
        foreach ($forbidden as $f) if (str_contains($c, $f)) return true;
        return false;
    });
    ok("{$t} carries no authorization column", $bad === [], implode(',', $bad));
}

// Experience must not be able to satisfy the two-turn contract.
$src = file_get_contents('/var/www/levelup-staging/app/Core/Experience888/PlaybookGovernor.php')
     . file_get_contents('/var/www/levelup-staging/app/Core/Experience888/ExperienceRecorder.php')
     . file_get_contents('/var/www/levelup-staging/app/Core/Experience888/PatternEngine.php')
     . file_get_contents('/var/www/levelup-staging/app/Core/Experience888/OwnerFeedbackClassifier.php');
ok('Experience888 never writes approvals', !preg_match("/table\(\s*'approvals'/", $src));
ok('Experience888 never writes chat proposals', !preg_match("/table\(\s*'strategy_proposals'/", $src));
ok('Experience888 never touches credits', !preg_match("/table\(\s*'credits'/", $src));
ok('Experience888 never writes tasks', !preg_match("/table\(\s*'tasks'\s*\)->(insert|update)/", $src));
ok('Experience888 never calls the authorization binder', !str_contains($src, 'AuthorizationBinder'));

echo "\n----------------------------------------\n";
printf("  passed: %d   failed: %d\n", $P, $F);
exit($F > 0 ? 1 : 0);
