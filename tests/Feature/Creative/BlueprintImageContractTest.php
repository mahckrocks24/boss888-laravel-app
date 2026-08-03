<?php

namespace Tests\Feature\Creative;

use App\Connectors\DeepSeekConnector;
use App\Connectors\RuntimeClient;
use App\Engines\Creative\Services\BlueprintInfluenceService;
use App\Engines\Creative\Services\BlueprintRetrieverService;
use App\Engines\Creative\Services\BlueprintService;
use App\Engines\Creative\Services\CimsService;
use Mockery;
use Tests\TestCase;

/**
 * WAVE 0-1 · CREATIVE888 image-blueprint CONTRACT characterization.
 *
 * Pins the CURRENT output of BlueprintService::getImageBlueprint() — the existing
 * contract Studio must consume post-restoration — with ALL dependencies mocked:
 * NO database, NO provider execution, NO credits, NO persistence, NO flags.
 * Read-only intent: this does not change any behavior.
 */
class BlueprintImageContractTest extends TestCase
{
    private function service(array $identity, string $brandContext, array $stored, array $influence): BlueprintService
    {
        $cims = Mockery::mock(CimsService::class);
        $cims->shouldReceive('buildBrandContext')->andReturn($brandContext);
        $cims->shouldReceive('getBrandIdentity')->andReturn($identity);

        $retriever = Mockery::mock(BlueprintRetrieverService::class);
        $retriever->shouldReceive('getRelevant')->andReturn($stored);

        $inf = Mockery::mock(BlueprintInfluenceService::class);
        $inf->shouldReceive('apply')->andReturn($influence);

        // (6)(7)(8) The brief must perform NO provider execution and NO media
        // persistence — proven by asserting the connectors are never invoked.
        $llm = Mockery::mock(DeepSeekConnector::class);
        $runtime = Mockery::mock(RuntimeClient::class);
        $runtime->shouldNotReceive('imageGenerate');
        $runtime->shouldNotReceive('chatJson');

        return new BlueprintService($llm, $cims, $retriever, $inf, $runtime);
    }

    public function test_contract_shape_and_creative_ownership(): void
    {
        $svc = $this->service(
            ['visual_style' => 'modern minimal editorial', 'tone' => 'bold', 'colors' => ['#111111', '#e5b80b'], 'target_audience' => 'independent chefs'],
            'BRAND CONTEXT STRING',
            [], []
        );
        $bp = $svc->getImageBlueprint(2, 'A gourmet taco hero shot', ['style' => 'cinematic']);

        // (4) structured brief sufficient for Studio execution
        foreach (['type', 'enhanced_prompt', 'original_prompt', 'brand_context', 'visual_style', 'workspace_id', 'confidence', 'influence_applied', 'stored_blueprint_ids'] as $k) {
            $this->assertArrayHasKey($k, $bp, "brief must expose '$k'");
        }
        $this->assertSame('image', $bp['type']);
        $this->assertSame('A gourmet taco hero shot', $bp['original_prompt']);
        // (1)(2) Creative resolves creative context + reads brand source
        $this->assertStringContainsString('modern minimal editorial', $bp['enhanced_prompt']);
        $this->assertStringContainsString('cinematic', $bp['enhanced_prompt']);
        $this->assertSame('BRAND CONTEXT STRING', $bp['brand_context']);
        // (5) workspace-isolated
        $this->assertSame(2, $bp['workspace_id']);
    }

    public function test_reads_creative_memory_and_learning_when_available(): void
    {
        $svc = $this->service(
            ['visual_style' => 'x', 'tone' => 'professional', 'colors' => ['#000000']],
            'BRAND',
            [['_id' => 'bp1'], ['_id' => 'bp2']],
            ['modified' => true, 'final_prompt' => 'INFLUENCED PROMPT']
        );
        $bp = $svc->getImageBlueprint(7, 'menu backdrop', []);
        // (3) reads creative memory + applies creative learning
        $this->assertTrue($bp['influence_applied']);
        $this->assertSame(['bp1', 'bp2'], $bp['stored_blueprint_ids']);
        $this->assertSame('INFLUENCED PROMPT', $bp['enhanced_prompt']);
    }

