<?php

/**
 * Engineer888 Command Center — admin API routes.
 *
 * Required from routes/api.php inside the existing
 * `Route::middleware(['auth.jwt','admin'])->prefix('admin')` group, so every
 * route here already carries the JWT check and AdminMiddleware's
 * `is_platform_admin` gate.
 *
 * THOSE ARE NO LONGER SUFFICIENT, AND WERE NEVER MEANT TO BE. Until Sprint 10.4
 * they were all there was, which meant every platform administrator could read
 * and act on engineering work. Each route now names the ONE capability it
 * needs, and RequireEngineer888Access answers from the canonical policy. No
 * route decides anything for itself.
 *
 * DenyApiKeyAuth stays on the group as well as being re-checked inside the
 * policy. The redundancy is deliberate: whichever of the two a future route
 * forgets, the other still holds.
 */

use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use App\Http\Controllers\Api\Admin\Engineer888Controller;
use App\Http\Middleware\DenyApiKeyAuth;
use App\Http\Middleware\RequireEngineer888Access as Gate;
use Illuminate\Support\Facades\Route;

Route::prefix('engineer888')->middleware([DenyApiKeyAuth::class])->group(function () {

    // ── chat (2026-08-05) ───────────────────────────────────────────────
    // The same definition the companion app mounts, so the two surfaces
    // cannot drift apart. Each route names its own capability inside.
    require __DIR__ . '/engineer888-chat.php';

    // ── read ────────────────────────────────────────────────────────────
    Route::get('/bootstrap',         [Engineer888Controller::class, 'bootstrap'])
        ->middleware(Gate::class . ':' . Cap::DISCOVER);

    Route::get('/projects',          [Engineer888Controller::class, 'projects'])
        ->middleware(Gate::class . ':' . Cap::VIEW);

    Route::get('/tasks',             [Engineer888Controller::class, 'tasks'])
        ->middleware(Gate::class . ':' . Cap::VIEW);

    Route::get('/tasks/{uuid}',      [Engineer888Controller::class, 'task'])
        ->middleware(Gate::class . ':' . Cap::VIEW);

    // Reading a candidate means reading proposed source, which is a higher bar
    // than reading a task summary.
    Route::get('/candidates/{uuid}', [Engineer888Controller::class, 'candidate'])
        ->middleware(Gate::class . ':' . Cap::REVIEW_CANDIDATE);

    Route::get('/recoveries/{id}',   [Engineer888Controller::class, 'recovery'])
        ->whereNumber('id')
        ->middleware(Gate::class . ':' . Cap::APPROVE_RECOVERY);

    // ── write ───────────────────────────────────────────────────────────
    Route::post('/tasks',                [Engineer888Controller::class, 'createTask'])
        ->middleware(Gate::class . ':' . Cap::CREATE_TASK);

    Route::post('/tasks/{uuid}/reason',  [Engineer888Controller::class, 'reason'])
        ->middleware(Gate::class . ':' . Cap::CREATE_TASK);

    Route::post('/tasks/{uuid}/execute', [Engineer888Controller::class, 'execute'])
        ->middleware(Gate::class . ':' . Cap::EXECUTE);

    // Approval is a statement by a named human about exact bytes.
    Route::post('/candidates/{uuid}/approve', [Engineer888Controller::class, 'approve'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_CANDIDATE);

    Route::post('/candidates/{uuid}/reject',  [Engineer888Controller::class, 'reject'])
        ->middleware(Gate::class . ':' . Cap::REVIEW_CANDIDATE);

    Route::post('/candidates/{uuid}/revoke',  [Engineer888Controller::class, 'revoke'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_CANDIDATE);

    Route::post('/recoveries/{uuid}/approve', [Engineer888Controller::class, 'approveRecovery'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_RECOVERY);

    Route::post('/recoveries/{uuid}/reject',  [Engineer888Controller::class, 'rejectRecovery'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_RECOVERY);

    Route::post('/recoveries/{uuid}/execute', [Engineer888Controller::class, 'executeRecovery'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_RECOVERY);
});
