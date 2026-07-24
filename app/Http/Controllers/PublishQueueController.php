<?php

namespace App\Http\Controllers;

use App\Core\Orchestration\PublishGateService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PublishQueueController extends Controller
{
    public function __construct(private readonly PublishGateService $gate) {}

    /** GET /api/publish-queue */
    public function index(Request $r)
    {
        $wsId   = (int) $r->attributes->get('workspace_id');
        $status = $r->query('status', 'queued');
        $limit  = (int) ($r->query('limit', 50));
        return response()->json([
            'success' => true,
            'data'    => $this->gate->listForWorkspace($wsId, $status, $limit),
        ]);
    }

    /** GET /api/publish-queue/plan/{planId} */
    public function listForPlan(Request $r, int $planId)
    {
        $wsId   = (int) $r->attributes->get('workspace_id');
        $status = $r->query('status');
        return response()->json([
            'success' => true,
            'data'    => $this->gate->listForPlan($wsId, $planId, $status),
            'stats'   => $this->gate->statsForPlan($wsId, $planId),
        ]);
    }

    /** POST /api/publish-queue/{id}/approve */
    public function approve(Request $r, int $id)
    {
        $wsId   = (int) $r->attributes->get('workspace_id');
        $userId = $r->user()?->id ?? 1;
        return response()->json($this->gate->approve($wsId, $id, $userId, $r->input('note')));
    }

    /** POST /api/publish-queue/{id}/reject */
    public function reject(Request $r, int $id)
    {
        $wsId   = (int) $r->attributes->get('workspace_id');
        $userId = $r->user()?->id ?? 1;
        return response()->json($this->gate->reject($wsId, $id, $userId, $r->input('note')));
    }

    /** POST /api/publish-queue/bulk-approve */
    public function bulkApprove(Request $r)
    {
        $wsId   = (int) $r->attributes->get('workspace_id');
        $userId = $r->user()?->id ?? 1;
        $ids    = $r->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'error' => 'ids array required'], 422);
        }
        return response()->json($this->gate->bulkApprove($wsId, $ids, $userId, $r->input('note')));
    }
}