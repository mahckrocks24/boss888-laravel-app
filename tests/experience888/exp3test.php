<?php
/**
 * EXPERIENCE888 suite 3 - retrieval, authority hierarchy, negative learning,
 * uncertainty on thin evidence, and isolation of the read path.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Experience888\{ExperienceConfidence as C, ExperienceRecorder as R,
    PatternEngine, OwnerFeedbackClassifier as OF, PlaybookGovernor as PG, ExperienceRetriever};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$A = 999853; $B = 999854;
foreach ([$A,$B] as $w)
    DB::table('workspaces')->updateOrInsert(['id'=>$w], ['name'=>"EXP888 r{$w}",'slug'=>"exp888-r{$w}",
        'timezone'=>'UTC','created_by'=>2,'created_at'=>now(),'updated_at'=>now()]);
foreach (['experience_pattern_evidence','experience_patterns','experience_outcomes','experience_events',
          'experience_owner_feedback','experience_playbook_runs','experience_playbooks'] as $t)
    DB::table($t)->whereIn('workspace_id',[$A,$B])->delete();

$rec = new R(); $pat = new PatternEngine(); $of = new OF(); $pg = new PG();
$ret = new ExperienceRetriever();

echo "-- ExperienceSarahIntegrationTest --\n";
ok('empty store yields no experience block', $ret->forTurn($A, 'what should I do?') === '');

// Negative evidence: write_article mostly fails.
for ($i=1;$i<=10;$i++) {
    $e = $rec->recordEvent($A, R::TASK_FAILED, ['capability'=>'write','action'=>'write_article',
        'evidence_type'=>'tasks','evidence_id'=>"wa-f{$i}"]);
    $pat->observe($A, PatternEngine::TYPE_ACTION_RECOVERY, 'action:write_article', 'contradicts',
        ['event_id'=>$e, 'statement'=>'write_article completes successfully when attempted']);
}
for ($i=1;$i<=2;$i++) {
    $e = $rec->recordEvent($A, R::TASK_COMPLETED, ['capability'=>'write','action'=>'write_article',
        'evidence_type'=>'tasks','evidence_id'=>"wa-s{$i}"]);
    $pat->observe($A, PatternEngine::TYPE_ACTION_RECOVERY, 'action:write_article', 'supports', ['event_id'=>$e]);
}
$row = DB::table('experience_patterns')->where('workspace_id',$A)->where('subject','action:write_article')->first();
ok('mostly-failing action is CONTRADICTED', $row->status === C::STATUS_CONTRADICTED, $row->status);

$block = $ret->forTurn($A, 'Should we retry the failed write_article tasks?');
ok('refuted evidence IS surfaced to Sarah', str_contains($block, 'REFUTES'), $block);
ok('refutation quotes the real counts', str_contains($block, '10 of 12'), $block);
ok('block is labelled as workspace-only', str_contains($block, 'this workspace only'));
ok('block states it grants no authorisation', str_contains($block, 'never grants authorisation'));
ok('block respects its budget', mb_strlen($block) <= ExperienceRetriever::BUDGET_CHARS);

// Irrelevant turn must not drag experience in.
$other = $ret->forTurn($A, 'Draft a board update about hiring.');
ok('irrelevant turn gets no write_article evidence', !str_contains($other, 'write_article'), $other);

echo "-- authority hierarchy --\n";
$of->record($A, 'caption_length', 'From now on always keep captions short and sharp.');
$block = $ret->forTurn($A, 'How should we write captions for write_article posts?');
$posPref = strpos($block, 'Owner');
$posPat  = strpos($block, 'REFUTES');
ok('owner preference is emitted', $posPref !== false, $block);
ok('owner preference outranks learned patterns',
   $posPat === false || $posPref < $posPat, $block);

echo "-- uncertainty on thin evidence --\n";
$e = $rec->recordEvent($A, R::CONTENT_PUBLISHED, ['capability'=>'write','action'=>'publish_article',
    'evidence_type'=>'articles','evidence_id'=>'thin-1']);
$pat->observe($A, PatternEngine::TYPE_PUBLISH_OUTCOME, 'publish:friday', 'supports',
    ['event_id'=>$e, 'statement'=>'Publishing on Friday performs better']);
$block = $ret->forTurn($A, 'Is Friday a good day to publish?');
ok('single observation is never asserted', !str_contains($block, 'Friday performs better'), $block);

echo "-- playbook surfaced only when evidenced --\n";
for ($i=1;$i<=8;$i++) {
    $e = $rec->recordEvent($A, R::CONTENT_PUBLISHED, ['evidence_type'=>'articles','evidence_id'=>"pbr-{$i}"]);
    $pid = $pat->observe($A, PatternEngine::TYPE_PUBLISH_OUTCOME, 'publish:backlog', 'supports',
        ['event_id'=>$e, 'statement'=>'Publishing the draft backlog restores output']);
}
$r = $pg->promoteFromPattern($A, $pid, ['name'=>'Publish backlog','trigger_type'=>'backlog']);
ok('evidenced playbook promotes', $r['promoted'] === true, $r['reason'] ?? '');
$block = $ret->forTurn($A, 'We have a publish backlog problem, what do you suggest?');
ok('active playbook is offered', str_contains($block, 'Publish backlog'), $block);

echo "-- explainability from provenance --\n";
$ex = $ret->explain($A, 'action:write_article');
ok('explain returns stored evidence', ($ex['sample_size'] ?? 0) === 12, json_encode($ex['sample_size'] ?? null));
ok('explain gives supporting/contradicting split',
   ($ex['supporting'] ?? -1) === 2 && ($ex['contradicting'] ?? -1) === 10);
ok('explain has a deterministic reason', !empty($ex['why']));
ok('explain answers what would change its mind', !empty($ex['would_change_my_mind']));
ok('explain lists evidence rows', count($ex['evidence'] ?? []) === 12);

echo "-- ExperienceWorkspaceIsolationTest (read path) --\n";
ok('workspace B sees nothing from A', $ret->forTurn($B, 'Should we retry write_article?') === '');
$of->record($B, 'caption_length', 'From now on always use long captions.');
$bBlock = $ret->forTurn($B, 'How should we write captions?');
ok('B sees only its own preference', str_contains($bBlock, 'long captions'), $bBlock);
ok('B does not see A preference', !str_contains($bBlock, 'short and sharp'), $bBlock);
ok('A does not see B preference',
   !str_contains($ret->forTurn($A, 'How should we write captions?'), 'long captions'));
ok('explain refuses across workspaces', $ret->explain($B, 'action:write_article') === []);

echo "-- read path writes nothing --\n";
$before = DB::table('experience_events')->where('workspace_id',$A)->count();
$ret->forTurn($A, 'Should we retry write_article?');
$ret->explain($A, 'action:write_article');
ok('retrieval is read-only',
   DB::table('experience_events')->where('workspace_id',$A)->count() === $before);

$src = file_get_contents('/var/www/levelup-staging/app/Core/Experience888/ExperienceRetriever.php');
ok('retriever contains no writes',
   !preg_match('/->(insert|update|delete|insertGetId|updateOrInsert)\s*\(/', $src));

echo "\n----------------------------------------\n";
printf("  passed: %d   failed: %d\n", $P, $F);
exit($F > 0 ? 1 : 0);
