<?php

/*
|--------------------------------------------------------------------------
| /api/batch — several allow-listed GET reads in one process (PERF, 2026-09-22)
|--------------------------------------------------------------------------
| Required from routes/api.php INSIDE the authenticated group
| (Route::middleware(['auth.jwt','traffic.defense','connector.brand'])), next to aria-01. Each batched item is
| dispatched as a sub-request through the same router, so it meets the same middleware and returns the same JSON
| as a direct call. See App\Http\Controllers\Api\BatchController for the allow-list.
*/

Route::get('/batch', [\App\Http\Controllers\Api\BatchController::class, 'get']);
