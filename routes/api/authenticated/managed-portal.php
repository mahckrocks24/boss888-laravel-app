<?php

use App\Http\Controllers\Api\ManagedPortalController;
use Illuminate\Support\Facades\Route;

/**
 * MANAGED-1 (RFC-0029, DEC-0086) — the managed portal's reads. Required from routes/api.php inside its own
 * ['auth.jwt', DenyApiKeyAuth] group (one require line is the whole footprint there). No route takes a workspace
 * id from the client except the platform-admin preview, which the controller checks.
 */
Route::prefix('managed')->group(function () {
    Route::get('portal', [ManagedPortalController::class, 'portal']);
    Route::get('receipt', [ManagedPortalController::class, 'receipt']);
});
