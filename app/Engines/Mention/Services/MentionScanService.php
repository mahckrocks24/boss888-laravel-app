<?php

namespace App\Engines\Mention\Services;

use App\Engines\Web\Services\WebActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MentionScanService — execute a single scan of one watchlist row.
 *
 * Flow:
 *   1. Load watchlist row (ws-scoped). Skip if !is_active.
 *   2. Open a brand_mention_scan_runs row in 'running' state.
 *   3. For each variant (or just the canonical term if no variants):
 *      a. Call WebActivityService.search → SERP results.
 *      b. For each result:
 *         - hash source_url (sha256)
 *         - if already in brand_mentions for this workspace → skip
 *         - apply negative_keywords filter against title + excerpt
 *         - extract source_domain, classify source_type
 *         - call MentionSentimentService.classify → sentiment + confidence
 *         - compute priority from sentiment × scope × source authority
 *         - INSERT INTO brand_mentions
 *   4. Close scan_runs row with totals (results_found, results_new, error?)
 *   5. Update watchlist.last_scanned_at
 *
 * Agent attribution: scans run on behalf of 'sarah' (the DMM) since this
 * is platform-orchestrated work. WebActivityService logs to
 * agent_web_activity as if Sarah had searched — meaning the user sees the
 * search in her transparency log, which is correct.
 */
class MentionScanService
{
    public const SOURCE_TYPES = ['news','blog','forum','review','qa','directory','social','other'];

    /** Map common domain patterns to source_type. Conservative — falls back to 'other'. */
    private const DOMAIN_SOURCE_MAP = [
        // reviews
        'trustpilot.com'  => 'review',
        'g2.com'          => 'review',
        'capterra.com'    => 'review',
        'glassdoor.com'   => 'review',
        'yelp.com'        => 'review',
        'tripadvisor.com' => 'review',
        // forums + Q&A
        'reddit.com'      => 'forum',
        'stackoverflow.com' => 'qa',
        'quora.com'       => 'qa',
        'producthunt.com' => 'forum',
        'ycombinator.com' => 'forum',
        // social
        'twitter.com'     => 'social',
        'x.com'           => 'social',
        'facebook.com'    => 'social',
        'linkedin.com'    => 'social',
        'instagram.com'   => 'social',
        'tiktok.com'      => 'social',
        'youtube.com'     => 'social',
        // directories
        'crunchbase.com'  => 'directory',
        'wikipedia.org'   => 'directory',
    ];

    public function __construct(
        private readonly WatchlistService $watchlist,
        private readonly MentionSentimentService $sentiment,
        private readonly WebActivityService $web,
    ) {}

