<?php
/**
 * EXPERIENCE888 suite 5 - operational closure.
 *
 * Scheduler wiring, command idempotency, chat capture gating, chat
 * explainability, decay determinism, and cross-tenant isolation on the
 * SCHEDULED paths (not just the direct service calls).
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB, Artisan};
use App\Core\Experience888\{ExperienceConfidence as C, ExperienceRecorder as R,
    PatternEngine, OwnerFeedbackClassifier as OF, PlaybookGovernor as PG, ExperienceRetriever};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$A = 999861; $B = 999862;
foreach ([$A,$B] as $w)
    DB::table('workspaces')->updateOrInsert(['id'=>$w], ['name'=>"EXP888 op{$w}",'slug'=>"exp888-op{$w}",
        'timezone'=>'UTC','created_by'=>2,'created_at'=>now(),'updated_at'=>now()]);
foreach (['experience_pattern_evidence','experience_patterns','experience_outcomes','experience_events',
          'experience_owner_feedback','experience_playbook_runs','experience_playbooks'] as $t)
    DB::table($t)->whereIn('workspace_id',[$A,$B])->delete();
DB::table('tasks')->whereIn('workspace_id',[$A,$B])->delete();

$rec = new R(); $pat = new PatternEngine(); $of = new OF(); $pg = new PG();
$ret = app(ExperienceRetriever::class);

// -- SCHEDULER WIRING -----------------------------------------------------
echo "-- ExperienceSchedulerTest --\n";
Artisan::call('schedule:list');
$list = Artisan::output();
ok('ingest is scheduled', str_contains($list, 'experience888:ingest'), 'not in schedule:list');
ok('decay is scheduled', str_contains($list, 'experience888:decay'));
ok('ingest runs hourly', (bool) preg_match('/0\s+\*\s+\*\s+\*\s+\*\s+php artisan experience888:ingest/', $list));
ok('decay runs daily', (bool) preg_match('/20\s+3\s+\*\s+\*\s+\*\s+php artisan experience888:decay/', $list));

$cmds = array_keys(Artisan::all());
ok('ingest command is registered', in_array('experience888:ingest', $cmds, true));
ok('decay command is registered', in_array('experience888:decay', $cmds, true));

// -- SCHEDULED INGESTION + IDEMPOTENCY ------------------------------------
echo "-- ExperienceScheduledIngestTest --\n";
$mk = function (int $ws, string $status, string $action, int $n) {
    for ($i=1;$i<=$n;$i++)
        DB::table('tasks')->insert(['workspace_id'=>$ws,'engine'=>'write','action'=>$action,
            'status'=>$status,'payload_json'=>json_encode(['created_via'=>'sarah_proposal']),
            'created_at'=>now()->subDays(2),'updated_at'=>now()->subDays(2)]);
};
$mk($A,'completed','write_article',7);
$mk($A,'failed','write_article',1);

Artisan::call('experience888:ingest', ['--workspace' => $A, '--dry-run' => true]);
ok('dry run writes nothing', DB::table('experience_events')->where('workspace_id',$A)->count() === 0);

Artisan::call('experience888:ingest', ['--workspace' => $A]);
$after1 = DB::table('experience_events')->where('workspace_id',$A)->count();
ok('scheduled ingest creates events', $after1 === 8, "got {$after1}");

Artisan::call('experience888:ingest', ['--workspace' => $A]);
$after2 = DB::table('experience_events')->where('workspace_id',$A)->count();
ok('second run is idempotent (zero duplicates)', $after2 === $after1, "{$after1} -> {$after2}");
ok('evidence rows did not duplicate',
   DB::table('experience_pattern_evidence')->where('workspace_id',$A)->count() === 8);

$p = DB::table('experience_patterns')->where('workspace_id',$A)->where('subject','action:write_article')->first();
ok('pattern formed from ingested evidence', $p !== null);
ok('pattern counts match the task rows', (int)$p->positive_count === 7 && (int)$p->negative_count === 1,
   "+{$p->positive_count} -{$p->negative_count}");

// A NEW qualifying task must be picked up on the next run.
$mk($A,'failed','write_article',1);
Artisan::call('experience888:ingest', ['--workspace' => $A]);
ok('new qualifying task is ingested on the next run',
   DB::table('experience_events')->where('workspace_id',$A)->count() === 9,
   (string) DB::table('experience_events')->where('workspace_id',$A)->count());

// -- SCHEDULED PATH ISOLATION ---------------------------------------------
echo "-- ExperienceScheduledIsolationTest --\n";
ok('ingesting A created nothing in B',
   DB::table('experience_events')->where('workspace_id',$B)->count() === 0);

// Same action, same engine, same wording, same playbook name in B.
$mk($B,'completed','write_article',8);
Artisan::call('experience888:ingest', ['--workspace' => $B]);
$pb = DB::table('experience_patterns')->where('workspace_id',$B)->where('subject','action:write_article')->first();
ok('B forms its own identically-named pattern', $pb !== null && (int)$pb->id !== (int)$p->id);
ok('B counts are independent of A', (int)$pb->positive_count === 8 && (int)$pb->negative_count === 0,
   "+{$pb->positive_count} -{$pb->negative_count}");

$aRow = DB::table('experience_patterns')->where('workspace_id',$A)->where('subject','action:write_article')->first();
ok('A counts unchanged by B ingestion', (int)$aRow->positive_count === 7 && (int)$aRow->negative_count === 2,
   "+{$aRow->positive_count} -{$aRow->negative_count}");

// Identical playbook name in both workspaces.
$pgA = $pg->promoteFromPattern($A, (int)$aRow->id, ['name'=>'Shared Name Playbook','trigger_type'=>'t']);
$pgB = $pg->promoteFromPattern($B, (int)$pb->id, ['name'=>'Shared Name Playbook','trigger_type'=>'t']);
ok('same playbook name promotes independently per workspace',
   ($pgB['promoted'] ?? false) && ($pgA['playbook_id'] ?? 0) !== ($pgB['playbook_id'] ?? -1));
$bUsable = array_map(fn($x)=>(int)$x->workspace_id, $pg->usable($B, null, 10));
ok('B playbook listing contains only B', array_unique($bUsable) === [$B] || $bUsable === []);

// "What have we learned?" must never surface the other tenant.
$of->record($A, 'tone_of_voice', 'From now on always use a formal tone.');
$of->record($B, 'tone_of_voice', 'From now on always use a playful tone.');
$aBlock = $ret->forTurn($A, 'What have we learned about tone and write_article?');
$bBlock = $ret->forTurn($B, 'What have we learned about tone and write_article?');
ok('A never sees B preference', !str_contains($aBlock, 'playful'), $aBlock);
ok('B never sees A preference', !str_contains($bBlock, 'formal'), $bBlock);
ok('A block is non-empty (proves the test is meaningful)', $aBlock !== '');
ok('B block is non-empty', $bBlock !== '');

// Cross-tenant explainability.
ok('explain in B cannot reach A pattern id', $pat->explain($B, (int)$aRow->id) === []);
ok('explain in A cannot reach B pattern id', $pat->explain($A, (int)$pb->id) === []);

// -- DECAY COMMAND --------------------------------------------------------
echo "-- ExperienceDecayCommandTest --\n";
DB::table('experience_pattern_evidence')->where('workspace_id',$A)
  ->update(['observed_at' => now()->subDays(200)]);
Artisan::call('experience888:decay', ['--workspace' => $A]);
$stale = DB::table('experience_patterns')->where('workspace_id',$A)
    ->where('subject','action:write_article')->first();
ok('decay marks a long-unobserved pattern STALE', $stale->status === C::STATUS_STALE, $stale->status);

Artisan::call('experience888:decay', ['--workspace' => $A]);
$stale2 = DB::table('experience_patterns')->where('workspace_id',$A)
    ->where('subject','action:write_article')->first();
ok('decay is deterministic on rerun', $stale2->status === $stale->status
   && $stale2->confidence_level === $stale->confidence_level);

$freshB = DB::table('experience_patterns')->where('workspace_id',$B)
    ->where('subject','action:write_article')->first();
ok('decay in A did not touch B', $freshB->status !== C::STATUS_STALE, $freshB->status);
ok('stale experience stops influencing Sarah',
   !str_contains($ret->forTurn($A, 'Should we retry write_article?'), 'write_article completes'));

// -- CHAT CAPTURE GATING --------------------------------------------------
echo "-- ExperienceChatCaptureTest --\n";
ok('ordinary question is not feedback', !$of->isFeedback('What should I focus on this week?'));
ok('status question is not feedback', !$of->isFeedback('How many drafts are waiting?'));
ok('empty message is not feedback', !$of->isFeedback('   '));
ok('preference IS feedback', $of->isFeedback('I prefer short captions.'));
ok('policy IS feedback', $of->isFeedback('From now on never publish without my approval.'));
ok('correction IS feedback', $of->isFeedback("No, that's wrong. Use 15 not 50."));
ok('question carrying an instruction IS feedback',
   $of->isFeedback('Why did you use a long caption? Keep them short from now on.'));

ok('subject derived for captions', $of->deriveSubject('keep captions short') === 'caption_length');
ok('subject derived for approvals', $of->deriveSubject('never publish without approval') === 'publishing_policy');
ok('unknown subject falls back to general', $of->deriveSubject('mmm ok fine') === 'general');

// -- CHAT EXPLAINABILITY --------------------------------------------------
echo "-- ExperienceChatExplainabilityTest --\n";
ok('detects "why do you think that"', $ret->isProvenanceQuestion('Why do you think that?'));
ok('detects "where did you learn that"', $ret->isProvenanceQuestion('Where did you learn that?'));
ok('detects "how confident are you"', $ret->isProvenanceQuestion('How confident are you?'));
ok('detects "what would change your mind"', $ret->isProvenanceQuestion('What would make you change your mind?'));
ok('ignores an ordinary request', !$ret->isProvenanceQuestion('Draft a caption for the new stockist.'));

$prov = $ret->explainForTurn($B, 'Why do you think that?');
ok('provenance block is produced when experience exists', $prov !== '');
ok('provenance forbids invention', str_contains($prov, 'fabrication') || str_contains($prov, 'not recorded'));

$empty = $ret->explainForTurn(999863, 'Why do you think that?');
ok('no experience yields an explicit admission',
   str_contains($empty, 'NO recorded experience'), $empty);
ok('non-provenance question yields nothing',
   $ret->explainForTurn($B, 'Draft a caption please.') === '');

echo "\n----------------------------------------\n";
printf("  passed: %d   failed: %d\n", $P, $F);
exit($F > 0 ? 1 : 0);
