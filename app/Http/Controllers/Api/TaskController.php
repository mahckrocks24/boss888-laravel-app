<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\TaskSystem\TaskService;
use App\Services\TaskProgressService;

class TaskController
{
    public function __construct(
        private TaskService $service,
        private TaskProgressService $progressService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'engine' => 'required|string',
            'action' => 'required|string',
            'payload' => 'nullable|array',
            'source' => 'nullable|in:manual,agent,system',
            'assigned_agents' => 'nullable|array',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'requires_approval' => 'nullable|boolean',
            'credit_cost' => 'nullable|integer|min:0',
        ]);

        $workspaceId = $request->attributes->get('workspace_id');
        try {
            $task = $this->service->create($workspaceId, $data);
            return response()->json(['task' => $task], 201);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // 2026-05-26 — duplicate idempotency_key. Return a friendly 409
            // with the existing task instead of a generic 500.
            $payload = $data['payload'] ?? [];
            $payloadForHash = $payload;
            if (is_array($payloadForHash)) ksort($payloadForHash);
            $idemKey = $data['idempotency_key'] ?? hash('sha256',
                "{$workspaceId}:{$data['action']}:" . json_encode($payloadForHash));
            $existing = \App\Models\Task::where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idemKey)
                ->first();
            return response()->json([
                'error'   => 'duplicate_task',
                'message' => 'An identical task already exists. Returning the existing one.',
                'task'    => $existing,
            ], 409);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $tasks = $this->service->listForWorkspace($workspaceId, $request->only(['status', 'engine', 'limit']));
        return response()->json(['tasks' => $tasks]);
    }

    /**
     * LAUNCH-P0-1 (2026-09-07, DEC-0039 / EV-0916). show(), status() and events() resolved a task by bare id:
     * any authenticated customer could read ANY workspace's task — payload, user_request, result — proven live
     * against a QA tenant during the launch pass (GET /api/tasks/32082 from workspace 1000001 returned
     * workspace 999995's task). Every read is now confined to the caller's workspace, and a foreign id
     * answers 404 exactly like a nonexistent one so existence is not leaked either (same contract as
     * PUT /tasks/{id}/status, POST /tasks/{id}/retry and POST /tasks/{id}/cancel in projects-01.php).
     */
    private function ownTask(Request $request, int $id): ?\App\Models\Task
    {
        $ws = (int) $request->attributes->get('workspace_id');
        if ($ws <= 0) return null;
        return \App\Models\Task::where('id', $id)->where('workspace_id', $ws)->first();
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $task = $this->ownTask($request, $id);
        if (! $task) {
            return response()->json(['error' => 'Task not found'], 404);
        }
        return response()->json(['task' => $task->load('approval')]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        if (! $this->ownTask($request, $id)) {
            return response()->json(['error' => 'Task not found'], 404);
        }
        $status = $this->progressService->getStatus($id);
        if (! $status) {
            return response()->json(['error' => 'Task not found'], 404);
        }
        return response()->json($status);
    }

    public function events(Request $request, int $id): JsonResponse
    {
        if (! $this->ownTask($request, $id)) {
            return response()->json(['error' => 'Task not found'], 404);
        }
        $events = $this->progressService->getEvents($id);
        return response()->json(['events' => $events]);
    }
}
