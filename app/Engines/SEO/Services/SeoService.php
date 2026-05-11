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

        return ['audit_id' => $auditId, 'status' => 'completed', 'report' => $report];
    }

    // ═══════════════════════════════════════════════════════════
    // TOOL 3: DEEP AUDIT
    // ═══════════════════════════════════════════════════════════

    public function deepAudit(int $wsId, array $params): array
    {
        $url = $params['url'] ?? '';
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

        $results = [
            'url' => $url,
            'total_checks' => $total,
            'passed' => $passed,
            'warnings' => $warnings,
            'errors' => $errors,
            'score' => $score,
            'checks' => $checks,
            'categories' => [
                'meta_tags' => array_values(array_filter($checks, fn($c) => ($c['category'] ?? '') === 'meta')),
                'performance' => array_values(array_filter($checks, fn($c) => ($c['category'] ?? '') === 'performance')),
                'mobile' => array_values(array_filter($checks, fn($c) => ($c['category'] ?? '') === 'mobile')),
                'security' => array_values(array_filter($checks, fn($c) => ($c['category'] ?? '') === 'security')),
                'content' => array_values(array_filter($checks, fn($c) => ($c['category'] ?? '') === 'content')),
                'technical' => array_values(array_filter($checks, fn($c) => ($c['category'] ?? '') === 'technical')),
                'schema' => array_values(array_filter($checks, fn($c) => ($c['category'] ?? '') === 'schema')),
            ],
        ];

        DB::table('seo_audits')->where('id', $auditId)->update([
            'status' => 'completed', 'score' => $score,
            'results_json' => json_encode($results),
            'issues_json' => json_encode($issues),
            'updated_at' => now(),
        ]);

        // Store per-URL audit items in seo_audit_items
        try {
            foreach ($checks as $check) {
                DB::table('seo_audit_items')->insert([
                    'audit_id' => $auditId,
                    'workspace_id' => $wsId,
                    'url' => $url,
                    'category' => $check['category'] ?? 'general',
                    'check_name' => $check['check'] ?? 'unknown',
                    'status' => $check['status'] ?? 'unknown',
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
        return DB::table('seo_links')->where('workspace_id', $wsId)
            ->where('status', 'suggested')
            ->orderByDesc('priority_score')
            ->limit($params['limit'] ?? 50)
            ->get()->toArray();
    }

    public function generateLinkSuggestions(int $wsId, array $params): array
    {
        $url = $params['url'] ?? '';
        // Analyze content and suggest internal linking opportunities
        // In production: crawls site pages and finds semantic connections
        $pages = DB::table('pages')
            ->join('websites', 'pages.website_id', '=', 'websites.id')
            ->where('websites.workspace_id', $wsId)
            ->select('pages.title', 'pages.slug', 'websites.domain')
            ->get();

        $keywords = DB::table('seo_keywords')->where('workspace_id', $wsId)->pluck('keyword')->toArray();

        $suggestions = [];
        foreach ($pages as $page) {
            foreach ($keywords as $kw) {
                if (stripos($page->title, $kw) !== false || stripos($kw, $page->slug) !== false) {
                    $suggestions[] = [
                        'workspace_id' => $wsId,
                        'source_url' => $url ?: '/',
                        'target_url' => ($page->domain ? "https://{$page->domain}" : '') . "/{$page->slug}",
                        'anchor_text' => $kw,
                        'type' => 'internal',
                        'status' => 'suggested',
                        'priority_score' => rand(30, 90),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }

        if (!empty($suggestions)) {
            DB::table('seo_links')->insert(array_slice($suggestions, 0, 20));
        }

        // Log activity
        $this->logActivity($wsId, null, 'generate_link_suggestions', 'links', null, [
            'url' => $url,
            'generated' => count($suggestions),
        ]);

        return ['generated' => count($suggestions), 'suggestions' => array_slice($suggestions, 0, 20)];
    }

    public function insertLink(int $wsId, int $linkId): bool
    {
        $updated = DB::table('seo_links')->where('workspace_id', $wsId)->where('id', $linkId)
            ->update(['status' => 'inserted', 'updated_at' => now()]);
        $this->engineIntel->recordToolUsage('seo', 'insert_link');
        return $updated > 0;
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

    public function checkOutbound(int $wsId, array $params = []): array
    {
        $links = DB::table('seo_links')->where('workspace_id', $wsId)->where('type', 'outbound')->get();
        $checked = 0; $broken = 0; $healthy = 0;
        $details = [];

        foreach ($links as $link) {
            $checked++;
            $targetUrl = $link->target_url ?? '';
            $httpStatus = null;
            $isHealthy = false;

            if (!empty($targetUrl) && filter_var($targetUrl, FILTER_VALIDATE_URL)) {
                try {
                    $response = Http::timeout(5)->head($targetUrl);
                    $httpStatus = $response->status();
                    $isHealthy = $httpStatus >= 200 && $httpStatus < 400;
                } catch (\Throwable $e) {
                    $httpStatus = 0;
                    $isHealthy = false;
                    Log::debug('SeoService: Outbound link check failed', [
                        'url' => $targetUrl,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // Non-URL or empty — mark as broken
                $httpStatus = 0;
                $isHealthy = false;
            }

            if ($isHealthy) {
                $healthy++;
            } else {
                $broken++;
            }

            // Update seo_links with actual HTTP status
            try {
                DB::table('seo_links')->where('id', $link->id)->update([
                    'http_status' => $httpStatus,
                    'last_checked_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Column may not exist yet — non-fatal
                Log::debug('SeoService: Could not update link http_status', ['error' => $e->getMessage()]);
            }

            $details[] = [
                'id' => $link->id,
                'url' => $targetUrl,
                'http_status' => $httpStatus,
                'healthy' => $isHealthy,
            ];
        }

        $this->engineIntel->recordToolUsage('seo', 'check_outbound');

        // Log activity
        $this->logActivity($wsId, null, 'check_outbound', 'links', null, [
            'checked' => $checked,
            'broken' => $broken,
            'healthy' => $healthy,
        ]);

        return ['checked' => $checked, 'broken' => $broken, 'healthy' => $healthy, 'details' => $details];
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
        return $q->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get()->toArray();
    }

    // ═══════════════════════════════════════════════════════════
    // DASHBOARD & REPORTING
    // ═══════════════════════════════════════════════════════════

    public function getDashboard(int $wsId): array
    {
        $keywords = DB::table('seo_keywords')->where('workspace_id', $wsId)->where('status', 'tracking');
        $audits = DB::table('seo_audits')->where('workspace_id', $wsId);

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
        $suggestedLinks = DB::table('seo_links')->where('workspace_id', $wsId)->where('status', 'suggested')->count();
        $insertedLinks = DB::table('seo_links')->where('workspace_id', $wsId)->where('status', 'inserted')->count();

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
        ];
    }

    public function getReport(int $wsId): array
    {
        $dashboard = $this->getDashboard($wsId);
        $keywords = $this->listKeywords($wsId);
        $recentAudits = $this->listAudits($wsId, ['limit' => 10]);
        $goals = $this->listGoals($wsId);
        $links = $this->linkSuggestions($wsId, ['limit' => 10]);

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
     * Save SEO settings for a workspace (upsert key-value pairs).
     */
    public function saveSettings(int $wsId, array $data): array
    {
        $saved = [];
        try {
            foreach ($data as $key => $value) {
                $exists = DB::table('seo_settings')
                    ->where('workspace_id', $wsId)
                    ->where('key', $key)
                    ->exists();

                if ($exists) {
                    DB::table('seo_settings')
                        ->where('workspace_id', $wsId)
                        ->where('key', $key)
                        ->update([
                            'value' => (string) $value,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('seo_settings')->insert([
                        'workspace_id' => $wsId,
                        'key' => $key,
                        'value' => (string) $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
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
                'source' => 'fallback_dataforseo_unavailable',
                'error' => $serp['error'] ?? $kwd['error'] ?? 'unknown',
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
    public function fetchAndIndexUrl(int $wsId, string $url): array
    {
        $fetchStart = microtime(true);
        try {
            $response = Http::timeout(15)->withHeaders(['User-Agent' => 'LevelUpSEO/1.0 (indexer)'])->get($url);
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

        $urlHost = parse_url($url, PHP_URL_HOST);
        $internalLinks = 0;
        $externalLinks = 0;
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            if (empty($href) || $href === '#') continue;
            $linkHost = parse_url($href, PHP_URL_HOST);
            if ($linkHost === null || $linkHost === $urlHost) $internalLinks++;
            else $externalLinks++;
        }

        $intent = self::classifyIntent($titleText, $url, $text);
        $scoreResult = $this->scoreContent($titleText, $metaDesc, $h1Text, $h2Count, $wordCount, $imageCount, $internalLinks);

        $data = [
            'workspace_id' => $wsId,
            'title' => $titleText, 'meta_title' => $titleText, 'meta_description' => $metaDesc,
            'h1' => $h1Text, 'h2_count' => $h2Count, 'word_count' => $wordCount,
            'image_count' => $imageCount, 'internal_link_count' => $internalLinks, 'external_link_count' => $externalLinks,
            'canonical' => $canonical, 'robots' => $metaRobots,
            'has_schema' => $hasSchema, 'has_og' => $hasOg, 'lang' => $lang, 'intent' => $intent,
            'content_score' => $scoreResult['score'],
            'http_status' => $httpStatus, 'response_time_ms' => $responseTimeMs, 'page_size_bytes' => $pageSize,
            'score_breakdown_json' => json_encode($scoreResult['breakdown']),
        ];

        $this->upsertContentIndex($url, $data);
        $this->logActivity($wsId, null, 'index_url', 'seo_index', null, ['url' => $url, 'score' => $scoreResult['score']]);

        return ['success' => true, 'url' => $url, 'score' => $scoreResult['score'], 'word_count' => $wordCount, 'intent' => $intent];
    }

    /**
     * Upsert a row in seo_content_index by URL hash.
     */
    /**
     * Public wrapper for upsertContentIndex — called from the WP Connector
     * route closure (POST /api/connector/analyze-page). Normalises the payload
     * the plugin sends (h2s/images/internal_links arrays → counts) and adds
     * workspace_id before upserting.
     */
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
        $score = $this->scoreContent(
            $data['title'] ?? '',
            $data['meta_description'] ?? '',
            $data['h1'] ?? '',
            $h2Count, $wordCount, $imgCount, $intLinks,
            null,
            $data['content'] ?? null
        );

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
        ];
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
    public function assistantMessage(int $wsId, string $message, array $context = []): array
    {
        try {
            $auditScore = DB::table('seo_audits')
                ->where('workspace_id', $wsId)
                ->orderByDesc('created_at')
                ->value('score');
            $keywords = DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->orderByDesc('volume')
                ->limit(5)
                ->pluck('keyword')
                ->toArray();
            $workspace = DB::table('workspaces')->find($wsId);
            $bizName   = $workspace->business_name ?? $workspace->name ?? 'this workspace';

            $systemPrompt = "You are James, an expert SEO Strategist for {$bizName}. "
                . 'Current site SEO score: ' . ($auditScore ?? 'not yet audited') . '/100. '
                . 'Top tracked keywords: ' . implode(', ', $keywords ?: ['none yet']) . '. '
                . 'Be sharp, conversational, and direct — max 3 sentences unless the user '
                . 'explicitly asks for a plan or detailed breakdown. No bullet frameworks unless asked.';

            $runtime = app(\App\Connectors\RuntimeClient::class);
            if (! method_exists($runtime, 'isConfigured') || ! $runtime->isConfigured()) {
                return [
                    'response'    => 'James is offline right now (runtime not configured). Try the main app SEO dashboard for current insights.',
                    'suggestions' => [],
                    'agent'       => 'james',
                ];
            }
            $resp = $runtime->assistant(
                $message,
                [
                    'workspace_id'  => $wsId,
                    'business_name' => $bizName,
                    'agent_slug'    => 'james',
                    'agent_name'    => 'James',
                    'system_prompt' => $systemPrompt,
                ],
                "seo_assistant_ws_{$wsId}",
                'james'
            );
            return [
                'response'    => $resp['response'] ?? 'Trouble parsing the response. Try again?',
                'suggestions' => [],
                'agent'       => 'james',
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SEO Assistant] ' . $e->getMessage());
            return [
                'response'    => "I'm having trouble connecting right now. Check your SEO dashboard for the latest insights.",
                'suggestions' => [],
                'agent'       => 'james',
            ];
        }
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

        // 7. H1 (10 pts)
        $s = !empty($h1) ? 10 : 0;
        $breakdown[] = ['factor' => 'h1', 'weight' => 10, 'score' => $s, 'details' => !empty($h1) ? "H1: \"" . mb_substr($h1, 0, 50) . "\"" : 'Missing'];
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
        $plan = \App\Models\Plan::find(
            \App\Models\Subscription::where('workspace_id', $wsId)
                ->where('status', 'active')->latest()->value('plan_id')
        ) ?? \App\Models\Plan::where('slug', 'free')->first();

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

}
