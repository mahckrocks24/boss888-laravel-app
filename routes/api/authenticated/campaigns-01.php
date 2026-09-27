<?php

/*
|--------------------------------------------------------------------------
| /api/campaigns — campaigns that grow the business (CAMPAIGNS-1, RFC-0018, 2026-09-27)
|--------------------------------------------------------------------------
| Required from routes/api.php inside the authenticated group. The Campaigns page (formerly Projects) and the cards
| Sarah posts in her chat read and act through these. Launch is the owner's one plan-level approval (DEC-0018).
*/

use App\Core\Campaigns\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$cmpWrite = function (Request $r): ?\Illuminate\Http\JsonResponse {
    $role = (string) ($r->attributes->get('workspace_role') ?? '');
    if ($role !== '' && ! in_array($role, ['owner', 'admin'], true)) return response()->json(['success' => false, 'error' => 'Only an owner or admin can run campaigns.'], 403);
    return null;
};
$cmpUser = fn (Request $r) => (int) (optional($r->user())->id ?? 0);

Route::get('/growth/campaigns', function (Request $r) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $svc = app(CampaignService::class);
    $q = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', '!=', 'archived');
    if ($b = (int) $r->query('business_id', 0)) $q->where('business_id', $b);
    $rows = $q->orderByRaw("FIELD(status,'active','launching','paused','idea','completed','declined')")->orderBy('starts_on')->limit(100)->get();
    $needs = DB::table('campaign_items as i')->join('marketing_campaigns as c', 'c.id', '=', 'i.campaign_id')->where('i.workspace_id', $wsId)->where('i.status', 'needs_you')->whereNull('c.deleted_at')
        ->orderBy('i.scheduled_at')->limit(20)->get(['i.id', 'i.title', 'i.kind', 'i.channel', 'i.scheduled_at', 'i.result_type', 'i.result_id', 'c.id as campaign_id', 'c.title as campaign_title']);
    $pending = \Illuminate\Support\Facades\Cache::get('campaign-ideas-pending:' . $wsId);
    return response()->json(['success' => true, 'campaigns' => $rows->map(fn ($c) => $svc->row($c))->values(), 'needs_you' => $needs, 'ideas_pending' => (bool) $pending,
        'businesses' => DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->get(['id', 'name'])]);
});

Route::get('/growth/campaigns/calendar', function (Request $r) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $from = \Carbon\Carbon::parse($r->query('from', now()->startOfMonth()->subDays(7)->toDateString()))->startOfDay();
    $to = \Carbon\Carbon::parse($r->query('to', now()->endOfMonth()->addDays(7)->toDateString()))->endOfDay();
    $items = DB::table('campaign_items as i')->join('marketing_campaigns as c', 'c.id', '=', 'i.campaign_id')->where('i.workspace_id', $wsId)->whereNull('c.deleted_at')
        ->whereIn('c.status', ['active', 'launching', 'paused', 'completed', 'idea'])->whereBetween('i.scheduled_at', [$from, $to])->where('i.status', '!=', 'skipped')
        ->orderBy('i.scheduled_at')->get(['i.id', 'i.title', 'i.kind', 'i.channel', 'i.status', 'i.scheduled_at', 'c.id as campaign_id', 'c.title as campaign_title', 'c.status as campaign_status']);
    $bookings = DB::table('calendar_events')->where('workspace_id', $wsId)->where('category', 'like', 'booking%')->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->get(['id', 'title', 'category', 'starts_at']);
    $other = DB::table('calendar_events')->where('workspace_id', $wsId)->where('category', 'not like', 'booking%')->where('category', 'not like', 'campaign_%')->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->get(['id', 'title', 'category', 'starts_at']);
    return response()->json(['success' => true, 'items' => $items, 'bookings' => $bookings, 'events' => $other]);
});

Route::get('/growth/campaigns/{id}', function (Request $r, int $id) {
    $c = app(CampaignService::class)->show((int) $r->attributes->get('workspace_id'), $id);
    return $c ? response()->json(['success' => true, 'campaign' => $c]) : response()->json(['success' => false, 'error' => 'Not found.'], 404);
})->whereNumber('id');

Route::post('/growth/campaigns/ideas', function (Request $r) use ($cmpWrite) {
    if ($e = $cmpWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    $bid = (int) $r->input('business_id', 0);
    if ($bid && ! DB::table('businesses')->where('id', $bid)->where('workspace_id', $wsId)->whereNull('deleted_at')->exists()) return response()->json(['success' => false, 'error' => 'That business is not in this workspace.'], 422);
    \Illuminate\Support\Facades\Cache::put('campaign-ideas-pending:' . $wsId, 1, now()->addMinutes(4));
    \App\Jobs\CampaignIdeasJob::dispatch($wsId, $bid ?: null, 'sarah_page', mb_substr(trim((string) $r->input('ask', '')), 0, 600));
    return response()->json(['success' => true, 'queued' => true, 'message' => 'Sarah is thinking of ideas. They will appear here and in her chat in about a minute.']);
});

Route::post('/growth/campaigns/{id}/launch', function (Request $r, int $id) use ($cmpWrite, $cmpUser) {
    if ($e = $cmpWrite($r)) return $e;
    $res = app(CampaignService::class)->launch((int) $r->attributes->get('workspace_id'), $id, $cmpUser($r));
    return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
})->whereNumber('id');

Route::post('/growth/campaigns/{id}/decline', function (Request $r, int $id) use ($cmpWrite, $cmpUser) {
    if ($e = $cmpWrite($r)) return $e;
    $ok = app(CampaignService::class)->decline((int) $r->attributes->get('workspace_id'), $id, $cmpUser($r), $r->input('reason'));
    return response()->json(['success' => $ok], $ok ? 200 : 422);
})->whereNumber('id');

Route::post('/growth/campaigns/{id}/pause', function (Request $r, int $id) use ($cmpWrite) {
    if ($e = $cmpWrite($r)) return $e;
    $ok = app(CampaignService::class)->pause((int) $r->attributes->get('workspace_id'), $id);
    return response()->json(['success' => $ok], $ok ? 200 : 422);
})->whereNumber('id');

Route::post('/growth/campaigns/{id}/complete', function (Request $r, int $id) use ($cmpWrite) {
    if ($e = $cmpWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    if (! DB::table('marketing_campaigns')->where('id', $id)->where('workspace_id', $wsId)->whereIn('status', ['active', 'paused'])->exists()) return response()->json(['success' => false, 'error' => 'Only a running campaign can be finished.'], 422);
    DB::table('campaign_items')->where('campaign_id', $id)->where('status', 'planned')->update(['status' => 'skipped', 'updated_at' => now()]);
    app(CampaignService::class)->complete($id);
    return response()->json(['success' => true]);
})->whereNumber('id');

Route::delete('/growth/campaigns/{id}', function (Request $r, int $id) use ($cmpWrite) {
    if ($e = $cmpWrite($r)) return $e;
    $ok = (bool) DB::table('marketing_campaigns')->where('id', $id)->where('workspace_id', (int) $r->attributes->get('workspace_id'))->whereIn('status', ['idea', 'declined', 'completed'])->update(['status' => 'archived', 'updated_at' => now()]);
    return response()->json(['success' => $ok], $ok ? 200 : 422);
})->whereNumber('id');

Route::patch('/growth/campaigns/items/{id}', function (Request $r, int $id) use ($cmpWrite) {
    if ($e = $cmpWrite($r)) return $e;
    $res = app(CampaignService::class)->updateItem((int) $r->attributes->get('workspace_id'), $id, $r->only(['done', 'skip', 'date']));
    return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
})->whereNumber('id');
