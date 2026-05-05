<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminIntelligenceController
{
    /**
     * All meetings across workspaces.
     */
    public function listMeetings(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('meetings')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'meetings.workspace_id')
                ->count();

            $meetings = DB::table('meetings')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'meetings.workspace_id')
                ->select('meetings.*', 'workspaces.name as workspace_name')
                ->orderBy('meetings.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $meetings,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch meetings', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * All strategy proposals across workspaces.
     */
    public function listProposals(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('strategy_proposals')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'strategy_proposals.workspace_id')
                ->count();

            $proposals = DB::table('strategy_proposals')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'strategy_proposals.workspace_id')
                ->select('strategy_proposals.*', 'workspaces.name as workspace_name')
                ->orderBy('strategy_proposals.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $proposals,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch proposals', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * All global knowledge entries.
     */
    public function globalKnowledge(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('global_knowledge')->count();

            $entries = DB::table('global_knowledge')
                ->orderBy('created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $entries,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch global knowledge', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Memory entries across all workspaces.
     */
    public function workspaceMemory(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('workspace_memory')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'workspace_memory.workspace_id')
                ->count();

            $memory = DB::table('workspace_memory')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'workspace_memory.workspace_id')
                ->select('workspace_memory.*', 'workspaces.name as workspace_name')
                ->orderBy('workspace_memory.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $memory,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch workspace memory', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * All experiments.
     */
    public function experiments(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('experiments')->count();

            $experiments = DB::table('experiments')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'experiments.workspace_id')
                ->select('experiments.*', 'workspaces.name as workspace_name')
                ->orderBy('experiments.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $experiments,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch experiments', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * All notifications across workspaces.
     */
    public function notifications(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('notifications')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'notifications.workspace_id')
                ->count();

            $notifications = DB::table('notifications')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'notifications.workspace_id')
                ->select('notifications.*', 'workspaces.name as workspace_name')
                ->orderBy('notifications.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $notifications,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch notifications', 'message' => $e->getMessage()], 500);
        }
    }
}
