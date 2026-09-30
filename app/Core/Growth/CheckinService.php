<?php

namespace App\Core\Growth;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WATCH-1 (RFC-0019): Sarah talks with the owner through the day, like a marketing manager who cares about the business.
 * Owner 2026-09-27: "morning brief, afternoon brief, and night brief. but afternoon and night should not sound like a
 * report but a kind of communication to the boss. 'How's business going?' not her reporting but also asking what's new,
 * so she will learn the growth of the business too. ... weekly feedback question to the user to gauge satisfaction,
 * conversational and strategy driven so she can adjust her campaign based on actual results and perception of the user.
 * The goal is to keep the user satisfied and maintain subscription with us."
 *
 *   morning   the existing brief (SarahDailyOrchestrator, 08:00 local) — the one report of the day
 *   afternoon 14:00 local — a short, human check-in: how is business today, anything new
 *   night     19:00 local — winding down: how did the day go, anything coming up she should know
 *   weekly    Friday afternoon replaces the check-in: an honest, strategy-minded "how am I doing for you?"
 * The owner's answers are absorbed into the business journal (what is new, sales, customers, wins, problems, plans) and
 * become signals, so campaigns adjust to real results AND to how the owner feels about them. Never nags: no check-in
 * while the owner is already chatting, and she backs off when check-ins go unanswered. The owner can turn them off
 * ("stop checking in", "don't message me at night").
 */
final class CheckinService
{
    public const HOURS = ['afternoon' => 14, 'night' => 19];

