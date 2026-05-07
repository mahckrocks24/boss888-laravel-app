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

    // ═══════════════════════════════════════════════════════════════════
    // V2 endpoints (2026-05-07) — user-scoped notifications + preferences
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        return response()->json([
            'count' => $this->service->unreadCount($userId),
        ]);
    }

    /**
     * POST /api/notifications/read-all
     * Body (optional): {workspace_id: int} — defaults to attributes.workspace_id.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $userId      = (int) $request->attributes->get('user_id');
        $workspaceId = $request->input('workspace_id')
            ?? $request->attributes->get('workspace_id');
        $this->service->markAllRead($userId, $workspaceId ? (int) $workspaceId : null);
        return response()->json(['success' => true]);
    }

    /**
     * GET /api/notifications/preferences
     */
    public function preferences(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        $prefs  = \Illuminate\Support\Facades\DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->get();
        return response()->json(['preferences' => $prefs]);
    }

    /**
     * PUT /api/notifications/preferences
     * Body: {preferences: [{notification_type, in_app, email, workspace_id?}]}
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        $items  = (array) $request->input('preferences', []);

        foreach ($items as $item) {
            if (empty($item['notification_type'])) continue;

            \Illuminate\Support\Facades\DB::table('notification_preferences')->updateOrInsert(
                [
                    'user_id'           => $userId,
                    'workspace_id'      => $item['workspace_id'] ?? null,
                    'notification_type' => $item['notification_type'],
                ],
                [
                    'in_app'     => isset($item['in_app']) ? (bool) $item['in_app'] : true,
                    'email'      => isset($item['email'])  ? (bool) $item['email']  : true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return response()->json(['success' => true]);
    }
}
