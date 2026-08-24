<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdCreativeValidator;
use App\Engines\Ads\Services\AdReachEstimator;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Services\AdTokenService;
use App\Engines\Ads\Services\IndustryClassifier;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 — the commercial thresholds are operator-configurable, and the
 * guardrails that stop a change quietly making the inventory indefensible.
 */
class AdSettingsConfigurabilityTest extends TestCase
{
    use RefreshDatabase;

    private AdSettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = app(AdSettingsService::class);
        $this->settings->flush();
    }

    // ── The thresholds actually read from settings ──────────────────────

    public function test_paid_targeting_threshold_is_configurable(): void
    {
        $this->assertSame(0.75, IndustryClassifier::minConfidenceForPaid());
        $this->assertTrue(IndustryClassifier::isSellableForTargeting('classified', 0.75));
        $this->assertFalse(IndustryClassifier::isSellableForTargeting('classified', 0.50));

        $this->settings->set(AdSettings::MIN_CONFIDENCE_FOR_PAID, 0.50);

        $this->assertSame(0.50, IndustryClassifier::minConfidenceForPaid());
        $this->assertTrue(
            IndustryClassifier::isSellableForTargeting('classified', 0.50),
            'archetype-only inventory becomes sellable once the threshold is lowered'
        );
    }

    public function test_token_ttl_is_configurable(): void
    {
        $this->assertSame(300, AdTokenService::ttlSeconds());

        $this->settings->set(AdSettings::TOKEN_TTL_SECONDS, 90);

        $this->assertSame(90, AdTokenService::ttlSeconds());

        $issued = app(AdTokenService::class)->issue(1, 2, 3, 4);

        $this->assertLessThanOrEqual(now()->getTimestamp() + 90, $issued['expires_at']);
    }

    public function test_creative_min_short_edge_is_configurable(): void
    {
        $validator = app(AdCreativeValidator::class);
        $creative  = ['width' => 400, 'height' => 400, 'asset_url' => 'x'];

        $this->assertFalse($validator->validate($creative)['valid'], '400px is below the 600px default');

        $this->settings->set(AdSettings::CREATIVE_MIN_SHORT_EDGE, 300);

        $this->assertTrue($validator->validate($creative)['valid'], 'now accepted at a 300px floor');
    }

    public function test_reach_quote_floors_are_configurable(): void
    {
        $this->settings->set(AdSettings::REACH_MIN_SITES, 1);
        $this->settings->set(AdSettings::REACH_MIN_IMPRESSIONS, 0);

        $result = app(AdReachEstimator::class)->estimate([]);

        $this->assertSame(30, $result['history_days']);

        $this->settings->set(AdSettings::REACH_HISTORY_DAYS, 7);

        $this->assertSame(7, app(AdReachEstimator::class)->estimate([])['history_days']);
    }

    // ── Guardrails ──────────────────────────────────────────────────────

    /** @dataProvider dangerousValues */
    public function test_dangerous_values_are_refused_with_a_reason(string $key, mixed $value, string $expect): void
    {
        $objection = AdSettings::validate($key, $value);

        $this->assertNotNull(
            $objection,
            sprintf('%s=%s should have been objected to', $key, json_encode($value))
        );
        $this->assertStringContainsString($expect, $objection);
    }

    public static function dangerousValues(): array
    {
        return [
            'refresh below IAB floor' => [AdSettings::REFRESH_INTERVAL_MS, 5000, 'IAB'],
            'modal on first pageview' => [AdSettings::MODAL_MIN_PAGEVIEWS, 1, 'Google penalises'],
            'confidence too low'      => [AdSettings::MIN_CONFIDENCE_FOR_PAID, 0.2, 'archetype-only'],
            'close delay too long'    => [AdSettings::MODAL_CLOSE_DELAY_MS, 30000, 'legal sign-off'],
            'retention too short'     => [AdSettings::EVENT_RETENTION_DAYS, 1, 'dispute'],
            'blank disclosure'        => [AdSettings::DISCLOSURE_LABEL, '', 'legally required'],
            'empty plan list'         => [AdSettings::ELIGIBLE_PLAN_SLUGS, [], 'non-empty'],
        ];
    }

    /** @dataProvider safeValues */
    public function test_safe_values_are_accepted(string $key, mixed $value): void
    {
        $this->assertNull(AdSettings::validate($key, $value));
    }

    public static function safeValues(): array
    {
        return [
            'rotation off'        => [AdSettings::REFRESH_INTERVAL_MS, 0],
            'slower rotation'     => [AdSettings::REFRESH_INTERVAL_MS, 45000],
            'stricter modal'      => [AdSettings::MODAL_MIN_PAGEVIEWS, 3],
            'immediate dismissal' => [AdSettings::MODAL_CLOSE_DELAY_MS, 0],
            'stricter confidence' => [AdSettings::MIN_CONFIDENCE_FOR_PAID, 0.95],
            'more plans'          => [AdSettings::ELIGIBLE_PLAN_SLUGS, ['free', 'starter']],
        ];
    }

    // ── Provenance ──────────────────────────────────────────────────────

    public function test_every_change_is_audited(): void
    {
        $this->settings->set(AdSettings::REFRESH_INTERVAL_MS, 45000, null, 'test-actor');

        $row = DB::table('ad_audit_log')->where('action', 'settings.update')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('test-actor', $row->actor_label);
        $this->assertStringContainsString('30000', (string) $row->before);
        $this->assertStringContainsString('45000', (string) $row->after);
    }

    public function test_unknown_keys_are_rejected_rather_than_silently_ignored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->settings->set('not_a_real_setting', 1);
    }

    /**
     * The measurement standard is deliberately NOT tunable — an operator must
     * not be able to redefine "viewable" and bill on a non-standard metric.
     */
    public function test_measurement_standard_is_documented_as_fixed(): void
    {
        $fixed = AdSettings::fixedByDesign();

        $this->assertArrayHasKey('viewable_ratio (0.5)', $fixed);
        $this->assertArrayHasKey('viewable_ms (1000)', $fixed);
        $this->assertStringContainsString('IAB/MRC', $fixed['viewable_ratio (0.5)']);

        foreach (array_keys($fixed) as $what) {
            $this->assertFalse(
                AdSettings::isKnown($what),
                "{$what} is documented as fixed but is also a setting — contradictory"
            );
        }
    }

    public function test_every_setting_has_a_description(): void
    {
        foreach (AdSettings::definitions() as $key => [$default, $description]) {
            $this->assertNotEmpty(
                trim((string) $description),
                "{$key} has no description — an operator cannot judge whether changing it is safe"
            );
        }
    }
}
