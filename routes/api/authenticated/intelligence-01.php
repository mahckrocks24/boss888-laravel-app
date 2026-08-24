<?php

/**
 * CR-22B — extracted route module: intelligence-01
 *
 * Source: routes/api.php lines 3950-4081 of the authoritative pre-extraction
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
 * Owner: Intelligence   ·   Routes: 18   ·   Statements: 3
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
    // 2026-05-24 — AgentBrowser transparency layer
    //
    // Every external-web touch by any agent (or user-on-behalf-of-agent)
    // goes through WebActivityService and lands in agent_web_activity.
    // The GET /activity endpoint surfaces the full log for transparency.
    // ══════════════════════════════════════════════════════════════
    Route::prefix('web')->group(function () {
        Route::post('/fetch', function (\Illuminate\Http\Request $r) {
            $r->validate(['url' => 'required|url|max:2048', 'agent' => 'required|string|max:64']);
            $svc = app(\App\Engines\Web\Services\WebActivityService::class);
            return response()->json($svc->fetch(
                (int) $r->attributes->get('workspace_id'),
                (string) $r->input('agent'),
                (int) ($r->user()->id ?? 0) ?: null,
                (string) $r->input('url'),
                (int) $r->input('task_id') ?: null,
            ));
        });

        Route::post('/search', function (\Illuminate\Http\Request $r) {
            $r->validate(['query' => 'required|string|min:1|max:512', 'agent' => 'required|string|max:64']);
            $svc = app(\App\Engines\Web\Services\WebActivityService::class);
            return response()->json($svc->search(
                (int) $r->attributes->get('workspace_id'),
                (string) $r->input('agent'),
                (int) ($r->user()->id ?? 0) ?: null,
                (string) $r->input('query'),
                (int) $r->input('task_id') ?: null,
            ));
        });

        Route::get('/activity', function (\Illuminate\Http\Request $r) {
            $svc = app(\App\Engines\Web\Services\WebActivityService::class);
            return response()->json([
                'activity' => $svc->listActivity(
                    (int) $r->attributes->get('workspace_id'),
                    $r->input('agent'),
                    $r->input('since'),
                    (int) $r->input('limit', 100),
                    (int) $r->input('task_id') ?: null,
                ),
            ]);
        });
    });

    // ══════════════════════════════════════════════════════════════
    // EXPERIMENTS — A/B testing engine
    // ══════════════════════════════════════════════════════════════

    Route::prefix('experiments')->group(function () {
        $e = \App\Core\Intelligence\CampaignOptimizationEngine::class;
        Route::get('/', function (\Illuminate\Http\Request $r) use ($e) {
            return response()->json(['experiments' => app($e)->listExperiments($r->attributes->get('workspace_id'), $r->all())]);
        });
        Route::post('/', function (\Illuminate\Http\Request $r) use ($e) {
            $r->validate(['name' => 'required|string', 'engine' => 'required|string', 'hypothesis' => 'required|string']);
            return response()->json(['experiment_id' => app($e)->createExperiment($r->attributes->get('workspace_id'), $r->all())], 201);
        });
        Route::get('/{id}', function (\Illuminate\Http\Request $r, $id) use ($e) {
            return response()->json(app($e)->getExperiment($r->attributes->get('workspace_id'), $id));
        });
        Route::post('/{id}/start', function ($id) use ($e) {
            app($e)->startExperiment($id);
            return response()->json(['started' => true]);
        });
        Route::post('/{id}/result', function (\Illuminate\Http\Request $r, $id) use ($e) {
            $r->validate(['variant_id' => 'required|string', 'results' => 'required|array']);
            app($e)->recordVariantResult($id, $r->input('variant_id'), $r->input('results'));
            return response()->json(['recorded' => true]);
        });
        Route::post('/{id}/evaluate', function ($id) use ($e) {
            return response()->json(app($e)->evaluateExperiment($id));
        });
        Route::get('/{wsId}/compare/{engine}', function (\Illuminate\Http\Request $r, $wsId, $engine) use ($e) {
            return response()->json(app($e)->compareStrategies($wsId, $engine));
        });
    });

    // ══════════════════════════════════════════════════════════════
    // INTELLIGENCE — query knowledge, agent profiles, validation
    // ══════════════════════════════════════════════════════════════

    Route::prefix('intelligence')->group(function () {
        // Global knowledge query
        Route::get('/knowledge', function (\Illuminate\Http\Request $r) {
            return response()->json(['insights' => app(\App\Core\Intelligence\GlobalKnowledgeService::class)->query($r->all())]);
        });

        // Agent profile
        Route::get('/agents/{slug}/profile', function ($slug) {
            $agent = \App\Models\Agent::where('slug', $slug)->first();
            if (!$agent) return response()->json(['error' => 'Agent not found'], 404);
            return response()->json(app(\App\Core\Intelligence\AgentExperienceService::class)->getAgentProfile($agent->id));
        });

        // Agent trust score
        Route::get('/agents/{slug}/trust', function (\Illuminate\Http\Request $r, $slug) {
            $agent = \App\Models\Agent::where('slug', $slug)->first();
            if (!$agent) return response()->json(['error' => 'Agent not found'], 404);
            $ws = \App\Models\Workspace::find($r->attributes->get('workspace_id'));
            return response()->json(app(\App\Core\Intelligence\Validation\IntelligenceValidator::class)
                ->getAgentTrustScore($agent->id, $ws?->industry));
        });

        // Engine briefing
        Route::get('/engines/{engine}/briefing', function ($engine) {
            return response()->json(app(\App\Core\Intelligence\EngineIntelligenceService::class)->getBriefing($engine));
        });

        // Segmented effectiveness
        Route::get('/engines/{engine}/effectiveness/{tool}', function ($engine, $tool) {
            return response()->json(app(\App\Core\Intelligence\Validation\IntelligenceValidator::class)
                ->getSegmentedEffectiveness($engine, $tool));
        });

        // Time-based intelligence
        Route::get('/seasonal-patterns', function (\Illuminate\Http\Request $r) {
            return response()->json(app(\App\Core\Intelligence\GlobalKnowledgeService::class)
                ->getSeasonalPatterns($r->input('engine'), $r->input('industry')));
        });

        Route::get('/trends', function (\Illuminate\Http\Request $r) {
            return response()->json(app(\App\Core\Intelligence\GlobalKnowledgeService::class)
                ->getTrends($r->input('engine'), $r->input('industry'), $r->input('months', 6)));
        });

        Route::get('/lifecycle', function (\Illuminate\Http\Request $r) {
            return response()->json(app(\App\Core\Intelligence\GlobalKnowledgeService::class)
                ->getLifecycleInsights($r->input('engine')));
        });
    });
