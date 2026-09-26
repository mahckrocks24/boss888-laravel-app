<?php

namespace App\Engines\Social\Services;

use App\Core\Publisher\ConnectionHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * COMMENTS-1 (2026-09-26). Sarah watches the business's Facebook Page for comments, reads each one, drafts a reply in
 * the business's voice, and puts it in front of the Owner as an approval. NOTHING is posted to the Page until the Owner
 * approves the social_reply_comment task (capability approval_mode 'protected').
 *
 *   sync()    — every 10 minutes (social:comments-sync): new comments on the Page's recent posts are stored.
 *   triage()  — Sarah (runtime LLM) classifies the comment and drafts the reply; a reply-worthy one becomes an approval.
 *   reply()   — the approved task's handler: posts the approved text as a reply under the comment.
 *
 * Needs the Page token to carry pages_read_user_content (read) and pages_manage_engagement (reply). Without them the
 * sync records why and stops for that account; nothing is guessed.
 */
class CommentInboxService
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';
    private const LOOKBACK_DAYS = 14;
    public const CATEGORIES = ['question', 'enquiry', 'praise', 'complaint', 'spam', 'other'];

    /** @return array<int, array> one result per account */
    public function syncAll(?int $wsId = null): array
    {
        $q = DB::table('social_accounts')->where('platform', 'facebook')->where('status', 'connected');
        if ($wsId) $q->where('workspace_id', $wsId);
        $out = [];
        foreach ($q->get() as $acct) {
            try { $out[] = $this->syncAccount($acct); }
            catch (\Throwable $e) { $out[] = ['account_id' => (int) $acct->id, 'ok' => false, 'error' => $e->getMessage()]; Log::warning('[COMMENTS-1] sync failed', ['account' => $acct->id, 'error' => $e->getMessage()]); }
        }
        return $out;
    }

    public function syncAccount(object $acct): array
    {
        $creds = ConnectionHealth::readCredentials($acct);
        $token = (string) ($creds['page_access_token'] ?? $creds['access_token'] ?? '');
        $page  = (string) ($acct->linked_page_id ?: $acct->account_id);
        if ($token === '' || $page === '') return ['account_id' => (int) $acct->id, 'ok' => false, 'error' => 'no page token'];

        $resp = Http::timeout(25)->get(self::GRAPH . "/{$page}/published_posts", [
            'fields' => 'id,message,permalink_url,created_time,comments.limit(50).order(reverse_chronological){id,message,from{id,name},created_time,parent{id}}',
            'limit' => 15, 'access_token' => $token,
        ]);
        $j = $resp->json() ?: [];
        if (! $resp->successful() || isset($j['error'])) {
            $code = (int) ($j['error']['code'] ?? $resp->status());
            $msg  = (string) ($j['error']['message'] ?? 'HTTP ' . $resp->status());
            // #10 / #200 / #283 = the token lacks the comment permissions: the Owner reconnects the Page once.
            $perm = in_array($code, [10, 200, 283], true) || stripos($msg, 'permission') !== false;
            Log::info('[COMMENTS-1] page read refused', ['account' => $acct->id, 'code' => $code, 'message' => mb_substr($msg, 0, 200)]);
            return ['account_id' => (int) $acct->id, 'ok' => false, 'needs_reconnect' => $perm, 'error' => $msg];
        }

        $since = now()->subDays(self::LOOKBACK_DAYS);
        $new = [];
        foreach ((array) ($j['data'] ?? []) as $post) {
            foreach ((array) ($post['comments']['data'] ?? []) as $c) {
                if (($c['from']['id'] ?? null) === $page) continue;            // the Page's own replies
                $at = ! empty($c['created_time']) ? \Illuminate\Support\Carbon::parse($c['created_time'])->utc() : null;
                if ($at && $at->lt($since)) continue;
                $ext = (string) ($c['id'] ?? ''); if ($ext === '') continue;
                if (DB::table('social_comments')->where('external_comment_id', $ext)->exists()) continue;
                $id = DB::table('social_comments')->insertGetId([
                    'workspace_id' => (int) $acct->workspace_id, 'business_id' => $acct->business_id ?? null, 'social_account_id' => (int) $acct->id,
                    'platform' => 'facebook', 'external_comment_id' => $ext, 'external_post_id' => (string) ($post['id'] ?? ''),
                    'parent_comment_id' => $c['parent']['id'] ?? null, 'author_name' => mb_substr((string) ($c['from']['name'] ?? ''), 0, 190) ?: null,
                    'author_external_id' => $c['from']['id'] ?? null, 'message' => (string) ($c['message'] ?? ''),
                    'post_excerpt' => mb_substr((string) ($post['message'] ?? ''), 0, 400), 'post_permalink' => $post['permalink_url'] ?? null,
                    'commented_at' => $at, 'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $new[] = $id;
            }
        }
        $drafted = 0;
        foreach ($new as $id) { if ($this->triage($id)) $drafted++; }
        return ['account_id' => (int) $acct->id, 'ok' => true, 'new' => count($new), 'awaiting_approval' => $drafted];
    }

    /** Sarah reads the comment and drafts a reply. Returns true when an approval was created. */
    public function triage(int $commentId): bool
    {
        $c = DB::table('social_comments')->where('id', $commentId)->first();
        if (! $c || $c->status !== 'new') return false;
        $biz = $c->business_id ? DB::table('businesses')->where('id', $c->business_id)->first() : null;
        if (! $biz) $biz = DB::table('businesses')->where('workspace_id', $c->workspace_id)->orderByDesc('is_default')->orderBy('id')->first();
        $facts = array_filter([
            'business' => $biz->name ?? null, 'industry' => $biz->industry ?? null, 'location' => $biz->location ?? null,
            'services' => $biz->services_json ?? null, 'tone' => $biz->tone ?? null, 'phone' => $biz->phone ?? null, 'email' => $biz->email ?? null,
        ]);
        $system = "You are Sarah, the digital marketing manager for this business, handling comments on its Facebook Page.\n"
            . "Read the comment and return ONLY JSON: {\"category\":\"question|enquiry|praise|complaint|spam|other\",\"sentiment\":\"positive|neutral|negative\","
            . "\"should_reply\":true|false,\"needs_owner\":true|false,\"reply\":\"...\",\"note\":\"one line for the owner\"}.\n"
            . "Rules: reply as the business, warm and brief (1-3 sentences), in the business's tone, in the commenter's language. "
            . "Never invent prices, availability, dates, offers or facts that are not in BUSINESS FACTS — for a price or booking question invite them to message the Page or use the contact given. "
            . "Do not reply to spam (should_reply false). A complaint or anything sensitive: needs_owner true, draft a calm, apologetic reply that moves the conversation to a private message. "
            . "No hashtags. At most one emoji. Address the commenter by first name when it is known.";
        $user = "BUSINESS FACTS: " . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "POST: " . mb_substr((string) $c->post_excerpt, 0, 400) . "\n"
            . "COMMENTER: " . ($c->author_name ?: 'someone') . "\n"
            . "COMMENT: " . mb_substr((string) $c->message, 0, 1500);
        $runtime = app(\App\Connectors\RuntimeClient::class);
        $r = $runtime->isConfigured() ? $runtime->chatJson($system, $user, ['task' => 'comment_triage', 'workspace_id' => (string) $c->workspace_id], 500) : ['success' => false];
        $p = (($r['success'] ?? false) && is_array($r['parsed'] ?? null)) ? $r['parsed'] : null;
        if (! $p) {
            DB::table('social_comments')->where('id', $commentId)->update(['status' => 'failed', 'error' => 'triage: no answer from the reasoning service', 'updated_at' => now()]);
            return false;
        }
        $cat = in_array($p['category'] ?? '', self::CATEGORIES, true) ? $p['category'] : 'other';
        $reply = trim((string) ($p['reply'] ?? ''));
        $should = (bool) ($p['should_reply'] ?? false) && $reply !== '' && $cat !== 'spam';
        DB::table('social_comments')->where('id', $commentId)->update([
            'category' => $cat, 'sentiment' => in_array($p['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true) ? $p['sentiment'] : null,
            'needs_owner' => (bool) ($p['needs_owner'] ?? false) || $cat === 'complaint', 'draft_reply' => $reply !== '' ? mb_substr($reply, 0, 1500) : null,
            'triage_note' => mb_substr((string) ($p['note'] ?? ''), 0, 300) ?: null, 'status' => $should ? 'new' : 'no_reply', 'updated_at' => now(),
        ]);
        if (! $should) return false;
        return $this->requestApproval($commentId);
    }

    /** The drafted reply becomes a protected task: it waits in Review / Sarah's chat until the Owner approves it. */
    public function requestApproval(int $commentId): bool
    {
        $c = DB::table('social_comments')->where('id', $commentId)->first();
        if (! $c || ! $c->draft_reply || $c->task_id) return false;
        $who = $c->author_name ?: 'Someone';
        $task = app(\App\Core\TaskSystem\TaskService::class)->create((int) $c->workspace_id, [
            'engine' => 'social', 'action' => 'social_reply_comment', 'source' => 'agent', 'priority' => $c->needs_owner ? 'high' : 'normal',
            'assigned_agents' => ['marcus'], 'requires_approval' => true, 'business_id' => $c->business_id,
            'idempotency_key' => hash('sha256', 'comment-reply:' . $c->external_comment_id),
            'payload' => [
                'comment_row_id' => (int) $c->id, 'comment_id' => $c->external_comment_id, 'reply' => $c->draft_reply,
                'title' => "Reply to {$who}'s comment", 'author' => $who, 'comment' => mb_substr((string) $c->message, 0, 500),
                'category' => $c->category, 'needs_owner' => (bool) $c->needs_owner, 'post' => mb_substr((string) $c->post_excerpt, 0, 160),
                'description' => "Reply to {$who}'s comment",
            ],
        ]);
        DB::table('tasks')->where('id', $task->id)->update(['progress_message' => mb_substr("Reply to {$who}'s comment", 0, 480)]);
        DB::table('social_comments')->where('id', $commentId)->update(['task_id' => (int) $task->id, 'status' => 'awaiting_approval', 'updated_at' => now()]);
        return true;
    }

    /** Handler of the APPROVED social_reply_comment task: posts the approved text under the comment. */
    public function reply(int $wsId, array $params): array
    {
        $c = DB::table('social_comments')->where('id', (int) ($params['comment_row_id'] ?? 0))->where('workspace_id', $wsId)->first();
        if (! $c) return ['success' => false, 'error' => 'Comment not found in this workspace.', 'code' => 'NOT_FOUND', 'no_charge' => true];
        if ($c->status === 'replied') return ['success' => true, 'message' => 'Already replied.', 'data' => ['comment_id' => $c->external_comment_id, 'reply_id' => $c->reply_external_id]];
        $text = trim((string) ($params['reply'] ?? $c->draft_reply ?? ''));
        if ($text === '') return ['success' => false, 'error' => 'There is no reply text.', 'code' => 'INVALID_INPUT', 'no_charge' => true];
        $acct = DB::table('social_accounts')->where('id', $c->social_account_id)->first();
        $creds = $acct ? ConnectionHealth::readCredentials($acct) : [];
        $token = (string) ($creds['page_access_token'] ?? $creds['access_token'] ?? '');
        if ($token === '') {
            DB::table('social_comments')->where('id', $c->id)->update(['status' => 'failed', 'error' => 'The Facebook Page is not connected.', 'updated_at' => now()]);
            return ['success' => false, 'error' => 'The Facebook Page is not connected.', 'code' => 'NO_CONNECTION', 'no_charge' => true];
        }

        $resp = Http::asForm()->timeout(25)->post(self::GRAPH . "/{$c->external_comment_id}/comments", ['message' => $text, 'access_token' => $token]);
        $j = $resp->json() ?: [];
        if ($resp->successful() && ! empty($j['id'])) {
            DB::table('social_comments')->where('id', $c->id)->update(['status' => 'replied', 'reply_external_id' => (string) $j['id'], 'reply_sent' => $text,
                'replied_at' => now(), 'error' => null, 'updated_at' => now()]);
            return ['success' => true, 'message' => 'Reply posted.', 'data' => ['comment_id' => $c->external_comment_id, 'reply_id' => (string) $j['id']]];
        }
        $code = (int) ($j['error']['code'] ?? $resp->status()); $msg = (string) ($j['error']['message'] ?? 'HTTP ' . $resp->status());
        $words = in_array($code, [10, 200, 283], true) || stripos($msg, 'permission') !== false
            ? 'Facebook says this Page connection cannot reply to comments yet. Reconnect the Page in Settings › Social and allow comment management, then approve again.'
            : 'Facebook did not accept the reply: ' . mb_substr($msg, 0, 160);
        DB::table('social_comments')->where('id', $c->id)->update(['status' => 'failed', 'error' => mb_substr($msg, 0, 500), 'updated_at' => now()]);
        return ['success' => false, 'error' => $words, 'code' => 'PROVIDER_REFUSED', 'no_charge' => true];
    }
}
