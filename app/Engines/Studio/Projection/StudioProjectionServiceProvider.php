<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use Illuminate\Support\ServiceProvider;

/**
 * STUDIO888 · Projection — Phase 3B service provider (dormant).
 *
 * Binds the projection adapter interface to the fake in-memory reference
 * implementation and the projection verifier (dependency inversion). A real
 * live adapter will be provided by the frontend owner in a later phase.
 *
 * PHASE 3B IS DORMANT: no routes, middleware, listeners, or postMessage
 * handlers; nothing consumes these bindings; the provider is NOT registered.
 * It changes no production behaviour and projects to no live renderer.
 */
final class StudioProjectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StudioProjectionAdapterInterface::class, fn () => new InMemoryProjectionAdapter());
        $this->app->singleton(ProjectionVerifier::class, fn () => new ProjectionVerifier());
    }

    public function boot(): void
    {
        // Intentionally empty in Phase 3B. Contract layer only — no wiring.
    }
}
