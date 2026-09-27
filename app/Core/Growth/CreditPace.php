<?php

namespace App\Core\Growth;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PACE-1 (Owner 2026-09-28): Sarah watches how fast each paying business uses its monthly credits and says something
 * useful about it, in her chat (and so the companion app):
 *   under  $99 and up, weekly (Monday): at this pace ~N credits go unused this month — shall I make the plan more
 *          aggressive (two articles a week, posting more often)? A yes becomes a campaign idea to launch.
 *   over   any paid plan, when the pace runs out before the renewal: at this pace your credits run out in about N days
 *          (around DATE, renewal DATE) — want to move up to PLAN? With the plan's real figures.
 *   nudge  the entry plan, once a month mid-cycle: what the next plans make possible each month, in posts, videos and
 *          articles — capacity, never a promised result.
 * Figures come from the credit ledger and the plans table; nothing is estimated from outside data.
 */
final class CreditPace
{
    private const POST = 3;    // a social post with its designed banner (post 1 + image 2)
    private const VIDEO = 8;   // a short brand video

    /** @return array{credits:int, used:int, balance:int, days_in:int, days_left:int, per_day:float, runs_out_in:?int, unused:int, renews_on:string, plan:object}|null */
    public function pace(int $wsId): ?array
    {
        $sub = DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')->where('s.workspace_id', $wsId)->whereIn('s.status', ['active', 'trialing'])
            ->where('p.price', '>', 0)->where('p.credit_limit', '>', 0)->orderByDesc('s.id')->first(['p.id', 'p.name', 'p.slug', 'p.price', 'p.credit_limit', 's.starts_at']);
        if (! $sub) return null;
        $alloc = DB::table('credit_transactions')->where('workspace_id', $wsId)->where('reference_type', 'plan/allocation')->orderByDesc('id')->value('created_at');
        $start = Carbon::parse($alloc ?: ($sub->starts_at ?: now()->startOfMonth()));
        while ($start->copy()->addMonth()->lte(now())) $start->addMonth();   // the current monthly cycle
        $end = $start->copy()->addMonth();
        $daysIn = max(1, (int) floor($start->diffInDays(now())));
        $daysLeft = max(0, (int) ceil(now()->diffInDays($end)));
        $used = app(\App\Core\Billing\CreditService::class)->workspaceUsage($wsId, $start);
        $bal = (int) floor((float) (DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0) - (float) (DB::table('credits')->where('workspace_id', $wsId)->value('reserved_balance') ?? 0));
        // the recent pace counts most: the last 14 days (or the cycle so far when shorter)
        $window = min(14, $daysIn);
        $recent = (int) DB::table('credit_transactions')->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays($window))->where(fn ($q) => $q->where('type', 'commit')->orWhere(fn ($x) => $x->where('type', 'reserve')->where('reservation_status', 'pending')))->sum('amount');
        $perDay = $recent / max(1, $window);
        return ['credits' => (int) $sub->credit_limit, 'used' => $used, 'balance' => max(0, $bal), 'days_in' => $daysIn, 'days_left' => $daysLeft, 'per_day' => round($perDay, 2),
            'runs_out_in' => $perDay > 0 ? (int) floor(max(0, $bal) / $perDay) : null, 'unused' => max(0, (int) round($bal - $perDay * $daysLeft)), 'renews_on' => $end->toDateString(), 'plan' => $sub];
    }

    /** What a plan makes possible in a month — capacity, not results. */
    public static function capacity(int $credits): array
    {
        return ['posts_with_banner' => intdiv($credits, self::POST), 'videos' => intdiv($credits, self::VIDEO), 'weekly_article' => 'included',
            'example_month' => $credits >= 900 ? 'a designed post every day, two short videos a week and extra articles' : ($credits >= 300 ? 'a designed post every weekday and a short video every week' : 'about three designed posts a week')];
    }

    /** Hourly: each workspace is looked at once a day at 11:00 its local time. @return array{under:int, over:int, nudge:int} */
    public function tick(?int $onlyWs = null, bool $force = false): array
    {
        $n = ['under' => 0, 'over' => 0, 'nudge' => 0];
        $q = DB::table('workspaces');
        if ($onlyWs) $q->where('id', $onlyWs);
        foreach ($q->get(['id', 'timezone']) as $ws) {
            try {
                $tz = (string) ($ws->timezone ?: 'UTC'); try { new \DateTimeZone($tz); } catch (\Throwable $e) { $tz = 'UTC'; }
                $local = Carbon::now($tz);
                if (! $force && $local->hour !== 11) continue;
                $p = $this->pace((int) $ws->id);
                if (! $p || $p['days_in'] < 4) continue;
                $price = (float) $p['plan']->price;
                $next = DB::table('plans')->where('is_public', 1)->where('price', '>', $price)->where('credit_limit', '>', $p['credits'])->orderBy('price')->first(['name', 'price', 'credit_limit']);
                // over: the credits run out well before the renewal
                if ($p['runs_out_in'] !== null && $p['runs_out_in'] + 2 < $p['days_left'] && ($force || Cache::add('pace-over:' . $ws->id, 1, now()->addDays(7)))) {
                    $this->over((int) $ws->id, $p, $next, $tz); $n['over']++; continue;
                }
                // under: $99 and up, weekly on Monday
                if ($price >= 99 && ($force || $local->isMonday()) && $p['unused'] >= max(50, (int) round(0.3 * $p['credits'])) && ($force || Cache::add('pace-under:' . $ws->id, 1, now()->addDays(6)))) {
                    $this->under((int) $ws->id, $p, $tz); $n['under']++; continue;
                }
                // nudge: the entry plan, once a month around day 10 of the cycle
                if ($price < 99 && $next && ($force || ($p['days_in'] >= 9 && $p['days_in'] <= 12)) && ($force || Cache::add('pace-nudge:' . $ws->id . ':' . $p['renews_on'], 1, now()->addDays(35)))) {
                    $this->nudge((int) $ws->id, $p, $tz); $n['nudge']++;
                }
            } catch (\Throwable $e) { Log::info('[PACE-1] failed', ['ws' => $ws->id, 'e' => $e->getMessage()]); }
        }
        return $n;
    }

    private function over(int $wsId, array $p, ?object $next, string $tz): void
    {
        $out = now()->addDays((int) $p['runs_out_in'])->setTimezone($tz)->format('l j M');
        $facts = ['plan' => $p['plan']->name, 'credits_left' => $p['balance'], 'using_per_day' => $p['per_day'], 'runs_out_around' => $out, 'renews_on' => Carbon::parse($p['renews_on'])->setTimezone($tz)->format('l j M'),
            'next_plan' => $next ? ['name' => $next->name, 'price_per_month' => '$' . (int) $next->price, 'credits' => (int) $next->credit_limit, 'makes_possible' => self::capacity((int) $next->credit_limit)] : null, 'plans_link' => 'https://levelupgrowth.io/pricing/'];
        $fallback = 'At the pace we are going, your credits will run out around ' . $out . ', before they renew on ' . $facts['renews_on'] . '.' . ($next ? ' Want to move up to ' . $next->name . ' ($' . (int) $next->price . '/month, ' . (int) $next->credit_limit . ' credits) so nothing pauses? https://levelupgrowth.io/pricing/' : '');
        $this->say($wsId, 'credit_pace', "Write Sarah's short chat message (2-3 sentences) to the owner: good news that the business is busy — at the current pace their credits run out around FACTS.runs_out_around, before they renew on FACTS.renews_on, which would pause the work. If FACTS.next_plan is given, suggest moving up to it (name, price, credits, and one concrete line of what it makes possible each month from makes_possible) and include the link. They change the plan themselves on the plans page — never offer to switch it for them. Warm, helpful, not salesy, no emojis.",
            $facts, $fallback, ['kind' => 'over', 'next_plan' => $next->name ?? null]);
    }

    private function under(int $wsId, array $p, string $tz): void
    {
        $facts = ['plan' => $p['plan']->name, 'credits_per_month' => $p['credits'], 'likely_unused_this_month' => $p['unused'], 'renews_on' => Carbon::parse($p['renews_on'])->setTimezone($tz)->format('l j M'),
            'what_the_spare_credits_could_do' => ['posts_with_banner' => intdiv($p['unused'], self::POST), 'videos' => intdiv($p['unused'], self::VIDEO)]];
        $fallback = 'At this pace about ' . $p['unused'] . ' of your ' . $p['credits'] . ' credits will go unused before they renew. Would you like me to make the plan more ambitious — two articles a week and posting more often on social? Say yes and I will draw it up.';
        $this->say($wsId, 'credit_pace', "Write Sarah's short chat message (2-3 sentences) to the owner: at the current pace about FACTS.likely_unused_this_month of their monthly credits will go unused before they renew on FACTS.renews_on. Offer to make the plan more ambitious — for example two articles a week for Google and posting more often on social (use what_the_spare_credits_could_do for one concrete line). Ask if they want her to draw it up. Warm, proactive, no emojis.",
            $facts, $fallback, ['kind' => 'under', 'unused' => $p['unused']]);
    }

    private function nudge(int $wsId, array $p, string $tz): void
    {
        $plans = DB::table('plans')->where('is_public', 1)->where('price', '>', (float) $p['plan']->price)->where('credit_limit', '>', 0)->orderBy('price')->limit(2)->get(['name', 'price', 'credit_limit']);
        if (! $plans->count()) return;
        $facts = ['current_plan' => ['name' => $p['plan']->name, 'credits' => $p['credits'], 'makes_possible' => self::capacity($p['credits'])],
            'bigger_plans' => $plans->map(fn ($x) => ['name' => $x->name, 'price_per_month' => '$' . (int) $x->price, 'credits' => (int) $x->credit_limit, 'makes_possible' => self::capacity((int) $x->credit_limit)])->all(), 'plans_link' => 'https://levelupgrowth.io/pricing/'];
        $a = $plans[0];
        $fallback = 'A thought for next month: on ' . $a->name . ' ($' . (int) $a->price . ') you get ' . (int) $a->credit_limit . ' credits — enough for up to ' . intdiv((int) $a->credit_limit, self::POST) . ' designed posts a month, plus your weekly article. More regular posting and more articles give Google and your followers more to find. Want to move up? https://levelupgrowth.io/pricing/';
        $this->say($wsId, 'plan_nudge', "Write Sarah's short chat message (3-4 sentences) to the owner: businesses grow faster when they show up more often — more posts, more videos, more articles for Google (say this as general marketing sense, never as a statistic or a promise). Compare what their current plan makes possible each month with the bigger plans in FACTS (name, price, credits, one concrete line each from makes_possible). Invite them to look at the plans (they change it themselves on that page — never offer to switch it for them), with the link. Warm, confident, no pressure, no emojis.",
            $facts, $fallback, ['kind' => 'nudge']);
    }

    private function say(int $wsId, string $type, string $instruction, array $facts, string $fallback, array $card): void
    {
        $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, $type, $instruction, $facts, $fallback);
        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, ['notification_type' => $type, 'card' => ['type' => $type] + $card]);
    }

    /** The owner said yes to "make it more ambitious": Sarah drafts it as campaign ideas to launch. */
    public function intensify(int $wsId, ?int $userId, int $unused): void
    {
        \App\Jobs\CampaignIdeasJob::dispatch($wsId, null, 'sarah_chat', 'The owner wants a more ambitious plan to use about ' . $unused . ' spare credits this month: two website articles a week for Google (built to reach page one) and posting more often on social with designed banners, plus a short video where motion helps. Plan it over the rest of this month.')->delay(now()->addSeconds(5));
    }
}
