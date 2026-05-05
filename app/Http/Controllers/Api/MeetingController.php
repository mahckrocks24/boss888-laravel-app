<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\Meetings\MeetingService;

class MeetingController
{
    public function __construct(private MeetingService $service) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'agent_ids' => 'nullable|array',
            'agent_ids.*' => 'integer|exists:agents,id',
        ]);

        $workspaceId = $request->attributes->get('workspace_id');
        $meeting = $this->service->create($workspaceId, $request->user()->id, $data);
        return response()->json(['meeting' => $meeting], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        return response()->json(['meetings' => $this->service->listForWorkspace($workspaceId)]);
    }

    public function show(int $id): JsonResponse
    {
        $meeting = $this->service->find($id);
        if (! $meeting) {
            return response()->json(['error' => 'Meeting not found'], 404);
        }
        return response()->json(['meeting' => $meeting]);
    }

    public function addMessage(Request $request, int $id): JsonResponse
    {
        $request->validate(['message' => 'required|string']);
        $msg = $this->service->addMessage(
            $id, 'user', $request->user()->id,
            $request->input('message'),
            $request->input('attachments'),
        );
        return response()->json(['message' => $msg], 201);
    }
}
