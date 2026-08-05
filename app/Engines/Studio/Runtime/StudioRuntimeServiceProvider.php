<?php

namespace App\Engines\Studio\Runtime;

use App\Engines\Studio\Bridge\Contracts\SelectionProjectionBridgeInterface;
use App\Engines\Studio\Bridge\SelectionProjectionBridge;
use App\Engines\Studio\Runtime\Contracts\StudioRuntimeInterface;
use App\Engines\Studio\Runtime\Events\RuntimeEventBus;
use Illuminate\Support\ServiceProvider;

/**
 * STUDIO888 · Runtime — Phase 5A service provider (dormant).
 *
 * Binds the runtime to its interface (dependency inversion). The runtime is a
 * per-session coordinator, so it is bound as a fresh instance rather than a
 * shared singleton.
 *
 * PHASE 5A IS DORMANT: no routes, middleware, listeners, or transport; nothing
 * in the running app consumes this binding; the provider is NOT registered. The
 * runtime drives no live renderer and changes no live behaviour.
 */
final class StudioRuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StudioRuntimeInterface::class, fn () => new StudioRuntime(
            new SelectionProjectionBridge(),
            new RuntimeEventBus(),
        ));
    }

    public function boot(): void
    {
        // Intentionally empty in Phase 5A. Runtime foundation only — no wiring.
    }
}
