<?php

namespace Tests\Feature\Studio\Selection;

use App\Engines\Studio\Document\Contracts\StudioElementRepositoryInterface;
use App\Engines\Studio\Document\InMemoryStudioElementRepository;
use App\Engines\Studio\Selection\Contracts\ElementResolverInterface;
use App\Engines\Studio\Selection\Contracts\InspectorInterface;
use App\Engines\Studio\Selection\Contracts\SelectionEngineInterface;
use App\Engines\Studio\Selection\GraphElementResolver;
use App\Engines\Studio\Selection\Inspector;
use App\Engines\Studio\Selection\SelectionEngine;
use App\Engines\Studio\Selection\StudioSelectionServiceProvider;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Dependency inversion for the understanding layer. Pure — a bare container,
 * no app/DB/Runtime boot.
 */
final class SelectionBindingTest extends TestCase
{
    private function container(): Container
    {
        $c = new Container();
        (new StudioSelectionServiceProvider($c))->register();

        return $c;
    }

    public function test_interfaces_resolve_to_defaults(): void
    {
        $c = $this->container();
        $this->assertInstanceOf(InMemoryStudioElementRepository::class, $c->make(StudioElementRepositoryInterface::class));
        $this->assertInstanceOf(GraphElementResolver::class, $c->make(ElementResolverInterface::class));
        $this->assertInstanceOf(SelectionEngine::class, $c->make(SelectionEngineInterface::class));
        $this->assertInstanceOf(Inspector::class, $c->make(InspectorInterface::class));
    }

    public function test_engine_and_inspector_share_wiring(): void
    {
        $c = $this->container();
        $this->assertSame($c->make(SelectionEngineInterface::class), $c->make(SelectionEngineInterface::class));
        // Inspector receives the same engine binding (no duplicate wiring).
        $this->assertInstanceOf(InspectorInterface::class, $c->make(InspectorInterface::class));
    }

    public function test_concretes_honour_contracts(): void
    {
        $this->assertInstanceOf(ElementResolverInterface::class, new GraphElementResolver());
        $this->assertInstanceOf(SelectionEngineInterface::class, new SelectionEngine(new GraphElementResolver()));
        $this->assertInstanceOf(InspectorInterface::class, new Inspector(new SelectionEngine(new GraphElementResolver())));
    }
}
