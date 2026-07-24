<?php

namespace App\Core\Notifications;

use App\Models\Notification;

class NotificationService
{
    /**
     * Sender label for SYSTEM notifications (task events, etc.). Agent CHAT
     * replies keep the agent's OWN name (handled in PushDispatcherService) —
     * only non-conversational system notifications use the platform brand.
     */
    public const SYSTEM_SENDER = 'LevelUp Growth';

    public function send(int $workspaceId, string $channel, string $type, array $data = []): Notification
    {
        // 2026-06-11 — legacy send() stored ONLY type+data_json, so task.completed/
        // failed notifications rendered BLANK in the bell ("no traces"). Populate a
        // human title/body: sender = "LevelUp Growth" (system events are from the
        // platform, not the agent persona), body = a no-schema-leakage summary.
        return Notification::create([
            'workspace_id' => $workspaceId,
            'channel'      => $channel,
            'type'         => $type,
            'title'        => self::deriveSender($type),
            'body'         => self::deriveBody($type, $data),
            'data_json'    => $data,
        ]);
    }

    /**
     * Sender label. An agent's OWN proactive outreach (sarah_proposal / reminder
     * / weekly / monthly) keeps the AGENT's name; everything else is a system
     * event and uses the platform brand "LevelUp Growth".
     */
    public static function deriveSender(string $type): string
    {
        if (preg_match('/^([a-z]+)_(proposal|reminder|weekly|monthly|monthly_proposal|message|suggestion|checkin)/', $type, $m)) {
            // W6: never surface a removed agent as sender of a live notification.
            if (\App\Core\LaunchScope\AgentDirectory::isRemoved($m[1])) return self::SYSTEM_SENDER;
            $row = \Illuminate\Support\Facades\DB::table('agents')->where('slug', $m[1])->value('name');
            return $row ?: ucfirst($m[1]);
        }
        return self::SYSTEM_SENDER;
    }

    /** Human, no-schema-leakage body for a system notification (type + data). */
    public static function deriveBody(string $type, array $data): string
    {
        $action = isset($data['action']) ? self::humanizeAction((string) $data['action']) : null;
        switch ($type) {
            case 'task.completed':
                return $action ? ucfirst($action) . ' is done.' : 'A task finished successfully.';
            case 'task.failed':
                $reason = self::humanizeError((string) ($data['error'] ?? ''));
                return ($action ? ucfirst($action) . ' didn\'t finish' : 'A task didn\'t finish')
                    . ($reason !== '' ? ' — ' . $reason . '.' : '.');
            case 'task.approval_required':
                return 'A task is waiting for your approval.';
            case 'approval.approved':
                return 'An approved task has been started.';
            case 'subscription.upgraded':
                return 'Your subscription has been upgraded.';
            case 'sarah_proposal':
            case 'sarah_monthly_proposal':
                return 'I have a growth proposal ready for you to review.';
            case 'sarah_reminder':
                return 'A quick reminder on your marketing plan.';
            case 'sarah_weekly':
                return 'Your weekly growth summary is ready.';
            default:
                return 'You have a new update.';
        }
    }

    /** Map an internal action slug to plain English. Never surfaces raw slugs. */
    public static function humanizeAction(string $slug): string
    {
        $map = [
            'write_article' => 'writing your article', 'generate_meta' => 'the meta description',
            'generate_image_mini' => 'the featured image', 'generate_image' => 'the featured image',
            'generate_image_high' => 'the featured image', 'aeo_enrich' => 'the AI-search optimisation',
            'link_suggestions' => 'finding internal links', 'insert_link' => 'adding an internal link',
            'fix_orphans' => 'linking your orphan pages', 'deep_audit' => 'the SEO audit',
            'serp_analysis' => 'the SERP analysis', 'competitor_serp' => 'the competitor analysis',
            'competitor_keywords' => 'the keyword research', 'social_create_post' => 'your social post',
            'create_lead' => 'adding a lead', 'create_campaign' => 'your campaign',
            'email_ai_generate' => 'your email', 'publish_article' => 'publishing your article',
            'improve_draft' => 'improving your draft', 'generate_article' => 'writing your article',
        ];
        if (isset($map[$slug])) return $map[$slug];
        $words = trim(str_replace('_', ' ', $slug));
        return $words !== '' ? $words : 'a task';
    }

    /** Reduce a raw error to a short, safe phrase — never leaks the raw error/stack. */
    public static function humanizeError(string $err): string
    {
        $e = strtolower($err);
        if (str_contains($e, 'not supported') || str_contains($e, 'no capability') || str_contains($e, 'no handler')) return 'that step isn\'t available yet';
        if (str_contains($e, 'prompt') && str_contains($e, 'required')) return 'it was missing some details';
        if (str_contains($e, 'url required')) return 'it was missing a URL';
        if (str_contains($e, 'credit') || str_contains($e, 'insufficient')) return 'there weren\'t enough credits';
        return '';
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
