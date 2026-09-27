<?php

namespace App\Core\Growth;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WATCH-1 (RFC-0019): one stream of things that happened, from inside the business (results) and from the world.
 * Owner 2026-09-27: "her actions must also be triggered not only by schedule but also events triggered by the results
 * and or what's happening in the world". Signals are facts only; the SignalReactor decides what to do.
 *
 * Inside signals are collected every few minutes from the authoritative tables (leads, buying comments, search ranks,
 * search traffic, campaign pace, steps waiting on the owner). Each has a dedupe key, so a signal is raised once.
 */
final class SignalService
{
    /** The default business owns the "no business" rows (as brand profiles do): its id is stored as null. */
    public static function bizKey(?int $id): ?int
    {
        static $defaults = [];
        if (! $id) return null;
        if (! array_key_exists($id, $defaults)) $defaults[$id] = (bool) DB::table('businesses')->where('id', $id)->value('is_default');
        return $defaults[$id] ? null : $id;
    }

    public function emit(int $wsId, ?int $bizId, string $source, string $kind, int $strength, string $title, ?string $detail, array $payload, string $key, ?int $campaignId = null): ?int
    {
        $bizId = self::bizKey($bizId);
        $ok = DB::table('growth_signals')->insertOrIgnore([
            'workspace_id' => $wsId, 'business_id' => $bizId, 'campaign_id' => $campaignId, 'source' => $source, 'kind' => $kind, 'strength' => max(1, min(3, $strength)),
            'title' => mb_substr($title, 0, 300), 'detail' => $detail !== null ? mb_substr($detail, 0, 2000) : null, 'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'dedupe_key' => mb_substr($key, 0, 120), 'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $ok) return null;
        return (int) DB::table('growth_signals')->where('workspace_id', $wsId)->where('dedupe_key', mb_substr($key, 0, 120))->value('id');
    }

    /** The business a website belongs to (leads carry a website, not a business). */
    private function siteBiz(int $wsId): array
    {
        return DB::table('websites')->where('workspace_id', $wsId)->whereNotNull('business_id')->pluck('business_id', 'id')->map(fn ($b) => (int) $b)->all();
    }

    /** @return array<int|string,int> new signals per business key ('' = default business) */
    public function collect(int $wsId): array
    {
        $made = [];
        $add = function (?int $biz, ?int $id) use (&$made) { if ($id) { $k = $biz ?? 0; $made[$k] = ($made[$k] ?? 0) + 1; } };
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        try { $now = Carbon::now($tz); } catch (\Throwable $e) { $now = Carbon::now('UTC'); }
        $sites = $this->siteBiz($wsId);

        // new leads since the last sweep, grouped per business (one signal per batch)
        try {
            $last = (int) (DB::table('growth_signals')->where('workspace_id', $wsId)->where('kind', 'new_leads')->max(DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.max_id')) AS UNSIGNED)")) ?? 0);
            if ($last === 0) $last = (int) (DB::table('leads')->where('workspace_id', $wsId)->where('created_at', '<', now()->subHours(6))->max('id') ?? 0);
            $rows = DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('id', '>', $last)->orderBy('id')->limit(200)->get(['id', 'website_id', 'source', 'name', 'city']);
            foreach ($rows->groupBy(fn ($l) => $sites[(int) $l->website_id] ?? 0) as $biz => $group) {
                $bySrc = $group->groupBy(fn ($l) => $l->source ?: 'unknown')->map->count()->all();
                $n = $group->count();
                $add($biz ?: null, $this->emit($wsId, $biz ?: null, 'inside', 'new_leads', $n >= 3 ? 2 : 1, $n . ' new lead' . ($n === 1 ? '' : 's') . ' (' . implode(', ', array_map(fn ($s, $c) => $c . ' from ' . str_replace('_', ' ', $s), array_keys($bySrc), $bySrc)) . ')',
                    null, ['count' => $n, 'by_source' => $bySrc, 'max_id' => (int) $group->max('id')], 'leads:' . $group->max('id')));
            }
        } catch (\Throwable $e) { Log::info('[WATCH-1] leads signal skipped', ['ws' => $wsId, 'e' => $e->getMessage()]); }

        // a quiet week: no leads for 7 days when the business normally gets some
        try {
            $week = DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(7))->count();
            $base = DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereBetween('created_at', [now()->subDays(35), now()->subDays(7)])->count() / 4;
            if ($week === 0 && $base >= 2) $add(null, $this->emit($wsId, null, 'inside', 'leads_quiet', 2, 'No new leads in the last 7 days (usually about ' . round($base) . ' a week)', null, ['baseline_per_week' => round($base, 1)], 'quiet:' . $now->format('o-W')));
        } catch (\Throwable $e) {}

        // a buying comment on the business's own posts (the owner was already told by the comment desk; Sarah uses it for campaigns)
        try {
            foreach (DB::table('social_comments')->where('workspace_id', $wsId)->where('intent', 'hot')->where('created_at', '>=', now()->subDays(2))->limit(20)->get(['id', 'business_id', 'author_name', 'message', 'post_excerpt', 'campaign_id']) as $c) {
                $add($c->business_id ? (int) $c->business_id : null, $this->emit($wsId, $c->business_id ? (int) $c->business_id : null, 'inside', 'buying_comment', 2,
                    'A buying comment from ' . ($c->author_name ?: 'someone') . ': "' . mb_substr((string) $c->message, 0, 140) . '"', $c->post_excerpt ? 'On the post: ' . mb_substr((string) $c->post_excerpt, 0, 200) : null,
                    ['comment_id' => (int) $c->id, 'owner_already_told' => true], 'hotc:' . $c->id, $c->campaign_id ? (int) $c->campaign_id : null));
            }
        } catch (\Throwable $e) {}

        // search rank moves into or out of the first page
        try {
            $hist = DB::table('keyword_rank_history as h')->join('seo_keywords as k', 'k.id', '=', 'h.keyword_id')->where('h.workspace_id', $wsId)->where('h.captured_at', '>=', now()->subDays(3))
                ->orderByDesc('h.id')->limit(50)->get(['h.id', 'h.keyword_id', 'h.rank', 'h.captured_at', 'k.keyword']);
            foreach ($hist as $h) {
                $prev = DB::table('keyword_rank_history')->where('keyword_id', $h->keyword_id)->where('id', '<', $h->id)->orderByDesc('id')->value('rank');
                if ($prev === null || $h->rank === null) continue;
                $p = (int) $prev; $c = (int) $h->rank;
                $in = $c > 0 && $c <= 10 && ($p === 0 || $p > 10); $out = $p > 0 && $p <= 10 && ($c === 0 || $c > 10);
                if (! $in && ! $out && abs($c - $p) < 8) continue;
                $add(null, $this->emit($wsId, null, 'inside', 'rank_move', $in || $out ? 2 : 1, '"' . $h->keyword . '" moved from position ' . ($p ?: 'unranked') . ' to ' . ($c ?: 'unranked') . ' on Google', null,
                    ['keyword' => $h->keyword, 'from' => $p, 'to' => $c], 'rank:' . $h->id));
            }
        } catch (\Throwable $e) {}

        // search traffic this week vs last week (Search Console), once a week
        try {
            $a = (int) DB::table('gsc_metrics')->where('workspace_id', $wsId)->whereBetween('date', [now()->subDays(9)->toDateString(), now()->subDays(3)->toDateString()])->sum('clicks');
            $b = (int) DB::table('gsc_metrics')->where('workspace_id', $wsId)->whereBetween('date', [now()->subDays(16)->toDateString(), now()->subDays(10)->toDateString()])->sum('clicks');
            if (max($a, $b) >= 20 && $b > 0 && abs($a - $b) / $b >= 0.3) {
                $up = $a > $b;
                $add(null, $this->emit($wsId, null, 'inside', 'traffic_move', 1, 'Visits from Google ' . ($up ? 'rose' : 'fell') . ' ' . round(abs($a - $b) / $b * 100) . '% week on week (' . $b . ' to ' . $a . ' clicks)', null,
                    ['this_week' => $a, 'last_week' => $b], 'traffic:' . $now->format('o-W')));
            }
        } catch (\Throwable $e) {}

        // campaign checkpoints: at the halfway point and each full week, is the campaign on pace for its target?
        try {
            $svc = app(\App\Core\Campaigns\CampaignService::class);
            foreach (DB::table('marketing_campaigns')->where('workspace_id', $wsId)->where('status', 'active')->whereNull('deleted_at')->get() as $c) {
                $start = Carbon::parse($c->starts_on, $tz)->startOfDay(); $end = Carbon::parse($c->ends_on, $tz)->endOfDay();
                $total = max(1, (int) round(abs($start->diffInDays($end))) + 1); $elapsed = (int) floor(abs($start->diffInDays($now, false)));
                if ($now->lt($start) || $elapsed < 3) continue;
                $checkpoint = $elapsed >= intdiv($total, 2) && $elapsed < intdiv($total, 2) + 1 ? 'half' : ($elapsed % 7 === 0 ? 'w' . intdiv($elapsed, 7) : null);
                if (! $checkpoint) continue;
                $res = $svc->results($c);
                $kpi = json_decode((string) $c->kpi_json, true) ?: [];
                $target = (int) ($kpi['target'] ?? 0); $actual = $res['kpi_actual'] ?? null;
                $pace = ($target > 0 && $actual !== null) ? $actual / max(0.01, $target * min(1, $elapsed / $total)) : null;
                $state = $pace === null ? 'unknown' : ($pace < 0.6 ? 'behind' : ($pace > 1.3 ? 'ahead' : 'on_track'));
                if ($state === 'on_track' || $state === 'unknown') {
                    $add($c->business_id ? (int) $c->business_id : null, $this->emit($wsId, $c->business_id ? (int) $c->business_id : null, 'inside', 'campaign_pace', 1, '"' . $c->title . '" checkpoint: ' . ($state === 'on_track' ? 'on track' : 'no result measure yet') . ' — ' . $res['summary'], null,
                        ['campaign_id' => (int) $c->id, 'state' => $state, 'elapsed_days' => $elapsed, 'total_days' => $total, 'results' => $res], 'pace:' . $c->id . ':' . $checkpoint, (int) $c->id));
                    continue;
                }
                $add($c->business_id ? (int) $c->business_id : null, $this->emit($wsId, $c->business_id ? (int) $c->business_id : null, 'inside', 'campaign_pace', 2,
                    '"' . $c->title . '" is ' . ($state === 'behind' ? 'behind' : 'ahead of') . ' its target: ' . $actual . ' of ' . $target . ' ' . ($kpi['metric'] ?? 'leads') . ' after ' . $elapsed . ' of ' . $total . ' days', $res['summary'],
                    ['campaign_id' => (int) $c->id, 'state' => $state, 'elapsed_days' => $elapsed, 'total_days' => $total, 'results' => $res], 'pace:' . $c->id . ':' . $checkpoint, (int) $c->id));
            }
        } catch (\Throwable $e) { Log::info('[WATCH-1] pace skipped', ['ws' => $wsId, 'e' => $e->getMessage()]); }

        // a campaign step has waited on the owner for two days
        try {
            foreach (DB::table('campaign_items as i')->join('marketing_campaigns as c', 'c.id', '=', 'i.campaign_id')->where('i.workspace_id', $wsId)->where('i.status', 'needs_you')->where('i.updated_at', '<', now()->subHours(48))
                ->where('c.status', 'active')->limit(10)->get(['i.id', 'i.title', 'i.kind', 'c.id as cid', 'c.title as ctitle', 'c.business_id']) as $it) {
                $add($it->business_id ? (int) $it->business_id : null, $this->emit($wsId, $it->business_id ? (int) $it->business_id : null, 'inside', 'waiting_on_owner', 1, '"' . $it->title . '" in "' . $it->ctitle . '" has waited for the owner for two days', null,
                    ['item_id' => (int) $it->id, 'kind' => $it->kind], 'wait:' . $it->id, (int) $it->cid));
            }
        } catch (\Throwable $e) {}

        return $made;
    }
}
