<?php

/*
 * DESIGN-UPDATES-1 (Owner 2026-10-06) — the owner's side of a design update. Every route is workspace-scoped and answers only
 * while storage/app/designupd.on covers the workspace. Nothing here applies an update without the owner's Agree.
 *   GET  /api/builder/design-updates                 offers waiting (Sarah's Needs your OK card)
 *   GET  /api/builder/design-updates/{id}            one offer: what changes, the content check, preview links
 *   POST /api/builder/design-updates/{id}/agree      snapshot, apply, Revert for 30 days
 *   POST /api/builder/design-updates/{id}/cancel     nothing changes; this version is not offered again
 *   POST /api/builder/design-updates/{id}/revert     the snapshot back, byte for byte
 *   GET  /api/builder/websites/{id}/design-update    Site settings: an offer waiting, the update that can be reverted
 */

use App\Engines\Builder\Services\DesignUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$__dupdWs = fn (Request $r) => (int) $r->attributes->get('workspace_id');
$__dupdOwn = function (Request $r, int $id) use ($__dupdWs) { return (DesignUpdateService::on($__dupdWs($r)) || \App\Engines\Builder\Services\UpgradeOfferService::on($__dupdWs($r))) &&   // UPGRADE-OFFER-1
    (int) DB::table('design_updates')->where('id', $id)->value('workspace_id') === $__dupdWs($r); };

Route::get('/builder/design-updates', function (Request $r) use ($__dupdWs) {
    return response()->json(['success' => true, 'updates' => app(DesignUpdateService::class)->offers($__dupdWs($r))]);
});
Route::get('/builder/design-updates/{id}', function (Request $r, $id) use ($__dupdOwn) {
    if (! $__dupdOwn($r, (int) $id)) return response()->json(['success' => false, 'message' => 'Update not found'], 404);
    return response()->json(['success' => true, 'update' => app(DesignUpdateService::class)->forOwner((int) $id)]);
})->whereNumber('id');
foreach (['agree', 'cancel', 'revert'] as $__act) {
    Route::post('/builder/design-updates/{id}/' . $__act, function (Request $r, $id) use ($__dupdOwn, $__dupdWs, $__act) {
        if (! $__dupdOwn($r, (int) $id)) return response()->json(['success' => false, 'message' => 'Update not found'], 404);
        $res = app(DesignUpdateService::class)->{$__act}((int) $id, $__dupdWs($r), $r->user()?->id);
        return response()->json($res, ($res['success'] ?? false) ? 200 : ((($res['code'] ?? '') === 'changed') ? 409 : 422));
    })->whereNumber('id');
}
Route::get('/builder/websites/{id}/design-update', function (Request $r, $id) use ($__dupdWs) {
    if ((int) DB::table('websites')->where('id', (int) $id)->value('workspace_id') !== $__dupdWs($r)) return response()->json(['success' => false, 'message' => 'Website not found'], 404);
    return response()->json(['success' => true] + app(DesignUpdateService::class)->forSite((int) $id, $__dupdWs($r)));
})->whereNumber('id');
