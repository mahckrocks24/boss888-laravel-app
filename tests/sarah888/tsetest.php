<?php
/**
 * SARAH888 — TurnStatusEmitter: ONE status trailer per turn (permanent).
 *
 * T008 in Boss's owner-beta conversation, ws 2, 2026-08-13:
 *
 *   "I'll proceed with this task now.
 *    To be exact, in this workspace today 1 item is waiting...
 *    I've held 1 item for your approval...
 *    I haven't started anything yet."
 *
 * Four writers, one pending item, and a last line contradicting the first.
 * This suite fixes the contract that replaced them.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Cache;
use App\Core\Sarah888\TurnStatusEmitter;

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$WS = 999873;
$fresh = function (): TurnStatusEmitter {
    $e = new TurnStatusEmitter();
    app()->instance(TurnStatusEmitter::class, $e);
    return $e;
};
$conv = fn(string $s) => 'conv-tse-' . $s;
Cache::flush();

echo "-- nothing recorded means nothing appended --\n";
$e = $fresh();
ok('silent turn is untouched', $e->emit($WS, $conv('a'), 'Morning. Things are steady.')
   === 'Morning. Things are steady.');

echo "-- the T008 stack collapses to ONE trailer --\n";
$e = $fresh();
$e->note(TurnStatusEmitter::HELD, "\n\nI've held 1 item for your approval rather than running it — 5 credits in total.", 'held:1', true);
$e->note(TurnStatusEmitter::PROPOSAL, "\n\nAwaiting your approval: publish the wholesale page — 5 credits. Nothing has been created or charged yet.", '77', true);
$out = $e->emit($WS, $conv('t008'), 'That page is ready to go.');
ok('exactly one trailer survives',
   !(str_contains($out, "I've held") && str_contains($out, 'Awaiting your approval')), $out);
ok('the strongest (cost-bearing proposal) is the one kept',
   str_contains($out, 'Awaiting your approval'), $out);
ok('the held notice is suppressed', !str_contains($out, "I've held"), $out);
ok("Sarah's own sentence survives", str_contains($out, 'That page is ready to go'), $out);

echo "-- a fresh cost is ALWAYS disclosed, even if the reply mentions approval --\n";
$e = $fresh();
$e->note(TurnStatusEmitter::PROPOSAL,
    "\n\nAwaiting your approval: publish the drafts — 12 credits. Nothing has been created or charged yet.",
    '81', true);
$out = $e->emit($WS, $conv('fresh'), 'I can publish those drafts — it just needs your approval first.');
ok('informed consent is not sacrificed to brevity', str_contains($out, '12 credits'), $out);

echo "-- an UNCHANGED restatement is dropped on the next turn --\n";
$c = $conv('repeat');
$e = $fresh();
$e->note(TurnStatusEmitter::PROPOSAL, "\n\nAwaiting your approval: publish the drafts — 12 credits.", '91', true);
$first = $e->emit($WS, $c, 'Here is where things stand.');
ok('turn 1 discloses', str_contains($first, '12 credits'), $first);

$e = $fresh();
$e->note(TurnStatusEmitter::PROPOSAL, "\n\nAwaiting your approval: publish the drafts — 12 credits.", '91', false);
$second = $e->emit($WS, $c, 'The SEO work is the bigger lever this week.');
ok('turn 2 does NOT repeat the same pending set', !str_contains($second, '12 credits'), $second);
ok('  and the reply itself is untouched',
   $second === 'The SEO work is the bigger lever this week.', $second);

echo "-- a CHANGED pending set is disclosed again --\n";
$e = $fresh();
$e->note(TurnStatusEmitter::PROPOSAL, "\n\nAwaiting your approval: publish the drafts — 12 credits.", '91,92', false);
$third = $e->emit($WS, $c, 'One more thing came in.');
ok('a new proposal id re-triggers disclosure', str_contains($third, '12 credits'), $third);

echo "-- a reply that already said it gets no trailer --\n";
$e = $fresh();
$e->note(TurnStatusEmitter::PROPOSAL, "\n\nAwaiting your approval: publish — 3 credits.", '99', false);
$out = $e->emit($WS, $conv('said'), 'Two things are awaiting your approval right now.');
ok('no restatement', substr_count(mb_strtolower($out), 'awaiting your approval') === 1, $out);

echo "-- the authority note is the weakest voice --\n";
$e = $fresh();
$e->note(TurnStatusEmitter::AUTHORITY, "\n\nI haven't started anything yet.", 'auth', true);
$e->note(TurnStatusEmitter::HELD, "\n\nI've held 1 item for your approval — 5 credits.", 'held:1', true);
$out = $e->emit($WS, $conv('weak'), 'Understood.');
ok('held outranks the bare authority line', str_contains($out, "I've held"), $out);
ok('  and they never both appear', !str_contains($out, "haven't started anything yet"), $out);

echo "-- emit() is idempotent: a second call cannot double-append --\n";
$e = $fresh();
$e->note(TurnStatusEmitter::PROPOSAL, "\n\nAwaiting your approval: X — 1 credit.", 'idem', true);
$one = $e->emit($WS, $conv('idem'), 'Base.');
$two = $e->emit($WS, $conv('idem'), $one);
ok('second emit adds nothing', $one === $two, $two);
ok('  disclosed exactly once', substr_count($one, 'Awaiting your approval') === 1, $one);

echo "-- reset() starts a clean turn --\n";
$e = $fresh();
$e->note(TurnStatusEmitter::PROPOSAL, "\n\nAwaiting your approval: Y — 1 credit.", 'r1', true);
$e->reset();
ok('after reset nothing is pending to emit',
   $e->emit($WS, $conv('reset'), 'Clean.') === 'Clean.');

echo "-- an empty note is not a trailer --\n";
$e = $fresh();
$e->note(TurnStatusEmitter::PROPOSAL, '', 'empty', true);
ok('empty text records nothing', $e->emit($WS, $conv('empty'), 'Fine.') === 'Fine.');

echo "-- only ONE component may emit: the others are recorded, not printed --\n";
$src = file_get_contents('/var/www/levelup-staging/routes/api/authenticated/agents-01.php');
ok('agents-01 no longer appends the spend disclosure directly',
   !preg_match('/\$reply \.= app\(\\\\App\\\\Core\\\\Sarah888\\\\SpendPolicy::class\)->disclosure/', $src));
ok('agents-01 no longer appends the proposal disclosure directly',
   !preg_match('/\$reply = rtrim\(\(string\) \$reply\) \. \$__capSvc->disclosure/', $src));
ok('agents-01 emits exactly once',
   substr_count($src, 'TurnStatusEmitter::class)') >= 1
   && substr_count($src, '->emit((int) $wsId') === 1, (string) substr_count($src, '->emit((int) $wsId'));
$ccg = file_get_contents('/var/www/levelup-staging/app/Core/Sarah888/ConfirmationClaimGuard.php');
ok('ConfirmationClaimGuard records rather than appends',
   !str_contains($ccg, "\$out['reply'] = rtrim(\$reply)\n                              . \$this->proposals->disclosure")
   && str_contains($ccg, 'TurnStatusEmitter::PROPOSAL'));

Cache::flush();
echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
