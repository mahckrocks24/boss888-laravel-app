<?php
/*
 | RFC-0023 P1 (2026-10-02) - MEMORY-01: the owner's window on what Sarah remembers (DEC-0073 D5).
 |   GET    /memory/me                 the Owner Model in plain words + the raw facts (for the Settings page)
 |   DELETE /memory/me                 erase everything personal Sarah holds (facts, observed events, journal, corrections)
 |   POST   /memory/facts/{id}/confirm | /dismiss
 | Included from routes/api.php inside the authenticated group; business scope via ?business_id.
 */
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

Route::get('/memory/me', function (Request $r) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $bizId = (int) $r->input('business_id') ?: null;
    $svc = app(\App\Core\OwnerModel\OwnerModelService::class);
    $facts = array_map(fn ($f) => ['id' => (int) $f->id, 'business_id' => $f->business_id ? (int) $f->business_id : null, 'group' => $f->group, 'key' => $f->key, 'value' => $f->value, 'source' => $f->source, 'status' => $f->status, 'confidence' => (float) $f->confidence, 'since' => substr((string) ($f->first_seen_at ?: $f->created_at), 0, 10), 'confirmed_at' => $f->last_confirmed_at ? substr((string) $f->last_confirmed_at, 0, 10) : null, 'expires_at' => $f->expires_at ? substr((string) $f->expires_at, 0, 10) : null], $svc->facts($wsId, $bizId));
    return response()->json(['success' => true, 'summary' => $svc->summary($wsId, $bizId), 'facts' => $facts, 'behaviour' => $svc->behaviour($wsId), 'enabled' => file_exists(storage_path('app/memory1.on'))]);
});

Route::delete('/memory/me', function (Request $r) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $role = (string) ($r->attributes->get('workspace_role') ?? $r->user()?->role ?? 'owner');
    if (! in_array($role, ['owner', 'admin'], true)) return response()->json(['error' => 'Only an owner or admin can erase what Sarah remembers.'], 403);
    $out = app(\App\Core\OwnerModel\OwnerModelService::class)->erase($wsId);
    try { app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', "Done. I've erased everything personal I kept about you: your stated goals and preferences, what I noticed about how you work, your journal notes and your corrections. Your business details stay, since they come from your business record. Tell me again whenever you like.", ['notification_type' => 'memory_erased']); } catch (\Throwable) {}
    return response()->json(['success' => true] + $out);
});

Route::post('/memory/facts/{id}/confirm', function (Request $r, int $id) {
    $wsId = (int) $r->attributes->get('workspace_id');
    return response()->json(['success' => true, 'updated' => app(\App\Core\OwnerModel\OwnerModelService::class)->confirm($wsId, [$id])]);
});

Route::post('/memory/facts/{id}/dismiss', function (Request $r, int $id) {
    $wsId = (int) $r->attributes->get('workspace_id');
    return response()->json(['success' => true, 'updated' => app(\App\Core\OwnerModel\OwnerModelService::class)->dismiss($wsId, [$id])]);
});
