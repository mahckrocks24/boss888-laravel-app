<?php

namespace App\Engines\CRM\Services;

use App\Connectors\RuntimeClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LEADS-W1 (DEC-0089 wave 1, Owner 2026-10-05: "crm and leads, it scores 6/10"; "follow your recommendations"):
 * Sarah as a sales assistant that catches, chases and reads every enquiry.
 *
 *  - rate():  hot / warm / cold plus what to say, offer and ask and when to follow up, from what the person asked,
 *             how urgent it is and what it may be worth. Never invents prices or availability. Stored on the lead
 *             (metadata.sarah_rating). Included like the client summary: no credits.
 *  - tick():  the response clock for every enquiry nobody has answered: an in-app nudge at 1 h (one message per
 *             workspace, the replies ready to send), a push to the companion app at 2 h, one email to the owner at 24 h.
 *             Each step once per lead (lead_clock), batched per workspace, 08:00 to 21:00 in the owner's time. It stops
 *             the moment the lead is contacted, answered, won or lost (those leads are no longer "unanswered").
 *  - old():   once a week, enquiries over 21 days old that nobody answered come back as one decision card:
 *             "follow up #n" (Sarah writes it, it waits in Needs your OK) or "lost #n <reason>" (the reason is kept).
 *
 * Switch: storage/app/leads2.on lists workspace ids (one per line or comma separated; "*" = every workspace).
 */
final class LeadsAssistant
{
    public const LEVELS = ['hot', 'warm', 'cold'];
    private const HUMAN = "('note','call','email','meeting')";
    private static ?array $on = null;
    /** dry runs: what would have been sent, for the command to print */
    public array $report = [];

