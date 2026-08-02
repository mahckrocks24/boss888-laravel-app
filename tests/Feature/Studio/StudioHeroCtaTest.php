<?php

namespace Tests\Feature\Studio;

use Tests\TestCase;

/**
 * STUDIO888 P4 — AI hero-image CTA (Option B) source-characterization guard.
 *
 * The CTA lives in public/app/js/studio.js. These assertions pin the invariants that
 * make P4 safe & correct so a future edit cannot silently regress them. Behavioural
 * proof is the live browser QA (see LVL/BOSS888-STUDIO888-P4-AI-HERO-IMAGE-UX.md);
 * this is the fast, no-DB regression net.
 */
class StudioHeroCtaTest extends TestCase
{
    private string $src = '';

    protected function setUp(): void
    {
        parent::setUp();
        $path = base_path('public/app/js/studio.js');
        $this->assertFileExists($path, 'Studio SPA must exist.');
        $this->src = file_get_contents($path);
    }

    /** Isolate the CTA click handler body for scoped assertions. */
    private function heroHandler(): string
    {
        $start = strpos($this->src, 'window._st2GenerateHeroImage');
        $this->assertNotFalse($start, 'The hero-image CTA handler must exist.');
        // grab a generous slice (the function body is well under 2k chars)
        return substr($this->src, $start, 2000);
    }

    public function test_cta_element_and_handler_exist(): void
    {
        $this->assertStringContainsString('id="st-hero-cta"', $this->src, 'The CTA element must be rendered in the editor.');
        $this->assertStringContainsString('_st2GenerateHeroImage', $this->src, 'The CTA click handler must exist.');
        $this->assertStringContainsString('Uses 1 image credit', $this->src, 'The CTA must disclose the 1-credit cost.');
    }

    public function test_cta_reuses_generate_image_and_never_calls_arthur(): void
    {
        $body = $this->heroHandler();
        $this->assertStringContainsString('/studio/ai/generate-image', $body, 'The CTA must reuse the existing image-generation endpoint.');
        $this->assertStringNotContainsString('/studio/ai/generate-design', $body, 'The CTA must NOT re-run Arthur (generate-design).');
    }

    public function test_cta_reuses_stored_prompt_not_a_new_one(): void
    {
        $body = $this->heroHandler();
        $this->assertStringContainsString('_st2HeroPrompts', $body, 'The CTA must reuse Arthur\'s stored hero prompt.');
    }

    public function test_cta_replaces_only_the_hero_image_field(): void
    {
        $body = $this->heroHandler();
        // Uses the field-scoped image update (replace one field), never addElement (which would add a stray element).
        $this->assertStringContainsString("type: 'lu-update-image'", $body, 'The CTA must replace a single named image field.');
        $this->assertStringNotContainsString('addElement', $body, 'The CTA must not add a new element.');
        $this->assertStringContainsString('_st2HeroFieldName()', $body, 'The CTA must target the resolved hero field only.');
    }

    public function test_hero_field_resolver_prefers_hero_then_background_then_single(): void
    {
        $start = strpos($this->src, 'function _st2HeroFieldName');
        $this->assertNotFalse($start);
        $resolver = substr($this->src, $start, 700);
        $this->assertStringContainsString("f.name === 'hero_image'", $resolver, 'Resolver must prefer an explicit hero_image field.');
        $this->assertStringContainsString('background', $resolver, 'Resolver must fall back to a background/primary image field (posters).');
        $this->assertStringContainsString('imgs.length === 1', $resolver, 'Resolver must accept the sole image field when unambiguous.');
    }
}
