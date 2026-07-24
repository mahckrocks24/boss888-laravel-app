<?php

namespace App\Core\Strategy;

use App\Connectors\RuntimeClient;
use App\Core\Agents\AgentMessageService;
use App\Engines\SEO\Services\SeoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 2026-05-24 FIX 51 — Discovery Run (masterplan Phase 1).
 *
 * The agency-from-day-1 experience. When a workspace becomes published
 * (website live, first article ready, or first time Sarah's daily cron
 * touches it), Sarah AUTOMATICALLY and FREELY:
 *
 *   1. Posts a welcome message in chat: "Hi, I'm Sarah, your DMM.
 *      I'm spending today auditing your business. You'll have a 30-day
 *      plan by tomorrow morning."
 *
 *   2. Kicks off the discovery audit chain (all free except the meeting):
 *      - Site-wide deep audit (free for first audit)
 *      - Page indexing crawl (free)
 *      - AEO snapshot (free)
 *      - Brand extraction from About/Home/Services pages (free via
 *        existing workspace_memory infrastructure)
 *      - Competitor identification from SERP cache (free)
 *
 *   3. Triggers a multi-agent strategy meeting (8cr — auto-approved
 *      as part of onboarding budget; canonical strategy meeting price).
 *
 *   4. Marks workspace_memory.discovery_run_completed_at so the run
 *      never fires twice per workspace.
 *
 * Idempotent: safe to call repeatedly; only runs once per workspace.
 * Failure-tolerant: any step can fail without blocking others.
 *
 * Architecture rule: this class does NO synthesis. The audits read DB
 * and call existing services. The meeting + brand extraction are
 * delegated to runtime (per hands-vs-brain). Laravel just orchestrates.
 */
class DiscoveryRunOrchestrator
{
    public function __construct(
        private WorkspaceStateGatherer $gatherer,
        private RuntimeClient $runtime,
    ) {}

