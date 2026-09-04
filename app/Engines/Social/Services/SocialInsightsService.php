<?php

namespace App\Engines\Social\Services;

use Illuminate\Support\Facades\DB;

/**
 * SOCIAL INSIGHTS (SOC-P1-5 unit, 2026-09-04) — the ONE authoritative Social Insights data
 * contract, consumed by BOTH Manual Mode (API) and Sarah. Deterministic, credit-free.
 *
 * DISCOVERY (2026-09-04): the platform has NO canonical social-analytics store and NO provider-
 * insights reader (metrics tables are all SEO/GSC/Creative). So the only REAL data today is our
 * own `social_posts` records. Provider engagement (impressions/reach/likes/etc.) requires a Meta
 * connection that is App-Review-blocked, so it is reported HONESTLY as not-available — NEVER faked.
 *
 * HARD RULE: never invent a metric. Own-data metrics carry source='levelup_records'; provider
 * metrics carry source='provider' with available=false + a truthful reason until a live connection
 * and a provider-insights reader exist.
 */
class SocialInsightsService
{
    /** Minimum sample sizes before a claim is allowed (documented, enforced). */
    public const MIN_FOR_TRENDS   = 5;   // period-over-period comparison
    public const MIN_FOR_PATTERN  = 8;   // format/topic/timing pattern claims
    public const MIN_FOR_PLATFORM = 6;   // platform comparison

    /** Provider capability matrix — what each platform COULD supply once connected + scoped.
     *  Honest: parity is NOT assumed; unconfigured/absent providers say so. */
    public const PROVIDER_MATRIX = [
        'facebook'  => ['post_metrics' => true,  'audience' => true,  'reach' => true,  'clicks' => true,  'historical' => true,  'status' => 'oauth_ready_meta_review_pending'],
        'instagram' => ['post_metrics' => true,  'audience' => true,  'reach' => true,  'clicks' => false, 'historical' => true,  'status' => 'oauth_ready_meta_review_pending'],
        'linkedin'  => ['post_metrics' => true,  'audience' => true,  'reach' => true,  'clicks' => true,  'historical' => true,  'status' => 'not_configured'],
        'twitter'   => ['post_metrics' => true,  'audience' => false, 'reach' => true,  'clicks' => false, 'historical' => true,  'status' => 'not_configured'],
        'gbp'       => ['post_metrics' => false, 'audience' => false, 'reach' => false, 'clicks' => false, 'historical' => false, 'status' => 'not_implemented'],
    ];

    /**
     * The authoritative overview contract.
     * $opts: website_id (int for one site | 'all' | 'unattributed'), period_days (default 30).
     */
    public function overview(int $wsId, array $opts = []): array
    {
        $scope    = $opts['website_id'] ?? 'all';
        $days     = max(1, (int) ($opts['period_days'] ?? 30));
        $since    = now()->subDays($days);
        $prevFrom = now()->subDays($days * 2);

        $base = fn () => DB::table('social_posts')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $scoped = function ($q) use ($scope) {
            if (is_int($scope) || ctype_digit((string) $scope)) return $q->where('website_id', (int) $scope);
            if ($scope === 'unattributed') return $q->whereNull('website_id');
            return $q; // 'all' = workspace-wide
        };

        $all     = $scoped($base())->get();                                    // lifetime, scoped
        $period  = $scoped($base())->where('created_at', '>=', $since)->get();  // this period
        $prev    = $scoped($base())->whereBetween('created_at', [$prevFrom, $since])->get();

        $n = $all->count();
        $countBy = fn ($col, $coll) => $coll->groupBy($col)->map->count()->toArray();
        $isImagePost = fn ($r) => !empty(json_decode($r->media_json ?? '[]', true));

        // ── OWN DATA (REAL, source=levelup_records) ──────────────────────────
        $ownData = [
            'totals' => [
                'lifetime_posts'  => $n,
                'period_posts'    => $period->count(),
                'published'       => $all->where('status', 'published')->count(),
                'scheduled'       => $all->where('status', 'scheduled')->count(),
                'draft'           => $all->whereIn('status', ['draft'])->count(),
                'failed'          => $all->where('execution_status', 'failed')->count(),
            ],
            'by_platform'  => $countBy('platform', $all),
            'format_mix'   => [
                'with_media' => $all->filter($isImagePost)->count(),
                'text_only'  => $all->reject($isImagePost)->count(),
            ],
            'outcomes'     => $countBy('execution_status', $all->whereNotNull('execution_status')),
            'cadence'      => [
                'period_days'          => $days,
                'posts_this_period'    => $period->count(),
                'posts_prev_period'    => $prev->count(),
                'avg_per_week'         => $days > 0 ? round($period->count() / ($days / 7), 1) : 0,
            ],
            'source'       => 'levelup_records',
            'fetched_at'   => now()->toIso8601String(),
            'freshness'    => 'live',   // our own DB, always current
        ];

        // ── PROVIDER ENGAGEMENT (impressions/reach/likes/...) — HONESTLY unavailable ──
        $ownPlatforms = array_keys($ownData['by_platform']);
        $connected    = DB::table('social_accounts')->where('workspace_id', $wsId)
            ->where('status', 'connected')->pluck('platform')->unique()->values()->all();
        $providerEngagement = [
            'source'      => 'provider',
            'available'   => false,
            'reason'      => empty($connected) ? 'no_social_account_connected' : 'provider_insights_reader_not_enabled',
            'message'     => empty($connected)
                ? 'Connect a social account to see live engagement (impressions, reach, likes, clicks).'
                : 'Live engagement will appear once platform review is complete.',
            'connected_platforms' => $connected,
            'capability_matrix'   => self::PROVIDER_MATRIX,
            'metrics'     => null,   // never fabricated
        ];

        // ── SAMPLE-SIZE GUARDS (pure, see evaluateSample) ────────────────────
        $sample = self::evaluateSample($n, count($ownData['by_platform']));

        return [
            'scope' => [
                'workspace_id' => $wsId,
                'website_id'   => is_int($scope) || ctype_digit((string) $scope) ? (int) $scope : $scope,
                'label'        => $this->scopeLabel($wsId, $scope),
            ],
            'period'              => ['days' => $days, 'since' => $since->toIso8601String(), 'until' => now()->toIso8601String()],
            'own_data'            => $ownData,
            'provider_engagement' => $providerEngagement,
            'sample'              => $sample,
            'recommendations'     => $this->recommendations($ownData, $sample),
        ];
    }

