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

    public function test_documents_missing_studio_execution_fields_gap(): void
    {
        $svc = $this->service(['tone' => 'professional', 'colors' => ['#111111']], 'B', [], []);
        $bp = $svc->getImageBlueprint(2, 'x', []);
        // GAP (Restoration Wave 2): the current brief does NOT yet carry the
        // Studio-execution fields the compiler needs; today these come from
        // ImageReasoningService. Documented, not a failure.
        foreach (['typography_strategy', 'dimensions', 'provider_prompt', 'aspect_ratio'] as $missing) {
            $this->assertArrayNotHasKey($missing, $bp,
                "GAP: getImageBlueprint does not yet emit '$missing' — Wave 2 must add it.");
        }
    }
}
