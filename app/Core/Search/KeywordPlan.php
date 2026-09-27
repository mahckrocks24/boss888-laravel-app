<?php

namespace App\Core\Search;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PAGE-ONE-1 S1 — the foundation an SEO specialist lays for a small business website, then the 12-week Search Roadmap.
 *   1. keyword universe: real searches around the business's services and place (search volume, difficulty), plus what
 *      Search Console already shows people typing to find the site
 *   2. winnable targets: value to the business x chance of page one given who ranks now; local, specific and question
 *      searches first
 *   3. competitor map: the top results for the best targets — who they are, what their pages cover
 *   4. clusters: 3-6 topics, each with a pillar and supporting articles
 *   5. the roadmap: one article a week for 12 weeks, in order of opportunity and season — a campaign the owner approves
 *      once (Launch), which covers writing AND publishing. The weekly article is included in every plan (no credits).
 */
final class KeywordPlan
{
    public const SOURCE = 'search_roadmap';

    /** @return array{success:bool, error?:string, campaign_id?:int, targets?:int} */
    public function build(object $w, bool $announce = true): array
    {
        $wsId = (int) $w->workspace_id;
        $biz = SearchSites::business($w);
        $place = SearchSites::place($biz);
        $loc = SearchSites::locationCode($biz, $wsId);
        $host = SearchSites::host($w);
        if (! $host) return ['success' => false, 'error' => 'The website has no public address yet.'];
        $services = array_values(array_filter(array_map('strval', (array) (json_decode((string) ($biz->services_json ?? 'null'), true) ?: []))));
        $industry = trim((string) ($biz->industry ?? ''));

        // 1. keyword universe
        $seeds = [];
        foreach (array_slice($services, 0, 4) as $s) { $seeds[] = trim(strtolower($s . ' ' . $place)); $seeds[] = strtolower(trim($s)); }   // the local form and the broad service
        if ($industry !== '') { $seeds[] = trim(strtolower($industry . ' ' . $place)); $seeds[] = strtolower($industry); }
        foreach (DB::table('search_pages')->where('website_id', $w->id)->where('kind', 'page')->whereNull('gone_at')->pluck('title') as $pt) {   // what the site itself offers
            $pt = strtolower(trim((string) $pt));
            if ($pt !== '' && ! preg_match('/^(home|blog|news|about( us)?|contact( us)?|faq|faqs|privacy.*|terms.*|gallery|team|careers|menu)$/', $pt)) array_unshift($seeds, trim($pt . ' ' . strtolower($place)));
        }
        $seeds = array_slice(array_values(array_unique(array_filter($seeds))), 0, 10);
        if (! $seeds) return ['success' => false, 'error' => 'Sarah needs to know what the business offers first.'];
        $cand = [];
        $dfs = new \App\Connectors\DataForSeoConnector();
        foreach ($seeds as $seed) {
            try {
                $r = $dfs->relatedKeywords($seed, $loc, 'en', 50);
                foreach ((array) ($r['items'] ?? []) as $it) {
                    $k = strtolower(trim((string) ($it['keyword'] ?? ''))); if ($k === '') continue;
                    $cand[$k] = ['keyword' => $k, 'volume' => $it['volume'] ?? null, 'difficulty' => $it['competition_index'] ?? null];
                }
            } catch (\Throwable $e) { Log::info('[PAGE-ONE-1] related keywords failed', ['seed' => $seed, 'e' => $e->getMessage()]); }
        }
        $gsc = [];
        try {
            foreach (DB::table('gsc_metrics')->where('workspace_id', $wsId)->where('page', 'like', 'https://' . preg_replace('/^www\./', '', $host) . '%')->where('date', '>=', now()->subDays(90)->toDateString())
                ->selectRaw('query, SUM(impressions) imp, SUM(clicks) clk, SUM(position*impressions)/GREATEST(SUM(impressions),1) pos')->groupBy('query')->orderByDesc('imp')->limit(40)->get() as $q) {
                $k = strtolower(trim((string) $q->query)); if ($k === '') continue;
                $gsc[] = ['query' => $k, 'impressions_90d' => (int) $q->imp, 'clicks_90d' => (int) $q->clk, 'avg_position' => round((float) $q->pos, 1)];
                $cand[$k] = ($cand[$k] ?? ['keyword' => $k, 'volume' => null, 'difficulty' => null]) + ['already_seen' => true];
            }
        } catch (\Throwable $e) {}
        if (count($cand) < 5) return ['success' => false, 'error' => 'Search data is not available right now.'];
        uasort($cand, fn ($a, $b) => (int) ($b['volume'] ?? 0) <=> (int) ($a['volume'] ?? 0));
        $cand = array_slice(array_values($cand), 0, 160);
        $existing = DB::table('search_pages')->where('website_id', $w->id)->where('kind', 'article')->whereNull('gone_at')->orderByDesc('id')->limit(80)->pluck('title')->filter()->values()->all();

        // 2 + 4. winnable targets and clusters
        $facts = ['business' => array_filter(['name' => $biz->name ?? $w->name, 'industry' => $industry ?: null, 'services' => $services ?: null, 'place' => $place ?: null, 'audience' => $biz->target_audience ?? null,
            'differentiators' => $biz->differentiators ?? null, 'pricing' => $biz->pricing_anchor ?? null]), 'website' => $host, 'today' => now()->toDateString()];
        $sys = 'You are a senior SEO strategist planning search for ONE small business website that currently has little authority. '
            . 'From the candidate searches (with monthly volume and a 0-100 difficulty) and the searches Search Console already shows, pick 25-40 TARGETS the business can realistically reach page one for and that bring customers. '
            . 'Rules: a target is a search where THIS business\'s own page is the right answer — its products and services with the place, questions about them, how-to and buying guides about what it sells, local occasions it serves. NEVER "best X in <city>" or "top X near me" roundups, directory-style or competitor-name searches: a business cannot credibly rank its competitors. Prefer local, specific, long-tail and question searches over broad head terms; skip searches for other brands, jobs, recipes-only or DIY intent that brings no customers unless it builds authority for a core service; '
            . 'skip anything an existing article already targets. intent: learn | compare | buy | local. value 1-10 (how likely a searcher becomes a customer), winnable 1-10 (small site vs likely competition). '
            . 'Copy each keyword EXACTLY as written in candidates or search_console — never reword it. Group targets into 3-6 clusters (a topic with one pillar target and supporting targets). Return ONLY JSON {"clusters":[{"name":"","pillar":""}],"targets":[{"keyword":"","intent":"","cluster":"","pillar":false,"value":0,"winnable":0,"why":""}]}';
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, 'JSON input: ' . json_encode(['facts' => $facts, 'candidates' => $cand, 'search_console' => $gsc, 'existing_articles' => $existing], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['task' => 'seo_targets', 'workspace_id' => (string) $wsId], 4000);
        $targets = ($r['success'] ?? false) ? (array) ($r['parsed']['targets'] ?? []) : [];
        $clusters = ($r['success'] ?? false) ? (array) ($r['parsed']['clusters'] ?? []) : [];
        if (count($targets) < 5) { Log::warning('[PAGE-ONE-1] targets step returned too few', ['website' => $w->id, 'ok' => $r['success'] ?? null, 'error' => $r['error'] ?? null, 'keys' => array_keys((array) ($r['parsed'] ?? [])), 'n' => count($targets), 'candidates' => count($cand), 'text' => mb_substr((string) ($r['text'] ?? ''), 0, 300)]); return ['success' => false, 'error' => 'Sarah could not finish the search plan just now.']; }
        $byKw = []; foreach ($cand as $c) $byKw[$c['keyword']] = $c;
        foreach ($targets as $t) {
            $k = strtolower(trim((string) ($t['keyword'] ?? ''))); if ($k === '' || mb_strlen($k) > 190) continue;
            $score = (int) round(max(1, min(10, (int) ($t['value'] ?? 5))) * max(1, min(10, (int) ($t['winnable'] ?? 5))));
            DB::table('search_targets')->updateOrInsert(['website_id' => $w->id, 'keyword' => $k], [
                'workspace_id' => $wsId, 'business_id' => $biz->id ?? null, 'volume' => $byKw[$k]['volume'] ?? null, 'difficulty' => $byKw[$k]['difficulty'] ?? null,
                'intent' => mb_substr((string) ($t['intent'] ?? ''), 0, 16) ?: null, 'cluster' => mb_substr((string) ($t['cluster'] ?? ''), 0, 120) ?: null, 'pillar' => ! empty($t['pillar']),
                'score' => min(100, $score), 'why' => mb_substr((string) ($t['why'] ?? ''), 0, 300) ?: null, 'updated_at' => now(), 'created_at' => now()]);
        }

        $noVol = DB::table('search_targets')->where('website_id', $w->id)->whereNull('volume')->limit(100)->pluck('keyword')->all();
        if ($noVol) {
            try { foreach ((array) ($dfs->keywordData($noVol, $loc)['keywords'] ?? []) as $kd) DB::table('search_targets')->where('website_id', $w->id)->where('keyword', strtolower((string) $kd['keyword']))->update(['volume' => $kd['volume'] ?? null, 'difficulty' => $kd['competition_index'] ?? null]); } catch (\Throwable $e) {}
        }
        // a search nobody makes is not a target
        DB::table('search_targets')->where('website_id', $w->id)->where('status', 'target')->where('volume', 0)->update(['status' => 'dropped']);

        // 3. competitor map for the best targets
        $top = DB::table('search_targets')->where('website_id', $w->id)->whereIn('status', ['target', 'planned'])->orderByDesc('score')->limit(10)->get();
        $domains = [];
        foreach ($top as $t) {
            try {
                $s = $dfs->serpAnalysis($t->keyword, $loc);
                if (empty($s['success'])) continue;
                $res = array_map(fn ($x) => array_intersect_key($x, array_flip(['position', 'title', 'domain', 'url', 'snippet'])), (array) ($s['top_results'] ?? []));
                DB::table('search_targets')->where('id', $t->id)->update(['serp_json' => json_encode(['results' => $res, 'features' => $s['serp_features'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'serp_at' => now()]);
                foreach ($res as $x) { $d = preg_replace('/^www\./', '', strtolower((string) ($x['domain'] ?? ''))); if ($d !== '' && ! str_contains($d, preg_replace('/^www\./', '', $host)) && ! self::isDirectory($d)) $domains[$d] = ($domains[$d] ?? 0) + 1; }
            } catch (\Throwable $e) {}
        }
        arsort($domains);
        $competitors = array_slice($domains, 0, 10, true);

        // 5. the roadmap
        $pool = DB::table('search_targets')->where('website_id', $w->id)->where('status', 'target')->orderByDesc('score')->limit(30)->get();
        $pl = [];
        foreach ($pool as $t) { $serp = json_decode((string) $t->serp_json, true) ?: []; $pl[] = array_filter(['keyword' => $t->keyword, 'volume' => $t->volume, 'intent' => $t->intent, 'cluster' => $t->cluster, 'pillar' => (bool) $t->pillar, 'score' => $t->score, 'top_result_titles' => array_slice(array_column((array) ($serp['results'] ?? []), 'title'), 0, 5) ?: null]); }
        $sys2 = 'You are a senior SEO strategist. Plan 12 weekly articles (one a week) for this small business website from the TARGETS, to reach Google\'s first page for as many searches as possible. '
            . 'Order by opportunity and season (what people search in the coming weeks where the business is), pillar pages early in each cluster with supporting articles after them. Each article: a title a searcher would click (<= 65 characters, the search near the front, no year unless it is about this year), '
            . 'the keyword it targets (from TARGETS, exactly), an angle that beats the current top results (what they miss: real prices, local detail, first-hand experience, a comparison, a checklist), and type pillar | supporting | local | seasonal. '
            . 'Never plan two articles for the same search, never repeat an existing article, and never write a roundup or guide to other businesses ("best cafes in…", "cafes in downtown: our picks", "where to go", "top 10…") — every article is about what THIS business itself makes, sells or knows, written from its own experience. '
            . 'Titles are for searchers: helpful and specific (what it costs, how to choose, what to expect, how it is made, care tips, planning checklists) — never the business name in the title, never self-praise ("why we stand out", "our craft"). '
            . 'Return ONLY JSON {"why_now":"one sentence","articles":[{"week":1,"title":"","keyword":"","angle":"","type":""}]}';
        $valid = array_flip(array_map(fn ($t) => $t->keyword, $pool->all()));
        $bizName = strtolower(trim((string) ($biz->name ?? '')));
        $arts = []; $why = null; $want = min(12, count($valid));
        for ($attempt = 0; $attempt < 2 && count($arts) < $want; $attempt++) {
            $r2 = app(\App\Connectors\RuntimeClient::class)->chatJson($sys2, 'JSON input: ' . json_encode(['facts' => $facts, 'targets' => $pl, 'existing_articles' => array_slice($existing, 0, 60),
                'already_planned' => array_map(fn ($a) => $a['keyword'], $arts) ?: null, 'articles_needed' => $want - count($arts)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['task' => 'seo_roadmap', 'workspace_id' => (string) $wsId], 4000);
            $why = $why ?: ($r2['parsed']['why_now'] ?? null);
            foreach ((array) (($r2['success'] ?? false) ? ($r2['parsed']['articles'] ?? []) : []) as $a) {
                if (! is_array($a) || trim((string) ($a['title'] ?? '')) === '') continue;
                $k = strtolower(trim((string) ($a['keyword'] ?? '')));
                if (! isset($valid[$k]) || in_array($k, array_map(fn ($x) => strtolower(trim((string) $x['keyword'])), $arts), true)) continue;
                // a roundup of other businesses ("cafes in downtown austin: the best picks") is never an article for THIS business,
                // and a title carrying the business's own name is written for the owner, not for the searcher
                if (self::isRoundup((string) $a['title']) || ($bizName !== '' && str_contains(strtolower((string) $a['title']), $bizName))
                    || preg_match('/\b(why (our|we)|stands? out|our craft|(our|we) .*\b(is|are) the best)\b/i', (string) $a['title'])) continue;
                $arts[] = $a;
            }
        }
        $r2 = ['parsed' => ['why_now' => $why]];
        $arts = array_slice($arts, 0, 12);
        if (count($arts) < 4) return ['success' => false, 'error' => 'Sarah could not finish the roadmap just now.'];

        $phases = [];
        foreach ($arts as $i => $a) {
            $ph = intdiv($i, 4);
            $phases[$ph] ??= ['name' => 'Weeks ' . ($ph * 4 + 1) . '–' . min(count($arts), $ph * 4 + 4), 'order' => $ph + 1, 'items' => []];
            $phases[$ph]['items'][] = ['kind' => 'article', 'channel' => 'website', 'day_offset' => $i * 7, 'title' => mb_substr(trim((string) $a['title']), 0, 200),
                'brief' => 'Target search: ' . strtolower(trim((string) $a['keyword'])) . '. Angle: ' . mb_substr(trim((string) ($a['angle'] ?? '')), 0, 600) . ' Type: ' . mb_substr((string) ($a['type'] ?? 'supporting'), 0, 20) . '.'];
        }
        $name = $biz->name ?? $w->name;
        $idea = ['title' => 'Search Roadmap: ' . count($arts) . ' weeks to page one', 'objective' => 'Get ' . $name . ' found on Google\'s first page for the searches its customers make — one strong article a week, each built to beat what ranks today.',
            'why_now' => mb_substr((string) ($r2['parsed']['why_now'] ?? ''), 0, 500) ?: 'People are searching for these now and the top results are beatable.', 'audience' => $biz->target_audience ?? '', 'offer' => '',
            'channels' => ['website'], 'starts_in_days' => 2, 'duration_days' => count($arts) * 7, 'kpi' => ['metric' => 'visits', 'target' => max(20, min(500, (int) round(array_sum(array_map(fn ($t) => (int) ($t->volume ?? 0), $pool->take(12)->all())) * 0.02))), 'label' => 'more visits from Google'],
            'phases' => array_values($phases)];
        // an earlier roadmap still waiting is replaced, never stacked
        foreach (DB::table('marketing_campaigns')->where('workspace_id', $wsId)->where('source', self::SOURCE)->where('status', 'idea')->whereNull('deleted_at')->get(['id']) as $old)
            app(\App\Core\Campaigns\CampaignService::class)->decline($wsId, (int) $old->id, 0, 'Replaced by a newer Search Roadmap');
        $ids = app(\App\Core\Campaigns\CampaignService::class)->saveIdeas($wsId, $biz->id ?? null, [$idea], self::SOURCE);
        $cid = (int) ($ids[0] ?? 0);
        foreach ($arts as $a) DB::table('search_targets')->where('website_id', $w->id)->where('keyword', strtolower(trim((string) $a['keyword'])))->update(['status' => 'planned', 'updated_at' => now()]);
        DB::table('search_plans')->insert(['workspace_id' => $wsId, 'website_id' => $w->id, 'business_id' => $biz->id ?? null, 'kind' => 'roadmap', 'period' => now()->toDateString(), 'status' => 'proposed', 'campaign_id' => $cid,
            'facts_json' => json_encode(['targets' => DB::table('search_targets')->where('website_id', $w->id)->count(), 'clusters' => $clusters, 'competitors' => $competitors, 'location_code' => $loc, 'seeds' => $seeds], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(), 'updated_at' => now()]);
        if ($announce && $cid) $this->announce($w, $cid, $competitors);
        Log::info('[PAGE-ONE-1] roadmap', ['website' => $w->id, 'campaign' => $cid, 'articles' => count($arts), 'targets' => count($targets)]);
        return ['success' => true, 'campaign_id' => $cid, 'targets' => count($targets)];
    }

    /** The roadmap in Sarah's words, with the plan in plain text for the app and the Launch card for the web. */
    public function announce(object $w, int $cid, array $competitors = []): void
    {
        $wsId = (int) $w->workspace_id;
        $svc = app(\App\Core\Campaigns\CampaignService::class);
        $full = $svc->show($wsId, $cid);
        if (! $full) return;
        $steps = [];
        foreach ($full['phases'] as $p) foreach ($p['items'] as $it) $steps[] = ['title' => $it['title'], 'kind' => $it['kind'], 'channel' => $it['channel'], 'at' => $it['scheduled_at'], 'phase' => $p['name']];
        $card = array_intersect_key($full, array_flip(['id', 'title', 'objective', 'why_now', 'offer', 'channels', 'starts_on', 'ends_on', 'kpi', 'credit_estimate', 'steps_total'])) + ['steps' => array_slice($steps, 0, 4)];
        $gsc = app(SearchNotifier::class)->gscProperty($w);
        $name = SearchSites::business($w)->name ?? $w->name;
        $facts = ['business' => $name, 'website' => SearchSites::host($w), 'articles' => count($steps), 'first_article' => $steps[0]['title'] ?? null,
            'competitors_ranking_now' => array_slice(array_keys($competitors), 0, 3), 'search_console_connected' => (bool) $gsc, 'included_in_plan' => true];
        $fallback = 'I mapped what people search for around ' . $name . ' and who ranks today, and planned ' . count($steps) . ' articles — one a week — each built to reach Google\'s first page. They are included in your plan. Launch it once and my team writes, publishes and shares each one on its date.';
        $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, 'search_roadmap',
            "Write Sarah's chat message (3-4 sentences) as the business's SEO specialist presenting her Search Roadmap: she researched what people search for around the business and who ranks today (name up to 2 competitors from FACTS if given), and planned one article a week, each built to beat the current top results and reach Google's first page. Say it is included in their plan (no credits), and that launching approves the whole plan once — her team writes, publishes and shares each article on its date, and she reports what reaches page one. If search_console_connected is false, add one sentence asking them to connect Google Search Console (one Google sign-in) so she can see how each article does — the link comes right after your message. The plan is listed after your message; do not list it. Confident, plain words, no jargon, no promises of rankings, no emojis.",
            $facts, $fallback);
        $fmt = fn ($d) => $d ? Carbon::parse($d)->format('j M') : '';
        $words .= \App\Core\Growth\ChatReplies::APP_PART . '**' . $full['title'] . '** — ' . $fmt($full['starts_on']) . '–' . $fmt($full['ends_on']) . ' · included in your plan' . "\n"
            . implode("\n", array_map(fn ($s, $i) => ($i + 1) . '. ' . $fmt($s['at']) . ' — ' . $s['title'], $steps, array_keys($steps)))
            . (! $gsc ? "\n\nConnect Google Search Console: " . $this->gscLink($wsId) : '')
            . "\n\nReply **launch it** or **not now**.";
        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, ['notification_type' => 'campaign_ideas',
            'card' => ['type' => 'campaign_ideas', 'business_id' => $full['business_id'], 'business_name' => $name, 'ideas' => [$card], 'search_roadmap' => true]]);
    }

    public static function isRoundup(string $title): bool
    {
        $t = strtolower($title);
        $places = '(cafes|cafés|coffee shops|bakeries|restaurants|shops|stores|spots|places|salons|clinics|gyms|agencies|companies|chefs|venues|bars|hotels)';
        $hook = '(best|top\s*\d*|picks|where to (go|eat|find)|ultimate guide|must[- ]try|you.ll love|to visit|to try)';
        return (bool) preg_match('/\b' . $hook . '\b.*\b' . $places . '\b|\b' . $places . '\b.*\b' . $hook . '\b/u', $t);
    }

    /** Social networks, forums and directories rank for almost everything — they are not the business's competitors. */
    public static function isDirectory(string $d): bool
    {
        return (bool) preg_match('/(^|\.)(reddit|facebook|instagram|tiktok|youtube|pinterest|twitter|x|linkedin|yelp|tripadvisor|google|maps\.apple|wikipedia|quora|lemon8-app|yellowpages|thumbtack|nextdoor|groupon|opentable|ubereats|doordash|grubhub|bbb|angi|houzz|indeed|glassdoor|timeout|eater|infatuation|theknot|weddingwire|zola|bridebook|hitched|allrecipes|foodnetwork)\.[a-z.]+$/', $d);
    }

    public function gscLink(int $wsId): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('gsc.start', now()->addDays(7), ['ws' => $wsId]);
    }
}
