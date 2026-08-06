<?php

/**
 * Engineer888 chat routes — shared definition, mounted twice.
 *
 * MOUNTED BY BOTH SURFACES so they cannot diverge: routes/api/admin/engineer888.php
 * for the Admin console, and routes/exec-api/engineer888.php for the companion
 * app. Same controller, same services, same tables, same capabilities. A
 * requirement that web and mobile show the same conversation is met by there
 * being only one definition of what a conversation is.
 *
 * CAPABILITIES ARE ACTION-SPECIFIC. Reading the conversation is not sending a
 * message; sending a message is not approving bytes. One broad capability on
 * every endpoint would make the grant table decorative.
 *
 * NO EDIT ROUTE. NO DELETE ROUTE. Messages are append-only from every surface,
 * and the system messages that record project changes are protected by the
 * absence of a path rather than by a flag somebody could forget to check.
 *
 * The caller supplies the prefix and the middleware stack; both mounts already
 * carry auth.jwt and DenyApiKeyAuth before this file is reached.
 */

use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use App\Http\Controllers\Api\Admin\Engineer888ChatController as Chat;
use App\Http\Middleware\RequireEngineer888Access as Gate;
use Illuminate\Support\Facades\Route;

Route::prefix('chat')->group(function () {

    // ── read ────────────────────────────────────────────────────────────
    Route::get('/bootstrap', [Chat::class, 'bootstrap'])
        ->middleware(Gate::class . ':' . Cap::VIEW);

    Route::get('/conversation', [Chat::class, 'conversation'])
        ->middleware(Gate::class . ':' . Cap::VIEW);

    Route::get('/messages', [Chat::class, 'messages'])
        ->middleware(Gate::class . ':' . Cap::VIEW);

    Route::get('/events', [Chat::class, 'events'])
        ->middleware(Gate::class . ':' . Cap::VIEW);

    // NO GENERIC CARD READ. Cards arrive with the conversation, filtered per
    // card by the capability that card's own action requires, so
    // review_candidate cannot become a universal card-read permission.

    // ── write ───────────────────────────────────────────────────────────
    Route::post('/messages', [Chat::class, 'send'])
        ->middleware(Gate::class . ':' . Cap::MESSAGE);

    // Choosing where work will be filed is part of creating it. CREATE_TASK is
    // the narrowest existing capability that means "may cause engineering work
    // to exist"; VIEW would let a read-only grant redirect the department.
    Route::post('/project', [Chat::class, 'selectProject'])
        ->middleware(Gate::class . ':' . Cap::CREATE_TASK);

    // ── card actions: one endpoint per authority ────────────────────────
    // Different cards are different powers. A single mutation endpoint gated on
    // approve_candidate would let that one grant execute tasks, approve
    // recoveries and approve migrations, which makes the capability list
    // decorative. The route names the authority; the service then proves the
    // stored card is genuinely that action and re-asks for the exact capability.
    Route::post('/actions/{uuid}/approve-candidate', [Chat::class, 'approveCandidate'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_CANDIDATE);

    Route::post('/actions/{uuid}/reject-candidate', [Chat::class, 'rejectCandidate'])
        ->middleware(Gate::class . ':' . Cap::REVIEW_CANDIDATE);

    Route::post('/actions/{uuid}/execute-task', [Chat::class, 'executeTask'])
        ->middleware(Gate::class . ':' . Cap::EXECUTE);

    Route::post('/actions/{uuid}/approve-recovery', [Chat::class, 'approveRecovery'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_RECOVERY);

    Route::post('/actions/{uuid}/approve-migration', [Chat::class, 'approveMigration'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_MIGRATION);

    // Revoking a previously given approval needs the authority that gave it.
    Route::post('/actions/{uuid}/revoke', [Chat::class, 'revokeApproval'])
        ->middleware(Gate::class . ':' . Cap::APPROVE_CANDIDATE);
});
