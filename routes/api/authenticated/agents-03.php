<?php

/**
 * CR-22B — extracted route module: agents-03
 *
 * Source: routes/api.php lines 3751-3948 of the authoritative pre-extraction
 * file (sha256 9aa519a1445f8e26…), copied VERBATIM — not reformatted, reordered
 * or edited in any way.
 *
 * Included from INSIDE the authenticated group closure
 *   Route::middleware(['auth.jwt','traffic.defense','connector.brand'])->group(...)
 * at the exact position the code previously occupied, so middleware stack,
 * prefix nesting and registration order are unchanged. PHP `require` executes in
 * the including scope, so parent-closure variables remain visible.
 *
 * `use` aliases, however, do NOT cross a require boundary — they are resolved
 * per file at compile time. A missing import does not fatal: `TaskController::class`
 * silently becomes the string "TaskController" and the route registers against a
 * wrong action. The FULL parent import set is therefore re-declared below,
 * unconditionally, in every module. Unused imports trigger no autoload and cost
 * nothing; a missing one is a silent production defect.
 *
 * Owner: Agent platform   ·   Routes: 22   ·   Statements: 1
 *
 * CR-22 scope forbids improving anything in this file. Move it, do not edit it.
 */

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DesignTokenController;
use App\Http\Controllers\Api\EngineController;
use App\Http\Controllers\Api\ManualExecutionController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====
    // ══════════════════════════════════════════════════════════════
    // SARAH ORCHESTRATOR — THE real DMM system controller
    // All user goals flow through Sarah → plan → delegate → execute → evaluate
    // ══════════════════════════════════════════════════════════════

    Route::prefix('sarah')->group(function () {
        // Main entry: user sends goal → Sarah creates plan
        Route::post('/receive', function (\Illuminate\Http\Request $r) {
            $r->validate(['goal' => 'required|string']);
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            return response()->json($sarah->receive(
                $r->attributes->get('workspace_id'), $r->user()->id,
                $r->input('goal'), $r->input('context', [])
            ));
        });

        // Approve a plan
        Route::post('/plans/{id}/approve', function (\Illuminate\Http\Request $r, $id) {
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            $res = $sarah->approvePlan((int) $r->attributes->get('workspace_id'), (int) $id, $r->user()->id);
            // b21 — a plan in another workspace must answer 404, not a 200 whose
            // body happens to say not_found.
            if (($res['status'] ?? null) === 'not_found') {
                return response()->json(['error' => 'Plan not found'], 404);
            }
            return response()->json($res);
        });

        // Cancel a plan
        // b21 (2026-07-24) — pass the caller's workspace; cancelPlan() previously
        // took an id alone and would cancel ANY workspace's plan.
        Route::post('/plans/{id}/cancel', function (\Illuminate\Http\Request $r, $id) {
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            $ok = $sarah->cancelPlan((int) $id, (int) $r->attributes->get('workspace_id'));
            if (! $ok) {
                return response()->json(['error' => 'Plan not found'], 404);
            }
            return response()->json(['cancelled' => true]);
        });

        // Get plan status (for polling)
        // b21 — was unscoped: any authenticated caller could read any plan by id.
        Route::get('/plans/{id}', function (\Illuminate\Http\Request $r, $id) {
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            $res = $sarah->getPlanStatus((int) $id, (int) $r->attributes->get('workspace_id'));
            if (isset($res['error'])) {
                return response()->json($res, 404);
            }
            return response()->json($res);
        });

        // List plans
        Route::get('/plans', function (\Illuminate\Http\Request $r) {
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            return response()->json(['plans' => $sarah->listPlans($r->attributes->get('workspace_id'), $r->all())]);
        });

        // ── Strategy Meetings (real agent collaboration) ──
        Route::post('/meeting/start', function (\Illuminate\Http\Request $r) {
            $_wsId = (int) $r->attributes->get('workspace_id');
            $_credits = app(\App\Core\Billing\CreditService::class);
            if (!$_credits->hasBalance($_wsId, 8)) {
                return response()->json(['success' => false, 'error' => 'Not enough credits — strategy meeting costs 8 credits.', 'required_credits' => 8], 402);
            }
            $_credits->debit($_wsId, 8, 'sarah/strategy_meeting');
            $r->validate(['goal' => 'required|string']);
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            $result = $engine->startMeeting(
                $r->attributes->get('workspace_id'), $r->user()->id,
                $r->input('goal'), $r->input('agents', [])
            );

            // Wave 87 — auto-advance after opening returns. App->terminating
            // runs after the response is sent so opening surfaces fast (~5s)
            // while contributions/debate/synthesis run in background. UI keeps
            // polling /meeting/{id}/status — sees phases progress + final tasks.
            if (!empty($result['meeting_id']) && empty($result['error'])) {
                $meetingId = (int) $result['meeting_id'];
                app()->terminating(function () use ($meetingId, $engine) {
                    try {
                        $engine->autoAdvanceMeeting($meetingId);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('[Meeting auto-advance] failed', [
                            'meeting_id' => $meetingId, 'error' => $e->getMessage(),
                        ]);
                    }
                });
            }

            return response()->json($result);
        });

        Route::post('/meeting/{id}/advance', function ($id) {
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->advanceMeeting($id));
        });

        Route::post('/meeting/full', function (\Illuminate\Http\Request $r) {
            $_wsId = (int) $r->attributes->get('workspace_id');
            $_credits = app(\App\Core\Billing\CreditService::class);
            if (!$_credits->hasBalance($_wsId, 8)) {
                return response()->json(['success' => false, 'error' => 'Not enough credits — strategy meeting costs 8 credits.', 'required_credits' => 8], 402);
            }
            $_credits->debit($_wsId, 8, 'sarah/strategy_meeting');
            $r->validate(['goal' => 'required|string']);
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->runFullMeeting(
                $r->attributes->get('workspace_id'), $r->user()->id, $r->input('goal')
            ));
        });

        Route::get('/meeting/{id}', function ($id) {
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->getMeetingTranscript($id));
        });

        // User participates in meeting
        Route::post('/meeting/{id}/message', function (\Illuminate\Http\Request $r, $id) {
            $r->validate(['message' => 'required|string']);
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->userMessage($id, $r->user()->id, $r->input('message')));
        });

        // User ends meeting
        Route::post('/meeting/{id}/end', function (\Illuminate\Http\Request $r, $id) {
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->endMeeting($id, $r->user()->id));
        });

        // Get meeting cost estimate before starting
        Route::post('/meeting/estimate', function (\Illuminate\Http\Request $r) {
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->estimateMeetingCost($r->input('agents', [])));
        });

        // ── Proactive Strategy + Proposals ──
        Route::get('/proposals', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json(['proposals' => $proactive->listProposals($r->attributes->get('workspace_id'), $r->input('status'))]);
        });

        Route::post('/proposals/{id}/approve', function (\Illuminate\Http\Request $r, $id) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->approveProposal($r->attributes->get('workspace_id'), $r->user()->id, $id));
        });

        Route::post('/proposals/{id}/decline', function (\Illuminate\Http\Request $r, $id) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->declineProposal($r->attributes->get('workspace_id'), $id));
        });

        // 2026-05-24 FIX 55 — batch approve/decline. Accepts {"ids":[...]}.
        Route::post('/proposals/batch-approve', function (\Illuminate\Http\Request $r) {
            $r->validate(['ids' => 'required|array|min:1|max:50', 'ids.*' => 'integer']);
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->batchApprove(
                $r->attributes->get('workspace_id'),
                $r->user()->id,
                $r->input('ids', [])
            ));
        });

        Route::post('/proposals/batch-decline', function (\Illuminate\Http\Request $r) {
            $r->validate(['ids' => 'required|array|min:1|max:50', 'ids.*' => 'integer']);
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->batchDecline(
                $r->attributes->get('workspace_id'),
                $r->input('ids', [])
            ));
        });

        // Cost estimate for any plan
        Route::post('/estimate-cost', function (\Illuminate\Http\Request $r) {
            $r->validate(['tasks' => 'required|array']);
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->estimatePlanCost($r->input('tasks')));
        });

        Route::post('/proactive/onboarding', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->onOnboardingComplete($r->attributes->get('workspace_id'), $r->user()->id));
        });

        Route::post('/proactive/daily-check', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->dailyCheck($r->attributes->get('workspace_id')));
        });

        Route::post('/proactive/weekly-review', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->weeklyReview($r->attributes->get('workspace_id')));
        });

        Route::post('/proactive/monthly-strategy', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->monthlyStrategy($r->attributes->get('workspace_id'), $r->user()->id));
        });
    });
