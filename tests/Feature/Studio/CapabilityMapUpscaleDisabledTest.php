<?php

namespace Tests\Feature\Studio;

use App\Core\EngineKernel\CapabilityMapService;
use Tests\TestCase;

/**
 * Phase G Fix 3 — disable the dead `upscale_image` capability mapping.
 *
 * `upscale_image` was removed in Phase 2A (no engine implements the action;
 * EngineExecutionService throws "Unknown Creative action: upscale_image") but was
 * re-introduced in error by Wave 23. Fix 3 comments it out of CapabilityMapService.
 *
 * Regression contract — the change removes EXACTLY one capability and nothing else:
 *   1. a golden snapshot of the map (captured live, minus upscale_image) is the baseline
 *   2. upscale_image is no longer resolvable
 *   3. the live map equals the golden byte-for-byte (all other capabilities identical)
 *   4. the count dropped by exactly one, and the sole removed key is upscale_image
 *   5. representative unrelated capabilities resolve to their exact prior metadata
 */
class CapabilityMapUpscaleDisabledTest extends TestCase
{
    /** The count the map had BEFORE Fix 3 (golden + the one disabled entry). */
    private const COUNT_BEFORE = 134;

    /** The exact metadata upscale_image carried before it was disabled. */
    private const UPSCALE_BEFORE = [
        'engine' => 'creative', 'connector' => 'creative', 'action' => 'upscale_image',
        'approval_mode' => 'auto', 'credit_cost' => 1,
    ];

    private function golden(): array
    {
        $path = __DIR__ . '/fixtures/capability_map_golden.json';
        $this->assertFileExists($path, 'golden capability-map fixture must exist');

        return json_decode(file_get_contents($path), true);
    }

    private function service(): CapabilityMapService
    {
        return app(CapabilityMapService::class);
    }

    /** @test */
    public function golden_baseline_is_the_captured_before_map_minus_upscale(): void
    {
        $golden = $this->golden();
        $this->assertCount(self::COUNT_BEFORE - 1, $golden);
        $this->assertArrayNotHasKey('upscale_image', $golden);
    }

    /** @test */
    public function upscale_image_is_no_longer_resolvable(): void
    {
        $svc = $this->service();

        $this->assertArrayNotHasKey('upscale_image', $svc->getAllCapabilities());
        $this->assertNull($svc->resolve('upscale_image'));
        // accessors degrade to their safe null-fallbacks for an unknown action
        $this->assertSame(0, $svc->getCreditCost('upscale_image'));
        $this->assertSame('review', $svc->getApprovalMode('upscale_image'));
        $this->assertFalse($svc->isConnectorAvailable('upscale_image'));
    }

    /** @test */
    public function every_other_capability_is_byte_for_byte_identical(): void
    {
        // strict, order-sensitive equality — catches any reorder, mutation, add, or drop
        $this->assertSame($this->golden(), $this->service()->getAllCapabilities());
    }

    /** @test */
    public function exactly_one_capability_was_removed_and_it_is_upscale_image(): void
    {
        $live = $this->service()->getAllCapabilities();
        $this->assertCount(self::COUNT_BEFORE - 1, $live);

        // reconstruct the pre-fix map and confirm the ONLY delta is upscale_image
        $before = $this->golden() + ['upscale_image' => self::UPSCALE_BEFORE];
        $this->assertCount(self::COUNT_BEFORE, $before);

        $removed = array_keys(array_diff_key($before, $live));
        $this->assertSame(['upscale_image'], $removed);
    }

    /** @test */
    public function representative_unrelated_capabilities_are_unchanged(): void
    {
        $svc = $this->service();

        // the sibling creative caps that must NOT be touched
        $this->assertSame(
            ['engine' => 'creative', 'connector' => 'creative', 'action' => 'generate_image', 'approval_mode' => 'auto', 'credit_cost' => 2],
            $svc->resolve('generate_image')
        );
        $this->assertSame(
            ['engine' => 'creative', 'connector' => 'creative', 'action' => 'generate_video', 'approval_mode' => 'review', 'credit_cost' => 8],
            $svc->resolve('generate_video')
        );
        $this->assertSame(
            ['engine' => 'creative', 'connector' => null, 'action' => 'generate_image_high', 'approval_mode' => 'auto', 'credit_cost' => 4],
            $svc->resolve('generate_image_high')
        );
        // an unrelated non-creative capability
        $this->assertSame(
            ['engine' => 'social', 'connector' => 'creative', 'action' => 'social_image', 'approval_mode' => 'auto', 'credit_cost' => 1],
            $svc->resolve('social_image')
        );
    }
}
