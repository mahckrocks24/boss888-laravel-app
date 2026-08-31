<?php

namespace App\Engines\SEO\Services;

use App\Core\Intelligence\EngineIntelligenceService;
use App\Core\Intelligence\GlobalKnowledgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * SEO Engine Service — 15 verified tools + settings/redirects/404/snapshots/activity.
 *
 * Tools:
 *   1. serp_analysis     — Analyze SERP for keyword/URL
 *   2. ai_report         — AI-generated comprehensive SEO report
 *   3. deep_audit        — Full technical site audit
 *   4. improve_draft     — SEO-optimize existing content (delegates to Write engine)
 *   5. write_article     — Write SEO-optimized article (delegates to Write engine)
 *   6. ai_status         — Check AI processing status
 *   7. link_suggestions  — Find internal linking opportunities
 *   8. insert_link       — Mark a link as inserted
 *   9. dismiss_link      — Dismiss a link suggestion
 *  10. outbound_links    — List all outbound links
 *  11. check_outbound    — Check outbound link health
 *  12. autonomous_goal   — Set autonomous SEO goal
 *  13. agent_status      — Check SEO agent status
 *  14. list_goals        — List all SEO goals
 *  15. pause_goal        — Pause a goal
 *
 * All tools flow through EngineExecutionService for credits/approvals/intelligence.
 */
class SeoService
{
    /**
     * F7 (2026-05-17) — scoring formula version. Bump when weights/factors
     * change so seo_content_index rows can be flagged stale and recomputed.
     *   v1 = original 9-factor base + 4-factor extended (weights sum to 113)
     *   v2 = rebalanced to sum-to-100; H1 placeholder rejection; readability
     *        persisted to column; full structural inputs on syncFromArticle.
     */
    public const SCORE_VERSION = 2;

    public function __construct(
        private EngineIntelligenceService $engineIntel,
        private GlobalKnowledgeService $globalKnowledge,
        private \App\Connectors\DataForSeoConnector $dataForSeo,
    ) {}

    // ═══════════════════════════════════════════════════════════
    // TOOL 1: SERP ANALYSIS
    // ═══════════════════════════════════════════════════════════

    /**
     * Best-effort keyword extraction from free-text task description.
     * Used when Sarah dispatches serp_analysis without an explicit keyword param.
     * Strips common imperative prefixes and trailing context noise.
     */
    private static function _extractKeywordFromText(string $text): string
    {
        if (empty($text)) return '';
        $text = preg_replace(
            '/^(perform|run|do|execute|analyse|analyze|conduct)\s+(a\s+)?(serp|seo|keyword|search)\s+(analysis|research|audit|check)\s+(for\s+)?/i',
            '', $text
        );
        $text = preg_replace(
            '/\s+(blog\s+)?(topics?|keywords?|content|pages?|articles?|posts?).*$/i',
            '', $text
        );
        return trim(substr($text, 0, 150));
    }

