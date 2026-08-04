<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Projection\InMemoryProjectionAdapter;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionVerifier;
use App\Engines\Studio\Projection\StudioProjectionServiceProvider;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/** Dependency inversion - callers depend only on the projection interface. */
final class ProjectionBindingTest extends TestCase
{
    private function container(): Container
    {
        $c = new Container();
        (new StudioProjectionServiceProvider($c))->register();

        return $c;
    }

    public function test_interfaces_resolve_to_defaults(): void
    {
        $c = $this->container();
        $this->assertInstanceOf(InMemoryProjectionAdapter::class, $c->make(StudioProjectionAdapterInterface::class));
        $this->assertInstanceOf(ProjectionVerifier::class, $c->make(ProjectionVerifier::class));
    }

    public function test_concrete_honours_contract(): void
    {
        $this->assertInstanceOf(StudioProjectionAdapterInterface::class, ProjectionFixture::adapter());
    }

    public function test_a_consumer_can_depend_on_the_interface_only(): void
    {
        // A stand-in for the future executor: typed against the interface, no concrete.
        $consume = function (StudioProjectionAdapterInterface $adapter): string {
            return $adapter->project(ProjectionFixture::color('stat', '#ff0000'))->status;
        };

        $this->assertSame(ProjectionStatus::APPLIED, $consume(ProjectionFixture::adapter()));
    }
}
