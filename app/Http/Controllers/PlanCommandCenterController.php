<?php

namespace App\Http\Controllers;

use App\Core\Orchestration\PlanCommandCenterService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PlanCommandCenterController extends Controller
{
    public function __construct(private readonly PlanCommandCenterService $svc) {}

    /** GET /api/plans/{id}/command-center */
    public function show(Request $r, int $id)
    {
        $wsId = (int) $r->attributes->get('workspace_id');
        $payload = $this->svc->get($wsId, $id);
        if ($payload === null) {
            return response()->json(['success' => false, 'error' => 'plan not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $payload]);
    }

    /** GET /api/plans/{id}/summary — lightweight check */
    public function summary(Request $r, int $id)
    {
        $wsId = (int) $r->attributes->get('workspace_id');
        $payload = $this->svc->summary($wsId, $id);
        if ($payload === null) {
            return response()->json(['success' => false, 'error' => 'plan not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $payload]);
    }
}