<?php

namespace Tests\Unit\Social;

use App\Engines\Social\Services\SocialInsightsService;
use PHPUnit\Framework\TestCase;

/**
 * SOCIAL INSIGHTS (SOC-P1-5) — PURE logic tests (no DB, no app boot). Cover the deterministic
 * guarantees: honest provider matrix, sample-size guards, and evidence+action recommendations.
 * The DB-read assembly (overview) is proven separately via the live API route + raw-bootstrap
 * evidence, because this environment has no isolated test DB seeded with scratch data.
 */
class SocialInsightsLogicTest extends TestCase
{
    /** @test */
    public function provider_matrix_tells_the_truth_about_each_platform(): void
    {
        $m = SocialInsightsService::PROVIDER_MATRIX;
        $this->assertSame('not_implemented', $m['gbp']['status']);
        $this->assertSame('not_configured', $m['linkedin']['status']);
        $this->assertSame('not_configured', $m['twitter']['status']);
        $this->assertSame('oauth_ready_meta_review_pending', $m['facebook']['status']);
        $this->assertSame('oauth_ready_meta_review_pending', $m['instagram']['status']);
        $this->assertFalse($m['instagram']['clicks'], 'IG does not expose post clicks — not claimed');
    }

    /** @test */
    public function sample_guards_enforce_documented_thresholds(): void
    {
        $thin = SocialInsightsService::evaluateSample(2, 1);
        $this->assertFalse($thin['enough_for_trends']);
        $this->assertFalse($thin['enough_for_patterns']);
        $this->assertFalse($thin['enough_for_platform']);

        $rich = SocialInsightsService::evaluateSample(21, 2);
        $this->assertTrue($rich['enough_for_trends']);
        $this->assertTrue($rich['enough_for_patterns']);
        $this->assertTrue($rich['enough_for_platform']);

        // platform comparison needs >=2 platforms even with volume
        $onePlatform = SocialInsightsService::evaluateSample(20, 1);
        $this->assertFalse($onePlatform['enough_for_platform']);
    }

    /** @test */
    public function thin_data_yields_a_not_enough_recommendation_not_a_fabricated_strategy(): void
    {
        $svc = new SocialInsightsService();
        $own = ['format_mix' => ['with_media' => 1, 'text_only' => 1], 'cadence' => ['posts_this_period' => 1, 'period_days' => 30]];
        $recs = $svc->recommendations($own, SocialInsightsService::evaluateSample(3, 1));
        $this->assertNotEmpty($recs);
        $this->assertStringContainsStringIgnoringCase('not enough', $recs[0]['insight']);
        $this->assertSame('low', $recs[0]['confidence']);
    }

    /** @test */
    public function format_skew_recommendation_is_evidence_based_and_actionable(): void
    {
        $svc = new SocialInsightsService();
        // enough data, all image posts -> suggest a text/link post, evidence carried, real action id
        $own = ['format_mix' => ['with_media' => 10, 'text_only' => 0], 'cadence' => ['posts_this_period' => 4, 'period_days' => 30]];
        $recs = $svc->recommendations($own, SocialInsightsService::evaluateSample(10, 2));
        $this->assertNotEmpty($recs);
        $r = $recs[0];
        $this->assertStringContainsStringIgnoringCase('image', $r['insight']);
        $this->assertNotEmpty($r['evidence']);
        $this->assertArrayHasKey('type', $r['action']);
    }

    /** @test */
    public function cadence_gap_recommends_scheduling(): void
    {
        $svc = new SocialInsightsService();
        $own = ['format_mix' => ['with_media' => 5, 'text_only' => 5], 'cadence' => ['posts_this_period' => 0, 'period_days' => 30]];
        $recs = $svc->recommendations($own, SocialInsightsService::evaluateSample(10, 2));
        $types = array_map(fn ($r) => $r['action']['type'] ?? null, $recs);
        $this->assertContains('social_schedule_post', $types);
    }
}
