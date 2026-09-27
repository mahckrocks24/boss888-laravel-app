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
