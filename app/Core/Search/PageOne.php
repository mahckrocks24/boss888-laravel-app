<?php

namespace App\Core\Search;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PAGE-ONE-1 S2 — the article engine. Quality first: every article is written to beat what ranks today, carries the
 * owner's real experience, passes a quality gate before it can go live, and gets images in the body.
 *
 *   write()    brief from the live top results + the owner's facts → the writer → brief_json.page_one → finish job
 *   finish()   quality gate (one improvement round) → 2-3 in-body brand images with alt text → on-page pass →
 *              publish on its date (the campaign Launch covered publishing) or hold it and ask the owner what is missing
 *   publish()  live on the website, search engines told, shared through the connected social accounts, owner told
 */
final class PageOne
{
    /** Called from WriteService::writeArticle when params.page_one is set (campaign articles, the Search Roadmap). */
    public function write(int $wsId, array $params): array
    {
        $item = ! empty($params['campaign_item_id']) ? DB::table('campaign_items')->where('id', (int) $params['campaign_item_id'])->where('workspace_id', $wsId)->first() : null;
        $camp = $item ? DB::table('marketing_campaigns')->where('id', $item->campaign_id)->first() : null;
        $w = ! empty($params['website_id']) ? DB::table('websites')->where('id', (int) $params['website_id'])->where('workspace_id', $wsId)->whereNull('deleted_at')->first() : null;
        if (! $w) { $sites = SearchSites::managed($wsId); $w = $camp && $camp->business_id ? ($sites->firstWhere('business_id', $camp->business_id) ?? null) : null; $w = $w ?: ($sites->count() === 1 ? $sites->first() : null); }
        $biz = $w ? SearchSites::business($w) : DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->first();
        $topic = trim((string) ($params['topic'] ?? $params['title'] ?? ''));
        $kw = strtolower(trim((string) ($params['target_keyword'] ?? $params['keyword'] ?? '')));
        if ($kw === '' && $item && preg_match('/Target search:\s*([^.\n]+)/i', (string) $item->brief, $m)) $kw = strtolower(trim($m[1]));
        if ($kw === '') $kw = $this->chooseKeyword($wsId, $w, $biz, $topic);
        $loc = SearchSites::locationCode($biz, $wsId);
        $host = $w ? SearchSites::host($w) : null;

        // the top results today
        $target = $w ? DB::table('search_targets')->where('website_id', $w->id)->where('keyword', $kw)->first() : null;
        $serp = $target && $target->serp_at && Carbon::parse($target->serp_at)->gt(now()->subDays(14)) ? (json_decode((string) $target->serp_json, true) ?: []) : [];
        if (! $serp) {
            try { $s = (new \App\Connectors\DataForSeoConnector())->serpAnalysis($kw, $loc); if (! empty($s['success'])) $serp = ['results' => (array) ($s['top_results'] ?? []), 'features' => (array) ($s['serp_features'] ?? [])]; } catch (\Throwable $e) {}
        }
        $competitors = [];
        foreach (array_slice((array) ($serp['results'] ?? []), 0, 8) as $r) {
            if (count($competitors) >= 4) break;
            $d = strtolower((string) ($r['domain'] ?? ''));
            if ($d === '' || ($host && str_contains($d, preg_replace('/^www\./', '', $host))) || preg_match('/(youtube|facebook|instagram|reddit|pinterest|tiktok|wikipedia|yelp|tripadvisor)\./', $d)) continue;
            $competitors[] = $this->readCompetitor((string) ($r['url'] ?? ''), (string) ($r['title'] ?? ''));
        }
        $competitors = array_values(array_filter($competitors));

        // the owner's real experience
        $journal = []; $bfacts = [];
        try { $journal = DB::table('business_journal')->where('workspace_id', $wsId)->when($biz, fn ($q) => $q->where(fn ($x) => $x->where('business_id', $biz->id)->orWhereNull('business_id')))->orderByDesc('id')->limit(15)->pluck('text')->all(); } catch (\Throwable $e) {}
        try { if ($biz) $bfacts = DB::table('business_facts')->where('business_id', $biz->id)->whereNull('deleted_at')->where('published', 1)->limit(20)->get(['label', 'value'])->map(fn ($f) => $f->label . ': ' . $f->value)->all(); } catch (\Throwable $e) {}
        $owner = array_filter(['name' => $biz->name ?? null, 'industry' => $biz->industry ?? null, 'place' => SearchSites::place($biz) ?: null, 'services' => json_decode((string) ($biz->services_json ?? 'null'), true),
            'audience' => $biz->target_audience ?? null, 'differentiators' => $biz->differentiators ?? null, 'pricing' => $biz->pricing_anchor ?? null, 'tone' => $biz->tone ?? null,
            'what_the_owner_told_sarah' => $journal ?: null, 'verified_facts' => $bfacts ?: null]);

        // the brief
        $sys = 'You are the senior SEO content strategist for a small business. Plan ONE article that can reach Google\'s first page for the SEARCH. '
            . 'Study the COMPETITORS (the pages ranking now): cover what searchers need that they cover, and win on what they miss — first-hand detail from the owner, real local specifics, clear prices or ranges only when the owner gave them, a comparison, a checklist. '
            . 'Return ONLY JSON {"title":"<= 65 chars, the search near the front, worth clicking","answer_first":"the direct answer to the search in 40-60 words","outline":[{"h2":"","points":["what this section must say"]}],'
            . '"faq":["4-6 real questions searchers ask"],"table":"a comparison or price table idea, or empty","word_target":1500,"image_moments":[{"after_section":1,"subject":"what the picture shows, concrete","alt":"alt text"}],'
            . '"cta":"what the reader should do next with this business","missing_owner_facts":["a short question for the owner that would make this article more credible"]}. '
            . 'word_target 1100-2500 sized to the competitors. 5-8 H2 sections. 2-3 image_moments placed where a picture explains something (not decoration). Never plan invented statistics, awards, reviews or prices.';
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, 'JSON input: ' . json_encode(['search' => $kw, 'topic_hint' => $topic, 'campaign' => $camp->title ?? null, 'item_brief' => $item->brief ?? null,
            'business' => $owner, 'competitors' => $competitors, 'serp_features' => $serp['features'] ?? [], 'today' => now()->toDateString()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['task' => 'seo_brief', 'workspace_id' => (string) $wsId], 2500);
        $b = ($r['success'] ?? false) && is_array($r['parsed'] ?? null) ? $r['parsed'] : [];
        $title = OnPage::clean($b['title'] ?? '', 90) ?: ($item->title ?? $topic ?: ucfirst($kw));
        $outline = array_slice(array_values(array_filter((array) ($b['outline'] ?? []), 'is_array')), 0, 9);
        $faq = array_slice(array_values(array_filter(array_map(fn ($q) => OnPage::clean($q, 160), (array) ($b['faq'] ?? [])))), 0, 6);
        $words = max(1100, min(2500, (int) ($b['word_target'] ?? 1500)));
        $brief = 'Write for people searching Google for: "' . $kw . '".' . "\n"
            . 'Open with the direct answer in 40-60 words: ' . OnPage::clean($b['answer_first'] ?? '', 500) . "\n"
            . "Use these H2 sections in this order:\n" . implode("\n", array_map(fn ($o) => '- ' . OnPage::clean($o['h2'] ?? '', 120) . ': ' . implode('; ', array_map(fn ($p) => OnPage::clean($p, 200), array_slice((array) ($o['points'] ?? []), 0, 5))), $outline)) . "\n"
            . (! empty($b['table']) ? 'Include an HTML <table> where it helps: ' . OnPage::clean($b['table'], 300) . "\n" : '')
            . ($faq ? "End with an H2 \"Frequently asked questions\" and these questions as H3s, each answered in 2-3 sentences:\n" . implode("\n", array_map(fn ($q) => '- ' . $q, $faq)) . "\n" : '')
            . 'Close with a short paragraph inviting the reader to ' . OnPage::clean($b['cta'] ?? 'get in touch', 200) . ".\n"
            . 'Write from the business\'s own experience using ONLY these facts where numbers or claims are needed: ' . json_encode($owner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . 'Never invent statistics, prices, awards, reviews, client names or quotes. When a number is not in the facts, describe it without a number. Short paragraphs, plain words, helpful first — no filler, no keyword stuffing.';
        $res = app(\App\Engines\Write\Services\WriteService::class)->writeArticle($wsId, array_merge($params, [
            '_page_one_inner' => 1, 'title' => $title, 'topic' => $kw, 'target_keyword' => $kw, 'brief' => $brief, 'length' => $words, 'min_words' => $words - 150, 'max_words' => $words + 250,
            'auto_featured_image' => 1, 'website_id' => $w->id ?? null, 'is_marketing_blog' => 1, 'type' => 'blog_post', 'tone' => $biz->tone ?? ($params['tone'] ?? 'warm and expert'),
        ]));
        $aid = (int) ($res['article_id'] ?? $res['id'] ?? 0);
        if (! $aid) return $res;
        $included = $camp && $camp->source === KeywordPlan::SOURCE;
        $bj = json_decode((string) DB::table('articles')->where('id', $aid)->value('brief_json'), true) ?: [];
        $bj['page_one'] = ['keyword' => $kw, 'competitors' => array_column($competitors, 'domain'), 'faq' => $faq, 'images' => array_slice((array) ($b['image_moments'] ?? []), 0, 3),
            'missing_owner_facts' => array_slice((array) ($b['missing_owner_facts'] ?? []), 0, 2), 'publish_at' => $item && $item->scheduled_at ? Carbon::parse($item->scheduled_at)->toIso8601String() : null,
            'campaign_id' => $camp->id ?? null, 'campaign_item_id' => $item->id ?? null, 'campaign_source' => $camp->source ?? null, 'included' => $included, 'state' => 'written', 'written_at' => now()->toIso8601String()];
        DB::table('articles')->where('id', $aid)->update(['brief_json' => json_encode($bj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'focus_keyword' => mb_substr($kw, 0, 120), 'website_id' => $w->id ?? null]);
        if ($w) DB::table('search_targets')->updateOrInsert(['website_id' => $w->id, 'keyword' => $kw], ['workspace_id' => $wsId, 'business_id' => $biz->id ?? null, 'status' => 'written', 'article_id' => $aid, 'updated_at' => now()]);
        \App\Jobs\PageOneFinishJob::dispatch($aid)->delay(now()->addSeconds(10));
        Log::info('[PAGE-ONE-1] written', ['article' => $aid, 'keyword' => $kw, 'competitors' => count($competitors), 'included' => $included]);
        // the weekly roadmap article is included in the plan: the credit reserved for the step is released
        return $res + ['page_one' => true] + ($included ? ['no_charge' => true, 'no_charge_reason' => 'included_weekly_article'] : []);
    }

    /** Quality gate, in-body images, on-page pass, then publish or hold. Runs in PageOneFinishJob. */
    public function finish(int $aid): array
    {
        $a = DB::table('articles')->where('id', $aid)->whereNull('deleted_at')->first();
        if (! $a) return ['state' => 'missing'];
        $bj = json_decode((string) $a->brief_json, true) ?: [];
        $po = $bj['page_one'] ?? null;
        if (! $po || in_array($po['state'] ?? '', ['published', 'ready', 'held'], true)) return ['state' => $po['state'] ?? 'none'];
        $wsId = (int) $a->workspace_id;

        // 1. quality gate (before images, so an improvement round cannot drop them)
        $q = $this->grade($a, $po);
        if (! $q['pass']) {
            try {
                app(\App\Engines\Write\Services\WriteService::class)->improveDraft($wsId, ['article_id' => $aid, 'instructions' => 'Raise this article to page-one quality for the search "' . $po['keyword'] . '". Fix exactly these problems: ' . implode(' ', $q['fixes'])
                    . ' Remove anything not supported by the business facts (no invented numbers, awards, reviews or quotes). Keep the headings, the FAQ section and the call to action. Return the full article as HTML.']);
            } catch (\Throwable $e) { Log::info('[PAGE-ONE-1] improve failed', ['article' => $aid, 'e' => $e->getMessage()]); }
            $a = DB::table('articles')->where('id', $aid)->first();
            $q = $this->grade($a, $po);
        }
        $po['quality'] = ['score' => $q['score'], 'scores' => $q['scores'], 'pass' => $q['pass'], 'at' => now()->toIso8601String()];
        if (! $q['pass']) {
            $po['state'] = 'held'; $po['held_because'] = $q['fixes'];
            $this->save($aid, $bj, $po);
            $this->tell($wsId, 'page_one_held', "Write Sarah's short chat message (2-3 sentences): she held back this week's article because it is not yet good enough to reach Google's first page (say why in plain words from FACTS.why, max 2 points), and ask the owner the question(s) in FACTS.questions so the article can carry their real experience. Warm, no jargon, no emojis.",
                ['title' => $a->title, 'why' => array_slice($q['fixes'], 0, 2), 'questions' => $po['missing_owner_facts'] ?? []], 'I held back this week\'s article "' . $a->title . '" — it is not strong enough yet to reach page one. ' . (($po['missing_owner_facts'][0] ?? '') ?: 'Tell me one thing customers always ask you about this and I will finish it.'), ['article_id' => $aid]);
            return ['state' => 'held', 'score' => $q['score']];
        }

        // 2. images in the body
        $po['inline_images'] = $this->placeImages($a, (array) ($po['images'] ?? []));
        // 3. on-page pass (title, description, alt text, Article + FAQ structured data)
        try { $po['onpage'] = app(OnPage::class)->article($aid, false, $po['keyword'])['changed']; } catch (\Throwable $e) {}
        // 4. publish on its date
        $at = ! empty($po['publish_at']) ? Carbon::parse($po['publish_at']) : now();
        $po['state'] = 'ready';
        $this->save($aid, $bj, $po);
        if ($at->lte(now()->addMinutes(10))) return $this->publish($aid);
        $this->tell($wsId, 'page_one_ready', "Write Sarah's short chat message (1-2 sentences): this week's article is written, checked and ready; it goes live on FACTS.goes_live and she will tell them when it is up. Mention the search it is built for. No emojis.",
            ['title' => $a->title, 'search' => $po['keyword'], 'goes_live' => $this->localDate($wsId, $at), 'quality_score' => $q['score']], 'This week\'s article "' . $a->title . '" is written and checked. It goes live on ' . $this->localDate($wsId, $at) . '.', ['article_id' => $aid]);
        return ['state' => 'ready', 'publish_at' => $at->toIso8601String()];
    }

    /** Live, told to search engines, shared, and the owner hears it. */
    public function publish(int $aid): array
    {
        $a = DB::table('articles')->where('id', $aid)->whereNull('deleted_at')->first();
        if (! $a) return ['state' => 'missing'];
        $bj = json_decode((string) $a->brief_json, true) ?: []; $po = $bj['page_one'] ?? [];
        if (($po['state'] ?? '') === 'published') return ['state' => 'published'];
        $wsId = (int) $a->workspace_id;
        if (! Cache::add('page-one-publish:' . $aid, 1, now()->addMinutes(10))) return ['state' => 'busy'];
        try { app(\App\Engines\Write\Services\WriteService::class)->updateArticle($aid, ['status' => 'published', 'website_id' => $a->website_id], $wsId); }
        catch (\Throwable $e) { Log::warning('[PAGE-ONE-1] publish failed', ['article' => $aid, 'e' => $e->getMessage()]); return ['state' => 'failed']; }
        $w = $a->website_id ? DB::table('websites')->where('id', $a->website_id)->first() : null;
        $url = null;
        if ($w) { foreach (SearchSites::urls($w) as $u => $x) if ($x['kind'] === 'article' && (int) $x['ref_id'] === $aid) { $url = $u; break; } }
        if ($w && $url) app(SearchNotifier::class)->indexNow($w, [$url]);
        $share = [];
        try { $share = app(\App\Core\Strategy\PostPublishCoordinator::class)->onArticlePublished($wsId, $aid); } catch (\Throwable $e) {}
        Cache::put('page-one-told:' . $aid, 1, now()->addDays(3));
        $po['state'] = 'published'; $po['published_at'] = now()->toIso8601String(); $po['url'] = $url;
        $this->save($aid, $bj, $po);
        $this->tell($wsId, 'page_one_live', "Write Sarah's short chat message (2-3 sentences) as the owner's SEO specialist: this week's article is live (include the link), what search it is built to rank for, and one thing that makes it stronger than what ranks today (FACTS.edge). If shares were requested, say it is being shared on their social pages too. Say she will report when it reaches Google's first page. Never promise a ranking. No emojis.",
            ['title' => $a->title, 'link' => $url, 'search' => $po['keyword'] ?? null, 'edge' => 'written to beat ' . count((array) ($po['competitors'] ?? [])) . ' pages ranking today, with ' . count((array) ($po['inline_images'] ?? [])) . ' images and a questions section',
                'shares_requested' => (int) ($share['shares_requested'] ?? 0)],
            'This week\'s article is live: "' . $a->title . '"' . ($url ? ' — ' . $url : '') . '. It is built to rank for "' . ($po['keyword'] ?? '') . '". I will tell you when it reaches Google\'s first page.', ['article_id' => $aid]);
        return ['state' => 'published', 'url' => $url];
    }

    /** Every 10 minutes: articles whose date has come. */
    public function dueTick(): int
    {
        $n = 0;
        foreach (DB::table('articles')->whereNull('deleted_at')->where('status', '!=', 'published')->where('brief_json', 'like', '%"page_one"%')->where('updated_at', '>=', now()->subDays(120))->get(['id', 'brief_json']) as $a) {
            $po = (json_decode((string) $a->brief_json, true) ?: [])['page_one'] ?? null;
            if (! $po) continue;
            if (($po['state'] ?? '') === 'ready' && (empty($po['publish_at']) || Carbon::parse($po['publish_at'])->lte(now()))) { $this->publish((int) $a->id); $n++; }
            elseif (($po['state'] ?? '') === 'written' && ! empty($po['written_at']) && Carbon::parse($po['written_at'])->lt(now()->subMinutes(45))) \App\Jobs\PageOneFinishJob::dispatch((int) $a->id);   // a finish that never ran
        }
        return $n;
    }

    /** @return array{pass:bool, score:float, scores:array, fixes:string[]} */
    public function grade(object $a, array $po): array
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['</p>', '</h2>', '</h3>', '</li>'], ["\n", "\n", "\n", "\n"], (string) $a->content))));
        $sys = 'You are a strict senior SEO editor deciding whether an article is good enough to publish and compete for Google\'s first page. Score 1-10: intent (answers what the searcher wants, answer early), depth (covers the topic as well as or better than the competitors), '
            . 'experience (specific first-hand detail, not generic), accuracy (no invented numbers, awards, reviews, quotes or claims beyond the business facts), readability (short paragraphs, plain words, scannable), voice (fits the business), seo (the search used naturally in the title, first paragraph and headings; FAQ present). '
            . 'List invented_claims (exact phrases that are not supported by the facts) and fixes (specific edits, max 5). Return ONLY JSON {"scores":{"intent":0,"depth":0,"experience":0,"accuracy":0,"readability":0,"voice":0,"seo":0},"invented_claims":[],"fixes":[]}';
        $biz = DB::table('businesses')->where('workspace_id', $a->workspace_id)->whereNull('deleted_at')->orderByDesc('is_default')->first();
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, 'JSON input: ' . json_encode(['search' => $po['keyword'] ?? $a->focus_keyword, 'title' => $a->title, 'competitor_pages' => $po['competitors'] ?? [],
            'business_facts' => array_filter(['name' => $biz->name ?? null, 'services' => $biz->services_json ?? null, 'pricing' => $biz->pricing_anchor ?? null, 'differentiators' => $biz->differentiators ?? null, 'place' => $biz->location ?? null]),
            'article' => mb_substr($text, 0, 14000), 'words' => str_word_count($text)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['task' => 'seo_quality', 'workspace_id' => (string) $a->workspace_id], 1200);
        $p = ($r['success'] ?? false) && is_array($r['parsed'] ?? null) ? $r['parsed'] : null;
        if (! $p) return ['pass' => true, 'score' => 0.0, 'scores' => [], 'fixes' => [], 'ungraded' => true];   // the grader being down never blocks the week's article
        $sc = array_map(fn ($v) => max(1, min(10, (int) $v)), array_filter((array) ($p['scores'] ?? []), 'is_numeric'));
        $avg = $sc ? round(array_sum($sc) / count($sc), 1) : 0.0;
        $inv = array_values(array_filter(array_map(fn ($x) => OnPage::clean($x, 200), (array) ($p['invented_claims'] ?? []))));
        $fixes = array_values(array_filter(array_map(fn ($x) => OnPage::clean($x, 300), (array) ($p['fixes'] ?? []))));
        if ($inv) array_unshift($fixes, 'Remove or rephrase these unsupported claims: ' . implode(' | ', array_slice($inv, 0, 5)) . '.');
        $pass = $sc && $avg >= 7.5 && min($sc) >= 6 && ! $inv && str_word_count($text) >= 900;
        return ['pass' => $pass, 'score' => $avg, 'scores' => $sc, 'fixes' => array_slice($fixes, 0, 6)];
    }

    /** 2-3 brand images placed after the H2 sections the brief chose, each with alt text. @return array urls */
    private function placeImages(object $a, array $moments): array
    {
        $html = (string) $a->content; $placed = [];
        preg_match_all('#</h2>#i', $html, $m, PREG_OFFSET_CAPTURE);
        $h2Ends = array_map(fn ($x) => $x[1] + 5, $m[0] ?? []);
        if (! $h2Ends) return [];
        $inserts = [];
        foreach (array_slice($moments, 0, 3) as $i => $mo) {
            $sec = max(1, min(count($h2Ends), (int) ($mo['after_section'] ?? ($i + 1) * 2)));
            $subject = OnPage::clean($mo['subject'] ?? '', 300); if ($subject === '') continue;
            try {
                $img = app(\App\Engines\Creative\Services\CreativeService::class)->generateImage((int) $a->workspace_id, ['prompt' => $subject . '. For a website article titled "' . $a->title . '". Photographic, natural light, no text in the image.',
                    'aspect_ratio' => '16:9', 'quality' => 'mini', 'asset_type' => 'blog_image', 'source' => 'blog', 'business_id' => DB::table('websites')->where('id', $a->website_id)->value('business_id')]);
            } catch (\Throwable $e) { $img = []; }
            if (empty($img['url'])) continue;
            // after the first paragraph that follows this H2
            $pos = $h2Ends[$sec - 1];
            $pEnd = stripos($html, '</p>', $pos);
            $at = $pEnd !== false ? $pEnd + 4 : $pos;
            $alt = OnPage::clean($mo['alt'] ?? $subject, 150);
            $inserts[$at] = "\n<figure class=\"lu-article-figure\"><img src=\"" . e($img['url']) . '" alt="' . e($alt) . '" loading="lazy" decoding="async" width="1536" height="864" style="width:100%;height:auto;border-radius:12px"></figure>' . "\n";
            $placed[] = $img['url'];
        }
        krsort($inserts);
        foreach ($inserts as $at => $fig) $html = substr($html, 0, $at) . $fig . substr($html, $at);
        if ($placed) DB::table('articles')->where('id', $a->id)->update(['content' => $html, 'updated_at' => now()]);
        return $placed;
    }

    /** Headings and length of a ranking page (what the article must beat). */
    public function readCompetitor(string $url, string $title): ?array
    {
        if (! str_starts_with($url, 'http')) return null;
        $d = strtolower((string) parse_url($url, PHP_URL_HOST));
        $out = ['domain' => preg_replace('/^www\./', '', $d), 'title' => mb_substr($title, 0, 160)];
        try {
            $r = Http::timeout(8)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LevelUpGrowthBot/1.0; +https://levelupgrowth.io)'])->get($url);
            if ($r->successful() && str_contains((string) $r->header('Content-Type'), 'html')) {
                $h = mb_substr($r->body(), 0, 800000);
                $h = preg_replace('#<(script|style|nav|footer|header)\b.*?</\1>#is', ' ', $h);
                preg_match_all('#<h([23])[^>]*>(.*?)</h\1>#is', $h, $hm, PREG_SET_ORDER);
                $out['headings'] = array_slice(array_values(array_filter(array_map(fn ($x) => mb_substr(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($x[2])))), 0, 120), $hm))), 0, 25);
                $out['words'] = str_word_count(strip_tags($h));
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    private function chooseKeyword(int $wsId, ?object $w, ?object $biz, string $topic): string
    {
        if ($w) {
            $t = DB::table('search_targets')->where('website_id', $w->id)->where('status', 'target')->orderByDesc('score')->get(['keyword'])->first(function ($t) use ($topic) {
                $tw = array_filter(preg_split('/\W+/', strtolower($topic)), fn ($x) => mb_strlen($x) > 3);
                foreach ($tw as $x) if (str_contains($t->keyword, $x)) return true; return false;
            });
            if ($t) return $t->keyword;
        }
        $r = app(\App\Connectors\RuntimeClient::class)->chatJson('You are an SEO specialist. Give the one Google search (lowercase, 2-6 words, include the place when local) this article topic should target for this business. Return ONLY JSON {"search":""}',
            'JSON input: ' . json_encode(['topic' => $topic, 'business' => $biz->name ?? null, 'industry' => $biz->industry ?? null, 'place' => SearchSites::place($biz)]), ['task' => 'seo_keyword', 'workspace_id' => (string) $wsId], 120);
        return strtolower(OnPage::clean($r['parsed']['search'] ?? '', 120)) ?: strtolower(mb_substr($topic, 0, 80));
    }

    private function save(int $aid, array $bj, array $po): void
    {
        $bj['page_one'] = $po;
        DB::table('articles')->where('id', $aid)->update(['brief_json' => json_encode($bj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    private function localDate(int $wsId, Carbon $at): string
    {
        $tz = app(\App\Core\Sarah888\TemporalAnchor::class)->timezone($wsId);
        return $at->copy()->setTimezone($tz)->format('l j M');
    }

    private function tell(int $wsId, string $type, string $instruction, array $facts, string $fallback, array $meta = []): void
    {
        try {
            $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, $type, $instruction, array_filter($facts, fn ($v) => $v !== null && $v !== '' && $v !== []), $fallback);
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, $meta + ['notification_type' => $type]);
        } catch (\Throwable $e) { Log::info('[PAGE-ONE-1] tell failed', ['ws' => $wsId, 'type' => $type, 'e' => $e->getMessage()]); }
    }
}
