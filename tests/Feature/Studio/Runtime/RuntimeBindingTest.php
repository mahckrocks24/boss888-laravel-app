<?php

namespace Tests\Feature\Studio\Runtime;

use App\Engines\Studio\Bridge\SelectionProjectionBridge;
use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Runtime\Contracts\StudioRuntimeInterface;
use App\Engines\Studio\Runtime\Events\RuntimeEventBus;
use App\Engines\Studio\Runtime\StudioRuntime;
use App\Engines\Studio\Runtime\StudioRuntimeServiceProvider;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/** Dependency inversion - the runtime coordinates through interfaces only. */
final class RuntimeBindingTest extends TestCase
{
    public function test_provider_binds_the_runtime_interface(): void
    {
        $c = new Container();
        (new StudioRuntimeServiceProvider($c))->register();
        $this->assertInstanceOf(StudioRuntime::class, $c->make(StudioRuntimeInterface::class));
    }

    public function test_concrete_honours_contract(): void
    {
        $this->assertInstanceOf(StudioRuntimeInterface::class, new StudioRuntime(new SelectionProjectionBridge(), new RuntimeEventBus()));
    }

    public function test_renderer_is_any_projection_adapter_interface(): void
    {
        // The runtime accepts a renderer typed as the interface — no concrete renderer.
        $rt = RuntimeFixture::runtime();
        $rt->loadDocument('d1', RuntimeFixture::graph());
        $adapter = RuntimeFixture::adapter();
        $this->assertInstanceOf(StudioProjectionAdapterInterface::class, $adapter);
        $rt->attachRenderer($adapter);
        $this->assertSame($adapter, $rt->currentRenderer());
    }
}
