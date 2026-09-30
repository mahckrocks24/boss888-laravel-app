<?php

/*
|--------------------------------------------------------------------------
| /api/brand — the brand preferences of one business profile (BRAND-B1, RFC-0017 5d, 2026-09-27)
|--------------------------------------------------------------------------
| Required from routes/api.php inside the authenticated group. Read by Brand settings and by the cards Sarah posts in
| her thread (the ten design directions to pick 3-4, and the summary of uploaded brand material to confirm).
| Everything is per business: business_id names one of the workspace's businesses; absent = the default business.
*/

use App\Core\Brand\BrandProfileService;
use App\Core\Brand\DesignDirections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$brandWrite = function (Request $r): ?\Illuminate\Http\JsonResponse {
    $role = (string) ($r->attributes->get('workspace_role') ?? '');
    if ($role !== '' && ! in_array($role, ['owner', 'admin'], true)) {
        return response()->json(['success' => false, 'error' => 'Only an owner or admin can change the brand.'], 403);
    }
    return null;
};
$brandBiz = function (Request $r, int $wsId): array {
    $bid = (int) $r->input('business_id', $r->query('business_id', 0));
    if ($bid && ! DB::table('businesses')->where('id', $bid)->where('workspace_id', $wsId)->whereNull('deleted_at')->exists()) {
        return [null, response()->json(['success' => false, 'error' => 'That business is not in this workspace.'], 422)];
    }
    return [$bid ?: null, null];
};

Route::get('/brand/profile', function (Request $r) use ($brandBiz) {
    $wsId = (int) $r->attributes->get('workspace_id');
    [$bid, $err] = $brandBiz($r, $wsId); if ($err) return $err;
    return response()->json(['success' => true] + app(BrandProfileService::class)->profile($wsId, $bid));
});

Route::get('/brand/directions', function (Request $r) {
    return response()->json(['success' => true, 'directions' => DesignDirections::catalogue($r->query('industry'))]);
});

Route::post('/brand/directions', function (Request $r) use ($brandWrite, $brandBiz) {
    if ($e = $brandWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$bid, $err] = $brandBiz($r, $wsId); if ($err) return $err;
    $res = app(BrandProfileService::class)->setDirections($wsId, $bid, (array) $r->input('picks', []), (array) $r->input('never', []), $r->input('from') === 'chat' ? 'chat_card' : 'settings');
    if (($res['success'] ?? false) && $r->input('from') === 'chat') {
        // Sarah acknowledges in her own words, in the thread where the owner picked
        try {
            $svc = app(\App\Core\Brand\BrandIntakeService::class);
            $biz = app(BrandProfileService::class)->business($wsId, $bid);
            $facts = ['business' => $biz->name ?? null, 'chosen_styles_in_order' => $res['names'], 'never' => array_map(fn ($d) => DesignDirections::ALL[$d]['name'], $res['never'])];
            $fallback = 'Saved. For ' . ($biz->name ?? 'your business') . ' I will design in ' . implode(', ', $res['names']) . ', choosing the best fit for each post. You can change this any time in Settings › Business or just tell me.';
            $words = $svc->sarahWords($wsId, 'brand_directions_saved', "Write Sarah's short confirmation (1-2 sentences): the styles are saved for this business and she will use the best fit for each banner, image and video; they can change them any time by telling her or in Settings › Business. Name the styles. No emojis.", $facts, $fallback);
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, ['notification_type' => 'brand_directions_saved']);
        } catch (\Throwable $e) { /* the choice is saved either way */ }
    }
    return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
});

Route::post('/brand/intake/skip', function (Request $r) use ($brandWrite, $brandBiz) {
    if ($e = $brandWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$bid, $err] = $brandBiz($r, $wsId); if ($err) return $err;
    app(BrandProfileService::class)->skip($wsId, $bid);
    return response()->json(['success' => true]);
});

Route::get('/brand/proposals/{token}', function (Request $r, string $token) {
    return response()->json(['success' => true, 'status' => app(BrandProfileService::class)->proposalStatus((int) $r->attributes->get('workspace_id'), $token)]);
});

