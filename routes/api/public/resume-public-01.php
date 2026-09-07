<?php
/**
 * RESUME888 — public resume builder API (no JWT). Required from routes/api.php OUTSIDE the auth group,
 * next to the public chatbot routes. Auth = X-CHATBOT-TOKEN (label 'resume') + Origin allow-list + X-RESUME-SESSION.
 */
use App\Http\Controllers\Api\Widget\PublicResumeController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/resume')->middleware('throttle:120,1')->group(function () {
    Route::get('/config', [PublicResumeController::class, 'config']);
    Route::post('/session/start', [PublicResumeController::class, 'start']);
    Route::get('/session', [PublicResumeController::class, 'show']);
    Route::post('/answer', [PublicResumeController::class, 'answer']);
    Route::post('/patch', [PublicResumeController::class, 'patch']);
    Route::post('/upload', [PublicResumeController::class, 'upload']);
    Route::post('/finish', [PublicResumeController::class, 'finish']);
    Route::post('/improve', [PublicResumeController::class, 'improve']);
    Route::get('/preview', [PublicResumeController::class, 'preview']);
    Route::post('/render', [PublicResumeController::class, 'render']);
    Route::post('/email', [PublicResumeController::class, 'email']);
    Route::delete('/session', [PublicResumeController::class, 'destroy']);
});
