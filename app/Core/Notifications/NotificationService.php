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
}
