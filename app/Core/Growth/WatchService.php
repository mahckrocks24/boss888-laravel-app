<?php

namespace App\Core\Growth;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WATCH-1 (RFC-0019): Sarah keeps an eye on the world around each business — trends and local moments, competitors,
 * and what people say about the business online — on the schedule the owner approves ONCE, until the owner (or Sarah)
 * stops it. Owner 2026-09-27: "only if granted permission, sarah should proactively ask user how often does the user
 * want her to monitor then it gets added on her cron, one approval only unless stopped which she should be able to
 * stop as well."
 *
 * Every finding becomes a growth signal; the reactor decides what, if anything, to do about it. Research spends the
 * customer's credits (web search 2, page read 1), which is why nothing runs without the owner's yes.
 */
final class WatchService
{
    public const FREQUENCIES = ['daily' => 1, 'twice_weekly' => 3, 'weekly' => 7];   // days between runs (twice a week ~ every 3-4 days)
    private const RUNS_PER_WEEK = ['daily' => 7, 'twice_weekly' => 2, 'weekly' => 1];
    public const COST = ['trends' => 6, 'competitors' => 4, 'listening' => 4];   // credits per run: 3 searches; 4 page reads; 2 searches
    private const NOT_COMPETITORS = '/(^|\.)(instagram|facebook|fb|twitter|x|tiktok|youtube|linkedin|pinterest|reddit|quora|wikipedia|google|goo\.gl|bing|yahoo|amazon|ebay|yelp|tripadvisor|trustpilot|tiktok|medium|substack|wordpress|blogspot|wix|squarespace|shopify|apple|timeout|eventbrite|groupon|booking|airbnb|zomato|ubereats|deliveroo|doordash|grubhub|talabat|foursquare|glassdoor|indeed|craigslist|gumtree)\./i';

    public function __construct(private SignalService $signals) {}

    public function row(int $wsId, ?int $bizId, bool $create = false): ?object
    {
        $bizId = SignalService::bizKey($bizId);
        $q = DB::table('business_watch')->where('workspace_id', $wsId)->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'));
        $row = $q->first();
        if (! $row && $create) {
            DB::table('business_watch')->insertOrIgnore(['workspace_id' => $wsId, 'business_id' => $bizId, 'status' => 'not_asked', 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('business_watch')->where('workspace_id', $wsId)->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'))->first();
        }
        return $row;
    }

    public static function perRun(array $areas): int
    {
        $n = 0;
        foreach (self::COST as $k => $c) if (! empty($areas[$k])) $n += $c;
        return $n;
    }

    /** What each schedule costs a week, for the owner's choice. */
    public static function options(array $areas = ['trends' => 1, 'competitors' => 1, 'listening' => 1]): array
    {
        $per = self::perRun($areas);
        return array_map(fn ($f) => ['frequency' => $f, 'label' => ['daily' => 'Every day', 'twice_weekly' => 'Twice a week', 'weekly' => 'Once a week'][$f],
            'credits_per_week' => $per * self::RUNS_PER_WEEK[$f]], array_keys(self::FREQUENCIES));
    }

    /** The owner's one approval: how often and what. Runs until stopped. */
    public function setup(int $wsId, ?int $bizId, string $frequency, array $areas, ?int $userId, string $via = 'card'): array
    {
        if (! isset(self::FREQUENCIES[$frequency])) return ['success' => false, 'error' => 'Choose every day, twice a week or once a week.'];
        $areas = ['trends' => (bool) ($areas['trends'] ?? true), 'competitors' => (bool) ($areas['competitors'] ?? true), 'listening' => (bool) ($areas['listening'] ?? true)];
        if (! array_filter($areas)) return ['success' => false, 'error' => 'Pick at least one thing for Sarah to watch.'];
        $row = $this->row($wsId, $bizId, true);
        $wasOn = $row->status === 'on';
        DB::table('business_watch')->where('id', $row->id)->update($areas + [
            'status' => 'on', 'frequency' => $frequency, 'credits_per_run' => self::perRun($areas), 'approved_by' => $userId, 'approved_at' => now(),
            'stopped_at' => null, 'stopped_by' => null, 'stopped_reason' => null, 'empty_runs' => 0,
            'next_run_at' => $wasOn && $row->last_run_at ? Carbon::parse($row->last_run_at)->addDays(self::FREQUENCIES[$frequency]) : now()->addMinutes(2),
            'updated_at' => now(),
        ]);
        Log::info('[WATCH-1] watch on', ['ws' => $wsId, 'biz' => $bizId, 'frequency' => $frequency, 'areas' => $areas, 'via' => $via]);
        if ($via === 'card') $this->say($wsId, 'watch_on', "Write Sarah's one-line chat message confirming she now watches the market for the business in FACTS on the schedule in FACTS, will bring what matters to them here, and they can say stop any time. No emojis.",
            ['business' => app(\App\Core\Brand\BrandProfileService::class)->business($wsId, $bizId)->name ?? null, 'schedule' => str_replace('_', ' ', $frequency)], 'Done — I will keep an eye on the market ' . str_replace('_', ' ', $frequency) . ' and bring you anything that matters. Say stop any time.');
        $this->experience($wsId, (int) $row->id, 'OWNER_APPROVAL', 'watch_setup', ['frequency' => $frequency, 'areas' => $areas, 'via' => $via]);
        return ['success' => true, 'watch' => $this->state($wsId, $bizId)];
    }

    /** The owner — or Sarah herself — stops the watch. Nothing more is spent. */
    public function stop(int $wsId, ?int $bizId, string $by = 'owner', string $reason = '', bool $all = false): int
    {
        $q = DB::table('business_watch')->where('workspace_id', $wsId)->whereIn('status', ['on', 'asked']);
        if (! $all) $q->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'));
        $n = $q->update(['status' => 'off', 'stopped_at' => now(), 'stopped_by' => $by, 'stopped_reason' => mb_substr($reason, 0, 300) ?: null, 'next_run_at' => null, 'updated_at' => now()]);
        if ($n) Log::info('[WATCH-1] watch stopped', ['ws' => $wsId, 'biz' => $bizId, 'all' => $all, 'by' => $by, 'reason' => $reason]);
        return $n;
    }

    /** CHAT-FIRST-1: what the owner does on the web, and what Sarah finds, lands in her chat in her words. */
    private function say(int $wsId, string $task, string $instruction, array $facts, string $fallback, array $meta = []): void
    {
        try { app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, $task, $instruction, $facts, $fallback), $meta + ['notification_type' => $task]); } catch (\Throwable $e) {}
    }

