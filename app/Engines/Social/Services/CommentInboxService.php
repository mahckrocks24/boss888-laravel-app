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

        $this->ensureSubscribed($acct, $page, $token);   // WEBHOOK-1: after a reconnect, the Page starts pushing comments to us

        $new = [];
        foreach ((array) ($j['data'] ?? []) as $post) {
            foreach ((array) ($post['comments']['data'] ?? []) as $c) {
                $id = $this->ingest($acct, [
                    'comment_id' => $c['id'] ?? null, 'post_id' => $post['id'] ?? null, 'parent_id' => $c['parent']['id'] ?? null,
                    'from_id' => $c['from']['id'] ?? null, 'from_name' => $c['from']['name'] ?? null, 'message' => $c['message'] ?? '',
                    'created_time' => $c['created_time'] ?? null, 'post_message' => $post['message'] ?? '', 'permalink' => $post['permalink_url'] ?? null,
                ]);
                if ($id) $new[] = $id;
            }
        }
        $drafted = 0;
        foreach ($new as $id) { if ($this->triage($id)) $drafted++; }
        return ['account_id' => (int) $acct->id, 'ok' => true, 'new' => count($new), 'awaiting_approval' => $drafted];
    }

    /**
     * WEBHOOK-1: the ONE way a comment enters the inbox — from the 10-minute check or from Facebook's push. Stored once
     * (Facebook's comment id is unique); the Page's own comments and anything older than the look-back are ignored.
     * Returns the new row id, or null when there is nothing new. No tokens are spent here.
     */
    public function ingest(object $acct, array $c): ?int
    {
        $page = (string) ($acct->linked_page_id ?: $acct->account_id);
        $ext = (string) ($c['comment_id'] ?? '');
        if ($ext === '' || (string) ($c['from_id'] ?? '') === $page) return null;
        $t = $c['created_time'] ?? null;
        $at = $t === null || $t === '' ? now() : (is_numeric($t) ? \Illuminate\Support\Carbon::createFromTimestampUTC((int) $t) : \Illuminate\Support\Carbon::parse($t)->utc());
        if ($at->lt(now()->subDays(self::LOOKBACK_DAYS))) return null;
        if (DB::table('social_comments')->where('external_comment_id', $ext)->exists()) return null;
        try {
            return (int) DB::table('social_comments')->insertGetId([
                'workspace_id' => (int) $acct->workspace_id, 'business_id' => $acct->business_id ?? null, 'social_account_id' => (int) $acct->id,
                'platform' => 'facebook', 'external_comment_id' => $ext, 'external_post_id' => (string) ($c['post_id'] ?? ''),
                'parent_comment_id' => $c['parent_id'] ?? null, 'author_name' => mb_substr((string) ($c['from_name'] ?? ''), 0, 190) ?: null,
                'author_external_id' => $c['from_id'] ?? null, 'message' => (string) ($c['message'] ?? ''),
                'post_excerpt' => mb_substr((string) ($c['post_message'] ?? ''), 0, 400) ?: null, 'post_permalink' => $c['permalink'] ?? null,
                'commented_at' => $at, 'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return null;   // the other path stored it a moment earlier (unique key) — nothing to do
        }
    }

    /**
     * WEBHOOK-1: Facebook's push. Every 'feed' change that ADDS a comment on a Page we hold is ingested; the post's text
     * and link are fetched (a free Graph read) so Sarah sees what the comment is about. Returns the new row ids; the
     * caller runs triage() after the HTTP response so Facebook never waits for the reasoning service.
     */
    public function ingestWebhook(array $payload): array
    {
        if (($payload['object'] ?? '') !== 'page') return [];
        $ids = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            if ($pageId === '') continue;
            $acct = DB::table('social_accounts')->where('platform', 'facebook')->where('status', 'connected')
                ->where(function ($q) use ($pageId) { $q->where('linked_page_id', $pageId)->orWhere('account_id', $pageId); })->first();
            if (! $acct) continue;
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                $v = (array) ($ch['value'] ?? []);
                if (($ch['field'] ?? '') !== 'feed' || ($v['item'] ?? '') !== 'comment' || ($v['verb'] ?? '') !== 'add') continue;
                $postId = (string) ($v['post_id'] ?? '');
                $post = ['message' => '', 'permalink_url' => null];
                if ($postId !== '') {
                    try {
                        $creds = ConnectionHealth::readCredentials($acct);
                        $tok = (string) ($creds['page_access_token'] ?? $creds['access_token'] ?? '');
                        if ($tok !== '') $post = array_merge($post, (array) (Http::timeout(10)->get(self::GRAPH . "/{$postId}", ['fields' => 'message,permalink_url', 'access_token' => $tok])->json() ?: []));
                    } catch (\Throwable $e) { /* the comment is still stored without its post text */ }
                }
                $id = $this->ingest($acct, [
                    'comment_id' => $v['comment_id'] ?? null, 'post_id' => $postId, 'parent_id' => ($v['parent_id'] ?? null) !== $postId ? ($v['parent_id'] ?? null) : null,
                    'from_id' => $v['from']['id'] ?? null, 'from_name' => $v['from']['name'] ?? null, 'message' => $v['message'] ?? '',
                    'created_time' => $v['created_time'] ?? null, 'post_message' => $post['message'] ?? '', 'permalink' => $post['permalink_url'] ?? null,
                ]);
                if ($id) $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * WEBHOOK-1: subscribe the Page to our app's 'feed' webhook (needs pages_manage_metadata on the token). Checked at
     * most every 6 hours per account; a refusal is logged and the 10-minute check keeps working without it.
     */
    public function ensureSubscribed(object $acct, string $page, string $token): ?bool
    {
        $key = 'comments_webhook_sub:' . $acct->id;
        if (\Illuminate\Support\Facades\Cache::get($key) === 'ok') return true;
        if (\Illuminate\Support\Facades\Cache::get($key) === 'refused') return false;
        try {
            $r = Http::asForm()->timeout(15)->post(self::GRAPH . "/{$page}/subscribed_apps", ['subscribed_fields' => 'feed', 'access_token' => $token]);
            $j = $r->json() ?: [];
            if (! empty($j['success'])) { \Illuminate\Support\Facades\Cache::put($key, 'ok', now()->addHours(6)); Log::info('[WEBHOOK-1] page subscribed to feed', ['account' => $acct->id]); return true; }
            \Illuminate\Support\Facades\Cache::put($key, 'refused', now()->addMinutes(30));   // retried soon: a reconnect with the permission fixes it
            Log::info('[WEBHOOK-1] page subscription refused', ['account' => $acct->id, 'error' => mb_substr((string) ($j['error']['message'] ?? 'HTTP ' . $r->status()), 0, 200)]);
            return false;
        } catch (\Throwable $e) { return null; }
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
