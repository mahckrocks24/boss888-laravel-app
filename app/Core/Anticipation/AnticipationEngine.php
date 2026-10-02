<?php

namespace App\Core\Anticipation;

use App\Core\OwnerModel\OwnerModelService;
use App\Core\Repair\RepairService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0023 P5: Sarah proposes before being asked. Deterministic candidates from what the system already knows -
 *   calendar     an upcoming date that fits the business and its country; an active campaign about to end; a posting cadence slipping
 *   opportunity  a delivered piece that brought enquiries (do more of this); a keyword within reach of page one (RFC-0020)
 *   risk         a social connection expiring or unhealthy
 *   readiness    a draft Sarah can prepare ahead of a predictable moment ("ready when you are")
 *   question     the specific question the Owner Model has for this owner (goal progress, an open want, an unconfirmed guess) - DEC-0073 D4
 * One card at a time, in local daytime, never inside a repair cool-down, never while the owner is mid-conversation, at most one
 * every 48 h and three a week per workspace. Every card carries confidence, cost and evidence; a "yes" is the approval (D2
 * draft-and-ask): the action runs through TaskService and still obeys its own approval rules. Acceptance per kind per owner
 * reweights what gets proposed next; a declined key rests 30 days. Kill switch storage/app/anticipate1.on (and memory1.on).
 */
class AnticipationEngine
{
    public const MIN_GAP_HOURS = 48;
    public const MAX_PER_WEEK = 3;
    public const LOCAL_HOURS = [9, 19];      // inclusive start, exclusive end
    public const EXPIRES_DAYS = 7;
    private const COST = ['social_create_post' => 4, 'write_article' => 3, 'generate_image' => 4];

    public function __construct(private OwnerModelService $model) {}

    public static function enabled(): bool { return file_exists(storage_path('app/anticipate1.on')) && file_exists(storage_path('app/memory1.on')); }

    /** Scheduled hourly. Returns ws:key for every card posted. */
    public function scan(?int $onlyWs = null, bool $force = false): array
    {
        if (! self::enabled() && ! $force) return [];
        $posted = [];
        // unanswered proposals expire quietly and count as "ignored" for the acceptance score
        DB::table('anticipation_proposals')->where('status', 'proposed')->where('expires_at', '<', now())->update(['status' => 'ignored', 'updated_at' => now()]);
        $q = DB::table('workspaces')->where('onboarded', 1)->where('proactive_enabled', 1);
        if ($onlyWs) $q = DB::table('workspaces')->where('id', $onlyWs);
        foreach ($q->get(['id', 'timezone']) as $ws) {
            try {
                $wsId = (int) $ws->id;
                if (! $force && ! $this->mayPropose($wsId, $ws->timezone ?: 'UTC')) continue;
                $best = $this->pick($wsId, null);
                if (! $best) continue;
                $id = $this->post($wsId, $best['business_id'] ?? null, $best);
                if ($id) $posted[] = $wsId . ':' . $best['key'];
            } catch (\Throwable $e) { Log::warning('[ANTICIPATE] scan failed', ['ws' => $ws->id, 'e' => $e->getMessage()]); }
        }
        return $posted;
    }

