<?php
/**
 * PUBLISHER888 — Publisher Desk API. Required from routes/api.php INSIDE the auth.jwt group;
 * `desk.context` resolves website + role. Unit 2: per-user rate limits (reads 240/min, writes 60/min,
 * commissions 10/min), versions/restore/trash, audit, sessions, health.
 */
use App\Engines\Publisher\Http\Controllers\DeskController;
use Illuminate\Support\Facades\Route;

Route::prefix('desk')->middleware(['desk.context'])->group(function () {
    Route::middleware('throttle:240,1')->group(function () {
        Route::get('/context', [DeskController::class, 'context']);
        Route::get('/health', [DeskController::class, 'health']);
        Route::get('/audit', [DeskController::class, 'audit']);
        Route::get('/sessions', [DeskController::class, 'sessions']);
        Route::get('/stories', [DeskController::class, 'stories']);
        Route::get('/stories/{id}', [DeskController::class, 'story'])->whereNumber('id');
        Route::get('/stories/{id}/versions', [DeskController::class, 'storyVersions'])->whereNumber('id');
        Route::get('/sections', [DeskController::class, 'sections']);
        Route::get('/commissions', [DeskController::class, 'commissions']);
        Route::get('/jobs', [DeskController::class, 'jobs']);
        Route::get('/jobs/{id}', [DeskController::class, 'job'])->whereNumber('id');
        Route::get('/inbox', [DeskController::class, 'inbox']);
        Route::get('/inbox/{id}', [DeskController::class, 'inboxItem'])->whereNumber('id');
        Route::get('/members', [DeskController::class, 'members']);
        Route::get('/resume/stats', [DeskController::class, 'resumeStats']);
    });
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/sessions/revoke-others', [DeskController::class, 'revokeOtherSessions']);
        Route::post('/stories', [DeskController::class, 'createStory']);
        Route::put('/stories/{id}', [DeskController::class, 'updateStory'])->whereNumber('id');
        Route::delete('/stories/{id}', [DeskController::class, 'deleteStory'])->whereNumber('id');
        Route::post('/stories/{id}/publish', [DeskController::class, 'publishStory'])->whereNumber('id');
        Route::post('/stories/{id}/schedule', [DeskController::class, 'scheduleStory'])->whereNumber('id');
        Route::post('/stories/{id}/unpublish', [DeskController::class, 'unpublishStory'])->whereNumber('id');
        Route::post('/stories/{id}/restore', [DeskController::class, 'restoreStory'])->whereNumber('id');
        Route::post('/stories/{id}/versions/{vid}/restore', [DeskController::class, 'restoreStoryVersion'])->whereNumber('id')->whereNumber('vid');
        Route::post('/sections', [DeskController::class, 'createSection']);
        Route::put('/sections/{id}', [DeskController::class, 'updateSection'])->whereNumber('id');
        Route::delete('/sections/{id}', [DeskController::class, 'deleteSection'])->whereNumber('id');
        Route::post('/jobs', [DeskController::class, 'createJob']);
        Route::put('/jobs/{id}', [DeskController::class, 'updateJob'])->whereNumber('id');
        Route::post('/jobs/{id}/publish', [DeskController::class, 'publishJob'])->whereNumber('id');
        Route::post('/jobs/{id}/status', [DeskController::class, 'jobStatus'])->whereNumber('id');
        Route::put('/inbox/{id}', [DeskController::class, 'updateInbox'])->whereNumber('id');
        Route::post('/inbox/{id}/job', [DeskController::class, 'jobFromInbox'])->whereNumber('id');
        Route::put('/members/{userId}', [DeskController::class, 'setMemberRole'])->whereNumber('userId');
        Route::post('/members/invite', [DeskController::class, 'invite']);
        Route::put('/resume/settings', [DeskController::class, 'resumeSettings']);
    });
    Route::middleware('throttle:10,1')->post('/commissions', [DeskController::class, 'commission']);
});
