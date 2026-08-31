<?php

namespace App\Engines\Chatbot\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CHATBOT888 — Website Crawler.
 *
 * Pulls every URL in seo_content_index for a workspace, fetches the HTML,
 * extracts main content, chunks it into ~800-char windows, and stores rows
 * in chatbot_knowledge_sources (source_type='website_crawl') + chunks in
 * chatbot_knowledge_chunks. The chatbot's existing retrieveChunks pipeline
 * then surfaces them on every visitor turn.
 *
 * Design call:
 *   - One source row per crawled URL. Label = page title or URL path.
 *   - source_url = absolute URL. content_hash = sha256 of extracted text.
 *   - Re-running the crawl is idempotent: same URL + same hash → no-op.
 *     Different hash → replace chunks + bump updated_at.
 *   - website_crawl sources DO NOT count against the per-plan KB doc cap
 *     (FeatureGateService::chatbotKbDocLimit is for user-uploaded sources).
 *   - HTML extraction is mechanical (strip_tags + entity decode + whitespace
 *     normalise) — no LLM. Per `feedback_intelligence_belongs_in_runtime`,
 *     summarisation/topic extraction would move to runtime if added later.
 *
 * Cap defaults: 100 pages per crawl, 8s per page fetch, 20K chars per page
 * after extraction. Safe to run from a queue worker (no PHP-FPM timeout).
 */
class ChatbotWebsiteCrawler
{
    public const DEFAULT_MAX_PAGES   = 100;
    public const PAGE_TIMEOUT_SEC    = 8;
    public const MAX_TEXT_PER_PAGE   = 20000;
    private const CHUNK_SIZE         = 800;
    private const CHUNK_OVERLAP      = 100;

    /**
     * Skip URLs matching any of these. Catches WP drafts (?p=), admin paths,
     * non-content endpoints, and feed URLs.
     */
    private const URL_BLACKLIST_PATTERNS = [
        '/\?p=\d+/i',          // WP draft permalinks
        '#/wp-admin/#i',
        '#/wp-content/#i',
        '#/wp-includes/#i',
        '#/wp-json/#i',
        '#/feed/?$#i',
        '#/comments/feed/?$#i',
        '#\.(jpg|jpeg|png|gif|webp|pdf|zip|mp4|mp3|svg|ico)(\?|$)#i',
    ];

