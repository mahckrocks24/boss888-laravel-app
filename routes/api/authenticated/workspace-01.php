<?php

/**
 * CR-22B — extracted route module: workspace-01
 *
 * Source: routes/api.php lines 13599-13738 of the authoritative pre-extraction
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
 * Owner: Workspace   ·   Routes: 11   ·   Statements: 11
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
    // ── Workspace Onboarding ─────────────────────────────────────
    Route::put('/workspace/settings', function (\Illuminate\Http\Request $r) {
        // Alias for onboarding save — crm-engine.js and core.js call PUT /workspace/settings
        $ws = \App\Models\Workspace::findOrFail($r->attributes->get('workspace_id'));
        $ws->update(array_filter([
            'business_name' => $r->input('business_name'),
            'industry' => $r->input('industry'),
            'services_json' => $r->input('services') ?: $r->input('services_json'),
            'goal' => $r->input('goal') ?: $r->input('business_desc'),
            'location' => $r->input('location'),
        ]));
        return response()->json(['success' => true]);
    });

        Route::post('/workspace/onboarding', function (\Illuminate\Http\Request $r) {
        $ws = \App\Models\Workspace::findOrFail($r->attributes->get('workspace_id'));
        $ws->update([
            'business_name' => $r->input('business_name'),
            'industry' => $r->input('industry'),
            'services_json' => $r->input('services'),
            'goal' => $r->input('goal'),
            'location' => $r->input('location'),
        ]);
        return response()->json(['success' => true]);
    });

    Route::post('/workspace/complete-onboarding', function (\Illuminate\Http\Request $r) {
        $ws = \App\Models\Workspace::findOrFail($r->attributes->get('workspace_id'));
        $ws->update(['onboarded' => true, 'onboarded_at' => now()]);

        // Sarah sends cost estimate ONLY — zero credits, template message
        try {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            $proposal = $proactive->onOnboardingComplete($ws->id, $r->user()->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Proactive proposal failed: {$e->getMessage()}");
            $proposal = ['error' => $e->getMessage()];
        }

        return response()->json([
            'success' => true,
            'proposal' => $proposal,
            'message' => 'Welcome! Sarah has prepared a strategy proposal. Review and approve in the Strategy Room — no credits used until you approve.',
        ]);
    });

    // ── Workspace Status (for SaaS app dashboard) ────────────────
    Route::get('/workspace/status', function (\Illuminate\Http\Request $r) {
        $ws = \App\Models\Workspace::findOrFail($r->attributes->get('workspace_id'));
        $planRules = app(\App\Core\PlanGating\PlanGatingService::class)->getPlanRules($ws->id);
        // BUILDER888 D11 (2026-08-29) — website = workspace: a wizard-built site's workspace has no
        // credit row of its own; credits live in the billing pool (workspaces.billing_workspace_id,
        // see CreditService). Reading by workspace_id showed "0 of 900" in every new site's workspace.
        $__poolWs = (int) (\Illuminate\Support\Facades\DB::table('workspaces')->where('id', $ws->id)->value('billing_workspace_id') ?: $ws->id);
        $credit = \App\Models\Credit::where('workspace_id', $__poolWs)->first();
        $websiteCount = \Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', $ws->id)->whereNull('deleted_at')->count();
        return response()->json([
            'workspace' => $ws, 'plan' => $planRules,
            'credit_balance' => $credit?->balance ?? 0,
            'monthly_credit_limit' => $planRules['credit_limit'],
            'website_count' => $websiteCount,
            'business_name' => $ws->business_name,
            'industry' => $ws->industry,
            'onboarded' => (bool) $ws->onboarded,
        ]);
    });

    // ── Workspace Credits ────────────────────────────────────────
    Route::get('/workspace/credits', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // BUILDER888 D11 — balance comes from the billing pool (see /workspace/status).
        $__poolWs = (int) (\Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('billing_workspace_id') ?: $wsId);
        $credit = \App\Models\Credit::where('workspace_id', $__poolWs)->first();
        $planRules = app(\App\Core\PlanGating\PlanGatingService::class)->getPlanRules($wsId);
        $used = \App\Models\CreditTransaction::where('workspace_id', $wsId)->where('type', 'commit')->sum('amount');
        return response()->json([
            'credit_balance' => $credit?->balance ?? 0,
            'monthly_limit' => $planRules['credit_limit'],
            'plan_name' => $planRules['plan_name'],
            'lifetime_used' => abs($used),
        ]);
    });

    Route::get('/workspace/credits/transactions', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $txns = \App\Models\CreditTransaction::where('workspace_id', $wsId)
            ->orderByDesc('created_at')->limit($r->input('limit', 20))->get();
        return response()->json(['transactions' => $txns]);
    });

    // ── Workspace Proactive Settings ─────────────────────────────
    Route::get('/workspace/proactive-settings', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $ws   = \App\Models\Workspace::find($wsId);
        return response()->json([
            'proactive_enabled'   => $ws?->proactive_enabled ?? true,
            'proactive_frequency' => $ws?->proactive_frequency ?? 'daily',
        ]);
    });

    Route::put('/workspace/proactive-settings', function (\Illuminate\Http\Request $r) {
        $wsId    = $r->attributes->get('workspace_id');
        $enabled = $r->input('proactive_enabled');
        $freq    = $r->input('proactive_frequency');

        $update = [];
        if (!is_null($enabled))  $update['proactive_enabled']   = (bool) $enabled;
        if (!is_null($freq) && in_array($freq, ['daily', 'weekly'])) {
            $update['proactive_frequency'] = $freq;
        }

        if (!empty($update)) {
            \App\Models\Workspace::where('id', $wsId)->update($update);
        }

        $ws = \App\Models\Workspace::find($wsId);
        return response()->json([
            'updated'             => !empty($update),
            'proactive_enabled'   => $ws?->proactive_enabled ?? true,
            'proactive_frequency' => $ws?->proactive_frequency ?? 'daily',
        ]);
    });

    // ── Workspace Capabilities Map (for frontend feature gating) ─
    Route::get('/workspace/capabilities', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        return response()->json(
            app(\App\Core\Billing\FeatureGateService::class)->getCapabilities($wsId)
        );
    });

    // ── Trial status ──────────────────────────────────────────────
    Route::get('/workspace/trial-status', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        return response()->json(
            app(\App\Core\Billing\TrialService::class)->getTrialStatus($wsId)
        );
    });

    // Manual trial activation (for testing / admin override)
    Route::post('/workspace/trial/activate', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        return response()->json(
            app(\App\Core\Billing\TrialService::class)->activateTrial($wsId)
        );
    });
