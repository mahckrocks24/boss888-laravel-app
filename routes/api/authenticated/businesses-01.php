<?php

/*
|--------------------------------------------------------------------------
| /api/businesses — the businesses of ONE workspace (RFC-0011 U5a, 2026-09-22)
|--------------------------------------------------------------------------
| Required from routes/api.php inside the authenticated group. The Owner's model: several business profiles in one
| workspace, no switcher — these endpoints feed the Businesses cards in Settings, the "which business?" picker when a
| website is created, and the business chip in Clients. Writes need the owner/admin role. The default business's
| profile mirrors onto workspaces.* through the model (App\Models\Business); a non-default business never does.
*/

use App\Core\Business\BusinessProfileResolver;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$bizWrite = function (Request $r): ?\Illuminate\Http\JsonResponse {
    $role = (string) ($r->attributes->get('workspace_role') ?? '');
    if ($role !== '' && ! in_array($role, ['owner', 'admin'], true)) {
        return response()->json(['error' => 'Only an owner or admin can change businesses.'], 403);
    }
    return null;
};

$bizFields = ['name', 'industry', 'services', 'goal', 'location', 'brand_color', 'logo_url', 'tone', 'target_audience', 'differentiators', 'pricing_anchor', 'aliases'];

$bizPresent = function (Business $b, array $sites, array $leadCounts): array {
    $own = array_values(array_filter($sites, fn ($s) => (int) ($s->business_id ?? 0) === (int) $b->id));
    return [
        'id' => (int) $b->id, 'name' => $b->name, 'slug' => $b->slug, 'aliases' => (array) ($b->aliases_json ?? []),
        'industry' => $b->industry, 'services' => (array) ($b->services_json ?? []), 'goal' => $b->goal, 'location' => $b->location,
        'brand_color' => $b->brand_color, 'logo_url' => $b->logo_url, 'tone' => $b->tone, 'target_audience' => $b->target_audience,
        'differentiators' => $b->differentiators, 'pricing_anchor' => $b->pricing_anchor, 'is_default' => (bool) $b->is_default, 'sort_order' => (int) $b->sort_order,
        'websites' => array_map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name, 'host' => (string) (($s->custom_domain ?: $s->domain ?: $s->subdomain) ?? ''), 'status' => (string) ($s->status ?? '')], $own),
        'counts' => ['websites' => count($own), 'leads' => (int) array_sum(array_map(fn ($s) => (int) ($leadCounts[(int) $s->id] ?? 0), $own))],
    ];
};

$bizApply = function (Business $b, Request $r, array $fields): void {
    foreach ($fields as $f) {
        if (! $r->has($f)) { continue; }
        $v = $r->input($f);
        if ($f === 'services') { $b->services_json = is_array($v) ? array_values(array_filter(array_map('trim', array_map('strval', $v)))) : array_values(array_filter(array_map('trim', preg_split('/[,\n;]+/', (string) $v)))); continue; }
        if ($f === 'aliases') { $b->aliases_json = is_array($v) ? array_values(array_filter(array_map('trim', array_map('strval', $v)))) : array_values(array_filter(array_map('trim', preg_split('/[,\n;]+/', (string) $v)))); continue; }
        $b->{$f} = is_string($v) ? mb_substr(trim($v), 0, $f === 'name' ? 160 : ($f === 'industry' ? 120 : ($f === 'location' ? 200 : 4000))) : $v;
    }
};

Route::get('/businesses', function (Request $r) use ($bizPresent) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $resolver = app(BusinessProfileResolver::class); $resolver->forget($wsId);
    $list = $resolver->forWorkspace($wsId);
    $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderBy('created_at')->get(['id', 'name', 'subdomain', 'custom_domain', 'domain', 'status', 'business_id'])->all();
    $leadCounts = DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereNotNull('website_id')->selectRaw('website_id, COUNT(*) c')->groupBy('website_id')->pluck('c', 'website_id')->all();
    return response()->json([
        'businesses' => array_map(fn ($b) => $bizPresent($b, $sites, $leadCounts), $list),
        'multi' => $resolver->isMulti($wsId), 'enabled' => BusinessProfileResolver::enabledFor($wsId),
        'unassigned_websites' => array_values(array_map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name], array_filter($sites, fn ($s) => empty($s->business_id)))),
    ]);
});

