<?php

/**
 * ADS888 admin API routes — phases A1 (read only) and A3 (campaign management).
 *
 * Required from routes/api.php inside the existing
 * `Route::middleware(['auth.jwt','admin'])->prefix('admin')` group, so every
 * route here inherits BOTH the JWT check and AdminMiddleware's
 * `is_platform_admin` gate. Nothing in this file may be reachable without them.
 *
 * Kept as its own module (CR-22B style) so ADS888 adds ONE require line to the
 * governed parent rather than ~25 route definitions.
 *
 * MFA STEP-UP is applied to creative approval specifically. Approving a
 * creative is what puts third-party content onto customer websites; the admin
 * role alone is not a high enough bar for that one action.
 */

use App\Http\Controllers\Api\Admin\AdminAdsCampaignController;
use App\Http\Controllers\Api\Admin\AdminAdsController;
use Illuminate\Support\Facades\Route;

Route::prefix('ads')->group(function () {

    // ── A1 — read only ──────────────────────────────────────────────────
    Route::get('/status',                  [AdminAdsController::class, 'status']);
    Route::get('/dashboard',               [AdminAdsController::class, 'dashboard']);
    Route::get('/inventory',               [AdminAdsController::class, 'inventory']);
    Route::get('/inventory/unclassified',  [AdminAdsController::class, 'unclassified']);
    Route::get('/invalid-traffic',         [AdminAdsController::class, 'invalidTraffic']);
    Route::get('/revenue',                 [AdminAdsController::class, 'revenue']);
    Route::get('/settings',                [AdminAdsController::class, 'settings']);
    Route::get('/reconcile',               [AdminAdsController::class, 'reconcile']);
    Route::get('/audit',                   [AdminAdsController::class, 'audit']);
    Route::get('/campaigns/{id}/report',   [AdminAdsController::class, 'campaignReport'])
        ->whereNumber('id');

    // ── A3 — advertisers, campaigns, creatives ──────────────────────────
    Route::get   ('/advertisers',       [AdminAdsCampaignController::class, 'listAdvertisers']);
    Route::post  ('/advertisers',       [AdminAdsCampaignController::class, 'createAdvertiser']);
    Route::put   ('/advertisers/{id}',  [AdminAdsCampaignController::class, 'updateAdvertiser'])->whereNumber('id');

    Route::get   ('/campaigns',                 [AdminAdsCampaignController::class, 'listCampaigns']);
    Route::post  ('/campaigns',                 [AdminAdsCampaignController::class, 'createCampaign']);
    Route::put   ('/campaigns/{id}',            [AdminAdsCampaignController::class, 'updateCampaign'])->whereNumber('id');
    Route::get   ('/campaigns/{id}/targeting',  [AdminAdsCampaignController::class, 'campaignTargeting'])->whereNumber('id');

    Route::post  ('/reach',      [AdminAdsCampaignController::class, 'reach']);

    Route::get   ('/creatives',  [AdminAdsCampaignController::class, 'listCreatives']);
    Route::post  ('/creatives',  [AdminAdsCampaignController::class, 'uploadCreative']);

    // ── A2 — settings editing ───────────────────────────────────────────
    // Changing eligible_plan_slugs or the master switch decides whether ads
    // appear on customer websites at all, so these sit behind step-up with
    // creative approval rather than with ordinary reads.
    Route::middleware('mfa.stepup')->group(function () {
        Route::put ('/settings/{key}',        [AdminAdsController::class, 'updateSetting'])
            ->where('key', '[a-z0-9_]+');
        Route::post('/settings/{key}/reset',  [AdminAdsController::class, 'resetSetting'])
            ->where('key', '[a-z0-9_]+');

        // Approval puts third-party content on customer websites — admin role
        // is not enough on its own.
        Route::post('/creatives/{id}/approve', [AdminAdsCampaignController::class, 'approveCreative'])->whereNumber('id');
        Route::post('/creatives/{id}/reject',  [AdminAdsCampaignController::class, 'rejectCreative'])->whereNumber('id');
    });
});
