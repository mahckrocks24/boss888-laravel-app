<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\Brand\WorkspaceBrandKitResolver;
use App\Core\ImageIntelligence\ImageIntelligenceService;
use App\Core\ImageIntelligence\ImageReasoningService;
use App\Engines\Studio\Services\StudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * CRP-001 WP2 Phase 2.1D-B — brand OVERRIDE PRECEDENCE contract.
 *
 * Precedence (per field): explicit request override (only fields supplied)
 *   > workspace brand (WorkspaceBrandKitResolver) > neutral fallback.
 * Non-overridden workspace fields are retained; null/absent overrides never
 * erase; workspace identity is never overridable.
 */
class StudioImageBrandOverrideTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** Give a workspace a real brand: studio kit (colours+fonts) + creative identity (voice/tone). */
    private function seedBrand(int $wsId, string $primary = '#111111'): void
    {
        DB::table('studio_brand_kits')->where('workspace_id', $wsId)->delete();
        DB::table('studio_brand_kits')->insert([
            'workspace_id' => $wsId, 'primary_color' => $primary, 'secondary_color' => '#222222',
            'accent_color' => '#333333', 'heading_font' => 'Inter', 'body_font' => 'Roboto',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('creative_brand_identities')->where('workspace_id', $wsId)->delete();
        DB::table('creative_brand_identities')->insert([
            'workspace_id' => $wsId, 'voice' => 'confident', 'tone' => 'bold',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Deterministic merged brand (fallback runtime → no LLM). */
    private function brandFor(int $wsId, array $extra = []): array
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(RuntimeClient::class, $rt);
        $this->app->forgetInstance(ImageIntelligenceService::class);
        $this->app->forgetInstance(ImageReasoningService::class);
        $plan = app(ImageIntelligenceService::class)->plan(array_merge(
            ['source' => 'studio', 'workspace_id' => $wsId, 'user_prompt' => 'x'], $extra
        ));
        return $plan['context']['brand'] ?? [];
    }

    /** @test */ public function case1_workspace_brand_no_override(): void
    {
        $this->seedBrand($this->testWorkspace->id);
        $b = $this->brandFor($this->testWorkspace->id);
        $this->assertSame('#111111', $b['colors'][0]);
        $this->assertSame('confident', $b['voice']);
        $this->assertSame('Inter', $b['heading_font']);
    }

    /** @test */ public function case2_primary_color_override_only(): void
    {
        $this->seedBrand($this->testWorkspace->id);
        $b = $this->brandFor($this->testWorkspace->id, ['primary_color' => '#FF0000']);
        $this->assertSame('#FF0000', $b['colors'][0], 'primary overridden');
        $this->assertSame('#222222', $b['colors'][1], 'ws secondary RETAINED (not re-derived)');
        $this->assertSame('confident', $b['voice'], 'ws voice retained');
        $this->assertSame('Inter', $b['heading_font'], 'ws typography retained');
    }

    /** @test */ public function case3_multiple_overrides(): void
    {
        $this->seedBrand($this->testWorkspace->id);
        $b = $this->brandFor($this->testWorkspace->id, ['primary_color' => '#FF0000', 'voice' => 'playful', 'heading_font' => 'Poppins']);
        $this->assertSame('#FF0000', $b['colors'][0]);
        $this->assertSame('playful', $b['voice']);
        $this->assertSame('Poppins', $b['heading_font']);
        $this->assertSame('bold', $b['tone'], 'non-overridden tone retained');
    }

    /** @test */ public function case4_absent_fields_preserve_workspace(): void
    {
        $this->seedBrand($this->testWorkspace->id);
        $b = $this->brandFor($this->testWorkspace->id, ['primary_color' => '#FF0000']);
        $this->assertSame(['#FF0000', '#222222', '#333333'], $b['colors'], 'only primary changed; secondary+accent preserved');
        $this->assertSame('Roboto', $b['body_font']);
    }

    /** @test */ public function case5_null_and_empty_overrides_do_not_erase(): void
    {
        $this->seedBrand($this->testWorkspace->id);
        $b = $this->brandFor($this->testWorkspace->id, ['primary_color' => null, 'voice' => '', 'heading_font' => null]);
        $this->assertSame('#111111', $b['colors'][0], 'null primary did not erase ws');
        $this->assertSame('confident', $b['voice'], 'empty voice did not erase ws');
        $this->assertSame('Inter', $b['heading_font']);
    }

    /** @test */ public function case6_unbranded_plus_explicit_color(): void
    {
        // no seedBrand → neutral workspace
        $b = $this->brandFor($this->testWorkspace->id, ['primary_color' => '#FF0000']);
        $this->assertSame(['#FF0000'], $b['colors'], 'explicit colour applies on unbranded ws');
        $this->assertArrayNotHasKey('voice', $b, 'no neutral-default voice leaked');
        $this->assertArrayNotHasKey('brand_name', $b, 'no workspace-name leaked as brand');
    }

    /** @test */ public function case7_white_label_plus_permitted_override(): void
    {
        $b = $this->brandFor($this->testWorkspace->id, ['brand' => ['colors' => ['#00FF00']]]);
        $this->assertSame(['#00FF00'], $b['colors']);
        $this->assertArrayNotHasKey('voice', $b, 'white-label neutral not leaked');
    }

    /** @test */ public function case8_workspace_identity_override_is_rejected(): void
    {
        $this->seedBrand($this->testWorkspace->id, '#111111');
        // attempt to inject a different workspace id via the brand override:
        $b = $this->brandFor($this->testWorkspace->id, ['brand' => ['workspace_id' => 999999, 'primary_color' => '#ABCDEF']]);
        $this->assertSame('#ABCDEF', $b['colors'][0], 'colour override applied for the AUTHENTICATED ws');
        $this->assertSame('#222222', $b['colors'][1], 'still the authenticated ws brand (identity not hijacked)');
    }

    /** @test */ public function case9_cross_workspace_isolation(): void
    {
        $wsA = $this->testWorkspace->id;
        $this->seedBrand($wsA, '#AAAAAA');
        $wsB = $this->createAdditionalWorkspace('B')->id;
        $this->seedBrand($wsB, '#BBBBBB');
        $this->assertSame('#AAAAAA', $this->brandFor($wsA)['colors'][0]);
        $this->assertSame('#BBBBBB', $this->brandFor($wsB)['colors'][0]);
    }

    /** @test */ public function case10_resolver_called_exactly_once(): void
    {
        $resolver = \Mockery::spy(WorkspaceBrandKitResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['is_neutral' => true]);
        $this->app->instance(WorkspaceBrandKitResolver::class, $resolver);
        $this->brandFor($this->testWorkspace->id, ['primary_color' => '#FF0000']);
        $resolver->shouldHaveReceived('resolve')->once();
        $this->addToAssertionCount(1);
    }

    /** @test */ public function case11_no_direct_getBrandKit_bypass(): void
    {
        $studio = \Mockery::spy(StudioService::class);
        $this->app->instance(StudioService::class, $studio);
        $this->brandFor($this->testWorkspace->id, ['primary_color' => '#FF0000']);
        $studio->shouldNotHaveReceived('getBrandKit');
        $this->addToAssertionCount(1);
    }

    /** @test */ public function case12_final_compiled_blueprint_reflects_override(): void
    {
        $this->seedBrand($this->testWorkspace->id);
        // REAL reasoner (no fallback binding) so the merged brand reaches the blueprint.
        $plan = app(ImageIntelligenceService::class)->plan([
            'source' => 'studio', 'platform' => 'linkedin', 'asset_type' => 'social_post',
            'workspace_id' => $this->testWorkspace->id, 'user_prompt' => 'A milestone post',
            'primary_color' => '#FF0000',
        ]);
        // context carries the merged override (deterministic):
        $this->assertSame('#FF0000', $plan['context']['brand']['colors'][0]);
        // final blueprint/compiled reflects the merged colour (LLM reliably carries brand colours):
        $blob = strtolower(json_encode($plan['blueprint']) . '|' . json_encode($plan['compiled']));
        $this->assertStringContainsString('ff0000', $blob, 'the override colour must appear in the final blueprint/compiled output');
    }
}
