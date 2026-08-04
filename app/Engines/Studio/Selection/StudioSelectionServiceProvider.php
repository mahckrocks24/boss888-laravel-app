<?php

namespace App\Engines\Studio\Selection;

use App\Engines\Studio\Document\Contracts\StudioElementRepositoryInterface;
use App\Engines\Studio\Document\InMemoryStudioElementRepository;
use App\Engines\Studio\Selection\Contracts\ElementResolverInterface;
use App\Engines\Studio\Selection\Contracts\InspectorInterface;
use App\Engines\Studio\Selection\Contracts\SelectionEngineInterface;
use Illuminate\Support\ServiceProvider;

/**
 * STUDIO888 · Selection — Phase 2 service provider (dormant).
 *
 * Binds the stateless understanding services to their interfaces (dependency
 * inversion). The Semantic Graph is per-document DATA, so it is constructed on
 * demand from a repository rather than bound as a singleton.
 *
 * PHASE 2 IS DORMANT: no routes, middleware, or listeners; nothing in the
 * running app consumes these bindings; the provider is NOT registered. It
 * changes no production behaviour and executes no edits.
 */
final class StudioSelectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StudioElementRepositoryInterface::class, fn () => new InMemoryStudioElementRepository());

        $this->app->singleton(ElementResolverInterface::class, GraphElementResolver::class);

        $this->app->singleton(
            SelectionEngineInterface::class,
            fn ($app) => new SelectionEngine($app->make(ElementResolverInterface::class)),
        );

        $this->app->singleton(
            InspectorInterface::class,
            fn ($app) => new Inspector($app->make(SelectionEngineInterface::class)),
        );

        $this->app->singleton(QueryParser::class, fn () => new QueryParser());
    }

    public function boot(): void
    {
        // Intentionally empty in Phase 2. Understanding only — no execution.
    }
}
