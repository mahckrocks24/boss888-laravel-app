<?php

/**
 * CR-22B — extracted route module: content-01
 *
 * Source: routes/api.php lines 9614-10269 of the authoritative pre-extraction
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
 * Owner: Content   ·   Routes: 15   ·   Statements: 1
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
    // ══════════════════════════════════════════════════════════════
    // ALL ENGINE ROUTES — Controller-based, BaseEngineController enforced
    // READS = direct to service | WRITES = through execution pipeline
    // ══════════════════════════════════════════════════════════════

    // ── Write / Content Engine ───────────────────────────────────
    Route::prefix('write')->group(function () {
        $c = \App\Engines\Write\Http\Controllers\WriteController::class;
        Route::get('/articles', [$c, 'listArticles']);
        Route::post('/articles', [$c, 'createArticle']);
        Route::get('/articles/{id}', [$c, 'getArticle']);
        Route::put('/articles/{id}', [$c, 'updateArticle']);
        Route::delete('/articles/{id}', [$c, 'deleteArticle']);
        Route::get('/articles/{id}/versions', [$c, 'getVersions']);
        Route::post('/articles/{id}/versions/{vid}/restore', [$c, 'restoreVersion']);

        // Wave 7 (2026-05-18). Publish an article to the workspace's
        // WordPress site. Orchestrates: (1) update status to 'published',
        // (2) push to WP via existing /connector/publish-post logic,
        // (3) persist wp_post_id, (4) post notification to Priya's chat
        // Wave 50 — Generate AI featured image for an article (1cr).
        Route::post('/articles/{id}/generate-featured-image', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $articleId = (int) $id;
            $article = \Illuminate\Support\Facades\DB::table('articles')
                ->where('workspace_id', $wsId)->where('id', $articleId)->first(['id', 'title']);
            if (!$article) {
                return response()->json(['success' => false, 'error' => 'article_not_found'], 404);
            }
            $creditSvc = app(\App\Core\Billing\CreditService::class);
            $balance = (int) \Illuminate\Support\Facades\DB::table('credits')
                ->where('workspace_id', $wsId)->value('balance') ?: 0;
            if ($balance < 1) {
                return response()->json([
                    'success' => false, 'error' => 'insufficient_credits',
                    'required_credits' => 1, 'available' => $balance,
                ], 402);
            }
            $reservation = $creditSvc->reserveCredits($wsId, 1, 'Article', $articleId, 'gen_img_' . uniqid());
            $reservationRef = $reservation->reservation_reference;
            try {
                $result = app(\App\Engines\Creative\Services\CreativeService::class)
                    ->generateImage($wsId, [
                        'article_id' => $articleId,
                        'quality' => 'mini',
                        'user_id' => $r->user()?->id,
                    ]);
                $imgUrl = $result['url'] ?? $result['featured_image_url'] ?? null;
                if ($imgUrl) {
                    $creditSvc->commit($wsId, $reservationRef, 1);
                    return response()->json([
                        'success' => true,
                        'image_url' => $imgUrl,
                        'image_alt' => $result['featured_image_alt'] ?? null,
                        'credits_used' => 1,
                        'credits_remaining' => max(0, $balance - 1),
                    ]);
                }
                $creditSvc->release($wsId, $reservationRef);
                return response()->json([
                    'success' => false,
                    'error' => 'generation_failed',
                    'message' => 'Image generation did not return a URL.',
                ], 502);
            } catch (\Throwable $e) {
                $creditSvc->release($wsId, $reservationRef);
                \Illuminate\Support\Facades\Log::warning('[ArticleGenImage] failed', [
                    'article_id' => $articleId, 'error' => $e->getMessage(),
                ]);
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
        });

        // thread so the user sees the result in the unified messages
        // surfaces. Per AI Assistant Operating Rules: this fires only
        // on explicit user click (rule 5 — no auto-publish).
        Route::post('/articles/{id}/publish', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $articleId = (int) $id;

            // 2026-05-23 FIX 25 — search-engine submission helper. Fires
            // IndexNow (Bing + Yandex + DuckDuckGo) and Bing legacy ping
            // after a successful publish. Non-blocking — logs and returns
            // on any failure. Auto-generates + stores the IndexNow key in
            // seo_settings on first use. Works for Laravel-platform sites
            // because PublishedSiteMiddleware now serves /{key}.txt; for
            // WP-only sites IndexNow may fail ownership verification
            // (the WP plugin would need to serve the key file), but the
            // Bing ping still nudges the WP sitemap.
            $submitToSearchEngines = function (int $submitWsId, ?string $submitUrl, string $platform) {
                if (!$submitUrl || !preg_match('#^https?://#', $submitUrl)) {
                    return;
                }
                try {
                    $host = strtolower(parse_url($submitUrl, PHP_URL_HOST) ?: '');
                    if ($host === '') return;
                    // Resolve sitemap URL for this host. Platform sites
                    // self-serve /sitemap.xml; WP sites use /wp-sitemap.xml
                    // or /sitemap.xml depending on plugin.
                    $sitemapUrl = ($platform === 'wp')
                        ? 'https://' . $host . '/wp-sitemap.xml'
                        : 'https://' . $host . '/sitemap.xml';
                    // Bing legacy ping — still functional, no key needed.
                    try {
                        \Illuminate\Support\Facades\Http::timeout(8)
                            ->withHeaders(['User-Agent' => 'LevelUpSEO/1.0'])
                            ->get('https://www.bing.com/ping', ['sitemap' => $sitemapUrl]);
                    } catch (\Throwable $eBing) {
                        \Illuminate\Support\Facades\Log::info('[SearchSubmit] Bing ping failed (non-fatal): ' . $eBing->getMessage());
                    }
                    // IndexNow submission.
                    $key = \Illuminate\Support\Facades\DB::table('seo_settings')
                        ->where('workspace_id', $submitWsId)
                        ->where('key', 'indexnow_key')
                        ->value('value');
                    if (!$key) {
                        $key = bin2hex(random_bytes(16));
                        \Illuminate\Support\Facades\DB::table('seo_settings')->updateOrInsert(
                            ['workspace_id' => $submitWsId, 'key' => 'indexnow_key'],
                            ['value' => $key, 'updated_at' => now(), 'created_at' => now()]
                        );
                    }
                    $body = [
                        'host'        => $host,
                        'key'         => $key,
                        'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
                        'urlList'     => [$submitUrl],
                    ];
                    try {
                        $resp = \Illuminate\Support\Facades\Http::timeout(8)
                            ->withHeaders(['Content-Type' => 'application/json; charset=utf-8'])
                            ->post('https://api.indexnow.org/IndexNow', $body);
                        \Illuminate\Support\Facades\Log::info('[SearchSubmit] IndexNow submitted', [
                            'ws_id'   => $submitWsId,
                            'url'     => $submitUrl,
                            'platform'=> $platform,
                            'http'    => $resp->status(),
                        ]);
                    } catch (\Throwable $eIN) {
                        \Illuminate\Support\Facades\Log::info('[SearchSubmit] IndexNow failed (non-fatal): ' . $eIN->getMessage());
                    }
                } catch (\Throwable $eOuter) {
                    \Illuminate\Support\Facades\Log::warning('[SearchSubmit] submit failed (non-fatal): ' . $eOuter->getMessage());
                }
            };

            $article = \Illuminate\Support\Facades\DB::table('articles')
                ->where('workspace_id', $wsId)->where('id', $articleId)->first();
            if (! $article) {
                return response()->json(['error' => 'article_not_found'], 404);
            }
            // WP-3 (2026-08-29): wp_post_id alone is NOT proof of publication — the generation-time
            // push creates a WordPress DRAFT and records its id. Only a published article with a
            // WP post id is 'already published'; a draft is promoted below (upsert by article id).
            if (! empty($article->wp_post_id) && $article->status === 'published') {
                return response()->json([
                    'success'    => true,
                    'already'    => true,
                    'wp_post_id' => (int) $article->wp_post_id,
                    'message'    => 'Article was already published to WordPress.',
                ]);
            }

            // Wave 51 — auto-enrich on publish for AI-generated articles
            // (assigned_agent IS NOT NULL) when the workspace has AEO Mode
            // on. Charges 1cr separately from publish. Failure here is
            // logged but does NOT block the publish flow — graceful
            // degradation, the WP post still ships with whatever content
            // we have.
            $aeoOn = (bool) \Illuminate\Support\Facades\DB::table('aeo_settings')
                ->where('workspace_id', $wsId)
                ->value('aeo_mode_enabled');
            $isAiGenerated = !empty($article->assigned_agent);
            $alreadyEnriched = !empty($article->aeo_enriched_at);

            if ($aeoOn && $isAiGenerated && !$alreadyEnriched) {
                $autoEnrichSvc = app(\App\Core\Billing\CreditService::class);
                $autoBalance = (int) \Illuminate\Support\Facades\DB::table('credits')
                    ->where('workspace_id', $wsId)->value('balance') ?: 0;
                if ($autoBalance >= 1) {
                    $autoResv = $autoEnrichSvc->reserveCredits($wsId, 1, 'Article', $articleId, 'auto_aeo_pub_' . uniqid());
                    try {
                        $aeoRes = app(\App\Engines\Write\Services\WriteService::class)
                            ->aeoEnrich($wsId, ['article_id' => $articleId]);
                        if (!empty($aeoRes['enriched'])) {
                            $autoEnrichSvc->commit($wsId, $autoResv->reservation_reference, 1);
                            \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
                                'workspace_id' => $wsId,
                                'action' => 'write.aeo_enrich',
                                'entity_type' => 'Article',
                                'entity_id' => $articleId,
                                'metadata_json' => json_encode([
                                    'source' => 'auto_on_publish',
                                    'faq_count' => $aeoRes['faq_count'] ?? 0,
                                    'heading_rewrites' => $aeoRes['heading_rewrites'] ?? 0,
                                    'credit_cost' => 1,
                                ]),
                                'created_at' => now(),
                            ]);
                            // Refresh $article so the WP push uses the enriched content.
                            $article = \Illuminate\Support\Facades\DB::table('articles')
                                ->where('id', $articleId)->first();
                        } else {
                            $autoEnrichSvc->release($wsId, $autoResv->reservation_reference);
                        }
                    } catch (\Throwable $aeoErr) {
                        $autoEnrichSvc->release($wsId, $autoResv->reservation_reference);
                        \Illuminate\Support\Facades\Log::warning('[AutoEnrichOnPublish] failed', [
                            'article_id' => $articleId, 'error' => $aeoErr->getMessage(),
                        ]);
                    }
                } else {
                    \Illuminate\Support\Facades\Log::info('[AutoEnrichOnPublish] skipped — insufficient credits', [
                        'article_id' => $articleId, 'balance' => $autoBalance,
                    ]);
                }
            }

            // Wave 60 — detect Laravel-rendered tenant site first. If the
            // workspace has a published `websites` row, we skip the WP push
            // entirely: flip status, return success. The published article
            // is served at https://{host}/blog/{slug}/ via
            // PublishedSiteMiddleware -> BuilderRenderer.
            $laravelSite = \Illuminate\Support\Facades\DB::table('websites')
                ->where('workspace_id', $wsId)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->first(['id', 'subdomain', 'domain', 'custom_domain']);

            if ($laravelSite) {
                \Illuminate\Support\Facades\DB::table('articles')
                    ->where('id', $articleId)
                    ->update([
                        'status'       => 'published',
                        'published_at' => now(),
                        // BUGFIX (2026-07-24) — BuilderRenderer::renderArticle and the
                        // blog-index injection both require is_marketing_blog=1 to show
                        // an article on the tenant's Builder site. The pipeline sets it,
                        // but this raw publish endpoint didn't, so articles published
                        // here rendered "Article Not Found" on the live blog.
                        'is_marketing_blog' => 1,
                        'updated_at'   => now(),
                    ]);

                // WP-3 (2026-08-29): a workspace can run a Builder site AND a connected WordPress
                // site (WordPress is part of the Websites ecosystem, not a separate product). The
                // publish reaches both; the WordPress outcome is reported truthfully, never assumed.
                $__wpSync = app(\App\Engines\Write\Services\WriteService::class)
                    ->publishArticleToWordPressIfConnected($wsId, $articleId);
                $__wpOut  = !empty($__wpSync['connected'])
                    ? ['ok' => (bool) $__wpSync['ok'], 'wp_post_id' => $__wpSync['wp_post_id'], 'url' => $__wpSync['url'], 'error' => $__wpSync['error']]
                    : null;
                $__wpMsg  = $__wpOut === null ? ''
                    : ($__wpOut['ok'] ? ' Also published to your WordPress site' . ($__wpOut['url'] ? ' at ' . $__wpOut['url'] : '') . '.'
                                      : ' WordPress publish FAILED: ' . ($__wpOut['error'] ?: 'unknown error') . ' — the article is live on your website only.');

                try {
                    $host = $laravelSite->custom_domain ?: $laravelSite->domain ?: $laravelSite->subdomain ?: '';
                    if ($host) {
                        $host = strtolower(trim($host, ' /'));
                        $articleRow = \Illuminate\Support\Facades\DB::table('articles')
                            ->where('id', $articleId)
                            ->first(['slug', 'title', 'content', 'meta_title', 'meta_description', 'seo_json', 'featured_image_url', 'word_count', 'focus_keyword', 'readability_score']);
                        $publishedUrl = 'https://' . $host . '/blog/' . ltrim((string) ($articleRow->slug ?? ''), '/');

                        // Wave 66 — index into seo_content_index so SEO Engine
                        // reports (Pages tab, audits, link suggestions, AEO
                        // audit, keyword targeting) see the article.
                        if ($articleRow && $articleRow->slug) {
                            $seoJson = $articleRow->seo_json ? json_decode($articleRow->seo_json, true) : [];
                            $metaTitle = $articleRow->meta_title ?: ($seoJson['title'] ?? $articleRow->title);
                            $metaDesc  = $articleRow->meta_description ?: ($seoJson['description'] ?? null);
                            $bodyText  = trim(preg_replace('/\s+/', ' ', strip_tags((string) $articleRow->content)));
                            $imgCount  = preg_match_all('#<img\b#i', (string) $articleRow->content);
                            $intLinks  = preg_match_all('#<a\b[^>]*href="/[^"]+"#i', (string) $articleRow->content);
                            $extLinks  = preg_match_all('#<a\b[^>]*href="https?://[^"]+"#i', (string) $articleRow->content);

                            $payload = [
                                'workspace_id'        => $wsId,
                                'title'               => $articleRow->title,
                                'meta_title'          => $metaTitle,
                                'meta_description'    => $metaDesc,
                                'featured_image_url'  => $articleRow->featured_image_url,
                                'has_featured_image'  => $articleRow->featured_image_url ? 1 : 0,
                                'h1'                  => $articleRow->title,
                                'h2_count'            => (int) preg_match_all('#<h2\b#i', (string) $articleRow->content),
                                'word_count'          => (int) ($articleRow->word_count ?? str_word_count($bodyText)),
                                'image_count'         => (int) $imgCount,
                                'internal_link_count' => (int) $intLinks,
                                'external_link_count' => (int) $extLinks,
                                'inbound_links'       => 0,
                                'inbound_weight'      => 0,
                                'authority_score'     => 0,
                                'readability_score'   => $articleRow->readability_score,
                            ];

                            // upsertContentIndex is a private SeoService method;
                            // call via reflection (same pattern Wave 52 uses).
                            try {
                                $seoSvc = app(\App\Engines\SEO\Services\SeoService::class);
                                $ref = new \ReflectionMethod($seoSvc, 'upsertContentIndex');
                                $ref->setAccessible(true);
                                $ref->invoke($seoSvc, $publishedUrl, $payload);
                            } catch (\Throwable $idxErr) {
                                \Illuminate\Support\Facades\Log::warning('[ArticlePublish] index upsert failed', [
                                    'article_id' => $articleId, 'error' => $idxErr->getMessage(),
                                ]);
                            }

                            // Wave 68 — extract internal-link anchors from the article
                            // body and populate seo_link_graph so the Anchors tab has
                            // data. Also trigger analyzeAnchors() for each target seen
                            // to refresh the seo_anchor_analysis cache.
                            try {
                                $hostOnly = parse_url($publishedUrl, PHP_URL_HOST) ?: '';
                                $linkRows = [];
                                if ($hostOnly && preg_match_all('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', (string) $articleRow->content, $matches, PREG_SET_ORDER)) {
                                    foreach ($matches as $m) {
                                        $href = trim($m[1]);
                                        $anchorText = trim(strip_tags($m[2]));
                                        if ($anchorText === '' || strlen($anchorText) > 300) continue;
                                        // Internal = same host OR relative path
                                        $isInternal = false; $targetUrl = '';
                                        if (str_starts_with($href, '/')) {
                                            $targetUrl = 'https://' . $hostOnly . $href;
                                            $isInternal = true;
                                        } elseif (preg_match('#^https?://([^/]+)#i', $href, $hm)) {
                                            $targetHost = strtolower($hm[1]);
                                            if ($targetHost === $hostOnly) {
                                                $targetUrl = $href;
                                                $isInternal = true;
                                            }
                                        }
                                        if (!$isInternal || !$targetUrl) continue;
                                        $linkRows[] = [
                                            'workspace_id' => $wsId,
                                            'source_url'   => $publishedUrl,
                                            'target_url'   => $targetUrl,
                                            'anchor_text'  => mb_substr($anchorText, 0, 300),
                                            'is_internal'  => 1,
                                            'created_at'   => now(),
                                            'updated_at'   => now(),
                                        ];
                                    }
                                }
                                if (!empty($linkRows)) {
                                    // Delete prior rows for this source URL to keep the graph coherent.
                                    \Illuminate\Support\Facades\DB::table('seo_link_graph')
                                        ->where('workspace_id', $wsId)
                                        ->where('source_url', $publishedUrl)
                                        ->where('is_internal', 1)
                                        ->delete();
                                    \Illuminate\Support\Facades\DB::table('seo_link_graph')->insert($linkRows);

                                    // Recompute anchor analysis for each target URL seen.
                                    $targets = array_unique(array_column($linkRows, 'target_url'));
                                    foreach ($targets as $t) {
                                        try {
                                            app(\App\Engines\SEO\Services\SeoService::class)->analyzeAnchors($wsId, $t);
                                        } catch (\Throwable $aErr) {
                                            // skip — non-fatal
                                        }
                                    }
                                }
                            } catch (\Throwable $lgErr) {
                                \Illuminate\Support\Facades\Log::warning('[ArticlePublish] link_graph upsert failed', [
                                    'article_id' => $articleId, 'error' => $lgErr->getMessage(),
                                ]);
                            }

                            // Wave 70 — auto-generate seo_links suggestions if the
                            // article has 0 internal links AND the workspace has no
                            // suggestions for this article yet.
                            // Wave 73 — also AUTO-APPLY any 'suggested' rows so the
                            // article actually gets the links in its body, not just
                            // a list of unfired recommendations.
                            try {
                                $seoSvc = app(\App\Engines\SEO\Services\SeoService::class);
                                $hasSuggestions = \Illuminate\Support\Facades\DB::table('seo_links')
                                    ->where('workspace_id', $wsId)
                                    ->where('source_url', $publishedUrl)
                                    ->exists();
                                $hasInBody = preg_match('#<a\b[^>]*href=#i', (string) $articleRow->content);
                                if (!$hasSuggestions && !$hasInBody) {
                                    $seoSvc->generateLinkSuggestions($wsId, ['article_id' => $articleId]);
                                }
                                // Apply any pending suggestions for this source URL.
                                $pending = \Illuminate\Support\Facades\DB::table('seo_links')
                                    ->where('workspace_id', $wsId)
                                    ->where('source_url', $publishedUrl)
                                    ->where('status', 'suggested')
                                    ->limit(10)
                                    ->get(['id']);
                                foreach ($pending as $row) {
                                    try {
                                        $seoSvc->insertLink($wsId, (int) $row->id);
                                    } catch (\Throwable $insErr) {
                                        \Illuminate\Support\Facades\Log::warning('[ArticlePublish] insertLink failed', [
                                            'link_id' => $row->id, 'error' => $insErr->getMessage(),
                                        ]);
                                    }
                                }
                            } catch (\Throwable $lsErr) {
                                \Illuminate\Support\Facades\Log::warning('[ArticlePublish] auto link_suggestions failed', [
                                    'article_id' => $articleId, 'error' => $lsErr->getMessage(),
                                ]);
                            }

                            // Wave 67 — also track the featured image in seo_images so
                            // the SEO Engine Images tab surfaces it (alt-text audits,
                            // optimization status, missing-alt detection).
                            if ($articleRow->featured_image_url) {
                                $imgUrl = (string) $articleRow->featured_image_url;
                                $alt = (string) ($articleRow->title ?? '');
                                $hasAlt = $alt !== '';
                                try {
                                    $existing = \Illuminate\Support\Facades\DB::table('seo_images')
                                        ->where('workspace_id', $wsId)
                                        ->where('image_url', $imgUrl)
                                        ->first(['id']);
                                    $row = [
                                        'workspace_id'    => $wsId,
                                        'page_url'        => $publishedUrl,
                                        'image_url'       => $imgUrl,
                                        'alt_text'        => $alt,
                                        'missing_alt'    => $hasAlt ? 0 : 1,
                                        'empty_alt'       => $hasAlt ? 0 : 1,
                                        'optimization_status' => 'unoptimized',
                                        'updated_at'      => now(),
                                    ];
                                    if ($existing) {
                                        \Illuminate\Support\Facades\DB::table('seo_images')->where('id', $existing->id)->update($row);
                                    } else {
                                        $row['created_at'] = now();
                                        \Illuminate\Support\Facades\DB::table('seo_images')->insert($row);
                                    }
                                } catch (\Throwable $imgErr) {
                                    \Illuminate\Support\Facades\Log::warning('[ArticlePublish] seo_images upsert failed', [
                                        'article_id' => $articleId, 'error' => $imgErr->getMessage(),
                                    ]);
                                }
                            }
                        }

                        \Illuminate\Support\Facades\Log::info('[ArticlePublish] Laravel-rendered publish', [
                            'article_id' => $articleId, 'url' => $publishedUrl,
                        ]);

                        // 2026-05-23 FIX 25 — submit to search engines.
                        $submitToSearchEngines($wsId, $publishedUrl, 'laravel');

                        // 2026-05-24 FIX 47 — cross-engine post-publish
                        // coordination. Queues auto-share social posts
                        // for every connected platform + marks article
                        // for next newsletter feature + AEO ping. Non-
                        // fatal — failures are logged, not raised.
                        try {
                            app(\App\Core\Strategy\PostPublishCoordinator::class)
                                ->onArticlePublished($wsId, $articleId);
                        } catch (\Throwable $eCoord) {
                            \Illuminate\Support\Facades\Log::warning('[ArticlePublish] PostPublishCoordinator failed (non-fatal): ' . $eCoord->getMessage());
                        }

                        return response()->json([
                            'success'       => true,
                            'platform'      => 'laravel',
                            'article_id'    => $articleId,
                            'published_url' => $publishedUrl,
                            'website_id'    => $laravelSite->id,
                            'wordpress'     => $__wpOut,
                            'message'       => 'Article published. It is live on your website at ' . $publishedUrl . $__wpMsg,
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[ArticlePublish] Laravel publish post-step error', [
                        'article_id' => $articleId, 'error' => $e->getMessage(),
                    ]);
                }

                // Fallthrough success (status already flipped)
                return response()->json([
                    'success'    => true,
                    'platform'   => 'laravel',
                    'article_id' => $articleId,
                    'wordpress'  => $__wpOut,
                    'message'    => 'Article published.' . $__wpMsg,
                ]);
            }

            // Step 1 — WP path. Site config check (fail fast before mutating state)
            // WP-3: the connection row (plugin Test connection) is the source of truth; seo_settings
            // is the pre-WP-1 fallback (resolved inside wpConnectionFor).
            $__conn        = app(\App\Engines\Write\Services\WriteService::class)->wpConnectionFor($wsId);
            $siteUrl       = $__conn['site_url'] ?? null;
            $webhookSecret = $__conn['webhook_secret'] ?? null;
            if (! $siteUrl) {
                return response()->json([
                    'error'   => 'site_not_configured',
                    'message' => 'No WordPress site is connected to this workspace, and no published website was found. Connect a WordPress site (Websites → Connect WordPress) or publish a Builder website first.',
                ], 422);
            }

            // Step 2 — update Laravel-side first so even if WP push fails
            // we have published_at recorded. wp_post_id stays NULL until
            // WP confirms.
            \Illuminate\Support\Facades\DB::table('articles')
                ->where('id', $articleId)
                ->update([
                    'status'       => 'published',
                    'published_at' => now(),
                    'updated_at'   => now(),
                ]);

            // Step 3+4 (WP-3) — push through WriteService::publishArticleToWordPressIfConnected:
            // upsert by article id (promotes a generation-time draft), records last_push_* on the
            // connection row, persists wp_post_id and the seo_content_index row. Never claims success
            // without a real WordPress post id.
            $__wp = app(\App\Engines\Write\Services\WriteService::class)->publishArticleToWordPressIfConnected($wsId, $articleId);
            if (empty($__wp['ok'])) {
                $__err = (string) ($__wp['error'] ?? 'unknown error');
                $__unreach = str_contains($__err, 'cURL') || str_contains($__err, 'Connection') || str_contains($__err, 'resolve');
                return response()->json([
                    'success' => false,
                    'error'   => $__unreach ? 'wp_unreachable' : 'wp_publish_failed',
                    'message' => ($__unreach ? 'Could not reach your WordPress site: ' : 'WordPress rejected the post: ') . $__err
                              . ' (the article is marked published in your library; fix the connection and publish again to retry the WordPress push)',
                ], 502);
            }
            $wpPostId  = $__wp['wp_post_id'];
            $publicUrl = $__wp['url'];
            $wpResult  = ['post_id' => $wpPostId, 'url' => $publicUrl];

            // Step 5 — notify the user via the appropriate agent's chat
            // thread. Context-aware routing (Wave 9, 2026-05-18):
            //   - WP iframe caller (X-API-KEY auth → api_key_id attribute):
            //     post to 'james' thread. The WP plugin SEO drawer pulls
            //     /agents/james/messages, so this is the only place a WP
            //     user will see the publish confirmation after the toast.
            //   - Laravel SaaS caller (JWT auth): post to 'priya' thread.
            //     Content Manager owns "your article is live" in the
            //     team UX.
            // Same notification surfaces in both contexts.
            try {
                $isWpEmbed = $r->attributes->has('api_key_id');
                $targetAgentSlug = $isWpEmbed ? 'james' : 'priya';
                $msg = "Your article **\"{$article->title}\"** is now live on your WordPress site.";
                if ($publicUrl) { $msg .= "\n\nLive URL: {$publicUrl}"; }
                app(\App\Core\Agents\AgentMessageService::class)
                    ->postAsAgent($wsId, $targetAgentSlug, $msg, [
                        'notification_type' => 'article_published',
                        'article_id'        => $articleId,
                        'wp_post_id'        => $wpPostId,
                        'public_url'        => $publicUrl,
                        'action_link'       => $publicUrl ?: ("/app/?tab=blog&article=" . $articleId),
                        'caller_context'    => $isWpEmbed ? 'wp_embed' : 'saas',
                    ]);

                $uid = optional($r->user())->id;
                if ($uid) {
                    app(\App\Core\Notifications\NotificationService::class)->dispatch(
                        \App\Core\Notifications\NotificationTypes::AGENT_TASK_COMPLETED,
                        $uid,
                        "Published: \"{$article->title}\"",
                        $wsId,
                        $publicUrl ? "Live at {$publicUrl}" : "Pushed to WordPress.",
                        [
                            'notification_type' => 'article_published',
                            'article_id'        => $articleId,
                            'wp_post_id'        => $wpPostId,
                        ],
                        $publicUrl,
                        'success',
                        '🎉'
                    );
                }
            } catch (\Throwable $e) { /* non-fatal */ }

            // Wave 10 (2026-05-18) — post-publish image-optimization hook.
            // After the article is live on WordPress, queue a job that:
            //   1. Tier-2 browser-renders the public URL to populate
            //      seo_images rows for every image WP now hosts on the post
            //   2. Dispatches OptimizationOrchestrator for each routable
            //      image (orchestrator does plan-gating + classification)
            // Queued so the publish endpoint stays fast; puppeteer + the
            // optimization jobs run async. No-ops cleanly for free-tier
            // workspaces (orchestrator returns plan_upgrade_required).
            if ($publicUrl) {
                try {
                    \App\Jobs\SeoOptimization\PostPublishOptimizeJob::dispatch(
                        $wsId,
                        optional($r->user())->id,
                        $publicUrl,
                        $articleId
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        '[Wave10] PostPublishOptimizeJob dispatch failed: ' . $e->getMessage(),
                        ['ws_id' => $wsId, 'article_id' => $articleId]
                    );
                }
            }

            // 2026-05-23 FIX 25 — submit to search engines (WP path).
            // IndexNow ownership may fail unless the LGSC plugin serves
            // the key file at {wp_host}/{key}.txt; Bing legacy ping still
            // nudges the WP sitemap regardless.
            $submitToSearchEngines($wsId, $publicUrl, 'wp');

            // 2026-05-24 FIX 47 — cross-engine post-publish coordination
            // (WP path). Same auto-share + newsletter feature flow as the
            // Laravel-platform branch above. Non-fatal on failure.
            try {
                app(\App\Core\Strategy\PostPublishCoordinator::class)
                    ->onArticlePublished($wsId, $articleId);
            } catch (\Throwable $eCoord) {
                \Illuminate\Support\Facades\Log::warning('[ArticlePublish WP] PostPublishCoordinator failed (non-fatal): ' . $eCoord->getMessage());
            }

            return response()->json([
                'success'    => true,
                'wp_post_id' => $wpPostId,
                'public_url' => $publicUrl,
                'article_id' => $articleId,
            ]);
        });
        Route::post('/ai/write', [$c, 'writeArticle']);
        Route::post('/ai/improve', [$c, 'improveDraft']);
        Route::post('/ai/outline', [$c, 'generateOutline']);
        Route::post('/ai/headlines', [$c, 'generateHeadlines']);
        Route::post('/ai/meta', [$c, 'generateMeta']);
        Route::get('/dashboard', [$c, 'dashboard']);
    });
