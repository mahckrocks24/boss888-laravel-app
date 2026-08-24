<?php
/**
 * SARAH888 — EXECUTOR COVERAGE (permanent regression).
 *
 * Every wired executor must return REAL Laravel data with provenance, and must never
 * fabricate. Reads only; nothing here mutates.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{ToolIntent, ToolIntentGateway, ToolResult};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}
$gw = app(ToolIntentGateway::class);
$WS = 2; $WS2 = 990100;

echo "-- content_state matches the articles table exactly --\n";
$r = $gw->handle(new ToolIntent('content_state', $WS, []));
ok('SUCCEEDED', $r->status === ToolResult::SUCCEEDED, $r->status);
$pub = DB::table('articles')->where('workspace_id', $WS)->where('status', 'published')->count();
$drf = DB::table('articles')->where('workspace_id', $WS)->where('status', 'draft')->count();
ok('  published matches the table', $r->data['published'] === $pub, "{$r->data['published']} vs {$pub}");
ok('  drafts match the table', $r->data['drafts'] === $drf, "{$r->data['drafts']} vs {$drf}");
ok('  provenance names the source', $r->provenance === 'articles table', (string) $r->provenance);
ok('  workspace isolated', $gw->handle(new ToolIntent('content_state', $WS2, []))->data['published'] !== $pub);

echo "-- list_leads matches the CRM --\n";
$r = $gw->handle(new ToolIntent('list_leads', $WS, ['limit' => 3]));
ok('SUCCEEDED', $r->status === ToolResult::SUCCEEDED, $r->status);
$leads = DB::table('leads')->where('workspace_id', $WS)->whereNull('deleted_at')->count();
ok('  total matches', $r->data['total'] === $leads, "{$r->data['total']} vs {$leads}");
ok('  limit is honoured', count($r->data['recent']) <= 3, (string) count($r->data['recent']));
ok('  pipeline is broken out', is_array($r->data['by_status']) && $r->data['by_status'] !== []);

echo "-- get_queue counts by MEANING, not status string --\n";
$r = $gw->handle(new ToolIntent('get_queue', $WS, []));
ok('SUCCEEDED', $r->status === ToolResult::SUCCEEDED, $r->status);
$gated = DB::table('tasks')->where('workspace_id', $WS)
    ->where(function ($w) {
        $w->where('status', 'awaiting_approval')
          ->orWhere(function ($x) { $x->where('status', 'pending')->where('requires_approval', 1); });
    })->count();
ok('  awaiting_owner_approval counts gated work', $r->data['awaiting_owner_approval'] === $gated,
   "{$r->data['awaiting_owner_approval']} vs {$gated}");
ok('  runnable + awaiting == pending + awaiting_approval',
   $r->data['runnable_without_approval'] + $r->data['awaiting_owner_approval']
   === (($r->data['by_status']['pending'] ?? 0) + ($r->data['by_status']['awaiting_approval'] ?? 0)),
   json_encode($r->data['by_status']));

echo "-- email_readiness separates PLATFORM from WORKSPACE --\n";
// T019 said "no email sending service configured". Measured: POSTMARK_TOKEN is set and
// MAIL_MAILER=postmark, but no workspace sender store exists. Both facts must survive.
$r = $gw->handle(new ToolIntent('email_readiness', $WS, []));
ok('SUCCEEDED (a read, never gated as outbound)', $r->status === ToolResult::SUCCEEDED, $r->status);
ok('  platform transport is reported', $r->data['platform_transport'] !== '');
ok('  platform_can_send is a distinct fact', array_key_exists('platform_can_send', $r->data));
ok('  workspace sender state is a SEPARATE fact',
   array_key_exists('workspace_sender_configured', $r->data));
ok('  the summary does not claim email is impossible',
   !str_contains(mb_strtolower((string) $r->data['summary']), 'no email sending service'),
   (string) $r->data['summary']);

echo "-- list_articles returns the ITEMS, so publish_article can be targeted --\n";
// 2026-08-14. Runtime had publish_article (needs an article_id) and content_state
// (counts only), so asked to publish drafts it invented `list_posts`, got
// TOOL_UNAVAILABLE and told the owner publishing was not connected. A count is not
// an answer to "which one".
$r = $gw->handle(new ToolIntent('list_articles', $WS, []));
ok('SUCCEEDED', $r->status === ToolResult::SUCCEEDED, $r->status);
$drafts = DB::table('articles')->where('workspace_id', $WS)
    ->whereNull('deleted_at')->where('status', 'draft')->count();
ok('  defaults to drafts and matches the table', $r->data['matching'] === $drafts,
   "{$r->data['matching']} vs {$drafts}");
ok('  returns actual rows', count($r->data['articles']) > 0);
ok('  provenance names the source', $r->provenance === 'articles table', (string) $r->provenance);

$first = $r->data['articles'][0] ?? [];
ok('  every row carries an article_id', isset($first['article_id']) && is_int($first['article_id']));
ok('  the id is real and in this workspace',
   isset($first['article_id']) && DB::table('articles')->where('id', $first['article_id'])
       ->where('workspace_id', $WS)->exists());
ok('  every returned row is a draft',
   count(array_filter($r->data['articles'], fn ($a) => $a['status'] !== 'draft')) === 0);

// The id it hands out must be one publish_article will actually accept, or the
// capability pair is broken even though each half passes on its own.
$g = $gw->handle(new ToolIntent('publish_article', $WS,
        ['article_id' => $first['article_id'] ?? 0]), true);
ok('  an id from list_articles satisfies publish_article parameters',
   $g->status === ToolResult::WOULD_REQUIRE_APPROVAL, $g->status);

$r = $gw->handle(new ToolIntent('list_articles', $WS, ['status' => 'published', 'limit' => 3]));
ok('status filter is honoured', $r->status === ToolResult::SUCCEEDED
   && count(array_filter($r->data['articles'], fn ($a) => $a['status'] !== 'published')) === 0);
ok('  limit is honoured', count($r->data['articles']) <= 3);

$r = $gw->handle(new ToolIntent('list_articles', $WS, ['limit' => 9999]));
ok('limit is capped, not obeyed blindly', count($r->data['articles']) <= 50);

$r2 = $gw->handle(new ToolIntent('list_articles', $WS2, []));
ok('workspace isolated', $r2->status === ToolResult::SUCCEEDED
   && count(array_filter($r2->data['articles'],
        fn ($a) => DB::table('articles')->where('id', $a['article_id'])
            ->where('workspace_id', $WS)->exists())) === 0);

echo "-- list_campaigns: ZERO is an answer, not a missing capability --\n";
// Chef Red transcript turn 26: "since we already launched the summer campaign, how did
// that perform?" `list_campaigns` was in the registry, read, workspace-available - and had
// no executor, so the gateway said TOOL_UNAVAILABLE and Sarah said the capability "isn't
// wired up in this workspace". ws 2 has ZERO campaigns: the true answer is that the
// premise is wrong, which is the thing the owner needed to hear.
$r = $gw->handle(new ToolIntent('list_campaigns', $WS, []));
ok('SUCCEEDED even with no campaigns', $r->status === ToolResult::SUCCEEDED, $r->status);
ok('  it is NOT reported unavailable', $r->status !== ToolResult::UNAVAILABLE, $r->status);
$camps = DB::table('campaigns')->where('workspace_id', $WS)->whereNull('deleted_at')->count();
ok('  total matches the table', $r->data['total'] === $camps, "{$r->data['total']} vs {$camps}");
ok('  provenance names the source', $r->provenance === 'campaigns table', (string) $r->provenance);
if ($camps === 0) {
    ok('  an empty workspace says so explicitly',
       str_contains((string) $r->data['note'], 'no campaigns'), (string) $r->data['note']);
    ok('  and returns an empty list, not a null', $r->data['campaigns'] === []);
}

// A workspace that DOES have campaigns must get the real rows, or the executor is only
// ever proving the empty case.
$other = DB::table('campaigns')->whereNull('deleted_at')
    ->selectRaw('workspace_id, COUNT(*) c')->groupBy('workspace_id')
    ->orderByDesc('c')->first();
if ($other && (int) $other->workspace_id !== $WS) {
    $ows = (int) $other->workspace_id;
    $r2 = $gw->handle(new ToolIntent('list_campaigns', $ows, []));
    ok("ws{$ows} returns its real campaigns", $r2->status === ToolResult::SUCCEEDED
       && $r2->data['total'] === (int) $other->c, "{$r2->data['total']} vs {$other->c}");
    ok('  every row carries a campaign_id and name',
       count(array_filter($r2->data['campaigns'],
           fn ($c) => !isset($c['campaign_id']) || !array_key_exists('name', $c))) === 0);
    ok('  workspace isolated from ws2',
       count(array_filter($r2->data['campaigns'], fn ($c) =>
           DB::table('campaigns')->where('id', $c['campaign_id'])
             ->where('workspace_id', $WS)->exists())) === 0);
    ok('  stats are real or null, never invented',
       count(array_filter($r2->data['campaigns'],
           fn ($c) => $c['stats'] !== null && !is_array($c['stats']))) === 0);
}

echo "-- list_goals: the thing the whole conversation orbits --\n";
// The Chef Red transcript returns to it repeatedly ("the ranking goal is off track at 0%
// progress with the 22 August deadline"). Legacy Sarah could say that; Runtime-native
// Sarah could not — goals are in neither ExecutiveFacts nor any wired executor, so
// `list_goals` was advertised with nothing behind it. A straight parity gap.
$r = $gw->handle(new ToolIntent('list_goals', $WS, []));
ok('SUCCEEDED', $r->status === ToolResult::SUCCEEDED, $r->status);
$goalRows = DB::table('workspace_goals')->where('workspace_id', $WS)->whereNull('deleted_at')->count();
ok('  total matches the table', $r->data['total'] === $goalRows, "{$r->data['total']} vs {$goalRows}");
ok('  provenance names the source', $r->provenance === 'workspace_goals table', (string) $r->provenance);

if ($goalRows > 0) {
    $g = $r->data['goals'][0];
    ok('  carries a goal_id, title and status',
       isset($g['goal_id'], $g['title'], $g['status']));
    ok('  the goal belongs to this workspace',
       DB::table('workspace_goals')->where('id', $g['goal_id'])->where('workspace_id', $WS)->exists());
    // Progress must be reported as STORED. A goal the platform has never measured must
    // read as unknown, never as a computed stand-in — that is how a 0% becomes a lie.
    $stored = json_decode((string) DB::table('workspace_goals')->where('id', $g['goal_id'])
        ->value('current_state_json'), true);
    ok('  progress_pct is exactly what is stored, or null',
       $g['progress_pct'] === (is_array($stored) ? ($stored['progress_pct'] ?? null) : null),
       json_encode([$g['progress_pct'], $stored['progress_pct'] ?? null]));
    ok('  days_to_deadline is derived only from the stored date',
       $g['target_deadline'] === null || is_int($g['days_to_deadline']));
}

$r2 = $gw->handle(new ToolIntent('list_goals', $WS2, []));
ok('workspace isolated', $r2->status === ToolResult::SUCCEEDED
   && count(array_filter($r2->data['goals'], fn ($g) =>
        DB::table('workspace_goals')->where('id', $g['goal_id'])
          ->where('workspace_id', $WS)->exists())) === 0);

echo "-- a READ is never blocked by an outbound/publish risk word --\n";
foreach (['email_readiness', 'content_state', 'list_leads', 'get_queue', 'gsc_performance',
          'list_articles'] as $cap) {
    $x = $gw->handle(new ToolIntent($cap, $WS, []));
    ok("{$cap} is not approval-gated", $x->status !== ToolResult::REQUIRES_APPROVAL, $x->status);
}

echo "-- executors never fabricate: no model tokens in any payload --\n";
foreach (['content_state', 'list_leads', 'get_queue', 'email_readiness', 'gsc_performance',
          'list_articles'] as $cap) {
    $x = $gw->handle(new ToolIntent($cap, $WS, []));
    if ($x->status !== ToolResult::SUCCEEDED) continue;
    $j = json_encode($x->data);
    ok("{$cap} carries no token usage", !str_contains($j, 'completion_tokens'));
    ok("{$cap} names its provenance", trim((string) $x->provenance) !== '');
}

echo "-- reads do not mutate --\n";
$before = [DB::table('tasks')->where('workspace_id', $WS)->count(),
           DB::table('leads')->where('workspace_id', $WS)->count(),
           DB::table('articles')->where('workspace_id', $WS)->count()];
foreach (['content_state', 'list_leads', 'get_queue', 'email_readiness', 'list_articles'] as $cap) {
    $gw->handle(new ToolIntent($cap, $WS, []));
}
$after = [DB::table('tasks')->where('workspace_id', $WS)->count(),
          DB::table('leads')->where('workspace_id', $WS)->count(),
          DB::table('articles')->where('workspace_id', $WS)->count()];
ok('no row counts changed', $before === $after, json_encode([$before, $after]));

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
