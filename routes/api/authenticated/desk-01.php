<?php
/**
 * PUBLISHER888 Unit 1 (2026-09-04) — Publisher Desk API.
 * Required from routes/api.php INSIDE the auth.jwt group; `desk.context` resolves website + role.
 */
use App\Engines\Publisher\Http\Controllers\DeskController;
use Illuminate\Support\Facades\Route;

Route::prefix('desk')->middleware('desk.context')->group(function () {
    Route::get('/context', [DeskController::class, 'context']);

    Route::get('/stories', [DeskController::class, 'stories']);
    Route::post('/stories', [DeskController::class, 'createStory']);
    Route::get('/stories/{id}', [DeskController::class, 'story'])->whereNumber('id');
    Route::put('/stories/{id}', [DeskController::class, 'updateStory'])->whereNumber('id');
    Route::delete('/stories/{id}', [DeskController::class, 'deleteStory'])->whereNumber('id');
    Route::post('/stories/{id}/publish', [DeskController::class, 'publishStory'])->whereNumber('id');
    Route::post('/stories/{id}/schedule', [DeskController::class, 'scheduleStory'])->whereNumber('id');
    Route::post('/stories/{id}/unpublish', [DeskController::class, 'unpublishStory'])->whereNumber('id');

    Route::get('/sections', [DeskController::class, 'sections']);
    Route::post('/sections', [DeskController::class, 'createSection']);
    Route::put('/sections/{id}', [DeskController::class, 'updateSection'])->whereNumber('id');
    Route::delete('/sections/{id}', [DeskController::class, 'deleteSection'])->whereNumber('id');

    Route::get('/commissions', [DeskController::class, 'commissions']);
    Route::post('/commissions', [DeskController::class, 'commission']);

    Route::get('/jobs', [DeskController::class, 'jobs']);
    Route::post('/jobs', [DeskController::class, 'createJob']);
    Route::get('/jobs/{id}', [DeskController::class, 'job'])->whereNumber('id');
    Route::put('/jobs/{id}', [DeskController::class, 'updateJob'])->whereNumber('id');
    Route::post('/jobs/{id}/publish', [DeskController::class, 'publishJob'])->whereNumber('id');
    Route::post('/jobs/{id}/status', [DeskController::class, 'jobStatus'])->whereNumber('id');

    Route::get('/inbox', [DeskController::class, 'inbox']);
    Route::get('/inbox/{id}', [DeskController::class, 'inboxItem'])->whereNumber('id');
    Route::put('/inbox/{id}', [DeskController::class, 'updateInbox'])->whereNumber('id');
    Route::post('/inbox/{id}/job', [DeskController::class, 'jobFromInbox'])->whereNumber('id');

    Route::get('/members', [DeskController::class, 'members']);
    Route::put('/members/{userId}', [DeskController::class, 'setMemberRole'])->whereNumber('userId');
    Route::post('/members/invite', [DeskController::class, 'invite']);
});
