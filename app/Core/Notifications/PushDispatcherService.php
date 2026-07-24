<?php

namespace App\Core\Notifications;

use App\Core\Agents\AgentDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PushDispatcherService — v1.4.4
 *
 * Fires Expo push notifications to a user's registered devices when an
 * agent (or the system) posts a message that should surface as a phone
 * notification. Talks to Expo's push service at
 *   https://exp.host/--/api/v2/push/send
 *
 * Why Expo and not FCM/APNS directly?
 *   - The mobile companion is an Expo app — the token format Expo issues
 *     (ExponentPushToken[...]) is bound to Expo's project credentials
 *     (FCM v1 service account, APNS key) that Shukran uploaded via
 *     `eas credentials`. Going direct to FCM means re-implementing
 *     token routing on our side. Expo handles it.
 *   - Expo also batches up to 100 tokens per request, retries 5xx,
 *     and gives us delivery receipts. Free up to 600 req/sec which
 *     is comfortably above anything Sarah is going to produce.
 *
 * Body copy MUST be humanised — we re-use the same slug-scrubber the
 * mobile + web SPA already use (mirrored here as humanizeForPush()).
 * No internal task slugs ever surface in a phone banner.
 *
 * Used by AgentDispatchService after every agent reply insertion.
 */
class PushDispatcherService
{
    private const EXPO_ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    private const PREVIEW_MAX   = 120;

