<?php

namespace App\Engines\Studio\Bridge;

use App\Engines\Studio\Bridge\Contracts\SelectionProjectionBridgeInterface;
use Illuminate\Support\ServiceProvider;

/**
 * STUDIO888 · Bridge — Phase 4D service provider (dormant).
 *
 * Binds the Selection→Projection bridge to its interface (dependency inversion).
 * The bridge is stateless and takes the graph, operation, adapter, and context
 * per call, so it is a safe singleton.
 *
 * PHASE 4D IS DORMANT: no routes, middleware, listeners, or transport; nothing
 * in the running app consumes this binding; the provider is NOT registered. It
 * projects to no live renderer and changes no live behaviour.
 */
final class StudioBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SelectionProjectionBridgeInterface::class, SelectionProjectionBridge::class);
    }

    public function boot(): void
    {
        // Intentionally empty in Phase 4D. Offline bridge only — no wiring.
    }
}
