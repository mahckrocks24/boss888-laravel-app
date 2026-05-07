<?php

namespace App\Core\Notifications;

use App\Models\Notification;

class NotificationService
{
    public function send(int $workspaceId, string $channel, string $type, array $data = []): Notification
    {
        return Notification::create([
            'workspace_id' => $workspaceId,
            'channel' => $channel,
            'type' => $type,
            'data_json' => $data,
        ]);
    }

    public function listForWorkspace(int $workspaceId, bool $unreadOnly = false): \Illuminate\Database\Eloquent\Collection
    {
        $query = Notification::where('workspace_id', $workspaceId);

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->orderByDesc('created_at')->limit(50)->get();
    }

    public function markRead(int $notificationId): void
    {
        Notification::where('id', $notificationId)->update(['read_at' => now()]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // V2 surface — typed dispatch with user targeting + email + preferences
    // Added 2026-05-07. Coexists with legacy send()/listForWorkspace()/markRead().
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Dispatch a typed notification to a single user. Writes to notifications
     * table and queues an email if user preferences allow OR if the type is
     * email-required (security/billing-critical types per NotificationTypes::
     * emailRequired). Returns the new notification id.
     */
    public function dispatch(
        string $type,
        int $userId,
        string $title,
        ?int $workspaceId = null,
        ?string $body = null,
        ?array $data = null,
        ?string $actionUrl = null,
        string $severity = 'info',
        ?string $icon = null
    ): int {
        $emailRequired = NotificationTypes::emailRequired($type);
        $category      = NotificationTypes::category($type);

        $notification = Notification::create([
            'workspace_id'   => $workspaceId,
            'user_id'        => $userId,
            'type'           => $type,
            'category'       => $category,
            'title'          => $title,
            'body'           => $body,
            'data_json'      => $data,
            'action_url'     => $actionUrl,
            'icon'           => $icon,
            'severity'       => $severity,
            'email_required' => $emailRequired,
            'channel'        => 'in_app',
        ]);

        // Preference lookup — workspace-agnostic (matches user_id + type).
        // If no preference row exists, default email = true.
        $pref = \Illuminate\Support\Facades\DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->where('notification_type', $type)
            ->first();
        $emailEnabled = $pref ? (bool) $pref->email : true;

        if ($emailEnabled || $emailRequired) {
            \dispatch(new \App\Jobs\SendNotificationEmail($notification->id))
                ->onQueue('default');
        }

        return (int) $notification->id;
    }

    /**
     * Broadcast a message to every active workspace owner. Only callable by
     * a platform admin (verified via users.is_platform_admin). Returns the
     * count of notifications written.
     */
    public function broadcast(
        int $adminUserId,
        string $title,
        string $body,
        string $severity = 'info',
        ?string $actionUrl = null
    ): int {
        $admin = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $adminUserId)
            ->where('is_platform_admin', true)
            ->first();
        if (! $admin) {
            throw new \RuntimeException('Unauthorized broadcast: caller is not a platform admin');
        }

        $owners = \Illuminate\Support\Facades\DB::table('workspace_users as wu')
            ->join('users as u', 'u.id', '=', 'wu.user_id')
            ->where('wu.role', 'owner')
            ->select('wu.user_id', 'wu.workspace_id')
            ->get();

        $count = 0;
        foreach ($owners as $owner) {
            $this->dispatch(
                type: NotificationTypes::SYSTEM_ADMIN_BROADCAST,
                userId: (int) $owner->user_id,
                title: $title,
                workspaceId: (int) $owner->workspace_id,
                body: $body,
                severity: $severity,
                actionUrl: $actionUrl
            );
            $count++;
        }
        return $count;
    }

    /**
     * Unread notification count for a user across all workspaces.
     */
    public function unreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark all unread notifications as read for a user. If $workspaceId is
     * supplied, scope the mark to that workspace only.
     */
    public function markAllRead(int $userId, ?int $workspaceId = null): void
    {
        $q = Notification::where('user_id', $userId)->whereNull('read_at');
        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }
        $q->update(['read_at' => now()]);
    }
}