    public function decline(int $wsId, ?int $bizId): void
    {
        $row = $this->row($wsId, $bizId, true);
        DB::table('business_watch')->where('id', $row->id)->where('status', '!=', 'on')->update(['status' => 'declined', 'updated_at' => now()]);
    }

    public function state(int $wsId, ?int $bizId): array
    {
        $r = $this->row($wsId, $bizId);
        $areas = ['trends' => (bool) ($r->trends ?? true), 'competitors' => (bool) ($r->competitors ?? true), 'listening' => (bool) ($r->listening ?? true)];
        return ['status' => $r->status ?? 'not_asked', 'frequency' => $r->frequency ?? null, 'areas' => $areas, 'credits_per_run' => self::perRun($areas),
            'credits_per_week' => ($r && $r->frequency) ? self::perRun($areas) * self::RUNS_PER_WEEK[$r->frequency] : null,
            'last_run_at' => $r && $r->last_run_at ? Carbon::parse($r->last_run_at)->toIso8601String() : null, 'next_run_at' => $r && $r->next_run_at ? Carbon::parse($r->next_run_at)->toIso8601String() : null,
            'stopped_by' => $r->stopped_by ?? null, 'stopped_reason' => $r->stopped_reason ?? null, 'options' => self::options($areas)];
    }

