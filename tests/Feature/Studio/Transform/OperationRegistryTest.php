<?php

namespace Tests\Feature\Studio\Transform;

use App\Engines\Studio\Transform\OperationDefinition;
use App\Engines\Studio\Transform\OperationRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Pure — no DB, Runtime, providers, browser, credits, image-gen, or billing.
 */
final class OperationRegistryTest extends TestCase
{
    private function reg(): OperationRegistry
    {
        return new OperationRegistry(true);
    }

    public function test_default_vocabulary_is_registered(): void
    {
        $r = $this->reg();
        foreach (['set_text', 'set_style', 'move', 'resize', 'align', 'layer_order', 'replace_media', 'generate_image', 'remove_background'] as $type) {
            $this->assertTrue($r->has($type), "missing default op: $type");
            $this->assertInstanceOf(OperationDefinition::class, $r->get($type));
        }
    }

    public function test_unknown_operation_is_absent(): void
    {
        $r = $this->reg();
        $this->assertFalse($r->has('teleport_element'));
        $this->assertNull($r->get('teleport_element'));
    }

    public function test_operations_self_register_without_touching_defaults(): void
    {
        $r = new OperationRegistry(false);
        $this->assertSame([], $r->all());

        $r->register(new OperationDefinition(type: 'set_gradient', category: 'style'));
        $this->assertTrue($r->has('set_gradient'));
        $this->assertCount(1, $r->all());
    }

    public function test_register_overrides_same_type(): void
    {
        $r = new OperationRegistry(false);
        $r->register(new OperationDefinition(type: 'x', category: 'style', maturity: OperationDefinition::MATURITY_BETA));
        $r->register(new OperationDefinition(type: 'x', category: 'style', maturity: OperationDefinition::MATURITY_STABLE));
        $this->assertSame(OperationDefinition::MATURITY_STABLE, $r->get('x')->maturity);
    }

    public function test_schema_version_is_one(): void
    {
        $this->assertSame(1, $this->reg()->schemaVersion());
    }

    public function test_metadata_completeness_for_every_definition(): void
    {
        $r = $this->reg();
        $maturities = [
            OperationDefinition::MATURITY_UNAVAILABLE, OperationDefinition::MATURITY_EXPERIMENTAL,
            OperationDefinition::MATURITY_BETA, OperationDefinition::MATURITY_STABLE,
        ];
        $kinds = [
            OperationDefinition::KIND_NONE, OperationDefinition::KIND_COLOR, OperationDefinition::KIND_LENGTH,
            OperationDefinition::KIND_NUMBER, OperationDefinition::KIND_PERCENT, OperationDefinition::KIND_ANGLE,
            OperationDefinition::KIND_TEXT, OperationDefinition::KIND_ENUM, OperationDefinition::KIND_MEDIA,
        ];
        $complexities = [
            OperationDefinition::COMPLEXITY_INSTANT, OperationDefinition::COMPLEXITY_LIGHT,
            OperationDefinition::COMPLEXITY_MEDIUM, OperationDefinition::COMPLEXITY_HEAVY,
            OperationDefinition::COMPLEXITY_EXPENSIVE,
        ];

        foreach ($r->all() as $type => $def) {
            $this->assertIsString($def->type);
            $this->assertNotSame('', $def->type, "empty type");
            $this->assertSame($type, $def->type);
            $this->assertNotSame('', $def->category, "$type: empty category");
            $this->assertContains($def->maturity, $maturities, "$type: bad maturity");
            $this->assertContains($def->valueKind, $kinds, "$type: bad value kind");
            $this->assertNotEmpty($def->supportedMedia, "$type: empty supported media");
            $this->assertNotEmpty($def->supportedTargets, "$type: empty supported targets");
            $this->assertContains($def->estimatedComplexity, $complexities, "$type: bad estimated complexity");
            $this->assertSame(1, $def->schemaVersion, "$type: schema drift");
            if ($def->valueKind === OperationDefinition::KIND_ENUM) {
                $this->assertNotEmpty($def->enum, "$type: enum kind with no values");
            }
        }
    }

    public function test_unavailable_media_ops_are_declared_but_not_available(): void
    {
        $r = $this->reg();
        foreach (['generate_video', 'trim_video', 'replace_audio'] as $type) {
            $this->assertTrue($r->has($type), "$type should be declared");
            $this->assertFalse($r->get($type)->isAvailable(), "$type must be unavailable");
        }
    }
}
