<?php

/*
|--------------------------------------------------------------------------
| /api/growth/watch · /api/growth/competitors · /api/growth/changes — WATCH-1 (RFC-0019, 2026-09-27)
|--------------------------------------------------------------------------
| Required from routes/api.php inside the authenticated group. The Market watch tab on the Campaigns page and the cards
| Sarah posts in her chat (watch_setup, campaign_change) read and act through these. Research spends credits, so it only
| runs after the owner's one approval (setup); stop ends it. A campaign change is applied only when the owner approves.
*/

use App\Core\Growth\SignalReactor;
use App\Core\Growth\WatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$grWrite = function (Request $r): ?\Illuminate\Http\JsonResponse {
    $role = (string) ($r->attributes->get('workspace_role') ?? '');
    if ($role !== '' && ! in_array($role, ['owner', 'admin'], true)) return response()->json(['success' => false, 'error' => 'Only an owner or admin can change this.'], 403);
    return null;
};
$grBiz = function (Request $r, int $wsId): array {
    $bid = (int) $r->input('business_id', $r->query('business_id', 0));
    if ($bid && ! DB::table('businesses')->where('id', $bid)->where('workspace_id', $wsId)->whereNull('deleted_at')->exists()) return [false, null];
    return [true, \App\Core\Growth\SignalService::bizKey($bid ?: null)];   // the default business owns the "no business" row
};

Route::get('/growth/watch', function (Request $r) use ($grBiz) {
    $wsId = (int) $r->attributes->get('workspace_id');
    [$ok, $bizId] = $grBiz($r, $wsId);
    if (! $ok) return response()->json(['success' => false, 'error' => 'That business is not in this workspace.'], 422);
    $svc = app(WatchService::class);
    $bq = fn ($q) => $q->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'));
    $competitors = $bq(DB::table('business_competitors')->where('workspace_id', $wsId)->where('status', 'active'))->orderByDesc('last_change_at')->orderBy('name')->limit(12)
        ->get(['id', 'name', 'domain', 'source', 'summary', 'last_checked_at', 'last_change_at', 'last_change']);
    $world = $bq(DB::table('growth_signals')->where('workspace_id', $wsId)->where('source', 'world')->whereIn('kind', ['trend', 'moment'])->where('created_at', '>=', now()->subDays(45)))
        ->orderByDesc('id')->limit(12)->get(['id', 'kind', 'strength', 'title', 'detail', 'payload_json', 'decision', 'created_at'])
        ->map(fn ($s) => ['id' => (int) $s->id, 'kind' => $s->kind, 'strength' => (int) $s->strength, 'title' => $s->title, 'detail' => $s->detail, 'url' => json_decode((string) $s->payload_json, true)['url'] ?? null,
            'date' => json_decode((string) $s->payload_json, true)['date'] ?? null, 'acted' => in_array($s->decision, ['adjust', 'propose', 'tell'], true), 'at' => (string) $s->created_at]);
    $wlIds = DB::table('brand_watchlist')->where('workspace_id', $wsId)->get(['id', 'metadata_json'])->filter(fn ($w) => (json_decode((string) $w->metadata_json, true)['business_id'] ?? null) == $bizId)->pluck('id');
    $mentions = DB::table('brand_mentions')->where('workspace_id', $wsId)->whereIn('watchlist_id', $wlIds)->where('status', '!=', 'dismissed')->orderByDesc('id')->limit(12)
        ->get(['id', 'source_title', 'source_domain', 'source_url', 'excerpt', 'sentiment', 'discovered_at', 'created_at']);
    $activity = DB::table('growth_signals')->where('workspace_id', $wsId)->whereIn('decision', ['adjust', 'propose', 'tell'])->where('handled_at', '>=', now()->subDays(30))
        ->orderByDesc('handled_at')->limit(10)->get(['kind', 'title', 'decision', 'decision_note', 'handled_at'])
        ->map(fn ($s) => ['title' => $s->title, 'did' => $s->decision, 'why' => trim(preg_replace('/^#\w{6}\s*/', '', (string) $s->decision_note)), 'at' => (string) $s->handled_at]);
    $changes = DB::table('campaign_changes as x')->join('marketing_campaigns as c', 'c.id', '=', 'x.campaign_id')->where('x.workspace_id', $wsId)->where('x.status', 'proposed')
        ->get(['x.id', 'x.reason', 'x.changes_json', 'x.extra_credits', 'c.id as campaign_id', 'c.title as campaign_title'])
        ->map(fn ($x) => ['change_id' => (int) $x->id, 'campaign_id' => (int) $x->campaign_id, 'campaign_title' => $x->campaign_title, 'reason' => $x->reason, 'changes' => json_decode((string) $x->changes_json, true), 'extra_credits' => (int) $x->extra_credits]);
    return response()->json(['success' => true, 'business_id' => $bizId, 'watch' => $svc->state($wsId, $bizId), 'competitors' => $competitors, 'trends' => $world, 'mentions' => $mentions,
        'activity' => $activity, 'changes_waiting' => $changes, 'checkins' => \App\Core\Growth\CheckinService::prefs($wsId),
        'businesses' => DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->get(['id', 'name', 'is_default'])]);
});

