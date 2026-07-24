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

    public function show(int $id): JsonResponse
    {
        $task = $this->service->find($id);
        if (! $task) {
            return response()->json(['error' => 'Task not found'], 404);
        }
        return response()->json(['task' => $task->load('approval')]);
    }

    public function status(int $id): JsonResponse
    {
        $status = $this->progressService->getStatus($id);
        if (! $status) {
            return response()->json(['error' => 'Task not found'], 404);
        }
        return response()->json($status);
    }

    public function events(int $id): JsonResponse
    {
        $events = $this->progressService->getEvents($id);
        return response()->json(['events' => $events]);
    }
}