    /**
     * Run a full website crawl for a workspace. Synchronous — call from a job.
     *
     * @return array{
     *   total_urls:int, processed:int, skipped:int, ok:int, failed:int,
     *   sources_new:int, sources_updated:int, sources_unchanged:int
     * }
     */
    /**
     * INC-0006 - when a website is named, only that website's pages are crawled. seo_content_index
     * carries no website column, so the restriction is by host, which is how a page is attributed to
     * a site everywhere else in the codebase. Without it, crawling one site of a business re-ingested
     * every sibling site's pages into the same knowledge base.
     */
    public function crawlWorkspace(int $workspaceId, int $maxPages = self::DEFAULT_MAX_PAGES, ?int $websiteId = null): array
    {
        $report = [
            'total_urls' => 0, 'processed' => 0, 'skipped' => 0,
            'ok' => 0, 'failed' => 0,
            'sources_new' => 0, 'sources_updated' => 0, 'sources_unchanged' => 0,
        ];

        // Pull all indexed URLs for this workspace. Prefer pages with higher
        // word_count first so we hit the substantive content if we cap out.
        $urls = DB::table('seo_content_index')
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->orderByDesc('word_count')
            ->limit($maxPages * 3)              // overshoot — many will be skipped
            ->pluck('url')
            ->unique()
            ->values()
            ->all();

        if ($websiteId !== null && $websiteId > 0) {
            $site = DB::table('websites')->where('id', $websiteId)
                ->where('workspace_id', $workspaceId)->first(['subdomain', 'custom_domain', 'external_url']);
            $hosts = [];
            foreach ([$site->subdomain ?? null, $site->custom_domain ?? null] as $h) {
                if ($h) { $hosts[] = strtolower(preg_replace('/^www[.]/', '', (string) $h)); }
            }
            if (! empty($site->external_url)) {
                $h = strtolower((string) parse_url((string) $site->external_url, PHP_URL_HOST));
                if ($h) { $hosts[] = preg_replace('/^www[.]/', '', $h); }
            }
            if ($hosts) {
                $urls = array_values(array_filter($urls, function ($u) use ($hosts) {
                    $h = strtolower((string) parse_url((string) $u, PHP_URL_HOST));
                    $h = preg_replace('/^www[.]/', '', $h);
                    return in_array($h, $hosts, true);
                }));
            }
        }

        $report['total_urls'] = count($urls);

        foreach ($urls as $url) {
            if ($report['processed'] >= $maxPages) break;

            if ($this->shouldSkipUrl($url)) {
                $report['skipped']++;
                continue;
            }

            $report['processed']++;

            try {
                $outcome = $this->crawlOne($workspaceId, (string) $url);
                if ($outcome === 'new')        $report['sources_new']++;
                elseif ($outcome === 'updated') $report['sources_updated']++;
                else                            $report['sources_unchanged']++;
                $report['ok']++;
            } catch (\Throwable $e) {
                $report['failed']++;
                Log::warning('[chatbot-crawler] page failed', [
                    'workspace_id' => $workspaceId,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $report;
    }

    /**
     * Crawl a single URL. Returns 'new' | 'updated' | 'unchanged'.
     */
    public function crawlOne(int $workspaceId, string $url): string
    {
        $resp = Http::timeout(self::PAGE_TIMEOUT_SEC)
            ->withHeaders([
                'User-Agent' => 'LevelUpGrowthChatbotBot/1.0 (+https://levelupgrowth.io)',
                'Accept'     => 'text/html,application/xhtml+xml',
            ])
            ->withOptions(['allow_redirects' => true, 'verify' => true])
            ->get($url);

        if (! $resp->successful() || empty($resp->body())) {
            throw new \RuntimeException("HTTP {$resp->status()} on {$url}");
        }

        $html = (string) $resp->body();
        [$title, $text] = $this->extractTitleAndText($html);

        if (mb_strlen($text) < 100) {
            // Nothing meaningful extracted — likely 404 page, redirect stub,
            // or paywall splash. Don't pollute KB with thin content.
            throw new \RuntimeException('extracted text under 100 chars');
        }

        $text = mb_substr($text, 0, self::MAX_TEXT_PER_PAGE);
        $hash = hash('sha256', $text);
        $label = $title !== '' ? mb_substr($title, 0, 240) : (parse_url($url, PHP_URL_PATH) ?: $url);

        // B1 (2026-06-23) — resolve which website this URL belongs to so the
        // crawled KB is scoped per website (NULL = workspace-level fallback).
        $wid = $this->resolveWebsiteIdForUrl($workspaceId, $url);

        // Upsert by (workspace_id, source_url) — one row per URL.
        $existing = DB::table('chatbot_knowledge_sources')
            ->where('workspace_id', $workspaceId)
            ->where('source_url', $url)
            ->where('source_type', 'website_crawl')
            ->first();

        if ($existing && $existing->content_hash === $hash) {
            // Nothing changed — just bump updated_at.
            DB::table('chatbot_knowledge_sources')
                ->where('id', $existing->id)
                ->update(['updated_at' => now()]);
            return 'unchanged';
        }

        if ($existing) {
            DB::table('chatbot_knowledge_sources')->where('id', $existing->id)->update([
                'label'        => $label,
                'website_id'   => $wid,
                'raw_text'     => $text,
                'content_hash' => $hash,
                'size_bytes'   => strlen($text),
                'status'       => 'ready',
                'error_message'=> null,
                'updated_at'   => now(),
            ]);
            $sourceId = (int) $existing->id;
            $outcome  = 'updated';
        } else {
            $sourceId = DB::table('chatbot_knowledge_sources')->insertGetId([
                'workspace_id' => $workspaceId,
                'website_id'   => $wid,
                'source_type'  => 'website_crawl',
                'label'        => $label,
                'source_url'   => $url,
                'raw_text'     => $text,
                'size_bytes'   => strlen($text),
                'content_hash' => $hash,
                'status'       => 'ready',
                'chunk_count'  => 0,
                'created_at'   => now(), 'updated_at' => now(),
            ]);
            $outcome = 'new';
        }

        $this->reindexChunks($workspaceId, $sourceId, $text);
        return $outcome;
    }

    private function shouldSkipUrl(string $url): bool
    {
        foreach (self::URL_BLACKLIST_PATTERNS as $pat) {
            if (preg_match($pat, $url)) return true;
        }
        return false;
    }

    /**
     * Pull the page title + main text content out of HTML. Mechanical, no LLM.
     *
     * Strategy:
     *   1. Drop script/style/nav/header/footer/aside/form via DOMDocument.
     *   2. Prefer the first <main>, <article>, or [role=main] container; if
     *      none present, fall back to <body>.
     *   3. strip_tags, html_entity_decode, normalise whitespace.
     */
    private function extractTitleAndText(string $html): array
    {
        $title = '';
        $text  = '';

        // Suppress libxml warnings for messy HTML — we only need a best-effort parse.
        $prevUseErrors = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            // Force UTF-8 interpretation.
            $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
            if (! $loaded) {
                return [$this->fallbackTitle($html), $this->fallbackText($html)];
            }

            // Title
            $titles = $dom->getElementsByTagName('title');
            if ($titles->length > 0) {
                $title = trim((string) $titles->item(0)->textContent);
            }

            // Remove unwanted elements
            $xpath = new \DOMXPath($dom);
            $strip = $xpath->query('//script | //style | //nav | //header | //footer | //aside | //form | //noscript | //iframe | //*[@aria-hidden="true"]');
            foreach ($strip as $node) {
                $node->parentNode?->removeChild($node);
            }

            // Prefer main / article / role=main
            $mainCandidates = $xpath->query('(//main | //article | //*[@role="main"])[1]');
            $root = ($mainCandidates && $mainCandidates->length > 0)
                ? $mainCandidates->item(0)
                : ($dom->getElementsByTagName('body')->item(0) ?: $dom->documentElement);

            $rawText = $root ? (string) $root->textContent : strip_tags($html);
            $text = $this->normaliseWhitespace($rawText);
        } catch (\Throwable $e) {
            $text = $this->fallbackText($html);
            $title = $this->fallbackTitle($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);
        }

        return [$title, $text];
    }

    private function fallbackTitle(string $html): string
    {
        if (preg_match('#<title[^>]*>(.+?)</title>#is', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    private function fallbackText(string $html): string
    {
        $html = preg_replace('#<(script|style|nav|header|footer|aside|form|noscript|iframe)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        return $this->normaliseWhitespace(strip_tags($html));
    }

    private function normaliseWhitespace(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $this->sanitiseUtf8($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Strip invalid UTF-8 byte sequences + replace 4-byte characters with
     * their plain-ASCII fallback. The chatbot_knowledge_chunks.chunk_text
     * column is utf8 (3-byte), not utf8mb4 — a single 4-byte char (emoji,
     * some smart quotes, etc.) crashes the entire INSERT with
     * `SQLSTATE[HY000]: 1366 Incorrect string value`. Stripping them here
     * keeps the page content + skips only the offending characters.
     */
    private function sanitiseUtf8(string $text): string
    {
        // Drop bytes that aren't valid UTF-8 (e.g. raw Windows-1252 fragments).
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($clean === false) $clean = $text;

        // Replace 4-byte UTF-8 sequences (U+10000 and above — emojis, rare CJK,
        // etc.) with '?'. utf8 column can't hold them.
        $clean = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $clean) ?? $clean;

        return $clean;
    }

    /**
     * Re-chunk + replace chunks for this source. Same 800/100 pattern as
     * ChatbotKnowledgeService::indexChunks. Wrapped in a transaction so a
     * partial insert doesn't leave the source with zero chunks (the
     * partial-write hazard flagged in the 2026-05-28 audit).
     */
    /**
     * B1 (2026-06-23) — resolve which website a crawled URL belongs to so the KB
     * is scoped per website. Match the URL host against websites.subdomain /
     * custom_domain for the workspace; if the workspace has exactly one website,
     * default to it. NULL → workspace-level (shared) fallback.
     */
    private function resolveWebsiteIdForUrl(int $workspaceId, string $url): ?int
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host !== '') {
            $match = DB::table('websites')
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($host) {
                    $q->where('subdomain', $host)
                      ->orWhere('subdomain', 'like', '%' . $host . '%')
                      ->orWhere('custom_domain', $host);
                })
                ->value('id');
            if ($match) {
                return (int) $match;
            }
        }
        // Single-website workspace → unambiguous.
        $ids = DB::table('websites')->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')->limit(2)->pluck('id');
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private function reindexChunks(int $workspaceId, int $sourceId, string $text): void
    {
        $chunks = $this->chunkText($text);

        DB::transaction(function () use ($workspaceId, $sourceId, $chunks) {
            DB::table('chatbot_knowledge_chunks')->where('source_id', $sourceId)->delete();
            // B1 (2026-06-23) — chunks inherit website_id from their source.
            $websiteId = DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->value('website_id');

            if (! empty($chunks)) {
                $rows = [];
                foreach ($chunks as $i => $chunk) {
                    $rows[] = [
                        'source_id'    => $sourceId,
                        'workspace_id' => $workspaceId,
                        'website_id'   => $websiteId,
                        'chunk_index'  => $i,
                        'chunk_text'   => $chunk,
                        'char_count'   => strlen($chunk),
                        'created_at'   => now(),
                    ];
                }
                foreach (array_chunk($rows, 200) as $batch) {
                    DB::table('chatbot_knowledge_chunks')->insert($batch);
                }
            }

            DB::table('chatbot_knowledge_sources')
                ->where('id', $sourceId)
                ->update(['chunk_count' => count($chunks), 'updated_at' => now()]);
        });
    }

    private function chunkText(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];
        $chunks = [];
        $offset = 0;
        $len = strlen($text);
        while ($offset < $len) {
            $chunks[] = substr($text, $offset, self::CHUNK_SIZE);
            $offset += self::CHUNK_SIZE - self::CHUNK_OVERLAP;
        }
        return $chunks;
    }
}