    /**
     * Sarah asks — once per business, in her chat — whether and how often to watch. One card at a time: never while
     * another of her cards waits on the owner. Returns true when she asked.
     */
    public function ask(int $wsId): bool
    {
        if (\Illuminate\Support\Facades\Cache::has('campaign-ideas-pending:' . $wsId)) return false;
        if (DB::table('creative_brand_identities')->where('workspace_id', $wsId)->where(fn ($w) => $w->whereNotNull('proposal_json')->orWhere('intake_asked_at', '>=', now()->subDay()))->exists()) return false;
        if (DB::table('business_watch')->where('workspace_id', $wsId)->where('asked_at', '>=', now()->subDays(3))->exists()) return false;
        $profiles = app(\App\Core\Brand\BrandProfileService::class);
        $target = null;
        foreach ($profiles->businesses($wsId) ?: [null] as $b) {
            $row = $this->row($wsId, $b->id ?? null);
            if (! $row || $row->status === 'not_asked') { $target = $b; $found = true; break; }
        }
        if (empty($found)) return false;
        $biz = $profiles->business($wsId, $target->id ?? null);
        $bizId = SignalService::bizKey($biz->id ?? null);
        $row = $this->row($wsId, $bizId, true);
        $opts = self::options();
        $facts = ['business' => $biz->name ?? 'the business', 'industry' => $biz->industry ?? null, 'location' => $biz->location ?? null,
            'what_you_would_watch' => 'trends and seasonal or local moments in their industry and area; what competitors are offering and changing; what people say about the business online',
            'why' => 'so you can act on it: time campaigns to what is happening, respond to competitors, and catch praise or complaints early',
            'cost' => 'it uses a few credits each time you look (web searches); the card shows the cost per week for each choice', 'choices' => array_column($opts, 'label'),
            'approval' => 'one yes and you keep doing it on that schedule until they tell you to stop (they can say "stop monitoring" any time); you will also stop and tell them if it stops being useful'];
        $fallback = 'Would you like me to keep an eye on what is happening around ' . ($biz->name ?? 'your business') . '? I can watch trends and local moments, what competitors are doing, and what people say about you online, then act on it in your campaigns. '
            . 'It uses a few credits each time, so choose how often below. One yes and I will keep doing it until you tell me to stop.';
        $text = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, 'watch_ask',
            "Write Sarah's short chat message (3-4 sentences) asking the owner, once, whether she should keep watching the world around their business and how often. Say what she would watch and why it helps (from FACTS), that it uses a few credits each time, and that one yes keeps it running on that schedule until they say stop. The choices are listed right after your message; do not mention a card or buttons. Warm, direct, no headings, no emojis.",
            $facts, $fallback);
        $text .= \App\Core\Growth\ChatReplies::APP_PART . implode("\n", array_map(fn ($o) => '• ' . $o['label'] . ' — about ' . $o['credits_per_week'] . ' credits a week', $opts)) . "\n\nReply **twice a week**, **once a week**, **every day** or **not now**.";
        $card = ['type' => 'watch_setup', 'business_id' => $bizId, 'business_name' => $biz->name ?? null, 'options' => $opts, 'areas' => ['trends' => true, 'competitors' => true, 'listening' => true], 'cost' => self::COST];
        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $text, ['card' => $card, 'notification_type' => 'watch_ask']);
        DB::table('business_watch')->where('id', $row->id)->update(['status' => 'asked', 'asked_at' => now(), 'updated_at' => now()]);
        return true;
    }

    /** @return int[] watch row ids that are due */
    public function due(): array
    {
        return DB::table('business_watch')->where('status', 'on')->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->orderBy('next_run_at')->limit(20)->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    /** One watch run for one business. Every finding is a signal; the reactor runs right after. */
    public function run(int $watchId, bool $manual = false): array
    {
        $w = DB::table('business_watch')->where('id', $watchId)->first();
        if (! $w || $w->status !== 'on') return ['ran' => false, 'reason' => 'not on'];
        $wsId = (int) $w->workspace_id;
        $bizId = $w->business_id ? (int) $w->business_id : null;
        $lock = \Illuminate\Support\Facades\Cache::lock('watch-run:' . $watchId, 900);
        if (! $lock->get()) return ['ran' => false, 'reason' => 'running'];
        try {
            $credits = app(\App\Core\Billing\CreditService::class);
            $need = (int) ($w->credits_per_run ?: 6);
            if (! $credits->hasBalance($wsId, min($need, 6))) {
                return $this->noCredits($w);
            }
            $biz = app(\App\Core\Brand\BrandProfileService::class)->business($wsId, $bizId);
            $out = ['trends' => [], 'competitors' => [], 'mentions' => 0, 'signals' => 0, 'errors' => []];
            if ($w->trends) { try { $out['trends'] = $this->trends($wsId, $biz); } catch (\Throwable $e) { $out['errors'][] = 'trends: ' . $e->getMessage(); } }
            if ($w->competitors) { try { $out['competitors'] = $this->competitors($wsId, $biz); } catch (\Throwable $e) { $out['errors'][] = 'competitors: ' . $e->getMessage(); } }
            if ($w->listening) { try { $out['mentions'] = $this->listening($wsId, $biz); } catch (\Throwable $e) { $out['errors'][] = 'listening: ' . $e->getMessage(); } }
            $found = count($out['trends']) + count(array_filter($out['competitors'], fn ($c) => ! empty($c['changed']))) + (int) $out['mentions'];
            $spent = (int) DB::table('agent_web_activity')->where('workspace_id', $wsId)->where('created_at', '>=', now()->subMinutes(20))->where('status', 'ok')->sum('cost_credits');
            $next = now()->addDays(self::FREQUENCIES[$w->frequency] ?? 7);
            if ($w->frequency === 'twice_weekly') $next = now()->addHours(84);   // twice a week = every three and a half days
            DB::table('business_watch')->where('id', $watchId)->update(['last_run_at' => now(), 'next_run_at' => $manual && $w->next_run_at && Carbon::parse($w->next_run_at)->isFuture() ? $w->next_run_at : $next,
                'empty_runs' => $found ? 0 : ((int) $w->empty_runs + 1), 'last_run_json' => json_encode(['found' => $found, 'credits' => $spent, 'errors' => $out['errors'], 'at' => now()->toIso8601String()]), 'updated_at' => now()]);
            Log::info('[WATCH-1] run', ['ws' => $wsId, 'biz' => $bizId, 'trends' => count($out['trends']), 'competitors' => count($out['competitors']), 'mentions' => $out['mentions'], 'credits' => $spent, 'errors' => $out['errors']]);
            // CHAT-FIRST-1: every look is reported in the chat — what she found, briefly; a quiet look in one line
            $newComps = array_values(array_filter($out['competitors'], fn ($c) => ! empty($c['first'])));
            $changed = DB::table('business_competitors')->where('workspace_id', $wsId)->where('last_change_at', '>=', now()->subMinutes(20))->pluck('last_change', 'name')->all();
            $mentionsNew = DB::table('growth_signals')->where('workspace_id', $wsId)->where('kind', 'mention')->where('created_at', '>=', now()->subMinutes(20))->pluck('title')->all();
            $facts = array_filter(['business' => $biz->name ?? null, 'trends_and_moments' => $out['trends'], 'competitors_found' => array_column($newComps, 'name'), 'competitor_changes' => $changed, 'new_mentions' => $mentionsNew, 'credits_used' => $spent]);
            $quiet = ! $out['trends'] && ! $newComps && ! $changed && ! $mentionsNew;
            $this->say($wsId, 'watch_report', $quiet
                ? "Write Sarah's one-line chat message: she had her scheduled look at the market for the business and nothing new is worth their time this time. No emojis."
                : "Write Sarah's short chat message (2-4 sentences) reporting her scheduled look at the market for the business: the one to three findings that matter most from FACTS, in plain words, and that she will suggest anything worth acting on. Conversational, no lists, no emojis, never invent.",
                $facts, $quiet ? 'I had my look at the market — nothing new worth your time this round.' : 'I had my look at the market: ' . implode('; ', array_slice(array_merge($out['trends'], array_map(fn ($k, $v) => $k . ': ' . $v, array_keys($changed), $changed)), 0, 3)) . '. I will suggest anything worth acting on.', ['watch_id' => $watchId]);
            // Sarah reacts to what she found now, not at the next tick
            \App\Jobs\GrowthReactJob::dispatch($wsId, $bizId)->delay(now()->addSeconds(20));
            return ['ran' => true] + $out + ['credits' => $spent];
        } finally {
            optional($lock)->release();
        }
    }

    /** No credits: skip this run and say so once; after three misses in a row Sarah stops the watch herself and tells the owner. */
    private function noCredits(object $w): array
    {
        $misses = (int) (json_decode((string) ($w->last_run_json ?? ''), true)['credit_misses'] ?? 0) + 1;
        $wsId = (int) $w->workspace_id;
        DB::table('business_watch')->where('id', $w->id)->update(['next_run_at' => now()->addDay(), 'last_run_json' => json_encode(['credit_misses' => $misses, 'at' => now()->toIso8601String()]), 'updated_at' => now()]);
        $name = app(\App\Core\Brand\BrandProfileService::class)->business($wsId, $w->business_id ? (int) $w->business_id : null)->name ?? 'your business';
        $bi = app(\App\Core\Brand\BrandIntakeService::class);
        if ($misses >= 3) {
            $this->stop($wsId, $w->business_id ? (int) $w->business_id : null, 'sarah', 'Out of credits three times in a row');
            $text = $bi->sarahWords($wsId, 'watch_stopped', "Write Sarah's short message (2 sentences): she has paused watching trends, competitors and mentions for the business because there were not enough credits three times in a row, so nothing more is being spent; they can turn it back on from Campaigns, Market watch, or just ask her, once credits are topped up. No emojis.",
                ['business' => $name], 'I have paused watching the market for ' . $name . ' because there were not enough credits three times in a row, so nothing more is being spent. Turn it back on from Campaigns › Market watch, or just ask me, once you have topped up.');
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $text, ['notification_type' => 'watch_stopped']);
        } elseif ($misses === 1) {
            $text = $bi->sarahWords($wsId, 'watch_no_credits', "Write Sarah's short message (1-2 sentences): she skipped today's look at trends, competitors and mentions for the business because the credits ran out; she will try again tomorrow. No emojis.",
                ['business' => $name], 'I skipped today\'s look at the market for ' . $name . ' because your credits ran out. I will try again tomorrow.');
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $text, ['notification_type' => 'watch_no_credits']);
        }
        return ['ran' => false, 'reason' => 'no credits', 'misses' => $misses];
    }

    // ── trends and local moments ─────────────────────────────────────────────────────────────────────────────────────

    private function trends(int $wsId, ?object $biz): array
    {
        $industry = trim((string) ($biz->industry ?? '')) ?: 'small business';
        $loc = trim((string) ($biz->location ?? ''));
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        try { $now = Carbon::now($tz); } catch (\Throwable $e) { $now = Carbon::now('UTC'); }
        $month = $now->format('F Y');
        $queries = array_values(array_unique(array_filter([
            $industry . ' trends ' . $month,
            $loc !== '' ? $industry . ' ' . $loc . ' events ' . $now->format('F') . ' ' . $now->copy()->addMonth()->format('F Y') : $industry . ' marketing ideas ' . $month,
            $loc !== '' ? $loc . ' ' . $industry . ' news' : $industry . ' customer trends ' . $now->format('Y'),
        ])));
        $web = app(\App\Engines\Web\Services\WebActivityService::class);
        $results = [];
        foreach ($queries as $q) {
            $r = $web->search($wsId, 'sarah', null, $q);
            if (empty($r['success'])) { if (str_contains((string) ($r['error'] ?? ''), 'credit')) break; continue; }
            foreach (array_slice((array) ($r['data']['results'] ?? []), 0, 8) as $x) $results[] = ['q' => $q, 'title' => $x['title'] ?? '', 'url' => $x['url'] ?? '', 'snippet' => mb_substr((string) ($x['snippet'] ?? ''), 0, 300)];
        }
        if (! $results) return [];
        $recent = DB::table('growth_signals')->where('workspace_id', $wsId)->where('source', 'world')->whereIn('kind', ['trend', 'moment'])->where('created_at', '>=', now()->subDays(45))->pluck('title')->all();
        $sys = 'You are Sarah, a senior marketing manager, reading this week\'s search results for a small business. Pick what actually matters for ITS marketing in the next 8 weeks: '
            . 'a trend its customers care about, a seasonal or local moment (holiday, festival, event, weather-driven need) it can build a campaign or post around, or industry news that changes what customers want. '
            . 'Use ONLY the results; never invent dates or facts. Skip generic listicles unless a concrete, usable trend is in the snippet. Skip anything in ALREADY_KNOWN. At most 4. '
            . 'Return ONLY JSON {"findings":[{"kind":"trend|moment|news","title":"short plain title","why_it_matters":"one sentence for this business","date":"YYYY-MM-DD or empty","strength":1,"url":""}]}. strength: 1 nice to know, 2 worth acting on, 3 time-sensitive and clearly relevant.';
        $user = 'BUSINESS: ' . json_encode(array_filter(['name' => $biz->name ?? null, 'industry' => $industry, 'location' => $loc, 'services' => $biz->services_json ?? null, 'audience' => $biz->target_audience ?? null]), JSON_UNESCAPED_UNICODE)
            . "\nTODAY: " . $now->toDateString() . "\nALREADY_KNOWN: " . json_encode(array_slice($recent, 0, 20), JSON_UNESCAPED_UNICODE) . "\nRESULTS: " . json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, $user, ['task' => 'watch_trends', 'workspace_id' => (string) $wsId], 1500);
        $out = [];
        foreach (array_slice((array) (($r['success'] ?? false) ? ($r['parsed']['findings'] ?? []) : []), 0, 4) as $f) {
            if (! is_array($f) || trim((string) ($f['title'] ?? '')) === '') continue;
            $kind = in_array($f['kind'] ?? '', ['trend', 'moment', 'news'], true) ? $f['kind'] : 'trend';
            $title = mb_substr(trim((string) $f['title']), 0, 280);
            $id = $this->signals->emit($wsId, SignalService::bizKey($biz->id ?? null), 'world', $kind === 'news' ? 'trend' : $kind, max(1, min(3, (int) ($f['strength'] ?? 1))), $title,
                mb_substr((string) ($f['why_it_matters'] ?? ''), 0, 600), ['url' => $f['url'] ?? null, 'date' => $f['date'] ?? null, 'type' => $kind], 'world:' . $kind . ':' . substr(sha1(mb_strtolower(preg_replace('/\W+/u', '', $title))), 0, 20));
            if ($id) $out[] = $title;
        }
        return $out;
    }

    // ── competitors ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** Find competitors when fewer than three are known: recurring search rivals first, then one search. */
    public function discoverCompetitors(int $wsId, ?object $biz): int
    {
        $bizId = SignalService::bizKey($biz->id ?? null);
        $have = DB::table('business_competitors')->where('workspace_id', $wsId)->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'))->where('status', 'active')->count();
        $removed = DB::table('business_competitors')->where('workspace_id', $wsId)->where('status', 'removed')->pluck('domain')->filter()->all();
        if ($have >= 3) return 0;
        $own = array_filter(array_map(fn ($d) => $this->host($d), DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['custom_domain', 'subdomain'])->flatMap(fn ($s) => [$s->custom_domain, $s->subdomain])->filter()->all()));
        $cands = [];
        try {
            foreach (DB::table('seo_serp_results')->where('workspace_id', $wsId)->whereNotNull('domain')->where('created_at', '>=', now()->subDays(180))->select('domain', DB::raw('count(*) c'), DB::raw('max(title) t'))->groupBy('domain')->orderByDesc('c')->limit(25)->get() as $d) {
                $h = $this->host($d->domain);
                if (! $h || in_array($h, $own, true) || preg_match(self::NOT_COMPETITORS, $h . '.')) continue;
                $cands[] = ['domain' => $h, 'seen_in_search' => (int) $d->c, 'title' => $d->t];
            }
        } catch (\Throwable $e) {}
        $industry = trim((string) ($biz->industry ?? ''));
        $loc = trim((string) ($biz->location ?? ''));
        if (count($cands) < 5 && $industry !== '') {
            $r = app(\App\Engines\Web\Services\WebActivityService::class)->search($wsId, 'sarah', null, 'best ' . $industry . ($loc !== '' ? ' in ' . $loc : ''));
            foreach ((array) (($r['success'] ?? false) ? ($r['data']['results'] ?? []) : []) as $x) {
                $h = $this->host($x['url'] ?? '');
                if (! $h || in_array($h, $own, true) || preg_match(self::NOT_COMPETITORS, $h . '.')) continue;
                $cands[] = ['domain' => $h, 'title' => $x['title'] ?? '', 'snippet' => mb_substr((string) ($x['snippet'] ?? ''), 0, 200)];
            }
        }
        $cands = array_values(array_filter($cands, fn ($c) => ! in_array($c['domain'], $removed, true)));
        if (! $cands) return 0;
        $sys = 'From the candidates, pick the websites of real businesses that compete with THIS business for the same customers (same kind of service, same area or serving it). '
            . 'Exclude directories, marketplaces, media, blogs, list articles, social networks and suppliers. Return ONLY JSON {"competitors":[{"domain":"","name":"their business name"}]} with at most ' . (5 - $have) . ', best first.';
        $user = 'BUSINESS: ' . json_encode(array_filter(['name' => $biz->name ?? null, 'industry' => $industry, 'location' => $loc, 'services' => $biz->services_json ?? null]), JSON_UNESCAPED_UNICODE) . "\nCANDIDATES: " . json_encode(array_slice($cands, 0, 25), JSON_UNESCAPED_UNICODE);
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, $user, ['task' => 'watch_competitors_pick', 'workspace_id' => (string) $wsId], 600);
        $n = 0;
        $valid = array_column($cands, 'domain');
        foreach ((array) (($r['success'] ?? false) ? ($r['parsed']['competitors'] ?? []) : []) as $c) {
            $h = $this->host($c['domain'] ?? '');
            if (! $h || ! in_array($h, $valid, true) || DB::table('business_competitors')->where('workspace_id', $wsId)->where('domain', $h)->exists()) continue;
            DB::table('business_competitors')->insert(['workspace_id' => $wsId, 'business_id' => $bizId, 'name' => mb_substr(trim((string) ($c['name'] ?? '')) ?: $h, 0, 160), 'domain' => $h, 'source' => 'search', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            if (++$n + $have >= 5) break;
        }
        return $n;
    }

    public function addCompetitor(int $wsId, ?int $bizId, string $name, string $url): array
    {
        $h = $this->host($url);
        $name = trim($name);
        if (! $h && $name === '') return ['success' => false, 'error' => 'Give a name or a website.'];
        if ($h && DB::table('business_competitors')->where('workspace_id', $wsId)->where('domain', $h)->where('status', 'active')->exists()) return ['success' => false, 'error' => 'Sarah already watches that one.'];
        DB::table('business_competitors')->where('workspace_id', $wsId)->where('domain', $h)->where('status', 'removed')->delete();
        $id = DB::table('business_competitors')->insertGetId(['workspace_id' => $wsId, 'business_id' => $bizId, 'name' => mb_substr($name ?: $h, 0, 160), 'domain' => $h, 'source' => 'owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        return ['success' => true, 'id' => $id];
    }

    /** Read each competitor's site; the first read learns what they offer, later reads report what changed. */
    private function competitors(int $wsId, ?object $biz): array
    {
        $bizId = SignalService::bizKey($biz->id ?? null);
        $this->discoverCompetitors($wsId, $biz);
        $web = app(\App\Engines\Web\Services\WebActivityService::class);
        $runtime = app(\App\Connectors\RuntimeClient::class);
        $out = [];
        $list = DB::table('business_competitors')->where('workspace_id', $wsId)->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'))
            ->where('status', 'active')->whereNotNull('domain')->orderByRaw('last_checked_at IS NOT NULL')->orderBy('last_checked_at')->limit(4)->get();
        foreach ($list as $c) {
            $r = $web->fetch($wsId, 'sarah', null, 'https://' . $c->domain . '/');
            DB::table('business_competitors')->where('id', $c->id)->update(['last_checked_at' => now(), 'updated_at' => now()]);
            if (empty($r['success'])) { if (str_contains((string) ($r['error'] ?? ''), 'credit')) break; $out[] = ['name' => $c->name, 'read' => false]; continue; }
            $p = (array) ($r['data'] ?? []);
            $snap = ['title' => (string) data_get($p, 'og_data.title', data_get($p, 'title', '')), 'description' => (string) data_get($p, 'og_data.description', data_get($p, 'meta.description', '')),
                'headings' => array_slice(array_values(array_filter(array_map(fn ($h) => trim((string) (is_array($h) ? ($h['text'] ?? '') : $h)), (array) ($p['headings'] ?? [])))), 0, 30),
                'ctas' => array_slice(array_values(array_filter(array_map(fn ($h) => trim((string) (is_array($h) ? ($h['text'] ?? '') : $h)), (array) ($p['ctas'] ?? [])))), 0, 10)];
            $snap['prices'] = [];
            if (preg_match_all('/(?:[$€£₱]|AED|USD|PHP|SGD|EUR|GBP)\s?\d[\d,.]*/u', implode(' ', array_merge($snap['headings'], $snap['ctas'], [$snap['description']])), $pm)) $snap['prices'] = array_slice(array_values(array_unique($pm[0])), 0, 10);
            if (! $snap['title'] && ! $snap['headings']) { $out[] = ['name' => $c->name, 'read' => false]; continue; }
            $hash = hash('sha256', json_encode([$snap['headings'], $snap['ctas'], $snap['prices'], $snap['description']]));
            if (! $c->snapshot_hash) {
                $s = $runtime->chatJson('Describe in one or two plain sentences what this business offers and how it presents itself (offers, prices, positioning), from its homepage only. Return ONLY JSON {"summary":""}.',
                    'HOMEPAGE: ' . json_encode($snap, JSON_UNESCAPED_UNICODE), ['task' => 'watch_competitor_summary', 'workspace_id' => (string) $wsId], 300);
                DB::table('business_competitors')->where('id', $c->id)->update(['snapshot_hash' => $hash, 'snapshot_json' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'summary' => mb_substr((string) ($s['parsed']['summary'] ?? ''), 0, 600) ?: null, 'updated_at' => now()]);
                $out[] = ['name' => $c->name, 'read' => true, 'first' => true];
                continue;
            }
            if ($hash === $c->snapshot_hash) { $out[] = ['name' => $c->name, 'read' => true, 'changed' => false]; continue; }
            $old = json_decode((string) $c->snapshot_json, true) ?: [];
            $d = $runtime->chatJson('Compare a competitor\'s homepage before and after. Say whether something changed that matters to a rival business: a new offer, price change, new service, promotion, event, or new positioning. Ignore wording tweaks, dates, counters and layout. '
                . 'Return ONLY JSON {"meaningful":true|false,"change":"one plain sentence of what changed","strength":1}. strength 3 only for a promotion or price move that could pull customers away now.',
                'COMPETITOR: ' . $c->name . "\nBEFORE: " . json_encode($old, JSON_UNESCAPED_UNICODE) . "\nAFTER: " . json_encode($snap, JSON_UNESCAPED_UNICODE), ['task' => 'watch_competitor_diff', 'workspace_id' => (string) $wsId], 400);
            $upd = ['snapshot_hash' => $hash, 'snapshot_json' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
            $changed = ($d['success'] ?? false) && ! empty($d['parsed']['meaningful']) && trim((string) ($d['parsed']['change'] ?? '')) !== '';
            if ($changed) {
                $change = mb_substr(trim((string) $d['parsed']['change']), 0, 500);
                $upd += ['last_change_at' => now(), 'last_change' => $change];
                $this->signals->emit($wsId, $bizId, 'world', 'competitor_move', max(1, min(3, (int) ($d['parsed']['strength'] ?? 2))), $c->name . ': ' . $change, null,
                    ['competitor_id' => (int) $c->id, 'domain' => $c->domain], 'comp:' . $c->id . ':' . substr($hash, 0, 16));
            }
            DB::table('business_competitors')->where('id', $c->id)->update($upd);
            $out[] = ['name' => $c->name, 'read' => true, 'changed' => $changed];
        }
        return $out;
    }

    // ── listening ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Search the web for the business's name; new mentions become signals (a complaint is strong, praise is worth sharing). */
    private function listening(int $wsId, ?object $biz): int
    {
        $name = trim((string) ($biz->name ?? ''));
        if ($name === '' || mb_strlen($name) < 3) return 0;
        $bizId = SignalService::bizKey($biz->id ?? null);
        $wl = DB::table('brand_watchlist')->where('workspace_id', $wsId)->where('term', $name)->first();
        $own = array_values(array_filter(DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['custom_domain', 'subdomain'])->flatMap(fn ($s) => [$this->host($s->custom_domain), $this->host($s->subdomain)])->filter()->all()));
        if (! $wl) {
            $aliases = array_slice(array_values(array_filter((array) json_decode((string) ($biz->aliases_json ?? '[]'), true), fn ($a) => is_string($a) && mb_strlen($a) >= 3)), 0, 1);
            $wlId = DB::table('brand_watchlist')->insertGetId(['workspace_id' => $wsId, 'label' => $name, 'term' => $name, 'variants_json' => json_encode($aliases), 'negative_keywords_json' => json_encode([]),
                'scope' => 'brand', 'priority' => 'normal', 'is_active' => 1, 'metadata_json' => json_encode(['business_id' => $bizId, 'blocked_domains' => $own, 'source' => 'watch']), 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $wlId = (int) $wl->id;
            $meta = json_decode((string) $wl->metadata_json, true) ?: [];
            $meta['blocked_domains'] = array_values(array_unique(array_merge((array) ($meta['blocked_domains'] ?? []), $own)));
            DB::table('brand_watchlist')->where('id', $wlId)->update(['is_active' => 1, 'metadata_json' => json_encode($meta), 'updated_at' => now()]);
        }
        $before = (int) DB::table('brand_mentions')->where('workspace_id', $wsId)->max('id');
        $first = ! DB::table('brand_mentions')->where('watchlist_id', $wlId)->exists();
        $r = app(\App\Engines\Mention\Services\MentionScanService::class)->scanWatchlist($wsId, $wlId, ['scan_type' => 'scheduled', 'max_results' => 10, 'agent_slug' => 'sarah']);
        if (empty($r['success'])) throw new \RuntimeException((string) ($r['error'] ?? 'scan failed'));
        $new = DB::table('brand_mentions')->where('workspace_id', $wsId)->where('watchlist_id', $wlId)->where('id', '>', $before)->get();
        // only a page that names THIS business counts: search engines return every business with a similar word in its name
        $norm = fn ($t) => ' ' . trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $t))) . ' ';
        $needles = array_filter(array_map($norm, array_merge([$name], array_slice(array_values(array_filter((array) json_decode((string) ($biz->aliases_json ?? '[]'), true), fn ($a) => is_string($a) && mb_strlen($a) >= 4)), 0, 3))), fn ($n) => mb_strlen(trim($n)) >= 3);
        $new = $new->filter(function ($m) use ($norm, $needles) {
            $hay = $norm($m->source_title . ' ' . $m->excerpt);
            foreach ($needles as $n) if (str_contains($hay, $n)) return true;
            DB::table('brand_mentions')->where('id', $m->id)->update(['status' => 'dismissed', 'triage_notes' => 'Not about this business (name not on the page)', 'updated_at' => now()]);
            return false;
        })->values();
        if ($first) return $new->count();   // the first scan is the baseline: what is already out there is not news
        foreach ($new as $m) {
            $neg = $m->sentiment === 'negative';
            $this->signals->emit($wsId, $bizId, 'world', 'mention', $neg ? 3 : ($m->sentiment === 'positive' ? 2 : 1),
                ($neg ? 'A critical mention on ' : ($m->sentiment === 'positive' ? 'A good word on ' : 'Mentioned on ')) . ($m->source_domain ?: 'the web') . ': ' . mb_substr((string) ($m->source_title ?: $m->excerpt), 0, 200),
                mb_substr((string) $m->excerpt, 0, 500), ['mention_id' => (int) $m->id, 'url' => $m->source_url, 'sentiment' => $m->sentiment], 'mention:' . $m->id);
        }
        return $new->count();
    }

    private function host(?string $u): ?string
    {
        $u = trim((string) $u);
        if ($u === '') return null;
        if (! preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
        $h = strtolower((string) parse_url($u, PHP_URL_HOST));
        $h = preg_replace('/^www\./', '', $h);
        return $h && str_contains($h, '.') ? mb_substr($h, 0, 190) : null;
    }

    private function experience(int $wsId, int $id, string $type, string $action, array $payload): void
    {
        try {
            app(\App\Core\Experience888\ExperienceRecorder::class)->recordEvent($wsId, $type, ['actor_type' => 'owner', 'capability' => 'growth_watch', 'action' => $action,
                'entity_type' => 'business_watch', 'entity_id' => $id, 'evidence_type' => 'business_watch', 'evidence_id' => $id . ':' . now()->timestamp, 'payload' => $payload]);
        } catch (\Throwable $e) {}
    }
}
