<?php

namespace App\Core\Search;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PAGE-ONE-1 S3 — making past pages earn their place (Owner: "If previous articles are not performing, she should offer to
 * optimize them better depending on competitors and the GSC reports she is getting").
 *
 *   weekly()   Search Console (28 days) per page → a bucket, the way an SEO specialist reads it:
 *                not_indexed · seen_not_clicked · striking (4-20) · deep (21-50) · invisible (90 days, no impressions)
 *                · slipping (fell off page one) · winning (top 3) · page_one (4-10) · new · growing
 *              reaching page one is celebrated; slipping off it is an alert (both in Sarah's chat)
 *   tuneup()   monthly: the best 3-6 fixes as one "Search tune-up" campaign the owner approves once
 *   optimize() runs one approved fix: retitle · refresh against the current top results · reindex · move the call to action up
 *   merges()   overlapping articles on a large site: proposed in chat, merged (with a redirect) only on the owner's yes
 *   report()   the monthly search report in plain words
 */
final class Performance
{
    public const TUNEUP = 'search_tuneup';

    /** @return array{pages:int, page_one:int, slipping:int} */
    public function weekly(object $w): array
    {
        $out = ['pages' => 0, 'page_one' => 0, 'slipping' => 0];
        $host = SearchSites::host($w);
        if (! $host) return $out;
        $bare = preg_replace('/^www\./', '', $host);
        $rows = DB::table('gsc_metrics')->where('workspace_id', $w->workspace_id)->where('date', '>=', now()->subDays(28)->toDateString())
            ->where(fn ($q) => $q->where('page', 'like', 'https://' . $bare . '%')->orWhere('page', 'like', 'https://www.' . $bare . '%'))
            ->selectRaw('page, SUM(clicks) c, SUM(impressions) i, SUM(position*impressions)/GREATEST(SUM(impressions),1) p')->groupBy('page')->get();
        $gscOn = app(SearchNotifier::class)->gscProperty($w) !== null;
        $by = []; foreach ($rows as $r) $by[sha1(SearchSites::norm($r->page))] = $r;
        $top = [];
        foreach (DB::table('gsc_metrics')->where('workspace_id', $w->workspace_id)->where('date', '>=', now()->subDays(28)->toDateString())->where('page', 'like', 'https://%' . $bare . '%')
            ->selectRaw('page, query, SUM(impressions) i')->groupBy('page', 'query')->orderByDesc('i')->get() as $q) { $h = sha1(SearchSites::norm($q->page)); $top[$h] ??= $q->query; }
        $won = []; $lost = [];
        foreach (DB::table('search_pages')->where('website_id', $w->id)->whereNull('gone_at')->get() as $p) {
            $g = $by[$p->url_hash] ?? null;
            $c = (int) ($g->c ?? 0); $i = (int) ($g->i ?? 0); $pos = $g ? round((float) $g->p, 2) : null;
            $age = $p->first_seen_at ? (int) Carbon::parse($p->first_seen_at)->diffInDays(now()) : 0;
            $prev = $p->position_28 !== null ? (float) $p->position_28 : null;
            $bucket = match (true) {
                ! $gscOn => null,
                $p->indexed === 0 && $age >= 14 => 'not_indexed',
                $i === 0 && ($p->baseline || $age >= 90) => 'invisible',
                $i === 0 => 'new',
                $prev !== null && $prev <= 10 && $pos > 10 && $i >= 20 => 'slipping',
                $pos <= 3 => 'winning',
                $pos <= 10 && $i >= 100 && ($c / max(1, $i)) < 0.015 => 'seen_not_clicked',
                $pos <= 10 => 'page_one',
                $pos <= 20 => 'striking',
                $pos <= 50 => 'deep',
                default => 'growing',
            };
            if ($bucket === null) continue;
            DB::table('search_pages')->where('id', $p->id)->update(['bucket' => $bucket, 'bucket_at' => now(), 'clicks_28' => $c, 'impressions_28' => $i, 'position_28' => $pos,
                'top_query' => isset($top[$p->url_hash]) ? mb_substr((string) $top[$p->url_hash], 0, 200) : null,
                'bucket_json' => json_encode(['prev_position' => $prev, 'prev_bucket' => $p->bucket, 'ctr' => $i ? round($c / $i, 4) : null]), 'updated_at' => now()]);
            $out['pages']++;
            if ($pos !== null && $pos <= 10 && ($prev === null || $prev > 10) && $i >= 10 && Cache::add('p1-won:' . $p->id, 1, now()->addDays(30))) { $won[] = ['title' => $p->title, 'url' => $p->url, 'search' => $top[$p->url_hash] ?? null, 'position' => round($pos, 1)]; $out['page_one']++; }
            if ($bucket === 'slipping' && Cache::add('p1-lost:' . $p->id, 1, now()->addDays(14))) { $lost[] = ['title' => $p->title, 'url' => $p->url, 'search' => $top[$p->url_hash] ?? null, 'was' => round($prev, 1), 'now' => round($pos, 1)]; $out['slipping']++; }
        }
        foreach (DB::table('search_targets')->where('website_id', $w->id)->whereNotNull('article_id')->get(['id', 'article_id']) as $t) {
            $sp = DB::table('search_pages')->where('website_id', $w->id)->where('kind', 'article')->where('ref_id', $t->article_id)->first(['position_28']);
            if ($sp && $sp->position_28 !== null) DB::table('search_targets')->where('id', $t->id)->update(['position' => $sp->position_28, 'position_at' => now(), 'status' => $sp->position_28 <= 10 ? 'ranking' : 'written']);
        }
        if ($won) $this->tell((int) $w->workspace_id, 'search_page_one', "Write Sarah's short, warm chat message (1-3 sentences) as the owner's SEO specialist: these page(s) of their website reached Google's first page (name each, the search and the position, with the link). Say what she will do next to push them higher. Never promise it stays. No emojis.",
            ['pages' => array_slice($won, 0, 4)], 'Good news: ' . $won[0]['title'] . ' is now on Google\'s first page' . ($won[0]['search'] ? ' for "' . $won[0]['search'] . '"' : '') . ' (position ' . $won[0]['position'] . '). ' . $won[0]['url'], ['website_id' => (int) $w->id]);
        if ($lost) $this->tell((int) $w->workspace_id, 'search_slipping', "Write Sarah's short chat message (2 sentences) as the owner's SEO specialist: these page(s) slipped off Google's first page (name each, the search, from position to position). Say she will compare them with the pages that moved ahead and include the fix in this month's tune-up. Calm, no alarm, no emojis.",
            ['pages' => array_slice($lost, 0, 3)], $lost[0]['title'] . ' slipped from position ' . $lost[0]['was'] . ' to ' . $lost[0]['now'] . '. I will compare it with the pages that moved ahead and fix it in this month\'s tune-up.', ['website_id' => (int) $w->id]);
        return $out;
    }

    /** Monthly: the best fixes as one campaign the owner approves once. @return int|null campaign id */
    public function tuneup(object $w, bool $announce = true): ?int
    {
        $wsId = (int) $w->workspace_id;
        if (DB::table('marketing_campaigns')->where('workspace_id', $wsId)->where('source', self::TUNEUP)->where('created_at', '>=', now()->subDays(25))->whereNull('deleted_at')
            ->where('title', 'like', '%' . (SearchSites::business($w)->name ?? $w->name) . '%')->exists()) return null;
        $picks = [];
        $take = function (string $bucket, string $action, int $n, string $order = 'impressions_28') use ($w, &$picks) {
            foreach (DB::table('search_pages')->where('website_id', $w->id)->where('kind', 'article')->whereNull('gone_at')->where('bucket', $bucket)
                ->where(fn ($q) => $q->whereNull('optimized_at')->orWhere('optimized_at', '<', now()->subDays(45)))->orderByDesc($order)->limit($n)->get() as $p) {
                if (count($picks) < 6 && ! isset($picks[$p->id])) $picks[$p->id] = ['page' => $p, 'action' => $action];
            }
        };
        $take('slipping', 'refresh', 2);
        $take('seen_not_clicked', 'retitle', 2);
        $take('striking', 'refresh', 3);
        $take('not_indexed', 'reindex', 2, 'first_seen_at');
        $take('deep', 'refresh', 1);
        foreach ($this->heatmapFixes($w) as $hf) if (count($picks) < 6 && ! isset($picks[$hf['page']->id])) $picks[$hf['page']->id] = $hf;
        if (! $picks) return null;
        $label = ['refresh' => 'Refresh', 'retitle' => 'New title', 'reindex' => 'Get indexed', 'cta' => 'Booking button up'];
        $why = fn ($p, $a) => match ($a) {
            'refresh' => 'Position ' . round((float) $p->position_28, 1) . ($p->top_query ? ' for "' . $p->top_query . '"' : '') . ' — update it against the pages ranking above it.',
            'retitle' => (int) $p->impressions_28 . ' people saw it in Google' . ($p->top_query ? ' for "' . $p->top_query . '"' : '') . ' but ' . (int) $p->clicks_28 . ' clicked — a title and description worth clicking.',
            'reindex' => 'Google has not added it to its index yet — strengthen it and ask again.',
            'cta' => 'Most readers leave before reaching the booking button — move it up.',
            default => '',
        };
        $items = []; $i = 0;
        foreach ($picks as $x) {
            $p = $x['page'];
            $items[] = ['kind' => 'optimize', 'channel' => 'website', 'day_offset' => $i++, 'title' => $label[$x['action']] . ': ' . mb_substr((string) $p->title, 0, 150),
                'brief' => 'Action: ' . $x['action'] . '. Page: ' . $p->url . '. Why: ' . $why($p, $x['action']) . ($x['why'] ?? '')];
        }
        $name = SearchSites::business($w)->name ?? $w->name;
        $idea = ['title' => 'Search tune-up — ' . now()->format('F') . ' · ' . $name, 'objective' => 'Lift pages that are close to page one, earn more clicks from the people who already see them, and get every page into Google.',
            'why_now' => count($items) . ' pages have clear, specific fixes this month.', 'audience' => '', 'offer' => '', 'channels' => ['website'], 'starts_in_days' => 1, 'duration_days' => max(5, count($items) + 2),
            'kpi' => ['metric' => 'visits', 'target' => 10, 'label' => 'more clicks from Google'], 'phases' => [['name' => 'Fixes', 'order' => 1, 'items' => $items]]];
        $cid = (int) (app(\App\Core\Campaigns\CampaignService::class)->saveIdeas($wsId, SearchSites::business($w)->id ?? null, [$idea], self::TUNEUP)[0] ?? 0);
        if (! $cid) return null;
        // each fix knows its article
        $n = 0; foreach (DB::table('campaign_items')->where('campaign_id', $cid)->orderBy('sort')->get(['id']) as $it) { $p = array_values($picks)[$n++]['page'] ?? null; if ($p) DB::table('campaign_items')->where('id', $it->id)->update(['result_type' => 'article', 'result_id' => $p->ref_id]); }
        DB::table('search_plans')->insert(['workspace_id' => $wsId, 'website_id' => $w->id, 'business_id' => SearchSites::business($w)->id ?? null, 'kind' => 'tuneup', 'period' => now()->format('Y-m'), 'status' => 'proposed', 'campaign_id' => $cid, 'created_at' => now(), 'updated_at' => now()]);
        if ($announce) {
            $svc = app(\App\Core\Campaigns\CampaignService::class);
            $full = $svc->show($wsId, $cid);
            $card = array_intersect_key($full, array_flip(['id', 'title', 'objective', 'why_now', 'offer', 'channels', 'starts_on', 'ends_on', 'kpi', 'credit_estimate', 'steps_total'])) + ['steps' => array_slice(array_map(fn ($it) => ['title' => $it['title'], 'kind' => $it['kind'], 'channel' => $it['channel'], 'at' => $it['scheduled_at'], 'phase' => 'Fixes'], $full['phases'][0]['items'] ?? []), 0, 4)];
            $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, 'search_tuneup', "Write Sarah's chat message (2-3 sentences) as the owner's SEO specialist: she read this month's Google results for their website and found specific fixes for the pages listed after the message (do not list them). Say it is included in their plan and that approving it once lets her team make every fix; she will report the effect. Plain words, no emojis.",
                ['business' => $name, 'fixes' => count($items)], 'I went through this month\'s Google results for ' . $name . ' and found ' . count($items) . ' specific fixes. They are included in your plan — approve once and my team makes them.');
            $words .= \App\Core\Growth\ChatReplies::APP_PART . implode("\n", array_map(fn ($it, $k) => ($k + 1) . '. **' . $it['title'] . '** — ' . preg_replace('/^Action: \w+\. Page: \S+ Why: /', '', $it['brief']), $items, array_keys($items))) . "\n\nReply **launch it** or **not now**.";
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, ['notification_type' => 'campaign_ideas', 'card' => ['type' => 'campaign_ideas', 'business_id' => $full['business_id'], 'business_name' => $name, 'ideas' => [$card], 'search_tuneup' => true]]);
        }
        return $cid;
    }

    /** One approved fix (a released "optimize" step). */
    public function optimize(int $itemId): array
    {
        $it = DB::table('campaign_items')->where('id', $itemId)->first();
        if (! $it || ! $it->result_id) return ['ok' => false];
        $a = DB::table('articles')->where('id', $it->result_id)->where('workspace_id', $it->workspace_id)->whereNull('deleted_at')->first();
        if (! $a) { DB::table('campaign_items')->where('id', $itemId)->update(['status' => 'skipped', 'note' => 'The article is no longer there.', 'updated_at' => now()]); return ['ok' => false]; }
        preg_match('/Action:\s*(\w+)/', (string) $it->brief, $m); $action = $m[1] ?? 'refresh';
        $w = DB::table('websites')->where('id', $a->website_id)->first();
        $sp = $w ? DB::table('search_pages')->where('website_id', $w->id)->where('kind', 'article')->where('ref_id', $a->id)->first() : null;
        $query = $sp->top_query ?? $a->focus_keyword ?? null;
        $done = [];
        try {
            if ($action === 'retitle') {
                $r = app(OnPage::class)->article((int) $a->id, true, $query);
                $done = $r['changed'];
            } elseif ($action === 'refresh') {
                $po = app(PageOne::class);
                $loc = SearchSites::locationCode($w ? SearchSites::business($w) : null, (int) $a->workspace_id);
                $s = (new \App\Connectors\DataForSeoConnector())->serpAnalysis((string) $query, $loc);
                $comp = [];
                foreach (array_slice((array) ($s['top_results'] ?? []), 0, 6) as $x) { if (count($comp) >= 3) break; if ($w && str_contains((string) ($x['domain'] ?? ''), preg_replace('/^www\./', '', (string) SearchSites::host($w)))) continue; if ($c = $po->readCompetitor((string) ($x['url'] ?? ''), (string) ($x['title'] ?? ''))) $comp[] = $c; }
                $txt = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $a->content))), 0, 9000);
                $g = app(\App\Connectors\RuntimeClient::class)->chatJson('You are a senior SEO editor. Compare the ARTICLE with the pages ranking above it for the SEARCH. List the specific additions and edits that would make it the best answer: missing sections, questions, detail, a table, fresher facts, a clearer opening answer. Never suggest inventing numbers, reviews or awards. Return ONLY JSON {"edits":["..."]} (max 6).',
                    'JSON input: ' . json_encode(['search' => $query, 'article' => $txt, 'ranking_above' => $comp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['task' => 'seo_refresh_plan', 'workspace_id' => (string) $a->workspace_id], 900);
                $edits = array_values(array_filter(array_map(fn ($e) => OnPage::clean($e, 300), (array) ($g['parsed']['edits'] ?? []))));
                if ($edits) {
                    app(\App\Engines\Write\Services\WriteService::class)->improveDraft((int) $a->workspace_id, ['article_id' => (int) $a->id, 'instructions' => 'Refresh this article to compete for "' . $query . '". Make exactly these improvements: ' . implode(' ', $edits) . ' Keep what already works, the existing images and the FAQ section. No invented numbers, reviews or awards. Return the full article as HTML.']);
                    $done[] = 'content refreshed (' . count($edits) . ' improvements)';
                }
                $r = app(OnPage::class)->article((int) $a->id, true, $query);
                $done = array_merge($done, $r['changed']);
            } elseif ($action === 'reindex') {
                $r = app(OnPage::class)->article((int) $a->id, true, $query);
                $done = $r['changed'];
            } elseif ($action === 'cta') {
                $done[] = $this->ctaUp($a, $w) ? 'booking button moved up' : 'booking button already near the top';
            }
            if ($w && $sp) { app(SearchNotifier::class)->indexNow($w, [$sp->url]); DB::table('search_pages')->where('id', $sp->id)->update(['optimized_at' => now(), 'optimize_json' => json_encode(['action' => $action, 'done' => $done, 'at' => now()->toIso8601String()]), 'indexed' => $action === 'reindex' ? null : $sp->indexed]); }
            if ($w) try { app(\App\Engines\Publisher\Services\DeskService::class)->invalidate((int) $w->id, (int) $a->id); } catch (\Throwable $e) {}
            DB::table('campaign_items')->where('id', $itemId)->update(['status' => 'done', 'result_url' => $sp->url ?? null, 'note' => $done ? mb_substr(implode(', ', $done), 0, 250) : null, 'updated_at' => now()]);
            $this->tell((int) $a->workspace_id, 'search_fix_done', "Write Sarah's one or two sentence chat message: she made this month's fix on the page (name it, link) — say plainly what changed from FACTS.done and that she will watch how it does over the next weeks. No emojis.",
                ['page' => $a->title, 'link' => $sp->url ?? null, 'done' => $done, 'search' => $query], 'Done: ' . $a->title . ' — ' . implode(', ', $done ?: ['updated']) . '. I will watch how it does.', ['article_id' => (int) $a->id]);
            return ['ok' => true, 'done' => $done];
        } catch (\Throwable $e) {
            Log::warning('[PAGE-ONE-1] optimize failed', ['item' => $itemId, 'e' => $e->getMessage()]);
            DB::table('campaign_items')->where('id', $itemId)->update(['status' => 'failed', 'note' => 'This fix could not be made. Sarah will look at it.', 'updated_at' => now()]);
            return ['ok' => false];
        }
    }

    /** Heatmap: articles most readers leave before reaching the call to action. */
    private function heatmapFixes(object $w): array
    {
        $out = [];
        try {
            foreach (app(Heatmap::class)->ctaLate((int) $w->id) as $row) {
                $p = DB::table('search_pages')->where('website_id', $w->id)->where('kind', 'article')->where('url', 'like', '%' . $row['path'])->first();
                if ($p) $out[] = ['page' => $p, 'action' => 'cta', 'why' => ' ' . $row['reach_pct'] . '% of readers scroll far enough to see it.'];
            }
        } catch (\Throwable $e) {}
        return array_slice($out, 0, 2);
    }

    /** A short call to action after the second section, pointing at the contact or booking page. */
    private function ctaUp(object $a, ?object $w): bool
    {
        $html = (string) $a->content;
        if (str_contains($html, 'lu-cta-early')) return false;
        $target = '/contact';
        if ($w) foreach (DB::table('pages')->where('website_id', $w->id)->where('status', 'published')->pluck('slug') as $s) if (preg_match('/^(book|booking|reserve|contact|get-in-touch|enquire|quote)/', (string) $s)) { $target = '/' . $s; break; }
        $biz = $w ? SearchSites::business($w) : null;
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson('Write ONE short, warm sentence inviting a reader of this article to get in touch with the business, and a 2-4 word button label. Return ONLY JSON {"line":"","button":""}',
            'JSON input: ' . json_encode(['business' => $biz->name ?? null, 'article' => $a->title]), ['task' => 'seo_cta', 'workspace_id' => (string) $a->workspace_id], 200);
        $line = OnPage::clean($r['parsed']['line'] ?? 'Planning something? We would love to help.', 200); $btn = OnPage::clean($r['parsed']['button'] ?? 'Get in touch', 30);
        preg_match_all('#</h2>#i', $html, $m, PREG_OFFSET_CAPTURE);
        $pos = isset($m[0][1]) ? stripos($html, '</p>', $m[0][1][1]) : false;
        if ($pos === false) return false;
        $block = "\n<p class=\"lu-cta-early\" style=\"padding:14px 18px;border-radius:12px;background:rgba(0,0,0,.04)\">" . e($line) . ' <a href="' . e($target) . '"><strong>' . e($btn) . ' →</strong></a></p>' . "\n";
        DB::table('articles')->where('id', $a->id)->update(['content' => substr($html, 0, $pos + 4) . $block . substr($html, $pos + 4), 'updated_at' => now()]);
        return true;
    }

    /** Overlapping articles on a big site (the same search, near-identical titles): proposed, merged only on the owner's yes. */
    public function merges(object $w): array
    {
        $arts = DB::table('articles')->where('workspace_id', $w->workspace_id)->where('website_id', $w->id)->where('status', 'published')->whereNull('deleted_at')->get(['id', 'title', 'focus_keyword', 'slug']);
        if ($arts->count() < 25) return [];
        $imp = DB::table('search_pages')->where('website_id', $w->id)->where('kind', 'article')->pluck('impressions_28', 'ref_id')->all();
        $norm = fn ($s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', strtolower((string) $s))));
        $groups = []; $used = [];
        foreach ($arts as $x) {
            if (isset($used[$x->id])) continue;
            $g = [$x];
            foreach ($arts as $y) {
                if ($y->id === $x->id || isset($used[$y->id])) continue;
                similar_text($norm($x->title), $norm($y->title), $pct);
                if (($x->focus_keyword && $norm($x->focus_keyword) === $norm($y->focus_keyword)) || $pct >= 82) { $g[] = $y; $used[$y->id] = 1; }
            }
            if (count($g) > 1) { $used[$x->id] = 1; usort($g, fn ($a, $b) => ((int) ($imp[$b->id] ?? 0)) <=> ((int) ($imp[$a->id] ?? 0)) ?: $a->id <=> $b->id); $groups[] = ['keep' => $g[0], 'merge' => array_slice($g, 1)]; }
        }
        return array_slice($groups, 0, 12);
    }

    public function proposeMerges(object $w): bool
    {
        $groups = $this->merges($w);
        if (! $groups || ! Cache::add('p1-merge-ask:' . $w->id, 1, now()->addDays(30))) return false;
        $plan = array_map(fn ($g) => ['keep' => ['id' => $g['keep']->id, 'title' => $g['keep']->title], 'merge' => array_map(fn ($m) => ['id' => $m->id, 'title' => $m->title], $g['merge'])], $groups);
        $pid = DB::table('search_plans')->insertGetId(['workspace_id' => $w->workspace_id, 'website_id' => $w->id, 'kind' => 'merge', 'period' => now()->format('Y-m'), 'status' => 'proposed', 'facts_json' => json_encode($plan, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
        $n = array_sum(array_map(fn ($g) => count($g['merge']), $groups));
        $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords((int) $w->workspace_id, 'search_merge', "Write Sarah's chat message (2-3 sentences) as the owner's SEO specialist: several articles on their website cover the same topic and compete with each other in Google, which holds all of them back. She proposes folding each set into its strongest article (listed after the message; do not list them) — the others are taken down with a permanent redirect, so no link breaks and nothing is lost. Ask them to reply merge to go ahead, or keep to leave them. No emojis.",
            ['articles_to_fold' => $n, 'groups' => count($groups)], 'Several of your articles cover the same topic and compete with each other in Google. I suggest folding each set into its strongest article, with a permanent redirect so no link breaks.');
        $words .= \App\Core\Growth\ChatReplies::APP_PART . implode("\n\n", array_map(fn ($g, $k) => ($k + 1) . '. Keep **' . $g['keep']['title'] . '**' . "\n   fold in: " . implode('; ', array_map(fn ($m) => $m['title'], $g['merge'])), $plan, array_keys($plan))) . "\n\nReply **merge** or **keep**.";
        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent((int) $w->workspace_id, 'sarah', $words, ['notification_type' => 'search_merge', 'card' => ['type' => 'search_merge', 'plan_id' => $pid]]);
        return true;
    }

    /** The owner said merge: fold the weaker articles into the keeper (redirect, then off the site). */
    public function applyMerges(int $wsId, int $planId): array
    {
        $plan = DB::table('search_plans')->where('id', $planId)->where('workspace_id', $wsId)->where('kind', 'merge')->where('status', 'proposed')->first();
        if (! $plan) return ['ok' => false, 'n' => 0];
        $w = DB::table('websites')->where('id', $plan->website_id)->first();
        $urls = $w ? SearchSites::urls($w) : [];
        $pathOf = function (int $id) use ($urls) { foreach ($urls as $u => $x) if ($x['kind'] === 'article' && (int) $x['ref_id'] === $id) return parse_url($u, PHP_URL_PATH); return null; };
        $n = 0;
        foreach ((array) json_decode((string) $plan->facts_json, true) as $g) {
            $to = $pathOf((int) $g['keep']['id']); if (! $to) continue;
            foreach ((array) $g['merge'] as $m) {
                $from = $pathOf((int) $m['id']); if (! $from) continue;
                DB::table('seo_redirects')->insert(['workspace_id' => $wsId, 'source_url' => $from, 'target_url' => $to, 'status_code' => 301, 'is_active' => 1, 'type' => 301, 'is_regex' => 0, 'hit_count' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('articles')->where('id', (int) $m['id'])->where('workspace_id', $wsId)->update(['status' => 'draft', 'updated_at' => now()]);   // kept in the workspace, off the site
                $n++;
            }
            try { app(OnPage::class)->article((int) $g['keep']['id'], true); } catch (\Throwable $e) {}
        }
        DB::table('search_plans')->where('id', $planId)->update(['status' => 'applied', 'updated_at' => now()]);
        if ($w) try { app(\App\Engines\Publisher\Services\DeskService::class)->invalidate((int) $w->id); } catch (\Throwable $e) {}
        return ['ok' => true, 'n' => $n];
    }

    /** The monthly search report. */
    public function report(object $w): bool
    {
        $wsId = (int) $w->workspace_id;
        if (! app(SearchNotifier::class)->gscProperty($w) || ! Cache::add('p1-report:' . $w->id . ':' . now()->format('Y-m'), 1, now()->addDays(40))) return false;
        $host = preg_replace('/^www\./', '', (string) SearchSites::host($w));
        $sum = fn ($from, $to) => DB::table('gsc_metrics')->where('workspace_id', $wsId)->where('page', 'like', 'https://%' . $host . '%')->whereBetween('date', [$from, $to])->selectRaw('SUM(clicks) c, SUM(impressions) i')->first();
        $now = $sum(now()->subDays(28)->toDateString(), now()->toDateString()); $before = $sum(now()->subDays(56)->toDateString(), now()->subDays(29)->toDateString());
        $pages = DB::table('search_pages')->where('website_id', $w->id)->whereNull('gone_at');
        $facts = ['website' => $host, 'clicks_28_days' => (int) ($now->c ?? 0), 'clicks_before' => (int) ($before->c ?? 0), 'seen_in_google_28_days' => (int) ($now->i ?? 0), 'seen_before' => (int) ($before->i ?? 0),
            'pages_on_page_one' => (clone $pages)->whereIn('bucket', ['winning', 'page_one', 'seen_not_clicked'])->count(), 'pages_close' => (clone $pages)->where('bucket', 'striking')->count(),
            'pages_checked' => (clone $pages)->whereNotNull('index_checked_at')->count(), 'pages_indexed' => (clone $pages)->where('indexed', 1)->count(),
            'articles_published_this_month' => DB::table('articles')->where('website_id', $w->id)->where('status', 'published')->where('published_at', '>=', now()->subDays(30))->count(),
            'fixes_this_month' => (clone $pages)->where('optimized_at', '>=', now()->subDays(30))->count(),
            'top_pages' => (clone $pages)->whereNotNull('position_28')->where('impressions_28', '>', 0)->orderBy('position_28')->limit(3)->get(['title', 'position_28', 'top_query'])->map(fn ($p) => ['title' => $p->title, 'position' => round((float) $p->position_28, 1), 'search' => $p->top_query])->all()];
        $this->tell($wsId, 'search_report', "Write Sarah's monthly search report (4-6 short sentences) as the owner's SEO specialist, in plain words from FACTS only: clicks and how often the site appeared in Google compared with the month before, how many pages are on the first page and how many are close, the best pages (search and position), what was published and fixed this month, and what she will focus on next month. Honest: say 'during the month', never promise rankings. No jargon, no emojis.",
            $facts, 'Your search report: ' . $facts['clicks_28_days'] . ' clicks from Google in the last 28 days (' . $facts['clicks_before'] . ' the month before), ' . $facts['pages_on_page_one'] . ' pages on the first page and ' . $facts['pages_close'] . ' close to it.', ['website_id' => (int) $w->id]);
        return true;
    }

    /** S4: what the campaign planner should know about search — so campaigns and the roadmap point the same way. */
    public static function plannerFacts(int $wsId, ?int $bizId): array
    {
        $sites = SearchSites::managed($wsId);
        $w = $bizId ? $sites->firstWhere('business_id', $bizId) : null;
        $w = $w ?: ($sites->count() === 1 ? $sites->first() : null);
        if (! $w) return [];
        $road = DB::table('search_plans')->where('website_id', $w->id)->where('kind', 'roadmap')->orderByDesc('id')->value('campaign_id');
        $next = $road ? DB::table('campaign_items')->where('campaign_id', $road)->where('status', 'planned')->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->limit(4)->get(['title', 'scheduled_at', 'brief'])
            ->map(fn ($i) => ['article' => $i->title, 'week_of' => substr((string) $i->scheduled_at, 0, 10), 'search' => preg_match('/Target search:\s*([^.]+)/i', (string) $i->brief, $m) ? trim($m[1]) : null])->all() : [];
        $close = DB::table('search_pages')->where('website_id', $w->id)->whereIn('bucket', ['striking', 'seen_not_clicked'])->orderByDesc('impressions_28')->limit(4)->get(['title', 'top_query', 'position_28'])
            ->map(fn ($p) => ['page' => $p->title, 'search' => $p->top_query, 'position' => round((float) $p->position_28, 1)])->all();
        $targets = DB::table('search_targets')->where('website_id', $w->id)->where('status', 'target')->orderByDesc('score')->limit(6)->pluck('keyword')->all();
        return array_filter(['roadmap_articles_coming' => $next ?: null, 'pages_close_to_page_one' => $close ?: null, 'searches_worth_winning' => $targets ?: null]);
    }

    /** S4: one line for the morning brief when something in search moved. */
    public static function briefFacts(int $wsId): array
    {
        $out = [];
        $pub = DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->where('published_at', '>=', now()->subHours(30))->where('brief_json', 'like', '%"page_one"%')->limit(2)->pluck('title')->all();
        if ($pub) $out['published_yesterday'] = $pub;
        // newly on page one this week (it was below position 10, or unmeasured, at the previous reading)
        $won = DB::table('search_pages')->where('workspace_id', $wsId)->whereIn('bucket', ['winning', 'page_one', 'seen_not_clicked'])->where('bucket_at', '>=', now()->subDays(7))->orderBy('position_28')->limit(10)->get(['title', 'top_query', 'position_28', 'bucket_json'])
            ->filter(function ($p) { $prev = (json_decode((string) $p->bucket_json, true) ?: [])['prev_position'] ?? null; return $prev === null || (float) $prev > 10; })->take(2)
            ->map(fn ($p) => str_replace(['"', '“', '”'], '', $p->title . ' — ' . ($p->top_query ?? '') . ' #' . round((float) $p->position_28)))->values()->all();
        if ($won) $out['on_page_one'] = $won;
        $road = DB::table('campaign_items as i')->join('marketing_campaigns as c', 'c.id', '=', 'i.campaign_id')->where('c.workspace_id', $wsId)->where('c.source', KeywordPlan::SOURCE)->where('c.status', 'active')
            ->where('i.status', 'planned')->orderBy('i.scheduled_at')->first(['i.title', 'i.scheduled_at']);
        if ($road) $out['next_article'] = str_replace(['"', '“', '”'], '', $road->title) . ' (' . substr((string) $road->scheduled_at, 0, 10) . ')';
        return $out;
    }

    private function tell(int $wsId, string $type, string $instruction, array $facts, string $fallback, array $meta = []): void
    {
        try {
            $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, $type, $instruction, $facts, $fallback);
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, $meta + ['notification_type' => $type]);
        } catch (\Throwable $e) { Log::info('[PAGE-ONE-1] tell failed', ['ws' => $wsId, 'type' => $type, 'e' => $e->getMessage()]); }
    }
}
