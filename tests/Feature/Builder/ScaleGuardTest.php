<?php

namespace Tests\Feature\Builder;

use App\Engines\Builder\Support\ScaleGuard;
use Tests\TestCase;

/**
 * The serve-time scale + overflow guard (2026-09-20). Owner: templates were "too large" on phone and desktop and images
 * ran past the screen; the reference scale is the MR Systems site (h1 56/34, h2 36/26, body 15/14).
 */
class ScaleGuardTest extends TestCase
{
    private string $page = '<html><head><title>x</title></head><body><section data-block="hero"><h1>Hi</h1></section></body></html>';

    public function test_overflow_guard_is_injected_for_every_design_even_one_outside_the_scale_lists(): void
    {
        $out = ScaleGuard::inject($this->page, 'some_unknown_design');
        $this->assertStringContainsString('id="lu-overflow-guard"', $out);
        $this->assertStringNotContainsString('id="' . ScaleGuard::ID . '"', $out, 'unknown designs get no scale tier');
        $this->assertStringContainsString('min-width:0', $out);
        $this->assertStringContainsString('[data-block] input,[data-block] select,[data-block] textarea{min-width:0;max-width:100%;box-sizing:border-box}', $out);
    }

    public function test_base_design_gets_the_full_tiers_at_the_reference_scale(): void
    {
        $out = ScaleGuard::inject($this->page, 'event_venue');
        $this->assertStringContainsString('id="' . ScaleGuard::ID . '"', $out);
        $this->assertStringContainsString('clamp(40px,4.4vw,56px)', $out, 'desktop h1 caps at 56');
        $this->assertStringContainsString('clamp(28px,8.2vw,34px)', $out, 'phone h1 caps at 34');
        $this->assertMatchesRegularExpression('/p,[^{]*li\{font-size:15px!important/', $out, 'desktop body 15px');
        $this->assertMatchesRegularExpression('/p,[^{]*li\{font-size:14px!important/', $out, 'phone body 14px');
        // card media never gets a max-height (Chrome sizes the grid row by the image and hides the card text)
        $this->assertStringContainsString('aspect-ratio:16/9!important;height:auto!important;max-height:none!important', $out);
        $this->assertStringNotContainsString('.gallery-item', $out, 'gallery cells are not card media');
    }

    public function test_variant_design_gets_only_the_hero_tier(): void
    {
        $out = ScaleGuard::inject($this->page, 'venue_exclusive');
        $this->assertStringContainsString('LU scale guard (hero)', $out);
        $this->assertStringNotContainsString('p,li', $out);
    }

    public function test_injection_is_idempotent(): void
    {
        $once = ScaleGuard::inject($this->page, 'event_venue');
        $twice = ScaleGuard::inject($once, 'event_venue');
        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'id="lu-overflow-guard"'));
        $this->assertSame(1, substr_count($twice, 'id="' . ScaleGuard::ID . '"'));
    }
}