    /** Pure sample-size evaluation (no DB) — the documented thresholds, testable in isolation. */
    public static function evaluateSample(int $n, int $platformCount): array
    {
        return [
            'n'                   => $n,
            'enough_for_trends'   => $n >= self::MIN_FOR_TRENDS,
            'enough_for_patterns' => $n >= self::MIN_FOR_PATTERN,
            'enough_for_platform' => $platformCount >= 2 && $n >= self::MIN_FOR_PLATFORM,
            'thresholds'          => ['trends' => self::MIN_FOR_TRENDS, 'patterns' => self::MIN_FOR_PATTERN, 'platform' => self::MIN_FOR_PLATFORM],
        ];
    }

    /** Own-data, evidence-based recommendations (activity only — no provider metrics involved).
     *  Pure given its inputs. Every recommendation carries its evidence and a canonical action;
     *  guarded by sample size. */
    public function recommendations(array $own, array $sample): array
    {
        $recs = [];
        if (!$sample['enough_for_patterns']) {
            $recs[] = [
                'insight' => 'Not enough posts yet to spot performance patterns.',
                'evidence' => "You have {$sample['n']} posts; patterns need at least " . self::MIN_FOR_PATTERN . '.',
                'action'  => ['label' => 'Create a post', 'type' => 'social_create_post'],
                'confidence' => 'low',
            ];
            return $recs;
        }
        // Format skew (activity, not engagement): observe what the customer actually posts.
        $wm = $own['format_mix']['with_media']; $to = $own['format_mix']['text_only'];
        if ($wm + $to > 0 && min($wm, $to) === 0) {
            $recs[] = [
                'insight' => $wm > $to ? 'All your recent posts use images.' : 'All your recent posts are text-only.',
                'evidence' => "with_media={$wm}, text_only={$to}",
                'action'  => $wm > $to
                    ? ['label' => 'Try a text/link post', 'type' => 'social_create_post']
                    : ['label' => 'Create a visual post', 'type' => 'social_image'],
                'confidence' => 'medium',
            ];
        }
        // Cadence gap.
        if ($own['cadence']['posts_this_period'] === 0 && $sample['n'] > 0) {
            $recs[] = [
                'insight' => 'You haven\'t posted in this period.',
                'evidence' => "0 posts in the last {$own['cadence']['period_days']} days.",
                'action'  => ['label' => 'Schedule a post', 'type' => 'social_schedule_post'],
                'confidence' => 'high',
            ];
        }
        return $recs;
    }

    private function scopeLabel(int $wsId, $scope): string
    {
        if ($scope === 'all') return 'All websites in this workspace';
        if ($scope === 'unattributed') return 'Workspace-level (unattributed) posts';
        $name = DB::table('websites')->where('id', (int) $scope)->where('workspace_id', $wsId)->value('domain')
            ?: DB::table('websites')->where('id', (int) $scope)->where('workspace_id', $wsId)->value('name');
        return $name ? "Website: {$name}" : "Website #{$scope}";
    }
}