    /**
     * Run a scan for a single watchlist row.
     *
     * Required: $wsId, $watchlistId
     * Optional $opts:
     *   - scan_type:     'manual' | 'scheduled' | 'backfill' (default 'manual')
     *   - max_results:   int (default 20 per variant, hard-capped at 50)
     *   - sentiment_mode: 'full' (default) | 'rule_only' | 'skip'
     *      full      = rule-based fast path + LLM fallback for ambiguous
     *      rule_only = rule-based only; ambiguous excerpts → neutral
     *      skip      = no classification (sentiment='unknown')
     *   - skip_sentiment: legacy boolean — treated as sentiment_mode='rule_only'
     *      when true. Kept for the existing route closure compatibility.
     *   - user_id:       int — for audit attribution
     *   - agent_slug:    string (default 'sarah')
     */
    public function scanWatchlist(int $wsId, int $watchlistId, array $opts = []): array
    {
        // LAUNCH SCOPE (2026-07-20) — removed capability execution hard-stop.
        return ['success' => false, 'error' => 'Social listening is not available in the current plan.', 'code' => 'LAUNCH_SCOPE_REMOVED_ENGINE'];
        $get = $this->watchlist->get($wsId, $watchlistId);
        if (empty($get['success'])) {
            return ['success' => false, 'error' => 'watchlist row not found'];
        }
        $row = $get['data'];
        if (empty($row['is_active'])) {
            return ['success' => false, 'error' => 'watchlist row is inactive'];
        }

        $scanType   = $opts['scan_type']   ?? 'manual';
        $maxResults = max(1, min(50, (int) ($opts['max_results'] ?? 20)));
        $userId     = $opts['user_id']     ?? null;
        $agentSlug  = $opts['agent_slug']  ?? 'sarah';
        // Sentiment mode resolution. Explicit sentiment_mode wins; the
        // legacy skip_sentiment flag maps to 'rule_only' (rule-based fast
        // path only, no LLM call → no runtime credits).
        $sentMode = (string) ($opts['sentiment_mode'] ?? (($opts['skip_sentiment'] ?? false) ? 'rule_only' : 'full'));
        if (!in_array($sentMode, ['full', 'rule_only', 'skip'], true)) $sentMode = 'full';

        // Build the variant list (canonical term always included first)
        $variants = $this->buildVariantList($row['term'], $row['variants'] ?? []);
        $negKeywords = array_map('mb_strtolower', $row['negative_keywords'] ?? []);
        $blockedDomains = $row['metadata']['blocked_domains'] ?? [];

        // ── Open scan_run audit ──────────────────────────────────────────
        $now = now();
        $runId = DB::table('brand_mention_scan_runs')->insertGetId([
            'workspace_id' => $wsId,
            'watchlist_id' => $watchlistId,
            'scan_type'    => $scanType,
            'started_at'   => $now,
            'metadata_json'=> json_encode([
                'variants'       => $variants,
                'max_results'    => $maxResults,
                'sentiment_mode' => $sentMode,
            ]),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $found = 0;
        $new   = 0;
        $skippedDupe = 0;
        $skippedFilter = 0;
        $skippedBlocked = 0;
        $errors = [];
        $totalCreditsCharged = 0;
        $backend = null;

        try {
            foreach ($variants as $variant) {
                $resp = $this->web->search($wsId, $agentSlug, $userId, $variant);
                if (empty($resp['success'])) {
                    $errors[] = "search '{$variant}' failed: " . ($resp['error'] ?? 'unknown');
                    continue;
                }
                $totalCreditsCharged += (int) ($resp['cost'] ?? 0);
                $payload = $resp['data'] ?? [];
                if (!$backend) $backend = (string) ($payload['backend'] ?? 'unknown');

                $results = array_slice((array) ($payload['results'] ?? []), 0, $maxResults);
                $found += count($results);

                foreach ($results as $r) {
                    $url   = trim((string) ($r['url']   ?? ''));
                    $title = trim((string) ($r['title'] ?? ''));
                    $snippet = trim((string) ($r['snippet'] ?? $r['description'] ?? ''));
                    if ($url === '') continue;

                    // Block list (workspace-scoped)
                    $domain = $this->extractDomain($url);
                    if ($domain && $this->isBlocked($domain, $blockedDomains)) {
                        $skippedBlocked++;
                        continue;
                    }

                    // Negative-keyword filter (against title + snippet)
                    if ($this->hitsNegativeKeyword($title . ' ' . $snippet, $negKeywords)) {
                        $skippedFilter++;
                        continue;
                    }

                    $urlHash = hash('sha256', $url);

                    // Dedup: same (ws, url_hash) already exists?
                    $exists = DB::table('brand_mentions')
                        ->where('workspace_id', $wsId)
                        ->where('source_url_hash', $urlHash)
                        ->exists();
                    if ($exists) { $skippedDupe++; continue; }

                    // Sentiment classification
                    $sentResult = ['sentiment' => 'unknown', 'confidence' => null, 'method' => 'skipped'];
                    if ($sentMode !== 'skip' && $snippet !== '') {
                        $sentResult = $this->sentiment->classify(
                            $row['label'] ?: $row['term'],
                            $snippet,
                            ['skip_llm' => $sentMode === 'rule_only']
                        );
                    }

                    $sourceType = $this->classifySourceType($domain);
                    $priority   = $this->derivePriority($sentResult['sentiment'], $row['priority'] ?? 'normal');

                    DB::table('brand_mentions')->insert([
                        'workspace_id'         => $wsId,
                        'watchlist_id'         => $watchlistId,
                        'term_matched'         => mb_substr($variant, 0, 200),
                        'source_url'           => mb_substr($url, 0, 500),
                        'source_url_hash'      => $urlHash,
                        'source_title'         => mb_substr($title, 0, 512) ?: null,
                        'source_domain'        => $domain,
                        'source_type'          => $sourceType,
                        'excerpt'              => $snippet !== '' ? mb_substr($snippet, 0, 1000) : null,
                        'published_at'         => $this->parsePublishedAt($r['published_at'] ?? null),
                        'discovered_at'        => $now,
                        'sentiment'            => $sentResult['sentiment'],
                        'sentiment_confidence' => $sentResult['confidence'],
                        'priority'             => $priority,
                        'status'               => 'new',
                        'metadata_json'        => json_encode([
                            'serp_result'      => array_intersect_key($r, array_flip(['url','title','snippet','description','position','source'])),
                            'sentiment_method' => $sentResult['method'] ?? null,
                            'sentiment_rationale' => $sentResult['rationale'] ?? null,
                            'variant_matched'  => $variant,
                        ]),
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ]);
                    $new++;
                }
            }

            // Close scan run
            DB::table('brand_mention_scan_runs')->where('id', $runId)->update([
                'finished_at'    => now(),
                'results_found'  => $found,
                'results_new'    => $new,
                'credits_charged'=> $totalCreditsCharged,
                'backend_used'   => $backend,
                'error'          => $errors ? implode(' | ', array_slice($errors, 0, 3)) : null,
                'metadata_json'  => json_encode([
                    'variants'         => $variants,
                    'max_results'      => $maxResults,
                    'sentiment_mode'   => $sentMode,
                    'skipped_dupe'     => $skippedDupe,
                    'skipped_filter'   => $skippedFilter,
                    'skipped_blocked'  => $skippedBlocked,
                ]),
                'updated_at'     => now(),
            ]);

            // Stamp last_scanned_at on the watchlist row.
            //
            // RETRY-LOCKOUT FIX (2026-07-18) — this used to stamp
            // UNCONDITIONALLY, including on a run where every search errored.
            // MentionScanDailyCommand.php:113-116 then excludes anything scanned
            // in the last 24h, so a failed scan locked itself out of retry for a
            // full day. That is exactly how the 2026-06-20 -> 2026-07-18 outage
            // stayed invisible for 28 days: fail at 00:00, stamp, lock out,
            // repeat — 58 consecutive failures with no recovery path.
            // (Evidence: watchlist row 34 last_scanned_at = 2026-07-18 00:00:16,
            // the exact timestamp of that day's recorded error.)
            //
            // Now: only stamp when the scan actually produced something. A
            // total-failure run leaves last_scanned_at untouched so the next
            // cycle retries it — which matters because the dominant failure
            // (DataForSEO 40101 "Internal SE Server Error") is TRANSIENT.
            $producedResults = ($found > 0) || ($new > 0);
            $totalFailure    = ! empty($errors) && ! $producedResults;

            if (! $totalFailure) {
                DB::table('brand_watchlist')->where('id', $watchlistId)
                    ->update(['last_scanned_at' => now(), 'updated_at' => now()]);
            } else {
                Log::warning('[MentionScan] total failure — last_scanned_at NOT stamped, will retry next cycle', [
                    'workspace_id' => $wsId,
                    'watchlist_id' => $watchlistId,
                    'errors'       => array_slice($errors, 0, 3),
                ]);
            }

            return [
                'success'         => empty($errors) || $new > 0,
                'scan_run_id'     => $runId,
                'results_found'   => $found,
                'results_new'     => $new,
                'skipped_dupe'    => $skippedDupe,
                'skipped_filter'  => $skippedFilter,
                'skipped_blocked' => $skippedBlocked,
                'credits_charged' => $totalCreditsCharged,
                'errors'          => $errors,
            ];
        } catch (\Throwable $e) {
            Log::error('[MentionScan] unexpected error', [
                'workspace_id' => $wsId,
                'watchlist_id' => $watchlistId,
                'error' => $e->getMessage(),
            ]);
            DB::table('brand_mention_scan_runs')->where('id', $runId)->update([
                'finished_at' => now(),
                'error'       => mb_substr($e->getMessage(), 0, 500),
                'updated_at'  => now(),
            ]);
            return ['success' => false, 'error' => $e->getMessage(), 'scan_run_id' => $runId];
        }
    }

    /** Build deduped variant list (term first, then variants). */
    private function buildVariantList(string $term, array $variants): array
    {
        $out = [trim($term)];
        foreach ($variants as $v) {
            $vt = trim((string) $v);
            if ($vt !== '' && !in_array($vt, $out, true)) $out[] = $vt;
        }
        return array_slice($out, 0, 8); // hard cap per-scan to avoid runaway
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return '';
        return strtolower(preg_replace('/^www\./', '', (string) $host));
    }

    private function isBlocked(string $domain, array $blockedDomains): bool
    {
        foreach ($blockedDomains as $b) {
            $b = strtolower(trim((string) $b));
            if ($b === '') continue;
            if ($domain === $b || str_ends_with($domain, '.' . $b)) return true;
        }
        return false;
    }

    private function hitsNegativeKeyword(string $text, array $negKeywords): bool
    {
        if (empty($negKeywords)) return false;
        $haystack = mb_strtolower($text);
        foreach ($negKeywords as $kw) {
            if ($kw === '') continue;
            if (str_contains($haystack, $kw)) return true;
        }
        return false;
    }

    private function classifySourceType(string $domain): string
    {
        if (!$domain) return 'other';
        // Direct match
        if (isset(self::DOMAIN_SOURCE_MAP[$domain])) return self::DOMAIN_SOURCE_MAP[$domain];
        // Suffix match for known patterns
        foreach (self::DOMAIN_SOURCE_MAP as $needle => $type) {
            if (str_ends_with($domain, '.' . $needle)) return $type;
        }
        // Heuristic by keyword in domain
        if (str_contains($domain, 'blog') || str_contains($domain, 'medium.com')) return 'blog';
        if (str_contains($domain, 'news')) return 'news';
        if (str_contains($domain, 'review')) return 'review';
        return 'other';
    }

    /**
     * Priority derivation:
     *   - watchlist priority is the floor (low/normal/high)
     *   - negative sentiment escalates by one step
     *   - high-confidence positive can stay at normal (user prefers triaging issues)
     */
    private function derivePriority(string $sentiment, string $watchlistPriority): string
    {
        $order = ['low' => 0, 'normal' => 1, 'high' => 2];
        $reverse = array_flip($order);
        $base = $order[$watchlistPriority] ?? 1;

        if ($sentiment === 'negative') $base = min(2, $base + 1);
        if ($sentiment === 'mixed' && $base === 0) $base = 1; // mixed bumps low → normal

        return $reverse[$base];
    }

    private function parsePublishedAt($raw): ?string
    {
        if (!$raw) return null;
        try {
            $ts = is_numeric($raw) ? (int) $raw : strtotime((string) $raw);
            if (!$ts) return null;
            return date('Y-m-d H:i:s', $ts);
        } catch (\Throwable $e) {
            return null;
        }
    }
}