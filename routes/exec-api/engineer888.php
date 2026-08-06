<?php

/**
 * Engineer888 companion API — /exec-api/engineer888/*
 *
 * DELIBERATELY NOT BEHIND plan:app888. Engineer888 is an internal company
 * department, not a customer subscription feature. The existing companion
 * routes gate on a Pro+ entitlement because they serve customers; gating this
 * one the same way would make an internal engineering tool depend on a billing
 * flag, and would imply that buying a plan is a step towards reaching it.
 *
 * THIS IS NOT A BYPASS — IT IS A STRICTER AUTHORITY. The customer routes ask
 * "does this workspace have the feature?". These ask "is this the one human
 * Engineer888 belongs to?", answered by Engineer888Access: canonical id AND
 * canonical email AND active platform admin AND explicit grant AND human
 * session AND non-API-key. No plan, workspace, role or entitlement reaches it.
 *
 * The customer exec-api group is untouched; this is a separate mount.
 */

use App\Http\Middleware\DenyApiKeyAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('exec-api/engineer888')
    ->middleware(['throttle:60,1', 'auth.jwt', DenyApiKeyAuth::class])
    ->group(function () {
        require __DIR__ . '/../api/admin/engineer888-chat.php';
    });