    /**
     * Run discovery for a workspace if it hasn't run yet.
     * Returns a status array detailing each step's outcome.
     */
    public function runIfNew(int $wsId): array
    {
        $memKey = "seo_ws_memory_{$wsId}";
        try {
            $raw = Redis::get($memKey);
            $mem = $raw ? (json_decode($raw, true) ?: []) : [];
            if (!empty($mem['discovery_run_completed_at'])) {
                return [
                    'skipped'    => true,
                    'reason'     => 'already_completed',
                    'completed_at' => $mem['discovery_run_completed_at'],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[Discovery] memory read failed, proceeding: ' . $e->getMessage());
        }

        return $this->run($wsId);
    }

    /**
     * Force-run discovery regardless of prior completion.
     * Useful for testing + admin-triggered refresh.
     */
    public function run(int $wsId): array
    {
        Log::info('[Discovery] starting discovery run', ['workspace_id' => $wsId]);

        $started = now();
        $report = [
            'workspace_id' => $wsId,
            'started_at'   => $started->toIso8601String(),
            'steps'        => [],
        ];

        // STEP 1 — Welcome message into chat
        $report['steps']['welcome'] = $this->postWelcome($wsId);

        // STEP 2 — Site audit (deep_audit; free for first audit of the
        // workspace per CapabilityMap pricing of "first audit is free")
        $report['steps']['deep_audit'] = $this->runDeepAudit($wsId);

        // STEP 3 — Page indexing crawl — populates seo_content_index
        $report['steps']['index_pages'] = $this->runIndexCrawl($wsId);

        // STEP 4 — Brand extraction from About/Home/Services pages
        $report['steps']['brand_extraction'] = $this->runBrandExtraction($wsId);

        // STEP 5 — Competitor identification from existing SERP data
        $report['steps']['competitor_id'] = $this->identifyCompetitors($wsId);

        // STEP 6 — Schedule first strategy meeting (8cr)
        $report['steps']['strategy_meeting'] = $this->scheduleStrategyMeeting($wsId);

        // STEP 7 — Persist completion timestamp + summary report
        $this->markCompleted($wsId, $report);

        Log::info('[Discovery] completed', [
            'workspace_id' => $wsId,
            'duration_s'   => now()->diffInSeconds($started),
            'steps_ok'     => count(array_filter($report['steps'], fn ($s) => ($s['ok'] ?? false))),
        ]);

        return $report;
    }

    private function postWelcome(int $wsId): array
    {
        $msg = "👋 **Welcome aboard. I'm Sarah, your Digital Marketing Manager.**\n\n"
             . "Today is day 1. I'm running a full audit of your business — "
             . "site health, indexed pages, brand context, SEO foundation, and competitor landscape.\n\n"
             . "Tomorrow morning at 8:00 UTC I'll share a recommended 30-day game plan as a set of "
             . "proposed actions, each with its credit cost. You approve the ones you want, and I'll run those.\n\n"
             . "Nothing needed from you right now — I'll ping you when the plan is ready for your review.";
        try {
            app(AgentMessageService::class)->postAsAgent($wsId, 'sarah', $msg, [
                'notification_type' => 'discovery_welcome',
            ]);
            return ['ok' => true, 'message_posted' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function runDeepAudit(int $wsId): array
    {
        try {
            $seo = app(SeoService::class);
            // Use existing deep audit — runs free for first audit per Wave 6 pricing
            $audit = $seo->deepAudit($wsId, ['url' => $this->siteUrlFor($wsId), '_user_id' => null]);
            return [
                'ok'       => true,
                'audit_id' => $audit['audit_id'] ?? null,
                'score'    => $audit['score'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[Discovery] deep_audit failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function runIndexCrawl(int $wsId): array
    {
        try {
            // Use existing page discovery — pulls pages, populates seo_content_index
            $svc = app(\App\Engines\SEO\Services\PageDiscoveryService::class);
            $result = $svc->discoverWithStats($wsId);
            return [
                'ok' => true,
                'pages_indexed' => $result['indexed'] ?? $result['discovered'] ?? 0,
                'stats' => $result,
            ];
        } catch (\Throwable $e) {
            Log::debug('[Discovery] index crawl skipped: ' . $e->getMessage());
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    private function runBrandExtraction(int $wsId): array
    {
        try {
            // Read About/Home/Services page content from seo_content_index
            $pages = DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where(function ($q) {
                    $q->where('url', 'like', '%/about%')
                      ->orWhere('url', 'like', '%/home%')
                      ->orWhere('url', 'like', '%/services%')
                      ->orWhere('url', 'like', '%/about-us%')
                      ->orWhere('url', 'like', '%/contact%');
                })
                ->limit(5)
                ->get(['url', 'title', 'meta_description', 'h1']);

            if ($pages->isEmpty()) {
                return ['ok' => false, 'reason' => 'no_branded_pages_indexed'];
            }

            $context = $pages->map(fn ($p) => [
                'url'   => $p->url,
                'title' => $p->title,
                'h1'    => $p->h1,
                'meta'  => $p->meta_description,
            ])->toArray();

            // Brand extraction is "intelligence" — runtime LLM call
            $prompt = "Extract brand facts from these workspace pages. Return JSON: "
                    . "{\"business_name\":\"...\",\"industry\":\"...\",\"location\":\"...\","
                    . "\"target_audience\":\"...\",\"key_services\":[...],\"tone\":\"...\","
                    . "\"differentiators\":[\"...\"]}\n\n"
                    . "Pages:\n" . json_encode($context, JSON_UNESCAPED_SLASHES);

            $r = $this->runtime->aiRun('seo_content_generation', $prompt, [
                'workspace_id' => $wsId,
                'task'         => 'discovery_brand_extraction',
            ], 1500);
            if (empty($r['success']) || empty($r['text'])) {
                return ['ok' => false, 'reason' => 'runtime_empty'];
            }
            $text = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim((string) $r['text']));
            $brand = json_decode($text, true);
            if (!is_array($brand)) {
                if (preg_match('/\{.*\}/s', $text, $m)) $brand = json_decode($m[0], true);
            }
            if (!is_array($brand) || empty($brand['business_name'])) {
                return ['ok' => false, 'reason' => 'parse_failed'];
            }

            // Persist into workspace_memory for Sarah + Assistant to use
            $memKey = "seo_ws_memory_{$wsId}";
            $raw = Redis::get($memKey);
            $mem = $raw ? (json_decode($raw, true) ?: []) : [];
            foreach (['business_name', 'industry', 'location', 'target_audience', 'tone'] as $f) {
                if (!empty($brand[$f])) $mem[$f] = $brand[$f];
            }
            if (!empty($brand['key_services']) && is_array($brand['key_services'])) {
                $mem['services'] = $brand['key_services'];
            }
            if (!empty($brand['differentiators']) && is_array($brand['differentiators'])) {
                $mem['differentiators'] = $brand['differentiators'];
            }
            Redis::setex($memKey, 90 * 86400, json_encode($mem));

            return [
                'ok' => true,
                'extracted_fields' => array_keys($brand),
                'business_name' => $brand['business_name'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[Discovery] brand extraction failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function identifyCompetitors(int $wsId): array
    {
        try {
            // Read SERP results from existing seo_audits (audit results
            // include competitor URLs from SERP analysis). seo_keywords
            // doesn't persist SERP cache; the audit table does.
            if (!\Illuminate\Support\Facades\Schema::hasTable('seo_audits')) {
                return ['ok' => false, 'reason' => 'no_audit_table'];
            }
            $auditCol = \Illuminate\Support\Facades\Schema::hasColumn('seo_audits', 'competitor_urls_json')
                ? 'competitor_urls_json'
                : (\Illuminate\Support\Facades\Schema::hasColumn('seo_audits', 'results_json') ? 'results_json' : null);
            if (!$auditCol) {
                return ['ok' => false, 'reason' => 'no_competitor_data_column'];
            }
            $audits = DB::table('seo_audits')
                ->where('workspace_id', $wsId)
                ->whereNotNull($auditCol)
                ->orderByDesc('id')->limit(5)
                ->pluck($auditCol);

            $domainCounts = [];
            $ownHost = parse_url($this->siteUrlFor($wsId), PHP_URL_HOST);
            foreach ($audits as $json) {
                $data = json_decode((string) $json, true) ?: [];
                // Various possible shapes — be defensive
                $urls = $data['competitor_urls']
                    ?? $data['competitors']
                    ?? $data['serp_competitors']
                    ?? [];
                if (!is_array($urls)) continue;
                foreach ($urls as $entry) {
                    $u = is_array($entry) ? ($entry['url'] ?? $entry['domain'] ?? '') : (string) $entry;
                    $host = parse_url((string) $u, PHP_URL_HOST) ?: (string) $u;
                    $host = strtolower(trim($host, ' /'));
                    if ($host === '' || $host === strtolower((string) $ownHost)) continue;
                    $domainCounts[$host] = ($domainCounts[$host] ?? 0) + 1;
                }
            }
            arsort($domainCounts);
            $topCompetitors = array_slice(array_keys($domainCounts), 0, 10);

            if (empty($topCompetitors)) {
                return ['ok' => false, 'reason' => 'no_competitor_data_yet — need fresh serp_analysis runs first'];
            }

            // Persist to workspace_memory.identified_competitors so Sarah
            // can reference them in future state-driven proposals.
            $memKey = "seo_ws_memory_{$wsId}";
            $raw = Redis::get($memKey);
            $mem = $raw ? (json_decode($raw, true) ?: []) : [];
            $mem['identified_competitors'] = $topCompetitors;
            $mem['competitor_frequencies'] = $domainCounts;
            Redis::setex($memKey, 90 * 86400, json_encode($mem));

            return ['ok' => true, 'competitor_count' => count($topCompetitors), 'top_3' => array_slice($topCompetitors, 0, 3)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function scheduleStrategyMeeting(int $wsId): array
    {
        try {
            // Use SarahMonthlyOrchestrator's pattern but mark it as
            // the FIRST meeting (discovery meeting). Creates a
            // strategy_proposal of type='discovery_meeting' that the
            // orchestrator can run async via the existing infra.
            $proposalId = DB::table('strategy_proposals')->insertGetId([
                'workspace_id'        => $wsId,
                'type'                => 'discovery_strategy_meeting',
                'title'               => 'First strategy meeting — 30-day plan kick-off',
                'description'         => 'Multi-agent meeting (Sarah + James + Alex + Priya + Nora + Elena) to produce your first 30-day digital marketing plan.',
                'status'              => 'pending_approval',
                'cost_breakdown_json' => json_encode([
                    ['agent' => 'sarah', 'action' => 'strategy_meeting', 'credits' => 8, 'description' => 'Discovery meeting'],
                ]),
                'total_credits'       => 8,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
            return ['ok' => true, 'proposal_id' => $proposalId];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function markCompleted(int $wsId, array $report): void
    {
        try {
            $memKey = "seo_ws_memory_{$wsId}";
            $raw = Redis::get($memKey);
            $mem = $raw ? (json_decode($raw, true) ?: []) : [];
            $mem['discovery_run_completed_at'] = now()->toIso8601String();
            $mem['discovery_run_report'] = $report;
            Redis::setex($memKey, 90 * 86400, json_encode($mem));
        } catch (\Throwable $e) {
            Log::warning('[Discovery] markCompleted failed: ' . $e->getMessage());
        }
    }

    private function siteUrlFor(int $wsId): string
    {
        try {
            $w = DB::table('websites')
                ->where('workspace_id', $wsId)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->first(['subdomain', 'domain', 'custom_domain']);
            if ($w) {
                $host = $w->custom_domain ?: $w->domain ?: $w->subdomain ?: '';
                if ($host) return 'https://' . trim((string) $host, ' /');
            }
            // Fallback: read seo_settings.site_url (WP sites)
            $url = DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'site_url')
                ->value('value');
            return (string) ($url ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
