<?php

/*
| /api/search/* — PAGE-ONE-1 (RFC-0020). Required from routes/api.php inside the authenticated group.
| The website's Search overview (roadmap, targets, how each page does in Google) and its heatmaps, for the SEO page.
*/

use App\Core\Search\SearchSites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$srSite = function (Request $r, int $wsId): ?object {
    $sites = SearchSites::managed($wsId);
    if ($wid = (int) $r->query('website_id', 0)) return $sites->firstWhere('id', $wid);
    $u = strtolower(trim((string) $r->query('site_url', '')));
    $h = preg_replace('/^www\./', '', (string) (parse_url(str_contains($u, '://') ? $u : 'https://' . $u, PHP_URL_HOST) ?: ''));
    if ($h !== '') foreach ($sites as $w) { if (preg_replace('/^www\./', '', (string) SearchSites::host($w)) === $h || preg_replace('/^www\./', '', strtolower((string) $w->subdomain)) === $h) return $w; }
    return $sites->count() === 1 ? $sites->first() : null;
};
$srWrite = function (Request $r): ?\Illuminate\Http\JsonResponse {
    $role = (string) ($r->attributes->get('workspace_role') ?? '');
    return ($role !== '' && ! in_array($role, ['owner', 'admin'], true)) ? response()->json(['success' => false, 'error' => 'Only an owner or admin can do this.'], 403) : null;
};

Route::get('/search/overview', function (Request $r) use ($srSite) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $w = $srSite($r, $wsId);
    if (! $w) return response()->json(['success' => false, 'error' => 'Pick a website.'], 404);
    $pages = DB::table('search_pages')->where('website_id', $w->id)->whereNull('gone_at');
    $buckets = (clone $pages)->whereNotNull('bucket')->selectRaw('bucket, COUNT(*) n')->groupBy('bucket')->pluck('n', 'bucket');
    $road = DB::table('search_plans')->where('website_id', $w->id)->where('kind', 'roadmap')->orderByDesc('id')->first();
    $camp = $road && $road->campaign_id ? app(\App\Core\Campaigns\CampaignService::class)->show($wsId, (int) $road->campaign_id) : null;
    $steps = [];
    if ($camp) foreach ($camp['phases'] as $p) foreach ($p['items'] as $it) $steps[] = $it;
    return response()->json(['success' => true, 'website' => ['id' => (int) $w->id, 'name' => $w->name, 'host' => SearchSites::host($w)],
        'search_console' => app(\App\Core\Search\SearchNotifier::class)->gscProperty($w) !== null, 'gsc_link' => app(\App\Core\Search\KeywordPlan::class)->gscLink($wsId),
        'pages' => ['total' => (clone $pages)->count(), 'in_sitemap' => (clone $pages)->where('in_sitemap', 1)->count(), 'indexed' => (clone $pages)->where('indexed', 1)->count(),
            'checked' => (clone $pages)->whereNotNull('index_checked_at')->count(), 'buckets' => $buckets],
        'roadmap' => $camp ? ['id' => $camp['id'], 'title' => $camp['title'], 'status' => $camp['status'], 'starts_on' => $camp['starts_on'], 'ends_on' => $camp['ends_on'], 'steps' => $steps] : null,
        'competitors' => $road ? array_slice((array) (json_decode((string) $road->facts_json, true)['competitors'] ?? []), 0, 8, true) : [],
        'targets' => DB::table('search_targets')->where('website_id', $w->id)->whereIn('status', ['target', 'planned', 'written', 'ranking'])->orderByDesc('score')->limit(40)->get(['keyword', 'volume', 'intent', 'cluster', 'score', 'status', 'position']),
        'top_pages' => (clone $pages)->whereNotNull('position_28')->orderBy('position_28')->limit(25)->get(['title', 'url', 'kind', 'bucket', 'position_28', 'impressions_28', 'clicks_28', 'top_query', 'optimized_at'])]);
});

Route::post('/search/roadmap', function (Request $r) use ($srSite, $srWrite) {
    if ($deny = $srWrite($r)) return $deny;
    $wsId = (int) $r->attributes->get('workspace_id');
    $w = $srSite($r, $wsId);
    if (! $w) return response()->json(['success' => false, 'error' => 'Pick a website.'], 404);
    if (! \Illuminate\Support\Facades\Cache::add('search-roadmap-build:' . $w->id, 1, 600)) return response()->json(['success' => false, 'error' => 'Sarah is already working on it.'], 429);
    $wid = (int) $w->id;
    dispatch(function () use ($wid) { $site = DB::table('websites')->where('id', $wid)->first(); if ($site) app(\App\Core\Search\KeywordPlan::class)->build($site); });
    return response()->json(['success' => true, 'message' => 'Sarah is researching now. Your Search Roadmap will arrive in her chat in a few minutes.']);
});

Route::get('/search/heatmap/pages', function (Request $r) use ($srSite) {
    $w = $srSite($r, (int) $r->attributes->get('workspace_id'));
    if (! $w) return response()->json(['success' => false, 'error' => 'Pick a website.'], 404);
    return response()->json(['success' => true, 'website' => ['id' => (int) $w->id, 'host' => SearchSites::host($w), 'frame_host' => $w->subdomain ?: SearchSites::host($w)], 'enabled' => \App\Core\Search\Heatmap::enabled($w), 'pages' => app(\App\Core\Search\Heatmap::class)->pages((int) $w->id)]);
});

Route::get('/search/heatmap', function (Request $r) use ($srSite) {
    $w = $srSite($r, (int) $r->attributes->get('workspace_id'));
    if (! $w) return response()->json(['success' => false, 'error' => 'Pick a website.'], 404);
    $path = '/' . ltrim(mb_substr((string) $r->query('path', '/'), 0, 290), '/');
    return response()->json(['success' => true, 'host' => SearchSites::host($w)] + app(\App\Core\Search\Heatmap::class)->data((int) $w->id, $path, $r->query('device') === 'd' ? 'd' : 'm'));
});

Route::post('/search/heatmap/toggle', function (Request $r) use ($srSite, $srWrite) {
    if ($deny = $srWrite($r)) return $deny;
    $w = $srSite($r, (int) $r->attributes->get('workspace_id'));
    if (! $w) return response()->json(['success' => false, 'error' => 'Pick a website.'], 404);
    $s = json_decode((string) ($w->settings_json ?? '{}'), true) ?: [];
    $s['heatmaps'] = (bool) $r->input('on', true);
    DB::table('websites')->where('id', $w->id)->update(['settings_json' => json_encode($s), 'updated_at' => now()]);
    return response()->json(['success' => true, 'enabled' => $s['heatmaps']]);
});