    /** Candidates ranked by confidence x acceptance prior; the first that is not resting. */
    public function pick(int $wsId, ?int $bizId): ?array
    {
        if ($bizId !== null) { $cands = array_map(fn ($c) => $c + ['business_id' => $bizId], $this->candidates($wsId, $bizId)); }
        else {
            // every business in the workspace (RFC-0011: many profiles, one workspace), default first; one key once
            // the business the owner has been working on lately comes first, so a shared key lands on the right one
            $bizIds = DB::table('businesses as b')->where('b.workspace_id', $wsId)->whereNull('b.deleted_at')
                ->orderByRaw('(select count(*) from tasks t where t.business_id = b.id and t.created_at >= date_sub(now(), interval 30 day)) desc')->orderByDesc('b.is_default')->orderBy('b.id')->limit(6)->pluck('b.id')->map(fn ($i) => (int) $i)->all() ?: [null];
            $cands = []; $seen = [];
            foreach ($bizIds as $b) foreach ($this->candidates($wsId, $b) as $c) { if (isset($seen[$c['key']])) continue; $seen[$c['key']] = true; $cands[] = $c + ['business_id' => $b]; }
        }
        if (! $cands) return null;
        $prior = $this->acceptancePrior($wsId);
        usort($cands, fn ($a, $b) => ($b['confidence'] * ($prior[$b['kind']] ?? 0.5)) <=> ($a['confidence'] * ($prior[$a['kind']] ?? 0.5)));
        foreach ($cands as $c) {
            $prev = DB::table('anticipation_proposals')->where('workspace_id', $wsId)->where('key', $c['key'])->first(['status', 'decided_at', 'created_at']);
            if (! $prev) return $c;
            if ($prev->status === 'declined' && strtotime((string) $prev->decided_at) < time() - 30 * 86400) { DB::table('anticipation_proposals')->where('workspace_id', $wsId)->where('key', $c['key'])->delete(); return $c; }
        }
        return null;
    }

    /** @return array<int,array{kind:string,key:string,title:string,body:string,confidence:float,cost:int,evidence:array,action:array}> */
    public function candidates(int $wsId, ?int $bizId): array
    {
        $out = [];
        $biz = $bizId ? DB::table('businesses')->where('id', $bizId)->first() : null;
        $name = (string) ($biz->name ?? DB::table('workspaces')->where('id', $wsId)->value('business_name') ?: 'your business');
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        $today = Carbon::now($tz)->startOfDay();
        $industry = strtolower((string) ($biz->industry ?? ''));
        $food = (bool) preg_match('/chef|restaurant|bakery|cafe|catering|food|dining|bar|pastry|coffee/', $industry);
        $hasSocial = DB::table('social_accounts')->where('workspace_id', $wsId)->where('status', 'connected')->exists() || DB::table('social_posts')->where('workspace_id', $wsId)->exists();

        // calendar: upcoming dates for the country, 5-28 days ahead, that fit the business
        foreach ($this->dates($tz, (int) $today->year) as $d) {
            $days = $today->diffInDays(Carbon::parse($d['date'], $tz), false);
            if ($days < 5 || $days > 28) continue;
            if (($d['fit'] ?? 'all') === 'food' && ! $food) continue;
            $post = preg_match('/season/i', $d['name']) ? 'a post for the ' . lcfirst($d['name']) : (preg_match('/^[aeiou]/i', $d['name']) ? 'an ' : 'a ') . $d['name'] . ' post';
            $out[] = ['kind' => 'calendar', 'key' => 'calendar:' . $d['slug'] . ':' . substr($d['date'], 0, 4), 'confidence' => $d['conf'] + ($food && ($d['fit'] ?? '') === 'food' ? 0.15 : 0),
                'title' => $d['name'] . ' is on ' . Carbon::parse($d['date'])->format('j F') . ' - ' . $post . ' for ' . $name . '?',
                'body' => ucfirst($d['name']) . ' is ' . $days . ' days away. I can have ' . $post . ' for ' . $name . ' drafted this week, so it is ready to go out in good time' . ($d['angle'] ? ' - ' . $d['angle'] : '') . '.',
                'cost' => self::COST['social_create_post'], 'evidence' => ['date' => $d['date'], 'days_away' => $days, 'country' => $d['country']],
                'action' => ['type' => 'task', 'engine' => 'social', 'action' => 'social_create_post', 'params' => ['platform' => 'instagram', 'topic' => $d['name'] . ' post for ' . $name . ($d['angle'] ? ': ' . $d['angle'] : ''), 'title' => $d['name'] . ' - ' . $name, 'created_via' => 'anticipation']]];
        }

        // calendar: an active campaign ends within 3 days
        foreach (DB::table('marketing_campaigns')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'active')->whereNotNull('ends_on')->whereBetween('ends_on', [$today->toDateString(), $today->copy()->addDays(3)->toDateString()])->get(['id', 'title', 'ends_on']) as $c) {
            $out[] = ['kind' => 'calendar', 'key' => 'campaign_end:' . $c->id, 'confidence' => 0.7, 'title' => '"' . $c->title . '" ends on ' . Carbon::parse($c->ends_on)->format('j F') . ' - plan what follows?',
                'body' => 'Your campaign "' . $c->title . '" finishes on ' . Carbon::parse($c->ends_on)->format('l j F') . '. Shall I put together what comes next, built on what this one produced?',
                'cost' => 0, 'evidence' => ['campaign_id' => $c->id, 'ends_on' => $c->ends_on],
                'action' => ['type' => 'reply_note', 'note' => 'The owner said yes to planning what follows the campaign "' . $c->title . '". Propose three campaign ideas now, each with its objective, dates and credit estimate, grounded in that campaign\'s results from your memory; nothing launches until they pick one.']];
        }

