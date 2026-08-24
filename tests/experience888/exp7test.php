<?php
/**
 * EXPERIENCE888 — SPLIT ELIGIBILITY (permanent regression).
 *
 * ws 2 is two things at once: Chef Red's real customer operations, and the room
 * Boss runs the Sarah owner beta in. Those need opposite answers.
 *
 *   Boss's corrections and standing rules  -> LEARN  (T020: "keep captions short"
 *                                                     was given at turn 6, fell out
 *                                                     of the 20-message window, and
 *                                                     was gone by turn 20)
 *   Chef Red's 2,000+ automated outcomes   -> DO NOT LEARN (live customer data)
 *
 * One boolean cannot say that, so `enabled` carries two optional refinements.
 * Absent refinements mean "follow `enabled`", which is why ws 990100 is unchanged.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Experience888\ExperienceEligibility;

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$e = app(ExperienceEligibility::class);

echo "-- ws 2: owner feedback ON, automated ingestion OFF --\n";
ok('ws2 is enrolled',                 $e->isEnabled(2) === true);
ok('ws2 captures owner feedback',     $e->isFeedbackEnabled(2) === true);
ok('ws2 is NOT swept for ingestion',  $e->isIngestionEnabled(2) === false);
ok('ws2 absent from the sweep set',   !in_array(2, $e->ingestionEnabledWorkspaceIds(), true));

echo "-- ws 990100 keeps BOTH, exactly as before --\n";
ok('990100 enabled',   $e->isEnabled(990100) === true);
ok('990100 feedback',  $e->isFeedbackEnabled(990100) === true);
ok('990100 ingestion', $e->isIngestionEnabled(990100) === true);
ok('990100 IS in the sweep set', in_array(990100, $e->ingestionEnabledWorkspaceIds(), true));

echo "-- an unenrolled workspace learns nothing, by default --\n";
$W = 999875;
DB::table('workspaces')->updateOrInsert(['id' => $W], ['name' => 'exp7', 'slug' => 'exp7',
    'timezone' => 'UTC', 'created_by' => 2, 'created_at' => now(), 'updated_at' => now()]);
DB::table('workspaces')->where('id', $W)->update(['settings_json' => null]);
ok('null settings -> nothing enabled',   $e->isEnabled($W) === false);
ok('null settings -> no feedback',       $e->isFeedbackEnabled($W) === false);
ok('null settings -> no ingestion',      $e->isIngestionEnabled($W) === false);

echo "-- an UNREFINED opt-in still means both (backwards compatible) --\n";
$e->enable($W, 'exp7 plain opt-in', 'exp7test');
ok('plain enable -> feedback on',  $e->isFeedbackEnabled($W) === true);
ok('plain enable -> ingestion on', $e->isIngestionEnabled($W) === true);
ok('plain enable -> in sweep set', in_array($W, $e->ingestionEnabledWorkspaceIds(), true));

echo "-- refinements are independent in BOTH directions --\n";
$e->enable($W, 'ingestion only', 'exp7test', [
    ExperienceEligibility::FEEDBACK_KEY => false,
    ExperienceEligibility::INGESTION_KEY => true,
]);
ok('feedback can be off while ingestion is on',
   $e->isFeedbackEnabled($W) === false && $e->isIngestionEnabled($W) === true);

$e->enable($W, 'feedback only', 'exp7test', [
    ExperienceEligibility::FEEDBACK_KEY => true,
    ExperienceEligibility::INGESTION_KEY => false,
]);
ok('feedback can be on while ingestion is off',
   $e->isFeedbackEnabled($W) === true && $e->isIngestionEnabled($W) === false);

echo "-- disabling the workspace overrides nothing silently --\n";
$e->disable($W, 'exp7 teardown', 'exp7test');
ok('disabled -> isEnabled false', $e->isEnabled($W) === false);
ok('refinement still readable and honoured for feedback',
   $e->isFeedbackEnabled($W) === true, 'explicit refinement survives');

echo "-- settings_json is merged, never replaced --\n";
$s = json_decode((string) DB::table('workspaces')->where('id', 2)->value('settings_json'), true);
ok('ws2 keeps sarah_autonomy',        array_key_exists('sarah_autonomy', $s));
ok('ws2 keeps agent_positions',       array_key_exists('agent_positions', $s));
ok('ws2 keeps chatbot_bootstrap_token', array_key_exists('chatbot_bootstrap_token', $s));
ok('ws2 records WHY it was enrolled', !empty($s['experience888']['reason']));
ok('ws2 records WHO enrolled it',     !empty($s['experience888']['changed_by']));

echo "-- the gates are wired to the split, not to the old single flag --\n";
$route = file_get_contents('/var/www/levelup-staging/routes/api/authenticated/agents-01.php');
ok('chat capture uses isFeedbackEnabled',
   str_contains($route, '->isFeedbackEnabled((int) $wsId)'));
$ing = file_get_contents('/var/www/levelup-staging/app/Console/Commands/ExperienceIngestCommand.php');
ok('ingest command uses isIngestionEnabled', str_contains($ing, 'isIngestionEnabled($id)'));
ok('ingest command sweeps the ingestion set', str_contains($ing, 'ingestionEnabledWorkspaceIds()'));
$dec = file_get_contents('/var/www/levelup-staging/app/Console/Commands/ExperienceDecayCommand.php');
ok('decay command sweeps the ingestion set', str_contains($dec, 'ingestionEnabledWorkspaceIds()'));

echo "-- no cross-tenant leakage: the sweep set is exactly what we intend --\n";
$sweep = $e->ingestionEnabledWorkspaceIds();
ok('sweep set contains no customer workspace 2', !in_array(2, $sweep, true), json_encode($sweep));

echo "-- P2-1: a standing rule must outlive the 20-message history window --\n";
// T006 gave the rule; T020, fourteen turns later, had no record of it. History
// is the last 20 MESSAGES (~10 turns), so the rule had simply fallen out. The
// fix is not a longer window - it is that an owner POLICY is retained and
// retrieved by subject, wherever it was said.
$ofc = app(App\Core\Experience888\OwnerFeedbackClassifier::class);
$ret = app(App\Core\Experience888\ExperienceRetriever::class);
$T006 = "Right. Before I forget — keep all our social captions short from now on. "
      . "Long ones don't suit the brand.";

ok('the T006 message is recognised as owner feedback', $ofc->isFeedback($T006) === true);
ok('it resolves to a stable subject', $ofc->deriveSubject($T006) === 'caption_length',
   (string) $ofc->deriveSubject($T006));
ok('ws2 holds the caption rule', $ofc->current(2, 'caption_length') !== null);

$frameQ = (string) $ret->forTurn(2, 'What did I tell you earlier about captions?', 1200);
ok('T020 question retrieves the rule', stripos($frameQ, 'caption') !== false, $frameQ);
ok('  and the rule text itself', stripos($frameQ, 'short') !== false, $frameQ);
ok('  labelled as owner policy, not as authorisation',
   stripos($frameQ, 'never grants authorisation') !== false, $frameQ);

$frameT = (string) $ret->forTurn(2, 'Draft a social post for the new tasting menu.', 1200);
ok('an unrelated social task also honours the standing rule',
   stripos($frameT, 'caption') !== false, $frameT);

echo "-- and it must not cross tenants --\n";
$MARK = 'ZQXISO' . substr(sha1((string) mt_rand()), 0, 8);
$mid = $ofc->record(2, 'tone_marker_probe', "Always use {$MARK} in our tone from now on.",
    ['conversation_id' => 'exp7-iso']);
$mine   = (string) $ret->forTurn(2, "What tone should we use? {$MARK}", 1500);
$theirs = (string) $ret->forTurn(990100, "What tone should we use? {$MARK}", 1500);
ok('ws2 sees its own marker',        str_contains($mine, $MARK));
ok('ws990100 never sees ws2 marker', !str_contains($theirs, $MARK));
DB::table('experience_owner_feedback')->where('id', $mid)->delete();

DB::table('workspaces')->where('id', $W)->delete();
echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
