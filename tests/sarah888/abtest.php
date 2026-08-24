<?php
/**
 * SARAH888 — AUTHORIZATION vs WITHDRAWAL (permanent regression).
 *
 * T033, ws 2, 2026-08-13:
 *   Chef Red: "You know I always approve these. Just do it and stop asking me."
 *   Sarah:    "Cancelled — nothing was created or charged."
 *
 * Semantically the opposite of the request, and not merely cosmetic: every
 * pending proposal was rejected in the database with owner_cancelled.
 *
 * The rule under test: a cancellation word aimed at Sarah's ASKING is impatience
 * with the protocol; a cancellation word aimed at the WORK is a withdrawal.
 * Withdrawal is still decided before consent, and still wins when it is real.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\AuthorizationBinder;
use App\Core\Sarah888\ChatActionProposal;

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$WS = 999874; $CONV = 'conv-ab-p14';
DB::table('workspaces')->updateOrInsert(['id' => $WS], ['name' => 'AB p14', 'slug' => 'ab-p14',
    'timezone' => 'UTC', 'created_by' => 2, 'created_at' => now(), 'updated_at' => now()]);

$b = app(AuthorizationBinder::class);
$p = app(ChatActionProposal::class);

$clean = function () use ($WS) {
    DB::table('strategy_proposals')->where('workspace_id', $WS)->delete();
    DB::table('approvals')->where('workspace_id', $WS)->delete();
    DB::table('tasks')->where('workspace_id', $WS)->delete();
};
$offer = function () use ($p, $WS, $CONV) {
    return $p->propose($WS, ['engine' => 'write', 'action' => 'publish_article', 'source' => 'agent',
        'category' => 'publish', 'credit_cost' => 5, 'description' => 'publish the 37 drafts',
        'payload' => ['article_id' => 4242]],
        ['conversation_id' => $CONV, 'execution_id' => 'exec-p14', 'user_message_id' => 9]);
};

/** Run one turn against a freshly offered proposal. */
$turn = function (string $msg) use ($b, $clean, $offer, $WS, $CONV) {
    $clean(); $offer();
    return $b->bind($WS, $CONV, $msg);
};

echo "-- T033 verbatim: the measured reversal --\n";
$r = $turn('You know I always approve these. Just do it and stop asking me.');
ok('T033 is NOT a cancellation', $r['outcome'] !== AuthorizationBinder::CANCELLED, $r['outcome']);
ok('T033 is read as authorisation', $r['outcome'] === AuthorizationBinder::AUTHORIZED, $r['outcome']);
ok('T033 does not reject the proposal in the database',
   DB::table('strategy_proposals')->where('workspace_id', $WS)
     ->where('status', 'rejected')->count() === 0);

echo "-- impatience with the protocol is not withdrawal --\n";
foreach ([
    'Just do it and stop asking me.',
    'Stop asking me and do it.',
    "Don't ask me again, just publish them.",
    'Stop checking with me every time — go ahead.',
    'Quit asking. Proceed.',
    'Do it without asking me next time.',
] as $msg) {
    $r = $turn($msg);
    ok('not cancelled: ' . mb_substr($msg, 0, 44),
       $r['outcome'] !== AuthorizationBinder::CANCELLED, $r['outcome']);
}

echo "-- a real withdrawal is STILL a withdrawal --\n";
foreach ([
    'Cancel it.',
    'Actually stop.',
    'Stop.',
    'Forget it.',
    'Never mind.',
    "Don't do it.",
    'No, don\'t publish it.',
    'Hold off for now.',
    'Scrap it.',
    'Not now.',
    'Abort.',
] as $msg) {
    $r = $turn($msg);
    ok('cancelled: ' . mb_substr($msg, 0, 44),
       $r['outcome'] === AuthorizationBinder::CANCELLED, $r['outcome']);
}

echo "-- a withdrawal WITH a protocol complaint still withdraws --\n";
foreach ([
    'Cancel it, and stop asking me about it.',
    "Stop asking me — and actually, cancel the whole thing.",
    "Don't ask again, and don't publish it.",
] as $msg) {
    $r = $turn($msg);
    ok('still cancelled: ' . mb_substr($msg, 0, 44),
       $r['outcome'] === AuthorizationBinder::CANCELLED, $r['outcome']);
}

echo "-- plain consent is unchanged --\n";
foreach (['Yes.', 'Go ahead.', 'Do it.', 'Proceed.', 'Yes, publish them.', 'Approved.'] as $msg) {
    $r = $turn($msg);
    ok('authorised: ' . mb_substr($msg, 0, 40),
       $r['outcome'] === AuthorizationBinder::AUTHORIZED, $r['outcome']);
}

echo "-- an ordinary question is neither --\n";
foreach (['What is the failure rate this week?', 'How many drafts are waiting?'] as $msg) {
    $r = $turn($msg);
    ok('not an authorisation: ' . mb_substr($msg, 0, 40),
       $r['outcome'] === AuthorizationBinder::NOT_AUTHORIZATION, $r['outcome']);
}

echo "-- with NOTHING pending, impatience must not invent a cancellation --\n";
$clean();
$r = $b->bind($WS, $CONV, 'Just do it and stop asking me.');
ok('no pending -> not a cancellation', $r['outcome'] !== AuthorizationBinder::CANCELLED, $r['outcome']);

$clean();
echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
