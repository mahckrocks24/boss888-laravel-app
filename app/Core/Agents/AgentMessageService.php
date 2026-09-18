<?php

namespace App\Core\Agents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AgentMessageService — central writer for agent-to-user messages.
 *
 * Wave 5 (2026-05-18). Single entry point for any agent (James, Sarah,
 * Priya, etc.) to post a message that lands in the user's view via the
 * platform messages-ui.js floater, the agent profile thread, and the
 * unified Messages page. All three surfaces consume the same
 * `agent_messages` table — so writing here lights up all three at once.
 *
 * Pair with NotificationService::dispatch() when the message ALSO
 * deserves a cross-engine notification (e.g. task completed); use
 * postAsAgent() alone when it's purely a chat update.
 *
 * Counts as unread when role='agent' AND read_at IS NULL — see
 * /messages/unread-count route. Once the user opens that agent's
 * thread, /messages/{slug}/read flips read_at and the badge clears.
 */
class AgentMessageService
{
    /**
     * Post a message AS an agent (proactive update from the agent to the
     * user). Returns the new agent_messages row id on success.
     *
     * @param int    $wsId        Workspace id
     * @param string $agentSlug   Lowercase agent slug — must exist in `agents` table
     * @param string $content     Message body (markdown OK). Up to 65k chars; truncated.
     * @param array  $metadata    Optional structured metadata (action_link, related_ids, etc.)
     */
    public function postAsAgent(int $wsId, string $agentSlug, string $content, array $metadata = []): ?int
    {
        $agentSlug = strtolower(trim($agentSlug));
        if ($agentSlug === '') {
            return null;
        }
        // Validate slug — silently skip if agent unknown (no DB error)
        $agent = DB::table('agents')->where('slug', $agentSlug)->first(['id', 'name']);
        if (! $agent) {
            Log::warning('AgentMessageService::postAsAgent — unknown agent slug', [
                'workspace_id' => $wsId, 'slug' => $agentSlug,
            ]);
            return null;
        }

        // Owner 2026-09-18 — the customer hears from Sarah only. A specialist's proactive message lands in
        // Sarah's thread, under her name, with the specialist named inside it (SarahVoice). The slug validated
        // above is kept in the metadata so nothing about who did the work is lost.
        $voice     = SarahVoice::relay($agentSlug, (string) $agent->name, $content, $metadata);
        $agentSlug = $voice['slug'];
        $content   = $voice['content'];
        $metadata  = $voice['metadata'];
        try {
            $id = DB::table('agent_messages')->insertGetId([
                'workspace_id'  => $wsId,
                'agent_slug'    => $agentSlug,
                'sender'        => $voice['sender'],
                'content'       => mb_substr($content, 0, 65535),
                'role'          => 'agent',
                'metadata_json' => empty($metadata) ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'read_at'       => null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            // 2026-06-15 — push proactive agent messages. The interactive
            // two-phase chat reply (routes/api.php) direct-inserts + pushes
            // itself, so it does NOT route through here → no double-push.
            // Every proactive/orchestrator message (weekly/daily/monthly
            // retrospectives, proposals, reminders, discovery runs, James SEO
            // replies) DOES go through postAsAgent and previously pushed
            // NOTHING — that is exactly why Sarah's self-initiated messages
            // never produced a notification. Push to each workspace member's
            // devices (dispatchAgentReply no-ops when a user has no tokens).
            // Opt-out via metadata['push'] === false for bulk / low-value
            // notifications (e.g. per-link insert notices in a bulk run).
            if (($metadata['push'] ?? true) !== false) {
                $this->pushToWorkspace($wsId, $agentSlug, $content, (int) $id);
            }
            return (int) $id;
        } catch (\Throwable $e) {
            Log::warning('AgentMessageService::postAsAgent — insert failed', [
                'workspace_id' => $wsId, 'slug' => $agentSlug, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 2026-06-15 — Fan a proactive agent message out as a push notification
     * to every member of the workspace. Non-fatal: a missing push service,
     * empty roster, or per-device send error never breaks the chat write.
     * Mirrors the conversation_id contract used by the interactive reply
     * (conversation_id == agent slug == the per-agent thread).
     */
    private function pushToWorkspace(int $wsId, string $agentSlug, string $content, int $messageId): void
    {
        try {
            $userIds = DB::table('workspace_users')
                ->where('workspace_id', $wsId)
                ->pluck('user_id');
            if ($userIds->isEmpty()) {
                return;
            }
            $push = app(\App\Core\Notifications\PushDispatcherService::class);
            foreach ($userIds as $uid) {
                $push->dispatchAgentReply((int) $uid, $wsId, $agentSlug, $content, $agentSlug, $messageId);
            }
        } catch (\Throwable $e) {
            Log::warning('AgentMessageService::pushToWorkspace failed: ' . $e->getMessage(), [
                'workspace_id' => $wsId, 'slug' => $agentSlug,
            ]);
        }
    }

    /**
     * Convenience for posting from the user side. Mirrors the existing
     * agent-messages POST route's write — exposed here so service code
     * can record user inputs without duplicating insert logic.
     */
    public function postFromUser(int $wsId, string $agentSlug, ?int $userId, string $content): ?int
    {
        $agentSlug = strtolower(trim($agentSlug));
        try {
            $id = DB::table('agent_messages')->insertGetId([
                'workspace_id'  => $wsId,
                'agent_slug'    => $agentSlug,
                'sender'        => $userId ? ('user:' . $userId) : 'user',
                'content'       => mb_substr($content, 0, 65535),
                'role'          => 'user',
                'metadata_json' => null,
                'read_at'       => now(),  // user messages are read by default
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            return (int) $id;
        } catch (\Throwable $e) {
            Log::warning('AgentMessageService::postFromUser — insert failed', [
                'workspace_id' => $wsId, 'slug' => $agentSlug, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Mark all unread messages from a given agent as read for the
     * workspace. Called by the /messages/{slug}/read route — exposed
     * here so service-layer code can also clear without duplication.
     */
    public function markAgentThreadRead(int $wsId, string $agentSlug): int
    {
        $agentSlug = strtolower(trim($agentSlug));
        return DB::table('agent_messages')
            ->where('workspace_id', $wsId)
            ->where('agent_slug', $agentSlug)
            ->where('role', 'agent')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