Route::post('/brand/proposals/{token}/confirm', function (Request $r, string $token) use ($brandWrite) {
    if ($e = $brandWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    $res = app(BrandProfileService::class)->confirm($wsId, $token, (array) $r->input('edits', []));
    return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
});

Route::post('/brand/proposals/{token}/discard', function (Request $r, string $token) use ($brandWrite) {
    if ($e = $brandWrite($r)) return $e;
    $ok = app(BrandProfileService::class)->discard((int) $r->attributes->get('workspace_id'), $token);
    return response()->json(['success' => $ok], $ok ? 200 : 422);
});

// VISION-INSPIRE-1: the inspiration library
$inspRow = function (Request $r, int $id) {
    return DB::table('design_inspirations')->where('id', $id)->where('workspace_id', (int) $r->attributes->get('workspace_id'))->first();
};
$inspOut = function ($x) {
    $a = json_decode((string) $x->analysis_json, true) ?: [];
    return ['id' => (int) $x->id, 'business_id' => $x->business_id ? (int) $x->business_id : null, 'title' => $x->title, 'image_url' => $x->image_url, 'pinned' => (bool) $x->pinned,
        'uses' => (int) $x->uses, 'status' => $x->status, 'created_at' => (string) $x->created_at, 'why' => array_slice((array) ($a['what_makes_it_work'] ?? []), 0, 3),
        'directions' => array_map(fn ($d) => DesignDirections::ALL[$d]['name'] ?? $d, json_decode((string) $x->directions_json, true) ?: [])];
};
Route::get('/brand/inspirations', function (Request $r) use ($brandBiz, $inspOut) {
    $wsId = (int) $r->attributes->get('workspace_id');
    [$bid, $err] = $brandBiz($r, $wsId); if ($err) return $err;
    $q = DB::table('design_inspirations')->where('workspace_id', $wsId)->where('status', 'active');
    if ($bid) $q->where(fn ($w) => $w->where('business_id', $bid)->orWhereNull('business_id'));
    return response()->json(['success' => true, 'inspirations' => $q->orderByDesc('pinned')->orderByDesc('id')->limit(60)->get()->map($inspOut)->values()]);
});
Route::get('/brand/inspirations/{id}', function (Request $r, int $id) use ($inspRow, $inspOut) {
    $x = $inspRow($r, $id);
    return $x ? response()->json(['success' => true, 'inspiration' => $inspOut($x)]) : response()->json(['success' => false, 'error' => 'Not found.'], 404);
});
Route::post('/brand/inspirations/{id}/focus', function (Request $r, int $id) use ($inspRow) {
    $x = $inspRow($r, $id); if (! $x || $x->status !== 'active') return response()->json(['success' => false, 'error' => 'That inspiration is no longer saved.'], 404);
    \Illuminate\Support\Facades\Cache::put('brand:insp:focus:' . (int) $x->workspace_id, (int) $x->id, now()->addMinutes(30));
    $biz = $x->business_id ? DB::table('businesses')->where('id', $x->business_id)->value('name') : null;
    return response()->json(['success' => true, 'suggested_message' => 'Make a banner' . ($biz ? ' for ' . $biz : '') . ' in the style of my saved inspiration "' . $x->title . '"']);
});
Route::post('/brand/inspirations/{id}/pin', function (Request $r, int $id) use ($brandWrite, $inspRow) {
    if ($e = $brandWrite($r)) return $e;
    $x = $inspRow($r, $id); if (! $x) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
    DB::table('design_inspirations')->where('id', $x->id)->update(['pinned' => (bool) $r->input('pinned', true), 'updated_at' => now()]);
    return response()->json(['success' => true, 'pinned' => (bool) $r->input('pinned', true)]);
});
Route::delete('/brand/inspirations/{id}', function (Request $r, int $id) use ($brandWrite, $inspRow) {
    if ($e = $brandWrite($r)) return $e;
    $x = $inspRow($r, $id); if (! $x) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
    DB::table('design_inspirations')->where('id', $x->id)->update(['status' => 'forgotten', 'pinned' => false, 'updated_at' => now()]);
    return response()->json(['success' => true]);
});

Route::post('/brand/rules', function (Request $r) use ($brandWrite, $brandBiz) {
    if ($e = $brandWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$bid, $err] = $brandBiz($r, $wsId); if ($err) return $err;
    $rule = trim((string) $r->input('rule', ''));
    if ($rule === '') return response()->json(['success' => false, 'error' => 'Write the rule first.'], 422);
    $svc = app(BrandProfileService::class);
    $row = $svc->row($wsId, $svc->business($wsId, $bid), true);
    $rules = BrandProfileService::json($row, 'rules_json');
    $rules[] = ['rule' => mb_substr($rule, 0, 200), 'source' => 'settings', 'at' => now()->toIso8601String()];
    $svc->write($row, ['rules_json' => json_encode(array_slice($rules, -30), JSON_UNESCAPED_UNICODE)], 'settings', 'rule added');
    return response()->json(['success' => true]);
});

Route::delete('/brand/rules/{index}', function (Request $r, int $index) use ($brandWrite, $brandBiz) {
    if ($e = $brandWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$bid, $err] = $brandBiz($r, $wsId); if ($err) return $err;
    $ok = app(BrandProfileService::class)->removeRule($wsId, $bid, $index);
    return response()->json(['success' => $ok], $ok ? 200 : 422);
});

/* ── DESIGN-LIBRARY-2 (Owner 2026-10-01): the searchable library of reference designs replaces the ten fixed styles ──
   GET  /brand/library            search + filters (q, industry, archetype, format, style, people, page)
   GET  /brand/library/{id}       one design's public face (never the recipe or prompt - SECRET-1)
   GET  /brand/library/picks      the business's chosen designs (up to three)
   POST /brand/library/picks      save the choice {business_id, recipe_ids[]} */
Route::get('/brand/library', function (Request $r) use ($brandBiz) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $home = null;
    try { [$bid] = $brandBiz($r, $wsId); $biz = app(BrandProfileService::class)->business($wsId, $bid); $home = $biz->industry ?? null; if ($home) { $map = ['restaurant' => 'Restaurant', 'private chef' => 'Private Chef', 'chef' => 'Private Chef', 'cafe' => 'Cafe', 'gym' => 'Gym & Fitness', 'fitness' => 'Gym & Fitness', 'dental' => 'Dental', 'real estate' => 'Real Estate Agency', 'salon' => 'Beauty Salon', 'beauty' => 'Beauty Salon', 'barber' => 'Barbershop', 'consult' => 'Consulting', 'marketing' => 'Marketing Agency', 'graphic' => 'Graphic Design', 'design' => 'Graphic Design', 'it ' => 'IT Services', 'software' => 'IT Services', 'retail' => 'Retail Shop', 'ecommerce' => 'E-commerce', 'hotel' => 'Hotel', 'event' => 'Event Venue', 'pet' => 'Pet Services', 'joinery' => 'Construction', 'construction' => 'Construction']; $h = strtolower($home); $home = null; foreach ($map as $k => $v) { if (str_contains($h, $k)) { $home = $v; break; } } } } catch (\Throwable) {}
    $out = app(\App\Core\Brand\DesignLibraryService::class)->search($r->query(), $home);
    return response()->json(['success' => true, 'home_industry' => $home, 'max_picks' => \App\Core\Brand\DesignLibraryService::MAX_PICKS] + $out);
});

