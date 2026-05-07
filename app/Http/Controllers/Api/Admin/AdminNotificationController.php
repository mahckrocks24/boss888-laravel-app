<?php

namespace App\Http\Controllers\Api\Admin;

use App\Core\Notifications\NotificationService;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-admin notification endpoints.
 *
 * Gated by the `is_platform_admin` flag — applied via AdminMiddleware on
 * the route group, plus the broadcast() service method does an additional
 * defense-in-depth check.
 */
class AdminNotificationController
{
    public function __construct(private NotificationService $service) {}

    /**
     * GET /api/admin/notifications
     * Filters: workspace_id, type, severity, category
     */
    public function index(Request $request): JsonResponse
    {
        $q = Notification::with(['workspace'])->orderByDesc('created_at');

        if ($request->filled('workspace_id')) $q->where('workspace_id', (int) $request->input('workspace_id'));
        if ($request->filled('type'))         $q->where('type',         $request->input('type'));
        if ($request->filled('severity'))     $q->where('severity',     $request->input('severity'));
        if ($request->filled('category'))     $q->where('category',     $request->input('category'));

        return response()->json($q->paginate(50));
    }

    /**
     * POST /api/admin/notifications/broadcast
     * Body: title, body, severity?, action_url?
     */
    public function broadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'      => 'required|string|max:255',
            'body'       => 'required|string',
            'severity'   => 'sometimes|in:info,success,warning,error',
            'action_url' => 'nullable|string|max:255',
        ]);

        $adminUserId = (int) $request->attributes->get('user_id');

        $count = $this->service->broadcast(
            adminUserId: $adminUserId,
            title:       $validated['title'],
            body:        $validated['body'],
            severity:    $validated['severity'] ?? 'info',
            actionUrl:   $validated['action_url'] ?? null
        );

        return response()->json(['success' => true, 'sent_to' => $count]);
    }
}
