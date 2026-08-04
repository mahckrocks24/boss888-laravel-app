<?php

namespace Tests\Feature\Studio\Transform;

use App\Engines\Studio\Transform\CapabilityRegistry;
use App\Engines\Studio\Transform\ColorNormalizer;
use App\Engines\Studio\Transform\Contracts\CapabilityRegistryInterface;
use App\Engines\Studio\Transform\Contracts\ColorNormalizerInterface;
use App\Engines\Studio\Transform\Contracts\OperationRegistryInterface;
use App\Engines\Studio\Transform\Contracts\OperationValidatorInterface;
use App\Engines\Studio\Transform\OperationRegistry;
use App\Engines\Studio\Transform\OperationValidator;
use App\Engines\Studio\Transform\StudioTransformServiceProvider;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Dependency inversion: every subsystem resolves through its interface, so an
 * implementation can be swapped without touching callers. Pure — a bare
 * container, no app/DB/Runtime boot.
 */
final class StudioTransformBindingTest extends TestCase
{
    private function container(): Container
    {
        $c = new Container();
        (new StudioTransformServiceProvider($c))->register();

        return $c;
    }

    public function test_interfaces_resolve_to_default_implementations(): void
    {
        $c = $this->container();

        $this->assertInstanceOf(ColorNormalizer::class, $c->make(ColorNormalizerInterface::class));
        $this->assertInstanceOf(OperationRegistry::class, $c->make(OperationRegistryInterface::class));
        $this->assertInstanceOf(CapabilityRegistry::class, $c->make(CapabilityRegistryInterface::class));
        $this->assertInstanceOf(OperationValidator::class, $c->make(OperationValidatorInterface::class));
    }

    public function test_bindings_are_singletons(): void
    {
        $c = $this->container();
        $this->assertSame($c->make(OperationRegistryInterface::class), $c->make(OperationRegistryInterface::class));
        $this->assertSame($c->make(OperationValidatorInterface::class), $c->make(OperationValidatorInterface::class));
    }

    public function test_concretes_honour_their_contracts(): void
    {
        $this->assertInstanceOf(OperationRegistryInterface::class, new OperationRegistry(true));
        $this->assertInstanceOf(CapabilityRegistryInterface::class, new CapabilityRegistry(new OperationRegistry(true)));
        $this->assertInstanceOf(ColorNormalizerInterface::class, new ColorNormalizer());
        $this->assertInstanceOf(
            OperationValidatorInterface::class,
            new OperationValidator(new OperationRegistry(true), new ColorNormalizer())
        );
    }

    public function test_a_swapped_implementation_is_transparent_to_callers(): void
    {
        $c = $this->container();
        // Rebind the registry to a custom implementation; callers are unchanged.
        $c->singleton(OperationRegistryInterface::class, fn () => new OperationRegistry(false));

        $reg = $c->make(OperationRegistryInterface::class);
        $this->assertInstanceOf(OperationRegistryInterface::class, $reg);
        $this->assertSame([], $reg->all());
    }
}
