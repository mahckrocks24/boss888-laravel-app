<?php

namespace App\Http\Controllers\Api;

use App\Core\EngineKernel\EngineExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BaseEngineController — THE enforcement layer.
 *
 * Every engine controller MUST extend this class.
 *
 * Rules:
 *   - READ actions (GET): call engine service directly for performance
 *   - WRITE actions (POST/PUT/DELETE): MUST call $this->executeAction()
 *     which routes through EngineExecutionService → credits → approvals → intelligence
 *
 * What executeAction() enforces:
 *   1. Capability validation
 *   2. Plan gating
 *   3. Credit check + reserve
 *   4. Approval check
 *   5. Execute via engine service
 *   6. Credit commit
 *   7. Automation triggers
 *   8. Cross-engine sync
 *   9. Audit log
 *  10. Intelligence recording (agent experience, engine usage, global knowledge)
 *
 * Usage in child controllers:
 *
 *   // READ — direct (no credits, no pipeline)
 *   public function listLeads(Request $r): JsonResponse {
 *       return $this->readJson($this->service()->listLeads($this->wsId($r), $r->all()));
 *   }
 *
 *   // WRITE — through pipeline (credits, approvals, intelligence)
 *   public function createLead(Request $r): JsonResponse {
 *       $r->validate(['name' => 'required|string']);
 *       return $this->executeAction($r, 'create_lead', $r->all());
 *   }
 */
abstract class BaseEngineController
{
    /**
     * Engine slug — set by each child controller.
     * Must match engine names in capability map.
     */
    abstract protected function engineSlug(): string;

    /**
     * Get workspace ID from request.
     */
    protected function wsId(Request $r): int
    {
        return (int) $r->attributes->get('workspace_id');
    }

    /**
     * Get user ID from request.
     */
    protected function userId(Request $r): ?int
    {
        return $r->user()?->id;
    }

    /**
     * Execute a WRITE action through the full AI OS pipeline.
     * This is THE method all write endpoints must use.
     *
     * @param Request $r       The HTTP request
     * @param string  $action  The action name (create_lead, serp_analysis, etc.)
     * @param array   $params  Action parameters
     * @param string  $source  Execution source (manual, agent, automation)
     * @param int     $status  HTTP status code on success
     */
    protected function executeAction(Request $r, string $action, array $params = [], string $source = 'manual', int $status = 200): JsonResponse
    {
        $executor = app(EngineExecutionService::class);

        $result = $executor->execute(
            $this->wsId($r),
            $this->engineSlug(),
            $action,
            array_merge($params, ['_user_id' => $this->userId($r)]),
            [
                'user_id' => $this->userId($r),
                'source' => $source,
                'priority' => $r->input('_priority', 'normal'),
                'agent_id' => $r->input('_agent_id'),
            ]
        );

        if (!($result['success'] ?? false)) {
            $code = match ($result['code'] ?? 'UNKNOWN') {
                'PLAN_GATED' => 403,
                'NO_CREDITS' => 402,
                'AWAITING_APPROVAL' => 202,
                'INVALID_ACTION' => 400,
                'EXECUTION_FAILED' => 500,
                default => 400,
            };

            return response()->json($result, $code);
        }

        return response()->json($result, $result['pending_approval'] ?? false ? 202 : $status);
    }

    /**
     * Execute a WRITE action asynchronously (for agent mode).
     */
    protected function executeAsync(Request $r, string $action, array $params = []): JsonResponse
    {
        $executor = app(EngineExecutionService::class);

        $result = $executor->executeAsync(
            $this->wsId($r),
            $this->engineSlug(),
            $action,
            $params,
            [
                'user_id' => $this->userId($r),
                'agent_id' => $r->input('_agent_id', 'sarah'),
                'source' => 'agent',
                'priority' => $r->input('_priority', 'normal'),
            ]
        );

        return response()->json($result, 202);
    }

    /**
     * Return JSON for READ operations (no pipeline needed).
     */
    protected function readJson($data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }
}