        // cadence slipping: posts used to go out, none for 10+ days
        if ($hasSocial) {
            $last = DB::table('social_posts')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'published')->max('published_at');
            $before = DB::table('social_posts')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'published')->whereBetween('published_at', [now()->subDays(40), now()->subDays(10)])->count();
            if ($last && $before >= 2 && strtotime((string) $last) < time() - 10 * 86400) {
                $days = (int) floor((time() - strtotime((string) $last)) / 86400);
                $out[] = ['kind' => 'calendar', 'key' => 'cadence:' . $today->format('o\wW'), 'confidence' => 0.6, 'title' => 'Nothing has gone out for ' . $days . ' days - a post this week?',
                    'body' => 'Your last post went out ' . $days . ' days ago, after a steady run before that. Want me to draft one for this week so the page does not go quiet?',
                    'cost' => self::COST['social_create_post'], 'evidence' => ['last_published_at' => $last, 'posts_30d_before' => $before],
                    'action' => ['type' => 'task', 'engine' => 'social', 'action' => 'social_create_post', 'params' => ['platform' => 'instagram', 'topic' => 'A fresh post for ' . $name . ' this week, in the voice and themes of the recent ones', 'title' => 'This week - ' . $name, 'created_via' => 'anticipation']]];
            }
        }

        // opportunity: a delivered piece that brought enquiries within a week
        foreach (DB::table('outcome_ledger')->where('workspace_id', $wsId)->when($bizId, fn ($q) => $q->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id')))->where('status', 'delivered')->whereNotNull('result_7d_json')->where('delivered_at', '>=', now()->subDays(60))->orderByDesc('delivered_at')->limit(30)->get() as $r) {
            $res = json_decode((string) $r->result_7d_json, true) ?: []; $p = json_decode((string) $r->planned_json, true) ?: [];
            $leads = (int) ($res['leads'] ?? 0); $prior = (int) ($res['leads_prior'] ?? $res['leads_before'] ?? 0);
            if ($leads >= 2 && $leads > $prior && in_array($r->kind, ['post', 'article'], true)) {
                $what = mb_substr((string) ($p['what'] ?? $r->kind), 0, 90);
                $action = $r->kind === 'article' ? 'write_article' : 'social_create_post';
                $out[] = ['kind' => 'opportunity', 'key' => 'more_of:' . $r->id, 'confidence' => 0.75, 'title' => 'Your ' . $r->kind . ' "' . $what . '" brought ' . $leads . ' enquiries in a week - another in the same vein?',
                    'body' => 'The ' . $r->kind . ' "' . $what . '" brought ' . $leads . ' enquiries in its first week, against ' . $prior . ' the week before. That is worth repeating: shall I prepare another on the same theme?',
                    'cost' => self::COST[$action], 'evidence' => ['ledger_id' => $r->id, 'leads_7d' => $leads, 'prior' => $prior],
                    'action' => ['type' => 'task', 'engine' => $action === 'write_article' ? 'write' : 'social', 'action' => $action, 'params' => ['topic' => 'A follow-up to "' . $what . '", same theme and audience', 'title' => 'More like: ' . $what, 'platform' => 'instagram', 'created_via' => 'anticipation']]];
                break;
            }
        }

        // opportunity: a keyword within reach of page one
        foreach (DB::table('seo_keywords')->where('workspace_id', $wsId)->whereBetween('current_rank', [11, 20])->where('rank_change', '>', 0)->where('last_rank_check', '>=', now()->subDays(14))->orderBy('current_rank')->limit(1)->get() as $k) {
            $out[] = ['kind' => 'opportunity', 'key' => 'rank_reach:' . $k->id . ':' . $today->format('o\wW'), 'confidence' => 0.65, 'title' => '"' . $k->keyword . '" is at #' . $k->current_rank . ' - within reach of page one',
                'body' => '"' . $k->keyword . '" has moved up to #' . $k->current_rank . ' (from #' . $k->previous_rank . '). One well-aimed article could carry it onto page one. Shall I write it?',
                'cost' => self::COST['write_article'], 'evidence' => ['keyword_id' => $k->id, 'rank' => $k->current_rank, 'previous' => $k->previous_rank],
                'action' => ['type' => 'task', 'engine' => 'write', 'action' => 'write_article', 'params' => ['topic' => $k->keyword, 'title' => $k->keyword, 'target_keyword' => $k->keyword, 'page_one' => 1, 'created_via' => 'anticipation']]];
        }

        // risk: a social connection expiring or unhealthy
        foreach (DB::table('social_accounts')->where('workspace_id', $wsId)->where('status', 'connected')->where(fn ($q) => $q->whereIn('health_state', ['expired', 'error', 'unhealthy', 'needs_reconnect', 'revoked', 'failing'])->orWhere(fn ($w) => $w->whereNotNull('token_expires_at')->where('token_expires_at', '<', now()->addDays(7))))->whereNotNull('platform')->limit(1)->get() as $a) {
            $exp = $a->token_expires_at ? Carbon::parse($a->token_expires_at)->format('j F') : null;
            $out[] = ['kind' => 'risk', 'key' => 'social_health:' . $a->id . ':' . $today->format('o\wW'), 'confidence' => 0.8, 'title' => 'Your ' . ucfirst((string) $a->platform) . ' connection needs attention',
                'body' => 'Your ' . ucfirst((string) $a->platform) . ' connection ' . ($exp && strtotime((string) $a->token_expires_at) < time() + 7 * 86400 ? 'expires on ' . $exp : 'is reporting a problem (' . ($a->health_detail ?: $a->health_state) . ')') . '. Reconnecting it under Social takes a minute and keeps the scheduled posts going. Want me to hold the next posts until it is back?',
                'cost' => 0, 'evidence' => ['account_id' => $a->id, 'health' => $a->health_state, 'expires' => $a->token_expires_at], 'action' => ['type' => 'reply_note', 'note' => 'The owner asked you to hold the scheduled ' . $a->platform . ' posts until the connection is reconnected. Say so in one line and remind them where to reconnect (Social).']];
        }

        // question (D4): the Owner Model asks something specific instead of "how was your day"
        $facts = DB::table('owner_model_facts')->where('workspace_id', $wsId)->whereIn('status', ['confirmed', 'proposed'])->where('group', '<>', 'relationship')->orderBy('id')->get();
        $week = $today->format('o\wW');
        foreach ($facts as $f) {
            if ($f->group === 'goals' && $f->status === 'confirmed' && strtotime((string) ($f->last_confirmed_at ?: $f->created_at)) < time() - 7 * 86400) {
                $out[] = ['kind' => 'question', 'key' => 'question:goal:' . $f->id . ':' . $week, 'confidence' => 0.55, 'title' => 'How is "' . mb_substr((string) $f->value, 0, 60) . '" tracking?',
                    'body' => 'You told me your goal is ' . lcfirst(rtrim((string) $f->value, '.')) . '. How is it tracking so far this month - ahead, on pace, or behind? Even a rough number helps me aim the next campaign at it.',
                    'cost' => 0, 'evidence' => ['fact_id' => $f->id], 'action' => ['type' => 'reply_note', 'note' => 'The owner answered your question about their goal "' . $f->value . '". Note the number they gave as progress, thank them in one line, and say what you will adjust because of it.']];
                break;
            }
            if ($f->group === 'wants' && $f->status === 'confirmed' && strtotime((string) $f->created_at) < time() - 7 * 86400) {
                $out[] = ['kind' => 'question', 'key' => 'question:want:' . $f->id . ':' . $week, 'confidence' => 0.6, 'title' => 'Still want: ' . mb_substr((string) $f->value, 0, 60) . '?',
                    'body' => 'A while back you mentioned you wanted ' . lcfirst(rtrim((string) $f->value, '.')) . '. Is that still on your mind? If so I can start on it now.',
                    'cost' => 0, 'evidence' => ['fact_id' => $f->id], 'action' => ['type' => 'reply_note', 'note' => 'The owner confirmed they still want: "' . $f->value . '". Start on it now (create the task with its cost) and say so in one line.']];
                break;
            }
            if ($f->status === 'proposed' && (float) $f->confidence >= 0.6) {
                $out[] = ['kind' => 'question', 'key' => 'question:confirm:' . $f->id, 'confidence' => 0.45, 'title' => 'Did I get this right: ' . mb_substr((string) $f->value, 0, 60) . '?',
                    'body' => 'Something I think I noticed, and want to check rather than assume: ' . lcfirst(rtrim((string) $f->value, '.')) . '. Is that right?',
                    'cost' => 0, 'evidence' => ['fact_id' => $f->id], 'action' => ['type' => 'confirm_fact', 'fact_id' => (int) $f->id]];
                break;
            }
        }
        return $out;
    }

    /** The owner's answer. Returns a note for Sarah's reply. */
    public function decide(int $wsId, int $proposalId, bool $accepted, ?int $userId, string $text = ''): ?string
    {
        $p = DB::table('anticipation_proposals')->where('id', $proposalId)->where('workspace_id', $wsId)->where('status', 'proposed')->first();
        if (! $p) return null;
        $action = json_decode((string) $p->action_json, true) ?: ['type' => 'none'];
        DB::table('anticipation_proposals')->where('id', $p->id)->update(['status' => $accepted ? 'accepted' : 'declined', 'decided_at' => now(), 'updated_at' => now()]);
        $this->model->observe($wsId, $p->business_id ? (int) $p->business_id : null, $accepted ? 'anticipation_accepted' : 'anticipation_declined', 'anticipation:' . $p->id, ['kind' => $p->kind, 'key' => $p->key, 'cost' => $p->cost_credits]);
        $this->model->event($wsId, 'anticipation_decided', ['id' => $p->id, 'kind' => $p->kind, 'accepted' => $accepted, 'hours_to_decide' => (int) round((time() - strtotime((string) $p->created_at)) / 3600)]);
        if (! $accepted) {
            if ($action['type'] === 'confirm_fact') { try { $this->model->dismiss($wsId, [(int) $action['fact_id']]); } catch (\Throwable) {} }
            return 'The owner said not now to your suggestion "' . $p->title . '". One short line, no selling, no second attempt; carry on with whatever else they said.';
        }
        switch ($action['type']) {
            case 'task':
                try {
                    $params = ($action['params'] ?? []) + array_filter(['business_id' => $p->business_id ? (int) $p->business_id : null]);
                    $params['description'] = $params['description'] ?? $p->title;
                    $task = app(\App\Core\TaskSystem\TaskService::class)->create($wsId, array_filter(['engine' => $action['engine'], 'action' => $action['action'], 'payload' => $params, 'source' => 'agent', 'credit_cost' => (int) $p->cost_credits, 'business_id' => $p->business_id ? (int) $p->business_id : null], fn ($v) => $v !== null));
                    DB::table('anticipation_proposals')->where('id', $p->id)->update(['result_task_id' => $task->id, 'status' => 'done', 'updated_at' => now()]);
                    return 'The owner said yes to your suggestion "' . $p->title . '": the work is created (task ' . $task->id . ', ' . (int) $p->cost_credits . ' credits' . (! empty($task->requires_approval) ? ', waiting for their approval card' : '') . '). Confirm in one line and say when it will be ready; do not re-explain the idea.';
                } catch (\Throwable $e) {
                    DB::table('anticipation_proposals')->where('id', $p->id)->update(['status' => 'failed', 'updated_at' => now()]);
                    Log::warning('[ANTICIPATE] action failed', ['id' => $p->id, 'e' => $e->getMessage()]);
                    return 'The owner said yes to your suggestion "' . $p->title . '" but creating the work failed (' . mb_substr($e->getMessage(), 0, 120) . '). Say so plainly in one line and offer to try again.';
                }
            case 'confirm_fact':
                try { $this->model->confirm($wsId, [(int) $action['fact_id']]); } catch (\Throwable) {}
                DB::table('anticipation_proposals')->where('id', $p->id)->update(['status' => 'done', 'updated_at' => now()]);
                return 'The owner confirmed what you noticed ("' . $p->title . '"). One short line of thanks, then carry on.';
            case 'reply_note':
                DB::table('anticipation_proposals')->where('id', $p->id)->update(['status' => 'done', 'updated_at' => now()]);
                return (string) ($action['note'] ?? 'The owner said yes to "' . $p->title . '". Act on it now and say what you did.') . ($text !== '' ? ' Their exact words: "' . mb_substr($text, 0, 200) . '".' : '');
            default:
                DB::table('anticipation_proposals')->where('id', $p->id)->update(['status' => 'done', 'updated_at' => now()]);
                return 'The owner said yes to "' . $p->title . '". Acknowledge in one line.';
        }
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function mayPropose(int $wsId, string $tz): bool
    {
        try { if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessSarah($wsId)) return false; } catch (\Throwable) {}   // SARAH-GATE-2
        try { $h = Carbon::now($tz)->hour; } catch (\Throwable) { $h = Carbon::now('UTC')->hour; }
        if ($h < self::LOCAL_HOURS[0] || $h >= self::LOCAL_HOURS[1]) return false;
        if (RepairService::cooldownActive($wsId)) return false;
        if (Cache::has('campaign-ideas-pending:' . $wsId)) return false;
        if (DB::table('anticipation_proposals')->where('workspace_id', $wsId)->where('status', 'proposed')->exists()) return false;
        if (DB::table('anticipation_proposals')->where('workspace_id', $wsId)->where('created_at', '>=', now()->subHours(self::MIN_GAP_HOURS))->exists()) return false;
        if (DB::table('anticipation_proposals')->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(7))->count() >= self::MAX_PER_WEEK) return false;
        // the same interrupt rules as check-ins: never while the owner is talking or Sarah has just posted; never over an open card
        if (DB::table('agent_messages')->where('workspace_id', $wsId)->where('role', 'user')->where('created_at', '>=', now()->subMinutes(90))->exists()) return false;
        if (DB::table('agent_messages')->where('workspace_id', $wsId)->where('agent_slug', 'sarah')->where('role', 'agent')->where('created_at', '>=', now()->subMinutes(60))->exists()) return false;
        try { if (app(\App\Core\Growth\ChatReplies::class)->openQuestion($wsId)) return false; } catch (\Throwable) {}
        // two ignored in a row: wait a week before the next
        $last2 = DB::table('anticipation_proposals')->where('workspace_id', $wsId)->orderByDesc('id')->limit(2)->pluck('status', 'created_at');
        if ($last2->count() === 2 && $last2->every(fn ($s) => $s === 'ignored') && strtotime((string) $last2->keys()->first()) > time() - 7 * 86400) return false;
        return true;
    }

    /** Laplace acceptance rate per kind for this owner; ignored counts as a soft no. */
    private function acceptancePrior(int $wsId): array
    {
        $out = [];
        foreach (DB::table('anticipation_proposals')->where('workspace_id', $wsId)->whereIn('status', ['accepted', 'done', 'declined', 'ignored', 'failed'])->selectRaw("kind, sum(status in ('accepted','done','failed')) yes, sum(status = 'declined') no, sum(status = 'ignored') ign")->groupBy('kind')->get() as $r) {
            $out[$r->kind] = ((int) $r->yes + 1) / ((int) $r->yes + (int) $r->no + 0.5 * (int) $r->ign + 2);
        }
        return $out;
    }

    private function post(int $wsId, ?int $bizId, array $c): ?int
    {
        $words = $c['body'] . ' ' . ($c['cost'] > 0 ? 'This would use ' . $c['cost'] . ' credits and nothing happens until you say yes.' : 'No credits involved.') . ' Reply **yes** or **not now**.';
        $id = (int) DB::table('anticipation_proposals')->insertGetId(['workspace_id' => $wsId, 'business_id' => $bizId, 'kind' => $c['kind'], 'key' => $c['key'], 'title' => mb_substr($c['title'], 0, 160), 'body' => $words,
            'evidence_json' => json_encode($c['evidence'], JSON_UNESCAPED_UNICODE), 'confidence' => round(min(1, $c['confidence']), 2), 'cost_credits' => (int) $c['cost'], 'action_json' => json_encode($c['action'], JSON_UNESCAPED_UNICODE),
            'status' => 'proposed', 'expires_at' => now()->addDays(self::EXPIRES_DAYS), 'created_at' => now(), 'updated_at' => now()]);
        $msgId = app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, ['notification_type' => 'anticipation', 'card' => ['type' => 'anticipation', 'proposal_id' => $id, 'kind' => $c['kind'], 'cost' => (int) $c['cost'], 'business_id' => $bizId]]);
        DB::table('anticipation_proposals')->where('id', $id)->update(['message_id' => $msgId]);
        $this->model->observe($wsId, $bizId, 'anticipation_proposed', 'anticipation:' . $id, ['kind' => $c['kind'], 'key' => $c['key'], 'confidence' => $c['confidence'], 'cost' => $c['cost']]);
        $this->model->event($wsId, 'anticipation_proposed', ['id' => $id, 'kind' => $c['kind'], 'key' => $c['key'], 'confidence' => $c['confidence'], 'cost' => $c['cost']]);
        Log::info('[ANTICIPATE] proposed', ['ws' => $wsId, 'id' => $id, 'key' => $c['key']]);
        return $msgId;
    }

    /** Dates by country (from the timezone), this year and next. Variable-date holidays computed; lunar ones omitted. */
    private function dates(string $tz, int $year): array
    {
        $country = match (true) { str_starts_with($tz, 'America/') => 'US', $tz === 'Asia/Manila' => 'PH', in_array($tz, ['Asia/Dubai', 'Asia/Muscat', 'Asia/Riyadh', 'Asia/Qatar', 'Asia/Bahrain', 'Asia/Kuwait'], true) => 'GCC', str_starts_with($tz, 'Europe/London') => 'UK', str_starts_with($tz, 'Australia/') => 'AU', default => 'ALL' };
        $out = [];
        foreach ([$year, $year + 1] as $y) {
            $nth = function (int $m, int $dow, int $n) use ($y) { $d = Carbon::create($y, $m, 1); if ($d->dayOfWeek !== $dow) $d = $d->next($dow); return $d->addWeeks($n - 1)->toDateString(); };
            $all = [
                ['slug' => 'valentines', 'name' => "Valentine's Day", 'date' => "$y-02-14", 'conf' => 0.6, 'fit' => 'all', 'angle' => 'a dinner-for-two or a gift angle', 'c' => ['ALL']],
                ['slug' => 'mothers-day', 'name' => "Mother's Day", 'date' => $nth(5, Carbon::SUNDAY, 2), 'conf' => 0.65, 'fit' => 'all', 'angle' => 'treat mum', 'c' => ['US', 'PH', 'AU', 'ALL']],
                ['slug' => 'mothers-day-uk', 'name' => "Mother's Day", 'date' => Carbon::parse("$y-03-21")->subDays(21)->next(Carbon::SUNDAY)->toDateString(), 'conf' => 0.6, 'fit' => 'all', 'angle' => 'treat mum', 'c' => ['UK']],
                ['slug' => 'fathers-day', 'name' => "Father's Day", 'date' => $nth(6, Carbon::SUNDAY, 3), 'conf' => 0.55, 'fit' => 'all', 'angle' => 'treat dad', 'c' => ['US', 'PH', 'UK', 'ALL']],
                ['slug' => 'halloween', 'name' => 'Halloween', 'date' => "$y-10-31", 'conf' => 0.5, 'fit' => 'all', 'angle' => 'something playful and seasonal', 'c' => ['US', 'UK', 'AU', 'PH', 'ALL']],
                ['slug' => 'thanksgiving', 'name' => 'Thanksgiving', 'date' => $nth(11, Carbon::THURSDAY, 4), 'conf' => 0.7, 'fit' => 'all', 'angle' => 'gratitude to your customers, or a Thanksgiving table offer', 'c' => ['US']],
                ['slug' => 'black-friday', 'name' => 'Black Friday', 'date' => Carbon::parse($nth(11, Carbon::THURSDAY, 4))->addDay()->toDateString(), 'conf' => 0.55, 'fit' => 'all', 'angle' => 'one clear offer with a deadline', 'c' => ['US', 'UK', 'AU', 'GCC', 'ALL']],
                ['slug' => 'christmas', 'name' => 'Christmas', 'date' => "$y-12-25", 'conf' => 0.75, 'fit' => 'all', 'angle' => 'bookings, gifts or a festive menu, announced early', 'c' => ['US', 'UK', 'AU', 'PH', 'ALL']],
                ['slug' => 'new-year', 'name' => "New Year's Eve", 'date' => "$y-12-31", 'conf' => 0.6, 'fit' => 'all', 'angle' => 'a celebration or a fresh-start message', 'c' => ['US', 'UK', 'AU', 'PH', 'GCC', 'ALL']],
                ['slug' => 'july-4', 'name' => 'Independence Day', 'date' => "$y-07-04", 'conf' => 0.5, 'fit' => 'all', 'angle' => 'a summer gathering angle', 'c' => ['US']],
                ['slug' => 'ph-independence', 'name' => 'Independence Day', 'date' => "$y-06-12", 'conf' => 0.5, 'fit' => 'all', 'angle' => 'a proudly local angle', 'c' => ['PH']],
                ['slug' => 'undas', 'name' => 'Undas', 'date' => "$y-11-01", 'conf' => 0.5, 'fit' => 'all', 'angle' => 'family gatherings and comfort food', 'c' => ['PH']],
                ['slug' => 'ber-months', 'name' => 'Christmas season (Ber months)', 'date' => "$y-09-01", 'conf' => 0.55, 'fit' => 'all', 'angle' => 'early holiday bookings', 'c' => ['PH']],
                ['slug' => 'uae-national-day', 'name' => 'National Day', 'date' => "$y-12-02", 'conf' => 0.6, 'fit' => 'all', 'angle' => 'a celebration angle', 'c' => ['GCC']],
                ['slug' => 'autumn-dining', 'name' => 'Autumn dining season', 'date' => "$y-10-15", 'conf' => 0.4, 'fit' => 'food', 'angle' => 'a seasonal menu or tasting evening', 'c' => ['US', 'UK', 'ALL']],
            ];
            foreach ($all as $d) { if (in_array($country, $d['c'], true) || ($country === 'ALL' && in_array('ALL', $d['c'], true))) { $d['country'] = $country; unset($d['c']); $out[] = $d; } }
        }
        return $out;
    }
}
