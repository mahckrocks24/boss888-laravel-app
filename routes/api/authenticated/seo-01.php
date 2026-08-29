<?php

/**
 * CR-22B — extracted route module: seo-01
 *
 * Source: routes/api.php lines 4347-9612 of the authoritative pre-extraction
 * file (sha256 9aa519a1445f8e26…), copied VERBATIM — not reformatted, reordered
 * or edited in any way.
 *
 * Included from INSIDE the authenticated group closure
 *   Route::middleware(['auth.jwt','traffic.defense','connector.brand'])->group(...)
 * at the exact position the code previously occupied, so middleware stack,
 * prefix nesting and registration order are unchanged. PHP `require` executes in
 * the including scope, so parent-closure variables remain visible.
 *
 * `use` aliases, however, do NOT cross a require boundary — they are resolved
 * per file at compile time. A missing import does not fatal: `TaskController::class`
 * silently becomes the string "TaskController" and the route registers against a
 * wrong action. The FULL parent import set is therefore re-declared below,
 * unconditionally, in every module. Unused imports trigger no autoload and cost
 * nothing; a missing one is a silent production defect.
 *
 * Owner: SEO engine   ·   Routes: 149   ·   Statements: 1
 *
 * CR-22 scope forbids improving anything in this file. Move it, do not edit it.
 */

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DesignTokenController;
use App\Http\Controllers\Api\EngineController;
use App\Http\Controllers\Api\ManualExecutionController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====
    // ── SEO Engine (100% complete — 25 routes) ────────────────
    Route::prefix('seo')->group(function () {
        $c = \App\Engines\SEO\Http\Controllers\SeoController::class;

        // Analysis tools (3)
        Route::post('/serp-analysis', [$c, 'serpAnalysis']);
        Route::post('/ai-report', [$c, 'aiReport']);
        Route::post('/deep-audit', [$c, 'deepAudit']);

        // Content delegation (2)
        Route::post('/improve-draft', [$c, 'improveDraft']);
        Route::post('/write-article', [$c, 'writeArticle']);

        // AI status (1)
        Route::get('/ai-status', [$c, 'aiStatus']);

        // Link management (6)
        Route::get('/links', [$c, 'linkSuggestions']);
        Route::post('/links/generate', [$c, 'generateLinks']);
        Route::post('/links/{id}/insert', [$c, 'insertLink']);
        // Wave 3 — R7 (2026-05-17). Preview a link insertion without
        // mutating the article body. Returns the before/after snippet
        // + paragraph index, or a structured 'reason' explaining why
        // insertion isn't safely possible right now.
        Route::get('/links/{id}/preview-insertion', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\SEO\Services\SeoService::class)
                    ->aiPreviewLinkInsertion($wsId, (int) $id)
            );
        });
        Route::post('/links/{id}/apply-insertion', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\SEO\Services\SeoService::class)
                    ->aiApplyLinkInsertion($wsId, (int) $id)
            );
        });
        Route::post('/links/{id}/dismiss', [$c, 'dismissLink']);
        // REMOVED 2026-08-24 (MISSION-018 WS-1, RISK-0005 census):
        // GET /outbound here was shadowed by the 2026-05-12 FIX 6 closure at
        // the bottom of this file (reads seo_outbound_links), which is the
        // one that serves and the one intended. One registration remains.
        Route::post('/outbound/check', [$c, 'checkOutbound']);

        // Goals (5)
        Route::get('/goals', [$c, 'listGoals']);
        Route::post('/goals', [$c, 'createGoal']);
        Route::get('/goals/{id}', [$c, 'getGoal']);
        Route::post('/goals/{id}/pause', [$c, 'pauseGoal']);
        Route::post('/goals/{id}/resume', [$c, 'resumeGoal']);
        Route::get('/agent-status', [$c, 'agentStatus']);

        // Keywords (3)
        Route::get('/keywords', [$c, 'listKeywords']);
        Route::post('/keywords', [$c, 'addKeyword']);

        // 2026-05-19 (Wave 20a) — Keyword research: seed keyword in, related keywords + volume/CPC/difficulty out.
        Route::post('/keywords/research', function (\Illuminate\Http\Request $r) {
            $kw       = trim((string) $r->input('keyword', ''));
            $locCode  = (int) $r->input('location_code', 0);
            $locLabel = (string) $r->input('location', '');
            if ($kw === '') {
                return response()->json(['success' => false, 'error' => 'Keyword is required', 'ideas' => []], 422);
            }
            // Backwards-compat: accept either location_code (int) or location (name).
            if (!$locCode) {
                $map = ['USA' => 2840, 'UK' => 2826, 'UAE' => 2784, 'United States' => 2840, 'United Kingdom' => 2826, 'United Arab Emirates' => 2784];
                $locCode = $map[$locLabel] ?? 2840;
            }
            $wsId = (int) $r->attributes->get('workspace_id');
            $credits = app(\App\Core\Billing\CreditService::class);
            $cost = 1;
            if (!$credits->hasBalance($wsId, $cost)) {
                return response()->json(['success' => false, 'error' => "Not enough credits — keyword research costs {$cost} credit.", 'required_credits' => $cost, 'ideas' => []], 402);
            }
            $ref = $credits->reserve($wsId, $cost, 'seo/keyword_research');
            try {
                $conn = new \App\Connectors\DataForSeoConnector();
                $res  = $conn->relatedKeywords($kw, $locCode, 'en', 30);
                if (empty($res['success'])) {
                    $credits->release($wsId, $ref);
                    return response()->json([
                        'success' => false,
                        'error'   => 'Keyword research is temporarily unavailable. Please try again in a moment.',
                        'ideas'   => [],
                    ], 200);
                }
                $credits->commit($wsId, $ref, $cost);
                return response()->json([
                    'success'       => true,
                    'keyword'       => $res['keyword'] ?? $kw,
                    'ideas'         => $res['items'] ?? [],
                    'data'          => $res['items'] ?? [],
                    'credits_spent' => $cost,
                ]);
            } catch (\Throwable $e) {
                $credits->release($wsId, $ref);
                \Illuminate\Support\Facades\Log::warning('keywords/research failed', ['error' => $e->getMessage()]);
                return response()->json(['success' => false, 'error' => 'Keyword research is temporarily unavailable. Please try again in a moment.', 'ideas' => []], 200);
            }
        });


        // 2026-05-19 (Wave 32) — XML Sitemap status + ping. Free utility.
        Route::get('/sitemap', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $siteUrl = (string) ($r->query('site_url') ?? '');
            // Wave 32f — Removed silent fallback to first published tenant.
            // All subdomains are individual websites; if no site_url is
            // provided, return unknown rather than picking one arbitrarily.
            $host = $siteUrl ? (parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl) : '';
            $host = strtolower(preg_replace('#^www\.#', '', (string) $host));

            // Wave 32b — Platform-self detection. The Laravel app host itself
            // is not a tenant site — surface a friendly explanation rather than
            // a misleading not-found.
            $platformHosts = ['staging.levelupgrowth.io', 'levelupgrowth.io', 'www.levelupgrowth.io', 'app.levelupgrowth.io'];
            if (in_array($host, $platformHosts, true)) {
                return response()->json([
                    'success'     => true,
                    'mode'        => 'platform_self',
                    'host'        => $host,
                    'sitemap_url' => 'https://' . $host . '/sitemap.xml',
                    'message'     => 'This is the LevelUp Growth platform admin URL, not a content site. Sitemaps are auto-generated for your tenant sites — switch to one in the site dropdown above.',
                ]);
            }

            // Mode A — match any Laravel-hosted published site by host.
            // Wave 32b — dropped workspace_id filter; sitemap is public info.
            // 2026-05-23 FIX 26 — also match on custom_domain. Without this
            // a tenant connected via custom domain (e.g. chefredraymundo.com
            // → chef-red.levelupgrowth.io) falls through to external Mode B
            // and the indexed/unindexed counts compare URLs with mismatched
            // hosts (sitemap uses subdomain, content_index uses custom_domain).
            $site = null;
            if ($host) {
                $site = \Illuminate\Support\Facades\DB::table('websites')
                    ->where('status', 'published')
                    ->where(function ($q) use ($host) {
                        $q->where('subdomain', $host)
                          ->orWhere('domain', $host)
                          ->orWhere('custom_domain', $host);
                    })
                    ->whereNull('deleted_at')
                    ->first();
            }

            if ($site) {
                $sub = $site->subdomain ?: $host;
                $canonHost = !empty($site->custom_domain) ? strtolower(trim($site->custom_domain, ' /')) : $sub;
                $wsForSite = (int) ($site->workspace_id ?? $wsId);

                $pages = \Illuminate\Support\Facades\DB::table('pages')
                    ->where('website_id', $site->id)
                    ->where('status', 'published')
                    ->get(['slug', 'updated_at']);
                $articles = \Illuminate\Support\Facades\DB::table('articles')
                    ->where('workspace_id', $wsForSite)
                    ->where('status', 'published')
                    ->whereNotNull('slug')
                    ->get(['slug', 'updated_at', 'published_at']);

                // 2026-05-23 FIX 26 — build the canonical URL list the sitemap
                // would emit, then cross-reference against seo_content_index
                // to compute indexed/unindexed counts (Mode A used to skip
                // this and the UI showed "0 indexed / N unindexed" for
                // every Laravel site).
                $sitemapUrls = [];
                foreach ($pages as $p) {
                    $slugSeg = ($p->slug === 'home' || $p->slug === '') ? '' : $p->slug;
                    $sitemapUrls[] = 'https://' . $canonHost . '/' . $slugSeg;
                }
                foreach ($articles as $a) {
                    $slugSeg = trim((string) $a->slug, '/');
                    if ($slugSeg === '') continue;
                    $sitemapUrls[] = 'https://' . $canonHost . '/blog/' . $slugSeg;
                }
                $sitemapNorm = array_map(fn ($u) => rtrim(strtolower($u), '/'), $sitemapUrls);
                $indexedUrls = \Illuminate\Support\Facades\DB::table('seo_content_index')
                    ->where('workspace_id', $wsForSite)
                    ->pluck('url')
                    ->map(fn ($u) => rtrim(strtolower((string) $u), '/'))
                    ->toArray();
                $indexedCount = count(array_intersect($sitemapNorm, $indexedUrls));
                $urlCount = count($sitemapUrls);

                $latest = max((string) ($pages->max('updated_at') ?? ''), (string) ($articles->max('updated_at') ?? '')) ?: null;
                return response()->json([
                    'success'         => true,
                    'mode'            => 'laravel',
                    'sitemap_url'     => "https://{$canonHost}/sitemap.xml",
                    'robots_url'     => "https://{$canonHost}/robots.txt",
                    'url_count'       => $urlCount,
                    'page_count'      => $pages->count(),
                    'article_count'   => $articles->count(),
                    'indexed_count'   => $indexedCount,
                    'unindexed_count' => max(0, $urlCount - $indexedCount),
                    'last_updated'    => $latest,
                    'website_id'      => $site->id,
                    'sample_urls'     => array_slice($sitemapUrls, 0, 10),
                ]);
            }

            // Mode B — external site. Try to fetch the user's sitemap.xml.
            if (!$siteUrl) {
                return response()->json([
                    'success' => true,
                    'mode'    => 'unknown',
                    'message' => 'No active site selected.',
                ]);
            }

            $base = rtrim($siteUrl, '/');
            $candidates = [$base . '/sitemap.xml', $base . '/wp-sitemap.xml', $base . '/sitemap_index.xml'];
            $found = null;
            $urls  = [];
            foreach ($candidates as $u) {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(10)->get($u);
                    if ($resp->ok() && preg_match('/<urlset|<sitemapindex/i', $resp->body())) {
                        $found = $u;
                        $body = $resp->body();
                        // 2026-05-23 FIX 26 — if the sitemap is an index (WP
                        // standard wp-sitemap.xml + Yoast sitemap_index.xml),
                        // recurse one level: fetch each sub-sitemap and
                        // collect the actual page URLs from their <loc>
                        // entries. Without this we counted sub-sitemap URLs
                        // (e.g. 7 sub-sitemaps on shukran) as "URLs in
                        // sitemap" and the indexed cross-reference was
                        // guaranteed to be 0.
                        if (preg_match('/<sitemapindex/i', $body)) {
                            $subSitemaps = [];
                            if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $body, $sm)) {
                                $subSitemaps = array_slice(array_unique($sm[1]), 0, 20);
                            }
                            foreach ($subSitemaps as $subUrl) {
                                try {
                                    $sub = \Illuminate\Support\Facades\Http::timeout(10)->get($subUrl);
                                    if ($sub->ok() && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $sub->body(), $subM)) {
                                        foreach ($subM[1] as $childUrl) {
                                            $urls[] = $childUrl;
                                            if (count($urls) >= 5000) break 2;
                                        }
                                    }
                                } catch (\Throwable $eSub) {
                                    // skip this sub-sitemap, keep walking the rest
                                }
                            }
                            $urls = array_values(array_unique($urls));
                        } elseif (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $body, $m)) {
                            $urls = array_slice(array_unique($m[1]), 0, 5000);
                        }
                        break;
                    }
                } catch (\Throwable $e) {}
            }

            if (!$found) {
                return response()->json([
                    'success' => true,
                    'mode'    => 'external',
                    'found'   => false,
                    'host'    => $host,
                    'tried'   => $candidates,
                    'message' => 'No sitemap found at ' . $host . '. Tried /sitemap.xml, /wp-sitemap.xml, /sitemap_index.xml. Add one to improve search-engine discovery.',
                ]);
            }

            // Cross-reference with seo_content_index to see how many sitemap URLs we have indexed.
            $indexedUrls = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->pluck('url')
                ->map(fn ($u) => rtrim(strtolower((string) $u), '/'))
                ->toArray();
            $sitemapNorm = array_map(fn ($u) => rtrim(strtolower((string) $u), '/'), $urls);
            $indexedCount = count(array_intersect($sitemapNorm, $indexedUrls));

            return response()->json([
                'success'         => true,
                'mode'            => 'external',
                'found'           => true,
                'sitemap_url'     => $found,
                'url_count'       => count($urls),
                'indexed_count'   => $indexedCount,
                'unindexed_count' => count($urls) - $indexedCount,
                'sample_urls'     => array_slice($urls, 0, 10),
            ]);
        });

        // ── Wave 44 — AEO Audit (Answer Engine Optimization) ───────────────
        // Read-only audit of how well a workspace's published pages will be
        // cited by LLM-based search (ChatGPT, Perplexity, Claude, Google AI
        // Overviews, Bing Copilot). Available on ALL plan tiers as upsell hook
        // (the enrichment that fixes the gaps is gated to $69+ in Wave 45).
        // No credits charged — read-only HTTP fetch + HTML parse.

        // GET /api/seo/aeo/audit — list audited pages with scores.
        Route::get('/aeo/audit', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('aeo_audits')
                ->where('workspace_id', $wsId)
                ->orderByDesc('last_audited_at')
                ->limit(200)
                ->get(['id', 'url', 'score', 'checks_json', 'http_status', 'error_text', 'last_audited_at']);

            $weights = [
                'article_jsonld' => 12, 'faqpage_jsonld' => 12, 'tldr_at_top' => 12,
                'ai_crawlers_allowed' => 10, 'llms_txt_present' => 8, 'date_modified' => 8,
                'question_h2s' => 8, 'lists_tables' => 8, 'external_citation' => 6,
                'images_with_alt' => 6, 'meta_description_len' => 5, 'title_length' => 5,
            ];
            $labels = [
                'article_jsonld' => 'Article JSON-LD schema',
                'faqpage_jsonld' => 'FAQPage JSON-LD schema',
                'tldr_at_top' => 'TLDR / answer in first 300 chars',
                'ai_crawlers_allowed' => 'robots.txt allows AI crawlers',
                'llms_txt_present' => 'llms.txt available at site root',
                'date_modified' => 'dateModified in JSON-LD',
                'question_h2s' => 'At least 2 question-style H2s',
                'lists_tables' => 'At least 2 lists or tables',
                'external_citation' => 'At least 1 external citation link',
                'images_with_alt' => 'All images have alt text',
                'meta_description_len' => 'Meta description 120-160 chars',
                'title_length' => 'Title 30-60 chars',
            ];

            $audits = $rows->map(function ($row) use ($weights, $labels) {
                $checks = json_decode($row->checks_json ?? '[]', true) ?: [];
                $enriched = [];
                foreach ($weights as $key => $weight) {
                    $check = $checks[$key] ?? ['pass' => false, 'fix' => 'Not yet audited'];
                    $enriched[] = [
                        'key' => $key,
                        'label' => $labels[$key],
                        'weight' => $weight,
                        'pass' => (bool) ($check['pass'] ?? false),
                        'fix' => $check['fix'] ?? null,
                    ];
                }
                return [
                    'id' => $row->id, 'url' => $row->url, 'score' => $row->score,
                    'http_status' => $row->http_status, 'error_text' => $row->error_text,
                    'last_audited_at' => $row->last_audited_at,
                    'checks' => $enriched,
                ];
            })->values();

            $avg = $audits->count() > 0
                ? (int) round($audits->avg('score'))
                : null;

            return response()->json([
                'success' => true,
                'workspace_id' => $wsId,
                'audits' => $audits,
                'count' => $audits->count(),
                'workspace_avg_score' => $avg,
                'max_score' => 100,
            ]);
        });

        // POST /api/seo/aeo/audit/recrawl — audit every indexed URL (synchronous, up to 100).
        Route::post('/aeo/audit/recrawl', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $svc = app(\App\Engines\SEO\Services\AeoAuditService::class);
            $count = $svc->auditAllIndexed($wsId);
            return response()->json([
                'success' => true,
                'audited' => $count,
                'message' => $count > 0
                    ? "Audited {$count} pages. View results in the AEO tab."
                    : 'No indexed pages found to audit. Add pages to your SEO index first.',
            ]);
        });

        // ── Wave 52 — Re-scan builder pages into seo_content_index ────────

        // POST /api/seo/reindex-builder-pages — on-demand refresh.
        // Free for all tiers; no LLM cost.
        Route::post('/reindex-builder-pages', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $count = app(\App\Engines\SEO\Services\BuilderPageIndexer::class)
                ->indexWorkspace($wsId);
            return response()->json([
                'success' => true,
                'pages_indexed' => $count,
                'message' => $count > 0
                    ? "Indexed {$count} published pages."
                    : 'No published pages to index. Publish a website first.',
            ]);
        });

        // ── Wave 49b — AEO score evolution chart ──────────────────────────

        // GET /api/seo/aeo/score-history?days=90 — daily snapshots.
        Route::get('/aeo/score-history', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $days = max(7, min(180, (int) $r->query('days', 90)));
            $rows = \Illuminate\Support\Facades\DB::table('aeo_score_snapshots')
                ->where('workspace_id', $wsId)
                ->where('captured_at', '>=', now()->subDays($days))
                ->orderBy('captured_at')
                ->get(['avg_score', 'audits_count', 'articles_total', 'articles_enriched', 'captured_at']);
            return response()->json([
                'success' => true,
                'days' => $days,
                'series' => $rows->map(fn($s) => [
                    'date' => $s->captured_at,
                    'score' => $s->avg_score,
                    'audits' => $s->audits_count,
                    'articles_total' => $s->articles_total,
                    'articles_enriched' => $s->articles_enriched,
                ]),
                'point_count' => $rows->count(),
            ]);
        });

        // ── Wave 48 — AEO traffic measurement (Stage 1) ────────────────────

        // GET /api/seo/aeo/traffic — last-N-days crawler hits + AI referrals.
        // Free for all plan tiers — read-only summary, no LLM calls.
        Route::get('/aeo/traffic', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $days = (int) $r->query('days', 30);
            $days = max(1, min(90, $days));
            $svc = app(\App\Engines\SEO\Services\AeoTrafficLogger::class);
            return response()->json([
                'success' => true,
                'data' => $svc->summary($wsId, $days),
            ]);
        });

        // ── Wave 46 — AEO Settings + llms.txt control ─────────────────────

        // GET /api/seo/aeo/settings — fetch current settings + crawler labels.
        Route::get('/aeo/settings', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $svc = app(\App\Engines\SEO\Services\AeoSettingsService::class);
            $settings = $svc->get($wsId);
            return response()->json([
                'success' => true,
                'settings' => $settings,
                'crawlers' => \App\Engines\SEO\Services\AeoSettingsService::crawlerLabels(),
            ]);
        });

        // POST /api/seo/aeo/settings — update settings (any combo of fields).
        Route::post('/aeo/settings', function (\Illuminate\Http\Request $r) {

            // Wave 47 — AEO plan gate inline check.
            $_ws = (int) $r->attributes->get('workspace_id');
            // AEO-1 (2026-08-29): AEO is part of every AI plan (ADR-0012 — capacity, not capability, from $49 up).
            // The old "$69 WP Bundle" price gate is exactly the assumption the Owner said not to resurrect.
            $_rules = app(\App\Core\PlanGating\PlanGatingService::class)->getPlanRules($_ws);
            $_planSlug = $_rules['plan_slug'] ?? 'free';
            if (($_rules['ai_access'] ?? 'none') !== 'full') {
                return response()->json([
                    'success' => false,
                    'error' => 'aeo_plan_required',
                    'message' => 'AEO Mode is part of the AI plans — AI Lite ($49/month) and up.',
                    'current_plan_slug' => $_planSlug,
                ], 402);
            }
            $wsId = (int) $r->attributes->get('workspace_id');
            $svc = app(\App\Engines\SEO\Services\AeoSettingsService::class);
            $changes = $r->only(array_merge(
                ['aeo_mode_enabled'],
                array_keys(\App\Engines\SEO\Services\AeoSettingsService::crawlerLabels())
            ));
            // Normalize booleans coming in as strings/ints from form submits.
            foreach ($changes as $k => $v) {
                if (is_string($v)) {
                    $changes[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                }
            }
            $settings = $svc->update($wsId, $changes);
            return response()->json(['success' => true, 'settings' => $settings]);
        });

        // POST /api/seo/aeo/llms-txt/regenerate — force-regen llms.txt now.
        Route::post('/aeo/llms-txt/regenerate', function (\Illuminate\Http\Request $r) {

            // Wave 47 — AEO plan gate inline check.
            $_ws = (int) $r->attributes->get('workspace_id');
            // AEO-1 (2026-08-29): AEO is part of every AI plan (ADR-0012 — capacity, not capability, from $49 up).
            // The old "$69 WP Bundle" price gate is exactly the assumption the Owner said not to resurrect.
            $_rules = app(\App\Core\PlanGating\PlanGatingService::class)->getPlanRules($_ws);
            $_planSlug = $_rules['plan_slug'] ?? 'free';
            if (($_rules['ai_access'] ?? 'none') !== 'full') {
                return response()->json([
                    'success' => false,
                    'error' => 'aeo_plan_required',
                    'message' => 'AEO Mode is part of the AI plans — AI Lite ($49/month) and up.',
                    'current_plan_slug' => $_planSlug,
                ], 402);
            }
            $wsId = (int) $r->attributes->get('workspace_id');
            $svc = app(\App\Engines\SEO\Services\AeoSettingsService::class);
            $body = $svc->regenerateLlmsTxt($wsId);
            return response()->json([
                'success' => true,
                'bytes' => strlen($body),
                'preview' => mb_substr($body, 0, 600),
            ]);
        });

        // GET /api/seo/aeo/llms-txt — preview current llms.txt content (no regen).
        Route::get('/aeo/llms-txt', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $svc = app(\App\Engines\SEO\Services\AeoSettingsService::class);
            $body = $svc->getLlmsTxt($wsId);
            return response()->json([
                'success' => true,
                'content' => $body,
                'bytes' => strlen($body),
            ]);
        });

        // ── Wave 45 — AEO Enrichment endpoints ────────────────────────────

        // GET /api/seo/aeo/articles — list workspace articles with AEO status.
        Route::get('/aeo/articles', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            // Wave 47f — published-only. Drafts aren't crawlable by LLMs so
            // there's no AEO benefit to enriching them yet (the LLM-cite
            // surface is the rendered public page).
            $articles = \Illuminate\Support\Facades\DB::table('articles')
                ->where('workspace_id', $wsId)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'title', 'slug', 'status', 'jsonld_json', 'aeo_enriched_at', 'word_count', 'created_at']);

            // Wave 49a — fetch 30-day AI traffic counts per article URL.
            // Match aeo_traffic.url by slug suffix to handle full-URL storage.
            $since = now()->subDays(30);
            $trafficByUrl = \Illuminate\Support\Facades\DB::table('aeo_traffic')
                ->where('workspace_id', $wsId)
                ->where('created_at', '>=', $since)
                ->selectRaw('url, type, COUNT(*) as cnt')
                ->groupBy('url', 'type')
                ->get();
            $trafficMap = [];
            foreach ($trafficByUrl as $t) {
                $trafficMap[$t->url] = $trafficMap[$t->url] ?? ['crawler' => 0, 'referral' => 0];
                $trafficMap[$t->url][$t->type] = (int) $t->cnt;
            }
            $rows = $articles->map(function ($a) use ($trafficMap) {
                $enriched = $a->aeo_enriched_at !== null && $a->jsonld_json !== null;
                // Wave 49a — match aeo_traffic URLs that end with /blog/{slug}
                // or /{slug}. Crude but works without joining via website_id.
                $crawler = 0; $referral = 0;
                foreach ($trafficMap as $url => $counts) {
                    if ($a->slug && (str_ends_with($url, '/blog/' . $a->slug) || str_ends_with($url, '/' . $a->slug))) {
                        $crawler += $counts['crawler'] ?? 0;
                        $referral += $counts['referral'] ?? 0;
                    }
                }
                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'slug' => $a->slug,
                    'status' => $a->status,
                    'word_count' => $a->word_count,
                    'aeo_enriched' => $enriched,
                    'aeo_enriched_at' => $a->aeo_enriched_at,
                    'created_at' => $a->created_at,
                    'crawler_hits_30d' => $crawler,
                    'referrals_30d' => $referral,
                ];
            })->values();

            // 2026-05-28 — WP-synced pages from seo_content_index. For
            // workspaces backed by WP (or any site whose canonical content
            // lives outside Laravel's articles table), Wave-45 enrichment
            // needs a different surface. The chatbot website crawler stores
            // raw_text for each crawled page in chatbot_knowledge_sources;
            // if that exists, the page is known-live and substantive — use
            // it as the AEO list. Falls back to seo_content_index pages
            // with word_count >= 200 so unindexed-but-crawled-by-WP pages
            // also surface.
            $wpRows = collect();
            try {
                $crawled = \Illuminate\Support\Facades\DB::table('chatbot_knowledge_sources')
                    ->where('workspace_id', $wsId)
                    ->where('source_type', 'website_crawl')
                    ->where('chunk_count', '>', 0)
                    ->pluck('source_url')
                    ->filter()
                    ->all();

                $q = \Illuminate\Support\Facades\DB::table('seo_content_index')
                    ->where('workspace_id', $wsId)
                    ->whereNotNull('url')
                    ->where('url', '!=', '');
                if (! empty($crawled)) {
                    $q->whereIn('url', $crawled);
                } else {
                    $q->where('word_count', '>=', 200);
                }

                $wpRows = $q->orderByDesc('word_count')
                    ->limit(50)
                    ->get(['id', 'url', 'title', 'meta_description', 'word_count', 'aeo_enriched_at', 'aeo_jsonld_json', 'created_at'])
                    ->map(function ($p) {
                        $enriched = $p->aeo_enriched_at !== null && $p->aeo_jsonld_json !== null;
                        return [
                            'id'              => 'wp:' . $p->id,
                            'seo_index_id'    => (int) $p->id,
                            'title'           => $p->title ?: $p->url,
                            'slug'            => $p->url,
                            'url'             => $p->url,
                            'status'          => 'published',
                            'word_count'      => (int) ($p->word_count ?? 0),
                            'aeo_enriched'    => $enriched,
                            'aeo_enriched_at' => $p->aeo_enriched_at,
                            'created_at'      => $p->created_at,
                            'crawler_hits_30d'=> 0,
                            'referrals_30d'   => 0,
                            'source'          => 'wp_synced',
                        ];
                    });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[aeo/articles] wp_synced pull failed', ['err' => $e->getMessage()]);
            }

            $merged = $rows->merge($wpRows)->values();

            return response()->json([
                'success' => true,
                'articles' => $merged,
                'total' => $merged->count(),
                'enriched_count' => $merged->where('aeo_enriched', true)->count(),
                'laravel_count' => $rows->count(),
                'wp_synced_count' => $wpRows->count(),
            ]);
        });

        // 2026-05-28 — POST /api/seo/aeo/enrich-wp
        // Enrichment for WP-synced pages. Same JSON shape as a Wave-45
        // Article-side aeoEnrich payload (jsonld + tldr + faq) but the
        // source data comes from chatbot_knowledge_sources.raw_text
        // (cheap — already crawled) instead of articles.content, and the
        // result is stored on seo_content_index columns. The admin SPA
        // shows the generated blocks in a modal for paste into WordPress
        // (auto-push to WP is a future v1.4 / v1.5 sprint).
        //
        // Cost: 1cr per page (matches the Article-side cost).
        Route::post('/aeo/enrich-wp', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (! $wsId) {
                return response()->json(['success' => false, 'error' => 'NO_WORKSPACE'], 400);
            }
            $data = $r->validate([
                'seo_index_id' => 'required|integer',
            ]);

            $page = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('id', (int) $data['seo_index_id'])
                ->where('workspace_id', $wsId)
                ->first();
            if (! $page) {
                return response()->json(['success' => false, 'error' => 'PAGE_NOT_FOUND'], 404);
            }

            // Reuse the chatbot crawler's raw_text where possible — no extra
            // HTTP fetch. Falls back to a fresh fetch if the page wasn't
            // crawled (admin clicked Enrich on a non-crawled URL).
            $rawText = \Illuminate\Support\Facades\DB::table('chatbot_knowledge_sources')
                ->where('workspace_id', $wsId)
                ->where('source_url', $page->url)
                ->where('source_type', 'website_crawl')
                ->value('raw_text');

            if (! $rawText) {
                try {
                    $crawler = app(\App\Engines\Chatbot\Services\ChatbotWebsiteCrawler::class);
                    // Best-effort one-page crawl to get content into the KB
                    // (also stores chunks for the chatbot — side benefit).
                    $crawler->crawlOne($wsId, (string) $page->url);
                    $rawText = \Illuminate\Support\Facades\DB::table('chatbot_knowledge_sources')
                        ->where('workspace_id', $wsId)
                        ->where('source_url', $page->url)
                        ->value('raw_text');
                } catch (\Throwable $e) {
                    return response()->json([
                        'success' => false,
                        'error'   => 'PAGE_FETCH_FAILED',
                        'message' => 'Could not fetch the page content: ' . $e->getMessage(),
                    ], 422);
                }
            }

            if (! $rawText || mb_strlen($rawText) < 200) {
                return response()->json([
                    'success' => false,
                    'error'   => 'PAGE_TOO_THIN',
                    'message' => 'Page content is too thin to enrich. Make sure the URL is reachable and has substantive text.',
                ], 422);
            }

            // Reserve 1cr before the LLM call.
            $reservationRef = null;
            try {
                $rsv = app(\App\Core\Billing\CreditService::class)->reserveCredits(
                    $wsId, 1, 'aeo_enrich_wp', (int) $page->id
                );
                $reservationRef = $rsv->reservation_reference;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if ($e->getStatusCode() === 402) {
                    return response()->json([
                        'success' => false,
                        'error'   => 'INSUFFICIENT_CREDITS',
                        'message' => 'Top up to enrich more pages.',
                    ], 402);
                }
                throw $e;
            }

            $title = (string) ($page->meta_title ?: $page->title ?: '');
            $url   = (string) $page->url;
            $h1    = (string) ($page->h1 ?: '');
            $excerpt = mb_substr($rawText, 0, 8000);

            // 2026-05-28 — Pull canonical author + dates so the LLM doesn't
            // hallucinate ("Shukran UAE" vs the actual "Shukran Group", made-up
            // datePublished, etc.).
            $ws = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first(['business_name', 'name']);
            $brandName = (string) ($ws->business_name ?: $ws->name ?: 'Site');
            $publishedAt = $page->created_at
                ? \Carbon\Carbon::parse($page->created_at)->toDateString()
                : now()->toDateString();
            $modifiedAt  = $page->updated_at
                ? \Carbon\Carbon::parse($page->updated_at)->toDateString()
                : $publishedAt;

            $system = "You are an Answer-Engine-Optimization (AEO) author. Given a page's text, produce three artifacts that make it cite-worthy for LLM-based search (ChatGPT, Perplexity, Claude, etc.):\n"
                . "  1. jsonld — a complete schema.org Article JSON-LD object with @context, @type='Article', headline, description, author, datePublished, dateModified, mainEntityOfPage.\n"
                . "  2. tldr — a 2-3 sentence summary that answers the page's core question directly. Goes at the top of the article in the visible HTML.\n"
                . "  3. faq — an array of 3-5 {question, answer} objects derived from the page. The questions should be ones a real visitor might ask.\n"
                . "\n"
                . "AUTHORITATIVE VALUES — use these EXACTLY in the jsonld, do not invent or change them:\n"
                . "  - author.@type = 'Organization'\n"
                . "  - author.name  = '{$brandName}'\n"
                . "  - datePublished = '{$publishedAt}'\n"
                . "  - dateModified  = '{$modifiedAt}'\n"
                . "  - mainEntityOfPage.@id = '{$url}'\n"
                . "\n"
                . "Respond ONLY with valid JSON in this exact shape:\n"
                . '{ "jsonld": <object>, "tldr": "...", "faq": [ { "question": "...", "answer": "..." }, ... ] }';

            $user = "URL: {$url}\nTitle: {$title}\nH1: {$h1}\nBrand: {$brandName}\n\nPAGE TEXT (truncated to 8000 chars):\n{$excerpt}";

            try {
                $resp = app(\App\Connectors\RuntimeClient::class)->chatJson($system, $user, [
                    'task' => 'aeo_enrich_wp',
                    'workspace_id' => $wsId,
                    'seo_index_id' => (int) $page->id,
                ], 1500);
            } catch (\Throwable $e) {
                app(\App\Core\Billing\CreditService::class)->releaseReservedCredits($reservationRef);
                return response()->json([
                    'success' => false,
                    'error'   => 'RUNTIME_FAILED',
                    'message' => 'AEO generation failed: ' . $e->getMessage(),
                ], 502);
            }

            $parsed = $resp['parsed'] ?? null;
            if (! ($resp['success'] ?? false) || ! is_array($parsed) || empty($parsed['jsonld']) || empty($parsed['tldr']) || empty($parsed['faq'])) {
                app(\App\Core\Billing\CreditService::class)->releaseReservedCredits($reservationRef);
                return response()->json([
                    'success' => false,
                    'error'   => 'BAD_LLM_OUTPUT',
                    'message' => 'The AEO generator returned an incomplete response. Try again in a moment.',
                ], 502);
            }

            // Persist on the seo_content_index row.
            \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('id', $page->id)
                ->update([
                    'aeo_enriched_at' => now(),
                    'aeo_jsonld_json' => json_encode(\App\Engines\SEO\Services\AeoAuditService::buildJsonLd((array) $parsed['jsonld'], (array) ($parsed['faq'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'aeo_tldr'        => (string) $parsed['tldr'],
                    'aeo_faq_json'    => json_encode($parsed['faq'], JSON_UNESCAPED_UNICODE),
                    'updated_at'      => now(),
                ]);

            app(\App\Core\Billing\CreditService::class)->commitReservedCredits($reservationRef);

            return response()->json([
                'success' => true,
                'data' => [
                    'seo_index_id' => (int) $page->id,
                    'url'          => $url,
                    'title'        => $title,
                    'jsonld'       => $parsed['jsonld'],
                    'tldr'         => (string) $parsed['tldr'],
                    'faq'          => $parsed['faq'],
                    'enriched_at'  => now()->toIso8601String(),
                ],
                'credits_used' => 1,
            ]);
        });

        // 2026-06-22 — POST /api/seo/aeo/bulk-enrich
        // Bulk version of /aeo/enrich-wp. Enriches up to a safe chunk (max 8,
        // PHP-FPM 120s-safe) of the workspace's UNENRICHED, already-crawled
        // pages per call; the dashboard calls it repeatedly until `remaining`
        // is 0 (and stops if a chunk makes no progress). Per-page 1cr
        // reservation — a page that can't be enriched is skipped, never fails
        // the whole batch. Does NOT crawl in bulk (only pages that already have
        // crawled raw_text) and does NOT auto-push to WP (generation only;
        // review + push stays explicit). Mirrors enrich-wp — keep in sync.
        Route::post('/aeo/bulk-enrich', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (! $wsId) {
                return response()->json(['success' => false, 'error' => 'NO_WORKSPACE'], 400);
            }
            $data = $r->validate([
                'seo_index_ids'   => 'nullable|array',
                'seo_index_ids.*' => 'integer',
                'limit'           => 'nullable|integer',
            ]);
            $chunk = max(1, min(8, (int) ($data['limit'] ?? 8)));

            $buildQuery = function () use ($wsId, $data) {
                $q = \Illuminate\Support\Facades\DB::table('seo_content_index as sci')
                    ->join('chatbot_knowledge_sources as ks', function ($j) use ($wsId) {
                        $j->on('ks.source_url', '=', 'sci.url')
                          ->where('ks.source_type', '=', 'website_crawl')
                          ->where('ks.workspace_id', '=', $wsId);
                    })
                    ->where('sci.workspace_id', $wsId)
                    ->whereNull('sci.aeo_enriched_at')
                    ->whereRaw('CHAR_LENGTH(ks.raw_text) >= 200');
                if (! empty($data['seo_index_ids']) && is_array($data['seo_index_ids'])) {
                    $q->whereIn('sci.id', $data['seo_index_ids']);
                }
                return $q;
            };

            $remainingTotal = $buildQuery()->count();
            $targets = $buildQuery()->orderBy('sci.id')->limit($chunk)
                ->get(['sci.id', 'sci.url', 'sci.title', 'sci.meta_title', 'sci.h1', 'sci.created_at', 'sci.updated_at', 'ks.raw_text']);

            if ($targets->isEmpty()) {
                return response()->json([
                    'success' => true, 'results' => [], 'enriched' => 0, 'failed' => 0,
                    'skipped' => 0, 'remaining' => 0, 'credits_used' => 0,
                    'message' => 'No crawled, unenriched pages left to enrich.',
                ]);
            }

            $ws = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first(['business_name', 'name']);
            $brandName = (string) ($ws->business_name ?: $ws->name ?: 'Site');
            $runtime = app(\App\Connectors\RuntimeClient::class);
            $credits = app(\App\Core\Billing\CreditService::class);

            $results = []; $enriched = 0; $failed = 0; $skipped = 0; $creditsUsed = 0;

            foreach ($targets as $page) {
                $rawText = (string) $page->raw_text;
                if (mb_strlen($rawText) < 200) {
                    $results[] = ['id' => (int) $page->id, 'status' => 'skipped', 'reason' => 'thin'];
                    $skipped++; continue;
                }

                $reservationRef = null;
                try {
                    $rsv = $credits->reserveCredits($wsId, 1, 'aeo_enrich_wp', (int) $page->id);
                    $reservationRef = $rsv->reservation_reference;
                } catch (\Throwable $e) {
                    $results[] = ['id' => (int) $page->id, 'status' => 'skipped', 'reason' => 'insufficient_credits'];
                    $skipped++; continue;
                }

                $title   = (string) ($page->meta_title ?: $page->title ?: '');
                $url     = (string) $page->url;
                $h1      = (string) ($page->h1 ?: '');
                $excerpt = mb_substr($rawText, 0, 8000);
                $publishedAt = $page->created_at ? \Carbon\Carbon::parse($page->created_at)->toDateString() : now()->toDateString();
                $modifiedAt  = $page->updated_at ? \Carbon\Carbon::parse($page->updated_at)->toDateString() : $publishedAt;

                $system = "You are an Answer-Engine-Optimization (AEO) author. Given a page's text, produce three artifacts that make it cite-worthy for LLM-based search (ChatGPT, Perplexity, Claude, etc.):\n"
                    . "  1. jsonld — a complete schema.org Article JSON-LD object with @context, @type='Article', headline, description, author, datePublished, dateModified, mainEntityOfPage.\n"
                    . "  2. tldr — a 2-3 sentence summary that answers the page's core question directly.\n"
                    . "  3. faq — an array of 3-5 {question, answer} objects derived from the page.\n"
                    . "\nAUTHORITATIVE VALUES — use these EXACTLY in the jsonld, do not invent or change them:\n"
                    . "  - author.@type = 'Organization'\n"
                    . "  - author.name  = '{$brandName}'\n"
                    . "  - datePublished = '{$publishedAt}'\n"
                    . "  - dateModified  = '{$modifiedAt}'\n"
                    . "  - mainEntityOfPage.@id = '{$url}'\n"
                    . "\nRespond ONLY with valid JSON in this exact shape:\n"
                    . '{ "jsonld": <object>, "tldr": "...", "faq": [ { "question": "...", "answer": "..." }, ... ] }';
                $user = "URL: {$url}\nTitle: {$title}\nH1: {$h1}\nBrand: {$brandName}\n\nPAGE TEXT (truncated to 8000 chars):\n{$excerpt}";

                try {
                    $resp = $runtime->chatJson($system, $user, [
                        'task' => 'aeo_enrich_wp', 'workspace_id' => $wsId, 'seo_index_id' => (int) $page->id,
                    ], 1500);
                } catch (\Throwable $e) {
                    $credits->releaseReservedCredits($reservationRef);
                    $results[] = ['id' => (int) $page->id, 'status' => 'failed', 'reason' => 'runtime'];
                    $failed++; continue;
                }

                $parsed = $resp['parsed'] ?? null;
                if (! ($resp['success'] ?? false) || ! is_array($parsed) || empty($parsed['jsonld']) || empty($parsed['tldr']) || empty($parsed['faq'])) {
                    $credits->releaseReservedCredits($reservationRef);
                    $results[] = ['id' => (int) $page->id, 'status' => 'failed', 'reason' => 'bad_output'];
                    $failed++; continue;
                }

                \Illuminate\Support\Facades\DB::table('seo_content_index')->where('id', $page->id)->update([
                    'aeo_enriched_at' => now(),
                    'aeo_jsonld_json' => json_encode(\App\Engines\SEO\Services\AeoAuditService::buildJsonLd((array) $parsed['jsonld'], (array) ($parsed['faq'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'aeo_tldr'        => (string) $parsed['tldr'],
                    'aeo_faq_json'    => json_encode($parsed['faq'], JSON_UNESCAPED_UNICODE),
                    'updated_at'      => now(),
                ]);
                $credits->commitReservedCredits($reservationRef);
                $results[] = ['id' => (int) $page->id, 'status' => 'enriched'];
                $enriched++; $creditsUsed++;
            }

            return response()->json([
                'success'      => true,
                'results'      => $results,
                'enriched'     => $enriched,
                'failed'       => $failed,
                'skipped'      => $skipped,
                'remaining'    => max(0, $remainingTotal - $enriched),
                'credits_used' => $creditsUsed,
            ]);
        });

        // 2026-05-28 — POST /api/seo/aeo/push-to-wp
        // Take a previously-generated enrichment (from /aeo/enrich-wp,
        // persisted on seo_content_index) and push it into WordPress via
        // the WP plugin's new /wp-json/lgsc/v1/aeo-enrich-post REST route.
        // WP stores the blocks as post meta + renders them via wp_head
        // (JSON-LD) and the_content (TLDR + FAQ) — original post_content
        // is never modified.
        //
        // Auth: Laravel sends the workspace's plaintext connector api_key
        // (lgs_*) in the X-LGSC-API-KEY header. The plugin compares against
        // its own copy of the same value (option 'lgsc_api_key') with
        // hash_equals — same secret in both directions.
        Route::post('/aeo/push-to-wp', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (! $wsId) {
                return response()->json(['success' => false, 'error' => 'NO_WORKSPACE'], 400);
            }
            $data = $r->validate([
                'seo_index_id' => 'required|integer',
            ]);

            $page = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('id', (int) $data['seo_index_id'])
                ->where('workspace_id', $wsId)
                ->first();
            if (! $page) {
                return response()->json(['success' => false, 'error' => 'PAGE_NOT_FOUND'], 404);
            }
            if (! $page->aeo_enriched_at || ! $page->aeo_jsonld_json) {
                return response()->json([
                    'success' => false,
                    'error'   => 'NOT_ENRICHED',
                    'message' => 'Run AEO enrichment first.',
                ], 409);
            }

            $key = \Illuminate\Support\Facades\DB::table('api_keys')
                ->where('workspace_id', $wsId)
                ->where('type', 'connector')
                ->where('is_active', true)
                ->orderByDesc('id')
                ->value('key');
            if (! $key) {
                return response()->json([
                    'success' => false,
                    'error'   => 'NO_CONNECTOR_KEY',
                    'message' => 'No active connector API key for this workspace. Reconnect the WordPress plugin first.',
                ], 409);
            }

            $wsSettings = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('settings_json');
            $wsSettings = is_string($wsSettings) ? (json_decode($wsSettings, true) ?: []) : (array) $wsSettings;
            $wpBase = (string) ($wsSettings['website_url'] ?? '');
            if (! $wpBase) {
                $parts = parse_url((string) $page->url);
                if (! empty($parts['scheme']) && ! empty($parts['host'])) {
                    $wpBase = $parts['scheme'] . '://' . $parts['host'];
                }
            }
            if (! $wpBase) {
                return response()->json([
                    'success' => false,
                    'error'   => 'NO_WP_URL',
                    'message' => 'Could not determine the WordPress site URL for this workspace.',
                ], 409);
            }

            $payload = [
                'post_url' => (string) $page->url,
                'jsonld'   => json_decode((string) $page->aeo_jsonld_json, true) ?: null,
                'tldr'     => (string) ($page->aeo_tldr ?? ''),
                'faq'      => json_decode((string) ($page->aeo_faq_json ?? '[]'), true) ?: [],
            ];

            $endpoint = rtrim($wpBase, '/') . '/wp-json/lgsc/v1/aeo-enrich-post';

            try {
                $resp = \Illuminate\Support\Facades\Http::timeout(15)
                    ->withHeaders([
                        'X-LGSC-API-KEY' => $key,
                        'Accept'         => 'application/json',
                    ])
                    ->post($endpoint, $payload);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'error'   => 'WP_UNREACHABLE',
                    'message' => 'Could not reach WordPress: ' . $e->getMessage(),
                ], 502);
            }

            if (! $resp->successful()) {
                $body = $resp->json();
                return response()->json([
                    'success' => false,
                    'error'   => 'WP_REJECTED',
                    'status'  => $resp->status(),
                    'message' => is_array($body) ? ($body['message'] ?? ('HTTP ' . $resp->status())) : ('HTTP ' . $resp->status()),
                    'wp_response' => $body,
                ], 502);
            }

            return response()->json([
                'success'    => true,
                'wp_response'=> $resp->json(),
                'endpoint'   => $endpoint,
            ]);
        });

        // POST /api/seo/aeo/enrich — run aeoEnrich on a specific article.
        // Cost: 1cr standalone (Wave 45 default). Will be 0 when bundled
        // inside a Sarah chain after Wave 47 wires the chain integration.
        Route::post('/aeo/enrich', function (\Illuminate\Http\Request $r) {

            // Wave 47 — AEO plan gate inline check.
            $_ws = (int) $r->attributes->get('workspace_id');
            // AEO-1 (2026-08-29): AEO is part of every AI plan (ADR-0012 — capacity, not capability, from $49 up).
            // The old "$69 WP Bundle" price gate is exactly the assumption the Owner said not to resurrect.
            $_rules = app(\App\Core\PlanGating\PlanGatingService::class)->getPlanRules($_ws);
            $_planSlug = $_rules['plan_slug'] ?? 'free';
            if (($_rules['ai_access'] ?? 'none') !== 'full') {
                return response()->json([
                    'success' => false,
                    'error' => 'aeo_plan_required',
                    'message' => 'AEO Mode is part of the AI plans — AI Lite ($49/month) and up.',
                    'current_plan_slug' => $_planSlug,
                ], 402);
            }
            $wsId = (int) $r->attributes->get('workspace_id');
            $articleId = (int) $r->input('article_id', 0);
            if (!$articleId) {
                return response()->json(['success' => false, 'error' => 'article_id required'], 422);
            }

            // Wave 47e — reserve 1cr upfront. Release if aeoEnrich is a
            // no-op (parse failure or recently_enriched cache). Commit only
            // on a real enrichment.
            $creditSvc = app(\App\Core\Billing\CreditService::class);
            // AEO-1b: pooled, reservation-aware balance (a child workspace bills through its parent).
            $balance = (int) ($creditSvc->getBalance($wsId)['available'] ?? 0);
            if ($balance < 1) {
                return response()->json([
                    'success' => false,
                    'error' => 'insufficient_credits',
                    'required_credits' => 1,
                    'available' => $balance,
                ], 402);
            }
            $reservation = $creditSvc->reserveCredits($wsId, 1, 'Article', $articleId, 'aeo_enrich_' . uniqid());
            $reservationRef = $reservation->reservation_reference;

            try {
                $result = app(\App\Engines\Write\Services\WriteService::class)
                    ->aeoEnrich($wsId, ['article_id' => $articleId]);

                if (!empty($result['enriched'])) {
                    $creditSvc->commit($wsId, $reservationRef, 1);
                    \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
                        'workspace_id' => $wsId,
                        'action' => 'write.aeo_enrich',
                        'entity_type' => 'Article',
                        'entity_id' => $articleId,
                        'metadata_json' => json_encode([
                            'source' => 'manual',
                            'faq_count' => $result['faq_count'] ?? 0,
                            'heading_rewrites' => $result['heading_rewrites'] ?? 0,
                            'credit_cost' => 1,
                        ]),
                        'created_at' => now(),
                    ]);
                    $result['credits_used'] = 1;
                } else {
                    $creditSvc->release($wsId, $reservationRef);
                    $result['credits_used'] = 0;
                }
                $result['credits_remaining'] = max(0, $balance - ($result['credits_used'] ?? 0));
                return response()->json(['success' => true, 'result' => $result]);
            } catch (\Throwable $e) {
                $creditSvc->release($wsId, $reservationRef);
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
        });

        // POST /api/seo/aeo/audit/url — audit a single URL on demand.
        Route::post('/aeo/audit/url', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $url = trim((string) $r->input('url', ''));
            if (!preg_match('#^https?://#', $url)) {
                return response()->json(['success' => false, 'error' => 'Valid http(s) URL required'], 422);
            }
            $svc = app(\App\Engines\SEO\Services\AeoAuditService::class);
            $result = $svc->auditPage($wsId, $url);
            return response()->json(['success' => true, 'audit' => $result]);
        });

        // POST /api/seo/sitemap/ping — notify Google + Bing of the sitemap.
        Route::post('/sitemap/ping', function (\Illuminate\Http\Request $r) {
            $sitemapUrl = (string) $r->input('sitemap_url', '');
            if ($sitemapUrl === '' || !preg_match('#^https?://#', $sitemapUrl)) {
                return response()->json(['success' => false, 'error' => 'sitemap_url is required'], 422);
            }
            $results = [];
            foreach ([
                'google' => 'https://www.google.com/ping?sitemap=' . urlencode($sitemapUrl),
                'bing'   => 'https://www.bing.com/ping?sitemap=' . urlencode($sitemapUrl),
            ] as $engine => $pingUrl) {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(8)->get($pingUrl);
                    $results[$engine] = ['ok' => $resp->ok(), 'status' => $resp->status()];
                } catch (\Throwable $e) {
                    $results[$engine] = ['ok' => false, 'error' => $e->getMessage()];
                }
            }
            return response()->json([
                'success' => true,
                'sitemap' => $sitemapUrl,
                'pings'   => $results,
                'message' => 'Sitemap submitted to Google + Bing.',
            ]);
        });
                // 2026-05-19 (Wave 20e) — Position check for a single tracked keyword.// POST /keywords/{id}/check accepts {country|location_code, site_url}.
        // Mirrors TrackKeywordRanksCommand: resolve target domain, call SERP
        // API, update seo_keywords with current/previous rank + delta.
        Route::post('/keywords/{id}/check', function (\Illuminate\Http\Request $r, int $id) {
            $wsId = (int) $r->attributes->get('workspace_id');

            $kw = \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('id', $id)
                ->where('workspace_id', $wsId)
                ->first();

            if (!$kw) {
                return response()->json(['success' => false, 'error' => 'Keyword not found.'], 404);
            }

            // Resolve target domain: keyword.target_url → request site_url →
            // workspace's published website.
            $domain = null;
            if (!empty($kw->target_url)) {
                $domain = parse_url($kw->target_url, PHP_URL_HOST);
            }
            if (!$domain) {
                $siteUrl = (string) ($r->input('site_url') ?? $r->query('site_url') ?? '');
                if ($siteUrl !== '') {
                    $domain = parse_url($siteUrl, PHP_URL_HOST) ?: \App\Engines\SEO\Support\SiteScope::hostFromUrl($siteUrl);
                }
            }
            // Wave 32f — Removed silent fallback to first published tenant.
            // The keyword must have target_url set OR the request must include
            // an explicit site_url. We refuse to scan an arbitrary tenant.
            if (!$domain) {
                return response()->json([
                    'success' => false,
                    'error'   => 'No target website for this keyword. Set a target URL on the keyword or pick an active site at the top of SEO.',
                ], 422);
            }

            // Resolve location code.
            $locInput = $r->input('location_code') ?? $r->input('location') ?? $r->input('country');
            $locCode  = is_numeric($locInput) ? (int) $locInput : 0;
            if (!$locCode) {
                $map = ['USA' => 2840, 'UK' => 2826, 'UAE' => 2784, 'United States' => 2840, 'United Kingdom' => 2826, 'United Arab Emirates' => 2784, 'AE' => 2784, 'US' => 2840, 'GB' => 2826];
                $locCode = $map[(string) $locInput] ?? 2840;
            }

            $credits = app(\App\Core\Billing\CreditService::class);
            $cost = 1;
            if (!$credits->hasBalance($wsId, $cost)) {
                return response()->json(['success' => false, 'error' => "Not enough credits — rank check costs {$cost} credit.", 'required_credits' => $cost], 402);
            }
            $ref = $credits->reserve($wsId, $cost, 'seo/keyword_check');

            $conn = new \App\Connectors\DataForSeoConnector();
            if (!$conn->isConfigured()) {
                $credits->release($wsId, $ref);
                return response()->json([
                    'success' => false,
                    'error'   => 'SERP provider not configured. Please contact support.',
                ], 503);
            }

            $res = $conn->trackKeywordRank((string) $kw->keyword, (string) $domain, $locCode);
            if (empty($res['success'])) {
                $credits->release($wsId, $ref);
                return response()->json([
                    'success' => false,
                    'error'   => 'Position check failed: ' . ($res['error'] ?? 'unknown'),
                ], 200);
            }

            $credits->commit($wsId, $ref, $cost);
            $newRank      = $res['position'] ?? null;  // null = not in top N
            $previousRank = $kw->current_rank;
            $rankChange   = null;
            if ($newRank !== null && $previousRank !== null) {
                $rankChange = (int) $previousRank - (int) $newRank;  // positive = improved
            }

            \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('id', $id)
                ->update([
                    'previous_rank'   => $previousRank,
                    'current_rank'    => $newRank,
                    'rank_change'     => $rankChange,
                    'last_rank_check' => now(),
                    'rank_url'        => $res['url'] ?? null,
                    'updated_at'      => now(),
                ]);

            return response()->json([
                'success'       => true,
                'keyword'       => $kw->keyword,
                'domain'        => $domain,
                'position'      => $newRank,
                'previous_rank' => $previousRank,
                'rank_change'   => $rankChange,
                'url'           => $res['url'] ?? null,
                'title'         => $res['title'] ?? null,
                'location_code' => $locCode,
            ]);
        });
        // 2026-05-13 — Keyword suggestions from indexed content. MUST register
        // BEFORE /keywords/{id} or Laravel matches "suggestions" as {id} and
        // routes to DELETE → 405. Derives from seo_content_index (title + h1)
        // and tags `already_tracked` against seo_keywords. No DataForSEO call
        // (no credit cost) — pure on-site signal.
        Route::get('/keywords/suggestions', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');

            $pages = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->orderByDesc('content_score')
                ->limit(40)
                ->get(['url', 'title', 'h1', 'meta_title']);

            if ($pages->isEmpty()) {
                return response()->json([
                    'success'     => true,
                    'suggestions' => [],
                    'reason'      => 'no_indexed_pages',
                ]);
            }

            // Already-tracked keywords for the workspace (lowercased for compare).
            $tracked = \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->pluck('keyword')
                ->map(fn ($k) => mb_strtolower((string) $k))
                ->toArray();

            // Derive candidate keywords from each page: title + h1 + meta_title.
            // Strategy:
            //   1. Collect all candidate strings (titles, h1s, meta titles)
            //   2. Normalise whitespace, strip leading/trailing punctuation
            //   3. Generate n-grams (2-4 words) per candidate
            //   4. Dedupe + frequency-rank across all pages
            //   5. Skip stop-word-only n-grams + already-tracked keywords
            $stop = [
                'the','a','an','and','or','but','of','for','to','in','on','at','with','by','from','is','are','was','were',
                'be','been','being','this','that','these','those','it','its','as','if','your','our','my','we','you','i',
                'how','what','why','when','where','about','best','top','new','more','most','all','some','any',
            ];
            $freq = [];   // n-gram → count
            $sources = []; // n-gram → first page URL
            foreach ($pages as $p) {
                $strings = array_filter([
                    (string) ($p->title ?? ''),
                    (string) ($p->h1 ?? ''),
                    (string) ($p->meta_title ?? ''),
                ]);
                foreach ($strings as $s) {
                    // Strip site-suffix patterns like "| Brand", "- Brand", "— Brand"
                    $s = preg_replace('/\s*[\|\-–—]\s*[^\|\-–—]+$/u', '', $s) ?? $s;
                    $s = mb_strtolower(trim($s));
                    // Replace non-alphanumeric with single space.
                    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
                    $tokens = array_values(array_filter(
                        preg_split('/\s+/', $s) ?: [],
                        fn ($t) => $t !== '' && ! in_array($t, $stop, true) && mb_strlen($t) >= 3
                    ));
                    if (count($tokens) < 2) continue;
                    // 2-, 3-, and 4-grams
                    for ($n = 2; $n <= 4; $n++) {
                        for ($i = 0; $i + $n <= count($tokens); $i++) {
                            $gram = implode(' ', array_slice($tokens, $i, $n));
                            if (mb_strlen($gram) > 80) continue;
                            $freq[$gram] = ($freq[$gram] ?? 0) + 1;
                            if (! isset($sources[$gram])) $sources[$gram] = (string) $p->url;
                        }
                    }
                }
            }
            // Rank by frequency (desc), keep top 50, drop already-tracked.
            arsort($freq);
            $top = array_slice($freq, 0, 80, true);
            $suggestions = [];
            foreach ($top as $kw => $count) {
                $alreadyTracked = in_array($kw, $tracked, true);
                if ($alreadyTracked) continue;
                $suggestions[] = [
                    'keyword'           => $kw,
                    'frequency'         => $count,
                    'volume'            => null,
                    'competition'       => null,
                    'competition_index' => null,
                    'already_tracked'   => false,
                    'source_url'        => $sources[$kw] ?? null,
                ];
                if (count($suggestions) >= 25) break;
            }

            // Wave 20d — Enrich with live volume/competition via a single
            // DataForSEO Keywords Data batch call. Falls back to null on
            // any failure (the frontend renders "—" gracefully).
            $locInput = $r->input('location_code') ?? $r->input('location');
            $locCode  = is_numeric($locInput) ? (int) $locInput : 0;
            if (!$locCode) {
                $map = ['USA' => 2840, 'UK' => 2826, 'UAE' => 2784, 'United States' => 2840, 'United Kingdom' => 2826, 'United Arab Emirates' => 2784, 'AE' => 2784, 'US' => 2840, 'GB' => 2826];
                $locCode = $map[(string) $locInput] ?? 2840;
            }
            $enrichmentNote = null;
            $creditsSpent = 0;
            if (!empty($suggestions)) {
                try {
                    $conn = new \App\Connectors\DataForSeoConnector();
                    if ($conn->isConfigured()) {
                        $credits = app(\App\Core\Billing\CreditService::class);
                        $cost = 1;
                        if (!$credits->hasBalance($wsId, $cost)) {
                            return response()->json([
                                'success' => false,
                                'error'   => "Not enough credits — suggestion analysis costs {$cost} credit.",
                                'required_credits' => $cost,
                                'suggestions' => $suggestions,
                            ], 402);
                        }
                        $ref = $credits->reserve($wsId, $cost, 'seo/keywords_suggest');
                        $kwList = array_map(fn ($s) => $s['keyword'], $suggestions);
                        $kdRes  = $conn->keywordData($kwList, $locCode, 'en');
                        if (!empty($kdRes['success']) && !empty($kdRes['keywords'])) {
                            $byKw = [];
                            foreach ($kdRes['keywords'] as $row) {
                                if (!empty($row['keyword'])) {
                                    $byKw[mb_strtolower($row['keyword'])] = $row;
                                }
                            }
                            foreach ($suggestions as $i => $s) {
                                $hit = $byKw[mb_strtolower($s['keyword'])] ?? null;
                                if ($hit) {
                                    $suggestions[$i]['volume']            = $hit['volume'] ?? null;
                                    $suggestions[$i]['cpc']               = $hit['cpc'] ?? null;
                                    $suggestions[$i]['competition']       = $hit['competition'] ?? null;
                                    $suggestions[$i]['competition_index'] = $hit['competition_index'] ?? null;
                                }
                            }
                            // Re-rank: live volume desc, then on-site frequency desc.
                            usort($suggestions, function ($a, $b) {
                                $va = (int) ($a['volume'] ?? 0);
                                $vb = (int) ($b['volume'] ?? 0);
                                if ($va !== $vb) return $vb <=> $va;
                                return ($b['frequency'] ?? 0) <=> ($a['frequency'] ?? 0);
                            });
                            $credits->commit($wsId, $ref, $cost);
                            $creditsSpent = $cost;
                        } else {
                            $credits->release($wsId, $ref);
                            $enrichmentNote = 'enrichment_failed';
                        }
                    } else {
                        $enrichmentNote = 'serp_provider_not_configured';
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('keywords/suggestions enrichment failed', [
                        'error' => $e->getMessage(),
                    ]);
                    $enrichmentNote = 'enrichment_exception';
                }
            }

            return response()->json([
                'success'       => true,
                'suggestions'   => $suggestions,
                'total'         => count($suggestions),
                'derived_from'  => 'seo_content_index',
                'enrichment'    => $enrichmentNote ?: 'live',
                'location_code' => $locCode,
                'credits_spent' => $creditsSpent,
                'note'          => 'Candidates derived from your indexed content; volume + competition fetched live.',
            ]);
        });
        Route::delete('/keywords/{id}', [$c, 'deleteKeyword']);

        // Audits (2)
        Route::get('/audits', [$c, 'listAudits']);
        Route::get('/audits/{id}', [$c, 'getAudit']);

        // Dashboard & Reporting (2)
        Route::get('/dashboard', [$c, 'dashboard']);
        Route::get('/report', [$c, 'report']);

        // F11 (2026-05-17) — Live SEO knowledge aggregate.
        // The Overview tab's main gauge + content/links dim cards read
        // from this. It MUST stay live (no audit dependency) so that any
        // page meta edit immediately reflects in the dashboard.
        Route::get('/knowledge', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\SEO\Services\SeoService::class)->getKnowledge($wsId)
            );
        });

        // F12 (2026-05-17) — Aliases for endpoints the live UI calls under
        // alternate paths. Discovered via end-to-end smoke test of every
        // _seoApi(...) call against staging — these were silent 404s.
        // Pattern: alias points to the canonical handler, no logic
        // duplication. Each alias has the exact same shape as its target.

        // /wins → /quick-wins (UI uses both names on the Overview tab).
        Route::get('/wins', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\SEO\Services\SeoDataService::class)
                    ->quickWins($wsId, $r->query('url'))
            );
        });

        // /clusters/build → /clusters/rebuild (UI sends 'build').
        Route::post('/clusters/build', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            \Illuminate\Support\Facades\Artisan::call('seo:cluster', ['workspace_id' => $wsId]);
            return response()->json(['success' => true, 'message' => 'cluster rebuild queued']);
        });

        // /outbound-check → /outbound/check (UI sends the no-slash variant).
        Route::post('/outbound-check', [$c, 'checkOutbound']);

        // /link-graph/orphans — live SCI query for pages with no inbound
        // internal links. Was a 404 before — Overview's link_health and
        // any Links-tab "orphan pages" surface depended on it.
        Route::get('/link-graph/orphans', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $q = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('inbound_links', 0)
                ->where('word_count', '>', 100);
            // Wave 16 — site filter (URL-based, since SCI is workspace-scoped only).
            if ($host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r)) {
                $q->where('url', 'like', '%//' . $host . '%');
            }
            $rows = $q->orderByDesc('updated_at')->limit(200)
                ->get(['id', 'url', 'title', 'content_score', 'authority_score', 'inbound_links']);
            return response()->json([
                'success' => true,
                'orphans' => $rows,
                'count'   => $rows->count(),
            ]);
        });

        // Wave 16 (2026-05-19). GET /api/seo/sites — picker inventory.
        // Returns the dropdown options for the global site selector at the
        // top of the SEO engine. Unions:
        //   1) `websites` table rows (workspace-internal Laravel sites)
        //   2) `seo_settings.site_url` when it doesn't match any websites row
        //      (external WP-connected sites — paid-tier feature, ws7 etc.)
        // Default is the most-recently-audited site (or first if no audits).
        Route::get('/sites', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');

            $sites = [];

            // Internal Laravel sites
            $rows = \Illuminate\Support\Facades\DB::table('websites')
                ->where('workspace_id', $wsId)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->get(['id', 'name', 'domain', 'subdomain', 'custom_domain', 'platform', 'status']);
            foreach ($rows as $row) {
                $host = '';
                if (! empty($row->custom_domain))      $host = $row->custom_domain;
                elseif (! empty($row->domain))         $host = $row->domain;
                elseif (! empty($row->subdomain)) {
                    // Wave 16 fix — subdomain column already contains the full
                    // host (e.g. "growthlab-agency.levelupgrowth.io") in most
                    // workspaces; only append the platform suffix when bare.
                    $host = str_contains((string) $row->subdomain, '.')
                        ? (string) $row->subdomain
                        : $row->subdomain . '.levelupgrowth.io';
                }
                if ($host === '') {
                    // Skip drafts with no configured domain — they have no URL
                    // for the SEO engine to scope to.
                    continue;
                }
                $host = strtolower(trim($host, " /\t\n\r"));
                $sites[] = [
                    'id'       => 'website_' . $row->id,
                    'name'     => $row->name ?: $host,
                    'url'      => 'https://' . $host,
                    'host'     => $host,
                    'kind'     => 'internal',
                    'platform' => $row->platform ?: 'laravel',
                    'status'   => $row->status ?: 'draft',
                ];
            }

            // External / connector-registered site (surfaces from
            // seo_settings.site_url when there's no matching `websites` row).
            //
            // Wave 16d-v2 (2026-05-19) — dedup correctly when an internal
            // `websites` row and the external `seo_settings` entry refer to
            // the SAME brand at different subdomains of one root. Picks the
            // host that ACTUALLY has SEO data (SCI page count) — the empty
            // shell drops, the populated host wins. Also stops claiming the
            // external entry is WordPress: it's whatever URL the user has
            // connected (could be plain HTML, WP, Shopify…). Label stays
            // neutral until we have a reliable signal.
            $rootDomain = static function (string $host): string {
                if ($host === '') return '';
                $parts = explode('.', $host);
                return count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;
            };
            $pageCountFor = static function (int $ws, string $host): int {
                if ($host === '') return 0;
                return (int) \Illuminate\Support\Facades\DB::table('seo_content_index')
                    ->where('workspace_id', $ws)
                    ->where('url', 'like', '%//' . $host . '%')
                    ->count();
            };

            $extUrl = (string) \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
            $extName = (string) \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'site_name')->value('value');
            if ($extUrl !== '') {
                $extHost  = strtolower((string) parse_url($extUrl, PHP_URL_HOST));
                $extRoot  = $rootDomain($extHost);
                $extPages = $pageCountFor($wsId, $extHost);

                // Pass 1: exact host match (cleanest signal — same site).
                $rivalIdx = null;
                foreach ($sites as $i => $s) {
                    if (strtolower((string) $s['host']) === $extHost) { $rivalIdx = $i; break; }
                }
                // Pass 2: same NAME + same root domain — signals the same
                // logical brand registered twice (e.g. internal `platform.X.com`
                // + external `staging.X.com`, both named "X"). Without the
                // name guard, "same root" would steal an unrelated sibling
                // site (e.g. 123-fitness-gym.X.com) as the rival.
                if ($rivalIdx === null && $extRoot !== '') {
                    $extNameLc = strtolower(trim((string) $extName));
                    foreach ($sites as $i => $s) {
                        if ($extNameLc !== ''
                            && strtolower(trim((string) $s['name'])) === $extNameLc
                            && $rootDomain(strtolower((string) $s['host'])) === $extRoot) {
                            $rivalIdx = $i;
                            break;
                        }
                    }
                }

                $extEntry = [
                    'id'       => 'external_' . md5($extUrl),
                    'name'     => $extName ?: $extHost,
                    'url'      => rtrim($extUrl, '/'),
                    'host'     => $extHost,
                    'kind'     => 'external',
                    'platform' => 'unknown',
                    'status'   => 'connected',
                ];

                if ($rivalIdx === null) {
                    $sites[] = $extEntry;
                } else {
                    $rivalHost  = strtolower((string) $sites[$rivalIdx]['host']);
                    $rivalPages = $pageCountFor($wsId, $rivalHost);
                    if ($extPages > $rivalPages) {
                        // External host has more indexed pages — it's the
                        // real site; replace the empty internal shell.
                        $sites[$rivalIdx] = $extEntry;
                    }
                    // else: rival (internal) has equal/more data; keep it, skip external.
                }
            }

            // Default site = most-recently-audited (matched by host)
            $defaultUrl = null;
            $lastAudit = \Illuminate\Support\Facades\DB::table('seo_audits')
                ->where('workspace_id', $wsId)
                ->whereNotNull('url')
                ->orderByDesc('created_at')
                ->value('url');
            if ($lastAudit) {
                $lastHost = strtolower((string) parse_url($lastAudit, PHP_URL_HOST));
                foreach ($sites as $s) {
                    if (strtolower((string) $s['host']) === $lastHost) {
                        $defaultUrl = $s['url'];
                        break;
                    }
                }
            }
            if (! $defaultUrl && ! empty($sites)) {
                $defaultUrl = $sites[0]['url'];
            }

            return response()->json([
                'success'     => true,
                'sites'       => $sites,
                'default_url' => $defaultUrl,
                'count'       => count($sites),
            ]);
        });

        // ═══════════════════════════════════════════════════════════════
        // Wave 18a (2026-05-19) — Reports tab endpoints
        // ═══════════════════════════════════════════════════════════════
        // Five CSV exports + an HTML report. The UI buttons at line ~7078
        // (lgseDownloadReport) and ~7237 (lgseExportCsv) were calling these
        // paths and getting silent 404s. All endpoints honor the Wave 16
        // SiteScope filter (?site_url=…) so reports reflect the user's
        // currently-selected website.
        //
        // CSV streaming via response()->streamDownload to avoid PHP memory
        // bloat on large workspaces (e.g. ws7 with 1318 link rows).

        // Internal helper — CSV row writer with proper escaping.
        $csvLine = function (array $row): string {
            $cells = array_map(function ($v) {
                if ($v === null) return '';
                $s = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES);
                if (preg_match('/[",\n\r]/', $s)) {
                    $s = '"' . str_replace('"', '""', $s) . '"';
                }
                return $s;
            }, $row);
            return implode(',', $cells) . "\r\n";
        };

        // GET /api/seo/reports/export/keywords
        Route::get('/reports/export/keywords', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $q = \Illuminate\Support\Facades\DB::table('seo_keywords')->where('workspace_id', $wsId);
            if ($host !== '') {
                $q->where(function ($x) use ($host) {
                    $x->where('target_url', 'like', '%//' . $host . '%')->orWhereNull('target_url');
                });
            }
            $rows = $q->orderByDesc('volume')->get();
            $fname = 'seo-keywords-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $rows) {
                echo $csvLine(['keyword', 'volume', 'current_rank', 'previous_rank', 'status', 'target_url', 'last_checked_at', 'created_at']);
                foreach ($rows as $row) {
                    echo $csvLine([
                        $row->keyword, $row->volume, $row->current_rank, $row->previous_rank,
                        $row->status, $row->target_url, $row->last_checked_at ?? '', $row->created_at,
                    ]);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // GET /api/seo/reports/export/pages
        Route::get('/reports/export/pages', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $q = \Illuminate\Support\Facades\DB::table('seo_content_index AS sci')
                ->leftJoin('articles AS a', function ($j) {
                    $j->on('a.wp_post_id', '=', 'sci.wp_post_id')
                      ->on('a.workspace_id', '=', 'sci.workspace_id');
                })
                ->where('sci.workspace_id', $wsId)
                ->select(
                    'sci.url', 'sci.title', 'sci.content_score', 'sci.word_count',
                    'sci.inbound_links', 'sci.internal_link_count', 'sci.external_link_count',
                    'sci.meta_title', 'sci.meta_description', 'sci.h1', 'sci.authority_score',
                    'a.featured_image_url', 'a.featured_image_alt', 'a.featured_image_error'
                );
            if ($host !== '') { $q->where('sci.url', 'like', '%//' . $host . '%'); }
            $rows = $q->orderBy('sci.content_score')->get();
            $fname = 'seo-pages-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $rows) {
                echo $csvLine(['url', 'title', 'content_score', 'word_count', 'inbound_links',
                               'internal_link_count', 'external_link_count', 'meta_title',
                               'meta_description', 'h1', 'authority_score',
                               'featured_image_url', 'featured_image_alt', 'featured_image_error']);
                foreach ($rows as $row) {
                    echo $csvLine([
                        $row->url, $row->title, $row->content_score, $row->word_count,
                        $row->inbound_links, $row->internal_link_count, $row->external_link_count,
                        $row->meta_title, $row->meta_description, $row->h1, $row->authority_score,
                        $row->featured_image_url, $row->featured_image_alt, $row->featured_image_error,
                    ]);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // GET /api/seo/reports/export/links
        Route::get('/reports/export/links', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $q = \Illuminate\Support\Facades\DB::table('seo_links')->where('workspace_id', $wsId);
            if ($host !== '') {
                $q->where(function ($x) use ($host) {
                    $x->where('source_url', 'like', '%//' . $host . '%')
                      ->orWhere('target_url', 'like', '%//' . $host . '%');
                });
            }
            $rows = $q->orderByDesc('priority_score')->get();
            $fname = 'seo-links-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $rows) {
                echo $csvLine(['source_url', 'target_url', 'anchor_text', 'status', 'priority_score', 'created_at', 'updated_at']);
                foreach ($rows as $row) {
                    echo $csvLine([
                        $row->source_url, $row->target_url, $row->anchor_text,
                        $row->status, $row->priority_score ?? '', $row->created_at, $row->updated_at,
                    ]);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // GET /api/seo/reports/export/images
        Route::get('/reports/export/images', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $q = \Illuminate\Support\Facades\DB::table('seo_images')->where('workspace_id', $wsId);
            if ($host !== '') {
                $q->where(function ($x) use ($host) {
                    $x->where('image_url', 'like', '%//' . $host . '%')
                      ->orWhere('page_url', 'like', '%//' . $host . '%');
                });
            }
            $rows = $q->orderByDesc('updated_at')->get();
            $fname = 'seo-images-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $rows) {
                echo $csvLine(['page_url', 'image_url', 'optimization_status', 'optimization_provider',
                               'size_bytes', 'verified_size_bytes', 'saved_bytes', 'alt_text',
                               'missing_alt', 'empty_alt', 'webp_url', 'last_optimized_at']);
                foreach ($rows as $row) {
                    echo $csvLine([
                        $row->page_url, $row->image_url, $row->optimization_status,
                        $row->optimization_provider, $row->size_bytes, $row->verified_size_bytes,
                        $row->saved_bytes, $row->alt_text, $row->missing_alt, $row->empty_alt,
                        $row->webp_url, $row->last_optimized_at,
                    ]);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // GET /api/seo/reports/export/anchors
        // Uses Wave 16e flattened-per-anchor data via the same code path
        // the /anchors endpoint uses, so the CSV matches what users see
        // on the Anchors sub-tab.
        Route::get('/reports/export/anchors', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $rowsQ = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')
                ->where('workspace_id', $wsId);
            if ($host !== '') { $rowsQ->where('target_url', 'like', '%//' . $host . '%'); }
            $pages = $rowsQ->orderByDesc('total_inbound')->get();
            $genericTerms = ['click here','here','read more','learn more','this','link','page','website','more','info','details','visit','read'];
            $keywordsLower = \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('workspace_id', $wsId)->pluck('keyword')
                ->map(fn ($k) => strtolower(trim((string) $k)))->filter()->all();

            $fname = 'seo-anchors-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $pages, $genericTerms, $keywordsLower) {
                echo $csvLine(['target_url', 'anchor_text', 'count', 'classification', 'issue_type', 'fix']);
                foreach ($pages as $row) {
                    $dist = json_decode($row->anchor_distribution ?? '[]', true) ?: [];
                    foreach ($dist as $entry) {
                        $anchorText = (string) ($entry['anchor'] ?? '');
                        $count = (int) ($entry['count'] ?? 1);
                        if ($anchorText === '') continue;
                        $anchorLower = strtolower(trim($anchorText));
                        $classification = 'descriptive';
                        if (in_array($anchorLower, $genericTerms, true)) {
                            $classification = 'generic';
                        } elseif (! empty($keywordsLower) && in_array($anchorLower, $keywordsLower, true)) {
                            $classification = 'exact_match';
                        } elseif (mb_strlen($anchorText) > 40) {
                            $classification = 'long_phrase';
                        }
                        $issueType = null; $fix = null;
                        if ($classification === 'generic') {
                            $issueType = 'generic'; $fix = 'Replace with descriptive anchor';
                        } elseif ($count > 3) {
                            $issueType = 'over_optimised'; $fix = 'Vary anchor text — reused ' . $count . 'x';
                        }
                        echo $csvLine([$row->target_url, $anchorText, $count, $classification, $issueType, $fix]);
                    }
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // Wave 18c (2026-05-19) — comprehensive SEO report HTML.
        // Used by both /reports/audit/html (returns the HTML) and
        // /reports/audit/pdf (pipes the HTML through puppeteer for binary PDF).
        // Composes site-state KPIs, ranking summary, link health, content
        // health, image optimization, top issues, cluster gaps + audit history.
        $auditReportBuildHtml = function (int $wsId, string $host): string {
            $like = $host !== '' ? ('%//' . $host . '%') : null;

            // 1. Audit history
            $aQ = \Illuminate\Support\Facades\DB::table('seo_audits')->where('workspace_id', $wsId);
            if ($like) { $aQ->where('url', 'like', $like); }
            $audits = $aQ->orderByDesc('created_at')->limit(20)->get();
            $latest = $audits->first();
            $oldest = $audits->last();
            $avgScore = $audits->count() ? (int) round($audits->avg('score')) : 0;
            $scoreChange = ($latest && $oldest) ? ((int) $latest->score - (int) $oldest->score) : 0;

            // 2. Pages stats
            $sciQ = \Illuminate\Support\Facades\DB::table('seo_content_index')->where('workspace_id', $wsId);
            if ($like) { $sciQ->where('url', 'like', $like); }
            $sci = $sciQ->selectRaw('
                COUNT(*) AS total,
                AVG(content_score) AS avg_sc,
                SUM(CASE WHEN inbound_links=0 AND word_count>100 THEN 1 ELSE 0 END) AS orphans,
                SUM(CASE WHEN inbound_links BETWEEN 1 AND 2 THEN 1 ELSE 0 END) AS weak,
                SUM(CASE WHEN (meta_description IS NULL OR meta_description="") THEN 1 ELSE 0 END) AS no_meta,
                SUM(CASE WHEN word_count < 300 THEN 1 ELSE 0 END) AS thin,
                SUM(CASE WHEN content_score < 50 THEN 1 ELSE 0 END) AS below_50,
                SUM(CASE WHEN h1 IS NULL OR h1="" THEN 1 ELSE 0 END) AS no_h1
            ')->first();

            // 3. Keywords
            $kwQ = \Illuminate\Support\Facades\DB::table('seo_keywords')->where('workspace_id', $wsId);
            if ($like) {
                $kwQ->where(function ($q) use ($like) {
                    $q->where('target_url', 'like', $like)->orWhereNull('target_url');
                });
            }
            $kwTotal = (clone $kwQ)->count();
            $kwTop3  = (clone $kwQ)->whereBetween('current_rank', [1, 3])->count();
            $kwTop10 = (clone $kwQ)->whereBetween('current_rank', [1, 10])->count();
            $kwImproving = (clone $kwQ)->whereNotNull('current_rank')->whereNotNull('previous_rank')
                ->whereColumn('current_rank', '<', 'previous_rank')->count();
            $kwDeclining = (clone $kwQ)->whereNotNull('current_rank')->whereNotNull('previous_rank')
                ->whereColumn('current_rank', '>', 'previous_rank')->count();

            // 4. Links
            $lnQ = \Illuminate\Support\Facades\DB::table('seo_links')->where('workspace_id', $wsId);
            if ($like) {
                $lnQ->where(function ($q) use ($like) {
                    $q->where('source_url', 'like', $like)->orWhere('target_url', 'like', $like);
                });
            }
            $linkSugs    = (clone $lnQ)->where('status', 'suggested')->count();
            $linkApplied = (clone $lnQ)->where('status', 'inserted')->count();
            $linkDismissed = (clone $lnQ)->where('status', 'dismissed')->count();
            $linkTotal = $linkSugs + $linkApplied + $linkDismissed;
            $applyRate = $linkTotal > 0 ? (int) round(100 * $linkApplied / $linkTotal) : 0;

            // 5. Images
            $imgQ = \Illuminate\Support\Facades\DB::table('seo_images')->where('workspace_id', $wsId);
            if ($like) {
                $imgQ->where(function ($q) use ($like) {
                    $q->where('image_url', 'like', $like)->orWhere('page_url', 'like', $like);
                });
            }
            $imgStats = $imgQ->selectRaw('
                COUNT(*) AS total,
                SUM(CASE WHEN optimization_status="optimized" THEN 1 ELSE 0 END) AS optimized,
                SUM(CASE WHEN optimization_status="failed" THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN missing_alt=1 OR empty_alt=1 THEN 1 ELSE 0 END) AS no_alt,
                COALESCE(SUM(saved_bytes), 0) AS saved_bytes
            ')->first();

            // 6. Anchors (only need health-summary counts)
            $anQ = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')->where('workspace_id', $wsId);
            if ($like) { $anQ->where('target_url', 'like', $like); }
            $anchorStats = $anQ->selectRaw('
                COUNT(*) AS pages,
                SUM(total_inbound) AS total_inbound,
                SUM(generic_anchors) AS generic
            ')->first();

            // 7. Clusters + gaps
            $clQ = \Illuminate\Support\Facades\DB::table('seo_clusters')->where('workspace_id', $wsId);
            if ($like) {
                $clQ->where(function ($q) use ($like) {
                    $q->where('pillar_url', 'like', $like)->orWhereNull('pillar_url');
                });
            }
            $clusters = $clQ->orderByDesc('page_count')->get();
            $clustersTotal = $clusters->count();
            $noPillar = $clusters->whereNull('pillar_url')->count() + $clusters->filter(fn ($c) => empty($c->pillar_url))->count() - $clusters->whereNull('pillar_url')->count();
            $thinClusters = $clusters->filter(fn ($c) => (int) $c->page_count < 3)->count();

            // 8. Articles (Wave 11 image-error tracking)
            $artQ = \Illuminate\Support\Facades\DB::table('articles')->where('workspace_id', $wsId);
            $articlesTotal = (clone $artQ)->count();
            $articleImgErrors = (clone $artQ)->whereNotNull('featured_image_error')->count();
            $articleNoAlt = (clone $artQ)->whereNotNull('featured_image_url')
                ->where(function ($q) { $q->whereNull('featured_image_alt')->orWhere('featured_image_alt', ''); })
                ->count();

            // 9. Audit-items (open issues from latest audit)
            $openIssues = 0;
            if ($latest && $latest->id) {
                $openIssues = \Illuminate\Support\Facades\DB::table('seo_audit_items')
                    ->where('audit_id', $latest->id)
                    ->whereIn('status', ['error', 'warning'])
                    ->count();
            }

            // 10. Workspace info
            $ws = \Illuminate\Support\Facades\DB::table('workspaces')->find($wsId);
            $wsName = $ws->business_name ?? $ws->name ?? 'Workspace ' . $wsId;
            $siteLabel = $host !== '' ? $host : 'all workspace sites';
            $generatedAt = date('Y-m-d H:i');

            $esc = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
            $fmtKb = fn ($b) => number_format(((int) $b) / 1024, 1) . ' KB';

            // Audit rows
            $auditRows = '';
            foreach ($audits as $a) {
                $when = $a->created_at ? date('M j, Y', strtotime($a->created_at)) : '—';
                $score = (int) ($a->score ?? 0);
                $cls = $score >= 70 ? 'good' : ($score >= 50 ? 'warn' : 'bad');
                $auditRows .= '<tr><td>' . $esc($when) . '</td><td class="url">' . $esc($a->url) . '</td><td class="' . $cls . '">' . $score . '</td><td>' . $esc($a->status) . '</td></tr>';
            }

            // Cluster gap rows (top 10)
            $clusterRows = '';
            foreach ($clusters->take(10) as $c) {
                $gaps = [];
                if (empty($c->pillar_url))                  $gaps[] = 'no-pillar';
                if ((int) $c->page_count < 3)               $gaps[] = 'thin';
                if ((float) ($c->avg_score ?? 0) < 50)      $gaps[] = 'low-quality';
                if ((float) ($c->avg_authority ?? 0) < 0.3) $gaps[] = 'low-authority';
                $clusterRows .= '<tr><td>' . $esc($c->label ?? 'Cluster #' . $c->id) . '</td><td>' . (int) $c->page_count . '</td><td>' . round((float) ($c->avg_score ?? 0), 1) . '</td><td>' . ($gaps ? $esc(implode(', ', $gaps)) : '<span class="good">healthy</span>') . '</td></tr>';
            }

            // KPI card helper.
            $kpi = function ($label, $val, $cls = '') use ($esc) {
                return '<div class="kpi"><div class="kpi-label">' . $esc($label) . '</div><div class="kpi-val ' . $cls . '">' . $esc((string) $val) . '</div></div>';
            };

            $html = '<!doctype html><html><head><meta charset="utf-8">'
                . '<title>SEO Report — ' . $esc($wsName) . ' — ' . $esc($siteLabel) . '</title>'
                . '<style>'
                . 'body{font-family:-apple-system,BlinkMacSystemFont,Inter,Arial,sans-serif;color:#1f2937;max-width:980px;margin:24px auto;padding:0 24px;background:#fff;line-height:1.4}'
                . 'h1{font-size:26px;margin:0 0 4px;color:#111827}'
                . '.sub{color:#6b7280;font-size:13px;margin-bottom:24px}'
                . '.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:18px}'
                . '.kpi{border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;background:#fafafa}'
                . '.kpi-label{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:3px;font-weight:600}'
                . '.kpi-val{font-size:20px;font-weight:700;color:#111827;font-variant-numeric:tabular-nums}'
                . 'h2{font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;border-bottom:1px solid #e5e7eb;padding-bottom:6px;margin:22px 0 10px;font-weight:700}'
                . '.section-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}'
                . 'table{width:100%;border-collapse:collapse;font-size:11.5px;margin-bottom:14px}'
                . 'th,td{padding:7px 10px;text-align:left;border-bottom:1px solid #eef0f3}'
                . 'th{font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-weight:600;background:#fafafa}'
                . '.good{color:#10b981;font-weight:700}.warn{color:#f59e0b;font-weight:700}.bad{color:#ef4444;font-weight:700}'
                . '.url{color:#6b7280;font-size:11px;max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
                . '.foot{margin-top:32px;padding-top:14px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:10.5px;text-align:center}'
                . '@page{size:A4;margin:14mm 12mm}'
                . '@media print{body{margin:0;padding:0 8mm}.kpi-grid,.section-grid{break-inside:avoid}h2{break-after:avoid}}'
                . '</style></head><body>'
                . '<h1>SEO Report</h1>'
                . '<div class="sub"><strong>' . $esc($wsName) . '</strong> · ' . $esc($siteLabel) . ' · generated ' . $esc($generatedAt) . '</div>'

                . '<h2>Site health</h2>'
                . '<div class="kpi-grid">'
                .   $kpi('Avg score',     $avgScore)
                .   $kpi('Score change',  ($scoreChange >= 0 ? '+' : '') . $scoreChange, $scoreChange > 0 ? 'good' : ($scoreChange < 0 ? 'bad' : 'warn'))
                .   $kpi('Audits run',    $audits->count())
                .   $kpi('Open issues',   $openIssues)
                .   $kpi('Pages indexed', (int) ($sci->total ?? 0))
                . '</div>'

                . '<h2>Content</h2>'
                . '<div class="kpi-grid">'
                .   $kpi('Avg content',  round((float) ($sci->avg_sc ?? 0), 1))
                .   $kpi('Below 50',     (int) ($sci->below_50 ?? 0), ((int) ($sci->below_50 ?? 0) > 0 ? 'warn' : ''))
                .   $kpi('Thin (<300w)', (int) ($sci->thin ?? 0))
                .   $kpi('Missing meta', (int) ($sci->no_meta ?? 0), ((int) ($sci->no_meta ?? 0) > 0 ? 'warn' : ''))
                .   $kpi('No H1',        (int) ($sci->no_h1 ?? 0))
                . '</div>'

                . '<h2>Links</h2>'
                . '<div class="kpi-grid">'
                .   $kpi('Orphan pages', (int) ($sci->orphans ?? 0), ((int) ($sci->orphans ?? 0) > 0 ? 'bad' : 'good'))
                .   $kpi('Weak (1–2)',   (int) ($sci->weak ?? 0))
                .   $kpi('Suggested',    $linkSugs)
                .   $kpi('Applied',      $linkApplied)
                .   $kpi('Apply rate',   $applyRate . '%', $applyRate >= 50 ? 'good' : ($applyRate >= 20 ? 'warn' : 'bad'))
                . '</div>'

                . '<h2>Keywords + topics</h2>'
                . '<div class="kpi-grid">'
                .   $kpi('Tracked',     $kwTotal)
                .   $kpi('Top 3',       $kwTop3, $kwTop3 > 0 ? 'good' : '')
                .   $kpi('Top 10',      $kwTop10)
                .   $kpi('Improving',   $kwImproving, $kwImproving > 0 ? 'good' : '')
                .   $kpi('Declining',   $kwDeclining, $kwDeclining > 0 ? 'bad' : '')
                . '</div>'

                . '<h2>Images + anchors</h2>'
                . '<div class="kpi-grid">'
                .   $kpi('Image rows',  (int) ($imgStats->total ?? 0))
                .   $kpi('Optimized',   (int) ($imgStats->optimized ?? 0), ((int) ($imgStats->optimized ?? 0) > 0 ? 'good' : ''))
                .   $kpi('Image errors',(int) ($imgStats->failed ?? 0), ((int) ($imgStats->failed ?? 0) > 0 ? 'bad' : ''))
                .   $kpi('Bytes saved', $fmtKb((int) ($imgStats->saved_bytes ?? 0)))
                .   $kpi('Generic anchors', (int) ($anchorStats->generic ?? 0), ((int) ($anchorStats->generic ?? 0) > 0 ? 'warn' : ''))
                . '</div>'

                . '<h2>Clusters (top 10)</h2>'
                . '<table><thead><tr><th>Cluster</th><th>Pages</th><th>Avg score</th><th>Gaps</th></tr></thead><tbody>'
                . ($clusterRows ?: '<tr><td colspan="4" style="color:#9ca3af;text-align:center">No clusters built yet. Run topic clustering on the Topics tab.</td></tr>')
                . '</tbody></table>'

                . '<h2>Audit history</h2>'
                . '<table><thead><tr><th>Date</th><th>URL</th><th>Score</th><th>Status</th></tr></thead><tbody>'
                . ($auditRows ?: '<tr><td colspan="4" style="color:#9ca3af;text-align:center">No audits in scope.</td></tr>')
                . '</tbody></table>'

                . '<div class="foot">Generated by LevelUp Growth · ' . $esc($generatedAt) . '</div>'
                . '</body></html>';

            return $html;
        };

        // Wave 18c — rich summary endpoint for the Reports-tab top section.
        // Same data the HTML/PDF report uses but as structured JSON so the SPA
        // can render 5 sections × 5 KPI cards without re-fetching everything.
        Route::get('/reports/summary', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $like = $host !== '' ? ('%//' . $host . '%') : null;

            // Audits
            $aQ = \Illuminate\Support\Facades\DB::table('seo_audits')->where('workspace_id', $wsId);
            if ($like) { $aQ->where('url', 'like', $like); }
            $audits  = $aQ->orderByDesc('created_at')->limit(20)->get();
            $latest  = $audits->first();
            $oldest  = $audits->last();
            $avgAud  = $audits->count() ? (int) round($audits->avg('score')) : 0;
            $delta   = ($latest && $oldest) ? ((int) $latest->score - (int) $oldest->score) : 0;
            $openIssues = $latest && $latest->id
                ? (int) \Illuminate\Support\Facades\DB::table('seo_audit_items')
                    ->where('audit_id', $latest->id)
                    ->whereIn('status', ['error', 'warning'])->count()
                : 0;

            // Pages
            $sciQ = \Illuminate\Support\Facades\DB::table('seo_content_index')->where('workspace_id', $wsId);
            if ($like) { $sciQ->where('url', 'like', $like); }
            $sci = $sciQ->selectRaw('
                COUNT(*) AS total,
                AVG(content_score) AS avg_sc,
                SUM(CASE WHEN inbound_links=0 AND word_count>100 THEN 1 ELSE 0 END) AS orphans,
                SUM(CASE WHEN inbound_links BETWEEN 1 AND 2 THEN 1 ELSE 0 END) AS weak,
                SUM(CASE WHEN (meta_description IS NULL OR meta_description="") THEN 1 ELSE 0 END) AS no_meta,
                SUM(CASE WHEN word_count < 300 THEN 1 ELSE 0 END) AS thin,
                SUM(CASE WHEN content_score < 50 THEN 1 ELSE 0 END) AS below_50,
                SUM(CASE WHEN h1 IS NULL OR h1="" THEN 1 ELSE 0 END) AS no_h1
            ')->first();

            // Keywords
            $kwQ = \Illuminate\Support\Facades\DB::table('seo_keywords')->where('workspace_id', $wsId);
            if ($like) {
                $kwQ->where(function ($q) use ($like) {
                    $q->where('target_url', 'like', $like)->orWhereNull('target_url');
                });
            }
            $kwTotal = (clone $kwQ)->count();
            $kwTop3  = (clone $kwQ)->whereBetween('current_rank', [1, 3])->count();
            $kwTop10 = (clone $kwQ)->whereBetween('current_rank', [1, 10])->count();
            $kwImp   = (clone $kwQ)->whereNotNull('current_rank')->whereNotNull('previous_rank')
                ->whereColumn('current_rank', '<', 'previous_rank')->count();
            $kwDec   = (clone $kwQ)->whereNotNull('current_rank')->whereNotNull('previous_rank')
                ->whereColumn('current_rank', '>', 'previous_rank')->count();

            // Links
            $lnQ = \Illuminate\Support\Facades\DB::table('seo_links')->where('workspace_id', $wsId);
            if ($like) {
                $lnQ->where(function ($q) use ($like) {
                    $q->where('source_url', 'like', $like)->orWhere('target_url', 'like', $like);
                });
            }
            $lSug    = (clone $lnQ)->where('status', 'suggested')->count();
            $lApp    = (clone $lnQ)->where('status', 'inserted')->count();
            $lDis    = (clone $lnQ)->where('status', 'dismissed')->count();
            $lTot    = $lSug + $lApp + $lDis;
            $applyR  = $lTot > 0 ? (int) round(100 * $lApp / $lTot) : 0;

            // Images
            $imgQ = \Illuminate\Support\Facades\DB::table('seo_images')->where('workspace_id', $wsId);
            if ($like) {
                $imgQ->where(function ($q) use ($like) {
                    $q->where('image_url', 'like', $like)->orWhere('page_url', 'like', $like);
                });
            }
            $imgS = $imgQ->selectRaw('
                COUNT(*) AS total,
                SUM(CASE WHEN optimization_status="optimized" THEN 1 ELSE 0 END) AS optimized,
                SUM(CASE WHEN optimization_status="failed" THEN 1 ELSE 0 END) AS failed,
                COALESCE(SUM(saved_bytes), 0) AS saved_bytes
            ')->first();

            // Anchors
            $anQ = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')->where('workspace_id', $wsId);
            if ($like) { $anQ->where('target_url', 'like', $like); }
            $generic = (int) $anQ->sum('generic_anchors');

            return response()->json([
                'success' => true,
                'data' => [
                    'site_health' => [
                        'avg_audit_score' => $avgAud,
                        'score_change'    => $delta,
                        'audits_run'      => $audits->count(),
                        'open_issues'     => $openIssues,
                        'pages_indexed'   => (int) ($sci->total ?? 0),
                    ],
                    'content' => [
                        'avg_content_score' => round((float) ($sci->avg_sc ?? 0), 1),
                        'below_50'          => (int) ($sci->below_50 ?? 0),
                        'thin'              => (int) ($sci->thin ?? 0),
                        'no_meta'           => (int) ($sci->no_meta ?? 0),
                        'no_h1'             => (int) ($sci->no_h1 ?? 0),
                    ],
                    'links' => [
                        'orphans'        => (int) ($sci->orphans ?? 0),
                        'weak'           => (int) ($sci->weak ?? 0),
                        'suggested'      => $lSug,
                        'applied'        => $lApp,
                        'apply_rate_pct' => $applyR,
                    ],
                    'keywords' => [
                        'tracked'   => $kwTotal,
                        'top_3'     => $kwTop3,
                        'top_10'    => $kwTop10,
                        'improving' => $kwImp,
                        'declining' => $kwDec,
                    ],
                    'images_anchors' => [
                        'image_rows'      => (int) ($imgS->total ?? 0),
                        'image_optimized' => (int) ($imgS->optimized ?? 0),
                        'image_failed'    => (int) ($imgS->failed ?? 0),
                        'bytes_saved_kb'  => round((int) ($imgS->saved_bytes ?? 0) / 1024, 1),
                        'generic_anchors' => $generic,
                    ],
                ],
            ]);
        });

        // HTML response (cheap — no puppeteer).
        Route::get('/reports/audit/html', function (\Illuminate\Http\Request $r) use ($auditReportBuildHtml) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            return response($auditReportBuildHtml($wsId, $host), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        });

        // PDF response — pipes HTML through puppeteer (tools/report-render-pdf.cjs).
        // Bundled Chromium lives at .puppeteer-cache (already in use for studio
        // PNG export). Falls back to inline HTML with a 503 header so the
        // frontend can detect + warn cleanly.
        Route::get('/reports/audit/pdf', function (\Illuminate\Http\Request $r) use ($auditReportBuildHtml) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $html = $auditReportBuildHtml($wsId, $host);

            $script = base_path('tools/report-render-pdf.cjs');
            if (! is_file($script)) {
                return response($html, 503, ['Content-Type' => 'text/html; charset=UTF-8', 'X-PDF-Fallback' => 'script_missing']);
            }

            $childEnv = [
                'HOME'                => '/tmp',
                'PATH'                => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'PUPPETEER_CACHE_DIR' => base_path('.puppeteer-cache'),
                'LANG'                => 'C.UTF-8',
                'LC_ALL'              => 'C.UTF-8',
            ];
            $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open('node ' . escapeshellarg($script), $descriptorspec, $pipes, null, $childEnv);
            if (! is_resource($proc)) {
                return response($html, 503, ['Content-Type' => 'text/html; charset=UTF-8', 'X-PDF-Fallback' => 'proc_open_failed']);
            }
            fwrite($pipes[0], $html);
            fclose($pipes[0]);
            $pdf = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);

            if ($exit !== 0 || ! $pdf || strncmp($pdf, '%PDF-', 5) !== 0) {
                \Illuminate\Support\Facades\Log::warning('[reports/audit/pdf] puppeteer failed', [
                    'workspace_id' => $wsId, 'exit' => $exit, 'stderr' => mb_substr((string) $err, 0, 500),
                ]);
                return response($html, 503, ['Content-Type' => 'text/html; charset=UTF-8', 'X-PDF-Fallback' => 'render_failed']);
            }

            $fname = 'seo-report-' . ($host ?: 'all') . '-' . date('Ymd') . '.pdf';
            return response($pdf, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $fname . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });

        // ───────────────────────────────────────────────────────────────
        // Wave 18b (2026-05-19) — Tier-2 action-list reports.
        // Six CSVs sourced from data we already correctly compute in the
        // SPA tabs (Wave 16e/16f/16g) so the downloads match what users
        // see on screen. All site-scoped via SiteScope.
        // ───────────────────────────────────────────────────────────────

        // /reports/export/orphans — pages with no inbound internal links.
        Route::get('/reports/export/orphans', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $q = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('inbound_links', 0)
                ->where('word_count', '>', 100);
            if ($host !== '') { $q->where('url', 'like', '%//' . $host . '%'); }
            $rows = $q->orderByDesc('content_score')->get(['url', 'title', 'content_score', 'word_count', 'authority_score', 'updated_at']);
            $fname = 'seo-orphans-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $rows) {
                echo $csvLine(['url', 'title', 'content_score', 'word_count', 'authority_score', 'last_seen']);
                foreach ($rows as $row) {
                    echo $csvLine([$row->url, $row->title, $row->content_score, $row->word_count, $row->authority_score, $row->updated_at]);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // /reports/export/weak-pages — pages with 1-2 inbound (under-linked).
        Route::get('/reports/export/weak-pages', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $q = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->whereBetween('inbound_links', [1, 2]);
            if ($host !== '') { $q->where('url', 'like', '%//' . $host . '%'); }
            $rows = $q->orderBy('inbound_links')->orderByDesc('content_score')
                ->get(['url', 'title', 'inbound_links', 'content_score', 'word_count', 'authority_score']);
            $fname = 'seo-weak-pages-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $rows) {
                echo $csvLine(['url', 'title', 'inbound_count', 'content_score', 'word_count', 'authority_score']);
                foreach ($rows as $row) {
                    echo $csvLine([$row->url, $row->title, $row->inbound_links, $row->content_score, $row->word_count, $row->authority_score]);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // /reports/export/quick-wins — reuses SeoDataService::quickWins so the
        // CSV matches what users see on the Quick Wins sub-tab.
        Route::get('/reports/export/quick-wins', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $siteUrl = $r->query('site_url');
            $data = app(\App\Engines\SEO\Services\SeoDataService::class)
                ->quickWins($wsId, null, $siteUrl ?: null);
            $wins = $data['quick_wins'] ?? [];
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $fname = 'seo-quick-wins-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $wins) {
                echo $csvLine(['title', 'severity', 'description', 'url', 'score']);
                foreach ($wins as $w) {
                    $w = (array) $w;
                    echo $csvLine([$w['title'] ?? '', $w['severity'] ?? '', $w['description'] ?? '', $w['url'] ?? '', $w['score'] ?? '']);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // /reports/export/cluster-gaps — Wave 16g per-cluster health gaps.
        Route::get('/reports/export/cluster-gaps', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $cq = \Illuminate\Support\Facades\DB::table('seo_clusters')->where('workspace_id', $wsId);
            if ($host !== '') {
                $cq->where(function ($q) use ($host) {
                    $q->where('pillar_url', 'like', '%//' . $host . '%')->orWhereNull('pillar_url');
                });
            }
            $clusters = $cq->orderByDesc('page_count')->get();
            $fname = 'seo-cluster-gaps-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $clusters) {
                echo $csvLine(['cluster_id', 'topic', 'page_count', 'avg_score', 'avg_authority', 'pillar_url', 'gaps', 'recommendation']);
                foreach ($clusters as $c) {
                    $issues = []; $recs = [];
                    $label = (string) ($c->label ?? 'Cluster #' . $c->id);
                    if (empty($c->pillar_url))                  { $issues[] = 'no_pillar';      $recs[] = 'Create a pillar page on "' . $label . '".'; }
                    if ((int) $c->page_count < 3)               { $issues[] = 'thin_cluster';   $recs[] = 'Only ' . (int) $c->page_count . ' pages — add 2-3 more articles.'; }
                    if ((float) ($c->avg_score ?? 0) < 50)      { $issues[] = 'low_quality';    $recs[] = 'Avg content score ' . round((float) ($c->avg_score ?? 0)) . '/100 — improve existing pages.'; }
                    if ((float) ($c->avg_authority ?? 0) < 0.3) { $issues[] = 'low_authority';  $recs[] = 'Cluster has weak link authority — add inbound internal links.'; }
                    echo $csvLine([
                        $c->id, $label, $c->page_count,
                        round((float) ($c->avg_score ?? 0), 1),
                        round((float) ($c->avg_authority ?? 0), 3),
                        $c->pillar_url ?? '', implode('|', $issues), implode(' ', $recs),
                    ]);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // /reports/export/anchor-health — only anchors with detected issues.
        Route::get('/reports/export/anchor-health', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $pagesQ = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')->where('workspace_id', $wsId);
            if ($host !== '') { $pagesQ->where('target_url', 'like', '%//' . $host . '%'); }
            $pages = $pagesQ->get();
            $genericTerms = ['click here','here','read more','learn more','this','link','page','website','more','info','details','visit','read'];
            $keywordsLower = \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('workspace_id', $wsId)->pluck('keyword')
                ->map(fn ($k) => strtolower(trim((string) $k)))->filter()->all();
            $fname = 'seo-anchor-health-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $pages, $genericTerms, $keywordsLower) {
                echo $csvLine(['target_url', 'anchor_text', 'count', 'classification', 'issue_type', 'fix']);
                foreach ($pages as $row) {
                    $dist = json_decode($row->anchor_distribution ?? '[]', true) ?: [];
                    foreach ($dist as $entry) {
                        $anchorText = (string) ($entry['anchor'] ?? '');
                        $count = (int) ($entry['count'] ?? 1);
                        if ($anchorText === '') continue;
                        $anchorLower = strtolower(trim($anchorText));
                        $isGeneric = in_array($anchorLower, $genericTerms, true);
                        $isOverOpt = $count > 3;
                        $isTooLong = mb_strlen($anchorText) > 60;
                        // Only emit ROWS WITH AT LEAST ONE ISSUE — that's the point of "health".
                        if (! $isGeneric && ! $isOverOpt && ! $isTooLong) continue;
                        if ($isGeneric)   { $cls = 'generic';        $fix = 'Replace with descriptive anchor'; $iss = 'generic'; }
                        elseif ($isOverOpt) { $cls = 'descriptive';  $fix = 'Vary anchor text — reused ' . $count . 'x'; $iss = 'over_optimised'; }
                        else              { $cls = 'long_phrase';    $fix = 'Trim to 2-6 descriptive words';            $iss = 'too_long'; }
                        echo $csvLine([$row->target_url, $anchorText, $count, $cls, $iss, $fix]);
                    }
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // /reports/export/link-backlog — per-source-page count of suggested vs
        // applied vs dismissed link suggestions. Surfaces where the backlog
        // is concentrated so the user can prioritise.
        Route::get('/reports/export/link-backlog', function (\Illuminate\Http\Request $r) use ($csvLine) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $q = \Illuminate\Support\Facades\DB::table('seo_links')
                ->where('workspace_id', $wsId)
                ->groupBy('source_url')
                ->selectRaw("source_url,
                             SUM(CASE WHEN status='suggested' THEN 1 ELSE 0 END) AS suggested_count,
                             SUM(CASE WHEN status='inserted'  THEN 1 ELSE 0 END) AS inserted_count,
                             SUM(CASE WHEN status='dismissed' THEN 1 ELSE 0 END) AS dismissed_count,
                             COUNT(*) AS total_count,
                             MAX(updated_at) AS last_change");
            if ($host !== '') {
                $q->where(function ($x) use ($host) {
                    $x->where('source_url', 'like', '%//' . $host . '%')
                      ->orWhere('target_url', 'like', '%//' . $host . '%');
                });
            }
            $rows = $q->orderByDesc('suggested_count')->limit(500)->get();
            $fname = 'seo-link-backlog-' . ($host ?: 'all') . '-' . date('Ymd') . '.csv';
            return response()->streamDownload(function () use ($csvLine, $rows) {
                echo $csvLine(['source_url', 'suggested_count', 'inserted_count', 'dismissed_count', 'total_count', 'apply_rate_pct', 'last_change']);
                foreach ($rows as $row) {
                    $total = (int) $row->total_count;
                    $rate = $total > 0 ? (int) round(100 * ((int) $row->inserted_count) / $total) : 0;
                    echo $csvLine([
                        $row->source_url,
                        $row->suggested_count, $row->inserted_count, $row->dismissed_count,
                        $row->total_count, $rate, $row->last_change,
                    ]);
                }
            }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
        });

        // ── Wave 15 (2026-05-18) — Manual CTAs (Pages + Links tabs) ──
        // Direct API surface for the Pages-tab "Fix orphan" / "Retry image"
        // chips and the Links-tab "Apply top N" bulk button. Works in both
        // contexts (WP X-API-KEY iframe + Laravel JWT SaaS) — the route
        // group's auth middleware already accepts both, and the underlying
        // executor's notify() respects Wave-9 context-aware agent routing.

        // POST /api/seo/links/apply-bulk
        // Body: {limit?: int<=100, mode?: 'orphans_first'|'newest', target_url?: string}
        // Wraps SeoAssistantService::bulkApplyLinkSuggestionsExternal. The
        // UI button click IS the user's approval — no proposal/confirm step.
        Route::post('/links/apply-bulk', function (\Illuminate\Http\Request $r) {
            $wsId   = (int) $r->attributes->get('workspace_id');
            $userId = optional($r->user())->id;
            $params = [];
            if ($r->filled('limit'))      { $params['limit']      = (int) $r->input('limit'); }
            if ($r->filled('mode'))       { $params['mode']       = (string) $r->input('mode'); }
            if ($r->filled('target_url')) { $params['target_url'] = (string) $r->input('target_url'); }

            $svc = app(\App\Engines\SEO\Services\SeoAssistantService::class);
            $result = $svc->bulkApplyLinkSuggestionsExternal($wsId, $userId ? (int) $userId : null, $params);
            $status = ($result['success'] ?? false) ? 200 : 402;
            return response()->json($result, $status);
        });

        // POST /api/seo/pages/retry-image
        // Body: {url: string, force?: bool}
        // Pages-tab "Retry image" chip → looks up the article whose
        // featured_image_error is non-null AND url matches, calls the
        // existing connector regenerate-image endpoint, clears the error
        // column on success.
        Route::post('/pages/retry-image', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $url  = trim((string) $r->input('url', ''));
            if ($url === '') {
                return response()->json(['success' => false, 'error' => 'missing_url'], 422);
            }

            // Find the article: by wp_post_id via SCI match, OR by slug match in URL.
            $sci = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('url', $url)
                ->first(['wp_post_id', 'title']);
            $article = null;
            if ($sci && ! empty($sci->wp_post_id)) {
                $article = \Illuminate\Support\Facades\DB::table('articles')
                    ->where('workspace_id', $wsId)
                    ->where('wp_post_id', $sci->wp_post_id)
                    ->first(['id', 'title', 'slug', 'featured_image_error']);
            }
            if (! $article) {
                // Fallback: last path segment slug match (handles unpublished or
                // SCI-missing rows).
                $path = (string) parse_url($url, PHP_URL_PATH);
                $slug = trim(basename($path));
                if ($slug !== '') {
                    $article = \Illuminate\Support\Facades\DB::table('articles')
                        ->where('workspace_id', $wsId)
                        ->where('slug', 'like', $slug . '%')
                        ->orderByDesc('id')
                        ->first(['id', 'title', 'slug', 'featured_image_error']);
                }
            }
            if (! $article) {
                return response()->json([
                    'success' => false,
                    'error'   => 'article_not_found',
                    'message' => 'No Laravel-managed article matches this URL. WordPress-hosted images need to be regenerated from the WP admin.',
                ], 404);
            }

            // Call the existing connector endpoint with the workspace's API key.
            $apiKey = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'webhook_secret')->value('value');
            $base   = rtrim((string) config('app.url', 'http://127.0.0.1'), '/');
            try {
                $resp = \Illuminate\Support\Facades\Http::withHeaders([
                        'X-API-KEY'      => (string) $apiKey,
                        'X-Workspace-ID' => (string) $wsId,
                        'Accept'         => 'application/json',
                        'Host'           => 'staging.levelupgrowth.io',
                    ])
                    ->timeout(120)
                    ->post($base . '/api/connector/pages/regenerate-image', [
                        'url'   => $url,
                        'title' => $article->title,
                        'force' => true,
                    ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'error'   => 'connection_failed',
                    'message' => 'Could not reach the image generator: ' . $e->getMessage(),
                ], 502);
            }

            $j = $resp->json() ?: [];
            if ($resp->successful() && ($j['success'] ?? false) && ! empty($j['image_url'])) {
                $newAttempts = (int) ($article->featured_image_error ? 1 : 0); // reset counter
                \Illuminate\Support\Facades\DB::table('articles')
                    ->where('id', $article->id)
                    ->update([
                        'featured_image_url'      => $j['image_url'],
                        'featured_image_error'    => null,
                        'featured_image_attempts' => $newAttempts,
                        'updated_at'              => now(),
                    ]);
                return response()->json([
                    'success'   => true,
                    'image_url' => $j['image_url'],
                    'message'   => 'Featured image regenerated.',
                ]);
            }

            $err = $j['error'] ?? $j['message'] ?? ('http_' . $resp->status());
            return response()->json([
                'success' => false,
                'error'   => $err,
                'http'    => $resp->status(),
                'message' => 'Image generation failed: ' . $err,
            ], 502);
        });

        // ── Wave 13 (2026-05-18) — Stubs for feature-gap 404s ──
        // The live UI calls these endpoints but the underlying features
        // aren't built yet. Returning structured "not available" envelopes
        // (instead of 404) keeps the UI quiet and gives users honest
        // feedback. Each stub records the request so we can measure demand.
        //
        // When a feature ships for real, replace the stub here with the
        // real implementation — the response shape contracts below match
        // what each UI consumer expects.

        // GSC integration — Phase 5 on the roadmap.
        // ── Google Search Console (model B — platform OAuth app) ──────────
        // Phase 1: OAuth core. Each workspace connects its OWN Google account
        // + property; tokens are stored per workspace_id (gsc_connections).
        // These routes live in the dual-auth /seo group, so they work both in
        // the standalone app (JWT) and the embedded connector (X-API-KEY) —
        // workspace_id is set by JwtAuthMiddleware for both. Response shapes
        // are SUPERSETS of the old stubs (success/connected/site/queries kept)
        // so no existing UI consumer breaks.

        // Start the connect flow — returns the Google consent URL the button
        // opens in a new tab. window.open(r.url) is already wired in seo.js.
        Route::get('/gsc/auth-url', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $gsc  = app(\App\Engines\SEO\Services\GscClient::class);
            if (! $gsc->isConfigured()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'not_configured',
                    'message' => 'Google Search Console is not set up on this server yet. Please try again shortly.',
                ], 503);
            }
            return response()->json(['success' => true, 'url' => $gsc->getAuthUrl($wsId)]);
        });

        Route::get('/gsc/status', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $gsc  = app(\App\Engines\SEO\Services\GscClient::class);
            $conn = $gsc->getConnection($wsId);
            $connected = $conn !== null && (bool) $conn->connected && ! empty($conn->refresh_token_enc);
            return response()->json([
                'success'    => true,
                'connected'  => $connected,
                'site'       => $conn->site_url ?? null,
                'email'      => $conn->connected_email ?? null,
                'last_sync'  => optional($conn?->last_sync_at)->toIso8601String(),
                'message'    => $connected ? null : 'Connect Google Search Console to sync ranking data.',
            ]);
        });

        // After consent, list the GSC properties the account can access so the
        // user can map one to this workspace.
        Route::get('/gsc/sites', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $gsc  = app(\App\Engines\SEO\Services\GscClient::class);
            try {
                return response()->json(['success' => true, 'sites' => $gsc->listSites($wsId)]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'sites' => [], 'message' => $e->getMessage()], 400);
            }
        });

        // Persist the chosen property + flip the connection to "connected".
        Route::post('/gsc/select-site', function (\Illuminate\Http\Request $r) {
            $wsId    = (int) $r->attributes->get('workspace_id');
            $siteUrl = trim((string) $r->input('site_url', ''));
            if ($siteUrl === '') {
                return response()->json(['success' => false, 'message' => 'Please choose a Search Console property.'], 422);
            }
            $gsc = app(\App\Engines\SEO\Services\GscClient::class);
            if (! $gsc->getConnection($wsId)) {
                return response()->json(['success' => false, 'message' => 'Connect Google Search Console first.'], 409);
            }
            // Guard: only allow a property the account actually owns.
            try {
                $owned = array_column($gsc->listSites($wsId), 'siteUrl');
            } catch (\Throwable $e) {
                $owned = [];
            }
            if (! empty($owned) && ! in_array($siteUrl, $owned, true)) {
                return response()->json(['success' => false, 'message' => 'That property is not available on the connected account.'], 422);
            }
            $gsc->setSite($wsId, $siteUrl);
            return response()->json(['success' => true, 'site' => $siteUrl]);
        });

        Route::post('/gsc/disconnect', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            app(\App\Engines\SEO\Services\GscClient::class)->disconnect($wsId);
            return response()->json(['success' => true, 'connected' => false]);
        });

        // Pull this workspace's Search Console data into gsc_metrics (idempotent
        // upsert). Cost 0 — it is a data pull, not generation. Also registered
        // in CapabilityMap + Orchestrator as gsc_sync so agents (James/Sarah)
        // can trigger a refresh as a first-class action.
        Route::post('/gsc/sync', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            try {
                $res = app(\App\Engines\SEO\Services\GscSyncService::class)->sync($wsId, [
                    'days' => (int) $r->input('days', 28),
                ]);
                return response()->json($res, $res['success'] ? 200 : 409);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'rows_synced' => 0, 'message' => $e->getMessage()], 400);
            }
        });

        // Reads persisted rows (populated by the Phase 2 sync). Until the first
        // sync runs this returns an empty set with connected state — same shape
        // the UI already handles.
        Route::get('/gsc/queries', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $gsc  = app(\App\Engines\SEO\Services\GscClient::class);
            $connected = $gsc->isConnected($wsId);
            if (! $connected) {
                return response()->json([
                    'success' => true, 'connected' => false, 'queries' => [],
                    'message' => 'Google Search Console is not connected for this workspace yet.',
                ]);
            }
            $rows = \Illuminate\Support\Facades\DB::table('gsc_metrics')
                ->where('workspace_id', $wsId)
                ->selectRaw('`query`, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
                ->groupBy('query')
                ->orderByDesc('clicks')
                ->limit(200)
                ->get()
                ->map(fn ($x) => [
                    'query'       => $x->query,
                    'clicks'      => (int) $x->clicks,
                    'impressions' => (int) $x->impressions,
                    'ctr'         => $x->impressions > 0 ? round($x->clicks / $x->impressions, 4) : 0,
                    'position'    => round((float) $x->position, 1),
                ]);
            return response()->json(['success' => true, 'connected' => true, 'queries' => $rows]);
        });

        // Comprehensive Search Console report for the visual dashboard
        // (trend, top queries/pages, devices, countries, position buckets).
        Route::get('/gsc/report', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $gsc  = app(\App\Engines\SEO\Services\GscClient::class);
            if (! $gsc->isConnected($wsId)) {
                return response()->json(['success' => true, 'connected' => false, 'report' => null,
                    'message' => 'Connect Google Search Console to see search reports.']);
            }
            try {
                $days = max(1, min((int) $r->query('days', 28), 90));
                return response()->json(['success' => true, 'connected' => true, 'report' => $gsc->report($wsId, $days)]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'connected' => true, 'report' => null, 'message' => $e->getMessage()], 502);
            }
        });

        // GSC INTELLIGENCE (Phase 3) — ranked opportunities. Laravel reads the
        // latest persisted snapshot (+ the previous one for decline detection)
        // and hands the RAW rows to the runtime, which does ALL scoring/ranking
        // (striking-distance, CTR-gap, cannibalization, decline). No analysis
        // happens in Laravel. Degrades gracefully if the runtime is unreachable.
        Route::get('/gsc/insights', function (\Illuminate\Http\Request $r) {
            // 2026-06-12 — logic extracted to GscInsightsService so the SEO
            // Assistant live-context shares the SAME cached runtime analysis.
            // Response shape is unchanged; '_http' carries the 502 status hint.
            $wsId = (int) $r->attributes->get('workspace_id');
            $out  = app(\App\Engines\SEO\Services\GscInsightsService::class)->insights($wsId);
            $status = $out['_http'] ?? 200;
            unset($out['_http']);
            return response()->json($out, $status);
        });

        // ── Google Analytics (GA4) — website-visitor reports ──────────────
        // Shares the same Google connection as GSC (same tokens + auth modes).
        // GET /ga/status   → is a GA4 property linked + which one
        // GET /ga/report   → visitors / sessions / top pages / channels /
        //                    devices / countries for the trailing window
        // GET /ga/properties + POST /ga/select-property → manual picker
        Route::get('/ga/status', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $conn = app(\App\Engines\SEO\Services\GscClient::class)->getConnection($wsId);
            $googleConnected = $conn !== null && ! empty($conn->refresh_token_enc);
            $propertySelected = $conn !== null && ! empty($conn->ga_property_id);
            return response()->json([
                'success'          => true,
                'google_connected' => $googleConnected,   // the Google account is linked
                'connected'        => $propertySelected,  // a GA4 property is chosen → ready to report
                'property'         => $conn->ga_property_id ?? null,
                'name'             => $conn->ga_property_name ?? null,
            ]);
        });

        Route::get('/ga/report', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $ga   = app(\App\Engines\SEO\Services\GaClient::class);
            if (! $ga->isConnected($wsId)) {
                return response()->json([
                    'success' => true, 'connected' => false, 'report' => null,
                    'message' => 'Connect Google to see website-visitor analytics.',
                ]);
            }
            try {
                $days = max(1, min((int) $r->query('days', 28), 90));
                return response()->json(['success' => true, 'connected' => true, 'report' => $ga->visitorReport($wsId, $days)]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'connected' => true, 'report' => null, 'message' => $e->getMessage()], 502);
            }
        });

        Route::get('/ga/properties', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            try {
                $s = app(\App\Engines\SEO\Services\GaClient::class)->scopedProperties($wsId);
                return response()->json([
                    'success'          => true,
                    'properties'       => $s['matched'],   // only those tracking THIS workspace's site
                    'all'              => $s['all'],        // every property (manual override)
                    'matched'          => ! empty($s['matched']),
                    'workspace_domain' => $s['workspace_domain'],
                ]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'properties' => [], 'all' => [], 'message' => $e->getMessage()], 400);
            }
        });

        Route::post('/ga/select-property', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $pid  = trim((string) $r->input('property_id', ''));
            if ($pid === '') {
                return response()->json(['success' => false, 'message' => 'Choose a property.'], 422);
            }
            app(\App\Engines\SEO\Services\GaClient::class)->setProperty($wsId, $pid, (string) $r->input('name', ''));
            return response()->json(['success' => true, 'property' => str_replace('properties/', '', $pid)]);
        });

        // ── Website tracking (GA4 tag on the published site) ──────────────
        // Reading GA data (above) is OAuth. COLLECTING data needs the GA4 tag
        // on the site's pages. The platform auto-detects the Measurement ID
        // from the connected property so the user rarely types anything; a
        // manual field is the fallback. Installing = website.seo_json.ga4_id,
        // which BuilderRenderer injects into every page's <head>.
        Route::get('/ga/tracking', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $website = \Illuminate\Support\Facades\DB::table('websites')
                ->where('workspace_id', $wsId)->whereNull('deleted_at')
                ->orderByRaw("status = 'published' desc")->orderByDesc('id')->first();
            $installed = null;
            if ($website) {
                $seo = json_decode($website->seo_json ?: '{}', true) ?: [];
                $installed = $seo['ga4_id'] ?? null;
            }
            $detected = null;
            try { $detected = app(\App\Engines\SEO\Services\GaClient::class)->detectedMeasurementId($wsId); } catch (\Throwable $e) {}
            return response()->json([
                'success'   => true,
                'has_site'  => (bool) $website,
                'domain'    => $website ? ($website->custom_domain ?: $website->domain) : null,
                'installed' => $installed,
                'detected'  => $detected,
            ]);
        });

        Route::post('/ga/tracking', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $id   = strtoupper(trim((string) $r->input('measurement_id', '')));
            if (! preg_match('/^G-[A-Z0-9]{6,}$/', $id)) {
                return response()->json(['success' => false, 'message' => 'Enter a valid Measurement ID — it looks like G-XXXXXXXXXX.'], 422);
            }
            $website = \Illuminate\Support\Facades\DB::table('websites')
                ->where('workspace_id', $wsId)->whereNull('deleted_at')
                ->orderByRaw("status = 'published' desc")->orderByDesc('id')->first();
            if (! $website) {
                return response()->json(['success' => false, 'message' => 'No website is set up for this workspace yet.'], 404);
            }
            $seo = json_decode($website->seo_json ?: '{}', true) ?: [];
            $seo['ga4_id'] = $id;
            \Illuminate\Support\Facades\DB::table('websites')->where('id', $website->id)
                ->update(['seo_json' => json_encode($seo), 'updated_at' => now()]);
            return response()->json(['success' => true, 'installed' => $id]);
        });

        // Link graph — partial. Build is async/heavy, unlinked-mentions is content-scan.
        // Wave 71 — real implementation. Scans articles + builder pages in the
        // workspace for unlinked mentions of the target's focus_keyword.
        Route::get('/link-graph/unlinked-mentions', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            if (!$user) return response()->json(['success' => false, 'mentions' => [], 'error' => 'unauthenticated'], 401);
            $wsId = (int) ($user->workspace_id ?? 0);
            if ($wsId <= 0) return response()->json(['success' => true, 'mentions' => [], 'count' => 0]);

            $rawKeyword = trim((string) $r->query('keyword', ''));
            $targetUrl  = trim((string) $r->query('target_url', ''));

            // Resolve target article (if the URL points to one) to extract focus_keyword.
            $focusKeyword = null;
            $targetTitle  = null;
            $targetSlug   = null;
            if ($targetUrl !== '') {
                $tgtPath = parse_url($targetUrl, PHP_URL_PATH) ?: '';
                if (preg_match('#/blog/([^/]+)/?$#i', $tgtPath, $sm)) {
                    $targetSlug = $sm[1];
                    $tgtArticle = \Illuminate\Support\Facades\DB::table('articles')
                        ->where('workspace_id', $wsId)
                        ->where('slug', $targetSlug)
                        ->whereNull('deleted_at')
                        ->first(['title', 'focus_keyword']);
                    if ($tgtArticle) {
                        $focusKeyword = $tgtArticle->focus_keyword;
                        $targetTitle  = $tgtArticle->title;
                    }
                }
            }

            // Build a prioritized list of search terms.
            $stopwords = ['the','a','an','and','or','but','of','for','to','in','on','at','by','with','what','does','is','are','how','your','my','i','it','this','that'];
            $terms = [];
            if ($focusKeyword) {
                $terms[] = trim($focusKeyword);
                // Also try the longest 2-word substring of the focus keyword.
                $fkWords = preg_split('/\s+/', strtolower(trim($focusKeyword))) ?: [];
                $fkWords = array_values(array_filter($fkWords, function ($w) use ($stopwords) {
                    return strlen($w) > 2 && !in_array($w, $stopwords, true);
                }));
                for ($i = 0; $i < count($fkWords) - 1; $i++) {
                    $terms[] = $fkWords[$i] . ' ' . $fkWords[$i+1];
                }
            }
            if ($rawKeyword !== '') {
                // Derive 2-word phrases from the title-style keyword too.
                $kwClean = preg_replace('/[\?\!\.,;:|\-]/', ' ', $rawKeyword);
                $kwWords = preg_split('/\s+/', strtolower(trim($kwClean))) ?: [];
                $kwWords = array_values(array_filter($kwWords, function ($w) use ($stopwords) {
                    return strlen($w) > 2 && !in_array($w, $stopwords, true);
                }));
                for ($i = 0; $i < count($kwWords) - 1; $i++) {
                    $terms[] = $kwWords[$i] . ' ' . $kwWords[$i+1];
                }
                // Also try the single most significant word as a last resort.
                if (count($kwWords) >= 1) $terms[] = $kwWords[0];
            }
            $terms = array_values(array_unique(array_filter($terms)));
            if (empty($terms)) {
                return response()->json(['success' => true, 'mentions' => [], 'count' => 0, 'reason' => 'no_keyword']);
            }

            // Pages already linking to the target — exclude.
            $alreadyLinking = [];
            if ($targetUrl !== '') {
                $alreadyLinking = \Illuminate\Support\Facades\DB::table('seo_link_graph')
                    ->where('workspace_id', $wsId)
                    ->where('target_url', $targetUrl)
                    ->pluck('source_url')->all();
            }
            $excludeUrls = array_map('strtolower', $alreadyLinking);
            if ($targetUrl !== '') $excludeUrls[] = strtolower($targetUrl);

            // Scan published articles in workspace for body mentions.
            $articles = \Illuminate\Support\Facades\DB::table('articles')
                ->where('workspace_id', $wsId)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->when($targetSlug, function ($q) use ($targetSlug) { return $q->where('slug', '!=', $targetSlug); })
                ->get(['id', 'title', 'slug', 'content']);

            $mentions = [];
            foreach ($articles as $a) {
                $bodyText = strip_tags((string) $a->content);
                $bodyLower = strtolower($bodyText);
                // Already-link check via raw href scan on the source's body.
                $sourceUrl = null;
                if ($a->slug) {
                    // Build the source URL using the same host pattern as the target.
                    $host = $targetUrl ? (parse_url($targetUrl, PHP_URL_HOST) ?: '') : '';
                    if ($host) $sourceUrl = 'https://' . $host . '/blog/' . $a->slug;
                }
                if ($sourceUrl && in_array(strtolower($sourceUrl), $excludeUrls, true)) continue;
                if ($targetUrl !== '' && stripos((string) $a->content, $targetUrl) !== false) continue;
                if ($targetSlug && stripos((string) $a->content, '/blog/' . $targetSlug) !== false) continue;

                foreach ($terms as $term) {
                    $termLower = strtolower($term);
                    $pos = strpos($bodyLower, $termLower);
                    if ($pos !== false) {
                        $start = max(0, $pos - 50);
                        $snippet = substr($bodyText, $start, 160);
                        if ($start > 0) $snippet = '…' . $snippet;
                        if (strlen($bodyText) > $start + 160) $snippet .= '…';
                        $mentions[] = [
                            'source_url'   => $sourceUrl,
                            'url'          => $sourceUrl,
                            'source_title' => $a->title,
                            'title'        => $a->title,
                            'matched'      => $term,
                            'snippet'      => $snippet,
                        ];
                        break; // one match per article is enough
                    }
                }
                if (count($mentions) >= 20) break;
            }

            // Wave 72 — snapshot persistence. Upsert every live match into
            // seo_unlinked_mention_snapshots so prior analysis survives
            // between clicks. If the live scan found 0, fall back to the
            // snapshot table so the user always sees something.
            $tgtHash = $targetUrl !== '' ? hash('sha256', strtolower($targetUrl)) : null;
            if ($tgtHash) {
                // Prune entries that have since become real links.
                if (!empty($alreadyLinking)) {
                    $linkingHashes = array_map(function ($u) { return hash('sha256', strtolower($u)); }, $alreadyLinking);
                    \Illuminate\Support\Facades\DB::table('seo_unlinked_mention_snapshots')
                        ->where('workspace_id', $wsId)
                        ->where('target_url_hash', $tgtHash)
                        ->whereIn('source_url_hash', $linkingHashes)
                        ->delete();
                }
                // Upsert live matches.
                $now = now();
                foreach ($mentions as $m) {
                    if (empty($m['source_url'])) continue;
                    $srcHash = hash('sha256', strtolower($m['source_url']));
                    try {
                        \Illuminate\Support\Facades\DB::table('seo_unlinked_mention_snapshots')->updateOrInsert(
                            ['workspace_id' => $wsId, 'target_url_hash' => $tgtHash, 'source_url_hash' => $srcHash],
                            [
                                'target_url'   => $targetUrl,
                                'source_url'   => $m['source_url'],
                                'source_title' => $m['source_title'] ?? null,
                                'matched_term' => $m['matched'] ?? null,
                                'snippet'      => $m['snippet'] ?? null,
                                'scanned_at'   => $now,
                                'updated_at'   => $now,
                                'created_at'   => $now,
                            ]
                        );
                    } catch (\Throwable $ue) {
                        // swallow — snapshot persistence must never block the live response
                    }
                }
            }

            $isCached = false;
            if (empty($mentions) && $tgtHash) {
                $prior = \Illuminate\Support\Facades\DB::table('seo_unlinked_mention_snapshots')
                    ->where('workspace_id', $wsId)
                    ->where('target_url_hash', $tgtHash)
                    ->orderByDesc('scanned_at')
                    ->limit(20)
                    ->get();
                if ($prior->count() > 0) {
                    $isCached = true;
                    foreach ($prior as $row) {
                        $mentions[] = [
                            'source_url'   => $row->source_url,
                            'url'          => $row->source_url,
                            'source_title' => $row->source_title,
                            'title'        => $row->source_title,
                            'matched'      => $row->matched_term,
                            'snippet'      => $row->snippet,
                            'scanned_at'   => $row->scanned_at,
                        ];
                    }
                }
            }

            return response()->json([
                'success'  => true,
                'mentions' => $mentions,
                'count'    => count($mentions),
                'cached'   => $isCached,
                'terms'    => $terms,
                'target'   => ['url' => $targetUrl, 'title' => $targetTitle, 'focus_keyword' => $focusKeyword],
            ]);
        });
        Route::post('/link-graph/build', function (\Illuminate\Http\Request $r) {
            return response()->json([
                'success'        => true,
                'queued'         => false,
                'feature_status' => 'coming_soon',
                'message'        => 'Link-graph rebuild runs automatically nightly. On-demand rebuild ships in a future update.',
            ]);
        });

        // Links — gap detection vs link suggestions
        // Wave 16f (2026-05-19) — Gaps sub-tab data source.
        // Was a feature_status=coming_soon stub returning {gaps:[]}. The
        // Anchors/Gaps/Internal-Links UIs all run off seo_content_index +
        // seo_link_graph which ARE populated, so we can answer with real
        // data now. Replaces the Wave 13 placeholder.
        //
        // Shape matches what lgseLoadGaps + lgseLoadGapsData expect:
        //   { orphans:[{url,title,word_count,content_score}],
        //     weak:[{...same... + inbound_count}],
        //     summary:{orphan_count,weak_count} }
        Route::get('/links/gaps', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $like = $host !== '' ? ('%//' . $host . '%') : null;

            $base = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId);
            if ($like) { $base->where('url', 'like', $like); }

            $orphans = (clone $base)
                ->where('inbound_links', 0)
                ->where('word_count', '>', 100)
                ->orderByDesc('content_score')
                ->limit(100)
                ->get(['url', 'title', 'word_count', 'content_score'])
                ->toArray();

            $weakRows = (clone $base)
                ->whereBetween('inbound_links', [1, 2])
                ->orderBy('inbound_links')
                ->orderByDesc('content_score')
                ->limit(100)
                ->get(['url', 'title', 'word_count', 'content_score', 'inbound_links'])
                ->map(function ($r) {
                    $row = (array) $r;
                    $row['inbound_count'] = (int) ($row['inbound_links'] ?? 0);
                    unset($row['inbound_links']);
                    return (object) $row;
                })
                ->toArray();

            $orphanCount = (clone $base)->where('inbound_links', 0)->where('word_count', '>', 100)->count();
            $weakCount   = (clone $base)->whereBetween('inbound_links', [1, 2])->count();

            return response()->json([
                'success' => true,
                'orphans' => $orphans,
                'weak'    => $weakRows,
                'summary' => [
                    'orphan_count' => $orphanCount,
                    'weak_count'   => $weakCount,
                ],
            ]);
        });

        // Anchors — intent-aware suggestion
        Route::post('/anchors/suggest-intent', function (\Illuminate\Http\Request $r) {
            return response()->json([
                'success'        => false,
                'error'          => 'feature_coming_soon',
                'message'        => 'Intent-aware anchor suggestions are on the roadmap. Use the Anchors tab to see current anchor distribution.',
            ], 200);
        });

        // Wave 19 (2026-05-19) — Competitors tab endpoints.
        // Was three Wave 13 coming-soon stubs; the frontend interpreted the
        // empty payload as "DataForSEO not configured" even though it is
        // configured. Now wires through SeoService::serpAnalysis (which uses
        // DataForSeoConnector) and uses the runtime for AI-driven gap detection.

        // Map common country strings to DataForSEO location codes.
        $locationFromString = function (?string $loc): int {
            $loc = strtolower(trim((string) $loc));
            $map = [
                ''                       => \App\Connectors\DataForSeoConnector::LOCATION_USA,
                'united states'          => \App\Connectors\DataForSeoConnector::LOCATION_USA,
                'usa'                    => \App\Connectors\DataForSeoConnector::LOCATION_USA,
                'us'                     => \App\Connectors\DataForSeoConnector::LOCATION_USA,
                'united kingdom'         => \App\Connectors\DataForSeoConnector::LOCATION_UK,
                'uk'                     => \App\Connectors\DataForSeoConnector::LOCATION_UK,
                'united arab emirates'   => \App\Connectors\DataForSeoConnector::LOCATION_UAE,
                'uae'                    => \App\Connectors\DataForSeoConnector::LOCATION_UAE,
            ];
            return $map[$loc] ?? \App\Connectors\DataForSeoConnector::LOCATION_USA;
        };

        // POST /api/seo/competitors/analyze
        // Body: { keyword: string, location_code?: int, location?: string }
        // Wave 19.1 — accepts the DataForSEO location_code directly from
        // the frontend (cleaner than maintaining a name→code map). Falls
        // back to the legacy `location` string for older clients.
        Route::post('/competitors/analyze', function (\Illuminate\Http\Request $r) use ($locationFromString) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $credits = app(\App\Core\Billing\CreditService::class);
            $cost = 1;
            if (!$credits->hasBalance($wsId, $cost)) {
                return response()->json(['success' => false, 'error' => "Not enough credits — competitor analysis costs {$cost} credit.", 'required_credits' => $cost], 402);
            }
            $credits->debit($wsId, $cost, 'seo/competitor_serp');
            $wsId = (int) $r->attributes->get('workspace_id');
            $data = $r->validate([
                'keyword'       => 'required|string|max:200',
                'location'      => 'nullable|string|max:80',
                'location_code' => 'nullable|integer|min:1000|max:99999',
            ]);

            $locationCode = ! empty($data['location_code'])
                ? (int) $data['location_code']
                : $locationFromString($data['location'] ?? '');

            // Re-use SeoService::serpAnalysis which already handles DataForSEO,
            // graceful fallback, and persistence into seo_serp_results.
            $svc = app(\App\Engines\SEO\Services\SeoService::class);
            $result = $svc->serpAnalysis($wsId, [
                'keyword'       => $data['keyword'],
                'location_code' => $locationCode,
            ]);

            // If service-level fallback hit (DataForSEO unavailable), try DB cache.
            $top = $result['top_competitors'] ?? [];
            if (empty($top)) {
                $top = \Illuminate\Support\Facades\DB::table('seo_serp_results')
                    ->where('workspace_id', $wsId)
                    ->whereRaw('LOWER(keyword) = LOWER(?)', [$data['keyword']])
                    ->whereNotNull('domain')->where('domain', '!=', '')
                    ->orderBy('position')->limit(10)
                    ->get(['position AS rank', 'title', 'domain', 'url', 'snippet'])
                    ->map(fn ($r) => (array) $r)->toArray();
            }

            // Normalize + dedupe by (rank, domain) — the DB fallback can
            // surface duplicate rows from accumulated SERP-history inserts.
            $seen = [];
            $competitors = [];
            foreach ($top as $i => $c) {
                $c = (array) $c;
                $rank = (int) ($c['rank'] ?? $c['position'] ?? ($i + 1));
                $domain = strtolower((string) ($c['domain'] ?? ''));
                $key = $rank . '|' . $domain;
                if ($domain === '' || isset($seen[$key])) continue;
                $seen[$key] = true;
                $competitors[] = [
                    'rank'           => $rank,
                    'title'          => (string) ($c['title'] ?? ''),
                    'domain'         => $domain,
                    'url'            => (string) ($c['url'] ?? ''),
                    'snippet'        => (string) ($c['snippet'] ?? ''),
                    'est_word_count' => (int)    ($c['est_word_count'] ?? 0),
                ];
            }
            usort($competitors, fn ($a, $b) => $a['rank'] <=> $b['rank']);

            return response()->json([
                'success'         => true,
                'competitors'     => $competitors,
                'keyword'         => $data['keyword'],
                'location_code'   => $locationCode,
                'estimated_volume'=> $result['estimated_volume'] ?? null,
                'difficulty'      => $result['difficulty'] ?? null,
                'source'          => $result['source'] ?? 'dataforseo',
                'message'         => empty($competitors)
                    ? 'No SERP data available right now — our SEO data provider may be temporarily unavailable, or this keyword has no public results.'
                    : null,
            ]);
        });

        // POST /api/seo/competitors/gaps
        // Body: { keyword: string }
        // Uses the runtime (DeepSeek) to identify content topics that
        // appear in top-10 competitor titles/snippets but are missing from
        // the user's own ranking content.
        Route::post('/competitors/gaps', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $credits = app(\App\Core\Billing\CreditService::class);
            $cost = 3;
            if (!$credits->hasBalance($wsId, $cost)) {
                return response()->json(['success' => false, 'error' => "Not enough credits — AI gap analysis costs {$cost} credits.", 'required_credits' => $cost], 402);
            }
            $credits->debit($wsId, $cost, 'seo/competitor_gaps');
            $wsId = (int) $r->attributes->get('workspace_id');
            $data = $r->validate(['keyword' => 'required|string|max:200']);
            $keyword = $data['keyword'];

            // Pull the latest SERP for this keyword from cache.
            $comps = \Illuminate\Support\Facades\DB::table('seo_serp_results')
                ->where('workspace_id', $wsId)
                ->whereRaw('LOWER(keyword) = LOWER(?)', [$keyword])
                ->whereNotNull('domain')->where('domain', '!=', '')
                ->orderBy('position')->limit(10)
                ->get(['position', 'title', 'domain', 'snippet']);

            if ($comps->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'gaps'    => [],
                    'message' => 'No SERP cache for this keyword — run Analyze first to populate.',
                ]);
            }

            // Build runtime prompt: titles + snippets, ask for topics found
            // in N≥3 competitors that the user should also cover.
            $compList = $comps->map(function ($c) {
                return '- #' . $c->position . ' [' . $c->domain . '] '
                    . ($c->title ?: '(no title)')
                    . ($c->snippet ? ' — ' . mb_substr($c->snippet, 0, 140) : '');
            })->implode("\n");

            $prompt = "You are an SEO content strategist. Below are the top-10 Google results for the keyword \"" . $keyword . "\":\n\n"
                . $compList . "\n\n"
                . "Identify 5-8 CONTENT TOPICS or SUB-TOPICS that appear repeatedly in competitor titles/snippets and represent content gaps a new article should cover. "
                . "Return ONLY a JSON array, no prose, no markdown fences. Each item: {topic, found_in_n_competitors, priority (high/medium/low), suggested_heading}. "
                . "Priority is based on how many competitors mention the topic (≥6: high, 4-5: medium, 3: low). "
                . "Example: [{\"topic\":\"Pricing comparison\",\"found_in_n_competitors\":7,\"priority\":\"high\",\"suggested_heading\":\"How much does X cost in 2026?\"}]";

            $gaps = [];
            try {
                $runtime = app(\App\Connectors\RuntimeClient::class);
                $resp = $runtime->aiRun('seo_content_generation', $prompt, ['workspace_id' => $wsId], 60);
                $text = trim((string) ($resp['text'] ?? $resp['response'] ?? ''));
                // Strip Markdown fences if any.
                $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?: $text;
                $parsed = json_decode($text, true);
                if (is_array($parsed)) {
                    foreach ($parsed as $g) {
                        if (! is_array($g) || empty($g['topic'])) continue;
                        $gaps[] = [
                            'topic'                  => (string) $g['topic'],
                            'found_in_n_competitors' => (int) ($g['found_in_n_competitors'] ?? 0),
                            'priority'               => in_array(($g['priority'] ?? ''), ['high','medium','low'], true) ? $g['priority'] : 'medium',
                            'suggested_heading'      => (string) ($g['suggested_heading'] ?? $g['topic']),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[/competitors/gaps] runtime gap detection failed', [
                    'workspace_id' => $wsId, 'keyword' => $keyword, 'err' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'gaps'    => $gaps,
                'keyword' => $keyword,
                'message' => empty($gaps)
                    ? 'AI runtime returned no parseable gaps. Try a more specific keyword or re-run Analyze.'
                    : null,
            ]);
        });

        // POST /api/seo/competitors/compare
        // Body: { your_url: string, keyword: string }
        // Compares the user's page to the top-10 SERP for that keyword.
        Route::post('/competitors/compare', function (\Illuminate\Http\Request $r) use ($locationFromString) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $credits = app(\App\Core\Billing\CreditService::class);
            $cost = 1;
            if (!$credits->hasBalance($wsId, $cost)) {
                return response()->json(['success' => false, 'error' => "Not enough credits — competitor compare costs {$cost} credit.", 'required_credits' => $cost], 402);
            }
            $credits->debit($wsId, $cost, 'seo/competitor_serp');
            $wsId = (int) $r->attributes->get('workspace_id');
            $data = $r->validate([
                'your_url' => 'required|string|max:500',
                'keyword'  => 'required|string|max:200',
                'location' => 'nullable|string|max:80',
            ]);

            // Fetch user's page from seo_content_index (or zero stats if absent).
            $yourHost = strtolower((string) parse_url($data['your_url'], PHP_URL_HOST));
            $you = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where(function ($q) use ($data) {
                    $q->where('url', $data['your_url'])
                      ->orWhere('url', rtrim($data['your_url'], '/'));
                })
                ->first(['url', 'title', 'content_score', 'word_count', 'inbound_links', 'meta_description']);

            // Pull top-10 competitor SERP from cache (or trigger fresh analyze).
            $comps = \Illuminate\Support\Facades\DB::table('seo_serp_results')
                ->where('workspace_id', $wsId)
                ->whereRaw('LOWER(keyword) = LOWER(?)', [$data['keyword']])
                ->whereNotNull('domain')->where('domain', '!=', '')
                ->orderBy('position')->limit(10)
                ->get(['position', 'title', 'domain', 'url', 'snippet']);

            $youInTop10 = false;
            $youPosition = null;
            foreach ($comps as $c) {
                if (strtolower((string) $c->domain) === $yourHost) {
                    $youInTop10 = true;
                    $youPosition = (int) $c->position;
                    break;
                }
            }

            // Computed comparison KPIs
            $compAvgTitleLen = $comps->avg(fn ($c) => mb_strlen($c->title ?? ''));
            $yourTitleLen    = $you ? mb_strlen((string) $you->title) : 0;

            return response()->json([
                'success'   => true,
                'keyword'   => $data['keyword'],
                'your_url'  => $data['your_url'],
                'your'      => [
                    'in_top_10'        => $youInTop10,
                    'position'         => $youPosition,
                    'indexed'          => (bool) $you,
                    'title'            => $you->title ?? null,
                    'word_count'       => $you->word_count ?? null,
                    'content_score'    => $you->content_score ?? null,
                    'inbound_links'    => $you->inbound_links ?? null,
                    'title_length'     => $yourTitleLen,
                    'meta_description' => $you->meta_description ?? null,
                ],
                'competitors' => $comps->map(fn ($c) => [
                    'rank'   => (int) $c->position,
                    'title'  => (string) $c->title,
                    'domain' => (string) $c->domain,
                    'url'    => (string) $c->url,
                ])->toArray(),
                'benchmarks' => [
                    'competitor_count' => $comps->count(),
                    'avg_title_length' => (int) round((float) $compAvgTitleLen),
                ],
                'message' => $comps->isEmpty()
                    ? 'No SERP cache. Run Analyze on this keyword first.'
                    : ($youInTop10
                        ? "You're already ranking at position #{$youPosition}."
                        : 'You are not in the top 10 for this keyword.'),
            ]);
        });

        // Equity calculator — page-equity scoring
        Route::post('/equity/calculate', function (\Illuminate\Http\Request $r) {
            return response()->json([
                'success'        => true,
                'equity'         => null,
                'feature_status' => 'derived_from_authority',
                'message'        => 'Page equity is currently shown via the authority_score column on indexed_content. Standalone recalculation ships in a future update.',
            ]);
        });

        // Outbound scan — aliases for the existing /outbound/check endpoint
        Route::post('/scan-outbound', [$c, 'checkOutbound']);

        // Frontend<->backend alignment: seo.js re-scans a single outbound link
        // (/scan-outbound/{id}). Re-check that one seo_outbound_links row via HTTP HEAD.
        // SSRF-guarded: only public http(s) hosts (no localhost/private ranges).
        Route::post('/scan-outbound/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = $r->attributes->get('workspace_id');
            $link = \Illuminate\Support\Facades\DB::table('seo_outbound_links')
                ->where('id', (int) $id)->where('workspace_id', $wsId)->first();
            if (! $link) { return response()->json(['success' => false, 'error' => 'Link not found'], 404); }
            $url = (string) ($link->target_url ?? '');
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
            $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
            $blocked = $host === '' || ! in_array($scheme, ['http', 'https'], true)
                || $host === 'localhost' || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.|::1|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host);
            if ($blocked) { return response()->json(['success' => false, 'error' => 'URL not scannable'], 422); }
            $status = 'broken'; $code = 0;
            try {
                $resp = \Illuminate\Support\Facades\Http::timeout(5)->head($url);
                $code = $resp->status();
                $status = ($code >= 200 && $code < 400) ? 'ok' : 'broken';
            } catch (\Throwable $e) { $status = 'broken'; $code = 0; }
            \Illuminate\Support\Facades\DB::table('seo_outbound_links')
                ->where('id', (int) $id)->where('workspace_id', $wsId)
                ->update(['status' => $status, 'http_status' => $code, 'last_checked_at' => now(), 'updated_at' => now()]);
            return response()->json(['success' => true, 'status' => $status, 'http_status' => $code]);
        });

        // NOTE: /connector/save-meta (PATCH) is a separate-prefix route and
        // can't be aliased from inside this seo group. UI fallback already
        // exists — most callers retry the bare /save-meta endpoint which
        // works. Tracked separately for future plugin endpoint cleanup.

        // Wave 1 (2026-05-17) — Assistant disclaimer acceptance.
        // The SEO Assistant won't process any message until the current
        // user has accepted the 90-day chat retention disclaimer. UI POSTs
        // here after the user clicks "Accept" in the disclaimer modal.
        Route::post('/assistant/accept-disclaimer', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            if (! $user) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }
            \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $user->id)
                ->whereNull('seo_assistant_disclaimer_accepted_at')
                ->update(['seo_assistant_disclaimer_accepted_at' => now()]);
            return response()->json([
                'success'      => true,
                'accepted_at'  => now()->toISOString(),
            ]);
        });

        // Wave 1 (2026-05-17) — Read disclaimer status for the current user.
        // UI may pre-check this on session start to decide whether to
        // show the modal up front instead of waiting for the first message.
        Route::get('/assistant/disclaimer-status', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            if (! $user) {
                return response()->json(['accepted' => false], 200);
            }
            $accepted = \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $user->id)
                ->value('seo_assistant_disclaimer_accepted_at');
            return response()->json([
                'accepted'       => $accepted !== null,
                'accepted_at'    => $accepted,
                'disclaimer'     => \App\Engines\SEO\Services\SeoAssistantService::DISCLAIMER_TEXT,
                'retention_days' => \App\Engines\SEO\Services\SeoAssistantService::DB_RETENTION_DAYS,
            ]);
        });

        // Wave 1 (2026-05-17) — Paginated chat history from DB (90 days).
        // The existing Redis history is 24h-TTL; this is the durable log.
        Route::get('/assistant/history', function (\Illuminate\Http\Request $r) {
            $wsId  = (int) $r->attributes->get('workspace_id');
            $limit = min(200, max(10, (int) $r->query('limit', 50)));
            $rows = \Illuminate\Support\Facades\DB::table('seo_assistant_messages')
                ->where('workspace_id', $wsId)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id', 'role', 'content', 'action_proposed_json', 'created_at']);
            return response()->json([
                'success'  => true,
                'messages' => $rows->reverse()->values(),  // chrono order
                'count'    => $rows->count(),
            ]);
        });

        // ── DEPRECATED Wave 4 routes — removed in Wave 7 (2026-05-18) ──
        // Routes /assistant/notifications/* were a parallel SEO-specific
        // notification surface built before discovering the platform
        // already had /messages/unread-count + agent_messages table.
        // Wave 5 refactored every writer to use AgentMessageService +
        // NotificationService::dispatch — these routes had no UI
        // consumer and have been removed.
        //
        // The seo_assistant_notifications table is retained for data
        // preservation (rows can still be queried directly if needed)
        // and can be dropped in a future cleanup migration.

        // Redirects management (DB-backed)
        Route::get("/redirects", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $redirects = \Illuminate\Support\Facades\DB::table('seo_redirects')
                ->where('workspace_id', $wsId)
                ->orderByDesc('created_at')
                ->get();
            return response()->json(["redirects" => $redirects]);
        });
        Route::post("/redirects", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $id = \Illuminate\Support\Facades\DB::table('seo_redirects')->insertGetId([
                'workspace_id' => $wsId,
                'source_url'   => $r->input('from', $r->input('source_url', '')),
                'target_url'   => $r->input('to', $r->input('target_url', '')),
                'type'         => $r->input('type', '301'),
                'is_regex'     => $r->boolean('is_regex', false),
                'status'       => 'active',
                'hit_count'    => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            return response()->json(["id" => $id, "created" => true]);
        });
        Route::delete("/redirects/{id}", function ($id, \Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $deleted = \Illuminate\Support\Facades\DB::table('seo_redirects')
                ->where('id', $id)
                ->where('workspace_id', $wsId)
                ->delete();
            return response()->json(["deleted" => $deleted > 0, "id" => $id]);
        });

        // Frontend<->backend alignment: seo.js edits a redirect (PATCH /redirects/{id},
        // was "NOT implemented yet"). Tenancy-scoped update of source/target/type.
        Route::patch('/redirects/{id}', function ($id, \Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $data = array_filter([
                'source_url' => $r->input('from_url', $r->input('from', $r->input('source_url'))),
                'target_url' => $r->input('to_url', $r->input('to', $r->input('target_url'))),
                'type'       => $r->input('type'),
            ], function ($v) { return $v !== null && $v !== ''; });
            if (empty($data)) { return response()->json(['success' => false, 'error' => 'No fields to update'], 422); }
            $data['updated_at'] = now();
            $updated = \Illuminate\Support\Facades\DB::table('seo_redirects')
                ->where('id', (int) $id)->where('workspace_id', $wsId)->update($data);
            return response()->json(['success' => $updated > 0, 'updated' => (int) $updated, 'id' => $id]);
        });
        Route::get("/404-log", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $entries = \Illuminate\Support\Facades\DB::table('seo_404_log')
                ->where('workspace_id', $wsId)
                ->orderByDesc('last_hit_at')
                ->limit(200)
                ->get();
            return response()->json(["entries" => $entries]);
        });

        // Frontend<->backend alignment: seo.js has a delete-404 button (was "NOT
        // implemented yet"). Tenancy-scoped delete of one 404-log entry.
        Route::delete('/404-log/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = $r->attributes->get('workspace_id');
            $deleted = \Illuminate\Support\Facades\DB::table('seo_404_log')
                ->where('id', (int) $id)->where('workspace_id', $wsId)->delete();
            return response()->json(['success' => $deleted > 0, 'deleted' => (int) $deleted]);
        });

        // Frontend<->backend alignment: seo.js "Purge all 404 logs" (DELETE /404-log,
        // was "NOT wired"). Tenancy-scoped bulk delete.
        Route::delete('/404-log', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $deleted = \Illuminate\Support\Facades\DB::table('seo_404_log')->where('workspace_id', $wsId)->delete();
            return response()->json(['success' => true, 'deleted' => (int) $deleted]);
        });

        // SEO Settings (DB-backed)
        Route::get("/settings", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)
                ->get();
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row->key] = $row->value;
            }
            if (empty($settings)) {
                $settings = [
                    'title_template' => '{page_title} | {site_name}',
                    'meta_description_fallback' => '',
                    'sitemap_enabled' => 'true',
                ];
            }
            return response()->json($settings);
        });
        Route::post("/settings", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $settings = $r->except(['_token']);
            $group = $r->input('_group', 'general');
            unset($settings['_group']);
            foreach ($settings as $key => $value) {
                \Illuminate\Support\Facades\DB::table('seo_settings')->updateOrInsert(
                    ['workspace_id' => $wsId, 'key' => $key],
                    ['value' => is_array($value) ? json_encode($value) : (string) $value, 'group' => $group, 'updated_at' => now(), 'created_at' => now()]
                );
            }
            return response()->json(["saved" => true]);
        });

        // Score weights (DB-backed)
        Route::get("/score-weights", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('seo_score_weights')
                ->where('workspace_id', $wsId)
                ->get();
            $weights = [];
            foreach ($rows as $row) {
                $weights[$row->factor] = $row->weight;
            }
            if (empty($weights)) {
                $defaults = ['title' => 20, 'meta_description' => 15, 'content_length' => 25, 'internal_links' => 15, 'image_alt' => 10, 'readability' => 15];
                foreach ($defaults as $factor => $weight) {
                    \Illuminate\Support\Facades\DB::table('seo_score_weights')->insert([
                        'workspace_id' => $wsId,
                        'factor' => $factor,
                        'weight' => $weight,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $weights = $defaults;
            }
            return response()->json($weights);
        });
        Route::post("/score-weights", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $weights = $r->except(['_token']);
            foreach ($weights as $factor => $weight) {
                \Illuminate\Support\Facades\DB::table('seo_score_weights')->updateOrInsert(
                    ['workspace_id' => $wsId, 'factor' => $factor],
                    ['weight' => (int) $weight, 'updated_at' => now(), 'created_at' => now()]
                );
            }
            return response()->json(["saved" => true]);
        });

        // ─── 2026-05-14 Phase 2 — anchor intelligence, link equity, AI cache ──

        Route::get('/anchors', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');

            // 2026-05-13 — auto-backfill from seo_link_graph when empty.
            // seo_anchor_analysis is populated on demand by bulk-analyze; if
            // the workspace has 220+ link_graph rows but no anchor_analysis
            // rows yet, the UI shows "0 anchors" forever. Triggering the
            // analyser inline on first read seeds the table without needing
            // a separate cron or button click.
            $count = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')
                ->where('workspace_id', $wsId)->count();
            if ($count === 0) {
                $hasGraph = \Illuminate\Support\Facades\DB::table('seo_link_graph')
                    ->where('workspace_id', $wsId)->where('is_internal', true)
                    ->limit(1)->exists();
                if ($hasGraph) {
                    try {
                        // Backfill — call computeLinkEquity which feeds the analyser.
                        $svc = app(\App\Engines\SEO\Services\SeoService::class);
                        $targetUrls = \Illuminate\Support\Facades\DB::table('seo_link_graph')
                            ->where('workspace_id', $wsId)->where('is_internal', true)
                            ->distinct()->pluck('target_url')->take(30)->toArray();
                        foreach ($targetUrls as $u) {
                            try { $svc->analyzeAnchors($wsId, $u); } catch (\Throwable) { /* skip */ }
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('anchors auto-backfill failed', [
                            'workspace_id' => $wsId, 'err' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Wave 16e (2026-05-19) — flatten per-target-page aggregates into
            // per-anchor rows + compute global distribution, the shape the
            // Anchors sub-tab JS actually expects (r.anchors + r.distribution).
            // Was returning {pages:[...]} only, which the JS ignored — hence
            // the "no data" empty state even though seo_anchor_analysis has rows.
            $rowsQ = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')
                ->where('workspace_id', $wsId);
            if ($host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r)) {
                $rowsQ->where('target_url', 'like', '%//' . $host . '%');
            }
            $pages = $rowsQ
                ->orderByRaw("CASE health WHEN 'poor' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
                ->orderByDesc('total_inbound')
                ->get();

            $genericTerms = ['click here','here','read more','learn more','this','link',
                             'page','website','more','info','details','visit','read'];
            $keywordsLower = \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->pluck('keyword')
                ->map(fn ($k) => strtolower(trim((string) $k)))
                ->filter()
                ->all();

            $anchors = [];
            $totals = ['total' => 0, 'generic' => 0, 'exact' => 0, 'natural' => 0];

            foreach ($pages as $row) {
                $dist = json_decode($row->anchor_distribution ?? '[]', true) ?: [];
                $articleTitle = trim((string) (parse_url($row->target_url, PHP_URL_PATH) ?: $row->target_url), '/');
                if ($articleTitle === '') $articleTitle = $row->target_url;

                foreach ($dist as $entry) {
                    $anchorText = (string) ($entry['anchor'] ?? '');
                    $count = (int) ($entry['count'] ?? 1);
                    if ($anchorText === '') continue;

                    $anchorLower = strtolower(trim($anchorText));

                    $classification = 'descriptive';
                    $kind = 'natural';
                    if (in_array($anchorLower, $genericTerms, true)) {
                        $classification = 'generic';
                        $kind = 'generic';
                    } elseif (! empty($keywordsLower) && in_array($anchorLower, $keywordsLower, true)) {
                        $classification = 'exact_match';
                        $kind = 'exact';
                    } elseif (mb_strlen($anchorText) > 40) {
                        $classification = 'long_phrase';
                        $kind = 'natural';
                    } elseif (! empty($keywordsLower)) {
                        // Partial-match: any tracked keyword token appears inside anchor text.
                        foreach ($keywordsLower as $kw) {
                            if ($kw !== '' && str_contains($anchorLower, $kw)) {
                                $classification = 'partial_match';
                                $kind = 'natural';
                                break;
                            }
                        }
                    }

                    $issueType = null;
                    $issueLabel = null;
                    $fix = null;
                    if ($classification === 'generic') {
                        $issueType  = 'generic';
                        $issueLabel = 'Generic';
                        $fix        = 'Replace with descriptive anchor text matching the target page topic';
                    } elseif ($count > 3) {
                        $issueType  = 'over_optimised';
                        $issueLabel = 'Over-optimised';
                        $fix        = 'Vary anchor text — reused ' . $count . '×';
                    } elseif (mb_strlen($anchorText) > 60) {
                        $issueType  = 'too_long';
                        $issueLabel = 'Too long';
                        $fix        = 'Trim to 2-6 descriptive words';
                    }

                    $anchors[] = [
                        'article_title'  => mb_substr($articleTitle, 0, 80),
                        'anchor_text'    => $anchorText,
                        'target_url'     => $row->target_url,
                        'count'          => $count,
                        'classification' => $classification,
                        'score'          => null,
                        'issue_type'     => $issueType,
                        'issue_label'    => $issueLabel,
                        'fix'            => $fix,
                    ];

                    $totals['total']    += $count;
                    $totals[$kind]      += $count;
                }
            }

            $tot = max(1, $totals['total']);
            $distribution = [
                'total'        => $totals['total'],
                'generic'      => $totals['generic'],
                'exact'        => $totals['exact'],
                'natural'      => $totals['natural'],
                'generic_pct'  => (int) round(100 * $totals['generic'] / $tot),
                'exact_pct'    => (int) round(100 * $totals['exact']   / $tot),
                'natural_pct'  => (int) round(100 * $totals['natural'] / $tot),
            ];

            return response()->json([
                'success'      => true,
                'anchors'      => $anchors,
                'distribution' => $distribution,
                'pages'        => $pages,
                'total'        => count($anchors),
            ]);
        });

        Route::get('/anchors/analyze', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $url  = $r->query('url', '');
            if (!$url) {
                return response()->json(['success' => false, 'error' => 'url_required'], 422);
            }
            $svc = app(\App\Engines\SEO\Services\SeoService::class);
            return response()->json(['success' => true, 'data' => $svc->analyzeAnchors($wsId, $url)]);
        });

        Route::post('/anchors/bulk-analyze', function (\Illuminate\Http\Request $r) {
            $wsId  = $r->attributes->get('workspace_id');
            $pages = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('inbound_links', '>', 0)
                ->limit(30)
                ->pluck('url');
            $svc   = app(\App\Engines\SEO\Services\SeoService::class);
            $count = 0;
            foreach ($pages as $u) {
                try { $svc->analyzeAnchors($wsId, $u); $count++; }
                catch (\Throwable $e) {}
            }
            return response()->json(['success' => true, 'pages_analyzed' => $count]);
        });

        Route::get('/link-equity', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $svc  = app(\App\Engines\SEO\Services\SeoService::class);
            $data = $svc->computeLinkEquity($wsId);
            return response()->json(array_merge(['success' => true], $data));
        });

        Route::delete('/ai-report/cache', function (\Illuminate\Http\Request $r) {
            $wsId    = $r->attributes->get('workspace_id');
            $deleted = \Illuminate\Support\Facades\DB::table('seo_ai_reports')
                ->where('workspace_id', $wsId)->delete();
            return response()->json([
                'success' => true,
                'message' => 'AI report cache cleared',
                'deleted' => $deleted,
            ]);
        });

        // ─── 2026-05-12: Missing /seo/* routes — close the SPA orphan-tab gap.
        // These mirror equivalent /connector/* endpoints so the SEO engine
        // bundle (which calls /api/seo/*) gets HTTP 200 instead of 404.

        // Pages tab
        Route::get('/indexed-content', function (\Illuminate\Http\Request $r) {
            $wsId    = $r->attributes->get('workspace_id');
            $perPage = min(100, max(1, (int) $r->query('per_page', 25)));
            $filter  = $r->query('filter', '');
            $q       = $r->query('q', '');
            $query   = \Illuminate\Support\Facades\DB::table('seo_content_index AS sci')
                ->leftJoin('articles AS a', function ($j) {
                    $j->on('a.wp_post_id', '=', 'sci.wp_post_id')
                      ->on('a.workspace_id', '=', 'sci.workspace_id');
                })
                ->where('sci.workspace_id', $wsId)
                ->select(
                    'sci.*',
                    // Wave 15 (2026-05-18) — fields the Pages-tab CTAs need.
                    'a.featured_image_error',
                    'a.featured_image_alt',
                    'a.featured_image_attempts',
                    'a.id AS article_id'
                );
            if ($filter === 'low_score')    { $query->where('sci.content_score', '<', 50); }
            if ($filter === 'missing_meta') { $query->whereNull('sci.meta_description'); }
            if ($filter === 'thin_content') { $query->where('sci.word_count', '<', 300); }
            if ($filter === 'no_h1')        { $query->whereNull('sci.h1'); }
            if ($filter === 'orphans')      { $query->where('sci.inbound_links', 0)->where('sci.word_count', '>', 100); }
            if ($filter === 'image_failed') { $query->whereNotNull('a.featured_image_error'); }
            if ($q) {
                $query->where(function ($x) use ($q) {
                    $x->where('sci.url', 'like', "%{$q}%")->orWhere('sci.title', 'like', "%{$q}%");
                });
            }
            // Wave 16 — site filter (URL-based until Wave 17 adds site_id FK).
            if ($host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r)) {
                $query->where('sci.url', 'like', '%//' . $host . '%');
            }
            $pages = $query->orderBy('sci.content_score')->paginate($perPage);
            return response()->json([
                'success' => true,
                'items'   => $pages->items(),
                'total'   => $pages->total(),
                'page'    => $pages->currentPage(),
            ]);
        });


        // Lightweight scan progress poll. Returns {state: {...} | null}.
        // Type values: 'pages' | 'images'. Cache TTL 600s.
        Route::get('/scan-status', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $type = (string) $r->query('type', 'pages');
            if (! in_array($type, ['pages', 'images'], true)) { $type = 'pages'; }
            return response()->json([
                'success' => true,
                'state'   => \App\Engines\SEO\Services\ScanProgressService::get($wsId, $type),
            ]);
        });

        // Change 3: PATCH /seo/indexed-content/{id}
        // Accepts featured_image_url + wp_attachment_id.
        // Conditional writes only — null/absent fields never wipe existing values.
        // Fixes pre-existing broken lgseSetFeaturedImage save path (was 404).
        Route::patch('/indexed-content/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = $r->attributes->get('workspace_id');
            $data = $r->validate([
                'featured_image_url' => 'nullable|url',
                'wp_attachment_id'   => 'nullable|integer',
                'meta_title'         => 'nullable|string|max:512',
                'meta_description'   => 'nullable|string',
                'h1'                 => 'nullable|string|max:512',
            ]);
            $update = [];
            if (array_key_exists('featured_image_url', $data) && $data['featured_image_url'] !== null) {
                $update['featured_image_url'] = $data['featured_image_url'];
                $update['has_featured_image'] = 1;
            }
            if (array_key_exists('wp_attachment_id', $data) && $data['wp_attachment_id'] !== null) {
                $update['wp_attachment_id'] = (int) $data['wp_attachment_id'];
            }
            foreach (['meta_title', 'meta_description', 'h1'] as $k) {
                if (array_key_exists($k, $data) && $data[$k] !== null) {
                    $update[$k] = $data[$k];
                }
            }
            if (empty($update)) {
                return response()->json(['success' => false, 'error' => 'nothing_to_update'], 422);
            }
            $update['updated_at'] = now();
            $affected = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('id', (int) $id)
                ->update($update);
            if ($affected === 0) {
                return response()->json(['success' => false, 'error' => 'not_found'], 404);
            }
            $row = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('id', (int) $id)
                ->first([
                    'id',
                    'meta_title',
                    'meta_description',
                    'h1',
                    'featured_image_url',
                    'wp_attachment_id',
                    'has_featured_image',
                ]);
            return response()->json([
                'success' => true,
                'id'      => (int) $id,
                'row'     => $row,
            ]);
        });

        Route::post('/scan-pages', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $siteUrl = $r->input('url') ?: \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
            if (!$siteUrl) {
                return response()->json(['success' => false, 'error' => 'url_required'], 422);
            }

            $siteUrl = rtrim((string) $siteUrl, '/');
            $maxPages = min(50, (int) $r->input('max_pages', 50));

            // Telemetry start (cache key visible to /scan-status polling)
            \App\Engines\SEO\Services\ScanProgressService::start($wsId, 'pages', [
                'site_url'  => $siteUrl,
                'max_pages' => $maxPages,
            ]);
            \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'pages', [
                'stage' => 'discovering',
            ]);

            // P0-B1 (2026-05-15): proper same-origin enforcement. Compares
            // parsed hosts, not string prefixes. Closes SSRF where
            // 'https://staging.levelupgrowth.io.evil.com' was accepted by
            // the prior stripos===0 check.
            $sameOriginAs = function (string $candidate, string $base): bool {
                $b = parse_url($base);
                $c = parse_url($candidate);
                if (!is_array($b) || !is_array($c)) return false;
                if (empty($b['host']) || empty($c['host'])) return false;
                if (strtolower((string) $b['host']) !== strtolower((string) $c['host'])) return false;
                if (!empty($b['scheme']) && !empty($c['scheme'])
                    && strtolower((string) $b['scheme']) !== strtolower((string) $c['scheme'])) return false;
                return true;
            };

            // 2026-05-12: walk sitemap (or sitemap-index, recursively) to
            // collect page URLs. Falls back to indexing only the homepage
            // if no sitemap is reachable.
            $fetchXml = function (string $url) {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(10)
                        ->withHeaders(['User-Agent' => 'LevelUpSEO/1.0 (sitemap-crawler)'])
                        ->get($url);
                    if (!$resp->successful()) { return null; }
                    $body = $resp->body();
                    // Skip HTML 404 pages that respond with 200 (some WP installs do this).
                    if (stripos($body, '<sitemapindex') === false &&
                        stripos($body, '<urlset')      === false) {
                        return null;
                    }
                    return $body;
                } catch (\Throwable $e) {
                    return null;
                }
            };

            $extractLocs = function (string $xml) {
                preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/i', $xml, $m);
                return array_map(
                    fn ($u) => trim(html_entity_decode((string) $u)),
                    $m[1] ?? []
                );
            };

            $isSitemapIndex = fn (string $xml) => stripos($xml, '<sitemapindex') !== false;

            // Phase P1+ (2026-05-15): DB-first discovery. The platform owns
            // articles + pages tables — there is no value in crawling HTML to
            // find URLs the DB already knows about. Sitemap walk + link crawler
            // remain as supplements for anything DB doesn't know.
            $pageUrls = app(\App\Engines\SEO\Services\PageDiscoveryService::class)
                ->discover($wsId);
            $sitemapsTried = [];
            foreach (['/sitemap.xml', '/wp-sitemap.xml', '/sitemap_index.xml'] as $path) {
                $rootUrl = $siteUrl . $path;
                $sitemapsTried[] = $rootUrl;
                $xml = $fetchXml($rootUrl);
                if (!$xml) { continue; }

                if ($isSitemapIndex($xml)) {
                    // Nested — fetch each sub-sitemap's URLs (cap 10 sub-maps).
                    foreach (array_slice($extractLocs($xml), 0, 10) as $subUrl) {
                        $subXml = $fetchXml($subUrl);
                        if ($subXml) {
                            $pageUrls = array_merge($pageUrls, $extractLocs($subXml));
                        }
                        if (count($pageUrls) >= $maxPages * 2) { break; }
                    }
                } else {
                    // Flat sitemap — extract page URLs directly. Merge with
                    // any DB-discovered URLs so we don't drop blog/article URLs
                    // that the platform serves but the sitemap doesn't list.
                    $pageUrls = array_merge($pageUrls, $extractLocs($xml));
                }
                if (!empty($pageUrls)) { break; }
            }

            // Phase 9A.2 (2026-05-15): when sitemap discovery yields no URLs,
            // fall back to a depth-1 link crawl from the homepage. Walks <a href>
            // tags and adds same-origin internal URLs. Capped to maxPages later.
            if (count($pageUrls) === 0) {
                try {
                    $homeResp = \Illuminate\Support\Facades\Http::timeout(10)
                        ->withHeaders(['User-Agent' => 'LevelUpSEO/1.0 (link-crawler)'])
                        ->get($siteUrl);
                    if ($homeResp->successful()) {
                        $aRegex = '/<a\b[^>]+href=(["\\\'])([^"\\\'#]+)\1/i';
                        preg_match_all($aRegex, $homeResp->body(), $aMatches);
                        $resolveLink = function (string $href) use ($siteUrl): string {
                            $href = trim($href);
                            if ($href === '' || $href[0] === '#'
                                || stripos($href, 'mailto:') === 0
                                || stripos($href, 'tel:') === 0
                                || stripos($href, 'javascript:') === 0) return '';
                            if (stripos($href, 'http') === 0) return $href;
                            if ($href[0] === '/') {
                                $origin = preg_replace('#^(https?://[^/]+).*#', '$1', $siteUrl);
                                return rtrim((string) $origin, '/') . $href;
                            }
                            return rtrim($siteUrl, '/') . '/' . ltrim($href, '/');
                        };
                        foreach (($aMatches[2] ?? []) as $href) {
                            $resolved = $resolveLink($href);
                            // Same-origin only — do not crawl external sites.
                            if ($resolved !== '' && $sameOriginAs($resolved, $siteUrl)) {
                                $pageUrls[] = $resolved;
                            }
                        }
                        $pageUrls = array_values(array_unique($pageUrls));

                        // Phase P1 (2026-05-15): depth-2 walk — visit each
                        // depth-1 URL and collect more internal links. Caps
                        // depth-2 calls so a giant homepage doesn't fan out
                        // unboundedly. Discovers pages NOT linked from home
                        // (typical for blog indexes, footer-only links, etc).
                        $depth1 = $pageUrls;
                        $depth2Cap = min(15, count($depth1));  // cap HTTP calls
                        foreach (array_slice($depth1, 0, $depth2Cap) as $depth1Url) {
                            if (count($pageUrls) >= $maxPages * 2) break;
                            try {
                                $sub = \Illuminate\Support\Facades\Http::timeout(8)
                                    ->withHeaders(['User-Agent' => 'LevelUpSEO/1.0 (link-crawler-d2)'])
                                    ->get($depth1Url);
                                if (!$sub->successful()) continue;
                                preg_match_all($aRegex, $sub->body(), $subMatches);
                                foreach (($subMatches[2] ?? []) as $href) {
                                    $resolved = $resolveLink($href);
                                    if ($resolved !== '' && $sameOriginAs($resolved, $siteUrl)) {
                                        $pageUrls[] = $resolved;
                                    }
                                }
                            } catch (\Throwable $e) { /* skip bad page */ }
                        }
                        $pageUrls = array_values(array_unique($pageUrls));
                    }
                } catch (\Throwable $e) {
                    // fall through to homepage-only behavior
                }
            }

            // Filter out non-page resources and dedupe.
            $skipExt = '/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|xml|css|js|ico|woff2?|ttf|otf|eot|mp4|webm|mp3)(\?.*)?$/i';
            $pageUrls = array_values(array_unique(array_filter(
                $pageUrls,
                fn ($u) => $u && !preg_match($skipExt, $u) && $sameOriginAs($u, $siteUrl)
            )));

            // Always include homepage so an empty/missing sitemap still gets something.
            if (!in_array($siteUrl, $pageUrls, true) &&
                !in_array($siteUrl . '/', $pageUrls, true)) {
                array_unshift($pageUrls, $siteUrl);
            }
            $pageUrls = array_slice($pageUrls, 0, $maxPages);

            \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'pages', [
                'stage' => 'fetching',
                'total' => count($pageUrls),
            ]);

            // Index each. Catch per-URL exceptions so one bad page doesn't kill the run.
            $svc = app(\App\Engines\SEO\Services\SeoService::class);
            $indexed = 0;
            $failed  = [];
            foreach ($pageUrls as $i => $u) {
                \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'pages', [
                    'processed'   => $i,
                    'current_url' => $u,
                ]);
                try {
                    $res = $svc->fetchAndIndexUrl($wsId, $u);
                    if (!empty($res['success']) || isset($res['page_id'])) {
                        $indexed++;
                        \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'pages', [
                            'tier1_done' => $indexed,
                        ]);
                    } else {
                        $failed[] = ['url' => $u, 'error' => $res['error'] ?? 'unknown'];
                        \App\Engines\SEO\Services\ScanProgressService::recordError(
                            $wsId, 'pages', $u, (string) ($res['error'] ?? 'unknown')
                        );
                    }
                } catch (\Throwable $e) {
                    $failed[] = ['url' => $u, 'error' => $e->getMessage()];
                    \App\Engines\SEO\Services\ScanProgressService::recordError(
                        $wsId, 'pages', $u, $e->getMessage()
                    );
                }
            }
            \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'pages', [
                'processed' => count($pageUrls),
            ]);

            \App\Engines\SEO\Services\ScanProgressService::finish($wsId, 'pages', [
                'pages_indexed'  => $indexed,
                'urls_found'     => count($pageUrls),
                'sitemaps_tried' => $sitemapsTried,
                'errors_total'   => count($failed),
            ]);

            return response()->json([
                'success'         => true,
                'pages_indexed'   => $indexed,
                'urls_found'      => count($pageUrls),
                'sitemaps_tried'  => $sitemapsTried,
                'errors'          => array_slice($failed, 0, 5),
                'errors_total'    => count($failed),
            ]);
        });

        Route::get('/image-issues', function (\Illuminate\Http\Request $r) {
            $wsId       = $r->attributes->get('workspace_id');
            $limit      = min(500, (int) $r->query('limit', 100));
            // Phase 9A.1 (2026-05-15): default returns ALL indexed images so the
            // SPA can render a full audit view (with flags on issue rows).
            // Legacy callers can pass ?issues_only=1 to keep the old behavior.
            $issuesOnly = (int) $r->query('issues_only', 0) === 1;

            $q = \Illuminate\Support\Facades\DB::table('seo_images')
                ->where('workspace_id', $wsId);
            if ($issuesOnly) {
                $q->where(function ($qq) {
                    $qq->where('missing_alt', true)->orWhere('empty_alt', true);
                });
            }
            $issues = $q->orderByDesc('updated_at')
                ->limit($limit)
                ->get([
                    'id', 'page_url', 'image_url', 'alt_text',
                    'missing_alt', 'empty_alt', 'suggested_alt',
                    'width', 'height', 'size_bytes', 'content_type', 'last_probed_at',
                    'scan_method',
                ]);
            $sumRow = \Illuminate\Support\Facades\DB::table('seo_images')
                ->where('workspace_id', $wsId)
                ->selectRaw('COUNT(*) AS total,
                             SUM(missing_alt) AS missing_alt,
                             SUM(empty_alt)   AS empty_alt,
                             COUNT(DISTINCT page_url) AS pages,
                             SUM(size_bytes)   AS bytes_total')
                ->first();
            $missing = (int) ($sumRow->missing_alt ?? 0);
            $empty   = (int) ($sumRow->empty_alt   ?? 0);
            $summary = [
                'total_images'        => (int) ($sumRow->total ?? 0),
                'pages_scanned'       => (int) ($sumRow->pages ?? 0),
                'pages_affected'      => (int) ($sumRow->pages ?? 0),
                'missing_alt'         => $missing,
                'empty_alt'           => $empty,
                'issues_found'        => $missing + $empty,
                'filename_unfriendly' => 0,
                'wrong_format'        => 0,
                'bytes_total'         => (int) ($sumRow->bytes_total ?? 0),
            ];
            return response()->json([
                'success' => true,
                'issues'  => $issues,
                'total'   => $issues->count(),
                'summary' => $summary,
            ]);
        });

        // Phase 9A.1 — HEAD-probe each NULL-sized seo_images row to capture
        // content-length + content-type. Up to {batch} rows per call so the
        // SPA can call this in chunks without blocking. Failed probes are
        // marked via last_probed_at so we don't retry endlessly.
        Route::post('/image-issues/probe-sizes', function (\Illuminate\Http\Request $r) {
            $wsId  = $r->attributes->get('workspace_id');
            $batch = max(1, min(100, (int) $r->input('batch', 50)));
            $rows = \Illuminate\Support\Facades\DB::table('seo_images')
                ->where('workspace_id', $wsId)
                ->whereNull('size_bytes')
                ->whereNull('last_probed_at')
                ->limit($batch)
                ->get(['id', 'image_url']);
            $probed = 0;
            $failed = 0;
            foreach ($rows as $row) {
                try {
                    $head = \Illuminate\Support\Facades\Http::timeout(8)
                        ->withHeaders(['User-Agent' => 'LevelUpSEO/1.0 (size-probe)'])
                        ->head($row->image_url);
                    $size = $head->successful() ? (int) ($head->header('Content-Length') ?? 0) : 0;
                    $type = $head->successful() ? (string) ($head->header('Content-Type') ?? '') : '';
                    \Illuminate\Support\Facades\DB::table('seo_images')
                        ->where('id', $row->id)
                        ->update([
                            'size_bytes'     => $size > 0 ? $size : null,
                            'content_type'   => $type !== '' ? mb_substr($type, 0, 100) : null,
                            'last_probed_at' => now(),
                            'updated_at'     => now(),
                        ]);
                    if ($size > 0) { $probed++; } else { $failed++; }
                } catch (\Throwable $e) {
                    $failed++;
                    \Illuminate\Support\Facades\DB::table('seo_images')
                        ->where('id', $row->id)
                        ->update(['last_probed_at' => now(), 'updated_at' => now()]);
                }
            }
            $remaining = \Illuminate\Support\Facades\DB::table('seo_images')
                ->where('workspace_id', $wsId)
                ->whereNull('size_bytes')
                ->whereNull('last_probed_at')
                ->count();
            return response()->json([
                'success'   => true,
                'probed'    => $probed,
                'failed'    => $failed,
                'remaining' => $remaining,
            ]);
        });

        // Phase 9A.1 — STUB. Phase 9D will implement actual download → recompress
        // → upload-back pipeline. For now this returns the size on disk + a
        // rough savings estimate so the user gets a clear "coming soon" message.
        // Phase A (2026-05-16) — REAL single-image optimization dispatch.
        // Replaces prior stub that returned fabricated estimated_savings_bytes.
        // This endpoint ONLY returns {accepted, status, job_id} — actual
        // optimization runs async in OptimizeWpAttachmentJob and is verified
        // independently before status transitions to 'optimized'. UI polls
        // /image-issues/optimize-status for outcome.
        Route::post('/image-issues/optimize', function (\Illuminate\Http\Request $r) {
            $wsId   = (int) $r->attributes->get('workspace_id');
            $userId = $r->user() ? (int) $r->user()->id : null;
            $imgUrl = trim((string) $r->input('image_url', ''));
            if ($imgUrl === '') {
                return response()->json(['accepted' => false, 'status' => 'failed', 'reason' => 'image_url_required'], 422);
            }
            $orchestrator = app(\App\Services\SeoOptimization\OptimizationOrchestrator::class);
            $result = $orchestrator->dispatch($wsId, $userId, $imgUrl);
            $http   = (int) ($result['http_status'] ?? 200);
            unset($result['http_status']);
            return response()->json($result, $http);
        });

        // Phase A — single-image optimization status (polling endpoint for FE)
        Route::get('/image-issues/optimize-status', function (\Illuminate\Http\Request $r) {
            $wsId   = (int) $r->attributes->get('workspace_id');
            $imgUrl = trim((string) $r->query('image_url', ''));
            if ($imgUrl === '') {
                return response()->json(['success' => false, 'error' => 'image_url_required'], 422);
            }
            $orchestrator = app(\App\Services\SeoOptimization\OptimizationOrchestrator::class);
            $state = $orchestrator->getStatus($wsId, $imgUrl);
            return response()->json(['success' => true, 'state' => $state]);
        });

        // Phase A — capability probe (admin / debug surface; FE may also call)
        Route::get('/image-issues/optimize-capability', function (\Illuminate\Http\Request $r) {
            $wsId  = (int) $r->attributes->get('workspace_id');
            $force = (bool) $r->query('force', false);
            $cap   = app(\App\Services\SeoOptimization\ConnectorCapabilityProbe::class)
                ->probe($wsId, $force);
            return response()->json(['success' => true, 'capability' => $cap]);
        });

        Route::post('/image-issues/bulk-analyze', function (\Illuminate\Http\Request $r) {
            $wsId  = $r->attributes->get('workspace_id');
            $pages = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->pluck('url')->toArray();

            \App\Engines\SEO\Services\ScanProgressService::start($wsId, 'images', [
                'pool' => count($pages),
            ]);
            $svc = app(\App\Engines\SEO\Services\SeoService::class);
            $scanned = 0;
            $tier1Imgs = 0;
            $tier2Attempts = 0;
            $tier2Imgs = 0;
            // P0.5 Tier 2 cap — each puppeteer call is 5-10s; 10 pages × ~7s
            // = ~70s. The route is synchronous; capping at 10 keeps total
            // runtime under typical nginx/PHP-FPM 60s timeout for the common
            // case where only a few pages need Tier 2.
            $tier2Cap = 10;
            $imgPool = array_slice($pages, 0, 30);
            \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'images', [
                'stage' => 'fetching',
                'total' => count($imgPool),
            ]);
            foreach ($imgPool as $i => $u) {
                \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'images', [
                    'stage'       => 'fetching',
                    'processed'   => $i,
                    'current_url' => $u,
                ]);
                $preCount = (int) \Illuminate\Support\Facades\DB::table('seo_images')
                    ->where('workspace_id', $wsId)
                    ->where('page_url', $u)
                    ->count();
                try { $svc->fetchAndIndexUrl($wsId, $u); $scanned++; } catch (\Throwable $e) {
                    \App\Engines\SEO\Services\ScanProgressService::recordError($wsId, 'images', $u, $e->getMessage());
                }
                $postCount = (int) \Illuminate\Support\Facades\DB::table('seo_images')
                    ->where('workspace_id', $wsId)
                    ->where('page_url', $u)
                    ->count();
                $tier1Delta = max(0, $postCount - $preCount);
                $tier1Imgs += $tier1Delta;
                \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'images', [
                    'tier1_done' => $tier1Imgs,
                ]);
                // Tier 2 fallback ONLY when the page has ZERO images after
                // Tier 1 ran. Note: $tier1Delta === 0 alone is wrong because
                // updateOrInsert on EXISTING tuples is an UPDATE that doesn't
                // move row count — that's still successful Tier 1 work and
                // must NOT trigger Tier 2 (would bleed laravel_browser into
                // pages already covered by laravel_http or wp_sync).
                if ($postCount === 0 && $tier2Attempts < $tier2Cap) {
                    $tier2Attempts++;
                    \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'images', [
                        'stage'         => 'rendering',
                        'tier2_attempts'=> $tier2Attempts,
                        'current_url'   => $u,
                    ]);
                    try {
                        $tier2Imgs += $svc->tier2ExtractRendered($wsId, $u);
                        \App\Engines\SEO\Services\ScanProgressService::update($wsId, 'images', [
                            'tier2_done' => $tier2Imgs,
                        ]);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('tier2 extract failed', [
                            'workspace_id' => $wsId,
                            'page_url'     => $u,
                            'err'          => $e->getMessage(),
                        ]);
                        \App\Engines\SEO\Services\ScanProgressService::recordError($wsId, 'images', $u, 'tier2: ' . $e->getMessage());
                    }
                }
            }
            \App\Engines\SEO\Services\ScanProgressService::finish($wsId, 'images', [
                'pages_scanned'  => $scanned,
                'tier1_images'   => $tier1Imgs,
                'tier2_attempts' => $tier2Attempts,
                'tier2_images'   => $tier2Imgs,
                'tier2_cap'      => $tier2Cap,
            ]);
            return response()->json([
                'success'        => true,
                'pages_scanned'  => $scanned,
                'tier1_images'   => $tier1Imgs,
                'tier2_attempts' => $tier2Attempts,
                'tier2_images'   => $tier2Imgs,
                'tier2_cap'      => $tier2Cap,
            ]);
        });

        Route::post('/image-issues/suggest-alt', function (\Illuminate\Http\Request $r) {
            $wsId    = $r->attributes->get('workspace_id');
            $imgUrl  = $r->input('image_url') ?: $r->input('image_src', '');
            $pageUrl = $r->input('page_url') ?: $r->input('page_context', '');
            $page    = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)->where('url', $pageUrl)->first();

            // Phase P2-light (2026-05-15): heuristic upgrade. Real AI vision
            // pending Railway runtime endpoint addition (/internal/image/describe).
            // For now, derive a meaningful alt from: filename → page H1 → page
            // title → host fallback. Top-keyword adds context when available.
            $filename = $imgUrl ? basename((string) parse_url($imgUrl, PHP_URL_PATH)) : '';
            $cleanFilename = preg_replace('/[-_]+/', ' ', preg_replace('/\.\w+$/', '', $filename));
            $cleanFilename = preg_replace('/\s+\d+x\d+\s*$/', '', $cleanFilename); // strip "1024x768" suffixes
            $cleanFilename = trim((string) $cleanFilename);

            $h1    = $page->h1 ?? '';
            $title = $page->title ?? '';
            $keyword = '';
            if ($pageUrl) {
                $keyword = (string) (\Illuminate\Support\Facades\DB::table('seo_keywords')
                    ->where('workspace_id', $wsId)
                    ->where('target_url', $pageUrl)
                    ->orderByDesc('volume')
                    ->value('keyword') ?? '');
            }

            // Strategy: filename if it's descriptive (8+ chars, not a generic ID),
            // else page context (h1 or title), with keyword prepended if not
            // already in the context.
            $isGenericFilename = $cleanFilename === '' || strlen($cleanFilename) < 8
                || preg_match('/^(img|dsc|dscn|p\d+|gemini|chatgpt|screen|untitled|placeholder|\d+|gal[ -_]\w+)/i', $cleanFilename);

            if (! $isGenericFilename) {
                $suggested = ucfirst($cleanFilename);
            } elseif ($h1) {
                $base = $h1;
                if ($keyword && stripos($base, $keyword) === false) {
                    $base = $keyword . ' — ' . $base;
                }
                $suggested = $base;
            } elseif ($title) {
                $suggested = $title;
            } else {
                $host = $pageUrl ? parse_url($pageUrl, PHP_URL_HOST) : 'website';
                $suggested = 'Image from ' . $host;
            }
            $suggested = mb_substr(trim((string) $suggested), 0, 120);

            // Persist suggestion so the SPA can display the saved value on revisit.
            if ($imgUrl) {
                \Illuminate\Support\Facades\DB::table('seo_images')
                    ->where('workspace_id', $wsId)
                    ->where('image_url', $imgUrl)
                    ->update([
                        'suggested_alt' => $suggested,
                        'updated_at'    => now(),
                    ]);
            }
            return response()->json([
                'success'       => true,
                'suggested_alt' => $suggested,
                'method'        => 'heuristic_v2',
            ]);
        });


        // P-AltApply (2026-05-15) — write generated alt text back to the canonical
        // seo_images row + best-effort push to WP via the existing connector
        // pattern (mirrors /save-meta). For Laravel-only sites (no WP plugin
        // configured) the WP push is skipped — Laravel persistence is the truth.
        Route::post('/image-issues/apply-alt', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $data = $r->validate([
                'image_url' => 'required|url',
                'alt_text'  => 'required|string|max:500',
                'page_url'  => 'nullable|url',
            ]);
            $alt = trim((string) $data['alt_text']);
            if ($alt === '') {
                return response()->json(['success' => false, 'error' => 'alt_text_empty'], 422);
            }

            // 1. Canonical persistence
            $affected = \Illuminate\Support\Facades\DB::table('seo_images')
                ->where('workspace_id', $wsId)
                ->where('image_url', $data['image_url'])
                ->update([
                    'alt_text'    => $alt,
                    'missing_alt' => 0,
                    'empty_alt'   => 0,
                    'updated_at'  => now(),
                ]);
            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'error'   => 'image_not_found',
                    'message' => 'No seo_images row matched (image_url + workspace).',
                ], 404);
            }

            // 2. Best-effort WP push — only when site is connector-attached.
            //    Mirrors /save-meta pattern: fire-and-forget, don't block on
            //    plugin-side errors. WP plugin endpoint /wp-json/lgsc/v1/update-image-alt
            //    is expected; on 404 we honestly report wp_pushed=false.
            $wpPushed = null;
            $wpReason = null;
            $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
            $secret  = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'webhook_secret')->value('value');
            if ($siteUrl && $secret) {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(5)
                        ->withHeaders(['User-Agent' => 'LevelUpGrowth/1.0 (alt-sync)'])
                        ->post(rtrim($siteUrl, '/') . '/wp-json/lgsc/v1/update-image-alt', [
                            'secret'    => $secret,
                            'image_url' => $data['image_url'],
                            'alt_text'  => $alt,
                        ]);
                    if ($resp->successful()) {
                        $wpPushed = true;
                    } else {
                        $wpPushed = false;
                        $wpReason = 'wp_status_' . $resp->status();
                        \Illuminate\Support\Facades\Log::warning('[SEO] apply-alt WP push non-2xx', [
                            'ws_id'     => $wsId,
                            'image_url' => $data['image_url'],
                            'http'      => $resp->status(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $wpPushed = false;
                    $wpReason = 'wp_unreachable';
                    \Illuminate\Support\Facades\Log::warning('[SEO] apply-alt WP push exception: ' . $e->getMessage());
                }
            }

            // 3. Recomputed counters for FE state sync (no extra round-trip needed)
            $sumRow = \Illuminate\Support\Facades\DB::table('seo_images')
                ->where('workspace_id', $wsId)
                ->selectRaw('SUM(missing_alt) AS missing, SUM(empty_alt) AS empty_v')
                ->first();

            return response()->json([
                'success'           => true,
                'image_url'         => $data['image_url'],
                'alt_text'          => $alt,
                'wp_pushed'         => $wpPushed,
                'wp_push_reason'    => $wpReason,
                'missing_remaining' => (int) ($sumRow->missing ?? 0),
                'empty_remaining'   => (int) ($sumRow->empty_v ?? 0),
            ]);
        });

        Route::get('/image-summary', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $s = \Illuminate\Support\Facades\DB::table('seo_images')
                ->where('workspace_id', $wsId)
                ->selectRaw('COUNT(*) AS total,
                             SUM(missing_alt) AS missing_alt,
                             SUM(empty_alt)   AS empty_alt,
                             COUNT(DISTINCT page_url) AS pages')
                ->first();
            // 2026-05-12: field names the SPA reads (lgseRenderImagesBody).
            // Cast SUM() results to int — MySQL returns DECIMAL-as-string.
            $missing = (int) ($s->missing_alt ?? 0);
            $empty   = (int) ($s->empty_alt   ?? 0);
            return response()->json([
                'success' => true,
                'summary' => [
                    'total_images'        => (int) ($s->total ?? 0),
                    'pages_scanned'       => (int) ($s->pages ?? 0),
                    'missing_alt'         => $missing,
                    'empty_alt'           => $empty,
                    'issues_found'        => $missing + $empty,
                    'filename_unfriendly' => 0,
                    'wrong_format'        => 0,
                ],
            ]);
        });

        // 2026-05-12: FIX 3 — /seo/serp-results
        Route::get('/serp-results', function (\Illuminate\Http\Request $r) {
            $wsId    = $r->attributes->get('workspace_id');
            $keyword = $r->query('keyword', '');
            $query   = \Illuminate\Support\Facades\DB::table('seo_serp_results')
                ->where('workspace_id', $wsId);
            if ($keyword) { $query->where('keyword', 'like', "%{$keyword}%"); }
            $results = $query->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'keyword', 'domain', 'position', 'url', 'title', 'snippet', 'created_at']);
            return response()->json(['success' => true, 'results' => $results, 'total' => $results->count()]);
        });

        // 2026-05-12: FIX 6 — /seo/outbound now reads seo_outbound_links table
        Route::get('/outbound', function (\Illuminate\Http\Request $r) {
            $wsId  = $r->attributes->get('workspace_id');
            $links = \Illuminate\Support\Facades\DB::table('seo_outbound_links')
                ->where('workspace_id', $wsId)
                ->orderByDesc('updated_at')
                ->limit(200)
                ->get(['source_url', 'target_url', 'target_host', 'anchor_text', 'status', 'updated_at']);
            return response()->json([
                'success' => true,
                'links'   => $links,
                'total'   => $links->count(),
            ]);
        });

        // Frontend<->backend alignment: seo.js "mark OK" on an outbound link
        // (PATCH /outbound/{id}, was "NOT implemented yet"). Tenancy-scoped status set.
        Route::patch('/outbound/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = $r->attributes->get('workspace_id');
            $status = strtolower((string) $r->input('status', 'ok'));
            if (! in_array($status, ['ok', 'broken', 'ignored', 'pending'], true)) { $status = 'ok'; }
            $updated = \Illuminate\Support\Facades\DB::table('seo_outbound_links')
                ->where('id', (int) $id)->where('workspace_id', $wsId)
                ->update(['status' => $status, 'updated_at' => now()]);
            return response()->json(['success' => $updated > 0, 'updated' => (int) $updated, 'status' => $status]);
        });

        Route::get('/ctr-analysis', function (\Illuminate\Http\Request $r) {
            $wsId  = $r->attributes->get('workspace_id');
            // DataForSEO<->GSC relationship: prefer REAL Google Search Console data
            // (gsc_metrics) when the workspace has synced it, enriching each page with
            // real clicks/impressions/ctr/position; fall back to the computed
            // ctr_potential_score estimate (seo_content_index) when GSC is not connected.
            $pages = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->whereNotNull('ctr_potential_score')
                ->orderByDesc('ctr_potential_score')
                ->limit(50)
                ->get(['url', 'title', 'ctr_potential_score', 'ctr_label',
                       'meta_title', 'meta_description', 'intent', 'content_score']);
            // Real GSC per-page aggregate (authoritative performance) keyed by URL.
            $gsc = \Illuminate\Support\Facades\DB::table('gsc_metrics')
                ->where('workspace_id', $wsId)
                ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
                ->groupBy('page')
                ->get()
                ->keyBy('page');
            $gscConnected = $gsc->isNotEmpty();
            if ($gscConnected) {
                foreach ($pages as $p) {
                    $g = $gsc[$p->url] ?? null;
                    if ($g) {
                        $imp = (int) $g->impressions; $clk = (int) $g->clicks;
                        $p->gsc_clicks      = $clk;
                        $p->gsc_impressions = $imp;
                        $p->gsc_ctr         = $imp > 0 ? round($clk / $imp, 4) : 0.0;
                        $p->gsc_position    = round((float) $g->position, 1);
                    }
                }
            }
            $avg = $pages->avg('ctr_potential_score');
            // Real average CTR across all GSC rows for the workspace (if connected).
            $realAvgCtr = null;
            if ($gscConnected) {
                $tot = \Illuminate\Support\Facades\DB::table('gsc_metrics')->where('workspace_id', $wsId)
                    ->selectRaw('SUM(clicks) c, SUM(impressions) i')->first();
                if ($tot && (int) $tot->i > 0) { $realAvgCtr = round(((int) $tot->c) / ((int) $tot->i), 4); }
            }
            return response()->json([
                'success'       => true,
                'pages'         => $pages,
                'avg_score'     => $avg ? (int) round($avg) : null,
                'total'         => $pages->count(),
                'gsc_connected' => $gscConnected,
                'source'        => $gscConnected ? 'gsc+estimate' : 'estimate',
                'real_avg_ctr'  => $realAvgCtr,
                'message'       => $pages->isEmpty()
                    ? 'Run a deep audit or scan-pages to populate CTR potential scores.'
                    : null,
            ]);
        });

        // Quick wins — uses REAL seo_audit_items columns (category, details, score)
        // NOT severity/recommendation which don't exist. Mirrors the working
        // /connector/quick-wins handler.
        Route::get('/quick-wins', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            // Wave 16c — forward active site URL into quick-wins scope.
            return response()->json(
                app(\App\Engines\SEO\Services\SeoDataService::class)
                    ->quickWins($wsId, $r->query('url'), $r->query('site_url'))
            );
            // legacy inline body kept below — never reached:
            $items = \Illuminate\Support\Facades\DB::table('seo_audit_items')
                ->join('seo_audits', 'seo_audit_items.audit_id', '=', 'seo_audits.id')
                ->where('seo_audits.workspace_id', $wsId)
                ->whereIn('seo_audit_items.status', ['error', 'warning'])
                ->when($r->query('url', ''), fn ($q) => $q->where('seo_audit_items.url', $r->query('url')))
                ->orderByRaw("CASE seo_audit_items.status WHEN 'error' THEN 1 ELSE 2 END")
                ->orderBy('seo_audit_items.score')
                ->limit(20)
                ->select(
                    'seo_audit_items.check_name as title',
                    'seo_audit_items.category as severity',
                    'seo_audit_items.details as description',
                    'seo_audit_items.url'
                )
                ->get();
            $rankWins = \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->whereBetween('current_rank', [11, 20])
                ->limit(10)
                ->get(['keyword', 'current_rank', 'volume', 'target_url'])
                ->map(fn ($k) => [
                    'title'       => "Rank boost: \"{$k->keyword}\" (pos #{$k->current_rank})",
                    'severity'    => 'opportunity',
                    'description' => "Volume: {$k->volume}. Push from #{$k->current_rank} to top 10.",
                    'url'         => $k->target_url,
                ]);
            return response()->json([
                'success'    => true,
                'quick_wins' => array_merge($items->toArray(), $rankWins->toArray()),
                'total'      => $items->count() + $rankWins->count(),
            ]);
        });

        // Links tab
        Route::get('/link-opportunities', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\SEO\Services\SeoDataService::class)
                    ->linkOpportunities($wsId, $r->query('source_url', ''))
            );
        });

        // Topics tab — stubs until topic clustering ships
        // 2026-05-15 Phase 4 — bidirectional meta save:
        //   1. update seo_content_index (canonical)
        //   2. update pages.seo_json IF a matching Builder page exists
        //   3. push to WP site via /wp-json/lgsc/v1/update-meta (fire-and-forget)
        Route::patch('/save-meta', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $data = $r->validate([
                'url'              => 'required|url',
                'meta_title'       => 'nullable|string|max:500',
                'meta_description' => 'nullable|string|max:1000',
                'h1'               => 'nullable|string|max:500',
            ]);
            $url   = $data['url'];
            $title = $data['meta_title'] ?? null;
            $desc  = $data['meta_description'] ?? null;
            $h1    = $data['h1'] ?? null;

            // 1. Canonical store — conditional writes only (null = leave alone)
            $update = ['updated_at' => now()];
            if ($title !== null) { $update['meta_title']       = $title; }
            if ($desc  !== null) { $update['meta_description'] = $desc;  }
            if ($h1    !== null) { $update['h1']               = $h1;    }
            if (count($update) > 1) {
                \Illuminate\Support\Facades\DB::table('seo_content_index')
                    ->where('workspace_id', $wsId)
                    ->where('url', $url)
                    ->update($update);
            }

            // 2. If this URL belongs to a Builder page, push back into pages.seo_json.
            //    We match by slug derived from URL path against websites in this workspace.
            try {
                $path = parse_url($url, PHP_URL_PATH) ?? '/';
                $slug = trim($path, '/') ?: 'home';
                $page = \Illuminate\Support\Facades\DB::table('pages')
                    ->join('websites', 'pages.website_id', '=', 'websites.id')
                    ->where('websites.workspace_id', $wsId)
                    ->where('pages.slug', $slug)
                    ->select('pages.*')
                    ->first();
                if ($page) {
                    $seo = json_decode($page->seo_json ?? '{}', true) ?: [];
                    if ($title !== null) { $seo['meta_title']       = $title; }
                    if ($desc  !== null) { $seo['meta_description'] = $desc;  }
                    if ($h1    !== null) { $seo['h1']               = $h1;    }
                    $seo['title']       = $title ?? ($seo['title']       ?? null);
                    $seo['description'] = $desc  ?? ($seo['description'] ?? null);

                    $pageUpdate = ['updated_at' => now(), 'seo_json' => json_encode(
                        array_filter($seo, fn ($v) => $v !== null && $v !== '')
                    )];
                    if ($title !== null) { $pageUpdate['meta_title']       = $title; }
                    if ($desc  !== null) { $pageUpdate['meta_description'] = $desc;  }
                    \Illuminate\Support\Facades\DB::table('pages')
                        ->where('id', $page->id)
                        ->update($pageUpdate);
                }
            } catch (\Throwable $e) {
                \Log::warning('[SEO] save-meta Builder writeback failed: ' . $e->getMessage());
            }

            // 3. If this is a connected WP site, fire-and-forget push to the plugin.
            try {
                $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
                    ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
                $secret = \Illuminate\Support\Facades\DB::table('seo_settings')
                    ->where('workspace_id', $wsId)->where('key', 'webhook_secret')->value('value');
                if ($siteUrl && $secret && str_contains($url, parse_url($siteUrl, PHP_URL_HOST) ?? 'x')) {
                    \Illuminate\Support\Facades\Http::timeout(5)
                        ->withHeaders(['User-Agent' => 'LevelUpGrowth/1.0 (meta-sync)'])
                        ->post(rtrim($siteUrl, '/') . '/wp-json/lgsc/v1/update-meta', [
                            'secret'           => $secret,
                            'url'              => $url,
                            'meta_title'       => $title ?? '',
                            'meta_description' => $desc  ?? '',
                            'h1'               => $h1    ?? '',
                        ]);
                }
            } catch (\Throwable $e) {
                \Log::debug('[SEO] save-meta WP push failed: ' . $e->getMessage());
            }

            // Rescore — manual meta edits should refresh content_score too.
            $newScore = null;
            $oldScore = null;
            $row = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)->where('url', $url)->first(['id']);
            if ($row) {
                $rescore = app(\App\Engines\SEO\Services\SeoService::class)
                    ->rescoreAfterMetaEdit($wsId, (int) $row->id);
                $newScore = $rescore['score'] ?? null;
                $oldScore = $rescore['old_score'] ?? null;
            }

            return response()->json([
                'success'       => true,
                'new_score'     => $newScore,
                'old_score'     => $oldScore,
                'score_changed' => $newScore !== null && $oldScore !== $newScore,
            ]);
        });


        // Phase A (2026-05-16) — pull WP featured images back into seo_content_index
        // via the connector's GET /lgsc/v1/posts endpoint. Idempotent + fast.
        Route::post('/sync-wp-featured-images', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $svc  = app(\App\Services\SeoSync\WpFeaturedImageSyncService::class);
            $result = $svc->sync($wsId);
            $http = ($result['success'] ?? false) ? 200 : 503;
            return response()->json($result, $http);
        });

        // ─── Meta optimization (Phase A 2026-05-19) ─────────────────────
        // /meta-optimize/keyword — detect focus keyword for a page (free, all tiers)
        Route::get('/meta-optimize/keyword', function (\Illuminate\Http\Request $r) {
            $wsId   = (int) $r->attributes->get('workspace_id');
            $pageId = (int) $r->query('page_id', 0);
            if ($pageId <= 0) {
                return response()->json(['success' => false, 'error' => 'page_id_required'], 422);
            }
            $detector = app(\App\Services\SeoOptimization\FocusKeywordDetector::class);
            return response()->json([
                'success'  => true,
                'keyword'  => $detector->detect($wsId, $pageId),
            ]);
        });

        // /meta-optimize/run — Growth+ AI optimize + auto-apply via /save-meta
        // Charges 0.5 credits per page. Single-page entry point; FE bulk
        // calls this in a loop for each selected page.
        Route::post('/meta-optimize/run', function (\Illuminate\Http\Request $r) {
            $wsId   = (int) $r->attributes->get('workspace_id');
            $pageId = (int) $r->input('page_id', 0);
            $override = trim((string) $r->input('override_keyword', ''));
            if ($pageId <= 0) {
                return response()->json(['success' => false, 'error' => 'page_id_required'], 422);
            }

            // Plan gate — Growth+ only (canUseImageOptimization is full-AI tier;
            // same semantic for meta optimization)
            $gate = app(\App\Core\Billing\FeatureGateService::class);
            if (! $gate->canUseImageOptimization($wsId)) {
                return response()->json([
                    'success'       => false,
                    'error'         => 'plan_upgrade_required',
                    'code'          => 'PLAN_UPGRADE_REQUIRED',
                    'required_plan' => 'growth',
                ], 403);
            }

            $detector = app(\App\Services\SeoOptimization\FocusKeywordDetector::class);
            $optimizer = app(\App\Services\SeoOptimization\MetaOptimizationService::class);

            // 1. Resolve focus keyword (override or detect)
            $kwResult = $detector->detect($wsId, $pageId);
            $primary  = $override !== '' ? $override : (string) ($kwResult['primary_keyword'] ?? '');
            $alts     = is_array($kwResult['alternatives'] ?? null) ? $kwResult['alternatives'] : [];
            if ($primary === '') {
                return response()->json([
                    'success' => false,
                    'error'   => 'no_focus_keyword',
                    'reason'  => 'detection_returned_none_no_override_supplied',
                ], 200);
            }

            // 2. Credit pre-check (0.5 per page)
            $cost    = $optimizer->creditPerPage();
            $credits = (float) (app(\App\Core\Billing\CreditService::class)->getBalance($wsId)['available'] ?? 0); // AEO-1b: pooled balance
            if ($credits < $cost) {
                return response()->json([
                    'success'   => false,
                    'error'     => 'insufficient_credits',
                    'code'      => 'NO_CREDITS',
                    'required'  => $cost,
                    'available' => $credits,
                ], 402);
            }

            // 3. Page row lookup (for URL needed at apply time)
            $page = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('id', $pageId)
                ->first(['url']);
            if (! $page) {
                return response()->json(['success' => false, 'error' => 'page_not_found'], 404);
            }

            // 4. Run AI optimize
            $result = $optimizer->optimize($wsId, $pageId, $primary, $alts);
            if (empty($result['success'])) {
                // NO credit charge on AI failure
                return response()->json([
                    'success'  => false,
                    'error'    => 'ai_failed',
                    'reason'   => (string) ($result['error']   ?? 'unknown'),
                    'details'  => (string) ($result['details'] ?? ''),
                ], 200);
            }

            // 5. Apply 'replace' actions via /save-meta (inline call — same workspace
            //    auth context already validated; we just re-use the meta-save logic).
            $applyPayload = ['url' => $page->url];
            $replaceCount = 0;
            $keepCount    = 0;
            foreach ($result['actions'] as $a) {
                if (($a['action'] ?? '') === 'replace' && isset($a['value'])) {
                    $applyPayload[$a['field']] = (string) $a['value'];
                    $replaceCount++;
                } elseif (($a['action'] ?? '') === 'keep') {
                    $keepCount++;
                }
            }

            $wpPushed = null;
            $wpReason = null;
            if ($replaceCount > 0) {
                // Persist via direct DB writes + same WP push pattern /save-meta uses
                $upd = ['updated_at' => now()];
                if (isset($applyPayload['meta_title']))       { $upd['meta_title']       = $applyPayload['meta_title']; }
                if (isset($applyPayload['meta_description'])) { $upd['meta_description'] = $applyPayload['meta_description']; }
                if (isset($applyPayload['h1']))               { $upd['h1']               = $applyPayload['h1']; }
                \Illuminate\Support\Facades\DB::table('seo_content_index')
                    ->where('workspace_id', $wsId)->where('id', $pageId)->update($upd);

                // Best-effort WP push
                try {
                    $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
                        ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
                    $secret  = \Illuminate\Support\Facades\DB::table('seo_settings')
                        ->where('workspace_id', $wsId)->where('key', 'webhook_secret')->value('value');
                    if ($siteUrl && $secret && str_contains($page->url, parse_url($siteUrl, PHP_URL_HOST) ?? 'x')) {
                        $resp = \Illuminate\Support\Facades\Http::timeout(8)
                            ->withHeaders(['User-Agent' => 'LevelUpGrowth/1.0 (meta-opt-sync)'])
                            ->post(rtrim($siteUrl, '/') . '/wp-json/lgsc/v1/update-meta', [
                                'secret'           => $secret,
                                'url'              => $page->url,
                                'meta_title'       => $applyPayload['meta_title']       ?? '',
                                'meta_description' => $applyPayload['meta_description'] ?? '',
                                'h1'               => $applyPayload['h1']               ?? '',
                            ]);
                        $wpPushed = $resp->successful();
                        if (! $wpPushed) { $wpReason = 'wp_status_' . $resp->status(); }
                    }
                } catch (\Throwable $e) {
                    $wpPushed = false;
                    $wpReason = 'wp_unreachable';
                    \Illuminate\Support\Facades\Log::warning('[SEO][meta-opt] WP push failed: ' . $e->getMessage());
                }
            }

            // 6. Recompute content score (only if any field was actually replaced)
            $rescore = null;
            if ($replaceCount > 0) {
                $rescore = app(\App\Engines\SEO\Services\SeoService::class)
                    ->rescoreAfterMetaEdit($wsId, $pageId);
            }

            // 7. Charge credit ONLY if AI executed (which it did — we're here)
            // AEO-1b: charge through the ledger (pooled workspace, credit_transactions row) — not a raw decrement.
            app(\App\Core\Billing\CreditService::class)->debit($wsId, $cost, 'seo/meta_optimize');

            return response()->json([
                'success'           => true,
                'page_id'           => $pageId,
                'page_url'          => $page->url,
                'primary_keyword'   => $primary,
                'keyword_source'    => $kwResult['source'] ?? 'unknown',
                'actions'           => $result['actions'],
                'fields_replaced'   => $replaceCount,
                'fields_kept'       => $keepCount,
                'wp_pushed'         => $wpPushed,
                'wp_push_reason'    => $wpReason,
                'credits_used'      => $cost,
                'credits_remaining' => max(0, $credits - $cost),
                'new_score'         => $rescore['score']     ?? null,
                'old_score'         => $rescore['old_score'] ?? null,
                'score_changed'     => $rescore['changed']   ?? false,
            ]);
        });

        // 2026-05-15 Phase 4 — adapter routes for existing seo.js shape.
        // The SPA already has full render code expecting these endpoint names
        // (_seoTopics → /topics/authority, _seoLinks → /links/anchor-analysis +
        // /anchors/bulk-analysis + /link-graph). Map Phase 1-3 storage to
        // those shapes without modifying seo.js.

        Route::get('/topics/authority', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $cq = \Illuminate\Support\Facades\DB::table('seo_clusters')
                ->where('workspace_id', $wsId);
            // Wave 16c — clusters with a pillar_url on this site only
            if ($host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r)) {
                $cq->where(function ($q) use ($host) {
                    $q->where('pillar_url', 'like', '%//' . $host . '%')->orWhereNull('pillar_url');
                });
            }
            $clusters = $cq->orderByDesc('page_count')->get();
            $topics = $clusters->map(function ($c) use ($wsId) {
                $hasPillar = !empty($c->pillar_url);
                return [
                    'cluster_id'           => (int) $c->id,
                    'topic'                => (string) ($c->label ?? 'Cluster #' . $c->id),
                    'authority'            => (int) round((float) ($c->avg_authority ?? 0) * 100),
                    'member_count'         => (int) ($c->page_count ?? 0),
                    'avg_content_score'    => (int) round((float) ($c->avg_score ?? 0)),
                    'completeness_score'   => min(100, (int) ($c->page_count ?? 0) * 20),
                    'has_pillar'           => $hasPillar,
                    'pillar_url'           => $c->pillar_url,
                ];
            })->values();
            return response()->json(['success' => true, 'topics' => $topics, 'total' => $topics->count()]);
        });

        Route::get('/links/anchor-analysis', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            // Flatten anchor analyses into per-issue rows the SPA renders
            $aQ = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')
                ->where('workspace_id', $wsId)
                ->whereIn('health', ['poor', 'warning']);
            if ($host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r)) {
                $aQ->where('target_url', 'like', '%//' . $host . '%');
            }
            $analyses = $aQ->limit(50)->get();
            $issues = [];
            foreach ($analyses as $a) {
                $dist = json_decode($a->anchor_distribution ?? '[]', true) ?: [];
                $recs = json_decode($a->recommendations ?? '[]', true) ?: [];
                $articleTitle = parse_url($a->target_url, PHP_URL_PATH) ?: $a->target_url;
                if ((int) $a->generic_anchors > 0) {
                    $issues[] = [
                        'article_title' => $articleTitle,
                        'anchor_text'   => 'generic (click here / read more)',
                        'issue_type'    => 'generic',
                        'fix'           => $recs[0] ?? 'Replace with descriptive anchor text',
                        'url'           => $a->target_url,
                    ];
                }
                foreach ($dist as $row) {
                    if (($row['count'] ?? 0) > 3) {
                        $issues[] = [
                            'article_title' => $articleTitle,
                            'anchor_text'   => $row['anchor'] ?? '',
                            'issue_type'    => 'over_optimised',
                            'fix'           => 'Vary anchor text — reused ' . $row['count'] . '×',
                            'url'           => $a->target_url,
                        ];
                    }
                }
            }
            return response()->json(['success' => true, 'issues' => $issues, 'total' => count($issues)]);
        });

        Route::get('/anchors/bulk-analysis', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            // Wave 16c — site scope filter
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $like = $host ? '%//' . $host . '%' : null;
            $sQ = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')
                ->where('workspace_id', $wsId);
            if ($like) $sQ->where('target_url', 'like', $like);
            $stats = $sQ
                ->selectRaw('SUM(total_inbound)        AS total_issues,
                             SUM(generic_anchors)     AS generic_issues,
                             SUM(CASE WHEN health = \'warning\' THEN 1 ELSE 0 END) AS over_optimised')
                ->first();
            $dQ = \Illuminate\Support\Facades\DB::table('seo_anchor_analysis')
                ->where('workspace_id', $wsId);
            if ($like) $dQ->where('target_url', 'like', $like);
            $dist = $dQ
                ->orderByDesc('total_inbound')
                ->limit(20)
                ->get(['target_url', 'total_inbound', 'unique_anchors', 'health']);
            return response()->json([
                'success'      => true,
                'summary'      => [
                    'total_issues'    => (int) ($stats->total_issues ?? 0),
                    'generic_issues'  => (int) ($stats->generic_issues ?? 0),
                    'over_optimised'  => (int) ($stats->over_optimised ?? 0),
                ],
                'distribution' => $dist,
            ]);
        });

        Route::get('/link-graph', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            // Wave 16 — site scope filter
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);
            $nodesQ = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId);
            $edgesQ = \Illuminate\Support\Facades\DB::table('seo_link_graph')
                ->where('workspace_id', $wsId)
                ->where('is_internal', true);
            if ($host) {
                $nodesQ->where('url', 'like', '%//' . $host . '%');
                $edgesQ->where(function ($q) use ($host) {
                    $q->where('source_url', 'like', '%//' . $host . '%')
                      ->orWhere('target_url', 'like', '%//' . $host . '%');
                });
            }
            $nodes = $nodesQ->limit(100)->get(['url AS id', 'title AS label', 'authority_score', 'inbound_links']);
            $edges = $edgesQ->limit(500)->get(['source_url AS source', 'target_url AS target', 'anchor_text']);
            return response()->json([
                'success' => true,
                'nodes'   => $nodes,
                'edges'   => $edges,
            ]);
        });

        // 2026-05-15 Phase 4b — chatbot status surface for SEO engine Chatbot tab.
        // Reads chatbot_settings (one row per workspace; the actual table — the
        // earlier draft referred to a non-existent `chatbots` table).
        Route::get('/chatbot/status', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $chatbot = \Illuminate\Support\Facades\DB::table('chatbot_settings')
                ->where('workspace_id', $wsId)
                ->first(['id', 'enabled', 'greeting', 'theme', 'timezone',
                         'primary_color', 'fallback_email', 'created_at', 'updated_at']);
            return response()->json([
                'success' => true,
                'chatbot' => $chatbot,
            ]);
        });

        // 2026-05-15 Phase 3 — semantic clusters
        Route::get('/clusters', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $clusters = \Illuminate\Support\Facades\DB::table('seo_clusters')
                ->where('workspace_id', $wsId)
                ->orderByDesc('page_count')
                ->get();
            foreach ($clusters as $cluster) {
                $cluster->members = \Illuminate\Support\Facades\DB::table('seo_cluster_members')
                    ->where('seo_cluster_members.cluster_id', $cluster->id)
                    ->leftJoin('seo_content_index as sci', function ($j) use ($wsId) {
                        $j->on('seo_cluster_members.url', '=', 'sci.url')
                          ->where('sci.workspace_id', $wsId);
                    })
                    ->select(
                        'seo_cluster_members.url',
                        'seo_cluster_members.similarity',
                        'sci.title',
                        'sci.content_score',
                        'sci.authority_score'
                    )
                    ->orderByDesc('seo_cluster_members.similarity')
                    ->get();
            }
            return response()->json([
                'success'  => true,
                'clusters' => $clusters,
                'total'    => $clusters->count(),
                'message'  => $clusters->isEmpty()
                    ? 'Run "seo:cluster" or POST /seo/clusters/rebuild to generate topic clusters.'
                    : null,
            ]);
        });
        // Wave 16g (2026-05-19) — Topics tab "Cluster gaps" data source.
        // Was returning raw unclustered-page rows from seo_content_index, but
        // the Topics-tab JS (renderTopics → lgseLoadTopics, line ~5544) expects
        // per-CLUSTER gap reports: {topic, gaps:[gap-type-keys], recommendation}.
        // Now synthesizes those reports from seo_clusters health signals
        // (missing pillar, thin cluster, low avg score, low avg authority).
        Route::get('/clusters/gaps', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $host = \App\Engines\SEO\Support\SiteScope::hostFromRequest($r);

            $cq = \Illuminate\Support\Facades\DB::table('seo_clusters')
                ->where('workspace_id', $wsId);
            if ($host !== '') {
                $cq->where(function ($q) use ($host) {
                    $q->where('pillar_url', 'like', '%//' . $host . '%')->orWhereNull('pillar_url');
                });
            }
            $clusters = $cq->orderByDesc('page_count')->get();

            $gaps = [];
            foreach ($clusters as $c) {
                $issues = [];
                $recs = [];
                $label = (string) ($c->label ?? 'Cluster #' . $c->id);

                if (empty($c->pillar_url)) {
                    $issues[] = 'no_pillar';
                    $recs[]   = 'Create a pillar page on "' . $label . '" — a comprehensive piece all related pages link into.';
                }
                if ((int) $c->page_count < 3) {
                    $issues[] = 'thin_cluster';
                    $recs[]   = 'Only ' . (int) $c->page_count . ' page(s) in this cluster — add 2-3 more articles to build topical authority.';
                }
                if ((float) ($c->avg_score ?? 0) < 50) {
                    $issues[] = 'low_quality';
                    $recs[]   = 'Avg content score ' . round((float) ($c->avg_score ?? 0)) . '/100 — improve existing pages before adding new ones.';
                }
                if ((float) ($c->avg_authority ?? 0) < 0.3) {
                    $issues[] = 'low_authority';
                    $recs[]   = 'Cluster pages get few inbound internal links — add references from related content.';
                }

                if (! empty($issues)) {
                    $gaps[] = [
                        'topic'          => $label,
                        'cluster_id'     => (int) $c->id,
                        'gaps'           => $issues,
                        'recommendation' => implode(' ', $recs),
                        'page_count'     => (int) $c->page_count,
                        'avg_score'      => round((float) ($c->avg_score ?? 0), 1),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'gaps'    => $gaps,
                'total'   => count($gaps),
                'message' => empty($gaps)
                    ? 'No cluster gaps — your topic clusters look healthy.'
                    : count($gaps) . ' cluster(s) have gaps. Address them to strengthen topical authority.',
            ]);
        });
        Route::post('/clusters/rebuild', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            \Illuminate\Support\Facades\Artisan::call('seo:cluster', ['workspace_id' => $wsId]);
            $count = \Illuminate\Support\Facades\DB::table('seo_clusters')
                ->where('workspace_id', $wsId)->count();
            return response()->json([
                'success'  => true,
                'clusters' => $count,
                'message'  => "Rebuilt {$count} topic clusters.",
            ]);
        });

        // Competitors tab — derive from SERP results
        Route::get('/competitors', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\SEO\Services\SeoDataService::class)
                    ->competitors($wsId)
            );
        });
        Route::get('/competitors/tracked', function (\Illuminate\Http\Request $r) {
            $wsId  = $r->attributes->get('workspace_id');
            $comps = \Illuminate\Support\Facades\DB::table('seo_serp_results')
                ->where('workspace_id', $wsId)
                ->whereNotNull('domain')
                ->select('domain',
                    \Illuminate\Support\Facades\DB::raw('COUNT(*) as appearances'),
                    \Illuminate\Support\Facades\DB::raw('AVG(position) as avg_position'))
                ->groupBy('domain')
                ->orderByDesc('appearances')
                ->limit(20)
                ->get();
            return response()->json(['success' => true, 'competitors' => $comps]);
        });

        // Insights tab
        Route::get('/insights/summary', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');

            // 2026-05-13 Phase 1 — return REAL insights from seo_insights
            // (populated by `php artisan seo:insights`). Sort critical→warning→opportunity.
            $insights = \Illuminate\Support\Facades\DB::table('seo_insights')
                ->where('workspace_id', $wsId)
                ->whereNull('dismissed_at')
                ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
                ->orderByDesc('created_at')
                ->get();

            $summary = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->selectRaw('COUNT(*)                                              AS total_pages,
                             ROUND(AVG(content_score), 1)                          AS avg_score,
                             SUM(CASE WHEN inbound_links = 0 THEN 1 ELSE 0 END)    AS orphan_pages,
                             SUM(CASE WHEN word_count   <  300 THEN 1 ELSE 0 END)  AS thin_pages,
                             SUM(CASE WHEN meta_description IS NULL THEN 1 ELSE 0 END) AS missing_meta')
                ->first();

            $keywords = \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('workspace_id', $wsId)->count();

            return response()->json([
                'success'     => true,
                'insights'    => $insights,
                'total'       => $insights->count(),
                'summary'     => $summary,
                'avg_score'   => (int) round($summary->avg_score ?? 0),
                'total_pages' => (int) ($summary->total_pages ?? 0),
                'keywords'    => $keywords,
            ]);
        });

        // 2026-05-13 Phase 1 — POST /seo/insights/{id}/dismiss
        Route::post('/insights/{id}/dismiss', function (\Illuminate\Http\Request $r, $id) {
            $wsId = $r->attributes->get('workspace_id');
            \Illuminate\Support\Facades\DB::table('seo_insights')
                ->where('id', $id)
                ->where('workspace_id', $wsId)
                ->update(['dismissed_at' => now(), 'updated_at' => now()]);
            return response()->json(['success' => true]);
        });
        Route::get('/insights/content-performance', function (\Illuminate\Http\Request $r) {
            $wsId  = $r->attributes->get('workspace_id');
            $pages = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->orderByDesc('content_score')
                ->limit(20)
                ->get(['url', 'title', 'content_score', 'word_count', 'updated_at']);
            return response()->json(['success' => true, 'pages' => $pages]);
        });
        Route::get('/insights/top-pages', function (\Illuminate\Http\Request $r) {
            $wsId  = $r->attributes->get('workspace_id');
            $pages = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->orderByDesc('content_score')
                ->limit(10)
                ->get(['url', 'title', 'content_score']);
            return response()->json(['success' => true, 'pages' => $pages]);
        });
        Route::get('/insights/traffic', function (\Illuminate\Http\Request $r) {
            return response()->json([
                'success' => true,
                'message' => 'Connect Google Search Console for traffic data.',
                'data'    => [],
            ]);
        });

        // Reports tab
        Route::get('/brand', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $ws   = \Illuminate\Support\Facades\DB::table('workspaces')->find($wsId);
            return response()->json([
                'success' => true,
                'brand'   => [
                    'name' => $ws->name ?? '',
                    'slug' => $ws->slug ?? '',
                ],
            ]);
        });

        // Settings — list articles
        Route::get('/articles', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $arts = \Illuminate\Support\Facades\DB::table('articles')
                ->where('workspace_id', $wsId)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'title', 'status', 'created_at']);
            return response()->json(['success' => true, 'articles' => $arts]);
        });

        // ai-report — GET alias (the existing POST stays at line 1760)
        Route::get('/ai-report', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $svc  = app(\App\Engines\SEO\Services\SeoService::class);
            return response()->json($svc->getReport($wsId));
        });

    });
