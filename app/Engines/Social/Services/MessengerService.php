<?php

namespace App\Engines\Social\Services;

use App\Core\Publisher\ConnectionHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SOCIAL-LEADS-2 (RFC-0016 P2). Messenger for the business's Page, through Sarah and gated by the Owner:
 *   privateReply() — the one private message Facebook allows per comment (sent with the approved public reply);
 *   ingest()       — inbound Messenger messages from the webhook: stored, tied to the person's lead, contact details
 *                    extracted into the lead, a reply drafted and queued for approval, the Owner told by Sarah;
 *   send()         — the handler of an APPROVED social_send_message task (inside Facebook's 24-hour window only).
 * Needs pages_messaging on the Page token. Without it every call refuses honestly and nothing is guessed.
 */
class MessengerService
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';

    private function tokenFor(object $acct): string
    {
        $c = ConnectionHealth::readCredentials($acct);
        return (string) ($c['page_access_token'] ?? $c['access_token'] ?? '');
    }

    /** One private message in reply to a comment. Returns ['ok' => bool, 'psid' => …, 'error' => …]. */
    public function privateReply(object $comment, string $text): array
    {
        $acct = DB::table('social_accounts')->where('id', $comment->social_account_id)->first();
        $tok = $acct ? $this->tokenFor($acct) : '';
        if ($tok === '' || trim($text) === '') return ['ok' => false, 'error' => 'no connection or no text'];
        $page = (string) ($acct->linked_page_id ?: $acct->account_id);
        $r = Http::asForm()->timeout(20)->post(self::GRAPH . "/{$page}/messages", [
            'recipient' => json_encode(['comment_id' => $comment->external_comment_id]), 'message' => json_encode(['text' => mb_substr($text, 0, 1900)]), 'access_token' => $tok,
        ]);
        $j = $r->json() ?: [];
        if ($r->successful() && ! empty($j['message_id'])) {
            $psid = (string) ($j['recipient_id'] ?? '');
            DB::table('social_messages')->insert(['workspace_id' => $comment->workspace_id, 'social_account_id' => $acct->id, 'psid' => $psid ?: 'unknown', 'direction' => 'out',
                'mid' => (string) $j['message_id'], 'text' => $text, 'lead_id' => $comment->lead_id, 'status' => 'sent', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($comment->lead_id && $psid) $this->tagLead((int) $comment->lead_id, ['messenger_psid' => $psid]);
            return ['ok' => true, 'psid' => $psid];
        }
        return ['ok' => false, 'error' => (string) ($j['error']['message'] ?? 'HTTP ' . $r->status()), 'code' => (int) ($j['error']['code'] ?? 0)];
    }

    /** Webhook 'messaging' events. Returns the ids of new inbound rows to process after the HTTP response. */
    public function ingest(array $payload): array
    {
        $obj = (string) ($payload['object'] ?? '');   // SOCIAL-LEADS-5: Instagram direct messages ride the same path
        if (! in_array($obj, ['page', 'instagram'], true)) return [];
        $ids = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            $acct = $pageId === '' ? null : ($obj === 'instagram'
                ? DB::table('social_accounts')->where('platform', 'instagram')->where('status', 'connected')->where('account_id', $pageId)->first()
                : DB::table('social_accounts')->where('platform', 'facebook')->where('status', 'connected')
                    ->where(function ($q) use ($pageId) { $q->where('linked_page_id', $pageId)->orWhere('account_id', $pageId); })->first());
            if (! $acct) continue;
            foreach ((array) ($entry['messaging'] ?? []) as $ev) {
                $text = (string) ($ev['message']['text'] ?? '');
                $mid = (string) ($ev['message']['mid'] ?? '');
                $from = (string) ($ev['sender']['id'] ?? '');
                if ($text === '' || $mid === '' || $from === '' || $from === $pageId || ! empty($ev['message']['is_echo'])) continue;
                if (DB::table('social_messages')->where('mid', $mid)->exists()) continue;
                $ids[] = (int) DB::table('social_messages')->insertGetId(['workspace_id' => $acct->workspace_id, 'social_account_id' => $acct->id, 'psid' => $from, 'direction' => 'in',
                    'mid' => $mid, 'text' => mb_substr($text, 0, 4000), 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        return $ids;
    }

    /**
     * Sarah reads an inbound message: contact details into the lead (a lead is made when none exists — source
     * facebook_messenger), a reply drafted and queued for the Owner's approval. Returns true when a draft is waiting.
     */
    public function process(int $messageId): bool
    {
        $m = DB::table('social_messages')->where('id', $messageId)->first();
        if (! $m || $m->direction !== 'in' || $m->status !== 'received') return false;
        $acct = DB::table('social_accounts')->where('id', $m->social_account_id)->first();
        $leadId = $this->leadForPsid((int) $m->workspace_id, (string) $m->psid, $acct);
        $history = DB::table('social_messages')->where('workspace_id', $m->workspace_id)->where('psid', $m->psid)->where('id', '<', $m->id)->orderByDesc('id')->limit(6)->get(['direction', 'text'])->reverse()
            ->map(fn ($h) => ($h->direction === 'in' ? 'THEM: ' : 'US: ') . mb_substr((string) $h->text, 0, 300))->implode("\n");
        $biz = $acct && $acct->business_id ? DB::table('businesses')->where('id', $acct->business_id)->first() : DB::table('businesses')->where('workspace_id', $m->workspace_id)->orderByDesc('is_default')->first();
        $facts = array_filter(['business' => $biz->name ?? null, 'services' => $biz->services_json ?? null, 'location' => $biz->location ?? null, 'tone' => $biz->tone ?? null]);
        $sys = "You are Sarah, the business's digital marketing manager, answering its Facebook Messenger. Return ONLY JSON "
            . '{"extracted":{"name":null,"email":null,"phone":null,"date":null,"guests":null,"location":null,"budget":null},"intent":"hot|warm|none","reply":"...","note":"one line for the owner"}. '
            . "extracted = only what THEY wrote. The reply: warm, brief, as the business; ask for the one most useful missing detail (date, guest count, phone or email) to move towards a booking; "
            . "never invent prices, availability or facts not in BUSINESS FACTS; never assume history with them.";
        $user = 'BUSINESS FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE) . "\nEARLIER:\n" . ($history ?: '(none)') . "\nTHEIR NEW MESSAGE: " . mb_substr((string) $m->text, 0, 1500);
        $rt = app(\App\Connectors\RuntimeClient::class);
        $r = $rt->isConfigured() ? $rt->chatJson($sys, $user, ['task' => 'messenger_triage', 'workspace_id' => (string) $m->workspace_id], 500) : ['success' => false];
        $p = (($r['success'] ?? false) && is_array($r['parsed'] ?? null)) ? $r['parsed'] : null;
        if (! $p) { DB::table('social_messages')->where('id', $m->id)->update(['status' => 'refused', 'updated_at' => now()]); return false; }
        $x = array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 120) : null, (array) ($p['extracted'] ?? [])), fn ($v) => $v !== null && $v !== '' && strtolower($v) !== 'null');
        if ($leadId) $this->enrichLead($leadId, $x);
        $reply = trim((string) ($p['reply'] ?? ''));
        DB::table('social_messages')->where('id', $m->id)->update(['lead_id' => $leadId, 'extracted_json' => $x ? json_encode($x, JSON_UNESCAPED_UNICODE) : null, 'status' => 'processed', 'updated_at' => now()]);
        if ($reply === '') return false;
        $draftId = (int) DB::table('social_messages')->insertGetId(['workspace_id' => $m->workspace_id, 'social_account_id' => $m->social_account_id, 'psid' => $m->psid, 'direction' => 'out',
            'text' => mb_substr($reply, 0, 1900), 'lead_id' => $leadId, 'status' => 'draft_waiting', 'created_at' => now(), 'updated_at' => now()]);
        $who = $this->nameFor($leadId);
        $task = app(\App\Core\TaskSystem\TaskService::class)->create((int) $m->workspace_id, [
            'engine' => 'social', 'action' => 'social_send_message', 'source' => 'agent', 'assigned_agents' => ['marcus'], 'requires_approval' => true,
            'idempotency_key' => hash('sha256', 'messenger-reply:' . $draftId),
            'payload' => ['message_row_id' => $draftId, 'text' => $reply, 'their_message' => mb_substr((string) $m->text, 0, 400), 'author' => $who, 'title' => "Message reply to {$who}", 'description' => "Message reply to {$who}"],
        ]);
        DB::table('social_messages')->where('id', $draftId)->update(['task_id' => (int) $task->id]);
        $this->tellOwner((int) $m->workspace_id, $who, (string) $m->text, $x, trim((string) ($p['note'] ?? '')));
        return true;
    }

    /** Handler of an APPROVED social_send_message task. */
    public function send(int $wsId, array $params): array
    {
        $d = DB::table('social_messages')->where('id', (int) ($params['message_row_id'] ?? 0))->where('workspace_id', $wsId)->first();
        if (! $d) return ['success' => false, 'error' => 'That message is not in this workspace.', 'code' => 'NOT_FOUND', 'no_charge' => true];
        if ($d->status === 'sent') return ['success' => true, 'message' => 'Already sent.'];
        $lastIn = DB::table('social_messages')->where('workspace_id', $wsId)->where('psid', $d->psid)->where('direction', 'in')->max('created_at');
        if (! $lastIn || now()->diffInHours(\Illuminate\Support\Carbon::parse($lastIn)) >= 24) {
            DB::table('social_messages')->where('id', $d->id)->update(['status' => 'refused', 'updated_at' => now()]);
            return ['success' => false, 'error' => "Facebook only allows a reply within 24 hours of their last message, and that window has closed.", 'code' => 'WINDOW_CLOSED', 'no_charge' => true];
        }
        $acct = DB::table('social_accounts')->where('id', $d->social_account_id)->first();
        $tok = $acct ? $this->tokenFor($acct) : '';
        if ($tok === '') return ['success' => false, 'error' => 'The Facebook Page is not connected.', 'code' => 'NO_CONNECTION', 'no_charge' => true];
        $page = (string) ($acct->linked_page_id ?: $acct->account_id);
        $text = trim((string) ($params['text'] ?? $d->text));
        $r = Http::asForm()->timeout(20)->post(self::GRAPH . "/{$page}/messages", ['recipient' => json_encode(['id' => $d->psid]), 'messaging_type' => 'RESPONSE',
            'message' => json_encode(['text' => mb_substr($text, 0, 1900)]), 'access_token' => $tok]);
        $j = $r->json() ?: [];
        if ($r->successful() && ! empty($j['message_id'])) {
            DB::table('social_messages')->where('id', $d->id)->update(['status' => 'sent', 'mid' => (string) $j['message_id'], 'text' => $text, 'sent_at' => now(), 'updated_at' => now()]);
            return ['success' => true, 'message' => 'Message sent.', 'data' => ['message_id' => $j['message_id']]];
        }
        $msg = (string) ($j['error']['message'] ?? 'HTTP ' . $r->status());
        DB::table('social_messages')->where('id', $d->id)->update(['status' => 'refused', 'updated_at' => now()]);
        return ['success' => false, 'error' => stripos($msg, 'permission') !== false ? 'The Page connection does not allow Messenger yet — add pages_messaging and reconnect the Page.' : 'Facebook did not accept the message: ' . mb_substr($msg, 0, 160), 'code' => 'PROVIDER_REFUSED', 'no_charge' => true];
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    private function leadForPsid(int $wsId, string $psid, ?object $acct): ?int
    {
        $l = DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.messenger_psid')) = ?", [$psid])->first(['id']);
        if ($l) return (int) $l->id;
        $name = null;
        try { $tok = $acct ? $this->tokenFor($acct) : ''; if ($tok) { $pj = Http::timeout(10)->get(self::GRAPH . "/{$psid}", ['fields' => 'name', 'access_token' => $tok])->json() ?: []; $name = $pj['name'] ?? null; } } catch (\Throwable $e) {}
        $__ig = ($acct->platform ?? '') === 'instagram';   // SOCIAL-LEADS-5
        $lead = app(\App\Engines\CRM\Services\CrmService::class)->createLead($wsId, ['name' => $name ?: ($__ig ? 'Instagram contact' : 'Messenger contact'), 'source' => $__ig ? 'instagram_dm' : 'facebook_messenger',
            'metadata' => ['channel' => $__ig ? 'instagram_dm' : 'facebook_messenger', 'messenger_psid' => $psid, 'fb_name' => $name, 'business_id' => $acct->business_id ?? null, 'stage_note' => 'new - social']]);
        return (int) $lead->id;
    }

    private function enrichLead(int $leadId, array $x): void
    {
        $l = DB::table('leads')->where('id', $leadId)->first(); if (! $l) return;
        $m = json_decode((string) $l->metadata_json, true) ?: [];
        $m['signals'] = array_merge((array) ($m['signals'] ?? []), array_diff_key($x, array_flip(['name', 'email', 'phone'])));
        $upd = ['metadata_json' => json_encode($m, JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
        if (empty($l->email) && ! empty($x['email']) && filter_var($x['email'], FILTER_VALIDATE_EMAIL)) $upd['email'] = $x['email'];
        if (empty($l->phone) && ! empty($x['phone']) && preg_match('/\d{6,}/', preg_replace('/\D/', '', $x['phone']))) $upd['phone'] = $x['phone'];
        DB::table('leads')->where('id', $leadId)->update($upd);
    }

    private function tagLead(int $leadId, array $kv): void
    {
        $l = DB::table('leads')->where('id', $leadId)->first(['metadata_json']); if (! $l) return;
        $m = json_decode((string) $l->metadata_json, true) ?: [];
        DB::table('leads')->where('id', $leadId)->update(['metadata_json' => json_encode(array_merge($m, $kv), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }

    private function nameFor(?int $leadId): string
    {
        $n = $leadId ? DB::table('leads')->where('id', $leadId)->value('name') : null;
        return $n ? explode(' ', trim($n))[0] : 'someone';
    }

    /** Sarah tells the Owner (LLM first, fixed text fallback) and the bell rings. */
    private function tellOwner(int $wsId, string $who, string $text, array $x, string $note): void
    {
        $fallback = "{$who} messaged your Page: \"" . mb_substr($text, 0, 200) . '"' . ($x ? ' — they gave: ' . implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($x), $x)) . ' (added to their lead)' : '')
            . ". I've drafted a reply; it is not sent until you approve it below.";
        $msg = $fallback;
        try {
            $rt = app(\App\Connectors\RuntimeClient::class);
            if ($rt->isConfigured()) {
                $r = $rt->chatJson("You are Sarah. Tell the business owner, in 2-3 sentences from the FACTS only, that someone messaged their Facebook Page, what they said (short quote), the details they gave (saved to their CRM lead), and that your drafted reply waits for their approval below. No emojis, no invented facts, never say it was sent. Return ONLY JSON {\"message\":\"...\"}.",
                    'FACTS: ' . json_encode(['from' => $who, 'message' => mb_substr($text, 0, 300), 'details_saved_to_lead' => $x, 'your_note' => $note], JSON_UNESCAPED_UNICODE), ['task' => 'messenger_announce', 'workspace_id' => (string) $wsId], 250);
                $mm = trim((string) (($r['success'] ?? false) ? ($r['parsed']['message'] ?? '') : ''));
                if ($mm !== '' && ! preg_match('/\b(has been|was|already)\s+sent\b/i', $mm)) $msg = mb_substr($mm, 0, 1000);
            }
        } catch (\Throwable $e) {}
        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $msg, ['kind' => 'messenger_reply_waiting']);
        $owner = DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id');
        if ($owner) {
            try { app(\App\Core\Notifications\NotificationService::class)->dispatch(type: \App\Core\Notifications\NotificationTypes::AGENT_TASK_REQUIRES_APPROVAL, userId: (int) $owner,
                title: "New message from {$who}", workspaceId: $wsId, body: mb_substr($text, 0, 120), severity: 'info', actionUrl: '/app/social'); } catch (\Throwable $e) {}
        }
    }
}
