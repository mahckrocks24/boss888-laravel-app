<?php

namespace App\Engines\CRM\Services;

use App\Connectors\RuntimeClient;
use App\Core\Agents\AgentMessageService;
use App\Core\Brand\BrandIntakeService;
use App\Core\Email888\TenantEmail;
use App\Core\Growth\ChatReplies;
use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * CRM-SARAH-3 (Clients revamp Phase 3): Sarah works the client list.
 *
 *  - Speed to lead: a new enquiry with an email gets a reply written in the business's voice within about a minute.
 *    The first time, Sarah asks the owner once (with the reply she would send); after that she sends (owner said
 *    "send them") or shows each one first (owner said "show me first"). Stoppable any time: "stop replying for me".
 *  - Who to contact today: each morning, a short list with the reason and a written message for each person.
 *  - A summary and next step on every client record.
 *
 * What goes to a client is white-label (TenantEmail, purpose tenant_transactional): the business speaks, never
 * LevelUpGrowth or Sarah. Replies never invent prices, availability or times that are not in the business's facts.
 * Kill switches: storage/app/speedlead.on (new-enquiry replies), storage/app/crmdaily.on (morning list).
 */
class SarahClients
{
    public const REPLY_CREDITS = 1;

    // ── the business, as a reply can speak for it ────────────────────────────
    public function businessFacts(int $ws, ?int $bizId): array
    {
        $b = $bizId ? DB::table('businesses')->where('id', $bizId)->where('workspace_id', $ws)->first() : null;
        $id = TenantEmail::identity($ws, $bizId);
        $services = $b ? array_slice(array_values(array_filter(array_map(fn ($s) => is_array($s) ? ($s['name'] ?? $s['title'] ?? null) : $s, json_decode((string) $b->services_json, true) ?: []))), 0, 12) : [];
        $hours = $b ? (json_decode((string) $b->opening_hours_json, true) ?: null) : null;
        return array_filter([
            'business_name' => $id['name'], 'industry' => $b->industry ?? null, 'services' => $services ?: null, 'location' => $b->location ?? $id['address_line'],
            'phone' => $id['phone'], 'website' => $id['website'], 'tone' => $b->tone ?? null, 'opening_hours' => $hours, 'what_makes_us_different' => $b->differentiators ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function leadFacts(object $lead): array
    {
        $meta = json_decode((string) $lead->metadata_json, true) ?: [];
        $req = (array) ($meta['last_booking_request'] ?? []);
        return array_filter([
            'first_name' => trim(explode(' ', (string) $lead->name)[0]) ?: null, 'came_through' => ClientIdentity::channelFor($lead->source),
            'their_message' => mb_substr((string) ($meta['first_message'] ?? $meta['notes'] ?? $req['notes'] ?? ''), 0, 1200) ?: null,
            'service_asked_for' => $meta['service'] ?? $req['service'] ?? ($meta['fields']['service'] ?? null),
            'preferred_date' => $meta['preferred_date'] ?? $req['preferred_date'] ?? null, 'preferred_time' => $meta['preferred_time'] ?? $req['preferred_time'] ?? null,
            'party_size' => $meta['party_size'] ?? $req['party_size'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Write a reply to a client as the business. @return array{subject:string, body:string}|null */
    public function writeReply(int $ws, object $lead, string $purpose = 'first_reply', ?string $reason = null): ?array
    {
        $biz = $this->businessFacts($ws, $lead->business_id ? (int) $lead->business_id : null);
        $facts = ['business' => $biz, 'client' => $this->leadFacts($lead), 'purpose' => $purpose, 'why_now' => $reason];
        $sys = 'You write short emails FROM a small business TO one of its clients, in the business\'s own voice ("we"). Return JSON {"subject":"...","body":"..."}. '
            . 'Rules: 50 to 110 words; plain text paragraphs, no markdown; greet the client by first name; answer exactly what they asked; '
            . 'NEVER invent prices, availability, capacity, dates, times, discounts or facts that are not in BUSINESS facts (welcome them warmly, but never say yes to whether a slot, place or service is available unless the facts say so) — if they asked to book or asked a price you do not have, say the team will confirm shortly and give the phone number if there is one; '
            . 'end with the business name as the sign-off; never mention AI, assistants, Sarah, LevelUpGrowth or any software. '
            . ($purpose === 'first_reply' ? 'This is the first reply to their enquiry, sent within minutes of it.' : 'This is a follow-up: they enquired earlier and have not heard from us recently (why_now says why). Be warm and useful, never pushy.');
        try {
            $r = app(RuntimeClient::class)->chatJson($sys, 'FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE), ['task' => 'crm_client_reply', 'workspace_id' => (string) $ws], 600);
            $p = (($r['success'] ?? false) && is_array($r['parsed'] ?? null)) ? $r['parsed'] : null;
            $subject = trim((string) ($p['subject'] ?? '')); $body = trim((string) ($p['body'] ?? ''));
            if ($body === '' || mb_strlen($body) < 30 || preg_match('/\b(levelup|artificial intelligence|chatgpt|openai|deepseek|flux\.1|black forest labs|fal\.ai|language model|an assistant)\b/i', $subject . ' ' . $body) || preg_match('/\bAI\b/', $subject . ' ' . $body)) return null;
            return ['subject' => mb_substr($subject ?: 'Thanks for getting in touch', 0, 150), 'body' => mb_substr($body, 0, 2000)];
        } catch (\Throwable $e) {
            Log::warning('[CRM-SARAH-3] writeReply: ' . $e->getMessage());
            return null;
        }
    }

    public function saveDraft(int $ws, object $lead, array $reply, string $source, ?string $reason = null): int
    {
        return (int) DB::table('crm_reply_drafts')->insertGetId([
            'workspace_id' => $ws, 'business_id' => $lead->business_id, 'lead_id' => $lead->id, 'source' => $source, 'channel' => 'email',
            'subject' => $reply['subject'], 'body' => $reply['body'], 'reason' => $reason ? mb_substr($reason, 0, 255) : null, 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Send a drafted reply by white-label email; the client's timeline and stage follow. */
    public function sendDraft(int $ws, int $draftId, ?int $userId = null, ?string $editedBody = null): array
    {
        $d = DB::table('crm_reply_drafts')->where('id', $draftId)->where('workspace_id', $ws)->first();
        if (! $d) return ['success' => false, 'error' => 'That message was not found.'];
        if ($d->status === 'sent') return ['success' => true, 'already' => true];
        $lead = DB::table('leads')->where('id', $d->lead_id)->where('workspace_id', $ws)->whereNull('deleted_at')->first();
        if (! $lead) return ['success' => false, 'error' => 'That client is no longer in Clients.'];
        $to = ClientIdentity::emailKey($lead->email);
        if (! $to) return ['success' => false, 'error' => 'They have no email address to reply to.'];
        if (! empty($lead->email_unsubscribed)) return ['success' => false, 'error' => 'They asked not to receive emails.'];
        $body = trim((string) ($editedBody ?? $d->body));
        $tid = TenantEmail::identity($ws, $lead->business_id ? (int) $lead->business_id : null);
        try {
            $html = TenantEmail::layout($tid, '', TenantEmail::paragraphs($body), null, null, mb_substr(preg_replace('/\s+/', ' ', $body), 0, 110));
            Mail::html($html, function ($m) use ($body, $ws, $lead, $d, $tid, $to) {
                $m->text($body);
                $h = $m->getSymfonyMessage()->getHeaders();
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_PURPOSE, 'tenant_transactional');
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_WORKSPACE, (string) $ws);
                if ($lead->business_id) $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_BUSINESS, (string) $lead->business_id);
                $m->from($tid['address'], $tid['name'])->to($to, $lead->name ?: null)->subject($d->subject ?: 'Thanks for getting in touch');
                if (! empty($tid['reply_to'])) $m->replyTo($tid['reply_to'], $tid['name']);
            });
        } catch (\Throwable $e) {
            try { app(\App\Core\Email888\DeliveryLedger::class)->markLastRecordedFailed($e); } catch (\Throwable $x) {}
            DB::table('crm_reply_drafts')->where('id', $d->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => now()]);
            return ['success' => false, 'error' => 'The email could not be sent.'];
        }
        DB::table('crm_reply_drafts')->where('id', $d->id)->update(['status' => 'sent', 'sent_at' => now(), 'sent_by' => $userId, 'body' => $body, 'updated_at' => now()]);
        Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => (int) $lead->id, 'type' => 'email', 'completed' => 1,
            'subject' => ($userId ? 'Emailed: ' : 'Sarah replied by email: ') . ($d->subject ?: 'Reply'), 'description' => $body, 'performed_by' => $userId,
            'metadata_json' => ['draft_id' => (int) $d->id, 'by' => $userId ? 'owner' : 'sarah']]);
        $upd = ['last_contacted_at' => now(), 'updated_at' => now()];
        if ($lead->status === 'new') {
            $pack = CrmPacks::forBusiness($lead->business_id ? (int) $lead->business_id : null);
            $upd['status'] = 'contacted';
            $upd['stage'] = collect($pack['stages'])->firstWhere('status', 'contacted')['key'] ?? null;
        }
        DB::table('leads')->where('id', $lead->id)->update($upd);
        if ($lead->business_id && ! $userId) DB::table('crm_autoreply')->where('workspace_id', $ws)->where('business_id', $lead->business_id)->update(['sent_count' => DB::raw('sent_count + 1'), 'last_sent_at' => now()]);
        return ['success' => true];
    }

    public function skipDraft(int $ws, int $draftId): void
    {
        DB::table('crm_reply_drafts')->where('id', $draftId)->where('workspace_id', $ws)->where('status', 'draft')->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    // ── speed to lead ────────────────────────────────────────────────────────
    public function autoreplyRow(int $ws, int $bizId): object
    {
        $r = DB::table('crm_autoreply')->where('workspace_id', $ws)->where('business_id', $bizId)->first();
        if ($r) return $r;
        DB::table('crm_autoreply')->insertOrIgnore(['workspace_id' => $ws, 'business_id' => $bizId, 'status' => 'not_asked', 'mode' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('crm_autoreply')->where('workspace_id', $ws)->where('business_id', $bizId)->first();
    }

    public function setAutoreply(int $ws, int $bizId, string $status, ?string $mode, ?int $userId): void
    {
        $this->autoreplyRow($ws, $bizId);
        $u = ['status' => $status, 'updated_at' => now()];
        if ($mode) $u['mode'] = $mode;
        if ($status === 'on') { $u['approved_by'] = $userId; $u['approved_at'] = now(); $u['stopped_at'] = null; }
        if (in_array($status, ['off', 'declined'], true)) { $u['stopped_by'] = $userId; $u['stopped_at'] = now(); }
        DB::table('crm_autoreply')->where('workspace_id', $ws)->where('business_id', $bizId)->update($u);
    }

    /** Should this new lead get a reply from Sarah? The reason it should not, or null. */
    public function speedBlocker(object $lead): ?string
    {
        if (! is_file(storage_path('app/speedlead.on'))) return 'switched off';
        if (! $lead->business_id) return 'no business';
        if ($lead->deleted_at) return 'archived';
        if ($lead->status !== 'new') return 'already handled';
        if (! ClientIdentity::emailKey($lead->email)) return 'no email';
        if (! empty($lead->email_unsubscribed)) return 'unsubscribed';
        if (! in_array($lead->channel, ['website', 'booking', 'chatbot', 'facebook', 'instagram', 'messenger'], true)) return 'not an enquiry';
        if (DB::table('activities')->where('lead_id', $lead->id)->whereIn('type', ['call', 'email', 'meeting', 'note'])->exists()) return 'already replied';
        if (DB::table('crm_reply_drafts')->where('lead_id', $lead->id)->where('source', 'enquiry')->exists()) return 'already drafted';
        $ws = (int) $lead->workspace_id;
        try { if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessSarah($ws)) return 'plan without Sarah'; } catch (\Throwable $e) {}
        return null;
    }

    /** SpeedToLeadJob: a new enquiry, about a minute old. */
    public function onNewLead(int $leadId): string
    {
        $lead = DB::table('leads')->where('id', $leadId)->first();
        if (! $lead) return 'gone';
        if ($why = $this->speedBlocker($lead)) return $why;
        $ws = (int) $lead->workspace_id; $biz = (int) $lead->business_id;
        $row = $this->autoreplyRow($ws, $biz);
        if (in_array($row->status, ['off', 'declined'], true)) return 'owner said no';
        try { if (! app(\App\Core\Billing\CreditService::class)->hasBalance($ws, self::REPLY_CREDITS)) return 'no credits'; } catch (\Throwable $e) {}
        $reply = $this->writeReply($ws, $lead, 'first_reply');
        if (! $reply) return 'could not write';
        try { app(\App\Core\Billing\CreditService::class)->debit($ws, self::REPLY_CREDITS, 'crm_reply', $leadId, ['what' => 'Reply to a new enquiry']); } catch (\Throwable $e) { Log::info('[CRM-SARAH-3] debit: ' . $e->getMessage()); }
        $draftId = $this->saveDraft($ws, $lead, $reply, 'enquiry');
        $bizName = (string) DB::table('businesses')->where('id', $biz)->value('name');
        $first = trim(explode(' ', (string) $lead->name)[0]) ?: 'Someone';
        $asked = mb_substr((string) ($this->leadFacts($lead)['their_message'] ?? ''), 0, 160);
        if ($row->status === 'on' && $row->mode === 'send') {
            $r = $this->sendDraft($ws, $draftId, null);
            if (! empty($r['success'])) {
                $this->say($ws, 'crm_autoreplied', 'Tell the owner in one short sentence that you already answered a new enquiry for them, who it was and what they asked. No greeting.',
                    ['new_client_who_enquired' => $lead->name, 'owner_business_that_received_it' => $bizName, 'what_the_client_asked' => $asked], "I replied to **{$lead->name}**'s enquiry for {$bizName} within a minute" . ($asked ? ": \"{$asked}\"" : '.'),
                    ['lead_id' => $leadId, 'draft_id' => $draftId, 'action_url' => '/app/crm/' . $leadId], '');
                return 'sent';
            }
            return 'send failed';
        }
        // show it first: the first time, ask for the standing yes; after "show me first", ask about this one
        $asking = $row->status !== 'on';
        $plain = "\n\n**Subject:** " . $reply['subject'] . "\n\n" . $reply['body'] . "\n\n"
            . ($asking ? "Reply **yes, send them** and I will answer every new enquiry for {$bizName} like this within a minute, or **show me first** to approve each one, or **no thanks**." : 'Reply **send it**, or **skip**.');
        $msgId = $this->say($ws, $asking ? 'autoreply_ask' : 'lead_reply',
            $asking ? 'A new enquiry just came in. In two short sentences: say who and what they asked, and offer to answer new enquiries like this for them within a minute, in the business\'s voice, never promising prices or times. The reply you wrote follows your words, so do not repeat it.'
                    : 'A new enquiry just came in. In one short sentence say who and what they asked, and that your reply is ready below for them to send. Do not repeat the reply.',
            ['new_client_who_enquired' => $lead->name, 'owner_business_that_received_it' => $bizName, 'what_the_client_asked' => $asked],
            $asking ? "{$first} just enquired with {$bizName}" . ($asked ? ": \"{$asked}\"" : '.') . " Want me to answer new enquiries like this for you within a minute? Here is the reply I would send:"
                    : "{$first} just enquired with {$bizName}" . ($asked ? ": \"{$asked}\"" : '.') . ' My reply is ready:',
            ['lead_id' => $leadId, 'draft_id' => $draftId, 'card' => ['business_id' => $biz, 'draft_id' => $draftId, 'lead_id' => $leadId], 'action_url' => '/app/crm/' . $leadId], $plain);
        DB::table('crm_reply_drafts')->where('id', $draftId)->update(['message_id' => $msgId]);
        if ($asking) DB::table('crm_autoreply')->where('id', $row->id)->update(['status' => 'asked', 'updated_at' => now()]);
        return $asking ? 'asked' : 'drafted';
    }

    /** Sarah speaks to the owner (her words first), then the exact text after the app marker. */
    public function say(int $ws, string $type, string $instruction, array $facts, string $fallback, array $meta, string $exact): int
    {
        $words = $fallback;
        try { $words = app(BrandIntakeService::class)->sarahWords($ws, $type, $instruction, $facts, $fallback); } catch (\Throwable $e) {}
        $text = $words . ($exact !== '' ? ChatReplies::APP_PART . ltrim($exact) : '');
        $id = app(AgentMessageService::class)->postAsAgent($ws, 'sarah', $text, $meta + ['notification_type' => $type, 'no_digest' => true]);
        return is_numeric($id) ? (int) $id : (int) DB::table('agent_messages')->where('workspace_id', $ws)->where('agent_slug', 'sarah')->max('id');
    }

    // ── summary and next step on a client record ─────────────────────────────
    public function summary(int $ws, int $leadId, bool $force = false): ?array
    {
        $lead = DB::table('leads')->where('id', $leadId)->where('workspace_id', $ws)->first();
        if (! $lead) return null;
        $meta = json_decode((string) $lead->metadata_json, true) ?: [];
        $sig = (string) DB::table('activities')->where('lead_id', $leadId)->max('id') . ':' . $lead->status . ':' . ($lead->stage ?? '');
        $cached = $meta['sarah_summary'] ?? null;
        if (! $force && is_array($cached) && ($cached['sig'] ?? '') === $sig) return $cached;
        $pack = CrmPacks::forBusiness($lead->business_id ? (int) $lead->business_id : null);
        $stage = CrmPacks::stageOf($pack, $lead->stage, $lead->status);
        $tl = array_slice(app(CrmService::class)->leadTimeline($ws, $leadId), 0, 25);
        $facts = [
            'client' => $lead->name, 'stage' => collect($pack['stages'])->firstWhere('key', $stage)['name'] ?? $stage, 'stages_in_order' => array_column($pack['stages'], 'name'),
            'came_through' => $lead->channel, 'added' => (string) $lead->created_at, 'today' => now()->toDateString(), 'value' => (float) $lead->deal_value ?: null,
            'their_first_message' => mb_substr((string) ($meta['first_message'] ?? ''), 0, 600) ?: null, 'details' => $meta['fields'] ?? null,
            'history_newest_first' => array_map(fn ($a) => ['when' => $a['created_at'], 'what' => $a['type'], 'text' => mb_substr(trim($a['title'] . ' ' . ($a['description'] ?? '')), 0, 220), 'task_done' => $a['type'] === 'task' ? $a['status'] === 'done' : null], $tl),
            'upcoming' => DB::table('calendar_events')->where('lead_id', $leadId)->where('starts_at', '>=', now()->subDay())->orderBy('starts_at')->limit(3)->get(['title', 'starts_at', 'category'])->all(),
        ];
        $sys = 'You brief a small-business owner on one of their clients. Return JSON {"summary":"...","next_step":"..."}. summary: 2 or 3 short sentences — who they are, what they want, where things stand, using only the facts. '
            . 'next_step: one concrete action for the owner with a when (e.g. "Call her today to confirm the Thursday slot"). Never invent facts. Plain words, no markdown, no internal codes.';
        try {
            $r = app(RuntimeClient::class)->chatJson($sys, 'FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE), ['task' => 'crm_client_summary', 'workspace_id' => (string) $ws], 350);
            $p = (($r['success'] ?? false) && is_array($r['parsed'] ?? null)) ? $r['parsed'] : null;
            if (! $p || trim((string) ($p['summary'] ?? '')) === '') return is_array($cached) ? $cached : null;
            $out = ['summary' => mb_substr(trim((string) $p['summary']), 0, 600), 'next_step' => mb_substr(trim((string) ($p['next_step'] ?? '')), 0, 240), 'sig' => $sig, 'at' => now()->toDateTimeString()];
            $meta['sarah_summary'] = $out;
            DB::table('leads')->where('id', $leadId)->update(['metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
            return $out;
        } catch (\Throwable $e) {
            Log::warning('[CRM-SARAH-3] summary: ' . $e->getMessage());
            return is_array($cached) ? $cached : null;
        }
    }
}
