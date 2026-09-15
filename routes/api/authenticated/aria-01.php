<?php
/**
 * ARIA888 — Aria, the platform FAQ (DEC-0054, 2026-09-15). Required from routes/api.php INSIDE the auth.jwt group,
 * so `workspace_id` is already on the request. Read-only endpoints; chat metered 1 credit per 10 messages.
 */
use App\Http\Controllers\Api\AriaController;
use Illuminate\Support\Facades\Route;

Route::prefix('aria')->group(function () {
    Route::get('/suggestions', [AriaController::class, 'suggestions'])->middleware('throttle:120,1');
    Route::post('/ask', [AriaController::class, 'ask'])->middleware('throttle:30,1');
});
