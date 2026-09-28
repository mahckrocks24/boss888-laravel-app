<?php

namespace App\Core\Growth;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WATCH-1 (RFC-0019): Sarah reacts to what happened. For each business with new signals she decides — like a marketing
 * manager, LLM first, from facts only — whether to:
 *   adjust   a running campaign (add, move or drop steps). The owner approves the change (Owner 2026-09-27: "upon
 *            approval for new or updated campaign"); nothing changes until they tap Approve.
 *   propose  a new, timely campaign — an idea card the owner launches.
 *   tell     the owner now, in her words (a complaint online, a competitor's promotion, a slow week).
 *   brief    keep it for the next morning brief.
 *   nothing  not worth anyone's attention (recorded with the reason, never shown).
 * Guards: quiet hours (21:00-08:00 local), at most three owner-facing reactions per business per day, one open change per
 * campaign, one proposal per business every three days, never the same signal twice. Every reaction is recorded in
 * Experience888 so Sarah learns which reactions the owner accepts and which pay off.
 */
final class SignalReactor
{
    private const OWNER_FACING_PER_DAY = 3;

    public function react(int $wsId, ?int $bizId): array
    {
        $ws = DB::table('workspaces')->where('id', $wsId)->first(['id', 'timezone', 'proactive_enabled', 'onboarded']);
        if (! $ws || ! $ws->onboarded) return ['reacted' => 0, 'reason' => 'not onboarded'];
        try { if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessSarah($wsId)) return ['reacted' => 0, 'reason' => 'plan without Sarah']; } catch (\Throwable $e) {}   // SARAH-GATE-1b
        $tz = (string) ($ws->timezone ?: 'UTC');
        try { $now = Carbon::now($tz); } catch (\Throwable $e) { $now = Carbon::now('UTC'); $tz = 'UTC'; }
        $q = DB::table('growth_signals')->where('workspace_id', $wsId)->where('status', 'new')->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'));
        $signals = (clone $q)->where(fn ($w) => $w->where('created_at', '<=', now()->subSeconds(60))->orWhere('strength', 3))->orderByDesc('strength')->orderBy('id')->limit(12)->get();
        if ($signals->isEmpty()) return ['reacted' => 0, 'reason' => 'no signals'];
        if (! $ws->proactive_enabled) {   // Sarah's proactive mode is off: nothing is pushed; the findings stay on the Market watch page
            DB::table('growth_signals')->whereIn('id', $signals->pluck('id'))->update(['status' => 'brief', 'decision' => 'brief', 'decision_note' => 'proactive mode off', 'handled_at' => now(), 'updated_at' => now()]);
            return ['reacted' => 0, 'reason' => 'proactive off'];
        }
        if ($now->hour < 8 || $now->hour >= 21) return ['reacted' => 0, 'reason' => 'quiet hours'];
        $lock = \Illuminate\Support\Facades\Cache::lock('growth-react:' . $wsId . ':' . ($bizId ?? 0), 240);
        if (! $lock->get()) return ['reacted' => 0, 'reason' => 'busy'];
        try {
            return $this->decide($wsId, $bizId, $signals, $now, $tz);
        } finally {
            optional($lock)->release();
        }
    }

    private function decide(int $wsId, ?int $bizId, $signals, Carbon $now, string $tz): array
    {
        $biz = app(\App\Core\Brand\BrandProfileService::class)->business($wsId, $bizId);
        $dayStart = $now->copy()->startOfDay()->utc();
        $ownerFacingToday = DB::table('growth_signals')->where('workspace_id', $wsId)->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'))
            ->whereIn('decision', ['adjust', 'propose', 'tell'])->where('handled_at', '>=', $dayStart)->distinct()->count('decision_note');
        $left = max(0, self::OWNER_FACING_PER_DAY - $ownerFacingToday);

        $campaigns = [];
        foreach (DB::table('marketing_campaigns')->where('workspace_id', $wsId)->whereIn('status', ['active', 'launching'])->whereNull('deleted_at')
            ->where(fn ($w) => $bizId ? $w->where('business_id', $bizId)->orWhereNull('business_id') : $w->whereNull('business_id'))->get() as $c) {
            $open = DB::table('campaign_changes')->where('campaign_id', $c->id)->where('status', 'proposed')->exists();
            $campaigns[] = ['id' => (int) $c->id, 'title' => $c->title, 'dates' => $c->starts_on . ' to ' . $c->ends_on, 'target' => json_decode((string) $c->kpi_json, true)['label'] ?? null,
                'change_already_waiting_for_owner' => $open,
                'remaining_steps' => DB::table('campaign_items')->where('campaign_id', $c->id)->where('status', 'planned')->orderBy('scheduled_at')->limit(12)->get(['id', 'kind', 'channel', 'scheduled_at', 'title'])
                    ->map(fn ($i) => ['item_id' => (int) $i->id, 'date' => Carbon::parse($i->scheduled_at)->setTimezone($tz)->toDateString(), 'kind' => $i->kind, 'channel' => $i->channel, 'title' => $i->title])->all(),
                'done_steps' => DB::table('campaign_items')->where('campaign_id', $c->id)->where('status', 'done')->count()];
        }
        $recentProposal = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->where('source', 'sarah_signal')->where('created_at', '>=', now()->subDays(3))->exists();
        $recent = DB::table('growth_signals')->where('workspace_id', $wsId)->whereIn('decision', ['adjust', 'propose', 'tell'])->where('handled_at', '>=', now()->subDays(7))
            ->orderByDesc('id')->limit(8)->get(['kind', 'title', 'decision', 'decision_note'])->map(fn ($s) => (array) $s)->all();
        $journal = DB::table('business_journal')->where('workspace_id', $wsId)->where(fn ($w) => $bizId ? $w->where('business_id', $bizId)->orWhereNull('business_id') : $w->whereNull('business_id'))
            ->orderByDesc('id')->limit(8)->get(['kind', 'text', 'created_at'])->map(fn ($j) => $j->kind . ' (' . substr((string) $j->created_at, 0, 10) . '): ' . $j->text)->all();
        $lessons = [];
        try { $lessons = mb_substr((string) app(\App\Core\Experience888\ExperienceRetriever::class)->forTurn($wsId, 'marketing campaign results', 1200), 0, 1200); } catch (\Throwable $e) {}

        $facts = [
            'today' => $now->toDateString() . ' (' . $now->format('l') . ')',
            'business' => array_filter(['name' => $biz->name ?? null, 'industry' => $biz->industry ?? null, 'location' => $biz->location ?? null, 'audience' => $biz->target_audience ?? null]),
            'signals' => $signals->map(fn ($s) => array_filter(['id' => (int) $s->id, 'from' => $s->source, 'kind' => $s->kind, 'strength' => (int) $s->strength, 'what' => $s->title, 'detail' => $s->detail,
                'owner_already_told' => (bool) (json_decode((string) $s->payload_json, true)['owner_already_told'] ?? false), 'campaign_id' => $s->campaign_id ? (int) $s->campaign_id : null]))->values()->all(),
            'running_campaigns' => $campaigns,
            'can_propose_new_campaign' => ! $recentProposal && ! \Illuminate\Support\Facades\Cache::has('campaign-ideas-pending:' . $wsId),
            'messages_to_owner_left_today' => $left,
            'what_you_did_recently' => $recent,
            'what_the_owner_told_you_lately' => $journal,
            'lessons' => $lessons,
        ];
        $sys = "You are Sarah, the owner's senior digital marketing manager. New things happened (SIGNALS). Decide what to do about them, the way a great marketing manager would: act on what can grow the business, stay quiet about noise, never nag.\n"
            . "Actions:\n"
            . "- adjust: change a RUNNING campaign's remaining steps because of a signal (do more of what is working, fix what is behind, ride a trend or local moment, answer a competitor). Give concrete changes. The owner will approve the change before anything happens. Only for a campaign in running_campaigns without change_already_waiting_for_owner.\n"
            . "- propose: a NEW campaign is the right answer (a clear moment or opening no running campaign covers). Only when can_propose_new_campaign is true. Give the idea in one sentence.\n"
            . "- tell: the owner should hear this now (a critical mention, a competitor promotion that could pull customers, a slow week, something great). Only when messages_to_owner_left_today > 0, and never for something marked owner_already_told.\n"
            . "- brief: worth mentioning in tomorrow's morning brief, not worth interrupting for.\n"
            . "- nothing: not useful.\n"
            . "Rules: facts only — never invent numbers, dates or events. Group related signals into one decision. At most one adjust per campaign and one propose. Prefer brief or nothing for weak signals. Do not repeat what_you_did_recently. "
            . "Changes: op add {kind: post|article|image|event|owner_task, channel: facebook|instagram|linkedin|website|in_person|email, date: YYYY-MM-DD within the campaign or up to 14 days after it ends, title, brief}; op move {item_id, date}; op drop {item_id}. Max 4 changes. Bulk email is not available: an email is an owner_task with the message to send.\n"
            . 'Return ONLY JSON {"decisions":[{"signal_ids":[1],"action":"adjust|propose|tell|brief|nothing","campaign_id":null,"changes":[],"idea":"","reason":"why, one plain sentence for the owner","message":"for tell: what you say to the owner, 1-3 warm sentences, no emojis"}]}';
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, 'FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['task' => 'growth_react', 'workspace_id' => (string) $wsId], 1800);
        $decisions = (($r['success'] ?? false) && is_array($r['parsed']['decisions'] ?? null)) ? $r['parsed']['decisions'] : null;
        if ($decisions === null) { Log::warning('[WATCH-1] reactor returned nothing', ['ws' => $wsId, 'err' => $r['error'] ?? null]); return ['reacted' => 0, 'reason' => 'llm']; }

        $valid = $signals->keyBy('id');
        $handled = [];
        $done = ['adjust' => 0, 'propose' => 0, 'tell' => 0, 'brief' => 0, 'nothing' => 0];
        $proposedOnce = false;
        foreach ($decisions as $d) {
            if (! is_array($d)) continue;
            $ids = array_values(array_filter(array_map('intval', (array) ($d['signal_ids'] ?? [])), fn ($id) => $valid->has($id) && ! in_array($id, $handled, true)));
            if (! $ids) continue;
            $action = in_array($d['action'] ?? '', ['adjust', 'propose', 'tell', 'brief', 'nothing'], true) ? $d['action'] : 'brief';
            $reason = mb_substr(trim((string) ($d['reason'] ?? '')), 0, 480);
            $ownerFacing = in_array($action, ['adjust', 'propose', 'tell'], true);
            if ($ownerFacing && $left <= 0) $action = 'brief';
            if ($action === 'tell' && collect($ids)->every(fn ($id) => (bool) (json_decode((string) $valid[$id]->payload_json, true)['owner_already_told'] ?? false))) $action = 'brief';
            $ok = false;
            try {
                if ($action === 'adjust') $ok = $this->proposeChange($wsId, (int) ($d['campaign_id'] ?? 0), $ids, (array) ($d['changes'] ?? []), $reason, $tz);
                elseif ($action === 'propose' && ! $proposedOnce && $facts['can_propose_new_campaign']) {
                    $idea = trim((string) ($d['idea'] ?? ''));
                    if ($idea !== '') {
                        \Illuminate\Support\Facades\Cache::put('campaign-ideas-pending:' . $wsId, 1, now()->addMinutes(4));
                        \App\Jobs\CampaignIdeasJob::dispatch($wsId, $bizId, 'sarah_signal', 'A timely opening Sarah spotted: ' . $idea . ($reason !== '' ? ' Why: ' . $reason : '') . ' Signals: ' . implode('; ', array_map(fn ($id) => $valid[$id]->title, $ids)), null, 1);
                        $ok = $proposedOnce = true;
                    }
                } elseif ($action === 'tell') {
                    $msg = trim((string) ($d['message'] ?? ''));
                    if ($msg !== '' && ! preg_match('/\b(D([1-9]|10)|prompt|signal_id|LLM|JSON)\b/', $msg)) {
                        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', mb_substr($msg, 0, 900), ['notification_type' => 'growth_alert', 'signal_ids' => $ids]);
                        $ok = true;
                    }
                } else $ok = true;
            } catch (\Throwable $e) { Log::warning('[WATCH-1] reaction failed', ['ws' => $wsId, 'action' => $action, 'e' => $e->getMessage()]); }
            if (! $ok) $action = 'brief';
            $note = mb_substr(($ownerFacing && $ok ? '#' . substr(sha1(json_encode($ids)), 0, 6) . ' ' : '') . $reason, 0, 500);
            DB::table('growth_signals')->whereIn('id', $ids)->update(['status' => $action === 'brief' ? 'brief' : ($action === 'nothing' ? 'ignored' : 'handled'), 'decision' => $action, 'decision_note' => $note ?: null, 'handled_at' => now(), 'updated_at' => now()]);
            if (in_array($action, ['adjust', 'propose', 'tell'], true)) { $left--; $this->experience($wsId, $ids[0], $action, $reason); }
            $handled = array_merge($handled, $ids);
            $done[$action]++;
        }
        // anything the model skipped waits for the brief
        $rest = $signals->pluck('id')->diff($handled)->values()->all();
        if ($rest) DB::table('growth_signals')->whereIn('id', $rest)->update(['status' => 'brief', 'decision' => 'brief', 'handled_at' => now(), 'updated_at' => now()]);
        Log::info('[WATCH-1] reacted', ['ws' => $wsId, 'biz' => $bizId, 'signals' => $signals->count()] + $done);
        return ['reacted' => count($handled)] + $done;
    }

    /** A change to a running campaign, shown to the owner as a card with Approve / Keep as is. */
    public function proposeChange(int $wsId, int $campaignId, array $signalIds, array $changes, string $reason, string $tz): bool
    {
        $c = DB::table('marketing_campaigns')->where('id', $campaignId)->where('workspace_id', $wsId)->whereIn('status', ['active', 'launching'])->first();
        if (! $c || DB::table('campaign_changes')->where('campaign_id', $campaignId)->where('status', 'proposed')->exists()) return false;
        $planner = \App\Core\Campaigns\CampaignPlanner::KINDS;
        $items = DB::table('campaign_items')->where('campaign_id', $campaignId)->get()->keyBy('id');
        $end = Carbon::parse($c->ends_on, $tz)->addDays(14);
        $clean = [];
        foreach (array_slice($changes, 0, 4) as $ch) {
            if (! is_array($ch)) continue;
            $op = $ch['op'] ?? '';
            if (in_array($op, ['move', 'drop'], true)) {
                $it = $items[(int) ($ch['item_id'] ?? 0)] ?? null;
                if (! $it || $it->status !== 'planned') continue;
                if ($op === 'drop') { $clean[] = ['op' => 'drop', 'item_id' => (int) $it->id, 'title' => $it->title]; continue; }
                try { $d = Carbon::parse((string) ($ch['date'] ?? ''), $tz); } catch (\Throwable $e) { continue; }
                if ($d->lt(Carbon::now($tz)->startOfDay()) || $d->gt($end)) continue;
                $clean[] = ['op' => 'move', 'item_id' => (int) $it->id, 'title' => $it->title, 'from' => Carbon::parse($it->scheduled_at)->setTimezone($tz)->toDateString(), 'date' => $d->toDateString()];
            } elseif ($op === 'add') {
                $kind = strtolower((string) ($ch['kind'] ?? ''));
                if ($kind === 'email') $kind = 'owner_task';
                if (! in_array($kind, $planner, true) || trim((string) ($ch['title'] ?? '')) === '') continue;
                try { $d = Carbon::parse((string) ($ch['date'] ?? ''), $tz); } catch (\Throwable $e) { continue; }
                if ($d->lt(Carbon::now($tz)->startOfDay())) $d = Carbon::now($tz)->addDay();
                if ($d->gt($end)) continue;
                $chan = strtolower((string) ($ch['channel'] ?? ''));
                if ($kind === 'post' && ! in_array($chan, ['facebook', 'instagram', 'linkedin'], true)) $chan = 'facebook';
                if ($kind === 'article') $chan = 'website';
                $clean[] = ['op' => 'add', 'kind' => $kind, 'channel' => $chan ?: 'in_person', 'date' => $d->toDateString(), 'title' => mb_substr(trim((string) $ch['title']), 0, 200), 'brief' => mb_substr(trim((string) ($ch['brief'] ?? '')), 0, 1200)];
            }
        }
        if (! $clean) return false;
        $extra = 0;
        try {
            $svc = app(\App\Core\Campaigns\CampaignService::class);
            $tasks = [];
            foreach ($clean as $ch) if ($ch['op'] === 'add' && ($t = $svc->planTask($c, (object) ['id' => 0, 'kind' => $ch['kind'], 'channel' => $ch['channel'], 'title' => $ch['title'], 'brief' => $ch['brief'], 'scheduled_at' => $ch['date'] . ' 10:00:00']))) $tasks[] = ['engine' => $t['engine'], 'action' => $t['action']];
            if ($tasks) $extra = (int) (app(\App\Core\Intelligence\ToolCostCalculatorService::class)->estimate($tasks)['total'] ?? 0);
        } catch (\Throwable $e) {}
        $id = DB::table('campaign_changes')->insertGetId(['campaign_id' => $campaignId, 'workspace_id' => $wsId, 'signal_ids_json' => json_encode($signalIds), 'reason' => $reason ?: 'Based on what happened this week.',
            'changes_json' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'extra_credits' => $extra, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
        $facts = ['campaign' => $c->title, 'why' => $reason, 'changes' => array_map(fn ($x) => $x['op'] . ': ' . $x['title'] . (isset($x['date']) ? ' on ' . $x['date'] : ''), $clean), 'extra_credits' => $extra];
        $fallback = 'Something changed that affects "' . $c->title . '". ' . ($reason ?: '') . ' I would like to update the plan; nothing changes until you approve.';
        $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, 'campaign_change',
            "Write Sarah's short chat message (2-3 sentences) to the owner: what happened (from FACTS.why) and that she suggests an update to the campaign, listed right after your message; nothing changes until they approve. Do not mention a card or buttons. Warm, direct, no emojis, no lists.",
            $facts, $fallback);
        // CHAT-FIRST-1: the change in words, so the companion app shows everything the web card shows
        $tz2 = $tz;
        $words .= \App\Core\Growth\ChatReplies::APP_PART . implode("\n", array_map(function ($x) use ($tz2) {
            $d = isset($x['date']) ? \Carbon\Carbon::parse($x['date'], $tz2)->format('D j M') : '';
            return match ($x['op']) { 'add' => '• Add: ' . $x['title'] . ' (' . $d . ')', 'move' => '• Move: ' . $x['title'] . ' to ' . $d, default => '• Drop: ' . $x['title'] };
        }, $clean)) . ($extra ? "\nUp to " . (int) ceil($extra * 1.25) . ' extra credits.' : '') . "\n\nReply **approve** or **keep as is**.";
        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, ['notification_type' => 'campaign_change', 'campaign_id' => $campaignId,
            'card' => ['type' => 'campaign_change', 'change_id' => $id, 'campaign_id' => $campaignId, 'campaign_title' => $c->title, 'reason' => $reason, 'changes' => $clean, 'extra_credits' => $extra]]);
        return true;
    }

    /** The owner approves Sarah's change: it is applied under the campaign's existing plan, with the extra spend added to its ceiling. */
    public function applyChange(int $wsId, int $changeId, int $userId, bool $announce = true): array
    {
        $ch = DB::table('campaign_changes')->where('id', $changeId)->where('workspace_id', $wsId)->first();
        if (! $ch) return ['success' => false, 'error' => 'Not found.'];
        if ($ch->status !== 'proposed') return ['success' => false, 'error' => 'This change was already ' . $ch->status . '.'];
        $c = DB::table('marketing_campaigns')->where('id', $ch->campaign_id)->first();
        if (! $c || ! in_array($c->status, ['active', 'launching', 'paused'], true)) return ['success' => false, 'error' => 'The campaign is no longer running.'];
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        $maxPhase = (int) DB::table('campaign_items')->where('campaign_id', $c->id)->max('phase_order');
        $lastDate = Carbon::parse($c->ends_on, $tz);
        DB::transaction(function () use ($ch, $c, $tz, $maxPhase, &$lastDate) {
            foreach (json_decode((string) $ch->changes_json, true) ?: [] as $x) {
                if ($x['op'] === 'drop') DB::table('campaign_items')->where('id', $x['item_id'])->where('campaign_id', $c->id)->where('status', 'planned')->update(['status' => 'skipped', 'note' => 'Dropped in Sarah\'s update', 'updated_at' => now()]);
                elseif ($x['op'] === 'move') DB::table('campaign_items')->where('id', $x['item_id'])->where('campaign_id', $c->id)->where('status', 'planned')->update(['scheduled_at' => Carbon::parse($x['date'] . ' 10:00', $tz)->utc(), 'updated_at' => now()]);
                elseif ($x['op'] === 'add') {
                    DB::table('campaign_items')->insert(['campaign_id' => $c->id, 'workspace_id' => $c->workspace_id, 'phase' => 'Sarah\'s update', 'phase_order' => min(255, $maxPhase + 1), 'kind' => $x['kind'], 'channel' => $x['channel'],
                        'title' => $x['title'], 'brief' => $x['brief'] ?: null, 'scheduled_at' => Carbon::parse($x['date'] . ' 10:00', $tz)->utc(), 'status' => 'planned', 'sort' => 900, 'created_at' => now(), 'updated_at' => now()]);
                }
                if (isset($x['date']) && Carbon::parse($x['date'], $tz)->gt($lastDate)) $lastDate = Carbon::parse($x['date'], $tz);
            }
            if ($lastDate->toDateString() > (string) $c->ends_on) DB::table('marketing_campaigns')->where('id', $c->id)->update(['ends_on' => $lastDate->toDateString(), 'updated_at' => now()]);
            // the approved extra spend raises the plan's ceiling; the plan runs long enough for the new dates
            if ($c->mandate_id) {
                $m = DB::table('mandates')->where('id', $c->mandate_id)->first();
                if ($m) {
                    $b = json_decode((string) $m->boundaries_json, true) ?: [];
                    $b['spend_ceiling_credits'] = (int) ($b['spend_ceiling_credits'] ?? 0) + (int) ceil(((int) $ch->extra_credits) * 1.25);
                    $meta = json_decode((string) ($m->meta_json ?? '{}'), true) ?: []; $meta['changes'][] = ['change_id' => (int) $ch->id, 'at' => now()->toIso8601String()];
                    $u = ['boundaries_json' => json_encode($b), 'meta_json' => json_encode($meta), 'updated_at' => now()];
                    $needEnd = $lastDate->copy()->addDays(14)->endOfDay()->utc();
                    if ($m->ends_at && Carbon::parse($m->ends_at)->lt($needEnd)) $u['ends_at'] = $needEnd;
                    DB::table('mandates')->where('id', $m->id)->update($u);
                }
            }
            DB::table('campaign_changes')->where('id', $ch->id)->update(['status' => 'applied', 'decided_by' => null, 'decided_at' => now(), 'updated_at' => now()]);
        });
        DB::table('campaign_changes')->where('id', $ch->id)->update(['decided_by' => $userId]);
        $svc = app(\App\Core\Campaigns\CampaignService::class);
        DB::table('marketing_campaigns')->where('id', $c->id)->update(['credit_estimate' => $svc->estimate((int) $c->id)]);
        $svc->syncCalendar((int) $c->id);
        $this->ownerDecision($wsId, $ch, true);
        if ($announce) $this->say($wsId, 'campaign_change_done', "Write Sarah's one-line chat message confirming the owner approved her update to the campaign in FACTS and it is applied; the new steps are on the calendar. No emojis.", ['campaign' => $c->title], 'Done — "' . $c->title . '" is updated and the new steps are on the calendar.');
        return ['success' => true];
    }

    public function declineChange(int $wsId, int $changeId, int $userId, bool $announce = true): bool
    {
        $ch = DB::table('campaign_changes')->where('id', $changeId)->where('workspace_id', $wsId)->where('status', 'proposed')->first();
        if (! $ch) return false;
        DB::table('campaign_changes')->where('id', $changeId)->update(['status' => 'declined', 'decided_by' => $userId, 'decided_at' => now(), 'updated_at' => now()]);
        $this->ownerDecision($wsId, $ch, false);
        if ($announce) $this->say($wsId, 'campaign_change_kept', "Write Sarah's one-line chat message acknowledging the owner kept the campaign in FACTS as it is; she will learn from it. No emojis.", ['campaign' => (string) DB::table('marketing_campaigns')->where('id', $ch->campaign_id)->value('title')], 'Understood — I have kept the campaign as it is and will learn from that.');
        return true;
    }

    /** CHAT-FIRST-1: whatever the owner does on the web also lands in Sarah's chat, in her words. */
    private function say(int $wsId, string $task, string $instruction, array $facts, string $fallback): void
    {
        try { app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, $task, $instruction, $facts, $fallback), ['notification_type' => $task]); } catch (\Throwable $e) {}
    }

    private function ownerDecision(int $wsId, object $ch, bool $accepted): void
    {
        try {
            $rec = app(\App\Core\Experience888\ExperienceRecorder::class);
            $rec->recordEvent($wsId, $accepted ? 'RECOMMENDATION_ACCEPTED' : 'RECOMMENDATION_REJECTED', ['actor_type' => 'owner', 'capability' => 'growth_watch', 'action' => 'campaign_change',
                'entity_type' => 'marketing_campaign', 'entity_id' => (int) $ch->campaign_id, 'evidence_type' => 'campaign_changes', 'evidence_id' => (int) $ch->id, 'payload' => ['reason' => $ch->reason]]);
        } catch (\Throwable $e) {}
    }

    private function experience(int $wsId, int $signalId, string $action, string $reason): void
    {
        try {
            app(\App\Core\Experience888\ExperienceRecorder::class)->recordEvent($wsId, 'RECOMMENDATION_ISSUED', ['actor_type' => 'agent', 'actor_id' => 'sarah', 'capability' => 'growth_watch', 'action' => 'react_' . $action,
                'entity_type' => 'growth_signal', 'entity_id' => $signalId, 'evidence_type' => 'growth_signals', 'evidence_id' => $signalId, 'payload' => ['reason' => $reason]]);
        } catch (\Throwable $e) {}
    }
}
