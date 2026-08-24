<?php

use App\Core\Email888\Admin\DeliveryLedgerAdminController;
use App\Core\Email888\Admin\WebhookAdminController;
use Illuminate\Support\Facades\Route;

/**
 * EMAIL888 — delivery ledger and webhook ingress operator console.
 *
 * Required INSIDE the admin group in routes/api.php, so every route below
 * already carries `auth.jwt` and AdminMiddleware. Its own file for the same
 * reason as ads.php and business-email.php: routes/api.php is edited by several
 * sessions at once, and one `require` line is the whole footprint.
 *
 * READ ONLY. There is deliberately no resend, retry or delete here. Retrying a
 * message means producing a new SendEmailCommand through Email888, with its own
 * idempotency key and its own ledger row — never re-firing an old one from an
 * admin screen, which would bypass the sender registry and the stream policy
 * this whole subsystem exists to enforce.
 *
 * The webhook routes are read-only for the same reason plus one of their own:
 * the only mutating action anyone would want here is "replay this event", and
 * replay is deliberately unimplemented — see WebhookAdminController::replayPosture().
 */

Route::prefix('email888')->group(function () {
    Route::get('deliveries', [DeliveryLedgerAdminController::class, 'index']);
    Route::get('deliveries/{id}', [DeliveryLedgerAdminController::class, 'show'])->whereNumber('id');

    // EM-6. `health` is declared BEFORE `{id}` so the literal always wins.
    Route::get('webhooks/health', [WebhookAdminController::class, 'health']);
    Route::get('webhooks', [WebhookAdminController::class, 'index']);
    Route::get('webhooks/{id}', [WebhookAdminController::class, 'show'])->whereNumber('id');
});
