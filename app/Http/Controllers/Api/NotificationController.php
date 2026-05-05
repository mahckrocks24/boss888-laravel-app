<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\Notifications\NotificationService;

class NotificationController
{
    public function __construct(private NotificationService $service) {}

    /**
     * GET /api/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $unreadOnly = $request->boolean('unread_only', false);

        $notifications = $this->service->listForWorkspace($workspaceId, $unreadOnly);

        return response()->json(['notifications' => $notifications]);
    }

    /**
     * POST /api/notifications/{id}/read
     */
    public function markRead(int $id): JsonResponse
    {
        $this->service->markRead($id);

        return response()->json(['success' => true]);
    }
}
