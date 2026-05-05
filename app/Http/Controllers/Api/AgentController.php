<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Core\Agents\AgentService;
use App\Core\PlanGating\PlanGatingService;

class AgentController
{
    public function __construct(
        private AgentService $service,
        private PlanGatingService $planGating,
    ) {}

    /**
     * GET /api/agents — returns agents available to the authenticated workspace.
     * Free/Starter: empty. AI Lite: Sarah only (research). Growth+: workspace team.
     */
    public function index(Request $request): JsonResponse
    {
        $wsId = $request->attributes->get('workspace_id');
        if (!$wsId) {
            // Fallback: resolve workspace from authenticated user
            $user = $request->user();
            if ($user) {
                $wsRow = \Illuminate\Support\Facades\DB::table('workspace_users')->where('user_id', $user->id)->first();
                if ($wsRow) $wsId = $wsRow->workspace_id;
            }
            if (!$wsId) {
                return response()->json(['agents' => [], 'error' => 'No workspace context']);
            }
        }

        $rules = $this->planGating->getPlanRules($wsId);

        // No AI access = no agents
        if ($rules['ai_access'] === 'none') {
            return response()->json(['agents' => [], 'plan_limit' => 'No AI access on current plan']);
        }

        // AI Lite = Sarah only (research assistant, not full agent)
        if (!$rules['includes_dmm']) {
            return response()->json(['agents' => [], 'plan_limit' => 'AI agents require Growth plan or above']);
        }

        // Growth/Pro/Agency = workspace-assigned agents
        $agents = $this->service->forWorkspace($wsId);

        return response()->json([
            'agents' => $agents,
            'plan' => $rules['plan_name'],
            'agent_count' => $rules['agent_count'],
            'agent_level' => $rules['agent_level'],
        ]);
    }

    public function forWorkspace(int $id): JsonResponse
    {
        return response()->json(['agents' => $this->service->forWorkspace($id)]);
    }
}
