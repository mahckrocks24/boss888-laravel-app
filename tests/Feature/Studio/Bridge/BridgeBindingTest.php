<?php

namespace Tests\Feature\Studio\Bridge;

use App\Engines\Studio\Bridge\Contracts\SelectionProjectionBridgeInterface;
use App\Engines\Studio\Bridge\SelectionProjectionBridge;
use App\Engines\Studio\Bridge\StudioBridgeServiceProvider;
use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Selection\SelectionQuery;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/** Dependency inversion - the bridge depends only on the Projection interface. */
final class BridgeBindingTest extends TestCase
{
    public function test_provider_binds_the_bridge_interface(): void
    {
        $c = new Container();
        (new StudioBridgeServiceProvider($c))->register();
        $this->assertInstanceOf(SelectionProjectionBridge::class, $c->make(SelectionProjectionBridgeInterface::class));
    }

    public function test_concrete_honours_contract(): void
    {
        $this->assertInstanceOf(SelectionProjectionBridgeInterface::class, new SelectionProjectionBridge());
    }

    public function test_bridge_uses_the_projection_interface_not_a_concrete_renderer(): void
    {
        // The adapter is typed as the interface; the bridge never sees HtmlProjectionAdapter directly.
        $graph = BridgeFixture::graph();
        $adapter = BridgeFixture::rawAdapter();
        $this->assertInstanceOf(StudioProjectionAdapterInterface::class, $adapter);

        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);
        $run = function (StudioProjectionAdapterInterface $projection) use ($graph, $sel): string {
            return (new SelectionProjectionBridge())->project(
                $sel, $graph, new Operation(type: 'replace_text', value: 'Hi', opId: 'b1'), $projection, new ExecutionContext()
            )->status;
        };

        $this->assertSame(ExecutionResult::APPLIED, $run($adapter));
    }
}
