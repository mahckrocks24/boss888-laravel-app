<?php

use App\Http\Controllers\Api\Admin\BusinessEmailAdminActionController;
use App\Http\Controllers\Api\Admin\BusinessEmailAdminController;
use Illuminate\Support\Facades\Route;

/**
 * INFRA888 · E3 — Business Email operator console API.
 *
 * Extracted to its own file following the ADS888 and Engineer888 precedent
 * (routes/api/admin/ads.php, routes/api/admin/engineer888.php). That is not
 * tidiness: routes/api.php is edited by several sessions at once, and adding
 * forty routes to it would put this milestone in the middle of every future
 * merge. One `require` line is the whole footprint.
 *
 * This file is required INSIDE the admin group in routes/api.php, so every
 * route below already carries `auth.jwt` and `AdminMiddleware`. `DenyApiKeyAuth`
 * is added here on the mutating routes: an API key must never be able to
 * provision or destroy a mailbox, and the platform's own audit found 48 of 64
 * mutating admin routes missing exactly that control.
 *
 * EVERY route is additionally gated by BusinessEmailAdminGate, which is off on
 * every installation today. Registering a route and exposing it are different
 * things, and the gate is what keeps them different.
 */

Route::prefix('business-email')->group(function () {

    // ── read ─────────────────────────────────────────────────────────────────
    Route::get('overview', [BusinessEmailAdminController::class, 'overview']);
    Route::get('domains', [BusinessEmailAdminController::class, 'domains']);
    Route::get('domains/{id}', [BusinessEmailAdminController::class, 'domain'])->whereNumber('id');
    Route::get('mailboxes', [BusinessEmailAdminController::class, 'mailboxes']);
    Route::get('mailboxes/{id}', [BusinessEmailAdminController::class, 'mailbox'])->whereNumber('id');
    Route::get('routing', [BusinessEmailAdminController::class, 'routing']);
    Route::get('operations', [BusinessEmailAdminController::class, 'operations']);
    Route::get('operations/{id}', [BusinessEmailAdminController::class, 'operation'])->whereNumber('id');
    Route::get('reconciliation', [BusinessEmailAdminController::class, 'reconciliation']);
    Route::get('observations', [BusinessEmailAdminController::class, 'observations']);
    Route::get('providers', [BusinessEmailAdminController::class, 'providers']);
    Route::get('health', [BusinessEmailAdminController::class, 'health']);

    // ── governed actions ─────────────────────────────────────────────────────
    // One entry point, one explicit action map. A caller cannot name a
    // capability, a class or a connector method — only an action the map
    // knows, which is what stops an operator console becoming an
    // arbitrary-command endpoint.
    Route::middleware(\App\Http\Middleware\DenyApiKeyAuth::class)->group(function () {
        Route::post('domains/{id}/actions/{action}', [BusinessEmailAdminActionController::class, 'act'])
            ->whereNumber('id')
            ->where('action', '[a-z-]+');

        Route::post('operations/{id}/resolve', [BusinessEmailAdminActionController::class, 'resolve'])
            ->whereNumber('id');
    });
});