Route::post('/growth/watch', function (Request $r) use ($grWrite, $grBiz) {
    if ($e = $grWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$ok, $bizId] = $grBiz($r, $wsId);
    if (! $ok) return response()->json(['success' => false, 'error' => 'That business is not in this workspace.'], 422);
    $res = app(WatchService::class)->setup($wsId, $bizId, (string) $r->input('frequency', ''), (array) $r->input('areas', []), (int) (optional($r->user())->id ?? 0) ?: null);
    return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
});

Route::post('/growth/watch/stop', function (Request $r) use ($grWrite, $grBiz) {
    if ($e = $grWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$ok, $bizId] = $grBiz($r, $wsId);
    if (! $ok) return response()->json(['success' => false, 'error' => 'That business is not in this workspace.'], 422);
    $svc = app(WatchService::class);
    $row = $svc->row($wsId, $bizId);
    if ($row && $row->status === 'asked') { $svc->decline($wsId, $bizId); return response()->json(['success' => true, 'watch' => $svc->state($wsId, $bizId)]); }
    $svc->stop($wsId, $bizId, 'owner', (string) $r->input('reason', 'Stopped by the owner'));
    return response()->json(['success' => true, 'watch' => $svc->state($wsId, $bizId)]);
});

Route::post('/growth/watch/run', function (Request $r) use ($grWrite, $grBiz) {
    if ($e = $grWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$ok, $bizId] = $grBiz($r, $wsId);
    $row = $ok ? app(WatchService::class)->row($wsId, $bizId) : null;
    if (! $row || $row->status !== 'on') return response()->json(['success' => false, 'error' => 'Turn on the market watch first.'], 422);
    if ($row->last_run_at && \Carbon\Carbon::parse($row->last_run_at)->gt(now()->subHours(6))) return response()->json(['success' => false, 'error' => 'Sarah looked less than six hours ago. She will look again on schedule.'], 422);
    \App\Jobs\WatchRunJob::dispatch((int) $row->id, true);
    return response()->json(['success' => true, 'message' => 'Sarah is looking now. New findings appear here in a few minutes.']);
});

Route::post('/growth/checkins', function (Request $r) use ($grWrite) {
    if ($e = $grWrite($r)) return $e;
    $v = (string) $r->input('value', 'on');
    if (! in_array($v, ['on', 'no_night', 'off'], true)) return response()->json(['success' => false, 'error' => 'Unknown choice.'], 422);
    \App\Core\Growth\CheckinService::setPrefs((int) $r->attributes->get('workspace_id'), $v);
    return response()->json(['success' => true, 'checkins' => $v]);
});

Route::post('/growth/competitors', function (Request $r) use ($grWrite, $grBiz) {
    if ($e = $grWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$ok, $bizId] = $grBiz($r, $wsId);
    if (! $ok) return response()->json(['success' => false, 'error' => 'That business is not in this workspace.'], 422);
    $res = app(WatchService::class)->addCompetitor($wsId, $bizId, mb_substr((string) $r->input('name', ''), 0, 160), mb_substr((string) $r->input('url', ''), 0, 300));
    return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
});

Route::delete('/growth/competitors/{id}', function (Request $r, int $id) use ($grWrite) {
    if ($e = $grWrite($r)) return $e;
    $ok = (bool) DB::table('business_competitors')->where('id', $id)->where('workspace_id', (int) $r->attributes->get('workspace_id'))->update(['status' => 'removed', 'updated_at' => now()]);
    return response()->json(['success' => $ok], $ok ? 200 : 404);
})->whereNumber('id');

Route::get('/growth/changes/{id}', function (Request $r, int $id) {
    $x = DB::table('campaign_changes')->where('id', $id)->where('workspace_id', (int) $r->attributes->get('workspace_id'))->first(['id', 'status', 'campaign_id']);
    return $x ? response()->json(['success' => true, 'change' => $x]) : response()->json(['success' => false], 404);
})->whereNumber('id');

Route::post('/growth/changes/{id}/approve', function (Request $r, int $id) use ($grWrite) {
    if ($e = $grWrite($r)) return $e;
    $res = app(SignalReactor::class)->applyChange((int) $r->attributes->get('workspace_id'), $id, (int) (optional($r->user())->id ?? 0));
    return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
})->whereNumber('id');

Route::post('/growth/changes/{id}/decline', function (Request $r, int $id) use ($grWrite) {
    if ($e = $grWrite($r)) return $e;
    $ok = app(SignalReactor::class)->declineChange((int) $r->attributes->get('workspace_id'), $id, (int) (optional($r->user())->id ?? 0));
    return response()->json(['success' => $ok], $ok ? 200 : 422);
})->whereNumber('id');
