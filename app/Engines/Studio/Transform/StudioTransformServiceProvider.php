<?php

namespace App\Engines\Studio\Transform;

use App\Engines\Studio\Transform\Contracts\CapabilityRegistryInterface;
use App\Engines\Studio\Transform\Contracts\ColorNormalizerInterface;
use App\Engines\Studio\Transform\Contracts\OperationRegistryInterface;
use App\Engines\Studio\Transform\Contracts\OperationValidatorInterface;
use Illuminate\Support\ServiceProvider;

/**
 * STUDIO888 · AI Transformation Engine — Phase 1 service provider.
 *
 * Binds every subsystem to its interface so callers depend on contracts, never
 * concretes (dependency inversion). Swapping an implementation later is a
 * single binding change here — no caller edits.
 *
 * PHASE 1 IS DORMANT: this provider only wires bindings. It registers no
 * routes, no middleware, no listeners, and touches no production code path.
 * Nothing in the running app consumes these bindings yet, so registering (or
 * not registering) this provider changes no production behaviour. The engine
 * is default-OFF (env STUDIO_TRANSFORM_ENGINE), and no code reads that flag yet.
 */
final class StudioTransformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ColorNormalizerInterface::class, ColorNormalizer::class);

        $this->app->singleton(OperationRegistryInterface::class, fn () => new OperationRegistry(true));

        $this->app->singleton(
            CapabilityRegistryInterface::class,
            fn ($app) => new CapabilityRegistry($app->make(OperationRegistryInterface::class)),
        );

        $this->app->singleton(
            OperationValidatorInterface::class,
            fn ($app) => new OperationValidator(
                $app->make(OperationRegistryInterface::class),
                $app->make(ColorNormalizerInterface::class),
            ),
        );
    }

    public function boot(): void
    {
        // Intentionally empty in Phase 1. No routes, no publishing, no hooks.
    }
}
