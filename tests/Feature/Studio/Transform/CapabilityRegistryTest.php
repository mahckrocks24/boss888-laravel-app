<?php

namespace Tests\Feature\Studio\Transform;

use App\Engines\Studio\Transform\Capability;
use App\Engines\Studio\Transform\CapabilityRegistry;
use App\Engines\Studio\Transform\OperationRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Pure — no DB, Runtime, providers, browser, credits, image-gen, or billing.
 */
final class CapabilityRegistryTest extends TestCase
{
    private function caps(): CapabilityRegistry
    {
        return new CapabilityRegistry(new OperationRegistry(true));
    }

    public function test_available_capabilities_are_discoverable(): void
    {
        $c = $this->caps();
        $this->assertTrue($c->isAvailable('set_style'));
        $this->assertTrue($c->isAvailable('replace_media'));
        $this->assertTrue($c->isAvailable('generate_image'));
        $this->assertTrue($c->isAvailable('remove_background'));
        $this->assertInstanceOf(Capability::class, $c->get('set_style'));
    }

    public function test_unavailable_operations_are_not_plannable(): void
    {
        $c = $this->caps();
        $this->assertFalse($c->isAvailable('generate_video'));
        $this->assertFalse($c->isAvailable('trim_video'));
        $this->assertFalse($c->isAvailable('replace_audio'));

        $keys = array_keys($c->unavailable());
        $this->assertContains('generate_video', $keys);
        $this->assertNotContains('generate_video', array_keys($c->available()));
    }

    public function test_unknown_operation_returns_null_and_is_unavailable(): void
    {
        $c = $this->caps();
        $this->assertNull($c->get('does_not_exist'));
        $this->assertFalse($c->isAvailable('does_not_exist'));
    }

    public function test_capability_is_a_projection_of_definition_metadata(): void
    {
        $reg = new OperationRegistry(true);
        $c = new CapabilityRegistry($reg);

        $def = $reg->get('generate_image');
        $cap = $c->get('generate_image');

        // No duplicated metadata — the capability mirrors the definition exactly.
        $this->assertSame($def->affectsBilling, $cap->affectsBilling);
        $this->assertSame($def->requiresCreative888, $cap->requiresCreative888);
        $this->assertSame($def->requiresConfirmation, $cap->requiresConfirmation);
        $this->assertSame($def->isAvailable(), $cap->available);
        $this->assertSame($def->supportedMedia, $cap->supportedMedia);
        $this->assertSame($def->estimatedComplexity, $cap->estimatedComplexity);
        // generation is expensive; a plain text set is instant.
        $this->assertSame('expensive', $cap->estimatedComplexity);
        $this->assertSame('instant', $c->get('set_text')->estimatedComplexity);
    }

    public function test_capability_serialises_to_discovery_shape(): void
    {
        $arr = $this->caps()->get('replace_media')->toArray();
        foreach ([
            'operation', 'available', 'enabled', 'maturity', 'supported_media', 'supported_targets',
            'required_capabilities', 'requires_creative888', 'requires_confirmation', 'affects_billing',
            'reversible', 'collaborative', 'animation_support', 'estimated_complexity',
        ] as $key) {
            $this->assertArrayHasKey($key, $arr, "missing discovery key: $key");
        }
        $this->assertSame('replace_media', $arr['operation']);
    }

    public function test_all_returns_every_registered_operation(): void
    {
        $reg = new OperationRegistry(true);
        $this->assertSame(array_keys($reg->all()), array_keys((new CapabilityRegistry($reg))->all()));
    }
}
