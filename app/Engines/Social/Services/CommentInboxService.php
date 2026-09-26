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
            'fields' => 'id,message,permalink_url,created_time,comments.filter(stream).limit(50).order(reverse_chronological){id,message,from{id,name},created_time,parent{id}}',
            'limit' => 15, 'access_token' => $token,
        ]);   // THREADS-1 (Owner 2026-09-26: "i posted another comment. nothing sarah" — it was a reply inside a thread,
              // and Graph returns only top-level comments unless filter(stream) is asked for)
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
        $drafted = [];
        foreach ($new as $id) { if ($this->triage($id)) $drafted[] = $id; }
        $this->announce($drafted);   // ANNOUNCE-1
        return ['account_id' => (int) $acct->id, 'ok' => true, 'new' => count($new), 'awaiting_approval' => count($drafted)];
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

    /**
     * ANNOUNCE-1 (Owner 2026-09-26: "no notification or chat from Sarah" / "Sarah is the point of contact, all things go
     * through her, LLM first before manual laravel"): drafted replies are TOLD to the Owner by Sarah — a message in her chat
     * written by her (runtime LLM, from the facts only; the fixed text is the fallback), which also pushes to the phone via
     * postAsAgent, plus a bell notification. One message per comment, or one summary when several arrive together.
     */
    public function announce(array $commentIds): void
    {
        $rows = $commentIds ? DB::table('social_comments')->whereIn('id', $commentIds)->where('status', 'awaiting_approval')->orderBy('id')->get() : collect();
        if ($rows->isEmpty()) return;
        foreach ($rows->groupBy('workspace_id') as $wsId => $group) {
            try {
                $complaints = $group->where('needs_owner', 1)->count();
                if ($group->count() === 1) {
                    $c = $group->first();
                    $who = $c->author_name ? explode(' ', trim($c->author_name))[0] : 'Someone';
                    $px = trim((string) $c->post_excerpt);
                    $post = $px !== '' ? ' on your post "' . mb_substr($px, 0, 60) . (mb_strlen($px) > 60 ? '…' : '') . '"' : '';
                    $fallback = ($c->needs_owner ? "This one needs you: a complaint from {$who}{$post}" : "New comment from {$who}{$post}")
                        . ":\n\n> " . mb_substr(trim((string) $c->message), 0, 280) . "\n\nI've drafted a reply — it stays unposted until you approve it here or in Social › Comments.";
                    $title = $c->needs_owner ? 'A complaint on your Page' : ((($c->intent ?? '') === 'hot') ? 'A buyer on your Page' : 'New comment on your Page');
                    $body = "{$who}: " . mb_substr(trim((string) $c->message), 0, 120);
                } else {
                    $n = $group->count();
                    $fallback = "{$n} new comments on your Page" . ($complaints ? " — {$complaints} " . ($complaints === 1 ? 'is a complaint' : 'are complaints') . ' that need you' : '')
                        . ". I've drafted a reply to each; nothing is posted until you approve. They're waiting here and in Social › Comments.";
                    $title = "{$n} new comments on your Page";
                    $body = 'Replies drafted and waiting for your approval.';
                }
                $msg = $this->sarahWords((int) $wsId, $group, $fallback);
                app(\App\Core\Agents\AgentMessageService::class)->postAsAgent((int) $wsId, 'sarah', $msg, [
                    'kind' => 'comment_reply_waiting', 'comment_ids' => $group->pluck('id')->values()->all(), 'action_link' => ['view' => 'social', 'tail' => 'comments'],
                ]);
                $owner = DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id');
                if ($owner) {
                    app(\App\Core\Notifications\NotificationService::class)->dispatch(
                        type: \App\Core\Notifications\NotificationTypes::AGENT_TASK_REQUIRES_APPROVAL, userId: (int) $owner, title: $title,
                        workspaceId: (int) $wsId, body: $body, severity: $complaints ? 'warning' : 'info', actionUrl: '/app/social'
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('[ANNOUNCE-1] could not tell the owner', ['ws' => $wsId, 'error' => $e->getMessage()]);
            }
        }
    }

    /** LLM first: Sarah writes the announcement from the facts only; never claims anything went out; the template is the fallback. */
    private function sarahWords(int $wsId, $group, string $fallback): string
    {
        try {
            $runtime = app(\App\Connectors\RuntimeClient::class);
            if (! $runtime->isConfigured()) return $fallback;
            $facts = $group->sortByDesc(fn ($c) => ($c->intent ?? '') === 'hot' ? 2 : (($c->intent ?? '') === 'warm' ? 1 : 0))->map(fn ($c) => ['from' => $c->author_name, 'comment' => mb_substr((string) $c->message, 0, 300), 'post' => mb_substr((string) $c->post_excerpt, 0, 80),
                'kind' => $c->category, 'buying_intent' => $c->intent ?? 'none', 'details_they_gave' => json_decode((string) ($c->signals_json ?? ''), true) ?: null,
                'added_to_crm_as_lead' => ! empty($c->lead_id), 'needs_owner' => (bool) $c->needs_owner, 'your_draft_reply' => $c->draft_reply])->values()->all();
            $sys = "You are Sarah, the business owner's digital marketing manager, writing a short chat message to the owner. "
                . "From the FACTS only, tell them about the new comment(s) on their Facebook Page: who, what they said (quote a short comment exactly), which post, "
                . "and that you drafted a reply which is NOT live until they approve it (the approval buttons are right below your message; do not offer to edit it in chat). "
                . "Call out any complaint first. A HOT buying signal comes next: say it plainly (what they want, with the details they gave) and that you added them to the CRM as a lead when added_to_crm_as_lead is true; "
                . "mention that the reply includes a link to their contact section when intent is hot or warm. 2-4 sentences, warm and direct, no headings, no emojis, no invented facts. "
                . 'Return ONLY JSON {"message":"..."}.';
            $r = $runtime->chatJson($sys, 'FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['task' => 'comment_announce', 'workspace_id' => (string) $wsId], 300);
            $m = trim((string) (($r['success'] ?? false) ? ($r['parsed']['message'] ?? '') : ''));
            if ($m === '' || preg_match('/\b(has been|was|is now|already)\s+(posted|published|replied)\b/i', $m)) return $fallback;   // never let the words claim it went out
            return mb_substr($m, 0, 1200);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** Sarah reads the comment and drafts a reply. Returns true when an approval was created. */
    public function triage(int $commentId, bool $requestApproval = true): bool
    {
        $c = DB::table('social_comments')->where('id', $commentId)->first();
        if (! $c || $c->status !== 'new') return false;
        $biz = $c->business_id ? DB::table('businesses')->where('id', $c->business_id)->first() : null;
        if (! $biz) $biz = DB::table('businesses')->where('workspace_id', $c->workspace_id)->orderByDesc('is_default')->orderBy('id')->first();
        $facts = array_filter([
            'business' => $biz->name ?? null, 'industry' => $biz->industry ?? null, 'location' => $biz->location ?? null,
            'services' => $biz->services_json ?? null, 'tone' => $biz->tone ?? null, 'phone' => $biz->phone ?? null, 'email' => $biz->email ?? null,
        ]);
        // SOCIAL-LEADS-1 (RFC-0016 P1): a tracked link to the business's contact section, made BEFORE the model is asked so
        // the model never invents a URL; it is only kept when the comment shows buying interest.
        $link = null; $linkCreated = false;
        try {
            if ($c->link_code) { $l = app(TrackedLinkService::class)->find((string) $c->link_code); if ($l) { $site = DB::table('websites')->where('id', $l->website_id)->first(); $base = $site ? app(TrackedLinkService::class)->baseUrl($site) : null; if ($base) $link = ['code' => $l->code, 'url' => $base . '/go/' . $l->code]; } }
            if (! $link) { $link = app(TrackedLinkService::class)->create((int) $c->workspace_id, $c->business_id ? (int) $c->business_id : null, 'facebook_comment', (int) $c->id, 'comment-' . $c->id); $linkCreated = (bool) $link; }
        } catch (\Throwable $e) { $link = null; }
        $system = "You are Sarah, the digital marketing manager for this business, handling comments on its Facebook Page.\n"
            . "Read the comment and return ONLY JSON: {\"category\":\"question|enquiry|praise|complaint|spam|other\",\"sentiment\":\"positive|neutral|negative\","
            . "\"intent\":\"hot|warm|none\",\"signals\":{\"event\":null,\"date\":null,\"guests\":null,\"location\":null,\"budget\":null},"
            . "\"should_reply\":true|false,\"needs_owner\":true|false,\"reply\":\"...\",\"note\":\"one line for the owner\"}.\n"
            . "BUYING INTENT: hot = asks about price, booking, availability, a date, a guest count, or how to hire/book; warm = asks about services, "
            . "area served, menus or dietary options; none = praise, chat, spam, complaint. signals = only what the comment itself states (null otherwise).\n"
            . ($link ? "CONTACT LINK: {$link['url']} — for intent hot or warm, end the reply with this exact link and a short invitation to use it "
                . "(e.g. 'Send us your details here: <link>'). Never use any other URL. For intent none, include no link.\n" : "")
            . "Rules: reply as the business, warm and brief (1-3 sentences), in the business's tone, in the commenter's language. "
            . "Never invent prices, availability, dates, offers or facts that are not in BUSINESS FACTS — for a price or booking question invite them to message the Page or use the contact given. "
            . "Do not reply to spam (should_reply false). A complaint or anything sensitive: needs_owner true, draft a calm, apologetic reply that moves the conversation to a private message. "
            . "No hashtags. At most one emoji. Address the commenter by first name when it is known. "
            // NEUTRAL-1 (Owner 2026-09-26: "she assumed that Chef Red cooked for me.. my comment was about the food. she has to
            // neutralize the comments to something more generic")
            . "Respond ONLY to what the comment and the post actually say. Never assume the commenter is or was a client, has eaten the food, "
            . "booked, attended, or knows the business or the owner — no 'again', no 'glad you enjoyed our service', no 'see you next time'. "
            . "Keep it generic and gracious; a light invitation to follow the Page or send a message is fine. "
            . "The note states what the comment is and what, if anything, the owner should do — never guess who the commenter is, their relationship to anyone, or anything about them beyond the comment itself.";
        $user = "BUSINESS FACTS: " . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "POST: " . mb_substr((string) $c->post_excerpt, 0, 400) . "\n"
            . "COMMENTER: " . ($c->author_name ?: 'someone') . "\n"
            . "COMMENT: " . mb_substr((string) $c->message, 0, 1500)
            . ($this->instruction !== '' ? "\nOWNER'S INSTRUCTION FOR THE REPLY: " . mb_substr($this->instruction, 0, 400) : '');
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
        // SOCIAL-LEADS-1: intent, the signals the comment states, and the link only where there is buying interest
        $intent = in_array($p['intent'] ?? '', ['hot', 'warm', 'none'], true) ? $p['intent'] : 'none';
        if (in_array($cat, ['spam', 'complaint'], true)) $intent = 'none';
        $signals = array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 80) : null, (array) ($p['signals'] ?? [])), fn ($v) => $v !== null && $v !== '' && strtolower($v) !== 'null');
        $useLink = $link && in_array($intent, ['hot', 'warm'], true);
        if ($link) {
            $reply = trim(preg_replace('#https?://\S+#', '', $reply) ?? $reply);              // only OUR link, never a model-made URL
            if ($useLink && $reply !== '') $reply = rtrim($reply) . (preg_match('/[:\-—]\s*$/u', $reply) ? ' ' : "\n") . $link['url'];
            if (! $useLink && $linkCreated) DB::table('tracked_links')->where('code', $link['code'])->delete();
        }
        DB::table('social_comments')->where('id', $commentId)->update([
            'category' => $cat, 'sentiment' => in_array($p['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true) ? $p['sentiment'] : null,
            'intent' => $intent, 'signals_json' => $signals ? json_encode($signals, JSON_UNESCAPED_UNICODE) : null, 'link_code' => $useLink ? $link['code'] : null,
            'needs_owner' => (bool) ($p['needs_owner'] ?? false) || $cat === 'complaint', 'draft_reply' => $reply !== '' ? mb_substr($reply, 0, 1500) : null,
            'triage_note' => mb_substr((string) ($p['note'] ?? ''), 0, 300) ?: null, 'status' => $should ? 'new' : 'no_reply', 'updated_at' => now(),
        ]);
        // Owner 2026-09-26: a hot comment is a CRM lead at once — the Facebook name is enough until they sign up on the website.
        if ($intent === 'hot') {
            try { $leadId = $this->upsertSocialLead(DB::table('social_comments')->where('id', $commentId)->first(), $signals); if ($leadId && $useLink) DB::table('tracked_links')->where('code', $link['code'])->update(['lead_id' => $leadId]); }
            catch (\Throwable $e) { Log::warning('[SOCIAL-LEADS-1] lead not created', ['comment' => $commentId, 'error' => $e->getMessage()]); }
        }
        if (! $should) return false;
        return $requestApproval ? $this->requestApproval($commentId) : true;
    }

    /**
     * SOCIAL-LEADS-1: the CRM lead a hot comment becomes (source facebook_comment). One lead per Facebook person per
     * workspace: a second hot comment from them is appended to the same lead. The website form later completes it.
     */
    public function upsertSocialLead(object $c, array $signals): ?int
    {
        $entry = ['comment_row_id' => (int) $c->id, 'comment_id' => $c->external_comment_id, 'message' => mb_substr((string) $c->message, 0, 500),
            'post' => mb_substr((string) $c->post_excerpt, 0, 120), 'at' => (string) $c->commented_at, 'signals' => $signals];
        $existing = null;
        if ($c->author_external_id) {
            $existing = DB::table('leads')->where('workspace_id', $c->workspace_id)->whereIn('source', ['facebook_comment', 'facebook_messenger'])->whereNull('deleted_at')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.fb_profile_id')) = ?", [(string) $c->author_external_id])->first();
        }
        if ($existing) {
            $m = json_decode((string) $existing->metadata_json, true) ?: [];
            $m['comments'] = array_values(array_merge(array_filter((array) ($m['comments'] ?? []), fn ($x) => (int) ($x['comment_row_id'] ?? 0) !== (int) $c->id), [$entry]));   // a redraft replaces, never duplicates
            $m['signals'] = array_merge((array) ($m['signals'] ?? []), $signals);
            DB::table('leads')->where('id', $existing->id)->update(['metadata_json' => json_encode($m, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            $leadId = (int) $existing->id;
        } else {
            $lead = app(\App\Engines\CRM\Services\CrmService::class)->createLead((int) $c->workspace_id, [
                'name' => $c->author_name ?: 'Facebook commenter', 'source' => 'facebook_comment',
                'metadata' => ['channel' => 'facebook_comment', 'fb_profile_id' => $c->author_external_id, 'fb_name' => $c->author_name,
                    'business_id' => $c->business_id, 'signals' => $signals, 'comments' => [$entry], 'stage_note' => 'new - social'],
            ]);
            $leadId = (int) $lead->id;
        }
        DB::table('social_comments')->where('id', $c->id)->update(['lead_id' => $leadId, 'updated_at' => now()]);
        return $leadId;
    }

    /**
     * NEUTRAL-1: redraft a reply that is still waiting for approval (new rules, or the Owner asked for a change). Updates the
     * stored draft AND the pending task's payload, so the approval card and the posted text are the new reply.
     */
    public function redraft(int $commentId, string $instruction = ''): ?string
    {
        $c = DB::table('social_comments')->where('id', $commentId)->first();
        if (! $c || $c->status !== 'awaiting_approval' || ! $c->task_id) return null;
        $pending = DB::table('tasks')->where('id', $c->task_id)->whereIn('status', ['pending', 'queued', 'awaiting_approval'])->where(function ($q) { $q->whereNull('approval_status')->orWhere('approval_status', 'pending'); })->first(['id', 'payload_json']);
        if (! $pending) return null;
        DB::table('social_comments')->where('id', $commentId)->update(['status' => 'new', 'updated_at' => now()]);
        $keepTask = $c->task_id;
        DB::table('social_comments')->where('id', $commentId)->update(['task_id' => null]);
        $this->instruction = $instruction;
        $ok = $this->triage($commentId, false);
        $this->instruction = '';
        $fresh = DB::table('social_comments')->where('id', $commentId)->first();
        $reply = $fresh->draft_reply ?? null;
        if (! $ok || ! $reply) { DB::table('social_comments')->where('id', $commentId)->update(['task_id' => $keepTask, 'status' => 'awaiting_approval', 'draft_reply' => $c->draft_reply]); return null; }
        $p = json_decode((string) $pending->payload_json, true) ?: [];
        $p['reply'] = $reply;
        DB::table('tasks')->where('id', $keepTask)->update(['payload_json' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        DB::table('social_comments')->where('id', $commentId)->update(['task_id' => $keepTask, 'status' => 'awaiting_approval', 'updated_at' => now()]);
        return $reply;
    }

    private string $instruction = '';

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
        if ((! $resp->successful() || empty($j['id'])) && ! empty($c->parent_comment_id)) {
            // THREADS-1: a reply to a reply goes under the thread's parent comment (one nesting level on Facebook)
            $resp = Http::asForm()->timeout(25)->post(self::GRAPH . "/{$c->parent_comment_id}/comments", ['message' => $text, 'access_token' => $token]);
            $j = $resp->json() ?: [];
        }
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
