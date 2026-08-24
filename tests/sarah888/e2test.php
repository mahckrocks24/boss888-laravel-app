<?php
/**
 * Slice 1E.2 — choke-point gate proof. Exercises TaskService::create directly,
 * which is what every paid path funnels through, on an isolated workspace.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require_once '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\SpendContext;
use App\Core\Sarah888\SpendPolicy;
use App\Core\TaskSystem\TaskService;

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = '') {
    global $pass, $fail;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " $n" . ($d ? " :: $d" : '') . "\n";
    $ok ? $pass++ : $fail++;
}

// isolated workspace
$WS = 999811;
$required = [];
foreach (DB::select('SHOW COLUMNS FROM workspaces') as $c) {
    if ($c->Null === 'NO' && $c->Default === null && $c->Extra !== 'auto_increment') $required[$c->Field] = $c->Type;
}
DB::table('tasks')->where('workspace_id', $WS)->delete();
DB::table('workspaces')->where('id', $WS)->delete();
$row = ['id'=>$WS,'created_at'=>now(),'updated_at'=>now()];
foreach ($required as $f=>$ty) { if (!isset($row[$f])) $row[$f] = str_contains($ty,'int')?0:(str_contains($ty,'json')?'{}':'s888-e2'); }
$row['name']='Sarah888 spend gate test';
if (array_key_exists('created_by',$row)) $row['created_by'] = (int) DB::table('users')->orderBy('id')->value('id');
DB::table('workspaces')->insert($row);

$svc = app(TaskService::class);
$pol = new SpendPolicy();

// Each scenario gets a FRESH context. Reusing one object across scenarios let
// state bleed between them and produced a failure that did not reproduce in
// isolation — the harness lying, not the gate.
$freshCtx = function (array $turn, int $ws) {
    app()->forgetInstance(SpendContext::class);
    $c = app(SpendContext::class);
    $c->setTurn($turn, $ws);
    return $c;
};

// TaskService dedupes identical tasks and returns the EXISTING row. Two
// scenarios creating the same action with the same payload therefore got the
// same task back — the second scenario read the first scenario's result. Each
// create is given a distinct payload so every assertion sees its own row.
$seq = 0;
$mk = function (array $d) use ($svc, $WS, &$seq) {
    $seq++;
    $d['payload'] = array_merge($d['payload'] ?? [], ['title' => 'e2 scenario ' . $seq, 'probe_seq' => $seq]);
    try { return ['ok'=>true,'task'=>$svc->create($WS, $d)]; }
    catch (\Throwable $e) { return ['ok'=>false,'err'=>$e->getMessage()]; }
};

echo "\n─── S1D-N01: UNMAPPED ACTIONS REFUSED AT CREATION ───\n\n";
foreach (['enhance_seo','confirm_cancellation','check_progress','follow_up'] as $a) {
    $r = $mk(['engine'=>'seo','action'=>$a,'source'=>'agent']);
    t("'$a' is refused", !$r['ok'] && str_contains($r['err'] ?? '', 'UNMAPPED_ACTION'),
       $r['ok'] ? 'CREATED — should have been refused' : 'refused');
}
$r = $mk(['engine'=>'seo','action'=>'fix_orphans','source'=>'agent','credit_cost'=>0]);
t('a REAL action is still accepted', $r['ok'], $r['ok'] ? 'created' : ($r['err'] ?? ''));

echo "\n─── F1-D07: PAID WORK FROM AN UNAUTHORISED TURN ───\n\n";
$ctx = $freshCtx($pol->assessTurn('Media: how many published articles do we have?'), $WS);
$r = $mk(['engine'=>'write','action'=>'generate_image_mini','source'=>'agent',
          'credit_cost'=>2,'auto_approve'=>true,'requires_approval'=>false]);
// CONTRACT MIGRATED 2026-08-08 — creation-gated authorization.
// Was: 'created but HELD' (requires_approval=1). A paid task from an
// unauthorised turn now creates NOTHING. The disclosure path is
// unaffected — the assertion below still proves the hold is recorded
// for the owner, so they are told what was proposed.
t('paid task from a QUESTION creates NO ROW',
  !$r['ok'] && str_contains((string) ($r['err'] ?? ''), 'UNCOMMISSIONED_TURN'),
  $r['ok'] ? 'a row WAS created' : ($r['err'] ?? ''));
t('the hold is recorded for disclosure', count($ctx->held()) === 1, json_encode($ctx->held()));

echo "\n─── AUTONOMY PRESERVED: AUTHORISED WORK STILL RUNS ───\n\n";
$ctx = $freshCtx($pol->assessTurn('Generate the missing featured images.'), $WS);
$r = $mk(['engine'=>'write','action'=>'generate_image_mini','source'=>'agent',
          'credit_cost'=>2,'auto_approve'=>true,'requires_approval'=>false]);
t('paid task from a DIRECTIVE runs without approval', $r['ok'] && (int) $r['task']->requires_approval === 0,
   $r['ok'] ? 'requires_approval=' . $r['task']->requires_approval : ($r['err'] ?? ''));
t('nothing was held', count($ctx->held()) === 0);

echo "\n─── FREE WORK IS UNTOUCHED ───\n\n";
$ctx = $freshCtx($pol->assessTurn('How many orphan pages are there?'), $WS);
// Slice 1R.2 (CERT-A-D02) narrowed this property, deliberately. Free work is no
// longer untouched as a class: free RESEARCH still auto-runs, because Sarah has
// to look things up to answer a question, but free MUTATING work from a turn
// that commissioned nothing is now held. Pass A executed 18 free create_lead
// tasks against invented premises before any reversal could reach them.
$r = $mk(['engine'=>'crm','action'=>'list_leads','source'=>'agent','credit_cost'=>0,'auto_approve'=>true]);
// CONTRACT MIGRATED 2026-08-09 — the research exemption is removed.
// It existed so Sarah could look something up in order to answer the
// question she was asked, and it was right when written. The
// execution-attributed battery then lost 8 of 30 read-only scenarios to
// free research lookups spawned from questions. The capability that
// justified the exception has been replaced by a better one: DerivedState,
// TemporalAnchor and CognitiveFrame answer counts, deadlines, overdue work
// and ownership deterministically, already in the frame before the model
// reads the turn. A question now creates nothing at all.
t('zero-cost RESEARCH is now REFUSED from a question',
  !$r['ok'] && str_contains((string) ($r['err'] ?? ''), 'UNCOMMISSIONED_TURN'),
  $r['ok'] ? 'a row WAS created' : ($r['err'] ?? ''));
$r = $mk(['engine'=>'seo','action'=>'fix_orphans','source'=>'agent','credit_cost'=>0,'auto_approve'=>true]);
// CONTRACT MIGRATED 2026-08-08 — creation-gated authorization.
// Was: 'is now HELD'. Free mutating work from a question now creates
// nothing at all. Free RESEARCH remains exempt (asserted above), because
// Sarah must be able to look something up to answer the question asked.
t('zero-cost MUTATING work from a question creates NO ROW',
  !$r['ok'] && str_contains((string) ($r['err'] ?? ''), 'UNCOMMISSIONED_TURN'),
  $r['ok'] ? 'a row WAS created' : ($r['err'] ?? ''));

echo "\n─── FAILS CLOSED ───\n\n";
// A path that never sets the context — a worker, a scheduled job, a caller
// that has never heard of Phase 1E.
app()->forgetInstance(SpendContext::class);
$fresh = app(SpendContext::class);
t('an unset context reports UNAUTHORISED', $fresh->isAuthorized() === false, $fresh->turn()['classification']);
$r = $mk(['engine'=>'write','action'=>'generate_image_mini','source'=>'agent',
          'credit_cost'=>2,'auto_approve'=>true,'requires_approval'=>false]);
t('paid work with NO turn context is held', $r['ok'] && (int) $r['task']->requires_approval === 1,
   $r['ok'] ? 'requires_approval=' . $r['task']->requires_approval : ($r['err'] ?? ''));

echo "\n─── THE GATE CANNOT LOWER THE BAR ───\n\n";
app()->forgetInstance(SpendContext::class);
app(SpendContext::class)->setTurn($pol->assessTurn('Publish it.'), $WS);
$r = $mk(['engine'=>'write','action'=>'publish_article','source'=>'agent',
          'credit_cost'=>0,'auto_approve'=>true,'requires_approval'=>true]);
// CONTRACT MIGRATED 2026-08-09 — creation-gated authorization.
// Was: the task is created and stays flagged requires_approval=1 even on an
// authorised turn, proving the gate cannot lower an existing bar. Publishing
// is now proposed rather than created, so the bar is higher still: saying
// "Publish it." produces no row, no queue entry and no charge until the owner
// approves the specific proposal.
t('a protected action stays protected even when authorised',
   !$r['ok'] && str_contains((string) ($r['err'] ?? ''), 'ASK_FIRST_PROPOSED'),
   $r['ok'] ? 'a row WAS created' : ($r['err'] ?? ''));

DB::table('tasks')->where('workspace_id', $WS)->delete();
DB::table('workspaces')->where('id', $WS)->delete();
echo "\n  cleanup done\n\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