Route::post('/businesses', function (Request $r) use ($bizWrite, $bizApply, $bizFields, $bizPresent) {
    if ($deny = $bizWrite($r)) { return $deny; }
    $wsId = (int) $r->attributes->get('workspace_id');
    $name = trim((string) $r->input('name', ''));
    if ($name === '') { return response()->json(['error' => 'A business needs a name.'], 422); }
    if (Business::where('workspace_id', $wsId)->count() >= 25) { return response()->json(['error' => 'This workspace already holds 25 businesses.'], 422); }
    $b = new Business(['workspace_id' => $wsId, 'name' => mb_substr($name, 0, 160), 'slug' => Business::slugFor($wsId, $name), 'is_default' => Business::where('workspace_id', $wsId)->count() === 0, 'sort_order' => (int) Business::where('workspace_id', $wsId)->max('sort_order') + 1]);
    $bizApply($b, $r, $bizFields);
    $b->save();
    if (is_array($r->input('website_ids'))) { DB::table('websites')->where('workspace_id', $wsId)->whereIn('id', array_map('intval', $r->input('website_ids')))->update(['business_id' => $b->id]); }
    app(BusinessProfileResolver::class)->forget($wsId);
    $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name', 'subdomain', 'custom_domain', 'domain', 'status', 'business_id'])->all();
    return response()->json(['success' => true, 'business' => $bizPresent($b->fresh(), $sites, [])], 201);
});

Route::put('/businesses/{id}', function (Request $r, $id) use ($bizWrite, $bizApply, $bizFields, $bizPresent) {
    if ($deny = $bizWrite($r)) { return $deny; }
    $wsId = (int) $r->attributes->get('workspace_id');
    $b = Business::where('workspace_id', $wsId)->where('id', (int) $id)->first();
    if (! $b) { return response()->json(['error' => 'not_found'], 404); }
    if ($r->has('name') && trim((string) $r->input('name')) === '') { return response()->json(['error' => 'A business needs a name.'], 422); }
    $bizApply($b, $r, $bizFields);
    if ($b->isDirty('name')) { $b->slug = Business::slugFor($wsId, (string) $b->name, (int) $b->id); }
    $b->save();
    app(BusinessProfileResolver::class)->forget($wsId);
    $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name', 'subdomain', 'custom_domain', 'domain', 'status', 'business_id'])->all();
    return response()->json(['success' => true, 'business' => $bizPresent($b->fresh(), $sites, [])]);
});

Route::delete('/businesses/{id}', function (Request $r, $id) use ($bizWrite) {
    if ($deny = $bizWrite($r)) { return $deny; }
    $wsId = (int) $r->attributes->get('workspace_id');
    $b = Business::where('workspace_id', $wsId)->where('id', (int) $id)->first();
    if (! $b) { return response()->json(['error' => 'not_found'], 404); }
    if ($b->is_default) { return response()->json(['error' => 'The default business cannot be deleted. Make another business the default first.'], 422); }
    $n = DB::table('websites')->where('workspace_id', $wsId)->where('business_id', $b->id)->whereNull('deleted_at')->count();
    if ($n > 0) { return response()->json(['error' => "This business still owns {$n} website" . ($n === 1 ? '' : 's') . ". Move them to another business first."], 422); }
    $b->delete();
    app(BusinessProfileResolver::class)->forget($wsId);
    return response()->json(['success' => true]);
});

Route::post('/businesses/{id}/default', function (Request $r, $id) use ($bizWrite) {
    if ($deny = $bizWrite($r)) { return $deny; }
    $wsId = (int) $r->attributes->get('workspace_id');
    $b = Business::where('workspace_id', $wsId)->where('id', (int) $id)->first();
    if (! $b) { return response()->json(['error' => 'not_found'], 404); }
    DB::transaction(function () use ($wsId, $b) {
        Business::where('workspace_id', $wsId)->where('id', '!=', $b->id)->update(['is_default' => false]);
        $b->is_default = true; $b->save(); // the model mirrors the new default onto workspaces.*
    });
    app(BusinessProfileResolver::class)->forget($wsId);
    return response()->json(['success' => true, 'default_id' => (int) $b->id]);
});

Route::put('/businesses/{id}/websites', function (Request $r, $id) use ($bizWrite) {
    if ($deny = $bizWrite($r)) { return $deny; }
    $wsId = (int) $r->attributes->get('workspace_id');
    $b = Business::where('workspace_id', $wsId)->where('id', (int) $id)->first();
    if (! $b) { return response()->json(['error' => 'not_found'], 404); }
    $ids = array_values(array_filter(array_map('intval', (array) $r->input('website_ids', []))));
    // websites named here move to this business; the business's other websites move to the default (never orphaned)
    $default = app(BusinessProfileResolver::class)->default($wsId);
    DB::table('websites')->where('workspace_id', $wsId)->where('business_id', $b->id)->whereNotIn('id', $ids ?: [0])->update(['business_id' => $default ? $default->id : null]);
    if ($ids) { DB::table('websites')->where('workspace_id', $wsId)->whereIn('id', $ids)->update(['business_id' => $b->id]); }
    app(BusinessProfileResolver::class)->forget($wsId);
    return response()->json(['success' => true, 'website_ids' => DB::table('websites')->where('workspace_id', $wsId)->where('business_id', $b->id)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all()]);
});
