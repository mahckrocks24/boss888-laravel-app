<?php

namespace App\Engines\Studio\Execution;

use App\Engines\Studio\Execution\Contracts\ExecutionVerifierInterface;
use App\Engines\Studio\Execution\Contracts\OperationExecutorInterface;
use App\Engines\Studio\Execution\Contracts\StudioDocumentAdapterInterface;
use App\Engines\Studio\Transform\CapabilityRegistry;
use App\Engines\Studio\Transform\ColorNormalizer;
use App\Engines\Studio\Transform\OperationRegistry;
use App\Engines\Studio\Transform\OperationValidator;
use Illuminate\Support\ServiceProvider;

/**
 * STUDIO888 · Execution — Phase 3A service provider (dormant).
 *
 * Binds the executor, verifier, document adapter, and handler registry to their
 * interfaces (dependency inversion). It builds a self-contained Phase-1 registry
 * (with the Phase-3A operation catalog applied) so nothing depends on other
 * providers being registered.
 *
 * PHASE 3A IS DORMANT: no routes, middleware, or listeners; nothing consumes
 * these bindings; the provider is NOT registered. It changes no production
 * behaviour, executes no live edit, and touches no renderer.
 */
final class StudioExecutionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExecutionVerifierInterface::class, ExecutionVerifier::class);

        $this->app->singleton(OperationHandlerRegistry::class, fn () => OperationHandlerRegistry::withDefaults());

        $this->app->singleton(StudioDocumentAdapterInterface::class, fn () => new InMemoryStudioDocumentAdapter());

        $this->app->singleton(OperationExecutorInterface::class, function ($app) {
            $registry = new OperationRegistry(true);
            ExecutorOperationCatalog::registerInto($registry);

            return new OperationExecutor(
                $registry,
                new CapabilityRegistry($registry),
                new OperationValidator($registry, new ColorNormalizer()),
                $app->make(OperationHandlerRegistry::class),
                $app->make(ExecutionVerifierInterface::class),
            );
        });
    }

    public function boot(): void
    {
        // Intentionally empty in Phase 3A. Dormant core — no execution, no wiring.
    }
}
