<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\ImageIntelligence\ImageIntelligenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 — deterministic FALLBACK image-quality characterization.
 *
 * When the reasoning model is unavailable (runtime timeout), Studio must still
 * emit a rich, category-aware, brand-grounded provider prompt — not a generic
 * "marketing visual of X". Pins the enriched fallback so it cannot regress.
 *
 * DatabaseTransactions (not RefreshDatabase) purely for speed — this suite only
 * reads the seeded workspace/brand; it asserts prompt content, not schema.
 */
class StudioFallbackQualityTest extends TestCase
{
    use DatabaseTransactions, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        // Force the deterministic fallback: reasoning "configured" but chatJson fails.
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('chatJson')->andReturn(['success' => false, 'error' => 'forced']);
        $this->app->instance(RuntimeClient::class, $rt);
    }

    private function fallbackPrompt(array $extra = []): string
    {
        $this->app->forgetInstance(ImageIntelligenceService::class);
        $plan = app(ImageIntelligenceService::class)->plan(array_merge([
            'source' => 'studio', 'workspace_id' => $this->testWorkspace->id, 'user_prompt' => 'a plated dish',
        ], $extra));
        $this->assertTrue($plan['reasoning']['fallback'] ?? false, 'must be on the fallback path');
        return (string) $plan['compiled']['provider_prompt'];
    }

    /** @test */
    public function food_category_gets_food_photography_framing(): void
    {
        $p = $this->fallbackPrompt(['asset_type' => 'food_photo', 'user_prompt' => 'seared duck breast']);
        $this->assertStringContainsStringIgnoringCase('food photograph', $p);
        $this->assertStringContainsStringIgnoringCase('seared duck breast', $p);
        $this->assertStringContainsStringIgnoringCase('no text', $p);
    }

    /** @test */
    public function menu_category_gets_fine_dining_background_with_negative_space(): void
    {
        $p = $this->fallbackPrompt(['asset_type' => 'menu', 'user_prompt' => 'tasting menu']);
        $this->assertStringContainsStringIgnoringCase('fine-dining background', $p);
        $this->assertStringContainsStringIgnoringCase('negative space', $p);
    }

    /** @test */
    public function advertisement_gets_promotional_key_visual_framing(): void
    {
        $p = $this->fallbackPrompt(['asset_type' => 'advertisement', 'user_prompt' => 'private dining', 'style' => 'bold']);
        $this->assertStringContainsStringIgnoringCase('promotional key visual', $p);
        $this->assertStringContainsStringIgnoringCase('high contrast', $p); // bold style modifier
    }

    /** @test */
    public function portrait_gets_environmental_portrait_framing(): void
    {
        $p = $this->fallbackPrompt(['asset_type' => 'portrait', 'user_prompt' => 'the chef']);
        $this->assertStringContainsStringIgnoringCase('environmental portrait', $p);
    }

    /** @test */
    public function brand_override_is_folded_into_the_scene_not_appended_as_text(): void
    {
        $p = $this->fallbackPrompt(['asset_type' => 'social_post', 'user_prompt' => 'signature dish',
            'primary_color' => '#C1272D', 'visual_style' => 'moody fine-dining']);
        $this->assertStringContainsStringIgnoringCase('#C1272D', $p);
        $this->assertStringContainsStringIgnoringCase('through props', $p);       // integrated, not text
        $this->assertStringContainsStringIgnoringCase('moody fine-dining', $p);   // visual_style honoured
        $this->assertStringContainsStringIgnoringCase('never as text', $p);
    }

    /** @test */
    public function verb_leading_prompt_is_not_mangled(): void
    {
        $prompt = 'Create a sophisticated promotional image for the private chef service';
        $p = $this->fallbackPrompt(['asset_type' => 'social_post', 'user_prompt' => $prompt]);
        // Must read as a directive (starts with the prompt), NOT "... of Create a ...".
        $this->assertStringStartsWith($prompt, $p);
        $this->assertStringNotContainsString('of Create', $p);
    }

    /** @test */
    public function empty_prompt_falls_back_to_a_sensible_subject(): void
    {
        $p = $this->fallbackPrompt(['asset_type' => 'food_photo', 'user_prompt' => '']);
        $this->assertStringContainsStringIgnoringCase('gourmet dish', $p);
        $this->assertNotEmpty($p);
    }

    /** @test */
    public function every_fallback_prompt_carries_composition_lighting_and_no_text(): void
    {
        foreach (['social_post','food_photo','menu','advertisement','portrait','story','cover'] as $type) {
            $p = $this->fallbackPrompt(['asset_type' => $type, 'user_prompt' => 'x']);
            $this->assertStringContainsStringIgnoringCase('composition:', $p, "$type composition");
            $this->assertStringContainsStringIgnoringCase('lighting:', $p, "$type lighting");
            $this->assertStringContainsStringIgnoringCase('no text', $p, "$type no-text rule");
        }
    }
}
