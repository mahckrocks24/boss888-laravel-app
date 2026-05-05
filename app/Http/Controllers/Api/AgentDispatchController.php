<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\Agent\AgentDispatchService;

class AgentDispatchController
{
    public function __construct(private AgentDispatchService $service) {}

    /**
     * POST /api/agent/dispatch
     * APP888 sends a message to an agent.
     */
    public function dispatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => 'required|string|exists:agents,slug',
            'content' => 'required|string|max:5000',
            'conversation_id' => 'nullable|string',
            'source' => 'nullable|string|max:20',
            'attachments' => 'nullable|array',
            'priority' => 'nullable|in:low,normal,high,urgent',
        ]);

        $workspaceId = $request->attributes->get('workspace_id');
        $userId = $request->user()->id;

        $result = $this->service->dispatch($workspaceId, $userId, $data);

        return response()->json($result, 201);
    }

    /**
     * GET /api/agent/conversations
     * List user's agent conversations.
     */
    public function conversations(Request $request): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $userId = $request->user()->id;

        $conversations = $this->service->listConversations($workspaceId, $userId);

        return response()->json($conversations);
    }

    /**
     * GET /api/agent/conversation/{id}
     * Get single conversation with messages.
     */
    public function conversation(Request $request, int $id): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $this->service->getConversation($workspaceId, $id);

        return response()->json($data);
    }

    /**
     * GET /api/agent/events
     * Cursor-based event stream for APP888 polling.
     */
    public function events(Request $request): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $userId = $request->user()->id;

        $cursor = $request->query('cursor');
        $conversationId = $request->query('conversation_id');

        $data = $this->service->getEvents($workspaceId, $userId, $cursor, $conversationId);

        return response()->json($data);
    }
}
