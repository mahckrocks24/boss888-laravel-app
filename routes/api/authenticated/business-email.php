<?php

use App\Http\Controllers\Api\BusinessEmailCustomerActionController;
use App\Http\Controllers\Api\BusinessEmailCustomerController;
use Illuminate\Support\Facades\Route;

/**
 * INFRA888 · E4 — customer Business Email portal API.
 *
 * Required INSIDE the existing `infrastructure` group in routes/api.php, so
 * every route below already carries `auth.jwt` and `DenyApiKeyAuth` — the same
 * stack every other customer infrastructure route uses. `DenyApiKeyAuth` in
 * particular matters here: a long-lived integration key embedded in a WordPress
 * plugin must never be able to create or delete a mailbox.
 *
 * Extracted to its own file for the same reason E3's admin routes were:
 * routes/api.php is edited by several sessions at once, and one `require` line
 * is the whole footprint.
 *
 * NO route here takes a workspace id. The workspace comes from the verified
 * token claim, so a customer cannot name someone else's.
 *
 * Every route is additionally gated by BusinessEmailCustomerGate, which is
 * closed on every installation today.
 */

// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====
Route::prefix('business-email')->group(function () {

    // ── read ─────────────────────────────────────────────────────────────────
    Route::get('overview', [BusinessEmailCustomerController::class, 'overview']);
    Route::get('domains/{id}', [BusinessEmailCustomerController::class, 'domain'])->whereNumber('id');
    Route::get('domains/{id}/setup', [BusinessEmailCustomerController::class, 'setup'])->whereNumber('id');
    Route::get('mailboxes', [BusinessEmailCustomerController::class, 'mailboxes']);
    Route::get('mailboxes/{id}', [BusinessEmailCustomerController::class, 'mailbox'])->whereNumber('id');
    Route::get('aliases', [BusinessEmailCustomerController::class, 'aliases']);
    Route::get('forwarders', [BusinessEmailCustomerController::class, 'forwarders']);
    Route::get('catch-all', [BusinessEmailCustomerController::class, 'catchAll']);
    Route::get('usage', [BusinessEmailCustomerController::class, 'usage']);
    Route::get('health', [BusinessEmailCustomerController::class, 'health']);

    // ── governed actions ─────────────────────────────────────────────────────
    // One entry point, one explicit action map. A customer cannot name a
    // capability, a class or a connector method.
    Route::post('domains/{id}/actions/{action}', [BusinessEmailCustomerActionController::class, 'act'])
        ->whereNumber('id')
        ->where('action', '[a-z-]+');
});
