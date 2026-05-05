<?php

use Illuminate\Support\Facades\Route;
use App\Engines\CRM\Http\Controllers\LeadController;

Route::middleware('auth.jwt')->prefix('api/crm')->group(function () {
    Route::post('/leads', [LeadController::class, 'store']);
    Route::get('/leads', [LeadController::class, 'index']);
});
