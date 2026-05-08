<?php

namespace App\Http\Controllers\Api;

use App\Engines\Builder\Services\ArthurEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Patch 8.5 Tier 1 — Arthur structured-JSON edit endpoint.
 *
 * POST /api/builder/pages/{pageId}/arthur-edit
 * Body: {message: "...", section_index?: int}
 *
 * Workspace-scoped via pages → websites.workspace_id join. Refuses with
 * HTTP 422 on pages without sections_json (i.e. legacy static-HTML-only
 * pages like Chef Red — those must use the legacy
 * /api/builder/websites/{id}/arthur-edit closure until T3.4 migrates them).
 */
class ArthurEditController
{
    public function __construct(
        protected ArthurEditService $arthur,
    ) {}

    public function edit(Request $request, int $pageId): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        if ($wsId <= 0) {
            return response()->json(['error' => 'Workspace context missing'], 400);
        }

        // Verify the page belongs to the caller's workspace via the websites join.
        $page = DB::table('pages')
            ->join('websites', 'websites.id', '=', 'pages.website_id')
            ->where('pages.id', $pageId)
            ->where('websites.workspace_id', $wsId)
            ->select('pages.id', 'pages.sections_json', 'pages.slug', 'websites.subdomain')
            ->first();
        if (! $page) {
            return response()->json(['error' => 'Page not found'], 404);
        }

        // Refuse legacy static-HTML pages — Patch 8.6 (Chef Red migration) handles those.
        if (empty($page->sections_json) || $page->sections_json === '[]' || strlen($page->sections_json) < 5) {
            return response()->json([
                'error'  => 'This page uses the legacy static-HTML path. Migrate to sections_json (T3.4) before using Arthur JSON edits.',
                'legacy' => true,
            ], 422);
        }

        $validated = $request->validate([
            'message'       => 'required|string|max:2000',
            'section_index' => 'nullable|integer|min:0',
        ]);

        try {
            $result = $this->arthur->editPage(
                pageId:       $pageId,
                userMessage:  $validated['message'],
                sectionIndex: $validated['section_index'] ?? null,
                context:      ['subdomain' => $page->subdomain ?? null],
            );
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => 'Arthur edit failed',
                'detail'  => $e->getMessage(),
            ], 500);
        }
    }
}
