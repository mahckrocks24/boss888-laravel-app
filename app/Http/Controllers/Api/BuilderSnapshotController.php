<?php

namespace App\Http\Controllers\Api;

use App\Engines\Builder\Services\BuilderSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builder snapshot endpoints.
 *
 * GET  /api/builder/pages/{pageId}/history
 *      → last 20 snapshots (id, reason, created_at)
 * POST /api/builder/pages/{pageId}/restore/{stateId}
 *      → restore page to that snapshot (also captures current state first)
 *
 * Both routes are workspace-scoped via the page → website join.
 */
class BuilderSnapshotController
{
    public function __construct(
        protected BuilderSnapshotService $snapshots,
    ) {}

    public function history(Request $req, int $pageId): JsonResponse
    {
        $wsId = (int) $req->attributes->get('workspace_id');
        if ($wsId <= 0) return response()->json(['error' => 'Workspace context missing'], 400);

        $page = DB::table('pages')
            ->join('websites', 'websites.id', '=', 'pages.website_id')
            ->where('pages.id', $pageId)
            ->where('websites.workspace_id', $wsId)
            ->select('pages.id')
            ->first();
        if (! $page) return response()->json(['error' => 'Not found'], 404);

        return response()->json([
            'success' => true,
            'page_id' => $pageId,
            'history' => $this->snapshots->history($pageId),
        ]);
    }

    public function restore(Request $req, int $pageId, int $stateId): JsonResponse
    {
        $wsId = (int) $req->attributes->get('workspace_id');
        if ($wsId <= 0) return response()->json(['error' => 'Workspace context missing'], 400);

        $page = DB::table('pages')
            ->join('websites', 'websites.id', '=', 'pages.website_id')
            ->where('pages.id', $pageId)
            ->where('websites.workspace_id', $wsId)
            ->select('pages.id')
            ->first();
        if (! $page) return response()->json(['error' => 'Not found'], 404);

        try {
            $this->snapshots->restore($pageId, $stateId);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'success'  => true,
            'page_id'  => $pageId,
            'state_id' => $stateId,
            'message'  => "Page restored to snapshot {$stateId}",
        ]);
    }
}