    /** Hourly: send the check-ins that are due in each workspace's local time. */
    public function tick(?int $onlyWs = null, ?string $force = null): array
    {
        $sent = [];
        // check-ins nobody answered within 12 hours are closed as unanswered
        DB::table('owner_checkins')->where('status', 'asked')->where('created_at', '<', now()->subHours(12))->update(['status' => 'unanswered', 'updated_at' => now()]);
        $q = DB::table('workspaces')->where('onboarded', 1)->where('proactive_enabled', 1);
        if ($onlyWs) $q->where('id', $onlyWs);
        foreach ($q->get(['id', 'timezone', 'settings_json']) as $ws) {
            try { $now = Carbon::now($ws->timezone ?: 'UTC'); } catch (\Throwable $e) { $now = Carbon::now('UTC'); }
            $kind = $force;
            if (! $kind) {
                if ($now->hour === self::HOURS['afternoon']) $kind = $now->isFriday() ? 'weekly_feedback' : 'afternoon';
                elseif ($now->hour === self::HOURS['night']) $kind = 'night';
            }
            if (! $kind) continue;
            try { if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessSarah((int) $ws->id)) continue; } catch (\Throwable $e) {}   // SARAH-GATE-1b
            try { if ($r = $this->send((int) $ws->id, $kind, $now, (bool) $force)) $sent[] = $ws->id . ':' . $kind; } catch (\Throwable $e) { Log::warning('[WATCH-1] check-in failed', ['ws' => $ws->id, 'kind' => $kind, 'e' => $e->getMessage()]); }
        }
        return $sent;
    }

    public static function prefs(int $wsId): string
    {
        $s = json_decode((string) (DB::table('workspaces')->where('id', $wsId)->value('settings_json') ?: '{}'), true) ?: [];
        return (string) ($s['sarah_checkins'] ?? 'on');   // on | no_night | off
    }

    public static function setPrefs(int $wsId, string $v): void
    {
        $raw = (string) (DB::table('workspaces')->where('id', $wsId)->value('settings_json') ?: '{}');
        $s = json_decode($raw, true) ?: [];
        $s['sarah_checkins'] = in_array($v, ['on', 'no_night', 'off'], true) ? $v : 'on';
        DB::table('workspaces')->where('id', $wsId)->update(['settings_json' => json_encode($s), 'updated_at' => now()]);
    }

    public function send(int $wsId, string $kind, Carbon $now, bool $force = false): ?int
    {
        $pref = self::prefs($wsId);
        if (! $force && ($pref === 'off' && $kind !== 'weekly_feedback')) return null;
        if (! $force && $pref === 'no_night' && $kind === 'night') return null;
        $date = $now->toDateString();
        if (DB::table('owner_checkins')->where('workspace_id', $wsId)->where('kind', $kind)->where('local_date', $date)->exists()) return null;
        // CHECKIN-2 (REPORT-0066 finding 9, 2026-09-30): one check-in a day, any kind, and never two within 20 hours (18:02 and 23:02 the same Sunday)
        if (! $force && $kind !== 'weekly_feedback' && DB::table('owner_checkins')->where('workspace_id', $wsId)->where('kind', '<>', 'weekly_feedback')
            ->where(fn ($q) => $q->where('local_date', $date)->orWhere('created_at', '>=', now()->subHours(20)))->exists()) return null;
        if (! $force) {
            // never interrupt: the owner is talking with Sarah right now, or Sarah just posted something
            if (DB::table('agent_messages')->where('workspace_id', $wsId)->where('role', 'user')->where('created_at', '>=', now()->subMinutes(90))->exists()) return null;
            if (DB::table('agent_messages')->where('workspace_id', $wsId)->where('agent_slug', 'sarah')->where('role', 'agent')->where('created_at', '>=', now()->subMinutes(60))->exists()) return null;
            // back off when the owner has not answered: three quiet check-ins → no night ones; six → only the weekly one
            $lastStatuses = DB::table('owner_checkins')->where('workspace_id', $wsId)->whereIn('status', ['answered', 'unanswered'])->orderByDesc('id')->limit(6)->pluck('status')->all();
            $quiet = 0; foreach ($lastStatuses as $s) { if ($s === 'unanswered') $quiet++; else break; }
            if ($quiet >= 6 && $kind !== 'weekly_feedback') return null;
            if ($quiet >= 3 && $kind === 'night') return null;
        }
        $facts = $this->facts($wsId, $now, $kind);
        [$instruction, $fallback] = $this->voice($kind, $facts);
        $text = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, 'checkin_' . $kind, $instruction, $facts, $fallback);
        $msgId = app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $text, ['notification_type' => 'sarah_checkin', 'checkin' => $kind]);
        DB::table('owner_checkins')->insertOrIgnore(['workspace_id' => $wsId, 'business_id' => null, 'kind' => $kind, 'local_date' => $date, 'message_id' => $msgId, 'question' => mb_substr($text, 0, 2000),
            'status' => 'asked', 'created_at' => now(), 'updated_at' => now()]);
        Log::info('[WATCH-1] check-in sent', ['ws' => $wsId, 'kind' => $kind]);
        return $msgId;
    }

    /** What Sarah may naturally refer to. Small things only: this is a conversation, not a report. */
    private function facts(int $wsId, Carbon $now, string $kind): array
    {
        $ws = DB::table('workspaces')->where('id', $wsId)->first(['name', 'business_name', 'created_by']);
        $owner = $ws->created_by ? (string) DB::table('users')->where('id', $ws->created_by)->value('name') : '';
        $first = trim(explode(' ', trim($owner))[0] ?? '');
        $dayStart = $now->copy()->startOfDay()->utc();
        $biz = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->pluck('name')->all();
        $journal = DB::table('business_journal')->where('workspace_id', $wsId)->orderByDesc('id')->limit(6)->get(['kind', 'text', 'created_at'])
            ->map(fn ($j) => $j->kind . ' (' . Carbon::parse($j->created_at)->diffForHumans() . '): ' . $j->text)->all();
        $recentQs = DB::table('owner_checkins')->where('workspace_id', $wsId)->orderByDesc('id')->limit(4)->pluck('question')->map(fn ($q) => mb_substr((string) $q, 0, 160))->all();
        $leadsToday = DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('created_at', '>=', $dayStart)->count();
        $needsYou = DB::table('campaign_items as i')->join('marketing_campaigns as c', 'c.id', '=', 'i.campaign_id')->where('i.workspace_id', $wsId)->where('i.status', 'needs_you')->where('c.status', 'active')->count();
        $active = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->where('status', 'active')->whereNull('deleted_at')->pluck('title')->all();
        $f = array_filter([
            'owner_first_name' => $first ?: null, 'businesses' => $biz ?: [$ws->business_name ?: $ws->name], 'local_time' => $now->format('l g:ia'),
            'today_small_news' => array_filter(['new_enquiries_today' => $leadsToday ?: null, 'steps_waiting_for_owner' => $needsYou ?: null]),
            'running_campaigns' => $active, 'what_owner_told_you_recently' => $journal, 'your_last_checkins_do_not_repeat' => $recentQs,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
        if ($kind === 'weekly_feedback') {
            $done = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->where('completed_at', '>=', now()->subDays(14))->get(['title', 'results_json'])
                ->map(fn ($c) => $c->title . ': ' . (json_decode((string) $c->results_json, true)['summary'] ?? ''))->all();
            $f['week'] = array_filter(['leads_this_week' => DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(7))->count(),
                'leads_week_before' => DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count(),
                'posts_published' => DB::table('social_posts')->where('workspace_id', $wsId)->where('status', 'published')->where('updated_at', '>=', now()->subDays(7))->count(),
                'campaigns_finished_recently' => $done], fn ($v) => $v !== [] && $v !== null);
            $f['last_satisfaction'] = DB::table('owner_checkins')->where('workspace_id', $wsId)->whereNotNull('satisfaction')->orderByDesc('id')->value('satisfaction');
        }
        return $f;
    }

    private function voice(string $kind, array $f): array
    {
        $name = $f['owner_first_name'] ?? '';
        $base = ' Speak like a trusted marketing manager messaging the business owner she works for: warm, natural, short. It is a conversation, not a report: no lists, no headings, no numbers dump, no emojis. '
            . 'You may mention ONE small thing from FACTS if it is genuinely interesting, or follow up on something the owner told you recently. Do not repeat the wording of your last check-ins. Use the owner\'s first name at most once. End with exactly one question.';
        return match ($kind) {
            'night' => ["Write Sarah's evening message (1-2 sentences) as the day winds down: ask how the day went, or whether anything is coming up this week she should plan around (an order, an event, a new product, a quiet spell)." . $base,
                'How did today go' . ($name ? ', ' . $name : '') . '? Anything coming up this week I should plan around?'],
            'weekly_feedback' => ["Write Sarah's end-of-week check (2-3 sentences). Honestly ask how the owner feels the marketing is going for the business: is it bringing the kind of customers they want, what they would like more or less of, and anything to change for next week. You may mention one real result from FACTS.week to anchor it. Make it easy to answer in their own words (they can also just say a score out of 5). Strategy-minded, humble, never defensive." . $base,
                'End of the week' . ($name ? ', ' . $name : '') . ' — how do you feel the marketing is going? Is it bringing the kind of customers you want, and what would you like more or less of next week? Even a score out of 5 helps me adjust.'],
            default => ["Write Sarah's afternoon check-in (1-2 sentences): ask how business is going today or what's new — customers, orders, anything happening. Curious and personal, like checking in with a boss she likes working for." . $base,
                'Hi' . ($name ? ' ' . $name : '') . ', how is business going today? Anything new I should know about?'],
        };
    }

    /** The owner's message after a check-in: is it an answer? */
    public function openFor(int $wsId): ?object
    {
        return DB::table('owner_checkins')->where('workspace_id', $wsId)->where('status', 'asked')->where('created_at', '>=', now()->subHours(12))->orderByDesc('id')->first();
    }

    /** Learn from the owner's answer: journal entries, satisfaction, and signals the reactor can act on. */
    public function absorb(int $wsId, int $checkinId, int $messageId): array
    {
        $ci = DB::table('owner_checkins')->where('id', $checkinId)->where('workspace_id', $wsId)->first();
        $msg = DB::table('agent_messages')->where('id', $messageId)->where('workspace_id', $wsId)->where('role', 'user')->first();
        if (! $ci || ! $msg || $ci->status !== 'asked') return ['absorbed' => false];
        $bizList = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name']);
        $sys = 'Sarah, a marketing manager, asked the business owner a check-in question and the owner replied. Extract what she should remember about the business and act on. Facts from the reply only; never invent. '
            . 'Return ONLY JSON {"is_answer":true|false,"business":"name if the reply is about one specific business, else empty","journal":[{"kind":"news|sales|customers|win|problem|plan|feedback|preference","text":"one short sentence in third person"}],'
            . '"satisfaction":null|1-5,"wants_more":[""],"wants_less":[""],"act":{"needed":true|false,"strength":1,"title":"what happened that Sarah could act on in marketing, one sentence"}}. '
            . 'is_answer is false if the reply ignores the question and asks for something else entirely. satisfaction only for a feedback question, 1 very unhappy .. 5 delighted, from the owner\'s words or score. act.needed when the reply gives a marketing opening or problem (slow week, new product, event coming, unhappy with results, a type of customer they want).';
        $user = 'QUESTION (' . $ci->kind . '): ' . $ci->question . "\nOWNER REPLY: " . mb_substr((string) $msg->content, 0, 2000) . "\nBUSINESSES: " . json_encode($bizList->pluck('name')->all(), JSON_UNESCAPED_UNICODE);
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, $user, ['task' => 'checkin_absorb', 'workspace_id' => (string) $wsId], 800);
        $p = (($r['success'] ?? false) && is_array($r['parsed'] ?? null)) ? $r['parsed'] : null;
        if (! $p) return ['absorbed' => false, 'reason' => 'llm'];
        if (empty($p['is_answer'])) return ['absorbed' => false, 'reason' => 'not an answer'];
        $bizId = null;
        $hint = mb_strtolower(trim((string) ($p['business'] ?? '')));
        if ($hint !== '') foreach ($bizList as $b) if (str_contains(mb_strtolower($b->name), $hint) || str_contains($hint, mb_strtolower($b->name))) { $bizId = SignalService::bizKey((int) $b->id); break; }
        $n = 0;
        foreach (array_slice((array) ($p['journal'] ?? []), 0, 6) as $j) {
            $t = trim((string) ($j['text'] ?? ''));
            if ($t === '') continue;
            $kind = in_array($j['kind'] ?? '', ['news', 'sales', 'customers', 'win', 'problem', 'plan', 'feedback', 'preference'], true) ? $j['kind'] : 'news';
            DB::table('business_journal')->insert(['workspace_id' => $wsId, 'business_id' => $bizId, 'kind' => $kind, 'text' => mb_substr($t, 0, 600), 'source' => $ci->kind === 'weekly_feedback' ? 'feedback' : 'checkin', 'message_id' => $messageId, 'created_at' => now(), 'updated_at' => now()]);
            $n++;
        }
        $sat = isset($p['satisfaction']) && is_numeric($p['satisfaction']) ? max(1, min(5, (int) $p['satisfaction'])) : null;
        if ($ci->kind !== 'weekly_feedback') $sat = null;
        $learned = array_filter(['wants_more' => array_values(array_filter((array) ($p['wants_more'] ?? []))), 'wants_less' => array_values(array_filter((array) ($p['wants_less'] ?? []))), 'journal_entries' => $n]);
        DB::table('owner_checkins')->where('id', $ci->id)->update(['status' => 'answered', 'answer_message_id' => $messageId, 'answered_at' => now(), 'satisfaction' => $sat, 'learned_json' => json_encode($learned, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        $sig = app(SignalService::class);
        if ($ci->kind === 'weekly_feedback') {
            $title = 'The owner\'s weekly feedback' . ($sat ? ' (' . $sat . '/5)' : '') . ': ' . mb_substr((string) $msg->content, 0, 220);
            $sig->emit($wsId, $bizId, 'owner', 'owner_feedback', $sat !== null && $sat <= 2 ? 3 : 2, $title, json_encode($learned, JSON_UNESCAPED_UNICODE), ['checkin_id' => (int) $ci->id, 'satisfaction' => $sat], 'feedback:' . $ci->id);
            try {
                app(\App\Core\Experience888\ExperienceRecorder::class)->recordEvent($wsId, 'OWNER_CORRECTION', ['actor_type' => 'owner', 'capability' => 'owner_satisfaction', 'action' => 'weekly_feedback',
                    'entity_type' => 'owner_checkin', 'entity_id' => (int) $ci->id, 'evidence_type' => 'owner_checkins', 'evidence_id' => (int) $ci->id, 'source_message_id' => $messageId, 'payload' => ['satisfaction' => $sat] + $learned]);
            } catch (\Throwable $e) {}
            if ($sat !== null && $sat <= 2) Log::warning('[WATCH-1] low owner satisfaction', ['ws' => $wsId, 'satisfaction' => $sat, 'checkin' => $ci->id]);
        } elseif (! empty($p['act']['needed']) && trim((string) ($p['act']['title'] ?? '')) !== '') {
            $sig->emit($wsId, $bizId, 'owner', 'owner_said', max(1, min(3, (int) ($p['act']['strength'] ?? 2))), mb_substr(trim((string) $p['act']['title']), 0, 280), mb_substr((string) $msg->content, 0, 600), ['checkin_id' => (int) $ci->id], 'said:' . $ci->id);
        }
        \App\Jobs\GrowthReactJob::dispatch($wsId, $bizId)->delay(now()->addSeconds(90));
        return ['absorbed' => true, 'journal' => $n, 'satisfaction' => $sat];
    }
}