Route::get('/brand/library/picks', function (Request $r) use ($brandBiz) {
    $wsId = (int) $r->attributes->get('workspace_id');
    [$bid, $err] = $brandBiz($r, $wsId); if ($err) return $err;
    return response()->json(['success' => true, 'business_id' => $bid, 'max_picks' => \App\Core\Brand\DesignLibraryService::MAX_PICKS, 'recipes' => app(\App\Core\Brand\DesignLibraryService::class)->picks($wsId, $bid)]);
});

Route::get('/brand/library/{id}', function (Request $r, int $id) {
    $row = app(\App\Core\Brand\DesignLibraryService::class)->get($id);
    return $row ? response()->json(['success' => true, 'design' => $row]) : response()->json(['success' => false, 'error' => 'Not found.'], 404);
})->whereNumber('id');

Route::post('/brand/library/picks', function (Request $r) use ($brandWrite, $brandBiz) {
    if ($e = $brandWrite($r)) return $e;
    $wsId = (int) $r->attributes->get('workspace_id');
    [$bid, $err] = $brandBiz($r, $wsId); if ($err) return $err;
    $res = app(\App\Core\Brand\DesignLibraryService::class)->setPicks($wsId, $bid, (array) $r->input('recipe_ids', []), $r->input('from') === 'chat' ? 'chat_card' : 'library');
    if (($res['success'] ?? false) && $r->input('from') === 'chat') {
        try {
            $svc = app(\App\Core\Brand\BrandIntakeService::class);
            $biz = app(BrandProfileService::class)->business($wsId, $bid);
            $fallback = 'Saved. For ' . ($biz->name ?? 'your business') . ' I will design from the looks you chose: ' . implode(', ', $res['names']) . '. You can change them any time in Settings › Business or just tell me.';
            $words = $svc->sarahWords($wsId, 'brand_directions_saved', "Write Sarah's short confirmation (1-2 sentences): the design looks are saved for this business and she will use the best fit for each banner, image and video; they can change them any time by telling her or in Settings › Business. Name the looks in plain words.", ['business' => $biz->name ?? null, 'chosen_looks_in_order' => $res['names']], $fallback);
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, ['notification_type' => 'brand_directions_saved']);
        } catch (\Throwable $e) { /* the choice is saved either way */ }
    }
    return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
});
