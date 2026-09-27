<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PAGE-ONE-1 (Owner 2026-09-27: "whenever there are new pages added on the website, make sure that all of them gets
 * added on sitemap and gets optimized accordingly").
 *
 * Every 15 minutes, for every published website:
 *   1. list every public URL the site serves (pages + articles in its scope; a WordPress site's own sitemap)
 *   2. the first look records them as the baseline; anything after that is NEW
 *   3. a new URL is checked against the live sitemap.xml, told to search engines (IndexNow), and given the on-page pass
 *      (search target, title, description, alt text, structured data)
 *   4. the owner hears it in Sarah's chat, once per batch, in plain words
 * Daily, URLs 7+ days old that Google has not confirmed are inspected (Search Console, when connected).
 */
final class NewPages
{
    public function __construct(private SearchNotifier $notifier, private OnPage $onPage) {}

    /** @return array{new:int, gone:int, missing_from_sitemap:int, notified:int} */
    public function scan(object $w, bool $announce = true): array
    {
        $out = ['new' => 0, 'gone' => 0, 'missing_from_sitemap' => 0, 'notified' => 0];
        $urls = SearchSites::urls($w);
        if (! $urls) return $out;
        $first = ! DB::table('search_pages')->where('website_id', $w->id)->exists();
        $known = DB::table('search_pages')->where('website_id', $w->id)->pluck('gone_at', 'url_hash')->all();
        $new = [];
        foreach ($urls as $u => $x) {
            $h = sha1(SearchSites::norm($u));
            if (array_key_exists($h, $known)) {
                if ($known[$h] !== null) DB::table('search_pages')->where('website_id', $w->id)->where('url_hash', $h)->update(['gone_at' => null, 'updated_at' => now()]);
                continue;
            }
            DB::table('search_pages')->insertOrIgnore(['workspace_id' => $w->workspace_id, 'website_id' => $w->id, 'url' => mb_substr($u, 0, 700), 'url_hash' => $h, 'kind' => $x['kind'],
                'ref_id' => $x['ref_id'], 'title' => $x['title'] ? mb_substr((string) $x['title'], 0, 300) : null, 'baseline' => $first, 'first_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if (! $first) $new[$u] = $x;
        }
        // gone: no longer served
        $live = array_map(fn ($u) => sha1(SearchSites::norm($u)), array_keys($urls));
        $out['gone'] = DB::table('search_pages')->where('website_id', $w->id)->whereNull('gone_at')->whereNotIn('url_hash', $live)->update(['gone_at' => now(), 'updated_at' => now()]);

        if ($first) {
            // the baseline: tell search engines about everything once, and note what the live sitemap lists
            $this->checkSitemap($w, array_keys($urls));
            $n = $this->notifier->indexNow($w, array_keys($urls));
            if ($n['ok']) DB::table('search_pages')->where('website_id', $w->id)->whereNull('notified_at')->update(['notified_at' => now(), 'notify_result' => 'indexnow:' . $n['http']]);
            Log::info('[PAGE-ONE-1] baseline', ['website' => $w->id, 'urls' => count($urls), 'indexnow' => $n]);
            return $out;
        }
        if (! $new) return $out;
        $out['new'] = count($new);
        $out['missing_from_sitemap'] = $this->checkSitemap($w, array_keys($new));
        $n = $this->notifier->indexNow($w, array_keys($new));
        $hashes = array_map(fn ($u) => sha1(SearchSites::norm($u)), array_keys($new));
        DB::table('search_pages')->where('website_id', $w->id)->whereIn('url_hash', $hashes)->update(['notified_at' => $n['ok'] ? now() : null, 'notify_result' => $n['ok'] ? 'indexnow:' . $n['http'] : 'indexnow_failed', 'updated_at' => now()]);
        $out['notified'] = $n['sent'];
        // optimize on arrival, then tell the owner (one message for the batch)
        \App\Jobs\SearchArrivalJob::dispatch((int) $w->id, $hashes, $announce)->delay(now()->addSeconds(20));
        return $out;
    }

    /** Mark which URLs the live sitemap lists. @return int how many are missing */
    public function checkSitemap(object $w, array $urls): int
    {
        $host = SearchSites::host($w);
        if (! $host || SearchSites::isWp($w)) return 0;
        $locs = array_flip(array_map([SearchSites::class, 'norm'], SearchSites::sitemapLocs('https://' . $host . '/sitemap.xml')));
        if (! $locs) return 0;   // the sitemap could not be read right now — do not mark anything missing on a failed read
        $missing = 0;
        foreach ($urls as $u) {
            $in = isset($locs[SearchSites::norm($u)]);
            if (! $in) $missing++;
            DB::table('search_pages')->where('website_id', $w->id)->where('url_hash', sha1(SearchSites::norm($u)))->update(['in_sitemap' => $in, 'sitemap_checked_at' => now()]);
        }
        if ($missing) Log::warning('[PAGE-ONE-1] URLs served but missing from the sitemap', ['website' => $w->id, 'missing' => $missing]);
        return $missing;
    }

    /** The on-page pass for the new URLs of one site, then one chat message. Runs in SearchArrivalJob. */
    public function arrive(int $websiteId, array $hashes, bool $announce): array
    {
        $w = DB::table('websites')->where('id', $websiteId)->first();
        if (! $w) return [];
        $done = [];
        foreach (DB::table('search_pages')->where('website_id', $websiteId)->whereIn('url_hash', $hashes)->whereNull('optimized_at')->get() as $p) {
            $r = ['changed' => [], 'target' => null];
            try {
                if ($p->kind === 'article' && $p->ref_id) $r = $this->onPage->article((int) $p->ref_id);
                elseif ($p->kind === 'page' && $p->ref_id) $r = $this->onPage->page((int) $p->ref_id);
            } catch (\Throwable $e) { Log::warning('[PAGE-ONE-1] on-arrival pass failed', ['page' => $p->id, 'e' => $e->getMessage()]); }
            DB::table('search_pages')->where('id', $p->id)->update(['optimized_at' => now(), 'target_query' => $r['target'] ? mb_substr($r['target'], 0, 200) : null,
                'optimize_json' => json_encode(['on_arrival' => $r['changed']]), 'updated_at' => now()]);
            $done[] = ['ref_id' => $p->ref_id, 'title' => $p->title, 'url' => $p->url, 'kind' => $p->kind, 'target' => $r['target'], 'changed' => $r['changed'], 'in_sitemap' => $p->in_sitemap];
        }
        if ($announce && $done) $this->announce($w, $done);
        return $done;
    }

    private function announce(object $w, array $done): void
    {
        $articles = array_values(array_filter($done, fn ($d) => $d['kind'] === 'article'));
        $pages = array_values(array_filter($done, fn ($d) => $d['kind'] !== 'article'));
        // an article Sarah's team published is announced by the article flow itself; here: pages, and articles the owner added
        $ownArticles = array_values(array_filter($articles, fn ($d) => ! \Illuminate\Support\Facades\Cache::has('page-one-told:' . $d['ref_id'])));
        $list = array_slice(array_merge($pages, $ownArticles), 0, 5);
        if (! $list) return;
        $facts = ['website' => $w->name, 'new' => array_map(fn ($d) => array_filter(['title' => $d['title'], 'link' => $d['url'], 'found_for' => $d['target'], 'in_sitemap' => $d['in_sitemap'] === null ? null : (bool) $d['in_sitemap']], fn ($v) => $v !== null && $v !== ''), $list), 'more' => max(0, count($done) - count($list))];
        $one = $list[0];
        $fallback = count($list) === 1
            ? 'Your new page "' . $one['title'] . '" is live, in your sitemap and search engines have been told about it. I set it up to be found' . ($one['target'] ? ' for "' . $one['target'] . '"' : '') . ' — title, description and the details Google reads.'
            : count($list) . ' new pages on ' . $w->name . ' are live, in your sitemap and search engines have been told. I set each one up to be found — titles, descriptions and the details Google reads.';
        try {
            $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords((int) $w->workspace_id, 'search_new_pages',
                "Write Sarah's short chat message (1-3 sentences) telling the owner that the new page(s) on their website are live, listed in the sitemap, search engines were told, and she set each up to be found (for the search in found_for, when given) — title, description and the behind-the-scenes details search engines read. Name the page(s) and include the link(s). If in_sitemap is false for one, say plainly it is not in the sitemap yet and she is on it. Plain words, no jargon beyond 'sitemap', no emojis.",
                $facts, $fallback);
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent((int) $w->workspace_id, 'sarah', $words, ['notification_type' => 'search_new_pages', 'website_id' => (int) $w->id]);
            DB::table('search_pages')->where('website_id', $w->id)->whereIn('url', array_column($list, 'url'))->update(['told_at' => now()]);
        } catch (\Throwable $e) { Log::info('[PAGE-ONE-1] announce failed', ['website' => $w->id, 'e' => $e->getMessage()]); }
    }

    /** Daily: URLs 7+ days old Google has not confirmed yet (Search Console connected). @return int inspected */
    public function inspect(object $w, int $max = 25): int
    {
        if (! $this->notifier->gscProperty($w)) return 0;
        $rows = DB::table('search_pages')->where('website_id', $w->id)->whereNull('gone_at')->where('first_seen_at', '<=', now()->subDays(7))
            ->where(fn ($q) => $q->whereNull('indexed')->orWhere('indexed', 0))
            ->where(fn ($q) => $q->whereNull('index_checked_at')->orWhere('index_checked_at', '<=', now()->subDays(3)))
            ->orderBy('baseline')->orderByDesc('first_seen_at')->limit($max)->get(['id', 'url']);
        $n = 0;
        foreach ($rows as $r) {
            $res = $this->notifier->inspect($w, $r->url);
            if ($res === null) break;
            DB::table('search_pages')->where('id', $r->id)->update(['indexed' => $res['indexed'], 'index_state' => $res['state'], 'index_checked_at' => now(), 'updated_at' => now()]);
            $n++;
        }
        return $n;
    }
}
