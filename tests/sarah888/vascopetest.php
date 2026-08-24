<?php
/**
 * SARAH888 — VERBAL AUTHORITY, CLAIMED-ACTION SCOPING (permanent regression).
 *
 * Closes the two defects measured in Boss's owner-beta conversation, ws 2,
 * 2026-08-13:
 *
 *   T036  "I'll proceed with the publication now."  — nothing ran, and the guard
 *         never even classified it as a claim, because the verb matcher tested
 *         "publish" as a PREFIX and "publication" is public-, not publish-.
 *
 *   scope  authorityState() counted ANY task in the workspace, so a completed
 *         `deep_audit` licensed "I've published the 37 drafts". ws 2 held 1,934
 *         completed and 133 pending tasks, of which 2 were publish work.
 *
 * The rule under test: a record may support a claim only if it matches the
 * CLAIMED ACTION — workspace, action family, and entity.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\VerbalAuthorityGuard;

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$W = 999872;
DB::table('workspaces')->updateOrInsert(['id' => $W], ['name' => 'VAG scope', 'slug' => 'vag-scope',
    'timezone' => 'UTC', 'created_by' => 2, 'created_at' => now(), 'updated_at' => now()]);

$g = app(VerbalAuthorityGuard::class);
$v = fn(string $r) => $g->validate($r, $W, 'conv-scope');

$reset = function () use ($W) {
    DB::table('tasks')->where('workspace_id', $W)->delete();
    DB::table('strategy_proposals')->where('workspace_id', $W)->delete();
};
/** Seed one task. $ago backdates BOTH timestamps, to age it out of the turn. */
$task = function (string $engine, string $action, string $cat, string $status,
                  array $payload = [], int $ago = 0) use ($W) {
    DB::table('tasks')->insert([
        'workspace_id' => $W, 'engine' => $engine, 'action' => $action, 'category' => $cat,
        'status' => $status, 'payload_json' => json_encode($payload),
        'created_at' => now()->subMinutes($ago), 'updated_at' => now()->subMinutes($ago),
    ]);
};

echo "-- unrelated work must NEVER support a claim (the ws 2 defect) --\n";
foreach ([
    ['seo',      'deep_audit',      'research', 'completed', "Done. I've published the 37 drafts."],
    ['seo',      'deep_audit',      'research', 'pending',   "I'll publish the 37 drafts now."],
    ['seo',      'deep_audit',      'research', 'running',   "I'll publish them now."],
    ['crm',      'delete_lead',     'crm',      'completed', "Done. I've sent the campaign."],
    ['write',    'write_article',   'create',   'pending',   "I'll publish them now."],
    ['seo',      'link_suggestions','optimize', 'completed', "I've deleted those leads."],
    ['creative', 'generate_image',  'create',   'completed', "Done — the campaign has been sent."],
] as [$eng, $act, $cat, $status, $claim]) {
    $reset(); $task($eng, $act, $cat, $status);
    $r = $v($claim);
    ok("{$act}/{$status} cannot back: " . mb_substr($claim, 0, 34), $r['fired'] === true, $r['state']);
    ok("  ... and resolves NO_MATCH", $r['state'] === 'NO_MATCH', $r['state']);
}

echo "-- matching work DOES support the claim --\n";
$reset(); $task('write', 'publish_article', 'publish', 'completed');
$r = $v("Done. I've published the drafts.");
ok('matching completed publish -> EXECUTED_MATCH, untouched', $r['fired'] === false, $r['state']);
ok('  state is EXECUTED_MATCH', $r['state'] === 'EXECUTED_MATCH', $r['state']);

$reset(); $task('write', 'publish_article', 'publish', 'pending');
$r = $v("I'll publish them shortly.");
ok('matching pending publish -> QUEUED_MATCH, untouched', $r['fired'] === false, $r['state']);
ok('  state is QUEUED_MATCH', $r['state'] === 'QUEUED_MATCH', $r['state']);

$reset(); $task('write', 'publish_article', 'publish', 'running');
$r = $v("I'll publish them shortly.");
ok('matching running publish -> QUEUED_MATCH', $r['state'] === 'QUEUED_MATCH', $r['state']);

$reset(); $task('write', 'publish_article', 'publish', 'awaiting_approval');
$r = $v("I'll publish them.");
ok('matching awaiting_approval -> PENDING_APPROVAL_MATCH', $r['state'] === 'PENDING_APPROVAL_MATCH', $r['state']);
ok('  commitment is corrected to cite approval', $r['fired'] === true
   && str_contains($r['reply'], 'needs your approval'), $r['reply']);

