<?php

/**
 * CR-22B — extracted route module: studio-01
 *
 * Source: routes/api.php lines 10271-10435 of the authoritative pre-extraction
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
 * Owner: STUDIO888   ·   Routes: 18   ·   Statements: 1
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
    // ── Creative Engine ──────────────────────────────────────────
    Route::prefix('creative')->group(function () {
        $s    = \App\Engines\Creative\Services\CreativeService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;

        // Dashboard
        Route::get('/dashboard', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getDashboard($r->attributes->get('workspace_id'))));

        // Prompt Studio — FREE enhancement preview (no credits, no asset, no provider call).
        // Surfaces "what the engine understood" + the exact prompt generation will send,
        // so the user confirms before spending a credit. wsId from JWT, never from params.
        Route::post('/plan-preview', fn(\Illuminate\Http\Request $r) => response()->json(
            app($s)->planPreview((int) $r->attributes->get('workspace_id'), $r->all())
        ));

        // Brand identity (CIMS)
        Route::get('/brand', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getBrandIdentity($r->attributes->get('workspace_id'))));
        Route::put('/brand', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->updateBrandIdentity($r->attributes->get('workspace_id'), $r->all())));

        // Generation memory
        Route::get('/memory', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getGenerationMemory($r->attributes->get('workspace_id'), $r->query('type'), (int) $r->query('limit', 20))));
        Route::get('/memory/stats', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getMemoryStats($r->attributes->get('workspace_id'))));

        // Blueprint (cross-engine entry point — callable by other engines or directly)
        Route::post('/blueprint', function (\Illuminate\Http\Request $r) use ($s) {
            $wsId   = $r->attributes->get('workspace_id');
            $engine = $r->input('engine', 'creative');
            $type   = $r->input('type', 'content');
            return response()->json(app($s)->generateThroughBlueprint($engine, $type, $wsId, $r->except(['engine', 'type'])));
        });

        // Image generation (through pipeline — approval-gated, credit-tracked)
        Route::post('/generate/image', function (\Illuminate\Http\Request $r) use ($exec) {
            return response()->json(app($exec)->execute(
                $r->attributes->get('workspace_id'), 'creative', 'generate_image', $r->all(),
                ['user_id' => $r->user()?->id, 'source' => 'manual']
            ), 202);
        })->middleware('throttle:20,1');

        // Video generation (async — returns in_progress immediately)
        Route::post('/generate/video', function (\Illuminate\Http\Request $r) use ($exec) {
            return response()->json(app($exec)->execute(
                $r->attributes->get('workspace_id'), 'creative', 'generate_video', $r->all(),
                ['user_id' => $r->user()?->id, 'source' => 'manual']
            ), 202);
        })->middleware('throttle:20,1');

        // Video job polling
        Route::get('/assets/{id}/poll', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->pollVideoJob((int) $id)));

        // STUDIO888 Phase O — masked/local prompt edit (through the kernel: credit
        // reserve/commit/release + idempotency + workspace scoping). Creates a
        // non-destructive child version; the original is never overwritten.
        //
        // EXACTLY-ONCE under concurrency: the kernel only enforces idempotency on
        // the ASYNC task path, so two same-key requests could otherwise both
        // reserve+dispatch (double charge). We serialise same-key edits with a
        // MySQL named lock BEFORE the kernel charges: the winner runs once; a
        // racing duplicate waits, then returns the already-created child (no
        // second reserve/dispatch/commit). Different keys never contend.
        Route::post('/edit', function (\Illuminate\Http\Request $r) use ($exec) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $key  = trim((string) $r->input('idempotency_key', ''));
            $src  = (int) $r->input('source_asset_id', 0);
            $run  = fn () => app($exec)->execute($wsId, 'creative', 'edit_image', $r->all(),
                ['user_id' => $r->user()?->id, 'source' => 'manual']);

            if ($key === '') {
                return response()->json($run()); // opted out of idempotency
            }

            $lock = 'studio_edit:' . $wsId . ':' . $src . ':' . md5($key);
            $db   = \Illuminate\Support\Facades\DB::connection();
            $existing = function () use ($wsId, $src, $key) {
                $row = \Illuminate\Support\Facades\DB::table('assets')
                    ->where('workspace_id', $wsId)->where('parent_asset_id', $src)->whereNull('deleted_at')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.idempotency_key')) = ?", [$key])
                    ->first();
                return $row ? ['success' => true, 'status' => 'completed', 'idempotent_replay' => true, 'data' => [
                    'id' => (int) $row->id, 'asset_id' => (int) $row->id, 'url' => $row->url,
                    'parent_asset_id' => (int) $row->parent_asset_id, 'root_asset_id' => (int) $row->root_asset_id,
                    'version' => (int) $row->version, 'edit_mode' => $row->edit_mode, 'type' => 'image',
                ]] : null;
            };

            // Fast path: already done.
            if ($hit = $existing()) return response()->json($hit);

            $got = (int) $db->selectOne('SELECT GET_LOCK(?, 45) AS l', [$lock])->l;
            if ($got !== 1) {
                // Couldn't acquire in time — an identical edit is almost certainly
                // in flight. Deterministic, non-charging conflict response.
                if ($hit = $existing()) return response()->json($hit);
                return response()->json(['success' => false, 'status' => 'in_progress',
                    'error' => 'An identical edit is already being processed.'], 409);
            }
            try {
                if ($hit = $existing()) return response()->json($hit); // winner finished while we waited
                return response()->json($run());
            } finally {
                $db->selectOne('SELECT RELEASE_LOCK(?) AS r', [$lock]);
            }
        })->middleware('throttle:20,1');

        // STUDIO888 Phase O — version tree for an asset (original + all edits).
        // P1R-8 (2026-08-31): an id outside this workspace is 404, like DELETE — not 200 with an empty tree.
        Route::get('/assets/{id}/versions', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $owner = (int) \Illuminate\Support\Facades\DB::table('assets')->where('id', (int) $id)->value('workspace_id');
            if ($owner !== $wsId) { return response()->json(['error' => 'not_found'], 404); }
            return response()->json(app($s)->getAssetVersions($wsId, (int) $id));
        });

        // STUDIO888 Phase P — resolve a Studio image URL → its creative asset,
        // so "AI Edit" can open on a selected Studio image (tenancy-scoped).
        Route::get('/resolve-asset', fn(\Illuminate\Http\Request $r) => response()->json(['asset' => app($s)->resolveAssetByUrl($r->attributes->get('workspace_id'), (string) $r->query('url', ''))]));

        // Asset CRUD
        Route::get('/assets', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listAssets($r->attributes->get('workspace_id'), $r->all())));
        // P1R-8: 404 for an asset that is not this workspace's, instead of 200 {}.
        Route::get('/assets/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $owner = (int) \Illuminate\Support\Facades\DB::table('assets')->where('id', (int) $id)->value('workspace_id');
            if ($owner !== $wsId) { return response()->json(['error' => 'not_found'], 404); }
            return response()->json(app($s)->getAsset($wsId, (int) $id));
        });
        Route::delete('/assets/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            if ((int) \Illuminate\Support\Facades\DB::table('assets')->where('id', (int) $id)->value('workspace_id') !== (int) $r->attributes->get('workspace_id')) {
                return response()->json(['deleted' => false, 'error' => 'not_found'], 404);
            }
            return response()->json(['deleted' => app($s)->deleteAsset((int) $id)]);
        });

        // ── Video job status (creative-engine.js polls this path) ──
        Route::get('/video/jobs/{id}/status', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->pollVideoJob((int) $id)));

        // ── Website scan (creative-engine.js advanced feature) ──
        Route::post('/scan-url', function (\Illuminate\Http\Request $r) {
            $url = $r->input('url');
            if (!$url) return response()->json(['error' => 'URL required'], 422);
            // Basic URL scan — returns page title, description, colors
            try {
                $html = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]));
                if (!$html) return response()->json(['url' => $url, 'error' => 'Could not fetch URL']);
                preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $titleMatch);
                preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $descMatch);
                return response()->json([
                    'url' => $url,
                    'title' => $titleMatch[1] ?? null,
                    'description' => $descMatch[1] ?? null,
                    'scan_complete' => true,
                ]);
            } catch (\Throwable $e) {
                return response()->json(['url' => $url, 'error' => $e->getMessage()]);
            }
        });

        // PATCH v1.0.1: POST /creative/assets — frontend calls this with {type, prompt}.
        // Was 404 because only GET /assets existed. Routes generation through the execution
        // pipeline so approval gating and credit deduction apply.
        // type=image → creative/generate_image (10 credits, auto-approve)
        // type=video → creative/generate_video (25 credits, review-gated)
        Route::post('/assets', function (\Illuminate\Http\Request $r) use ($exec) {
            $r->validate([
                'type'   => 'required|in:image,video',
                'prompt' => 'required|string|max:2000',
            ]);

            $wsId   = $r->attributes->get('workspace_id');
            $type   = $r->input('type');
            $action = $type === 'video' ? 'generate_video' : 'generate_image';

            return response()->json(
                app($exec)->execute(
                    $wsId,
                    'creative',
                    $action,
                    $r->all(),
                    ['user_id' => $r->user()?->id, 'source' => 'manual']
                ),
                202
            );
        });
    });