    public function test_fallback_structurally_usable_with_no_brand_or_memory(): void
    {
        // (9) still structurally usable when brand/memory absent
        $svc = $this->service(['tone' => 'professional', 'colors' => []], 'BRAND', [], []);
        $bp = $svc->getImageBlueprint(3, 'a product', []);
        $this->assertSame('image', $bp['type']);
        $this->assertNotEmpty($bp['enhanced_prompt']);
        $this->assertFalse($bp['influence_applied']);
    }

    public function test_enriched_brief_carries_full_studio_execution_direction(): void
    {
        // WAVE 2 PHASE 1: the Wave-0-1 gap is now CLOSED — the Creative888 brief
        // carries the complete creative direction Studio needs. All deterministic:
        // no LLM, no provider (connector shouldNotReceive), no persistence, no billing.
        $svc = $this->service(
            ['visual_style' => 'editorial', 'tone' => 'bold', 'colors' => ['#111111', '#e5b80b'], 'target_audience' => 'independent chefs'],
            'BRAND', [], []
        );
        $bp = $svc->getImageBlueprint(2, 'a gourmet taco', [
            'asset_type' => 'poster', 'platform' => 'instagram', 'requested_quality' => 'high', 'headline' => 'Taco Tuesday',
        ]);

        foreach (['intent', 'subject', 'audience', 'platform', 'asset_type', 'provider_prompt', 'aspect_ratio',
                  'dimensions', 'quality', 'composition', 'visual_hierarchy', 'lighting', 'mood',
                  'color_palette', 'brand_application', 'negative_constraints', 'typography_strategy'] as $k) {
            $this->assertArrayHasKey($k, $bp, "enriched brief must expose '$k'");
        }
        // provider_prompt is the brand-enriched prompt (Studio compiler input)
        $this->assertSame($bp['enhanced_prompt'], $bp['provider_prompt']);
        // audience + palette sourced from the brand identity (CimsService)
        $this->assertSame('independent chefs', $bp['audience']);
        $this->assertSame(['#111111', '#e5b80b'], $bp['color_palette']);
        // quality recommendation honours the request
        $this->assertSame('high', $bp['quality']);
        // dimensions snapped to a provider-supported size
        $this->assertContains($bp['dimensions']['width'] . 'x' . $bp['dimensions']['height'], ['1024x1024', '1024x1536', '1536x1024']);
        // typography: headline present → separate_overlay handed to the Studio layer
        $this->assertSame('separate_overlay', $bp['typography_strategy']['mode']);
        $this->assertSame('Taco Tuesday', $bp['typography_strategy']['headline']);
    }

    public function test_typography_none_when_no_copy_supplied(): void
    {
        $svc = $this->service(['tone' => 'professional', 'colors' => []], 'B', [], []);
        $bp = $svc->getImageBlueprint(2, 'a product', ['asset_type' => 'social_post']);
        $this->assertSame('none', $bp['typography_strategy']['mode']);
        $this->assertSame([], $bp['typography_strategy']['supporting_copy']);
    }

    public function test_enrichment_still_reads_brand_memory_learning_and_never_executes(): void
    {
        // brand/memory/learning still flow through the SAME Creative888 services,
        // and the connectors are still never called (no provider/no billing) —
        // enforced by the shouldNotReceive() expectations in service().
        $svc = $this->service(
            ['visual_style' => 'x', 'tone' => 'professional', 'colors' => ['#000000']],
            'BRAND', [['_id' => 'bpA']], ['modified' => true, 'final_prompt' => 'INF']
        );
        $bp = $svc->getImageBlueprint(9, 'menu backdrop', ['asset_type' => 'menu']);
        $this->assertTrue($bp['influence_applied']);        // learning (BlueprintInfluenceService)
        $this->assertSame(['bpA'], $bp['stored_blueprint_ids']); // memory (BlueprintRetrieverService)
        $this->assertSame('INF', $bp['provider_prompt']);   // enriched from the same enhanced prompt
        $this->assertSame(9, $bp['workspace_id']);          // workspace-isolated
    }
}
