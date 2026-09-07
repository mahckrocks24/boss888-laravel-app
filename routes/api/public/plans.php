<?php

use App\Http\Controllers\Api\PublicSite\PublicContactController;
use App\Http\Controllers\Api\PublicSite\PublicPlansController;
use App\Http\Controllers\Api\PublicSite\PublicStatusController;
use App\Http\Controllers\Api\PublicSite\PublicWaitlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public site API — /api/public/*
|--------------------------------------------------------------------------
| Unauthenticated. Feeds the marketing site so that every number it shows is
| the platform's own, and receives its forms. Added 2026-09-07 (rebuild).
*/
Route::prefix('public')->group(function () {
    Route::get('/plans', [PublicPlansController::class, 'index'])->name('public.plans');
    Route::get('/status', [PublicStatusController::class, 'index'])->name('public.status');
    Route::post('/waitlist', [PublicWaitlistController::class, 'store'])->name('public.waitlist');
    Route::post('/contact', [PublicContactController::class, 'store'])->name('public.contact');
});