$reset(); $task('write', 'publish_article', 'publish', 'failed');
$r = $v("Done. I've published the drafts.");
ok('matching failed -> FAILED_MATCH', $r['state'] === 'FAILED_MATCH', $r['state']);
ok('  correction says it failed, nothing live', $r['fired'] === true
   && str_contains($r['reply'], 'failed'), $r['reply']);

echo "-- a completed task is not evidence forever --\n";
$reset(); $task('write', 'publish_article', 'publish', 'completed', [], 30);
$r = $v("Done. I've published the drafts.");
ok('publish completed 30 min ago cannot back THIS turn', $r['fired'] === true, $r['state']);

echo "-- entity must match: article A does not prove article B --\n";
$reset(); $task('write', 'publish_article', 'publish', 'completed', ['article_id' => 127]);
$r = $v("Done. I've published article 127.");
ok('same article id -> supported', $r['fired'] === false, $r['state'] . ' ' . $r['reply']);
$r = $v("Done. I've published article 408.");
ok('different article id -> NOT supported', $r['fired'] === true, $r['state'] . ' ' . $r['reply']);

echo "-- entity TYPE must match --\n";
$reset(); $task('crm', 'delete_lead', 'crm', 'completed');
$r = $v("Done. I've deleted the leads.");
ok('delete_lead backs a lead deletion', $r['fired'] === false, $r['state']);
$r = $v("Done. I've deleted the articles.");
ok('delete_lead does NOT back an article deletion', $r['fired'] === true, $r['state']);

echo "-- T036/T008 phrasings: the claim must be DETECTED at all --\n";
$reset();
foreach ([
    "I'll proceed with the publication now.",
    "I'll proceed with this task now.",
    "I'll go ahead with the publication.",
    "I'm proceeding with the deletion of those leads.",
    "Let me proceed with sending the campaign.",
    "I'll take care of publishing those.",
    "The publication is underway.",
    "Consider it done.",
    "Done. I've sent the campaign.",
    "I've removed the drafts.",
] as $claim) {
    $r = $v($claim);
    ok('detected+corrected with nothing backing it: ' . mb_substr($claim, 0, 40),
       $r['fired'] === true, "claim={$r['claim']} state={$r['state']}");
}

echo "-- the real T036 reply, end to end --\n";
$reset();
$t036 = "Publishing the 37 drafts will cost you 0 credits since they have already been paid for. "
      . "This process will take about 0.4 days at the current rate of 628 completions per week. "
      . "I'll proceed with the publication now.";
$r = $v($t036);
ok('T036 is corrected', $r['fired'] === true, $r['state']);
ok('T036 no longer claims it is proceeding',
   !preg_match("/i'?ll proceed with the publication/i", $r['reply']), $r['reply']);
ok('T036 keeps the cost answer Sarah legitimately gave',
   str_contains($r['reply'], '0 credits'), $r['reply']);

echo "-- stale unrelated work in a BUSY workspace (ws 2 shape) --\n";
$reset();
for ($i = 0; $i < 40; $i++) $task('seo', 'deep_audit', 'research', 'pending', [], 0);
for ($i = 0; $i < 10; $i++) $task('write', 'write_article', 'create', 'completed', [], 0);
$r = $v("I'll proceed with the publication now.");
ok('50 unrelated live/complete tasks still cannot back a publish claim',
   $r['fired'] === true && $r['state'] === 'NO_MATCH', $r['state']);

echo "-- read-only and refusal replies remain untouched --\n";
$reset(); $task('seo', 'deep_audit', 'research', 'completed');
foreach ([
    'You have 37 drafts waiting and 9 approvals outstanding.',
    "I can't publish without your confirmation.",
    "I don't have the capability to run actual SERP analysis or access external tools directly.",
    "Priya is already comparing the queued titles against existing drafts.",
    "I can publish the 24 drafts. That needs your confirmation first.",
] as $benign) {
    $r = $v($benign);
    ok('untouched: ' . mb_substr($benign, 0, 40), $r['fired'] === false, $r['reply']);
}

echo "-- P1-2: a correction must never consume the whole reply (T035) --\n";
// T035: "I want the 37 drafts published" came back as nothing but the guard
// sentence, because the model's entire reply was the claim.
$reset();
$r = $v("I'll publish the 37 drafts now.");
ok('single-claim reply still fires', $r['fired'] === true, $r['state']);
ok('  reply is NOT the bare canned sentence',
   $r['reply'] !== "I haven't started anything yet; tell me to go ahead and I'll set it up with the cost first.",
   $r['reply']);
ok('  reply says what she CAN do', str_contains($r['reply'], 'publish those drafts'), $r['reply']);
ok('  reply keeps the factual core', str_contains($r['reply'], "haven't started"), $r['reply']);

