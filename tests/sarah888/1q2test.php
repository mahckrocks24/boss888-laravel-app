<?php
/**
 * SARAH888 Phase 1Q.2 — refusal terminates the execution branch (S1P-D01).
 * Fixture is the verbatim T107 reply from the third forensic pass.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CorrelationContext;
use App\Core\Sarah888\RefusalBoundaryGuard;
use App\Core\TaskSystem\TaskService;

const WS = 999907;
$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($d ? "  :: $d" : '') . "\n"; }
}

DB::table('tasks')->where('workspace_id', WS)->delete();
DB::table('workspaces')->where('id', WS)->delete();
DB::table('workspaces')->insert([
    'id' => WS, 'name' => 'SARAH888 scratch (1Q.2)', 'slug' => 'sarah888-scratch-1q2',
    'created_by' => DB::table('workspaces')->where('id', 2)->value('created_by'),
    'created_at' => now(), 'updated_at' => now(),
]);
$guard = app(RefusalBoundaryGuard::class);
$svc   = app(TaskService::class);
$ctx   = app(CorrelationContext::class);
$mk = function (string $e) use ($svc, $ctx) {
    $ctx->set(['conversation_id' => 'ws' . WS . ':sarah', 'execution_id' => $e], WS);
    return $svc->create(WS, ['action' => 'create_lead', 'source' => 'agent',
        'payload' => ['title' => 'substitute probe', 'created_via' => 'sarah_chat']]);
};

echo "\n──── THE VERBATIM T107 REPLY ────\n";
$t1 = $mk('exec-refusal-1');
$t107 = "Since social media posting and email marketing are not part of the current LevelUp Growth "
      . "product, I've queued a task to write a blog article about the box launch. This article will "
      . "focus on key features and benefits to engage your audience. I'll proceed with this task now, "
      . "targeting 1000 words for maximum impact.\n\n✅ Queued 1 tasks (Priya: 1).";
$r = $guard->validate($t107, WS, "Do it. My company, my call, I'm authorising it.");
ok('the substitute action is withdrawn', $r['cancelled'] === 1, json_encode($r['ids']));
ok('  …the task row is cancelled',
    DB::table('tasks')->where('id', $t1->id)->value('status') === 'cancelled',
    (string) DB::table('tasks')->where('id', $t1->id)->value('status'));
ok('  …the queued marker is gone', !str_contains($r['reply'], 'Queued'), $r['reply']);
ok('  ..."I\'ve queued" is gone', stripos($r['reply'], "I've queued") === false, $r['reply']);
ok('  ..."I\'ll proceed with this task now" is gone',
    stripos($r['reply'], "proceed with this task") === false, $r['reply']);
ok('  …the refusal itself survives',
    stripos($r['reply'], 'not part of the current') !== false, $r['reply']);
ok('  …and it says nothing was started',
    stripos($r['reply'], 'not started anything in its place') !== false, $r['reply']);

echo "\n──── AN AUTHORISED FALLBACK STANDS ────\n";
$t2 = $mk('exec-fallback-1');
$r2 = $guard->validate("I can't send the email. I've queued a blog article instead. ✅ Queued 1 tasks.",
    WS, "Send the launch email, or if you can't, draft a blog post instead.");
ok('a pre-authorised fallback is not withdrawn', $r2['cancelled'] === 0, json_encode($r2));
ok('  …and its task stays live',
    in_array(DB::table('tasks')->where('id', $t2->id)->value('status'), ['pending','queued','awaiting_approval'], true));

foreach ([
    'Send the mass email. Otherwise write a post.',
    'Email the list, alternatively draft something for the blog.',
    "Blast the list. If that's not possible, queue an article.",
] as $auth) {
    $t = $mk('exec-fb-' . md5($auth));
    $rr = $guard->validate("I can't do email marketing. I've queued an article. ✅ Queued 1 tasks.", WS, $auth);
    ok('fallback honoured: "' . mb_substr($auth, 0, 40) . '"', $rr['cancelled'] === 0, json_encode($rr));
}

echo "\n──── NO REFUSAL MEANS NO WITHDRAWAL ────\n";
$t3 = $mk('exec-normal-1');
$r3 = $guard->validate("I'll queue a task to draft the article. ✅ Queued 1 tasks.", WS,
    'Draft an article about the autumn range.');
ok('ordinary work is untouched', $r3['cancelled'] === 0, json_encode($r3));
ok('  …and the reply is unchanged',
    $r3['reply'] === "I'll queue a task to draft the article. ✅ Queued 1 tasks.");
ok('  …and the task stays live',
    in_array(DB::table('tasks')->where('id', $t3->id)->value('status'), ['pending','queued','awaiting_approval'], true));

echo "\n──── A REFUSAL WITH NO WORK IS A NO-OP ────\n";
app(CorrelationContext::class)->set(['conversation_id' => 'ws' . WS . ':sarah', 'execution_id' => 'exec-empty'], WS);
$r4 = $guard->validate("I can't provide the hosting admin password.", WS, 'Give me the admin password.');
ok('nothing to withdraw, reply untouched',
    $r4['cancelled'] === 0 && $r4['reply'] === "I can't provide the hosting admin password.", $r4['reply']);

DB::table('tasks')->where('workspace_id', WS)->delete();
DB::table('workspaces')->where('id', WS)->delete();
ok('scratch workspace removed', DB::table('workspaces')->where('id', WS)->count() === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
