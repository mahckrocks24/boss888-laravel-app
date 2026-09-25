<?php

/*
 * MANDATE-1 (DEC-0018 / SPEC-0023, 2026-09-25) — the Plan of Action as an approved mandate.
 * Reads for the shell (a plan's status, its tasks, what was held) and the customer's one write: stop a plan.
 * Approving and declining go through the ordinary approvals endpoints: the gate is a task that requires approval.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/mandates', function (Request $r) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $status = $r->query('status'); $status = is_string($status) && $status !== '' ? $status : null;
    return response()->json(['success' => true, 'items' => app(\App\Core\Governance\MandateService::class)->list($wsId, $status, (int) $r->query('limit', 50))]);
});

Route::get('/mandates/{id}', function (Request $r, $id) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $m = app(\App\Core\Governance\MandateService::class)->show($wsId, (int) $id);
    return $m ? response()->json(['success' => true, 'mandate' => $m]) : response()->json(['success' => false, 'error' => 'not_found'], 404);
});

Route::get('/meeting/{id}/mandate', function (Request $r, $id) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $m = app(\App\Core\Governance\MandateService::class)->forMeeting($wsId, (int) $id);
    return response()->json(['success' => true, 'mandate' => $m]);
});

Route::post('/mandates/{id}/revoke', function (Request $r, $id) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $role = (string) ($r->attributes->get('workspace_role') ?? $r->attributes->get('role') ?? '');
    if (in_array($role, ['viewer', 'member'], true)) {
        return response()->json(['success' => false, 'error' => 'forbidden', 'message' => 'Only an owner or admin can stop a plan.'], 403);
    }
    try {
        $out = app(\App\Core\Governance\MandateService::class)->revoke($wsId, (int) $id, $r->user()?->id, trim((string) $r->input('reason', '')));
        try { app(\App\Core\Audit\AuditLogService::class)->log($wsId, $r->user()?->id, 'mandate.revoked', 'Mandate', (int) $id, ['reason' => (string) $r->input('reason', '')]); } catch (\Throwable $e) {}
        return response()->json(['success' => true, 'mandate' => $out]);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'error' => 'revoke_failed', 'message' => $e->getMessage()], 422);
    }
});