    /**
     * Dispatch a push notification for a new agent message.
     *
     * @param int $userId       The user who owns the conversation (target).
     * @param int $workspaceId  Workspace context.
     * @param string $agentSlug Agent who sent the message.
     * @param string $rawContent The raw message content (will be humanised + truncated).
     * @param string $conversationId The Laravel conversation_id (string id).
     * @param int|null $messageId The conversation_message row id, if known.
     */
    public function dispatchAgentReply(
        int $userId,
        int $workspaceId,
        string $agentSlug,
        string $rawContent,
        string $conversationId,
        ?int $messageId = null,
    ): void {
        try {
            // ── b19 (2026-07-24) — DO NOT PUSH TO A LOGGED-OUT USER ──
            //
            // Reported by a customer: logged out of the companion app, kept
            // receiving notifications. Confirmed — this lookup keyed on
            // user_id alone, and logging out never removed the device row:
            //   * AuthService::logout() revoked the SESSION only and never
            //     touched device_tokens;
            //   * tokens were pruned solely on Expo 'DeviceNotRegistered',
            //     which means app UNINSTALLED — logging out leaves the Expo
            //     token perfectly valid, so it never fired.
            // Net effect: an Expo token registered once kept receiving agent
            // replies forever, on a phone nobody was signed in on.
            //
            // Sessions rotate on refresh and a logged-in user always holds
            // exactly one unrevoked, unexpired row, so "no active session"
            // is a sound proxy for "signed out everywhere".
            $hasActiveSession = DB::table('sessions')
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if (! $hasActiveSession) {
                Log::info('[PushDispatcher] suppressed — user has no active session', [
                    'user_id' => $userId, 'workspace_id' => $workspaceId,
                ]);
                return;
            }

            // ── Resolve the target devices ──
            //
            // b20 — PER-DEVICE, not per-user. A registration is delivered to
            // only while the sign-in that created it is still live. Signing out
            // on one phone silences that phone while the user's other signed-in
            // devices keep working — the coarse user-level check above cannot
            // make that distinction on its own.
            //
            // session_id IS NULL means the app registered with a pre-b20 access
            // token that carried no `sid` claim. Those are still governed by the
            // user-level check above (so a signed-out user can never be reached
            // either way) and are swept by sarah:prune-device-tokens.
            $tokens = DB::table('device_tokens')
                ->leftJoin('sessions', 'sessions.id', '=', 'device_tokens.session_id')
                ->where('device_tokens.user_id', $userId)
                ->whereNotNull('device_tokens.expo_push_token')
                ->where(function ($q) {
                    $q->whereNull('device_tokens.session_id')          // legacy, unbindable
                      ->orWhere(function ($q2) {                       // bound + still signed in
                          $q2->whereNull('sessions.revoked_at')
                             ->where('sessions.expires_at', '>', now());
                      });
                })
                ->pluck('device_tokens.expo_push_token')
                ->all();

            if (empty($tokens)) {
                Log::info('[PushDispatcher] no reachable devices — all registrations signed out', [
                    'user_id' => $userId, 'workspace_id' => $workspaceId,
                ]);
                return;
            }

            // ── Resolve agent display name ──
            $agentName = $this->resolveAgentName($agentSlug);

            // ── Build the notification body ──
            $body = $this->humanizeForPush($rawContent);

            $messages = array_map(function ($token) use ($agentName, $body, $conversationId, $agentSlug, $messageId, $workspaceId) {
                return [
                    'to'         => $token,
                    'title'      => $agentName,
                    'body'       => $body,
                    'sound'      => 'default',
                    'channelId'  => 'chat',
                    'priority'   => 'high',
                    'badge'      => 1,
                    'data'       => array_filter([
                        'conversation_id' => $conversationId,
                        'message_id'      => $messageId !== null ? (string) $messageId : null,
                        'agent_slug'      => $agentSlug,
                        'workspace_id'    => (string) $workspaceId,
                    ], fn ($v) => $v !== null),
                ];
            }, $tokens);

            $resp = Http::timeout(8)
                ->acceptJson()
                ->withHeaders([
                    'Accept-Encoding' => 'gzip, deflate',
                    'Content-Type'    => 'application/json',
                ])
                ->post(self::EXPO_ENDPOINT, $messages);

            if (! $resp->successful()) {
                Log::warning('[PushDispatcher] Expo push returned non-2xx', [
                    'status' => $resp->status(),
                    'body'   => substr($resp->body(), 0, 400),
                ]);
                return;
            }

            // Inspect per-token receipts for DeviceNotRegistered errors,
            // which mean we should prune the token from device_tokens.
            $payload = $resp->json('data', []);
            if (is_array($payload)) {
                foreach ($payload as $i => $receipt) {
                    if (($receipt['status'] ?? null) === 'error') {
                        $details = $receipt['details'] ?? [];
                        $err     = $details['error'] ?? null;
                        if ($err === 'DeviceNotRegistered' && isset($tokens[$i])) {
                            DB::table('device_tokens')
                                ->where('expo_push_token', $tokens[$i])
                                ->delete();
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Push failure must never block message persistence — log and move on.
            Log::warning('[PushDispatcher] dispatchAgentReply failed: ' . $e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function resolveAgentName(string $slug): string
    {
        try {
            // W6: a push must never read as a removed agent messaging you.
            if (\App\Core\LaunchScope\AgentDirectory::isRemoved($slug)) return 'LevelUp Growth';
            $row = DB::table('agents')->where('slug', $slug)->first(['name']);
            if ($row && $row->name) return $row->name;
        } catch (\Throwable $e) { /* fall through */ }
        return ucfirst($slug);
    }

    /**
     * Strip internal slugs / underscore-shaped tokens from a chunk of
     * agent-generated text before it lands in a notification banner.
     * Mirrors the same map used in src/utils/humanize.ts (mobile) and
     * public/app/js/core.js LU_humanize (web SPA).
     */
    private function humanizeForPush(string $text): string
    {
        $text = trim($text);
        if ($text === '') return 'Sent a message.';

        $map = [
            'write_article'          => 'drafting an article',
            'improve_draft'          => 'polishing the draft',
            'optimize_article'       => 'optimising the article',
            'generate_article'       => 'drafting an article',
            'create_campaign'        => 'building a campaign',
            'schedule_campaign'      => 'scheduling that campaign',
            'social_post_generation' => 'creating social posts',
            'social_post'            => 'creating a social post',
            'seo_content_generation' => 'writing SEO content',
            'email_generation'       => 'drafting an email',
            'competitor_analysis'    => 'analysing competitors',
            'serp_analysis'          => 'researching search rankings',
            'keyword_research'       => 'researching keywords',
            'builder_generate'       => 'designing the page',
            'image_generation'       => 'generating an image',
            'scene_planning'         => 'planning the scenes',
            'blueprint_generate'     => 'mapping the strategy',
            'waiting_approval'       => 'waiting for your sign-off',
            'waiting_input'          => 'waiting for your input',
            'queued'                 => 'queued up',
            'failed'                 => 'hit a problem',
            'cancelled'              => 'cancelled',
            'revision_requested'     => 'sent back for revisions',
        ];

        // 1. Replace known slugs
        foreach ($map as $slug => $phrase) {
            $text = preg_replace('/\b' . preg_quote($slug, '/') . '\b/', $phrase, $text);
        }
        // 2. Generic catch-all — any 2+ segment snake_case → spaces
        $text = preg_replace_callback('/\b([a-z]{2,}(?:_[a-z]{2,})+)\b/', function ($m) {
            return str_replace('_', ' ', $m[1]);
        }, $text);

        // 3. Strip markdown formatting that doesn't render in a banner
        $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text); // bold
        $text = preg_replace('/\*(.+?)\*/', '$1', $text);     // italic
        $text = preg_replace('/`(.+?)`/', '$1', $text);       // inline code
        $text = preg_replace('/^#+\s+/m', '', $text);         // headings
        $text = preg_replace('/!?\[(.*?)\]\([^)]+\)/', '$1', $text); // links / images

        // 4. Trim + collapse whitespace
        $text = trim(preg_replace('/\s+/', ' ', $text));

        // 5. Truncate to a banner-friendly length
        if (mb_strlen($text) > self::PREVIEW_MAX) {
            $text = mb_substr($text, 0, self::PREVIEW_MAX - 1) . '…';
        }
        return $text;
    }
}