    public static function enabled(int $ws): bool
    {
        if (self::$on === null) {
            $f = storage_path('app/leads2.on');
            $raw = is_file($f) ? (string) @file_get_contents($f) : '';
            self::$on = is_file($f) ? array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw)))) : [];
        }
        return in_array('*', self::$on, true) || in_array((string) $ws, self::$on, true);
    }

    /** @return int[] workspace ids the switch names (every onboarded workspace for "*") */
    public static function workspaces(): array
    {
        self::enabled(0);
        if (in_array('*', self::$on, true)) return DB::table('workspaces')->where('onboarded', 1)->pluck('id')->map(fn ($x) => (int) $x)->all();
        return array_values(array_map('intval', array_filter(self::$on, 'ctype_digit')));
    }

    public static function reset(): void { self::$on = null; }

    /** A reply that still has a fill-in ("(your phone number)", "[date]", "{name}") must never reach a customer. */
    public static function hasPlaceholder(string $s): bool
    {
        return (bool) preg_match('/\((your|insert|add|enter|business|company|phone|number|name|date|time|address|link|email)[^)]{0,40}\)|\[[^\]]{2,40}\]|\{[^}]{2,40}\}|\bxxx+\b|\bTBD\b/i', $s);
    }

    /** Enquiries nobody has answered: new, not archived, no human contact, no reply sent. */
    public static function unanswered(int $ws)
    {
        return DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')->where('status', 'new')
            ->whereRaw("NOT EXISTS (SELECT 1 FROM activities a WHERE a.lead_id = leads.id AND a.type IN " . self::HUMAN . ")")
            ->whereRaw("NOT EXISTS (SELECT 1 FROM crm_reply_drafts d WHERE d.lead_id = leads.id AND d.status = 'sent')")
            ->whereNotIn('channel', ['import', 'manual']);
    }

    public static function rating(object $lead): ?array
    {
        $m = json_decode((string) $lead->metadata_json, true) ?: [];
        $r = $m['sarah_rating'] ?? null;
        return is_array($r) && in_array($r['level'] ?? '', self::LEVELS, true) ? $r : null;
    }

    /** Worth chasing: rated hot or warm (unrated ones too, until Sarah has read them). Spam and cold are not chased. */
    public static function chaseable(object $lead): bool
    {
        $r = self::rating($lead);
        return ! $r || (empty($r['spam']) && $r['level'] !== 'cold');
    }

    public static function isSpam(object $lead): bool
    {
        $r = self::rating($lead);
        return $r && ! empty($r['spam']);
    }

    public static function levelWord(?array $r): string
    {
        return $r ? ucfirst((string) $r['level']) : '';
    }

    // ── rating ──────────────────────────────────────────────────────────────
    public function rate(int $ws, int $leadId, bool $force = false): ?array
    {
        $lead = DB::table('leads')->where('id', $leadId)->where('workspace_id', $ws)->whereNull('deleted_at')->first();
        if (! $lead) return null;
        $meta = json_decode((string) $lead->metadata_json, true) ?: [];
        $asked = trim((string) ($meta['first_message'] ?? $meta['message'] ?? $meta['notes'] ?? ($meta['last_booking_request']['notes'] ?? '')));
        $sig = md5($asked . '|' . ($lead->deal_value ?? '') . '|' . json_encode($meta['fields'] ?? null));
        $old = self::rating($lead);
        if (! $force && $old && ($old['sig'] ?? '') === $sig) return $old;
        $sc = app(SarahClients::class);
        $facts = [
            'business' => $sc->businessFacts($ws, $lead->business_id ? (int) $lead->business_id : null),
            'enquiry' => array_filter([
                'name' => $lead->name, 'came_through' => $lead->channel, 'received' => (string) $lead->created_at, 'now' => now()->toDateTimeString(),
                'their_message' => mb_substr($asked, 0, 1500) ?: null, 'details' => $meta['fields'] ?? null,
                'service_asked_for' => $meta['service'] ?? ($meta['last_booking_request']['service'] ?? null),
                'preferred_date' => $meta['preferred_date'] ?? ($meta['last_booking_request']['preferred_date'] ?? null),
                'party_size' => $meta['party_size'] ?? ($meta['last_booking_request']['party_size'] ?? null),
                'has_email' => (bool) ClientIdentity::emailKey($lead->email), 'has_phone' => (bool) $lead->phone, 'company' => $lead->company,
                'value_noted' => (float) $lead->deal_value ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
        $sys = 'You are Sarah, a sales assistant for a small business. Read ONE enquiry and return JSON '
            . '{"level":"hot|warm|cold","spam":true|false,"why":"...","say":"...","offer":"...","ask":"...","follow_up":"..."}. '
            . 'spam: true when it is not a real customer - someone selling to the business (data lists, SEO, links, marketing, loans), a bot, a test, gibberish, or a message that has nothing to do with the business; spam is always cold. '
            . 'level: hot = ready to buy or book soon (a date, a budget, a clear service, urgency, or a large job); warm = interested but undecided or vague; '
            . 'cold = no real intent (spam, job seekers, sellers, a general question with no need). '
            . 'why: one short sentence with the evidence from their message. say: the one thing the reply must answer first. '
            . 'offer: what the business could offer them, ONLY from the BUSINESS facts (services, hours); if the facts have nothing fitting, say "Ask what they need". '
            . 'ask: one question that moves them to book or buy. follow_up: when to follow up if they do not reply, in plain words (e.g. "tomorrow morning"). '
            . 'NEVER invent prices, availability, discounts, capacity or dates that are not in the facts. Plain words, no markdown, each field under 25 words.';
        try {
            $r = app(RuntimeClient::class)->chatJson($sys, 'FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE), ['task' => 'crm_lead_rating', 'workspace_id' => (string) $ws], 400);
            $p = (($r['success'] ?? false) && is_array($r['parsed'] ?? null)) ? $r['parsed'] : null;
            $level = strtolower(trim((string) ($p['level'] ?? '')));
            if (! $p || ! in_array($level, self::LEVELS, true)) return $old;
            $clip = fn ($k) => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($p[$k] ?? '')))), 0, 220);
            $spam = filter_var($p['spam'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($spam) $level = 'cold';
            $out = ['level' => $level, 'spam' => $spam, 'why' => $clip('why'), 'say' => $clip('say'), 'offer' => $clip('offer'), 'ask' => $clip('ask'), 'follow_up' => $clip('follow_up'), 'sig' => $sig, 'at' => now()->toDateTimeString()];
            $meta = json_decode((string) DB::table('leads')->where('id', $leadId)->value('metadata_json'), true) ?: [];
            $meta['sarah_rating'] = $out;
            DB::table('leads')->where('id', $leadId)->update(['metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
            return $out;
        } catch (\Throwable $e) {
            Log::warning('[LEADS-W1] rate: ' . $e->getMessage(), ['lead' => $leadId]);
            return $old;
        }
    }

    /** LeadRateJob: a new enquiry is read; a hot one reaches the owner's phone at once (inside 08:00-21:00). */
    public function onNewLead(int $leadId, bool $dry = false): string
    {
        $lead = DB::table('leads')->where('id', $leadId)->first();
        if (! $lead || $lead->deleted_at || ! self::enabled((int) $lead->workspace_id)) return 'off';
        $ws = (int) $lead->workspace_id;
        $r = $this->rate($ws, $leadId);
        if (! $r) return 'not rated';
        if ($r['level'] !== 'hot') return $r['level'];
        if (! $this->awake($ws)) return 'hot (quiet hours)';
        if (DB::table('lead_clock')->where('lead_id', $leadId)->where('step', 'hot')->exists()) return 'hot (told)';
        $first = trim(explode(' ', (string) $lead->name)[0]) ?: 'Someone';
        $text = "Hot enquiry: {$first}" . ($r['why'] ? ' — ' . $r['why'] : '') . ' Your reply is ready in Needs your OK.';
        $this->push($ws, $text, $dry);
        $this->mark($ws, [$leadId], 'hot', null, $dry);
        return 'hot (pushed)';
    }

    // ── the response clock ──────────────────────────────────────────────────
    /** @return array<string,int> what happened, per step */
    public function tick(?int $only = null, bool $dry = false, bool $ignoreHours = false, bool $everyone = false): array
    {
        self::reset();
        $done = ['nudge' => 0, 'push' => 0, 'email' => 0, 'skipped_quiet' => 0];
        $everyone = $everyone && $dry;   // every onboarded workspace, but only ever as a dry run
        $list = $only ? [$only] : ($everyone ? DB::table('workspaces')->where('onboarded', 1)->pluck('id')->map(fn ($x) => (int) $x)->all() : self::workspaces());
        foreach ($list as $ws) {
            if (! self::enabled($ws) && ! $everyone) continue;
            try {
                if (! $ignoreHours && ! $this->awake($ws)) { $done['skipped_quiet']++; continue; }
                $rows = self::unanswered($ws)->where('created_at', '>', now()->subDays(21))->orderBy('created_at')->limit(50)->get();
                if ($rows->isEmpty()) continue;
                // enquiries from before the switch (or typed in) get Sarah's read too, a few each run (no credits)
                if (! $dry) {
                    $unrated = $rows->filter(fn ($l) => ! self::rating($l))->take(4);
                    foreach ($unrated as $l) $this->rate($ws, (int) $l->id);
                    if ($unrated->isNotEmpty()) $rows = self::unanswered($ws)->where('created_at', '>', now()->subDays(21))->orderBy('created_at')->limit(50)->get();
                }
                $rows = $rows->filter(fn ($l) => self::chaseable($l))->values();   // spam and cold are not chased
                if ($rows->isEmpty()) continue;
                $age = fn ($l) => Carbon::parse($l->created_at)->diffInMinutes(now());
                $clock = DB::table('lead_clock')->whereIn('lead_id', $rows->pluck('id'))->get()->groupBy('lead_id');
                $has = fn ($id, $step) => isset($clock[$id]) && $clock[$id]->contains('step', $step);
                $due = fn ($mins, $step) => $rows->filter(fn ($l) => $age($l) >= $mins && ! $has($l->id, $step))->values();
                if (($n = $due(60, 'nudge'))->isNotEmpty()) $done['nudge'] += $this->nudge($ws, $n, $dry);
                if (($p = $due(120, 'push'))->isNotEmpty()) $done['push'] += $this->pushWaiting($ws, $p, $dry);
                if (($m = $due(1440, 'email'))->isNotEmpty()) $done['email'] += $this->emailOwner($ws, $m, $dry);
            } catch (\Throwable $e) { Log::warning('[LEADS-W1] tick ws ' . $ws . ': ' . $e->getMessage()); }
        }
        return $done;
    }

    /** 08:00 to 21:00 in the owner's own time zone. */
    public function awake(int $ws): bool
    {
        $tz = (string) (DB::table('workspaces')->where('id', $ws)->value('timezone') ?: 'UTC');
        try { $h = (int) Carbon::now($tz)->format('G'); } catch (\Throwable $e) { $h = (int) Carbon::now('UTC')->format('G'); }
        return $h >= 8 && $h < 21;
    }

    private function line(object $l, ?string $biz): string
    {
        $m = json_decode((string) $l->metadata_json, true) ?: [];
        $asked = mb_substr(preg_replace('/\s+/', ' ', trim((string) ($m['first_message'] ?? $m['message'] ?? ''))), 0, 90);
        $r = self::rating($l);
        return '**' . $l->name . '**' . ($biz ? " ({$biz})" : '') . ($r ? ' · ' . self::levelWord($r) : '') . ' — enquired ' . Carbon::parse($l->created_at)->diffForHumans()
            . ($asked !== '' ? ': "' . $asked . (mb_strlen((string) ($m['first_message'] ?? $m['message'] ?? '')) > 90 ? '…' : '') . '"' : '');
    }

    /** 1 h: one in-app message per workspace with a reply ready for each person who has an email. */
    private function nudge(int $ws, $leads, bool $dry): int
    {
        $sc = app(SarahClients::class);
        $biz = DB::table('businesses')->where('workspace_id', $ws)->pluck('name', 'id');
        $leads = $leads->sortByDesc(fn ($l) => ['hot' => 3, 'warm' => 2, 'cold' => 1][self::rating($l)['level'] ?? ''] ?? 0)->values();
        $show = $leads->slice(0, 5)->values();
        $ids = []; $lines = []; $i = 0; $wrote = false;
        foreach ($show as $l) {
            $i++;
            $draft = DB::table('crm_reply_drafts')->where('lead_id', $l->id)->where('status', 'draft')->orderByDesc('id')->get()->first(fn ($x) => ! self::hasPlaceholder($x->subject . ' ' . $x->body));
            if (! $draft && ! $dry && ClientIdentity::emailKey($l->email) && empty($l->email_unsubscribed)) {
                $reply = $sc->writeReply($ws, $l, 'first_reply');
                if ($reply) { $draft = (object) ['id' => $sc->saveDraft($ws, $l, $reply, 'enquiry'), 'subject' => $reply['subject'], 'body' => $reply['body']]; $wrote = true; }
            }
            if ($draft) $ids[] = (int) $draft->id;
            $lines[] = "**{$i}.** " . $this->line($l, $biz[$l->business_id] ?? null)
                . ($draft ? "\n_" . ($draft->subject ?? 'Reply') . '_: ' . mb_substr(preg_replace('/\s+/', ' ', (string) $draft->body), 0, 200) . (mb_strlen((string) $draft->body) > 200 ? '…' : '')
                          : (ClientIdentity::emailKey($l->email) ? "\nReply to {$l->email} from Clients." : ($l->phone ? "\nNo email: call them on {$l->phone}." : "\nNo email or phone: open them in Clients.")));
        }
        if ($dry) { $this->report[] = "ws {$ws} nudge text:
" . implode("
", $lines); $this->mark($ws, $leads->pluck('id')->all(), 'nudge', null, true); return 1; }
        if ($wrote) { try { app(\App\Core\Billing\CreditService::class)->debit($ws, SarahClients::REPLY_CREDITS, 'crm_reply', null, ['what' => 'Replies to waiting enquiries']); } catch (\Throwable $e) {} }
        $more = $leads->count() - $show->count();
        $n = $leads->count();
        $oldest = Carbon::parse($leads->min('created_at'))->diffForHumans();
        $msgId = $sc->say($ws, 'crm_daily',
            'In one or two short sentences tell the owner that ' . $n . ' ' . ($n === 1 ? 'enquiry is' : 'enquiries are') . ' still waiting for a reply (the oldest came in ' . $oldest . '), and that a fast reply wins more of them. The people and the replies you wrote follow your words; do not repeat them. No greeting.',
            ['waiting' => array_map(fn ($l) => ['name' => $l->name, 'rating' => self::rating($l)['level'] ?? null], $show->all()), 'replies_ready' => count($ids), 'oldest_came_in' => $oldest],
            $n . ' ' . ($n === 1 ? 'enquiry is' : 'enquiries are') . ' still waiting for a reply, the oldest from ' . $oldest . '. A quick reply wins more of them:',
            ['card' => ['draft_ids' => $ids, 'lead_ids' => $show->pluck('id')->all()], 'action_url' => '/app/crm', 'push' => false, 'source' => 'leads_w1_nudge'],
            implode("\n\n", $lines) . ($more > 0 ? "\n\nAnd {$more} more in Clients." : '') . "\n\n" . ($ids ? 'Reply **send #1** (or any number), **send all**, or **not today**. Each one is also in Needs your OK.' : ''));
        foreach (array_values($ids) as $k => $id) DB::table('crm_reply_drafts')->where('id', $id)->update(['message_id' => $msgId, 'position' => $k + 1]);
        $this->mark($ws, $leads->pluck('id')->all(), 'nudge', $msgId, false);
        return 1;
    }

    /** 2 h: one push to the owner's phone for all of them. */
    private function pushWaiting(int $ws, $leads, bool $dry): int
    {
        $n = $leads->count(); $hot = $leads->filter(fn ($l) => (self::rating($l)['level'] ?? '') === 'hot')->count();
        $names = $leads->take(3)->map(fn ($l) => trim(explode(' ', (string) $l->name)[0]))->filter()->implode(', ');
        $text = ($n === 1 ? '1 enquiry is' : "{$n} enquiries are") . ' still waiting for a reply' . ($names ? " ({$names}" . ($n > 3 ? ' and more' : '') . ')' : '') . ($hot ? " — {$hot} hot" : '') . '. Tap to reply.';
        $this->push($ws, $text, $dry);
        $this->mark($ws, $leads->pluck('id')->all(), 'push', null, $dry);
        return 1;
    }

    private function push(int $ws, string $text, bool $dry): void
    {
        $owner = (int) DB::table('workspaces')->where('id', $ws)->value('created_by');
        if ($dry || ! $owner) { $this->report[] = "ws {$ws} push" . ($dry ? '' : ' (no owner)') . ": {$text}"; return; }
        try { app(\App\Core\Notifications\PushDispatcherService::class)->dispatchAgentReply($owner, $ws, 'sarah', $text, '', null, ['screen' => 'review', 'kind' => 'leads_waiting']); }
        catch (\Throwable $e) { Log::info('[LEADS-W1] push failed: ' . $e->getMessage()); }
    }

    /** 24 h: one email to the owner (LevelUpGrowth's own notification email), once per lead. */
    private function emailOwner(int $ws, $leads, bool $dry): int
    {
        $owner = (int) DB::table('workspaces')->where('id', $ws)->value('created_by');
        $biz = DB::table('businesses')->where('workspace_id', $ws)->pluck('name', 'id');
        $n = $leads->count();
        $title = $n === 1 ? ('An enquiry from ' . $leads->first()->name . ' is still waiting for a reply') : "{$n} enquiries are still waiting for a reply";
        $body = ($n === 1 ? 'It has been more than a day since this person got in touch' : "It has been more than a day since these {$n} people got in touch") . ", and nobody has replied yet. People who hear back quickly are far more likely to book.\n\n"
            . implode("\n", $leads->take(8)->map(fn ($l) => '• ' . str_replace('**', '', $this->line($l, $biz[$l->business_id] ?? null)))->all())
            . ($n > 8 ? "\n• And " . ($n - 8) . ' more.' : '') . "\n\nSarah has a reply ready for each one with an email address. Open Clients to send them in one tap.";
        if ($dry || ! $owner) { $this->report[] = "ws {$ws} email" . ($dry ? '' : ' (no owner)') . ": {$title}"; $this->mark($ws, $leads->pluck('id')->all(), 'email', null, true); return 1; }
        $id = DB::table('notifications')->insertGetId([
            'workspace_id' => $ws, 'user_id' => $owner, 'channel' => 'email', 'type' => 'leads_waiting', 'category' => 'crm', 'title' => $title, 'body' => $body,
            'action_url' => rtrim((string) config('app.url'), '/') . '/app/crm', 'severity' => 'warning', 'email_required' => 1,
            'data_json' => json_encode(['lead_ids' => $leads->pluck('id')->all()]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Jobs\SendNotificationEmail::dispatch((int) $id);
        $this->mark($ws, $leads->pluck('id')->all(), 'email', null, false);
        return 1;
    }

    private function mark(int $ws, array $leadIds, string $step, ?int $msgId, bool $dry): void
    {
        if ($dry) { $this->report[] = "ws {$ws} {$step}: leads " . implode(',', $leadIds); return; }
        foreach ($leadIds as $id) {
            DB::table('lead_clock')->updateOrInsert(['lead_id' => (int) $id, 'step' => $step],
                ['workspace_id' => $ws, 'message_id' => $msgId, 'dry_run' => $dry ? 1 : 0, 'sent_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    // ── old enquiries: the weekly decision ──────────────────────────────────
    /** Mondays 09:00 local (or $force): enquiries over 21 days old that nobody answered, one card, follow up or lost. */
    public function old(?int $only = null, bool $dry = false, bool $force = false): int
    {
        self::reset();
        $done = 0;
        foreach ($only ? [$only] : self::workspaces() as $ws) {
            if (! self::enabled($ws)) continue;
            try {
                $tz = (string) (DB::table('workspaces')->where('id', $ws)->value('timezone') ?: 'UTC');
                try { $local = Carbon::now($tz); } catch (\Throwable $e) { $local = Carbon::now('UTC'); }
                if (! $force && ! ($local->isMonday() && (int) $local->format('G') === 9)) continue;
                if (! $force && ! $dry && ! Cache::add('leads-old:' . $ws . ':' . $local->toDateString(), 1, now()->addHours(30))) continue;
                $recent = DB::table('lead_clock')->where('workspace_id', $ws)->where('step', 'old')->where('sent_at', '>', now()->subDays(6))->pluck('lead_id')->all();
                $rows = self::unanswered($ws)->where('created_at', '<=', now()->subDays(21))->where('created_at', '>', now()->subDays(180))
                    ->whereNotIn('id', $recent ?: [0])->orderByDesc('created_at')->limit(8)->get();
                $rows = $rows->filter(fn ($l) => ! self::isSpam($l))->values();
                if ($rows->isEmpty()) continue;
                $biz = DB::table('businesses')->where('workspace_id', $ws)->pluck('name', 'id');
                $lines = []; foreach ($rows->values() as $i => $l) $lines[] = '**' . ($i + 1) . '.** ' . $this->line($l, $biz[$l->business_id] ?? null);
                if ($dry) { $this->report[] = "ws {$ws} old card:
" . implode("
", $lines); $done++; continue; }
                $n = $rows->count();
                $msgId = app(SarahClients::class)->say($ws, 'lead_old',
                    'In one short sentence tell the owner these ' . $n . ' older ' . ($n === 1 ? 'enquiry was' : 'enquiries were') . ' never answered and you need a decision on each: follow up, or close it as lost. The list follows your words; do not repeat it. No greeting.',
                    ['older_enquiries' => $n],
                    $n . ' older ' . ($n === 1 ? 'enquiry was' : 'enquiries were') . ' never answered. Shall I follow up, or close them as lost?',
                    ['card' => ['lead_ids' => $rows->pluck('id')->map(fn ($x) => (int) $x)->all()], 'action_url' => '/app/crm', 'source' => 'leads_w1_old'],
                    implode("\n\n", $lines) . "\n\nReply **follow up #1** (I write it, it waits for your OK), or **lost #2 because they booked elsewhere** to close it with the reason.");
                $this->mark($ws, $rows->pluck('id')->all(), 'old', $msgId, false);
                $done++;
            } catch (\Throwable $e) { Log::warning('[LEADS-W1] old ws ' . $ws . ': ' . $e->getMessage()); }
        }
        return $done;
    }

    /** Close a lead as lost with the owner's reason (kept for wave 2's learning). */
    public function markLost(int $ws, int $leadId, string $reason, ?int $userId = null): bool
    {
        $lead = DB::table('leads')->where('id', $leadId)->where('workspace_id', $ws)->whereNull('deleted_at')->first();
        if (! $lead) return false;
        $reason = mb_substr(trim(preg_replace('/\s+/', ' ', $reason)), 0, 240);
        $meta = json_decode((string) $lead->metadata_json, true) ?: [];
        $meta['lost_reason'] = $reason; $meta['lost_at'] = now()->toDateTimeString(); $meta['lost_by'] = $userId;
        $pack = CrmPacks::forBusiness($lead->business_id ? (int) $lead->business_id : null);
        $stage = collect($pack['stages'])->firstWhere('status', 'lost')['key'] ?? null;
        DB::table('leads')->where('id', $leadId)->update(['status' => 'lost', 'stage' => $stage, 'metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        \App\Models\Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => $leadId, 'type' => 'status_changed', 'completed' => 1,
            'subject' => 'Closed as lost' . ($reason !== '' ? ': ' . $reason : ''), 'performed_by' => $userId, 'metadata_json' => ['lost_reason' => $reason, 'by' => $userId ? 'owner' : 'sarah']]);
        DB::table('crm_reply_drafts')->where('lead_id', $leadId)->where('status', 'draft')->update(['status' => 'skipped', 'updated_at' => now()]);
        return true;
    }

    /** Write a follow-up for one lead; it waits in Needs your OK. @return int|null draft id */
    public function followUpDraft(int $ws, int $leadId): ?int
    {
        $lead = DB::table('leads')->where('id', $leadId)->where('workspace_id', $ws)->whereNull('deleted_at')->first();
        if (! $lead || ! ClientIdentity::emailKey($lead->email) || ! empty($lead->email_unsubscribed)) return null;
        $open = DB::table('crm_reply_drafts')->where('lead_id', $leadId)->where('status', 'draft')->value('id');
        if ($open) return (int) $open;
        try { if (! app(\App\Core\Billing\CreditService::class)->hasBalance($ws, SarahClients::REPLY_CREDITS)) return null; } catch (\Throwable $e) {}
        $sc = app(SarahClients::class);
        $reply = $sc->writeReply($ws, $lead, 'follow_up', 'they enquired ' . Carbon::parse($lead->created_at)->diffForHumans() . ' and never heard back');
        if (! $reply) return null;
        try { app(\App\Core\Billing\CreditService::class)->debit($ws, SarahClients::REPLY_CREDITS, 'crm_reply', $leadId, ['what' => 'Follow-up to an older enquiry']); } catch (\Throwable $e) {}
        return $sc->saveDraft($ws, $lead, $reply, 'followup', 'older enquiry, never answered');
    }

    /** What waits on the owner: replies to enquiries (for Needs your OK, the rail and Review). */
    public function waiting(int $ws, int $limit = 12): array
    {
        if (! self::enabled($ws)) return [];
        $rows = self::unanswered($ws)->where('created_at', '>', now()->subDays(21))->orderByDesc('created_at')->limit(60)->get();
        $biz = DB::table('businesses')->where('workspace_id', $ws)->pluck('name', 'id');
        $drafts = DB::table('crm_reply_drafts')->whereIn('lead_id', $rows->pluck('id')->all() ?: [0])->where('status', 'draft')->orderByDesc('id')->get()->groupBy('lead_id');
        $out = [];
        foreach ($rows as $l) {
            if (! self::chaseable($l)) continue;   // spam and cold stay in Clients, not in Needs your OK
            $m = json_decode((string) $l->metadata_json, true) ?: [];
            $r = self::rating($l); $d = isset($drafts[$l->id]) ? $drafts[$l->id]->first(fn ($x) => ! self::hasPlaceholder($x->subject . ' ' . $x->body)) : null;
            $out[] = ['lead_id' => (int) $l->id, 'name' => $l->name, 'business' => $biz[$l->business_id] ?? null, 'channel' => $l->channel, 'created_at' => (string) $l->created_at,
                'waiting' => Carbon::parse($l->created_at)->diffForHumans(null, true), 'asked' => mb_substr(trim((string) ($m['first_message'] ?? $m['message'] ?? '')), 0, 240) ?: null,
                'has_email' => (bool) ClientIdentity::emailKey($l->email), 'phone' => $l->phone ?: null,
                'rating' => $r ? ['level' => $r['level'], 'why' => $r['why'], 'say' => $r['say'], 'offer' => $r['offer'], 'ask' => $r['ask'], 'follow_up' => $r['follow_up']] : null,
                'draft' => $d ? ['id' => (int) $d->id, 'subject' => $d->subject, 'body' => $d->body] : null];
        }
        usort($out, fn ($a, $b) => (['hot' => 3, 'warm' => 2, 'cold' => 1][$b['rating']['level'] ?? ''] ?? 0) <=> (['hot' => 3, 'warm' => 2, 'cold' => 1][$a['rating']['level'] ?? ''] ?? 0) ?: strcmp($a['created_at'], $b['created_at']));
        return array_slice($out, 0, $limit);
    }
}
