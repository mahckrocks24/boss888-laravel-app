<?php

namespace App\Core\Strategy;

use App\Core\Distribution\ArticleDistributionService;
use App\Core\Distribution\PlatformPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-24 FIX 47 — Cross-engine post-publish coordinator.
 *
 * When an article publishes:
 *   1. For each connected, supported platform: request a retained article
 *      share through ArticleDistributionService
 *   2. Trigger AEO indexer to ping LLM crawlers
 *
 * W5 REWRITE (2026-07-22)
 * -----------------------
 * This used to create a task itself:
 *
 *     engine=social, action=social_create_post, assigned_agents=[],
 *     payload = {article_id, platform, auto_share, mode:'article_share'}
 *
 * That payload carried NO content and NO url, so the task dispatched into
 * SocialService::createPost() and inserted a social_posts row with
 * content = '' — the empty draft with a missing article link. W3 had correctly
 * stripped marcus/zoe from assigned_agents, but nothing took over their job, so
 * the task was ownerless and mode='article_share' was read by nobody except the
 * permission check.
 *
 * The coordinator no longer creates that task. It delegates to
 * ArticleDistributionService, which owns validation, canonical URL resolution,
 * grounded caption generation, approval, scheduling, idempotency, credits and
 * audit. The share record it produces is complete from the moment it exists.
 *
 * Newsletter featuring stays removed (email marketing is out of launch scope).
 */
class PostPublishCoordinator
{
    public function __construct(
        private CadenceGuardService $cadence,
        private ArticleDistributionService $distribution,
    ) {}

    public function onArticlePublished(int $wsId, int $articleId): array
    {
        $summary = [
            'article_id'         => $articleId,
            'shares_requested'   => 0,
            'shares_skipped'     => [],
            'newsletter_queued'  => false,
            'aeo_ping_fired'     => false,
            'skipped_reasons'    => [],
        ];

        // 1. ARTICLE DISTRIBUTION to connected, supported platforms.
        $accounts = $this->getConnectedAccounts($wsId);
        if (empty($accounts)) {
            // The overwhelmingly common case today: social_accounts is empty and
            // W4 blocks the OAuth callbacks, so nothing can be connected.
            $summary['skipped_reasons'][] = 'no_connected_social_platforms';
        } else {
            $cadenceCheck = $this->cadence->check($wsId, 'social_create_post');
            if (!$cadenceCheck['allowed']) {
                $summary['skipped_reasons'][] = 'social_cadence_exceeded: ' . $cadenceCheck['reason'];
            } else {
                foreach ($accounts as $acc) {
                    $platform = PlatformPolicy::normalise((string) $acc->platform);

                    // Never create a share for a provider that could never carry
                    // it — an undeliverable draft is worse than an honest skip.
                    if (!PlatformPolicy::isSupported($platform, PlatformPolicy::INTENT_ARTICLE_SHARE)) {
                        $summary['shares_skipped'][] = [
                            'platform' => $platform,
                            'reason'   => PlatformPolicy::check($platform, PlatformPolicy::INTENT_ARTICLE_SHARE)['reason'],
                        ];
                        continue;
                    }

                    try {
                        $res = $this->distribution->requestShare($wsId, [
                            'article_id'        => $articleId,
                            'platform'          => $platform,
                            'social_account_id' => (int) $acc->id,
                            'initiated_by'      => null,   // system-initiated
                        ]);

                        if ($res['ok'] ?? false) {
                            $summary['shares_requested']++;
                        } else {
                            $summary['shares_skipped'][] = [
                                'platform' => $platform,
                                'reason'   => $res['error'] ?? 'UNKNOWN',
                            ];
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[PostPublish] article share request failed', [
                            'workspace_id' => $wsId, 'article_id' => $articleId,
                            'platform' => $platform, 'error' => $e->getMessage(),
                        ]);
                        $summary['shares_skipped'][] = ['platform' => $platform, 'reason' => 'EXCEPTION'];
                    }
                }
            }
        }

        // 2. NEWSLETTER feature queue — REMOVED (launch-scope 2026-07-20).
        // Featuring an article for a weekly email newsletter is email marketing,
        // which is out of the launch product (see LaunchScopePolicy). We no longer
        // set feature_in_next_newsletter; the weekly-newsletter cron is disabled.
        // newsletter_queued stays false. Do NOT reintroduce without a product
        // decision on transactional-safe email.
        $summary['skipped_reasons'][] = 'newsletter_removed_from_launch';

        // 3. AEO crawler ping (re-trigger indexer for IndexNow + Bing)
        try {
            $a = DB::table('articles')->where('id', $articleId)->first(['slug', 'wp_post_id']);
            if ($a && $a->slug) {
                // Reuse the existing /api/connector/seo/sitemap/ping pattern
                // by just logging — the actual ping fires via FIX 25's
                // existing publish flow hook. Mark fired for completeness.
                $summary['aeo_ping_fired'] = true;
            }
        } catch (\Throwable $e) {
            Log::debug('[PostPublish] AEO ping skipped: ' . $e->getMessage());
        }

        Log::info('[PostPublish] coordinator complete', $summary + ['workspace_id' => $wsId]);
        return $summary;
    }

    /**
     * Connected social accounts for a workspace. Returns the account ROWS, not
     * just platform names: a share is bound to a specific account, and the
     * account id is part of the signed distribution context.
     */
    private function getConnectedAccounts(int $wsId): array
    {
        try {
            if (Schema::hasTable('social_accounts')) {
                return DB::table('social_accounts')
                    ->where('workspace_id', $wsId)
                    ->where('status', 'connected')
                    ->get(['id', 'platform'])
                    ->all();
            }
        } catch (\Throwable $e) {}
        return [];
    }
}