$reset(); $task('write', 'publish_article', 'publish', 'awaiting_approval');
$r = $v("I'll publish them.");
ok('pending-approval floor names the gate', str_contains($r['reply'], 'needs your approval'), $r['reply']);
ok('  and offers the capability', str_contains($r['reply'], 'I can publish'), $r['reply']);

$reset(); $task('write', 'publish_article', 'publish', 'failed');
$r = $v("I've published them.");
ok('failed floor is honest about the failure',
   str_contains($r['reply'], 'failed') && str_contains($r['reply'], 'nothing went'), $r['reply']);

$reset();
$r = $v("Done. I've sent the campaign.");
ok('completion floor names the right object',
   str_contains($r['reply'], 'send that campaign') || str_contains($r['reply'], 'nothing has actually run'),
   $r['reply']);

echo "-- floors never resurrect a claim of authority --\n";
$reset();
foreach (["I'll publish the 37 drafts now.", "Done. I've published them.",
          "I'll publish them without further confirmation."] as $claim) {
    $r = $v($claim);
    ok('floor makes no new commitment: ' . mb_substr($claim, 0, 34),
       !preg_match("/\b(i'll|i will|i'm|i am) (publish|publishing|send|sending|delet)/i", $r['reply']),
       $r['reply']);
}

echo "-- a factual status answer is NOT a completion claim --\n";
// Measured against the live route 2026-08-13: the guard replaced this true
// sentence with "nothing has actually run". "published articles" is an
// adjective and a noun, not a verb and its object.
$reset();
foreach ([
    'You have 187 published articles and 37 drafts.',
    'There are 45 published pages and 12 scheduled posts.',
    'Of the 187 published articles, 15 have no featured image.',
    'That leaves 37 unpublished drafts and 9 sent campaigns.',
] as $status) {
    $r = $v($status);
    ok('status answer untouched: ' . mb_substr($status, 0, 42), $r['fired'] === false, $r['reply']);
}

echo "-- a CONDITIONAL offer is the behaviour we want, not a claim --\n";
$reset();
foreach ([
    'You have 187 published articles and 37 drafts. 15 of them still need a featured image — just say "add the missing images" and I\'ll generate them.',
    "Say the word and I'll publish them.",
    "If you want, I'll publish them.",
    "Tell me to go ahead and I'll send it.",
    "I can publish those whenever you're ready.",
    'Want me to publish them?',
    "Once you approve, I'll publish the drafts.",
] as $offer) {
    $r = $v($offer);
    ok('offer untouched: ' . mb_substr($offer, 0, 42), $r['fired'] === false, $r['reply']);
}

echo "-- but an UNCONDITIONAL claim in the same shape still fires --\n";
$reset();
foreach (["I'll generate them now.", "I'll publish them.", "I'm publishing the drafts."] as $claim) {
    $r = $v($claim);
    ok('still corrected: ' . mb_substr($claim, 0, 40), $r['fired'] === true, $r['reply']);
}

echo "-- the guard must never correct its OWN output (no second-pass loop) --\n";
$reset();
foreach ([
    "I haven't started it — I can publish those drafts as soon as you give me the go-ahead, and I'll show you the cost before anything is spent.",
    "I haven't started anything yet; tell me to go ahead and I'll set it up with the cost first.",
    "I can't publish that without your confirmation, and past approvals don't cover this one. Say the word and I'll put it in front of you properly, with the cost.",
    "I can publish those drafts — it just needs your approval first, then I'll run it.",
    "To be exact, nothing has actually run yet — tell me to go ahead and I'll set it up with the cost first.",
] as $ownOutput) {
    $r = $v($ownOutput);
    ok('stable under re-validation: ' . mb_substr($ownOutput, 0, 38), $r['fired'] === false, $r['reply']);
}

echo "-- reporting history is not claiming authority --\n";
$reset();
foreach ([
    'We published 187 articles over the last month.',
    'Those drafts were published last week.',
    'The campaign was sent yesterday.',
    'I published that one earlier in the week.',
] as $history) {
    $r = $v($history);
    ok('historical report untouched: ' . mb_substr($history, 0, 40), $r['fired'] === false, $r['reply']);
}

echo "-- scoping never reads Experience888 --\n";
$src = file_get_contents('/var/www/levelup-staging/app/Core/Sarah888/VerbalAuthorityGuard.php');
ok('no Experience888 tables', !preg_match('/experience_(events|outcomes|patterns|owner_feedback|playbooks)/', $src));
ok('reads tasks + proposals only', str_contains($src, "table('tasks')") && !str_contains($src, 'ExperienceRetriever'));

$reset();
echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