    public function serpAnalysis(int $wsId, array $params): array
    {
        // FIX 2026-05-11 (sprint): fall through to target_keyword + extract from
        // description/title when Sarah dispatches via Orchestrator without an
        // explicit keyword param. Return structured retryable:false on miss so
        // the Orchestrator skips retry instead of hammering 3×.
        $keyword = $params['keyword']
                ?? $params['url']
                ?? $params['target_keyword']
                ?? self::_extractKeywordFromText(
                       $params['description'] ?? $params['title'] ?? ''
                   );
        if (empty($keyword)) {
            return [
                'success'   => false,
                'error'     => 'keyword_required',
                'message'   => 'Keyword or URL is required for SERP analysis.',
                'retryable' => false,
            ];
        }

        // Create audit record
        $auditId = DB::table('seo_audits')->insertGetId([
            'workspace_id' => $wsId,
            'url' => $keyword,
            'type' => 'serp',
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Perform SERP analysis
        // In production: calls SerpAPI, DataForSEO, or similar
        // Current: structured analysis framework ready for API integration
        $results = $this->performSerpAnalysis($keyword, $params);

        // Store results
        DB::table('seo_audits')->where('id', $auditId)->update([
            'status' => 'completed',
            'score' => $results['opportunity_score'] ?? null,
            'results_json' => json_encode($results),
            'issues_json' => json_encode($results['opportunities'] ?? []),
            'updated_at' => now(),
        ]);

        // Store SERP results in seo_serp_results for historical tracking.
        // FIX 2026-04-13 (Phase 2E.0): provide audit_id (was missing — caused
        // every insert to fail the NOT NULL constraint, silently). The schema
        // sweep migration also added all the snapshot columns this insert uses.
        try {
            DB::table('seo_serp_results')->insert([
                'audit_id' => $auditId,
                'workspace_id' => $wsId,
                'keyword' => $keyword,
                'position' => $results['current_position'] ?? null,
                'url' => $params['url'] ?? null,
                'domain' => isset($params['url'])
                    ? preg_replace('/^www\./', '', parse_url($params['url'], PHP_URL_HOST) ?? '')
                    : null,
                'snippet' => $results['top_competitors'][0]['domain'] ?? null,
                'features' => json_encode($results['serp_features'] ?? []),
                'volume' => $results['estimated_volume'] ?? null,
                'difficulty' => $results['difficulty'] ?? null,
                'cpc' => $results['cpc'] ?? null,
                'results_json' => json_encode($results),
                'checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2026-05-12: persist one row per competitor so /competitors can
            // aggregate domains across SERPs. Each competitor gets its own
            // row keyed by audit + position + domain.
            $competitors = $results['top_competitors'] ?? [];
            foreach ($competitors as $idx => $comp) {
                $compUrl  = $comp['url'] ?? $comp['domain'] ?? '';
                $compHost = preg_replace('/^www\./', '', parse_url($compUrl, PHP_URL_HOST) ?? '');
                if (!$compHost) { continue; }
                DB::table('seo_serp_results')->insert([
                    'audit_id'     => $auditId,
                    'workspace_id' => $wsId,
                    'keyword'      => $keyword,
                    'position'     => $idx + 1,
                    'rank'         => $idx + 1,
                    'url'          => $compUrl,
                    'domain'       => $compHost,
                    'title'        => $comp['title'] ?? null,
                    'snippet'      => $comp['snippet'] ?? null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            // 2026-05-12: FIX 5 — auto-update seo_keywords.current_rank when
            // the SERP query matches a tracked keyword and our site appears
            // in the results.
            try {
                $tracked = DB::table('seo_keywords')
                    ->where('workspace_id', $wsId)
                    ->whereRaw('LOWER(keyword) = LOWER(?)', [$keyword])
                    ->first();
                if ($tracked) {
                    $siteUrl = DB::table('seo_settings')->where('workspace_id', $wsId)
                        ->where('key', 'site_url')->value('value');
                    $siteHost = $siteUrl
                        ? preg_replace('/^www\./', '', parse_url($siteUrl, PHP_URL_HOST) ?? '')
                        : null;
                    $ourPosition = null;
                    if ($siteHost) {
                        foreach ($competitors as $idx => $comp) {
                            $compUrl = $comp['url'] ?? $comp['domain'] ?? '';
                            $compHost = preg_replace('/^www\./', '', parse_url($compUrl, PHP_URL_HOST) ?? '');
                            if ($compHost === $siteHost) { $ourPosition = $idx + 1; break; }
                        }
                    }
                    if ($ourPosition === null && !empty($results['current_position'])) {
                        $ourPosition = (int) $results['current_position'];
                    }
                    if ($ourPosition !== null) {
                        DB::table('seo_keywords')->where('id', $tracked->id)->update([
                            'previous_rank'   => $tracked->current_rank,
                            'current_rank'    => $ourPosition,
                            'rank_change'     => ($tracked->current_rank ?? $ourPosition) - $ourPosition,
                            'last_rank_check' => now(),
                            'updated_at'      => now(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('[SEO] Rank tracking update failed: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not store SERP result', ['error' => $e->getMessage()]);
        }

        // Record to intelligence
        $this->engineIntel->recordToolUsage('seo', 'serp_analysis', ($results['opportunity_score'] ?? 50) / 100);

        // If keyword not tracked, auto-track it
        $existing = DB::table('seo_keywords')->where('workspace_id', $wsId)->where('keyword', $keyword)->first();
        if (!$existing && strlen($keyword) < 100 && !filter_var($keyword, FILTER_VALIDATE_URL)) {
            $this->addKeyword($wsId, [
                'keyword' => $keyword,
                'volume' => $results['estimated_volume'] ?? null,
                'difficulty' => $results['difficulty'] ?? null,
            ]);
        }

        // Log activity
        $this->logActivity($wsId, null, 'serp_analysis', 'audit', $auditId, ['keyword' => $keyword]);

        return ['audit_id' => $auditId, 'status' => 'completed', 'results' => $results];
    }

    // ═══════════════════════════════════════════════════════════
    // TOOL 2: AI SEO REPORT
    // ═══════════════════════════════════════════════════════════

    public function aiReport(int $wsId, array $params): array
    {
        $url = $params['url'] ?? '';
        if (empty($url)) throw new \InvalidArgumentException('URL required');

        // 2026-05-14 Phase 2 — cache hit: return prior report for this URL if
        // generated within the last 24h. Bust manually via DELETE /seo/ai-report/cache.
        $cached = DB::table('seo_ai_reports')
            ->where('workspace_id', $wsId)
            ->where('report_type', 'page')
            ->where('context_key', $url)
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->first();
        if ($cached) {
            $payload = json_decode($cached->report_json, true) ?: [];
            $payload['_cached']    = true;
            $payload['_cached_at'] = (string) $cached->created_at;
            return $payload;
        }

        $auditId = DB::table('seo_audits')->insertGetId([
            'workspace_id' => $wsId, 'url' => $url,
            'type' => 'ai_report', 'status' => 'running',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Gather all available data for this URL
        $existingAudits = DB::table('seo_audits')->where('workspace_id', $wsId)
            ->where('url', 'like', "%{$url}%")->where('status', 'completed')
            ->orderByDesc('created_at')->limit(5)->get();

        $keywords = DB::table('seo_keywords')->where('workspace_id', $wsId)
            ->where('target_url', $url)->get();

        // Real counts from DB to inform scoring
        $kwCount = DB::table('seo_keywords')->where('workspace_id', $wsId)->count();
        $auditCount = DB::table('seo_audits')->where('workspace_id', $wsId)->where('status', 'completed')->count();
        $linkCount = DB::table('seo_links')->where('workspace_id', $wsId)->count();

        // Build comprehensive report structure
        $report = [
            'url' => $url,
            'generated_at' => now()->toISOString(),
            'data_basis' => [
                'keywords_in_db' => $kwCount,
                'completed_audits' => $auditCount,
                'links_tracked' => $linkCount,
                'prior_audits_for_url' => $existingAudits->count(),
            ],
            'sections' => [
                'executive_summary' => $this->generateExecutiveSummary($url, $existingAudits, $keywords),
                'technical_health' => $this->assessTechnicalHealth($url),
                'content_quality' => $this->assessContentQuality($url, $keywords),
                'keyword_performance' => $this->assessKeywordPerformance($wsId, $url),
                'backlink_profile' => $this->assessBacklinkProfile($url),
                'competitor_landscape' => $this->assessCompetitorLandscape($wsId, $url),
                'recommendations' => $this->generateRecommendations($url, $existingAudits),
            ],
            'overall_score' => null, // calculated below
        ];

        // Calculate overall score from section scores, weighted by real data availability
        $sectionScores = array_filter(array_map(fn($s) => $s['score'] ?? null, $report['sections']));
        if (!empty($sectionScores)) {
            // Boost confidence when we have real data
            $dataBonus = min(5, $kwCount + $auditCount);
            $rawAvg = array_sum($sectionScores) / count($sectionScores);
            $report['overall_score'] = (int) round(min(100, $rawAvg + $dataBonus));
        } else {
            $report['overall_score'] = 50;
        }

        DB::table('seo_audits')->where('id', $auditId)->update([
            'status' => 'completed', 'score' => $report['overall_score'],
            'results_json' => json_encode($report), 'updated_at' => now(),
        ]);

        $this->engineIntel->recordToolUsage('seo', 'ai_report', $report['overall_score'] / 100);

        // Log activity
        $this->logActivity($wsId, null, 'ai_report', 'audit', $auditId, ['url' => $url, 'score' => $report['overall_score']]);

        $result = ['audit_id' => $auditId, 'status' => 'completed', 'report' => $report];

        // 2026-05-14 Phase 2 — cache the report by URL with 24h TTL semantic.
        // Subsequent identical-URL calls return this row until DELETE
        // /seo/ai-report/cache wipes the workspace's cache or 24h elapses.
        try {
            DB::table('seo_ai_reports')->insert([
                'workspace_id' => $wsId,
                'audit_id'     => $auditId,
                'report_type'  => 'page',
                'context_key'  => $url,
                'report_json'  => json_encode($result),
                'tokens_used'  => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('[SEO] ai_report cache insert failed: ' . $e->getMessage());
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════
    // TOOL 3: DEEP AUDIT
    // ═══════════════════════════════════════════════════════════


    /**
     * Wave 81 — public router for SERP score. Note: this method loads
     * keyword ranks from DB then computes the score. The DB query stays
     * local (its just data); only the score formula goes to runtime.
     */
    private function computeSerpScore(int $wsId, string $url): ?int
    {
        // Wave 84 — runtime canonical. Load ranks locally (DB), runtime does
        // the proprietary scoring math. Returns null on runtime failure (UI shows empty state).
        $rows = DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->where('target_url', $url)
            ->select('current_rank')
            ->get();
        if ($rows->isEmpty()) {
            $rows = DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->select('current_rank')
                ->get();
        }
        if ($rows->isEmpty()) return null;
        $ranks = $rows->map(fn($r) => $r->current_rank)->all();
        return app(\App\Connectors\RuntimeClient::class)->computeSerpScore($ranks);
    }

    /**
     * Wave 44c — Compute SERP dimension score from seo_keywords data.
     *
     * Returns null only when the workspace has zero tracked keywords (so the
     * dashboard can show a "track keywords first" empty state). Otherwise
     * returns 0-100 based on average position of tracked keywords:
     *
     *   contribution per ranked keyword = max(0, 105 - rank * 5)
     *     rank 1  -> 100
     *     rank 5  -> 80
     *     rank 10 -> 55
     *     rank 20 -> 5
     *     rank 21+ -> 0
     *   score = sum(contributions) / total_tracked
     *     (untracked keywords drag down score, which is the honest signal)
     *
     * Prefers keywords specifically targeting $url; falls back to all
     * workspace keywords when $url has no targeted keywords.
     */

    public function deepAudit(int $wsId, array $params): array
    {
        $url = $params['url'] ?? '';
        // P0-B (2026-08-30, REPORT-0023 UX-018): a placeholder such as "sourdough_pre_order_url" reached this
        // method as a URL, was "audited" (score 0) and polluted the score trend. A non-address is not a target;
        // an empty value still resolves to the workspace's own site below.
        if ($url !== '' && !preg_match('~^https?://[^\s/]+~i', (string) $url)) {
            if (preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(/.*)?$~i', (string) $url)) {
                $url = 'https://' . ltrim((string) $url, '/');
            } else {
                throw new \InvalidArgumentException('"' . $url . '" is not a web address. Give me the page or site URL to audit.');
            }
        }
        if (empty($url)) {
            // 2026-07-23 — the runtime LLM rarely supplies a URL for a whole-site
            // audit. Resolve the workspace's own site deterministically:
            // WP-connected tenants -> seo_settings.site_url; Laravel-rendered
            // tenants -> their published websites row. Same "backend resolves,
            // Sarah never guesses" pattern as ensureImagePrompt / the publish resolver.
            $url = (string) (DB::table('seo_settings')->where('workspace_id', $wsId)
                        ->where('key', 'site_url')->value('value') ?: '');
            if ($url === '') {
                // INC-0006: only when the business has ONE published site. Picking the newest meant a
                // multi-site business got an audit of whichever site happened to be built last.
                $__pub = DB::table('websites')->where('workspace_id', $wsId)
                    ->where('status', 'published')->whereNull('deleted_at')
                    ->limit(2)->get(['custom_domain', 'domain', 'subdomain']);
                $__site = $__pub->count() === 1 ? $__pub->first() : null;
                if ($__site) {
                    $__host = $__site->custom_domain ?: $__site->domain ?: $__site->subdomain ?: '';
                    if ($__host) $url = 'https://' . ltrim(preg_replace('#^https?://#', '', $__host), '/');
                }
            }
        }
        if (empty($url)) throw new \InvalidArgumentException('URL required');

        $auditId = DB::table('seo_audits')->insertGetId([
            'workspace_id' => $wsId, 'url' => $url,
            'type' => 'full', 'status' => 'running',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Full technical audit — checks 40+ SEO factors
        $issues = [];
        $checks = $this->runTechnicalChecks($url);

        $passed = 0;
        $warnings = 0;
        $errors = 0;

        foreach ($checks as $check) {
            if ($check['status'] === 'pass') $passed++;
            elseif ($check['status'] === 'warning') { $warnings++; $issues[] = $check; }
            else { $errors++; $issues[] = $check; }
        }

        $total = count($checks);
        $score = $total > 0 ? (int) round(($passed / $total) * 100) : 50;

        // F10 (2026-05-17): aggregate per-category scores so the Dashboard's
        // 4 dimension cards (tech / content / links / serp) show real values
        // instead of falling back to the overall score (the "73 everywhere"
        // bug). Each backend category becomes {score, checks} instead of a
        // flat array of checks. Also exposes 4 top-level dimension scores
        // matched to the UI's vocabulary.
        $categories = [];
        $catNames = ['meta', 'performance', 'mobile', 'security', 'content', 'technical', 'schema'];
        foreach ($catNames as $cat) {
            $catChecks = array_values(array_filter($checks, fn($c) => ($c['category'] ?? '') === $cat));
            $categories[$cat] = [
                'score'  => $this->_categoryScore($catChecks),
                'checks' => $catChecks,
            ];
        }
        // Legacy alias — the UI also looks for results_json.meta_tags.
        $categories['meta_tags'] = $categories['meta'];

        // Roll up backend categories into the 4 UI dimensions.
        // 'links' has no audit checks today (orphan/anchor data lives in
        // knowledge endpoint) and 'serp' has no audit data (needs GSC),
        // so they stay null and the UI shows "—" rather than lying.
        $techScore    = $this->_avgScores([$categories['technical']['score'], $categories['security']['score'], $categories['performance']['score'], $categories['mobile']['score']]);
        $contentScore = $this->_avgScores([$categories['content']['score'], $categories['meta']['score'], $categories['schema']['score']]);

        $results = [
            'url' => $url,
            'total_checks' => $total,
            'passed' => $passed,
            'warnings' => $warnings,
            'errors' => $errors,
            'score' => $score,
            'checks' => $checks,
            'categories' => $categories,
            // Flat dimension scores matching the UI's vocabulary.
            'tech_score'     => $techScore,
            'content_score'  => $contentScore,
            'internal_score' => null, // populated by knowledge endpoint (link_health)
            'serp_score'     => $this->computeSerpScore($wsId, $url),
            // Nested form some UI paths use.
            'technical' => ['score' => $techScore],
            'content'   => ['score' => $contentScore],
            'internal'  => ['score' => null],
            'serp'      => ['score' => $this->computeSerpScore($wsId, $url)],
        ];

        DB::table('seo_audits')->where('id', $auditId)->update([
            'status' => 'completed', 'score' => $score,
            'results_json' => json_encode($results),
            'issues_json' => json_encode($issues),
            'updated_at' => now(),
        ]);

        // Store per-URL audit items in seo_audit_items.
        // F10 (2026-05-17): also populate the `score` column (100/50/0 for
        // pass/warning/error) so any consumer that aggregates audit_items
        // gets real numbers instead of NULL.
        try {
            foreach ($checks as $check) {
                $st = $check['status'] ?? 'unknown';
                $itemScore = $st === 'pass' ? 100 : ($st === 'warning' ? 50 : 0);
                DB::table('seo_audit_items')->insert([
                    'audit_id' => $auditId,
                    'workspace_id' => $wsId,
                    'url' => $url,
                    'category' => $check['category'] ?? 'general',
                    'check_name' => $check['check'] ?? 'unknown',
                    'status' => $st,
                    'score' => $itemScore,
                    'details' => $check['details'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not store audit items', ['error' => $e->getMessage()]);
        }

        // Create audit snapshot for historical tracking
        try {
            $this->createAuditSnapshot($wsId, $score, [
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                'total' => $total,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not create audit snapshot', ['error' => $e->getMessage()]);
        }

        $this->engineIntel->recordToolUsage('seo', 'deep_audit', $score / 100);

        // Log activity
        $this->logActivity($wsId, null, 'deep_audit', 'audit', $auditId, ['url' => $url, 'score' => $score]);

        return ['audit_id' => $auditId, 'status' => 'completed', 'score' => $score, 'summary' => compact('passed', 'warnings', 'errors', 'total')];
    }

    // ═══════════════════════════════════════════════════════════
    // TOOLS 4-5: CONTENT (delegates to Write engine)
    // ═══════════════════════════════════════════════════════════

    public function improveDraft(int $wsId, array $params): array
    {
        // Delegates to Write engine through EngineExecutionService
        // This is a cross-engine action: SEO tells Write what to optimize
        $seoContext = $this->buildSeoContext($wsId, $params['url'] ?? '');
        return [
            'delegate_to' => 'write',
            'action' => 'improve_draft',
            'params' => array_merge($params, ['seo_context' => $seoContext]),
            'status' => 'delegated',
        ];
    }

    public function writeArticle(int $wsId, array $params): array
    {
        $seoContext = $this->buildSeoContext($wsId, $params['keyword'] ?? '');
        return [
            'delegate_to' => 'write',
            'action' => 'write_article',
            'params' => array_merge($params, ['seo_context' => $seoContext]),
            'status' => 'delegated',
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // TOOL 6: AI STATUS
    // ═══════════════════════════════════════════════════════════

    public function aiStatus(int $wsId): array
    {
        $pending = DB::table('seo_audits')->where('workspace_id', $wsId)->where('status', 'pending')->count();
        $running = DB::table('seo_audits')->where('workspace_id', $wsId)->where('status', 'running')->count();
        $completed = DB::table('seo_audits')->where('workspace_id', $wsId)->where('status', 'completed')->count();
        $activeGoals = DB::table('seo_goals')->where('workspace_id', $wsId)->where('status', 'active')->count();

        return [
            'pending_audits' => $pending, 'running_audits' => $running,
            'completed_audits' => $completed, 'active_goals' => $activeGoals,
            'engine_healthy' => true,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // TOOLS 7-11: LINK MANAGEMENT
    // ═══════════════════════════════════════════════════════════

    public function linkSuggestions(int $wsId, array $params = []): array
    {
        $q = DB::table('seo_links')->where('workspace_id', $wsId)
            ->where('status', 'suggested');
        // Wave 16c — site scope filter (source OR target hostname)
        if (! empty($params['site_url'])) {
            $host = \App\Engines\SEO\Support\SiteScope::hostFromUrl((string) $params['site_url']);
            if ($host !== '') {
                $like = '%//' . $host . '%';
                $q->where(function ($x) use ($like) {
                    $x->where('source_url', 'like', $like)
                      ->orWhere('target_url', 'like', $like);
                });
            }
        }
        return $q->orderByDesc('priority_score')
            ->limit($params['limit'] ?? 50)
            ->get()->toArray();
    }

    public function generateLinkSuggestions(int $wsId, array $params): array
    {
        // Wave 38c — When called by Sarah's chain we get article_id (Wave 35b
        // parent_task passthrough) instead of source_url. Resolve article_id
        // → indexed URL so the Jaccard semantic matcher has a real source to
        // compare against (otherwise we fall through to authority-only mode
        // which returns 0 when all authority_scores are 0).
        $sourceUrl = $params['url'] ?? $params['source_url'] ?? '';
        // 2026-06-20 (forensic) — when called to rescue orphans, the caller
        // passes the orphan target URLs so we can relax the relevance gate
        // for THOSE targets only (de-orphaning beats a marginally-low score).
        $orphanTargets = array_map('strval', (array) ($params['orphan_targets'] ?? []));
        $articleId = isset($params['article_id']) ? (int) $params['article_id'] : null;
        if (!$sourceUrl && $articleId) {
            $article = DB::table('articles')->where('id', $articleId)->where('workspace_id', $wsId)->first(['slug', 'title']);
            if ($article) {
                // Look up the indexed URL by slug, OR by title match.
                $idxRow = DB::table('seo_content_index')->where('workspace_id', $wsId)
                    ->where('url', 'like', '%/' . ($article->slug ?? '') . '%')
                    ->first(['url']);
                if (!$idxRow && !empty($article->title)) {
                    $idxRow = DB::table('seo_content_index')->where('workspace_id', $wsId)
                        ->where('title', $article->title)
                        ->first(['url']);
                }
                if ($idxRow) {
                    $sourceUrl = $idxRow->url;
                }
            }
        }

        $pages = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->when($sourceUrl, fn ($q) => $q->where('url', '!=', $sourceUrl))
            ->get(['url', 'title', 'h1', 'authority_score', 'word_count',
                   'meta_description', 'intent']);
        if ($pages->isEmpty()) {
            return ['generated' => 0, 'suggestions' => []];
        }

        $sourcePage = $sourceUrl
            ? DB::table('seo_content_index')
                ->where('workspace_id', $wsId)->where('url', $sourceUrl)->first()
            : null;
        $sourceTokens = $sourcePage
            ? $this->tokenize(($sourcePage->title ?? '') . ' '
                . ($sourcePage->h1 ?? '') . ' '
                . ($sourcePage->meta_description ?? ''))
            : [];

        // 2026-05-15 hotfix — authority-only mode when source URL has no
        // tokenisable context (sourceUrl absent OR source not indexed yet).
        // Lower the gate to auth > 0.2 so workspaces with modest authority
        // distributions still produce suggestions.
        $authorityOnly = empty($sourceTokens);

        // Skip pages already linked from this source
        $existingTargets = DB::table('seo_link_graph')
            ->where('workspace_id', $wsId)
            ->when($sourceUrl, fn ($q) => $q->where('source_url', $sourceUrl))
            ->pluck('target_url')->toArray();

        $suggestions = [];
        foreach ($pages as $candidate) {
            if (in_array($candidate->url, $existingTargets, true)) { continue; }
            if ((int) ($candidate->word_count ?? 0) < 100) { continue; }

            $candidateTokens = $this->tokenize(
                ($candidate->title ?? '') . ' '
                . ($candidate->h1 ?? '') . ' '
                . ($candidate->meta_description ?? '')
            );

            $overlap = count(array_intersect($sourceTokens, $candidateTokens));
            $union   = count(array_unique(array_merge($sourceTokens, $candidateTokens)));
            $jaccard = $union > 0 ? $overlap / $union : 0.0;
            $auth    = (float) ($candidate->authority_score ?? 0);
            $relevance = ($jaccard * 0.7) + ($auth * 0.3);

            // 2026-05-15 hotfix — when authority-only (no source tokens), gate
            // purely on authority. Otherwise gate on Jaccard relevance.
            $isOrphanTarget = in_array($candidate->url, $orphanTargets, true);
            $meets = $isOrphanTarget
                ? true                                                  // orphan rescue: gate on body placeability (below), not title-Jaccard
                : ($authorityOnly ? ($auth > 0.2) : ($relevance > 0.05));
            if ($meets) {
                // Wave 77 — prefer a natural anchor that exists in source body.
                // Only fall back to title-slice if we have no body to scan.
                $naturalAnchor = null;
                if ($sourceUrl) {
                    static $bodyCache = [];
                    if (!isset($bodyCache[$sourceUrl])) {
                        // Load source article body once per call.
                        $bodyCache[$sourceUrl] = '';
                        if (preg_match('#/blog/([^/]+)/?$#i', parse_url($sourceUrl, PHP_URL_PATH) ?: '', $sm)) {
                            $srcArt = DB::table('articles')->where('workspace_id', $wsId)->where('slug', $sm[1])->first(['content']);
                            if ($srcArt) $bodyCache[$sourceUrl] = $this->stripNonBodyForAnchor((string) $srcArt->content);
                        }
                    }
                    if ($bodyCache[$sourceUrl] !== '') {
                        // Look up candidate's focus_keyword from articles table.
                        $candFk = null;
                        if (preg_match('#/blog/([^/]+)/?$#i', parse_url((string) $candidate->url, PHP_URL_PATH) ?: '', $cm)) {
                            $candArt = DB::table('articles')
                                ->where('workspace_id', $wsId)
                                ->where('slug', $cm[1])
                                ->first(['focus_keyword']);
                            if ($candArt) $candFk = $candArt->focus_keyword;
                        }
                        // Wave 79 — track anchors used so far for this source so
                        // we propose DIVERSE anchors across the article rather
                        // than repeating "Private Chef" 3x.
                        static $usedPerSource = [];
                        if (!isset($usedPerSource[$sourceUrl])) $usedPerSource[$sourceUrl] = [];
                        $naturalAnchor = $this->extractNaturalAnchor(
                            $bodyCache[$sourceUrl],
                            $candidate->title ?? '',
                            $candidate->meta_description ?? null,
                            $candFk,
                            $usedPerSource[$sourceUrl]
                        );
                        if ($naturalAnchor !== null) {
                            $usedPerSource[$sourceUrl][] = $naturalAnchor;
                        }
                    }
                }
                // 2026-06-20 (forensic: Chef Red orphan loop) — choose a
                // PLACEABLE anchor. Prefer the runtime's topical phrase; if it
                // is null OR unplaceable, fall back to the target's own keyword /
                // title phrase (verbatim in real source paragraphs). Only skip
                // when NOTHING places. Pure string validation (no runtime),
                // reusing the applier's own matcher so the two sides cannot
                // disagree — the root cause of the orphan no-op loop.
                if (! $sourceUrl || empty($bodyCache[$sourceUrl])) {
                    continue; // no source body to place a link into
                }
                $titleWords = array_values(array_filter(
                    preg_split('/\s+/', preg_replace('/[^a-z0-9 ]/i', ' ', (string) ($candidate->title ?? ''))),
                    fn ($w) => strlen($w) >= 3
                ));
                $anchorCandidates = [];
                if ($naturalAnchor !== null) { $anchorCandidates[] = $naturalAnchor; }
                $anchorCandidates[] = (string) ($candFk ?? '');
                if (count($titleWords) >= 3) { $anchorCandidates[] = $titleWords[0] . ' ' . $titleWords[1] . ' ' . $titleWords[2]; }
                if (count($titleWords) >= 2) { $anchorCandidates[] = $titleWords[0] . ' ' . $titleWords[1]; }
                $placeable = $this->_pickPlaceableAnchor($bodyCache[$sourceUrl], (string) $candidate->url, $anchorCandidates);
                if ($placeable === null) { continue; }
                $anchor = $placeable;
                $suggestions[] = [
                    'target_url'       => $candidate->url,
                    'title'            => $candidate->title,
                    'relevance_score'  => round($isOrphanTarget ? max($relevance, 0.9) : $relevance, 3), // 2026-06-20 forensic: orphan-rescue targets must survive the top-15 relevance cap
                    'authority_score'  => round($auth, 3),
                    'suggested_anchor' => $anchor,
                    'word_count'       => (int) $candidate->word_count,
                    'intent'           => $candidate->intent,
                ];
            }
        }

        // Sort by relevance, cap at 15
        usort($suggestions, fn ($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);
        $suggestions = array_slice($suggestions, 0, 15);

        // Persist to seo_links — idempotent via updateOrInsert keyed on
        // (workspace_id, source_url, target_url). source_url defaults to
        // 'workspace' for workspace-wide queries (no specific source).
        foreach ($suggestions as $sug) {
            DB::table('seo_links')->updateOrInsert(
                [
                    'workspace_id' => $wsId,
                    'source_url'   => $sourceUrl ?: 'workspace',
                    'target_url'   => $sug['target_url'],
                ],
                [
                    'anchor_text'    => $sug['suggested_anchor'],
                    'type'           => 'internal',
                    'status'         => 'suggested',
                    'priority_score' => (int) ($sug['relevance_score'] * 100),
                    'updated_at'     => now(),
                    'created_at'     => now(),
                ]
            );
        }

        $this->logActivity($wsId, null, 'generate_link_suggestions', 'links', null, [
            'url'       => $sourceUrl,
            'generated' => count($suggestions),
        ]);

        return ['generated' => count($suggestions), 'suggestions' => $suggestions];
    }

    /**
     * 2026-05-14 Phase 2 — tokenize text into a deduped set of meaningful
     * keywords for Jaccard similarity. Strips stopwords (incl. UAE/Dubai
     * which dominate workspace 7's content) and short words.
     */
    private function tokenize(string $text): array
    {
        $stopwords = [
            'the','a','an','and','or','but','in','on','at','to','for','of','with',
            'by','from','as','is','was','are','were','be','been','being','have','has',
            'had','do','does','did','will','would','could','should','may','might',
            'shall','can','this','that','these','those','it','its','we','our','you',
            'your','they','their','he','his','she','her','about','dubai','uae',
            'into','than','then','also','more','most','some','any','all','each',
        ];
        $clean  = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $text));
        $words  = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_filter($words, fn ($w) => strlen($w) > 3 && !in_array($w, $stopwords, true));
        return array_values(array_unique($tokens));
    }

    /**
     * 2026-05-14 Phase 2 — pick a natural anchor text snippet from the target
     * page's title that overlaps with the source page's tokens. Falls back
     * to first-5-words of title if there's no overlap.
     */
    private function suggestAnchor(string $targetTitle, array $sourceTokens): string
    {
        if ($targetTitle === '') { return ''; }
        if (empty($sourceTokens)) {
            return implode(' ', array_slice(explode(' ', $targetTitle), 0, 5));
        }
        $titleTokens = $this->tokenize($targetTitle);
        $overlap     = array_intersect($titleTokens, $sourceTokens);
        if (!empty($overlap)) {
            $word = reset($overlap);
            if (preg_match('/([A-Za-z]+ ){0,2}' . preg_quote($word, '/') . '( [A-Za-z]+){0,2}/i',
                           $targetTitle, $m)) {
                return trim($m[0]);
            }
        }
        return implode(' ', array_slice(explode(' ', $targetTitle), 0, 5));
    }

    /**
     * Wave 81 — public router. Delegates to runtime when
     * Wave 84 — proprietary algorithm lives in runtime (proprietary IP
     * hidden in Railway). Laravel only ships this thin call wrapper.
     */
    /**
     * 2026-06-20 (forensic: Chef Red orphan loop) — content cleaning before
     * anchor extraction. The runtime extractor strips <a>/<h1> but not <aside>,
     * so it mined the aeo-tldr summary and returned anchors that live only in
     * the TL;DR — which the applier cannot place in a real <p> (the true cause
     * of the orphan no-op loop). Remove <aside> blocks so the runtime only
     * proposes anchors from real paragraphs. Cleaning only — no scoring.
     */
    /**
     * 2026-06-20 (forensic) — return the first candidate anchor the applier can
     * actually place in $body (verbatim, in a real paragraph), or null. Free
     * string validation reusing the applier's own _findLinkInsertionPoint, so
     * generation and application can never disagree about an anchor.
     */
    private function _pickPlaceableAnchor(string $body, string $targetUrl, array $candidates): ?string
    {
        $tried = [];
        foreach ($candidates as $c) {
            $c = trim((string) $c);
            if ($c === '' || mb_strlen($c) < 6) continue;
            $key = strtolower($c);
            if (isset($tried[$key])) continue;
            $tried[$key] = true;
            $pt = $this->_findLinkInsertionPoint($body, $c, $targetUrl);
            if (! empty($pt['found'])) return $c;
        }
        return null;
    }

    private function stripNonBodyForAnchor(string $html): string
    {
        return preg_replace('#<aside\b[^>]*>.*?</aside>#is', ' ', $html) ?? $html;
    }

    private function extractNaturalAnchor(
        string $sourceBody,
        string $candidateTitle,
        ?string $candidateMeta = null,
        ?string $candidateFocusKeyword = null,
        array $usedAnchors = []
    ): ?string {
        // Wave 84 — runtime is canonical. No PHP fallback (proprietary
        // algorithm deleted). On runtime failure, return null — link
        // insertion gracefully skips. Runtime endpoint is required.
        return app(\App\Connectors\RuntimeClient::class)->extractAnchor(
            $sourceBody,
            $candidateTitle,
            $candidateMeta,
            $candidateFocusKeyword,
            $usedAnchors
        );
    }

    /**
     * Wave 77+78+79 — extract a NATURAL anchor phrase that:
     *   1. exists verbatim in the source article body (Wave 77)
     *   2. is NOT already inside an <a> tag in source (Wave 77)
     *   3. contains at least one significant content-word from the
     *      target's topical signal (focus_keyword OR title) (Wave 78)
     *   4. is NOT already used as an anchor in this source article (Wave 79)
     *
     * Wave 79 — body-first phrase extraction: instead of slicing the
     * target title and hoping it exists in prose, we scan the source body
     * for naturally-occurring n-grams (2-5 words) and filter by topical
     * relevance. Yields diverse anchors like "private chef cost",
     * "hire a chef", "chef services" rather than reusing the same fragment.
     *
     * Returns null if no phrase passes all 4 gates.
     */

    /**
     * 2026-05-14 Phase 2 — anchor intelligence: how is THIS target URL being
     * linked from elsewhere in the workspace? Counts generic anchors,
     * detects over-optimisation, returns recommendations + a health bucket.
     * Caches the result in seo_anchor_analysis.
     */
    public function analyzeAnchors(int $wsId, string $targetUrl): array
    {
        $inbound = DB::table('seo_link_graph')
            ->where('workspace_id', $wsId)
            ->where('target_url', $targetUrl)
            ->get(['source_url', 'anchor_text']);

        $anchors = $inbound->pluck('anchor_text')->filter()
            ->map(fn ($a) => strtolower(trim($a)))->toArray();
        $totalInbound  = $inbound->count();
        $uniqueAnchors = count(array_unique($anchors));

        $genericTerms = ['click here','here','read more','learn more','this','link',
                         'page','website','more','info','details','visit','read'];
        $genericCount = count(array_filter($anchors,
            fn ($a) => in_array($a, $genericTerms, true)));

        $anchorCounts  = array_count_values($anchors);
        $overOptimised = array_filter($anchorCounts, fn ($c) => $c > 3);

        $distribution = [];
        foreach ($anchorCounts as $anchor => $count) {
            $distribution[] = ['anchor' => $anchor, 'count' => $count];
        }
        usort($distribution, fn ($a, $b) => $b['count'] <=> $a['count']);

        $recommendations = [];
        if ($genericCount > 0) {
            $recommendations[] = "Replace {$genericCount} generic anchors ('click here', 'read more') with descriptive text.";
        }
        if (!empty($overOptimised)) {
            $recommendations[] = 'Some anchor texts are over-used. Vary anchor text to appear natural.';
        }
        if ($totalInbound === 0) {
            $recommendations[] = 'This page has no inbound internal links. Add links from related pages.';
        }
        if ($uniqueAnchors === 1 && $totalInbound > 2) {
            $recommendations[] = 'All inbound links use the same anchor text. Diversify for better SEO signals.';
        }

        $health = 'good';
        if ($totalInbound === 0 || ($totalInbound > 0 && $genericCount > $totalInbound * 0.5)) {
            $health = 'poor';
        } elseif ($genericCount > 0 || !empty($overOptimised)) {
            $health = 'warning';
        }

        $result = [
            'target_url'          => $targetUrl,
            'total_inbound'       => $totalInbound,
            'unique_anchors'      => $uniqueAnchors,
            'generic_anchors'     => $genericCount,
            'over_optimised'      => array_keys($overOptimised),
            'anchor_distribution' => array_slice($distribution, 0, 10),
            'recommendations'     => $recommendations,
            'health'              => $health,
        ];

        DB::table('seo_anchor_analysis')->updateOrInsert(
            ['workspace_id' => $wsId, 'target_url' => $targetUrl],
            [
                'total_inbound'       => $totalInbound,
                'unique_anchors'      => $uniqueAnchors,
                'generic_anchors'     => $genericCount,
                'anchor_distribution' => json_encode(array_slice($distribution, 0, 10)),
                'recommendations'     => json_encode($recommendations),
                'health'              => $health,
                'updated_at'          => now(),
                'created_at'          => now(),
            ]
        );

        return $result;
    }

    /**
     * 2026-05-14 Phase 2 — link equity: which pages are leaking authority
     * (high-auth pages with many external outbound links), which are orphaned
     * or underlinked, and which are healthy. Read-mostly diagnostic — does
     * not modify content_index, just returns a per-page assessment.
     */
    public function computeLinkEquity(int $wsId): array
    {
        $pages = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->get(['url', 'authority_score', 'inbound_links',
                   'external_link_count', 'content_score', 'word_count']);

        $equity = [];
        foreach ($pages as $page) {
            $extOutbound = DB::table('seo_outbound_links')
                ->where('workspace_id', $wsId)
                ->where('source_url', $page->url)
                ->count();
            $authority   = (float) ($page->authority_score ?? 0);
            $inbound     = (int)   ($page->inbound_links   ?? 0);

            // Leak risk — high-authority pages with many external outbound
            // links are bleeding link equity off-site.
            $leakRisk = 'low';
            if ($extOutbound > 10 && $authority > 0.5) {
                $leakRisk = 'high';
            } elseif ($extOutbound > 5 || ($extOutbound > 2 && $authority > 0.4)) {
                $leakRisk = 'medium';
            }

            $opportunity = null;
            if ($inbound === 0) {
                $opportunity = 'orphan';
            } elseif ($inbound < 2 && (int) ($page->content_score ?? 0) > 60) {
                $opportunity = 'underlinked';
            }

            $equity[] = [
                'url'           => $page->url,
                'authority'     => round($authority, 3),
                'inbound_links' => $inbound,
                'ext_outbound'  => $extOutbound,
                'leak_risk'     => $leakRisk,
                'opportunity'   => $opportunity,
                'content_score' => (int) ($page->content_score ?? 0),
            ];
        }

        $riskOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($equity, function ($a, $b) use ($riskOrder) {
            $cmp = $riskOrder[$a['leak_risk']] <=> $riskOrder[$b['leak_risk']];
            if ($cmp !== 0) { return $cmp; }
            return $b['authority'] <=> $a['authority'];
        });

        return ['equity' => $equity, 'total' => count($equity)];
    }

    /**
     * Wave 3 — R7 (2026-05-17). Replaces the legacy "status flip only"
     * behavior. insertLink now actually inserts the link into the article
     * body via aiApplyLinkInsertion, then returns true/false for the
     * existing controller contract. To inspect the change before applying,
     * call aiPreviewLinkInsertion first.
     */
    public function insertLink(int $wsId, int $linkId): bool
    {
        $r = $this->aiApplyLinkInsertion($wsId, $linkId);
        return (bool) ($r['success'] ?? false);
    }

    /**
     * 2026-06-14 — Deliver an internal-link edit into the LIVE WordPress post.
     * Pushes ONLY the updated post_content to lgsc/v1/update-post (the connector's
     * handle_update_post is a partial update — it leaves post_status / title /
     * meta / thumbnail untouched when those fields are omitted, verified against
     * connector v1.3.8), so we add the link without disturbing anything else on
     * the live post. Connection (site_url + webhook_secret) resolves from
     * seo_settings exactly as WriteService::pushDraftToWordPressIfConnected and
     * the /connector/update-post route do. Returns true only on a 2xx + success
     * envelope. Fully defensive: any missing config / transport / HTTP error
     * returns false so the caller skips the fix honestly (never throws).
     */
    private function pushLinkUpdateToWordPress(int $wsId, int $wpPostId, string $content): bool
    {
        try {
            $siteUrl = DB::table('seo_settings')->where('workspace_id', $wsId)
                ->where('key', 'site_url')->value('value');
            $secret  = DB::table('seo_settings')->where('workspace_id', $wsId)
                ->where('key', 'webhook_secret')->value('value');
            if (! $siteUrl || ! $secret) {
                // wp_post_id is set but no connector creds — we can't reach the
                // live post, so we must NOT claim the fix.
                Log::warning('[SEO] WP link-update skipped: wp_post_id set but no connector creds', [
                    'workspace_id' => $wsId, 'wp_post_id' => $wpPostId,
                ]);
                return false;
            }
            $wpUrl = rtrim((string) $siteUrl, '/') . '/wp-json/lgsc/v1/update-post';
            $r = \Illuminate\Support\Facades\Http::withHeaders([
                    'Content-Type'  => 'application/json',
                    'X-LGSC-Secret' => $secret,
                ])
                ->timeout(30)
                ->post($wpUrl, [
                    'post_id' => $wpPostId,
                    'content' => $content,
                    'secret'  => $secret,
                ]);
            if (! $r->successful()) {
                Log::warning('[SEO] WP link-update push HTTP error', [
                    'workspace_id' => $wsId, 'wp_post_id' => $wpPostId,
                    'http' => $r->status(), 'body' => mb_substr((string) $r->body(), 0, 300),
                ]);
                return false;
            }
            $body = $r->json() ?: [];
            return (bool) ($body['success'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('[SEO] WP link-update push failed (non-fatal): ' . $e->getMessage(), [
                'workspace_id' => $wsId, 'wp_post_id' => $wpPostId,
            ]);
            return false;
        }
    }

    /**
     * 2026-06-11 — FIRST-CLASS, CREDITED orphan fix so Sarah can delegate
     * "fix the orphans" as ONE orchestrator task (engine=seo/action=fix_orphans)
     * instead of the fix only being reachable from the James SEO-chat surface.
     * Generates internal-link suggestions from a bounded set of editable sources,
     * applies them ORPHAN-FIRST via aiApplyLinkInsertion (which now writes the
     * link graph + recomputes inbound — the keystone), and self-bills the EXACT
     * applied count (2cr/insert) through the atomic reserve+commit pipeline — the
     * same CreditService the orchestrator uses. Bounded (≤25 inserts, ≤2/orphan)
     * so the spend is capped. Returns a summary incl. credits_charged.
     */
    public function fixOrphans(int $wsId, array $params = []): array
    {
        $limit = max(1, min(50, (int) ($params['limit'] ?? 25)));
        $orphanQ = fn() => DB::table('seo_content_index')->where('workspace_id', $wsId)
            ->where('inbound_links', 0)->where('word_count', '>', 100);
        $before = (clone $orphanQ())->count();
        $orphanUrls = (clone $orphanQ())->pluck('url')->toArray();
        if (empty($orphanUrls)) {
            return ['success' => true, 'orphans_before' => 0, 'orphans_after' => 0, 'applied' => 0,
                    'credits_charged' => 0, 'message' => 'No orphan pages to fix — internal linking is healthy.'];
        }

        // ───────────────────────────────────────────────────────────────────
        // 2026-06-20 (forensic: Chef Red orphan loop) — INTEGRATED, RELIABLE
        // generate-and-place loop. The old design (a) sampled only 8 random
        // sources of ~85 and (b) generated a batch then applied ONCE. In
        // practice most stored anchors were unplaceable at insert time (the
        // runtime extracts an anchor from a normalized body — including the
        // aeo-tldr aside — that `_findLinkInsertionPoint` then cannot wrap
        // verbatim), so a one-shot apply almost always no-op'd and orphans were
        // only ever cleared by luck. We now walk a TARGETED, deterministic
        // source pool (sources whose body actually contains an orphan's focus
        // phrase — cheap LIKE retrieval, NOT scoring) and, per source,
        // immediately TRY TO PLACE a link into each still-orphaned page via the
        // real insertLink (which validates placement and skips unplaceable
        // anchors). We stop for an orphan the moment a link actually lands (one
        // inbound link de-orphans it), and stop the whole loop when every orphan
        // is linked, no recent source made progress, the pool is exhausted, or a
        // 60s wall-clock guard trips (under the PHP-FPM 120s / CF 100s ceilings).
        $orphanSlugs = [];
        foreach ($orphanUrls as $ou) {
            if (preg_match('#/blog/([^/]+)/?$#', parse_url($ou, PHP_URL_PATH) ?: '', $om)) {
                $orphanSlugs[] = $om[1];
            }
        }
        $phrases = DB::table('articles')->where('workspace_id', $wsId)
            ->whereIn('slug', $orphanSlugs)->whereNotNull('focus_keyword')
            ->pluck('focus_keyword')->map(fn ($p) => trim((string) $p))
            ->filter(fn ($p) => strlen($p) >= 4)->unique()->values()->all();

        // Editable, deliverable sources (status=published covers Laravel sites;
        // wp_post_id covers WP-pushed posts — matches nothing off WordPress).
        $srcQ = DB::table('articles')->where('workspace_id', $wsId)
            ->where(function ($q) {
                $q->where('status', 'published')->orWhereNotNull('wp_post_id');
            })
            ->whereNotNull('content');
        if (! empty($phrases)) {
            $srcQ->where(function ($q) use ($phrases) {
                foreach ($phrases as $p) { $q->orWhere('content', 'like', '%' . $p . '%'); }
            });
            $srcIds = $srcQ->orderBy('id')->limit(60)->pluck('id')->all(); // targeted + deterministic
        } else {
            $srcIds = $srcQ->inRandomOrder()->limit(25)->pluck('id')->all(); // legacy fallback
        }

        $applied = 0; $per = [];
        $remaining     = array_values($orphanUrls); // urls still orphaned
        $triedLinkIds  = [];                          // never retry a known-bad suggestion
        $sinceProgress = 0;                           // sources processed since the last successful place
        $loopStart     = microtime(true);
        foreach ($srcIds as $sid) {
            if (empty($remaining) || $applied >= $limit) break;
            if ($sinceProgress >= 6) break;                  // stubborn orphans: stop wasting calls
            if (microtime(true) - $loopStart > 30) break;     // wall-clock guard
            try { $this->generateLinkSuggestions($wsId, ['article_id' => (int) $sid, 'orphan_targets' => $remaining]); } catch (\Throwable $e) {}
            $placedThisSource = false;
            $cand = DB::table('seo_links')->where('workspace_id', $wsId)->where('status', 'suggested')
                ->whereIn('target_url', $remaining)
                ->when(! empty($triedLinkIds), fn ($q) => $q->whereNotIn('id', $triedLinkIds))
                ->orderByDesc('priority_score')->get(['id', 'source_url', 'target_url']);
            foreach ($cand as $s) {
                if ($applied >= $limit) break;
                if ($s->source_url === $s->target_url) continue;
                if (! in_array($s->target_url, $remaining, true)) continue; // already linked this pass
                $triedLinkIds[] = (int) $s->id;
                try {
                    if ($this->insertLink($wsId, (int) $s->id)) {
                        $applied++;
                        $per[$s->target_url] = 1;
                        $remaining = array_values(array_diff($remaining, [$s->target_url])); // de-orphaned
                        $placedThisSource = true;
                    }
                } catch (\Throwable $e) {}
            }
            $sinceProgress = $placedThisSource ? 0 : $sinceProgress + 1;
        }
        // 3. Self-bill the EXACT applied count (2cr/insert) via the atomic pipeline.
        $creditsCharged = 0;
        if ($applied > 0) {
            $cost = $applied * 2;
            try {
                $cs = app(\App\Core\Billing\CreditService::class);
                $ref = 'fix_orphans_' . $wsId . '_' . substr(md5($wsId . $applied . now()->timestamp), 0, 10);
                $resv = $cs->reserveCredits($wsId, $cost, 'SeoFixOrphans', 0, $ref);
                $cs->commitReservedCredits($resv->reservation_reference);
                $creditsCharged = $cost;
            } catch (\Throwable $e) {
                Log::warning('[fixOrphans] credit charge failed (links applied): ' . $e->getMessage());
            }
        }

        $after = (clone $orphanQ())->count();
        return [
            'success'         => true,
            'orphans_before'  => $before,
            'orphans_after'   => $after,
            'applied'         => $applied,
            'credits_charged' => $creditsCharged,
            'message'         => $applied > 0
                ? "Inserted {$applied} internal link(s) to orphan pages — orphans {$before} → {$after}. {$creditsCharged} credits used."
                : "No insertable internal links were found for the current orphan pages (sources may already link them or aren't editable). 0 credits used.",
        ];
    }

    /**
     * Wave 3 — R7 (2026-05-17). Preview of where a link suggestion will
     * be inserted, WITHOUT mutating the article body. Returns the
     * proposed before/after snippet and the paragraph index, or a
     * structured reason why insertion isn't safely possible.
     *
     * Position rules:
     *  - Never insert into the first or last paragraph (too prominent /
     *    too low impact)
     *  - Never insert into a paragraph that already has a link
     *  - Skip if the target URL is already linked elsewhere in the body
     *  - Prefer wrapping an existing plain-text match of the anchor;
     *    fall back to appending to the first eligible middle paragraph
     */
    public function aiPreviewLinkInsertion(int $wsId, int $linkId): array
    {
        $link = DB::table('seo_links')
            ->where('workspace_id', $wsId)->where('id', $linkId)->first();
        if (! $link) {
            return ['success' => false, 'error' => 'link_not_found'];
        }
        if (! empty($link->status) && $link->status === 'inserted') {
            return ['success' => false, 'error' => 'already_inserted', 'message' => 'This link suggestion has already been inserted.'];
        }
        if (! empty($link->status) && $link->status === 'dismissed') {
            return ['success' => false, 'error' => 'dismissed', 'message' => 'This link suggestion was previously dismissed.'];
        }

        $sourceUrl = (string) ($link->source_url ?? '');
        $slug = $this->_slugFromUrl($sourceUrl);
        if ($slug === '') {
            return ['success' => false, 'error' => 'no_source_slug', 'message' => 'Could not derive a slug from the source URL.', 'source_url' => $sourceUrl];
        }

        $article = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('slug', 'like', $slug . '%')   // articles get a -randstr suffix on create
            ->orderByDesc('id')
            ->first();
        if (! $article) {
            return [
                'success'    => false,
                'error'      => 'source_not_internal_article',
                'message'    => 'The source page is not a Laravel-managed article — the SEO Assistant can only modify articles in the Write engine library. For WordPress-managed pages, link insertion needs to happen in WP directly.',
                'source_url' => $sourceUrl,
                'slug'       => $slug,
            ];
        }

        $body   = (string) ($article->content ?? '');
        $anchor = (string) ($link->anchor_text ?? '');
        $target = (string) ($link->target_url ?? '');

        if ($anchor === '' || $target === '') {
            return ['success' => false, 'error' => 'incomplete_link', 'message' => 'Link suggestion is missing anchor text or target URL.'];
        }

        $point = $this->_findLinkInsertionPoint($body, $anchor, $target);
        if (! $point['found']) {
            return [
                'success'    => false,
                'error'      => $point['reason'],
                'message'    => $point['message'],
                'article_id' => (int) $article->id,
                'anchor'     => $anchor,
                'target'     => $target,
            ];
        }

        return [
            'success'           => true,
            'article_id'        => (int) $article->id,
            'article_title'     => $article->title,
            'link_id'           => (int) $link->id,
            'anchor'            => $anchor,
            'target'            => $target,
            'method'            => $point['method'],
            'paragraph_index'   => $point['paragraph_index'],
            'before_snippet'    => $point['before_snippet'],
            'after_snippet'     => $point['after_snippet'],
            // Internal carry — used by aiApplyLinkInsertion to avoid recomputing
            '_modified_body'    => $point['_modified_body'],
        ];
    }

    /**
     * Wave 3 — R7 (2026-05-17). Apply a link suggestion by mutating the
     * article body. Re-runs the preview to lock in a fresh insertion
     * point at apply time (the body may have changed between preview
     * and approval). Updates seo_links.status = 'inserted' on success.
     *
     * Snapshots the previous body to engine_intelligence as a rollback
     * breadcrumb (cross-engine consumer can find recent insertions).
     */
    public function aiApplyLinkInsertion(int $wsId, int $linkId): array
    {
        $preview = $this->aiPreviewLinkInsertion($wsId, $linkId);
        if (! ($preview['success'] ?? false)) {
            return $preview;
        }

        $articleId    = (int) $preview['article_id'];
        $modifiedBody = (string) $preview['_modified_body'];

        // 2026-06-14 — WP TRUTHFULNESS GATE. On a WordPress-hosted page the live
        // content lives in WP, NOT in articles.content. The keystone below writes
        // a seo_link_graph edge + drops the target's inbound_links (the orphan
        // count) at insert time. If the source article was pushed to a live WP
        // post (articles.wp_post_id set) we MUST deliver the link into that live
        // post FIRST — otherwise we'd record a "fix" that doesn't exist on the
        // live site, and the next re-crawl (which reads the real WP HTML) would
        // flap the orphan straight back. So: push the modified body to the live
        // post via lgsc/v1/update-post; only proceed when WP confirms success.
        // Skip HONESTLY (no body write, no inserted-status, no graph edge, no
        // inbound drop, no credit) on push failure. Laravel-managed articles
        // (wp_post_id NULL) are UNCHANGED — articles.content IS their live page.
        // Gating on wp_post_id (per-article ground truth) — not the workspace's
        // seo_settings WP connection, which is also set on Laravel-served sites.
        $wpPostId = (int) (DB::table('articles')
            ->where('id', $articleId)->where('workspace_id', $wsId)
            ->value('wp_post_id') ?? 0);
        if ($wpPostId > 0) {
            if (! $this->pushLinkUpdateToWordPress($wsId, $wpPostId, $modifiedBody)) {
                return [
                    'success'    => false,
                    'error'      => 'wp_push_failed',
                    'message'    => 'I couldn\'t update the live WordPress post just now, so I didn\'t record the link and you weren\'t charged. I\'ll retry on the next run.',
                    'article_id' => $articleId,
                    'wp_post_id' => $wpPostId,
                ];
            }
        }

        try {
            DB::transaction(function () use ($articleId, $modifiedBody, $linkId) {
                DB::table('articles')->where('id', $articleId)->update([
                    'content'    => $modifiedBody,
                    'updated_at' => now(),
                ]);
                DB::table('seo_links')->where('id', $linkId)->update([
                    'status'     => 'inserted',
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('SeoService::aiApplyLinkInsertion failed', [
                'workspace_id' => $wsId, 'link_id' => $linkId, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => 'db_error', 'message' => $e->getMessage()];
        }

        $this->engineIntel->recordToolUsage('seo', 'ai_insert_link');

        // Re-sync the article so seo_content_index gets fresh internal_link_count.
        try {
            $fresh = DB::table('articles')->find($articleId);
            if ($fresh) {
                $this->syncFromArticle($wsId, $fresh);
            }
        } catch (\Throwable $e) {
            Log::debug('post-insert syncFromArticle skipped: ' . $e->getMessage());
        }

        // 2026-06-10 — CLOSE THE INSERT→GRAPH→SCORE LOOP. Previously this method
        // updated the article body + the SOURCE's outbound internal_link_count,
        // but NEVER wrote seo_link_graph. inbound_links (which defines an orphan
        // page) is only synced from seo_link_graph by the nightly seo:authority-
        // score cron, so the link target's inbound_links never incremented — the
        // page stayed flagged as an orphan and the report/link_health never
        // reflected the fix until an unrelated full re-crawl happened to run.
        // Write the graph edge now (dedup-safe, mirroring indexUrl's delete-then-
        // insert per source_url) and recompute the target's inbound_links using
        // the SAME formula as SeoAuthorityScoreCommand, so the orphan count and
        // link_health update immediately. Non-fatal — never break the insert.
        try {
            $linkRow = DB::table('seo_links')->where('id', $linkId)->first(['source_url', 'target_url', 'anchor_text']);
            if ($linkRow && !empty($linkRow->source_url) && !empty($linkRow->target_url)) {
                // Normalise both URLs the SAME way extractInternalLinksWithAnchors
                // does (strip trailing slash, preserve query+fragment) so the
                // graph edge + the inbound recompute match seo_content_index.url
                // exactly. Without this a suggestion URL with a trailing slash
                // would write a row that the COUNT/match never finds.
                $normUrl = function ($u) {
                    $hp = parse_url((string) $u);
                    if (!$hp || !isset($hp['scheme'], $hp['host'])) return (string) $u;
                    $clean = $hp['scheme'] . '://' . $hp['host'] . (isset($hp['path']) ? rtrim($hp['path'], '/') : '');
                    if (!empty($hp['query']))    { $clean .= '?' . $hp['query']; }
                    if (!empty($hp['fragment'])) { $clean .= '#' . $hp['fragment']; }
                    return $clean;
                };
                $srcUrl = $normUrl($linkRow->source_url);
                $tgtUrl = $normUrl($linkRow->target_url);
                // Host-match: only a same-host edge is genuinely "internal" and may
                // count toward the target's inbound_links (mirrors the crawler's
                // host gate; prevents a cross-host suggestion from faking inbound).
                $isInternal = parse_url($srcUrl, PHP_URL_HOST)
                    && parse_url($srcUrl, PHP_URL_HOST) === parse_url($tgtUrl, PHP_URL_HOST);

                // Dedup: drop any prior edge for this (workspace, source, target).
                DB::table('seo_link_graph')
                    ->where('workspace_id', $wsId)
                    ->where('source_url', $srcUrl)
                    ->where('target_url', $tgtUrl)
                    ->delete();
                DB::table('seo_link_graph')->insert([
                    'workspace_id' => $wsId,
                    'source_url'   => $srcUrl,
                    'target_url'   => $tgtUrl,
                    'anchor_text'  => $linkRow->anchor_text ?? ($preview['anchor'] ?? null),
                    'is_internal'  => $isInternal,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                // Recompute the target's inbound_links EXACTLY as the nightly cron
                // does: COUNT of internal graph rows pointing at this target_url.
                if ($isInternal) {
                    $cnt = (int) DB::table('seo_link_graph')
                        ->where('workspace_id', $wsId)
                        ->where('is_internal', true)
                        ->where('target_url', $tgtUrl)
                        ->count();
                    DB::table('seo_content_index')
                        ->where('workspace_id', $wsId)
                        ->where('url', $tgtUrl)
                        ->update(['inbound_links' => $cnt, 'updated_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SEO] post-insert link-graph sync failed: ' . $e->getMessage(), ['link_id' => $linkId]);
        }

        // Wave 5 (2026-05-18). Notify via the platform agent-messaging
        // infrastructure so the unified messages floater badge updates
        // and the message is visible from all 3 surfaces (floater, agent
        // profile, Messages section). Posted as James (SEO Strategist).
        try {
            $article      = $fresh ?? DB::table('articles')->find($articleId);
            $articleTitle = $article->title ?? "article #{$articleId}";
            $chatMsg = "Internal link added to \"{$articleTitle}\""
                . "\n\nI inserted a link to '{$preview['anchor']}' pointing at {$preview['target']} (paragraph {$preview['paragraph_index']}).";
            // 2026-06-21 — WP connector surface has NO agents (owner directive):
            // post link-insert notices as the single SEO Assistant for WP-connected
            // workspaces, never as the James agent. Laravel platform keeps James.
            $seoAssistant = app(\App\Engines\SEO\Services\SeoAssistantService::class);
            if ($seoAssistant->isWpWorkspace($wsId)) {
                $seoAssistant->pushAssistantNotice($wsId, $chatMsg);
            } else {
                app(\App\Core\Agents\AgentMessageService::class)
                    ->postAsAgent($wsId, 'james', $chatMsg, [
                        'notification_type' => 'link_inserted',
                        'article_id'        => $articleId,
                        'link_id'           => $linkId,
                        'paragraph_index'   => $preview['paragraph_index'],
                        'action_link'       => "/app/write/{$articleId}",
                        // 2026-06-15 — no push: a bulk orphan-fix inserts many links
                        // and would spam one notification per insert. The chat row +
                        // the cross-engine NotificationService below still record it.
                        'push'              => false,
                    ]);
            }

            // Cross-engine notification surface, only when user is known.
            $uid = optional(request()->user())->id;
            if ($uid) {
                app(\App\Core\Notifications\NotificationService::class)->dispatch(
                    \App\Core\Notifications\NotificationTypes::AGENT_TASK_COMPLETED,
                    $uid,
                    "Internal link added to \"{$articleTitle}\"",
                    $wsId,
                    "I inserted a link to '{$preview['anchor']}' at paragraph {$preview['paragraph_index']}.",
                    [
                        'notification_type' => 'link_inserted',
                        'article_id'        => $articleId,
                        'link_id'           => $linkId,
                    ],
                    "/app/write/{$articleId}",
                    'success',
                    '🔗'
                );
            }
        } catch (\Throwable $e) {
            Log::debug('aiApplyLinkInsertion notify skipped: ' . $e->getMessage());
        }

        return [
            'success'         => true,
            'article_id'      => $articleId,
            'link_id'         => $linkId,
            'paragraph_index' => $preview['paragraph_index'],
            'anchor'          => $preview['anchor'],
            'target'          => $preview['target'],
            'method'          => $preview['method'],
            'message'         => "Link inserted at paragraph {$preview['paragraph_index']}.",
        ];
    }

    /** Wave 3 R7 helper — extract the last URL path segment as slug. */
    private function _slugFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $path  = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') return '';
        $segments = explode('/', $path);
        return (string) end($segments);
    }

    /**
     * Wave 3 R7 helper — find a paragraph in $body where the link can be
     * safely inserted. Returns:
     *   ['found' => true,  'method' => 'wrap_match'|'append', 'paragraph_index' => N,
     *    'before_snippet' => …, 'after_snippet' => …, '_modified_body' => …]
     * or
     *   ['found' => false, 'reason' => '…', 'message' => '…']
     */
    private function _findLinkInsertionPoint(string $body, string $anchor, string $target): array
    {
        // Already linked anywhere? Skip silently.
        $targetEsc = preg_quote($target, '/');
        if (preg_match('/<a[^>]+href=["\']' . $targetEsc . '["\']/i', $body)) {
            return ['found' => false, 'reason' => 'already_linked', 'message' => 'This target URL is already linked elsewhere in the article.'];
        }

        // Wave 79g — split on </p>/</h1>/.../</h6> WITH separator capture
        // so we can preserve which closing tag was at each boundary when
        // we rejoin after wrapping (otherwise heading closes would become
        // </p> on rejoin — corruption).
        // PREG_SPLIT_DELIM_CAPTURE puts content at even indices and the
        // matched separator at odd indices.
        $parts = preg_split('#(</p>|</h[1-6]>)#i', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts) || count($parts) < 4) {
            return ['found' => false, 'reason' => 'article_too_short', 'message' => 'Article has fewer than 3 paragraphs — not enough room to safely place an internal link.'];
        }
        $total = count($parts);

        // Wave 79g — even indices are content, odd indices are separators
        // (from PREG_SPLIT_DELIM_CAPTURE). Iterate content parts only.
        // Skip already-linked parts, parts containing <h1>/<h2> heading
        // markup, and very short fragments.
        for ($i = 0; $i < $total - 1; $i += 2) {
            $p = $parts[$i];
            // 2026-06-20 (forensic: Chef Red orphan loop) — do NOT skip a whole
            // paragraph just because it already contains a link. Many real
            // anchors (e.g. the site's main keyword) only occur in paragraphs
            // that also carry a CTA link. Record existing <a>…</a> ranges and
            // refuse only a match that OVERLAPS one (prevents nested anchors),
            // so we can still place into the link-free text of the paragraph.
            $linkRanges = [];
            if (preg_match_all('#<a\b[^>]*>.*?</a>#is', $p, $lm, PREG_OFFSET_CAPTURE)) {
                foreach ($lm[0] as $lr) { $linkRanges[] = [$lr[1], $lr[1] + strlen($lr[0])]; }
            }
            if (preg_match('/<h[12]\b/i', $p)) continue;
            // 2026-06-20 forensic: the aeo-tldr <aside> summary is not real body — never link from it.
            if (stripos($p, 'aeo-tldr') !== false || stripos($p, '<aside') !== false) continue;
            if (mb_strlen(strip_tags($p)) < 30) continue;

            // Wave 79e — find anchor in plain-text view of the paragraph
            // so inline tags (<strong>, <em>) don't block the match. Then
            // map the position back to the raw HTML and wrap that range.
            $anchorWords = preg_split('/\s+/', trim($anchor));
            $anchorEscParts = array_map(function ($w) { return preg_quote($w, '/'); }, $anchorWords);
            $anchorEsc = implode('[\\s\\p{P}]+', $anchorEscParts); // 2026-06-20 forensic: allow punctuation between anchor words (runtime normalizes .,;:!? to spaces) — fixes apply-time no_natural_anchor_match
            $pattern = '/(?<![>\w])(' . $anchorEsc . ')(?![\w<])/iu';
            $repCount = 0;
            // Build a stripped view of the paragraph + a position map so we
            // can locate the start/end of a hit in the original HTML.
            $stripped = '';
            $map = []; // map[stripped_offset] = original_offset
            $inTag = false;
            for ($k = 0; $k < strlen($p); $k++) {
                $c = $p[$k];
                if ($c === '<') { $inTag = true; continue; }
                if ($c === '>') { $inTag = false; continue; }
                if ($inTag) continue;
                $map[strlen($stripped)] = $k;
                $stripped .= $c;
            }
            // Search the stripped view.
            $wrapped = $p;
            if (preg_match($pattern, $stripped, $sm, PREG_OFFSET_CAPTURE)) {
                $matchedText = $sm[1][0];
                $sOffset = $sm[1][1];
                $sEnd    = $sOffset + strlen($matchedText);
                if (isset($map[$sOffset]) && isset($map[$sEnd - 1])) {
                    $origStart = $map[$sOffset];
                    $origEnd   = $map[$sEnd - 1] + 1;
                    $insideLink = false;
                    foreach ($linkRanges as $lr) {
                        if ($origStart < $lr[1] && $origEnd > $lr[0]) { $insideLink = true; break; }
                    }
                    if ($insideLink) { continue; } // would nest inside an existing <a> — try another paragraph
                    $origMatch = substr($p, $origStart, $origEnd - $origStart);
                    // Make sure we are not inside an existing <a>; the
                    // earlier check (`stripos($p, '<a ') !== false`) skips
                    // any paragraph with an existing link, so we're safe.
                    // 2026-05-23 FIX 22 — inline underline so the link is
                    // visible regardless of WP theme. Themes that strip
                    // text-decoration on a tags still honour inline style.
                    $wrapped = substr($p, 0, $origStart)
                             . '<a href="' . htmlspecialchars($target, ENT_QUOTES) . '" style="text-decoration: underline;">' . $origMatch . '</a>'
                             . substr($p, $origEnd);
                    $repCount = 1;
                }
            }
            if ($repCount > 0 && is_string($wrapped) && $wrapped !== $p) {
                $modParts = $parts;
                $modParts[$i] = $wrapped;
                return [
                    'found'           => true,
                    'method'          => 'wrap_match',
                    'paragraph_index' => $i,
                    'before_snippet'  => mb_substr(strip_tags($p), 0, 220),
                    'after_snippet'   => mb_substr(strip_tags($wrapped), 0, 250),
                    '_modified_body'  => implode('', $modParts), // Wave 79g — separator already in odd-index slots.
                ];
            }
        }

        // Wave 77 — no verbatim anchor match. We DO NOT append a link
        // at the end of a paragraph anymore — that produced garbage
        // dangling anchors that read as nonsense. Return found=false so
        // the caller skips this insertion. The link suggestion can be
        // re-considered with a better anchor by generateLinkSuggestions.
        return [
            'found'   => false,
            'reason'  => 'no_natural_anchor_match',
            'message' => "Anchor text \"{$anchor}\" does not appear verbatim in any unlinked paragraph of the source article. Skipping rather than appending to avoid garbage anchors.",
        ];
    }

    public function dismissLink(int $wsId, int $linkId): bool
    {
        return DB::table('seo_links')->where('workspace_id', $wsId)->where('id', $linkId)
            ->update(['status' => 'dismissed', 'updated_at' => now()]) > 0;
    }

    public function outboundLinks(int $wsId, array $params = []): array
    {
        return DB::table('seo_links')->where('workspace_id', $wsId)
            ->where('type', 'outbound')
            ->orderByDesc('created_at')
            ->get()->toArray();
    }

    /**
     * Keyword research for the AGENT path (James/Sarah). Mirrors POST /keywords/research
     * (DataForSeoConnector::relatedKeywords). Credits are reserved by EngineExecutionService
     * per the cap-map (credit_cost=1), so this does NOT reserve inline.
     */
    public function keywordResearch(int $wsId, array $params): array
    {
        $kw = trim((string) ($params['seed_keyword'] ?? $params['keyword'] ?? $params['topic'] ?? $params['seed'] ?? ''));
        if ($kw === '') { return ['success' => false, 'error' => 'seed keyword required', 'ideas' => []]; }
        $locCode = (int) ($params['location_code'] ?? 0);
        if (! $locCode) {
            $map = ['USA' => 2840, 'UK' => 2826, 'UAE' => 2784, 'US' => 2840, 'GB' => 2826, 'AE' => 2784];
            $locCode = $map[(string) ($params['market'] ?? $params['location'] ?? '')] ?? 2840;
        }
        try {
            $conn = new \App\Connectors\DataForSeoConnector();
            $res = $conn->relatedKeywords($kw, $locCode, 'en', 30);
            if (empty($res['success'])) {
                return ['success' => false, 'error' => 'Keyword research temporarily unavailable.', 'ideas' => []];
            }
            return ['success' => true, 'keyword' => $res['keyword'] ?? $kw, 'ideas' => $res['items'] ?? [], 'data' => $res['items'] ?? []];
        } catch (\Throwable $e) {
            Log::warning('SeoService keywordResearch failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Keyword research temporarily unavailable.', 'ideas' => []];
        }
    }

    public function checkOutbound(int $wsId, array $params = []): array
    {
        // Real outbound links live in seo_outbound_links (also the /outbound display).
        // (seo_links type=outbound was empty, so the old scan checked nothing.) Batch to
        // bound HTTP work; prioritise never-checked then stalest. SSRF-guarded HEADs.
        $limit = (int) ($params['limit'] ?? 50);
        if ($limit < 1 || $limit > 200) { $limit = 50; }
        $links = DB::table('seo_outbound_links')
            ->where('workspace_id', $wsId)
            ->orderByRaw('last_checked_at IS NULL DESC, last_checked_at ASC')
            ->limit($limit)
            ->get();

        $checked = 0; $broken = 0; $healthy = 0; $skipped = 0;
        $details = [];

        foreach ($links as $link) {
            $targetUrl = (string) ($link->target_url ?? '');
            $host   = strtolower((string) (parse_url($targetUrl, PHP_URL_HOST) ?: ''));
            $scheme = strtolower((string) (parse_url($targetUrl, PHP_URL_SCHEME) ?: ''));
            $blocked = $host === '' || ! in_array($scheme, ['http', 'https'], true)
                || $host === 'localhost'
                || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.|::1|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host);
            if ($blocked) { $skipped++; continue; }

            $checked++;
            $httpStatus = 0; $isHealthy = false;
            try {
                $response = Http::timeout(5)->head($targetUrl);
                $httpStatus = $response->status();
                $isHealthy = $httpStatus >= 200 && $httpStatus < 400;
            } catch (\Throwable $e) {
                Log::debug('SeoService: Outbound link check failed', ['url' => $targetUrl, 'error' => $e->getMessage()]);
            }
            $isHealthy ? $healthy++ : $broken++;

            try {
                DB::table('seo_outbound_links')->where('id', $link->id)->where('workspace_id', $wsId)->update([
                    'status'          => $isHealthy ? 'ok' : 'broken',
                    'http_status'     => $httpStatus,
                    'last_checked_at' => now(),
                    'updated_at'      => now(),
                ]);
            } catch (\Throwable $e) {
                Log::debug('SeoService: Could not update outbound link status', ['error' => $e->getMessage()]);
            }

            $details[] = ['id' => $link->id, 'url' => $targetUrl, 'http_status' => $httpStatus, 'healthy' => $isHealthy];
        }

        $this->engineIntel->recordToolUsage('seo', 'check_outbound');
        $this->logActivity($wsId, null, 'check_outbound', 'links', null, [
            'checked' => $checked, 'broken' => $broken, 'healthy' => $healthy, 'skipped' => $skipped,
        ]);

        return ['checked' => $checked, 'broken' => $broken, 'healthy' => $healthy, 'skipped' => $skipped, 'details' => $details, 'limit' => $limit];
    }

    // ═══════════════════════════════════════════════════════════
    // TOOLS 12-15: AUTONOMOUS GOALS
    // ═══════════════════════════════════════════════════════════

    public function createGoal(int $wsId, array $data): array
    {
        $goalId = DB::table('seo_goals')->insertGetId([
            'workspace_id' => $wsId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'active',
            'assigned_agent' => $data['agent'] ?? 'james',
            'progress_json' => json_encode(['steps_total' => 0, 'steps_completed' => 0, 'current_step' => null]),
            'tasks_json' => json_encode($this->planGoalTasks($data['title'], $data['description'] ?? '')),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->engineIntel->recordToolUsage('seo', 'autonomous_goal');

        return ['goal_id' => $goalId, 'status' => 'active'];
    }

    public function listGoals(int $wsId): array
    {
        return DB::table('seo_goals')->where('workspace_id', $wsId)->orderByDesc('created_at')->get()->toArray();
    }

    public function getGoal(int $wsId, int $goalId): ?object
    {
        return DB::table('seo_goals')->where('workspace_id', $wsId)->where('id', $goalId)->first();
    }

    public function pauseGoal(int $wsId, int $goalId): bool
    {
        return DB::table('seo_goals')->where('workspace_id', $wsId)->where('id', $goalId)
            ->update(['status' => 'paused', 'updated_at' => now()]) > 0;
    }

    public function resumeGoal(int $wsId, int $goalId): bool
    {
        return DB::table('seo_goals')->where('workspace_id', $wsId)->where('id', $goalId)
            ->update(['status' => 'active', 'updated_at' => now()]) > 0;
    }

    public function agentStatus(int $wsId): array
    {
        $activeGoals = DB::table('seo_goals')->where('workspace_id', $wsId)->where('status', 'active')->get();
        $recentAudits = DB::table('seo_audits')->where('workspace_id', $wsId)
            ->orderByDesc('created_at')->limit(5)->get();

        return [
            'active_goals' => $activeGoals->count(),
            'goals' => $activeGoals->toArray(),
            'recent_audits' => $recentAudits->toArray(),
            'agent' => 'james',
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // KEYWORDS
    // ═══════════════════════════════════════════════════════════

        public function addKeyword(int $wsId, array $data): int|array
    {
        // ── Plan limit enforcement ──────────────────────────────────
        $maxKeywords = $this->getPlanFeature($wsId, 'max_tracked_keywords', 0);
        if ($maxKeywords === 0) {
            return ['success' => false, 'error' => 'Keyword tracking requires AI Lite plan or higher.', 'limit_reached' => true, 'current' => 0, 'max' => 0];
        }
        $currentCount = DB::table('seo_keywords')->where('workspace_id', $wsId)->count();
        if ($currentCount >= $maxKeywords) {
            return ['success' => false, 'error' => "Keyword limit reached ({$maxKeywords} on your plan). Upgrade for more.", 'limit_reached' => true, 'current' => $currentCount, 'max' => $maxKeywords];
        }
        // ────────────────────────────────────────────────────────────

        // Check for duplicate
        $exists = DB::table('seo_keywords')->where('workspace_id', $wsId)->where('keyword', $data['keyword'])->exists();
        if ($exists) throw new \InvalidArgumentException("Keyword already tracked: {$data['keyword']}");

        return DB::table('seo_keywords')->insertGetId([
            'workspace_id' => $wsId,
            'keyword' => $data['keyword'],
            'volume' => $data['volume'] ?? null,
            'difficulty' => $data['difficulty'] ?? null,
            'cpc' => $data['cpc'] ?? null,
            'current_rank' => $data['current_rank'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'status' => 'tracking',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function updateKeywordRank(int $kwId, int $newRank): void
    {
        $kw = DB::table('seo_keywords')->where('id', $kwId)->first();
        if (!$kw) return;

        DB::table('seo_keywords')->where('id', $kwId)->update([
            'previous_rank' => $kw->current_rank,
            'current_rank' => $newRank,
            'updated_at' => now(),
        ]);
    }

        public function listKeywords(int $wsId, array $filters = []): array
    {
        $q = DB::table('seo_keywords')->where('workspace_id', $wsId);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['search'])) $q->where('keyword', 'like', '%' . $filters['search'] . '%');
        // Wave 16c — site scope filter on target_url (NULL = untargeted, show in all scopes)
        if (! empty($filters['site_url'])) {
            $host = \App\Engines\SEO\Support\SiteScope::hostFromUrl((string) $filters['site_url']);
            if ($host !== '') {
                $like = '%//' . $host . '%';
                $q->where(function ($x) use ($like) {
                    $x->where('target_url', 'like', $like)->orWhereNull('target_url');
                });
            }
        }
        $keywords = $q->orderByDesc('volume')->get()->toArray();

        // Usage metadata
        $maxKeywords = $this->getPlanFeature($wsId, 'max_tracked_keywords', 0);
        $count = count($keywords);

        // Scan info
        $scanInfo = $this->getKeywordScanInfo($wsId);

        return [
            'keywords' => $keywords,
            'usage' => [
                'count' => $count,
                'limit' => $maxKeywords,
                'can_add' => $maxKeywords > 0 && $count < $maxKeywords,
            ],
            'scan' => $scanInfo,
        ];
    }

    public function deleteKeyword(int $wsId, int $kwId): void
    {
        DB::table('seo_keywords')->where('workspace_id', $wsId)->where('id', $kwId)->delete();
    }

    // ═══════════════════════════════════════════════════════════
    // AUDITS
    // ═══════════════════════════════════════════════════════════

    public function getAudit(int $wsId, int $auditId): ?object
    {
        return DB::table('seo_audits')->where('workspace_id', $wsId)->where('id', $auditId)->first();
    }

    public function listAudits(int $wsId, array $filters = []): array
    {
        $q = DB::table('seo_audits')->where('workspace_id', $wsId);
        if (!empty($filters['type'])) $q->where('type', $filters['type']);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        // Wave 16c — site scope filter on audit URL
        if (! empty($filters['site_url'])) {
            $host = \App\Engines\SEO\Support\SiteScope::hostFromUrl((string) $filters['site_url']);
            if ($host !== '') {
                $q->where('url', 'like', '%//' . $host . '%');
            }
        }
        return $q->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get()->toArray();
    }

    // ═══════════════════════════════════════════════════════════
    // DASHBOARD & REPORTING
    // ═══════════════════════════════════════════════════════════

    /**
     * F11 (2026-05-17) — Live SEO knowledge aggregate for the Overview tab.
     *
     * Returns a snapshot of *current* workspace state — derived live from
     * seo_content_index and seo_link_graph. Crucially, NOT from the last
     * audit snapshot, so editing meta tags on any page is immediately
     * reflected in the dashboard's main gauge + content/link dim cards.
     *
     * Shape (matches what public/app/js/seo.js loadOverviewData expects):
     *   health_score        int 0..100        weighted avg content_score
     *   content_health      {avg_score, missing_meta_count, below_50_count, total_pages}
     *   link_health         {internal_link_count, orphan_count, score}
     *   keyword_rankings    [{kw, position, change}]
     *   top_issues          [{type, count}]
     *   summary             ?string           short human one-liner
     */
    public function getKnowledge(int $wsId): array
    {
        $sci = DB::table('seo_content_index')->where('workspace_id', $wsId);

        $totalPages   = (clone $sci)->count();
        $scoredAvg    = (clone $sci)->whereNotNull('content_score')->avg('content_score');
        $missingMeta  = (clone $sci)->where(function ($q) {
            $q->whereNull('meta_description')->orWhere('meta_description', '');
        })->count();
        $below50      = (clone $sci)->where('content_score', '<', 50)->whereNotNull('content_score')->count();
        $internalSum  = (int) (clone $sci)->sum('internal_link_count');
        $orphans      = (clone $sci)->where('inbound_links', 0)->where('word_count', '>', 100)->count(); // 2026-06-20 forensic: actionable orphans only (exclude homepage/thin)

        $healthScore  = $scoredAvg !== null ? (int) round($scoredAvg) : null;
        $linkScore    = $totalPages > 0 ? (int) round((($totalPages - $orphans) / max(1, $totalPages)) * 100) : null;

        // Tracked keyword positions (top N for the mini table).
        $keywordRanks = DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->where('status', 'tracking')
            ->orderByDesc('volume')
            ->limit(8)
            ->get(['keyword', 'current_rank', 'previous_rank'])
            ->map(fn($k) => [
                'kw'       => $k->keyword,
                'position' => $k->current_rank,
                'change'   => ($k->previous_rank !== null && $k->current_rank !== null)
                    ? ((int) $k->previous_rank - (int) $k->current_rank) : 0,
            ])
            ->all();

        // Top issues (counts, not per-row details).
        $topIssues = [];
        if ($missingMeta > 0) {
            $topIssues[] = ['type' => 'missing meta', 'count' => $missingMeta];
        }
        if ($below50 > 0) {
            $topIssues[] = ['type' => 'pages below 50', 'count' => $below50];
        }
        if ($orphans > 0) {
            $topIssues[] = ['type' => 'orphan pages', 'count' => $orphans];
        }

        $summary = $totalPages === 0
            ? 'No pages indexed yet. Run a scan to start tracking SEO health.'
            : ($healthScore === null
                ? "{$totalPages} pages indexed, scoring not yet computed."
                : "Avg page score {$healthScore} across {$totalPages} indexed pages. "
                  . ($missingMeta > 0 ? "{$missingMeta} pages missing meta description. " : '')
                  . ($orphans > 0 ? "{$orphans} orphan pages." : ''));

        return [
            'health_score'      => $healthScore,
            'content_health'    => [
                'avg_score'           => $healthScore,
                'missing_meta_count'  => $missingMeta,
                'below_50_count'      => $below50,
                'total_pages'         => $totalPages,
            ],
            'link_health'       => [
                'internal_link_count' => $internalSum,
                'orphan_count'        => $orphans,
                'score'               => $linkScore,
            ],
            'keyword_rankings'  => $keywordRanks,
            'top_issues'        => $topIssues,
            'summary'           => $summary,
        ];
    }

    public function getDashboard(int $wsId, ?string $siteUrl = null): array
    {
        // Wave 16c — site scope filter applied to every sub-query
        $host = $siteUrl ? \App\Engines\SEO\Support\SiteScope::hostFromUrl($siteUrl) : '';
        $like = $host !== '' ? ('%//' . $host . '%') : null;

        $keywords = DB::table('seo_keywords')->where('workspace_id', $wsId)->where('status', 'tracking');
        if ($like) {
            $keywords = $keywords->where(function ($q) use ($like) {
                $q->where('target_url', 'like', $like)->orWhereNull('target_url');
            });
        }
        $audits = DB::table('seo_audits')->where('workspace_id', $wsId);
        if ($like) { $audits = $audits->where('url', 'like', $like); }

        $kwCount = (clone $keywords)->count();
        $avgRank = (clone $keywords)->whereNotNull('current_rank')->avg('current_rank');
        $improving = (clone $keywords)->whereNotNull('current_rank')->whereNotNull('previous_rank')
            ->whereColumn('current_rank', '<', 'previous_rank')->count();
        $declining = (clone $keywords)->whereNotNull('current_rank')->whereNotNull('previous_rank')
            ->whereColumn('current_rank', '>', 'previous_rank')->count();

        $lastAudit = (clone $audits)->where('type', 'full')->where('status', 'completed')
            ->orderByDesc('created_at')->first();
        $avgAuditScore = (clone $audits)->where('status', 'completed')->avg('score');

        $activeGoals = DB::table('seo_goals')->where('workspace_id', $wsId)->where('status', 'active')->count();
        $linksQ = DB::table('seo_links')->where('workspace_id', $wsId);
        if ($like) {
            $linksQ = $linksQ->where(function ($q) use ($like) {
                $q->where('source_url', 'like', $like)->orWhere('target_url', 'like', $like);
            });
        }
        $suggestedLinks = (clone $linksQ)->where('status', 'suggested')->count();
        $insertedLinks  = (clone $linksQ)->where('status', 'inserted')->count();

        // Recent audit snapshots for trend data
        $recentSnapshots = [];
        try {
            $recentSnapshots = DB::table('seo_audit_snapshots')
                ->where('workspace_id', $wsId)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        // Settings status
        $settingsStatus = [];
        try {
            $settingsCount = DB::table('seo_settings')->where('workspace_id', $wsId)->count();
            $settingsStatus = [
                'configured' => $settingsCount > 0,
                'keys_set' => $settingsCount,
            ];
        } catch (\Throwable $e) {
            $settingsStatus = ['configured' => false, 'keys_set' => 0];
        }

        // DataForSEO<->GSC: surface real Search Console traffic on the dashboard when
        // the workspace has synced GSC (gsc_metrics). Site-url-filterable like the KPIs.
        $gscQ = DB::table('gsc_metrics')->where('workspace_id', $wsId);
        if ($siteUrl) { $gscQ->where('site_url', $siteUrl); }
        $gscAgg = (clone $gscQ)->selectRaw('SUM(clicks) c, SUM(impressions) i, AVG(position) p, COUNT(*) n')->first();
        $gscConnected = $gscAgg && (int) $gscAgg->n > 0;
        $gscClicks = $gscConnected ? (int) $gscAgg->c : null;
        $gscImpr   = $gscConnected ? (int) $gscAgg->i : null;
        $gscCtr    = ($gscConnected && $gscImpr > 0) ? round($gscClicks / $gscImpr, 4) : null;
        $gscPos    = $gscConnected ? round((float) $gscAgg->p, 1) : null;

        return [
            'keywords_tracked' => $kwCount,
            'avg_rank' => $avgRank ? round($avgRank, 1) : null,
            'keywords_improving' => $improving,
            'keywords_declining' => $declining,
            'last_audit_score' => $lastAudit?->score,
            'last_audit_date' => $lastAudit?->created_at,
            'avg_audit_score' => $avgAuditScore ? round($avgAuditScore, 1) : null,
            'total_audits' => (clone $audits)->count(),
            'active_goals' => $activeGoals,
            'suggested_links' => $suggestedLinks,
            'inserted_links' => $insertedLinks,
            'score_trend' => $recentSnapshots,
            'settings_status' => $settingsStatus,
            'gsc_connected' => $gscConnected,
            'gsc_clicks' => $gscClicks,
            'gsc_impressions' => $gscImpr,
            'gsc_ctr' => $gscCtr,
            'gsc_avg_position' => $gscPos,
        ];
    }

    public function getReport(int $wsId, ?string $siteUrl = null): array
    {
        // Wave 16c — forward site_url to every sub-fetch so the report is scoped.
        $f = $siteUrl ? ['site_url' => $siteUrl] : [];
        $dashboard = $this->getDashboard($wsId, $siteUrl);
        $keywords = $this->listKeywords($wsId, $f);
        $recentAudits = $this->listAudits($wsId, array_merge(['limit' => 10], $f));
        $goals = $this->listGoals($wsId);
        $links = $this->linkSuggestions($wsId, array_merge(['limit' => 10], $f));

        // Keyword rank distribution
        // FIX 2026-05-11: data_get() works on both arrays and objects.
        // listKeywords() returns ->toArray() so $kw is an array, but be defensive.
        $rankBuckets = ['1-3' => 0, '4-10' => 0, '11-20' => 0, '21-50' => 0, '51+' => 0, 'unranked' => 0];
        foreach ($keywords as $kw) {
            $r = data_get($kw, 'current_rank');
            if (!$r) $rankBuckets['unranked']++;
            elseif ($r <= 3) $rankBuckets['1-3']++;
            elseif ($r <= 10) $rankBuckets['4-10']++;
            elseif ($r <= 20) $rankBuckets['11-20']++;
            elseif ($r <= 50) $rankBuckets['21-50']++;
            else $rankBuckets['51+']++;
        }

        return array_merge($dashboard, [
            'keywords' => $keywords,
            'rank_distribution' => $rankBuckets,
            'recent_audits' => $recentAudits,
            'goals' => $goals,
            'top_link_suggestions' => $links,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // SETTINGS & CONFIGURATION
    // ═══════════════════════════════════════════════════════════

    /**
     * Get SEO settings for a workspace with defaults for missing keys.
     */
    public function getSettings(int $wsId): array
    {
        $defaults = [
            'auto_audit_enabled' => 'false',
            'auto_audit_frequency' => 'weekly',
            'serp_check_frequency' => 'daily',
            'notification_email' => '',
            'target_score' => '70',
            'auto_link_suggestions' => 'true',
            'max_crawl_pages' => '100',
            'ignore_noindex' => 'false',
        ];

        $stored = [];
        try {
            $rows = DB::table('seo_settings')
                ->where('workspace_id', $wsId)
                ->get();
            foreach ($rows as $row) {
                $stored[$row->key] = $row->value;
            }
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not read seo_settings', ['error' => $e->getMessage()]);
        }

        return array_merge($defaults, $stored);
    }

    /**
     * Save SEO settings (upsert key-value pairs).
     *
     * INC-0006 - settings are saved against ONE website. Passing no website targets the
     * business-wide default that every site inherits; the previous workspace-only write meant a
     * business with two sites had each save silently overwrite the other's configuration.
     */
    public function saveSettings(int $wsId, array $data, ?int $websiteId = null): array
    {
        $saved = [];
        $target = ($websiteId !== null && $websiteId > 0)
            ? $websiteId
            : \App\Core\Tenancy\WebsiteScope::BUSINESS_DEFAULT;
        try {
            foreach ($data as $key => $value) {
                DB::table('seo_settings')->updateOrInsert(
                    ['workspace_id' => $wsId, 'website_id' => $target, 'key' => $key],
                    ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()],
                );
                $saved[$key] = $value;
            }
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not save seo_settings', ['error' => $e->getMessage()]);
            return ['error' => 'Could not save settings: ' . $e->getMessage()];
        }

        $this->logActivity($wsId, null, 'save_settings', 'settings', null, ['keys' => array_keys($saved)]);

        return ['saved' => $saved];
    }

    // ═══════════════════════════════════════════════════════════
    // SCORE WEIGHTS
    // ═══════════════════════════════════════════════════════════

    /**
     * Get SEO score weights for a workspace with defaults for missing factors.
     */
    public function getScoreWeights(int $wsId): array
    {
        $defaults = [
            'meta_tags' => 15,
            'performance' => 20,
            'mobile' => 10,
            'security' => 10,
            'content' => 20,
            'technical' => 15,
            'schema' => 10,
        ];

        $stored = [];
        try {
            $rows = DB::table('seo_score_weights')
                ->where('workspace_id', $wsId)
                ->get();
            foreach ($rows as $row) {
                $stored[$row->factor] = (int) $row->weight;
            }
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not read seo_score_weights', ['error' => $e->getMessage()]);
        }

        return array_merge($defaults, $stored);
    }

    /**
     * Save SEO score weights for a workspace (upsert factor=>weight pairs).
     */
    public function saveScoreWeights(int $wsId, array $weights): array
    {
        $saved = [];
        try {
            foreach ($weights as $factor => $weight) {
                $weight = (int) $weight;
                $exists = DB::table('seo_score_weights')
                    ->where('workspace_id', $wsId)
                    ->where('factor', $factor)
                    ->exists();

                if ($exists) {
                    DB::table('seo_score_weights')
                        ->where('workspace_id', $wsId)
                        ->where('factor', $factor)
                        ->update([
                            'weight' => $weight,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('seo_score_weights')->insert([
                        'workspace_id' => $wsId,
                        'factor' => $factor,
                        'weight' => $weight,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $saved[$factor] = $weight;
            }
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not save seo_score_weights', ['error' => $e->getMessage()]);
            return ['error' => 'Could not save weights: ' . $e->getMessage()];
        }

        $this->logActivity($wsId, null, 'save_score_weights', 'settings', null, ['factors' => array_keys($saved)]);

        return ['saved' => $saved];
    }

    // ═══════════════════════════════════════════════════════════
    // REDIRECTS
    // ═══════════════════════════════════════════════════════════

    /**
     * List all redirects for a workspace.
     */
    public function listRedirects(int $wsId): array
    {
        try {
            return DB::table('seo_redirects')
                ->where('workspace_id', $wsId)
                ->orderByDesc('created_at')
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not list redirects', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create a new redirect.
     */
    public function createRedirect(int $wsId, array $data): array
    {
        try {
            $id = DB::table('seo_redirects')->insertGetId([
                'workspace_id' => $wsId,
                'source_url' => $data['source_url'],
                'target_url' => $data['target_url'],
                'status_code' => $data['status_code'] ?? 301,
                'is_active' => $data['is_active'] ?? true,
                'hit_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->logActivity($wsId, null, 'create_redirect', 'redirect', $id, [
                'source' => $data['source_url'],
                'target' => $data['target_url'],
            ]);

            return ['id' => $id, 'status' => 'created'];
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not create redirect', ['error' => $e->getMessage()]);
            return ['error' => 'Could not create redirect: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a redirect by ID.
     */
    public function deleteRedirect(int $id): bool
    {
        try {
            $redirect = DB::table('seo_redirects')->where('id', $id)->first();
            $deleted = DB::table('seo_redirects')->where('id', $id)->delete() > 0;

            if ($deleted && $redirect) {
                $this->logActivity(
                    $redirect->workspace_id ?? 0,
                    null,
                    'delete_redirect',
                    'redirect',
                    $id,
                    ['source' => $redirect->source_url ?? null]
                );
            }

            return $deleted;
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not delete redirect', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 404 LOG
    // ═══════════════════════════════════════════════════════════

    /**
     * Get the 404 error log for a workspace.
     */
    public function get404Log(int $wsId): array
    {
        try {
            return DB::table('seo_404_log')
                ->where('workspace_id', $wsId)
                ->orderByDesc('last_hit_at')
                ->limit(200)
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not read 404 log', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════
    // AUDIT SNAPSHOTS
    // ═══════════════════════════════════════════════════════════

    /**
     * Create an audit snapshot with delta calculation from previous snapshot.
     */
    public function createAuditSnapshot(int $wsId, int $score, array $counts): array
    {
        // Get previous snapshot for delta calculation
        $previous = null;
        try {
            $previous = DB::table('seo_audit_snapshots')
                ->where('workspace_id', $wsId)
                ->orderByDesc('created_at')
                ->first();
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        $delta = null;
        if ($previous) {
            $delta = $score - ($previous->score ?? 0);
        }

        try {
            $id = DB::table('seo_audit_snapshots')->insertGetId([
                'workspace_id' => $wsId,
                'score' => $score,
                'previous_score' => $previous->score ?? null,
                'delta' => $delta,
                'passed' => $counts['passed'] ?? 0,
                'warnings' => $counts['warnings'] ?? 0,
                'errors' => $counts['errors'] ?? 0,
                'total_checks' => $counts['total'] ?? 0,
                'snapshot_json' => json_encode($counts),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'score' => $score, 'delta' => $delta];
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not create audit snapshot', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════
    // ACTIVITY LOG
    // ═══════════════════════════════════════════════════════════

    /**
     * Log an activity to seo_activity_log.
     */
    public function logActivity(int $wsId, ?int $userId, string $action, ?string $objectType = null, ?int $objectId = null, ?array $meta = null): void
    {
        try {
            DB::table('seo_activity_log')->insert([
                'workspace_id' => $wsId,
                'user_id' => $userId,
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'meta_json' => $meta ? json_encode($meta) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Activity logging is non-critical — never let it break the main flow
            Log::debug('SeoService: Could not log activity', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE — ANALYSIS ENGINES
    // ═══════════════════════════════════════════════════════════

    /**
     * REFACTORED 2026-04-13 (Phase 2E.1): replaced rand()-based fakes with
     * real DataForSEO calls. Two endpoints touched per analysis:
     *   1. /v3/serp/google/organic/live/advanced — top results, SERP features, total count
     *   2. /v3/keywords_data/google_ads/search_volume/live — volume, CPC, difficulty
     *
     * Falls back gracefully to the old rand() shape if DataForSEO returns
     * an error or isn't configured (so the call site never crashes — same
     * defensive pattern as the rest of the engine).
     *
     * NOTE: opportunity_score is computed locally from real difficulty +
     * volume + CPC instead of being random.
     */
    /**
     * 2026-05-12 Phase 0 — array-based wrapper around the positional
     * scoreContent() method. Adds 4 new scoring factors not present in
     * the legacy 9-factor scorer. Total still normalises to 100.
     *
     * @param array        $data         Keys: title, meta_title, meta_description, h1,
     *                                   h2_count, word_count, image_count, internal_link_count,
     *                                   keyword (focus), has_schema, has_og, content (text body)
     * @return array                     {score:int, label:string, breakdown:array}
     */
    public function scoreContentExtended(array $data): array
    {
        // Delegate to the existing 9-factor positional scorer
        $base = $this->scoreContent(
            (string) ($data['meta_title']       ?? $data['title']       ?? ''),
            (string) ($data['meta_description'] ?? ''),
            (string) ($data['h1']               ?? ''),
            (int)    ($data['h2_count']         ?? 0),
            (int)    ($data['word_count']       ?? 0),
            (int)    ($data['image_count']      ?? 0),
            (int)    ($data['internal_link_count'] ?? 0),
            $data['keyword'] ?? null,
            $data['content'] ?? null
        );

        $breakdown = $base['breakdown'] ?? [];
        $total     = (int) ($base['score'] ?? 0);

        // ── New factor: Schema markup (4 pts) ───────────────────────────
        $hasSchema = (bool) ($data['has_schema'] ?? false);
        $schemaPts = $hasSchema ? 4 : 0;
        $breakdown[] = [
            'factor'  => 'schema_markup',
            'weight'  => 4,
            'score'   => $schemaPts,
            'details' => $hasSchema ? 'Schema markup detected' : 'No structured data — add JSON-LD',
        ];
        $total += $schemaPts;

        // ── New factor: Open Graph / Twitter tags (3 pts) ──────────────
        $hasOg = (bool) ($data['has_og'] ?? false);
        $ogPts = $hasOg ? 3 : 0;
        $breakdown[] = [
            'factor'  => 'og_tags',
            'weight'  => 3,
            'score'   => $ogPts,
            'details' => $hasOg ? 'Open Graph tags present' : 'Missing OG tags for social sharing',
        ];
        $total += $ogPts;

        // ── New factor: Internal links count (3 pts) ───────────────────
        $internalLinks = (int) ($data['internal_link_count'] ?? 0);
        $ilPts = $internalLinks >= 3 ? 3 : ($internalLinks >= 1 ? 1 : 0);
        $breakdown[] = [
            'factor'  => 'internal_links_weighted',
            'weight'  => 3,
            'score'   => $ilPts,
            'details' => "Found {$internalLinks} internal links" . ($internalLinks < 3 ? ' — aim for 3+' : ''),
        ];
        $total += $ilPts;

        // ── New factor: Readability — approx Flesch-Kincaid (3 pts) ────
        $readPts  = 0;
        $readNote = 'No content for readability check';
        $fkValue  = null;
        $text     = strip_tags((string) ($data['content'] ?? ''));
        if (mb_strlen($text) > 100) {
            $words     = max(1, str_word_count($text));
            $sentences = max(1, preg_match_all('/[.!?]+\s/', $text));
            preg_match_all('/[aeiouAEIOU]+/', $text, $vm);
            $syllables = max($words, count($vm[0] ?? []));
            $fk = 206.835 - (1.015 * ($words / $sentences)) - (84.6 * ($syllables / $words));
            $fk = max(0, min(100, $fk));
            $fkValue = round($fk, 1);
            if ($fk >= 60)      { $readPts = 3; $readNote = 'Easy to read (FK ' . round($fk) . ')'; }
            elseif ($fk >= 30)  { $readPts = 1; $readNote = 'Moderate readability (FK ' . round($fk) . ')'; }
            else                { $readPts = 0; $readNote = 'Hard to read (FK ' . round($fk) . ') — simplify sentences'; }
        }
        $breakdown[] = [
            'factor'  => 'readability',
            'weight'  => 3,
            'score'   => $readPts,
            'details' => $readNote,
        ];
        $total += $readPts;

        // ── F3 (2026-05-17): weights now sum to exactly 100 by design —
        //   base scoreContent caps at 100 (lines internal), and the four
        //   extended factors above re-scale base proportionally to free
        //   up their 13 pts. See _applyExtendedWeights() for the math.
        //   This replaces the old conditional rescale that left factor
        //   weights stale (e.g. 15-weight factor showing 13 score) and
        //   only fired when total > 100, producing inconsistent UI.
        // ── F9: pass workspace_id so per-workspace overrides from
        //   seo_score_weights take effect (only for known factor names).
        $wsId      = isset($data['workspace_id']) ? (int) $data['workspace_id'] : null;
        $rescaled  = $this->_applyExtendedWeights($breakdown, $wsId);
        $breakdown = $rescaled['breakdown'];
        $total     = $rescaled['total'];

        return [
            'score'             => $total,
            'label'             => $total >= 80 ? 'Great' : ($total >= 60 ? 'Good' : ($total >= 40 ? 'Needs Work' : 'Poor')),
            'breakdown'         => $breakdown,
            // F5 (2026-05-17): expose readability so callers can persist
            // it to seo_content_index.readability_score. Null = no content.
            'readability_score' => $fkValue,
        ];
    }

    /**
     * F3 (2026-05-17) — Rebalance extended scoring so weights sum to 100
     * exactly. F9 (2026-05-17) — optionally honors per-workspace overrides
     * from seo_score_weights table for known factor names (unknown factors
     * are ignored). If overrides don't sum to 100, the whole map is
     * renormalized so the 100-cap invariant holds.
     *
     * Default weights (sum = 100):
     *   content_length         17  (was 20)
     *   meta_title             13  (was 15)
     *   meta_description       13  (was 15)
     *   kw_factors / kw_*      26  (was 30 — split if keyword present)
     *   h1                      9  (was 10)
     *   h2                      4  (was 5)
     *   image                   4  (was 5)
     *   schema_markup           4  (unchanged)
     *   og_tags                 3  (unchanged)
     *   internal_links_weighted 3  (unchanged)
     *   readability             3  (unchanged)
     *   ─────────────────────────
     *   total                 100
     *
     * Returns {breakdown, total} where each entry's `weight` reflects the
     * post-rebalance value and `score` is proportional to it.
     */
    protected function _applyExtendedWeights(array $breakdown, ?int $wsId = null): array
    {
        $newWeights = $this->_getEffectiveWeights($wsId);

        $total = 0;
        foreach ($breakdown as &$entry) {
            if (! is_array($entry) || ! isset($entry['factor'])) { continue; }
            $factor = $entry['factor'];
            $oldWeight = (int) ($entry['weight'] ?? 0);
            $oldScore  = (int) ($entry['score']  ?? 0);
            $newWeight = $newWeights[$factor] ?? $oldWeight;

            // Rescale score proportionally to weight change; clamp to weight.
            if ($oldWeight > 0) {
                $newScore = (int) round($oldScore * ($newWeight / $oldWeight));
            } else {
                $newScore = 0;
            }
            $newScore = max(0, min($newWeight, $newScore));

            $entry['weight'] = $newWeight;
            $entry['score']  = $newScore;
            $total          += $newScore;
        }
        unset($entry);
        $total = min(100, max(0, $total));
        return ['breakdown' => $breakdown, 'total' => $total];
    }

    /**
     * F10 (2026-05-17) — Per-category audit score: pct of passes, with
     * warnings counted as half-credit. Returns null when the category has
     * zero checks, so the UI can show "—" instead of a fake 0.
     */
    protected function _categoryScore(array $catChecks): ?int
    {
        $n = count($catChecks);
        if ($n === 0) { return null; }
        $pass = 0; $warn = 0;
        foreach ($catChecks as $c) {
            $st = $c['status'] ?? 'unknown';
            if ($st === 'pass')         { $pass++; }
            elseif ($st === 'warning')  { $warn++; }
        }
        return (int) round((($pass + 0.5 * $warn) / $n) * 100);
    }

    /**
     * F10 (2026-05-17) — Average of category scores, ignoring nulls.
     * Returns null if every input is null (lets the UI show "—" rather
     * than fabricating a number from nothing).
     */
    protected function _avgScores(array $scores): ?int
    {
        $valid = array_values(array_filter($scores, fn($s) => $s !== null));
        if (count($valid) === 0) { return null; }
        return (int) round(array_sum($valid) / count($valid));
    }

    /**
     * F9 (2026-05-17) — Returns the effective per-factor weights for a
     * workspace. Reads seo_score_weights for known factor names; unknown
     * factors in the table are ignored (they exist from a legacy schema
     * where weights spoke about categories like 'performance' or 'mobile').
     * If the per-workspace overrides cause weights to not sum to 100, the
     * whole map is proportionally renormalized so the 100-cap holds.
     */
    protected function _getEffectiveWeights(?int $wsId): array
    {
        $defaults = [
            'content_length'          => 17,
            'meta_title'              => 13,
            'meta_description'        => 13,
            'kw_presence'             => 9,
            'kw_in_title'             => 9,
            'kw_density'              => 8,
            'kw_factors'              => 26,
            'h1'                      => 9,
            'h2'                      => 4,
            'image'                   => 4,
            'schema_markup'           => 4,
            'og_tags'                 => 3,
            'internal_links_weighted' => 3,
            'readability'             => 3,
        ];

        if ($wsId === null) { return $defaults; }

        try {
            $rows = DB::table('seo_score_weights')->where('workspace_id', $wsId)->get();
            $hasOverride = false;
            foreach ($rows as $row) {
                if (isset($defaults[$row->factor])) {
                    $defaults[$row->factor] = max(0, (int) $row->weight);
                    $hasOverride = true;
                }
            }
            if (! $hasOverride) { return $defaults; }

            // Renormalize: keyword sum (kw_factors counted separately from
            // kw_presence/kw_in_title/kw_density — never both at once in a
            // single breakdown), then everything else.
            $sum = array_sum($defaults) - $defaults['kw_factors'];
            // kw_factors is the "no-keyword" branch (worth same as
            // kw_presence+kw_in_title+kw_density). Renormalize the
            // non-keyword baseline to keep total = 100 in both branches.
            if ($sum > 0 && $sum !== (100 - $defaults['kw_factors'])) {
                $scale = (100 - $defaults['kw_factors']) / $sum;
                foreach ($defaults as $k => $v) {
                    if ($k === 'kw_factors') { continue; }
                    $defaults[$k] = (int) round($v * $scale);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SeoService::_getEffectiveWeights — table read failed', ['ws' => $wsId, 'error' => $e->getMessage()]);
        }
        return $defaults;
    }

    /**
     * 2026-05-12 Phase 0 — sync a Builder page into seo_content_index so the
     * SEO engine sees Builder-managed pages in the index. Non-destructive;
     * never modifies the pages table itself.
     *
     * Derives URL from websites.domain + pages.slug when available.
     */
    public function syncFromBuilder(int $wsId, object $page): void
    {
        try {
            $seoJson = json_decode((string) ($page->seo_json ?? '{}'), true) ?: [];

            // Build absolute URL: scheme://websites.domain/pages.slug
            $url = null;
            if (!empty($page->website_id)) {
                $website = DB::table('websites')->find($page->website_id);
                if ($website && !empty($website->domain) && !empty($page->slug)) {
                    $url = 'https://' . rtrim($website->domain, '/') . '/' . ltrim($page->slug, '/');
                }
            }
            // Fall back to workspace site_url + slug
            if (!$url && !empty($page->slug)) {
                $siteUrl = DB::table('seo_settings')->where('workspace_id', $wsId)
                    ->where('key', 'site_url')->value('value');
                if ($siteUrl) {
                    $url = rtrim($siteUrl, '/') . '/' . ltrim($page->slug, '/');
                }
            }
            if (!$url) { return; }

            $data = [
                'workspace_id'     => $wsId,
                'url'              => $url,
                'url_hash'         => md5($url),
                'title'            => $page->title ?? $seoJson['title'] ?? null,
                'meta_title'       => $page->meta_title       ?? $seoJson['meta_title'] ?? $seoJson['title'] ?? null,
                'meta_description' => $page->meta_description ?? $seoJson['meta_description'] ?? $seoJson['description'] ?? null,
                'updated_at'       => now(),
                'created_at'       => now(),
            ];

            // Score using the array-based wrapper
            $scored = $this->scoreContentExtended([
                'workspace_id'     => $wsId,
                'title'            => $data['title'],
                'meta_title'       => $data['meta_title'],
                'meta_description' => $data['meta_description'],
                'h1'               => $page->title ?? '',
                'word_count'       => 0,
                'keyword'          => $seoJson['keyword'] ?? null,
            ]);
            $data['content_score']        = (int) ($scored['score'] ?? 0);
            $data['score_breakdown_json'] = json_encode($scored['breakdown'] ?? []);
            if (isset($scored['readability_score'])) {
                $data['readability_score'] = (float) $scored['readability_score'];
            }
            $data['score_version'] = self::SCORE_VERSION;
            $data['scored_at']     = now();

            DB::table('seo_content_index')->upsert(
                [$data],
                ['workspace_id', 'url_hash'],
                ['url', 'title', 'meta_title', 'meta_description',
                 'content_score', 'score_breakdown_json', 'readability_score',
                 'score_version', 'scored_at', 'updated_at']
            );
        } catch (\Throwable $e) {
            \Log::warning('[SEO] syncFromBuilder failed: ' . $e->getMessage());
        }
    }

    /**
     * 2026-05-12 Phase 0 — sync a Write article into seo_content_index so the
     * SEO engine sees blog content in the index. Non-destructive; never
     * modifies the articles table itself.
     *
     * URL derived from articles.slug + workspace site_url. Articles without
     * a slug (drafts) are skipped silently.
     */
    public function syncFromArticle(int $wsId, object $article): void
    {
        try {
            if (empty($article->slug)) { return; }

            $seoJson = json_decode((string) ($article->seo_json ?? '{}'), true) ?: [];

            // RISK-0127 qq (2026-08-30): the index must hold REAL page URLs. An article bound to a LevelUp
            // website lives at https://{host}/blog/{slug}; an article that was published INTO WordPress is
            // indexed by the publish path with its real permalink (never site_url + Laravel slug, which
            // produced phantom rows like http://127.0.0.1:8093/<laravel-slug>); only a workspace with no
            // LevelUp site falls back to the legacy seo_settings site_url.
            $url = null;
            $websiteId = (int) ($article->website_id ?? 0);
            if ($websiteId > 0) {
                $site = DB::table('websites')->where('id', $websiteId)->first(['custom_domain', 'domain', 'subdomain', 'domain_verified']);
                $host = $site ? (($site->custom_domain && (int) $site->domain_verified === 1) ? $site->custom_domain : ($site->domain ?: $site->subdomain)) : null;
                if ($host) { $url = 'https://' . preg_replace('#^https?://#', '', rtrim((string) $host, '/')) . '/blog/' . ltrim($article->slug, '/'); }
            }
            if ($url === null && !empty($article->wp_post_id)) { return; }
            if ($url === null) {
                $siteUrl = DB::table('seo_settings')->where('workspace_id', $wsId)
                    ->where('key', 'site_url')->value('value');
                if (!$siteUrl) { return; }
                $url = rtrim($siteUrl, '/') . '/' . ltrim($article->slug, '/');
            }

            // Extract H1 from content if present, else title
            $content = (string) ($article->content ?? '');
            $h1 = $article->title ?? '';
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $content, $h1m)) {
                $h1 = trim(strip_tags($h1m[1]));
            }
            $textContent = strip_tags($content);
            $wordCount   = (int) ($article->word_count ?: str_word_count($textContent));

            // F8 (2026-05-17): extract full structural signals from article HTML.
            // Previously these were all 0/false, so every article scored ~69
            // regardless of its real content. Now h2/img/internal-link/schema/og
            // are parsed directly from the HTML the user wrote/agent generated.
            $h2Count    = preg_match_all('/<h2\b/i', $content) ?: 0;
            $imageCount = preg_match_all('/<img\b/i', $content) ?: 0;
            $parsedHost = parse_url($url, PHP_URL_HOST) ?: '';
            $internalLinkCount = 0;
            if ($parsedHost && preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $content, $hm)) {
                foreach ($hm[1] as $href) {
                    if ($href === '' || $href[0] === '#') { continue; }
                    if ($href[0] === '/' || parse_url($href, PHP_URL_HOST) === $parsedHost) {
                        $internalLinkCount++;
                    }
                }
            }
            $hasSchema = (bool) preg_match('/<script[^>]+type=["\']application\/ld\+json["\']/i', $content);
            $hasOg     = (bool) preg_match('/<meta[^>]+property=["\']og:/i', $content);

            $data = [
                'workspace_id'        => $wsId,
                'url'                 => $url,
                'url_hash'            => md5($url),
                'title'               => $article->title ?? null,
                'meta_title'          => $article->meta_title       ?? $seoJson['meta_title']       ?? $seoJson['title']       ?? $article->title ?? null,
                'meta_description'    => $article->meta_description ?? $seoJson['meta_description'] ?? $seoJson['description'] ?? null,
                'h1'                  => $h1,
                'word_count'          => $wordCount,
                'h2_count'            => $h2Count,
                'image_count'         => $imageCount,
                'internal_link_count' => $internalLinkCount,
                'has_schema'          => $hasSchema,
                'has_og'              => $hasOg,
                'updated_at'          => now(),
                'created_at'          => now(),
            ];

            $scored = $this->scoreContentExtended([
                'workspace_id'        => $wsId,
                'title'               => $data['title'],
                'meta_title'          => $data['meta_title'],
                'meta_description'    => $data['meta_description'],
                'h1'                  => $h1,
                'word_count'          => $wordCount,
                'h2_count'            => $h2Count,
                'image_count'         => $imageCount,
                'internal_link_count' => $internalLinkCount,
                'has_schema'          => $hasSchema,
                'has_og'              => $hasOg,
                'keyword'             => $article->focus_keyword ?? $seoJson['keyword'] ?? null,
                'content'             => $textContent,
            ]);
            $data['content_score']        = (int) ($scored['score'] ?? 0);
            $data['score_breakdown_json'] = json_encode($scored['breakdown'] ?? []);
            // F5 (2026-05-17): persist readability into the dedicated column,
            // not only inside the breakdown JSON. Anything reading the
            // column directly was getting NULL before.
            if (isset($scored['readability_score'])) {
                $data['readability_score'] = (float) $scored['readability_score'];
            }
            // F7 (2026-05-17): mark this row as scored under the current
            // scoring formula version, so a future weights/formula change
            // can flag stale rows for recompute.
            $data['score_version'] = self::SCORE_VERSION;
            $data['scored_at']     = now();

            DB::table('seo_content_index')->upsert(
                [$data],
                ['workspace_id', 'url_hash'],
                ['url', 'title', 'meta_title', 'meta_description', 'h1', 'word_count',
                 'h2_count', 'image_count', 'internal_link_count',
                 'has_schema', 'has_og',
                 'content_score', 'score_breakdown_json', 'readability_score',
                 'score_version', 'scored_at', 'updated_at']
            );

            // F1 (2026-05-17): write the computed score back to
            // articles.seo_score. Previously this column was NULL for every
            // article — the WriteService scorer never persisted (line 282
            // mutated an in-memory object that was thrown away), and nothing
            // else wrote to it. Now SCI is the source of truth and articles
            // gets a denormalized cache of the same value.
            try {
                if (! empty($article->id)) {
                    DB::table('articles')
                        ->where('id', $article->id)
                        ->update([
                            'seo_score'  => $data['content_score'],
                            'updated_at' => now(),
                        ]);
                }
            } catch (\Throwable $e) {
                \Log::warning('[SEO] syncFromArticle articles.seo_score writeback failed: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            \Log::warning('[SEO] syncFromArticle failed: ' . $e->getMessage());
        }
    }

    private function performSerpAnalysis(string $keyword, array $params): array
    {
        $isUrl = filter_var($keyword, FILTER_VALIDATE_URL);

        // URL analysis path stays approximate — DataForSEO doesn't accept a URL
        // as a SERP query (it expects a keyword). Keep the legacy shape for now;
        // a future enhancement could parse the URL's primary keyword and run a
        // full SERP analysis on that.
        if ($isUrl) {
            return [
                'keyword' => $keyword,
                'type' => 'url_analysis',
                'estimated_volume' => null,
                'difficulty' => null,
                'cpc' => null,
                'opportunity_score' => null,
                'current_position' => null,
                'serp_features' => [],
                'top_competitors' => [],
                'content_gaps' => [],
                'opportunities' => [],
                'source' => 'url_passthrough',
            ];
        }

        $locationCode = (int) ($params['location_code'] ?? \App\Connectors\DataForSeoConnector::LOCATION_UAE);

        // Fire SERP + keyword volume in sequence (could be parallelized later)
        $serp = $this->dataForSeo->serpAnalysis($keyword, $locationCode);
        $kwd  = $this->dataForSeo->keywordData([$keyword], $locationCode);

        $serpOk = ($serp['success'] ?? false);
        $kwdOk  = ($kwd['success']  ?? false);

        // Graceful fallback if DataForSEO returns errors
        if (!$serpOk && !$kwdOk) {
            Log::warning('SeoService: DataForSEO unavailable, returning structural fallback', [
                'keyword' => $keyword,
                'serp_error' => $serp['error'] ?? null,
                'kwd_error'  => $kwd['error']  ?? null,
            ]);
            return [
                'keyword' => $keyword,
                'type' => 'keyword_analysis',
                'estimated_volume' => null,
                'difficulty' => null,
                'cpc' => null,
                'opportunity_score' => null,
                'current_position' => null,
                'serp_features' => [],
                'top_competitors' => [],
                'content_gaps' => [],
                'opportunities' => [],
                'source' => 'live_data_unavailable',
                'error' => 'Live keyword and competitor data is temporarily unavailable. The rankings you already track and your Search Console data are unaffected.',
            ];
        }

        // Extract real values
        $kwRow      = $kwdOk ? ($kwd['keywords'][0] ?? []) : [];
        $volume     = $kwRow['volume'] ?? null;
        $cpc        = $kwRow['cpc'] ?? null;
        $compIndex  = $kwRow['competition_index'] ?? null;  // 0-100, used as difficulty proxy
        $serpFeat   = $serpOk ? ($serp['serp_features'] ?? []) : [];
        $topResults = $serpOk ? ($serp['top_results'] ?? []) : [];

        // Build top_competitors from real top SERP results (replaces hardcoded competitor1.com)
        $topCompetitors = [];
        foreach (array_slice($topResults, 0, 5) as $r) {
            $topCompetitors[] = [
                'domain'   => $r['domain'] ?? null,
                'position' => $r['position'] ?? null,
                'url'      => $r['url'] ?? null,
                'title'    => $r['title'] ?? null,
            ];
        }

        // Compute opportunity_score from real signals: lower difficulty + higher
        // volume + non-zero CPC + missing common SERP features = better opportunity.
        $opportunity = 50;
        if ($compIndex !== null) $opportunity += (int) round((50 - $compIndex) * 0.4);
        if ($volume    !== null && $volume > 1000)  $opportunity += 10;
        if ($volume    !== null && $volume > 10000) $opportunity += 10;
        if ($cpc       !== null && $cpc > 1.0)      $opportunity += 5;
        $opportunity = max(0, min(100, $opportunity));

        return [
            'keyword' => $keyword,
            'type' => 'keyword_analysis',
            'estimated_volume' => $volume,
            'difficulty' => $compIndex,  // 0-100 from DataForSEO competition_index
            'cpc' => $cpc,
            'opportunity_score' => $opportunity,
            'current_position' => null,  // we don't have a target site/domain in serpAnalysis params
            'serp_features' => $serpFeat,
            'top_competitors' => $topCompetitors,
            'top_results' => $topResults,
            'content_gaps' => [],  // computed in Phase 2E.2 from technical audit comparisons
            'opportunities' => $this->buildOpportunitiesFromRealData($volume, $compIndex, $cpc, $serpFeat),
            'source' => 'dataforseo',
            'location_code' => $locationCode,
        ];
    }

    /**
     * Build opportunity bullets from real DataForSEO signals (replaces the
     * hardcoded 3-item list). These feed the recommendations generator.
     */
    private function buildOpportunitiesFromRealData(?int $volume, ?int $compIndex, ?float $cpc, array $serpFeatures): array
    {
        $items = [];
        if ($volume !== null && $volume >= 1000 && ($compIndex === null || $compIndex < 50)) {
            $items[] = ['type' => 'content', 'description' => "High-volume keyword ({$volume}/mo) with manageable competition — create comprehensive pillar content", 'impact' => 'high'];
        }
        if ($compIndex !== null && $compIndex >= 70) {
            $items[] = ['type' => 'links', 'description' => 'High competition index — invest in authoritative backlinks before targeting', 'impact' => 'high'];
        }
        if ($cpc !== null && $cpc >= 2.0) {
            $items[] = ['type' => 'commercial', 'description' => "High CPC (\${$cpc}) signals strong commercial intent — prioritize conversion-focused content", 'impact' => 'medium'];
        }
        if (!in_array('featured_snippet', $serpFeatures, true)) {
            $items[] = ['type' => 'content', 'description' => 'No featured snippet present — opportunity to capture position 0 with structured content', 'impact' => 'medium'];
        }
        if (in_array('local_pack', $serpFeatures, true)) {
            $items[] = ['type' => 'local', 'description' => 'Local pack present in SERP — optimize Google Business Profile and local citations', 'impact' => 'high'];
        }
        if (empty($items)) {
            $items[] = ['type' => 'content', 'description' => 'Standard SEO best practices apply — produce well-structured, intent-matched content', 'impact' => 'medium'];
        }
        return $items;
    }

        /**
     * Real technical SEO audit — fetches URL, parses HTML with DOMDocument.
     * Replaces hardcoded 40-check stub with actual analysis.
     *
     * @since 2026-04-16 (P1 SEO audit rewrite)
     */
    private function runTechnicalChecks(string $url): array
    {
        $checks = [];

        // ── Fetch the URL ────────────────────────────────────────────
        $fetchStart = microtime(true);
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'LevelUpSEO/1.0 (audit)'])
                ->get($url);
        } catch (\Throwable $e) {
            $checks[] = ['category' => 'technical', 'check' => 'URL accessible', 'status' => 'error', 'details' => 'Could not fetch URL: ' . $e->getMessage()];
            return $checks;
        }
        $responseTimeMs = (int) round((microtime(true) - $fetchStart) * 1000);
        $httpStatus = $response->status();
        $html = $response->body();
        $pageSize = strlen($html);

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $checks[] = ['category' => 'technical', 'check' => 'URL accessible', 'status' => 'pass', 'details' => "HTTP {$httpStatus}"];
        } elseif ($httpStatus >= 300 && $httpStatus < 400) {
            $checks[] = ['category' => 'technical', 'check' => 'URL accessible', 'status' => 'warning', 'details' => "Redirect: HTTP {$httpStatus}"];
        } else {
            $checks[] = ['category' => 'technical', 'check' => 'URL accessible', 'status' => 'error', 'details' => "HTTP {$httpStatus}"];
            return $checks;
        }

        // ── Parse HTML with DOMDocument ──────────────────────────────
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        // Extract elements
        $titleNodes = $dom->getElementsByTagName('title');
        $titleText = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';

        $metaDesc = '';
        $metaRobots = '';
        $canonical = '';
        $hasOg = false;
        $hasTwitter = false;
        $viewport = false;

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $name = strtolower($meta->getAttribute('name'));
            $property = strtolower($meta->getAttribute('property'));
            $content = $meta->getAttribute('content');
            if ($name === 'description') $metaDesc = $content;
            if ($name === 'robots') $metaRobots = $content;
            if ($name === 'viewport') $viewport = true;
            if (str_starts_with($property, 'og:')) $hasOg = true;
            if (str_starts_with($name, 'twitter:')) $hasTwitter = true;
        }

        foreach ($dom->getElementsByTagName('link') as $link) {
            if (strtolower($link->getAttribute('rel')) === 'canonical') {
                $canonical = $link->getAttribute('href');
            }
        }

        $h1s = $dom->getElementsByTagName('h1');
        $h1Text = $h1s->length > 0 ? trim($h1s->item(0)->textContent) : '';
        $h1Count = $h1s->length;
        $h2Count = $dom->getElementsByTagName('h2')->length;
        $h3Count = $dom->getElementsByTagName('h3')->length;

        $images = $dom->getElementsByTagName('img');
        $imageCount = $images->length;
        $missingAlt = 0;
        foreach ($images as $img) {
            if (trim($img->getAttribute('alt')) === '') $missingAlt++;
        }

        $internalLinks = 0;
        $externalLinks = 0;
        $urlHost = parse_url($url, PHP_URL_HOST);
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            if (empty($href) || $href === '#') continue;
            $linkHost = parse_url($href, PHP_URL_HOST);
            if ($linkHost === null || $linkHost === $urlHost) $internalLinks++;
            else $externalLinks++;
        }

        $bodyNodes = $dom->getElementsByTagName('body');
        $bodyHtml = $bodyNodes->length > 0 ? $dom->saveHTML($bodyNodes->item(0)) : '';
        $text = strip_tags($bodyHtml);
        $text = preg_replace('/\s+/', ' ', trim($text));
        $wordCount = str_word_count($text);

        $hasSchema = false;
        $schemaTypes = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $hasSchema = true;
            $json = json_decode($script->textContent, true);
            if (is_array($json)) $schemaTypes[] = $json['@type'] ?? 'unknown';
        }

        // ── HTTPS ────────────────────────────────────────────────────
        $checks[] = ['category' => 'security', 'check' => 'HTTPS enabled', 'status' => str_starts_with($url, 'https://') ? 'pass' : 'error', 'details' => str_starts_with($url, 'https://') ? 'Site uses HTTPS' : 'Not using HTTPS'];

        // ── META TAGS ────────────────────────────────────────────────
        $titleLen = mb_strlen($titleText);
        $checks[] = ['category' => 'meta', 'check' => 'Title tag exists', 'status' => $titleLen > 0 ? 'pass' : 'error', 'details' => $titleLen > 0 ? "Title: \"" . mb_substr($titleText, 0, 60) . "\"" : 'No <title> tag found'];

        if ($titleLen > 0) {
            if ($titleLen >= 30 && $titleLen <= 60) {
                $checks[] = ['category' => 'meta', 'check' => 'Title length (30-60)', 'status' => 'pass', 'details' => "{$titleLen} chars"];
            } else {
                $checks[] = ['category' => 'meta', 'check' => 'Title length (30-60)', 'status' => 'warning', 'details' => "{$titleLen} chars — " . ($titleLen < 30 ? 'too short' : 'too long')];
            }
        }

        $descLen = mb_strlen($metaDesc);
        $checks[] = ['category' => 'meta', 'check' => 'Meta description exists', 'status' => $descLen > 0 ? 'pass' : 'error', 'details' => $descLen > 0 ? mb_substr($metaDesc, 0, 80) . ($descLen > 80 ? '...' : '') : 'No meta description'];

        if ($descLen > 0) {
            if ($descLen >= 70 && $descLen <= 160) {
                $checks[] = ['category' => 'meta', 'check' => 'Meta description length (70-160)', 'status' => 'pass', 'details' => "{$descLen} chars"];
            } else {
                $checks[] = ['category' => 'meta', 'check' => 'Meta description length (70-160)', 'status' => 'warning', 'details' => "{$descLen} chars — " . ($descLen < 70 ? 'too short' : 'too long')];
            }
        }

        if ($h1Count === 0) {
            $checks[] = ['category' => 'meta', 'check' => 'H1 tag exists', 'status' => 'error', 'details' => 'No H1 heading found'];
        } elseif ($h1Count === 1) {
            $checks[] = ['category' => 'meta', 'check' => 'H1 tag exists', 'status' => 'pass', 'details' => "H1: \"" . mb_substr($h1Text, 0, 60) . "\""];
        } else {
            $checks[] = ['category' => 'meta', 'check' => 'H1 tag exists', 'status' => 'warning', 'details' => "Multiple H1 tags ({$h1Count})"];
        }

        $checks[] = ['category' => 'meta', 'check' => 'Open Graph tags', 'status' => $hasOg ? 'pass' : 'warning', 'details' => $hasOg ? 'OG tags present' : 'No OG tags — social shares lack rich previews'];
        $checks[] = ['category' => 'meta', 'check' => 'Canonical URL', 'status' => !empty($canonical) ? 'pass' : 'warning', 'details' => !empty($canonical) ? 'Canonical set' : 'No canonical — duplicate content risk'];

        // ── PERFORMANCE ──────────────────────────────────────────────
        $checks[] = ['category' => 'performance', 'check' => 'Server response time', 'status' => $responseTimeMs < 1000 ? 'pass' : ($responseTimeMs < 3000 ? 'warning' : 'error'), 'details' => "{$responseTimeMs}ms" . ($responseTimeMs >= 1000 ? ' — aim for <1000ms' : '')];

        $pageSizeKb = round($pageSize / 1024);
        $checks[] = ['category' => 'performance', 'check' => 'Page size', 'status' => $pageSize < 500000 ? 'pass' : 'warning', 'details' => "{$pageSizeKb}KB"];

        if ($imageCount > 0) {
            $checks[] = ['category' => 'performance', 'check' => 'Image alt text', 'status' => $missingAlt === 0 ? 'pass' : 'warning', 'details' => $missingAlt === 0 ? "All {$imageCount} images have alt text" : "{$missingAlt}/{$imageCount} images missing alt text"];
        }

        $checks[] = ['category' => 'performance', 'check' => 'Compression', 'status' => $response->header('Content-Encoding') === 'gzip' ? 'pass' : 'warning', 'details' => $response->header('Content-Encoding') === 'gzip' ? 'GZIP enabled' : 'No GZIP detected'];

        // ── MOBILE ───────────────────────────────────────────────────
        $checks[] = ['category' => 'mobile', 'check' => 'Viewport meta tag', 'status' => $viewport ? 'pass' : 'error', 'details' => $viewport ? 'Responsive viewport found' : 'No viewport meta — not mobile-friendly'];

        // ── CONTENT ──────────────────────────────────────────────────
        if ($wordCount >= 300) {
            $checks[] = ['category' => 'content', 'check' => 'Content length', 'status' => 'pass', 'details' => "{$wordCount} words"];
        } elseif ($wordCount >= 150) {
            $checks[] = ['category' => 'content', 'check' => 'Content length', 'status' => 'warning', 'details' => "Thin: {$wordCount} words — aim for 300+"];
        } else {
            $checks[] = ['category' => 'content', 'check' => 'Content length', 'status' => 'error', 'details' => "Very thin: {$wordCount} words"];
        }

        $checks[] = ['category' => 'content', 'check' => 'Heading structure', 'status' => $h2Count > 0 ? 'pass' : 'warning', 'details' => "H1:{$h1Count} H2:{$h2Count} H3:{$h3Count}"];

        if ($internalLinks >= 3) {
            $checks[] = ['category' => 'content', 'check' => 'Internal links', 'status' => 'pass', 'details' => "{$internalLinks} internal links"];
        } elseif ($internalLinks > 0) {
            $checks[] = ['category' => 'content', 'check' => 'Internal links', 'status' => 'warning', 'details' => "Only {$internalLinks} internal link(s)"];
        } else {
            $checks[] = ['category' => 'content', 'check' => 'Internal links', 'status' => 'error', 'details' => 'No internal links — orphan page'];
        }

        $checks[] = ['category' => 'content', 'check' => 'External links', 'status' => $externalLinks > 0 ? 'pass' : 'warning', 'details' => $externalLinks > 0 ? "{$externalLinks} external links" : 'No external links'];

        if (!empty($metaRobots) && stripos($metaRobots, 'noindex') !== false) {
            $checks[] = ['category' => 'content', 'check' => 'Robots directive', 'status' => 'warning', 'details' => "noindex set: {$metaRobots}"];
        }

        // ── SCHEMA ───────────────────────────────────────────────────
        $checks[] = ['category' => 'schema', 'check' => 'Structured data', 'status' => $hasSchema ? 'pass' : 'error', 'details' => $hasSchema ? 'JSON-LD: ' . implode(', ', $schemaTypes) : 'No structured data found'];
        $checks[] = ['category' => 'schema', 'check' => 'Twitter Cards', 'status' => $hasTwitter ? 'pass' : 'warning', 'details' => $hasTwitter ? 'Twitter Card tags present' : 'No Twitter Card tags'];

        // ── TECHNICAL ────────────────────────────────────────────────
        $urlLen = mb_strlen($url);
        $checks[] = ['category' => 'technical', 'check' => 'URL length', 'status' => $urlLen <= 115 ? 'pass' : 'warning', 'details' => "{$urlLen} chars" . ($urlLen > 115 ? ' — too long' : '')];

        $hsts = $response->header('Strict-Transport-Security');
        $checks[] = ['category' => 'security', 'check' => 'HSTS header', 'status' => $hsts ? 'pass' : 'warning', 'details' => $hsts ? 'HSTS enabled' : 'HSTS not found'];

        // ── Store in content index ───────────────────────────────────
        $this->upsertContentIndex($url, [
            'title' => $titleText,
            'meta_title' => $titleText,
            'meta_description' => $metaDesc,
            'h1' => $h1Text,
            'h2_count' => $h2Count,
            'word_count' => $wordCount,
            'image_count' => $imageCount,
            'internal_link_count' => $internalLinks,
            'external_link_count' => $externalLinks,
            'canonical' => $canonical,
            'robots' => $metaRobots,
            'has_schema' => $hasSchema,
            'has_og' => $hasOg,
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTimeMs,
            'page_size_bytes' => $pageSize,
        ]);

        return $checks;
    }

    private function generateExecutiveSummary(string $url, $audits, $keywords): array
    {
        $avgScore = $audits->avg('score') ?? 50;
        return [
            'score' => (int) round($avgScore),
            'summary' => "SEO health for {$url}: " . ($avgScore >= 70 ? 'Good' : ($avgScore >= 40 ? 'Needs improvement' : 'Critical issues')),
            'top_priority' => $avgScore < 40 ? 'Technical fixes required urgently' : ($avgScore < 70 ? 'Content optimization recommended' : 'Maintain and build links'),
            'keywords_tracked' => $keywords->count(),
        ];
    }

        private function assessTechnicalHealth(string $url): array
    {
        try {
            $idx = DB::table('seo_content_index')->where('url_hash', hash('sha256', $url))->first();
            if ($idx) {
                $score = 50;
                $issues = 0;
                if (!empty($idx->meta_title)) $score += 10; else $issues++;
                if (!empty($idx->meta_description)) $score += 10; else $issues++;
                if (!empty($idx->h1)) $score += 5; else $issues++;
                if ($idx->has_schema) $score += 5; else $issues++;
                if ($idx->has_og) $score += 5; else $issues++;
                if (!empty($idx->canonical)) $score += 5; else $issues++;
                if ($idx->http_status >= 200 && $idx->http_status < 300) $score += 5;
                if ($idx->response_time_ms < 2000) $score += 5;
                return ['score' => min(100, $score), 'issues' => $issues, 'summary' => $score >= 70 ? 'Technical foundation is solid' : ($score >= 40 ? 'Some technical issues need attention' : 'Critical technical issues found')];
            }
        } catch (\Throwable $e) {}
        return ['score' => 50, 'issues' => 0, 'summary' => 'No audit data — run a deep audit first'];
    }

        private function assessContentQuality(string $url, $keywords): array
    {
        try {
            $idx = DB::table('seo_content_index')->where('url_hash', hash('sha256', $url))->first();
            if ($idx && $idx->content_score !== null) {
                return ['score' => $idx->content_score, 'word_count' => $idx->word_count, 'keyword_coverage' => round($keywords->count() > 0 ? 0.7 : 0.3, 2), 'pages_analyzed' => 1, 'avg_word_count' => $idx->word_count];
            }
        } catch (\Throwable $e) {}
        return ['score' => 50, 'pages_analyzed' => 0, 'avg_word_count' => 0, 'keyword_coverage' => round($keywords->count() > 0 ? 0.5 : 0.1, 2)];
    }

    private function assessKeywordPerformance(int $wsId, string $url): array
    {
        $kw = DB::table('seo_keywords')->where('workspace_id', $wsId)->get();
        $ranked = $kw->whereNotNull('current_rank');
        return [
            'score' => 60,
            'total_tracked' => $kw->count(),
            'ranked' => $ranked->count(),
            'avg_position' => $ranked->avg('current_rank') ? round($ranked->avg('current_rank'), 1) : null,
            'top_10' => $ranked->where('current_rank', '<=', 10)->count(),
        ];
    }

        private function assessBacklinkProfile(string $url): array
    {
        return ['score' => null, 'summary' => 'Backlink analysis requires external API integration (Phase 2)'];
    }

        private function assessCompetitorLandscape(int $wsId, string $url): array
    {
        $serpResults = DB::table('seo_serp_results')->where('workspace_id', $wsId)->orderByDesc('created_at')->limit(5)->get();
        if ($serpResults->isEmpty()) return ['score' => null, 'competitors_identified' => 0, 'summary' => 'No SERP data — run a SERP analysis first'];
        $competitorCount = $serpResults->pluck('snippet')->filter()->unique()->count();
        return ['score' => min(100, 40 + $competitorCount * 10), 'competitors_identified' => $competitorCount, 'summary' => "Analyzed {$competitorCount} competitor domains from SERP data"];
    }

    /**
     * REFACTORED 2026-04-13 (Phase 2E.1): generates real LLM-driven
     * recommendations from real DataForSEO SERP signals via the runtime
     * `chat_json` task type (Phase 0.17b). The LLM gets:
     *   - the target URL
     *   - the top-5 real SERP competitors (domain + title + snippet)
     *   - SERP features present
     *   - up to 5 most recent prior audit summaries
     * and returns a structured `{items: [...]}` recommendation list.
     *
     * Falls back to the legacy hardcoded 5 items if the LLM call fails OR if
     * DataForSEO can't supply SERP data (e.g. no keyword context). Defensive
     * because recommendations are surfaced in dashboards and shouldn't crash.
     */
    private function generateRecommendations(string $url, $audits): array
    {
        $hardcodedFallback = [
            'score' => null,
            'items' => [
                ['priority' => 'high', 'type' => 'technical', 'title' => 'Add structured data (Schema)', 'description' => 'Add Organization, LocalBusiness, Article, FAQ schema markup for better SERP visibility', 'engine' => 'builder'],
                ['priority' => 'high', 'type' => 'content', 'title' => 'Create pillar content', 'description' => 'Write 2000+ word guides for primary keywords', 'engine' => 'write'],
                ['priority' => 'medium', 'type' => 'performance', 'title' => 'Optimize images', 'description' => 'Compress and add alt text to all images', 'engine' => 'builder'],
                ['priority' => 'medium', 'type' => 'links', 'title' => 'Build internal link structure', 'description' => 'Add internal links between related content', 'engine' => 'seo'],
                ['priority' => 'low', 'type' => 'social', 'title' => 'Increase social signals', 'description' => 'Share content across social platforms for indirect SEO benefit', 'engine' => 'social'],
            ],
            'source' => 'fallback',
        ];

        try {
            $runtime = app(\App\Connectors\RuntimeClient::class);
            if (!$runtime->isConfigured()) {
                return $hardcodedFallback;
            }

            // Build a SERP context block from real DataForSEO data when possible.
            // We try to extract a candidate keyword from the URL path/host as the
            // SERP query input — best-effort, not perfect.
            $serpBlock = '';
            $kwCandidate = trim(preg_replace('/[^a-z0-9]+/i', ' ', parse_url($url, PHP_URL_HOST) ?? ''));
            if ($kwCandidate !== '') {
                $serp = $this->dataForSeo->serpAnalysis($kwCandidate);
                if ($serp['success'] ?? false) {
                    $top = array_slice($serp['top_results'] ?? [], 0, 5);
                    $serpBlock .= "TOP 5 SERP COMPETITORS for '{$kwCandidate}':\n";
                    foreach ($top as $i => $r) {
                        $idx = $i + 1;
                        $serpBlock .= "  {$idx}. {$r['domain']} — {$r['title']}\n";
                    }
                    if (!empty($serp['serp_features'])) {
                        $serpBlock .= "SERP FEATURES: " . implode(', ', $serp['serp_features']) . "\n";
                    }
                }
            }

            // Recent audit summaries (from the audits collection passed in)
            $auditBlock = '';
            $count = 0;
            foreach ($audits as $a) {
                if ($count >= 5) break;
                $auditBlock .= "  - audit_id={$a->id} score=" . ($a->score ?? 'n/a') . " type=" . ($a->type ?? 'n/a') . "\n";
                $count++;
            }

            $systemPrompt = "You are a senior SEO strategist. Generate URL-specific SEO recommendations based on the real signals provided. "
                          . "Return a JSON object with this exact shape: "
                          . '{"items":[{"priority":"high|medium|low","type":"technical|content|performance|links|social|local","title":"<short>","description":"<1-2 sentences>","engine":"seo|write|builder|social|marketing|crm"},...]}. '
                          . "Generate 5-8 items. Prioritize based on the SERP competitive landscape and prior audit findings. "
                          . "Be concrete — reference specific competitors or features when relevant. "
                          . "No markdown, no commentary outside the JSON.";

            $userPrompt = "TARGET URL: {$url}\n\n"
                        . ($serpBlock !== '' ? $serpBlock . "\n" : "(No SERP data available for this URL)\n\n")
                        . ($auditBlock !== '' ? "PRIOR AUDITS:\n{$auditBlock}\n" : "(No prior audits)\n\n")
                        . "Generate the recommendations now.";

            $result = $runtime->chatJson($systemPrompt, $userPrompt, [
                'task' => 'seo_recommendations',
                'url'  => $url,
            ], 1500);

            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null) && !empty($result['parsed']['items'])) {
                return [
                    'score' => null,
                    'items' => $result['parsed']['items'],
                    'source' => 'llm_via_dataforseo',
                ];
            }

            Log::warning('SeoService::generateRecommendations LLM call failed, using fallback', [
                'url' => $url,
                'error' => $result['error'] ?? null,
                'parse_error' => $result['parse_error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SeoService::generateRecommendations exception, using fallback', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $hardcodedFallback;
    }

    private function detectSerpFeatures(string $keyword): array
    {
        return ['featured_snippet', 'people_also_ask', 'local_pack', 'image_results'];
    }

    /**
     * REFACTORED 2026-04-13 (Phase 2E.1): replaced hardcoded
     * competitor1/2/3.com with real top-3 SERP results from DataForSEO.
     * Falls back to empty array if DataForSEO is unavailable (caller should
     * handle empty list gracefully — performSerpAnalysis already does).
     */
    private function getTopCompetitors(string $keyword): array
    {
        if (filter_var($keyword, FILTER_VALIDATE_URL)) {
            // URL passthrough — no SERP query possible
            return [];
        }

        $serp = $this->dataForSeo->serpAnalysis($keyword);
        if (!($serp['success'] ?? false)) {
            Log::warning('SeoService::getTopCompetitors fallback (DataForSEO unavailable)', [
                'keyword' => $keyword,
                'error' => $serp['error'] ?? 'unknown',
            ]);
            return [];
        }

        $top = [];
        foreach (array_slice($serp['top_results'] ?? [], 0, 3) as $r) {
            $top[] = [
                'domain'   => $r['domain'] ?? null,
                'position' => $r['position'] ?? null,
                'url'      => $r['url'] ?? null,
                'title'    => $r['title'] ?? null,
            ];
        }
        return $top;
    }

    private function buildSeoContext(int $wsId, string $keyword): array
    {
        $kw = DB::table('seo_keywords')->where('workspace_id', $wsId)->where('keyword', $keyword)->first();
        return [
            'keyword' => $keyword,
            'volume' => $kw?->volume,
            'difficulty' => $kw?->difficulty,
            'current_rank' => $kw?->current_rank,
            'target_url' => $kw?->target_url,
        ];
    }

    private function planGoalTasks(string $title, string $description): array
    {
        // Auto-plan tasks based on goal description
        $tasks = [
            ['step' => 1, 'action' => 'deep_audit', 'description' => 'Run technical audit', 'status' => 'pending'],
            ['step' => 2, 'action' => 'serp_analysis', 'description' => 'Analyze SERP for target keywords', 'status' => 'pending'],
            ['step' => 3, 'action' => 'link_suggestions', 'description' => 'Generate internal link suggestions', 'status' => 'pending'],
        ];

        if (stripos($title . $description, 'content') !== false || stripos($title . $description, 'article') !== false) {
            $tasks[] = ['step' => 4, 'action' => 'write_article', 'description' => 'Create SEO-optimized content', 'status' => 'pending', 'delegate_to' => 'write'];
        }

        return $tasks;
    }

    // ═══════════════════════════════════════════════════════════════
    // SEO CONTENT INDEX
    // ═══════════════════════════════════════════════════════════════

    /**
     * Fetch a URL and index its SEO data into seo_content_index.
     */
    /** RISK-0120 — block SSRF to internal/reserved hosts (incl. hostnames that resolve to them). */
    private function isBlockedFetchUrl(string $url): bool
    {
        // RISK-0120 — delegate to the canonical guard (single source of truth).
        return \App\Support\SsrfGuard::isBlockedUrl($url);
    }

    public function fetchAndIndexUrl(int $wsId, string $url): array
    {
        // 2026-05-12: normalize URL to a single canonical form so
        // 'https://site.com' and 'https://site.com/' don't produce
        // duplicate rows in seo_content_index / seo_images /
        // seo_outbound_links. Strip query/fragment last so they survive.
        $parts = parse_url($url);
        if ($parts && isset($parts['scheme'], $parts['host'])) {
            $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
            $url  = $parts['scheme'] . '://' . $parts['host'] . $path;
            if (!empty($parts['query']))    { $url .= '?' . $parts['query']; }
            if (!empty($parts['fragment'])) { $url .= '#' . $parts['fragment']; }
        }

        // RISK-0120 — SSRF guard: never fetch an internal/reserved address.
        if ($this->isBlockedFetchUrl($url)) {
            return ['success' => false, 'error' => 'blocked_url', 'message' => 'That URL is not fetchable.'];
        }
        $fetchStart = microtime(true);
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'LevelUpSEO/1.0 (indexer)'])
                ->withOptions(['allow_redirects' => [
                    'max'       => 5,
                    'protocols' => ['http', 'https'],
                    'on_redirect' => function ($req, $resp, $uri) {
                        // RISK-0120 — re-validate each redirect hop; abort on an internal target.
                        if ($this->isBlockedFetchUrl((string) $uri)) {
                            throw new \RuntimeException('blocked_redirect_target');
                        }
                    },
                ]])
                ->get($url);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Could not fetch: ' . $e->getMessage()];
        }
        $responseTimeMs = (int) round((microtime(true) - $fetchStart) * 1000);
        $httpStatus = $response->status();
        $html = $response->body();
        $pageSize = strlen($html);

        if ($httpStatus >= 400) {
            return ['success' => false, 'error' => "HTTP {$httpStatus}"];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $titleNodes = $dom->getElementsByTagName('title');
        $titleText = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';

        $metaDesc = '';
        $metaRobots = '';
        $canonical = '';
        $hasOg = false;
        $hasSchema = false;
        $lang = '';

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $name = strtolower($meta->getAttribute('name'));
            $property = strtolower($meta->getAttribute('property'));
            $content = $meta->getAttribute('content');
            if ($name === 'description') $metaDesc = $content;
            if ($name === 'robots') $metaRobots = $content;
            if (str_starts_with($property, 'og:')) $hasOg = true;
        }

        foreach ($dom->getElementsByTagName('link') as $link) {
            if (strtolower($link->getAttribute('rel')) === 'canonical') $canonical = $link->getAttribute('href');
        }

        $htmlTag = $dom->getElementsByTagName('html');
        if ($htmlTag->length > 0) $lang = $htmlTag->item(0)->getAttribute('lang');

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $_) { $hasSchema = true; break; }

        $h1s = $dom->getElementsByTagName('h1');
        $h1Text = $h1s->length > 0 ? trim($h1s->item(0)->textContent) : '';
        $h2Count = $dom->getElementsByTagName('h2')->length;
        $imageCount = $dom->getElementsByTagName('img')->length;

        $bodyNodes = $dom->getElementsByTagName('body');
        $bodyHtml = $bodyNodes->length > 0 ? $dom->saveHTML($bodyNodes->item(0)) : '';
        $text = strip_tags($bodyHtml);
        $text = preg_replace('/\s+/', ' ', trim($text));
        $wordCount = str_word_count($text);

        // 2026-05-13 Phase 1 — extract internal links with anchors so we can
        // (a) populate seo_link_graph (b) score CTR + (c) drive PageRank.
        $internalLinkRows = $this->extractInternalLinksWithAnchors($html ?? '', $url);
        $internalLinks    = count($internalLinkRows);
        $externalLinks    = 0;
        $urlHost = parse_url($url, PHP_URL_HOST);
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            if (empty($href) || $href === '#') { continue; }
            $linkHost = parse_url($href, PHP_URL_HOST);
            if ($linkHost && $linkHost !== $urlHost) { $externalLinks++; }
        }

        $intent = self::classifyIntent($titleText, $url, $text);

        // Build the data array up-front so scoreContentExtended can see all
        // the Phase 0 / Phase 1 signals (has_schema, has_og, internal links).
        $data = [
            'workspace_id'         => $wsId,
            'title'                => $titleText,
            'meta_title'           => $titleText,
            'meta_description'     => $metaDesc,
            'h1'                   => $h1Text,
            'h2_count'             => $h2Count,
            'word_count'           => $wordCount,
            'image_count'          => $imageCount,
            'internal_link_count'  => $internalLinks,
            'external_link_count'  => $externalLinks,
            'canonical'            => $canonical,
            'robots'               => $metaRobots,
            'has_schema'           => $hasSchema,
            'has_og'               => $hasOg,
            'lang'                 => $lang,
            'intent'               => $intent,
            'http_status'          => $httpStatus,
            'response_time_ms'     => $responseTimeMs,
            'page_size_bytes'      => $pageSize,
            'content'              => $text,
            'url'                  => $url,
        ];

        // Phase 1 — extended scorer (includes schema_markup, og_tags,
        // internal_links_weighted, readability).
        $scoreResult = $this->scoreContentExtended($data);
        $data['content_score']        = $scoreResult['score'];
        $data['score_breakdown_json'] = json_encode($scoreResult['breakdown']);
        if (isset($scoreResult['readability_score'])) {
            $data['readability_score'] = (float) $scoreResult['readability_score'];
        }
        $data['score_version'] = self::SCORE_VERSION;
        $data['scored_at']     = now();

        // Phase 1 — CTR potential scoring (intent × meta quality × schema × URL clarity).
        $ctr = $this->scoreCtrPotential($data);
        $data['ctr_potential_score'] = $ctr['score'];
        $data['ctr_label']           = $ctr['label'];

        // 2026-05-13 hotfix — $data['content'] and $data['url'] aren't columns
        // in seo_content_index; upsertContentIndex would otherwise silently
        // fail (try/catch in that method just Log::warnings). Strip them
        // before the upsert so ctr_potential_score + ctr_label actually persist.
        $upsertData = $data;
        unset($upsertData['content'], $upsertData['url']);
        $this->upsertContentIndex($url, $upsertData);
        $this->logActivity($wsId, null, 'index_url', 'seo_index', null, ['url' => $url, 'score' => $scoreResult['score']]);

        // 2026-05-12: extract outbound (external) links + <img> tags.
        try {
            $this->extractOutboundLinks($wsId, $url, $html ?? '');
            $this->extractImages($wsId, $url, $html ?? '');
        } catch (\Throwable $e) {
            Log::debug('[SEO] link/image extraction failed for ' . $url . ': ' . $e->getMessage());
        }

        // 2026-05-13 Phase 1 — persist internal-link graph rows. Delete-then-
        // insert per source URL keeps the table coherent without a UNIQUE
        // constraint (an anchor can change between visits).
        try {
            DB::table('seo_link_graph')
                ->where('workspace_id', $wsId)
                ->where('source_url', $url)
                ->where('is_internal', true)
                ->delete();
            if (!empty($internalLinkRows)) {
                $rows = [];
                $now = now();
                foreach ($internalLinkRows as $row) {
                    $rows[] = [
                        'workspace_id' => $wsId,
                        'source_url'   => $url,
                        'target_url'   => $row['url'],
                        'anchor_text'  => $row['anchor'] ?? null,
                        'is_internal'  => true,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }
                DB::table('seo_link_graph')->insert($rows);
            }
        } catch (\Throwable $e) {
            Log::debug('[SEO] link_graph insert failed for ' . $url . ': ' . $e->getMessage());
        }

        // 2026-05-12: auto-generate internal link suggestions after indexing.
        try {
            $this->generateLinkSuggestions($wsId, ['source_url' => $url]);
        } catch (\Throwable $e) {
            Log::debug('[SEO] link suggestions failed for ' . $url . ': ' . $e->getMessage());
        }

        return [
            'success'    => true,
            'url'        => $url,
            'score'      => $scoreResult['score'],
            'word_count' => $wordCount,
            'intent'     => $intent,
            'ctr_score'  => $ctr['score'],
            'ctr_label'  => $ctr['label'],
        ];
    }

    /**
     * 2026-05-12: extract external links from raw HTML into seo_outbound_links.
     * Internal links (same host) are skipped — handled separately by the
     * generateLinkSuggestions flow.
     */
    /**
     * 2026-05-13 Phase 1 — extract internal links WITH anchor text using
     * DOMDocument (more reliable than regex with HTML). Returns an array
     * of {url, anchor} pairs scoped to the same host as $baseUrl.
     */
    private function extractInternalLinksWithAnchors(string $html, string $baseUrl): array
    {
        if ($html === '' || $baseUrl === '') { return []; }
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!$host) { return []; }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $seen   = [];
        $links  = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#') { continue; }
            // Resolve relative URLs
            if (str_starts_with($href, '/')) {
                $href = $scheme . '://' . $host . $href;
            } elseif (!str_starts_with($href, 'http')) {
                continue;
            }
            $linkHost = parse_url($href, PHP_URL_HOST);
            if (!$linkHost || $linkHost !== $host) { continue; }
            // 2026-05-13 hotfix — mirror fetchAndIndexUrl URL normalization
            // (strip trailing slash, preserve query+fragment) so link_graph
            // targets match seo_content_index urls. Without this, every
            // page reports inbound_links = 0 and PageRank degenerates.
            $hp = parse_url($href);
            if ($hp && isset($hp['scheme'], $hp['host'])) {
                $path  = isset($hp['path']) ? rtrim($hp['path'], '/') : '';
                $clean = $hp['scheme'] . '://' . $hp['host'] . $path;
                if (!empty($hp['query']))    { $clean .= '?' . $hp['query']; }
                if (!empty($hp['fragment'])) { $clean .= '#' . $hp['fragment']; }
                $href = $clean;
            }
            if (isset($seen[$href])) { continue; }
            $seen[$href] = true;
            $anchor = trim($a->textContent ?? '');
            $links[] = [
                'url'    => $href,
                'anchor' => mb_substr($anchor, 0, 300),
            ];
        }
        return $links;
    }

    /**
     * Wave 81 — public router for CTR scoring.
     */
    private function scoreCtrPotential(array $data): array
    {
        // Wave 84 — runtime canonical. Safe default on failure.
        $result = app(\App\Connectors\RuntimeClient::class)->scoreCtr($data);
        return $result ?? ['score' => 0, 'label' => 'Unknown', 'reasons' => []];
    }

    /**
     * 2026-05-13 Phase 1 — compute CTR potential 0..100 for a page given
     * its meta/intent/schema signals. Heuristic; correlates with the
     * factors known to drive SERP click-through.
     *
     * Returns {score, label, reasons}.
     */

    /**
     * Re-compute content_score for a row whose meta_title/meta_description/h1
     * just changed, WITHOUT re-fetching the page body. Body-dependent
     * factors (kw_presence, kw_density, readability) are preserved from
     * the existing score_breakdown_json so the score doesn't artificially
     * drop just because we don't store body text.
     *
     * Returns: { score:int, label:string, breakdown:array, changed:bool, old_score:?int }
     */
    public function rescoreAfterMetaEdit(int $wsId, int $pageId): array
    {
        $row = \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('id', $pageId)
            ->first();

        if (! $row) {
            return ['score' => 0, 'label' => 'Poor', 'breakdown' => [], 'changed' => false, 'old_score' => null];
        }

        $oldScore = $row->content_score !== null ? (int) $row->content_score : null;
        $oldBreakdown = [];
        if (! empty($row->score_breakdown_json)) {
            $decoded = json_decode((string) $row->score_breakdown_json, true);
            if (is_array($decoded)) {
                $oldBreakdown = $decoded;
            }
        }

        // Index old breakdown by factor name for fast lookup
        $byFactor = [];
        foreach ($oldBreakdown as $entry) {
            if (is_array($entry) && isset($entry['factor'])) {
                $byFactor[(string) $entry['factor']] = $entry;
            }
        }

        // Try to pull the focus keyword from seo_keywords (highest-volume tracked
        // match for this URL); falls back to H1 phrase if available.
        $keyword = \Illuminate\Support\Facades\DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->where('target_url', $row->url)
            ->orderByDesc('volume')
            ->value('keyword');

        // Build the scorer data — meta_title/meta_description/h1 are the
        // NEW values from the just-updated DB row, everything else is what
        // was captured at scan time.
        $data = [
            'workspace_id'         => $wsId,
            'title'                => $row->title,
            'meta_title'           => $row->meta_title,
            'meta_description'     => $row->meta_description,
            'h1'                   => $row->h1,
            'h2_count'             => (int) ($row->h2_count ?? 0),
            'word_count'           => (int) ($row->word_count ?? 0),
            'image_count'          => (int) ($row->image_count ?? 0),
            'internal_link_count'  => (int) ($row->internal_link_count ?? 0),
            'has_schema'           => (bool) ($row->has_schema ?? false),
            'has_og'               => (bool) ($row->has_og ?? false),
            'keyword'              => $keyword,
            'content'              => null,  // intentionally null — preserved factors handle this
        ];

        // Run the extended scorer fresh
        $fresh = $this->scoreContentExtended($data);
        $freshBreakdown = $fresh['breakdown'] ?? [];

        // Identify body-text-dependent factors whose stored values are
        // higher than the fresh (zeroed-from-missing-content) values.
        // We swap those entries back in and recompute the total.
        $preserveFactors = ['kw_presence', 'kw_density', 'readability'];
        $mergedBreakdown = [];
        $total = 0;
        foreach ($freshBreakdown as $entry) {
            if (! is_array($entry) || ! isset($entry['factor'])) {
                $mergedBreakdown[] = $entry;
                continue;
            }
            $f = (string) $entry['factor'];
            if (in_array($f, $preserveFactors, true) && isset($byFactor[$f])) {
                $oldEntry = $byFactor[$f];
                $oldScore_factor = (int) ($oldEntry['score'] ?? 0);
                $newScore_factor = (int) ($entry['score']    ?? 0);
                // Take whichever is higher (old preserved if our fresh ran
                // with no content and got 0; new if meta actually changed it)
                if ($oldScore_factor > $newScore_factor) {
                    $merged = $entry;
                    $merged['score']   = $oldScore_factor;
                    $merged['details'] = ($oldEntry['details'] ?? $merged['details'])
                        . ' (preserved from last scan)';
                    $mergedBreakdown[] = $merged;
                    $total += $oldScore_factor;
                    continue;
                }
            }
            $mergedBreakdown[] = $entry;
            $total += (int) ($entry['score'] ?? 0);
        }
        $total = min(100, max(0, $total));
        $label = $total >= 80 ? 'Great' : ($total >= 60 ? 'Good' : ($total >= 40 ? 'Needs Work' : 'Poor'));

        // Persist
        $persistUpdate = [
            'content_score'        => $total,
            'score_breakdown_json' => json_encode($mergedBreakdown),
            'score_version'        => self::SCORE_VERSION,
            'scored_at'            => now(),
            'updated_at'           => now(),
        ];
        if (isset($fresh['readability_score'])) {
            $persistUpdate['readability_score'] = (float) $fresh['readability_score'];
        }
        \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('id', $pageId)
            ->update($persistUpdate);

        return [
            'score'     => $total,
            'label'     => $label,
            'breakdown' => $mergedBreakdown,
            'changed'   => $oldScore !== $total,
            'old_score' => $oldScore,
        ];
    }

    private function extractOutboundLinks(int $wsId, string $sourceUrl, string $html): void
    {
        if ($html === '') { return; }
        // Match double-quoted href only — covers virtually all real HTML and
        // sidesteps PHP-single-quote + regex-single-quote escape conflicts.
        preg_match_all('/<a\b[^>]*?\shref="(https?:\/\/[^"]+)"[^>]*>(.*?)<\/a>/si', $html, $m);
        $srcHost = preg_replace('/^www\./', '', parse_url($sourceUrl, PHP_URL_HOST) ?? '');
        if (!$srcHost) { return; }
        $seen = [];
        foreach ($m[1] ?? [] as $i => $targetUrl) {
            if (isset($seen[$targetUrl])) { continue; }
            $seen[$targetUrl] = true;
            $tgtHost = preg_replace('/^www\./', '', parse_url($targetUrl, PHP_URL_HOST) ?? '');
            if (!$tgtHost || $tgtHost === $srcHost) { continue; }
            $anchor = trim(strip_tags($m[2][$i] ?? ''));
            try {
                DB::table('seo_outbound_links')->updateOrInsert(
                    ['workspace_id' => $wsId, 'source_url' => $sourceUrl, 'target_url' => $targetUrl],
                    [
                        'target_host' => $tgtHost,
                        'anchor_text' => mb_substr($anchor, 0, 300),
                        'updated_at'  => now(),
                        'created_at'  => now(),
                    ]
                );
            } catch (\Throwable $e) { /* table may not exist on first deploy */ }
        }
    }

    
    private function extractImages(int $wsId, string $pageUrl, string $html): void
    {
        if ($html === '') { return; }

        // Phase 9A (2026-05-15): capture lazy-loaded imgs (data-src/data-lazy-src),
        // responsive imgs (srcset), and <picture><source srcset> variants — all
        // patterns that hide images from a raw-HTML scraper. Without this we
        // typically see ~1 img/page on modern WordPress sites; with it, ~5-15.

        $resolve = function (string $u) use ($pageUrl): string {
            $u = trim($u);
            if ($u === '' || stripos($u, 'data:') === 0) return '';
            if (stripos($u, 'http') === 0) return $u;
            if ($u[0] === '/') {
                $origin = preg_replace('#^(https?://[^/]+).*#', '$1', $pageUrl) ?? '';
                return rtrim($origin, '/') . $u;
            }
            return rtrim($pageUrl, '/') . '/' . ltrim($u, '/');
        };

        // Pick the largest URL from a srcset attribute. Handles both
        // "url 1x, url 2x" and "url 320w, url 1024w" forms. x-density is
        // weighted higher than width descriptors so 2x wins over 800w.
        $largestFromSrcset = function (string $srcset) use ($resolve): string {
            $best = ''; $bestScore = -1;
            foreach (explode(',', $srcset) as $part) {
                $bits = preg_split('/\s+/', trim($part));
                if (empty($bits[0])) continue;
                $score = 0;
                if (isset($bits[1]) && preg_match('/^(\d+)([wx])$/', $bits[1], $m)) {
                    $score = (int) $m[1] * ($m[2] === 'x' ? 1000 : 1);
                }
                if ($score >= $bestScore) { $bestScore = $score; $best = $bits[0]; }
            }
            return $resolve($best);
        };

        // Shared upsert helper — one entry point so <img> and <source> rows
        // land identically. Deduped on (workspace_id, page_url, image_url).
        $upsert = function (string $imgSrc, ?string $altText, ?string $titleText, bool $missingAlt, bool $emptyAlt, ?int $width, ?int $height) use ($wsId, $pageUrl) {
            if ($imgSrc === '' || stripos($imgSrc, 'data:') === 0) return;
            try {
                DB::table('seo_images')->updateOrInsert(
                    ['workspace_id' => $wsId, 'page_url' => $pageUrl, 'image_url' => mb_substr($imgSrc, 0, 500)],
                    [
                        'alt_text'    => $altText !== null ? mb_substr($altText, 0, 500) : null,
                        'title_text'  => $titleText !== null ? mb_substr($titleText, 0, 500) : null,
                        'missing_alt' => $missingAlt,
                        'empty_alt'   => $emptyAlt,
                        'width'       => $width,
                        'height'      => $height,
                        'scan_method' => 'laravel_http',
                        'updated_at'  => now(),
                        'created_at'  => now(),
                    ]
                );
            } catch (\Throwable $e) { /* table may not exist on first deploy */ }
        };

        // <img> tags — try src first, then lazy-load attrs, then srcset.
        preg_match_all('/<img\b[^>]*>/i', $html, $imgTags);
        foreach ($imgTags[0] ?? [] as $tag) {
            $imgSrc = '';

            if (preg_match('/\ssrc=(["\'])([^"\']+)\1/i', $tag, $sm)) {
                $imgSrc = $resolve($sm[2]);
            }

            // Detect lazy-load placeholders (transparent gif, 1x1, "blank", etc).
            // If src is a placeholder, prefer the lazy-attr URL instead.
            $isPlaceholder = $imgSrc !== '' && (
                stripos($imgSrc, 'data:image') === 0
                || preg_match('/(blank|placeholder|spinner|loading|lazy[\-_]bg)\.(gif|png|svg|webp)(\?|$)/i', $imgSrc)
                || preg_match('/(^|\/)1x1\.(gif|png)/i', $imgSrc)
            );

            if ($imgSrc === '' || $isPlaceholder) {
                foreach (['data-src', 'data-lazy-src', 'data-original', 'data-lazy', 'data-img', 'data-image'] as $attr) {
                    if (preg_match('/\s' . preg_quote($attr, '/') . '=(["\'])([^"\']+)\1/i', $tag, $lm)) {
                        $candidate = $resolve($lm[2]);
                        if ($candidate !== '') { $imgSrc = $candidate; break; }
                    }
                }
            }

            // srcset / data-srcset fallback (largest variant).
            if ($imgSrc === '' || $isPlaceholder) {
                foreach (['srcset', 'data-srcset'] as $attr) {
                    if (preg_match('/\s' . preg_quote($attr, '/') . '=(["\'])([^"\']+)\1/i', $tag, $ssm)) {
                        $candidate = $largestFromSrcset($ssm[2]);
                        if ($candidate !== '') { $imgSrc = $candidate; break; }
                    }
                }
            }

            if ($imgSrc === '') continue;

            $missingAlt = !preg_match('/\salt=/i', $tag);
            $altText    = null;
            $emptyAlt   = false;
            if (!$missingAlt && preg_match('/\salt=(["\'])([^"\']*)\1/i', $tag, $altM)) {
                $rawAlt  = $altM[2];
                $encoded = mb_convert_encoding($rawAlt, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                $altText = trim(html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $emptyAlt = $altText === '';
            }

            $titleText = null;
            if (preg_match('/\stitle=(["\'])([^"\']*)\1/i', $tag, $titleM)) {
                $titleText = $titleM[2];
            }

            $width  = preg_match('/\swidth=(["\']?)(\d+)\1/i',  $tag, $wM) ? (int) $wM[2] : null;
            $height = preg_match('/\sheight=(["\']?)(\d+)\1/i', $tag, $hM) ? (int) $hM[2] : null;

            $upsert($imgSrc, $altText, $titleText, $missingAlt, $emptyAlt, $width, $height);
        }

        // <source srcset="..."> from <picture> elements.
        // No alt of their own; we capture for inventory/size audit (Phase 9B)
        // with missing_alt=false so they don't surface as alt-text issues.
        preg_match_all('/<source\b[^>]*\ssrcset=(["\'])([^"\']+)\1/i', $html, $sourceTags);
        foreach ($sourceTags[2] ?? [] as $srcset) {
            $url = $largestFromSrcset($srcset);
            $upsert($url, null, null, false, false, null, null);
        }
    }

    /**
     * Phase P0.5 Tier 2 (2026-05-15) — rendered-DOM image extraction via
     * headless Chromium. Used when raw-HTML extraction (Tier 1) yields zero
     * images, typical for JS-rendered SPA pages.
     *
     * Security:
     *   - pageUrl host MUST match the workspace's canonical site_url host.
     *   - Localhost / private IPv4 / AWS metadata IP are explicitly blocked.
     *   - Process is spawned with limited env, hard 30s wall-clock cap.
     *
     * Persists captured images with scan_method='laravel_browser' so future
     * scans can distinguish them from Tier 1 captures.
     *
     * Returns the number of seo_images rows upserted (new + updated).
     * Returns 0 on any error (silent — Tier 2 is best-effort).
     */
    public function tier2ExtractRendered(int $wsId, string $pageUrl): int
    {
        // === Security: same-origin against workspace canonical site_url ===
        $canonical = (string) (DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'site_url')
            ->value('value') ?? '');
        if ($canonical === '') return 0;

        $cParsed = parse_url($canonical);
        $pParsed = parse_url($pageUrl);
        if (!is_array($cParsed) || !is_array($pParsed)) return 0;
        if (empty($cParsed['host']) || empty($pParsed['host'])) return 0;
        $cHost = strtolower((string) $cParsed['host']);
        $pHost = strtolower((string) $pParsed['host']);
        if ($cHost !== $pHost) return 0;

        // === Block localhost / private IPv4 / metadata IPs ===
        if (in_array($pHost, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '169.254.169.254'], true)) {
            return 0;
        }
        if (preg_match('/^10\./', $pHost)) return 0;
        if (preg_match('/^192\.168\./', $pHost)) return 0;
        if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $pHost)) return 0;
        if (preg_match('/^169\.254\./', $pHost)) return 0;

        // === Resolve renderer script ===
        $script = base_path('tools/page-image-extract.cjs');
        if (!is_file($script)) return 0;

        // === Spawn node child with explicit env (mirrors studio-render pattern) ===
        $cmd = 'node ' . escapeshellarg($script) . ' ' . escapeshellarg($pageUrl) . ' 2>&1';
        $childEnv = [
            'HOME'                => '/tmp',
            'PATH'                => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'PUPPETEER_CACHE_DIR' => base_path('.puppeteer-cache'),
            'LANG'                => 'C.UTF-8',
            'LC_ALL'              => 'C.UTF-8',
        ];
        $descriptorspec = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = @proc_open($cmd, $descriptorspec, $pipes, null, $childEnv);
        if (!is_resource($proc)) return 0;
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($proc);

        if ($status !== 0) {
            \Illuminate\Support\Facades\Log::warning('tier2ExtractRendered non-zero exit', [
                'workspace_id' => $wsId,
                'page_url'     => $pageUrl,
                'status'       => $status,
                'stderr'       => mb_substr((string) $stderr, 0, 500),
            ]);
            return 0;
        }

        $payload = json_decode((string) $stdout, true);
        if (!is_array($payload) || empty($payload['ok'])) {
            \Illuminate\Support\Facades\Log::warning('tier2ExtractRendered bad payload', [
                'workspace_id' => $wsId,
                'page_url'     => $pageUrl,
                'payload_head' => mb_substr((string) $stdout, 0, 300),
            ]);
            return 0;
        }

        $images = $payload['images'] ?? [];
        if (!is_array($images)) return 0;

        $upserted = 0;
        foreach ($images as $img) {
            $src = isset($img['src']) ? (string) $img['src'] : '';
            if ($src === '' || stripos($src, 'data:') === 0) continue;
            $src = mb_substr($src, 0, 500);
            $alt = isset($img['alt']) && $img['alt'] !== null ? mb_substr((string) $img['alt'], 0, 500) : null;
            $missingAlt = !array_key_exists('alt', $img) || $img['alt'] === null;
            $emptyAlt   = !$missingAlt && trim((string) ($img['alt'] ?? '')) === '';
            $width  = isset($img['width']) && is_numeric($img['width']) ? (int) $img['width'] : null;
            $height = isset($img['height']) && is_numeric($img['height']) ? (int) $img['height'] : null;
            try {
                DB::table('seo_images')->updateOrInsert(
                    ['workspace_id' => $wsId, 'page_url' => $pageUrl, 'image_url' => $src],
                    [
                        'alt_text'    => $alt,
                        'missing_alt' => $missingAlt,
                        'empty_alt'   => $emptyAlt,
                        'width'       => $width,
                        'height'      => $height,
                        'scan_method' => 'laravel_browser',
                        'updated_at'  => now(),
                        'created_at'  => now(),
                    ]
                );
                $upserted++;
            } catch (\Throwable $e) { /* table may not exist; skip silently */ }
        }
        return $upserted;
    }


    
    public function indexPageFromConnector(int $wsId, array $data): array
    {
        $url = $data['url'] ?? '';
        if (empty($url)) {
            throw new \InvalidArgumentException('url required');
        }
        $h2Count   = isset($data['h2s']) && is_array($data['h2s']) ? count($data['h2s']) : 0;
        $wordCount = (int) ($data['word_count'] ?? 0);
        $imgCount  = isset($data['images']) && is_array($data['images']) ? count($data['images']) : 0;
        $intLinks  = isset($data['internal_links']) && is_array($data['internal_links']) ? count($data['internal_links']) : 0;

        // FIX 2026-05-11: compute score INSIDE the wrapper so we can persist
        // content_score + score_breakdown_json on the same upsert. Previously
        // the route computed score post-upsert and never wrote it back.
        // 2026-05-13 Phase 1 — switch to scoreContentExtended so connector-pushed
        // pages also get schema/og/internal_links/readability scoring.
        $score = $this->scoreContentExtended([
            'workspace_id'        => $wsId,
            'title'               => $data['title'] ?? '',
            'meta_title'          => $data['title'] ?? '',
            'meta_description'    => $data['meta_description'] ?? '',
            'h1'                  => $data['h1'] ?? '',
            'h2_count'            => $h2Count,
            'word_count'          => $wordCount,
            'image_count'         => $imgCount,
            'internal_link_count' => $intLinks,
            'has_schema'          => (bool) ($data['has_schema'] ?? false),
            'has_og'              => (bool) ($data['has_og'] ?? false),
            'content'             => $data['content'] ?? null,
            'keyword'             => $data['target_keyword'] ?? null,
        ]);

        $payload = [
            'workspace_id'         => $wsId,
            'title'                => $data['title'] ?? null,
            'meta_title'           => $data['title'] ?? null,
            'meta_description'     => $data['meta_description'] ?? null,
            'h1'                   => $data['h1'] ?? null,
            'h2_count'             => $h2Count,
            'word_count'           => $wordCount,
            'image_count'          => $imgCount,
            'internal_link_count'  => $intLinks,
            'content_score'        => $score['score'] ?? null,
            'score_breakdown_json' => isset($score['breakdown']) ? json_encode($score['breakdown']) : null,
            'readability_score'    => $score['readability_score'] ?? null,
            'score_version'        => self::SCORE_VERSION,
            'scored_at'            => now(),
        ];
        // Change 2B-1: persist WP post_id when provided by plugin
        if (isset($data['post_id']) && is_numeric($data['post_id'])) {
            $payload['wp_post_id'] = (int) $data['post_id'];
        }
        $this->upsertContentIndex($url, $payload);
        $row = DB::table('seo_content_index')->where('url_hash', hash('sha256', $url))->first();
        return [
            'page_id' => $row ? (int) $row->id : 0,
            'score'   => $score,
        ];
    }

    /**
     * SEO assistant — routed through RuntimeClient::assistant() with a James
     * (SEO Strategist) persona + dynamic workspace context (latest audit score,
     * top keywords). Replaces the earlier stub.
     *
     * NOTE: SEOContextProvider only exposes get(); the LLM call goes via
     * RuntimeClient::assistant() — the same path agent DMs use.
     */
    /** W6: replies pass through LaunchScopeLanguageGuard - see wrapper below. */
    public function assistantMessage(int $wsId, string $message, array $context = []): array
    {
        // 2026-05-13 — full rebuild moved to SeoAssistantService.
        // The new service handles workspace memory (Redis, 90d), conversation
        // history (Redis, 24h, _v2 key), pending-action store (Redis, 5min),
        // a keyword intent classifier, and an execution engine that fires
        // audits / articles / SERP / reports / links / metas / keyword tracking.
        // W6: the WordPress connector assistant had no launch-scope guard at
        // all. Every reply now passes through the same control the SPA uses.
        $result = app(\App\Engines\SEO\Services\SeoAssistantService::class)
            ->handle($wsId, $message, $context);
        foreach (['response', 'message', 'reply', 'text'] as $k) {
            if (!empty($result[$k]) && is_string($result[$k])) {
                $result[$k] = \App\Core\LaunchScope\LaunchScopeLanguageGuard::apply($result[$k]);
            }
        }
        return $result;
    }

    private function upsertContentIndex(string $url, array $data): void
    {
        try {
            $urlHash = hash('sha256', $url);
            $data['url'] = $url;
            $data['url_hash'] = $urlHash;
            $data['indexed_at'] = now();
            $data['updated_at'] = now();

            $existing = DB::table('seo_content_index')->where('url_hash', $urlHash)->first();
            if ($existing) {
                DB::table('seo_content_index')->where('id', $existing->id)->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('seo_content_index')->insert($data);
            }
        } catch (\Throwable $e) {
            Log::warning('SeoService: Could not upsert seo_content_index', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // CONTENT SCORING ENGINE (9-factor weighted)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Score content using 9 weighted factors (ported from LUGS_Content_Scorer).
     * Weights: content_length(20) meta_title(15) meta_desc(15) kw_presence(10)
     *          kw_in_title(10) kw_density(10) h1(10) h2(5) image(5)
     */
    public function scoreContent(
        string $title, string $metaDesc, string $h1, int $h2Count,
        int $wordCount, int $imageCount, int $internalLinkCount,
        ?string $keyword = null, ?string $textContent = null
    ): array {
        $breakdown = [];
        $totalScore = 0;

        // 1. Content Length (20 pts)
        $lengthPct = min(100, $wordCount > 0 ? ($wordCount / 300) * 100 : 0);
        $ls = (int) round(20 * ($lengthPct / 100));
        $breakdown[] = ['factor' => 'content_length', 'weight' => 20, 'score' => $ls, 'details' => "{$wordCount} words" . ($wordCount < 300 ? ' — aim for 300+' : '')];
        $totalScore += $ls;

        // 2. Meta Title (15 pts)
        $tl = mb_strlen($title);
        if ($tl === 0) { $s = 0; $d = 'Missing'; }
        elseif ($tl >= 30 && $tl <= 60) { $s = 15; $d = "{$tl} chars (ideal)"; }
        else { $s = 8; $d = "{$tl} chars — " . ($tl < 30 ? 'too short' : 'too long'); }
        $breakdown[] = ['factor' => 'meta_title', 'weight' => 15, 'score' => $s, 'details' => $d];
        $totalScore += $s;

        // 3. Meta Description (15 pts)
        $dl = mb_strlen($metaDesc);
        if ($dl === 0) { $s = 0; $d = 'Missing'; }
        elseif ($dl >= 70 && $dl <= 160) { $s = 15; $d = "{$dl} chars (ideal)"; }
        else { $s = 8; $d = "{$dl} chars — " . ($dl < 70 ? 'too short' : 'too long'); }
        $breakdown[] = ['factor' => 'meta_description', 'weight' => 15, 'score' => $s, 'details' => $d];
        $totalScore += $s;

        // 4-6. Keyword factors (30 pts total)
        if ($keyword && $keyword !== '') {
            $kwLower = mb_strtolower($keyword);
            // 4. Presence
            $found = $textContent && stripos($textContent, $keyword) !== false;
            $breakdown[] = ['factor' => 'kw_presence', 'weight' => 10, 'score' => $found ? 10 : 0, 'details' => $found ? 'Found in content' : 'Not found'];
            $totalScore += $found ? 10 : 0;
            // 5. In title
            $inTitle = stripos($title, $keyword) !== false;
            $breakdown[] = ['factor' => 'kw_in_title', 'weight' => 10, 'score' => $inTitle ? 10 : 0, 'details' => $inTitle ? 'In title' : 'Not in title'];
            $totalScore += $inTitle ? 10 : 0;
            // 6. Density
            if ($textContent && $wordCount > 0) {
                $kwCount = mb_substr_count(mb_strtolower($textContent), $kwLower);
                $kwWords = str_word_count($keyword);
                $density = ($kwCount * $kwWords / $wordCount) * 100;
                if ($density >= 0.5 && $density <= 2.5) { $s = 10; $d = round($density, 1) . '% (ideal)'; }
                elseif ($density > 0) { $s = 5; $d = round($density, 1) . '%'; }
                else { $s = 0; $d = '0%'; }
            } else { $s = 5; $d = 'No text for density'; }
            $breakdown[] = ['factor' => 'kw_density', 'weight' => 10, 'score' => $s, 'details' => $d];
            $totalScore += $s;
        } else {
            $breakdown[] = ['factor' => 'kw_factors', 'weight' => 30, 'score' => 15, 'details' => 'No focus keyword — partial credit'];
            $totalScore += 15;
        }

        // 7. H1 (10 pts) — reject SPA placeholders so unrendered pages don't get full credit
        $h1Trimmed     = trim((string) $h1);
        $h1Placeholder = $h1Trimmed === '' || preg_match('/^(loading\.{0,3}|untitled|please wait|—|-)$/i', $h1Trimmed);
        if ($h1Placeholder) {
            $s = 0;
            $h1Detail = $h1Trimmed === '' ? 'Missing' : 'Placeholder H1: "' . mb_substr($h1Trimmed, 0, 50) . '" — page may not have rendered';
        } else {
            $s = 10;
            $h1Detail = 'H1: "' . mb_substr($h1Trimmed, 0, 50) . '"';
        }
        $breakdown[] = ['factor' => 'h1', 'weight' => 10, 'score' => $s, 'details' => $h1Detail];
        $totalScore += $s;

        // 8. H2 (5 pts)
        if ($h2Count >= 2) { $s = 5; } elseif ($h2Count === 1) { $s = 3; } else { $s = 0; }
        $breakdown[] = ['factor' => 'h2', 'weight' => 5, 'score' => $s, 'details' => "{$h2Count} H2 subheadings"];
        $totalScore += $s;

        // 9. Images (5 pts)
        $s = $imageCount > 0 ? 5 : 0;
        $breakdown[] = ['factor' => 'image', 'weight' => 5, 'score' => $s, 'details' => $imageCount > 0 ? "{$imageCount} image(s)" : 'No images'];
        $totalScore += $s;

        $totalScore = min(100, max(0, $totalScore));
        return [
            'score' => $totalScore,
            'label' => $totalScore >= 80 ? 'Great' : ($totalScore >= 60 ? 'Good' : ($totalScore >= 40 ? 'Needs Work' : 'Poor')),
            'breakdown' => $breakdown,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // INTENT DETECTION (deterministic)
    // ═══════════════════════════════════════════════════════════════

    public static function classifyIntent(string $title, string $url, string $text): string
    {
        $combined = mb_strtolower($title . ' ' . $url . ' ' . mb_substr($text, 0, 500));
        $scores = ['transactional' => 0, 'commercial' => 0, 'navigational' => 0, 'informational' => 0];

        foreach (['buy','price','cheap','deal','discount','order','purchase','shop','sale','checkout'] as $kw)
            if (strpos($combined, $kw) !== false) $scores['transactional'] += 2;
        foreach (['best','top','review','compare','vs','versus','alternative','comparison','recommended'] as $kw)
            if (strpos($combined, $kw) !== false) $scores['commercial'] += 2;
        foreach (['login','sign in','dashboard','account','contact us','about us','support'] as $kw)
            if (strpos($combined, $kw) !== false) $scores['navigational'] += 2;
        foreach (['how to','what is','why','guide','tutorial','learn','tips','example','definition','step by step'] as $kw)
            if (strpos($combined, $kw) !== false) $scores['informational'] += 2;

        $max = max($scores);
        if ($max === 0) return 'informational';
        return array_search($max, $scores) ?: 'informational';
    }



    // ═══════════════════════════════════════════════════════════════
    // PLAN FEATURE HELPERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get a feature value from the workspace's active plan.
     */
    private function getPlanFeature(int $wsId, string $feature, mixed $default = null): mixed
    {
        $plan = \App\Models\Subscription::entitledPlanFor($wsId); // MONEY-1: counts trialing + pool

        if (!$plan) return $default;

        $features = is_string($plan->features_json) ? json_decode($plan->features_json, true) : (array) $plan->features_json;
        return $features[$feature] ?? $default;
    }

    /**
     * Get keyword scan schedule info for a workspace.
     */
    private function getKeywordScanInfo(int $wsId): array
    {
        // Last scan: most recent last_rank_check from this workspace's keywords
        $lastCheck = DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->whereNotNull('last_rank_check')
            ->max('last_rank_check');

        $hasScanned = $lastCheck !== null;
        $lastScanDate = $hasScanned ? \Carbon\Carbon::parse($lastCheck) : null;

        // Next scan: next Monday from today
        $now = now();
        $nextMonday = $now->copy()->next(\Carbon\Carbon::MONDAY);
        if ($now->dayOfWeek === \Carbon\Carbon::MONDAY && !$hasScanned) {
            $nextMonday = $now->copy()->startOfDay(); // Today if Monday and not scanned yet
        }

        $frequency = $this->getPlanFeature($wsId, 'rank_check_frequency', 'never');

        return [
            'frequency' => $frequency,
            'has_scanned' => $hasScanned,
            'last_scan_date' => $lastScanDate?->toDateString(),
            'last_scan_date_formatted' => $lastScanDate?->format('l, F j, Y'),
            'next_scan_date' => $frequency !== 'never' ? $nextMonday->toDateString() : null,
            'next_scan_date_formatted' => $frequency !== 'never' ? $nextMonday->format('l, F j, Y') : null,
        ];
    }

    /**
     * v1.4.4 (2026-05-30) — Competitor SERP lookup.
     * Returns top-10 competitor positions for a keyword from
     * seo_serp_results (one row per SERP position, populated by serpAnalysis).
     */
    public function competitorSerp(int $wsId, array $params): array
    {
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword === '') {
            return ['success' => false, 'error' => 'keyword is required'];
        }
        $items = DB::table('seo_serp_results')
            ->where('workspace_id', $wsId)
            ->where('keyword', $keyword)
            ->orderBy('position')
            ->limit(10)
            ->get(['position', 'rank', 'domain', 'url', 'title', 'snippet', 'checked_at']);

        if ($items->isEmpty()) {
            return [
                'success' => false,
                'tracked' => false,
                'message' => "No cached SERP data for '{$keyword}' in this workspace. Call seo.add_keyword to track it, then seo.serp_analysis to populate the SERP.",
            ];
        }
        $asOf = $items->first()->checked_at ?? null;
        return [
            'success' => true,
            'keyword' => $keyword,
            'top_competitors' => $items->toArray(),
            'as_of' => $asOf,
            'result' => $items->count() . " competitor positions cached for '{$keyword}'.",
        ];
    }

    /**
     * v1.4.4 (2026-05-30) — Competitor gap analysis.
     * Returns keywords where competitors rank in top 10 but the workspace's
     * own domain doesn't appear. Workspace domain detected from any published
     * website row or workspace_memory.domain.
     */
    public function competitorGaps(int $wsId, array $params): array
    {
        $limit = max(1, min((int) ($params['limit'] ?? 25), 100));
        $filterDomain = trim((string) ($params['competitor_domain'] ?? ''));

        $own = '';
        try {
            // INC-0006: "our own domain" is only unambiguous for a one-site business; otherwise fall
            // through to the recorded workspace domain rather than adopting a sibling site's.
            $__own = DB::table('websites')->where('workspace_id', $wsId)->where('status', 'published')
                ->whereNull('deleted_at')->whereNotNull('custom_domain')->limit(2)->pluck('custom_domain');
            $w = $__own->count() === 1 ? (string) $__own->first() : '';
            if ($w) $own = strtolower(parse_url('https://' . $w, PHP_URL_HOST) ?? $w);
            if ($own === '') {
                $mem = DB::table('workspace_memory')->where('workspace_id', $wsId)->where('key', 'domain')->value('value_json');
                if ($mem) $own = strtolower(trim($mem, '" '));
            }
        } catch (\Throwable $e) {}

        // Pull every SERP result, grouped by keyword in PHP.
        $rows = DB::table('seo_serp_results')
            ->where('workspace_id', $wsId)
            ->select(['keyword', 'position', 'domain'])
            ->orderBy('keyword')->orderBy('position')
            ->limit(5000)
            ->get();

        $byKw = [];
        foreach ($rows as $r) {
            $byKw[$r->keyword][] = $r;
        }

        $gaps = [];
        foreach ($byKw as $kw => $entries) {
            $domains = [];
            $ownRanked = false;
            foreach ($entries as $e) {
                $pos = (int) $e->position;
                if ($pos < 1 || $pos > 10) continue;
                $d = strtolower((string) ($e->domain ?? ''));
                if ($d === '') continue;
                if ($own !== '' && (str_contains($d, $own) || str_contains($own, $d))) $ownRanked = true;
                if ($filterDomain !== '' && stripos($d, $filterDomain) === false) continue;
                $domains[] = $d;
            }
            if ($ownRanked) continue;
            if (empty($domains)) continue;
            $gaps[] = [
                'keyword'                => $kw,
                'top_competitor_domains' => array_values(array_unique($domains)),
            ];
            if (count($gaps) >= $limit) break;
        }
        return [
            'success'    => true,
            'own_domain' => $own ?: '(unknown — set workspace_memory.domain to enable filtering)',
            'gaps'       => $gaps,
            'result'     => count($gaps) . ' keyword gaps found' . ($own ? " for '{$own}'" : '') . '.',
        ];
    }

}
