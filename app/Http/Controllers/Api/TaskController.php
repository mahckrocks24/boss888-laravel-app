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
        $task = $this->service->create($workspaceId, $data);
        return response()->json(['task' => $task], 201);
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
