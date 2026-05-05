<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\TaskSystem\TaskService;
use App\Core\EngineKernel\CapabilityMapService;
use App\Services\ParameterResolverService;

class ManualExecutionController
{
    public function __construct(
        private TaskService $taskService,
        private CapabilityMapService $capabilityMap,
        private ParameterResolverService $paramResolver,
    ) {}

    /**
     * POST /api/manual/execute
     *
     * Manual execution goes through the SAME pipeline:
     *   validate → resolve → create task → approval check → dispatch → execute
     *
     * No shortcuts. No bypass.
     */
    public function execute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|string',
            'params' => 'nullable|array',
            'priority' => 'nullable|in:low,normal,high,urgent',
        ]);

        $workspaceId = $request->attributes->get('workspace_id');
        $action = $data['action'];
        $params = $data['params'] ?? [];

        // 1. Validate capability exists
        $capability = $this->capabilityMap->resolve($action);
        if (! $capability) {
            return response()->json([
                'success' => false,
                'message' => "Unknown action: {$action}",
                'available_actions' => array_keys($this->capabilityMap->getAllCapabilities()),
            ], 400);
        }

        // 2. Check connector availability
        if (! $this->capabilityMap->isConnectorAvailable($action)) {
            return response()->json([
                'success' => false,
                'message' => "Connector not available for action: {$action}",
            ], 503);
        }

        // 3. Parameter resolution (pre-validate before creating task)
        $resolution = $this->paramResolver->resolve($workspaceId, $action, $params);

        if (! $resolution['resolved']) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required parameters',
                'missing' => $resolution['missing'],
                'errors' => $resolution['errors'],
            ], 422);
        }

        // 4. Create task through the SAME TaskService pipeline
        $task = $this->taskService->create($workspaceId, [
            'engine' => $capability['engine'],
            'action' => $action,
            'payload' => $resolution['params'],
            'source' => 'manual',
            'priority' => $data['priority'] ?? 'normal',
            'credit_cost' => $capability['credit_cost'],
        ]);

        // 5. Return task status
        $response = [
            'success' => true,
            'task_id' => $task->id,
            'status' => $task->status,
            'requires_approval' => $task->requires_approval,
        ];

        if ($task->requires_approval) {
            $response['message'] = 'Task created and awaiting approval';
            $response['approval_mode'] = $capability['approval_mode'];
        } else {
            $response['message'] = 'Task dispatched for execution';
        }

        return response()->json($response, 201);
    }
}
